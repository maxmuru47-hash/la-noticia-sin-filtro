<?php
declare(strict_types=1);

/**
 * INSTALADOR DE UN SOLO USO
 *
 * Existe para poder instalar sin línea de comandos, desde el navegador.
 * Está pensado para borrarse en cuanto termina.
 *
 * Por qué se puede dejar un rato y no para siempre:
 *
 *  - Solo crea una cuenta si la tabla `users` está COMPLETAMENTE VACÍA.
 *    En cuanto existe un usuario, se bloquea solo y no vuelve a funcionar.
 *  - No muestra credenciales, ni rutas del servidor, ni versiones.
 *  - Al terminar intenta borrarse a sí mismo.
 *
 * Aun así: BÓRRALO cuando acabes. Es el último paso de la guía.
 */

require __DIR__ . '/rutas.php';
require $rutaBootstrap;

use App\Support\Database;
use App\Support\Str;

$errores = [];
$listo   = false;

// --- 1. ¿Tiene el servidor lo que hace falta? -------------------------

$requisitos = [
    'PHP 8.2 o superior' => ['ok' => PHP_VERSION_ID >= 80200, 'tiene' => PHP_VERSION],
];

foreach (['pdo_mysql', 'mbstring', 'gd', 'intl', 'fileinfo', 'dom', 'json', 'openssl'] as $extension) {
    $requisitos['Extensión ' . $extension] = [
        'ok'    => extension_loaded($extension),
        'tiene' => extension_loaded($extension) ? 'instalada' : 'FALTA',
    ];
}

foreach ([
    'storage/logs'      => LNSF_ROOT . '/storage/logs',
    'storage/backups'   => LNSF_ROOT . '/storage/backups',
    'storage/originals' => LNSF_ROOT . '/storage/originals',
    'public/uploads'    => LNSF_PUBLIC . '/uploads',
] as $nombre => $ruta) {
    $requisitos['Carpeta ' . $nombre] = [
        'ok'    => is_dir($ruta) && is_writable($ruta),
        'tiene' => is_dir($ruta) ? (is_writable($ruta) ? 'se puede escribir' : 'SIN PERMISO DE ESCRITURA') : 'NO EXISTE',
    ];
}

$todoOk = true;
foreach ($requisitos as $r) {
    if (!$r['ok']) { $todoOk = false; }
}

// --- 2. ¿Está la base de datos con su estructura? ---------------------

$tablas = 0;
$roles  = 0;
$baseOk = false;

try {
    $tablas = (int) Database::value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()');
    $roles  = (int) Database::value('SELECT COUNT(*) FROM roles');
    $baseOk = $tablas >= 40 && $roles > 0;
} catch (\Throwable) {
    $errores[] = 'No se pudo consultar la base de datos. Revisa los datos de .env.';
}

if ($tablas > 0 && $tablas < 40) {
    $errores[] = 'La base existe pero le faltan tablas (' . $tablas . ' de 41). Importa database/schema.sql.';
}
if ($tablas >= 40 && $roles === 0) {
    $errores[] = 'Faltan los roles. Importa database/seed.sql.';
}

// --- 2b. Montar la base con un botón, sin phpMyAdmin ------------------
//
// Los dos archivos .sql ya viajan con el proyecto, así que no tiene
// sentido obligar a nadie a descargarlos y volverlos a subir por otra
// herramienta. Esto solo actúa cuando la base está a medio hacer, y más
// abajo el instalador entero se cierra en cuanto existe una cuenta.

$montada = false;

if (($_POST['accion'] ?? '') === 'montar_base' && $todoOk && !$baseOk) {
    try {
        $carpetaSql = dirname($rutaBootstrap, 2) . '/database';

        foreach (['schema.sql', 'seed.sql'] as $archivo) {
            $ruta = $carpetaSql . '/' . $archivo;
            if (!is_file($ruta)) {
                throw new RuntimeException(
                    'No encuentro ' . $archivo . '. Debería estar en la carpeta privada, dentro de database.'
                );
            }
            $sql = (string) file_get_contents($ruta);
            if (trim($sql) === '') {
                throw new RuntimeException('El archivo ' . $archivo . ' llegó vacío. Vuelve a subirlo.');
            }
            Database::pdo()->exec($sql);
        }

        // Se vuelve a contar: el resultado manda, no la ausencia de error.
        $tablas  = (int) Database::value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()');
        $roles   = (int) Database::value('SELECT COUNT(*) FROM roles');
        $baseOk  = $tablas >= 40 && $roles > 0;
        $montada = $baseOk;

        if (!$baseOk) {
            $errores[] = 'La carga terminó pero la base quedó incompleta: ' . $tablas . ' tablas y ' . $roles . ' roles.';
        } else {
            $errores = [];
        }
    } catch (\Throwable $e) {
        $errores[] = 'No se pudo montar la base: ' . $e->getMessage();
    }
}

