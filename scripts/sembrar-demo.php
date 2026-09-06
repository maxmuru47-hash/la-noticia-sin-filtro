<?php
declare(strict_types=1);

/**
 * CONTENIDO DE DEMOSTRACIÓN
 *
 * Todo lo que crea este script está marcado con is_demo = 1 y se rotula
 * de forma visible en la página. NADA de esto describe hechos reales:
 * no hay cifras verdaderas, ni fuentes verdaderas, ni declaraciones de
 * personas reales. Los actores y las instituciones son inventados.
 *
 * Se crea a través de los servicios de la aplicación, no con INSERT
 * directos, para que se generen también las revisiones, las reservas de
 * dirección y el índice del buscador. Así la demo prueba el sistema real.
 *
 * Uso:  php scripts/sembrar-demo.php [--reiniciar]
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Services\ArticleService;
use App\Services\SearchService;
use App\Support\Database;
use App\Support\Dates;
use App\Support\Str;

if (PHP_SAPI !== 'cli') {
    exit("Este script solo se ejecuta desde la línea de comandos.\n");
}

$reiniciar = in_array('--reiniciar', $argv, true);

if ($reiniciar) {
    echo "Borrando SOLO el contenido marcado como demostración...\n";
    Database::run('SET FOREIGN_KEY_CHECKS = 0');
    Database::run('DELETE FROM articles WHERE is_demo = 1');
    Database::run('DELETE FROM videos WHERE is_demo = 1');
    Database::run('DELETE FROM lives WHERE is_demo = 1');
    Database::run('DELETE FROM polls WHERE is_demo = 1');
    Database::run('DELETE FROM community_questions WHERE is_demo = 1');
    Database::run('DELETE FROM dossiers WHERE is_demo = 1');
    Database::run('DELETE FROM homepage_slots');
    Database::run('SET FOREIGN_KEY_CHECKS = 1');
}

$hoy = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$ago = static fn (int $dias, int $horas = 0): string => $GLOBALS['hoy']->modify("-{$dias} days -{$horas} hours")->format('Y-m-d H:i:s');
$en  = static fn (int $dias, int $horas = 0): string => $GLOBALS['hoy']->modify("+{$dias} days +{$horas} hours")->format('Y-m-d H:i:s');

// ---------------------------------------------------------------------
// AUTORES DE DEMOSTRACIÓN
// ---------------------------------------------------------------------

function autorDemo(string $nombre, string $cargo, string $bio): int
{
    $slug = Str::slug($nombre, 120);
    $id   = Database::value('SELECT id FROM authors WHERE slug = :s', ['s' => $slug]);
    if ($id !== null) {
        return (int) $id;
    }
    return Database::insert('authors', [
        'uuid'       => Str::uuid(),
        'slug'       => $slug,
        'name'       => $nombre,
        'role_title' => $cargo,
        'bio'        => $bio,
        'is_active'  => 1,
    ]);
}

$maxId = autorDemo(
    'Max González',
    'Director editorial',
    'Conduce SIN FILTRO con Max. Firma su opinión cuando opina, y la separa siempre del hecho reportado. '
    . 'Ficha de demostración: los datos definitivos se completan antes del lanzamiento.'
);

$editoraId = autorDemo(
    'Editora de demostración',
    'Editora responsable',
    'Perfil ficticio creado para probar la plataforma. Verifica, clasifica y corrige. '
    . 'Esta persona no existe: sustitúyela por el equipo real antes de publicar.'
);

echo "Autores listos.\n";

$categorias = [];
foreach (Database::all('SELECT id, slug FROM categories') as $fila) {
    $categorias[$fila['slug']] = (int) $fila['id'];
}

// ---------------------------------------------------------------------
// EXPEDIENTE VIVO
// ---------------------------------------------------------------------

$expedienteSlug = 'demo-el-costo-de-la-electricidad';
$expedienteId   = Database::value('SELECT id FROM dossiers WHERE slug = :s', ['s' => $expedienteSlug]);

if ($expedienteId === null) {
    $expedienteId = Database::insert('dossiers', [
        'uuid'          => Str::uuid(),
        'slug'          => $expedienteSlug,
        'title'         => 'DEMO · El costo de la electricidad',
        'lead_question' => '¿Quién termina pagando cuando cambia la tarifa eléctrica?',
        'summary'       => 'Expediente de demostración. Sigue una medida ficticia desde su anuncio hasta sus efectos '
            . 'en comercios y hogares. Ninguno de estos hechos ocurrió.',
        'status'        => 'en_seguimiento',
        'is_demo'       => 1,
        'opened_at'     => $ago(40),
    ]);
} else {
    $expedienteId = (int) $expedienteId;
}

foreach ([
    [$ago(38), 'Se anuncia la medida', 'Una autoridad ficticia anuncia un ajuste de tarifa. Evento de demostración.', 'confirmado'],
    [$ago(30), 'Los comercios advierten', 'Gremios inventados calculan un impacto en costos. Evento de demostración.', 'probable'],
    [$ago(12), 'Primeras facturas', 'Llegan los primeros recibos con el nuevo cálculo. Evento de demostración.', 'confirmado'],
    [$ago(3),  'Se anuncia una revisión', 'Se abre una revisión de la medida. Evento de demostración.', 'disputado'],
] as $indice => [$fecha, $titulo, $detalle, $certeza]) {
    $existe = Database::value(
        'SELECT id FROM timeline_events WHERE dossier_id = :d AND title = :t',
        ['d' => $expedienteId, 't' => $titulo]
    );
    if ($existe === null) {
        Database::insert('timeline_events', [
            'dossier_id'  => $expedienteId,
            'occurred_on' => substr($fecha, 0, 10),
            'title'       => $titulo,
            'detail'      => $detalle,
            'certainty'   => $certeza,
            'position'    => $indice,
        ]);
    }
}

echo "Expediente y cronología listos.\n";

// ---------------------------------------------------------------------
// FUENTES DE DEMOSTRACIÓN
// ---------------------------------------------------------------------

function fuenteDemo(string $titulo, string $editor, string $tipo): int
{
    $id = Database::value('SELECT id FROM sources WHERE title = :t', ['t' => $titulo]);
    if ($id !== null) {
        return (int) $id;
    }
    return Database::insert('sources', [
        'title'       => $titulo,
        'publisher'   => $editor,
        'source_type' => $tipo,
        'notes'       => 'Fuente ficticia creada para la demostración. No corresponde a ningún documento real.',
    ]);
}

$fuenteResolucion = fuenteDemo('DEMO · Resolución ficticia 000-2026', 'Organismo de demostración', 'documento');
$fuenteGremio     = fuenteDemo('DEMO · Comunicado de un gremio inventado', 'Gremio de demostración', 'declaracion');
$fuenteDato       = fuenteDemo('DEMO · Serie de datos inventada', 'Registro de demostración', 'dato');

// ---------------------------------------------------------------------
// PIEZAS
// ---------------------------------------------------------------------

/** Crea una pieza de demostración completa a través del servicio real. */
function piezaDemo(array $datos): int
{
    $existente = Database::value('SELECT id FROM articles WHERE slug = :s', ['s' => $datos['slug']]);
    if ($existente !== null) {
        return (int) $existente;
    }

    $id = ArticleService::create(array_merge($datos, ['is_demo' => true]));

    if (!empty($datos['etiquetas'])) {
        ArticleService::syncTags($id, $datos['etiquetas']);
    }
    if (!empty($datos['temas'])) {
        ArticleService::syncTopics($id, $datos['temas']);
    }
    if (!empty($datos['fuentes'])) {
        foreach ($datos['fuentes'] as $posicion => [$fuenteId, $certeza]) {
            Database::run(
                'INSERT IGNORE INTO article_sources (article_id, source_id, position, certainty)
                 VALUES (:a, :s, :p, :c)',
                ['a' => $id, 's' => $fuenteId, 'p' => $posicion, 'c' => $certeza]
            );
        }
    }
    if (!empty($datos['impactos'])) {
        foreach ($datos['impactos'] as $posicion => [$perfil, $titular, $detalle, $certeza]) {
            Database::insert('impact_profiles', [
                'article_id' => $id,
                'profile'    => $perfil,
                'headline'   => $titular,
                'detail'     => $detalle,
                'certainty'  => $certeza,
                'position'   => $posicion,
            ]);
        }
    }

    return $id;
}

