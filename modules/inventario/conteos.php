<?php
/**
 * Conteo físico de inventario (toma de inventario).
 *
 * Abrir un conteo congela la existencia teórica de cada producto en una foto
 * fija. A partir de ahí se captura lo contado con calma —la tienda puede seguir
 * vendiendo— y al aplicarlo el sistema mueve el stock por la diferencia
 * encontrada, dejando el rastro en el kardex.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('conteos.ver');

if (isPost()) {
    verify_csrf();

    if (post('accion') === 'abrir') {
        require_perm('conteos.crear');
        $sucursalId  = postInt('sucursal_id');
        $categoriaId = postInt('categoria_id') ?: null;
        $descripcion = trim(post('descripcion'));
        $notas       = trim(post('notas'));
        $soloConStock = postInt('solo_con_stock', 0) === 1;

        try {
            require_sucursal_access($sucursalId);
            if ($descripcion === '') throw new RuntimeException('Ponle un nombre al conteo para identificarlo después.');
            if ($categoriaId && !qVal("SELECT 1 FROM categorias WHERE id = ?", [$categoriaId])) {
                throw new RuntimeException('La categoría seleccionada no existe.');
            }
            // Un conteo abierto por sucursal: dos a la vez sobre el mismo almacén
            // producirían ajustes contradictorios.
            $abierto = qOne("SELECT numero FROM conteos WHERE sucursal_id = ? AND estado = 'abierto' LIMIT 1", [$sucursalId]);
            if ($abierto) {
                throw new RuntimeException('Esa sucursal ya tiene el conteo ' . $abierto['numero'] . ' abierto. Ciérralo o cancélalo antes de abrir otro.');
            }

            $conteoId = txReintentable(function () use ($sucursalId, $categoriaId, $descripcion, $notas, $soloConStock) {
                // Bloquea la sucursal para que dos aperturas simultáneas no
                // dejen dos conteos abiertos sobre el mismo almacén.
                q("SELECT id FROM sucursales WHERE id = ? FOR UPDATE", [$sucursalId]);
                if (qVal("SELECT 1 FROM conteos WHERE sucursal_id = ? AND estado = 'abierto'", [$sucursalId])) {
                    throw new RuntimeException('Esa sucursal ya tiene un conteo abierto.');
                }

                $numero = nextNumero('conteos', 'numero', 'CNT');
                $id = dbInsert('conteos', [
                    'numero' => $numero, 'sucursal_id' => $sucursalId, 'categoria_id' => $categoriaId,
                    'descripcion' => $descripcion, 'notas' => $notas ?: null,
                    'estado' => 'abierto', 'usuario_id' => current_user()['id'] ?? null,
                ]);

                // Foto del inventario: se congelan existencia y costo de cada
                // producto del alcance en una sola sentencia.
                $cond = ["p.activo = 1", "p.tipo = 'producto'"];
                if ($categoriaId) $cond[] = 'p.categoria_id = ?';
                if ($soloConStock) $cond[] = 'COALESCE(s.cantidad,0) > 0';
                $where = implode(' AND ', $cond);

                q(
                    "INSERT INTO conteo_detalles (conteo_id, producto_id, stock_teorico, costo_unitario)
                     SELECT ?, p.id, COALESCE(s.cantidad,0), p.precio_compra
                       FROM productos p
                       LEFT JOIN inventario_stock s ON s.producto_id = p.id AND s.sucursal_id = ?
                      WHERE $where",
                    array_merge([$id, $sucursalId], $categoriaId ? [$categoriaId] : [])
                );

                if (!qVal("SELECT COUNT(*) FROM conteo_detalles WHERE conteo_id = ?", [$id])) {
                    throw new RuntimeException('No hay productos en ese alcance para contar.');
                }
                return $id;
            });

            audit('conteos', 'crear', 'Conteo de inventario abierto: ' . $descripcion, ['tabla' => 'conteos', 'registro_id' => $conteoId]);
            flash('success', 'Conteo abierto. Ya puedes capturar las cantidades contadas.');
            redirect('modules/inventario/conteo.php?id=' . $conteoId);
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/inventario/conteos.php');
    }
}

/* ---------- Listado ---------- */
[$scope, $sp] = sucursalFiltro('c.sucursal_id');
$estado = in_array(get('estado'), ['abierto', 'aplicado', 'cancelado'], true) ? get('estado') : '';
$cond = [$scope];
$par  = $sp;
if ($estado !== '') { $cond[] = 'c.estado = ?'; $par[] = $estado; }
$where = implode(' AND ', $cond);

