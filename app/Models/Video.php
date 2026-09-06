<?php
declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

final class Video
{
    public const TYPES = [
        'hero'        => 'Hero de portada',
        'resumen'     => 'Resumen en video',
        'entrevista'  => 'Entrevista',
        'clip'        => 'Clip vertical',
        'live'        => 'Grabación de Live',
        'explicacion' => 'Explicación',
        'fondo'       => 'Fondo editorial',
    ];

    public const ROLES = [
        'principal'     => 'Video principal',
        'resumen'       => 'Resumen de 30 a 90 segundos',
        'clip_vertical' => 'Clip vertical para redes',
        'explicacion'   => 'Explicación',
        'entrevista'    => 'Entrevista',
        'live'          => 'Live relacionado',
        'contexto'      => 'Contexto',
    ];

    public static function findBySlug(string $slug): ?array
    {
        return Database::first(
            'SELECT * FROM videos WHERE slug = :slug AND deleted_at IS NULL',
            ['slug' => $slug]
        );
    }

    public static function findById(int $id): ?array
    {
        return Database::first('SELECT * FROM videos WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);
    }

    public static function published(int $limit = 12, int $offset = 0, ?string $type = null): array
    {
        $where  = 'deleted_at IS NULL AND status = "publicado"';
        $params = [];
        if ($type !== null) {
            $where          .= ' AND video_type = :type';
            $params['type']  = $type;
        }
        return Database::all(
            'SELECT * FROM videos WHERE ' . $where . ' ORDER BY published_at DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
            $params
        );
    }

    public static function countPublished(?string $type = null): int
    {
        $where  = 'deleted_at IS NULL AND status = "publicado"';
        $params = [];
        if ($type !== null) {
            $where         .= ' AND video_type = :type';
            $params['type'] = $type;
        }
        return (int) Database::value('SELECT COUNT(*) FROM videos WHERE ' . $where, $params);
    }

    public static function chapters(int $videoId): array
    {
        return Database::all(
            'SELECT * FROM video_chapters WHERE video_id = :id ORDER BY starts_at, position',
            ['id' => $videoId]
        );
    }

    public static function transcript(int $videoId, string $language = 'es'): ?array
    {
        return Database::first(
            'SELECT * FROM video_transcripts WHERE video_id = :id AND language = :lang',
            ['id' => $videoId, 'lang' => $language]
        );
    }

    public static function articles(int $videoId): array
    {
        return Database::all(
            'SELECT a.id, a.slug, a.title, a.editorial_type, a.published_at, avr.editorial_role
               FROM articles a JOIN article_video_relations avr ON avr.article_id = a.id
              WHERE avr.video_id = :id AND a.deleted_at IS NULL
                AND a.status IN ("publicada","actualizada","archivada")
              ORDER BY a.published_at DESC',
            ['id' => $videoId]
        );
    }

    public static function forAdmin(int $limit, int $offset, array $filters = []): array
    {
        $conditions = ['deleted_at IS NULL'];
        $params     = [];
        if (!empty($filters['status'])) {
            $conditions[]     = 'status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['type'])) {
            $conditions[]   = 'video_type = :type';
            $params['type'] = $filters['type'];
        }
        $where = implode(' AND ', $conditions);

        return [
            'items' => Database::all(
                'SELECT * FROM videos WHERE ' . $where . ' ORDER BY updated_at DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
                $params
            ),
            'total' => (int) Database::value('SELECT COUNT(*) FROM videos WHERE ' . $where, $params),
        ];
    }

    /** URL de reproduccion para proveedores externos, sin cookies cuando se puede. */
    public static function embedUrl(array $video): ?string
    {
        if ($video['provider'] === 'youtube' && !empty($video['provider_video_id'])) {
            return 'https://www.youtube-nocookie.com/embed/' . rawurlencode((string) $video['provider_video_id']);
        }
        return $video['embed_url'] ?: null;
    }

    public static function isSelfHosted(array $video): bool
    {
        return $video['provider'] === 'propio' && !empty($video['storage_path']);
    }
}
