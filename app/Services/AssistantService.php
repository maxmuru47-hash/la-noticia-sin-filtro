<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Article;

/**
 * ASISTENTE DE LA REDACCIÓN
 *
 * Responde dos clases de pregunta y ninguna más:
 *
 *  1. Cómo funciona esto. Son respuestas escritas a mano, fijas, sobre el
 *     archivo permanente, las correcciones, el Pulso y el contacto.
 *
 *  2. Qué se ha publicado sobre algo. Eso no se contesta de memoria: se
 *     busca en el archivo real y se devuelven las piezas que existen, con
 *     su dirección. Si no hay ninguna, se dice que no hay ninguna.
 *
 * Lo que NO hace, a propósito: inventar una respuesta cuando no la tiene.
 * Un asistente de un medio que rellena huecos con lo que suena bien es una
 * máquina de desinformar con la cara de la casa. Prefiero que diga «no lo
 * sé» y ofrezca el buscador.
 */
final class AssistantService
{
    public const LIMITE_PREGUNTA = 300;

    /** Piezas reales que se ofrecen como mucho en una respuesta. */
    private const MAX_PIEZAS = 4;

    /**
     * @return array{respuesta: string, piezas: array<int, array<string, string>>, tipo: string}
     */
    public static function responder(string $pregunta): array
    {
        $limpia = mb_substr(trim($pregunta), 0, self::LIMITE_PREGUNTA);

        if ($limpia === '') {
            return self::sinTexto();
        }

        $normal = self::normalizar($limpia);

        $fija = self::respuestaFija($normal);
        if ($fija !== null) {
            return ['respuesta' => $fija, 'piezas' => [], 'tipo' => 'como-funciona'];
        }

        return self::buscarEnElArchivo($limpia, $normal);
    }

