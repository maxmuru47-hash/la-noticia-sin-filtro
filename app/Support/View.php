<?php
declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class View
{
    private static string $basePath = __DIR__ . '/../Views';
    private static array $shared = [];

    public static function setBasePath(string $path): void
    {
        self::$basePath = rtrim($path, '/');
    }

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function shared(string $key, mixed $default = null): mixed
    {
        return self::$shared[$key] ?? $default;
    }

    public static function render(string $template, array $data = [], ?string $layout = 'layouts/public'): string
    {
        $content = self::capture($template, $data);

        if ($layout === null) {
            return $content;
        }

        return self::capture($layout, array_merge($data, ['contenido' => $content]));
    }

    public static function partial(string $template, array $data = []): string
    {
        return self::capture($template, $data);
    }

    private static function capture(string $template, array $data): string
    {
        $file = self::$basePath . '/' . str_replace('..', '', $template) . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('Vista no encontrada: ' . $template);
        }

        extract(array_merge(self::$shared, $data), EXTR_SKIP);

        ob_start();
        require $file;
        return (string) ob_get_clean();
    }

    /** Escape por defecto. En las vistas se usa e() en cada salida. */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
