<?php
/**
 * Regalía pascual — el salario de Navidad, año por año.
 *
 * Se paga a más tardar el 20 de diciembre y es una duodécima parte del salario
 * ordinario devengado en el año. No paga ISR ni cotiza a la TSS. El cálculo, el
 * criterio de qué entra en «salario ordinario» y el porqué de cada peso están
 * en includes/regalia.php.
 *
 * Esta pantalla hace tres cosas y nada más: enseñar el cuadro con su origen
 * (cuánto salió de nóminas reales y cuánto se completó con el padrón), dejar
 * corregir a mano el monto de cada persona, y convertirlo en una nómina de tipo
 * `regalia` para que se pague, se exporte al banco y lleve volante como
 * cualquier otra. Confirmar y pagar se hacen desde la pantalla de Nómina.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('rrhh_nomina.ver');

$anio = (int) (get('anio') ?: date('Y'));
if ($anio < 2000 || $anio > (int) date('Y') + 1) $anio = (int) date('Y');
// get() devuelve '' cuando el parámetro no viene, no null: solo un '0'
// explícito apaga el relleno. Comparándolo contra null la pantalla arrancaba
// siempre en «dejarlos en cero» y enseñaba media regalía.
$completar = get('completar') !== '0';
$volver = 'modules/rrhh/regalia.php?anio=' . $anio . '&completar=' . ($completar ? '1' : '0');

/* ============================================================
 *  Acciones
 * ============================================================ */
if (isPost()) {
    verify_csrf();
    require_perm('rrhh_nomina.procesar');
    $accion = post('accion');
    $anioP  = (int) postInt('anio') ?: $anio;

    try {
        if ($accion === 'generar' || $accion === 'guardar') {
            $montos = $_POST['regalia'] ?? [];
            if (!is_array($montos)) $montos = [];
            $montos = array_filter($montos, fn($v) => trim((string) $v) !== '' && (float) $v > 0);
            if (!$montos) throw new RuntimeException('No hay ningún monto que registrar.');

            $nid = txReintentable(function () use ($montos, $anioP, $accion) {
                $ya = regaliaNominaDelAnio($anioP);

                if ($accion === 'generar') {
                    if ($ya) throw new RuntimeException('La regalía de ' . $anioP . ' ya está generada.');
                    // El período de la regalía es el año entero: de eso se
                    // devenga. La fecha tope de pago es el 20 de diciembre, pero
                    // eso es cuándo se paga, no qué período cubre.
                    $nid = dbInsert('nominas', [
                        'sucursal_id' => null,
                        'descripcion' => 'Regalía pascual ' . $anioP,
                        'tipo'        => 'regalia',
                        'fecha_desde' => sprintf('%04d-01-01', $anioP),
                        'fecha_hasta' => sprintf('%04d-12-31', $anioP),
                        'estado'      => 'borrador',
                        'usuario_id'  => current_user()['id'] ?? null,
                    ]);
                } else {
                    if (!$ya) throw new RuntimeException('Todavía no se ha generado la regalía de ' . $anioP . '.');
                    if ($ya['estado'] !== 'borrador') throw new RuntimeException('La regalía ya está confirmada: no se puede cambiar.');
                    $nid = (int) $ya['id'];
                    q("DELETE FROM nomina_detalles WHERE nomina_id = ?", [$nid]);
                }

                foreach ($montos as $eid => $monto) {
                    $eid = (int) $eid;
                    $monto = round((float) $monto, 2);
                    if ($eid <= 0 || $monto <= 0) continue;
                    // Todo lo que no sea el monto va en cero A PROPÓSITO: la
                    // regalía no cotiza a la TSS ni paga ISR (arts. 219-222).
                    dbInsert('nomina_detalles', [
                        'nomina_id' => $nid, 'empleado_id' => $eid,
                        'salario_base' => $monto, 'dias_base' => 0, 'dias_trabajados' => 0,
                        'horas_extra' => 0, 'monto_horas_extra' => 0, 'bonificaciones' => 0,
                        'comisiones' => 0, 'otros_ingresos' => 0, 'prima_vacacional' => 0,
                        'reembolso' => 0, 'vacaciones_diferencial' => 0, 'descuento_dias' => 0,
                        'per_capita' => 0,
                        'total_ingresos' => $monto,
                        'afp' => 0, 'sfs' => 0, 'isr' => 0, 'otras_deducciones' => 0,
                        'total_deducciones' => 0, 'salario_neto' => $monto,
                    ]);
                }
                nominaRecalcularTotales($nid);
                return $nid;
            });

            audit('rrhh_nomina', $accion === 'generar' ? 'regalia_generar' : 'regalia_guardar',
                'Regalía pascual ' . $anioP . ': ' . count($montos) . ' empleado(s)',
                ['tabla' => 'nominas', 'registro_id' => $nid]);
            flash('success', $accion === 'generar'
                ? 'Regalía de ' . $anioP . ' generada para ' . count($montos) . ' persona(s). '
                  . 'Revísala y confírmala desde Nómina; ahí también sale el archivo del banco y los volantes.'
                : 'Montos actualizados.');
            redirect($volver);
        }

        if ($accion === 'eliminar') {
            $ya = regaliaNominaDelAnio($anioP);
            if (!$ya) throw new RuntimeException('No hay regalía generada para ' . $anioP . '.');
            if ($ya['estado'] !== 'borrador') throw new RuntimeException('Solo se puede eliminar un borrador.');
            txReintentable(function () use ($ya) {
                q("DELETE FROM nomina_detalles WHERE nomina_id = ?", [(int) $ya['id']]);
                q("DELETE FROM nominas WHERE id = ?", [(int) $ya['id']]);
            });
            audit('rrhh_nomina', 'regalia_eliminar', 'Regalía pascual ' . $anioP . ' eliminada (borrador)');
            flash('success', 'Borrador de regalía eliminado. Puedes volver a generarlo.');
            redirect($volver);
        }
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage());
        redirect($volver);
    }
}

