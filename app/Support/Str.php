<?php
declare(strict_types=1);

namespace App\Support;

final class Str
{
    /** Slug con transliteracion del castellano. La n con virgulilla vale n, no se pierde. */
    public static function slug(string $text, int $maxLength = 180): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');

        $map = [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss',
            '·' => '-', '/' => '-', '_' => '-', ',' => '-', ':' => '-', ';' => '-',
        ];
        $text = strtr($text, $map);

        $text = preg_replace('/[^a-z0-9\s-]/u', '', $text) ?? '';
        $text = preg_replace('/[\s-]+/', '-', $text) ?? '';
        $text = trim($text, '-');

        if ($text === '') {
            $text = 'pieza-' . substr(bin2hex(random_bytes(4)), 0, 8);
        }

        if (mb_strlen($text) > $maxLength) {
            $text = mb_substr($text, 0, $maxLength);
            $text = rtrim((string) preg_replace('/-[^-]*$/', '', $text), '-');
        }

        return $text;
    }

    public static function excerpt(string $text, int $maxLength = 180): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }
        $cut = mb_substr($text, 0, $maxLength);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > $maxLength * 0.6) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }
        return rtrim($cut, " ,.;:") . '...';
    }

    /** Minutos de lectura a 200 palabras por minuto, minimo 1. */
    public static function readingMinutes(string $text): int
    {
        $words = preg_split('/\s+/u', trim(strip_tags($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return max(1, (int) ceil(count($words) / 200));
    }

    public static function uuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /** Duracion legible: 1:04:12 o 4:30. */
    public static function duration(?int $seconds): string
    {
        if ($seconds === null || $seconds <= 0) {
            return '';
        }
        $hours   = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $rest    = $seconds % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $rest)
            : sprintf('%d:%02d', $minutes, $rest);
    }

    /** Duracion ISO 8601 para VideoObject. */
    public static function durationIso(?int $seconds): ?string
    {
        if ($seconds === null || $seconds <= 0) {
            return null;
        }
        return sprintf('PT%dH%dM%dS', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    /**
     * Fragmento con la coincidencia resaltada, para los resultados del
     * buscador. Devuelve HTML ya escapado salvo la marca <mark>.
     */
    public static function highlight(string $haystack, string $needle, int $radius = 110): string
    {
        $haystack = trim(preg_replace('/\s+/u', ' ', strip_tags($haystack)) ?? '');
        $terms    = preg_split('/\s+/u', trim($needle), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $position = false;
        $matched  = '';

        foreach ($terms as $term) {
            if (mb_strlen($term) < 3) {
                continue;
            }
            $found = mb_stripos($haystack, $term);
            if ($found !== false) {
                $position = $found;
                $matched  = $term;
                break;
            }
        }

        if ($position === false) {
            return htmlspecialchars(self::excerpt($haystack, $radius * 2), ENT_QUOTES, 'UTF-8');
        }

        $start   = max(0, $position - $radius);
        $snippet = mb_substr($haystack, $start, $radius * 2);
        $snippet = ($start > 0 ? '...' : '') . $snippet . '...';
        $escaped = htmlspecialchars($snippet, ENT_QUOTES, 'UTF-8');

        return (string) preg_replace(
            '/(' . preg_quote(htmlspecialchars($matched, ENT_QUOTES, 'UTF-8'), '/') . ')/iu',
            '<mark>$1</mark>',
            $escaped,
            1
        );
    }
}
