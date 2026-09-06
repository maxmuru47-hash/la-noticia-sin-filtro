<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\Str;
use RuntimeException;

/**
 * Todo lo que convierte una pieza en una Noticia Viva: pulso, fuentes,
 * cronología, actores, consecuencias, herencia y relaciones.
 *
 * Antes esto solo existía porque lo sembraba un script. Ahora se crea desde
 * el panel, que era el punto: Max tiene que poder publicar sin tocar SQL.
 *
 * Igual que ArticleService, este servicio es el ÚNICO sitio donde se
 * escriben estas tablas. Los controladores no tocan la base directamente.
 */
final class EditorialService
{
    public const PERFILES = [
        'familia'    => 'Familia',
        'negocio'    => 'Negocio',
        'trabajador' => 'Trabajador',
        'diaspora'   => 'Diáspora',
        'general'    => 'General',
    ];

    public const CERTEZAS = [
        'confirmado' => 'Confirmado',
        'probable'   => 'Probable',
        'disputado'  => 'Disputado',
    ];

    public const RELACIONES = [
        'contexto'    => 'Da contexto',
        'actualiza'   => 'Actualiza a',
        'contradice'  => 'Contradice a',
        'continua'    => 'Continúa a',
        'relacionada' => 'Relacionada',
    ];

    // -----------------------------------------------------------------
    // PULSO
    // -----------------------------------------------------------------

    /**
     * Crea o actualiza el pulso de una pieza.
     *
     * Reglas que se aplican aquí y no se negocian:
     *  - Los RESULTADOS nunca se tocan. Este método no escribe en
     *    poll_responses, y no existe ningún método que lo haga desde el panel.
     *  - Una opción que ya tiene votos no se puede eliminar: borrarla
     *    falsearía el resultado histórico.
     *  - El texto de una opción sí se puede corregir, porque corregir una
     *    errata no cambia lo que votó nadie.
     *
     * @param array<int,string> $opciones Una etiqueta por elemento.
     */
    public static function guardarPulso(int $articleId, string $pregunta, array $opciones, ?string $metodologia, bool $abierto): int
    {
        $pregunta = trim($pregunta);
        if ($pregunta === '') {
            throw new RuntimeException('El pulso necesita una pregunta.');
        }

        $limpias = [];
        foreach ($opciones as $etiqueta) {
            $etiqueta = trim($etiqueta);
            if ($etiqueta !== '') {
                $limpias[] = mb_substr($etiqueta, 0, 190);
            }
        }

        if (count($limpias) < 2) {
            throw new RuntimeException('Un pulso necesita al menos dos opciones. Con una sola no se mide nada.');
        }
        if (count($limpias) > 6) {
            throw new RuntimeException('Máximo seis opciones: más que eso y la gente no responde.');
        }

        $existente = Database::first(
            'SELECT * FROM polls WHERE article_id = :id AND scope = "articulo" ORDER BY id DESC LIMIT 1',
            ['id' => $articleId]
        );

        return Database::transaction(static function () use ($articleId, $pregunta, $limpias, $metodologia, $abierto, $existente): int {
            if ($existente === null) {
                $pollId = Database::insert('polls', [
                    'uuid'             => Str::uuid(),
                    'article_id'       => $articleId,
                    'scope'            => 'articulo',
                    'question'         => $pregunta,
                    'methodology_note' => self::nulo($metodologia),
                    'is_open'          => $abierto ? 1 : 0,
                ]);

                foreach ($limpias as $posicion => $etiqueta) {
                    Database::insert('poll_options', [
                        'poll_id'  => $pollId,
                        'label'    => $etiqueta,
                        'position' => $posicion,
                    ]);
                }

                AuditService::log('pulso.crear', 'pulso', $pollId, $pregunta);
                return $pollId;
            }

            $pollId = (int) $existente['id'];

            Database::update('polls', [
                'question'         => $pregunta,
                'methodology_note' => self::nulo($metodologia),
                'is_open'          => $abierto ? 1 : 0,
            ], 'id = :id', ['id' => $pollId]);

            $actuales = Database::all(
                'SELECT po.id, po.label,
                        (SELECT COUNT(*) FROM poll_responses pr WHERE pr.option_id = po.id) AS votos
                   FROM poll_options po WHERE po.poll_id = :id ORDER BY po.position, po.id',
                ['id' => $pollId]
            );

            // Se corrigen las etiquetas existentes en su mismo orden.
            foreach ($actuales as $posicion => $opcion) {
                if (isset($limpias[$posicion])) {
                    Database::update('poll_options', [
                        'label'    => $limpias[$posicion],
                        'position' => $posicion,
                    ], 'id = :id', ['id' => (int) $opcion['id']]);
                } elseif ((int) $opcion['votos'] > 0) {
                    // Tiene votos: se queda. Quitarla falsearía el histórico.
                    throw new RuntimeException(
                        'No se puede quitar la opción «' . $opcion['label'] . '»: ya tiene ' .
                        (int) $opcion['votos'] . ' voto(s). Los resultados no se alteran.'
                    );
                } else {
                    Database::run('DELETE FROM poll_options WHERE id = :id', ['id' => (int) $opcion['id']]);
                }
            }

            // Y se añaden las nuevas.
            for ($i = count($actuales); $i < count($limpias); $i++) {
                Database::insert('poll_options', [
                    'poll_id'  => $pollId,
                    'label'    => $limpias[$i],
                    'position' => $i,
                ]);
            }

            AuditService::log('pulso.editar', 'pulso', $pollId, $pregunta);
            return $pollId;
        });
    }

