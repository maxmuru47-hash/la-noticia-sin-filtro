<?php
declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

/**
 * La portada SELECCIONA y ORDENA. Nunca almacena ni elimina noticias.
 * Vaciar una zona no toca el articulo: solo deja de mostrarlo arriba.
 */
final class Homepage
{
    public const ZONES = [
        'hero'           => 'Hero: historia principal',
        'pregunta_dia'   => 'Pregunta principal del día',
        'pulso_dia'      => 'Pulso del día',
        'esencial'       => 'Lo esencial ahora (máximo 3)',
        'max_analiza'    => 'Max lo analiza',
        'conversacion'   => 'La conversación se movió',
        'proximo_live'   => 'Próximo Live',
        'ultimos_videos' => 'Últimos videos',
        'expedientes'    => 'Expedientes vivos',
        'preguntas'      => 'Preguntas de la comunidad',
    ];

    /** Piezas seleccionadas para una zona, ya resueltas y solo si son publicables. */
    public static function articlesForZone(string $zone, int $limit = 3): array
    {
        return Database::all(
            'SELECT a.*, hs.override_title, hs.position,
                    c.slug AS category_slug, c.name AS category_name, c.color AS category_color,
                    au.name AS author_name, au.slug AS author_slug,
                    m.storage_path AS hero_path, m.alt_text AS hero_media_alt,
                    m.width AS hero_width, m.height AS hero_height,
                    (SELECT COUNT(*) FROM article_video_relations avr WHERE avr.article_id = a.id) AS videos_total
               FROM homepage_slots hs
               JOIN articles a ON a.id = hs.article_id
               LEFT JOIN categories c ON c.id = a.category_id
               LEFT JOIN authors au ON au.id = a.author_id
               LEFT JOIN media_assets m ON m.id = a.hero_media_id
              WHERE hs.zone = :zone AND hs.is_active = 1
                AND a.deleted_at IS NULL
                AND a.status IN ("publicada", "actualizada")
                AND a.published_at <= UTC_TIMESTAMP()
              ORDER BY hs.position, hs.id
              LIMIT ' . (int) $limit,
            ['zone' => $zone]
        );
    }

    public static function videosForZone(string $zone, int $limit = 4): array
    {
        return Database::all(
            'SELECT v.*, hs.override_title, hs.position
               FROM homepage_slots hs
               JOIN videos v ON v.id = hs.video_id
              WHERE hs.zone = :zone AND hs.is_active = 1
                AND v.deleted_at IS NULL AND v.status = "publicado"
              ORDER BY hs.position, hs.id LIMIT ' . (int) $limit,
            ['zone' => $zone]
        );
    }

    public static function dossiersForZone(string $zone, int $limit = 4): array
    {
        return Database::all(
            'SELECT d.*, hs.override_title,
                    (SELECT COUNT(*) FROM articles a WHERE a.dossier_id = d.id
                       AND a.deleted_at IS NULL AND a.status IN ("publicada","actualizada","archivada")) AS piezas
               FROM homepage_slots hs
               JOIN dossiers d ON d.id = hs.dossier_id
              WHERE hs.zone = :zone AND hs.is_active = 1
              ORDER BY hs.position, hs.id LIMIT ' . (int) $limit,
            ['zone' => $zone]
        );
    }

    public static function slots(string $zone): array
    {
        return Database::all(
            'SELECT hs.*, a.title AS article_title, a.slug AS article_slug, a.status AS article_status,
                    v.title AS video_title, d.title AS dossier_title, l.title AS live_title,
                    p.question AS poll_question
               FROM homepage_slots hs
               LEFT JOIN articles a ON a.id = hs.article_id
               LEFT JOIN videos v ON v.id = hs.video_id
               LEFT JOIN dossiers d ON d.id = hs.dossier_id
               LEFT JOIN lives l ON l.id = hs.live_id
               LEFT JOIN polls p ON p.id = hs.poll_id
              WHERE hs.zone = :zone ORDER BY hs.position, hs.id',
            ['zone' => $zone]
        );
    }

    /**
     * Relleno automatico. Si el equipo no ha curado una zona, la portada
     * no se queda vacia: toma lo mas reciente que corresponda.
     */
    public static function fallbackArticles(string $editorialType = null, int $limit = 3, array $excludeIds = []): array
    {
        return Article::published([
            'limit'          => $limit,
            'editorial_type' => $editorialType,
            'exclude_ids'    => $excludeIds,
        ]);
    }
}
