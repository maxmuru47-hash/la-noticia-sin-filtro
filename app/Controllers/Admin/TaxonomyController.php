<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Middleware\Auth;
use App\Models\Taxonomy;
use App\Services\AuditService;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\Request;
use App\Support\Response;
use App\Support\Str;
use App\Support\View;

final class TaxonomyController
{
    public function __construct(private readonly array $config)
    {
    }

    public function index(Request $request): void
    {
        Auth::require(Auth::CAP_TAXONOMY_MANAGE);

        Response::securityHeaders(false);
        Response::html(View::render('admin/taxonomias', [
            'titulo'     => 'Categorías, temas, autores y fuentes · Panel',
            'noindex'    => true,
            'categorias' => Taxonomy::categoriesWithCounts(),
            'temas'      => Taxonomy::topics(false),
            'etiquetas'  => Taxonomy::tags(),
            'autores'    => Taxonomy::authors(false),
            'fuentes'    => Taxonomy::sources(),
            'expedientes' => \App\Models\Dossier::all(50),
        ], 'layouts/admin'));
    }

    public function store(Request $request): void
    {
        Auth::require(Auth::CAP_TAXONOMY_MANAGE);

        if (!Csrf::verify($request->text('_token'))) {
            $this->flash('La sesión expiró.', 'error');
            Response::redirect('/panel/taxonomias');
        }

        $tipo   = $request->text('entidad');
        $nombre = $request->text('nombre');

        if ($nombre === '') {
            $this->flash('Escribe un nombre.', 'error');
            Response::redirect('/panel/taxonomias');
        }

        try {
            match ($tipo) {
                'categoria'  => $this->storeCategory($request, $nombre),
                'tema'       => Taxonomy::ensureTopic($nombre),
                'etiqueta'   => Taxonomy::ensureTag($nombre),
                'autor'      => $this->storeAuthor($request, $nombre),
                'fuente'     => $this->storeSource($request, $nombre),
                'expediente' => $this->storeDossier($request, $nombre),
                default      => throw new \RuntimeException('Tipo no reconocido.'),
            };
            AuditService::log('taxonomia.crear', $tipo, null, $nombre);
            $this->flash('Guardado.');
        } catch (\Throwable $e) {
            $this->flash($e->getMessage(), 'error');
        }

        Response::redirect('/panel/taxonomias');
    }

    private function storeCategory(Request $request, string $nombre): void
    {
        Database::run(
            'INSERT INTO categories (slug, name, question, description, position)
             VALUES (:slug, :name, :question, :description, :position)
             ON DUPLICATE KEY UPDATE name = VALUES(name), question = VALUES(question), description = VALUES(description)',
            [
                'slug'        => Str::slug($nombre, 120),
                'name'        => $nombre,
                'question'    => $request->text('pregunta') ?: null,
                'description' => $request->text('descripcion') ?: null,
                'position'    => (int) $request->int('posicion', 0),
            ]
        );
    }

    private function storeAuthor(Request $request, string $nombre): void
    {
        Database::run(
            'INSERT INTO authors (uuid, slug, name, role_title, bio, instagram, tiktok)
             VALUES (:uuid, :slug, :name, :role, :bio, :ig, :tt)
             ON DUPLICATE KEY UPDATE name = VALUES(name), role_title = VALUES(role_title), bio = VALUES(bio)',
            [
                'uuid' => Str::uuid(),
                'slug' => Str::slug($nombre, 120),
                'name' => $nombre,
                'role' => $request->text('cargo') ?: null,
                'bio'  => $request->text('bio') ?: null,
                'ig'   => ltrim($request->text('instagram'), '@') ?: null,
                'tt'   => ltrim($request->text('tiktok'), '@') ?: null,
            ]
        );
    }

    private function storeSource(Request $request, string $nombre): void
    {
        $url = trim($request->text('url'));
        Database::insert('sources', [
            'title'       => $nombre,
            'publisher'   => $request->text('editor') ?: null,
            'url'         => $url !== '' && filter_var($url, FILTER_VALIDATE_URL) ? $url : null,
            'source_type' => in_array($request->text('tipo_fuente'), ['documento', 'declaracion', 'medio', 'dato', 'entrevista', 'otro'], true)
                ? $request->text('tipo_fuente') : 'otro',
            'notes'       => $request->text('notas') ?: null,
        ]);
    }

    private function storeDossier(Request $request, string $nombre): void
    {
        Database::run(
            'INSERT INTO dossiers (uuid, slug, title, lead_question, summary, status)
             VALUES (:uuid, :slug, :title, :question, :summary, "abierto")
             ON DUPLICATE KEY UPDATE title = VALUES(title), lead_question = VALUES(lead_question), summary = VALUES(summary)',
            [
                'uuid'     => Str::uuid(),
                'slug'     => Str::slug($nombre, 180),
                'title'    => $nombre,
                'question' => $request->text('pregunta') ?: null,
                'summary'  => $request->text('descripcion') ?: null,
            ]
        );
    }

    private function flash(string $message, string $type = 'ok'): void
    {
        $_SESSION['_flash'] = ['mensaje' => $message, 'tipo' => $type];
    }
}
