<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Middleware\Auth;
use App\Models\Question;
use App\Services\AnalyticsService;
use App\Support\Database;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

final class DashboardController
{
    public function __construct(private readonly array $config)
    {
    }

    public function index(Request $request): void
    {
        Auth::requireLogin();

        $conteos = Database::first(
            'SELECT
                SUM(status = "borrador")   AS borradores,
                SUM(status = "revision")   AS revision,
                SUM(status = "programada") AS programadas,
                SUM(status IN ("publicada","actualizada")) AS publicadas,
                SUM(status = "archivada")  AS archivadas,
                SUM(status = "retirada")   AS retiradas,
                COUNT(*) AS total
               FROM articles WHERE deleted_at IS NULL'
        ) ?? [];

        Response::securityHeaders(false);
        Response::html(View::render('admin/tablero', [
            'titulo'    => 'Panel · ' . $this->config['app']['name'],
            'noindex'   => true,
            'conteos'   => $conteos,
            'pendientes' => Question::pendingCount(),
            'recientes' => Database::all(
                'SELECT a.id, a.title, a.slug, a.status, a.updated_at, a.published_at, au.name AS author_name
                   FROM articles a LEFT JOIN authors au ON au.id = a.author_id
                  WHERE a.deleted_at IS NULL
                  ORDER BY a.updated_at DESC LIMIT 8'
            ),
            'programadas' => Database::all(
                'SELECT id, title, slug, scheduled_for FROM articles
                  WHERE status = "programada" AND deleted_at IS NULL
                  ORDER BY scheduled_for ASC LIMIT 5'
            ),
            'proximoLive' => \App\Models\Live::next(),
            'auditoria'   => Database::all('SELECT * FROM audit_logs ORDER BY id DESC LIMIT 8'),
        ], 'layouts/admin'));
    }

    public function metrics(Request $request): void
    {
        Auth::require(Auth::CAP_METRICS_VIEW);

        $dias = max(7, min(365, (int) $request->int('dias', 30)));

        Response::securityHeaders(false);
        Response::html(View::render('admin/metricas', [
            'titulo'   => 'Métricas · ' . $this->config['app']['name'],
            'noindex'  => true,
            'dias'     => $dias,
            'resumen'  => AnalyticsService::summary($dias),
            'top'      => AnalyticsService::topArticles($dias),
            'pulsos'   => Database::all(
                'SELECT p.id, p.question, a.title AS article_title, a.slug,
                        (SELECT COUNT(*) FROM poll_responses pr WHERE pr.poll_id = p.id AND pr.stage = "inicial") AS inicial,
                        (SELECT COUNT(*) FROM poll_responses pr WHERE pr.poll_id = p.id AND pr.stage = "informado") AS informado
                   FROM polls p LEFT JOIN articles a ON a.id = p.article_id
                  ORDER BY p.id DESC LIMIT 10'
            ),
        ], 'layouts/admin'));
    }
}
