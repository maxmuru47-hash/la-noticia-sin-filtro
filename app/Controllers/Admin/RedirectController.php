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

final class RedirectController
{
    public function __construct(private readonly array $config)
    {
    }

    public function index(Request $request): void
    {
        Auth::require(Auth::CAP_REDIRECT_MANAGE);

        Response::securityHeaders(false);
        Response::html(View::render('admin/redirecciones', [
            'titulo'   => 'Redirecciones · Panel',
            'noindex'  => true,
            'redirecciones' => Database::all(
                'SELECT r.*, u.display_name AS creada_por
                   FROM redirects r LEFT JOIN users u ON u.id = r.created_by
                  ORDER BY r.created_at DESC LIMIT 300'
            ),
            'reservados' => Database::all(
                'SELECT rs.slug, rs.reserved_at, a.title, a.slug AS slug_actual
                   FROM reserved_slugs rs LEFT JOIN articles a ON a.id = rs.article_id
                  ORDER BY rs.reserved_at DESC LIMIT 100'
            ),
        ], 'layouts/admin'));
    }

    public function store(Request $request): void
    {
        Auth::require(Auth::CAP_REDIRECT_MANAGE);

        if (!Csrf::verify($request->text('_token'))) {
            $this->flash('La sesión expiró.', 'error');
            Response::redirect('/panel/redirecciones');
        }

        $desde = '/' . ltrim(trim($request->text('desde')), '/');
        $hacia = trim($request->text('hacia'));

        if ($desde === '/' || $hacia === '') {
            $this->flash('Necesito una ruta de origen y un destino.', 'error');
            Response::redirect('/panel/redirecciones');
        }

        // Destino interno, o externo solo si es https. Nada de
        // redirecciones abiertas hacia cualquier sitio.
        if (!str_starts_with($hacia, '/')) {
            if (!filter_var($hacia, FILTER_VALIDATE_URL) || !str_starts_with($hacia, 'https://')) {
                $this->flash('El destino debe ser una ruta interna o una URL https válida.', 'error');
                Response::redirect('/panel/redirecciones');
            }
        }

        if ($desde === $hacia) {
            $this->flash('El origen y el destino no pueden ser iguales: crearía un bucle.', 'error');
            Response::redirect('/panel/redirecciones');
        }

        $codigo = (int) $request->int('codigo', 301);
        if (!in_array($codigo, [301, 302, 308], true)) {
            $codigo = 301;
        }

        Database::run(
            'INSERT INTO redirects (from_path, to_path, status_code, reason, created_by)
             VALUES (:from, :to, :code, :reason, :user)
             ON DUPLICATE KEY UPDATE to_path = VALUES(to_path), status_code = VALUES(status_code), reason = VALUES(reason)',
            [
                'from'   => $desde,
                'to'     => $hacia,
                'code'   => $codigo,
                'reason' => $request->text('motivo') ?: null,
                'user'   => Auth::id(),
            ]
        );

        AuditService::log('redireccion.crear', 'redireccion', null, $desde . ' -> ' . $hacia);
        $this->flash('Redirección guardada.');
        Response::redirect('/panel/redirecciones');
    }

    private function flash(string $message, string $type = 'ok'): void
    {
        $_SESSION['_flash'] = ['mensaje' => $message, 'tipo' => $type];
    }
}
