<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Article;
use App\Models\Poll;
use App\Models\Question;
use App\Services\AnalyticsService;
use App\Services\BlockRenderer;
use App\Services\SeoService;
use App\Support\Database;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/**
 * Pagina individual de noticia.
 *
 * La URL /noticia/{slug} es permanente. Sirve la pieza mientras exista,
 * incluida la archivada y la retirada. Solo un borrador o una programada
 * responden 404 al visitante.
 */
final class ArticleController
{
    public function __construct(private readonly array $config)
    {
    }

    public function show(Request $request, array $params): void
    {
        $slug    = (string) ($params['slug'] ?? '');
        $article = Article::findBySlug($slug);

        // La URL no responde? Quiza es un slug antiguo con redireccion.
        if ($article === null) {
            $this->redirectOrNotFound('/noticia/' . $slug);
            return;
        }

        // Borrador, revision o programada: invisible para el visitante,
        // pero visible para quien tiene sesion y permiso, con aviso.
        $visible     = Article::isPubliclyVisible($article);
        $vistaPrevia = false;

        if (!$visible) {
            if (\App\Middleware\Auth::check() && \App\Middleware\Auth::canEditArticle($article)) {
                $vistaPrevia = true;
            } else {
                $this->notFound();
                return;
            }
        }

        $id     = (int) $article['id'];
        $blocks = BlockRenderer::decode($article['body_blocks']);
        $videos = Article::videos($id);
        $tags   = Article::tags($id);

        $poll             = Poll::forArticle($id);
        $pollOptions      = $poll === null ? [] : Poll::options((int) $poll['id']);
        $pollResults      = $poll === null ? null : Poll::results((int) $poll['id']);
        $pollShift        = $poll === null ? null : Poll::opinionShift((int) $poll['id']);
        $votoInicial      = $poll === null ? false : Poll::hasVoted((int) $poll['id'], $request->voterHash($this->config['app']['key'], 'pulso'), 'inicial');
        $votoInformado    = $poll === null ? false : Poll::hasVoted((int) $poll['id'], $request->voterHash($this->config['app']['key'], 'pulso'), 'informado');

        $corrections = Article::corrections($id);

        if (!$vistaPrevia) {
            Article::incrementViews($id);
        }

        $jsonLd = [
            SeoService::article($article, $this->config, ['tags' => $tags, 'corrections' => $corrections]),
            SeoService::breadcrumbs([
                ['nombre' => 'Inicio', 'ruta' => '/'],
                ['nombre' => (string) ($article['category_name'] ?? 'Noticias'), 'ruta' => '/categoria/' . ($article['category_slug'] ?? '')],
                ['nombre' => (string) $article['title'], 'ruta' => '/noticia/' . $article['slug']],
            ], $this->config['app']['url']),
        ];

        foreach ($videos as $video) {
            $transcript = \App\Models\Video::transcript((int) $video['id']);
            $jsonLd[]   = SeoService::video($video, $this->config, $transcript);
        }

        Response::securityHeaders();
        Response::html(View::render('article', [
            'titulo'         => ($article['seo_title'] ?: $article['title']) . ' · ' . $this->config['app']['name'],
            'descripcion'    => $article['seo_description'] ?: \App\Support\Str::excerpt((string) ($article['summary'] ?? $article['title']), 200),
            'rutaCanonica'   => '/noticia/' . $article['slug'],
            'imagenSocial'   => $article['social_image_path'] ?: $article['hero_path'],
            'noindex'        => (bool) $article['noindex'],
            'tipoOg'         => 'article',
            'articulo'       => $article,
            'bloques'        => $blocks,
            'cuerpo'         => BlockRenderer::render($blocks, ['videos' => $videos]),
            'etiquetas'      => $tags,
            'temas'          => Article::topics($id),
            'fuentes'        => Article::sources($id),
            'actores'        => Article::actors($id),
            'impactos'       => Article::impacts($id),
            'cronologia'     => Article::timeline($id),
            'correcciones'   => $corrections,
            'videos'         => $videos,
            'lives'          => Article::lives($id),
            'herencia'       => Article::lineage($id),
            'relacionadas'   => Article::related($article, 4),
            'preguntas'      => Question::publicList(4, $id),
            'pulso'          => $poll,
            'pulsoOpciones'  => $pollOptions,
            'pulsoResultados' => $pollResults,
            'pulsoCambio'    => $pollShift,
            'votoInicial'    => $votoInicial,
            'votoInformado'  => $votoInformado,
            'vistaPrevia'    => $vistaPrevia,
            'jsonLd'         => $jsonLd,
            'tokenAnalitica' => AnalyticsService::sessionToken(),
            'conBarraProgreso' => true,
        ]));
    }

    /** Una URL vieja puede tener redireccion 301 registrada. */
    private function redirectOrNotFound(string $path): void
    {
        $redirect = Database::first(
            'SELECT * FROM redirects WHERE from_path = :path LIMIT 1',
            ['path' => $path]
        );

        if ($redirect !== null) {
            Database::run('UPDATE redirects SET hits = hits + 1 WHERE id = :id', ['id' => (int) $redirect['id']]);
            Response::redirect((string) $redirect['to_path'], (int) $redirect['status_code']);
        }

        $this->notFound();
    }

    private function notFound(): void
    {
        Response::securityHeaders();
        Response::html(View::render('errors/404', [
            'titulo'      => 'Página no encontrada · ' . $this->config['app']['name'],
            'descripcion' => 'La dirección solicitada no corresponde a ninguna pieza publicada.',
            'noindex'     => true,
            'sugerencias' => Article::published(['limit' => 4]),
        ]), 404);
    }
}
