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
use App\Support\Database;

if (PHP_SAPI !== 'cli') {
    exit("Este script solo se ejecuta desde la línea de comandos.\n");
}

$inicio = microtime(true);
$total  = SearchService::reindexAll();

/*
 * Rehacer tambien el indice de texto completo.
 *
 * MySQL mantiene ese indice en unas tablas auxiliares aparte. Tras un
 * borrado masivo o una restauracion de respaldo pueden quedar desfasadas:
 * la fila esta en la tabla y se encuentra con LIKE, pero el buscador
 * devuelve vacio sin dar ningun error. Un buscador que calla es peor que
 * uno que falla, porque nadie se entera. Aqui, que es mantenimiento y se
 * ejecuta a mano, rehacerlo cuesta poco y lo deja limpio.
 */
try {
    Database::run('ALTER TABLE search_index DROP INDEX ft_search_all, DROP INDEX ft_search_title');
    Database::run(
        'ALTER TABLE search_index
            ADD FULLTEXT KEY ft_search_all (title,summary,body,transcript,keywords),
            ADD FULLTEXT KEY ft_search_title (title,summary)'
    );
    $indice = 'rehecho';
} catch (Throwable $e) {
    $indice = 'no se pudo rehacer (' . $e->getMessage() . ')';
}

printf("Reindexadas %d piezas en %.2f segundos. Indice de texto: %s.\n",
    $total, microtime(true) - $inicio, $indice);
