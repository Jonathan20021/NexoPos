<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('pos.terminales');

// ---------- Acciones ----------
if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    if ($accion === 'renombrar') {
        $id = postInt('id');
        $nombre = trim(post('nombre'));
        if ($id > 0 && $nombre !== '') {
            dbUpdate('pos_terminales', ['nombre' => $nombre], 'id = ?', [$id]);
            audit('pos', 'terminales', "Terminal renombrado: $nombre", ['tabla' => 'pos_terminales', 'registro_id' => $id]);
            flash('success', 'Terminal renombrado.');
        }
    } elseif ($accion === 'estado') {
        $id = postInt('id');
        $activo = postInt('activo');
        if ($id > 0) {
            dbUpdate('pos_terminales', ['activo' => $activo], 'id = ?', [$id]);
            flash('success', $activo ? 'Terminal reactivado.' : 'Terminal desactivado.');
        }
    } elseif ($accion === 'devolver') {
        $reservaId = postInt('reserva_id');
        if ($reservaId > 0) {
            $r = devolverReserva($reservaId);
            if ($r['devueltos'] > 0) {
                flash('success', "Se devolvieron {$r['devueltos']} NCF a la secuencia general.");
            } elseif ($r['huecos'] > 0) {
                flash('warning', "El bloque se cerró. {$r['huecos']} NCF no usados quedan como hueco en la secuencia (la DGII lo admite; se reportan al contador).");
            } else {
                flash('info', 'El bloque ya estaba totalmente emitido; se cerró sin huecos.');
            }
            audit('pos', 'terminales', "Reserva de NCF devuelta (#$reservaId)", ['tabla' => 'ncf_reservas', 'registro_id' => $reservaId]);
        }
    }
    redirect(url('modules/pos/terminales.php'));
}

// ---------- Datos ----------
$terminales = terminalesResumen();
$totActivos = count(array_filter($terminales, fn($t) => (int) $t['activo'] === 1));

layout_start('Terminales offline', 'Dispositivos del POS y sus reservas de NCF para vender sin conexión');
?>

<div class="mb-4 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-800 flex gap-3">
  <?= icon('bell', 'w-5 h-5 shrink-0 mt-0.5') ?>
  <div>
    Cada terminal reserva por adelantado rangos de NCF (mientras hay internet) para poder imprimir el
    <strong>comprobante fiscal definitivo</strong> aunque se caiga la conexión. Los números reservados se
    tallan de la secuencia general en <strong>Configuración → Comprobantes</strong>; la secuencia salta por
    encima, así que online y offline nunca se solapan. Un bloque cerrado sin agotar deja huecos en la
    secuencia (la DGII los admite): usa <strong>Devolver</strong> para recuperar los no usados cuando se pueda.
  </div>
</div>

<?php if (!$terminales): ?>
  <?= empty_state('Aún no hay terminales', 'Un dispositivo se registra solo la primera vez que abre el Punto de Venta con conexión.', 'cart') ?>
