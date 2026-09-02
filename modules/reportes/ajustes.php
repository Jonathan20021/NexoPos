<?php
/**
 * Ajustes y mermas: por qué el inventario bajó sin que hubiera una venta.
 *
 * Es la pregunta que hizo la dirección: «si habían veinte y ahora hay quince,
 * ¿quién lo bajó y por qué?». Las ventas, compras y traslados ya tienen su
 * documento y su informe; lo que queda —ajustes a mano y conteos físicos— es
 * justo lo que puede mover existencia sin dejar rastro comercial, y por eso es
 * lo único que se mira aquí.
 *
 * El informe no se limita a listar: separa lo que FALTÓ de lo que APARECIÓ,
 * le pone precio a la diferencia y señala los ajustes que se hicieron sin
 * explicación. Un ajuste sin nota no es un dato incompleto, es un control que
 * falló, y sale arriba en rojo para que se persiga.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_any_perm(['reportes.inventario', 'reportes.operacion']);

$p = rep_periodo('mes');
[$scope, $scopeP] = rep_scope('m.sucursal_id');

// Solo los movimientos que NO nacen de un documento comercial. Venta, compra,
// devolución y traslado ya se explican solos en sus propios informes; mezclarlos
// aquí escondería las cinco unidades que desaparecieron entre miles de líneas.
$tiposControl = "m.tipo IN ('ajuste','entrada','salida')";

$sentido = in_array(get('sentido'), ['faltantes', 'sobrantes', 'sin_nota'], true) ? get('sentido') : '';
$origen  = in_array(get('origen'), ['conteo', 'manual'], true) ? get('origen') : '';

// Un ajuste de conteo lleva de motivo el número del documento; eso identifica
// de dónde salió, pero no explica nada. Los dos casos se cuentan como «sin
// explicación» porque ninguno responde a la pregunta de la dirección.
$sinNota = "(TRIM(COALESCE(m.motivo,'')) = '' OR m.motivo REGEXP '^Conteo [A-Z0-9-]+$')";

$cond = ["m.created_at BETWEEN ? AND ?", $scope, $tiposControl];
$par  = array_merge([$p['ini'], $p['fin']], $scopeP);
if ($sentido === 'faltantes')      $cond[] = 'm.cantidad < 0';
elseif ($sentido === 'sobrantes')  $cond[] = 'm.cantidad > 0';
elseif ($sentido === 'sin_nota')   $cond[] = $sinNota;
if ($origen === 'conteo')          $cond[] = "m.referencia_tipo = 'conteo'";
elseif ($origen === 'manual')      $cond[] = "COALESCE(m.referencia_tipo,'') <> 'conteo'";
$where = implode(' AND ', $cond);

// El costo del movimiento manda cuando existe; el ajuste a mano no lo pide y
// llega en cero, y ahí el costo de reposición del producto es lo más cercano a
// lo que valía la mercancía que se fue.
$valor = "m.cantidad * COALESCE(NULLIF(m.costo_unitario, 0), pr.precio_compra, 0)";

/* ---------- Lo que salió de una tienda y nunca llegó a la otra ----------
 *
 *  Estas unidades no son un ajuste: nadie las tocó a mano. Salieron del origen
 *  al aprobar el traslado y no entraron en el destino porque no llegaron. Por
 *  eso no aparecen en la tabla de movimientos y aun así son mercancía perdida,
 *  igual de real que la que falta en un conteo. Aquí es donde toca verla.
 *
 *  Un traslado toca dos sucursales, así que el alcance mira las dos: el
 *  faltante le importa tanto a quien lo mandó como a quien lo esperaba.
 */