$bloques = static fn (array $partes): string => json_encode($partes, JSON_UNESCAPED_UNICODE);

// 1. NOTICIA VIVA principal, con expediente, pulso y video.
$pieza1 = piezaDemo([
    'slug'            => 'demo-que-cambia-con-la-nueva-tarifa-electrica',
    'title'           => 'DEMO · Qué cambia con la nueva tarifa eléctrica',
    'subtitle'        => 'Pieza de demostración. Los hechos, cifras y fuentes de este texto son inventados.',
    'lead_question'   => '¿Quién termina pagando cuando cambia la tarifa eléctrica?',
    'summary'         => 'Una medida ficticia cambia el cálculo del recibo. Esto es lo que se sabe, lo que no, '
        . 'y qué significaría para un hogar y para un comercio pequeño.',
    'summary_60s'     => '<p>La medida cambia cómo se calcula el consumo. Un hogar promedio vería un ajuste; un comercio pequeño, uno mayor. '
        . 'Todavía no se sabe si habrá excepciones.</p><p><strong>Todo en esta pieza es una demostración.</strong></p>',
    'summary_5min'    => '<p>El cálculo pasa de un tramo único a tramos escalonados. Los gremios inventados de esta demostración '
        . 'advierten un efecto mayor en locales con refrigeración. La autoridad ficticia anunció una revisión, sin fecha.</p>'
        . '<p>Lo que sigue abierto: si la revisión cambia los tramos, y desde cuándo.</p>',
    'editorial_type'  => 'noticia',
    'category_id'     => $categorias['dinero-real'] ?? null,
    'author_id'       => $maxId,
    'editor_id'       => $editoraId,
    'dossier_id'      => $expedienteId,
    'facts_confirmed' => 'La resolución ficticia de esta demostración fue publicada y entra en vigor. El texto define tramos escalonados de consumo.',
    'facts_probable'  => 'El efecto sería mayor en comercios con refrigeración continua. El cálculo de los gremios inventados no ha sido verificado de forma independiente.',
    'facts_unknown'   => 'Si la revisión anunciada cambiará los tramos, desde cuándo, y si habrá excepciones por actividad.',
    'perspective_a_title' => 'A favor: los tramos protegen al consumo bajo',
    'perspective_a_body'  => 'El mejor argumento de este lado, en la demostración, es que un tramo escalonado cobra menos a quien consume menos, '
        . 'y traslada el costo a quien más consume. Sin escalones, todos pagan igual sin importar su uso.',
    'perspective_b_title' => 'En contra: el escalón castiga a quien no puede reducir',
    'perspective_b_body'  => 'El mejor argumento del otro lado, en la demostración, es que un comercio con refrigeración no puede bajar su consumo sin cerrar. '
        . 'Para ese caso el escalón no es un incentivo, es un costo fijo mayor.',
    'max_opinion'     => 'Opinión de demostración: una medida no se juzga por su intención, sino por quién termina pagándola. '
        . 'Mientras no se publique quién queda exento, esto es una promesa, no una política.',
    'legacy_question' => '¿Qué actividades quedarán exentas, y quién decide esa lista?',
    'etiquetas'       => ['tarifas', 'electricidad', 'comercios'],
    'temas'           => ['Costo de vida'],
    'fuentes'         => [[$fuenteResolucion, 'confirmado'], [$fuenteGremio, 'disputado'], [$fuenteDato, 'probable']],
    'impactos'        => [
        ['familia', 'Un hogar promedio vería un ajuste moderado', 'Cifra de demostración: no corresponde a ningún cálculo real.', 'probable'],
        ['negocio', 'Un local con refrigeración vería el mayor impacto', 'Escenario de demostración construido para probar la plataforma.', 'probable'],
        ['diaspora', 'Quien mantiene una vivienda cerrada pagaría el tramo base', 'Escenario de demostración.', 'probable'],
    ],
    'body_blocks' => $bloques([
        ['tipo' => 'aviso', 'texto' => 'Esta pieza es una demostración técnica. Ningún hecho, cifra, fuente o declaración de este texto es real.'],
        ['tipo' => 'parrafo', 'texto' => 'La medida ficticia de esta demostración cambia la forma de calcular el recibo: en lugar de un tramo único, se aplican escalones según el consumo del mes.'],
        ['tipo' => 'subtitulo', 'texto' => 'Cómo funcionaba antes'],
        ['tipo' => 'parrafo', 'texto' => 'Hasta ahora, en el escenario de esta demostración, el cálculo era plano. Quien consumía poco y quien consumía mucho pagaban la misma tarifa por unidad.'],
        ['tipo' => 'dato', 'valor' => '3 tramos', 'nota' => 'El nuevo esquema de la demostración divide el consumo en tres escalones.', 'fuente' => 'Dato inventado para la demostración'],
        ['tipo' => 'subtitulo', 'texto' => 'Qué dicen los gremios'],
        ['tipo' => 'cita', 'texto' => 'Un comercio con refrigeración no puede bajar su consumo sin cerrar.', 'autor' => 'Portavoz ficticio de un gremio inventado'],
        ['tipo' => 'lista', 'items' => [
            'El cálculo pasa a ser escalonado.',
            'El tramo base sigue siendo el más barato.',
            'No se ha publicado la lista de exenciones.',
        ]],
        ['tipo' => 'parrafo', 'texto' => 'La autoridad ficticia anunció una revisión, sin fecha. Hasta que esa revisión se publique, el efecto real de la medida no puede calcularse con precisión.'],
        ['tipo' => 'separador'],
        ['tipo' => 'parrafo', 'texto' => 'Esta pieza se actualizará si la revisión se publica. El historial de cambios quedará visible al final de la página.'],
    ]),
]);

