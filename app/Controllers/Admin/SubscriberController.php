<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Middleware\Auth;
use App\Models\Subscriber;
use App\Support\Paginator;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/**
 * La lista solo se mira, no se edita. Un suscriptor entra porque el
 * decidio entrar y sale porque el decidio salir: dar de alta a alguien
 * a mano desde el panel seria apuntar a una persona sin su permiso.
 *
 * Pide CAP_USER_MANAGE y no una capacidad de metricas: esto son datos
 * personales, no numeros.
 */
final class SubscriberController
{
    public function __construct(private readonly array $config)
    {
    }

    public function index(Request $request): void
    {
        Auth::require(Auth::CAP_USER_MANAGE);

        $porPagina = $this->config['pagination']['admin'];
        $pagina    = Paginator::resolvePage($request->input('pagina'));
        $datos     = Subscriber::paraPanel($porPagina, ($pagina - 1) * $porPagina);

        Response::securityHeaders(false);
        Response::html(View::render('admin/suscriptores', [
            'titulo'        => 'Suscriptores · Panel',
            'noindex'       => true,
            'suscriptores'  => $datos['items'],
            'resumen'       => Subscriber::resumen(),
            'paginador'     => new Paginator($datos['total'], $porPagina, $pagina, '/panel/suscriptores', []),
        ], 'layouts/admin'));
    }
}