[$scopeTo, $scopeToP] = rep_scope('t.sucursal_origen_id');
[$scopeTd, $scopeTdP] = rep_scope('t.sucursal_destino_id');
$transito = qAll(
    "SELECT t.id, t.numero, t.recibida_at, t.notas_recepcion,
            so.nombre AS origen, sd.nombre AS destino,
            pr.nombre AS producto, pr.codigo,
            (td.cantidad - td.cantidad_recibida) AS faltaron,
            (td.cantidad - td.cantidad_recibida) * COALESCE(NULLIF(pr.precio_compra,0), 0) AS valor
       FROM transferencia_detalles td
       JOIN transferencias t  ON t.id = td.transferencia_id
       JOIN sucursales so     ON so.id = t.sucursal_origen_id
       JOIN sucursales sd     ON sd.id = t.sucursal_destino_id
       JOIN productos pr      ON pr.id = td.producto_id
      WHERE t.estado = 'recibida'
        AND td.cantidad_recibida IS NOT NULL
        AND td.cantidad_recibida < td.cantidad
        AND t.recibida_at BETWEEN ? AND ?
        AND ($scopeTo OR $scopeTd)
      ORDER BY t.recibida_at DESC, pr.nombre",
    array_merge([$p['ini'], $p['fin']], $scopeToP, $scopeTdP)
);
$transitoU = array_sum(array_map(fn($x) => (float) $x['faltaron'], $transito));
$transitoV = array_sum(array_map(fn($x) => (float) $x['valor'], $transito));

/* ---------- Resumen del periodo ---------- */
$r = qOne(
    "SELECT COUNT(*) n,
            COALESCE(SUM(CASE WHEN m.cantidad < 0 THEN -m.cantidad ELSE 0 END), 0) u_faltan,
            COALESCE(SUM(CASE WHEN m.cantidad > 0 THEN  m.cantidad ELSE 0 END), 0) u_aparecen,
            COALESCE(SUM(CASE WHEN m.cantidad < 0 THEN -($valor) ELSE 0 END), 0) v_faltan,
            COALESCE(SUM(CASE WHEN m.cantidad > 0 THEN  ($valor) ELSE 0 END), 0) v_aparecen,
            COALESCE(SUM($sinNota), 0) sin_nota,
            COUNT(DISTINCT m.producto_id) productos
       FROM movimientos_inventario m
       JOIN productos pr ON pr.id = m.producto_id
      WHERE $where",
    $par
) ?: [];
$r = array_map('floatval', $r);
$netoValor = $r['v_aparecen'] - $r['v_faltan'];

/* ---------- Por qué se ajustó ---------- */
// El motivo es texto libre y se agrupa por la CAUSA, no por el documento: un
// ajuste de conteo llega como «Conteo CNT-000009 · Mercancía rota», y quedarse
// con el número dejaría cada conteo en su propia fila sin decir nunca por qué.
// Se recorta el prefijo para que la misma causa sume a través de los conteos.
$porMotivo = qAll(
    "SELECT CASE WHEN TRIM(COALESCE(m.motivo,'')) = '' THEN 'SIN EXPLICACIÓN'
                 WHEN m.motivo REGEXP '^Conteo [A-Z0-9-]+$' THEN 'Conteo físico, sin explicar'
                 WHEN m.motivo LIKE 'Conteo % · %'
                      THEN TRIM(SUBSTRING(m.motivo, LOCATE(' · ', m.motivo) + 3))
                 ELSE TRIM(m.motivo) END AS motivo,
            COUNT(*) n,
            COALESCE(SUM(CASE WHEN m.cantidad < 0 THEN -m.cantidad ELSE 0 END), 0) u_faltan,
            COALESCE(SUM(CASE WHEN m.cantidad > 0 THEN  m.cantidad ELSE 0 END), 0) u_aparecen,
            COALESCE(SUM($valor), 0) neto
       FROM movimientos_inventario m
       JOIN productos pr ON pr.id = m.producto_id
      WHERE $where
      GROUP BY motivo
      ORDER BY SUM(ABS(m.cantidad)) DESC
      LIMIT 20",
    $par
);

/* ---------- Quién ajustó ---------- */
$porUsuario = qAll(
    "SELECT COALESCE(CONCAT(u.nombre,' ',u.apellido), 'Sistema') AS quien,
            COUNT(*) n,
            COALESCE(SUM(CASE WHEN m.cantidad < 0 THEN -m.cantidad ELSE 0 END), 0) u_faltan,
            COALESCE(SUM($sinNota), 0) sin_nota,
            COALESCE(SUM($valor), 0) neto
       FROM movimientos_inventario m
       JOIN productos pr ON pr.id = m.producto_id
       LEFT JOIN usuarios u ON u.id = m.usuario_id
      WHERE $where
      GROUP BY quien
      ORDER BY u_faltan DESC, n DESC
      LIMIT 15",
    $par
);