    // -----------------------------------------------------------------
    // FUENTES
    // -----------------------------------------------------------------

    /** Crea la fuente si hace falta y la enlaza con la pieza. */
    public static function adjuntarFuente(int $articleId, array $datos): int
    {
        $titulo = trim((string) ($datos['titulo'] ?? ''));
        $id     = (int) ($datos['fuente_id'] ?? 0);

        if ($id === 0) {
            if ($titulo === '') {
                throw new RuntimeException('Elige una fuente del catálogo o escribe el título de una nueva.');
            }

            $url = self::urlSegura((string) ($datos['url'] ?? ''));

            $id = Database::insert('sources', [
                'title'       => mb_substr($titulo, 0, 255),
                'publisher'   => self::nulo($datos['editor'] ?? null),
                'url'         => $url,
                'source_type' => in_array($datos['tipo'] ?? '', ['documento', 'declaracion', 'medio', 'dato', 'entrevista', 'otro'], true)
                    ? $datos['tipo'] : 'otro',
                'published_on' => self::fechaONulo($datos['fecha'] ?? null),
                'notes'       => self::nulo($datos['notas'] ?? null),
            ]);
        }

        $certeza = in_array($datos['certeza'] ?? '', array_keys(self::CERTEZAS), true) ? $datos['certeza'] : 'confirmado';

        Database::run(
            'INSERT INTO article_sources (article_id, source_id, position, certainty)
             VALUES (:a, :s, :p, :c)
             ON DUPLICATE KEY UPDATE certainty = VALUES(certainty), position = VALUES(position)',
            [
                'a' => $articleId,
                's' => $id,
                'p' => (int) ($datos['orden'] ?? 0),
                'c' => $certeza,
            ]
        );

        AuditService::log('articulo.adjuntar_fuente', 'articulo', $articleId, 'Fuente ' . $id);
        SearchService::reindexArticle($articleId);

        return $id;
    }

    public static function quitarFuente(int $articleId, int $sourceId): void
    {
        Database::run('DELETE FROM article_sources WHERE article_id = :a AND source_id = :s',
            ['a' => $articleId, 's' => $sourceId]);
        AuditService::log('articulo.quitar_fuente', 'articulo', $articleId, 'Fuente ' . $sourceId);
        SearchService::reindexArticle($articleId);
    }

