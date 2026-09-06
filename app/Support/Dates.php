<?php
declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Toda fecha vive en UTC dentro de la base y se presenta en la zona
 * editorial. Una sola fuente de verdad, tal como exige el documento
 * maestro para los Lives.
 */
final class Dates
{
    private const MESES = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
        5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
        9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];

    private static string $display = 'America/Caracas';

    public static function setDisplayTimezone(string $timezone): void
    {
        self::$display = $timezone;
    }

    public static function displayTimezone(): string
    {
        return self::$display;
    }

    public static function nowUtc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    public static function parse(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '' || str_starts_with($value, '0000')) {
            return null;
        }
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    public static function local(?string $value): ?DateTimeImmutable
    {
        return self::parse($value)?->setTimezone(new DateTimeZone(self::$display));
    }

    /** Ej: 5 de septiembre de 2026 */
    public static function long(?string $value): string
    {
        $date = self::local($value);
        if ($date === null) {
            return '';
        }
        return sprintf('%d de %s de %d', (int) $date->format('j'), self::MESES[(int) $date->format('n')], (int) $date->format('Y'));
    }

    /** Ej: 5 de septiembre de 2026, 8:30 p. m. */
    public static function longWithTime(?string $value): string
    {
        $date = self::local($value);
        if ($date === null) {
            return '';
        }
        $hour   = (int) $date->format('g');
        $minute = $date->format('i');
        $suffix = $date->format('a') === 'am' ? 'a. m.' : 'p. m.';
        return self::long($value) . sprintf(', %d:%s %s', $hour, $minute, $suffix);
    }

    /** Ej: 2026-09-05T20:30:00-04:00 para datos estructurados. */
    public static function iso(?string $value): ?string
    {
        return self::local($value)?->format('c');
    }

    public static function monthName(int $month): string
    {
        return self::MESES[$month] ?? '';
    }

    /** Ej: hace 3 horas. Para el panel, nunca para la fecha publica. */
    public static function relative(?string $value): string
    {
        $date = self::parse($value);
        if ($date === null) {
            return '';
        }
        $seconds = time() - $date->getTimestamp();
        if ($seconds < 60)    return 'hace instantes';
        if ($seconds < 3600)  return 'hace ' . intdiv($seconds, 60) . ' min';
        if ($seconds < 86400) return 'hace ' . intdiv($seconds, 3600) . ' h';
        if ($seconds < 2592000) return 'hace ' . intdiv($seconds, 86400) . ' d';
        return self::long($value);
    }

    /** Convierte una fecha escrita en hora local (panel) a UTC para guardar. */
    public static function toUtc(?string $localValue): ?string
    {
        if ($localValue === null || trim($localValue) === '') {
            return null;
        }
        try {
            $date = new DateTimeImmutable($localValue, new DateTimeZone(self::$display));
        } catch (\Exception) {
            return null;
        }
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** Devuelve el valor UTC formateado para un input datetime-local. */
    public static function toLocalInput(?string $utcValue): string
    {
        return self::local($utcValue)?->format('Y-m-d\TH:i') ?? '';
    }
}
