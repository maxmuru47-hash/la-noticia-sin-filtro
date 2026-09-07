<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Middleware\Auth;
use App\Models\Live;
use App\Services\AuditService;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\Dates;
use App\Support\Paginator;
use App\Support\Request;
use App\Support\Response;
use App\Support\Str;
use App\Support\View;

/**
 * Lives. La fecha se escribe en hora de Caracas y se guarda en UTC. Una
 * sola fuente de verdad, tal como exige el documento maestro.
 */
final class LiveController
{
    public function __construct(private readonly array $config)
    {
    }

    public function index(Request $request): void
    {
        Auth::require(Auth::CAP_LIVE_MANAGE);

        $perPage = $this->config['pagination']['admin'];
        $page    = Paginator::resolvePage($request->input('pagina'));
        $result  = Live::forAdmin($perPage, ($page - 1) * $perPage);

        Response::securityHeaders(false);
        Response::html(View::render('admin/lives', [
            'titulo'    => 'Lives · Panel',
            'noindex'   => true,
            'lives'     => $result['items'],
            'paginador' => new Paginator($result['total'], $perPage, $page, '/panel/lives', []),
        ], 'layouts/admin'));
    }

    public function create(Request $request): void
    {
        Auth::require(Auth::CAP_LIVE_MANAGE);
        $this->renderForm(null);
    }

    public function store(Request $request): void
    {
        Auth::require(Auth::CAP_LIVE_MANAGE);
        $this->assertToken($request);

        $title = $request->text('titulo');
        if ($title === '') {
            $this->flash('El Live necesita un título.', 'error');
            Response::redirect('/panel/lives/nuevo');
        }

        $data = $this->payload($request);
        $data['uuid'] = Str::uuid();
        $data['slug'] = $this->uniqueSlug($title);

        $id = Database::insert('lives', $data);
        AuditService::log('live.crear', 'live', $id, $title);

        $this->flash('Live creado.');
        Response::redirect('/panel/lives/' . $id);
    }

    public function edit(Request $request, array $params): void
    {
        Auth::require(Auth::CAP_LIVE_MANAGE);

        $live = Live::findById((int) $params['id']);
        if ($live === null) {
            $this->flash('Ese Live no existe.', 'error');
            Response::redirect('/panel/lives');
        }

        $this->renderForm($live);
    }

    public function update(Request $request, array $params): void
    {
        Auth::require(Auth::CAP_LIVE_MANAGE);
        $this->assertToken($request);

        $id = (int) $params['id'];
        Database::update('lives', $this->payload($request), 'id = :id', ['id' => $id]);
        AuditService::log('live.editar', 'live', $id, $request->text('titulo'));

        $this->flash('Live actualizado.');
        Response::redirect('/panel/lives/' . $id);
    }

    /**
     * CARGA EN LOTE
     *
     * La ficha completa de un Live tiene doce campos. Para el archivo
     * historico —programas que ya ocurrieron y solo necesitan existir con su
     * titulo, su fecha y su grabacion— doce campos por programa es un muro:
     * diez programas son ciento veinte casillas.
     *
     * Aqui se pegan todos de una vez, uno por linea. Lo que se crea no es un
     * Live de segunda: es el mismo registro, con la misma direccion
     * permanente, al que despues se le puede abrir la ficha y anadirle
     * resumen, invitados y pendientes.
     *
     * Dos decisiones deliberadas:
     *  - Siempre hay paso de revision antes de escribir. Quien pega diez
     *    lineas no puede comprobar diez fechas de cabeza; se las mostramos
     *    interpretadas y decide.
     *  - Un titulo que ya existe se salta, no se duplica. Volver a pegar la
     *    misma lista no crea diez copias.
     */
    public function bulkForm(Request $request): void
    {
        Auth::require(Auth::CAP_LIVE_MANAGE);
        $this->renderBulk((string) ($_SESSION['_lives_lote'] ?? ''), []);
        unset($_SESSION['_lives_lote']);
    }

