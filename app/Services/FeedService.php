<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Article;
use App\Models\Video;
use App\Support\Database;
use App\Support\Dates;
use App\Support\Str;

/** Sitemaps, sitemap de noticias, sitemap de video y RSS. */
final class FeedService
{
    public static function sitemapIndex(string $appUrl): string
    {
        $parts = ['sitemap-contenido.xml', 'sitemap-noticias.xml', 'sitemap-videos.xml', 'sitemap-secciones.xml'];

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
             . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($parts as $part) {
            $xml .= '  <sitemap><loc>' . self::esc($appUrl . '/' . $part) . '</loc>'
                 . '<lastmod>' . gmdate('Y-m-d') . '</lastmod></sitemap>' . "\n";
        }

        return $xml . '</sitemapindex>';
    }

    /** Todo el archivo permanente, incluidas las piezas archivadas. */
    public static function sitemapContent(string $appUrl): string
    {
        $rows = Database::all(
            'SELECT slug, published_at, updated_content_at, status
               FROM articles
              WHERE deleted_at IS NULL AND noindex = 0 AND published_at IS NOT NULL
                AND status IN ("publicada","actualizada","archivada")
              ORDER BY published_at DESC LIMIT 45000'
        );

        $xml = self::openUrlset();
        foreach ($rows as $row) {
            $priority = $row['status'] === 'archivada' ? '0.4' : '0.8';
            $xml .= self::url(
                $appUrl . '/noticia/' . $row['slug'],
                $row['updated_content_at'] ?? $row['published_at'],
                $row['status'] === 'archivada' ? 'yearly' : 'weekly',
                $priority
            );
        }
        return $xml . '</urlset>';
    }

    /** Sitemap de noticias de Google: solo las ultimas 48 horas. */
    public static function sitemapNews(string $appUrl, string $siteName): string
    {
        $rows = Database::all(
            'SELECT a.slug, a.title, a.published_at
               FROM articles a
              WHERE a.deleted_at IS NULL AND a.noindex = 0
                AND a.status IN ("publicada","actualizada")
                AND a.published_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY)
              ORDER BY a.published_at DESC LIMIT 1000'
        );

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
             . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n"
             . '        xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n";

        foreach ($rows as $row) {
            $xml .= '  <url>' . "\n"
                 . '    <loc>' . self::esc($appUrl . '/noticia/' . $row['slug']) . '</loc>' . "\n"
                 . '    <news:news>' . "\n"
                 . '      <news:publication>' . "\n"
                 . '        <news:name>' . self::esc($siteName) . '</news:name>' . "\n"
                 . '        <news:language>es</news:language>' . "\n"
                 . '      </news:publication>' . "\n"
                 . '      <news:publication_date>' . self::esc((string) Dates::iso($row['published_at'])) . '</news:publication_date>' . "\n"
                 . '      <news:title>' . self::esc((string) $row['title']) . '</news:title>' . "\n"
                 . '    </news:news>' . "\n"
                 . '  </url>' . "\n";
        }

