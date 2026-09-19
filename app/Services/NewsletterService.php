<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Article;
use App\Models\Subscriber;
use App\Support\Dates;

/**
 * LO QUE SE ESCRIBE A LA LISTA
 *
 * Dos correos, y nada mas. Cuantos menos correos manda una casa, mas se
 * abren los que manda.
 *
 *  1. El de confirmar, cuando alguien se apunta.
 *  2. El resumen, una vez por semana.
 *
 * Se publica a diario, pero NO se escribe a diario. Un correo diario se
 * convierte en una baja diaria; el resumen semanal deja que la gente se
 * entere de lo que se perdio sin sentirse perseguida. Quien quiera la
 * pieza del dia la tiene en la web y en el canal.
 *
 * Todo en texto plano, y todo correo lleva su enlace de baja. Sin
 * excepcion: un correo del que no se puede uno bajar es spam, lo firme
 * quien lo firme.
 */
final class NewsletterService
{
    public static function confirmacion(string $token, string $urlBase, string $marca): array
    {
        $enlace = rtrim($urlBase, '/') . '/suscripcion/confirmar?t=' . $token;

        return [
            'asunto' => 'Confirma tu correo · ' . $marca,
            'cuerpo' => implode("\n", [
                'Te apuntaste al resumen de ' . $marca . '.',
                '',
                'Para que empecemos a escribirte, confirma que este correo es tuyo:',
                '',
                $enlace,
                '',
                'Si no confirmas, no te escribimos. Y si no fuiste tú quien se apuntó,',
                'no hagas nada: sin confirmar, esa dirección no recibe nada.',
                '',
                '—',
                $marca,
                'Cada cifra con su fecha y su fuente.',
            ]),
        ];
    }

    /**
     * El resumen de los ultimos siete dias.
     *
     * Devuelve null si no hubo nada que contar. Mandar un correo vacio
     * para cumplir el calendario es la forma mas rapida de enseñarle a
     * alguien a ignorarte.
     */
    public static function resumenSemanal(string $urlBase, string $marca, int $dias = 7): ?array
    {
        // Article::published no sabe filtrar por fecha de inicio. Se pide
        // un puñado de las ultimas y se recorta aqui: vienen ordenadas de
        // mas nueva a mas vieja, asi que basta con quedarse con el tramo.
        $desde  = gmdate('Y-m-d H:i:s', time() - ($dias * 86400));
        $piezas = array_values(array_filter(
            Article::published(['limit' => 30]),
            static fn (array $p): bool => !empty($p['published_at']) && $p['published_at'] >= $desde
        ));

        if ($piezas === []) {
            return null;
        }

        $lineas = [
            'Lo que publicamos esta semana en ' . $marca . '.',
            '',
        ];

        foreach ($piezas as $pieza) {
            $lineas[] = mb_strtoupper((string) ($pieza['category_name'] ?? 'Pieza'));
            $lineas[] = (string) $pieza['title'];
            if (!empty($pieza['summary'])) {
                $lineas[] = (string) $pieza['summary'];
            }
            $lineas[] = rtrim($urlBase, '/') . '/noticia/' . $pieza['slug'];
            $lineas[] = '';
        }

        $lineas[] = '—';
        $lineas[] = $marca;
        $lineas[] = 'Toda cifra con su fecha y su fuente. Cuando no lo sabemos, lo decimos.';

        return [
            'asunto' => 'La semana en ' . $marca . ' · ' . count($piezas)
                        . (count($piezas) === 1 ? ' pieza' : ' piezas'),
            'cuerpo' => implode("\n", $lineas),
        ];
    }

    /** El pie con la baja. Va en TODO correo, sin excepción. */
    public static function pieDeBaja(string $token, string $urlBase): string
    {
        return "\n\n---\n"
            . "Para dejar de recibir estos correos, entra aquí:\n"
            . rtrim($urlBase, '/') . '/suscripcion/baja?t=' . $token . "\n"
            . "No hace falta que expliques nada.";
    }

    /**
     * Encola el resumen para todo el que lo pidio y lo confirmo.
     *
     * @return int cuantos se pusieron en cola
     */
    public static function encolarResumen(string $urlBase, string $marca): int
    {
        $contenido = self::resumenSemanal($urlBase, $marca);
        if ($contenido === null) {
            return 0;
        }

        $puestos = 0;
        foreach (Subscriber::destinatarios('resumen_semanal') as $persona) {
            Mailer::encolar(
                (string) $persona['email'],
                $contenido['asunto'],
                $contenido['cuerpo'] . self::pieDeBaja((string) $persona['confirm_token'], $urlBase)
            );
            $puestos++;
        }

        return $puestos;
    }

    /** Fecha legible para el asunto, en la zona editorial. */
    public static function semanaDe(): string
    {
        return Dates::long(gmdate('Y-m-d H:i:s'));
    }
}
