<?php
/**
 * Movimiento de mercancía entre tiendas.
 *
 * La pantalla de transferencias sirve para operarlas: crear, aprobar, recibir.
 * Este informe sirve para responder preguntas de después: qué salió de dónde,
 * cuánto, por qué, quién lo pidió y quién lo autorizó.
 *
 * Desde que la salida necesita aprobación, esto es además el rastro de esas
 * autorizaciones. Por eso el motivo y el nombre de quien aprobó son columnas y
 * no un detalle escondido: son la razón de ser del informe.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_any_perm(['reportes.operacion', 'transferencias.ver']);

$p = rep_periodo('mes');
[$scope, $scopeP] = rep_scope('t.sucursal_origen_id');
$estado = in_array(get('estado'), ['borrador', 'pendiente', 'enviada', 'recibida', 'rechazada', 'anulada'], true)
    ? get('estado') : '';

$cond = ["t.fecha BETWEEN ? AND ?", $scope];
$par  = array_merge([substr($p['ini'], 0, 10), substr($p['fin'], 0, 10)], $scopeP);
if ($estado !== '') { $cond[] = 't.estado = ?'; $par[] = $estado; }
$where = implode(' AND ', $cond);

/* ---------- Los documentos, con sus unidades y su valor ---------- */
$trf = qAll(
    "SELECT t.id, t.numero, t.fecha, t.estado, t.notas, t.motivo_rechazo,
            t.created_at, t.enviada_at, t.recibida_at,
            so.nombre AS origen, sd.nombre AS destino,
            CONCAT(us.nombre,' ',us.apellido) AS solicita,
            CONCAT(ue.nombre,' ',ue.apellido) AS aprueba,
            CONCAT(ur.nombre,' ',ur.apellido) AS recibe,
            COALESCE(d.unidades, 0) AS unidades,
            COALESCE(d.valor, 0)    AS valor
       FROM transferencias t
       JOIN sucursales so ON so.id = t.sucursal_origen_id
       JOIN sucursales sd ON sd.id = t.sucursal_destino_id
       LEFT JOIN usuarios us ON us.id = t.usuario_id
       LEFT JOIN usuarios ue ON ue.id = t.enviada_por
       LEFT JOIN usuarios ur ON ur.id = t.recibida_por
       LEFT JOIN (SELECT td.transferencia_id,
                         SUM(td.cantidad) AS unidades,
                         SUM(td.cantidad * pr.precio_compra) AS valor
                    FROM transferencia_detalles td
                    JOIN productos pr ON pr.id = td.producto_id
                   GROUP BY td.transferencia_id) d ON d.transferencia_id = t.id
      WHERE $where
      ORDER BY t.fecha DESC, t.id DESC",
    $par
);

/* ---------- Resumen ---------- */
$res = ['n' => count($trf), 'unidades' => 0.0, 'valor' => 0.0,
        'pendientes' => 0, 'en_camino' => 0, 'varadas' => 0, 'recibidas' => 0];
foreach ($trf as $t) {
    $res['unidades'] += (float) $t['unidades'];
    $res['valor']    += (float) $t['valor'];
    if ($t['estado'] === 'pendiente') $res['pendientes']++;
    if ($t['estado'] === 'enviada') {
        $res['en_camino']++;
        if ($t['enviada_at'] && strtotime($t['enviada_at']) < strtotime('-7 days')) $res['varadas']++;
    }
    if ($t['estado'] === 'recibida') $res['recibidas']++;
}

/* ---------- Ruta más transitada: de dónde a dónde se mueve la mercancía ---------- */
$rutas = [];
foreach ($trf as $t) {
    if (!in_array($t['estado'], ['enviada', 'recibida'], true)) continue;
    $k = $t['origen'] . ' → ' . $t['destino'];
    $rutas[$k] ??= ['n' => 0, 'unidades' => 0.0, 'valor' => 0.0];
    $rutas[$k]['n']++;
    $rutas[$k]['unidades'] += (float) $t['unidades'];
    $rutas[$k]['valor']    += (float) $t['valor'];
}
arsort($rutas);
uasort($rutas, fn($a, $b) => $b['valor'] <=> $a['valor']);

/* ---------- Exportación ---------- */
if (export_solicitado()) {
    $filas = [];
    foreach ($trf as $t) {
        $filas[] = [$t['numero'], fechaCorta($t['fecha']), $t['origen'], $t['destino'],
            ucfirst($t['estado']), qty($t['unidades']), money($t['valor'], false),
            (string) $t['notas'], (string) $t['solicita'], (string) $t['aprueba'], (string) $t['recibe']];
    }
    export_tabla('movimiento_entre_tiendas_' . $p['desde'] . '_' . $p['hasta'],
        ['Documento', 'Fecha', 'Sale de', 'Va a', 'Estado', 'Unidades', 'Valor a costo',
         'Motivo', 'Solicitó', 'Autorizó', 'Recibió'],
        $filas, 'Movimiento de mercancía entre tiendas');
}

/* ---------- Pantalla ---------- */
layout_start('Movimiento entre tiendas', rep_subtitulo($p), rep_barra_titulo());
echo rep_abrir('Movimiento entre tiendas', $p, ['sucursal' => true]);
?>

