<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Middleware\Auth;
use App\Models\Article;
use App\Services\EditorialService;
use App\Support\Csrf;
use App\Support\Request;
use App\Support\Response;

/**
 * Expediente de una pieza: pulso, fuentes, cronología, actores,
 * consecuencias, herencia, relaciones y restauración de versiones.
 *
 * Todas las acciones comparten la misma puerta: sesión válida, token CSRF,
 * permiso sobre ESA pieza concreta, y todo pasa por EditorialService.
 */
final class EditorialController
{
    public function __construct(private readonly array $config)
    {
    }

    // --- PULSO -------------------------------------------------------

    public function guardarPulso(Request $request, array $params): void
    {
        $articulo = $this->autorizar($params, $request);

        $this->intentar(
            fn () => EditorialService::guardarPulso(
                (int) $articulo['id'],
                $request->text('pulso_pregunta'),
                preg_split('/\r?\n/', $request->text('pulso_opciones')) ?: [],
                $request->text('pulso_metodologia') ?: null,
                $request->bool('pulso_abierto')
            ),
            'Pulso guardado. Los resultados se calculan de los votos y no se pueden editar.',
            (int) $articulo['id']
        );
    }

    // --- FUENTES -----------------------------------------------------

    public function adjuntarFuente(Request $request, array $params): void
    {
        $articulo = $this->autorizar($params, $request);

        $this->intentar(
            fn () => EditorialService::adjuntarFuente((int) $articulo['id'], [
                'fuente_id' => $request->int('fuente_id', 0),
                'titulo'    => $request->text('fuente_titulo'),
                'editor'    => $request->text('fuente_editor'),
                'url'       => $request->text('fuente_url'),
                'tipo'      => $request->text('fuente_tipo'),
                'fecha'     => $request->text('fuente_fecha'),
                'notas'     => $request->text('fuente_notas'),
                'certeza'   => $request->text('fuente_certeza'),
                'orden'     => $request->int('fuente_orden', 0),
            ]),
            'Fuente adjuntada.',
            (int) $articulo['id']
        );
    }

    public function quitarFuente(Request $request, array $params): void
    {
        $articulo = $this->autorizar($params, $request);

        $this->intentar(
            fn () => EditorialService::quitarFuente((int) $articulo['id'], (int) $request->int('fuente_id', 0)),
            'Fuente desvinculada. Sigue en el catálogo de fuentes.',
            (int) $articulo['id']
        );
    }

    // --- CRONOLOGÍA --------------------------------------------------

    public function anadirEvento(Request $request, array $params): void
    {
        $articulo = $this->autorizar($params, $request);

        $this->intentar(
            fn () => EditorialService::anadirEvento([
                'articulo'   => (int) $articulo['id'],
                'expediente' => $request->int('evento_expediente'),
                'fecha'      => $request->text('evento_fecha'),
                'hora'       => $request->text('evento_hora'),
                'titulo'     => $request->text('evento_titulo'),
                'detalle'    => $request->text('evento_detalle'),
                'certeza'    => $request->text('evento_certeza'),
                'fuente'     => $request->int('evento_fuente'),
                'orden'      => $request->int('evento_orden', 0),
            ]),
            'Evento añadido a la cronología.',
            (int) $articulo['id']
        );
    }

    public function quitarEvento(Request $request, array $params): void
    {
        $articulo = $this->autorizar($params, $request);

        $this->intentar(
            fn () => EditorialService::quitarEvento((int) $request->int('evento_id', 0)),
            'Evento eliminado de la cronología.',
            (int) $articulo['id']
        );
    }

    // --- ACTORES -----------------------------------------------------

    public function adjuntarActor(Request $request, array $params): void
    {
        $articulo = $this->autorizar($params, $request);

        $this->intentar(
            fn () => EditorialService::adjuntarActor((int) $articulo['id'], [
                'actor_id'    => $request->int('actor_id', 0),
                'nombre'      => $request->text('actor_nombre'),
                'tipo'        => $request->text('actor_tipo'),
                'descripcion' => $request->text('actor_descripcion'),
                'papel'       => $request->text('actor_papel'),
                'orden'       => $request->int('actor_orden', 0),
            ]),
            'Actor añadido al mapa.',
            (int) $articulo['id']
        );
    }

