<div class="contenedor seccion">
  <?= \App\Support\View::partial('partials/transparencia-nav', ['paginas' => $paginas, 'paginaActual' => $paginaActual]) ?>
  <div class="lectura" style="margin-top:var(--e5)">
    <h1><?= e($encabezado) ?></h1>
    <p style="font-size:var(--t-lg)">Recogemos lo mínimo indispensable y lo decimos claro.</p>

    <h2>Qué guardamos</h2>
    <ul>
      <li><strong>El Pulso.</strong> Guardamos tu voto junto a una huella anónima, que es un resumen
        criptográfico irreversible. No guardamos tu dirección IP en claro y no podemos reconstruirla.
        Sirve solo para evitar que una misma persona vote varias veces.</li>
      <li><strong>Preguntas de la comunidad.</strong> Guardamos el texto que envías. Tu nombre y tu correo
        son opcionales, y el correo solo se guarda si marcas la casilla de consentimiento.</li>
      <li><strong>Avisos de Lives.</strong> Solo si nos das tu correo con consentimiento explícito.
        Puedes darte de baja cuando quieras.</li>
      <li><strong>Analítica propia.</strong> Registramos eventos anónimos, como si una pieza se leyó hasta
        la mitad o hasta el final, asociados a un identificador de sesión temporal. Sin nombres, sin perfiles
        publicitarios y sin terceros.</li>
    </ul>

    <h2>Qué no hacemos</h2>
    <ul>
      <li>No usamos rastreadores publicitarios de terceros.</li>
      <li>No vendemos ni cedemos datos personales.</li>
      <li>No hace falta registrarse para leer: todo el contenido principal está disponible sin cuenta.</li>
    </ul>

    <h2>Cookies</h2>
    <p>Usamos una cookie de sesión técnica, necesaria para que funcionen el Pulso y los formularios sin
      que alguien pueda enviarlos en tu nombre. No es publicitaria y desaparece al cerrar el navegador.</p>

    <h2>Personalización</h2>
    <p>Cuando te recomendamos una pieza, la web explica por qué la recomienda. Puedes ignorarla sin
      consecuencias: no encerramos a nadie en una burbuja, y siempre incluimos al menos una perspectiva
      distinta, identificada como tal.</p>

    <h2>Tus derechos</h2>
    <p>Puedes pedirnos que borremos tu correo o tu pregunta escribiendo desde
      <a href="/transparencia/contacto">la página de contacto</a>.</p>
  </div>
</div>
