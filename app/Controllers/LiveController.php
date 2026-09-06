<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Live;
use App\Models\Video;
use App\Services\SeoService;
use App\Support\Paginator;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/**
 * Lives. Cuando un Live termina, su pagina no desaparece: se convierte en
 * expediente con video, resumen, preguntas respondidas, asuntos
 * pendientes y las noticias posteriores.
 */
final class LiveController
{
    public function __construct(private readonly array $config)
    {
    }

    public function index(Request $request): void
    {
        $perPage   = $this->config['pagination']['archive'];
        $page      = Paginator::resolvePage($request->input('pagina'));
        $total     = Live::countPast();
        $paginator = new Paginator($total, $perPage, $page, '/lives', []);

        Response::securityHeaders();
        Response::html(View::render('lives', [
            'titulo'       => 'Lives · ' . $this->config['app']['name'],
            'descripcion'  => 'Agenda de programas en vivo y archivo completo, con grabación, capítulos y transcripción.',
            'rutaCanonica' => '/lives',
            'proximos'     => Live::upcoming(4),
            'pasados'      => Live::past($perPage, $paginator->offset()),
            'paginador'    => $paginator,
        ]));
    }

    public function show(Request $request, array $params): void
    {
        $live = Live::findBySlug((string) $params['slug']);
        if ($live === null) {
            Response::securityHeaders();
            Response::html(View::render('errors/404', [
                'titulo'      => 'Live no encontrado · ' . $this->config['app']['name'],
                'noindex'     => true,
                'sugerencias' => [],
            ]), 404);
            return;
        }

        $id        = (int) $live['id'];
        $grabacion = $live['recording_video_id'] === null ? null : Video::findById((int) $live['recording_video_id']);

        Response::securityHeaders();
        Response::html(View::render('live', [
            'titulo'       => $live['title'] . ' · Live · ' . $this->config['app']['name'],
            'descripcion'  => \App\Support\Str::excerpt((string) ($live['summary'] ?? $live['title']), 200),
            'rutaCanonica' => '/live/' . $live['slug'],
            'imagenSocial' => $live['cover_path'],
            'live'         => $live,
            'grabacion'    => $grabacion,
            'capitulos'    => $grabacion === null ? [] : Video::chapters((int) $grabacion['id']),
            'transcripcion' => $grabacion === null ? null : Video::transcript((int) $grabacion['id']),
            'antes'        => Live::articles($id, 'antes'),
            'despues'      => Live::articles($id, 'despues'),
            'preguntas'    => Live::questions($id),
            'jsonLd'       => [SeoService::live($live, $this->config)],
        ]));
    }
}
