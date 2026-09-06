<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Middleware\Auth;
use App\Services\AuditService;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\Request;
use App\Support\Response;
use App\Support\Str;
use App\Support\View;

final class UserController
{
    public function __construct(private readonly array $config)
    {
    }

    public function index(Request $request): void
    {
        Auth::require(Auth::CAP_USER_MANAGE);

        Response::securityHeaders(false);
        Response::html(View::render('admin/usuarios', [
            'titulo'   => 'Usuarios y roles · Panel',
            'noindex'  => true,
            'usuarios' => Database::all(
                'SELECT u.*, r.name AS role_name, r.slug AS role_slug
                   FROM users u JOIN roles r ON r.id = u.role_id
                  WHERE u.deleted_at IS NULL ORDER BY r.rank DESC, u.display_name'
            ),
            'roles'    => Database::all('SELECT * FROM roles ORDER BY `rank` DESC'),
        ], 'layouts/admin'));
    }

    public function store(Request $request): void
    {
        Auth::require(Auth::CAP_USER_MANAGE);

        if (!Csrf::verify($request->text('_token'))) {
            $this->flash('La sesión expiró.', 'error');
            Response::redirect('/panel/usuarios');
        }

        $email  = mb_strtolower(trim($request->text('correo')));
        $nombre = $request->text('nombre');
        $roleId = (int) $request->int('rol', 0);
        $clave  = (string) $request->input('clave', '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $nombre === '') {
            $this->flash('Necesito un nombre y un correo válido.', 'error');
            Response::redirect('/panel/usuarios');
        }

        if (mb_strlen($clave) < 12) {
            $this->flash('La contraseña debe tener al menos 12 caracteres.', 'error');
            Response::redirect('/panel/usuarios');
        }

        $rol = Database::first('SELECT * FROM roles WHERE id = :id', ['id' => $roleId]);
        if ($rol === null) {
            $this->flash('Ese rol no existe.', 'error');
            Response::redirect('/panel/usuarios');
        }

        // Nadie puede crear una cuenta mas poderosa que la suya.
        $actual = Auth::user();
        if ((int) $rol['rank'] > (int) ($actual['role_rank'] ?? 0)) {
            $this->flash('No puedes crear una cuenta con más permisos que la tuya.', 'error');
            Response::redirect('/panel/usuarios');
        }

        if (Database::value('SELECT id FROM users WHERE email = :email', ['email' => $email]) !== null) {
            $this->flash('Ya existe una cuenta con ese correo.', 'error');
            Response::redirect('/panel/usuarios');
        }

        $id = Database::insert('users', [
            'uuid'          => Str::uuid(),
            'role_id'       => $roleId,
            'email'         => $email,
            'password_hash' => password_hash($clave, PASSWORD_ARGON2ID),
            'display_name'  => $nombre,
            'is_active'     => 1,
            'must_change_password' => 1,
        ]);

        // Toda cuenta editorial nace con su firma publica asociada.
        if ($request->bool('crear_autor')) {
            Database::run(
                'INSERT INTO authors (uuid, user_id, slug, name, role_title)
                 VALUES (:uuid, :user, :slug, :name, :role)
                 ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)',
                [
                    'uuid' => Str::uuid(),
                    'user' => $id,
                    'slug' => Str::slug($nombre, 120),
                    'name' => $nombre,
                    'role' => $rol['name'],
                ]
            );
        }

        AuditService::log('usuario.crear', 'usuario', $id, $nombre . ' (' . $rol['name'] . ')');
        $this->flash('Cuenta creada. Deberá cambiar su contraseña al entrar.');
        Response::redirect('/panel/usuarios');
    }

    private function flash(string $message, string $type = 'ok'): void
    {
        $_SESSION['_flash'] = ['mensaje' => $message, 'tipo' => $type];
    }
}
