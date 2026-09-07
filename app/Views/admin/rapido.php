<div class="panel__cabeza">
  <h1>Publicación rápida</h1>
</div>

<div class="panel__caja">
  <p style="color:var(--tinta-tenue);font-size:var(--t-sm);max-width:62ch">
    Cuatro campos y ya. Todo lo demás —perspectivas, expediente, fuentes, redes,
    SEO— se puede añadir después abriendo la pieza en el editor completo. Lo que
    escribas aquí no es una pieza de segunda: es la misma pieza empezada por lo
    que hace falta para publicar.
  </p>

  <form class="formulario" method="post" action="/panel/rapido">
    <?= \App\Support\Csrf::field() ?>

    <div class="campo">
      <label for="titulo">Titular</label>
      <input type="text" id="titulo" name="titulo" maxlength="255" required
             autofocus autocomplete="off"
             value="<?= atributo((string) ($borrador['titulo'] ?? '')) ?>">
      <span class="campo__ayuda">
        De aquí sale la dirección permanente de la pieza. Se puede cambiar después,
        pero la dirección vieja se queda reservada para siempre.
      </span>
    </div>

    <div class="campo">
      <label for="resumen">Entradilla <span style="font-weight:400;color:var(--tinta-tenue)">(opcional)</span></label>
      <textarea id="resumen" name="resumen" rows="2" maxlength="500"><?= e((string) ($borrador['resumen'] ?? '')) ?></textarea>
      <span class="campo__ayuda">Una o dos frases. Es lo que se lee en la portada y lo que se comparte.</span>
    </div>

    <div class="campo">
      <label for="cuerpo">Cuerpo</label>
      <textarea id="cuerpo" name="cuerpo" rows="16" required
                style="font-family:var(--cuerpo);line-height:1.7"><?= e((string) ($borrador['cuerpo'] ?? '')) ?></textarea>
      <span class="campo__ayuda">
        Escribe normal. Deja una línea en blanco entre párrafos. Si una línea va
        sola, es corta y no acaba en punto, se guarda como intertítulo.
      </span>
    </div>

    <div class="campo">
      <label for="categoria">Sección</label>
      <select id="categoria" name="categoria">
        <option value="">Sin sección</option>
        <?php foreach ($categorias as $categoria): ?>
          <option value="<?= (int) $categoria['id'] ?>"
            <?= (int) ($borrador['categoria'] ?? 0) === (int) $categoria['id'] ? 'selected' : '' ?>>
            <?= e((string) $categoria['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center">
      <?php if ($puedePublicar): ?>
        <button class="boton boton--rojo" type="submit" name="accion" value="publicar">
          Publicar ahora
        </button>
      <?php endif; ?>
      <button class="boton boton--linea" type="submit" name="accion" value="borrador">
        Guardar como borrador
      </button>
    </div>

    <p style="color:var(--tinta-tenue);font-size:var(--t-sm);margin-top:1rem;max-width:62ch">
      <?php if ($puedePublicar): ?>
        Publicar la deja visible de inmediato en su dirección definitiva. Después
        podrás corregirla, ampliarla o archivarla, pero la dirección ya no cambia sola.
      <?php else: ?>
        Tu cuenta puede escribir pero no publicar. Se guardará como borrador para
        que alguien con permiso la revise.
      <?php endif; ?>
    </p>
  </form>
</div>

<div class="panel__caja">
  <h2>¿Necesitas más?</h2>
  <p style="color:var(--tinta-tenue);font-size:var(--t-sm);max-width:62ch">
    Para una pieza con imagen principal, perspectivas enfrentadas, expediente,
    cronología o Pulso, empieza aquí igual y ábrela luego en
    <a href="/panel/noticias">Noticias</a>. Añadir es fácil; lo difícil es empezar.
  </p>
</div>
