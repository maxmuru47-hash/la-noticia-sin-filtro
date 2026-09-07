<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Support\Database;

/**
 * Limite de intentos. Se usa en el acceso al panel y en los envios
 * publicos (pulso, preguntas), para que nadie pueda inundar la
 * plataforma ni adivinar contrasenas a fuerza bruta.
 */
final class RateLimit
{
    public static function loginBlocked(string $identifier, string $ipHash, int $maxAttempts, int $windowMinutes): bool
    {
        $since = gmdate('Y-m-d H:i:s', time() - ($windowMinutes * 60));

        $byIdentifier = (int) Database::value(
            'SELECT COUNT(*) FROM login_attempts
              WHERE identifier = :id AND successful = 0 AND attempted_at > :since',
            ['id' => $identifier, 'since' => $since]
        );

        $byIp = (int) Database::value(
            'SELECT COUNT(*) FROM login_attempts
              WHERE ip_hash = :ip AND successful = 0 AND attempted_at > :since',
            ['ip' => $ipHash, 'since' => $since]
        );

        return $byIdentifier >= $maxAttempts || $byIp >= ($maxAttempts * 3);
    }

    public static function recordLogin(string $identifier, string $ipHash, bool $successful): void
    {
        Database::insert('login_attempts', [
            'identifier'  => substr($identifier, 0, 190),
            'ip_hash'     => $ipHash,
            'successful'  => $successful ? 1 : 0,
            'attempted_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** Retardo progresivo: cada intento fallido cuesta mas tiempo. */
    public static function backoffSeconds(string $identifier, int $windowMinutes = 15): int
    {
        $since = gmdate('Y-m-d H:i:s', time() - ($windowMinutes * 60));
        $fails = (int) Database::value(
            'SELECT COUNT(*) FROM login_attempts
              WHERE identifier = :id AND successful = 0 AND attempted_at > :since',
            ['id' => $identifier, 'since' => $since]
        );
        return min(8, max(0, $fails - 2));
    }

    /** Limite generico por accion, apoyado en la sesion del visitante. */
    public static function tooFrequent(string $action, int $minSeconds = 20): bool
    {
        $key  = '_rl_' . $action;
        $last = $_SESSION[$key] ?? 0;
        if (time() - (int) $last < $minSeconds) {
            return true;
        }
        $_SESSION[$key] = time();
        return false;
    }

    /**
     * Deja pasar una ráfaga normal y frena solo el machaque.
     *
     * Un intervalo mínimo fijo castiga a quien usa la página bien —pulsar un
     * atajo y escribir a continuación son dos acciones seguidas y legítimas—
     * y no detiene a quien abusa, que puede tirar la cookie y empezar de
     * cero. Contar cuántas veces en una ventana permite ambas cosas: varias
     * seguidas sí, cien no.
     */
    public static function burst(string $action, int $max, int $seconds): bool
    {
        $key    = '_rlb_' . $action;
        $ahora  = time();
        $marcas = array_values(array_filter(
            (array) ($_SESSION[$key] ?? []),
            static fn ($t): bool => is_int($t) && ($ahora - $t) < $seconds
        ));

        if (count($marcas) >= $max) {
            $_SESSION[$key] = $marcas;
            return true;
        }

        $marcas[] = $ahora;
        $_SESSION[$key] = $marcas;

        return false;
    }

    public static function purgeOld(int $days = 30): void
    {
        Database::run('DELETE FROM login_attempts WHERE attempted_at < :cutoff', [
            'cutoff' => gmdate('Y-m-d H:i:s', time() - ($days * 86400)),
        ]);
    }
}
