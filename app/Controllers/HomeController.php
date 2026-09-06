<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Article;
use App\Models\Dossier;
use App\Models\Homepage;
use App\Models\Live;
use App\Models\Poll;
use App\Models\Question;
use App\Models\Taxonomy;
use App\Models\Video;
use App\Services\SeoService;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/**
 * Portada.
 *
 * La portada SELECCIONA y ORDENA. No almacena noticias ni las elimina
 * cuando cambia la seleccion: cada zona lee de homepage_slots y, si el
 * equipo no ha curado esa zona, cae al contenido mas reciente.
 */
final class HomeController
{
    public function __construct(private readonly array $config)
    {
    }

    public function index(Request $request): void
    {
        $usados = [];

        // 1. Hero: una sola historia, no un mosaico de veinte titulares.
        $hero = Homepage::articlesForZone('hero', 1)[0]
            ?? (Homepage::fallbackArticles(null, 1)[0] ?? null);
        if ($hero !== null) {
            $usados[] = (int) $hero['id'];
        }

        // 2. Pulso del dia.
        $pulso          = Poll::pulseOfTheDay();
        $pulsoResultados = $pulso === null ? null : Poll::results((int) $pulso['id']);
        $pulsoOpciones   = $pulso === null ? [] : Poll::options((int) $pulso['id']);

        // 3. Lo esencial ahora: maximo tres asuntos.
        $esencial = Homepage::articlesForZone('esencial', 3);
        if (count($esencial) < 3) {
            $esencial = array_merge(
                $esencial,
                Homepage::fallbackArticles(null, 3 - count($esencial), array_merge($usados, array_column($esencial, 'id')))
            );
        }
        $usados = array_merge($usados, array_column($esencial, 'id'));

        // 4. Max lo analiza: siempre rotulado como analisis u opinion.
        $maxAnaliza = Homepage::videosForZone('max_analiza', 1)[0] ?? null;
        $maxPiezas  = Homepage::articlesForZone('max_analiza', 2);
        if ($maxPiezas === []) {
            $maxPiezas = Article::published(['limit' => 2, 'editorial_type' => 'opinion', 'exclude_ids' => $usados]);
            if ($maxPiezas === []) {
                $maxPiezas = Article::published(['limit' => 2, 'editorial_type' => 'analisis', 'exclude_ids' => $usados]);
            }
        }
        $usados = array_merge($usados, array_column($maxPiezas, 'id'));

        // 5. La conversacion se movio: piezas con pulso completado.
        $conversacion = $this->conversacionQueSeMovio();

        // 6. Proximo Live: una sola fuente de verdad para fecha y estado.
        $proximoLive = Live::next();

        // 7. Ultimos videos.
        $videos = Homepage::videosForZone('ultimos_videos', 4);
        if (count($videos) < 4) {
            $videos = array_merge($videos, Video::published(4 - count($videos)));
        }

        // 8. Expedientes vivos.
        $expedientes = Homepage::dossiersForZone('expedientes', 3);
        if ($expedientes === []) {
            $expedientes = Dossier::active(3);
        }

        // 9. Preguntas de la comunidad, ya moderadas.
        $preguntas = Question::publicList(4);

        // 10. Ultimas piezas, para que la portada nunca quede corta.
        $recientes = Article::published(['limit' => 6, 'exclude_ids' => $usados]);

        $jsonLd = [
            array_merge(['@context' => 'https://schema.org'], SeoService::organization($this->config)),
            [
                '@context'        => 'https://schema.org',
                '@type'           => 'WebSite',
                'name'            => $this->config['app']['name'],
                'url'             => $this->config['app']['url'],
                'inLanguage'      => 'es-VE',
                'potentialAction' => [
                    '@type'       => 'SearchAction',
                    'target'      => ['@type' => 'EntryPoint', 'urlTemplate' => $this->config['app']['url'] . '/buscar?q={search_term_string}'],
                    'query-input' => 'required name=search_term_string',
                ],
            ],
        ];

        Response::securityHeaders();
        Response::html(View::render('home', [
            'titulo'          => $this->config['app']['name'] . ' · ' . $this->config['brand']['promise'],
            'descripcion'     => 'Medio venezolano de comprensión y conversación. No te decimos solo qué pasó: te mostramos qué significa, qué versiones existen y qué cambió en la conversación.',
            'rutaCanonica'    => '/',
            'hero'            => $hero,
            'pulso'           => $pulso,
            'pulsoOpciones'   => $pulsoOpciones,
            'pulsoResultados' => $pulsoResultados,
            'esencial'        => $esencial,
            'maxAnaliza'      => $maxAnaliza,
            'maxPiezas'       => $maxPiezas,
            'conversacion'    => $conversacion,
            'proximoLive'     => $proximoLive,
            'videos'          => $videos,
            'expedientes'     => $expedientes,
            'preguntas'       => $preguntas,
            'recientes'       => $recientes,
            'categorias'      => Taxonomy::categoriesWithCounts(),
            'jsonLd'          => $jsonLd,
            'esPortada'       => true,
            'conHero'         => true,
        ]));
    }

    /**
     * Piezas donde el pulso informado ya tiene respuestas suficientes.
     * Si nadie ha votado lo bastante, la zona no se muestra: no se
     * fabrican resultados.
     */
    private function conversacionQueSeMovio(): array
    {
        $seleccion = Homepage::articlesForZone('conversacion', 2);
        if ($seleccion === []) {
            $seleccion = \App\Support\Database::all(
                'SELECT a.*, c.name AS category_name, c.slug AS category_slug,
                        m.storage_path AS hero_path, m.alt_text AS hero_media_alt
                   FROM articles a
                   JOIN polls p ON p.article_id = a.id
                   LEFT JOIN categories c ON c.id = a.category_id
                   LEFT JOIN media_assets m ON m.id = a.hero_media_id
                  WHERE a.deleted_at IS NULL AND a.status IN ("publicada","actualizada")
                    AND (SELECT COUNT(*) FROM poll_responses pr
                          WHERE pr.poll_id = p.id AND pr.stage = "informado") >= 10
                  ORDER BY a.published_at DESC LIMIT 2'
            );
        }

        $resultado = [];
        foreach ($seleccion as $pieza) {
            $poll = Poll::forArticle((int) $pieza['id']);
            if ($poll === null) {
                continue;
            }
            $shift = Poll::opinionShift((int) $poll['id']);
            if (!$shift['suficiente']) {
                continue;
            }
            $resultado[] = [
                'articulo'  => $pieza,
                'pulso'     => $poll,
                'resultados' => Poll::results((int) $poll['id']),
                'cambio'    => $shift,
            ];
        }

        return $resultado;
    }
}
