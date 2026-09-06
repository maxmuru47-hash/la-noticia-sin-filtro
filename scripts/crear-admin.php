<?php
declare(strict_types=1);

/**
 * Crea la primera cuenta del panel.
 *
 * Uso:
 *   php scripts/crear-admin.php "Nombre" correo@dominio.com "contrasena-larga" [rol]
 *
 * El rol por defecto es superadministrador. La contraseña NUNCA se
 * guarda en el repositorio: se pasa por línea de comandos y se almacena
 * como hash Argon2id.
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Support\Database;
use App\Support\Str;

if (PHP_SAPI !== 'cli') {
    exit("Este script solo se ejecuta desde la línea de comandos.\n");
}

$nombre = $argv[1] ?? null;
$correo = $argv[2] ?? null;
$clave  = $argv[3] ?? null;
$rol    = $argv[4] ?? 'superadministrador';

if ($nombre === null || $correo === null || $clave === null) {
    exit("Uso: php scripts/crear-admin.php \"Nombre\" correo@dominio.com \"contrasena\" [rol]\n");
}

if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    exit("Ese correo no es válido.\n");
}

if (mb_strlen($clave) < 12) {
    exit("La contraseña debe tener al menos 12 caracteres.\n");
}

$fila = Database::first('SELECT id, name FROM roles WHERE slug = :slug', ['slug' => $rol]);
if ($fila === null) {
    exit("El rol \"$rol\" no existe. Carga primero database/seed.sql.\n");
}

if (Database::value('SELECT id FROM users WHERE email = :c', ['c' => $correo]) !== null) {
    exit("Ya existe una cuenta con ese correo.\n");
}

$userId = Database::insert('users', [
    'uuid'          => Str::uuid(),
    'role_id'       => (int) $fila['id'],
    'email'         => mb_strtolower($correo),
    'password_hash' => password_hash($clave, PASSWORD_ARGON2ID),
    'display_name'  => $nombre,
    'is_active'     => 1,
    'must_change_password' => 0,
]);

// Toda cuenta editorial nace con su firma pública. Si ya existe una firma
// con ese mismo nombre y sin cuenta asociada, se enlaza en lugar de
// duplicarla: así la nueva cuenta hereda el archivo ya publicado.
$slug     = Str::slug($nombre, 120);
$existente = Database::first('SELECT id, user_id FROM authors WHERE slug = :s', ['s' => $slug]);

if ($existente !== null && $existente['user_id'] === null) {
    Database::update('authors', ['user_id' => $userId], 'id = :id', ['id' => (int) $existente['id']]);
    echo "Se enlazó la firma existente /autor/$slug con esta cuenta.\n";
} else {
    $n = 2;
    while (Database::value('SELECT id FROM authors WHERE slug = :s', ['s' => $slug]) !== null) {
        $slug = Str::slug($nombre, 110) . '-' . $n++;
    }
    Database::insert('authors', [
        'uuid'       => Str::uuid(),
        'user_id'    => $userId,
        'slug'       => $slug,
        'name'       => $nombre,
        'role_title' => $fila['name'],
        'is_active'  => 1,
    ]);
}

echo "Cuenta creada.\n";
echo "  Usuario:  #$userId  $correo\n";
echo "  Rol:      {$fila['name']}\n";
echo "  Autor:    /autor/$slug\n";
echo "\nEntra en /panel/entrar\n";
