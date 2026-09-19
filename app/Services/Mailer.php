<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\Logger;

/**
 * CORREO
 *
 * Nada se envia durante una visita. Escribir a una persona significa
 * hablar con un servidor de fuera, y eso tarda o falla: si ocurriera
 * mientras alguien pulsa «Quiero recibirlo», el formulario se quedaria
 * colgado por un problema que no es suyo.
 *
 * Asi que aqui solo se ENCOLA, en la tabla notifications, que ya existia
 * en el esquema para esto. El cron vacia la cola cada cinco minutos.
 *
 * Mientras no haya clave de proveedor configurada, los correos se quedan
 * en la cola en estado pendiente. No se marcan como fallidos: el dia que
 * se configure la clave, todo lo acumulado sale. Perder un alta porque
 * todavia no habiamos contratado el envio seria tirar a la basura justo
 * lo que mas cuesta conseguir.
 */
final class Mailer
{
    private static array $config = [
        'clave'        => '',
        'desde'        => '',
        'desde_nombre' => '',
        'url'          => '',
    ];

    public static function configurar(array $config): void
    {
        self::$config = array_merge(self::$config, $config);
    }

    public static function configurado(): bool
    {
        return self::$config['clave'] !== '' && self::$config['desde'] !== '';
    }

    /**
     * Pone un correo en la cola. Devuelve el id de la fila.
     *
     * El destinatario viaja en payload y no en user_id: quien recibe el
     * resumen es un suscriptor, no una cuenta del panel.
     */
    public static function encolar(string $email, string $asunto, string $cuerpo): int
    {
        return Database::insert('notifications', [
            'channel'  => 'correo',
            'audience' => 'suscriptores',
            'subject'  => mb_substr($asunto, 0, 255),
            'body'     => $cuerpo,
            'payload'  => json_encode(['email' => $email], JSON_UNESCAPED_UNICODE),
            'status'   => 'pendiente',
        ]);
    }

    /**
     * Vacia la cola. Solo lo llama el cron.
     *
     * @return array{enviados:int,fallidos:int,pendientes:int}
     */
    public static function enviarPendientes(int $lote = 50): array
    {
        if (!self::configurado()) {
            return ['enviados' => 0, 'fallidos' => 0, 'pendientes' => self::enCola()];
        }

        $filas = Database::all(
            'SELECT id, subject, body, payload FROM notifications
              WHERE channel = "correo" AND status = "pendiente"
                AND (scheduled_for IS NULL OR scheduled_for <= UTC_TIMESTAMP())
              ORDER BY id LIMIT ' . max(1, min(200, $lote))
        );

        $enviados = 0;
        $fallidos = 0;

        foreach ($filas as $fila) {
            $datos = json_decode((string) $fila['payload'], true);
            $email = is_array($datos) ? (string) ($datos['email'] ?? '') : '';

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                self::marcar((int) $fila['id'], 'fallida');
                Logger::warning('Correo en cola sin destinatario válido', ['id' => $fila['id']]);
                $fallidos++;
                continue;
            }

            if (self::entregar($email, (string) $fila['subject'], (string) $fila['body'])) {
                self::marcar((int) $fila['id'], 'enviada');
                $enviados++;
            } else {
                self::marcar((int) $fila['id'], 'fallida');
                $fallidos++;
            }
        }

        return ['enviados' => $enviados, 'fallidos' => $fallidos, 'pendientes' => self::enCola()];
    }

    public static function enCola(): int
    {
        return (int) Database::value(
            'SELECT COUNT(*) FROM notifications WHERE channel = "correo" AND status = "pendiente"'
        );
    }

    private static function marcar(int $id, string $estado): void
    {
        Database::run(
            'UPDATE notifications SET status = :estado, sent_at = :enviado WHERE id = :id',
            [
                'estado'  => $estado,
                'enviado' => $estado === 'enviada' ? gmdate('Y-m-d H:i:s') : null,
                'id'      => $id,
            ]
        );
    }

    /**
     * La unica parte que habla con el mundo exterior. Todo lo demas de
     * esta clase se puede probar sin red; esto no, y por eso es lo mas
     * corto posible.
     *
     * Se envia en texto plano a proposito: llega mejor, se lee igual, y
     * un boletin de texto encaja con una casa que presume de no adornar.
     */
    private static function entregar(string $email, string $asunto, string $cuerpo): bool
    {
        $cuerpoPeticion = json_encode([
            'sender'      => ['email' => self::$config['desde'], 'name' => self::$config['desde_nombre']],
            'to'          => [['email' => $email]],
            'subject'     => $asunto,
            'textContent' => $cuerpo,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'accept: application/json',
                'content-type: application/json',
                'api-key: ' . self::$config['clave'],
            ],
            CURLOPT_POSTFIELDS     => $cuerpoPeticion,
        ]);

        $respuesta = curl_exec($ch);
        $estado    = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errorRed  = curl_error($ch);
        curl_close($ch);

        if ($estado >= 200 && $estado < 300) {
            return true;
        }

        // Nunca se registra la clave ni el cuerpo del correo.
        Logger::error('El proveedor rechazó un correo', [
            'estado'    => $estado,
            'red'       => $errorRed,
            'respuesta' => is_string($respuesta) ? mb_substr($respuesta, 0, 300) : '',
        ]);

        return false;
    }
}
