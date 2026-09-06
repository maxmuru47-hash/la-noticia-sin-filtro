<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Middleware\Auth;
use App\Models\Live;
use App\Services\AuditService;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\Dates;
use App\Support\Paginator;
use App\Support\Request;
use App\Support\Response;
use App\Support\Str;
use App\Support\View;

/**
 * Lives. La fecha se escribe en hora de Caracas y se guarda en UTC. Una
 * sola fuente de verdad, tal como exige el documento maestro.
 */
final class LiveController
{
    public function __construct(private readonly array $config)
    {
    }

    public function index(Request $request): void
    {
        Auth::require(Auth::CAP_LIVE_MANAGE);

        $perPage = $this->config['pagination']['admin'];
        $page    = Paginator::resolvePage($request->input('pagina'));
        $result  = Live::forAdmin($perPage, ($page - 1) * $perPage);

        Response::securityHeaders(false);
        Response::html(View::render('admin/lives', [
            'titulo'    => 'Lives · Panel',
            'noindex'   => true,
            'lives'     => $result['items'],
            'paginador' => new Paginator($result['total'], $perPage, $page, '/panel/lives', []),
        ], 'layouts/admin'));
    }

    public function create(Request $request): void
    {
        Auth::require(Auth::CAP_LIVE_MANAGE);
        $this->renderForm(null);
    }

    public function store(Request $request): void
    {
        Auth::require(Auth::CAP_LIVE_MANAGE);
        $this->assertToken($request);

        $title = $request->text('titulo');
        if ($title === '') {
            $this->flash('El Live necesita un título.', 'error');
            Response::redirect('/panel/lives/nuevo');
        }

        $data = $this->payload($request);
        $data['uuid'] = Str::uuid();
        $data['slug'] = $this->uniqueSlug($title);

        $id = Database::insert('lives', $data);
        AuditService::log('live.crear', 'live', $id, $title);

        $this->flash('Live creado.');
        Response::redirect('/panel/lives/' . $id);
    }

    public function edit(Request $request, array $params): void
    {
        Auth::require(Auth::CAP_LIVE_MANAGE);

        $live = Live::findById((int) $params['id']);
        if ($live === null) {
            $this->flash('Ese Live no existe.', 'error');
            Response::redirect('/panel/lives');
        }

        $this->renderForm($live);
    }

    public function update(Request $request, array $params): void
    {
        Auth::require(Auth::CAP_LIVE_MANAGE);
        $this->assertToken($request);

        $id = (int) $params['id'];
        Database::update('lives', $this->payload($request), 'id = :id', ['id' => $id]);
        AuditService::log('live.editar', 'live', $id, $request->text('titulo'));

        $this->flash('Live actualizado.');
        Response::redirect('/panel/lives/' . $id);
    }

    private function renderForm(?array $live): void
    {
        $id = $live === null ? 0 : (int) $live['id'];

        Response::securityHeaders(false);
        Response::html(View::render('admin/live-editor', [
            'titulo'    => ($live === null ? 'Nuevo Live' : 'Editar: ' . $live['title']) . ' · Panel',
            'noindex'   => true,
            'live'      => $live,
            'preguntas' => $id === 0 ? [] : Live::questions($id, ['pendiente', 'aprobada', 'seleccionada', 'respondida']),
            'antes'     => $id === 0 ? [] : Live::articles($id, 'antes'),
            'despues'   => $id === 0 ? [] : Live::articles($id, 'despues'),
            'grabaciones' => Database::all('SELECT id, title FROM videos WHERE deleted_at IS NULL AND video_type = "live" ORDER BY id DESC LIMIT 50'),
        ], 'layouts/admin'));
    }

    private function payload(Request $request): array
    {
        return [
            'title'              => $request->text('titulo'),
            'lead_question'      => $request->text('pregunta_principal') ?: null,
            'summary'            => $request->text('resumen') ?: null,
            'status'             => array_key_exists($request->text('estado'), Live::STATUSES) ? $request->text('estado') : 'anunciado',
            'starts_at'          => Dates::toUtc($request->text('empieza')),
            'ends_at'            => Dates::toUtc($request->text('termina')),
            'timezone'           => $this->config['app']['timezone'],
            'guests'             => $request->text('invitados') ?: null,
            'stream_url'         => $this->safeUrl($request->text('url_transmision')),
            'recording_video_id' => $request->int('grabacion'),
            'aftermath'          => $request->text('resumen_posterior') ?: null,
            'pending_matters'    => $request->text('pendientes') ?: null,
            'is_demo'            => $request->bool('es_demo') ? 1 : 0,
        ];
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title);
        $slug = $base;
        $n    = 2;
        while (Database::value('SELECT id FROM lives WHERE slug = :slug', ['slug' => $slug]) !== null) {
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
            Response::redirect('/panel/lives');
        }
    }

    private function flash(string $message, string $type = 'ok'): void
    {
        $_SESSION['_flash'] = ['mensaje' => $message, 'tipo' => $type];
    }
}