/* ============================================================
 *  Datos
 * ============================================================ */
$r        = regaliaCalcular($anio, $completar);
$nomina   = regaliaNominaDelAnio($anio);
$guardado = [];
if ($nomina) {
    foreach (qAll("SELECT empleado_id, salario_neto FROM nomina_detalles WHERE nomina_id = ?", [(int) $nomina['id']]) as $d) {
        $guardado[(int) $d['empleado_id']] = (float) $d['salario_neto'];
    }
}
$sospechosas = regaliaIngresosSospechosos($anio);
$editable    = (!$nomina || $nomina['estado'] === 'borrador') && can('rrhh_nomina.procesar');
$totalMostrar = $nomina ? array_sum($guardado) : $r['totales']['regalia'];

$acciones = '';
if ($nomina) {
    $acciones .= '<a href="' . e(url('modules/rrhh/nomina.php?ver=' . (int) $nomina['id'])) . '" class="btn btn-ghost">'
        . icon('wallet', 'w-4 h-4') . ' Ver la nómina</a>'
        . '<a href="' . e(url('modules/rrhh/volante.php?nomina=' . (int) $nomina['id'])) . '" target="_blank" class="btn btn-ghost">'
        . icon('receipt', 'w-4 h-4') . ' Volantes</a>';
}

layout_start('Regalía pascual ' . $anio,
    'Salario de Navidad · una duodécima parte del salario ordinario del año (arts. 219-222)',
    $acciones);
?>