/* ---------- Qué producto se ajusta una y otra vez ---------- */
// Un artículo que aparece cada mes en esta lista no tiene un problema de
// conteo: tiene un problema de control.
$porProducto = qAll(
    "SELECT pr.codigo, pr.nombre, COUNT(*) n,
            COALESCE(SUM(CASE WHEN m.cantidad < 0 THEN -m.cantidad ELSE 0 END), 0) u_faltan,
            COALESCE(SUM(CASE WHEN m.cantidad > 0 THEN  m.cantidad ELSE 0 END), 0) u_aparecen,
            COALESCE(SUM($valor), 0) neto
       FROM movimientos_inventario m
       JOIN productos pr ON pr.id = m.producto_id
      WHERE $where
      GROUP BY pr.id, pr.codigo, pr.nombre
      ORDER BY u_faltan DESC, n DESC
      LIMIT 15",
    $par
);

/* ---------- El detalle: cada movimiento con su explicación ---------- */
$pg = paginar((int) qVal(
    "SELECT COUNT(*) FROM movimientos_inventario m JOIN productos pr ON pr.id = m.producto_id WHERE $where", $par), 60);

$movs = qAll(
    "SELECT m.id, m.created_at, m.cantidad, m.stock_anterior, m.stock_nuevo, m.motivo,
            m.referencia_tipo, m.referencia_id, m.tipo,
            pr.codigo, pr.nombre AS producto,
            su.nombre AS sucursal,
            COALESCE(CONCAT(u.nombre,' ',u.apellido), 'Sistema') AS quien,
            $valor AS valor
       FROM movimientos_inventario m
       JOIN productos pr ON pr.id = m.producto_id
       JOIN sucursales su ON su.id = m.sucursal_id
       LEFT JOIN usuarios u ON u.id = m.usuario_id
      WHERE $where
      ORDER BY m.id DESC
      LIMIT {$pg['porPagina']} OFFSET {$pg['offset']}",
    $par
);

/* ---------- Exportación ---------- */
if (export_solicitado()) {
    $todo = qAll(
        "SELECT m.created_at, pr.codigo, pr.nombre AS producto, su.nombre AS sucursal,
                m.stock_anterior, m.stock_nuevo, m.cantidad, m.motivo, m.referencia_tipo,
                COALESCE(CONCAT(u.nombre,' ',u.apellido), 'Sistema') AS quien,
                $valor AS valor
           FROM movimientos_inventario m
           JOIN productos pr ON pr.id = m.producto_id
           JOIN sucursales su ON su.id = m.sucursal_id
           LEFT JOIN usuarios u ON u.id = m.usuario_id
          WHERE $where
          ORDER BY m.id DESC",
        $par
    );
    export_tabla('ajustes_y_mermas_' . $p['desde'] . '_' . $p['hasta'],
        ['Fecha', 'Código', 'Producto', 'Tienda', 'Había', 'Quedó', 'Diferencia',
         'Explicación', 'Origen', 'Quién', 'Valor'],
        array_map(fn($m) => [
            $m['created_at'], $m['codigo'], $m['producto'], $m['sucursal'],
            qty($m['stock_anterior']), qty($m['stock_nuevo']), qty($m['cantidad']),
            trim((string) $m['motivo']) !== '' ? $m['motivo'] : 'SIN EXPLICACIÓN',
            $m['referencia_tipo'] === 'conteo' ? 'Conteo físico' : 'Ajuste manual',
            $m['quien'], money($m['valor'], false),
        ], $todo),
        'Ajustes y mermas de inventario');
}

/* ---------- Pantalla ---------- */
layout_start('Ajustes y mermas', rep_subtitulo($p), rep_barra_titulo());
echo rep_abrir('Ajustes y mermas de inventario', $p, ['sucursal' => true]);
?>

