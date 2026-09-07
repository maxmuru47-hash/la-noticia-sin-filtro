<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Middleware\Auth;
use App\Models\Article;
use App\Models\Dossier;
use App\Models\Live;
use App\Models\Taxonomy;
use App\Models\Video;
use App\Services\ArticleService;
use App\Services\BlockRenderer;
use App\Services\EditorialService;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\Dates;
use App\Support\Paginator;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/**
 * Editor de noticias.
 *
 * Ninguna accion de este controlador escribe directamente en la tabla
 * articles: todo pasa por ArticleService, que es donde viven las reglas
 * de permanencia.
 */
final class ArticleController
{
    public function __construct(private readonly array $config)
    {
    }

    public function index(Request $request): void
    {
        Auth::requireLogin();

        $perPage = $this->config['pagination']['admin'];
        $page    = Paginator::resolvePage($request->input('pagina'));

        $filtros = [
            'status'         => $request->text('estado'),
            'editorial_type' => $request->text('tipo'),
            'author_id'      => $request->int('autor'),
            'q'              => $request->text('q'),
        ];

        $resultado = Article::forAdmin($filtros, $perPage, ($page - 1) * $perPage);

        Response::securityHeaders(false);
        Response::html(View::render('admin/noticias', [
            'titulo'    => 'Noticias · Panel',
            'noindex'   => true,
            'piezas'    => $resultado['items'],
            'paginador' => new Paginator($resultado['total'], $perPage, $page, '/panel/noticias', array_filter($filtros)),
            'filtros'   => $filtros,
            'autores'   => Taxonomy::authors(false),
            'puedePublicar' => Auth::can(Auth::CAP_ARTICLE_PUBLISH),
            'puedeArchivar' => Auth::can(Auth::CAP_ARTICLE_ARCHIVE),
        ], 'layouts/admin'));
    }

    public function create(Request $request): void
    {
        Auth::require(Auth::CAP_ARTICLE_CREATE);
        $this->renderForm(null);
    }

    public function store(Request $request): void
    {
        Auth::require(Auth::CAP_ARTICLE_CREATE);
        $this->assertToken($request);

        $id = ArticleService::create($this->payload($request));

        ArticleService::syncTags($id, $this->splitList($request->text('etiquetas')));
        ArticleService::syncTopics($id, $this->splitList($request->text('temas')));

        $this->flash('Pieza creada. Su URL permanente será /noticia/' . (Article::findById($id)['slug'] ?? ''));
        Response::redirect('/panel/noticias/' . $id);
    }

    public function edit(Request $request, array $params): void
    {
        Auth::requireLogin();

        $article = Article::findById((int) $params['id']);
        if ($article === null) {
            $this->flash('Esa pieza no existe.', 'error');
            Response::redirect('/panel/noticias');
        }

        if (!Auth::canEditArticle($article) && !Auth::can(Auth::CAP_ARTICLE_EDIT_ANY)) {
            Auth::require(Auth::CAP_ARTICLE_EDIT_ANY);
        }

        $this->renderForm($article);
    }

    public function update(Request $request, array $params): void
    {
        $article = $this->authorizeEdit($params);
        $this->assertToken($request);

        ArticleService::update((int) $article['id'], $this->payload($request), $request->text('nota_cambio') ?: null);
        ArticleService::syncTags((int) $article['id'], $this->splitList($request->text('etiquetas')));
        ArticleService::syncTopics((int) $article['id'], $this->splitList($request->text('temas')));

        $this->flash('Cambios guardados. La URL no cambió.');
        Response::redirect('/panel/noticias/' . $article['id']);
    }

    /** Guardado automatico del editor. Responde JSON, no redirige. */
    public function autosave(Request $request, array $params): void
    {
        if (!Auth::check() || !Csrf::verify($request->text('_token'))) {
            Response::json(['ok' => false], 419);
            return;
        }

        $article = Article::findById((int) $params['id']);
        if ($article === null || !Auth::canEditArticle($article) && !Auth::can(Auth::CAP_ARTICLE_EDIT_ANY)) {
            Response::json(['ok' => false], 403);
            return;
        }

        ArticleService::update((int) $article['id'], $this->payload($request), 'Guardado automático');
        Response::json(['ok' => true, 'hora' => Dates::longWithTime(Dates::nowUtc())]);
    }