// 2. EXPLICADOR
$pieza2 = piezaDemo([
    'slug'           => 'demo-como-leer-un-recibo-de-electricidad',
    'title'          => 'DEMO · Cómo leer un recibo de electricidad sin perderte',
    'subtitle'       => 'Explicador de demostración.',
    'lead_question'  => '¿Qué significa cada línea de tu recibo?',
    'summary'        => 'Un explicador de demostración que recorre el recibo línea por línea, con ejemplos inventados.',
    'editorial_type' => 'explicador',
    'category_id'    => $categorias['dinero-real'] ?? null,
    'author_id'      => $editoraId,
    'editor_id'      => $maxId,
    'dossier_id'     => $expedienteId,
    'legacy_question' => '¿Qué haces cuando el recibo no coincide con tu consumo?',
    'etiquetas'      => ['electricidad', 'guias'],
    'temas'          => ['Costo de vida'],
    'body_blocks'    => $bloques([
        ['tipo' => 'aviso', 'texto' => 'Explicador de demostración. Los ejemplos son inventados.'],
        ['tipo' => 'parrafo', 'texto' => 'Un recibo tiene tres bloques: lo que consumiste, cómo se calculó y qué se sumó aparte.'],
        ['tipo' => 'subtitulo', 'texto' => 'Lo que consumiste'],
        ['tipo' => 'parrafo', 'texto' => 'Es la diferencia entre dos lecturas del medidor. Si esa diferencia no coincide con tu uso, el resto del cálculo tampoco va a coincidir.'],
        ['tipo' => 'subtitulo', 'texto' => 'Cómo se calculó'],
        ['tipo' => 'parrafo', 'texto' => 'Aquí aparecen los tramos. Con escalones, una parte de tu consumo se cobra a un precio y el resto a otro.'],
    ]),
]);