    public function quitarActor(Request $request, array $params): void
    {
        $articulo = $this->autorizar($params, $request);

        $this->intentar(
            fn () => EditorialService::quitarActor((int) $articulo['id'], (int) $request->int('actor_id', 0)),
            'Actor desvinculado. Sigue en el catálogo.',
            (int) $articulo['id']
        );
    }

    // --- CONSECUENCIAS -----------------------------------------------

    public function anadirImpacto(Request $request, array $params): void
    {
        $articulo = $this->autorizar($params, $request);

        $this->intentar(
            fn () => EditorialService::anadirImpacto((int) $articulo['id'], [
                'perfil'  => $request->text('impacto_perfil'),
                'titular' => $request->text('impacto_titular'),
                'detalle' => $request->text('impacto_detalle'),
                'certeza' => $request->text('impacto_certeza'),
                'orden'   => $request->int('impacto_orden', 0),
            ]),
            'Consecuencia añadida.',
            (int) $articulo['id']
        );
    }

    public function quitarImpacto(Request $request, array $params): void
    {
        $articulo = $this->autorizar($params, $request);

        $this->intentar(
            fn () => EditorialService::quitarImpacto((int) $request->int('impacto_id', 0)),
            'Consecuencia eliminada.',
            (int) $articulo['id']
        );
    }

    // --- HERENCIA Y RELACIONES ---------------------------------------

    public function fijarHerencia(Request $request, array $params): void
    {
        $articulo = $this->autorizar($params, $request);

        $this->intentar(
            fn () => EditorialService::fijarHerencia(
                (int) $articulo['id'],
                $request->int('herencia_pieza'),
                $request->int('herencia_pregunta'),
                $request->text('herencia_nota') ?: null
            ),
            'Herencia registrada. El lector verá de qué nació esta pieza.',
            (int) $articulo['id']
        );
    }

    public function relacionar(Request $request, array $params): void
    {
        $articulo = $this->autorizar($params, $request);

        $this->intentar(
            fn () => EditorialService::relacionar(
                (int) $articulo['id'],
                (int) $request->int('relacionada_id', 0),
                $request->text('relacion', 'relacionada'),
                (int) $request->int('relacionada_orden', 0)
            ),
            'Piezas relacionadas.',
            (int) $articulo['id']
        );
    }

    public function desrelacionar(Request $request, array $params): void
    {
        $articulo = $this->autorizar($params, $request);

        $this->intentar(
            fn () => EditorialService::desrelacionar((int) $articulo['id'], (int) $request->int('relacionada_id', 0)),
            'Relación eliminada.',
            (int) $articulo['id']
        );
    }

    // --- VERSIONES ---------------------------------------------------

    public function restaurarVersion(Request $request, array $params): void
    {
        $articulo = $this->autorizar($params, $request);

        // Restaurar reescribe el contenido publicado: exige el mismo
        // permiso que editar cualquier pieza, no solo la propia.
        Auth::require(Auth::CAP_ARTICLE_EDIT_ANY);

        $this->intentar(
            fn () => EditorialService::restaurarRevision((int) $articulo['id'], (int) $request->int('revision', 0)),
            'Versión restaurada. El cambio quedó registrado como una versión nueva.',
            (int) $articulo['id']
        );
    }

    // -----------------------------------------------------------------

    /** Comprueba sesión, token y permiso sobre esta pieza concreta. */
    private function autorizar(array $params, Request $request): array
    {
        Auth::requireLogin();

        if (!Csrf::verify($request->text('_token'))) {
            $this->flash('La sesión expiró. Vuelve a intentarlo.', 'error');
            Response::redirect('/panel/noticias');
        }

        $articulo = Article::findById((int) $params['id']);
        if ($articulo === null) {
            $this->flash('Esa pieza no existe.', 'error');
            Response::redirect('/panel/noticias');
        }

        if (!Auth::canEditArticle($articulo) && !Auth::can(Auth::CAP_ARTICLE_EDIT_ANY)) {
            Auth::require(Auth::CAP_ARTICLE_EDIT_ANY);
        }

        return $articulo;
    }

    private function intentar(callable $accion, string $exito, int $articleId): void
    {
        try {
            $accion();
            $this->flash($exito);
        } catch (\Throwable $e) {
            $this->flash($e->getMessage(), 'error');
        }

        Response::redirect('/panel/noticias/' . $articleId . '#expediente');
    }

    private function flash(string $mensaje, string $tipo = 'ok'): void
    {
        $_SESSION['_flash'] = ['mensaje' => $mensaje, 'tipo' => $tipo];
    }
}