// --- 3. La primera cuenta. Solo si NO hay ninguna. --------------------

$hayUsuarios = false;
try {
    $hayUsuarios = (int) Database::value('SELECT COUNT(*) FROM users') > 0;
} catch (\Throwable) {
    // Sin tablas todavía; el paso 2 ya lo avisó.
}

if ($hayUsuarios) {
    http_response_code(410);
    ?>
    <!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow"><title>Instalador cerrado</title>
    <link rel="stylesheet" href="/assets/css/sistema.css">
    <link rel="stylesheet" href="/assets/css/componentes.css"></head>
    <body><main class="contenedor seccion"><div class="lectura">
      <h1>El instalador ya cumplió su función</h1>
      <p>Esta plataforma ya tiene al menos una cuenta, así que el instalador se bloqueó solo
         y no volverá a crear ninguna.</p>
      <p class="aviso aviso--error"><strong>Ahora bórralo.</strong>
         Elimina el archivo <code>public/instalar.php</code> del servidor.</p>
      <p><a class="boton boton--rojo" href="/panel/entrar">Ir al panel</a>
         <a class="boton boton--linea" href="/">Ver el sitio</a></p>
    </div></main></body></html>
    <?php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $todoOk && $baseOk) {
    $nombre  = trim((string) ($_POST['nombre'] ?? ''));
    $correo  = mb_strtolower(trim((string) ($_POST['correo'] ?? '')));
    $clave   = (string) ($_POST['clave'] ?? '');
    $repetir = (string) ($_POST['repetir'] ?? '');

    if ($nombre === '')                                  { $errores[] = 'Escribe tu nombre, tal como quieres que aparezca firmando.'; }
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL))     { $errores[] = 'Ese correo no es válido.'; }
    if (mb_strlen($clave) < 12)                          { $errores[] = 'La contraseña debe tener al menos 12 caracteres.'; }
    if ($clave !== $repetir)                             { $errores[] = 'Las dos contraseñas no coinciden.'; }

    if ($errores === []) {
        try {
            // Se vuelve a comprobar DENTRO de la transacción: si dos
            // personas abrieran el instalador a la vez, solo una crearía
            // la cuenta.
            Database::transaction(static function () use ($nombre, $correo, $clave): void {
                if ((int) Database::value('SELECT COUNT(*) FROM users') > 0) {
                    throw new RuntimeException('Ya existe una cuenta.');
                }

                $rol = Database::first('SELECT id FROM roles WHERE slug = "superadministrador"');
                if ($rol === null) {
                    throw new RuntimeException('Falta el rol superadministrador. Importa database/seed.sql.');
                }

                $userId = Database::insert('users', [
                    'uuid'          => Str::uuid(),
                    'role_id'       => (int) $rol['id'],
                    'email'         => $correo,
                    'password_hash' => password_hash($clave, PASSWORD_ARGON2ID),
                    'display_name'  => $nombre,
                    'is_active'     => 1,
                    'must_change_password' => 0,
                ]);

                $slug = Str::slug($nombre, 120);
                $n = 2;
                while (Database::value('SELECT id FROM authors WHERE slug = :s', ['s' => $slug]) !== null) {
                    $slug = Str::slug($nombre, 110) . '-' . $n++;
                }

                Database::insert('authors', [
                    'uuid'       => Str::uuid(),
                    'user_id'    => $userId,
                    'slug'       => $slug,
                    'name'       => $nombre,
                    'role_title' => 'Director editorial',
                    'is_active'  => 1,
                ]);
            });

            $listo = true;
            @unlink(__FILE__);
        } catch (\Throwable $e) {
            $errores[] = $e->getMessage();
        }
    }
}