// 3. VERIFICACIÓN
$pieza3 = piezaDemo([
    'slug'           => 'demo-verificamos-la-cifra-que-circula-en-redes',
    'title'          => 'DEMO · Verificamos la cifra que circula en redes',
    'subtitle'       => 'Verificación de demostración.',
    'lead_question'  => '¿De dónde salió esa cifra que estás viendo?',
    'summary'        => 'Una cifra inventada circula sin fuente. Esto es lo que encontramos al buscarla, en un ejercicio de demostración.',
    'editorial_type' => 'verificacion',
    'category_id'    => $categorias['venezuela'] ?? null,
    'author_id'      => $editoraId,
    'editor_id'      => $maxId,
    'facts_confirmed' => 'La cifra de esta demostración no aparece en ningún documento citado por quienes la difunden.',
    'facts_probable'  => 'Podría venir de una lectura equivocada de un dato distinto. Escenario de demostración.',
    'facts_unknown'   => 'Quién la publicó primero.',
    'legacy_question' => '¿Cómo se rastrea el origen de una cifra que ya circuló mil veces?',
    'etiquetas'      => ['verificacion'],
    'temas'          => ['Desinformación'],
    'fuentes'        => [[$fuenteDato, 'disputado']],
    'body_blocks'    => $bloques([
        ['tipo' => 'aviso', 'texto' => 'Verificación de demostración. La cifra, su origen y el resultado son inventados.'],
        ['tipo' => 'parrafo', 'texto' => 'Buscamos la cifra en las fuentes que citan quienes la comparten. No aparece en ninguna.'],
        ['tipo' => 'parrafo', 'texto' => 'Cuando una cifra no tiene documento detrás, lo honesto es decir que no pudo verificarse, no elegir un bando.'],
    ]),
]);

