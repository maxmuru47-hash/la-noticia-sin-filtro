<?php
declare(strict_types=1);

namespace App\Services;

use App\Middleware\Auth;
use App\Models\Article;
use App\Support\Database;
use App\Support\Dates;
use App\Support\Html;
use App\Support\Str;
use RuntimeException;

/**
 * REGLAS INNEGOCIABLES DE PERMANENCIA
 *
 * 1. Archivar no es eliminar. Una archivada sale de portada y sigue en su
 *    URL, en el buscador, en el archivo y en las taxonomias.
 * 2. Cambiar el titulo NUNCA cambia la URL ya publicada.
 * 3. Si una URL cambia de forma excepcional, se crea una redireccion 301.
 * 4. Una URL usada jamas se reutiliza para otra noticia.
 * 5. Toda correccion relevante deja fecha, motivo y cambio.
 * 6. Una retirada conserva una pagina que explica el motivo.
 * 7. La eliminacion definitiva exige rol superior, confirmacion reforzada
 *    y registro de auditoria.
 *
 * Este servicio es el unico lugar donde esas reglas se aplican. Ningun
 * controlador escribe directamente en la tabla articles.
 */
final class ArticleService
{
    /** Campos que se vigilan para el historial de cambios. */
    private const TRACKED_FIELDS = [
        'title', 'subtitle', 'lead_question', 'summary', 'summary_60s', 'summary_5min',
        'body_blocks', 'editorial_type', 'status', 'category_id', 'author_id', 'editor_id',
        'facts_confirmed', 'facts_probable', 'facts_unknown', 'max_opinion', 'legacy_question',
    ];

    /**
     * Genera un slug libre. Nunca devuelve uno ya usado por otra pieza,
     * ni uno quemado por una noticia anterior.
     */
    public static function generateSlug(string $title, ?int $ignoreArticleId = null): string
    {
        $base = Str::slug($title);
        $slug = $base;
        $n    = 2;

        while (self::slugTaken($slug, $ignoreArticleId)) {
            $slug = $base . '-' . $n;
            $n++;
            if ($n > 200) {
                $slug = $base . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
                break;
            }
        }

        return $slug;
    }

    private static function slugTaken(string $slug, ?int $ignoreArticleId): bool
    {
        $inUse = Database::value(
            'SELECT id FROM articles WHERE slug = :slug' . ($ignoreArticleId ? ' AND id <> :ignore' : ''),
            $ignoreArticleId ? ['slug' => $slug, 'ignore' => $ignoreArticleId] : ['slug' => $slug]
        );
        if ($inUse !== null) {
            return true;
        }

        // Slug quemado: pertenecio a otra noticia y no se reutiliza.
        $reserved = Database::first('SELECT article_id FROM reserved_slugs WHERE slug = :slug', ['slug' => $slug]);
        if ($reserved === null) {
            return false;
        }
        return $ignoreArticleId === null || (int) $reserved['article_id'] !== $ignoreArticleId;
    }

