<?php
declare(strict_types=1);

namespace App\Support;

final class Logger
{
    private static string $directory = __DIR__ . '/../../storage/logs';

    public static function setDirectory(string $path): void
    {
        self::$directory = rtrim($path, '/');
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('AVISO', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        if (!is_dir(self::$directory)) {
            @mkdir(self::$directory, 0775, true);
        }

        $line = sprintf(
            "[%s] %s: %s %s\n",
            gmdate('Y-m-d H:i:s'),
            $level,
            $message,
            $context === [] ? '' : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        @file_put_contents(self::$directory . '/app-' . gmdate('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
