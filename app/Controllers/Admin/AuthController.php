<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Middleware\Auth;
use App\Middleware\RateLimit;
use App\Services\AuditService;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

final class AuthController
{
    public function __construct(private readonly array $config)
    {
    }

    public function showLogin(Request $request): void
    {
        if (Auth::check()) {
            Response::redirect('/panel');
        }

        Response::securityHeaders(false);
        Response::html(View::render('admin/entrar', [
            'titulo'  => 'Entrar al panel · ' . $this->config['app']['name'],
            'noindex' => true,
            'destino' => $request->text('destino', '/panel'),
            'error'   => $_SESSION['_flash_error'] ?? null,
        ], 'layouts/entrar'));

        unset($_SESSION['_flash_error']);
    }

    public function login(Request $request): void
    {
        if (!Csrf::verify($request->text('_token'))) {
            $_SESSION['_flash_error'] = 'La sesión expiró. Inténtalo de nuevo.';
            Response::redirect('/panel/entrar');
        }

        $email    = mb_strtolower(trim($request->text('correo')));
        $password = (string) $request->input('clave', '');
        $ipHash   = hash('sha256', $request->ip() . '|acceso');

        if (RateLimit::loginBlocked($email, $ipHash, $this->config['security']['login_max_attempts'], $this->config['security']['login_window_minutes'])) {
            RateLimit::recordLogin($email, $ipHash, false);
            AuditService::log('acceso.bloqueado', 'usuario', null, $email);
            $_SESSION['_flash_error'] = 'Demasiados intentos fallidos. Espera unos minutos antes de volver a probar.';
            Response::redirect('/panel/entrar');
        }

        // Retardo progresivo: cada fallo encarece el siguiente intento.
        $backoff = RateLimit::backoffSeconds($email);
        if ($backoff > 0) {
            sleep($backoff);
        }

        $user = Database::first(
            'SELECT * FROM users WHERE email = :email AND is_active = 1 AND deleted_at IS NULL',
            ['email' => $email]
        );

        // Se compara siempre contra un hash, exista o no la cuenta, para
        // que el tiempo de respuesta no revele si el correo esta registrado.
        $hash = $user['password_hash'] ?? '$2y$12$invalidoinvalidoinvalidoinvalidoinvalidoinvalidoinvalidoinva';

        if (!password_verify($password, $hash) || $user === null) {
            RateLimit::recordLogin($email, $ipHash, false);
            $_SESSION['_flash_error'] = 'Correo o contraseña incorrectos.';
            Response::redirect('/panel/entrar');
        }

        if (password_needs_rehash($hash, PASSWORD_ARGON2ID)) {
            Database::update('users', [
                'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
            ], 'id = :id', ['id' => (int) $user['id']]);
        }

        RateLimit::recordLogin($email, $ipHash, true);
        Auth::login($user);
        Csrf::rotate();
        AuditService::log('acceso.entrar', 'usuario', (int) $user['id'], $user['display_name']);

        $destino = $request->text('destino', '/panel');
        // Solo se acepta un destino interno: nada de redirecciones abiertas.
        if (!str_starts_with($destino, '/panel')) {
            $destino = '/panel';
        }

        Response::redirect($destino);
    }

    public function logout(Request $request): void
    {
        if (Csrf::verify($request->text('_token'))) {
            AuditService::log('acceso.salir', 'usuario', Auth::id(), Auth::label());
            Auth::logout();
        }
        Response::redirect('/panel/entrar');
    }
}
