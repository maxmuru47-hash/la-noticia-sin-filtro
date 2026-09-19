<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Middleware\RateLimit;
use App\Models\Subscriber;
use App\Support\Csrf;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/**
 * SUSCRIPCION
 *
 * Publicar todos los dias sin forma de que nadie vuelva es una cinta de
 * correr. Esto es lo que convierte a un lector de una vez en un lector.
 *
 * Tres decisiones deliberadas:
 *
 *  - El formulario va al FINAL de la pieza, nunca en una ventana que
 *    tape lo que la persona vino a leer. Se pide volver a quien ya
 *    decidio que merecia la pena, no a quien acaba de llegar.
 *
 *  - Todavia no hay envio de correo, y el texto lo dice. Se guarda el
 *    alta sin confirmar y se avisa de que la confirmacion llegara cuando
 *    empiecen los envios. Prometer un correo que no va a llegar es la
 *    forma mas rapida de quemar una lista antes de tenerla.
 *
 *  - Funciona sin JavaScript. Si el navegador acepta JSON responde JSON
 *    y el aviso sale en la propia pieza; si no, redirige a una pagina
 *    de verdad. El sitio se lee entero sin scripts y esto no es la
 *    excepcion.
 */
final class SubscriptionController
{
    private const CONSENTIMIENTO =
        'Acepto recibir por correo el resumen de La Noticia Sin Filtro y los avisos que he marcado. '
        . 'Antes del primer envio recibire un correo para confirmar. Puedo darme de baja cuando quiera, '
        . 'con un enlace en cada correo.';

    public function __construct(private readonly array $config)
    {
    }

    public function store(Request $request): void
    {
        if (!Csrf::verify($request->text('_token'))) {
            $this->responder($request, false, 'La página llevaba mucho tiempo abierta. Recárgala y vuelve a intentarlo.', 419);
            return;
        }

        $email = trim($request->text('correo'));
        if ($email === '' || mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->responder($request, false, 'Ese correo no parece válido. Revísalo.', 422);
            return;
        }

        if (!$request->bool('consiento')) {
            $this->responder($request, false, 'Necesitamos que marques la casilla: sin tu permiso no guardamos nada.', 422);
            return;
        }

        // Ocho intentos por minuto. Un lector real nunca llega a eso;
        // un script que prueba direcciones, si.
        if (RateLimit::demasiadosEnVentana('suscripcion', 8, 60)) {
            $this->responder($request, false, 'Demasiados intentos seguidos. Espera un minuto.', 429);
            return;
        }

        Subscriber::alta($email, $request->list('intereses'), self::CONSENTIMIENTO);

        // La respuesta es la MISMA tanto si la direccion ya estaba como
        // si es nueva. Decir «ya estabas apuntado» le confirmaria a un
        // desconocido que ese correo esta en la lista.
        $this->responder(
            $request,
            true,
            'Apuntado a la lista de espera. Todavía no enviamos correos y no tenemos fecha; el día que empecemos, lo primero será pedirte que confirmes. Mientras tanto, el RSS te avisa de cada pieza.',
            200
        );
    }

    public function confirm(Request $request): void
    {
        $ok = Subscriber::confirmar($request->text('t'));

        $this->pagina(
            $ok ? 'Confirmado' : 'Ese enlace ya no vale',
            $ok
                ? 'Listo. Tu correo queda confirmado y empezarás a recibir el resumen.'
                : 'Ese enlace no corresponde a ninguna suscripción. Puede que ya te hayas dado de baja, o que esté incompleto: cópialo entero desde el correo.',
            $ok
        );
    }

    public function unsubscribe(Request $request): void
    {
        $ok = Subscriber::baja($request->text('t'));

        $this->pagina(
            $ok ? 'Te has dado de baja' : 'Ese enlace ya no vale',
            $ok
                ? 'No volverás a recibir correos nuestros. No hace falta que hagas nada más, y no te vamos a escribir para preguntarte por qué.'
                : 'Ese enlace no corresponde a ninguna suscripción. Si sigues recibiendo correos, escríbenos y te sacamos a mano.',
            $ok
        );
    }

    private function responder(Request $request, bool $ok, string $mensaje, int $estado): void
    {
        if ($request->wantsJson()) {
            Response::json($ok ? ['ok' => true, 'mensaje' => $mensaje] : ['ok' => false, 'error' => $mensaje], $estado);
            return;
        }

        $this->pagina($ok ? 'Apuntado' : 'No se pudo apuntar', $mensaje, $ok, $ok ? 200 : $estado);
    }

    private function pagina(string $encabezado, string $mensaje, bool $ok, int $estado = 200): void
    {
        Response::securityHeaders();
        Response::html(View::render('pages/suscripcion', [
            'titulo'      => $encabezado . ' · ' . $this->config['app']['name'],
            'descripcion' => $mensaje,
            'noindex'     => true,
            'encabezado'  => $encabezado,
            'mensaje'     => $mensaje,
            'ok'          => $ok,
        ]), $estado);
    }
}