<form method="get" class="card p-4 mb-5 flex items-end gap-3 flex-wrap no-print">
  <div>
    <label class="label" for="anio">Año</label>
    <select id="anio" name="anio" class="select">
      <?php for ($a = (int) date('Y') + 1; $a >= (int) date('Y') - 3; $a--): ?>
        <option value="<?= $a ?>" <?= $anio === $a ? 'selected' : '' ?>><?= $a ?></option>
      <?php endfor; ?>
    </select>
  </div>
  <div>
    <label class="label" for="completar">Los días sin nómina en el sistema</label>
    <select id="completar" name="completar" class="select min-w-[22rem]">
      <option value="1" <?= $completar ? 'selected' : '' ?>>Completarlos con el sueldo del padrón</option>
      <option value="0" <?= $completar ? '' : 'selected' ?>>Dejarlos en cero (solo lo que hay en nóminas)</option>
    </select>
  </div>
  <button class="btn btn-primary"><?= icon('filter', 'w-4 h-4') ?> Recalcular</button>
</form>

<?php if ($sospechosas): ?>
  <div class="card p-4 mb-5 flex items-start gap-3 bg-rose-50 border-rose-200">
    <?= icon('alert', 'w-5 h-5 text-rose-600 mt-0.5 shrink-0') ?>
    <div class="text-sm text-rose-900">
      <?php foreach ($sospechosas as $s): ?>
        <p><strong><?= number_format($s['empleados']) ?> empleados tienen exactamente la misma fecha de ingreso
        (<?= e(fechaCorta($s['fecha'])) ?>).</strong></p>
      <?php endforeach; ?>
      <p class="mt-1 text-rose-800">
        Casi seguro es la fecha con la que se cargó el padrón, no la real de cada persona. La regalía se devenga
        <strong>desde el día que entró</strong>: con una fecha de mitad de año, a quien lleva cinco años se le
        calcularía media regalía. Corrige las fechas de ingreso en la ficha de cada empleado antes de generar.
      </p>
    </div>
  </div>
<?php endif; ?>

<?php
$dias = $r['dias_para_tope'];
$colorTope = $dias < 0 ? 'rose' : ($dias <= 15 ? 'rose' : ($dias <= 45 ? 'amber' : 'slate'));
echo kpis([
    ['label' => 'Personas con derecho', 'valor' => number_format(count($r['filas'])),
     'icono' => 'users', 'color' => 'blue',
     'nota' => 'Activos y quienes salieron durante ' . $anio],
    ['label' => 'Salario ordinario del año', 'valor' => money($r['totales']['devengado']),
     'icono' => 'coins', 'color' => 'indigo',
     'nota' => 'Hasta ' . fechaCorta($r['corte'])],
    ['label' => 'Regalía a pagar', 'valor' => money($totalMostrar),
     'icono' => 'cash', 'color' => 'emerald',
     'nota' => $nomina ? 'Según la nómina generada' : 'Una duodécima parte del devengado'],
    ['label' => $dias < 0 ? 'El plazo venció' : 'Para el 20 de diciembre',
     'valor' => $dias < 0 ? 'Vencido' : number_format(max(0, $dias)) . ' días',
     'icono' => 'calendar', 'color' => $colorTope,
     'nota' => $dias < 0 ? 'La ley obliga a pagarla antes del 20/12' : 'Fecha tope de pago (art. 219)'],
], 4);
?>

<?php if ($r['totales']['de_padron'] > 0): ?>
  <div class="card p-4 mb-5 flex items-start gap-3 bg-amber-50 border-amber-200">
    <?= icon('alert', 'w-5 h-5 text-amber-600 mt-0.5 shrink-0') ?>
    <div class="text-sm text-amber-900">
      <strong><?= money($r['totales']['de_padron']) ?> del devengado no salió de una nómina de este sistema.</strong>
      <p class="mt-1 text-amber-800">
        Son <?= number_format($r['totales']['dias_padron']) ?> día(s) que ninguna nómina cubre —porque la nómina de
        ese tramo se llevó fuera del sistema— y se completaron con el sueldo actual del padrón, prorrateado.
        Si en ese tramo alguien ganaba otro sueldo, corrige su monto en la tabla antes de generar.
      </p>
    </div>
  </div>
