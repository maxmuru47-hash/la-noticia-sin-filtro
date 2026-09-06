<?php
declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

/** Expedientes vivos: el tema que no se cierra cuando termina la noticia. */
final class Dossier
{
    public static function findBySlug(string $slug): ?array
    {
        return Database::first(
            'SELECT d.*, t.name AS topic_name, t.slug AS topic_slug
               FROM dossiers d LEFT JOIN topics t ON t.id = d.topic_id
              WHERE d.slug = :slug',
            ['slug' => $slug]
        );
    }

    public static function active(int $limit = 4): array
    {
        return Database::all(
            'SELECT d.*,
                    (SELECT COUNT(*) FROM articles a
                      WHERE a.dossier_id = d.id AND a.deleted_at IS NULL
                        AND a.status IN ("publicada","actualizada","archivada")) AS piezas,
                    (SELECT MAX(a.published_at) FROM articles a
                      WHERE a.dossier_id = d.id AND a.deleted_at IS NULL) AS ultima
               FROM dossiers d
              WHERE d.status IN ("abierto", "en_seguimiento")
              ORDER BY ultima DESC LIMIT ' . (int) $limit
        );
    }

    public static function all(int $limit = 30, int $offset = 0): array
    {
        return Database::all(
            'SELECT * FROM dossiers ORDER BY updated_at DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
        );
    }

    public static function timeline(int $dossierId): array
    {
        return Database::all(
            'SELECT te.*, s.title AS source_title, s.url AS source_url,
                    a.slug AS article_slug, a.title AS article_title
               FROM timeline_events te
               LEFT JOIN sources s ON s.id = te.source_id
               LEFT JOIN articles a ON a.id = te.article_id
              WHERE te.dossier_id = :id
              ORDER BY te.occurred_on ASC, te.occurred_time ASC, te.position ASC',
            ['id' => $dossierId]
        );
    }

    public static function articles(int $dossierId): array
    {
        return Database::all(
            'SELECT a.id, a.slug, a.title, a.summary, a.editorial_type, a.status, a.published_at,
                    c.name AS category_name, c.slug AS category_slug,
                    m.storage_path AS hero_path, m.alt_text AS hero_media_alt
               FROM articles a
               LEFT JOIN categories c ON c.id = a.category_id
               LEFT JOIN media_assets m ON m.id = a.hero_media_id
              WHERE a.dossier_id = :id AND a.deleted_at IS NULL
                AND a.status IN ("publicada","actualizada","archivada")
              ORDER BY a.published_at DESC',
            ['id' => $dossierId]
        );
    }

    /** Preguntas que siguen abiertas dentro del expediente. */
    public static function openQuestions(int $dossierId): array
    {
        return Database::all(
            'SELECT DISTINCT a.legacy_question, a.slug, a.title
               FROM articles a
              WHERE a.dossier_id = :id AND a.deleted_at IS NULL
                AND a.legacy_question IS NOT NULL AND a.legacy_question <> ""
                AND a.status IN ("publicada","actualizada")
              ORDER BY a.published_at DESC LIMIT 8',
            ['id' => $dossierId]
        );
    }
}
