<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;

/**
 * Analitica propia por eventos, sin datos personales y sin terceros.
 *
 * Alimenta la metrica norte del documento maestro, la sesion informada
 * completa, y el Indice de Comprension. Se presenta siempre como senal de
 * producto, nunca como medicion cientifica.
 */
final class AnalyticsService
{
    public const EVENTS = [
        'lectura_50', 'lectura_90', 'capa_abierta', 'modo_profundidad',
        'pulso_inicial', 'pulso_informado', 'pregunta_enviada',
        'video_iniciado', 'transcripcion_abierta', 'fuentes_abiertas',
        'perspectivas_comparadas',
    ];

    public static function record(string $event, string $sessionToken, ?string $entityType = null, ?int $entityId = null, array $meta = []): bool
    {
        if (!in_array($event, self::EVENTS, true)) {
            return false;
        }

        Database::insert('analytics_events', [
            'event'         => $event,
            'entity_type'   => $entityType,
            'entity_id'     => $entityId,
            'session_token' => substr($sessionToken, 0, 32),
            'meta'          => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
        ]);

        return true;
    }

    /** Resumen para el panel: los ultimos N dias. */
    public static function summary(int $days = 30): array
    {
        $since = gmdate('Y-m-d H:i:s', time() - ($days * 86400));

        $counts = [];
        foreach (Database::all(
            'SELECT event, COUNT(*) AS total, COUNT(DISTINCT session_token) AS sesiones
               FROM analytics_events WHERE created_at >= :since GROUP BY event',
            ['since' => $since]
        ) as $row) {
            $counts[$row['event']] = [
                'total'    => (int) $row['total'],
                'sesiones' => (int) $row['sesiones'],
            ];
        }

        $totalSessions = (int) Database::value(
            'SELECT COUNT(DISTINCT session_token) FROM analytics_events WHERE created_at >= :since',
            ['since' => $since]
        );

        // Sesion informada completa: consumio contexto, comparo
        // perspectivas y cerro con pulso informado o pregunta.
        $informed = (int) Database::value(
            'SELECT COUNT(*) FROM (
                SELECT session_token
                  FROM analytics_events
                 WHERE created_at >= :since
                 GROUP BY session_token
                HAVING SUM(event IN ("capa_abierta","fuentes_abiertas","modo_profundidad")) > 0
                   AND SUM(event = "perspectivas_comparadas") > 0
                   AND SUM(event IN ("pulso_informado","pregunta_enviada")) > 0
             ) AS s',
            ['since' => $since]
        );

        return [
            'dias'                => $days,
            'sesiones'            => $totalSessions,
            'eventos'             => $counts,
            'sesion_informada'    => $informed,
            'sesion_informada_pct' => $totalSessions > 0 ? round($informed * 100 / $totalSessions, 1) : 0.0,
            'lectura_50_pct'      => self::depthPercentage($counts, 'lectura_50', $totalSessions),
            'lectura_90_pct'      => self::depthPercentage($counts, 'lectura_90', $totalSessions),
            'suficiente'          => $totalSessions >= 30,
        ];
    }

    private static function depthPercentage(array $counts, string $event, int $totalSessions): float
    {
        if ($totalSessions === 0) {
            return 0.0;
        }
        return round((($counts[$event]['sesiones'] ?? 0) * 100) / $totalSessions, 1);
    }

    public static function topArticles(int $days = 30, int $limit = 10): array
    {
        return Database::all(
            'SELECT a.id, a.slug, a.title, a.view_count,
                    SUM(ev.event = "lectura_90") AS lecturas_completas,
                    COUNT(DISTINCT ev.session_token) AS sesiones
               FROM analytics_events ev
               JOIN articles a ON a.id = ev.entity_id AND ev.entity_type = "articulo"
              WHERE ev.created_at >= :since AND a.deleted_at IS NULL
              GROUP BY a.id ORDER BY sesiones DESC LIMIT ' . (int) $limit,
            ['since' => gmdate('Y-m-d H:i:s', time() - ($days * 86400))]
        );
    }

    /** Token de sesion anonimo, guardado solo en la sesion del navegador. */
    public static function sessionToken(): string
    {
        if (empty($_SESSION['_analytics_token'])) {
            $_SESSION['_analytics_token'] = bin2hex(random_bytes(16));
        }
        return (string) $_SESSION['_analytics_token'];
    }

    /** Limpieza: la analitica cruda no se guarda para siempre. */
    public static function purgeOlderThan(int $days = 400): int
    {
        return Database::run(
            'DELETE FROM analytics_events WHERE created_at < :cutoff',
            ['cutoff' => gmdate('Y-m-d H:i:s', time() - ($days * 86400))]
        )->rowCount();
    }
}