<?php endif; ?>

<?php if ($nomina): ?>
  <div class="card p-4 mb-5 flex items-start gap-3 <?= $nomina['estado'] === 'pagada' ? 'bg-emerald-50 border-emerald-200' : 'bg-blue-50 border-blue-200' ?>">
    <?= icon($nomina['estado'] === 'pagada' ? 'check' : 'wallet', 'w-5 h-5 mt-0.5 shrink-0 ' . ($nomina['estado'] === 'pagada' ? 'text-emerald-600' : 'text-blue-600')) ?>
    <p class="text-sm <?= $nomina['estado'] === 'pagada' ? 'text-emerald-900' : 'text-blue-900' ?>">
      La regalía de <?= $anio ?> ya está generada como nómina
      <strong><?= e($nomina['descripcion']) ?></strong> · <?= badgeFor($nomina['estado']) ?>
      por <strong><?= money($nomina['total_neto']) ?></strong>.
      <?= $nomina['estado'] === 'borrador'
          ? 'Se confirma y se paga desde la pantalla de Nómina, igual que una quincena.'
          : 'Ya no se puede modificar desde aquí.' ?>
    </p>
  </div>
<?php endif; ?>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="anio" value="<?= $anio ?>">

  <div class="card overflow-hidden mb-5">
    <?= toolbar(
        '<h3 class="font-bold text-slate-800">Cuánto le toca a cada quien</h3>'
        . '<p class="text-xs text-slate-400 mt-0.5">Del 1 de enero al ' . e(fechaCorta($r['corte'])) . '</p>',
        toolbar_conteo(count($r['filas']), 'persona')
    ) ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead>
          <tr>
            <th>Empleado</th>
            <th>Desde cuándo se le devenga</th>
            <th class="text-right">De nóminas</th>
            <th class="text-right">Completado</th>
            <th class="text-right">Salario ordinario</th>
            <th class="text-right">Regalía</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$r['filas']): ?>
            <tr><td colspan="6" class="text-center text-slate-400 py-8">
              Nadie tiene salario devengado en <?= $anio ?>.
            </td></tr>
          <?php endif; ?>
          <?php foreach ($r['filas'] as $f):
              $eid = (int) $f['empleado_id'];
              $valor = $guardado[$eid] ?? $f['regalia']; ?>
            <tr>
              <td class="whitespace-nowrap">
                <div class="flex items-center gap-2">
                  <?= avatar($f['nombre'], 'w-8 h-8 shrink-0') ?>
                  <div>
                    <p class="font-semibold text-slate-700"><?= e($f['nombre']) ?></p>
                    <p class="text-xs text-slate-400">
                      <?= e($f['cedula'] ?: '—') ?> · <?= e($f['grupo']) ?>
                      <?php if ($f['estado'] !== 'activo'): ?>
                        <span class="badge badge-slate ml-1">salió</span>
                      <?php endif; ?>
                    </p>
                  </div>
                </div>
              </td>
              <td class="text-sm text-slate-600 whitespace-nowrap">
                <?= e(fechaCorta($f['ventana'][0])) ?> al <?= e(fechaCorta($f['ventana'][1])) ?>
                <span class="block text-xs text-slate-400"><?= number_format($f['dias_ventana']) ?> día(s)</span>
              </td>
              <td class="text-right text-slate-600 tabular-nums">
                <?= money($f['de_nomina'], false) ?>
                <span class="block text-xs text-slate-400"><?= number_format($f['dias_nomina']) ?> día(s)</span>
              </td>
              <td class="text-right tabular-nums <?= $f['de_padron'] > 0 ? 'text-amber-700' : 'text-slate-300' ?>">
                <?= $f['de_padron'] > 0 ? money($f['de_padron'], false) : '—' ?>
                <?php if ($f['dias_padron'] > 0): ?>
                  <span class="block text-xs text-amber-600"><?= number_format($f['dias_padron']) ?> día(s) del padrón</span>
                <?php endif; ?>
              </td>
              <td class="text-right font-medium text-slate-700 tabular-nums"><?= money($f['devengado'], false) ?></td>
              <td class="text-right">
                <?php if ($editable): ?>
                  <input type="number" step="0.01" min="0" name="regalia[<?= $eid ?>]"
                         value="<?= e(number_format($valor, 2, '.', '')) ?>"
                         class="w-28 px-2 py-1 rounded-lg border border-slate-200 text-sm text-right
                                focus:border-blue-400 focus:ring-2 focus:ring-blue-100 focus:outline-none">
                <?php else: ?>
                  <span class="font-bold text-emerald-600 tabular-nums"><?= money($valor) ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <?php if ($r['filas']): ?>
          <tfoot>
            <tr class="bg-slate-50 font-bold text-slate-800">
              <td colspan="4">Total</td>
              <td class="text-right tabular-nums"><?= money($r['totales']['devengado'], false) ?></td>
              <td class="text-right tabular-nums"><?= money($totalMostrar, false) ?></td>
            </tr>
          </tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>

  <?php if ($editable && $r['filas']): ?>
    <div class="flex flex-wrap items-center gap-2 mb-5 no-print">
      <?php if (!$nomina): ?>
        <?php $av = 'Se generará la nómina de regalía ' . $anio . ' por ' . money($r['totales']['regalia'], false)
                  . ' para ' . count($r['filas']) . ' persona(s). Queda en borrador: se confirma y se paga desde Nómina.'; ?>
        <button name="accion" value="generar" class="btn btn-primary"
                onclick="return confirm('<?= e(addslashes($av)) ?>')">
          <?= icon('check', 'w-4 h-4') ?> Generar la nómina de regalía
        </button>
      <?php else: ?>
        <button name="accion" value="guardar" class="btn btn-primary">
          <?= icon('save', 'w-4 h-4') ?> Guardar los montos
        </button>
        <?php $avE = 'Se eliminará el borrador de regalía ' . $anio . ' con sus ' . count($guardado) . ' línea(s).'; ?>
        <button name="accion" value="eliminar" class="btn btn-ghost"
                onclick="return confirm('<?= e(addslashes($avE)) ?>')">
          <?= icon('trash', 'w-4 h-4') ?> Eliminar el borrador
        </button>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</form>

