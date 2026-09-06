<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Middleware\Auth;
use App\Models\Video;
use App\Services\AuditService;
use App\Services\SearchService;
use App\Services\UploadService;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\Dates;
use App\Support\Paginator;
use App\Support\Request;
use App\Support\Response;
use App\Support\Str;
use App\Support\View;

final class VideoController
{
    public function __construct(private readonly array $config)
    {
    }

    public function index(Request $request): void
    {
        Auth::require(Auth::CAP_VIDEO_MANAGE);

        $perPage = $this->config['pagination']['admin'];
        $page    = Paginator::resolvePage($request->input('pagina'));
        $result  = Video::forAdmin($perPage, ($page - 1) * $perPage, [
            'status' => $request->text('estado'),
            'type'   => $request->text('tipo'),
        ]);

        Response::securityHeaders(false);
        Response::html(View::render('admin/videos', [
            'titulo'    => 'Videos · Panel',
            'noindex'   => true,
            'videos'    => $result['items'],
            'paginador' => new Paginator($result['total'], $perPage, $page, '/panel/videos', []),
        ], 'layouts/admin'));
    }

    public function create(Request $request): void
    {
        Auth::require(Auth::CAP_VIDEO_MANAGE);
        $this->renderForm(null);
    }

    public function store(Request $request): void
    {
        Auth::require(Auth::CAP_VIDEO_MANAGE);
        $this->assertToken($request);

        $title = $request->text('titulo');
        if ($title === '') {
            $this->flash('El video necesita un título.', 'error');
            Response::redirect('/panel/videos/nuevo');
        }

        $data = $this->payload($request);
        $data['uuid'] = Str::uuid();
        $data['slug'] = $this->uniqueSlug($title);

        try {
            $this->handleFiles($request, $data);
        } catch (\Throwable $e) {
            $this->flash($e->getMessage(), 'error');
            Response::redirect('/panel/videos/nuevo');
        }

        $id = Database::insert('videos', $data);
        $this->saveTranscript($request, $id);
        $this->saveChapters($request, $id);

        AuditService::log('video.crear', 'video', $id, $title);
        $this->flash('Video creado.');
        Response::redirect('/panel/videos/' . $id);
    }

    public function edit(Request $request, array $params): void
    {
        Auth::require(Auth::CAP_VIDEO_MANAGE);

        $video = Video::findById((int) $params['id']);
        if ($video === null) {
            $this->flash('Ese video no existe.', 'error');
            Response::redirect('/panel/videos');
        }

        $this->renderForm($video);
    }

    public function update(Request $request, array $params): void
    {
        Auth::require(Auth::CAP_VIDEO_MANAGE);
        $this->assertToken($request);

        $id    = (int) $params['id'];
        $video = Video::findById($id);
        if ($video === null) {
            $this->flash('Ese video no existe.', 'error');
            Response::redirect('/panel/videos');
        }

        $data = $this->payload($request);

        try {
            $this->handleFiles($request, $data);
        } catch (\Throwable $e) {
            $this->flash($e->getMessage(), 'error');
            Response::redirect('/panel/videos/' . $id);
        }

        Database::update('videos', $data, 'id = :id', ['id' => $id]);
        $this->saveTranscript($request, $id);
        $this->saveChapters($request, $id);

        AuditService::log('video.editar', 'video', $id, $data['title']);

        // Un cambio en la transcripcion cambia lo que el buscador encuentra.
        foreach (Video::articles($id) as $article) {
            SearchService::reindexArticle((int) $article['id']);
        }

        $this->flash('Video actualizado. Se reindexaron las noticias relacionadas.');
        Response::redirect('/panel/videos/' . $id);
    }

    private function renderForm(?array $video): void
    {
        $id = $video === null ? 0 : (int) $video['id'];

        Response::securityHeaders(false);
        Response::html(View::render('admin/video-editor', [
            'titulo'        => ($video === null ? 'Nuevo video' : 'Editar: ' . $video['title']) . ' · Panel',
            'noindex'       => true,
            'video'         => $video,
            'capitulos'     => $id === 0 ? [] : Video::chapters($id),
            'transcripcion' => $id === 0 ? null : Video::transcript($id),
            'noticias'      => $id === 0 ? [] : Video::articles($id),
        ], 'layouts/admin'));
    }

