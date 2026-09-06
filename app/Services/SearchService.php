<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Article;
use App\Support\Database;
use App\Support\Str;

/**
 * Buscador real en servidor, con paginacion. Nunca se carga el archivo
 * completo en una respuesta.
 *
 * El indice incluye la transcripcion de los videos relacionados, asi que
 * una noticia se encuentra por lo que se dijo en camara, no solo por lo
 * que se escribio.
 *
 * Estrategia doble a proposito: FULLTEXT cuando el alojamiento lo
 * permite, y un LIKE ponderado como respaldo. En hosting compartido no
 * siempre se puede bajar innodb_ft_min_token_size, y palabras cortas
 * como "IA" o "PIB" quedarian fuera del indice.
 */
final class SearchService
{
    public const ORDERS = [
        'relevancia' => 'Relevancia',
        'reciente'   => 'Más reciente',
        'antiguo'    => 'Más antiguo',
    ];

    /** Reconstruye la fila del indice para una pieza. */
    public static function reindexArticle(int $articleId): void
    {
        $article = Article::findById($articleId);
        if ($article === null) {
            self::removeArticle($articleId);
            return;
        }

        // Una pieza que no ha llegado a publicarse no entra al indice
        // publico. Una archivada SI: debe poder encontrarse anos despues.
        if (!in_array($article['status'], Article::ARCHIVED_STATUSES, true) || $article['deleted_at'] !== null) {
            self::removeArticle($articleId);
            return;
        }

        $tags   = array_column(Article::tags($articleId), 'name');
        $topics = array_column(Article::topics($articleId), 'name');
        $videos = Article::videos($articleId);

        $transcript = '';
        foreach ($videos as $video) {
            $row = Database::first(
                'SELECT content FROM video_transcripts WHERE video_id = :id ORDER BY language = "es" DESC LIMIT 1',
                ['id' => (int) $video['id']]
            );
            if ($row !== null) {
                $transcript .= ' ' . $row['content'];
            }
            $transcript .= ' ' . (string) ($video['text_alternative'] ?? '');
        }

        $liveId = Database::value(
            'SELECT live_id FROM live_articles WHERE article_id = :id ORDER BY live_id DESC LIMIT 1',
            ['id' => $articleId]
        );

        $keywords = implode(' ', array_filter(array_merge(
            $tags,
            $topics,
            [
                (string) ($article['category_name'] ?? ''),
                (string) ($article['author_name'] ?? ''),
                (string) ($article['dossier_title'] ?? ''),
                Article::EDITORIAL_TYPES[$article['editorial_type']] ?? '',
            ]
        )));

        $body = trim(implode(' ', array_filter([
            (string) ($article['body_plain'] ?? ''),
            (string) ($article['facts_confirmed'] ?? ''),
            (string) ($article['facts_probable'] ?? ''),
            (string) ($article['facts_unknown'] ?? ''),
            (string) ($article['perspective_a_body'] ?? ''),
            (string) ($article['perspective_b_body'] ?? ''),
            (string) ($article['max_opinion'] ?? ''),
            (string) ($article['legacy_question'] ?? ''),
        ])));

        Database::run(
            'INSERT INTO search_index
                (entity_type, entity_id, article_id, title, summary, body, transcript, keywords,
                 editorial_type, status, has_video, live_id, category_id, author_id, published_at)
             VALUES
                ("articulo", :entity_id, :article_id, :title, :summary, :body, :transcript, :keywords,
                 :editorial_type, :status, :has_video, :live_id, :category_id, :author_id, :published_at)
             ON DUPLICATE KEY UPDATE
                article_id = VALUES(article_id), title = VALUES(title), summary = VALUES(summary),
                body = VALUES(body), transcript = VALUES(transcript), keywords = VALUES(keywords),
                editorial_type = VALUES(editorial_type), status = VALUES(status),
                has_video = VALUES(has_video), live_id = VALUES(live_id),
                category_id = VALUES(category_id), author_id = VALUES(author_id),
                published_at = VALUES(published_at)',
            [
                'entity_id'      => $articleId,
                'article_id'     => $articleId,
                'title'          => (string) $article['title'],
                'summary'        => trim(((string) ($article['subtitle'] ?? '')) . ' ' . ((string) ($article['summary'] ?? '')) . ' ' . ((string) ($article['lead_question'] ?? ''))),
                'body'           => $body,
                'transcript'     => trim($transcript),
                'keywords'       => $keywords,
                'editorial_type' => (string) $article['editorial_type'],
                'status'         => (string) $article['status'],
                'has_video'      => $videos === [] ? 0 : 1,
                'live_id'        => $liveId === null ? null : (int) $liveId,
                'category_id'    => $article['category_id'] === null ? null : (int) $article['category_id'],
                'author_id'      => $article['author_id'] === null ? null : (int) $article['author_id'],
                'published_at'   => $article['published_at'],
            ]
        );
    }