<div class="card p-5">
  <h3 class="font-bold text-slate-800 mb-2">Qué entra y qué no en el «salario ordinario»</h3>
  <p class="text-sm text-slate-500 mb-3">
    El art. 219 manda pagar una duodécima parte del <strong>salario ordinario</strong> del año, y el art. 220 deja
    fuera el extraordinario. La lista cerrada no está en la ley: este es el criterio del sistema y se puede
    corregir persona por persona en la tabla de arriba.
  </p>
  <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
    <div>
      <p class="text-xs font-semibold text-emerald-700 uppercase tracking-wider mb-1.5">Entra</p>
      <ul class="space-y-1 text-slate-600">
        <li>· Sueldo del período</li>
        <li>· Comisiones</li>
        <li>· Vacaciones (diferencial)</li>
      </ul>
    </div>
    <div>
      <p class="text-xs font-semibold text-rose-700 uppercase tracking-wider mb-1.5">No entra</p>
      <ul class="space-y-1 text-slate-600">
        <?php foreach (regaliaConceptosExcluidos() as $qué => $porqué): ?>
          <li>· <strong><?= e($qué) ?></strong> — <?= e($porqué) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <p class="text-sm text-slate-500 mt-4 pt-3 border-t border-slate-100">
    La regalía <strong>no paga ISR</strong> (art. 222) y <strong>no cotiza a la TSS</strong>. Por eso su nómina se
    guarda con AFP, SFS e ISR en cero: el neto es el monto íntegro.
  </p>
</div>

<?php layout_end(); ?>