<form method="get" class="card p-4 mb-5 flex items-end gap-3 flex-wrap no-print">
  <?php foreach (['periodo', 'desde', 'hasta', 'sucursal_id'] as $k): ?>
    <?php if (get($k) !== null && get($k) !== ''): ?>
      <input type="hidden" name="<?= $k ?>" value="<?= e((string) get($k)) ?>">
    <?php endif; ?>
  <?php endforeach; ?>
  <div>
    <label class="label" for="sentido">Qué mirar</label>
    <select id="sentido" name="sentido" class="select">
      <option value="">Todo movimiento</option>
      <option value="faltantes" <?= $sentido === 'faltantes' ? 'selected' : '' ?>>Solo lo que faltó</option>
      <option value="sobrantes" <?= $sentido === 'sobrantes' ? 'selected' : '' ?>>Solo lo que apareció</option>
      <option value="sin_nota"  <?= $sentido === 'sin_nota'  ? 'selected' : '' ?>>Solo lo que quedó sin explicar</option>
    </select>
  </div>
  <div>
    <label class="label" for="origen">De dónde salió</label>
    <select id="origen" name="origen" class="select">
      <option value="">Cualquiera</option>
      <option value="manual" <?= $origen === 'manual' ? 'selected' : '' ?>>Ajuste a mano</option>
      <option value="conteo" <?= $origen === 'conteo' ? 'selected' : '' ?>>Conteo físico</option>
    </select>
  </div>
  <button class="btn btn-primary"><?= icon('filter', 'w-4 h-4') ?> Aplicar</button>
</form>

<?= rep_kpis([
    ['label' => 'Unidades que faltaron', 'valor' => qty($r['u_faltan']), 'icono' => 'trending-down',
     'color' => $r['u_faltan'] > 0 ? 'rose' : 'emerald',
     'nota' => $r['u_faltan'] > 0 ? money($r['v_faltan']) . ' a costo' : 'Nada se perdió'],
    ['label' => 'Unidades que aparecieron', 'valor' => qty($r['u_aparecen']), 'icono' => 'trending',
     'color' => 'sky', 'nota' => money($r['v_aparecen']) . ' a costo'],
    ['label' => 'Diferencia neta', 'valor' => money($netoValor), 'icono' => 'coins',
     'color' => $netoValor < 0 ? 'rose' : ($netoValor > 0 ? 'amber' : 'emerald'),
     'nota' => $netoValor < 0 ? 'Es pérdida del periodo' : ($netoValor > 0 ? 'Sobró mercancía' : 'Cuadra')],
    ['label' => 'Sin explicación', 'valor' => number_format($r['sin_nota']), 'icono' => 'alert',
     'color' => $r['sin_nota'] > 0 ? 'rose' : 'emerald',
     'nota' => $r['sin_nota'] > 0 ? 'De ' . number_format($r['n']) . ' movimiento(s)' : 'Todos llevan su nota'],
], 4) ?>

<?php if ($r['sin_nota'] > 0): ?>
  <div class="card p-4 mb-5 flex items-start gap-3 bg-rose-50 border-rose-200">
    <?= icon('alert', 'w-5 h-5 text-rose-600 mt-0.5 shrink-0') ?>
    <div class="text-sm text-rose-900">
      <strong><?= number_format($r['sin_nota']) ?> movimiento(s) cambiaron la existencia sin decir por qué.</strong>
      <p class="mt-1 text-rose-800">
        Los ajustes de conteo entran aquí a propósito: el número del documento dice de dónde salió la
        corrección, no qué pasó con la mercancía.
        <a href="<?= e(url('modules/reportes/ajustes.php?' . http_build_query(
            array_merge($_GET, ['sentido' => 'sin_nota'])))) ?>" class="underline font-semibold">Verlos</a>.
      </p>
    </div>
  </div>
