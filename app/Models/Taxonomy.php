<?php
declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use App\Support\Str;

final class Taxonomy
{
    public static function categories(bool $onlyActive = true): array
    {
        return Database::all(
            'SELECT * FROM categories' . ($onlyActive ? ' WHERE is_active = 1' : '') . ' ORDER BY position, name'
        );
    }

    public static function categoryBySlug(string $slug): ?array
    {
        return Database::first('SELECT * FROM categories WHERE slug = :slug', ['slug' => $slug]);
    }

    public static function tags(): array
    {
        return Database::all('SELECT * FROM tags ORDER BY name');
    }

    public static function tagBySlug(string $slug): ?array
    {
        return Database::first('SELECT * FROM tags WHERE slug = :slug', ['slug' => $slug]);
    }

    public static function topics(bool $onlyActive = true): array
    {
        return Database::all(
            'SELECT * FROM topics' . ($onlyActive ? ' WHERE is_active = 1' : '') . ' ORDER BY name'
        );
    }

    public static function topicBySlug(string $slug): ?array
    {
        return Database::first('SELECT * FROM topics WHERE slug = :slug', ['slug' => $slug]);
    }

    public static function authors(bool $onlyActive = true): array
    {
        return Database::all(
            'SELECT * FROM authors' . ($onlyActive ? ' WHERE is_active = 1' : '') . ' ORDER BY name'
        );
    }

    public static function authorBySlug(string $slug): ?array
    {
        return Database::first('SELECT * FROM authors WHERE slug = :slug', ['slug' => $slug]);
    }

    public static function sources(): array
    {
        return Database::all('SELECT * FROM sources ORDER BY title');
    }

    /** Crea la etiqueta si no existe y devuelve su identificador. */
    public static function ensureTag(string $name): int
    {
        $slug     = Str::slug($name, 120);
        $existing = Database::value('SELECT id FROM tags WHERE slug = :slug', ['slug' => $slug]);
        if ($existing !== null) {
            return (int) $existing;
        }
        return Database::insert('tags', ['slug' => $slug, 'name' => trim($name)]);
    }

    public static function ensureTopic(string $name): int
    {
        $slug     = Str::slug($name, 120);
        $existing = Database::value('SELECT id FROM topics WHERE slug = :slug', ['slug' => $slug]);
        if ($existing !== null) {
            return (int) $existing;
        }
        return Database::insert('topics', ['slug' => $slug, 'name' => trim($name)]);
    }

    /** Categorias con su recuento publico, para el pie y el archivo. */
    public static function categoriesWithCounts(): array
    {
        return Database::all(
            'SELECT c.*, COUNT(a.id) AS total
               FROM categories c
               LEFT JOIN articles a ON a.category_id = c.id
                    AND a.deleted_at IS NULL
                    AND a.status IN ("publicada", "actualizada", "archivada")
              WHERE c.is_active = 1
              GROUP BY c.id ORDER BY c.position, c.name'
        );
    }
}
