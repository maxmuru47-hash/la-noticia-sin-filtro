<?php
/**
 * Genera una APP_KEY y nada más.
 *
 * No lee la base de datos, no toca ningún archivo y no recibe parámetros.
 * Aun así, BÓRRALO cuando hayas copiado la clave: no hay razón para dejar
 * en el servidor un archivo que nadie va a volver a usar.
 */
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$clave = bin2hex(random_bytes(32));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Tu APP_KEY</title>
<link rel="stylesheet" href="/assets/css/sistema.css">
<link rel="stylesheet" href="/assets/css/componentes.css">
</head>
<body>
<main class="contenedor seccion">
<div class="lectura">
  <h1>Tu APP_KEY</h1>
  <p>Copia esta línea entera y pégala en tu archivo <code>.env</code>,
     sustituyendo la que dice <code>APP_KEY=</code>.</p>

  <p style="font-family:var(--mono);font-size:var(--t-sm);background:var(--papel-hueco);
            padding:var(--e3);border-radius:var(--radio);word-break:break-all;
            border:1px solid var(--borde)">APP_KEY=<?= $clave ?></p>

  <p class="aviso aviso--error">
    <strong>Ahora borra este archivo.</strong>
    Elimina <code>public/clave.php</code> del servidor.
  </p>

  <p style="color:var(--tinta-tenue);font-size:var(--t-sm)">
    Esta clave es la sal de las huellas anónimas del Pulso. Si la cambias más
    adelante, los votos ya registrados siguen contando, pero las huellas
    antiguas dejan de coincidir: alguien que ya votó podría votar otra vez.
    Ponla una vez y no la toques.
  </p>
</div>
</main>
</body>
</html>
