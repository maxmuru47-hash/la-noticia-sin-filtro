<?php
declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

/** Preguntas de la comunidad: contribucion estructurada, siempre moderada. */
final class Question
{
    public const STATUSES = [
        'pendiente'    => 'Pendiente',
        'aprobada'     => 'Aprobada',
        'seleccionada' => 'Seleccionada para el Live',
        'respondida'   => 'Respondida',
        'rechazada'    => 'Rechazada',
    ];

    public static function findById(int $id): ?array
    {
        return Database::first('SELECT * FROM community_questions WHERE id = :id', ['id' => $id]);
    }

    /** Preguntas visibles al publico: solo las que paso la moderacion. */
    public static function publicList(int $limit = 6, ?int $articleId = null): array
    {
        $where  = 'cq.status IN ("aprobada", "seleccionada", "respondida")';
        $params = [];
        if ($articleId !== null) {
            $where              .= ' AND cq.article_id = :article_id';
            $params['article_id'] = $articleId;
        }
        return Database::all(
            'SELECT cq.*, a.slug AS answered_slug, a.title AS answered_title
               FROM community_questions cq
               LEFT JOIN articles a ON a.id = cq.answered_article_id
              WHERE ' . $where . '
              ORDER BY FIELD(cq.status, "respondida", "seleccionada", "aprobada"), cq.created_at DESC
              LIMIT ' . (int) $limit,
            $params
        );
    }

    public static function forModeration(string $status, int $limit, int $offset): array
    {
        $where  = $status === 'todas' ? '1 = 1' : 'cq.status = :status';
        $params = $status === 'todas' ? [] : ['status' => $status];

        return [
            'items' => Database::all(
                'SELECT cq.*, a.title AS article_title, a.slug AS article_slug, l.title AS live_title
                   FROM community_questions cq
                   LEFT JOIN articles a ON a.id = cq.article_id
                   LEFT JOIN lives l ON l.id = cq.live_id
                  WHERE ' . $where . '
                  ORDER BY cq.created_at DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
                $params
            ),
            'total' => (int) Database::value('SELECT COUNT(*) FROM community_questions cq WHERE ' . $where, $params),
        ];
    }

    public static function pendingCount(): int
    {
        return (int) Database::value('SELECT COUNT(*) FROM community_questions WHERE status = "pendiente"');
    }

    /** Cuantas ha enviado esta huella en la ultima hora. Control de abuso. */
    public static function recentBySubmitter(string $submitterHash): int
    {
        return (int) Database::value(
            'SELECT COUNT(*) FROM community_questions
              WHERE submitter_hash = :hash AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)',
            ['hash' => $submitterHash]
        );
    }
}
