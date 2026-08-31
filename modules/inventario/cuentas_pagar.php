<?php
/**
 * Cuentas por pagar: lo que le debes a tus proveedores.
 *
 * El espejo de Cuentas por Cobrar. Se paga factura por factura o se abona a la
 * cuenta y el sistema salda las más viejas primero, que es como funciona una
 * cuenta corriente con un suplidor.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('cxp.ver');

if (!cxp_disponible()) {
    layout_start('Cuentas por Pagar', 'Falta aplicar la migración');
    echo '<div class="card p-8 text-center">' . icon('alert', 'w-10 h-10 text-amber-500 mx-auto mb-3')
       . '<h3 class="font-bold text-slate-700">Módulo no disponible todavía</h3>'
       . '<p class="text-sm text-slate-500 mt-1">Aplica <code class="bg-slate-100 px-1.5 py-0.5 rounded">database/migracion_cxp_monedas_cotizaciones_p11.sql</code>.</p></div>';
    layout_end();
    exit;
}

/* ============================================================
 *  Registrar un pago (POST · PRG)
 * ============================================================ */
if (isPost()) {
    verify_csrf();
    if (post('accion') === 'pagar') {
        require_perm('cxp.pagar');
        try {
            $r = cxp_registrarPago([
                'proveedor_id'   => postInt('proveedor_id'),
                'compra_id'      => postInt('compra_id'),
                'monto'          => postNum('monto'),
                'moneda_id'      => postInt('moneda_id'),
                'tasa'           => postNum('tasa') ?: null,
                'metodo_pago_id' => postInt('metodo_pago_id'),
                'referencia'     => post('referencia'),
                'notas'          => post('notas'),
            ]);

            $msg = 'Pago registrado: salieron ' . money($r['aplicado_base']);
            if ($r['facturas'] > 1) $msg .= ' (aplicado a ' . $r['facturas'] . ' facturas)';
            if (abs($r['diferencia']) >= 0.01) {
                $msg .= '. La deuda bajó ' . money($r['reduccion']) . ' y '
                     . ($r['diferencia'] > 0
                        ? money($r['diferencia']) . ' se registró como pérdida cambiaria'
                        : money(abs($r['diferencia'])) . ' como ganancia cambiaria');
            }
            audit('cxp', 'crear', 'Pago a proveedor #' . postInt('proveedor_id') . ' por ' . money($r['aplicado_base']),
                  ['tabla' => 'pagos_proveedores', 'registro_id' => $r['pago_id']]);
            flash('success', $msg . '.');
            if (!empty($r['sin_caja'])) {
                flash('warning', 'El pago salió en efectivo y no tenías la caja abierta, así que ese egreso '
                    . 'no aparece en ningún arqueo. Anótalo como egreso al abrir tu caja, o el turno cerrará '
                    . 'con un faltante sin explicación.');
            }
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/inventario/cuentas_pagar.php');
    }
}

/* ============================================================
 *  Datos
 * ============================================================ */
$q = trim(get('q'));
$where  = "p.balance > 0.01";
$params = [];
if ($q !== '') { $where .= " AND (p.nombre LIKE ? OR p.rnc LIKE ? OR p.codigo LIKE ?)"; $params = ["%$q%", "%$q%", "%$q%"]; }

$deudores = qAll(
    "SELECT p.*,
            (SELECT COUNT(*) FROM compras c WHERE c.proveedor_id = p.id AND c.estado <> 'anulada' AND c.saldo > 0.01) facturas,
            (SELECT MIN(c.fecha) FROM compras c WHERE c.proveedor_id = p.id AND c.estado <> 'anulada' AND c.saldo > 0.01) mas_vieja
       FROM proveedores p
      WHERE $where
      ORDER BY p.balance DESC", $params
);

$resumen    = cxp_resumen();
$antiguedad = cxp_antiguedad();

// El contador pide esta lista en Excel para cuadrar el pasivo con sus libros.
if (export_solicitado()) {
    export_tabla('cuentas_por_pagar',
        ['Código', 'Proveedor', 'RNC', 'Teléfono', 'Facturas abiertas', 'Factura más vieja', 'Días', 'Saldo'],
        array_map(function ($d) {
            $dias = $d['mas_vieja'] ? (int) ((time() - strtotime($d['mas_vieja'])) / 86400) : 0;
            return [$d['codigo'], $d['nombre'], $d['rnc'], $d['telefono'],
                    (int) $d['facturas'], $d['mas_vieja'], $dias, (float) $d['balance']];
        }, $deudores));
}
$metodos    = qAll("SELECT id, nombre FROM metodos_pago WHERE activo = 1 AND es_credito = 0 ORDER BY id");
$monedasAct = monedas();

// Facturas pendientes de cada proveedor, para el desglose del modal.
$facturas = [];
foreach ($deudores as $d) {
    foreach (cxp_compras((int) $d['id']) as $c) {
        $mon = moneda($c['moneda_id'] ? (int) $c['moneda_id'] : null);
        $facturas[(int) $d['id']][] = [
            'id'      => (int) $c['id'],
            'numero'  => $c['numero'],
            'fecha'   => fechaCorta($c['fecha']),
            'dias'    => (int) ((time() - strtotime($c['fecha'])) / 86400),
            'saldo'   => (float) $c['saldo'],
            'saldo_txt' => money((float) $c['saldo']),
            'moneda'  => (int) $mon['es_base'] === 1 ? '' : $mon['codigo'],
            'saldo_moneda' => (float) $c['saldo_moneda'],
        ];
    }
}

if (export_solicitado()) {
    export_tabla('cuentas_por_pagar',
        ['Código', 'Proveedor', 'RNC', 'Teléfono', 'Facturas', 'Deuda'],
        array_map(fn($p) => [$p['codigo'], $p['nombre'], $p['rnc'], $p['telefono'], $p['facturas'], $p['balance']], $deudores),
        'Cuentas por Pagar');
}

layout_start('Cuentas por Pagar', 'Lo que le debes a tus proveedores', export_buttons());

echo kpis([
    ['label' => 'Deuda total', 'valor' => money($resumen['total']), 'icono' => 'wallet',
     'color' => $resumen['total'] > 0 ? 'rose' : 'emerald',
     'nota' => $resumen['total'] > 0 ? 'Saldo abierto con suplidores' : 'No se le debe nada a nadie'],
    ['label' => 'Proveedores', 'valor' => number_format($resumen['proveedores']), 'icono' => 'briefcase', 'color' => 'slate',
     'nota' => 'Con saldo pendiente'],
    ['label' => 'Facturas pendientes', 'valor' => number_format($resumen['facturas']), 'icono' => 'receipt', 'color' => 'slate'],
    // Los 30 días son la línea a partir de la cual una factura deja de ser
    // «pendiente» y pasa a ser un problema con el suplidor.
    ['label' => 'Con más de 30 días', 'valor' => money($resumen['vencido']), 'icono' => 'clock',
     'color' => $resumen['vencido'] > 0 ? 'amber' : 'slate',
     'nota' => $resumen['vencido'] > 0 ? 'Ya vencidas' : 'Nada vencido'],
], 4);
?>

<!-- Antigüedad -->
<?php if ($resumen['total'] > 0): ?>
  <div class="card p-5 mb-5">
    <h2 class="font-bold text-slate-800 mb-1">Antigüedad de la deuda</h2>
    <p class="text-xs text-slate-400 mb-4">Cuánto lleva esperando cada peso. Es lo que se mira antes de decidir a quién se le paga esta semana.</p>
    <div class="space-y-3">
      <?php
      $colores = ['0-30 días' => 'bg-emerald-500', '31-60 días' => 'bg-amber-500',
                  '61-90 días' => 'bg-orange-500', 'Más de 90' => 'bg-rose-500'];
      foreach ($antiguedad as $tramo => $monto):
        $pct = $resumen['total'] > 0 ? round($monto * 100 / $resumen['total'], 1) : 0; ?>
        <div>
          <div class="flex items-center justify-between text-sm mb-1.5">
            <span class="text-slate-600 font-medium"><?= e($tramo) ?></span>
            <span class="font-bold text-slate-800"><?= e(money($monto)) ?>
              <span class="text-xs text-slate-400 font-semibold ml-1"><?= $pct ?>%</span></span>
          </div>
          <div class="h-2.5 rounded-full bg-slate-100 overflow-hidden">
            <div class="h-full rounded-full <?= $colores[$tramo] ?>" style="width: <?= max(0.5, $pct) ?>%"></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<div class="card overflow-hidden">
  <div class="p-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
    <?= search_box('Buscar proveedor...') ?>
    <span class="text-sm text-slate-400"><?= count($deudores) ?> proveedor(es) con deuda</span>
  </div>

  <?php if (!$deudores): ?>
    <?= empty_state('Sin deudas pendientes',
        'No le debes nada a ningún proveedor. Las compras a crédito aparecerán aquí en cuanto se registren.',
        'check') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr>
          <th>Proveedor</th><th>RNC</th><th>Contacto</th><th class="text-center">Facturas</th>
          <th class="text-center">Más antigua</th><th class="text-right">Deuda</th><th class="text-right">Acciones</th>
        </tr></thead>
        <tbody>
          <?php foreach ($deudores as $p):
            $dias = $p['mas_vieja'] ? (int) ((time() - strtotime($p['mas_vieja'])) / 86400) : 0; ?>
            <tr>
              <td>
                <p class="font-semibold text-slate-700"><?= e($p['nombre']) ?></p>
                <p class="text-xs text-slate-400"><?= e($p['codigo']) ?></p>
              </td>
              <td class="text-slate-500 text-sm"><?= e($p['rnc'] ?: '—') ?></td>
              <td class="text-slate-500 text-sm"><?= e($p['telefono'] ?: $p['contacto'] ?: '—') ?></td>
              <td class="text-center text-slate-600"><?= (int) $p['facturas'] ?></td>
              <td class="text-center">
                <?php if ($dias > 90): ?><?= badge($dias . ' días', 'rose') ?>
                <?php elseif ($dias > 60): ?><?= badge($dias . ' días', 'orange') ?>
                <?php elseif ($dias > 30): ?><?= badge($dias . ' días', 'amber') ?>
                <?php else: ?><?= badge($dias . ' días', 'emerald') ?><?php endif; ?>
              </td>
              <td class="text-right font-bold text-rose-600"><?= e(money((float) $p['balance'])) ?></td>
              <td class="text-right">
                <?php if (can('cxp.pagar')): ?>
                  <button onclick="<?= jsEvent('pago:new', [
                      'proveedor_id' => (int) $p['id'],
                      'nombre'       => $p['nombre'],
                      'balance'      => (float) $p['balance'],
                      'balance_txt'  => money((float) $p['balance']),
                      'facturas'     => $facturas[(int) $p['id']] ?? [],
                  ]) ?>" class="btn btn-soft btn-sm"><?= icon('cash', 'w-3.5 h-3.5') ?> Pagar</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="bg-slate-50 font-bold">
            <td colspan="5" class="text-right text-slate-600">Total adeudado</td>
            <td class="text-right text-rose-600"><?= e(money($resumen['total'])) ?></td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php if (can('cxp.pagar')): ?>
