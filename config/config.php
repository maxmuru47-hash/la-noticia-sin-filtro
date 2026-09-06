<?php
declare(strict_types=1);

use App\Support\Env;

$root = dirname(__DIR__);
Env::load($root . '/.env');

$appUrl = rtrim((string) Env::get('APP_URL', 'http://localhost:8000'), '/');

/*
 * Carpeta pública real. La define public/index.php, que siempre vive dentro
 * de ella. Si por lo que sea no llegara, se usa la de dentro del proyecto.
 */
$publico = defined('LNSF_PUBLIC') ? LNSF_PUBLIC : $root . '/public';

return [
    'app' => [
        'name'      => (string) Env::get('APP_NAME', 'La Noticia SIN FILTRO'),
        'env'       => (string) Env::get('APP_ENV', 'produccion'),
        'debug'     => Env::bool('APP_DEBUG', false),
        'url'       => $appUrl,
        'timezone'  => (string) Env::get('APP_TIMEZONE', 'America/Caracas'),
        'key'       => (string) Env::get('APP_KEY', ''),
        'root'      => $root,
        'demo_badge' => Env::bool('SHOW_DEMO_BADGE', true),
    ],

    'database' => [
        'host'     => (string) Env::get('DB_HOST', 'localhost'),
        'port'     => Env::int('DB_PORT', 3306),
        'database' => (string) Env::get('DB_DATABASE', ''),
        'username' => (string) Env::get('DB_USERNAME', ''),
        'password' => (string) Env::get('DB_PASSWORD', ''),
    ],

    'session' => [
        'session_name'   => (string) Env::get('SESSION_NAME', 'lnsf_sesion'),
        'secure_cookies' => Env::bool('SESSION_SECURE_COOKIES', true),
    ],

    'security' => [
        'login_max_attempts'   => Env::int('LOGIN_MAX_ATTEMPTS', 5),
        'login_window_minutes' => Env::int('LOGIN_WINDOW_MINUTES', 15),
    ],

    'uploads' => [
        'public_path'   => $publico . '/uploads',
        'originals_path' => $root . '/storage/originals',
        'max_image_bytes'    => Env::int('UPLOAD_MAX_IMAGE_MB', 8) * 1024 * 1024,
        'max_video_bytes'    => Env::int('UPLOAD_MAX_VIDEO_MB', 200) * 1024 * 1024,
        'max_document_bytes' => Env::int('UPLOAD_MAX_DOCUMENT_MB', 25) * 1024 * 1024,
        'image_mimes'    => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/avif' => 'avif'],
        'video_mimes'    => ['video/mp4' => 'mp4', 'video/webm' => 'webm'],
        'document_mimes' => ['application/pdf' => 'pdf'],
        'caption_mimes'  => ['text/vtt' => 'vtt', 'text/plain' => 'vtt'],
    ],

    'brand' => [
        'name'              => (string) Env::get('BRAND_NAME', 'SIN FILTRO con Max'),
        'instagram'         => (string) Env::get('BRAND_INSTAGRAM', 'sinfiltroconmax'),
        'instagram_personal' => (string) Env::get('BRAND_INSTAGRAM_PERSONAL', 'maxegonzalezp'),
        'tiktok'            => (string) Env::get('BRAND_TIKTOK', 'sinfiltroconmax'),
        'email'             => (string) Env::get('EDITORIAL_EMAIL', ''),
        'principle'         => 'No te muestro exito, te muestro el camino.',
        'promise'           => 'La noticia no termina cuando la lees. Comienza cuando la entiendes.',
    ],

    'hero' => [
        'enabled'     => Env::bool('HERO_VIDEO_ENABLED', true),
        'video_path'      => (string) Env::get('HERO_VIDEO_PATH', '/assets/video/hero-scrub.mp4'),
        'video_webm_path' => (string) Env::get('HERO_VIDEO_WEBM_PATH', '/assets/video/hero-scrub.webm'),
        'poster_path' => (string) Env::get('HERO_POSTER_PATH', '/assets/images/hero-poster.jpg'),
        'ending_path' => (string) Env::get('HERO_ENDING_PATH', '/assets/images/hero-final.jpg'),
    ],

    'pagination' => [
        'search'  => 12,
        'archive' => 15,
        'admin'   => 25,
    ],
];
