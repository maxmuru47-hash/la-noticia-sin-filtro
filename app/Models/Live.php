<?php
declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

/**
 * Lives. La fecha y el estado tienen UNA sola fuente de verdad: las
 * columnas starts_at y status de esta tabla. Ninguna vista escribe una
 * fecha a mano.
 */
final class Live
{
    public const STATUSES = [
        'anunciado'  => 'Anunciado',
        'programado' => 'Programado',
        'en_vivo'    => 'En vivo',
        'finalizado' => 'Finalizado',
        'cancelado'  => 'Cancelado',
    ];

    public static function findBySlug(string $slug): ?array
    {
        return Database::first(
            'SELECT l.*, v.slug AS recording_slug, v.title AS recording_title,
                    v.duration_seconds AS recording_duration, v.poster_path AS recording_poster
               FROM lives l LEFT JOIN videos v ON v.id = l.recording_video_id
              WHERE l.slug = :slug',
            ['slug' => $slug]
        );
    }

    public static function findById(int $id): ?array
    {
        return Database::first('SELECT * FROM lives WHERE id = :id', ['id' => $id]);
    }

    /** El proximo programa: en vivo primero, luego el mas cercano. */
    public static function next(): ?array
    {
        return Database::first(
            'SELECT * FROM lives
              WHERE status IN ("en_vivo", "programado", "anunciado")
                AND (starts_at IS NULL OR starts_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 4 HOUR))
              ORDER BY FIELD(status, "en_vivo", "programado", "anunciado"), starts_at ASC
              LIMIT 1'
        );
    }

    public static function past(int $limit = 12, int $offset = 0): array
    {
        return Database::all(
            'SELECT l.*, v.slug AS recording_slug, v.duration_seconds AS recording_duration
               FROM lives l LEFT JOIN videos v ON v.id = l.recording_video_id
              WHERE l.status = "finalizado"
              ORDER BY l.starts_at DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
        );
    }

    public static function upcoming(int $limit = 6): array
    {
        return Database::all(
            'SELECT * FROM lives
              WHERE status IN ("anunciado","programado","en_vivo")
              ORDER BY starts_at ASC LIMIT ' . (int) $limit
        );
    }

    public static function countPast(): int
    {
        return (int) Database::value('SELECT COUNT(*) FROM lives WHERE status = "finalizado"');
    }

    public static function articles(int $liveId, ?string $moment = null): array
    {
        $where  = 'la.live_id = :id AND a.deleted_at IS NULL AND a.status IN ("publicada","actualizada","archivada")';
        $params = ['id' => $liveId];
        if ($moment !== null) {
            $where           .= ' AND la.moment = :moment';
            $params['moment'] = $moment;
        }
        return Database::all(
            'SELECT a.id, a.slug, a.title, a.summary, a.editorial_type, a.published_at, a.status,
                    la.moment, c.name AS category_name
               FROM articles a
               JOIN live_articles la ON la.article_id = a.id
               LEFT JOIN categories c ON c.id = a.category_id
              WHERE ' . $where . ' ORDER BY la.moment, la.position, a.published_at DESC',
            $params
        );
    }

    public static function questions(int $liveId, array $statuses = ['aprobada', 'seleccionada', 'respondida']): array
    {
        $placeholders = [];
        $params       = ['id' => $liveId];
        foreach (array_values($statuses) as $index => $status) {
            $placeholders[]       = ':s' . $index;
            $params['s' . $index] = $status;
        }
        return Database::all(
            'SELECT * FROM community_questions
              WHERE live_id = :id AND status IN (' . implode(', ', $placeholders) . ')
              ORDER BY FIELD(status, "respondida", "seleccionada", "aprobada"), created_at DESC',
            $params
        );
    }

    public static function forAdmin(int $limit, int $offset): array
    {
        return [
            'items' => Database::all(
                'SELECT * FROM lives ORDER BY starts_at DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
            ),
            'total' => (int) Database::value('SELECT COUNT(*) FROM lives'),
        ];
    }
}
