<?php
declare(strict_types=1);

/**
 * Marco de pruebas mínimo. Sin dependencias: el proyecto debe poder
 * probarse en cualquier alojamiento con PHP, sin instalar nada.
 */

final class Pruebas
{
    private static int $pasadas = 0;
    private static int $fallidas = 0;
    private static array $errores = [];
    private static string $grupo = '';

    public static function grupo(string $nombre): void
    {
        self::$grupo = $nombre;
        echo "\n\033[1m" . $nombre . "\033[0m\n";
    }

    public static function afirmar(bool $condicion, string $descripcion, string $detalle = ''): void
    {
        if ($condicion) {
            self::$pasadas++;
            echo "  \033[32m✓\033[0m " . $descripcion . "\n";
            return;
        }

        self::$fallidas++;
        self::$errores[] = self::$grupo . ' → ' . $descripcion . ($detalle === '' ? '' : ' | ' . $detalle);
        echo "  \033[31m✗\033[0m " . $descripcion . ($detalle === '' ? '' : "\n      " . $detalle) . "\n";
    }

    public static function iguales(mixed $esperado, mixed $real, string $descripcion): void
    {
        self::afirmar(
            $esperado === $real,
            $descripcion,
            'esperado: ' . var_export($esperado, true) . ' · real: ' . var_export($real, true)
        );
    }

    public static function contiene(string $aguja, string $pajar, string $descripcion): void
    {
        self::afirmar(
            str_contains($pajar, $aguja),
            $descripcion,
            'no se encontró: ' . $aguja
        );
    }

    public static function noContiene(string $aguja, string $pajar, string $descripcion): void
    {
        self::afirmar(
            !str_contains($pajar, $aguja),
            $descripcion,
            'apareció algo que no debía: ' . $aguja
        );
    }

    public static function resumen(): int
    {
        $total = self::$pasadas + self::$fallidas;
        echo "\n" . str_repeat('─', 62) . "\n";
        printf("  %d de %d pruebas pasaron.\n", self::$pasadas, $total);

        if (self::$fallidas > 0) {
            echo "\n\033[31m  FALLOS:\033[0m\n";
            foreach (self::$errores as $error) {
                echo "   · " . $error . "\n";
            }
            echo "\n";
            return 1;
        }

        echo "\033[32m  Todo en verde.\033[0m\n\n";
        return 0;
    }
}