    // -----------------------------------------------------------------
    // CRONOLOGÍA
    // -----------------------------------------------------------------

    /** Un evento pertenece a una pieza, a un expediente, o a los dos. */
    public static function anadirEvento(array $datos): int
    {
        $titulo = trim((string) ($datos['titulo'] ?? ''));
        $fecha  = self::fechaONulo($datos['fecha'] ?? null);

        if ($titulo === '') {
            throw new RuntimeException('El evento necesita un título.');
        }
        if ($fecha === null) {
            throw new RuntimeException('El evento necesita una fecha válida.');
        }

        $articleId = self::idONulo($datos['articulo'] ?? null);
        $dossierId = self::idONulo($datos['expediente'] ?? null);

        if ($articleId === null && $dossierId === null) {
            throw new RuntimeException('El evento tiene que colgar de una pieza o de un expediente.');
        }

        $id = Database::insert('timeline_events', [
            'dossier_id'    => $dossierId,
            'article_id'    => $articleId,
            'occurred_on'   => $fecha,
            'occurred_time' => self::horaONula($datos['hora'] ?? null),
            'title'         => mb_substr($titulo, 0, 255),
            'detail'        => self::nulo($datos['detalle'] ?? null),
            'certainty'     => in_array($datos['certeza'] ?? '', array_keys(self::CERTEZAS), true) ? $datos['certeza'] : 'confirmado',
            'source_id'     => self::idONulo($datos['fuente'] ?? null),
            'position'      => (int) ($datos['orden'] ?? 0),
        ]);

        AuditService::log('cronologia.anadir', $articleId !== null ? 'articulo' : 'expediente',
            $articleId ?? $dossierId, $titulo);

        if ($articleId !== null) {
            SearchService::reindexArticle($articleId);
        }

        return $id;
    }

    public static function quitarEvento(int $eventId): void
    {
        $evento = Database::first('SELECT article_id, title FROM timeline_events WHERE id = :id', ['id' => $eventId]);
        Database::run('DELETE FROM timeline_events WHERE id = :id', ['id' => $eventId]);

        AuditService::log('cronologia.quitar', 'evento', $eventId, (string) ($evento['title'] ?? ''));

        if ($evento !== null && $evento['article_id'] !== null) {
            SearchService::reindexArticle((int) $evento['article_id']);
        }
    }

    // -----------------------------------------------------------------
    // ACTORES
    // -----------------------------------------------------------------

    public static function adjuntarActor(int $articleId, array $datos): int
    {
        $nombre = trim((string) ($datos['nombre'] ?? ''));
        $id     = (int) ($datos['actor_id'] ?? 0);

        if ($id === 0) {
            if ($nombre === '') {
                throw new RuntimeException('Elige un actor del catálogo o escribe el nombre de uno nuevo.');
            }

            $slug = Str::slug($nombre, 160);
            $existente = Database::value('SELECT id FROM actors WHERE slug = :s', ['s' => $slug]);

            $id = $existente !== null ? (int) $existente : Database::insert('actors', [
                'slug'        => $slug,
                'name'        => mb_substr($nombre, 0, 190),
                'actor_type'  => in_array($datos['tipo'] ?? '', ['persona', 'institucion', 'empresa', 'colectivo', 'otro'], true)
                    ? $datos['tipo'] : 'persona',
                'description' => self::nulo($datos['descripcion'] ?? null),
            ]);
        }

        Database::run(
            'INSERT INTO article_actors (article_id, actor_id, role_note, position)
             VALUES (:a, :ac, :n, :p)
             ON DUPLICATE KEY UPDATE role_note = VALUES(role_note), position = VALUES(position)',
            [
                'a'  => $articleId,
                'ac' => $id,
                'n'  => self::nulo($datos['papel'] ?? null),
                'p'  => (int) ($datos['orden'] ?? 0),
            ]
        );

        AuditService::log('articulo.adjuntar_actor', 'articulo', $articleId, 'Actor ' . $id);
        SearchService::reindexArticle($articleId);

        return $id;
    }