<?php endif; ?>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-5 mb-5">
  <div>
    <?= rep_seccion('Por qué se ajustó', 'Agrupado por la nota que dejó quien lo hizo', 'list', 'blue') ?>
      <?php
      $fm = [];
      foreach ($porMotivo as $m) {
          $sos = $m['motivo'] === 'SIN EXPLICACIÓN';
          $fm[] = [
              '<span class="text-sm ' . ($sos ? 'text-rose-600 font-semibold' : 'text-slate-700') . '">'
                  . e($m['motivo']) . '</span>'
                  . '<span class="block text-xs text-slate-400">' . number_format($m['n']) . ' movimiento(s)</span>',
              '<span class="text-rose-600 font-medium">' . ($m['u_faltan'] > 0 ? '−' . qty($m['u_faltan']) : '—') . '</span>',
              '<span class="text-sky-600 font-medium">' . ($m['u_aparecen'] > 0 ? '+' . qty($m['u_aparecen']) : '—') . '</span>',
              money($m['neto'], false),
          ];
      }
      echo rep_tabla([
          'Explicación', ['Faltó', 'center'], ['Apareció', 'center'], ['Neto', 'right']
      ], $fm, ['vacio' => 'Nadie ajustó existencias en este periodo.', 'vacio_icono' => 'check']);
      ?>
    <?= rep_fin() ?>
  </div>

  <div>
    <?= rep_seccion('Quién lo hizo', 'Con cuántos ajustes quedaron sin nota', 'users', 'indigo') ?>
      <?php
      $fu = [];
      foreach ($porUsuario as $u) {
          $fu[] = [
              '<span class="text-sm text-slate-700">' . e($u['quien']) . '</span>'
                  . '<span class="block text-xs text-slate-400">' . number_format($u['n']) . ' movimiento(s)</span>',
              '<span class="text-rose-600 font-medium">' . ($u['u_faltan'] > 0 ? '−' . qty($u['u_faltan']) : '—') . '</span>',
              $u['sin_nota'] > 0
                  ? '<span class="text-rose-600 font-semibold">' . number_format($u['sin_nota']) . '</span>'
                  : '<span class="text-slate-300">0</span>',
              money($u['neto'], false),
          ];
      }
      echo rep_tabla([
          'Quién', ['Faltó', 'center'], ['Sin nota', 'center'], ['Neto', 'right']
      ], $fu, ['vacio' => 'Nadie ajustó existencias en este periodo.', 'vacio_icono' => 'users']);
      ?>
    <?= rep_fin() ?>
  </div>

  <div>
    <?= rep_seccion('Qué producto se descuadra', 'Si repite mes tras mes, el problema no es el conteo', 'package', 'amber') ?>
      <?php
      $fp = [];
      foreach ($porProducto as $pp) {
          $fp[] = [
              '<span class="text-sm text-slate-700">' . e($pp['nombre']) . '</span>'
                  . '<span class="block text-xs text-slate-400">' . e($pp['codigo']) . ' · '
                  . number_format($pp['n']) . ' vez(ces)</span>',
              '<span class="text-rose-600 font-medium">' . ($pp['u_faltan'] > 0 ? '−' . qty($pp['u_faltan']) : '—') . '</span>',
              '<span class="text-sky-600 font-medium">' . ($pp['u_aparecen'] > 0 ? '+' . qty($pp['u_aparecen']) : '—') . '</span>',
              money($pp['neto'], false),
          ];
      }
      echo rep_tabla([
          'Producto', ['Faltó', 'center'], ['Apareció', 'center'], ['Neto', 'right']
      ], $fp, ['vacio' => 'Ningún producto se ajustó en este periodo.', 'vacio_icono' => 'package']);
      ?>
    <?= rep_fin() ?>
  </div>
</div>

