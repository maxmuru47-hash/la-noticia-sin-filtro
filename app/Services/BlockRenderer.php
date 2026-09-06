<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Html;
use App\Support\Str;
use App\Support\View;

/**
 * Cuerpo estructurado por bloques.
 *
 * El editor guarda JSON, no HTML suelto. Asi el contenido se puede
 * reordenar, reutilizar y volver a maquetar sin reescribir noticias, y
 * cada bloque pasa por su propia sanitizacion.
 */
final class BlockRenderer
{
    public const TYPES = [
        'parrafo'    => 'Párrafo',
        'subtitulo'  => 'Subtítulo',
        'cita'       => 'Cita',
        'lista'      => 'Lista',
        'imagen'     => 'Imagen',
        'video'      => 'Video',
        'dato'       => 'Dato destacado',
        'fuente'     => 'Fuente citada',
        'aviso'      => 'Aviso editorial',
        'separador'  => 'Separador',
    ];

    /** @param array<int,array<string,mixed>> $blocks */
    public static function render(array $blocks, array $context = []): string
    {
        $html = '';
        foreach ($blocks as $block) {
            $html .= self::renderBlock($block, $context);
        }
        return $html;
    }

    private static function renderBlock(array $block, array $context): string
    {
        $type = (string) ($block['tipo'] ?? 'parrafo');
        $e    = static fn (mixed $v): string => View::e($v);

        return match ($type) {
            'subtitulo' => sprintf(
                '<h2 id="%s" class="cuerpo__subtitulo">%s</h2>',
                $e(Str::slug((string) ($block['texto'] ?? ''), 60)),
                $e($block['texto'] ?? '')
            ),

            'cita' => sprintf(
                '<blockquote class="cuerpo__cita"><p>%s</p>%s</blockquote>',
                $e($block['texto'] ?? ''),
                empty($block['autor']) ? '' : '<cite>' . $e($block['autor']) . '</cite>'
            ),

            'lista' => self::renderList($block),

            'imagen' => self::renderImage($block),

            'video' => self::renderVideo($block, $context),

            'dato' => sprintf(
                '<aside class="cuerpo__dato"><p class="cuerpo__dato-valor">%s</p><p class="cuerpo__dato-nota">%s</p>%s</aside>',
                $e($block['valor'] ?? ''),
                $e($block['nota'] ?? ''),
                empty($block['fuente']) ? '' : '<p class="cuerpo__dato-fuente">Fuente: ' . $e($block['fuente']) . '</p>'
            ),

            'fuente' => self::renderSource($block),

            'aviso' => sprintf('<aside class="cuerpo__aviso">%s</aside>', $e($block['texto'] ?? '')),

            'separador' => '<hr class="cuerpo__separador">',

            default => self::renderParagraph($block),
        };
    }

    private static function renderParagraph(array $block): string
    {
        // El parrafo admite negrita, cursiva y enlaces, todo saneado.
        $html = Html::sanitize((string) ($block['texto'] ?? ''));
        if ($html === '') {
            return '';
        }
        // Si el editor escribio texto plano, se envuelve en un parrafo.
        if (!preg_match('/^\s*<(p|ul|ol|h[2-4]|blockquote|figure|table|pre)/i', $html)) {
            $html = '<p>' . $html . '</p>';
        }
        return $html;
    }

    private static function renderList(array $block): string
    {
        $items = $block['items'] ?? [];
        if (!is_array($items) || $items === []) {
            return '';
        }
        $tag  = ($block['ordenada'] ?? false) ? 'ol' : 'ul';
        $html = '<' . $tag . ' class="cuerpo__lista">';
        foreach ($items as $item) {
            $html .= '<li>' . Html::sanitize((string) $item) . '</li>';
        }
        return $html . '</' . $tag . '>';
    }