$pg = paginar((int) qVal("SELECT COUNT(*) FROM conteos c WHERE $where", $par), 20);
$conteos = qAll(
    "SELECT c.*, su.nombre AS sucursal, cat.nombre AS categoria,
            CONCAT(u.nombre,' ',u.apellido) AS abierto_por,
            (SELECT COUNT(*) FROM conteo_detalles d WHERE d.conteo_id = c.id) AS productos,
            (SELECT COUNT(*) FROM conteo_detalles d WHERE d.conteo_id = c.id AND d.stock_contado IS NOT NULL) AS contados,
            (SELECT COUNT(*) FROM conteo_detalles d WHERE d.conteo_id = c.id AND d.stock_contado IS NOT NULL
                AND ABS(d.stock_contado - d.stock_teorico) > 0.0001) AS diferencias,
            (SELECT COALESCE(SUM((d.stock_contado - d.stock_teorico) * d.costo_unitario),0)
               FROM conteo_detalles d WHERE d.conteo_id = c.id AND d.stock_contado IS NOT NULL) AS valor_diferencia
       FROM conteos c
       JOIN sucursales su ON su.id = c.sucursal_id
       LEFT JOIN categorias cat ON cat.id = c.categoria_id
       LEFT JOIN usuarios u ON u.id = c.usuario_id
      WHERE $where
      ORDER BY c.id DESC LIMIT {$pg['porPagina']} OFFSET {$pg['offset']}",
    $par
);

$sucursales = sucursales_visibles();
$categorias = qAll("SELECT id, nombre FROM categorias WHERE activo = 1 ORDER BY nombre");

$acciones = can('conteos.crear') ? btn_nuevo('cnt:new', 'Nuevo conteo') : '';
layout_start('Conteo físico de inventario', 'Cuadra el almacén contra el sistema con trazabilidad', $acciones);

// Un conteo abierto congela la existencia de su sucursal, así que saber cuántos
// hay sin cerrar es lo primero. El impacto es lo que los ajustes ya aplicados le
// movieron al valor del inventario: si sale grande, el almacén no cuadra.
[$scopeK, $parK] = sucursalScope('c.sucursal_id');
$resumen = qOne(
    "SELECT COALESCE(SUM(c.estado = 'abierto'), 0)  abiertos,
            COALESCE(SUM(c.estado = 'aplicado'), 0) aplicados,
            COALESCE((SELECT SUM((d.stock_contado - d.stock_teorico) * d.costo_unitario)
                        FROM conteo_detalles d
                        JOIN conteos c2 ON c2.id = d.conteo_id
                       WHERE c2.estado = 'aplicado' AND d.stock_contado IS NOT NULL), 0) impacto
       FROM conteos c WHERE $scopeK", $parK
) ?: ['abiertos' => 0, 'aplicados' => 0, 'impacto' => 0];
$impacto = (float) $resumen['impacto'];

echo kpis([
    ['label' => 'Conteos abiertos', 'valor' => number_format((int) $resumen['abiertos']), 'icono' => 'clipboard',
     'color' => (int) $resumen['abiertos'] > 0 ? 'amber' : 'slate',
     'nota' => (int) $resumen['abiertos'] > 0 ? 'Pendientes de aplicar' : 'Nada pendiente',
     'href' => (int) $resumen['abiertos'] > 0 ? '?estado=abierto' : ''],
    ['label' => 'Conteos aplicados', 'valor' => number_format((int) $resumen['aplicados']), 'icono' => 'check',
     'color' => 'emerald', 'nota' => 'Ya ajustaron el inventario', 'href' => '?estado=aplicado'],
    ['label' => 'Impacto acumulado', 'valor' => ($impacto >= 0 ? '+' : '−') . money(abs($impacto)),
     'icono' => 'wallet', 'color' => abs($impacto) < 0.01 ? 'slate' : ($impacto >= 0 ? 'emerald' : 'rose'),
     'nota' => abs($impacto) < 0.01
        ? 'El almacén cuadraba'
        : ($impacto >= 0 ? 'Había más de lo que decía el sistema' : 'Faltaba mercancía')],
], 3);
?>

