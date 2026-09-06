<?php
declare(strict_types=1);

namespace App\Support;

final class Router
{
    /** @var array<string,array<int,array{pattern:string,names:array<int,string>,handler:callable|array}>> */
    private array $routes = ['GET' => [], 'POST' => []];

    public function get(string $path, callable|array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable|array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    private function add(string $method, string $path, callable|array $handler): void
    {
        $names   = [];
        // El patron admite cuantificadores dentro de la restriccion, como
        // {anio:\d{4}}: sin las llaves internas, \d{4} se cortaba en \d{4
        // y el resto quedaba suelto en la ruta.
        $pattern = preg_replace_callback(
            '/\{([a-z_]+)(?::((?:[^{}]|\{\d+(?:,\d*)?\})+))?\}/',
            static function (array $m) use (&$names): string {
                $names[] = $m[1];
                return '(' . ($m[2] ?? '[^/]+') . ')';
            },
            $path
        ) ?? $path;

        $this->routes[$method][] = [
            'pattern' => '#^' . $pattern . '$#u',
            'names'   => $names,
            'handler' => $handler,
        ];
    }

    /** @return array{handler:callable|array,params:array<string,string>}|null */
    public function match(string $method, string $path): ?array
    {
        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route['pattern'], $path, $matches) === 1) {
                array_shift($matches);
                $params = [];
                foreach ($route['names'] as $index => $name) {
                    $params[$name] = $matches[$index] ?? '';
                }
                return ['handler' => $route['handler'], 'params' => $params];
            }
        }
        return null;
    }

    /** Existe la ruta con otro metodo? Sirve para responder 405 y no 404. */
    public function pathExists(string $path): bool
    {
        foreach ($this->routes as $routes) {
            foreach ($routes as $route) {
                if (preg_match($route['pattern'], $path) === 1) {
                    return true;
                }
            }
        }
        return false;
    }
}
