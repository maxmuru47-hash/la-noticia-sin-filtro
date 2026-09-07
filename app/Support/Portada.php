<?php
declare(strict_types=1);

namespace App\Support;

/**
 * PORTADA GENERADA
 *
 * Cuando una pieza no trae foto, la web le fabrica una portada tipográfica
 * en vez de dejar un hueco. No es una imagen de banco ni una foto falsa:
 * es el titular, la sección y una composición geométrica con los colores
 * de la casa. Nadie puede confundirla con una fotografía, que es
 * justamente la idea.
 *
 * La variante se calcula a partir de la dirección permanente de la pieza,
 * así que es SIEMPRE la misma para la misma noticia. Si cambiara en cada
 * visita, el sitio parecería inestable y la gente dudaría de lo demás.
 */
final class Portada
{
    /** Cuántas composiciones distintas existen. */
    public const VARIANTES = 6;

    /**
     * Variante estable para una pieza. Del 1 al 6.
     *
     * Se usa crc32 y no aleatoriedad: mismo slug, misma portada, hoy y
     * dentro de tres años.
     */
    public static function variante(string $slug): int
    {
        if ($slug === '') {
            return 1;
        }

        return (int) (crc32($slug) % self::VARIANTES) + 1;
    }

    /**
     * Marca corta para la esquina de la portada.
     *
     * Se prefiere la sección porque sitúa la pieza de un vistazo. Si no
     * tiene, se recurre al tipo editorial, y en última instancia a la casa.
     */
    public static function marca(array $pieza): string
    {
        foreach (['category_name', 'editorial_type'] as $campo) {
            $valor = trim((string) ($pieza[$campo] ?? ''));
            if ($valor !== '') {
                return mb_strtoupper($valor);
            }
        }

        return 'SIN FILTRO';
    }

    /**
     * Tamaño de letra según lo larga que sea la palabra.
     *
     * «Venezuela» y «Emprendimiento» no pueden ir al mismo cuerpo: con un
     * tamaño fijo, el segundo se sale de la caja. Se decide aquí, en el
     * servidor, y no con artificios del navegador, para que el resultado sea
     * el mismo en todos y no dependa de que cargue una fuente.
     */
    public static function escala(string $texto): string
    {
        $largo = mb_strlen(trim($texto));

        if ($largo <= 10) { return 'g'; }
        if ($largo <= 16) { return 'm'; }

        return 'p';
    }

    /**
     * Un titular largo en un espacio pequeño se vuelve ilegible. Se recorta
     * por palabras, nunca a mitad de una, y solo para la portada: el titular
     * de verdad sigue entero en la pieza.
     */
    public static function rotulo(string $titulo, int $maximo = 58): string
    {
        $titulo = trim($titulo);
        if (mb_strlen($titulo) <= $maximo) {
            return $titulo;
        }

        $corte = mb_substr($titulo, 0, $maximo);
        $ultimo = mb_strrpos($corte, ' ');

        return ($ultimo === false ? $corte : mb_substr($corte, 0, $ultimo)) . '…';
    }
}
