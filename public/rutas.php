<?php
declare(strict_types=1);

/**
 * DÓNDE ESTÁ EL RESTO DEL CÓDIGO
 *
 * Este es el ÚNICO archivo que hay que tocar, y solo si el código no está
 * dentro de esta misma carpeta. Se cambia UNA línea, la que está marcada
 * más abajo con flechas.
 */

// ══════════════════════════════════════════════════════════════════
//  ▼ ESTA ES LA LÍNEA QUE SE CAMBIA. LA ÚNICA. ▼
// ══════════════════════════════════════════════════════════════════

$carpetaDelCodigo = '..';

// ══════════════════════════════════════════════════════════════════
//  ▲ NO TOQUES NADA MÁS DE ESTE ARCHIVO. ▲
// ══════════════════════════════════════════════════════════════════
//
//  Qué poner ahí arriba:
//
//  CASO A · El dominio apunta a la carpeta `public` del proyecto.
//           Déjalo como está:            '..'
//
//  CASO B · El dominio apunta a `public_html`, y el resto del proyecto
//           está en una carpeta hermana. Escribe el nombre de esa
//           carpeta, con ../ delante. Por ejemplo, si la carpeta se
//           llama lanoticia-privado:     '../lanoticia-privado'
//
//  Fíjate bien: van dos puntos, una barra, y el nombre. Entre comillas
//  simples, y con el punto y coma al final.
//

$rutaBootstrap = __DIR__ . '/' . $carpetaDelCodigo . '/app/bootstrap.php';

// Esta carpeta es la que ve internet. La aplicación necesita saberlo para
// guardar las imágenes donde el navegador pueda encontrarlas.
if (!defined('LNSF_PUBLIC')) {
    define('LNSF_PUBLIC', __DIR__);
}

if (!is_file($rutaBootstrap)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit(
        "No encuentro el código de la aplicación.\n\n"
        . "Busqué aquí:\n  " . $rutaBootstrap . "\n\n"
        . "Abre el archivo rutas.php que está junto a index.php y corrige\n"
        . "la línea marcada con flechas. Ahí mismo está explicado.\n\n"
        . "Ahora mismo dice:  '" . $carpetaDelCodigo . "'\n"
    );
}
