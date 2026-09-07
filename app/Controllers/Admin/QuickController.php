<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Middleware\Auth;
use App\Models\Article;
use App\Models\Taxonomy;
use App\Services\ArticleService;
use App\Support\Csrf;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;
use RuntimeException;
use Throwable;

/**
 * PUBLICACIÓN RÁPIDA
 *
 * El editor completo tiene cuarenta y cinco campos. Sirve para una pieza
 * trabajada: perspectivas, expediente, correcciones, redes. Para la noticia
 * del día es un muro, y un muro entre el periodista y el botón de publicar
 * termina en piezas que nunca se publican.
 *
 * Este camino pide cuatro cosas y ya. Todo lo demás se puede añadir después
 * abriendo la pieza en el editor completo: lo que se escribe aquí no es una
 * pieza distinta ni de peor calidad, es la misma pieza empezada por lo
 * imprescindible.
 *
 * No duplica ni una regla. Llama al mismo ArticleService que el editor
 * completo, así que la dirección permanente, el historial, la auditoría y
 * el indice del buscador funcionan igual.
 */
final class QuickController
{
    public function form(Request $request): void
    {
        Auth::require(Auth::CAP_ARTICLE_CREATE);

        $borrador = $_SESSION['_rapido_borrador'] ?? [];
        unset($_SESSION['_rapido_borrador']);

        Response::securityHeaders(false);
        Response::html(View::render('admin/rapido', [
            'titulo'        => 'Publicación rápida · Panel',
            'noindex'       => true,
            'categorias'    => Taxonomy::categories(),
            'puedePublicar' => Auth::can(Auth::CAP_ARTICLE_PUBLISH),
            'borrador'      => $borrador,
        ], 'layouts/admin'));
    }

    public function store(Request $request): void
    {
        Auth::require(Auth::CAP_ARTICLE_CREATE);
        if (!Csrf::verify($request->text('_token'))) {
            $this->avisar('La sesión expiró. Vuelve a intentarlo.', 'error');
            Response::redirect('/panel/rapido');
        }

        $titulo = trim($request->text('titulo'));
        $cuerpo = trim($request->text('cuerpo'));
        $publicarYa = $request->text('accion') === 'publicar';

        $errores = [];
        if ($titulo === '') {
            $errores[] = 'Falta el titular.';
        }
        if ($cuerpo === '') {
            $errores[] = 'Falta el cuerpo. Aunque sean dos líneas, algo tiene que decir.';
        }
        // Que la cuenta no pueda publicar no es un error de quien escribe:
        // es su permiso. Se guarda igual como borrador y se le dice. Tratarlo
        // como error le haria perder el texto por algo que no hizo mal.
        $sinPermisoParaPublicar = $publicarYa && !Auth::can(Auth::CAP_ARTICLE_PUBLISH);
        if ($sinPermisoParaPublicar) {
            $publicarYa = false;
        }

        if ($errores !== []) {
            // Se devuelve lo escrito: perder el texto por un campo vacio es
            // la forma mas rapida de que alguien no vuelva a usar el panel.
            $_SESSION['_rapido_borrador'] = [
                'titulo'    => $titulo,
                'resumen'   => $request->text('resumen'),
                'cuerpo'    => $cuerpo,
                'categoria' => $request->int('categoria'),
            ];
            $this->avisar(implode(' ', $errores), 'error');
            Response::redirect('/panel/rapido');
        }

        try {
            $id = ArticleService::create([
                'title'          => $titulo,
                'summary'        => trim($request->text('resumen')),
                'body_blocks'    => self::aBloques($cuerpo),
                'editorial_type' => 'noticia',
                'category_id'    => $request->int('categoria'),
                'author_id'      => Auth::id(),
            ]);

            if ($publicarYa) {
                ArticleService::publish($id);
            }
        } catch (Throwable $e) {
            $_SESSION['_rapido_borrador'] = [
                'titulo'    => $titulo,
                'resumen'   => $request->text('resumen'),
                'cuerpo'    => $cuerpo,
                'categoria' => $request->int('categoria'),
            ];
            $this->avisar('No se pudo guardar: ' . $e->getMessage(), 'error');
            Response::redirect('/panel/rapido');
        }

        $slug = Article::findById($id)['slug'] ?? '';

        if ($publicarYa) {
            $this->avisar('Publicada. Ya está en /noticia/' . $slug . ', y esa dirección es para siempre.');
        } elseif ($sinPermisoParaPublicar) {
            $this->avisar('Guardada como borrador: tu cuenta escribe pero no publica. '
                . 'Ya puede revisarla alguien con permiso.');
        } else {
            $this->avisar('Guardada como borrador. Todavía no la ve nadie.');
        }

        Response::redirect('/panel/noticias/' . $id);
    }

    private function avisar(string $mensaje, string $tipo = 'ok'): void
    {
        $_SESSION['_flash'] = ['mensaje' => $mensaje, 'tipo' => $tipo];
    }

    /**
     * Convierte texto escrito a mano en los bloques que guarda la pieza.
     *
     * Se separa por lineas en blanco, como se separan los parrafos al
     * escribir. Una linea suelta que termina sin punto y va seguida de un
     * parrafo se trata como intertitulo: es como la gente escribe cuando no
     * tiene botones de formato delante.
     */
    private static function aBloques(string $texto): string
    {
        $texto  = str_replace(["\r\n", "\r"], "\n", $texto);
        $trozos = preg_split('/\n{2,}/', $texto) ?: [];

        $bloques = [];
        foreach ($trozos as $indice => $trozo) {
            $trozo = trim($trozo);
            if ($trozo === '') {
                continue;
            }

            $esUnaLinea  = !str_contains($trozo, "\n");
            $sinPuntoFinal = !preg_match('/[.:;!?]$/u', $trozo);
            $vieneOtro   = isset($trozos[$indice + 1]) && trim((string) $trozos[$indice + 1]) !== '';

            if ($esUnaLinea && $sinPuntoFinal && mb_strlen($trozo) <= 80 && $vieneOtro) {
                $bloques[] = ['tipo' => 'subtitulo', 'texto' => $trozo];
                continue;
            }

            $bloques[] = ['tipo' => 'parrafo', 'texto' => $trozo];
        }

        if ($bloques === []) {
            throw new RuntimeException('El cuerpo quedó vacío.');
        }

        return (string) json_encode($bloques, JSON_UNESCAPED_UNICODE);
    }
}
