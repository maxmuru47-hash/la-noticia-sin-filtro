<?php
/**
 * ACTUALIZADOR DE UN SOLO USO
 *
 * Trae dentro los archivos que hay que instalar y los coloca en su carpeta.
 * Existe para que una actualización no sea siete subidas repartidas en cinco
 * carpetas, que es la forma más segura de equivocarse.
 *
 * Lo que hace y lo que no:
 *
 *  - Solo funciona si has iniciado sesión como superadministrador.
 *  - Nunca toca .env, ni la base de datos, ni lo que hayas subido tú.
 *  - Antes de escribir nada guarda una copia de cada archivo que sustituye,
 *    en storage/respaldo-actualizacion, por si hay que volver atrás.
 *  - Si algo falla a mitad, deja escrito qué se cambió y qué no.
 *  - Al terminar intenta borrarse solo.
 *
 * BÓRRALO cuando acabes, si no lo hizo él.
 */
declare(strict_types=1);

require __DIR__ . '/rutas.php';
$config = require $rutaBootstrap;

use App\Middleware\Auth;
use App\Support\Csrf;

// La sesion la abre normalmente index.php, y aqui no pasamos por el. Sin
// esto nadie estaria identificado nunca y la pagina rebotaria al login en
// bucle, incluso con la sesion abierta en el panel.
Auth::startSession($config['session']);
Auth::requireLogin();

$usuario = Auth::user();
$esJefe  = ($usuario['role_slug'] ?? '') === 'superadministrador';

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$privada = dirname($rutaBootstrap, 2);
$publica = __DIR__;

/** @var array<string,string> Archivos en base64, con su destino. */
$CARGA = /*__CARGA__*/[];

$resultados = [];
$errores    = [];
$hecho      = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $esJefe) {
    if (!Csrf::verify((string) ($_POST['_token'] ?? ''))) {
        $errores[] = 'La página lleva demasiado tiempo abierta. Recárgala y vuelve a intentarlo.';
    } else {
        $carpetaRespaldo = $privada . '/storage/respaldo-actualizacion/' . gmdate('Ymd-His');

        foreach ($CARGA as $destino => $contenidoB64) {
            // Ni rutas absolutas ni saltos hacia arriba: solo lo previsto.
            if (str_contains($destino, '..') || str_starts_with($destino, '/')) {
                $errores[] = 'Ruta rechazada: ' . $destino;
                continue;
            }
            if (str_contains($destino, '.env')) {
                $errores[] = 'No se toca la configuración: ' . $destino;
                continue;
            }

            $base = str_starts_with($destino, 'publico/')
                ? $publica . '/' . substr($destino, strlen('publico/'))
                : $privada . '/' . $destino;

            $contenido = base64_decode($contenidoB64, true);
            if ($contenido === false || $contenido === '') {
                $errores[] = 'Contenido ilegible: ' . $destino;
                continue;
            }

            $carpeta = dirname($base);
            if (!is_dir($carpeta) && !@mkdir($carpeta, 0755, true) && !is_dir($carpeta)) {
                $errores[] = 'No pude crear la carpeta de ' . $destino;
                continue;
            }

            $existia = is_file($base);
            if ($existia) {
                $copia = $carpetaRespaldo . '/' . $destino;
                if (!is_dir(dirname($copia))) {
                    @mkdir(dirname($copia), 0755, true);
                }
                if (@copy($base, $copia) === false) {
                    $errores[] = 'No pude respaldar ' . $destino . ', así que no lo toco.';
                    continue;
                }
            }

            if (@file_put_contents($base, $contenido, LOCK_EX) === false) {
                $errores[] = 'No pude escribir ' . $destino;
                continue;
            }

            $resultados[] = ['ruta' => $destino, 'accion' => $existia ? 'sustituido' : 'nuevo'];
        }

        $hecho = $resultados !== [];
        if ($hecho && $errores === []) {
            @unlink(__FILE__);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Actualizar la plataforma</title>
<link rel="stylesheet" href="/assets/css/sistema.css">
<link rel="stylesheet" href="/assets/css/componentes.css">
</head>
<body>
<main class="contenedor seccion">
<div class="lectura">

<?php if (!$esJefe): ?>

  <h1>Esta página no es para tu cuenta</h1>
  <p class="aviso aviso--error">
    Actualizar la plataforma solo lo puede hacer el superadministrador.
    Entra con esa cuenta o pide que lo haga quien la tenga.
  </p>
  <p><a class="boton boton--linea" href="/panel">Volver al panel</a></p>

<?php elseif ($hecho && $errores === []): ?>

  <h1>Listo. <?= count($resultados) ?> archivos en su sitio.</h1>
  <p class="aviso aviso--ok">
    Antes de tocar nada se guardó una copia de cada archivo sustituido.
    Esta página se borró sola; si sigue ahí, elimina <code>actualizar.php</code> a mano.
  </p>
  <ul>
    <?php foreach ($resultados as $r): ?>
      <li><code><?= htmlspecialchars($r['ruta'], ENT_QUOTES) ?></code> — <?= $r['accion'] ?></li>
    <?php endforeach; ?>
  </ul>
  <p>
    <a class="boton boton--rojo" href="/panel/rapido">Ir a Publicar rápido</a>
    <a class="boton boton--linea" href="/">Ver el sitio</a>
  </p>

<?php else: ?>

  <h1>Actualizar la plataforma</h1>

  <?php foreach ($errores as $mensaje): ?>
    <p class="aviso aviso--error"><?= htmlspecialchars($mensaje, ENT_QUOTES) ?></p>
  <?php endforeach; ?>

  <?php if ($resultados !== []): ?>
    <p class="aviso aviso--ok">Se alcanzaron a colocar <?= count($resultados) ?> archivos antes del fallo.</p>
  <?php endif; ?>

  <p>Se van a colocar <strong><?= count($CARGA) ?> archivos</strong> en sus carpetas.
     No se toca tu configuración, ni la base de datos, ni las imágenes que hayas subido.</p>

  <p>De cada archivo que se sustituya se guarda antes una copia en
     <code>storage/respaldo-actualizacion</code>, con la fecha, por si hubiera que volver atrás.</p>

  <form method="post" action="">
    <?= Csrf::field() ?>
    <button class="boton boton--rojo" type="submit">Actualizar ahora</button>
  </form>

  <p class="nota">Tarda unos segundos. No cierres la página mientras trabaja.</p>

<?php endif; ?>

</div>
</main>
</body>
</html>