<form method="get" class="card p-4 mb-5 flex items-end gap-3 flex-wrap no-print">
  <?php foreach (['periodo', 'desde', 'hasta', 'sucursal_id'] as $k): ?>
    <?php if (get($k) !== null && get($k) !== ''): ?>
      <input type="hidden" name="<?= $k ?>" value="<?= e((string) get($k)) ?>">
    <?php endif; ?>
  <?php endforeach; ?>
  <div>
    <label class="label" for="estado">Estado</label>
    <select id="estado" name="estado" class="select">
      <option value="">Todos</option>
      <?php foreach (['pendiente' => 'Esperando autorización', 'enviada' => 'En camino',
                      'recibida' => 'Recibida', 'borrador' => 'Borrador',
                      'rechazada' => 'Rechazada', 'anulada' => 'Anulada'] as $k => $v): ?>
        <option value="<?= $k ?>" <?= $estado === $k ? 'selected' : '' ?>><?= $v ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn btn-primary"><?= icon('filter', 'w-4 h-4') ?> Aplicar</button>
</form>

<?= rep_kpis([
    ['label' => 'Traslados del periodo', 'valor' => number_format($res['n']), 'icono' => 'transfer',
     'color' => 'blue', 'nota' => number_format($res['recibidas']) . ' ya recibida(s)'],
    ['label' => 'Unidades movidas', 'valor' => qty($res['unidades']), 'icono' => 'layers', 'color' => 'indigo',
     'nota' => 'Suma de todas las líneas'],
    ['label' => 'Valor a costo', 'valor' => money($res['valor']), 'icono' => 'coins', 'color' => 'emerald',
     'nota' => 'Lo que vale la mercancía trasladada'],
    ['label' => 'Esperando autorización', 'valor' => number_format($res['pendientes']), 'icono' => 'clock',
     'color' => $res['pendientes'] > 0 ? 'amber' : 'emerald',
     'nota' => $res['pendientes'] > 0 ? 'No han salido del origen' : 'Nada detenido'],
], 4) ?>

<?php if ($res['varadas'] > 0): ?>
  <div class="card p-4 mb-5 flex items-start gap-3 bg-amber-50 border-amber-200">
    <?= icon('alert', 'w-5 h-5 text-amber-600 mt-0.5 shrink-0') ?>
    <p class="text-sm text-amber-900">
      <strong><?= number_format($res['varadas']) ?> traslado(s) llevan más de 7 días enviados sin que nadie
      los reciba.</strong> Esa mercancía ya salió del origen y no ha entrado en el destino: mientras tanto no
      figura en ninguna tienda.
    </p>
  </div>
<?php endif; ?>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-5 mb-5">
  <div class="xl:col-span-2">
    <?= rep_seccion('Cada traslado, con su motivo y quién lo autorizó',
        'El rastro de por qué salió esa mercancía', 'transfer', 'blue') ?>
      <div class="overflow-x-auto">
        <table class="data-table">
          <thead><tr>
            <th>Documento</th><th>Ruta</th><th class="text-center">Unidades</th>
            <th class="text-right">Valor</th><th>Motivo</th><th>Autorizó</th><th class="text-center">Estado</th>
          </tr></thead>
          <tbody>
            <?php if (!$trf): ?>
              <tr><td colspan="7" class="text-center text-slate-400 py-8">
                Ningún traslado en este periodo.
              </td></tr>
            <?php endif; ?>
            <?php foreach ($trf as $t): ?>
              <tr>
                <td>
                  <p class="font-semibold text-slate-700"><?= e($t['numero']) ?></p>
                  <p class="text-xs text-slate-400"><?= e(fechaCorta($t['fecha'])) ?></p>
                </td>
                <td class="text-sm text-slate-600">
                  <?= e($t['origen']) ?><br>
                  <span class="text-slate-400">→ <?= e($t['destino']) ?></span>
                </td>
                <td class="text-center font-semibold text-slate-700 tabular-nums"><?= qty($t['unidades']) ?></td>
                <td class="text-right text-slate-600 tabular-nums"><?= money($t['valor'], false) ?></td>
                <td class="text-sm text-slate-600 max-w-[16rem]">
                  <?= e($t['notas'] ?: '—') ?>
                  <?php if ($t['motivo_rechazo']): ?>
                    <span class="block text-xs text-amber-700 mt-0.5">No autorizada: <?= e($t['motivo_rechazo']) ?></span>
                  <?php endif; ?>
                </td>
                <td class="text-sm text-slate-500">
                  <?= e($t['aprueba'] ?: '—') ?>
                  <?php if ($t['solicita']): ?>
                    <span class="block text-xs text-slate-400">pidió <?= e($t['solicita']) ?></span>
                  <?php endif; ?>
                </td>
                <td class="text-center"><?= badgeFor($t['estado']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?= rep_fin() ?>
  </div>

  <div>
    <?= rep_seccion('Por dónde se mueve', 'Rutas con más mercancía trasladada', 'transfer', 'indigo') ?>
      <?php
      $filasR = [];
      foreach (array_slice($rutas, 0, 12, true) as $ruta => $r) {
          $filasR[] = [
              '<span class="text-sm text-slate-700">' . e($ruta) . '</span>',
              number_format($r['n']),
              qty($r['unidades']),
              money($r['valor'], false),
          ];
      }
      echo rep_tabla(
          ['Ruta', ['Veces', 'center'], ['Unidades', 'center'], ['Valor', 'right']],
          $filasR,
          ['vacio' => 'Todavía no se ha movido mercancía entre tiendas en este periodo.',
           'vacio_icono' => 'transfer']
      );
      ?>
    <?= rep_fin() ?>
  </div>
</div>

<?php layout_end(); ?>
