<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Middleware\Auth;
use App\Services\AuditService;
use App\Support\Database;
use App\Support\Paginator;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

final class AuditController
{
    public function __construct(private readonly array $config)
    {
    }

    public function index(Request $request): void
    {
        Auth::require(Auth::CAP_AUDIT_VIEW);

        $perPage = 40;
        $page    = Paginator::resolvePage($request->input('pagina'));
        $filtros = [
            'action'      => $request->text('accion'),
            'entity_type' => $request->text('entidad'),
            'user_id'     => $request->int('usuario'),
        ];

        $result = AuditService::recent($perPage, ($page - 1) * $perPage, $filtros);

        Response::securityHeaders(false);
        Response::html(View::render('admin/auditoria', [
            'titulo'    => 'Auditoría · Panel',
            'noindex'   => true,
            'registros' => $result['items'],
            'paginador' => new Paginator($result['total'], $perPage, $page, '/panel/auditoria', array_filter($filtros)),
            'acciones'  => AuditService::actions(),
            'usuarios'  => Database::all('SELECT id, display_name FROM users ORDER BY display_name'),
            'filtros'   => $filtros,
        ], 'layouts/admin'));
    }
}