<?php else: ?>
  <div class="mb-3 text-sm text-slate-400"><?= count($terminales) ?> terminal(es) · <?= $totActivos ?> activo(s)</div>

  <div class="grid grid-cols-1 xl:grid-cols-2 gap-4" x-data="{ editando: 0 }">
    <?php foreach ($terminales as $t): ?>
      <?php
        $activo = (int) $t['activo'] === 1;
        $reservasActivas = array_filter($t['reservas'], fn($r) => $r['estado'] === 'activa');
      ?>
      <div class="card p-5">
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <div class="flex items-center gap-2">
              <span class="w-9 h-9 rounded-xl bg-slate-100 text-slate-500 inline-flex items-center justify-center shrink-0"><?= icon('cart', 'w-5 h-5') ?></span>
              <div class="min-w-0">
                <p class="font-bold text-slate-800 truncate"><?= e($t['nombre']) ?></p>
                <p class="text-xs text-slate-400 truncate"><?= e($t['sucursal'] ?? 'Sin sucursal') ?> · visto <?= $t['ultimo_visto'] ? e(fechaHora($t['ultimo_visto'])) : 'nunca' ?></p>
              </div>
            </div>
          </div>
          <?= $activo ? badge('Activo', 'emerald') : badge('Inactivo', 'slate') ?>
        </div>

        <!-- NCF disponibles para offline -->
        <div class="grid grid-cols-2 gap-3 mt-4">
          <div class="rounded-xl border border-slate-100 bg-slate-50 p-3 text-center">
            <p class="text-2xl font-extrabold text-slate-800"><?= (int) $t['disp_b02'] ?></p>
            <p class="text-xs text-slate-500 font-semibold">NCF B02 disponibles</p>
          </div>
          <div class="rounded-xl border border-slate-100 bg-slate-50 p-3 text-center">
            <p class="text-2xl font-extrabold text-slate-800"><?= (int) $t['disp_b01'] ?></p>
            <p class="text-xs text-slate-500 font-semibold">NCF B01 disponibles</p>
          </div>
        </div>

        <!-- Reservas (rangos) -->
        <?php if ($t['reservas']): ?>
          <div class="mt-4 overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="text-left text-xs text-slate-400 border-b border-slate-100">
                  <th class="py-1.5 font-semibold">Tipo</th>
                  <th class="py-1.5 font-semibold">Rango</th>
                  <th class="py-1.5 font-semibold text-center">Emitidos</th>
                  <th class="py-1.5 font-semibold text-center">Disp.</th>
                  <th class="py-1.5 font-semibold">Estado</th>
                  <th class="py-1.5"></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($t['reservas'] as $r): ?>
                  <tr class="border-b border-slate-50">
                    <td class="py-2 font-semibold text-slate-600"><?= e($r['tipo']) ?></td>
                    <td class="py-2 text-slate-500 whitespace-nowrap font-mono text-xs">
                      <?= e(ncfFormatear($r['tipo'], (int) $r['secuencia_desde'])) ?>–<?= e(str_pad((string) $r['secuencia_hasta'], 8, '0', STR_PAD_LEFT)) ?>
                    </td>
                    <td class="py-2 text-center text-slate-600"><?= (int) $r['emitidos'] ?>/<?= (int) $r['total'] ?></td>
                    <td class="py-2 text-center font-semibold text-slate-700"><?= (int) $r['disponibles'] ?></td>
                    <td class="py-2"><?= $r['estado'] === 'activa' ? badge('Activa', 'sky') : ($r['estado'] === 'devuelta' ? badge('Devuelta', 'slate') : badge('Vencida', 'amber')) ?></td>
                    <td class="py-2 text-right">
                      <?php if ($r['estado'] === 'activa' && (int) $r['disponibles'] > 0): ?>
                        <form method="post" onsubmit="return confirm('¿Cerrar este bloque y devolver los NCF no usados? Los que no se puedan devolver quedarán como hueco en la secuencia.');" class="inline">
                          <?= csrf_field() ?>
                          <input type="hidden" name="accion" value="devolver">
                          <input type="hidden" name="reserva_id" value="<?= (int) $r['id'] ?>">
                          <button class="text-xs font-semibold text-rose-600 hover:text-rose-700 underline">Devolver</button>
                        </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <p class="mt-4 text-sm text-slate-400">Sin reservas todavía. Se crean solas cuando el terminal abre el POS con conexión.</p>
        <?php endif; ?>

        <!-- Acciones del terminal -->
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center gap-2 flex-wrap">
          <div x-show="editando !== <?= (int) $t['id'] ?>" class="flex items-center gap-2">
            <button type="button" @click="editando = <?= (int) $t['id'] ?>" class="text-xs font-semibold text-slate-500 hover:text-slate-700 underline">Renombrar</button>
            <form method="post" class="inline">
              <?= csrf_field() ?>
              <input type="hidden" name="accion" value="estado">
              <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
              <input type="hidden" name="activo" value="<?= $activo ? 0 : 1 ?>">
              <button class="text-xs font-semibold <?= $activo ? 'text-rose-600 hover:text-rose-700' : 'text-emerald-600 hover:text-emerald-700' ?> underline"><?= $activo ? 'Desactivar' : 'Reactivar' ?></button>
            </form>
          </div>
          <form method="post" x-show="editando === <?= (int) $t['id'] ?>" class="flex items-center gap-2 w-full" style="display:none">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="renombrar">
            <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
            <input type="text" name="nombre" value="<?= e($t['nombre']) ?>" maxlength="80" class="input flex-1" placeholder="Nombre del terminal">
            <button class="btn btn-primary btn-sm">Guardar</button>
            <button type="button" @click="editando = 0" class="btn btn-ghost btn-sm">Cancelar</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php layout_end(); ?>