$sePudoBorrar = $listo && !is_file(__FILE__);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Instalar La Noticia SIN FILTRO</title>
<link rel="stylesheet" href="/assets/css/sistema.css">
<link rel="stylesheet" href="/assets/css/componentes.css">
</head>
<body>
<main class="contenedor seccion">
<div class="lectura">

  <p style="font-family:var(--titulares);font-weight:800;font-size:var(--t-lg)">La Noticia SIN FILTRO</p>
  <h1>Instalación</h1>

  <?php if ($listo): ?>

    <p class="aviso aviso--ok"><strong>Listo. Tu cuenta está creada.</strong></p>

    <?php if ($sePudoBorrar): ?>
      <p class="aviso aviso--ok">El instalador se borró solo. No queda nada que hacer.</p>
    <?php else: ?>
      <p class="aviso aviso--error">
        <strong>Falta un paso, y es importante.</strong><br>
        No pude borrarme solo porque no tengo permiso de escritura.
        Entra al gestor de archivos de tu hosting y <strong>elimina
        <code>public/instalar.php</code></strong>.
      </p>
    <?php endif; ?>

    <p style="margin-top:var(--e5)">
      <a class="boton boton--rojo" href="/panel/entrar">Entrar al panel</a>
      <a class="boton boton--linea" href="/">Ver el sitio</a>
    </p>

  <?php else: ?>

    <h2>1. El servidor</h2>
    <div class="tabla-envoltura">
      <table class="tabla">
        <tbody>
        <?php foreach ($requisitos as $nombre => $r): ?>
          <tr>
            <td><?= $r['ok'] ? '&#10003;' : '&#10007;' ?></td>
            <td><?= htmlspecialchars($nombre, ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars((string) $r['tiene'], ENT_QUOTES) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if (!$todoOk): ?>
      <p class="aviso aviso--error">
        Falta algo en el servidor. Si es una extensión, actívala desde el panel de tu hosting.
        Si es una carpeta, dale permiso de escritura.
      </p>
    <?php endif; ?>

    <h2>2. La base de datos</h2>
    <p class="aviso <?= $baseOk ? 'aviso--ok' : 'aviso--error' ?>">
      <?php if ($baseOk): ?>
        Conectada. <?= (int) $tablas ?> tablas y <?= (int) $roles ?> roles. Todo en su sitio.
      <?php else: ?>
        Conectada, pero vacía: le faltan las tablas. Se montan con el botón de aquí abajo.
      <?php endif; ?>
    </p>

    <?php if ($montada): ?>
      <p class="aviso aviso--ok">
        Base montada: <?= (int) $tablas ?> tablas y <?= (int) $roles ?> roles creados desde
        los archivos que ya viajaban con el proyecto.
      </p>
    <?php endif; ?>

    <?php if ($todoOk && !$baseOk): ?>
      <form method="post" action="">
        <input type="hidden" name="accion" value="montar_base">
        <button class="boton boton--rojo" type="submit">Montar la base de datos</button>
      </form>
      <p class="nota">
        Crea las 41 tablas y los roles. Tarda unos segundos. No hace falta phpMyAdmin:
        los archivos <code>schema.sql</code> y <code>seed.sql</code> ya están en la carpeta
        privada de tu servidor.
      </p>
    <?php endif; ?>

    <?php foreach ($errores as $mensaje): ?>
      <p class="aviso aviso--error"><?= htmlspecialchars($mensaje, ENT_QUOTES) ?></p>
    <?php endforeach; ?>

    <h2>3. Tu cuenta</h2>

    <?php if ($todoOk && $baseOk): ?>
      <p>Esta será la cuenta con todos los permisos. El instalador se bloquea en cuanto la crees.</p>

      <form class="formulario" method="post" action="">
        <div class="campo">
          <label for="nombre">Tu nombre</label>
          <input type="text" id="nombre" name="nombre" required maxlength="120"
                 value="<?= htmlspecialchars((string) ($_POST['nombre'] ?? ''), ENT_QUOTES) ?>">
          <span class="campo__ayuda">Así aparecerá firmando las piezas.</span>
        </div>
        <div class="campo">
          <label for="correo">Tu correo</label>
          <input type="email" id="correo" name="correo" required maxlength="190"
                 value="<?= htmlspecialchars((string) ($_POST['correo'] ?? ''), ENT_QUOTES) ?>">
          <span class="campo__ayuda">Con este entras al panel.</span>
        </div>
        <div class="campo">
          <label for="clave">Contraseña</label>
          <input type="password" id="clave" name="clave" required minlength="12" autocomplete="new-password">
          <span class="campo__ayuda">
            Mínimo 12 caracteres. Una frase larga que solo tú recuerdes es más segura
            que una palabra corta con símbolos.
          </span>
        </div>
        <div class="campo">
          <label for="repetir">Repite la contraseña</label>
          <input type="password" id="repetir" name="repetir" required minlength="12" autocomplete="new-password">
        </div>

        <button class="boton boton--rojo" type="submit">Crear mi cuenta</button>
      </form>
    <?php else: ?>
      <p class="aviso">Arregla primero lo de arriba y recarga esta página.</p>
    <?php endif; ?>

  <?php endif; ?>
</div>
</main>
</body>
</html>