// 4. OPINIÓN
$pieza4 = piezaDemo([
    'slug'           => 'demo-lo-que-no-se-dice-cuando-suben-los-costos',
    'title'          => 'DEMO · Lo que no se dice cuando suben los costos',
    'subtitle'       => 'Opinión firmada. Pieza de demostración.',
    'summary'        => 'Columna de demostración, firmada y separada del hecho reportado.',
    'editorial_type' => 'opinion',
    'category_id'    => $categorias['sociedad-y-familia'] ?? null,
    'author_id'      => $maxId,
    'editor_id'      => $editoraId,
    'max_opinion'    => 'Opinión de demostración: el problema no es solo cuánto sube, es que nadie explica quién decidió que subiera y con qué criterio.',
    'legacy_question' => '¿Quién rinde cuentas por una decisión que afecta a todos?',
    'etiquetas'      => ['opinion'],
    'body_blocks'    => $bloques([
        ['tipo' => 'aviso', 'texto' => 'Esto es opinión firmada, no un hecho reportado. Además, es contenido de demostración.'],
        ['tipo' => 'parrafo', 'texto' => 'Texto de demostración escrito para probar que la opinión se ve, se lee y se distingue del reporte sin ningún esfuerzo.'],
    ]),
]);

// 5. PIEZA QUE SE ARCHIVARÁ, para probar que sigue en el buscador.
$pieza5 = piezaDemo([
    'slug'           => 'demo-cobertura-de-una-jornada-que-ya-termino',
    'title'          => 'DEMO · Cobertura de una jornada que ya terminó',
    'subtitle'       => 'Pieza de demostración que se archivará.',
    'summary'        => 'Esta pieza existe para demostrar una regla: archivar no es eliminar. '
        . 'Sale de portada y sigue en su dirección, en el buscador y en el archivo.',
    'editorial_type' => 'noticia',
    'category_id'    => $categorias['venezuela'] ?? null,
    'author_id'      => $editoraId,
    'editor_id'      => $maxId,
    'legacy_question' => '¿Qué queda de una cobertura cuando pasa la noticia?',
    'etiquetas'      => ['archivo'],
    'temas'          => ['Memoria editorial'],
    'body_blocks'    => $bloques([
        ['tipo' => 'aviso', 'texto' => 'Pieza de demostración creada para probar el archivo permanente.'],
        ['tipo' => 'parrafo', 'texto' => 'La palabra clave para probar el buscador en esta pieza es: perlacontinental. Búscala y verás que esta pieza aparece incluso archivada.'],
    ]),
]);

echo "Piezas creadas.\n";

// ---------------------------------------------------------------------
// PUBLICACIÓN, ACTUALIZACIÓN Y ARCHIVO
// ---------------------------------------------------------------------

$publicaciones = [
    $pieza1 => $ago(2, 3),
    $pieza2 => $ago(9),
    $pieza3 => $ago(5, 6),
    $pieza4 => $ago(1, 4),
    $pieza5 => $ago(45),
];

foreach ($publicaciones as $id => $cuando) {
    Database::update('articles', ['published_at' => $cuando], 'id = :id', ['id' => $id]);
    ArticleService::publish($id);
}

// La pieza 1 se actualiza y deja historial de correcciones visible.
ArticleService::update($pieza1, [
    'facts_probable' => 'El efecto sería mayor en comercios con refrigeración continua. '
        . 'El cálculo de los gremios inventados no ha sido verificado de forma independiente. '
        . 'Añadido en la actualización: el cálculo original de esta demostración usaba un tramo equivocado.',
], 'Actualización de demostración');

ArticleService::addCorrection(
    $pieza1,
    'correccion',
    'Corregimos el número de tramos: la versión inicial decía dos, y el esquema de esta demostración tiene tres.',
    'Se corrigió el bloque de datos destacados y el resumen de 60 segundos. El texto original decía "2 tramos".'
);