    public function publish(Request $request, array $params): void
    {
        Auth::require(Auth::CAP_ARTICLE_PUBLISH);
        $this->assertToken($request);

        $id = (int) $params['id'];
        ArticleService::publish($id);

        $article = Article::findById($id);
        $this->flash('Publicada en /noticia/' . ($article['slug'] ?? '') . '. Esa dirección ya es permanente.');
        Response::redirect('/panel/noticias/' . $id);
    }

    public function schedule(Request $request, array $params): void
    {
        Auth::require(Auth::CAP_ARTICLE_PUBLISH);
        $this->assertToken($request);

        $utc = Dates::toUtc($request->text('programar_para'));
        if ($utc === null) {
            $this->flash('Esa fecha no es válida.', 'error');
            Response::redirect('/panel/noticias/' . (int) $params['id']);
        }

        ArticleService::schedule((int) $params['id'], $utc);
        $this->flash('Programada para ' . Dates::longWithTime($utc) . '.');
        Response::redirect('/panel/noticias/' . (int) $params['id']);
    }

    public function archive(Request $request, array $params): void
    {
        Auth::require(Auth::CAP_ARTICLE_ARCHIVE);
        $this->assertToken($request);

        ArticleService::archive((int) $params['id'], $request->text('motivo'));
        $this->flash('Archivada. Sale de portada pero sigue en su URL, en el buscador y en el archivo.');
        Response::redirect('/panel/noticias/' . (int) $params['id']);
    }

    public function unarchive(Request $request, array $params): void
    {
        Auth::require(Auth::CAP_ARTICLE_ARCHIVE);
        $this->assertToken($request);

        ArticleService::unarchive((int) $params['id']);
        $this->flash('Vuelve a estar activa en los listados.');
        Response::redirect('/panel/noticias/' . (int) $params['id']);
    }

    public function withdraw(Request $request, array $params): void
    {
        Auth::require(Auth::CAP_ARTICLE_WITHDRAW);
        $this->assertToken($request);

        try {
            ArticleService::withdraw((int) $params['id'], $request->text('motivo'), $request->bool('noindex'));
            $this->flash('Retirada. La página permanece y explica el motivo.');
        } catch (\Throwable $e) {
            $this->flash($e->getMessage(), 'error');
        }

        Response::redirect('/panel/noticias/' . (int) $params['id']);
    }

    public function correct(Request $request, array $params): void
    {
        $article = $this->authorizeEdit($params);
        $this->assertToken($request);

        if (trim($request->text('motivo')) === '') {
            $this->flash('Una corrección exige un motivo.', 'error');
            Response::redirect('/panel/noticias/' . $article['id']);
        }

        ArticleService::addCorrection(
            (int) $article['id'],
            $request->text('tipo_correccion', 'actualizacion'),
            $request->text('motivo'),
            $request->text('detalle'),
            !$request->bool('privada')
        );

        $this->flash('Corrección registrada. Queda visible en la pieza con su fecha y motivo.');
        Response::redirect('/panel/noticias/' . $article['id']);
    }

    /** Cambio excepcional de URL. Crea la redireccion 301 automaticamente. */
    public function changeSlug(Request $request, array $params): void
    {
        Auth::require(Auth::CAP_REDIRECT_MANAGE);
        $this->assertToken($request);

        $motivo = $request->text('motivo');
        if (trim($motivo) === '') {
            $this->flash('Cambiar una URL publicada exige un motivo registrado.', 'error');
            Response::redirect('/panel/noticias/' . (int) $params['id']);
        }

        try {
            $nuevo = ArticleService::changeSlug((int) $params['id'], $request->text('slug'), $motivo);
            $this->flash('Nueva URL: /noticia/' . $nuevo . '. La anterior redirige con 301 y queda reservada para siempre.');
        } catch (\Throwable $e) {
            $this->flash($e->getMessage(), 'error');
        }

        Response::redirect('/panel/noticias/' . (int) $params['id']);
    }

