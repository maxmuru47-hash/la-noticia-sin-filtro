<?php
declare(strict_types=1);

namespace App\Services;

use App\Middleware\Auth;
use App\Support\Database;
use App\Support\Str;
use RuntimeException;

/**
 * Carga de archivos.
 *
 * Validacion en este orden y sin atajos: error de PHP, tamano, tipo MIME
 * real leido del contenido (no del nombre ni de la cabecera del cliente),
 * extension derivada del MIME aceptado, y nombre generado por el servidor.
 * El nombre original del visitante nunca toca el sistema de archivos.
 */
final class UploadService
{
    public function __construct(private readonly array $config)
    {
    }

    public function storeImage(array $file, array $meta = []): int
    {
        return $this->store($file, 'imagen', $this->config['uploads']['image_mimes'], $this->config['uploads']['max_image_bytes'], $meta);
    }

    public function storeDocument(array $file, array $meta = []): int
    {
        return $this->store($file, 'documento', $this->config['uploads']['document_mimes'], $this->config['uploads']['max_document_bytes'], $meta);
    }

    public function storeCaptions(array $file): string
    {
        $this->assertUploadOk($file);

        if ($file['size'] > 2 * 1024 * 1024) {
            throw new RuntimeException('El archivo de subtítulos supera 2 MB.');
        }

        $content = (string) file_get_contents($file['tmp_name']);
        if (!str_starts_with(ltrim($content, "\xEF\xBB\xBF \t\r\n"), 'WEBVTT')) {
            throw new RuntimeException('El archivo no es un WebVTT válido: debe empezar por WEBVTT.');
        }

        $relative = $this->relativeDirectory() . '/' . Str::uuid() . '.vtt';
        $absolute = $this->config['uploads']['public_path'] . '/' . $relative;
        $this->ensureDirectory(dirname($absolute));

        if (!move_uploaded_file($file['tmp_name'], $absolute) && !rename($file['tmp_name'], $absolute)) {
            throw new RuntimeException('No se pudo guardar el archivo de subtítulos.');
        }
        @chmod($absolute, 0644);

        return '/uploads/' . $relative;
    }

    /** Video propio. El original se conserva fuera de la carpeta publica. */
    public function storeVideo(array $file): array
    {
        $this->assertUploadOk($file);

        $maxBytes = $this->config['uploads']['max_video_bytes'];
        if ($file['size'] > $maxBytes) {
            throw new RuntimeException(sprintf('El video supera el máximo de %d MB.', (int) ($maxBytes / 1048576)));
        }

        $mime      = $this->detectMime($file['tmp_name']);
        $allowed   = $this->config['uploads']['video_mimes'];
        if (!isset($allowed[$mime])) {
            throw new RuntimeException('Formato de video no permitido. Se aceptan MP4 y WebM.');
        }

        $relative = $this->relativeDirectory() . '/' . Str::uuid() . '.' . $allowed[$mime];
        $absolute = $this->config['uploads']['public_path'] . '/' . $relative;
        $this->ensureDirectory(dirname($absolute));

        if (!move_uploaded_file($file['tmp_name'], $absolute) && !rename($file['tmp_name'], $absolute)) {
            throw new RuntimeException('No se pudo guardar el video.');
        }
        @chmod($absolute, 0644);

        return [
            'ruta'  => '/uploads/' . $relative,
            'bytes' => (int) $file['size'],
            'mime'  => $mime,
        ];
    }

    private function store(array $file, string $kind, array $allowedMimes, int $maxBytes, array $meta): int
    {
        $this->assertUploadOk($file);

        if ($file['size'] > $maxBytes) {
            throw new RuntimeException(sprintf('El archivo supera el máximo de %d MB.', (int) ($maxBytes / 1048576)));
        }

        $mime = $this->detectMime($file['tmp_name']);
        if (!isset($allowedMimes[$mime])) {
            throw new RuntimeException('Tipo de archivo no permitido: ' . $mime);
        }

        $extension = $allowedMimes[$mime];
        $relative  = $this->relativeDirectory() . '/' . Str::uuid() . '.' . $extension;
        $absolute  = $this->config['uploads']['public_path'] . '/' . $relative;
        $this->ensureDirectory(dirname($absolute));

        // El original intacto se guarda fuera de public, para no volver a
        // comprimir un archivo ya comprimido en el futuro.
        $originalRelative = null;
        if ($kind === 'imagen') {
            $originalRelative = gmdate('Y/m') . '/' . basename($relative);
            $originalAbsolute = $this->config['uploads']['originals_path'] . '/' . $originalRelative;
            $this->ensureDirectory(dirname($originalAbsolute));
            @copy($file['tmp_name'], $originalAbsolute);
            @chmod($originalAbsolute, 0640);
        }

        if (!move_uploaded_file($file['tmp_name'], $absolute) && !rename($file['tmp_name'], $absolute)) {
            throw new RuntimeException('No se pudo guardar el archivo.');
        }
        @chmod($absolute, 0644);

        $width = $height = null;
        if ($kind === 'imagen') {
            $size = @getimagesize($absolute);
            if ($size !== false) {
                [$width, $height] = $size;
            }
        }

        $id = Database::insert('media_assets', [
            'uuid'          => Str::uuid(),
            'kind'          => $kind,
            'original_name' => mb_substr((string) $file['name'], 0, 255),
            'storage_path'  => '/uploads/' . $relative,
            'original_kept_path' => $originalRelative,
            'mime_type'     => $mime,
            'bytes'         => (int) $file['size'],
            'width'         => $width,
            'height'        => $height,
            'alt_text'      => isset($meta['alt']) ? mb_substr((string) $meta['alt'], 0, 255) : null,
            'caption'       => isset($meta['caption']) ? mb_substr((string) $meta['caption'], 0, 500) : null,
            'credit'        => isset($meta['credit']) ? mb_substr((string) $meta['credit'], 0, 190) : null,
            'is_synthetic'  => !empty($meta['sintetica']) ? 1 : 0,
            'uploaded_by'   => Auth::id(),
        ]);

        AuditService::log('medio.subir', 'medio', $id, (string) $file['name']);

        return $id;
    }

    private function assertUploadOk(array $file): void
    {
        if (!isset($file['tmp_name'], $file['error'])) {
            throw new RuntimeException('No se recibió ningún archivo.');
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException(match ($file['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo excede el tamaño permitido por el servidor.',
                UPLOAD_ERR_PARTIAL   => 'La carga se interrumpió antes de terminar.',
                UPLOAD_ERR_NO_FILE   => 'No se seleccionó ningún archivo.',
                UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo escribir el archivo temporal.',
                default              => 'Error desconocido al subir el archivo.',
            });
        }

        if (!is_uploaded_file($file['tmp_name']) && !is_file($file['tmp_name'])) {
            throw new RuntimeException('El archivo temporal no es válido.');
        }
    }

    /** MIME leido del contenido real, nunca del nombre ni del cliente. */
    private function detectMime(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($path);
        return $mime === false ? 'application/octet-stream' : $mime;
    }

    private function relativeDirectory(): string
    {
        return gmdate('Y/m');
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('No se pudo crear el directorio de destino.');
        }
    }
}