    public static function quitarActor(int $articleId, int $actorId): void
    {
        Database::run('DELETE FROM article_actors WHERE article_id = :a AND actor_id = :ac',
            ['a' => $articleId, 'ac' => $actorId]);
        AuditService::log('articulo.quitar_actor', 'articulo', $articleId, 'Actor ' . $actorId);
        SearchService::reindexArticle($articleId);
    }

    // -----------------------------------------------------------------
    // CONSECUENCIAS
    // -----------------------------------------------------------------

    public static function anadirImpacto(int $articleId, array $datos): int
    {
        $titular = trim((string) ($datos['titular'] ?? ''));
        if ($titular === '') {
            throw new RuntimeException('La consecuencia necesita un titular: qué cambia, en una frase.');
        }

        $id = Database::insert('impact_profiles', [
            'article_id' => $articleId,
            'profile'    => array_key_exists($datos['perfil'] ?? '', self::PERFILES) ? $datos['perfil'] : 'general',
            'headline'   => mb_substr($titular, 0, 255),
            'detail'     => self::nulo($datos['detalle'] ?? null),
            'certainty'  => in_array($datos['certeza'] ?? '', array_keys(self::CERTEZAS), true) ? $datos['certeza'] : 'probable',
            'position'   => (int) ($datos['orden'] ?? 0),
        ]);

        AuditService::log('articulo.anadir_impacto', 'articulo', $articleId, $titular);
        SearchService::reindexArticle($articleId);

        return $id;
    }

    public static function quitarImpacto(int $impactId): void
    {
        $fila = Database::first('SELECT article_id, headline FROM impact_profiles WHERE id = :id', ['id' => $impactId]);
        Database::run('DELETE FROM impact_profiles WHERE id = :id', ['id' => $impactId]);

        AuditService::log('articulo.quitar_impacto', 'impacto', $impactId, (string) ($fila['headline'] ?? ''));

        if ($fila !== null) {
            SearchService::reindexArticle((int) $fila['article_id']);
        }
    }

    // -----------------------------------------------------------------
    // HERENCIA Y RELACIONES
    // -----------------------------------------------------------------

    /** De qué pieza y de qué duda de la audiencia nació esta noticia. */
    public static function fijarHerencia(int $articleId, ?int $parentId, ?int $questionId, ?string $nota): void
    {
        if ($parentId === $articleId) {
            throw new RuntimeException('Una pieza no puede nacer de sí misma.');
        }

        Database::run('DELETE FROM article_lineage WHERE article_id = :id', ['id' => $articleId]);

        if ($parentId === null && $questionId === null) {
            AuditService::log('articulo.quitar_herencia', 'articulo', $articleId);
            return;
        }

        Database::insert('article_lineage', [
            'article_id'        => $articleId,
            'parent_article_id' => $parentId,
            'question_id'       => $questionId,
            'note'              => self::nulo($nota),
        ]);

        AuditService::log('articulo.fijar_herencia', 'articulo', $articleId,
            'Nace de la pieza ' . ($parentId ?? '—') . ' y la pregunta ' . ($questionId ?? '—'));
    }

    public static function relacionar(int $articleId, int $relatedId, string $relacion, int $orden = 0): void
    {
        if ($articleId === $relatedId) {
            throw new RuntimeException('Una pieza no se relaciona consigo misma.');
        }

        Database::run(
            'INSERT INTO article_relations (article_id, related_id, relation, position)
             VALUES (:a, :r, :rel, :p)
             ON DUPLICATE KEY UPDATE relation = VALUES(relation), position = VALUES(position)',
            [
                'a'   => $articleId,
                'r'   => $relatedId,
                'rel' => array_key_exists($relacion, self::RELACIONES) ? $relacion : 'relacionada',
                'p'   => $orden,
            ]
        );

        AuditService::log('articulo.relacionar', 'articulo', $articleId, 'Con la pieza ' . $relatedId);
    }

