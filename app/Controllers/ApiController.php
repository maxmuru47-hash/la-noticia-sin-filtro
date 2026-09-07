<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Middleware\RateLimit;
use App\Models\Article;
use App\Models\Live;
use App\Models\Poll;
use App\Models\Question;
use App\Services\AnalyticsService;
use App\Services\AssistantService;
use App\Services\SearchService;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\Request;
use App\Support\Response;

/**
 * API publica minima. Todo lo que escribe exige token CSRF y pasa por
 * limite de frecuencia. Nada de esto es imprescindible para leer el
 * sitio: si JavaScript falla, la web sigue funcionando en modo lectura.
 */
final class ApiController
{
    public function __construct(private readonly array $config)
    {
    }

    /** Voto del Pulso, inicial o informado. Anonimo. */
    public function vote(Request $request, array $params): void
    {
        if (!Csrf::verify($request->text('_token'))) {
            Response::json(['ok' => false, 'error' => 'Token de seguridad no válido. Recarga la página.'], 419);
            return;
        }

        $pollId = (int) ($params['id'] ?? 0);
        $poll   = Poll::findById($pollId);

        if ($poll === null || (int) $poll['is_open'] !== 1) {
            Response::json(['ok' => false, 'error' => 'Este pulso no está abierto.'], 404);
            return;
        }

        $stage    = $request->text('etapa') === 'informado' ? 'informado' : 'inicial';
        $optionId = (int) $request->int('opcion', 0);

        if (!Poll::optionBelongsToPoll($optionId, $pollId)) {
            Response::json(['ok' => false, 'error' => 'Opción no válida.'], 422);
            return;
        }

        if (RateLimit::tooFrequent('pulso_' . $pollId . '_' . $stage, 3)) {
            Response::json(['ok' => false, 'error' => 'Espera un momento antes de volver a votar.'], 429);
            return;
        }

        $voterHash = $request->voterHash($this->config['app']['key'], 'pulso');

        if (Poll::hasVoted($pollId, $voterHash, $stage)) {
            Response::json([
                'ok'         => false,
                'error'      => 'Ya registramos tu respuesta en esta etapa.',
                'resultados' => Poll::results($pollId),
            ], 409);
            return;
        }

        Database::insert('poll_responses', [
            'poll_id'       => $pollId,
            'option_id'     => $optionId,
            'stage'         => $stage,
            'voter_hash'    => $voterHash,
            'session_token' => AnalyticsService::sessionToken(),
        ]);

        AnalyticsService::record(
            $stage === 'inicial' ? 'pulso_inicial' : 'pulso_informado',
            AnalyticsService::sessionToken(),
            'articulo',
            $poll['article_id'] === null ? null : (int) $poll['article_id']
        );

        Response::json([
            'ok'         => true,
            'etapa'      => $stage,
            'resultados' => Poll::results($pollId),
            'cambio'     => Poll::opinionShift($pollId),
        ]);
    }

    /** Pregunta de la comunidad. Entra a moderacion, nunca se publica sola. */
    public function question(Request $request): void
    {
        if (!Csrf::verify($request->text('_token'))) {
            Response::json(['ok' => false, 'error' => 'Token de seguridad no válido. Recarga la página.'], 419);
            return;
        }

        $body = trim($request->text('pregunta'));

        if (mb_strlen($body) < 12) {
            Response::json(['ok' => false, 'error' => 'Escribe tu pregunta con un poco más de detalle.'], 422);
            return;
        }
        if (mb_strlen($body) > 1000) {
            Response::json(['ok' => false, 'error' => 'La pregunta es demasiado larga. Máximo 1000 caracteres.'], 422);
            return;
        }

        $submitterHash = $request->voterHash($this->config['app']['key'], 'pregunta');

        if (Question::recentBySubmitter($submitterHash) >= 5) {
            Response::json(['ok' => false, 'error' => 'Has enviado varias preguntas seguidas. Inténtalo más tarde.'], 429);
            return;
        }
        if (RateLimit::tooFrequent('pregunta', 30)) {
            Response::json(['ok' => false, 'error' => 'Espera un momento antes de enviar otra.'], 429);
            return;
        }

        $email = trim($request->text('correo'));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::json(['ok' => false, 'error' => 'Ese correo no parece válido.'], 422);
            return;
        }
        // Sin consentimiento explicito, el correo no se guarda.
        if ($email !== '' && !$request->bool('consiento_correo')) {
            $email = '';
        }

