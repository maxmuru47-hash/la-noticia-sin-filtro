<?php
declare(strict_types=1);

/**
 * Tareas periódicas. Se ejecuta cada 5 minutos desde el cron del
 * alojamiento:
 *
 *   (cada 5 minutos) /usr/bin/php /ruta/al/proyecto/scripts/cron.php
 *   >> /ruta/al/proyecto/storage/logs/cron.log 2>&1
 *
 * La expresion de cron es: 5 barra-asterisco en el campo de minutos.
 * Ver GUIA_DE_ALOJAMIENTO.md para la linea exacta.
 *
 * Hace lo mismo que la comprobación oportunista del front controller,
 * pero sin depender de que llegue una visita.
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Middleware\RateLimit;
use App\Services\AnalyticsService;
use App\Services\ArticleService;
use App\Services\Mailer;
use App\Services\NewsletterService;
use App\Support\Database;
use App\Support\Logger;

if (PHP_SAPI !== 'cli') {
    exit("Este script solo se ejecuta desde la línea de comandos.\n");
}

$inicio = microtime(true);

// 1. Publicar las piezas programadas cuya hora ya llegó.
$publicadas = ArticleService::publishDue();

// 2. Marcar como finalizado un Live cuya hora de término ya pasó,
//    para que la fecha y el estado nunca se contradigan.
$finalizados = Database::run(
    'UPDATE lives SET status = "finalizado"
      WHERE status = "en_vivo" AND ends_at IS NOT NULL AND ends_at < UTC_TIMESTAMP()'
)->rowCount();

// 3. Pasar a "en vivo" el programa cuya hora ya llegó.
$enVivo = Database::run(
    'UPDATE lives SET status = "en_vivo"
      WHERE status = "programado" AND starts_at IS NOT NULL
        AND starts_at <= UTC_TIMESTAMP()
        AND (ends_at IS NULL OR ends_at > UTC_TIMESTAMP())'
)->rowCount();

// 3b. El resumen de la semana, una sola vez por semana.
//     El cron corre cada cinco minutos, así que hace falta una marca: se
//     guarda la semana ya enviada en settings y no se repite. Sin esa
//     marca, un boletín semanal se convierte en 288 boletines al día.
$semanaActual = gmdate('o-\WW');
$semanaHecha  = (string) Database::value(
    'SELECT value FROM settings WHERE name = "resumen_semanal_enviado"'
);
$encolados = 0;

// Domingo por la mañana en Venezuela: 13:00 UTC son las 9:00 en Caracas.
$esHora = (int) gmdate('w') === 0 && (int) gmdate('G') >= 13;

if ($esHora && $semanaHecha !== $semanaActual) {
    $encolados = NewsletterService::encolarResumen(
        $config['app']['url'],
        $config['app']['name']
    );
    Database::run(
        'INSERT INTO settings (name, value) VALUES ("resumen_semanal_enviado", :v)
         ON DUPLICATE KEY UPDATE value = :v2',
        ['v' => $semanaActual, 'v2' => $semanaActual]
    );
}

// 3c. Vaciar la cola de correo. Si no hay proveedor configurado esto no
//     hace nada y los correos siguen esperando, que es lo correcto.
$correo = Mailer::enviarPendientes(50);

// 4. Limpieza. La analítica cruda no se guarda para siempre.
RateLimit::purgeOld(30);
$eventosBorrados = AnalyticsService::purgeOlderThan(400);

$resumen = sprintf(
    'cron: %d publicadas, %d lives en vivo, %d finalizados, %d eventos purgados, '
    . 'correo[%d encolados, %d enviados, %d fallidos, %d en cola], %.2fs',
    $publicadas, $enVivo, $finalizados, $eventosBorrados,
    $encolados, $correo['enviados'], $correo['fallidos'], $correo['pendientes'],
    microtime(true) - $inicio
);

Logger::info($resumen);
echo gmdate('Y-m-d H:i:s') . ' UTC · ' . $resumen . "\n";