    public function bulkStore(Request $request): void
    {
        Auth::require(Auth::CAP_LIVE_MANAGE);
        $this->assertToken($request);

        $lista = trim($request->text('lista'));
        if ($lista === '') {
            $this->flash('No pegaste nada. Escribe al menos un Live.', 'error');
            Response::redirect('/panel/lives/cargar');
        }

        $filas = self::interpretar($lista);

        // Marcar cuales ya existen, para poder decirlo antes de escribir.
        foreach ($filas as $i => $fila) {
            if ($fila['error'] === null) {
                $filas[$i]['repetido'] = Database::value(
                    'SELECT id FROM lives WHERE slug = :slug',
                    ['slug' => Str::slug($fila['titulo'])]
                ) !== null;
            }
        }

        if ($request->text('accion') !== 'confirmar') {
            $this->renderBulk($lista, $filas);
            return;
        }

        $creados = 0;
        $saltados = 0;
        foreach ($filas as $fila) {
            if ($fila['error'] !== null || $fila['repetido']) {
                $saltados++;
                continue;
            }

            $id = Database::insert('lives', [
                'uuid'       => Str::uuid(),
                'slug'       => $this->uniqueSlug($fila['titulo']),
                'title'      => $fila['titulo'],
                'status'     => $fila['estado'],
                'starts_at'  => $fila['empieza'],
                'timezone'   => $this->config['app']['timezone'],
                'stream_url' => $fila['enlace'],
                'is_demo'    => 0,
            ]);
            AuditService::log('live.crear', 'live', $id, $fila['titulo']);
            $creados++;
        }

        $this->flash(sprintf(
            '%d %s cargado%s.%s',
            $creados,
            $creados === 1 ? 'Live' : 'Lives',
            $creados === 1 ? '' : 's',
            $saltados > 0 ? sprintf(' %d línea%s no se cargó: o ya existía o no se entendió.', $saltados, $saltados === 1 ? '' : 's') : ''
        ), $creados > 0 ? 'ok' : 'error');

        Response::redirect('/panel/lives');
    }

    private function renderBulk(string $lista, array $filas): void
    {
        Response::securityHeaders(false);
        Response::html(View::render('admin/lives-cargar', [
            'titulo'  => 'Cargar Lives · Panel',
            'noindex' => true,
            'lista'   => $lista,
            'filas'   => $filas,
        ], 'layouts/admin'));
    }

    /**
     * Cada linea es: Titulo | fecha | enlace. La fecha y el enlace pueden
     * faltar. Nunca se adivina un titulo: sin titulo la linea es un error y
     * se dice cual, en vez de crear un Live sin nombre.
     *
     * @return list<array{titulo:string,empieza:?string,enlace:?string,estado:string,error:?string,repetido:bool,cruda:string}>
     */
    public static function interpretar(string $texto): array
    {
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        $filas = [];

        foreach (explode("\n", $texto) as $linea) {
            $linea = trim($linea);
            if ($linea === '') {
                continue;
            }

            $partes = array_map('trim', explode('|', $linea));
            $titulo = $partes[0] ?? '';

            $fila = [
                'titulo'   => $titulo,
                'empieza'  => null,
                'enlace'   => null,
                'estado'   => 'finalizado',
                'error'    => null,
                'repetido' => false,
                'cruda'    => $linea,
            ];

            if ($titulo === '') {
                $fila['error'] = 'Falta el título.';
                $filas[] = $fila;
                continue;
            }
            if (mb_strlen($titulo) > 255) {
                $fila['error'] = 'El título pasa de 255 caracteres.';
                $filas[] = $fila;
                continue;
            }

            // Las partes 2 y 3 se reconocen por lo que son, no por su orden:
            // quien pega una lista no se acuerda de cual iba primero.
            foreach (array_slice($partes, 1) as $parte) {
                if ($parte === '') {
                    continue;
                }
                if (preg_match('~^https?://~i', $parte)) {
                    $fila['enlace'] = self::urlSegura($parte);
                    if ($fila['enlace'] === null) {
                        $fila['error'] = 'El enlace no es una dirección https válida.';
                    }
                    continue;
                }
                $local = self::fechaLocal($parte);
                if ($local === null) {
                    $fila['error'] = 'No entendí «' . $parte . '» como fecha ni como enlace.';
                    continue;
                }
                $fila['empieza'] = Dates::toUtc($local);
            }

            // Un programa con fecha futura esta programado; sin fecha no se
            // puede saber, y lo honesto es 'anunciado', no inventar que ya paso.
            if ($fila['empieza'] === null) {
                $fila['estado'] = 'anunciado';
            } elseif ($fila['empieza'] > Dates::nowUtc()) {
                $fila['estado'] = 'programado';
            }

            $filas[] = $fila;
        }

        return $filas;
    }