<!-- Modal de pago -->
<div x-data="pagoProveedor()"
     @pago:new.window="abrir($event.detail)"
     @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-lg" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="pagar">
        <input type="hidden" name="proveedor_id" :value="f.proveedor_id">

        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800">Registrar pago</h3>
          <button type="button" @click="open=false" aria-label="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
        </div>

        <div class="p-6 space-y-4 max-h-[70vh] overflow-y-auto">
          <div class="rounded-xl bg-slate-50 p-3">
            <p class="font-semibold text-slate-700" x-text="f.nombre"></p>
            <p class="text-sm text-rose-600 font-semibold">Deuda: <span x-text="f.balance_txt"></span></p>
          </div>

          <div>
            <label class="label">¿Qué factura pagas?</label>
            <select name="compra_id" x-model="f.compra_id" @change="alSeleccionarFactura()" class="select">
              <option value="0">Abonar a la cuenta (salda las más viejas primero)</option>
              <template x-for="fa in f.facturas" :key="fa.id">
                <option :value="fa.id" x-text="`${fa.numero} · ${fa.fecha} · ${fa.saldo_txt}` + (fa.moneda ? ' ('+fa.moneda+')' : '')"></option>
              </template>
            </select>
          </div>

          <?php if (mon_hayExtranjeras()): ?>
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="label">Moneda del pago</label>
                <select name="moneda_id" x-model.number="f.moneda_id" @change="alCambiarMoneda()" class="select">
                  <?php foreach ($monedasAct as $m): ?>
                    <option value="<?= (int) $m['id'] ?>" data-tasa="<?= e((string) $m['tasa']) ?>" data-base="<?= (int) $m['es_base'] ?>">
                      <?= e($m['codigo']) ?> — <?= e($m['nombre']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div x-show="!esBase()">
                <label class="label">Tasa de cambio</label>
                <input type="number" step="0.0001" min="0.0001" name="tasa" x-model.number="f.tasa" class="input">
                <p class="text-xs text-slate-400 mt-1">Pesos por 1 unidad.</p>
              </div>
            </div>
          <?php else: ?>
            <input type="hidden" name="moneda_id" value="<?= (int) monedaBase()['id'] ?>">
            <input type="hidden" name="tasa" value="1">
          <?php endif; ?>

          <div>
            <label class="label">Monto del pago *</label>
            <input type="number" step="0.01" min="0.01" name="monto" x-model.number="f.monto" required
                   class="input text-lg font-bold">
            <p class="text-xs text-slate-500 mt-1" x-show="!esBase()">
              Equivale a <span class="font-semibold" x-text="enPesos()"></span> a la tasa indicada.
            </p>
          </div>

          <div class="grid sm:grid-cols-2 gap-4">
            <div>
              <label class="label">Forma de pago *</label>
              <select name="metodo_pago_id" required class="select">
                <?php foreach ($metodos as $m): ?>
                  <option value="<?= (int) $m['id'] ?>"><?= e($m['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="label">Referencia</label>
              <input type="text" name="referencia" maxlength="60" class="input" placeholder="N.º de cheque o transferencia">
            </div>
          </div>

          <div>
            <label class="label">Notas</label>
            <input type="text" name="notas" maxlength="255" class="input" placeholder="Opcional">
          </div>

          <p class="text-xs text-slate-400">
            El dinero se descuenta de la cuenta al guardar. Si pagas en efectivo y tienes la caja abierta,
            también se registra en el cuadre del día.
          </p>
        </div>

        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button type="submit" class="btn btn-primary"><?= icon('cash', 'w-4 h-4') ?> Registrar pago</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
  function pagoProveedor() {
    return {
      open: false,
      f: { proveedor_id: 0, nombre: '', balance: 0, balance_txt: '', facturas: [], compra_id: '0', moneda_id: <?= (int) monedaBase()['id'] ?>, tasa: 1, monto: 0 },
      base: <?= (int) monedaBase()['id'] ?>,

      abrir(d) {
        this.f = Object.assign({ compra_id: '0', moneda_id: this.base, tasa: 1, monto: d.balance }, d);
        this.open = true;
      },
      esBase() { return Number(this.f.moneda_id) === this.base; },
      alCambiarMoneda() {
        const op = document.querySelector(`select[name=moneda_id] option[value="${this.f.moneda_id}"]`);
        this.f.tasa = op ? Number(op.dataset.tasa) : 1;
        this.alSeleccionarFactura();
      },
      // Al elegir una factura, se propone exactamente su saldo.
      alSeleccionarFactura() {
        const id = Number(this.f.compra_id);
        const fa = this.f.facturas.find(x => x.id === id);
        const enPesos = fa ? fa.saldo : this.f.balance;
        this.f.monto = this.esBase() ? enPesos : Math.round((enPesos / (this.f.tasa || 1)) * 100) / 100;
      },
      enPesos() {
        const v = (Number(this.f.monto) || 0) * (Number(this.f.tasa) || 1);
        return '<?= e(setting('moneda', 'RD$')) ?> ' + v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      }
    };
  }
</script>
<?php endif; ?>

<?php layout_end(); ?>
