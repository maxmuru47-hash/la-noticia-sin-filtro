<?php
declare(strict_types=1);

/**
 * Reconstruye el índice del buscador desde cero.
 *
 * Útil después de importar contenido, de cambiar transcripciones en masa
 * o de restaurar un respaldo.
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Services\SearchService;

if (PHP_SAPI !== 'cli') {
    exit("Este script solo se ejecuta desde la línea de comandos.\n");
}

$inicio = microtime(true);
$total  = SearchService::reindexAll();

printf("Reindexadas %d piezas en %.2f segundos.\n", $total, microtime(true) - $inicio);