<?php if ($transito): ?>
  <?= rep_seccion('Lo que salió de una tienda y nunca llegó a la otra',
      'Traslados recibidos incompletos: la mercancía salió del origen y no entró en ningún sitio',
      'truck', 'amber') ?>
    <div class="rounded-xl bg-amber-50 border border-amber-100 p-3 mb-4 text-sm text-amber-800">
      <?= number_format(count($transito)) ?> línea(s) llegaron cortas:
      <strong><?= qty($transitoU) ?></strong> unidades que valían
      <strong><?= money($transitoV) ?></strong>.
      Esto no es un ajuste de conteo: es mercancía que se perdió entre las dos tiendas.
    </div>
    <?php
    $ft = [];
    foreach ($transito as $x) {
        $ft[] = [
            '<a class="link" href="' . e(url('modules/inventario/transferencias.php?ver=' . (int) $x['id'])) . '">'
                . e($x['numero']) . '</a>'
                . '<span class="block text-xs text-slate-400">' . fechaCorta($x['recibida_at']) . '</span>',
            '<span class="text-sm text-slate-700">' . e($x['origen']) . '</span>'
                . '<span class="block text-xs text-slate-400">a ' . e($x['destino']) . '</span>',
            '<span class="text-sm text-slate-700">' . e($x['producto']) . '</span>'
                . '<span class="block text-xs text-slate-400">' . e($x['codigo']) . '</span>',
            '<span class="text-amber-600 font-semibold">' . qty($x['faltaron']) . '</span>',
            money($x['valor'], false),
            '<span class="text-xs text-slate-500">' . e($x['notas_recepcion'] ?: '—') . '</span>',
        ];
    }
    echo rep_tabla([
        'Traslado', 'Ruta', 'Producto', ['Faltaron', 'center'], ['Valor', 'right'], 'Qué pasó'
    ], $ft, ['vacio' => 'Todo lo que salió llegó completo.', 'vacio_icono' => 'truck']);
    ?>
  <?= rep_fin() ?>
<?php endif; ?>

<?= rep_seccion('Cada cambio de existencia, con su explicación',
    'De cuánto a cuánto, quién lo hizo y qué dijo', 'history', 'blue') ?>
  <div class="overflow-x-auto">
    <table class="data-table">
      <thead><tr>
        <th>Cuándo</th><th>Producto</th><th>Tienda</th>
        <th class="text-center">Había</th><th class="text-center">Quedó</th><th class="text-center">Diferencia</th>
        <th>Explicación</th><th>Quién</th><th class="text-right">Valor</th>
      </tr></thead>
      <tbody>
        <?php if (!$movs): ?>
          <tr><td colspan="9" class="text-center text-slate-400 py-8">
            Ninguna existencia se movió a mano ni por conteo en este periodo.
          </td></tr>
        <?php endif; ?>
        <?php foreach ($movs as $m):
            $dif  = (float) $m['cantidad'];
            $nota = trim((string) $m['motivo']);
            $esConteo = $m['referencia_tipo'] === 'conteo'; ?>
          <tr>
            <td class="text-slate-500 text-[13px] whitespace-nowrap"><?= e(fechaHora($m['created_at'])) ?></td>
            <td>
              <p class="font-semibold text-slate-700"><?= e($m['producto']) ?></p>
              <p class="text-xs text-slate-400"><?= e($m['codigo']) ?></p>
            </td>
            <td class="text-slate-600 text-sm"><?= e($m['sucursal']) ?></td>
            <td class="text-center text-slate-500 tabular-nums"><?= qty($m['stock_anterior']) ?></td>
            <td class="text-center font-semibold text-slate-700 tabular-nums"><?= qty($m['stock_nuevo']) ?></td>
            <td class="text-center font-bold tabular-nums <?= $dif < 0 ? 'text-rose-600' : 'text-sky-600' ?>">
              <?= ($dif > 0 ? '+' : '−') . qty(abs($dif)) ?>
            </td>
            <td class="text-sm max-w-[18rem]">
              <?php if ($nota === ''): ?>
                <span class="text-rose-600 font-semibold">Sin explicación</span>
              <?php else: ?>
                <span class="text-slate-600"><?= e($nota) ?></span>
              <?php endif; ?>
              <?php if ($esConteo): ?>
                <a class="block text-xs text-blue-600 hover:underline"
                   href="<?= e(url('modules/inventario/conteo.php?id=' . (int) $m['referencia_id'])) ?>">
                  Ver el conteo
                </a>
              <?php endif; ?>
            </td>
            <td class="text-sm text-slate-500"><?= e($m['quien']) ?></td>
            <td class="text-right text-slate-600 tabular-nums"><?= money($m['valor'], false) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pg['totalPag'] > 1): ?>
    <div class="px-5 pb-4 no-print"><?= paginacion($pg) ?></div>
  <?php endif; ?>
<?= rep_fin() ?>

<?php layout_end(); ?>