ArticleService::addCorrection(
    $pieza1,
    'actualizacion',
    'Añadimos el anuncio de la revisión.',
    'Se sumó un párrafo al final explicando que la revisión anunciada todavía no tiene fecha.'
);

// La pieza 5 se archiva: sale de portada, permanece en el archivo.
ArticleService::archive($pieza5, 'La jornada terminó. Se archiva sin eliminarla, según la política editorial.');

echo "Publicación, actualización y archivo listos.\n";

// ---------------------------------------------------------------------
// VIDEO Y TRANSCRIPCIÓN
// ---------------------------------------------------------------------

function videoDemo(array $datos, ?string $transcripcion = null, array $capitulos = []): int
{
    $id = Database::value('SELECT id FROM videos WHERE slug = :s', ['s' => $datos['slug']]);
    if ($id !== null) {
        return (int) $id;
    }

    $id = Database::insert('videos', array_merge([
        'uuid'    => Str::uuid(),
        'is_demo' => 1,
    ], $datos));

    if ($transcripcion !== null) {
        Database::insert('video_transcripts', [
            'video_id' => $id,
            'language' => 'es',
            'content'  => $transcripcion,
        ]);
    }

    foreach ($capitulos as $posicion => [$segundos, $titulo]) {
        Database::insert('video_chapters', [
            'video_id'  => $id,
            'starts_at' => $segundos,
            'title'     => $titulo,
            'position'  => $posicion,
        ]);
    }

    return $id;
}

$videoResumen = videoDemo([
    'title'             => 'DEMO · La tarifa en 90 segundos',
    'slug'              => 'demo-la-tarifa-en-90-segundos',
    'description'       => 'Resumen en video de demostración. No contiene material real.',
    'video_type'        => 'resumen',
    'provider'          => 'propio',
    'duration_seconds'  => 88,
    'orientation'       => 'horizontal',
    'aspect_ratio'      => '16:9',
    'transcript_status' => 'revisada',
    'text_alternative'  => 'Alternativa textual de demostración: el video explica que el cálculo del recibo pasa de un tramo '
        . 'único a tres escalones, que el tramo base sigue siendo el más barato, y que todavía no se ha publicado '
        . 'la lista de exenciones. Todo el contenido es ficticio.',
    'status'            => 'publicado',
    'published_at'      => $ago(2, 2),
    'autoplay_allowed'  => 0,
],
'Transcripción de demostración. Lo primero que hay que entender es que el cálculo cambió de forma: '
. 'antes había un tramo único y ahora hay tres escalones. El tramo base sigue siendo el más barato. '
. 'El punto que nadie está explicando es quién queda exento, porque esa lista todavía no se ha publicado. '
. 'La palabra de prueba para el buscador en esta transcripción es perlacontinental. '
. 'Mientras la revisión no tenga fecha, cualquier cálculo del efecto real es una estimación.',
[[0, 'Qué cambió'], [22, 'Los tres escalones'], [51, 'Quién queda exento'], [70, 'Lo que sigue abierto']]
);

$videoLive = videoDemo([
    'title'             => 'DEMO · Grabación del Live sobre el costo de la electricidad',
    'slug'              => 'demo-grabacion-live-costo-electricidad',
    'description'       => 'Grabación completa de demostración.',
    'video_type'        => 'live',
    'provider'          => 'propio',
    'duration_seconds'  => 3720,
    'orientation'       => 'horizontal',
    'aspect_ratio'      => '16:9',
    'transcript_status' => 'revisada',
    'text_alternative'  => 'Alternativa textual de demostración: el programa recorrió las preguntas enviadas por la audiencia '
        . 'sobre el cálculo del recibo, y cerró con dos asuntos pendientes. Contenido ficticio.',
    'status'            => 'publicado',
    'published_at'      => $ago(6),
    'autoplay_allowed'  => 0,
],
'Transcripción de demostración del programa. Empezamos con la pregunta que más se repitió: por qué el recibo '
. 'no coincide con lo que la gente cree que consume. La respuesta corta es que el cálculo cambió de forma. '
. 'Después revisamos qué pasa con los comercios pequeños, que fue la segunda pregunta más enviada. '
. 'Cerramos con dos asuntos que quedaron abiertos y que vamos a seguir. Todo este contenido es ficticio.',
[[0, 'Apertura'], [420, 'Las preguntas más enviadas'], [1580, 'Comercios pequeños'], [3210, 'Lo que queda pendiente']]
);