    public static function desrelacionar(int $articleId, int $relatedId): void
    {
        Database::run('DELETE FROM article_relations WHERE article_id = :a AND related_id = :r',
            ['a' => $articleId, 'r' => $relatedId]);
        AuditService::log('articulo.desrelacionar', 'articulo', $articleId, 'De la pieza ' . $relatedId);
    }

    /** @return array<int,array<string,mixed>> */
    public static function relacionadasManuales(int $articleId): array
    {
        return Database::all(
            'SELECT a.id, a.slug, a.title, a.status, ar.relation, ar.position
               FROM article_relations ar
               JOIN articles a ON a.id = ar.related_id
              WHERE ar.article_id = :id AND a.deleted_at IS NULL
              ORDER BY ar.position, a.published_at DESC',
            ['id' => $articleId]
        );
    }

    // -----------------------------------------------------------------
    // RESTAURAR UNA VERSIÓN
    // -----------------------------------------------------------------

    /**
     * Devuelve el contenido de una versión anterior.
     *
     * NO revive una pieza eliminada ni cambia su estado ni su dirección:
     * restaura solo el texto. La URL sigue siendo intocable, y el propio
     * acto de restaurar deja una versión nueva en el historial, así que
     * tampoco se pierde lo que había antes de restaurar.
     */
    public static function restaurarRevision(int $articleId, int $revisionNo): void
    {
        $revision = Database::first(
            'SELECT * FROM article_revisions WHERE article_id = :a AND revision_no = :n',
            ['a' => $articleId, 'n' => $revisionNo]
        );

        if ($revision === null) {
            throw new RuntimeException('Esa versión no existe.');
        }

        $copia = json_decode((string) $revision['snapshot'], true);
        if (!is_array($copia)) {
            throw new RuntimeException('Esa versión no guardó una copia utilizable.');
        }

        // Solo se devuelve el CONTENIDO. Nada de estado, fecha ni dirección.
        $campos = [
            'title', 'subtitle', 'lead_question', 'summary', 'summary_60s', 'summary_5min',
            'body_blocks', 'body_plain', 'editorial_type', 'facts_confirmed', 'facts_probable',
            'facts_unknown', 'perspective_a_title', 'perspective_a_body',
            'perspective_b_title', 'perspective_b_body', 'max_opinion', 'legacy_question',
        ];

        $datos = [];
        foreach ($campos as $campo) {
            if (array_key_exists($campo, $copia)) {
                $datos[$campo] = $copia[$campo];
            }
        }

        if ($datos === []) {
            throw new RuntimeException('Esa versión no contiene texto que restaurar.');
        }

        Database::update('articles', $datos, 'id = :id', ['id' => $articleId]);

        // Restaurar es un cambio más: deja su propia versión en el historial.
        ArticleService::snapshot($articleId, 'Restaurada la versión #' . $revisionNo);
        AuditService::log('articulo.restaurar_version', 'articulo', $articleId, 'Versión #' . $revisionNo);
        SearchService::reindexArticle($articleId);
    }

    // -----------------------------------------------------------------

    private static function nulo(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        $valor = trim((string) $valor);
        return $valor === '' ? null : $valor;
    }

    private static function idONulo(mixed $valor): ?int
    {
        return is_numeric($valor) && (int) $valor > 0 ? (int) $valor : null;
    }

    private static function fechaONulo(mixed $valor): ?string
    {
        $valor = trim((string) ($valor ?? ''));
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) === 1 ? $valor : null;
    }

    private static function horaONula(mixed $valor): ?string
    {
        $valor = trim((string) ($valor ?? ''));
        return preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $valor) === 1
            ? (strlen($valor) === 5 ? $valor . ':00' : $valor)
            : null;
    }

    private static function urlSegura(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        return filter_var($url, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }
}