        return $xml . '</urlset>';
    }

    public static function sitemapVideos(string $appUrl): string
    {
        $rows = Database::all(
            'SELECT v.*, (SELECT a.slug FROM articles a
                            JOIN article_video_relations avr ON avr.article_id = a.id
                           WHERE avr.video_id = v.id AND a.deleted_at IS NULL
                           ORDER BY a.published_at DESC LIMIT 1) AS article_slug
               FROM videos v
              WHERE v.deleted_at IS NULL AND v.status = "publicado"
              ORDER BY v.published_at DESC LIMIT 5000'
        );

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
             . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n"
             . '        xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">' . "\n";

        foreach ($rows as $row) {
            $poster = $row['poster_path'] ?: $row['thumbnail_path'];
            if ($poster === null || $poster === '') {
                // Sin miniatura Google descarta la entrada. Mejor omitirla
                // que emitir un sitemap invalido.
                continue;
            }

            $xml .= '  <url>' . "\n"
                 . '    <loc>' . self::esc($appUrl . '/video/' . $row['slug']) . '</loc>' . "\n"
                 . '    <video:video>' . "\n"
                 . '      <video:thumbnail_loc>' . self::esc(SeoService::absoluteUrl((string) $poster, $appUrl)) . '</video:thumbnail_loc>' . "\n"
                 . '      <video:title>' . self::esc((string) $row['title']) . '</video:title>' . "\n"
                 . '      <video:description>' . self::esc(Str::excerpt((string) ($row['description'] ?: $row['title']), 1900)) . '</video:description>' . "\n";

            if (!empty($row['storage_path'])) {
                $xml .= '      <video:content_loc>' . self::esc(SeoService::absoluteUrl((string) $row['storage_path'], $appUrl)) . '</video:content_loc>' . "\n";
            }
            if (!empty($row['embed_url'])) {
                $xml .= '      <video:player_loc>' . self::esc((string) $row['embed_url']) . '</video:player_loc>' . "\n";
            }
            if (!empty($row['duration_seconds'])) {
                $xml .= '      <video:duration>' . (int) $row['duration_seconds'] . '</video:duration>' . "\n";
            }
            if (!empty($row['published_at'])) {
                $xml .= '      <video:publication_date>' . self::esc((string) Dates::iso($row['published_at'])) . '</video:publication_date>' . "\n";
            }

            $xml .= '      <video:family_friendly>yes</video:family_friendly>' . "\n"
                 . '    </video:video>' . "\n"
                 . '  </url>' . "\n";
        }

        return $xml . '</urlset>';
    }

    public static function sitemapSections(string $appUrl): string
    {
        $xml = self::openUrlset();

        foreach (['/', '/archivo', '/buscar', '/videos', '/lives', '/transparencia/politica-editorial',
                  '/transparencia/correcciones', '/transparencia/quienes-somos', '/transparencia/metodologia',
                  '/transparencia/privacidad', '/transparencia/publicidad', '/transparencia/contacto'] as $path) {
            $xml .= self::url($appUrl . rtrim($path, '/'), null, 'weekly', $path === '/' ? '1.0' : '0.5');
        }

        foreach (Database::all('SELECT slug FROM categories WHERE is_active = 1') as $row) {
            $xml .= self::url($appUrl . '/categoria/' . $row['slug'], null, 'daily', '0.7');
        }
        foreach (Database::all('SELECT slug FROM authors WHERE is_active = 1') as $row) {
            $xml .= self::url($appUrl . '/autor/' . $row['slug'], null, 'weekly', '0.6');
        }
        foreach (Database::all('SELECT slug FROM dossiers') as $row) {
            $xml .= self::url($appUrl . '/expediente/' . $row['slug'], null, 'weekly', '0.7');
        }
        foreach (Database::all('SELECT slug FROM lives') as $row) {
            $xml .= self::url($appUrl . '/live/' . $row['slug'], null, 'monthly', '0.6');
        }
        foreach (Article::archiveYears() as $row) {
            $xml .= self::url($appUrl . '/archivo/' . $row['anio'], null, 'monthly', '0.4');
        }

        return $xml . '</urlset>';
    }

    public static function rss(string $appUrl, array $config): string
    {
        $items = Article::published(['limit' => 30]);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
             . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/">' . "\n"
             . '<channel>' . "\n"
             . '  <title>' . self::esc($config['app']['name']) . '</title>' . "\n"
             . '  <link>' . self::esc($appUrl) . '</link>' . "\n"
             . '  <description>' . self::esc($config['brand']['promise']) . '</description>' . "\n"
             . '  <language>es-VE</language>' . "\n"
             . '  <lastBuildDate>' . gmdate('D, d M Y H:i:s') . ' +0000</lastBuildDate>' . "\n"
             . '  <atom:link href="' . self::esc($appUrl . '/rss.xml') . '" rel="self" type="application/rss+xml" />' . "\n";

        foreach ($items as $item) {
            $link = $appUrl . '/noticia/' . $item['slug'];
            $xml .= '  <item>' . "\n"
                 . '    <title>' . self::esc((string) $item['title']) . '</title>' . "\n"
                 . '    <link>' . self::esc($link) . '</link>' . "\n"
                 . '    <guid isPermaLink="true">' . self::esc($link) . '</guid>' . "\n"
                 . '    <pubDate>' . gmdate('D, d M Y H:i:s', strtotime((string) $item['published_at'])) . ' +0000</pubDate>' . "\n"
                 . '    <description>' . self::esc(Str::excerpt((string) ($item['summary'] ?? $item['title']), 400)) . '</description>' . "\n";

            if (!empty($item['category_name'])) {
                $xml .= '    <category>' . self::esc((string) $item['category_name']) . '</category>' . "\n";
            }
            $xml .= '  </item>' . "\n";
        }

        return $xml . '</channel>' . "\n" . '</rss>';
    }

    public static function robots(string $appUrl, bool $allow = true): string
    {
        if (!$allow) {
            return "User-agent: *\nDisallow: /\n";
        }

        return "User-agent: *\n"
            . "Allow: /\n"
            . "Disallow: /panel\n"
            . "Disallow: /api/\n"
            . "Disallow: /uploads/originales\n"
            . "\n"
            . "Sitemap: " . $appUrl . "/sitemap.xml\n"
            . "Sitemap: " . $appUrl . "/sitemap-noticias.xml\n"
            . "Sitemap: " . $appUrl . "/sitemap-videos.xml\n";
    }

    private static function openUrlset(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
             . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    }

    private static function url(string $loc, ?string $lastmod, string $changefreq, string $priority): string
    {
        $xml = '  <url><loc>' . self::esc($loc) . '</loc>';
        if ($lastmod !== null) {
            $xml .= '<lastmod>' . substr((string) $lastmod, 0, 10) . '</lastmod>';
        }
        return $xml . '<changefreq>' . $changefreq . '</changefreq>'
             . '<priority>' . $priority . '</priority></url>' . "\n";
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
