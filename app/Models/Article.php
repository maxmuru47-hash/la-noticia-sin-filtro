<?php
declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

/**
 * Lectura de noticias.
 *
 * PERMANENCIA: los estados publicos son publicada, actualizada, archivada
 * y retirada. Una archivada sale de portada pero sigue viva en su URL, en
 * el buscador, en el archivo y en las taxonomias. Aqui no existe ningun
 * metodo de borrado.
 */
final class Article
{
    /** Estados que un visitante puede ver en su URL permanente. */
    public const PUBLIC_STATUSES = ['publicada', 'actualizada', 'archivada', 'retirada'];

    /** Estados que pueden aparecer en listados y portada. */
    public const LISTABLE_STATUSES = ['publicada', 'actualizada'];

    /** Estados que aparecen en el archivo y el buscador. */
    public const ARCHIVED_STATUSES = ['publicada', 'actualizada', 'archivada'];

    public const EDITORIAL_TYPES = [
        'noticia'      => 'Noticia',
        'analisis'     => 'Análisis',
        'opinion'      => 'Opinión',
        'explicador'   => 'Explicador',
        'verificacion' => 'Verificación',
    ];

    public const STATUSES = [
        'borrador'   => 'Borrador',
        'revision'   => 'En revisión',
        'programada' => 'Programada',
        'publicada'  => 'Publicada',
        'actualizada' => 'Actualizada',
        'archivada'  => 'Archivada',
        'retirada'   => 'Retirada',
    ];

    private const SELECT_BASE = '
        SELECT a.*,
               c.slug AS category_slug, c.name AS category_name, c.color AS category_color,
               au.slug AS author_slug, au.name AS author_name, au.role_title AS author_role,
               au.photo_path AS author_photo, au.photo_alt AS author_photo_alt,
               ed.name AS editor_name, ed.slug AS editor_slug,
               m.storage_path AS hero_path, m.alt_text AS hero_media_alt,
               m.width AS hero_width, m.height AS hero_height, m.is_synthetic AS hero_is_synthetic,
               d.slug AS dossier_slug, d.title AS dossier_title
          FROM articles a
          LEFT JOIN categories c   ON c.id = a.category_id
          LEFT JOIN authors au     ON au.id = a.author_id
          LEFT JOIN authors ed     ON ed.id = a.editor_id
          LEFT JOIN media_assets m ON m.id = a.hero_media_id
          LEFT JOIN dossiers d     ON d.id = a.dossier_id
    ';

    public static function findBySlug(string $slug): ?array
    {
        return Database::first(
            self::SELECT_BASE . ' WHERE a.slug = :slug AND a.deleted_at IS NULL LIMIT 1',
            ['slug' => $slug]
        );
    }

    public static function findById(int $id): ?array
    {
        return Database::first(
            self::SELECT_BASE . ' WHERE a.id = :id AND a.deleted_at IS NULL LIMIT 1',
            ['id' => $id]
        );
    }

    /** Es visible para un visitante en su URL permanente? */
    public static function isPubliclyVisible(array $article): bool
    {
        return in_array($article['status'], self::PUBLIC_STATUSES, true)
            && $article['deleted_at'] === null
            && $article['published_at'] !== null;
    }

    /**
     * Listado publico. Por defecto solo publicada y actualizada, que es lo
     * que alimenta portada y listados. El archivo pide explicitamente
     * incluir las archivadas.
     */
    public static function published(array $options = []): array
    {
        $statuses = $options['statuses'] ?? self::LISTABLE_STATUSES;
        $limit    = (int) ($options['limit'] ?? 10);
        $offset   = (int) ($options['offset'] ?? 0);

        [$where, $params] = self::buildFilters($options, $statuses);

        $order = match ($options['order'] ?? 'reciente') {
            'antiguo'  => 'a.published_at ASC',
            'leido'    => 'a.view_count DESC, a.published_at DESC',
            default    => 'a.published_at DESC',
        };

        return Database::all(
            self::SELECT_BASE . ' WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
            $params
        );
    }