    public static function create(array $data): int
    {
        $title = trim((string) ($data['title'] ?? 'Sin titulo'));
        $slug  = trim((string) ($data['slug'] ?? '')) !== ''
            ? self::generateSlug((string) $data['slug'])
            : self::generateSlug($title);

        $blocks = BlockRenderer::decode($data['body_blocks'] ?? null);
        $plain  = BlockRenderer::toPlainText($blocks);

        $id = Database::transaction(static function () use ($data, $title, $slug, $blocks, $plain): int {
            $articleId = Database::insert('articles', [
                'uuid'            => Str::uuid(),
                'slug'            => $slug,
                'title'           => $title,
                'subtitle'        => self::nullable($data['subtitle'] ?? null),
                'lead_question'   => self::nullable($data['lead_question'] ?? null),
                'summary'         => self::nullable($data['summary'] ?? null),
                'summary_60s'     => self::nullable($data['summary_60s'] ?? null),
                'summary_5min'    => self::nullable($data['summary_5min'] ?? null),
                'body_blocks'     => json_encode($blocks, JSON_UNESCAPED_UNICODE),
                'body_plain'      => $plain,
                'editorial_type'  => self::validType($data['editorial_type'] ?? 'noticia'),
                'status'          => 'borrador',
                'category_id'     => self::nullableInt($data['category_id'] ?? null),
                'author_id'       => self::nullableInt($data['author_id'] ?? null),
                'editor_id'       => self::nullableInt($data['editor_id'] ?? null),
                'dossier_id'      => self::nullableInt($data['dossier_id'] ?? null),
                'facts_confirmed' => self::nullable($data['facts_confirmed'] ?? null),
                'facts_probable'  => self::nullable($data['facts_probable'] ?? null),
                'facts_unknown'   => self::nullable($data['facts_unknown'] ?? null),
                'perspective_a_title' => self::nullable($data['perspective_a_title'] ?? null),
                'perspective_a_body'  => self::nullable($data['perspective_a_body'] ?? null),
                'perspective_b_title' => self::nullable($data['perspective_b_title'] ?? null),
                'perspective_b_body'  => self::nullable($data['perspective_b_body'] ?? null),
                'max_opinion'     => self::nullable($data['max_opinion'] ?? null),
                'legacy_question' => self::nullable($data['legacy_question'] ?? null),
                'hero_media_id'   => self::nullableInt($data['hero_media_id'] ?? null),
                'hero_alt'        => self::nullable($data['hero_alt'] ?? null),
                'reading_minutes' => Str::readingMinutes($plain),
                'is_demo'         => !empty($data['is_demo']) ? 1 : 0,
                'seo_title'       => self::nullable($data['seo_title'] ?? null),
                'seo_description' => self::nullable($data['seo_description'] ?? null),
                'social_title'    => self::nullable($data['social_title'] ?? null),
                'social_description' => self::nullable($data['social_description'] ?? null),
                'scheduled_for'   => self::nullable($data['scheduled_for'] ?? null),
            ]);

            // El slug queda reservado desde el minuto uno, incluso en borrador.
            Database::run(
                'INSERT INTO reserved_slugs (slug, article_id) VALUES (:slug, :id)
                 ON DUPLICATE KEY UPDATE article_id = VALUES(article_id)',
                ['slug' => $slug, 'id' => $articleId]
            );

            return $articleId;
        });

        self::snapshot($id, 'Creación de la pieza');
        AuditService::log('articulo.crear', 'articulo', $id, $title);
        SearchService::reindexArticle($id);

        return $id;
    }

    /**
     * Actualiza una pieza. El slug SOLO cambia si se pide de forma
     * explicita, y ese cambio deja siempre una redireccion 301.
     */
    public static function update(int $articleId, array $data, ?string $changeNote = null): void
    {
        $before = Article::findById($articleId);
        if ($before === null) {
            throw new RuntimeException('La pieza no existe.');
        }

        $blocks = BlockRenderer::decode($data['body_blocks'] ?? $before['body_blocks']);
        $plain  = BlockRenderer::toPlainText($blocks);

        $fields = [
            'title'           => trim((string) ($data['title'] ?? $before['title'])),
            'subtitle'        => self::nullable($data['subtitle'] ?? $before['subtitle']),
            'lead_question'   => self::nullable($data['lead_question'] ?? $before['lead_question']),
            'summary'         => self::nullable($data['summary'] ?? $before['summary']),
            'summary_60s'     => self::nullable($data['summary_60s'] ?? $before['summary_60s']),
            'summary_5min'    => self::nullable($data['summary_5min'] ?? $before['summary_5min']),
            'body_blocks'     => json_encode($blocks, JSON_UNESCAPED_UNICODE),
            'body_plain'      => $plain,
            'editorial_type'  => self::validType($data['editorial_type'] ?? $before['editorial_type']),
            'category_id'     => self::nullableInt($data['category_id'] ?? $before['category_id']),
            'author_id'       => self::nullableInt($data['author_id'] ?? $before['author_id']),
            'editor_id'       => self::nullableInt($data['editor_id'] ?? $before['editor_id']),
            'dossier_id'      => self::nullableInt($data['dossier_id'] ?? $before['dossier_id']),
            'facts_confirmed' => self::nullable($data['facts_confirmed'] ?? $before['facts_confirmed']),
            'facts_probable'  => self::nullable($data['facts_probable'] ?? $before['facts_probable']),
            'facts_unknown'   => self::nullable($data['facts_unknown'] ?? $before['facts_unknown']),
            'perspective_a_title' => self::nullable($data['perspective_a_title'] ?? $before['perspective_a_title']),
            'perspective_a_body'  => self::nullable($data['perspective_a_body'] ?? $before['perspective_a_body']),
            'perspective_b_title' => self::nullable($data['perspective_b_title'] ?? $before['perspective_b_title']),
            'perspective_b_body'  => self::nullable($data['perspective_b_body'] ?? $before['perspective_b_body']),
            'max_opinion'     => self::nullable($data['max_opinion'] ?? $before['max_opinion']),
            'legacy_question' => self::nullable($data['legacy_question'] ?? $before['legacy_question']),
            'hero_media_id'   => self::nullableInt($data['hero_media_id'] ?? $before['hero_media_id']),
            'hero_alt'        => self::nullable($data['hero_alt'] ?? $before['hero_alt']),
            'reading_minutes' => Str::readingMinutes($plain),
            'seo_title'       => self::nullable($data['seo_title'] ?? $before['seo_title']),
            'seo_description' => self::nullable($data['seo_description'] ?? $before['seo_description']),
            'social_title'    => self::nullable($data['social_title'] ?? $before['social_title']),
            'social_description' => self::nullable($data['social_description'] ?? $before['social_description']),
        ];

        // Una pieza ya publicada que se toca pasa a "actualizada" y deja
        // constancia de la hora del cambio.
        if (in_array($before['status'], ['publicada', 'actualizada'], true)) {
            $fields['status']             = 'actualizada';
            $fields['updated_content_at'] = Dates::nowUtc();
        }

        Database::update('articles', $fields, 'id = :id', ['id' => $articleId]);

        $changes = AuditService::diff($before, $fields, self::TRACKED_FIELDS);
        if ($changes !== []) {
            self::snapshot($articleId, $changeNote ?? 'Edición');
            AuditService::log('articulo.editar', 'articulo', $articleId, $fields['title'], $changes);
        }

        SearchService::reindexArticle($articleId);
    }