ArticleService::attachVideo($pieza1, $videoResumen, 'resumen', 0);
ArticleService::attachVideo($pieza2, $videoResumen, 'contexto', 0);

echo "Videos y transcripciones listos.\n";

// ---------------------------------------------------------------------
// LIVES
// ---------------------------------------------------------------------

function liveDemo(array $datos): int
{
    $id = Database::value('SELECT id FROM lives WHERE slug = :s', ['s' => $datos['slug']]);
    if ($id !== null) {
        return (int) $id;
    }
    return Database::insert('lives', array_merge(['uuid' => Str::uuid(), 'is_demo' => 1], $datos));
}

$liveProgramado = liveDemo([
    'slug'          => 'demo-live-quien-paga-la-electricidad',
    'title'         => 'DEMO · Quién paga la electricidad',
    'lead_question' => '¿Quién termina pagando cuando cambia la tarifa?',
    'summary'       => 'Programa de demostración, programado para probar la agenda y los recordatorios.',
    'status'        => 'programado',
    'starts_at'     => $en(6, 2),
    'ends_at'       => $en(6, 4),
    'timezone'      => 'America/Caracas',
    'guests'        => 'Invitados de demostración. Ninguna persona real está confirmada.',
]);

$liveFinalizado = liveDemo([
    'slug'               => 'demo-live-el-recibo-por-dentro',
    'title'              => 'DEMO · El recibo por dentro',
    'lead_question'      => '¿Por qué el recibo no coincide con lo que crees que consumes?',
    'summary'            => 'Programa de demostración ya finalizado, con grabación, capítulos y transcripción.',
    'status'             => 'finalizado',
    'starts_at'          => $ago(6, 2),
    'ends_at'            => $ago(6),
    'timezone'           => 'America/Caracas',
    'recording_video_id' => $videoLive,
    'aftermath'          => 'Resumen de demostración: se respondieron las cinco preguntas más enviadas sobre el cálculo del recibo '
        . 'y sobre el efecto en comercios pequeños.',
    'pending_matters'    => 'Quedó pendiente publicar la lista de exenciones y confirmar la fecha de la revisión anunciada.',
]);

Database::run('INSERT IGNORE INTO live_articles (live_id, article_id, moment, position) VALUES (:l, :a, "antes", 0)',
    ['l' => $liveFinalizado, 'a' => $pieza2]);
Database::run('INSERT IGNORE INTO live_articles (live_id, article_id, moment, position) VALUES (:l, :a, "despues", 0)',
    ['l' => $liveFinalizado, 'a' => $pieza1]);
Database::run('INSERT IGNORE INTO live_articles (live_id, article_id, moment, position) VALUES (:l, :a, "antes", 0)',
    ['l' => $liveProgramado, 'a' => $pieza1]);

echo "Lives listos.\n";

// ---------------------------------------------------------------------
// PULSOS
// ---------------------------------------------------------------------

function pulsoDemo(int $articleId, string $scope, string $pregunta, array $opciones): int
{
    $id = Database::value('SELECT id FROM polls WHERE question = :q', ['q' => $pregunta]);
    if ($id !== null) {
        return (int) $id;
    }

    $pollId = Database::insert('polls', [
        'uuid'             => Str::uuid(),
        'article_id'       => $articleId ?: null,
        'scope'            => $scope,
        'question'         => $pregunta,
        'methodology_note' => 'Pulso de demostración. Voto anónimo y no representativo. Los resultados no se editan nunca.',
        'is_open'          => 1,
        'is_demo'          => 1,
    ]);

    foreach ($opciones as $posicion => $etiqueta) {
        Database::insert('poll_options', ['poll_id' => $pollId, 'label' => $etiqueta, 'position' => $posicion]);
    }

    return $pollId;
}

$pulsoPieza = pulsoDemo($pieza1, 'articulo',
    '¿Crees que el nuevo cálculo afectará más a los hogares o a los comercios?',
    ['A los hogares', 'A los comercios', 'A los dos por igual', 'No lo sé']);

