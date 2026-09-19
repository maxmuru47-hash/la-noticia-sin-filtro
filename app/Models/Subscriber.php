<?php
declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

/**
 * SUSCRIPTORES
 *
 * La lista de correo es lo unico de esta casa que no depende de un
 * algoritmo ajeno. Por eso se trata con mas cuidado que el resto: cada
 * alta guarda el texto exacto que la persona acepto y el momento en que
 * lo acepto, y nadie entra en la lista sin haber marcado la casilla.
 *
 * Sobre el token: la tabla guarda UNO solo por persona. Sirve para
 * confirmar el alta y despues para darse de baja, y por eso NO se borra
 * al confirmar. Si se borrara, el enlace de baja de cualquier correo ya
 * enviado dejaria de funcionar, que es justo lo contrario de lo que pide
 * una lista limpia: darse de baja tiene que ser mas facil que apuntarse.
 */
final class Subscriber
{
    /** Los mismos valores que admite la columna SET de la base. */
    public const PROPOSITOS = [
        'resumen_semanal' => 'El resumen de la semana',
        'avisos_live'     => 'Aviso antes de cada Live',
        'correcciones'    => 'Cuando corregimos una pieza',
    ];

    /**
     * Alta o reactivacion. Devuelve el token de la persona.
     *
     * Una direccion que ya estaba apuntada no se duplica ni pierde su
     * token: se le actualizan los intereses y se le quita la baja. Que
     * alguien se vuelva a apuntar nunca puede ser un error.
     */
    public static function alta(string $email, array $propositos, string $textoConsentido): string
    {
        $propositos = self::limpiarPropositos($propositos);
        $lista      = implode(',', $propositos);

        Database::run(
            'INSERT INTO subscribers (email, purpose, consent_text, confirm_token)
                  VALUES (:email, :purpose, :consent, :token)
             ON DUPLICATE KEY UPDATE
                  purpose         = :purpose_dup,
                  consent_text    = :consent_dup,
                  unsubscribed_at = NULL',
            [
                'email'       => $email,
                'purpose'     => $lista,
                'purpose_dup' => $lista,
                'consent'     => $textoConsentido,
                'consent_dup' => $textoConsentido,
                'token'       => bin2hex(random_bytes(24)),
            ]
        );

        return (string) Database::value(
            'SELECT confirm_token FROM subscribers WHERE email = :email',
            ['email' => $email]
        );
    }

    public static function porToken(string $token): ?array
    {
        if (!preg_match('/^[0-9a-f]{48}$/', $token)) {
            return null;
        }
        return Database::first(
            'SELECT * FROM subscribers WHERE confirm_token = :token',
            ['token' => $token]
        );
    }

    /** Confirmar es idempotente: pulsar el enlace dos veces no es un error. */
    public static function confirmar(string $token): bool
    {
        $persona = self::porToken($token);
        if ($persona === null) {
            return false;
        }
        if ($persona['confirmed_at'] === null || $persona['unsubscribed_at'] !== null) {
            Database::run(
                'UPDATE subscribers
                    SET confirmed_at = COALESCE(confirmed_at, UTC_TIMESTAMP()),
                        unsubscribed_at = NULL
                  WHERE id = :id',
                ['id' => $persona['id']]
            );
        }
        return true;
    }

    /** Darse de baja tambien es idempotente, y no borra el registro:
     *  hay que poder demostrar que alguien pidio la baja y cuando. */
    public static function baja(string $token): bool
    {
        $persona = self::porToken($token);
        if ($persona === null) {
            return false;
        }
        if ($persona['unsubscribed_at'] === null) {
            Database::run(
                'UPDATE subscribers SET unsubscribed_at = UTC_TIMESTAMP() WHERE id = :id',
                ['id' => $persona['id']]
            );
        }
        return true;
    }

    /** @return array{total:int,confirmados:int,pendientes:int,bajas:int} */
    public static function resumen(): array
    {
        $fila = Database::first(
            'SELECT COUNT(*) AS total,
                    SUM(confirmed_at IS NOT NULL AND unsubscribed_at IS NULL) AS confirmados,
                    SUM(confirmed_at IS NULL     AND unsubscribed_at IS NULL) AS pendientes,
                    SUM(unsubscribed_at IS NOT NULL) AS bajas
               FROM subscribers'
        ) ?? [];

        return [
            'total'       => (int) ($fila['total'] ?? 0),
            'confirmados' => (int) ($fila['confirmados'] ?? 0),
            'pendientes'  => (int) ($fila['pendientes'] ?? 0),
            'bajas'       => (int) ($fila['bajas'] ?? 0),
        ];
    }

    /** @return array{items:array<int,array>,total:int} */
    public static function paraPanel(int $porPagina, int $desde): array
    {
        return [
            'items' => Database::all(
                'SELECT id, email, purpose, consented_at, confirmed_at, unsubscribed_at
                   FROM subscribers
                  ORDER BY consented_at DESC
                  LIMIT ' . max(1, $porPagina) . ' OFFSET ' . max(0, $desde)
            ),
            'total' => (int) Database::value('SELECT COUNT(*) FROM subscribers'),
        ];
    }

    /**
     * Solo los que sirven para enviar: confirmados, sin baja y con el
     * proposito pedido. Ninguna otra consulta debe usarse para enviar.
     */
    public static function destinatarios(string $proposito): array
    {
        if (!array_key_exists($proposito, self::PROPOSITOS)) {
            return [];
        }
        return Database::all(
            'SELECT email, confirm_token FROM subscribers
              WHERE confirmed_at IS NOT NULL
                AND unsubscribed_at IS NULL
                AND FIND_IN_SET(:proposito, purpose) > 0
              ORDER BY id',
            ['proposito' => $proposito]
        );
    }

    /** @return list<string> Siempre devuelve al menos el resumen. */
    public static function limpiarPropositos(array $pedidos): array
    {
        $validos = [];
        foreach ($pedidos as $p) {
            $p = is_string($p) ? trim($p) : '';
            if (array_key_exists($p, self::PROPOSITOS) && !in_array($p, $validos, true)) {
                $validos[] = $p;
            }
        }
        return $validos === [] ? ['resumen_semanal'] : $validos;
    }
}