<div class="card p-4 mb-5 no-print flex flex-wrap items-center gap-2">
  <div class="flex flex-wrap items-center gap-1 p-1 bg-slate-100 rounded-xl">
    <?php foreach (['' => 'Todos', 'abierto' => 'Abiertos', 'aplicado' => 'Aplicados', 'cancelado' => 'Cancelados'] as $k => $lbl):
      $qs = array_filter(['estado' => $k ?: null] + array_intersect_key($_GET, ['sucursal_id' => 1])); ?>
      <a href="?<?= e(http_build_query($qs)) ?>"
         class="px-3 py-1.5 rounded-lg text-[13px] font-semibold transition <?= $estado === $k ? 'bg-white text-blue-700 shadow-sm' : 'text-slate-500 hover:text-slate-800' ?>"><?= e($lbl) ?></a>
    <?php endforeach; ?>
  </div>
  <?php $selSuc = selectSucursalFiltro(); if ($selSuc): ?>
    <form method="get" class="flex items-center gap-2">
      <?php if ($estado): ?><input type="hidden" name="estado" value="<?= e($estado) ?>"><?php endif; ?>
      <?= $selSuc ?>
      <button class="btn btn-ghost btn-sm"><?= icon('filter', 'w-3.5 h-3.5') ?> Filtrar</button>
    </form>
  <?php endif; ?>
  <span class="ml-auto text-sm text-slate-400"><?= number_format($pg['total']) ?> conteo(s)</span>
</div>

<div class="card overflow-hidden">
  <?php if (!$conteos): ?>
    <?= empty_state(
        'Todavía no hay conteos',
        'Un conteo físico congela la existencia del sistema, te deja capturar lo que hay de verdad en el almacén y ajusta la diferencia dejando rastro en el kardex.',
        'clipboard',
        can('conteos.crear') ? btn_nuevo('cnt:new', 'Abrir el primer conteo') : ''
    ) ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr>
          <th>Conteo</th><th>Alcance</th><th class="text-center">Avance</th>
          <th class="text-center">Diferencias</th><th class="text-right">Impacto</th>
          <th class="text-center">Estado</th><th class="text-right">Acciones</th>
        </tr></thead>
        <tbody>
          <?php foreach ($conteos as $c):
            $pctAvance = (int) $c['productos'] > 0 ? (int) $c['contados'] / (int) $c['productos'] * 100 : 0;
            $val = (float) $c['valor_diferencia'];
            $estados = ['abierto' => ['En proceso', 'amber'], 'aplicado' => ['Aplicado', 'emerald'], 'cancelado' => ['Cancelado', 'slate']];
            [$eLbl, $eCol] = $estados[$c['estado']];
          ?>
            <tr>
              <td>
                <a href="<?= e(url('modules/inventario/conteo.php?id=' . (int) $c['id'])) ?>" class="font-semibold text-slate-700 hover:text-blue-700"><?= e($c['numero']) ?></a>
                <span class="block text-[11.5px] text-slate-400"><?= e($c['descripcion']) ?></span>
              </td>
              <td>
                <span class="text-slate-600 text-sm"><?= e($c['sucursal']) ?></span>
                <span class="block text-[11.5px] text-slate-400"><?= e($c['categoria'] ?: 'Todo el catálogo') ?> · <?= (int) $c['productos'] ?> producto(s)</span>
              </td>
              <td class="text-center">
                <div class="min-w-[110px] mx-auto">
                  <div class="flex items-center justify-between text-[11px] mb-1">
                    <span class="font-semibold text-slate-600"><?= (int) $c['contados'] ?>/<?= (int) $c['productos'] ?></span>
                    <span class="text-slate-400"><?= number_format($pctAvance, 0) ?>%</span>
                  </div>
                  <div class="h-1.5 rounded-full bg-slate-100 overflow-hidden">
                    <div class="h-full rounded-full <?= $pctAvance >= 100 ? 'bg-emerald-500' : 'bg-blue-500' ?>" style="width:<?= max($pctAvance, 2) ?>%"></div>
                  </div>
                </div>
              </td>
              <td class="text-center">
                <?= (int) $c['diferencias'] > 0
                    ? badge((int) $c['diferencias'] . ' producto(s)', 'amber')
                    : ((int) $c['contados'] > 0 ? badge('Sin diferencias', 'emerald') : '<span class="text-slate-300">—</span>') ?>
              </td>
              <td class="text-right tabular-nums <?= abs($val) < 0.005 ? 'text-slate-400' : ($val < 0 ? 'text-rose-600 font-semibold' : 'text-emerald-600 font-semibold') ?>">
                <?= abs($val) < 0.005 ? '—' : ($val > 0 ? '+' : '') . money($val) ?>
              </td>
              <td class="text-center"><?= badge($eLbl, $eCol) ?></td>
              <td class="text-right">
                <a href="<?= e(url('modules/inventario/conteo.php?id=' . (int) $c['id'])) ?>" class="btn btn-soft btn-sm">
                  <?= $c['estado'] === 'abierto' ? 'Continuar' : 'Ver' ?> <?= icon('arrow-right', 'w-3.5 h-3.5') ?>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= paginacion($pg) ?>
  <?php endif; ?>
