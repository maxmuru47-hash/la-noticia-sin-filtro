<div class="panel__cabeza">
  <h1>Cargar Lives</h1>
  <a class="boton" href="/panel/lives">Volver a la lista</a>
</div>

<p class="aviso">Pega aquí todos los programas que ya hiciste, <strong>uno por línea</strong>.
Primero te muestro lo que entendí y tú decides si se guarda. Un título que ya exista <strong>no se duplica</strong>:
puedes volver a pegar la misma lista sin miedo.</p>

<div class="panel__caja">
  <form method="post" action="/panel/lives/cargar">
    <?= \App\Support\Csrf::field() ?>

    <label class="campo">
      <span class="campo__etiqueta">Un Live por línea</span>
      <span class="campo__ayuda">Formato: <code>Título | fecha | enlace</code>. La fecha y el enlace pueden faltar.
        La fecha vale como <code>12/08/2026</code> o <code>2026-08-12</code>, y si le pones hora (<code>20:30</code>) se usa esa;
        si no, se asume las 8:00 de la noche, hora de Venezuela.</span>
      <textarea name="lista" rows="12" class="campo__control" placeholder="El dólar que nadie controla | 12/08/2026 20:30 | https://youtube.com/watch?v=...
Emprender con el país en pausa | 05/08/2026
Inteligencia artificial sin humo | 29/07/2026 | https://youtube.com/watch?v=..."><?= e($lista) ?></textarea>
    </label>

    <?php if ($filas === []): ?>
      <button class="boton boton--rojo" type="submit" name="accion" value="revisar">Revisar</button>
    <?php else: ?>
      <button class="boton" type="submit" name="accion" value="revisar">Volver a revisar</button>
    <?php endif; ?>
  </form>
</div>

<?php if ($filas !== []): ?>
  <?php
    $listos    = array_filter($filas, static fn (array $f): bool => $f['error'] === null && !$f['repetido']);
    $problemas = array_filter($filas, static fn (array $f): bool => $f['error'] !== null);
    $repetidos = array_filter($filas, static fn (array $f): bool => $f['error'] === null && $f['repetido']);
  ?>
  <div class="panel__caja">
    <h2>Esto es lo que entendí</h2>
    <div class="tabla-envoltura">
      <table class="tabla">
        <thead><tr><th>Título</th><th>Fecha y hora</th><th>Estado</th><th>Enlace</th><th>Qué pasará</th></tr></thead>
        <tbody>
        <?php foreach ($filas as $f): ?>
          <tr>
            <td><?= e($f['titulo'] !== '' ? $f['titulo'] : $f['cruda']) ?></td>
            <td class="numero"><?= e($f['empieza'] ? fechaHora($f['empieza']) : 'sin fecha') ?></td>
            <td><?= e(\App\Models\Live::STATUSES[$f['estado']] ?? '') ?></td>
            <td><?= $f['enlace'] ? 'sí' : '—' ?></td>
            <td>
              <?php if ($f['error'] !== null): ?>
                <strong>No se carga.</strong> <?= e($f['error']) ?>
              <?php elseif ($f['repetido']): ?>
                <strong>No se carga.</strong> Ya existe un Live con ese título.
              <?php else: ?>
                Se crea.
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <p class="aviso">
      Se van a crear <strong><?= count($listos) ?></strong>.
      <?php if ($repetidos !== []): ?><?= count($repetidos) ?> ya existían y se saltan. <?php endif; ?>
      <?php if ($problemas !== []): ?><?= count($problemas) ?> tienen un problema y no se guardan; corrige esas líneas arriba y vuelve a revisar.<?php endif; ?>
    </p>

    <?php if ($listos !== []): ?>
      <form method="post" action="/panel/lives/cargar">
        <?= \App\Support\Csrf::field() ?>
        <input type="hidden" name="lista" value="<?= atributo($lista) ?>">
        <button class="boton boton--rojo" type="submit" name="accion" value="confirmar">
          Sí, crear <?= count($listos) ?> Lives
        </button>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>