    /**
     * Se quitan acentos y signos para que «qué es el pulso» y «que es el
     * Pulso?» lleguen al mismo sitio. Quien pregunta no debería tener que
     * acertar con la tilde.
     */
    private static function normalizar(string $texto): string
    {
        $texto = mb_strtolower($texto);
        $texto = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'ü' => 'u', 'ñ' => 'n',
        ]);

        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L}\p{N} ]/u', ' ', $texto)));
    }

    private static function contiene(string $texto, string ...$palabras): bool
    {
        foreach ($palabras as $palabra) {
            if (str_contains($texto, $palabra)) {
                return true;
            }
        }

        return false;
    }

    private static function respuestaFija(string $q): ?string
    {
        if (self::contiene($q, 'pulso', 'votar', 'votacion', 'encuesta')) {
            return 'El **Pulso** es la votación que acompaña a algunas piezas. '
                 . 'Se guarda sin tu nombre y sin tu dirección IP: solo una huella que no permite volver atrás. '
                 . 'Por eso un resultado nunca se puede editar ni borrar, ni siquiera desde dentro. '
                 . 'Un dato que se puede corregir después no es un dato, es una opinión con adorno.';
        }

        if (self::contiene($q, 'correccion', 'corregir', 'error', 'equivoc', 'rectific')) {
            return 'Cuando una pieza se corrige, la corrección se queda escrita dentro con su fecha y su motivo, '
                 . 'y todas juntas se pueden ver en **Transparencia → Correcciones**. '
                 . 'No se borra lo que se dijo antes: se enseña qué cambió y por qué.';
        }

        if (self::contiene($q, 'archivo', 'borrar', 'desaparec', 'permanente', 'antigua', 'vieja')) {
            return 'Nada de lo publicado desaparece. Una pieza puede salir de portada, pero conserva su dirección, '
                 . 'sigue en el buscador y sigue en el **Archivo**. '
                 . 'Si alguna vez cambia de dirección, la vieja se queda reservada y te lleva a la nueva.';
        }

        if (self::contiene($q, 'contacto', 'escribir', 'correo', 'email', 'contactar', 'hablar con max')) {
            return 'Puedes escribir a **sinfiltroconmax@sinfiltroconmax.com**. '
                 . 'También está Max en Instagram (@maxegonzalezp) y en TikTok (@sinfiltroconmax).';
        }

        if (self::contiene($q, 'mentoria', 'curso', 'clase', 'formacion', 'aprender ia', 'inteligencia artificial')) {
            return 'La formación no vive aquí: está en **sinfiltroconmax.com**, que es la casa principal. '
                 . 'Allí están la mentoría privada de IA y el curso gratuito. '
                 . 'Esta parte del sitio es la redacción: aquí se publica y se archiva.';
        }

        if (self::contiene($q, 'quien eres', 'que eres', 'eres un bot', 'eres humano', 'chatgpt', 'inteligencia')) {
            return 'Soy un asistente de reglas, no una inteligencia artificial. '
                 . 'No invento respuestas: o te contesto con algo que está escrito, '
                 . 'o busco en el archivo y te enseño lo que hay de verdad. '
                 . 'Si no encuentro nada, te lo digo.';
        }

        if (self::contiene($q, 'hola', 'buenas', 'buenos dias', 'buenas tardes', 'buenas noches')) {
            return 'Hola. Puedo buscarte lo que se haya publicado sobre un tema, '
                 . 'o explicarte cómo funcionan el archivo, las correcciones o el Pulso. '
                 . '¿Sobre qué quieres saber?';
        }

        return null;
    }

    /**
     * @return array{respuesta: string, piezas: array<int, array<string, string>>, tipo: string}
     */
    private static function buscarEnElArchivo(string $original, string $normal): array
    {
        // Palabras que no aportan nada a una busqueda y solo la ensucian.
        $vacias = ['que', 'cual', 'cuales', 'como', 'donde', 'cuando', 'por', 'para', 'sobre',
                   'del', 'las', 'los', 'una', 'unos', 'unas', 'han', 'hay', 'the', 'con',
                   'dime', 'sabes', 'algo', 'tienes', 'publicado', 'noticia', 'noticias'];

        $palabras = array_values(array_filter(
            explode(' ', $normal),
            static fn (string $p): bool => mb_strlen($p) >= 3 && !in_array($p, $vacias, true)
        ));

        if ($palabras === []) {
            return self::sinTexto();
        }

        $consulta  = implode(' ', array_slice($palabras, 0, 6));
        $resultado = SearchService::search(['q' => $consulta], self::MAX_PIEZAS, 0);
        $items     = $resultado['items'] ?? [];

        if ($items === []) {
            return [
                'respuesta' => 'No encuentro nada publicado sobre eso. '
                             . 'Puede que aún no se haya tratado, o que lo estés llamando de otra forma. '
                             . 'Prueba con otra palabra, o escribe a sinfiltroconmax@sinfiltroconmax.com si crees que merece cubrirse.',
                'piezas'    => [],
                'tipo'      => 'sin-resultados',
            ];
        }

        $piezas = [];
        foreach ($items as $item) {
            $piezas[] = [
                'titulo' => (string) ($item['title'] ?? ''),
                'url'    => '/noticia/' . (string) ($item['slug'] ?? ''),
                'fecha'  => (string) ($item['published_at'] ?? ''),
            ];
        }

        $cuantas = count($piezas);
        $total   = (int) ($resultado['total'] ?? $cuantas);

        $respuesta = $cuantas === 1
            ? 'Encontré una pieza sobre eso:'
            : 'Encontré ' . $cuantas . ' piezas sobre eso:';

        if ($total > $cuantas) {
            $respuesta .= ' (hay ' . $total . ' en total, estas son las más cercanas)';
        }

        return ['respuesta' => $respuesta, 'piezas' => $piezas, 'tipo' => 'archivo'];
    }

    /**
     * @return array{respuesta: string, piezas: array<int, array<string, string>>, tipo: string}
     */
    private static function sinTexto(): array
    {
        return [
            'respuesta' => 'Escribe una palabra o dos sobre lo que buscas y miro en el archivo.',
            'piezas'    => [],
            'tipo'      => 'vacio',
        ];
    }
}
