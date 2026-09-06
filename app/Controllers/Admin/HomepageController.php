<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Middleware\Auth;
use App\Models\Homepage;
use App\Services\AuditService;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/**
 * Curaduria de portada.
 *
 * Vaciar una zona NO borra ninguna noticia: solo retira la seleccion.
 * Ese es el punto entero de tener homepage_slots aparte.
 */
final class HomepageController
{
    public function __construct(private readonly array $config)
    {
    }

    public function index(Request $request): void
    {
        Auth::require(Auth::CAP_HOMEPAGE_MANAGE);

        $zonas = [];
        foreach (array_keys(Homepage::ZONES) as $zona) {
            $zonas[$zona] = Homepage::slots($zona);
        }

        Response::securityHeaders(false);
        Response::html(View::render('admin/portada', [
            'titulo'   => 'Portada · Panel',
            'noindex'  => true,
            'zonas'    => $zonas,
            'etiquetasZona' => Homepage::ZONES,
            'articulos' => Database::all(
                'SELECT id, title, status, published_at FROM articles
                  WHERE deleted_at IS NULL AND status IN ("publicada","actualizada")
                  ORDER BY published_at DESC LIMIT 120'
            ),
            'videos'   => Database::all('SELECT id, title FROM videos WHERE deleted_at IS NULL AND status = "publicado" ORDER BY published_at DESC LIMIT 60'),
            'expedientes' => Database::all('SELECT id, title FROM dossiers ORDER BY updated_at DESC LIMIT 60'),
            'lives'    => Database::all('SELECT id, title FROM lives ORDER BY starts_at DESC LIMIT 40'),
            'pulsos'   => Database::all('SELECT id, question FROM polls ORDER BY id DESC LIMIT 40'),
        ], 'layouts/admin'));
    }

    public function update(Request $request): void
    {
        Auth::require(Auth::CAP_HOMEPAGE_MANAGE);

        if (!Csrf::verify($request->text('_token'))) {
            $this->flash('La sesión expiró.', 'error');
            Response::redirect('/panel/portada');
        }

        $accion = $request->text('accion');
        $zona   = $request->text('zona');

        if (!array_key_exists($zona, Homepage::ZONES)) {
            $this->flash('Zona no válida.', 'error');
            Response::redirect('/panel/portada');
        }

        if ($accion === 'quitar') {
            Database::run('DELETE FROM homepage_slots WHERE id = :id', ['id' => (int) $request->int('slot', 0)]);
            AuditService::log('portada.quitar', 'portada', null, $zona);
            $this->flash('Retirado de la portada. La pieza sigue publicada en su URL, en el buscador y en el archivo.');
            Response::redirect('/panel/portada');
        }

        Database::insert('homepage_slots', [
            'zone'           => $zona,
            'position'       => (int) $request->int('posicion', 0),
            'article_id'     => $request->int('articulo'),
            'video_id'       => $request->int('video'),
            'live_id'        => $request->int('live'),
            'dossier_id'     => $request->int('expediente'),
            'poll_id'        => $request->int('pulso'),
            'override_title' => $request->text('titulo_alterno') ?: null,
            'updated_by'     => Auth::id(),
        ]);

        AuditService::log('portada.seleccionar', 'portada', null, $zona);
        $this->flash('Portada actualizada.');
        Response::redirect('/panel/portada');
    }

    private function flash(string $message, string $type = 'ok'): void
    {
        $_SESSION['_flash'] = ['mensaje' => $message, 'tipo' => $type];
    }
}