    /** Acepta 2026-08-12, 12/08/2026 y 12-08-2026, con hora opcional. */
    private static function fechaLocal(string $texto): ?string
    {
        $texto = trim($texto);
        $hora  = '20:00';

        if (preg_match('/\b(\d{1,2}):(\d{2})\b/', $texto, $m)) {
            $h = (int) $m[1];
            $i = (int) $m[2];
            if ($h > 23 || $i > 59) {
                return null;
            }
            $hora  = sprintf('%02d:%02d', $h, $i);
            $texto = trim(str_replace($m[0], '', $texto));
        }

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $texto, $m)) {
            [$y, $mes, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('~^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$~', $texto, $m)) {
            [$d, $mes, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        if (!checkdate($mes, $d, $y)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d %s', $y, $mes, $d, $hora);
    }

    private static function urlSegura(string $url): ?string
    {
        $url = trim($url);
        return filter_var($url, FILTER_VALIDATE_URL) && str_starts_with($url, 'https://') ? $url : null;
    }

    private function renderForm(?array $live): void
    {
        $id = $live === null ? 0 : (int) $live['id'];

        Response::securityHeaders(false);
        Response::html(View::render('admin/live-editor', [
            'titulo'    => ($live === null ? 'Nuevo Live' : 'Editar: ' . $live['title']) . ' · Panel',
            'noindex'   => true,
            'live'      => $live,
            'preguntas' => $id === 0 ? [] : Live::questions($id, ['pendiente', 'aprobada', 'seleccionada', 'respondida']),
            'antes'     => $id === 0 ? [] : Live::articles($id, 'antes'),
            'despues'   => $id === 0 ? [] : Live::articles($id, 'despues'),
            'grabaciones' => Database::all('SELECT id, title FROM videos WHERE deleted_at IS NULL AND video_type = "live" ORDER BY id DESC LIMIT 50'),
        ], 'layouts/admin'));
    }

    private function payload(Request $request): array
    {
        return [
            'title'              => $request->text('titulo'),
            'lead_question'      => $request->text('pregunta_principal') ?: null,
            'summary'            => $request->text('resumen') ?: null,
            'status'             => array_key_exists($request->text('estado'), Live::STATUSES) ? $request->text('estado') : 'anunciado',
            'starts_at'          => Dates::toUtc($request->text('empieza')),
            'ends_at'            => Dates::toUtc($request->text('termina')),
            'timezone'           => $this->config['app']['timezone'],
            'guests'             => $request->text('invitados') ?: null,
            'stream_url'         => $this->safeUrl($request->text('url_transmision')),
            'recording_video_id' => $request->int('grabacion'),
            'aftermath'          => $request->text('resumen_posterior') ?: null,
            'pending_matters'    => $request->text('pendientes') ?: null,
            'is_demo'            => $request->bool('es_demo') ? 1 : 0,
        ];
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title);
        $slug = $base;
        $n    = 2;
        while (Database::value('SELECT id FROM lives WHERE slug = :slug', ['slug' => $slug]) !== null) {
            $slug = $base . '-' . $n++;
        }
        return $slug;
    }

    private function safeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        return filter_var($url, FILTER_VALIDATE_URL) && str_starts_with($url, 'https://') ? $url : null;
    }

    private function assertToken(Request $request): void
    {
        if (!Csrf::verify($request->text('_token'))) {
            $this->flash('La sesión expiró.', 'error');
            Response::redirect('/panel/lives');
        }
    }

    private function flash(string $message, string $type = 'ok'): void
    {
        $_SESSION['_flash'] = ['mensaje' => $message, 'tipo' => $type];
    }
}
