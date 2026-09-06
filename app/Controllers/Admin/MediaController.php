<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Middleware\Auth;
use App\Services\UploadService;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\Paginator;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

final class MediaController
{
    public function __construct(private readonly array $config)
    {
    }

    public function index(Request $request): void
    {
        Auth::require(Auth::CAP_MEDIA_MANAGE);

        $perPage = 24;
        $page    = Paginator::resolvePage($request->input('pagina'));
        $total   = (int) Database::value('SELECT COUNT(*) FROM media_assets WHERE deleted_at IS NULL');

        Response::securityHeaders(false);
        Response::html(View::render('admin/medios', [
            'titulo'    => 'Medios · Panel',
            'noindex'   => true,
            'medios'    => Database::all(
                'SELECT m.*, u.display_name AS subido_por
                   FROM media_assets m LEFT JOIN users u ON u.id = m.uploaded_by
                  WHERE m.deleted_at IS NULL ORDER BY m.id DESC
                  LIMIT ' . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage)
            ),
            'paginador' => new Paginator($total, $perPage, $page, '/panel/medios', []),
            'limites'   => $this->config['uploads'],
        ], 'layouts/admin'));
    }

    public function store(Request $request): void
    {
        Auth::require(Auth::CAP_MEDIA_MANAGE);

        if (!Csrf::verify($request->text('_token'))) {
            $this->flash('La sesión expiró.', 'error');
            Response::redirect('/panel/medios');
        }

        $alt = trim($request->text('alt'));
        if ($alt === '') {
            $this->flash('Toda imagen necesita texto alternativo. Es accesibilidad, no un trámite.', 'error');
            Response::redirect('/panel/medios');
        }

        try {
            $service = new UploadService($this->config);
            $service->storeImage($request->files['archivo'] ?? [], [
                'alt'       => $alt,
                'caption'   => $request->text('pie'),
                'credit'    => $request->text('credito'),
                'sintetica' => $request->bool('sintetica'),
            ]);
            $this->flash('Imagen guardada. El original quedó fuera de la carpeta pública.');
        } catch (\Throwable $e) {
            $this->flash($e->getMessage(), 'error');
        }

        Response::redirect('/panel/medios');
    }

    private function flash(string $message, string $type = 'ok'): void
    {
        $_SESSION['_flash'] = ['mensaje' => $message, 'tipo' => $type];
    }
}
