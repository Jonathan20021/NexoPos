<?php
/**
 * Panel de autorizaciones: la mercancía que quiere salir y todavía no ha salido.
 *
 * Existe porque aprobar desde el listado general de transferencias obliga a
 * filtrar, abrir cada una y volver. Quien autoriza no administra transferencias:
 * mira lo que está detenido, ve qué es y decide. Por eso aquí va el detalle
 * completo —productos, cantidades, quién la pidió y por qué— sin tener que
 * entrar a ninguna otra pantalla.
 *
 * Solo muestra el estado «pendiente». En cuanto se autoriza o se devuelve, la
 * fila desaparece: un panel de pendientes con cosas ya resueltas deja de leerse.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('transferencias.aprobar');

if (isPost()) {
    verify_csrf();
    $accion = post('accion');
    $id     = postInt('id');
    try {
        if ($accion === 'aprobar') {
            txReintentable(fn() => transferenciaEnviar($id));
            audit('transferencias', 'enviar', "Transferencia #$id autorizada desde el panel: la mercancía salió del origen",
                  ['tabla' => 'transferencias', 'registro_id' => $id]);
            flash('success', 'Autorizada. El stock salió del origen y va camino al destino.');
        } elseif ($accion === 'devolver') {
            $motivo = trim(post('motivo_rechazo'));
            txReintentable(fn() => transferenciaDevolverABorrador($id, $motivo));
            audit('transferencias', 'editar', "Transferencia #$id devuelta a borrador desde el panel: $motivo",
                  ['tabla' => 'transferencias', 'registro_id' => $id]);
            flash('success', 'Devuelta a borrador. Quien la solicitó verá el motivo.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('modules/inventario/aprobaciones.php');
}

[$scope, $scopeP] = sucursalFiltro('t.sucursal_origen_id');

$pendientes = qAll(
    "SELECT t.*, so.nombre AS origen, sd.nombre AS destino,
            CONCAT(u.nombre,' ',u.apellido) AS solicita
       FROM transferencias t
       JOIN sucursales so ON so.id = t.sucursal_origen_id
       JOIN sucursales sd ON sd.id = t.sucursal_destino_id
       LEFT JOIN usuarios u ON u.id = t.usuario_id
      WHERE t.estado = 'pendiente' AND $scope
      ORDER BY t.created_at",
    $scopeP
);

// Las líneas de todas de una vez: una consulta por fila convertiría un panel de
// veinte solicitudes en veintiuna consultas.
$lineas = [];
if ($pendientes) {
    $ids = array_column($pendientes, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    foreach (qAll(
        "SELECT td.transferencia_id, p.nombre, p.codigo, td.cantidad,
                COALESCE(s.cantidad, 0) AS existencia
           FROM transferencia_detalles td
           JOIN productos p ON p.id = td.producto_id
           JOIN transferencias t ON t.id = td.transferencia_id
           LEFT JOIN inventario_stock s ON s.producto_id = td.producto_id AND s.sucursal_id = t.sucursal_origen_id
          WHERE td.transferencia_id IN ($ph)
          ORDER BY p.nombre",
        $ids
    ) as $l) {
        $lineas[(int) $l['transferencia_id']][] = $l;
    }
}

$uid = (int) (current_user()['id'] ?? 0);

layout_start('Autorizaciones', 'Mercancía esperando permiso para salir');

echo kpis([
    ['label' => 'Esperando tu autorización', 'valor' => number_format(count($pendientes)),
     'icono' => 'clock', 'color' => count($pendientes) > 0 ? 'amber' : 'emerald',
     'nota' => count($pendientes) > 0
        ? 'Nada de esto se ha movido todavía'
        : 'No hay ninguna salida detenida'],
], 1);
?>

<?php if (!$pendientes): ?>
  <?= empty_state('Nada que autorizar',
      'Cuando alguien pida sacar mercancía de una tienda aparecerá aquí, con el detalle de qué es y por qué.',
      'check') ?>
<?php else: ?>
  <div class="space-y-4">
    <?php foreach ($pendientes as $t): $propia = (int) $t['usuario_id'] === $uid; ?>
      <div class="card overflow-hidden">
        <div class="p-5 border-b border-slate-100 flex items-start justify-between gap-4 flex-wrap">
          <div class="min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <span class="font-bold text-slate-800"><?= e($t['numero']) ?></span>
              <span class="badge badge-amber">esperando autorización</span>
            </div>
            <p class="mt-1.5 text-sm text-slate-600">
              Sale de <strong><?= e($t['origen']) ?></strong> · va a <strong><?= e($t['destino']) ?></strong>
            </p>
            <p class="mt-0.5 text-xs text-slate-400">
              Solicitada por <?= e($t['solicita'] ?: '—') ?> · <?= e(tiempoRelativo($t['created_at'])) ?>
            </p>
          </div>
          <div class="flex items-center gap-2 shrink-0 flex-wrap">
            <?php if ($propia): ?>
              <span class="text-sm text-slate-400 max-w-xs leading-snug">
                La solicitaste tú: tiene que autorizarla otra persona.
              </span>
            <?php else: ?>
              <button type="button"
                      onclick="<?= jsEvent('apr:devolver', ['id' => (int) $t['id'], 'numero' => $t['numero']]) ?>"
                      class="btn btn-ghost"><?= icon('undo', 'w-4 h-4') ?> No autorizar</button>
              <form method="post" class="inline"
                    onsubmit="return confirm('¿Autorizar la salida? El stock se descuenta del origen ahora.')">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="aprobar">
                <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                <button class="btn btn-primary"><?= icon('check', 'w-4 h-4') ?> Autorizar la salida</button>
              </form>
            <?php endif; ?>
          </div>
        </div>

        <div class="px-5 py-3 bg-slate-50 border-b border-slate-100">
          <p class="text-xs font-semibold text-slate-400 uppercase">Motivo</p>
          <p class="text-sm text-slate-700 mt-0.5"><?= e($t['notas'] ?: 'Sin motivo escrito') ?></p>
          <?php if ($t['motivo_rechazo']): ?>
            <p class="mt-2 text-xs text-amber-700">
              Ya se devolvió una vez: «<?= e($t['motivo_rechazo']) ?>»
            </p>
          <?php endif; ?>
        </div>

        <div class="overflow-x-auto">
          <table class="data-table">
            <thead><tr>
              <th>Producto</th>
              <th class="text-center">Sale</th>
              <th class="text-center">Hay en <?= e($t['origen']) ?></th>
              <th></th>
            </tr></thead>
            <tbody>
              <?php foreach ($lineas[(int) $t['id']] ?? [] as $l):
                  $alcanza = (float) $l['existencia'] >= (float) $l['cantidad']; ?>
                <tr>
                  <td>
                    <p class="font-semibold text-slate-700"><?= e($l['nombre']) ?></p>
                    <p class="text-xs text-slate-400"><?= e($l['codigo']) ?></p>
                  </td>
                  <td class="text-center font-bold text-slate-800"><?= qty($l['cantidad']) ?></td>
                  <td class="text-center <?= $alcanza ? 'text-slate-500' : 'text-rose-600 font-semibold' ?>">
                    <?= qty($l['existencia']) ?>
                  </td>
                  <td class="text-xs">
                    <?php if (!$alcanza): ?>
                      <span class="text-rose-600">Ya no alcanza: se vendió después de pedirla</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- No autorizar: vuelve a borrador con el motivo -->
<div x-data="{ open:false, id:0, numero:'' }" @apr:devolver.window="id=$event.detail.id; numero=$event.detail.numero; open=true"
     @keydown.escape.window="open=false" x-show="open" x-transition.opacity style="display:none"
     class="modal-overlay" @click.self="open=false">
  <div class="modal-panel bg-white rounded-2xl shadow-pop max-w-md" @click.stop>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="devolver">
      <input type="hidden" name="id" :value="id">
      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <h3 class="font-bold text-slate-800">No autorizar <span x-text="numero"></span></h3>
        <button type="button" @click="open=false" aria-label="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
      </div>
      <div class="p-6 space-y-3">
        <p class="text-sm text-slate-500">Vuelve a borrador y quien la solicitó verá el motivo. La mercancía no se ha movido.</p>
        <div>
          <label class="label">Por qué no se autoriza *</label>
          <input name="motivo_rechazo" required maxlength="255" class="input"
                 placeholder="Ej. Esa cantidad hace falta en la tienda, falta el visto bueno de compras...">
        </div>
      </div>
      <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
        <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
        <button class="btn btn-primary"><?= icon('undo', 'w-4 h-4') ?> Devolver a borrador</button>
      </div>
    </form>
  </div>
</div>

<?php layout_end(); ?>
