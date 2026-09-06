<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Middleware\Auth;
use App\Services\AuditService;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/**
 * Perfil de la cuenta: cambiar la propia contraseña.
 *
 * Hasta ahora, una cuenta creada con clave provisional se quedaba con esa
 * clave para siempre: la marca must_change_password se guardaba y nadie la
 * miraba. Aquí se cierra ese hueco.
 */
final class PerfilController
{
    public const LONGITUD_MINIMA = 12;

    public function __construct(private readonly array $config)
    {
    }

    public function mostrarClave(Request $request): void
    {
        Auth::requireLogin();

        $usuario   = Auth::user();
        $obligado  = (int) ($usuario['must_change_password'] ?? 0) === 1;

        Response::securityHeaders(false);
        Response::html(View::render('admin/clave', [
            'titulo'   => 'Cambiar contraseña · Panel',
            'noindex'  => true,
            'obligado' => $obligado,
            'minimo'   => self::LONGITUD_MINIMA,
            'error'    => $_SESSION['_error_clave'] ?? null,
        ], $obligado ? 'layouts/entrar' : 'layouts/admin'));

        unset($_SESSION['_error_clave']);
    }

    public function cambiarClave(Request $request): void
    {
        Auth::requireLogin();

        if (!Csrf::verify($request->text('_token'))) {
            $this->error('La sesión expiró. Inténtalo de nuevo.');
        }

        $usuario = Auth::user();
        $actual  = (string) $request->input('clave_actual', '');
        $nueva   = (string) $request->input('clave_nueva', '');
        $repetir = (string) $request->input('clave_repetir', '');

        // Se comprueba la contraseña actual aunque la sesión ya esté
        // abierta: si alguien deja el panel abierto, que no le puedan
        // cambiar la clave y quedarse con la cuenta.
        if (!password_verify($actual, (string) $usuario['password_hash'])) {
            AuditService::log('clave.fallo', 'usuario', (int) $usuario['id'], 'Contraseña actual incorrecta');
            $this->error('La contraseña actual no es correcta.');
        }

        if (mb_strlen($nueva) < self::LONGITUD_MINIMA) {
            $this->error('La nueva contraseña debe tener al menos ' . self::LONGITUD_MINIMA . ' caracteres.');
        }
        if ($nueva !== $repetir) {
            $this->error('Las dos contraseñas nuevas no coinciden.');
        }
        if (password_verify($nueva, (string) $usuario['password_hash'])) {
            $this->error('La nueva contraseña tiene que ser distinta de la actual.');
        }
        if (mb_stripos($nueva, (string) $usuario['email']) !== false) {
            $this->error('La contraseña no puede contener tu correo.');
        }

        Database::update('users', [
            'password_hash'        => password_hash($nueva, PASSWORD_ARGON2ID),
            'must_change_password' => 0,
        ], 'id = :id', ['id' => (int) $usuario['id']]);

        // Cambiar la contraseña invalida cualquier sesión robada.
        session_regenerate_id(true);
        Csrf::rotate();

        AuditService::log('clave.cambiar', 'usuario', (int) $usuario['id'], (string) $usuario['display_name']);

        $_SESSION['_flash'] = [
            'mensaje' => 'Contraseña cambiada. Si tenías la sesión abierta en otro sitio, ya no sirve.',
            'tipo'    => 'ok',
        ];

        Response::redirect('/panel');
    }

    private function error(string $mensaje): never
    {
        $_SESSION['_error_clave'] = $mensaje;
        Response::redirect('/panel/clave');
    }
}
