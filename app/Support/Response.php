<?php
declare(strict_types=1);

namespace App\Support;

final class Response
{
    public static function html(string $content, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo $content;
    }

    public static function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function xml(string $content, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/xml; charset=utf-8');
        echo $content;
    }

    public static function text(string $content, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $content;
    }

    public static function redirect(string $location, int $status = 302): never
    {
        http_response_code($status);
        header('Location: ' . $location);
        exit;
    }

    /** Cabeceras de seguridad aplicadas a toda respuesta HTML. */
    public static function securityHeaders(bool $allowExternalEmbeds = true): void
    {
        $frameSources = $allowExternalEmbeds
            ? "frame-src 'self' https://www.youtube.com https://www.youtube-nocookie.com https://player.vimeo.com;"
            : "frame-src 'none';";

        header("Content-Security-Policy: default-src 'self'; "
            . "img-src 'self' data: https:; "
            . "media-src 'self' blob:; "
            . "script-src 'self'; "
            . "style-src 'self'; "
            // Los atributos style en linea se usan solo para valores
            // calculados en el servidor (anchos de barra del Pulso). El
            // sanitizador elimina cualquier atributo style del contenido
            // escrito en el panel, asi que no es una via de inyeccion.
            . "style-src-attr 'unsafe-inline'; "
            . "font-src 'self'; "
            . "connect-src 'self' blob:; "
            . $frameSources
            . " object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), interest-cohort=()');
        header('X-Frame-Options: SAMEORIGIN');
        header_remove('X-Powered-By');
    }
}
