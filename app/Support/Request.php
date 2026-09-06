<?php
declare(strict_types=1);

namespace App\Support;

final class Request
{
    public readonly string $method;
    public readonly string $path;
    public readonly array $query;
    public readonly array $body;
    public readonly array $files;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri          = $_SERVER['REQUEST_URI'] ?? '/';
        $path         = parse_url($uri, PHP_URL_PATH) ?: '/';
        $this->path   = '/' . trim(rawurldecode($path), '/');
        $this->query  = $_GET;
        $this->files  = $_FILES;

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $raw        = file_get_contents('php://input') ?: '';
            $decoded    = json_decode($raw, true);
            $this->body = is_array($decoded) ? $decoded : [];
        } else {
            $this->body = $_POST;
        }
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function text(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function int(string $key, ?int $default = null): ?int
    {
        $value = $this->input($key);
        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }
        return (int) $value;
    }

    public function bool(string $key): bool
    {
        $value = $this->input($key);
        return in_array((string) $value, ['1', 'true', 'on', 'si', 'yes'], true);
    }

    /** @return array<int,string> */
    public function list(string $key): array
    {
        $value = $this->input($key, []);
        if (!is_array($value)) {
            return $value === '' || $value === null ? [] : [(string) $value];
        }
        return array_values(array_filter(array_map(
            static fn ($v) => is_scalar($v) ? trim((string) $v) : '',
            $value
        ), static fn (string $v): bool => $v !== ''));
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return str_contains($accept, 'application/json')
            || str_starts_with($this->path, '/api/');
    }

    public function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    /**
     * Huella anonima del visitante para control de abuso en el Pulso.
     * No guarda la IP: solo un hash con la sal del servidor, que no
     * permite reconstruirla ni cruzarla con otros sistemas.
     */
    public function voterHash(string $salt, string $scope = ''): string
    {
        return hash('sha256', $salt . '|' . $this->ip() . '|' . $this->userAgent() . '|' . $scope);
    }
}
