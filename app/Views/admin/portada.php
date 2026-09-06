<div class="panel__cabeza"><h1>Portada</h1></div>

<p class="aviso">
  <strong>La portada selecciona y ordena. No almacena.</strong>
  Quitar una pieza de aquí no la elimina ni la despublica: sigue en su dirección permanente,
  en el buscador, en el archivo y en sus categorías.
</p>

<?php foreach ($etiquetasZona as $zona => $etiqueta): ?>
  <div class="zona-portada">
    <div class="zona-portada__cabeza"><?= e($etiqueta) ?></div>
    <div class="zona-portada__cuerpo">
      <?php if (empty($zonas[$zona])): ?>
        <p style="margin:0;color:var(--tinta-tenue);font-size:var(--t-sm)">
          Sin selección. La portada mostrará automáticamente lo más reciente que corresponda.
        </p>
      <?php else: ?>
        <ul style="margin:0;padding-left:1.2rem">
          <?php foreach ($zonas[$zona] as $slot): ?>
            <li style="display:flex;gap:var(--e2);align-items:center;margin-bottom:var(--e1)">
              <span>
                <?= e($slot['override_title'] ?: ($slot['article_title'] ?? $slot['video_title'] ?? $slot['dossier_title'] ?? $slot['live_title'] ?? $slot['poll_question'] ?? 'Sin contenido')) ?>
                <?php if (!empty($slot['article_status'])): ?>
                  <small style="color:var(--tinta-tenue)">(<?= e($slot['article_status']) ?>)</small>
                <?php endif; ?>
              </span>
              <form method="post" action="/panel/portada">
                <?= \App\Support\Csrf::field() ?>
                <input type="hidden" name="accion" value="quitar">
                <input type="hidden" name="zona" value="<?= atributo($zona) ?>">
                <input type="hidden" name="slot" value="<?= (int) $slot['id'] ?>">
                <button class="mini-boton" type="submit">Quitar de portada</button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <details>
        <summary style="cursor:pointer;font-size:var(--t-sm)">Añadir a esta zona</summary>
        <form class="formulario" method="post" action="/panel/portada" style="margin-top:var(--e2)">
          <?= \App\Support\Csrf::field() ?>
          <input type="hidden" name="accion" value="agregar">
          <input type="hidden" name="zona" value="<?= atributo($zona) ?>">

          <div class="panel__filtros">
            <div class="campo">
              <label for="articulo-<?= atributo($zona) ?>">Noticia</label>
              <select id="articulo-<?= atributo($zona) ?>" name="articulo">
                <option value="">—</option>
                <?php foreach ($articulos as $art): ?>
                  <option value="<?= (int) $art['id'] ?>"><?= e($art['title']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="campo">
              <label for="video-<?= atributo($zona) ?>">Video</label>
              <select id="video-<?= atributo($zona) ?>" name="video">
                <option value="">—</option>
                <?php foreach ($videos as $v): ?>
                  <option value="<?= (int) $v['id'] ?>"><?= e($v['title']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="campo">
              <label for="expediente-<?= atributo($zona) ?>">Expediente</label>
              <select id="expediente-<?= atributo($zona) ?>" name="expediente">
                <option value="">—</option>
                <?php foreach ($expedientes as $exp): ?>
                  <option value="<?= (int) $exp['id'] ?>"><?= e($exp['title']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="campo" style="max-width:6rem">
              <label for="posicion-<?= atributo($zona) ?>">Orden</label>
              <input type="number" id="posicion-<?= atributo($zona) ?>" name="posicion" value="0" min="0" max="99">
            </div>
            <button class="boton boton--pequeno" type="submit">Añadir</button>
          </div>
        </form>
      </details>
    </div>
  </div>
<?php endforeach; ?>
