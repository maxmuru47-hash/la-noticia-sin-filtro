<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Article;
use App\Support\Dates;
use App\Support\Str;

/**
 * Datos estructurados y metadatos. Nada se inventa: si un campo no
 * existe en la base, no se emite.
 */
final class SeoService
{
    public static function absoluteUrl(string $path, string $appUrl): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        return rtrim($appUrl, '/') . '/' . ltrim($path, '/');
    }

    public static function organization(array $config): array
    {
        $appUrl = $config['app']['url'];
        $social = array_values(array_filter([
            $config['brand']['instagram'] ? 'https://www.instagram.com/' . $config['brand']['instagram'] : null,
            $config['brand']['tiktok'] ? 'https://www.tiktok.com/@' . $config['brand']['tiktok'] : null,
        ]));

        return [
            '@type'  => 'NewsMediaOrganization',
            '@id'    => $appUrl . '/#organizacion',
            'name'   => $config['app']['name'],
            'url'    => $appUrl,
            'parentOrganization' => [
                '@type' => 'Organization',
                'name'  => $config['brand']['name'],
            ],
            'logo'   => [
                '@type' => 'ImageObject',
                'url'   => self::absoluteUrl('/assets/images/logo-lnsf.svg', $appUrl),
            ],
            'sameAs' => $social,
            'ethicsPolicy'      => $appUrl . '/transparencia/politica-editorial',
            'correctionsPolicy' => $appUrl . '/transparencia/correcciones',
            'diversityPolicy'   => $appUrl . '/transparencia/quienes-somos',
        ];
    }

    /** NewsArticle o Article segun el tipo editorial. */
    public static function article(array $article, array $config, array $extra = []): array
    {
        $appUrl = $config['app']['url'];
        $url    = $appUrl . '/noticia/' . $article['slug'];

        $type = match ($article['editorial_type']) {
            'noticia', 'verificacion' => 'NewsArticle',
            'opinion'                 => 'OpinionNewsArticle',
            'analisis'                => 'AnalysisNewsArticle',
            default                   => 'Article',
        };

        $data = [
            '@context'         => 'https://schema.org',
            '@type'            => $type,
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
            'url'              => $url,
            'headline'         => mb_substr((string) $article['title'], 0, 110),
            'datePublished'    => Dates::iso($article['published_at']),
            'dateModified'     => Dates::iso($article['updated_content_at'] ?? $article['published_at']),
            'inLanguage'       => 'es-VE',
            'isAccessibleForFree' => true,
            'publisher'        => self::organization($config),
        ];

        if (!empty($article['subtitle'])) {
            $data['alternativeHeadline'] = mb_substr((string) $article['subtitle'], 0, 200);
        }
        if (!empty($article['summary'])) {
            $data['description'] = Str::excerpt((string) $article['summary'], 300);
        }
        if (!empty($article['author_name'])) {
            $data['author'] = [
                '@type' => 'Person',
                'name'  => $article['author_name'],
                'url'   => $appUrl . '/autor/' . $article['author_slug'],
            ];
        }
        if (!empty($article['hero_path'])) {
            $data['image'] = [self::absoluteUrl((string) $article['hero_path'], $appUrl)];
        }
        if (!empty($article['category_name'])) {
            $data['articleSection'] = $article['category_name'];
        }
        if (!empty($extra['tags'])) {
            $data['keywords'] = implode(', ', array_column($extra['tags'], 'name'));
        }
        if (!empty($article['body_plain'])) {
            $data['wordCount'] = count(preg_split('/\s+/u', (string) $article['body_plain'], -1, PREG_SPLIT_NO_EMPTY) ?: []);
        }
        if (!empty($extra['corrections'])) {
            $data['correction'] = array_map(
                static fn (array $c): string => $c['reason'],
                array_slice($extra['corrections'], 0, 5)
            );
        }

        return $data;
    }

    public static function video(array $video, array $config, ?array $transcript = null): array
    {
        $appUrl = $config['app']['url'];

        $data = [
            '@context'    => 'https://schema.org',
            '@type'       => 'VideoObject',
            'name'        => $video['title'],
            'description' => Str::excerpt((string) ($video['description'] ?? $video['title']), 300),
            'uploadDate'  => Dates::iso($video['published_at'] ?? $video['created_at']),
            'url'         => $appUrl . '/video/' . $video['slug'],
            'inLanguage'  => 'es-VE',
            'publisher'   => self::organization($config),
        ];

        if (!empty($video['poster_path'])) {
            $data['thumbnailUrl'] = [self::absoluteUrl((string) $video['poster_path'], $appUrl)];
        } elseif (!empty($video['thumbnail_path'])) {
            $data['thumbnailUrl'] = [self::absoluteUrl((string) $video['thumbnail_path'], $appUrl)];
        }
        if (!empty($video['duration_seconds'])) {
            $data['duration'] = Str::durationIso((int) $video['duration_seconds']);
        }
        if (!empty($video['storage_path'])) {
            $data['contentUrl'] = self::absoluteUrl((string) $video['storage_path'], $appUrl);
        }
        if (!empty($video['embed_url'])) {
            $data['embedUrl'] = $video['embed_url'];
        }
        if ($transcript !== null && !empty($transcript['content'])) {
            $data['transcript'] = Str::excerpt((string) $transcript['content'], 4000);
        }
        if (!empty($video['captions_path'])) {
            $data['caption'] = self::absoluteUrl((string) $video['captions_path'], $appUrl);
        }

        return $data;
    }

    public static function live(array $live, array $config): array
    {
        $appUrl = $config['app']['url'];

        $status = match ($live['status']) {
            'cancelado'  => 'https://schema.org/EventCancelled',
            'finalizado' => 'https://schema.org/EventScheduled',
            default      => 'https://schema.org/EventScheduled',
        };

        $data = [
            '@context'            => 'https://schema.org',
            '@type'               => 'BroadcastEvent',
            'name'                => $live['title'],
            'url'                 => $appUrl . '/live/' . $live['slug'],
            'eventStatus'         => $status,
            'eventAttendanceMode' => 'https://schema.org/OnlineEventAttendanceMode',
            'isLiveBroadcast'     => $live['status'] === 'en_vivo',
            'publisher'           => self::organization($config),
        ];

        if (!empty($live['starts_at'])) {
            $data['startDate'] = Dates::iso($live['starts_at']);
        }
        if (!empty($live['ends_at'])) {
            $data['endDate'] = Dates::iso($live['ends_at']);
        }
        if (!empty($live['summary'])) {
            $data['description'] = Str::excerpt((string) $live['summary'], 300);
        }

        return $data;
    }

    /** @param array<int,array{nombre:string,ruta:string}> $trail */
    public static function breadcrumbs(array $trail, string $appUrl): array
    {
        $items = [];
        foreach ($trail as $index => $step) {
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $index + 1,
                'name'     => $step['nombre'],
                'item'     => self::absoluteUrl($step['ruta'], $appUrl),
            ];
        }

        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }

    public static function person(array $author, array $config): array
    {
        $appUrl = $config['app']['url'];
        $data   = [
            '@context' => 'https://schema.org',
            '@type'    => 'Person',
            'name'     => $author['name'],
            'url'      => $appUrl . '/autor/' . $author['slug'],
        ];

        if (!empty($author['role_title'])) {
            $data['jobTitle'] = $author['role_title'];
        }
        if (!empty($author['bio'])) {
            $data['description'] = Str::excerpt((string) $author['bio'], 300);
        }
        if (!empty($author['photo_path'])) {
            $data['image'] = self::absoluteUrl((string) $author['photo_path'], $appUrl);
        }
        $sameAs = array_values(array_filter([
            !empty($author['instagram']) ? 'https://www.instagram.com/' . $author['instagram'] : null,
            !empty($author['tiktok']) ? 'https://www.tiktok.com/@' . $author['tiktok'] : null,
        ]));
        if ($sameAs !== []) {
            $data['sameAs'] = $sameAs;
        }
        $data['worksFor'] = self::organization($config);

        return $data;
    }

    /**
     * Este JSON viaja dentro de un <script> en el HTML, asi que un titular
     * que contenga la cadena de cierre de esa etiqueta cerraria el bloque y
     * lo que viniera detras seria HTML vivo. Escapar los signos de menor y
     * mayor lo impide de raiz, y la barra se deja escapar tambien: la salida
     * es menos bonita de leer y sigue siendo el mismo JSON para quien lo
     * consume, que son buscadores, no personas.
     */
    public static function jsonLd(array $data): string
    {
        return json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) ?: '{}';
    }
}
