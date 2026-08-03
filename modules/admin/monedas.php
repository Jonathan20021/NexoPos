<?php
/**
 * Monedas y tasa de cambio.
 *
 * La tasa se escribe a mano a propósito: no hay ninguna API oficial gratuita del
 * Banco Central que se pueda consultar sin permiso, y una tasa sacada de una web
 * cualquiera puede descuadrar una contabilidad. Quien la teclea sabe a qué tasa
 * está comprando de verdad.
 *
 * Cambiar la tasa NO reescribe el pasado: cada compra y cada cotización guardan
 * la tasa con la que se hicieron. Lo que se cambia aquí es la que se propone en
 * los documentos nuevos.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('monedas.gestionar');

if (!mon_disponible()) {
    layout_start('Monedas', 'Falta aplicar la migración');
    echo '<div class="card p-8 text-center">' . icon('alert', 'w-10 h-10 text-amber-500 mx-auto mb-3')
       . '<h3 class="font-bold text-slate-700">Módulo no disponible todavía</h3>'
       . '<p class="text-sm text-slate-500 mt-1">Aplica <code class="bg-slate-100 px-1.5 py-0.5 rounded">database/migracion_cxp_monedas_cotizaciones_p11.sql</code>.</p></div>';
    layout_end();
    exit;
}

if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    if ($accion === 'tasa') {
        $id   = postInt('id');
        $tasa = postNum('tasa');
        $m    = moneda($id);
        try {
            if ((int) $m['es_base'] === 1) throw new RuntimeException('La moneda base siempre vale 1: es la referencia de todas las demás.');
            if ($tasa <= 0) throw new RuntimeException('La tasa debe ser mayor que cero.');
            if ($tasa > 10000) throw new RuntimeException('Esa tasa no parece correcta. Revisa el número.');

            $anterior = (float) $m['tasa'];
            mon_actualizarTasa($id, $tasa);
            audit('monedas', 'editar', "Tasa de {$m['codigo']}: " . number_format($anterior, 4) . ' → ' . number_format($tasa, 4),
                  ['tabla' => 'monedas', 'registro_id' => $id]);
            flash('success', 'Tasa de ' . $m['codigo'] . ' actualizada a ' . number_format($tasa, 2) . '.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/admin/monedas.php');
    }

    if ($accion === 'activar') {
        $id = postInt('id');
        $m  = moneda($id);
        if ((int) $m['es_base'] === 1) {
            flash('error', 'La moneda base no se puede desactivar.');
        } else {
            dbUpdate('monedas', ['activo' => postInt('activo')], 'id = ?', [$id]);
            flash('success', $m['codigo'] . (postInt('activo') ? ' activada.' : ' desactivada.'));
        }
        redirect('modules/admin/monedas.php');
    }
}

$todas = monedas(false);
$base  = monedaBase();

// Dónde se está usando cada moneda: desactivar una que ya tiene documentos
// confundiría al que abra esos papeles.
$usos = [];
if (cxp_disponible()) {
    foreach (qAll("SELECT moneda_id, COUNT(*) n FROM compras WHERE moneda_id IS NOT NULL GROUP BY moneda_id") as $r) {
        $usos[(int) $r['moneda_id']]['compras'] = (int) $r['n'];
    }
}
if (cot_disponible()) {
    foreach (qAll("SELECT moneda_id, COUNT(*) n FROM cotizaciones WHERE moneda_id IS NOT NULL GROUP BY moneda_id") as $r) {
        $usos[(int) $r['moneda_id']]['cotizaciones'] = (int) $r['n'];
    }
}

layout_start('Monedas y tasa de cambio', 'La referencia con la que se convierte a ' . e($base['simbolo']));
?>

<div class="card p-4 mb-5 flex items-start gap-3 bg-sky-50 border-sky-100">
  <?= icon('alert', 'w-5 h-5 text-sky-500 mt-0.5 shrink-0') ?>
  <div class="text-sm text-sky-800">
    <p class="font-semibold">Tu contabilidad sigue siendo en <?= e($base['simbolo']) ?>.</p>
    <p>
      Reportes, DGII e IT-1 no cambian: todo se guarda convertido a pesos. Lo que permite esta pantalla es
      <strong>registrar lo que pactaste en otra moneda</strong> y a qué tasa, para saber cuánto debes de verdad
      en dólares y calcular la diferencia cambiaria cuando pagues.
    </p>
  </div>
</div>

<div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
  <?php foreach ($todas as $m):
    $esBase = (int) $m['es_base'] === 1;
    $activa = (int) $m['activo'] === 1;
    $uso    = $usos[(int) $m['id']] ?? [];
  ?>
    <div class="card p-5 <?= $esBase ? 'ring-1 ring-blue-200' : '' ?>">
      <div class="flex items-start justify-between mb-4">
        <div>
          <div class="flex items-center gap-2">
            <span class="text-2xl font-bold text-slate-800"><?= e($m['simbolo']) ?></span>
            <span class="text-sm font-bold text-slate-500"><?= e($m['codigo']) ?></span>
          </div>
          <p class="text-xs text-slate-400 mt-0.5"><?= e($m['nombre']) ?></p>
        </div>
        <?php if ($esBase): ?><?= badge('Moneda base', 'blue') ?>
        <?php else: ?><?= $activa ? badge('Activa', 'emerald') : badge('Inactiva', 'slate') ?><?php endif; ?>
      </div>

      <?php if ($esBase): ?>
        <div class="rounded-xl bg-blue-50 p-4 text-center">
          <p class="text-2xl font-bold text-blue-700">1.00</p>
          <p class="text-xs text-blue-600 mt-1">Es la referencia. No se cambia.</p>
        </div>
      <?php else: ?>
        <form method="post" class="space-y-3">
          <?= csrf_field() ?>
          <input type="hidden" name="accion" value="tasa">
          <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
          <div>
            <label class="label">1 <?= e($m['codigo']) ?> equivale a</label>
            <div class="flex items-center gap-2">
              <span class="text-sm font-semibold text-slate-500 shrink-0"><?= e($base['simbolo']) ?></span>
              <input type="number" step="0.0001" min="0.0001" name="tasa"
                     value="<?= e(rtrim(rtrim(number_format((float) $m['tasa'], 4, '.', ''), '0'), '.')) ?>"
                     class="input text-lg font-bold">
            </div>
          </div>
          <button class="btn btn-primary w-full btn-sm"><?= icon('save', 'w-3.5 h-3.5') ?> Guardar tasa</button>
        </form>

        <p class="text-xs text-slate-400 mt-3">
          Actualizada <?= e(tiempoRelativo($m['updated_at'])) ?>.
        </p>

        <?php if ($uso): ?>
          <p class="text-xs text-slate-500 mt-2">
            En uso:
            <?= isset($uso['compras']) ? $uso['compras'] . ' compra(s)' : '' ?>
            <?= isset($uso['compras'], $uso['cotizaciones']) ? ' · ' : '' ?>
            <?= isset($uso['cotizaciones']) ? $uso['cotizaciones'] . ' cotización(es)' : '' ?>
          </p>
        <?php endif; ?>

        <form method="post" class="mt-3">
          <?= csrf_field() ?>
          <input type="hidden" name="accion" value="activar">
          <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
          <input type="hidden" name="activo" value="<?= $activa ? 0 : 1 ?>">
          <button class="text-xs font-semibold <?= $activa ? 'text-slate-400 hover:text-rose-600' : 'text-emerald-600 hover:text-emerald-700' ?>">
            <?= $activa ? 'Desactivar' : 'Activar' ?>
          </button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<div class="card p-5 mt-5">
  <h3 class="font-bold text-slate-800 mb-2">Cómo se usa la tasa</h3>
  <ul class="text-sm text-slate-500 space-y-2 list-disc pl-4">
    <li>Al registrar una <strong>compra en dólares</strong>, se guarda el importe en dólares y su equivalente en pesos a la tasa de ese día. Es lo que entra al inventario y a la DGII.</li>
    <li>Al <strong>pagar esa compra</strong> más adelante, si el dólar cambió, la diferencia se registra sola como pérdida o ganancia cambiaria.</li>
    <li>En una <strong>cotización en dólares</strong>, la tasa queda fija en el documento: es el precio que se le prometió al cliente, y se respeta al facturar.</li>
    <li>Cambiar la tasa aquí <strong>no altera</strong> ningún documento ya emitido.</li>
  </ul>
</div>

<?php layout_end(); ?>