    /** Eliminacion definitiva: rol superior y confirmacion reforzada. */
    public function destroy(Request $request, array $params): void
    {
        Auth::require(Auth::CAP_ARTICLE_DESTROY);
        $this->assertToken($request);

        try {
            ArticleService::destroy((int) $params['id'], $request->text('motivo'), $request->text('confirmacion'));
            $this->flash('Pieza eliminada de forma definitiva. La acción quedó registrada en auditoría y su URL nunca se reutilizará.');
            Response::redirect('/panel/noticias');
        } catch (\Throwable $e) {
            $this->flash($e->getMessage(), 'error');
            Response::redirect('/panel/noticias/' . (int) $params['id']);
        }
    }

    public function attachVideo(Request $request, array $params): void
    {
        $article = $this->authorizeEdit($params);
        $this->assertToken($request);

        $videoId = $request->int('video_id', 0);
        if ($videoId > 0 && Video::findById($videoId) !== null) {
            ArticleService::attachVideo(
                (int) $article['id'],
                $videoId,
                array_key_exists($request->text('rol'), Video::ROLES) ? $request->text('rol') : 'resumen',
                (int) $request->int('orden', 0)
            );
            $this->flash('Video relacionado.');
        } else {
            $this->flash('Ese video no existe.', 'error');
        }

        Response::redirect('/panel/noticias/' . $article['id']);
    }

    public function detachVideo(Request $request, array $params): void
    {
        $article = $this->authorizeEdit($params);
        $this->assertToken($request);

        ArticleService::detachVideo((int) $article['id'], (int) $request->int('video_id', 0));
        $this->flash('Video desvinculado. El video sigue existiendo en su propia ficha.');
        Response::redirect('/panel/noticias/' . $article['id']);
    }

    public function attachLive(Request $request, array $params): void
    {
        $article = $this->authorizeEdit($params);
        $this->assertToken($request);

        $liveId = $request->int('live_id', 0);
        if ($liveId > 0 && Live::findById($liveId) !== null) {
            ArticleService::attachLive((int) $article['id'], $liveId, $request->text('momento', 'antes'));
            $this->flash('Live relacionado.');
        } else {
            $this->flash('Ese Live no existe.', 'error');
        }

        Response::redirect('/panel/noticias/' . $article['id']);
    }

    // -----------------------------------------------------------------

    private function renderForm(?array $article): void
    {
        $id    = $article === null ? 0 : (int) $article['id'];
        $pulso = $id === 0 ? null : \App\Models\Poll::forArticle($id);

        Response::securityHeaders(false);
        Response::html(View::render('admin/noticia-editor', [
            'titulo'      => ($article === null ? 'Nueva pieza' : 'Editar: ' . $article['title']) . ' · Panel',
            'noindex'     => true,
            'articulo'    => $article,
            'bloques'     => $article === null ? [] : BlockRenderer::decode($article['body_blocks']),
            'categorias'  => Taxonomy::categories(false),
            'autores'     => Taxonomy::authors(false),
            'expedientes' => Dossier::all(100),
            'medios'      => Database::all('SELECT id, storage_path, alt_text, original_name FROM media_assets WHERE kind = "imagen" AND deleted_at IS NULL ORDER BY id DESC LIMIT 60'),
            'etiquetas'   => $id === 0 ? [] : Article::tags($id),
            'temas'       => $id === 0 ? [] : Article::topics($id),
            'correcciones' => $id === 0 ? [] : Article::corrections($id),
            'revisiones'  => $id === 0 ? [] : Article::revisions($id),
            'videosLigados' => $id === 0 ? [] : Article::videos($id),
            'livesLigados'  => $id === 0 ? [] : Article::lives($id),
            'videosDisponibles' => Database::all('SELECT id, title, video_type, status FROM videos WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 100'),
            'livesDisponibles'  => Database::all('SELECT id, title, status, starts_at FROM lives ORDER BY starts_at DESC LIMIT 60'),
            'tiposBloque' => BlockRenderer::TYPES,

            // Expediente de la pieza.
            'pulso'           => $pulso,
            'pulsoOpciones'   => $pulso === null ? [] : \App\Models\Poll::options((int) $pulso['id']),
            'pulsoResultados' => $pulso === null ? null : \App\Models\Poll::results((int) $pulso['id']),
            'fuentes'        => $id === 0 ? [] : Article::sources($id),
            'catalogoFuentes' => Taxonomy::sources(),
            'cronologia'     => $id === 0 ? [] : Article::timeline($id),
            'actores'        => $id === 0 ? [] : Article::actors($id),
            'catalogoActores' => Database::all('SELECT id, name, actor_type FROM actors ORDER BY name LIMIT 200'),
            'impactos'       => $id === 0 ? [] : Article::impacts($id),
            'herencia'       => $id === 0 ? null : Article::lineage($id),
            'relacionadas'   => $id === 0 ? [] : EditorialService::relacionadasManuales($id),
            // El id va como marcador, no concatenado: la regla del proyecto
            // es que ninguna consulta interpole datos, aunque el valor ya
            // venga casteado. Hay una prueba que lo verifica.
            'piezasDisponibles' => Database::all(
                'SELECT id, title, status FROM articles
                  WHERE deleted_at IS NULL AND id <> :actual
                  ORDER BY COALESCE(published_at, updated_at) DESC LIMIT 150',
                ['actual' => $id]
            ),
            'preguntasDisponibles' => Database::all(
                'SELECT id, body FROM community_questions
                  WHERE status IN ("aprobada","seleccionada","respondida")
                  ORDER BY created_at DESC LIMIT 100'
            ),
            'perfilesImpacto' => EditorialService::PERFILES,
            'certezas'        => EditorialService::CERTEZAS,
            'tiposRelacion'   => EditorialService::RELACIONES,
        ], 'layouts/admin'));
    }

