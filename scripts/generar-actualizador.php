<?php
/**
 * Genera el actualizador de un solo archivo.
 *
 * Mete los archivos que hay que instalar dentro de un PHP que se sube solo
 * y los coloca en su sitio. Asi una actualizacion deja de ser siete subidas
 * en cinco carpetas y pasa a ser: subir uno, abrirlo, listo.
 */
declare(strict_types=1);

$raiz = '/home/user/la-noticia-sin-filtro';

// destino => origen. El destino es relativo a la carpeta privada, salvo
// los que empiezan por publico/, que van a la carpeta que ve internet.
$archivos = [
    'app/Controllers/Admin/QuickController.php' => 'app/Controllers/Admin/QuickController.php',
    'app/Views/admin/rapido.php'                => 'app/Views/admin/rapido.php',
    'app/Views/layouts/admin.php'               => 'app/Views/layouts/admin.php',
    'app/Views/admin/tablero.php'               => 'app/Views/admin/tablero.php',
    'app/Views/admin/noticias.php'              => 'app/Views/admin/noticias.php',
    'app/Views/home.php'                        => 'app/Views/home.php',
    'app/Views/partials/asistente.php'          => 'app/Views/partials/asistente.php',
    'app/Controllers/Admin/ArticleController.php'=> 'app/Controllers/Admin/ArticleController.php',
    'app/Controllers/ApiController.php'         => 'app/Controllers/ApiController.php',
    'app/Services/AssistantService.php'         => 'app/Services/AssistantService.php',
    'app/Services/SeoService.php'               => 'app/Services/SeoService.php',
    'app/Models/Dossier.php'                    => 'app/Models/Dossier.php',
    'app/Models/Live.php'                       => 'app/Models/Live.php',
    'app/Views/partials/tarjeta.php'            => 'app/Views/partials/tarjeta.php',
    'app/Support/Portada.php'                   => 'app/Support/Portada.php',
    'app/Middleware/RateLimit.php'              => 'app/Middleware/RateLimit.php',
    'scripts/reindexar.php'                     => 'scripts/reindexar.php',
    'app/bootstrap.php'                         => 'app/bootstrap.php',
    'config/config.php'                         => 'config/config.php',
    'publico/index.php'                         => 'public/index.php',
    'publico/instalar.php'                      => 'public/instalar.php',
    'publico/assets/css/componentes.css'        => 'public/assets/css/componentes.css',
    'publico/assets/css/panel.css'              => 'public/assets/css/panel.css',
    'publico/assets/js/sitio.js'                => 'public/assets/js/sitio.js',
];

$carga = [];
foreach ($archivos as $destino => $origen) {
    $ruta = $raiz . '/' . $origen;
    if (!is_file($ruta)) {
        fwrite(STDERR, "FALTA: $origen\n");
        exit(1);
    }
    $carga[$destino] = base64_encode((string) file_get_contents($ruta));
}

$payload = var_export($carga, true);
$plantilla = (string) file_get_contents('/tmp/plantilla-actualizador.php');
file_put_contents('/tmp/envio/actualizar.php', str_replace('/*__CARGA__*/[]', $payload, $plantilla));

echo count($carga) . " archivos empaquetados\n";
echo round(filesize('/tmp/envio/actualizar.php') / 1024) . " KB\n";
