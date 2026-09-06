<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Dossier;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/** Expedientes vivos: el tema que sigue abierto despues de la noticia. */
final class DossierController
{
    public function __construct(private readonly array $config)
    {
    }

    public function index(Request $request): void
    {
        Response::securityHeaders();
        Response::html(View::render('dossiers', [
            'titulo'       => 'Expedientes vivos · ' . $this->config['app']['name'],
            'descripcion'  => 'Temas que no se cierran cuando termina la noticia: cronología, actores y preguntas abiertas.',
            'rutaCanonica' => '/expedientes',
            'expedientes'  => Dossier::all(30),
        ]));
    }

    public function show(Request $request, array $params): void
    {
        $expediente = Dossier::findBySlug((string) $params['slug']);
        if ($expediente === null) {
            Response::securityHeaders();
            Response::html(View::render('errors/404', [
                'titulo'      => 'Expediente no encontrado · ' . $this->config['app']['name'],
                'noindex'     => true,
                'sugerencias' => [],
            ]), 404);
            return;
        }

        $id = (int) $expediente['id'];

        Response::securityHeaders();
        Response::html(View::render('dossier', [
            'titulo'       => $expediente['title'] . ' · Expediente · ' . $this->config['app']['name'],
            'descripcion'  => \App\Support\Str::excerpt((string) ($expediente['summary'] ?? $expediente['title']), 200),
            'rutaCanonica' => '/expediente/' . $expediente['slug'],
            'imagenSocial' => $expediente['cover_path'],
            'expediente'   => $expediente,
            'cronologia'   => Dossier::timeline($id),
            'piezas'       => Dossier::articles($id),
            'abiertas'     => Dossier::openQuestions($id),
        ]));
    }
}
