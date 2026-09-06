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

Database::connect($config['database']);

return $config;