    /**
     * Cambio excepcional de URL. Deja redireccion 301 y quema el slug
     * antiguo para que nunca sirva a otra noticia.
     */
    public static function changeSlug(int $articleId, string $newSlugRaw, string $reason): string
    {
        $article = Article::findById($articleId);
        if ($article === null) {
            throw new RuntimeException('La pieza no existe.');
        }

        $oldSlug = (string) $article['slug'];
        $newSlug = self::generateSlug($newSlugRaw, $articleId);

        if ($newSlug === $oldSlug) {
            return $oldSlug;
        }

        Database::transaction(static function () use ($articleId, $oldSlug, $newSlug, $reason, $article): void {
            Database::update('articles', ['slug' => $newSlug], 'id = :id', ['id' => $articleId]);

            Database::run(
                'INSERT INTO reserved_slugs (slug, article_id) VALUES (:slug, :id)
                 ON DUPLICATE KEY UPDATE article_id = VALUES(article_id)',
                ['slug' => $newSlug, 'id' => $articleId]
            );

            // El slug viejo queda quemado, apuntando a esta misma pieza.
            Database::run(
                'INSERT INTO reserved_slugs (slug, article_id) VALUES (:slug, :id)
                 ON DUPLICATE KEY UPDATE article_id = VALUES(article_id)',
                ['slug' => $oldSlug, 'id' => $articleId]
            );

            // Solo se redirige lo que llego a estar publicado.
            if ($article['published_at'] !== null) {
                Database::run(
                    'INSERT INTO redirects (from_path, to_path, status_code, reason, created_by)
                     VALUES (:from, :to, 301, :reason, :user)
                     ON DUPLICATE KEY UPDATE to_path = VALUES(to_path), reason = VALUES(reason)',
                    [
                        'from'   => '/noticia/' . $oldSlug,
                        'to'     => '/noticia/' . $newSlug,
                        'reason' => substr($reason, 0, 255),
                        'user'   => Auth::id(),
                    ]
                );
            }
        });

        AuditService::log('articulo.cambiar_url', 'articulo', $articleId,
            'De /noticia/' . $oldSlug . ' a /noticia/' . $newSlug,
            ['motivo' => $reason]);

        SearchService::reindexArticle($articleId);

        return $newSlug;
    }

    public static function publish(int $articleId, ?string $publishAtUtc = null): void
    {
        $article = Article::findById($articleId);
        if ($article === null) {
            throw new RuntimeException('La pieza no existe.');
        }

        // La fecha de publicacion original nunca se sobrescribe al
        // republicar: eso mantiene coherente el archivo cronologico.
        $publishedAt = $article['published_at'] ?? ($publishAtUtc ?? Dates::nowUtc());

        Database::update('articles', [
            'status'        => 'publicada',
            'published_at'  => $publishedAt,
            'scheduled_for' => null,
            'archived_at'   => null,
        ], 'id = :id', ['id' => $articleId]);

        self::snapshot($articleId, 'Publicación');
        AuditService::log('articulo.publicar', 'articulo', $articleId, (string) $article['title']);
        SearchService::reindexArticle($articleId);
    }