    public static function removeArticle(int $articleId): void
    {
        Database::run(
            'DELETE FROM search_index WHERE entity_type = "articulo" AND entity_id = :id',
            ['id' => $articleId]
        );
    }

    public static function reindexAll(): int
    {
        $ids = Database::all('SELECT id FROM articles WHERE deleted_at IS NULL');
        foreach ($ids as $row) {
            self::reindexArticle((int) $row['id']);
        }
        return count($ids);
    }

    /**
     * Busqueda con filtros y paginacion.
     *
     * @return array{items:array<int,array<string,mixed>>,total:int,modo:string}
     */
    public static function search(array $filters, int $limit, int $offset): array
    {
        $query = trim((string) ($filters['q'] ?? ''));

        [$conditions, $params] = self::buildConditions($filters);

        $useFulltext = $query !== '' && self::fulltextUsable($query);
        $mode        = 'sin_consulta';

        // El recuento solo ejecuta el WHERE, asi que no puede recibir los
        // marcadores que unicamente aparecen en el SELECT.
        $paramsSelect = [];

        // PDO sin emulacion no permite repetir un marcador con nombre, y
        // tanto la relevancia como el filtro necesitan el mismo termino.
        // Por eso cada aparicion lleva su propio marcador.
        if ($query !== '') {
            if ($useFulltext) {
                $mode         = 'fulltext';
                $booleanQuery = self::toBooleanQuery($query);
                $campos       = 'si.title, si.summary, si.body, si.transcript, si.keywords';

                $select       = 'MATCH(' . $campos . ') AGAINST (:q_sel IN BOOLEAN MODE) AS relevancia';
                $conditions[] = 'MATCH(' . $campos . ') AGAINST (:q_filtro IN BOOLEAN MODE) > 0';

                $paramsSelect['q_sel'] = $booleanQuery;
                $params['q_filtro']    = $booleanQuery;
            } else {
                $mode  = 'like';
                $comodin = '%' . $query . '%';

                foreach (['f1', 'f2', 'f3', 'f4', 'f5'] as $marcador) {
                    $params[$marcador] = $comodin;
                }
                foreach (['s1', 's2', 's3', 's4', 's5'] as $marcador) {
                    $paramsSelect[$marcador] = $comodin;
                }

                $conditions[] = '(si.title LIKE :f1 OR si.summary LIKE :f2 OR si.body LIKE :f3
                                  OR si.transcript LIKE :f4 OR si.keywords LIKE :f5)';

                // Relevancia manual: el titulo pesa mas que el cuerpo.
                $select = '((si.title LIKE :s1) * 8 + (si.summary LIKE :s2) * 4
                            + (si.keywords LIKE :s3) * 3 + (si.body LIKE :s4) * 2
                            + (si.transcript LIKE :s5)) AS relevancia';
            }
        } else {
            $select = '0 AS relevancia';
        }

        $order = match ($filters['order'] ?? 'relevancia') {
            'reciente' => 'si.published_at DESC',
            'antiguo'  => 'si.published_at ASC',
            default    => $query === '' ? 'si.published_at DESC' : 'relevancia DESC, si.published_at DESC',
        };

        $where = implode(' AND ', $conditions);

        $items = Database::all(
            'SELECT si.*, ' . $select . ',
                    a.slug, a.editorial_type AS tipo, a.reading_minutes, a.is_demo,
                    c.name AS category_name, c.slug AS category_slug,
                    au.name AS author_name, au.slug AS author_slug,
                    m.storage_path AS hero_path, m.alt_text AS hero_media_alt,
                    (SELECT MAX(v.duration_seconds) FROM videos v
                       JOIN article_video_relations avr ON avr.video_id = v.id
                      WHERE avr.article_id = si.article_id) AS video_duration
               FROM search_index si
               JOIN articles a ON a.id = si.article_id AND a.deleted_at IS NULL
               LEFT JOIN categories c ON c.id = si.category_id
               LEFT JOIN authors au ON au.id = si.author_id
               LEFT JOIN media_assets m ON m.id = a.hero_media_id
              WHERE ' . $where . '
              ORDER BY ' . $order . '
              LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
            array_merge($params, $paramsSelect)
        );

        $total = (int) Database::value(
            'SELECT COUNT(*) FROM search_index si
               JOIN articles a ON a.id = si.article_id AND a.deleted_at IS NULL
              WHERE ' . $where,
            $params
        );

        // Fragmento con la coincidencia resaltada.
        foreach ($items as $index => $item) {
            $haystack = trim(((string) $item['summary']) . ' ' . ((string) $item['body']));
            $items[$index]['fragmento'] = $query === ''
                ? Str::excerpt((string) $item['summary'], 200)
                : Str::highlight($haystack, $query);

            $items[$index]['coincide_transcripcion'] = $query !== ''
                && $item['transcript'] !== null
                && mb_stripos((string) $item['transcript'], $query) !== false;

            unset($items[$index]['body'], $items[$index]['transcript']);
        }

        return ['items' => $items, 'total' => $total, 'modo' => $mode];
    }

    /** @return array{0:array<int,string>,1:array<string,mixed>} */
    private static function buildConditions(array $filters): array
    {
        // El buscador SI incluye las archivadas. Ese es el punto.
        $conditions = ['si.entity_type = "articulo"', 'si.status IN ("publicada","actualizada","archivada")'];
        $params     = [];

        if (!empty($filters['desde'])) {
            $conditions[]     = 'si.published_at >= :desde';
            $params['desde']  = $filters['desde'] . ' 00:00:00';
        }
        if (!empty($filters['hasta'])) {
            $conditions[]     = 'si.published_at <= :hasta';
            $params['hasta']  = $filters['hasta'] . ' 23:59:59';
        }
        if (!empty($filters['anio'])) {
            $conditions[]    = 'YEAR(si.published_at) = :anio';
            $params['anio']  = (int) $filters['anio'];
        }
        if (!empty($filters['mes'])) {
            $conditions[]   = 'MONTH(si.published_at) = :mes';
            $params['mes']  = (int) $filters['mes'];
        }
        if (!empty($filters['categoria'])) {
            $conditions[]        = 'si.category_id = (SELECT id FROM categories WHERE slug = :categoria)';
            $params['categoria'] = $filters['categoria'];
        }
        if (!empty($filters['autor'])) {
            $conditions[]    = 'si.author_id = (SELECT id FROM authors WHERE slug = :autor)';
            $params['autor'] = $filters['autor'];
        }
        if (!empty($filters['tipo'])) {
            $conditions[]   = 'si.editorial_type = :tipo';
            $params['tipo'] = $filters['tipo'];
        }
        if (!empty($filters['etiqueta'])) {
            $conditions[]       = 'si.article_id IN (SELECT at.article_id FROM article_tags at
                                     JOIN tags t ON t.id = at.tag_id WHERE t.slug = :etiqueta)';
            $params['etiqueta'] = $filters['etiqueta'];
        }
        if (!empty($filters['tema'])) {
            $conditions[]   = 'si.article_id IN (SELECT atp.article_id FROM article_topics atp
                                 JOIN topics tp ON tp.id = atp.topic_id WHERE tp.slug = :tema)';
            $params['tema'] = $filters['tema'];
        }
        if (isset($filters['video']) && $filters['video'] !== '') {
            $conditions[] = $filters['video'] === 'si' ? 'si.has_video = 1' : 'si.has_video = 0';
        }
        if (!empty($filters['live'])) {
            $conditions[]   = 'si.live_id = (SELECT id FROM lives WHERE slug = :live)';
            $params['live'] = $filters['live'];
        }

        return [$conditions, $params];
    }

    /**
     * FULLTEXT solo sirve si al menos un termino alcanza la longitud
     * minima del indice. Si no, se cae al respaldo LIKE.
     */
    private static function fulltextUsable(string $query): bool
    {
        static $minLength = null;
        if ($minLength === null) {
            $value     = Database::value("SELECT @@innodb_ft_min_token_size");
            $minLength = $value === null ? 3 : (int) $value;
        }

        foreach (preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $term) {
            if (mb_strlen(trim($term, '"*+-')) >= $minLength) {
                return true;
            }
        }
        return false;
    }

    /** Consulta booleana segura: sin operadores inyectados por el visitante. */
    private static function toBooleanQuery(string $query): string
    {
        $terms = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $clean = [];

        foreach (array_slice($terms, 0, 12) as $term) {
            $term = preg_replace('/[+\-><\(\)~*"@]/u', '', $term) ?? '';
            if (mb_strlen($term) < 2) {
                continue;
            }
            $clean[] = '+' . $term . '*';
        }

        return $clean === [] ? '' : implode(' ', $clean);
    }

    /** Sugerencias rapidas para el campo de busqueda. */
    public static function suggest(string $query, int $limit = 6): array
    {
        if (mb_strlen(trim($query)) < 2) {
            return [];
        }
        return Database::all(
            'SELECT a.slug, si.title, si.editorial_type, si.published_at
               FROM search_index si
               JOIN articles a ON a.id = si.article_id AND a.deleted_at IS NULL
              WHERE si.entity_type = "articulo"
                AND si.status IN ("publicada","actualizada","archivada")
                AND (si.title LIKE :like OR si.keywords LIKE :like)
              ORDER BY si.published_at DESC LIMIT ' . (int) $limit,
            ['like' => '%' . trim($query) . '%']
        );
    }
}