    public static function countPublished(array $options = []): int
    {
        $statuses = $options['statuses'] ?? self::LISTABLE_STATUSES;
        [$where, $params] = self::buildFilters($options, $statuses);

        return (int) Database::value(
            'SELECT COUNT(*) FROM articles a
               LEFT JOIN categories c ON c.id = a.category_id
               LEFT JOIN authors au ON au.id = a.author_id
              WHERE ' . $where,
            $params
        );
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private static function buildFilters(array $options, array $statuses): array
    {
        $conditions = ['a.deleted_at IS NULL', 'a.published_at IS NOT NULL', 'a.published_at <= UTC_TIMESTAMP()'];
        $params     = [];

        $placeholders = [];
        foreach (array_values($statuses) as $index => $status) {
            $key                = 'st' . $index;
            $placeholders[]     = ':' . $key;
            $params[$key]       = $status;
        }
        $conditions[] = 'a.status IN (' . implode(', ', $placeholders) . ')';

        if (!empty($options['category_slug'])) {
            $conditions[]           = 'c.slug = :category_slug';
            $params['category_slug'] = $options['category_slug'];
        }
        if (!empty($options['author_slug'])) {
            $conditions[]         = 'au.slug = :author_slug';
            $params['author_slug'] = $options['author_slug'];
        }
        if (!empty($options['editorial_type'])) {
            $conditions[]           = 'a.editorial_type = :editorial_type';
            $params['editorial_type'] = $options['editorial_type'];
        }
        if (!empty($options['dossier_id'])) {
            $conditions[]        = 'a.dossier_id = :dossier_id';
            $params['dossier_id'] = (int) $options['dossier_id'];
        }
        if (!empty($options['tag_slug'])) {
            $conditions[]      = 'a.id IN (SELECT at.article_id FROM article_tags at JOIN tags t ON t.id = at.tag_id WHERE t.slug = :tag_slug)';
            $params['tag_slug'] = $options['tag_slug'];
        }
        if (!empty($options['topic_slug'])) {
            $conditions[]        = 'a.id IN (SELECT atp.article_id FROM article_topics atp JOIN topics tp ON tp.id = atp.topic_id WHERE tp.slug = :topic_slug)';
            $params['topic_slug'] = $options['topic_slug'];
        }
        if (!empty($options['year'])) {
            $conditions[]  = 'YEAR(a.published_at) = :year';
            $params['year'] = (int) $options['year'];
        }
        if (!empty($options['month'])) {
            $conditions[]   = 'MONTH(a.published_at) = :month';
            $params['month'] = (int) $options['month'];
        }
        if (!empty($options['day'])) {
            $conditions[] = 'DAY(a.published_at) = :day';
            $params['day'] = (int) $options['day'];
        }
        if (!empty($options['exclude_ids'])) {
            foreach (array_values($options['exclude_ids']) as $index => $id) {
                $key           = 'ex' . $index;
                $conditions[]  = 'a.id <> :' . $key;
                $params[$key]  = (int) $id;
            }
        }

        return [implode(' AND ', $conditions), $params];
    }

    /** Listado para el panel, que si ve borradores y programadas. */
    public static function forAdmin(array $filters, int $limit, int $offset): array
    {
        $conditions = ['a.deleted_at IS NULL'];
        $params     = [];

        if (!empty($filters['status'])) {
            $conditions[]     = 'a.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['editorial_type'])) {
            $conditions[]             = 'a.editorial_type = :editorial_type';
            $params['editorial_type'] = $filters['editorial_type'];
        }
        if (!empty($filters['author_id'])) {
            $conditions[]        = 'a.author_id = :author_id';
            $params['author_id'] = (int) $filters['author_id'];
        }
        if (!empty($filters['q'])) {
            $conditions[] = '(a.title LIKE :q OR a.slug LIKE :q OR a.summary LIKE :q)';
            $params['q']  = '%' . $filters['q'] . '%';
        }

        $where = implode(' AND ', $conditions);

        return [
            'items' => Database::all(
                self::SELECT_BASE . ' WHERE ' . $where
                . ' ORDER BY COALESCE(a.published_at, a.scheduled_for, a.updated_at) DESC'
                . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
                $params
            ),
            'total' => (int) Database::value('SELECT COUNT(*) FROM articles a WHERE ' . $where, $params),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public static function tags(int $articleId): array
    {
        return Database::all(
            'SELECT t.* FROM tags t JOIN article_tags at ON at.tag_id = t.id
              WHERE at.article_id = :id ORDER BY t.name',
            ['id' => $articleId]
        );
    }

    public static function topics(int $articleId): array
    {
        return Database::all(
            'SELECT tp.* FROM topics tp JOIN article_topics atp ON atp.topic_id = tp.id
              WHERE atp.article_id = :id ORDER BY tp.name',
            ['id' => $articleId]
        );
    }

    public static function sources(int $articleId): array
    {
        return Database::all(
            'SELECT s.*, asrc.certainty, asrc.position
               FROM sources s JOIN article_sources asrc ON asrc.source_id = s.id
              WHERE asrc.article_id = :id ORDER BY asrc.position, s.title',
            ['id' => $articleId]
        );
    }

    public static function actors(int $articleId): array
    {
        return Database::all(
            'SELECT ac.*, aa.role_note, aa.position
               FROM actors ac JOIN article_actors aa ON aa.actor_id = ac.id
              WHERE aa.article_id = :id ORDER BY aa.position, ac.name',
            ['id' => $articleId]
        );
    }

    public static function impacts(int $articleId): array
    {
        return Database::all(
            'SELECT * FROM impact_profiles WHERE article_id = :id ORDER BY position, id',
            ['id' => $articleId]
        );
    }

    public static function timeline(int $articleId): array
    {
        return Database::all(
            'SELECT te.*, s.title AS source_title, s.url AS source_url
               FROM timeline_events te
               LEFT JOIN sources s ON s.id = te.source_id
              WHERE te.article_id = :id
              ORDER BY te.occurred_on ASC, te.occurred_time ASC, te.position ASC',
            ['id' => $articleId]
        );
    }

    public static function corrections(int $articleId): array
    {
        return Database::all(
            'SELECT ac.*, u.display_name AS user_name
               FROM article_corrections ac
               LEFT JOIN users u ON u.id = ac.user_id
              WHERE ac.article_id = :id AND ac.is_public = 1
              ORDER BY ac.corrected_at DESC',
            ['id' => $articleId]
        );
    }

    public static function revisions(int $articleId, int $limit = 30): array
    {
        return Database::all(
            'SELECT ar.id, ar.revision_no, ar.change_note, ar.status, ar.created_at,
                    u.display_name AS user_name
               FROM article_revisions ar
               LEFT JOIN users u ON u.id = ar.user_id
              WHERE ar.article_id = :id
              ORDER BY ar.revision_no DESC LIMIT ' . (int) $limit,
            ['id' => $articleId]
        );
    }

    public static function videos(int $articleId): array
    {
        return Database::all(
            'SELECT v.*, avr.editorial_role, avr.position
               FROM videos v JOIN article_video_relations avr ON avr.video_id = v.id
              WHERE avr.article_id = :id AND v.deleted_at IS NULL
              ORDER BY avr.position, v.id',
            ['id' => $articleId]
        );
    }

    public static function lives(int $articleId): array
    {
        return Database::all(
            'SELECT l.*, la.moment FROM lives l
               JOIN live_articles la ON la.live_id = l.id
              WHERE la.article_id = :id ORDER BY l.starts_at DESC',
            ['id' => $articleId]
        );
    }

    /** De que pieza y de que duda nacio esta noticia. */
    public static function lineage(int $articleId): ?array
    {
        return Database::first(
            'SELECT al.*, p.title AS parent_title, p.slug AS parent_slug,
                    q.body AS question_body
               FROM article_lineage al
               LEFT JOIN articles p ON p.id = al.parent_article_id
               LEFT JOIN community_questions q ON q.id = al.question_id
              WHERE al.article_id = :id ORDER BY al.id DESC LIMIT 1',
            ['id' => $articleId]
        );
    }

    /** Contenido siguiente: mismo tema, mismo expediente o misma categoria. */
    public static function related(array $article, int $limit = 4): array
    {
        $rows = Database::all(
            'SELECT DISTINCT a.id, a.slug, a.title, a.summary, a.editorial_type, a.published_at,
                    a.status, c.name AS category_name, c.slug AS category_slug,
                    m.storage_path AS hero_path, m.alt_text AS hero_media_alt
               FROM articles a
               LEFT JOIN categories c ON c.id = a.category_id
               LEFT JOIN media_assets m ON m.id = a.hero_media_id
               LEFT JOIN article_topics atp ON atp.article_id = a.id
              WHERE a.id <> :id
                AND a.deleted_at IS NULL
                AND a.status IN ("publicada", "actualizada")
                AND a.published_at <= UTC_TIMESTAMP()
                AND (
                      (:dossier_a IS NOT NULL AND a.dossier_id = :dossier_b)
                   OR atp.topic_id IN (SELECT topic_id FROM article_topics WHERE article_id = :id2)
                   OR (:categoria_a IS NOT NULL AND a.category_id = :categoria_b)
                )
              ORDER BY a.published_at DESC
              LIMIT ' . (int) $limit,
            [
                // PDO sin emulacion no permite repetir un marcador con
                // nombre: cada aparicion necesita el suyo.
                'id'          => (int) $article['id'],
                'id2'         => (int) $article['id'],
                'dossier_a'   => $article['dossier_id'] === null ? null : (int) $article['dossier_id'],
                'dossier_b'   => $article['dossier_id'] === null ? null : (int) $article['dossier_id'],
                'categoria_a' => $article['category_id'] === null ? null : (int) $article['category_id'],
                'categoria_b' => $article['category_id'] === null ? null : (int) $article['category_id'],
            ]
        );

        return $rows;
    }

    public static function incrementViews(int $articleId): void
    {
        Database::run('UPDATE articles SET view_count = view_count + 1 WHERE id = :id', ['id' => $articleId]);
    }

    /** Piezas programadas cuya hora ya llego. Las publica el cron. */
    public static function dueForPublishing(): array
    {
        return Database::all(
            'SELECT * FROM articles
              WHERE status = "programada" AND scheduled_for IS NOT NULL
                AND scheduled_for <= UTC_TIMESTAMP() AND deleted_at IS NULL'
        );
    }

    public static function archiveYears(): array
    {
        return Database::all(
            'SELECT YEAR(published_at) AS anio, COUNT(*) AS total
               FROM articles
              WHERE deleted_at IS NULL AND published_at IS NOT NULL
                AND status IN ("publicada", "actualizada", "archivada")
              GROUP BY anio ORDER BY anio DESC'
        );
    }

    public static function archiveMonths(int $year): array
    {
        return Database::all(
            'SELECT MONTH(published_at) AS mes, COUNT(*) AS total
               FROM articles
              WHERE deleted_at IS NULL AND published_at IS NOT NULL
                AND YEAR(published_at) = :year
                AND status IN ("publicada", "actualizada", "archivada")
              GROUP BY mes ORDER BY mes DESC',
            ['year' => $year]
        );
    }
}