    private function payload(Request $request): array
    {
        return [
            'title'           => $request->text('titulo'),
            'slug'            => $request->text('slug_inicial'),
            'subtitle'        => $request->text('subtitulo'),
            'lead_question'   => $request->text('pregunta_principal'),
            'summary'         => $request->text('resumen'),
            'summary_60s'     => $request->text('resumen_60s'),
            'summary_5min'    => $request->text('resumen_5min'),
            'body_blocks'     => $request->text('bloques_json', '[]'),
            'editorial_type'  => $request->text('tipo_editorial', 'noticia'),
            'category_id'     => $request->int('categoria'),
            'author_id'       => $request->int('autor'),
            'editor_id'       => $request->int('editor'),
            'dossier_id'      => $request->int('expediente'),
            'facts_confirmed' => $request->text('confirmado'),
            'facts_probable'  => $request->text('probable'),
            'facts_unknown'   => $request->text('desconocido'),
            'perspective_a_title' => $request->text('perspectiva_a_titulo'),
            'perspective_a_body'  => $request->text('perspectiva_a_cuerpo'),
            'perspective_b_title' => $request->text('perspectiva_b_titulo'),
            'perspective_b_body'  => $request->text('perspectiva_b_cuerpo'),
            'max_opinion'     => $request->text('opinion_max'),
            'legacy_question' => $request->text('pregunta_viva'),
            'hero_media_id'   => $request->int('imagen_principal'),
            'hero_alt'        => $request->text('imagen_alt'),
            'seo_title'       => $request->text('seo_titulo'),
            'seo_description' => $request->text('seo_descripcion'),
            'social_title'    => $request->text('social_titulo'),
            'social_description' => $request->text('social_descripcion'),
            'is_demo'         => $request->bool('es_demo'),
        ];
    }

    private function authorizeEdit(array $params): array
    {
        Auth::requireLogin();

        $article = Article::findById((int) $params['id']);
        if ($article === null) {
            $this->flash('Esa pieza no existe.', 'error');
            Response::redirect('/panel/noticias');
        }

        if (!Auth::canEditArticle($article) && !Auth::can(Auth::CAP_ARTICLE_EDIT_ANY)) {
            Auth::require(Auth::CAP_ARTICLE_EDIT_ANY);
        }

        return $article;
    }

    private function assertToken(Request $request): void
    {
        if (!Csrf::verify($request->text('_token'))) {
            $this->flash('La sesión expiró. Vuelve a intentarlo.', 'error');
            Response::redirect('/panel/noticias');
        }
    }

    private function splitList(string $raw): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $v): bool => $v !== ''));
    }

    private function flash(string $message, string $type = 'ok'): void
    {
        $_SESSION['_flash'] = ['mensaje' => $message, 'tipo' => $type];
    }
}