    private static function renderImage(array $block): string
    {
        // La ruta se valida igual que cualquier otra URL: un
        // javascript: escrito en el editor no puede llegar al navegador.
        $src = self::safeUrl((string) ($block['ruta'] ?? ''));
        if ($src === null) {
            return '';
        }
        $e = static fn (mixed $v): string => View::e($v);

        // Se reservan las dimensiones para que la maqueta no salte.
        $dimensions = '';
        if (!empty($block['ancho']) && !empty($block['alto'])) {
            $dimensions = sprintf(' width="%d" height="%d"', (int) $block['ancho'], (int) $block['alto']);
        }

        return sprintf(
            '<figure class="cuerpo__figura"><img src="%s" alt="%s"%s loading="lazy" decoding="async">%s</figure>',
            $e($src),
            $e($block['alt'] ?? ''),
            $dimensions,
            empty($block['pie']) ? '' : '<figcaption>' . $e($block['pie'])
                . (empty($block['credito']) ? '' : ' <span class="cuerpo__credito">' . $e($block['credito']) . '</span>')
                . (!empty($block['sintetica']) ? ' <span class="cuerpo__sintetica">Imagen generada con inteligencia artificial</span>' : '')
                . '</figcaption>'
        );
    }

    private static function renderSource(array $block): string
    {
        $e     = static fn (mixed $v): string => View::e($v);
        $texto = (string) ($block['texto'] ?? '');
        $url   = self::safeUrl((string) ($block['url'] ?? ''));

        if ($url === null) {
            // Sin URL válida, la fuente se publica igual como texto: se
            // pierde el enlace, nunca la atribución.
            return sprintf('<p class="cuerpo__fuente">%s</p>', $e($texto));
        }

        return sprintf(
            '<p class="cuerpo__fuente"><a href="%s" rel="noopener noreferrer nofollow" target="_blank">%s</a></p>',
            $e($url),
            $e($texto !== '' ? $texto : $url)
        );
    }

    /**
     * Solo se admite una ruta interna del propio sitio o una URL http/https.
     *
     * Se normaliza antes de comprobar, para que "java\0script:" o
     * "JaVaScRiPt :" no pasen por escribirse de forma rara.
     */
    private static function safeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $normalizada = strtolower((string) preg_replace('/[\x00-\x20]/', '', $url));
        foreach (['javascript:', 'vbscript:', 'data:', 'file:'] as $prohibido) {
            if (str_starts_with($normalizada, $prohibido)) {
                return null;
            }
        }

        // Ruta interna: empieza por / y no es un enlace protocolo-relativo.
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return $url;
        }

        $esquema = parse_url($url, PHP_URL_SCHEME);
        if ($esquema === null || $esquema === false) {
            return null;
        }

        return in_array(strtolower($esquema), ['http', 'https'], true) ? $url : null;
    }

    private static function renderVideo(array $block, array $context): string
    {
        $videos = $context['videos'] ?? [];
        $id     = (int) ($block['video_id'] ?? 0);

        foreach ($videos as $video) {
            if ((int) $video['id'] === $id) {
                return View::partial('partials/reproductor', [
                    'video'    => $video,
                    'contexto' => 'cuerpo',
                ]);
            }
        }
        return '';
    }

    /** Texto plano de los bloques, para indexar y contar minutos de lectura. */
    public static function toPlainText(array $blocks): string
    {
        $parts = [];
        foreach ($blocks as $block) {
            $type = (string) ($block['tipo'] ?? 'parrafo');
            $parts[] = match ($type) {
                'lista'  => implode(' ', array_map('strval', (array) ($block['items'] ?? []))),
                'imagen' => trim(((string) ($block['pie'] ?? '')) . ' ' . ((string) ($block['alt'] ?? ''))),
                'dato'   => trim(((string) ($block['valor'] ?? '')) . ' ' . ((string) ($block['nota'] ?? ''))),
                'cita'   => trim(((string) ($block['texto'] ?? '')) . ' ' . ((string) ($block['autor'] ?? ''))),
                'video', 'separador' => '',
                default  => Html::toPlainText((string) ($block['texto'] ?? '')),
            };
        }
        return trim(preg_replace('/\s+/u', ' ', implode(' ', array_filter($parts))) ?? '');
    }

    /** @return array<int,array<string,mixed>> */
    public static function decode(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }
}
