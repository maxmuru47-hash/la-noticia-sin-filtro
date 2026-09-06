<?php
declare(strict_types=1);

namespace App\Support;

final class Csrf
{
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    public static function field(): string
    {
        return sprintf(
            '<input type="hidden" name="_token" value="%s">',
            htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8')
        );
    }

    public static function verify(?string $candidate): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }
        $expected = $_SESSION[self::KEY] ?? '';
        return $expected !== '' && is_string($candidate) && hash_equals($expected, $candidate);
    }

    /** Rota el token tras una accion sensible. */
    public static function rotate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
    }
}
