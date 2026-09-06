<?php
declare(strict_types=1);

namespace App\Support;

final class Paginator
{
    public readonly int $totalPages;

    public function __construct(
        public readonly int $total,
        public readonly int $perPage,
        public readonly int $currentPage,
        public readonly string $basePath,
        public readonly array $queryParams = []
    ) {
        $this->totalPages = max(1, (int) ceil($total / max(1, $perPage)));
    }

    public function offset(): int
    {
        return ($this->currentPage - 1) * $this->perPage;
    }

    public function hasPrevious(): bool
    {
        return $this->currentPage > 1;
    }

    public function hasNext(): bool
    {
        return $this->currentPage < $this->totalPages;
    }

    public function url(int $page): string
    {
        $params = $this->queryParams;
        unset($params['pagina']);
        if ($page > 1) {
            $params['pagina'] = $page;
        }
        $query = http_build_query($params);
        return $this->basePath . ($query === '' ? '' : '?' . $query);
    }

    /** Ventana de paginas para la barra de navegacion. @return array<int,int> */
    public function window(int $radius = 2): array
    {
        $start = max(1, $this->currentPage - $radius);
        $end   = min($this->totalPages, $this->currentPage + $radius);
        return range($start, $end);
    }

    public static function resolvePage(mixed $raw): int
    {
        $page = is_numeric($raw) ? (int) $raw : 1;
        return max(1, min($page, 5000));
    }
}
