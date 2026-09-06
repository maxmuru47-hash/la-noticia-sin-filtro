<?php
declare(strict_types=1);

/**
 * Arranque de la aplicacion. Sin autocargador de Composer a proposito:
 * el proyecto debe funcionar en cualquier alojamiento compartido con
 * PHP 8.2, sin proceso de instalacion ni compilacion.
 */

const LNSF_ROOT = __DIR__ . '/..';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, 4));
    $file     = LNSF_ROOT . '/app/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

require_once LNSF_ROOT . '/app/Support/helpers.php';

use App\Support\Database;
use App\Support\Dates;
use App\Support\Logger;
use App\Support\View;

$config = require LNSF_ROOT . '/config/config.php';

date_default_timezone_set('UTC');
Dates::setDisplayTimezone($config['app']['timezone']);
Logger::setDirectory(LNSF_ROOT . '/storage/logs');
View::setBasePath(LNSF_ROOT . '/app/Views');

if ($config['app']['debug']) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

set_exception_handler(static function (\Throwable $e) use ($config): void {
    Logger::error('Excepcion no capturada', [
        'mensaje' => $e->getMessage(),
        'archivo' => $e->getFile() . ':' . $e->getLine(),
    ]);

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }

    if ($config['app']['debug']) {
        echo '<pre style="padding:2rem;font:14px ui-monospace,monospace">'
            . htmlspecialchars($e->getMessage() . "\n\n" . $e->getTraceAsString(), ENT_QUOTES, 'UTF-8')
            . '</pre>';
        return;
    }

    echo View::render('errors/500', [], 'layouts/public');
});

// Las vistas reciben la configuracion ANTES de conectar: si la base de
// datos falla, la pagina de error todavia sabe como pintarse.
View::share('config', $config);
View::share('appUrl', $config['app']['url']);
View::share('brand', $config['brand']);

/*
 * APP_KEY es la sal de las huellas anónimas del Pulso y de las preguntas.
 * Con una sal vacía, esa huella se vuelve adivinable: cualquiera podría
 * calcular la de otra persona a partir de su IP y su navegador, y el voto
 * anónimo dejaría de serlo. Arrancar en silencio sin ella sería peor que
 * no arrancar.
 */
if (strlen((string) $config['app']['key']) < 32) {
    // Este mensaje lo lee una persona que acaba de subir los archivos y
    // todavía no ha configurado nada. Se le dice qué hacer, no qué falló.
    $hayArchivo = is_file($root . '/.env');
    // La direccion se toma de la peticion real: el .env es justo el
    // archivo que todavia no existe, asi que no sirve para esto.
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host !== '' && preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host) === 1) {
        $esSeguro  = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
        $direccion = ($esSeguro ? 'https://' : 'http://') . $host;
    } else {
        $direccion = rtrim((string) ($config['app']['url'] ?? ''), '/');
    }

    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Retry-After: 3600');
    exit(
        "TODAVÍA FALTA UN PASO\n"
        . "=====================\n\n"
        . ($hayArchivo
            ? "El archivo .env existe, pero le falta la clave de seguridad\n"
              . "(la línea que empieza por APP_KEY=), o es demasiado corta.\n\n"
            : "Falta crear el archivo de configuración. Se llama .env y va\n"
              . "dentro de la carpeta privada, junto a las carpetas app y config.\n"
              . "Al lado hay un archivo .env.example: cópialo y renombra la\n"
              . "copia como .env (sí, empieza por punto y no lleva nada más).\n\n")
        . "CÓMO CONSEGUIR LA CLAVE\n"
        . "-----------------------\n"
        . "Abre esta dirección en el navegador:\n\n"
        . "  " . $direccion . "/clave.php\n\n"
        . "Esa página genera la clave y la muestra en pantalla. Cópiala y\n"
        . "pégala en el archivo .env, en la línea APP_KEY=, sin espacios\n"
        . "ni comillas. Después vuelve a cargar esta página.\n\n"
        . "POR QUÉ HACE FALTA\n"
        . "------------------\n"
        . "El voto del Pulso se guarda sin nombre y sin dirección IP: se\n"
        . "guarda una huella calculada con esta clave. Sin ella la huella\n"
        . "se puede adivinar y el voto dejaría de ser anónimo. Por eso la\n"
        . "web no arranca hasta que exista: prefiero no abrir a abrir mal.\n"
    );
}

Database::connect($config['database']);

return $config;
