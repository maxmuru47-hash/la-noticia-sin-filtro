<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Middleware\Auth;
use App\Models\Question;
use App\Services\AuditService;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\Dates;
use App\Support\Paginator;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/** Moderacion de preguntas de la comunidad y control de los pulsos. */
final class ModerationController
{
    public function __construct(private readonly array $config)
    {
    }

    public function index(Request $request): void
    {
        Auth::require(Auth::CAP_MODERATE);

        $estado  = $request->text('estado', 'pendiente');
        $estado  = array_key_exists($estado, Question::STATUSES) || $estado === 'todas' ? $estado : 'pendiente';
        $perPage = $this->config['pagination']['admin'];
        $page    = Paginator::resolvePage($request->input('pagina'));

        $result = Question::forModeration($estado, $perPage, ($page - 1) * $perPage);

        Response::securityHeaders(false);
        Response::html(View::render('admin/moderacion', [
            'titulo'    => 'Moderación · Panel',
            'noindex'   => true,
            'preguntas' => $result['items'],
            'paginador' => new Paginator($result['total'], $perPage, $page, '/panel/moderacion', ['estado' => $estado]),
            'estado'    => $estado,
            'lives'     => Database::all('SELECT id, title FROM lives WHERE status <> "cancelado" ORDER BY starts_at DESC LIMIT 30'),
            'pulsos'    => Database::all(
                'SELECT p.*, a.title AS article_title,
                        (SELECT COUNT(*) FROM poll_responses pr WHERE pr.poll_id = p.id) AS respuestas
                   FROM polls p LEFT JOIN articles a ON a.id = p.article_id
                  ORDER BY p.id DESC LIMIT 12'
            ),
        ], 'layouts/admin'));
    }

    public function moderate(Request $request, array $params): void
    {
        Auth::require(Auth::CAP_MODERATE);

        if (!Csrf::verify($request->text('_token'))) {
            $this->flash('La sesión expiró.', 'error');
            Response::redirect('/panel/moderacion');
        }

        $id       = (int) $params['id'];
        $pregunta = Question::findById($id);

        if ($pregunta === null) {
            $this->flash('Esa pregunta no existe.', 'error');
            Response::redirect('/panel/moderacion');
        }

        $nuevoEstado = $request->text('estado');
        if (!array_key_exists($nuevoEstado, Question::STATUSES)) {
            $this->flash('Estado no válido.', 'error');
            Response::redirect('/panel/moderacion');
        }

        Database::update('community_questions', [
            'status'       => $nuevoEstado,
            'answer'       => $request->text('respuesta') ?: null,
            'live_id'      => $request->int('live') ?: $pregunta['live_id'],
            'answered_article_id' => $request->int('articulo_respuesta'),
            'moderated_by' => Auth::id(),
            'moderated_at' => Dates::nowUtc(),
        ], 'id = :id', ['id' => $id]);

        AuditService::log('pregunta.moderar', 'pregunta', $id, $nuevoEstado);

        $this->flash('Pregunta actualizada.');
        Response::redirect('/panel/moderacion?estado=' . rawurlencode($request->text('volver_a', 'pendiente')));
    }

    private function flash(string $message, string $type = 'ok'): void
    {
        $_SESSION['_flash'] = ['mensaje' => $message, 'tipo' => $type];
    }
}