</div>

<?php if (can('conteos.crear')): ?>
<!-- Modal: abrir conteo -->
<div x-data="{open:false}" @cnt:new.window="open=true" @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-lg" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="abrir">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800">Abrir conteo de inventario</h3>
          <button type="button" @click="open=false" aria-label="Cerrar modal" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 space-y-4">
          <div class="flex items-start gap-3 rounded-xl bg-slate-50 p-3.5">
            <span class="w-9 h-9 rounded-xl bg-white border border-slate-200 text-slate-400 flex items-center justify-center shrink-0"><?= icon('clipboard', 'w-4 h-4') ?></span>
            <p class="text-[13px] text-slate-600 leading-relaxed">
              Al abrirlo se guarda una foto de la existencia actual. La tienda puede seguir vendiendo:
              al aplicar, se ajusta por la <strong>diferencia encontrada</strong>, no por el número absoluto.
            </p>
          </div>
          <div>
            <label class="label" for="cnt_desc">Nombre del conteo *</label>
            <input id="cnt_desc" name="descripcion" required class="input" placeholder="Ej. Conteo general de agosto"
                   value="Conteo <?= e(mesNombre((int) date('n'))) ?> <?= date('Y') ?>">
          </div>
          <div>
            <label class="label" for="cnt_suc">Sucursal *</label>
            <select id="cnt_suc" name="sucursal_id" required class="select">
              <?php foreach ($sucursales as $s): ?>
                <option value="<?= (int) $s['id'] ?>" <?= current_sucursal_id() === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="text-xs text-slate-400 mt-1.5">Solo puede haber un conteo abierto por sucursal.</p>
          </div>
          <div>
            <label class="label" for="cnt_cat">Alcance</label>
            <select id="cnt_cat" name="categoria_id" class="select">
              <option value="">Todo el catálogo</option>
              <?php foreach ($categorias as $c): ?>
                <option value="<?= (int) $c['id'] ?>"><?= e($c['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="text-xs text-slate-400 mt-1.5">Contar por categoría permite cuadrar el almacén por partes sin cerrar la tienda.</p>
          </div>
          <label class="flex items-start gap-2.5 text-sm text-slate-600 cursor-pointer">
            <input type="hidden" name="solo_con_stock" value="0">
            <input type="checkbox" name="solo_con_stock" value="1" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500 mt-0.5">
            <span>Incluir solo productos con existencia
              <span class="block text-xs text-slate-400">Si lo dejas sin marcar se incluyen también los que están en cero, para detectar mercancía que el sistema no sabe que tienes.</span>
            </span>
          </label>
          <div>
            <label class="label" for="cnt_notas">Notas</label>
            <textarea id="cnt_notas" name="notas" rows="2" class="input" placeholder="Opcional: quién cuenta, en qué turno…"></textarea>
          </div>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button type="submit" class="btn btn-primary"><?= icon('clipboard', 'w-4 h-4') ?> Abrir conteo</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php layout_end(); ?>