        $articleId = $request->int('articulo');
        $liveId    = $request->int('live');

        Database::insert('community_questions', [
            'article_id'     => $articleId,
            'live_id'        => $liveId,
            'body'           => $body,
            'author_name'    => mb_substr(trim($request->text('nombre')), 0, 120) ?: null,
            'contact_email'  => $email === '' ? null : $email,
            'status'         => 'pendiente',
            'submitter_hash' => $submitterHash,
        ]);

        AnalyticsService::record('pregunta_enviada', AnalyticsService::sessionToken(), 'articulo', $articleId);

        Response::json([
            'ok'      => true,
            'mensaje' => 'Recibimos tu pregunta. La revisa una persona antes de publicarla, y puede llegar al próximo Live.',
        ]);
    }

    /** Aviso de un Live. Requiere consentimiento explicito. */
    public function reminder(Request $request, array $params): void
    {
        if (!Csrf::verify($request->text('_token'))) {
            Response::json(['ok' => false, 'error' => 'Token de seguridad no válido. Recarga la página.'], 419);
            return;
        }

        $live = Live::findById((int) ($params['id'] ?? 0));
        if ($live === null) {
            Response::json(['ok' => false, 'error' => 'Ese Live no existe.'], 404);
            return;
        }

        $email = trim($request->text('correo'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::json(['ok' => false, 'error' => 'Escribe un correo válido.'], 422);
            return;
        }
        if (!$request->bool('consiento')) {
            Response::json(['ok' => false, 'error' => 'Necesitamos tu consentimiento explícito para escribirte.'], 422);
            return;
        }
        if (RateLimit::tooFrequent('recordatorio', 20)) {
            Response::json(['ok' => false, 'error' => 'Espera un momento.'], 429);
            return;
        }

        $consentText = 'Acepto recibir un aviso por correo del Live "' . $live['title'] . '". Puedo darme de baja cuando quiera.';

        Database::run(
            'INSERT INTO subscribers (email, purpose, consent_text, confirm_token)
             VALUES (:email, "avisos_live", :consent, :token)
             ON DUPLICATE KEY UPDATE purpose = "avisos_live", unsubscribed_at = NULL',
            ['email' => $email, 'consent' => $consentText, 'token' => bin2hex(random_bytes(24))]
        );

        Response::json(['ok' => true, 'mensaje' => 'Listo. Te avisaremos antes de que empiece.']);
    }

    /** Evento de analitica. Sin datos personales, sin terceros. */
    public function event(Request $request): void
    {
        $event = $request->text('evento');
        $ok    = AnalyticsService::record(
            $event,
            AnalyticsService::sessionToken(),
            $request->text('tipo') ?: null,
            $request->int('id')
        );

        Response::json(['ok' => $ok], $ok ? 200 : 422);
    }

    /** Sugerencias del buscador. */
    public function suggest(Request $request): void
    {
        header('Cache-Control: private, max-age=30');
        Response::json(['items' => SearchService::suggest($request->text('q'))]);
    }

    /**
     * El asistente de la redacción. Contesta con lo que está escrito o con lo
     * que hay publicado de verdad, nunca con lo que suene bien.
     *
     * Va limitado por dos motivos: cada pregunta abre una búsqueda contra la
     * base, y un formulario público sin freno es un ariete gratis.
     */
    public function assistant(Request $request): void
    {
        if (!Csrf::verify($request->text('_token'))) {
            Response::json(['ok' => false, 'error' => 'La página lleva demasiado tiempo abierta. Recárgala.'], 419);
            return;
        }

        $pregunta = trim($request->text('pregunta'));

        if ($pregunta === '') {
            Response::json(['ok' => false, 'error' => 'Escribe tu pregunta.'], 422);
            return;
        }
        if (mb_strlen($pregunta) > AssistantService::LIMITE_PREGUNTA) {
            Response::json([
                'ok'    => false,
                'error' => 'Demasiado largo. Máximo ' . AssistantService::LIMITE_PREGUNTA . ' caracteres.',
            ], 422);
            return;
        }

        // Ocho preguntas en medio minuto: de sobra para conversar, poco para
        // usar la búsqueda del archivo como ariete.
        if (RateLimit::burst('asistente', 8, 30)) {
            Response::json([
                'ok'    => false,
                'error' => 'Muchas preguntas seguidas. Espera unos segundos y sigue.',
            ], 429);
            return;
        }

        header('Cache-Control: no-store');
        Response::json(['ok' => true] + AssistantService::responder($pregunta));
    }
}