    public static function schedule(int $articleId, string $scheduledForUtc): void
    {
        Database::update('articles', [
            'status'        => 'programada',
            'scheduled_for' => $scheduledForUtc,
        ], 'id = :id', ['id' => $articleId]);

        AuditService::log('articulo.programar', 'articulo', $articleId, 'Para ' . $scheduledForUtc);
        SearchService::reindexArticle($articleId);
    }

    /**
     * ARCHIVAR NO ES ELIMINAR.
     * Sale de portada y de los listados. Permanece en su URL, en el
     * buscador, en el archivo cronologico y en sus taxonomias.
     */
    public static function archive(int $articleId, string $reason = ''): void
    {
        $article = Article::findById($articleId);
        if ($article === null) {
            throw new RuntimeException('La pieza no existe.');
        }

        Database::update('articles', [
            'status'      => 'archivada',
            'archived_at' => Dates::nowUtc(),
        ], 'id = :id', ['id' => $articleId]);

        // Al archivar, la pieza deja de ocupar espacio en portada. El
        // articulo NO se toca: solo se retira la seleccion.
        Database::run('DELETE FROM homepage_slots WHERE article_id = :id', ['id' => $articleId]);

        AuditService::log('articulo.archivar', 'articulo', $articleId, (string) $article['title'],
            $reason === '' ? null : ['motivo' => $reason]);

        // Se reindexa, no se borra del indice: debe seguir encontrandose.
        SearchService::reindexArticle($articleId);
    }

    public static function unarchive(int $articleId): void
    {
        Database::update('articles', [
            'status'      => 'actualizada',
            'archived_at' => null,
        ], 'id = :id', ['id' => $articleId]);

        AuditService::log('articulo.desarchivar', 'articulo', $articleId);
        SearchService::reindexArticle($articleId);
    }

    /**
     * Retiro excepcional. La pagina sigue existiendo y explica el motivo,
     * salvo prohibicion legal, en cuyo caso se marca noindex.
     */
    public static function withdraw(int $articleId, string $publicReason, bool $noindex = false): void
    {
        if (trim($publicReason) === '') {
            throw new RuntimeException('Un retiro exige un motivo publico.');
        }

        Database::update('articles', [
            'status'           => 'retirada',
            'withdrawn_reason' => Html::sanitize($publicReason),
            'noindex'          => $noindex ? 1 : 0,
            'archived_at'      => Dates::nowUtc(),
        ], 'id = :id', ['id' => $articleId]);

        Database::run('DELETE FROM homepage_slots WHERE article_id = :id', ['id' => $articleId]);

        Database::insert('article_corrections', [
            'article_id' => $articleId,
            'user_id'    => Auth::id(),
            'kind'       => 'retiro',
            'reason'     => substr($publicReason, 0, 500),
            'detail'     => 'La pieza fue retirada. Esta página permanece para explicar el motivo.',
            'is_public'  => 1,
        ]);

        AuditService::log('articulo.retirar', 'articulo', $articleId, $publicReason);
        SearchService::reindexArticle($articleId);
    }

    /** Correccion visible: fecha, motivo y cambio realizado. */
    public static function addCorrection(int $articleId, string $kind, string $reason, string $detail, bool $isPublic = true): int
    {
        $id = Database::insert('article_corrections', [
            'article_id' => $articleId,
            'user_id'    => Auth::id(),
            'kind'       => in_array($kind, ['correccion', 'actualizacion', 'aclaracion', 'retiro'], true) ? $kind : 'actualizacion',
            'reason'     => substr(trim($reason), 0, 500),
            'detail'     => trim($detail),
            'is_public'  => $isPublic ? 1 : 0,
        ]);

        Database::update('articles', ['updated_content_at' => Dates::nowUtc()], 'id = :id', ['id' => $articleId]);

        AuditService::log('articulo.corregir', 'articulo', $articleId, $reason);
        return $id;
    }

    /**
     * ELIMINACION DEFINITIVA. Solo rol superior, solo con confirmacion
     * reforzada, siempre auditada, y el slug queda quemado para siempre.
     */
    public static function destroy(int $articleId, string $reason, string $typedConfirmation): void
    {
        $article = Article::findById($articleId);
        if ($article === null) {
            throw new RuntimeException('La pieza no existe.');
        }
        if (!Auth::can(Auth::CAP_ARTICLE_DESTROY)) {
            throw new RuntimeException('Esta acción exige un rol superior.');
        }
        if ($typedConfirmation !== $article['slug']) {
            throw new RuntimeException('La confirmación no coincide con la URL de la pieza.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Una eliminación definitiva exige un motivo registrado.');
        }

        AuditService::log('articulo.eliminar_definitivo', 'articulo', $articleId,
            (string) $article['title'],
            ['motivo' => $reason, 'slug' => $article['slug'], 'copia' => $article]);

        Database::update('articles', ['deleted_at' => Dates::nowUtc()], 'id = :id', ['id' => $articleId]);
        Database::run('DELETE FROM homepage_slots WHERE article_id = :id', ['id' => $articleId]);
        SearchService::removeArticle($articleId);
        // El slug NO se libera: sigue quemado en reserved_slugs.
    }

