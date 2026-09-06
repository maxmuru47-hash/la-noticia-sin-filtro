<div class="panel__cabeza">
  <h1>Métricas</h1>
  <form method="get" action="/panel/metricas" class="panel__filtros" style="margin:0">
    <div class="campo">
      <label for="dias">Últimos</label>
      <select id="dias" name="dias" onchange="this.form.submit()">
        <?php foreach ([7, 30, 90, 365] as $opcion): ?>
          <option value="<?= $opcion ?>" <?= $dias === $opcion ? 'selected' : '' ?>><?= $opcion ?> días</option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>
</div>

<p class="aviso">
  Analítica propia, sin terceros y sin datos personales. Estas cifras son una
  <strong>señal de producto</strong>, no una medición científica.
  <?php if (!$resumen['suficiente']): ?>
    Con <?= (int) $resumen['sesiones'] ?> sesiones todavía hay muy pocos datos para sacar conclusiones.
  <?php endif; ?>
</p>

<div class="panel__metricas">
  <div class="metrica"><p class="metrica__valor"><?= (int) $resumen['sesiones'] ?></p><p class="metrica__etiqueta">Sesiones con actividad</p></div>
  <div class="metrica"><p class="metrica__valor"><?= e(number_format($resumen['lectura_50_pct'], 1)) ?>%</p><p class="metrica__etiqueta">Leyó al 50%</p></div>
  <div class="metrica"><p class="metrica__valor"><?= e(number_format($resumen['lectura_90_pct'], 1)) ?>%</p><p class="metrica__etiqueta">Leyó al 90%</p></div>
  <div class="metrica metrica--alerta"><p class="metrica__valor"><?= e(number_format($resumen['sesion_informada_pct'], 1)) ?>%</p><p class="metrica__etiqueta">Sesión informada completa</p></div>
</div>

<div class="panel__caja">
  <h2>Métrica norte: sesión informada completa</h2>
  <p>Porcentaje de visitas que consultaron al menos una capa de contexto, compararon las dos perspectivas
     y cerraron con el pulso informado o una pregunta. Es lo contrario de optimizar para clics o permanencia pasiva.</p>
  <p><strong><?= (int) $resumen['sesion_informada'] ?></strong> de <?= (int) $resumen['sesiones'] ?> sesiones en los últimos <?= (int) $dias ?> días.</p>
</div>

<div class="panel__caja">
  <h2>Eventos registrados</h2>
  <div class="tabla-envoltura">
    <table class="tabla">
      <thead><tr><th>Evento</th><th>Veces</th><th>Sesiones distintas</th></tr></thead>
      <tbody>
      <?php foreach ($resumen['eventos'] as $evento => $datos): ?>
        <tr><td><code><?= e($evento) ?></code></td>
            <td class="numero"><?= (int) $datos['total'] ?></td>
            <td class="numero"><?= (int) $datos['sesiones'] ?></td></tr>
      <?php endforeach; ?>
      <?php if ($resumen['eventos'] === []): ?><tr><td colspan="3">Todavía no hay eventos registrados.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel__caja">
  <h2>Pulsos</h2>
  <div class="tabla-envoltura">
    <table class="tabla">
      <thead><tr><th>Pregunta</th><th>Pieza</th><th>Pulso inicial</th><th>Pulso informado</th></tr></thead>
      <tbody>
      <?php foreach ($pulsos as $p): ?>
        <tr>
          <td><?= e($p['question']) ?></td>
          <td><?php if (!empty($p['slug'])): ?><a href="/noticia/<?= atributo($p['slug']) ?>" target="_blank" rel="noopener"><?= e($p['article_title']) ?></a><?php else: ?>—<?php endif; ?></td>
          <td class="numero"><?= (int) $p['inicial'] ?></td>
          <td class="numero"><?= (int) $p['informado'] ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($pulsos === []): ?><tr><td colspan="4">Todavía no hay pulsos.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($top !== []): ?>
<div class="panel__caja">
  <h2>Piezas más consultadas</h2>
  <div class="tabla-envoltura">
    <table class="tabla">
      <thead><tr><th>Pieza</th><th>Sesiones</th><th>Lecturas completas</th><th>Vistas totales</th></tr></thead>
      <tbody>
      <?php foreach ($top as $t): ?>
        <tr>
          <td><a href="/noticia/<?= atributo($t['slug']) ?>" target="_blank" rel="noopener"><?= e($t['title']) ?></a></td>
          <td class="numero"><?= (int) $t['sesiones'] ?></td>
          <td class="numero"><?= (int) $t['lecturas_completas'] ?></td>
          <td class="numero"><?= (int) $t['view_count'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
