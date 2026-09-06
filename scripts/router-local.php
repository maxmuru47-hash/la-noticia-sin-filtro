<?php
/**
 * Router para el servidor de desarrollo de PHP.
 *
 *   php -S localhost:8080 -t public scripts/router-local.php
 *
 * Reproduce lo que hace .htaccess en Apache: sirve los archivos reales
 * y manda todo lo demás al front controller. En producción NO se usa.
 */

$ruta = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$archivo = __DIR__ . '/../public' . $ruta;

// Los archivos subidos nunca se ejecutan, igual que en producción.
if (str_starts_with($ruta, '/uploads/') && preg_match('/\.(php|phtml|phar|cgi|pl|py|sh)$/i', $ruta)) {
    http_response_code(403);
    exit('Prohibido.');
}

if ($ruta !== '/' && is_file($archivo) && !str_ends_with($archivo, '.php')) {
    return false; // El servidor integrado lo sirve tal cual.
}

require __DIR__ . '/../public/index.php';