    private function payload(Request $request): array
    {
        $provider = in_array($request->text('proveedor'), ['propio', 'youtube', 'tiktok', 'instagram', 'otro'], true)
            ? $request->text('proveedor') : 'propio';

        $type = array_key_exists($request->text('tipo'), Video::TYPES) ? $request->text('tipo') : 'resumen';

        return [
            'title'             => $request->text('titulo'),
            'description'       => $request->text('descripcion') ?: null,
            'video_type'        => $type,
            'provider'          => $provider,
            'provider_video_id' => $request->text('proveedor_id') ?: null,
            'source_url'        => $this->safeUrl($request->text('url_origen')),
            'embed_url'         => $this->safeUrl($request->text('url_embebido')),
            'duration_seconds'  => $request->int('duracion'),
            'orientation'       => in_array($request->text('orientacion'), ['horizontal', 'vertical', 'cuadrada'], true)
                ? $request->text('orientacion') : 'horizontal',
            'aspect_ratio'      => $request->text('relacion_aspecto') ?: null,
            'text_alternative'  => $request->text('alternativa_textual') ?: null,
            'transcript_status' => in_array($request->text('estado_transcripcion'), ['ninguna', 'pendiente', 'borrador', 'revisada'], true)
                ? $request->text('estado_transcripcion') : 'ninguna',
            'status'            => in_array($request->text('estado'), ['borrador', 'publicado', 'archivado'], true)
                ? $request->text('estado') : 'borrador',
            // Solo el hero y el fondo editorial pueden reproducirse solos,
            // y siempre sin audio. Nunca un video editorial.
            'autoplay_allowed'  => in_array($type, ['hero', 'fondo'], true) && $request->bool('autoplay') ? 1 : 0,
            'published_at'      => Dates::toUtc($request->text('publicado_en')) ?? ($request->text('estado') === 'publicado' ? Dates::nowUtc() : null),
            'is_demo'           => $request->bool('es_demo') ? 1 : 0,
        ];
    }

    private function handleFiles(Request $request, array &$data): void
    {
        $service = new UploadService($this->config);

        if (!empty($request->files['archivo_video']['name'] ?? '')) {
            $stored               = $service->storeVideo($request->files['archivo_video']);
            $data['storage_path'] = $stored['ruta'];
            $data['bytes']        = $stored['bytes'];
        }

        if (!empty($request->files['poster']['name'] ?? '')) {
            $mediaId = $service->storeImage($request->files['poster'], ['alt' => $request->text('titulo')]);
            $path    = Database::value('SELECT storage_path FROM media_assets WHERE id = :id', ['id' => $mediaId]);
            $data['poster_path']    = $path;
            $data['thumbnail_path'] = $path;
        }

        if (!empty($request->files['subtitulos']['name'] ?? '')) {
            $data['captions_path'] = $service->storeCaptions($request->files['subtitulos']);
        }
    }

    private function saveTranscript(Request $request, int $videoId): void
    {
        $content = trim($request->text('transcripcion'));
        if ($content === '') {
            return;
        }

        Database::run(
            'INSERT INTO video_transcripts (video_id, language, content, reviewed_by, reviewed_at)
             VALUES (:id, "es", :content, :user, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE content = VALUES(content), reviewed_by = VALUES(reviewed_by), reviewed_at = VALUES(reviewed_at)',
            ['id' => $videoId, 'content' => $content, 'user' => Auth::id()]
        );
    }

    /** Capitulos en texto plano: "00:00 Titulo" por linea. */
    private function saveChapters(Request $request, int $videoId): void
    {
        $raw = trim($request->text('capitulos'));
        if ($raw === '') {
            return;
        }

        Database::run('DELETE FROM video_chapters WHERE video_id = :id', ['id' => $videoId]);

        $position = 0;
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            if (!preg_match('/^\s*(?:(\d{1,2}):)?(\d{1,2}):(\d{2})\s+(.+)$/u', $line, $m)) {
                continue;
            }
            $seconds = ((int) ($m[1] ?: 0)) * 3600 + ((int) $m[2]) * 60 + (int) $m[3];
            Database::insert('video_chapters', [
                'video_id'  => $videoId,
                'starts_at' => $seconds,
                'title'     => mb_substr(trim($m[4]), 0, 255),
                'position'  => $position++,
            ]);
        }
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title);
        $slug = $base;
        $n    = 2;
        while (Database::value('SELECT id FROM videos WHERE slug = :slug', ['slug' => $slug]) !== null) {
            $slug = $base . '-' . $n++;
        }
        return $slug;
    }

    private function safeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        return filter_var($url, FILTER_VALIDATE_URL) && str_starts_with($url, 'https://') ? $url : null;
    }

    private function assertToken(Request $request): void
    {
        if (!Csrf::verify($request->text('_token'))) {
            $this->flash('La sesión expiró.', 'error');
            Response::redirect('/panel/videos');
        }
    }

    private function flash(string $message, string $type = 'ok'): void
    {
        $_SESSION['_flash'] = ['mensaje' => $message, 'tipo' => $type];
    }
}