$pulsoDia = pulsoDemo(0, 'dia',
    '¿Sientes que entiendes cómo se calcula tu recibo de electricidad?',
    ['Sí, lo entiendo', 'Más o menos', 'No lo entiendo']);
Database::update('polls', ['article_id' => $pieza1], 'id = :id', ['id' => $pulsoDia]);

echo "Pulsos listos. (Sin votos: los resultados solo aparecen cuando hay respuestas reales.)\n";

// ---------------------------------------------------------------------
// PREGUNTAS DE LA COMUNIDAD
// ---------------------------------------------------------------------

foreach ([
    [$pieza1, null, '¿Los comercios pequeños tienen alguna exención?', 'Lectora de demostración', 'seleccionada', null],
    [$pieza1, null, '¿Desde cuándo se aplica el nuevo cálculo en el recibo?', null, 'respondida', 'Respuesta de demostración: se aplica desde el ciclo de facturación siguiente.'],
    [$pieza2, null, '¿Qué hago si la lectura del medidor no coincide?', 'Lector de demostración', 'aprobada', null],
    [null, $liveFinalizado, '¿Van a publicar la lista de exenciones?', null, 'seleccionada', null],
] as [$articuloId, $liveId, $cuerpo, $nombre, $estado, $respuesta]) {
    if (Database::value('SELECT id FROM community_questions WHERE body = :b', ['b' => $cuerpo]) !== null) {
        continue;
    }
    Database::insert('community_questions', [
        'article_id'     => $articuloId,
        'live_id'        => $liveId,
        'body'           => $cuerpo,
        'author_name'    => $nombre,
        'status'         => $estado,
        'answer'         => $respuesta,
        'submitter_hash' => hash('sha256', 'demo|' . $cuerpo),
        'is_demo'        => 1,
        'moderated_at'   => Dates::nowUtc(),
    ]);
}

// La pieza 3 nació de una pregunta de la comunidad: eso es la pregunta heredada.
$preguntaOrigen = Database::value('SELECT id FROM community_questions WHERE body LIKE "%exención%" LIMIT 1');
if ($preguntaOrigen !== null && Database::value('SELECT id FROM article_lineage WHERE article_id = :a', ['a' => $pieza3]) === null) {
    Database::insert('article_lineage', [
        'article_id'        => $pieza3,
        'parent_article_id' => $pieza1,
        'question_id'       => (int) $preguntaOrigen,
        'note'              => 'Demostración de la pregunta heredada.',
    ]);
}

echo "Preguntas de la comunidad listas.\n";

// ---------------------------------------------------------------------
// PORTADA
// La portada SELECCIONA. Vaciar una zona no borra ninguna pieza.
// ---------------------------------------------------------------------

Database::run('DELETE FROM homepage_slots');

$slots = [
    ['hero',         0, 'article_id', $pieza1],
    ['esencial',     0, 'article_id', $pieza1],
    ['esencial',     1, 'article_id', $pieza3],
    ['esencial',     2, 'article_id', $pieza2],
    ['max_analiza',  0, 'article_id', $pieza4],
    ['max_analiza',  0, 'video_id',   $videoResumen],
    ['proximo_live', 0, 'live_id',    $liveProgramado],
    ['ultimos_videos', 0, 'video_id', $videoResumen],
    ['ultimos_videos', 1, 'video_id', $videoLive],
    ['expedientes',  0, 'dossier_id', $expedienteId],
    ['pulso_dia',    0, 'poll_id',    $pulsoDia],
];

foreach ($slots as [$zona, $posicion, $columna, $valor]) {
    Database::insert('homepage_slots', [
        'zone'      => $zona,
        'position'  => $posicion,
        $columna    => $valor,
        'is_active' => 1,
    ]);
}

echo "Portada curada.\n";

// ---------------------------------------------------------------------
// ÍNDICE DEL BUSCADOR
// ---------------------------------------------------------------------

$indexadas = SearchService::reindexAll();
Database::run('INSERT INTO settings (name, value) VALUES ("sembrado_en", :v)
               ON DUPLICATE KEY UPDATE value = VALUES(value)', ['v' => Dates::nowUtc()]);

echo "Índice del buscador: $indexadas piezas.\n";
echo "\nDemostración lista. Todo el contenido está marcado como demostración y se rotula en pantalla.\n";
