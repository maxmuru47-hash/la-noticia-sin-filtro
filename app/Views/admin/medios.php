<div class="panel__cabeza"><h1>Imágenes</h1></div>

<div class="panel__caja">
  <h2>Subir imagen</h2>
  <p style="color:var(--tinta-tenue);font-size:var(--t-sm)">
    Máximo <?= (int) ($limites['max_image_bytes'] / 1048576) ?> MB. Se acepta JPG, PNG, WebP y AVIF.
    El tipo se comprueba leyendo el archivo, no su nombre. El original se guarda fuera de la carpeta pública.
  </p>
  <form class="formulario" method="post" action="/panel/medios" enctype="multipart/form-data">
    <?= \App\Support\Csrf::field() ?>
    <div class="campo">
      <label for="archivo">Archivo</label>
      <input type="file" id="archivo" name="archivo" accept="image/jpeg,image/png,image/webp,image/avif" required>
    </div>
    <div class="campo">
      <label for="alt">Texto alternativo (obligatorio)</label>
      <input type="text" id="alt" name="alt" maxlength="255" required>
      <span class="campo__ayuda">Describe qué se ve. Es accesibilidad, no un trámite.</span>
    </div>
    <div class="campo">
      <label for="pie">Pie de foto</label>
      <input type="text" id="pie" name="pie" maxlength="500">
    </div>
    <div class="campo">
      <label for="credito">Crédito</label>
      <input type="text" id="credito" name="credito" maxlength="190">
    </div>
    <div class="campo campo--casilla">
      <input type="checkbox" id="sintetica" name="sintetica" value="1">
      <label for="sintetica">Imagen generada con inteligencia artificial (se rotula en la pieza)</label>
    </div>
    <button class="boton boton--rojo" type="submit">Subir</button>
  </form>
</div>

<div class="panel__caja">
  <h2>Biblioteca</h2>
  <?php if ($medios === []): ?>
    <p>Todavía no hay imágenes.</p>
  <?php else: ?>
    <div class="galeria">
      <?php foreach ($medios as $m): ?>
        <figure>
          <img src="<?= atributo($m['storage_path']) ?>" alt="<?= atributo($m['alt_text'] ?? '') ?>" loading="lazy">
          <figcaption>
            #<?= (int) $m['id'] ?> · <?= e($m['original_name']) ?><br>
            <?= (int) $m['width'] ?>×<?= (int) $m['height'] ?> · <?= e(number_format($m['bytes'] / 1024, 0)) ?> KB
            <?php if (!empty($m['is_synthetic'])): ?><br><strong>Generada con IA</strong><?php endif; ?>
          </figcaption>
        </figure>
      <?php endforeach; ?>
    </div>
    <?= \App\Support\View::partial('partials/paginacion', ['paginador' => $paginador]) ?>
  <?php endif; ?>
</div>
