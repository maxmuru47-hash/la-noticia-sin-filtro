<?php
declare(strict_types=1);

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Saneamiento de HTML por lista blanca estricta.
 *
 * Regla del proyecto: ningun texto escrito en el panel llega al navegador
 * sin pasar por aqui. Todo lo que no este explicitamente permitido se
 * elimina, incluidos <script>, <style>, <iframe>, atributos on*, y
 * cualquier URL con esquema javascript: o data: ejecutable.
 */
final class Html
{
    /** Etiquetas permitidas y sus atributos admitidos. */
    private const ALLOWED = [
        'p'          => [],
        'br'         => [],
        'strong'     => [], 'b' => [],
        'em'         => [], 'i' => [],
        'u'          => [],
        's'          => [],
        'mark'       => [],
        'sub'        => [], 'sup' => [],
        'h2'         => ['id'], 'h3' => ['id'], 'h4' => ['id'],
        'ul'         => [], 'ol' => [], 'li' => [],
        'blockquote' => ['cite'],
        'cite'       => [],
        'a'          => ['href', 'title', 'rel', 'target'],
        'figure'     => [], 'figcaption' => [],
        'img'        => ['src', 'alt', 'width', 'height', 'loading', 'decoding'],
        'table'      => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => ['scope'], 'td' => [],
        'code'       => [], 'pre' => [],
        'hr'         => [],
        'time'       => ['datetime'],
        'abbr'       => ['title'],
        'span'       => ['class'],
        'div'        => ['class'],
    ];

    /** Clases admitidas en span y div. Cualquier otra se descarta. */
    private const ALLOWED_CLASSES = [
        'nota', 'destacado', 'aviso', 'cita-fuente', 'dato', 'certeza-confirmado',
        'certeza-probable', 'certeza-disputado',
    ];

    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public static function sanitize(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        // Neutraliza comentarios condicionales y secciones CDATA antes de parsear.
        $html = (string) preg_replace('/<!--.*?-->/s', '', $html);

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="raiz-sanitizada">' . $html . '</div>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');
        }

        $root = $document->getElementById('raiz-sanitizada');
        if (!$root instanceof DOMElement) {
            return '';
        }

        self::clean($root, $document);

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $document->saveHTML($child);
        }

        return trim($output);
    }

    /** Texto plano indexable a partir de HTML o de bloques. */
    public static function toPlainText(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }
        $text = preg_replace('/<(br|\/p|\/li|\/h[1-6]|\/blockquote)[^>]*>/i', " \n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/[ \t]+/u', ' ', preg_replace('/\n{3,}/', "\n\n", $text) ?? '') ?? '');
    }

    private static function clean(DOMNode $node, DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        // Se recorre en orden inverso porque se eliminan nodos durante el paso.
        $elements = $xpath->query('.//*', $node);
        if ($elements === false) {
            return;
        }

        for ($i = $elements->length - 1; $i >= 0; $i--) {
            $element = $elements->item($i);
            if (!$element instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($element->nodeName);

            if (!array_key_exists($tag, self::ALLOWED)) {
                // script, style, iframe, object, form y similares pierden
                // tambien su contenido. El resto conserva su texto.
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'svg', 'math', 'link', 'meta', 'base', 'noscript'], true)) {
                    $element->parentNode?->removeChild($element);
                } else {
                    self::unwrap($element);
                }
                continue;
            }

            self::cleanAttributes($element, $tag);
        }
    }

    private static function cleanAttributes(DOMElement $element, string $tag): void
    {
        $allowed = self::ALLOWED[$tag];

        for ($i = $element->attributes->length - 1; $i >= 0; $i--) {
            $attribute = $element->attributes->item($i);
            if ($attribute === null) {
                continue;
            }
            $name = strtolower($attribute->nodeName);

            if (!in_array($name, $allowed, true)) {
                $element->removeAttribute($attribute->nodeName);
                continue;
            }

            $value = trim($attribute->nodeValue ?? '');

            if (($name === 'href' || $name === 'src') && !self::isSafeUrl($value)) {
                $element->removeAttribute($attribute->nodeName);
                continue;
            }

            if ($name === 'class') {
                $classes = array_values(array_intersect(
                    preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [],
                    self::ALLOWED_CLASSES
                ));
                if ($classes === []) {
                    $element->removeAttribute('class');
                } else {
                    $element->setAttribute('class', implode(' ', $classes));
                }
            }
        }

        // Todo enlace externo sale con rel de seguridad.
        if ($tag === 'a' && $element->hasAttribute('href')) {
            $href = $element->getAttribute('href');
            if (str_starts_with($href, 'http')) {
                $element->setAttribute('rel', 'noopener noreferrer');
            }
            if ($element->getAttribute('target') !== '_blank') {
                $element->removeAttribute('target');
            }
        }

        // Toda imagen llega con carga diferida y alt obligatorio.
        if ($tag === 'img') {
            if (!$element->hasAttribute('src')) {
                $element->parentNode?->removeChild($element);
                return;
            }
            if (!$element->hasAttribute('alt')) {
                $element->setAttribute('alt', '');
            }
            $element->setAttribute('loading', 'lazy');
            $element->setAttribute('decoding', 'async');
        }
    }

    private static function isSafeUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        // Rutas relativas y anclas del propio sitio.
        if (str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return !str_contains($url, '//') || str_starts_with($url, '/');
        }

        // Se normaliza para que "java\0script:" o "JaVaScRiPt :" no pasen.
        $normalized = strtolower(preg_replace('/[\x00-\x20]/', '', $url) ?? '');

        foreach (['javascript:', 'vbscript:', 'data:', 'file:'] as $blocked) {
            if (str_starts_with($normalized, $blocked)) {
                return false;
            }
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        return $scheme !== null && $scheme !== false && in_array(strtolower($scheme), self::ALLOWED_SCHEMES, true);
    }

    /** Quita la etiqueta pero conserva su contenido de texto. */
    private static function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;
        if ($parent === null) {
            return;
        }
        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }
        $parent->removeChild($element);
    }
}