    /** Guarda una version completa en el historial. */
    public static function snapshot(int $articleId, string $changeNote): void
    {
        $article = Article::findById($articleId);
        if ($article === null) {
            return;
        }

        $next = (int) Database::value(
            'SELECT COALESCE(MAX(revision_no), 0) + 1 FROM article_revisions WHERE article_id = :id',
            ['id' => $articleId]
        );

        Database::insert('article_revisions', [
            'article_id'  => $articleId,
            'revision_no' => $next,
            'user_id'     => Auth::id(),
            'title'       => $article['title'],
            'subtitle'    => $article['subtitle'],
            'summary'     => $article['summary'],
            'body_blocks' => $article['body_blocks'],
            'status'      => $article['status'],
            'change_note' => substr($changeNote, 0, 500),
            'snapshot'    => json_encode($article, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** Publica las piezas programadas cuya hora ya llego. Lo llama el cron. */
    public static function publishDue(): int
    {
        $published = 0;
        foreach (Article::dueForPublishing() as $article) {
            self::publish((int) $article['id'], (string) $article['scheduled_for']);
            $published++;
        }
        return $published;
    }

    public static function syncTags(int $articleId, array $tagNames): void
    {
        Database::run('DELETE FROM article_tags WHERE article_id = :id', ['id' => $articleId]);
        foreach (array_unique($tagNames) as $name) {
            if (trim((string) $name) === '') {
                continue;
            }
            $tagId = \App\Models\Taxonomy::ensureTag((string) $name);
            Database::run(
                'INSERT IGNORE INTO article_tags (article_id, tag_id) VALUES (:a, :t)',
                ['a' => $articleId, 't' => $tagId]
            );
        }
    }

    public static function syncTopics(int $articleId, array $topicNames): void
    {
        Database::run('DELETE FROM article_topics WHERE article_id = :id', ['id' => $articleId]);
        foreach (array_unique($topicNames) as $name) {
            if (trim((string) $name) === '') {
                continue;
            }
            $topicId = \App\Models\Taxonomy::ensureTopic((string) $name);
            Database::run(
                'INSERT IGNORE INTO article_topics (article_id, topic_id) VALUES (:a, :t)',
                ['a' => $articleId, 't' => $topicId]
            );
        }
    }

    public static function attachVideo(int $articleId, int $videoId, string $role, int $position = 0): void
    {
        Database::run(
            'INSERT INTO article_video_relations (article_id, video_id, editorial_role, position)
             VALUES (:a, :v, :r, :p)
             ON DUPLICATE KEY UPDATE editorial_role = VALUES(editorial_role), position = VALUES(position)',
            ['a' => $articleId, 'v' => $videoId, 'r' => $role, 'p' => $position]
        );
        AuditService::log('articulo.relacionar_video', 'articulo', $articleId, 'Video ' . $videoId . ' como ' . $role);
        SearchService::reindexArticle($articleId);
    }

    public static function detachVideo(int $articleId, int $videoId): void
    {
        Database::run(
            'DELETE FROM article_video_relations WHERE article_id = :a AND video_id = :v',
            ['a' => $articleId, 'v' => $videoId]
        );
        SearchService::reindexArticle($articleId);
    }

    public static function attachLive(int $articleId, int $liveId, string $moment): void
    {
        Database::run(
            'INSERT INTO live_articles (live_id, article_id, moment) VALUES (:l, :a, :m)
             ON DUPLICATE KEY UPDATE moment = VALUES(moment)',
            ['l' => $liveId, 'a' => $articleId, 'm' => $moment === 'despues' ? 'despues' : 'antes']
        );
        AuditService::log('articulo.relacionar_live', 'articulo', $articleId, 'Live ' . $liveId);
        SearchService::reindexArticle($articleId);
    }

    private static function nullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return (int) $value > 0 ? (int) $value : null;
    }

    private static function validType(mixed $type): string
    {
        return array_key_exists((string) $type, Article::EDITORIAL_TYPES) ? (string) $type : 'noticia';
    }
}
