<?php
declare(strict_types=1);

use App\Support\Dates;
use App\Support\Str;

if (!function_exists('e')) {
    /** Escape por defecto. Toda salida de una vista pasa por aqui. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('atributo')) {
    /** Valor seguro para un atributo HTML. */
    function atributo(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('fechaLarga')) {
    function fechaLarga(?string $utc): string
    {
        return Dates::long($utc);
    }
}

if (!function_exists('fechaHora')) {
    function fechaHora(?string $utc): string
    {
        return Dates::longWithTime($utc);
    }
}

if (!function_exists('fechaIso')) {
    function fechaIso(?string $utc): string
    {
        return (string) Dates::iso($utc);
    }
}

if (!function_exists('extracto')) {
    function extracto(?string $text, int $length = 180): string
    {
        return Str::excerpt((string) $text, $length);
    }
}

if (!function_exists('duracion')) {
    function duracion(?int $seconds): string
    {
        return Str::duration($seconds);
    }
}

if (!function_exists('etiquetaTipo')) {
    /** Clasificacion editorial visible. Nunca se omite. */
    function etiquetaTipo(string $type, bool $conEnlace = true): string
    {
        $nombres = \App\Models\Article::EDITORIAL_TYPES;
        $nombre  = $nombres[$type] ?? 'Noticia';
        $clase   = 'etiqueta-tipo etiqueta-tipo--' . e($type);

        return $conEnlace
            ? sprintf('<a class="%s" href="/tipo/%s">%s</a>', $clase, e($type), e($nombre))
            : sprintf('<span class="%s">%s</span>', $clase, e($nombre));
    }
}

if (!function_exists('selloEstado')) {
    /** Sello visible del estado, cuando aporta contexto al lector. */
    function selloEstado(string $status): string
    {
        return match ($status) {
            'archivada'   => '<span class="sello sello--archivada">Archivada</span>',
            'retirada'    => '<span class="sello sello--retirada">Retirada</span>',
            'actualizada' => '<span class="sello sello--actualizada">Actualizada</span>',
            'borrador'    => '<span class="sello sello--borrador">Borrador</span>',
            'programada'  => '<span class="sello sello--borrador">Programada</span>',
            'revision'    => '<span class="sello sello--borrador">En revisión</span>',
            default       => '',
        };
    }
}

if (!function_exists('selloDemo')) {
    /** Contenido de demostracion: siempre rotulado, nunca disfrazado. */
    function selloDemo(mixed $isDemo): string
    {
        return !empty($isDemo) ? '<span class="sello sello--demo">Demostración</span>' : '';
    }
}

if (!function_exists('rutaImagen')) {
    function rutaImagen(?string $path, string $porDefecto = '/assets/images/marcador.svg'): string
    {
        return $path !== null && $path !== '' ? $path : $porDefecto;
    }
}
