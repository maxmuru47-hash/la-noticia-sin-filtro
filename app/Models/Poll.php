<?php
declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

/**
 * El Pulso que cambia: la misma pregunta antes y despues de leer.
 *
 * Los resultados nunca se tocan a mano. Los porcentajes se calculan a
 * partir de poll_responses, y la metodologia se muestra al lector.
 */
final class Poll
{
    public static function forArticle(int $articleId): ?array
    {
        return Database::first(
            'SELECT * FROM polls WHERE article_id = :id AND scope = "articulo" ORDER BY id DESC LIMIT 1',
            ['id' => $articleId]
        );
    }

    public static function pulseOfTheDay(): ?array
    {
        return Database::first(
            'SELECT p.*, a.slug AS article_slug, a.title AS article_title
               FROM polls p LEFT JOIN articles a ON a.id = p.article_id
              WHERE p.scope = "dia" AND p.is_open = 1
              ORDER BY p.id DESC LIMIT 1'
        );
    }

    public static function findById(int $id): ?array
    {
        return Database::first('SELECT * FROM polls WHERE id = :id', ['id' => $id]);
    }

    public static function options(int $pollId): array
    {
        return Database::all(
            'SELECT * FROM poll_options WHERE poll_id = :id ORDER BY position, id',
            ['id' => $pollId]
        );
    }

    /**
     * Resultados por etapa con porcentajes y el desplazamiento entre el
     * pulso inicial y el informado. Ese desplazamiento es la metrica que
     * distingue al medio: mide reflexion, no clics.
     */
    public static function results(int $pollId): array
    {
        $options = self::options($pollId);

        $rows = Database::all(
            'SELECT option_id, stage, COUNT(*) AS total
               FROM poll_responses WHERE poll_id = :id
              GROUP BY option_id, stage',
            ['id' => $pollId]
        );

        $counts = ['inicial' => [], 'informado' => []];
        foreach ($rows as $row) {
            $counts[$row['stage']][(int) $row['option_id']] = (int) $row['total'];
        }

        $totals = [
            'inicial'   => array_sum($counts['inicial']),
            'informado' => array_sum($counts['informado']),
        ];

        $result = [];
        foreach ($options as $option) {
            $id      = (int) $option['id'];
            $initial = $counts['inicial'][$id] ?? 0;
            $informed = $counts['informado'][$id] ?? 0;

            $initialPct  = $totals['inicial'] > 0 ? round($initial * 100 / $totals['inicial'], 1) : 0.0;
            $informedPct = $totals['informado'] > 0 ? round($informed * 100 / $totals['informado'], 1) : 0.0;

            $result[] = [
                'id'            => $id,
                'label'         => $option['label'],
                'inicial'       => $initial,
                'informado'     => $informed,
                'inicial_pct'   => $initialPct,
                'informado_pct' => $informedPct,
                'cambio_pct'    => round($informedPct - $initialPct, 1),
            ];
        }

        return [
            'opciones'        => $result,
            'total_inicial'   => $totals['inicial'],
            'total_informado' => $totals['informado'],
            'suficiente'      => $totals['inicial'] >= 10,
        ];
    }

    /**
     * Cuantas personas cambiaron de opcion entre las dos etapas.
     * Se cruza por session_token, no por identidad.
     */
    public static function opinionShift(int $pollId): array
    {
        $rows = Database::all(
            'SELECT r1.session_token,
                    r1.option_id AS antes,
                    r2.option_id AS despues
               FROM poll_responses r1
               JOIN poll_responses r2
                 ON r2.poll_id = r1.poll_id
                AND r2.session_token = r1.session_token
                AND r2.stage = "informado"
              WHERE r1.poll_id = :id AND r1.stage = "inicial"',
            ['id' => $pollId]
        );

        $completed = count($rows);
        $changed   = 0;
        foreach ($rows as $row) {
            if ((int) $row['antes'] !== (int) $row['despues']) {
                $changed++;
            }
        }

        return [
            'completaron'  => $completed,
            'cambiaron'    => $changed,
            'porcentaje'   => $completed > 0 ? round($changed * 100 / $completed, 1) : 0.0,
            'suficiente'   => $completed >= 10,
        ];
    }

    /** Ya voto esta persona en esta etapa? */
    public static function hasVoted(int $pollId, string $voterHash, string $stage): bool
    {
        return Database::value(
            'SELECT id FROM poll_responses WHERE poll_id = :id AND voter_hash = :hash AND stage = :stage',
            ['id' => $pollId, 'hash' => $voterHash, 'stage' => $stage]
        ) !== null;
    }

    public static function optionBelongsToPoll(int $optionId, int $pollId): bool
    {
        return Database::value(
            'SELECT id FROM poll_options WHERE id = :oid AND poll_id = :pid',
            ['oid' => $optionId, 'pid' => $pollId]
        ) !== null;
    }
}
