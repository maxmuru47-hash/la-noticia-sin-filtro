<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Support\Database;
use App\Support\Dates;
use App\Support\Response;

/**
 * Sesion del panel y matriz de capacidades.
 *
 * Los permisos se comprueban por capacidad, no por nombre de rol: asi se
 * puede crear un rol nuevo sin tocar el codigo de los controladores.
 */
final class Auth
{
    public const CAP_ARTICLE_CREATE   = 'articulo.crear';
    public const CAP_ARTICLE_EDIT_OWN = 'articulo.editar_propio';
    public const CAP_ARTICLE_EDIT_ANY = 'articulo.editar_cualquiera';
    public const CAP_ARTICLE_PUBLISH  = 'articulo.publicar';
    public const CAP_ARTICLE_ARCHIVE  = 'articulo.archivar';
    public const CAP_ARTICLE_WITHDRAW = 'articulo.retirar';
    public const CAP_ARTICLE_DESTROY  = 'articulo.eliminar_definitivo';
    public const CAP_MEDIA_MANAGE     = 'medios.gestionar';
    public const CAP_VIDEO_MANAGE     = 'video.gestionar';
    public const CAP_LIVE_MANAGE      = 'live.gestionar';
    public const CAP_TAXONOMY_MANAGE  = 'taxonomia.gestionar';
    public const CAP_MODERATE         = 'comunidad.moderar';
    public const CAP_HOMEPAGE_MANAGE  = 'portada.gestionar';
    public const CAP_REDIRECT_MANAGE  = 'redirecciones.gestionar';
    public const CAP_USER_MANAGE      = 'usuarios.gestionar';
    public const CAP_AUDIT_VIEW       = 'auditoria.ver';
    public const CAP_METRICS_VIEW     = 'metricas.ver';

    private static ?array $user = null;
    private static bool $resolved = false;

    public static function startSession(array $config): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => (bool) ($config['secure_cookies'] ?? false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name($config['session_name'] ?? 'lnsf_sesion');
        session_start();
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['usuario_id']       = (int) $user['id'];
        $_SESSION['autenticado_en']   = time();
        $_SESSION['ultima_actividad'] = time();
        self::$user     = null;
        self::$resolved = false;

        Database::update('users', ['last_login_at' => Dates::nowUtc()], 'id = :id', ['id' => (int) $user['id']]);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        self::$user     = null;
        self::$resolved = true;
    }

    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;

        $id = $_SESSION['usuario_id'] ?? null;
        if ($id === null) {
            return null;
        }

        self::$user = Database::first(
            'SELECT u.*, r.slug AS role_slug, r.name AS role_name, r.rank AS role_rank, r.capabilities
               FROM users u
               JOIN roles r ON r.id = u.role_id
              WHERE u.id = :id AND u.is_active = 1 AND u.deleted_at IS NULL',
            ['id' => (int) $id]
        );

        return self::$user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /** @return array<int,string> */
    public static function capabilities(): array
    {
        $user = self::user();
        if ($user === null) {
            return [];
        }
        $caps = json_decode((string) $user['capabilities'], true);
        return is_array($caps) ? $caps : [];
    }

    public static function can(string $capability): bool
    {
        $caps = self::capabilities();
        return in_array('*', $caps, true) || in_array($capability, $caps, true);
    }

    /** Puede editar este articulo concreto? */
    public static function canEditArticle(array $article): bool
    {
        if (self::can(self::CAP_ARTICLE_EDIT_ANY)) {
            return true;
        }
        if (!self::can(self::CAP_ARTICLE_EDIT_OWN)) {
            return false;
        }
        $user     = self::user();
        $authorId = (int) ($article['author_id'] ?? 0);
        if ($authorId === 0 || $user === null) {
            return false;
        }
        $own = Database::value('SELECT id FROM authors WHERE user_id = :uid AND id = :aid', [
            'uid' => (int) $user['id'],
            'aid' => $authorId,
        ]);
        // Un autor solo toca lo suyo, y solo mientras no este publicado.
        return $own !== null && in_array($article['status'], ['borrador', 'revision'], true);
    }

    /** Sesión inactiva más de este tiempo: se cierra sola. */
    public const MINUTOS_INACTIVIDAD = 240;

    public static function requireLogin(): void
    {
        if (!self::check()) {
            Response::redirect('/panel/entrar?destino=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/panel'));
        }

        self::cerrarSiEstaInactiva();
        self::exigirCambioDeClave();
    }

    /**
     * Una sesión olvidada en un ordenador prestado no dura para siempre.
     * Se mide inactividad, no duración: trabajar no te echa fuera.
     */
    private static function cerrarSiEstaInactiva(): void
    {
        $ultima = $_SESSION['ultima_actividad'] ?? time();

        if (time() - (int) $ultima > self::MINUTOS_INACTIVIDAD * 60) {
            self::logout();
            session_start();
            $_SESSION['_flash_error'] = 'Cerramos tu sesión por inactividad. Vuelve a entrar.';
            Response::redirect('/panel/entrar');
        }

        $_SESSION['ultima_actividad'] = time();
    }

    /**
     * Con una contraseña provisional no se llega a ninguna otra pantalla.
     * Antes la marca se guardaba y nadie la miraba, así que una clave
     * provisional se quedaba puesta para siempre.
     */
    private static function exigirCambioDeClave(): void
    {
        $usuario = self::user();

        if ($usuario === null || (int) ($usuario['must_change_password'] ?? 0) !== 1) {
            return;
        }

        $ruta = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        // Solo se permite la propia pantalla de cambio y salir.
        if ($ruta === '/panel/clave' || $ruta === '/panel/salir') {
            return;
        }

        Response::redirect('/panel/clave');
    }

    public static function require(string $capability): void
    {
        self::requireLogin();
        if (!self::can($capability)) {
            http_response_code(403);
            echo \App\Support\View::render('admin/403', ['capacidad' => $capability], 'layouts/admin');
            exit;
        }
    }

    public static function id(): ?int
    {
        $user = self::user();
        return $user === null ? null : (int) $user['id'];
    }

    public static function label(): string
    {
        $user = self::user();
        return $user === null ? 'sistema' : (string) $user['display_name'];
    }
}
