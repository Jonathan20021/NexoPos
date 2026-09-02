<?php
/**
 * TSS — parámetros, declaración del mes y novedades.
 *
 * La pantalla existe sobre todo por una razón: los topes de la Ley 87-01 nacen
 * APAGADOS y encenderlos cambia lo que se le retiene a la plantilla entera. Eso
 * no se hace a ciegas, así que aquí se ve primero el número y luego se decide.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('tss.ver');

$tab = in_array(get('tab'), ['parametros', 'declaracion', 'pagos', 'novedades'], true) ? get('tab') : 'parametros';
$mes = preg_match('/^\d{4}-\d{2}$/', (string) get('mes')) ? get('mes') : date('Y-m');

if (!tssParametros()) {
    layout_start('TSS', 'Falta aplicar la migración');
    echo empty_state('Módulo no instalado',
        'Aplica database/migracion_tss_p22.sql para activar los parámetros y los topes de la TSS.', 'shield');
    layout_end();
    return;
}

/* ============================================================
 *  Acciones
 * ============================================================ */
if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    /* ---------- Registrar el pago del mes ---------- */
    if ($accion === 'pagar_mes') {
        require_perm('tss.pagar');
        $periodo = preg_match('/^\d{4}-\d{2}$/', (string) post('periodo')) ? (string) post('periodo') : '';
        $cual    = in_array(post('cual'), ['tss', 'isr'], true) ? post('cual') : '';
        try {
            if ($periodo === '' || $cual === '') throw new RuntimeException('Falta el período o qué se está pagando.');
            $id = txReintentable(fn() => tssPagoRegistrar($periodo, $cual, [
                'monto'      => post('monto') !== '' ? (float) post('monto') : null,
                'cuenta_id'  => postInt('cuenta_id'),
                'fecha_pago' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) post('fecha_pago'))
                                    ? (string) post('fecha_pago') : date('Y-m-d'),
                'referencia' => post('referencia'),
                'notas'      => post('notas'),
            ]));
            audit('tss', 'pagar', strtoupper($cual) . ' de ' . $periodo . ' registrada como pagada',
                ['tabla' => 'tss_pagos', 'registro_id' => $id]);
            flash('success', ($cual === 'tss' ? 'Pago a la TSS' : 'Pago del ISR retenido')
                . ' de ' . $periodo . ' registrado. El gasto ya está en el resultado.');
        } catch (Throwable $ex) {
            flash('error', $ex->getMessage());
        }
        redirect('modules/rrhh/tss.php?tab=pagos&mes=' . $periodo);
    }

    // El resto de acciones de esta pantalla cambian PARÁMETROS, que es otra cosa
    // que registrar un pago: se piden por separado.
    require_perm('tss.configurar');

    /* ---------- Nueva vigencia de parámetros ----------
       NUNCA se edita la fila anterior. La nómina de marzo cotizó con el mínimo
       de marzo y ante la TSS eso no se reescribe: se añade una vigencia nueva. */
    if ($accion === 'guardar') {
        $desde = post('vigencia_desde');
        $smc   = max(0.0, postNum('salario_minimo_cotizable'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $desde)) {
            flash('error', 'Indica desde cuándo rigen estos parámetros.');
            redirect('modules/rrhh/tss.php');
        }
        $aplicar = post('aplicar_topes') ? 1 : 0;

        $datos = [
            'vigencia_desde' => $desde,
            'salario_minimo_cotizable' => $smc,
            'sfs_empleado'  => postNum('sfs_empleado')  / 100,
            'sfs_empleador' => postNum('sfs_empleador') / 100,
            'afp_empleado'  => postNum('afp_empleado')  / 100,
            'afp_empleador' => postNum('afp_empleador') / 100,
            'srl_empleador' => postNum('srl_empleador') / 100,
            'infotep_empleador' => postNum('infotep_empleador') / 100,
            'tope_sfs_sm' => postNum('tope_sfs_sm'),
            'tope_afp_sm' => postNum('tope_afp_sm'),
            'tope_srl_sm' => postNum('tope_srl_sm'),
            'aplicar_topes' => $aplicar,
            'notas' => trim(post('notas')) ?: null,
            'usuario_id' => current_user()['id'],
        ];
        if ($aplicar) {
            $datos['confirmado_por'] = trim(post('confirmado_por')) ?: current_user()['nombre'];
            $datos['confirmado_at']  = date('Y-m-d H:i:s');
        }

        // Las tasas se teclean en porcentaje y se guardan en tanto por uno: una
        // coma que se salta convierte 7.09 en 709% y deja el neto de las 57
        // personas en cero sin decir nada. Ver tssValidarParametros().
        $problemas = tssValidarParametros($datos);
        if ($problemas) {
            foreach ($problemas as $pr) flash('error', $pr);
            redirect('modules/rrhh/tss.php');
        }

        try {
            $ya = qVal("SELECT id FROM tss_parametros WHERE vigencia_desde = ?", [$desde]);
            if ($ya) { dbUpdate('tss_parametros', $datos, 'id = ?', [(int) $ya]); $verbo = 'actualizada'; }
            else     { dbInsert('tss_parametros', $datos); $verbo = 'creada'; }
            audit('tss', 'configurar', "Vigencia $verbo desde $desde · mínimo cotizable "
                . money($smc, false) . ' · topes ' . ($aplicar ? 'ENCENDIDOS' : 'apagados'),
                ['tabla' => 'tss_parametros', 'registro_id' => (int) ($ya ?: 0)]);
            flash('success', 'Parámetros guardados. Vigencia ' . $verbo . ' desde ' . fechaCorta($desde) . '.'
                . ($aplicar ? ' Los topes quedan ENCENDIDOS: la próxima nómina ya cotiza con ellos.' : ''));
            // Cero es legal —un régimen puede desaparecer— pero dejar de retenerle
            // a la plantilla entera no puede pasar en silencio.
            $ceros = tssTasasEnCero($datos);
            if ($ceros) {
                flash('warning', 'Quedan en CERO: ' . implode(', ', $ceros)
                    . '. A partir de esta vigencia no se retendrá ni se aportará nada por ese concepto.');
            }
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/rrhh/tss.php');
    }
}

/* ============================================================
 *  Exportaciones
 * ============================================================ */
if ($tab === 'declaracion' && export_solicitado()) {
    $d = tssDeclaracionMes($mes);
    export_tabla('tss_' . $mes,
        ['Cédula', 'Empleado', 'Base cotizable', 'Base SFS', 'Base AFP', 'Base SRL',
         'SFS empleado', 'AFP empleado', 'SFS empresa', 'AFP empresa', 'Riesgos laborales', 'INFOTEP', 'Total'],
        array_map(fn($f) => [
            $f['cedula'], $f['nombre'], $f['base'],
            $f['bases']['sfs'], $f['bases']['afp'], $f['bases']['srl'],
            $f['empleado']['sfs'], $f['empleado']['afp'],
            $f['empleador']['sfs'], $f['empleador']['afp'], $f['empleador']['srl'], $f['empleador']['infotep'],
            $f['total'],
        ], $d['filas']));
}
if ($tab === 'novedades' && export_solicitado()) {
    $nov = tssNovedadesDelMes($mes);
    export_tabla('tss_novedades_' . $mes,
        ['Fecha', 'Tipo', 'Cédula', 'Empleado', 'Salario', 'Motivo', 'Origen'],
        array_map(fn($n) => [$n['fecha'], tssTiposNovedad()[$n['tipo']] ?? $n['tipo'], $n['cedula'],
                             $n['empleado'], $n['salario'] ?? '', $n['motivo'] ?? '', $n['origen']], $nov));
}

$p    = tssParametros();
$sim  = tssSimularTopes($p);
$acti = tssTopesActivos($p);

$acciones = ($tab !== 'parametros' ? export_buttons() : '');
layout_start('TSS · Tesorería de la Seguridad Social',
    'Ley 87-01 · parámetros, aportes del mes y novedades', $acciones);
?>

<div class="flex gap-1 overflow-x-auto pb-1 mb-5">
  <?php foreach (['parametros' => 'Parámetros y topes', 'declaracion' => 'Declaración del mes',
                  'pagos' => 'Pago del mes', 'novedades' => 'Novedades'] as $k => $lbl): ?>
    <a href="?tab=<?= $k ?><?= $k !== 'parametros' ? '&mes=' . e($mes) : '' ?>"
       class="btn btn-sm whitespace-nowrap <?= $tab === $k ? 'btn-primary' : 'btn-ghost' ?>"><?= e($lbl) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'parametros'): ?>

  <?php
  echo kpis([
      ['label' => 'Salario mínimo cotizable', 'valor' => (float) $p['salario_minimo_cotizable'] > 0
            ? money($p['salario_minimo_cotizable']) : '—',
       'icono' => 'wallet', 'color' => (float) $p['salario_minimo_cotizable'] > 0 ? 'blue' : 'rose',
       'nota' => (float) $p['salario_minimo_cotizable'] > 0
            ? 'Vigente desde ' . fechaCorta($p['vigencia_desde'])
            : 'Sin él no hay tope que calcular'],
      ['label' => 'Topes', 'valor' => $acti ? 'Encendidos' : 'Apagados', 'icono' => 'shield',
       'color' => $acti ? 'emerald' : 'amber',
       'nota' => $acti ? 'La nómina cotiza con ellos' : 'La nómina cotiza sobre el sueldo entero'],
      ['label' => 'Gente por encima de algún tope', 'valor' => number_format((int) ($sim['afectados'] ?? 0)),
       'icono' => 'users', 'color' => (int) ($sim['afectados'] ?? 0) > 0 ? 'violet' : 'slate',
       'nota' => ($sim['disponible'] ?? false) ? 'Con el mínimo cotizable de hoy' : 'No se puede saber sin el mínimo'],
      ['label' => 'Aporte patronal que se ahorra', 'valor' => ($sim['disponible'] ?? false)
            ? money(abs((float) $sim['dif_empleador'])) : '—',
       'icono' => 'trending', 'color' => 'emerald', 'nota' => 'Al mes, si se encienden'],
  ], 4);
  ?>

  <?php if (!$acti && ($sim['disponible'] ?? false) && $sim['afectados'] > 0): ?>
    <div class="card p-5 mb-5 border-l-4 border-l-amber-400">
      <div class="flex items-start gap-3">
        <?= icon('alert', 'w-5 h-5 text-amber-600 shrink-0 mt-0.5') ?>
        <div class="min-w-0 flex-1">
          <h3 class="font-bold text-slate-800">Lo que cambiaría al encender los topes</h3>
          <p class="text-sm text-slate-600 mt-0.5">
            Con un mínimo cotizable de <strong><?= money($p['salario_minimo_cotizable']) ?></strong> los topes quedan en
            SFS <strong><?= money($sim['topes']['sfs'], false) ?></strong> ·
            AFP <strong><?= money($sim['topes']['afp'], false) ?></strong> ·
            riesgos laborales <strong><?= money($sim['topes']['srl'], false) ?></strong>.
          </p>
          <div class="overflow-x-auto mt-3">
            <table class="data-table">
              <thead><tr><th>Empleado</th><th class="text-right">Salario</th><th>Topa en</th>
                <th class="text-right">Se le retiene</th><th class="text-right">Aporte de la empresa</th></tr></thead>
              <tbody>
                <?php foreach ($sim['filas'] as $f): ?>
                  <tr>
                    <td class="font-semibold text-slate-700"><?= e($f['empleado']) ?></td>
                    <td class="text-right"><?= money($f['salario']) ?></td>
                    <td><?php foreach ($f['regimenes'] as $r) echo badge(strtoupper($r), 'violet') . ' '; ?></td>
                    <td class="text-right <?= $f['dif_empleado'] < 0 ? 'text-emerald-600 font-semibold' : 'text-slate-500' ?>">
                      <?= ($f['dif_empleado'] >= 0 ? '+' : '−') . money(abs($f['dif_empleado']), false) ?>
                    </td>
                    <td class="text-right <?= $f['dif_empleador'] < 0 ? 'text-emerald-600 font-semibold' : 'text-slate-500' ?>">
                      <?= ($f['dif_empleador'] >= 0 ? '+' : '−') . money(abs($f['dif_empleador']), false) ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot><tr class="bg-slate-50 font-bold text-slate-800">
                <td colspan="3">TOTAL AL MES</td>
                <td class="text-right"><?= ($sim['dif_empleado'] >= 0 ? '+' : '−') . money(abs($sim['dif_empleado']), false) ?></td>
                <td class="text-right"><?= ($sim['dif_empleador'] >= 0 ? '+' : '−') . money(abs($sim['dif_empleador']), false) ?></td>
              </tr></tfoot>
            </table>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ((float) $p['salario_minimo_cotizable'] <= 0): ?>
    <div class="card p-5 mb-5 border-l-4 border-l-rose-500">
      <div class="flex items-start gap-3">
        <?= icon('alert', 'w-5 h-5 text-rose-600 shrink-0 mt-0.5') ?>
        <div>
          <h3 class="font-bold text-slate-800">Falta el salario mínimo cotizable</h3>
          <p class="text-sm text-slate-600 mt-0.5">
            Es la cifra que publica la TSS y de la que salen los tres topes. Mientras esté en cero,
            la nómina cotiza sobre el sueldo entero — que es lo que viene haciendo. <strong>Confírmala con
            el contador</strong> antes de encender los topes.
          </p>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if (can('tss.configurar')): ?>
  <form method="post" class="card p-5">
    <?= csrf_field() ?><input type="hidden" name="accion" value="guardar">
    <h3 class="font-bold text-slate-800 mb-1">Parámetros</h3>
    <p class="text-sm text-slate-500 mb-4">
      Guardar con una fecha distinta crea una <strong>vigencia nueva</strong> y deja intacta la anterior:
      lo ya declarado a la TSS no se reescribe.
    </p>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
      <div><label class="label">Vigente desde</label>
        <input type="date" name="vigencia_desde" value="<?= e($p['vigencia_desde']) ?>" class="input" required></div>
      <div><label class="label">Salario mínimo cotizable</label>
        <input type="number" step="0.01" min="0" name="salario_minimo_cotizable"
               value="<?= e(rtrim(rtrim(number_format((float) $p['salario_minimo_cotizable'], 2, '.', ''), '0'), '.')) ?>" class="input"></div>
      <div><label class="label">Confirmado por</label>
        <input type="text" name="confirmado_por" value="<?= e($p['confirmado_por'] ?? '') ?>"
               placeholder="Nombre del contador" class="input"></div>
    </div>

    <h4 class="font-bold text-slate-700 mt-5 mb-2 text-sm">Tasas (%)</h4>
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
      <?php foreach ([
          'sfs_empleado' => 'SFS empleado', 'afp_empleado' => 'AFP empleado',
          'sfs_empleador' => 'SFS empresa', 'afp_empleador' => 'AFP empresa',
          'srl_empleador' => 'Riesgos lab.', 'infotep_empleador' => 'INFOTEP',
      ] as $k => $lbl): ?>
        <div><label class="label text-[11px]"><?= e($lbl) ?></label>
          <input type="number" step="0.0001" min="0" name="<?= $k ?>"
                 value="<?= e(rtrim(rtrim(number_format((float) $p[$k] * 100, 4, '.', ''), '0'), '.')) ?>" class="input"></div>
      <?php endforeach; ?>
    </div>

    <h4 class="font-bold text-slate-700 mt-5 mb-2 text-sm">Topes, en múltiplos del salario mínimo cotizable</h4>
    <div class="grid grid-cols-3 gap-3 max-w-lg">
      <?php foreach (['tope_sfs_sm' => 'SFS', 'tope_afp_sm' => 'AFP', 'tope_srl_sm' => 'Riesgos laborales'] as $k => $lbl): ?>
        <div><label class="label text-[11px]"><?= e($lbl) ?></label>
          <input type="number" step="0.01" min="0" name="<?= $k ?>"
                 value="<?= e(rtrim(rtrim(number_format((float) $p[$k], 2, '.', ''), '0'), '.')) ?>" class="input">
          <p class="text-[11px] text-slate-400 mt-1">
            <?= (float) $p['salario_minimo_cotizable'] > 0
                ? money((float) $p['salario_minimo_cotizable'] * (float) $p[$k], false) : '—' ?>
          </p></div>
      <?php endforeach; ?>
    </div>

    <label class="flex items-start gap-3 mt-5 p-4 rounded-xl bg-slate-50 cursor-pointer">
      <input type="checkbox" name="aplicar_topes" value="1" <?= $acti ? 'checked' : '' ?>
             class="mt-0.5 w-4 h-4 rounded border-slate-300">
      <span class="text-sm">
        <span class="font-semibold text-slate-800">Aplicar los topes en la nómina</span>
        <span class="block text-slate-500">Cambia lo que se le retiene a quien esté por encima. Revisa antes la tabla de arriba.</span>
      </span>
    </label>

    <div class="mt-4"><label class="label">Notas</label>
      <textarea name="notas" rows="2" class="input" placeholder="De dónde salió la cifra, resolución, fecha…"><?= e($p['notas'] ?? '') ?></textarea></div>

    <div class="mt-5 flex justify-end">
      <button class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar parámetros</button>
    </div>
  </form>
  <?php endif; ?>

  <?php $hist = qAll("SELECT * FROM tss_parametros ORDER BY vigencia_desde DESC"); ?>
  <?php if (count($hist) > 1): ?>
    <div class="card overflow-hidden mt-5">
      <?= toolbar('<h3 class="font-bold text-slate-800">Vigencias anteriores</h3>', toolbar_conteo(count($hist), 'vigencia')) ?>
      <div class="overflow-x-auto"><table class="data-table">
        <thead><tr><th>Desde</th><th class="text-right">Mínimo cotizable</th><th>Topes</th><th>Confirmó</th><th>Notas</th></tr></thead>
        <tbody>
          <?php foreach ($hist as $h): ?>
            <tr><td class="font-semibold text-slate-700"><?= fechaCorta($h['vigencia_desde']) ?></td>
              <td class="text-right"><?= money($h['salario_minimo_cotizable']) ?></td>
              <td><?= (int) $h['aplicar_topes'] ? badge('Encendidos', 'emerald') : badge('Apagados', 'slate') ?></td>
              <td class="text-slate-500"><?= e($h['confirmado_por'] ?: '—') ?></td>
              <td class="text-slate-500 max-w-md truncate"><?= e($h['notas'] ?: '—') ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  <?php endif; ?>

<?php elseif ($tab === 'declaracion'): ?>

  <?php $d = tssDeclaracionMes($mes); $t = $d['totales']; ?>
  <form method="get" class="card p-4 mb-5 flex flex-wrap items-end gap-3">
    <input type="hidden" name="tab" value="declaracion">
    <div><label class="label">Mes</label><input type="month" name="mes" value="<?= e($mes) ?>" class="input w-auto"></div>
    <button class="btn btn-primary"><?= icon('filter', 'w-4 h-4') ?> Ver</button>
    <span class="ml-auto text-sm <?= $d['confirmada'] ? 'text-slate-400' : 'text-amber-700 font-semibold' ?>">
      Base tomada de: <?= e($d['fuente']) ?>
    </span>
  </form>

  <?php
  echo kpis([
      ['label' => 'Base cotizable del mes', 'valor' => money($t['base']), 'icono' => 'wallet', 'color' => 'blue',
       'nota' => count($d['filas']) . ' empleado' . (count($d['filas']) === 1 ? '' : 's')],
      ['label' => 'Retenido al empleado', 'valor' => money($t['empleado']), 'icono' => 'arrow-down', 'color' => 'amber',
       'nota' => 'SFS ' . money($t['sfs_e'], false) . ' · AFP ' . money($t['afp_e'], false)],
      ['label' => 'Aporte de la empresa', 'valor' => money($t['empleador']), 'icono' => 'briefcase', 'color' => 'violet',
       'nota' => 'Riesgos ' . money($t['srl'], false) . ' · INFOTEP ' . money($t['infotep'], false)],
      ['label' => 'Total a pagar a la TSS', 'valor' => money($t['general']), 'icono' => 'dollar', 'color' => 'emerald',
       'nota' => $acti ? 'Con topes aplicados' : 'Sin topes: sobre el sueldo entero'],
  ], 4);
  ?>

  <div class="card overflow-hidden">
    <?= toolbar('<h3 class="font-bold text-slate-800">Detalle por empleado</h3>', toolbar_conteo(count($d['filas']), 'empleado')) ?>
    <?php if (!$d['filas']): ?>
      <?= empty_state('Sin datos para ' . $mes, 'No hay nómina confirmada ni plantilla activa en ese mes.', 'users') ?>
    <?php else: ?>
      <?php
        // En la TSS el empleado se identifica por la cédula. Si está mal tecleada
        // no cuadra con nadie y esa persona se queda sin cotizar el mes entero,
        // aunque el cálculo de aquí esté perfecto. El dígito verificador lo
        // delata sin tener que consultar el portal.
        $cedulasMalas = [];
        foreach ($d['filas'] as $ff) {
            $rev = dgiiRevisarDocumento($ff['cedula'] ?? '');
            if (!$rev['valido']) $cedulasMalas[] = $ff['nombre'] . ' (' . ($ff['cedula'] ?: 'sin cédula') . ')';
        }
      ?>
      <?php if ($cedulasMalas): ?>
        <div class="mx-4 mb-4 p-3 rounded-xl bg-amber-50 border border-amber-200 flex items-start gap-2.5">
          <?= icon('alert', 'w-5 h-5 text-amber-600 mt-0.5 shrink-0') ?>
          <div class="text-sm text-amber-900 min-w-0">
            <p class="font-semibold">
              <?= count($cedulasMalas) ?>
              <?= count($cedulasMalas) === 1 ? 'empleado tiene la cédula' : 'empleados tienen la cédula' ?>
              mal escrita.
            </p>
            <p class="mt-0.5 leading-snug">
              En la TSS la cédula es lo que identifica a la persona: así no va a cuadrar y ese mes
              se queda sin cotizar. Corrígela en su ficha antes de cargar la autodeterminación.
            </p>
            <p class="mt-1 text-[13px] break-words"><?= e(implode(' · ', $cedulasMalas)) ?></p>
          </div>
        </div>
      <?php endif; ?>
      <div class="tabla-ancha">
        <table class="data-table">
          <thead><tr>
            <th class="min-w-[14rem]">Empleado</th><th class="text-right">Base</th>
            <th class="text-right">SFS emp.</th><th class="text-right">AFP emp.</th>
            <th class="text-right">SFS empresa</th><th class="text-right">AFP empresa</th>
            <th class="text-right">Riesgos</th><th class="text-right">INFOTEP</th><th class="text-right">Total</th>
          </tr></thead>
          <tbody>
            <?php foreach ($d['filas'] as $f): ?>
              <tr>
                <td class="whitespace-nowrap">
                  <p class="font-semibold text-slate-700"><?= e($f['nombre']) ?></p>
                  <?php $revCed = dgiiRevisarDocumento($f['cedula'] ?? ''); ?>
                  <p class="text-xs <?= $revCed['valido'] ? 'text-slate-400' : 'text-amber-700 font-medium' ?>">
                    <?= e($f['cedula'] ?: 'sin cédula') ?>
                    <?php if (!$revCed['valido']): ?>
                      <span class="badge badge-amber ml-1" title="<?= e($revCed['motivo']) ?>">cédula</span>
                    <?php endif; ?>
                    <?php foreach (array_keys(array_filter($f['topado'])) as $r): ?>
                      <span class="badge badge-violet ml-1">tope <?= strtoupper($r) ?></span>
                    <?php endforeach; ?></p>
                </td>
                <td class="text-right"><?= money($f['base'], false) ?></td>
                <td class="text-right text-slate-500"><?= money($f['empleado']['sfs'], false) ?></td>
                <td class="text-right text-slate-500"><?= money($f['empleado']['afp'], false) ?></td>
                <td class="text-right text-slate-500"><?= money($f['empleador']['sfs'], false) ?></td>
                <td class="text-right text-slate-500"><?= money($f['empleador']['afp'], false) ?></td>
                <td class="text-right text-slate-500"><?= money($f['empleador']['srl'], false) ?></td>
                <td class="text-right text-slate-500"><?= money($f['empleador']['infotep'], false) ?></td>
                <td class="text-right font-bold text-slate-800"><?= money($f['total'], false) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot><tr class="bg-slate-50 font-bold text-slate-800">
            <td>TOTALES</td>
            <td class="text-right"><?= money($t['base'], false) ?></td>
            <td class="text-right"><?= money($t['sfs_e'], false) ?></td>
            <td class="text-right"><?= money($t['afp_e'], false) ?></td>
            <td class="text-right"><?= money($t['sfs_p'], false) ?></td>
            <td class="text-right"><?= money($t['afp_p'], false) ?></td>
            <td class="text-right"><?= money($t['srl'], false) ?></td>
            <td class="text-right"><?= money($t['infotep'], false) ?></td>
            <td class="text-right text-emerald-700"><?= money($t['general'], false) ?></td>
          </tr></tfoot>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="card p-4 mt-5 flex items-start gap-3 bg-sky-50 border-sky-100">
    <?= icon('alert', 'w-5 h-5 text-sky-600 mt-0.5 shrink-0') ?>
    <p class="text-sm text-sky-900">
      <strong>Esto es la hoja de trabajo, no el archivo de SUIR+.</strong> Trae todos los datos que pide la
      autodeterminación —cédula, base cotizable por régimen y aportes de las dos partes— para cargarla o
      cuadrarla contra el portal. El archivo con el formato oficial de la TSS se añadirá cuando tengamos su
      especificación; inventarlo sería peor que no tenerlo.
    </p>
  </div>

<?php elseif ($tab === 'pagos'): ?>

  <?php
  /* ============================================================
   *  EL PAGO DEL MES
   * ============================================================
   *
   * Al pagar la nómina solo entraba al resultado el NETO. Lo que se le retiene
   * a la gente y el aporte patronal —juntos, un 20% largo del costo real— no
   * aparecían por ningún lado, porque `transacciones` es un libro de CAJA y ese
   * dinero todavía no había salido. Sale AQUÍ, y aquí es donde entra al gasto.
   */
  $ob = tssObligacionesMes($mes);
  $pagoTss = $ob['tss']['pago'];
  $pagoIsr = $ob['isr']['pago'];
  $cuentas = qAll("SELECT id, nombre, tipo, balance FROM cuentas_financieras
                    WHERE activo = 1 ORDER BY tipo = 'banco' DESC, sucursal_id IS NULL, nombre");
  $puedePagar = can('tss.pagar') && tssPagosDisponible();
  ?>

  <form method="get" class="card p-4 mb-5 flex flex-wrap items-end gap-3">
    <input type="hidden" name="tab" value="pagos">
    <div><label class="label" for="mesp">Mes</label>
      <input type="month" id="mesp" name="mes" value="<?= e($mes) ?>" class="input w-auto"></div>
    <button class="btn btn-primary"><?= icon('filter', 'w-4 h-4') ?> Ver</button>
    <span class="ml-auto text-sm <?= $ob['confirmada'] ? 'text-slate-400' : 'text-amber-700 font-semibold' ?>">
      Base tomada de: <?= e($ob['fuente']) ?>
    </span>
  </form>

  <?php if (!tssPagosDisponible()): ?>
    <div class="card p-4 mb-5 flex items-start gap-3 bg-amber-50 border-amber-200">
      <?= icon('alert', 'w-5 h-5 text-amber-600 mt-0.5 shrink-0') ?>
      <p class="text-sm text-amber-900">
        Falta aplicar <code>database/migracion_tss_pagos_p31.sql</code>. Los importes se calculan igual,
        pero todavía no se puede registrar el pago.
      </p>
    </div>
  <?php endif; ?>

  <?= kpis([
      ['label' => 'A la Tesorería (TSS)', 'valor' => money($ob['tss']['total']), 'icono' => 'shield',
       'color' => $pagoTss ? 'emerald' : 'violet',
       'nota' => $pagoTss ? 'Pagado el ' . fechaCorta($pagoTss['fecha_pago']) : 'Retención + per cápita + aporte patronal'],
      ['label' => 'A la DGII · IR-3', 'valor' => money($ob['isr']['total']), 'icono' => 'receipt',
       'color' => $pagoIsr ? 'emerald' : 'amber',
       'nota' => $pagoIsr ? 'Pagado el ' . fechaCorta($pagoIsr['fecha_pago']) : 'ISR retenido a los asalariados'],
      ['label' => 'Total del mes', 'valor' => money($ob['total_general']), 'icono' => 'dollar', 'color' => 'blue',
       'nota' => $ob['nominas'] . ' nómina(s) confirmada(s) en ' . e($mes)],
      ['label' => 'Ya registrado', 'valor' => money((float) ($pagoTss['monto'] ?? 0) + (float) ($pagoIsr['monto'] ?? 0)),
       'icono' => 'check', 'color' => ($pagoTss && $pagoIsr) ? 'emerald' : 'slate',
       'nota' => ($pagoTss && $pagoIsr) ? 'El mes está saldado' : 'Lo que falta no está en el resultado'],
  ], 4) ?>

  <?php if ($ob['nominas'] === 0): ?>
    <div class="card p-6"><?= empty_state('Sin nómina confirmada en ' . e($mes),
        'No hay nada que declarar ni que pagar. Confirma la nómina del mes primero.', 'wallet') ?></div>
  <?php else: ?>

    <?php if (abs($ob['tss']['diferencia']) >= 0.01): ?>
      <div class="card p-4 mb-5 flex items-start gap-3 bg-amber-50 border-amber-200">
        <?= icon('alert', 'w-5 h-5 text-amber-600 mt-0.5 shrink-0') ?>
        <div class="text-sm text-amber-900">
          <strong>La declaración y la nómina no retienen lo mismo: <?= money(abs($ob['tss']['diferencia'])) ?>
          de diferencia.</strong>
          <p class="mt-1 text-amber-800">
            La nómina retuvo <?= money($ob['tss']['retenido_en_nomina']) ?> y la declaración del mes da
            <?= money($ob['tss']['retencion_empleado']) ?>. Pasa cuando el tope de la TSS —que es
            <strong>mensual</strong>— corta distinto al aplicarse sobre el mes completo que quincena a
            quincena. A la Tesorería se le paga lo declarado; la diferencia es un ajuste que hay que
            cuadrar con el contador.
          </p>
        </div>
      </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-5">

      <!-- ============ TSS ============ -->
      <div class="card overflow-hidden flex flex-col">
        <?= toolbar('<h3 class="font-bold text-slate-800">Tesorería de la Seguridad Social</h3>'
            . '<p class="text-xs text-slate-400 mt-0.5">AFP, SFS, riesgos laborales e INFOTEP</p>',
            $pagoTss ? badge('Pagado', 'emerald') : badge('Pendiente', 'amber')) ?>
        <table class="data-table">
          <tbody>
            <tr><td class="text-slate-600">Retenido al empleado (AFP + SFS)</td>
                <td class="text-right tabular-nums text-slate-700"><?= money($ob['tss']['retencion_empleado'], false) ?></td></tr>
            <?php if ($ob['tss']['per_capita'] > 0): ?>
              <tr><td class="text-slate-600">Per cápita adicional retenida</td>
                  <td class="text-right tabular-nums text-slate-700"><?= money($ob['tss']['per_capita'], false) ?></td></tr>
            <?php endif; ?>
            <?php foreach (['sfs' => 'SFS de la empresa (7.09%)', 'afp' => 'AFP de la empresa (7.10%)',
                            'srl' => 'Riesgos laborales (1.10%)', 'infotep' => 'INFOTEP (1%)'] as $k => $lbl): ?>
              <tr><td class="text-slate-500 pl-6"><?= e($lbl) ?></td>
                  <td class="text-right tabular-nums text-slate-600"><?= money($ob['tss']['desglose_patronal'][$k], false) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr class="bg-slate-50 font-bold text-slate-800">
              <td>Total a pagar</td>
              <td class="text-right tabular-nums"><?= money($ob['tss']['total'], false) ?></td>
            </tr>
          </tfoot>
        </table>
        <?php if ($pagoTss): ?>
          <div class="px-5 py-4 border-t border-slate-100 text-sm text-slate-600">
            Pagado el <strong><?= e(fechaCorta($pagoTss['fecha_pago'])) ?></strong> por
            <strong><?= money($pagoTss['monto']) ?></strong>
            <?= $pagoTss['referencia'] ? ' · ref. ' . e($pagoTss['referencia']) : '' ?>.
            <span class="block text-xs text-slate-400 mt-0.5">El gasto ya está registrado en finanzas.</span>
          </div>
        <?php elseif ($puedePagar): ?>
          <form method="post" class="px-5 py-4 border-t border-slate-100 space-y-3 mt-auto">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="pagar_mes">
            <input type="hidden" name="periodo" value="<?= e($mes) ?>">
            <input type="hidden" name="cual" value="tss">
            <div class="grid grid-cols-2 gap-3">
              <div><label class="label" for="tss_fecha">Fecha del pago</label>
                <input type="date" id="tss_fecha" name="fecha_pago" value="<?= date('Y-m-d') ?>" class="input"></div>
              <div><label class="label" for="tss_monto">Monto</label>
                <input type="number" step="0.01" min="0" id="tss_monto" name="monto"
                       value="<?= e(number_format($ob['tss']['total'], 2, '.', '')) ?>" class="input"></div>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div><label class="label" for="tss_cuenta">De qué cuenta sale</label>
                <select id="tss_cuenta" name="cuenta_id" class="select">
                  <?php foreach ($cuentas as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"><?= e($c['nombre']) ?> · <?= money($c['balance']) ?></option>
                  <?php endforeach; ?>
                </select></div>
              <div><label class="label" for="tss_ref">Referencia</label>
                <input type="text" id="tss_ref" name="referencia" maxlength="60" class="input"
                       placeholder="Núm. de recibo del SUIR+"></div>
            </div>
            <?php $av = 'Se registrará el pago a la TSS de ' . $mes . ' y el gasto entrará al resultado.'; ?>
            <button class="btn btn-primary w-full" onclick="return confirm('<?= e(addslashes($av)) ?>')">
              <?= icon('check', 'w-4 h-4') ?> Registrar el pago a la TSS
            </button>
          </form>
        <?php endif; ?>
      </div>

      <!-- ============ ISR ============ -->
      <div class="card overflow-hidden flex flex-col">
        <?= toolbar('<h3 class="font-bold text-slate-800">ISR retenido · IR-3</h3>'
            . '<p class="text-xs text-slate-400 mt-0.5">Impuesto sobre la renta de los asalariados, a la DGII</p>',
            $pagoIsr ? badge('Pagado', 'emerald') : badge('Pendiente', 'amber')) ?>
        <table class="data-table">
          <tbody>
            <tr><td class="text-slate-600">ISR retenido en las nóminas del mes</td>
                <td class="text-right tabular-nums text-slate-700"><?= money($ob['isr']['total'], false) ?></td></tr>
          </tbody>
          <tfoot>
            <tr class="bg-slate-50 font-bold text-slate-800">
              <td>Total a pagar</td>
              <td class="text-right tabular-nums"><?= money($ob['isr']['total'], false) ?></td>
            </tr>
          </tfoot>
        </table>
        <?php if ($pagoIsr): ?>
          <div class="px-5 py-4 border-t border-slate-100 text-sm text-slate-600">
            Pagado el <strong><?= e(fechaCorta($pagoIsr['fecha_pago'])) ?></strong> por
            <strong><?= money($pagoIsr['monto']) ?></strong>
            <?= $pagoIsr['referencia'] ? ' · ref. ' . e($pagoIsr['referencia']) : '' ?>.
          </div>
        <?php elseif ($puedePagar && $ob['isr']['total'] > 0): ?>
          <form method="post" class="px-5 py-4 border-t border-slate-100 space-y-3 mt-auto">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="pagar_mes">
            <input type="hidden" name="periodo" value="<?= e($mes) ?>">
            <input type="hidden" name="cual" value="isr">
            <div class="grid grid-cols-2 gap-3">
              <div><label class="label" for="isr_fecha">Fecha del pago</label>
                <input type="date" id="isr_fecha" name="fecha_pago" value="<?= date('Y-m-d') ?>" class="input"></div>
              <div><label class="label" for="isr_monto">Monto</label>
                <input type="number" step="0.01" min="0" id="isr_monto" name="monto"
                       value="<?= e(number_format($ob['isr']['total'], 2, '.', '')) ?>" class="input"></div>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div><label class="label" for="isr_cuenta">De qué cuenta sale</label>
                <select id="isr_cuenta" name="cuenta_id" class="select">
                  <?php foreach ($cuentas as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"><?= e($c['nombre']) ?> · <?= money($c['balance']) ?></option>
                  <?php endforeach; ?>
                </select></div>
              <div><label class="label" for="isr_ref">Referencia</label>
                <input type="text" id="isr_ref" name="referencia" maxlength="60" class="input"
                       placeholder="Núm. del IR-3"></div>
            </div>
            <?php $av2 = 'Se registrará el pago del ISR retenido de ' . $mes . '.'; ?>
            <button class="btn btn-primary w-full" onclick="return confirm('<?= e(addslashes($av2)) ?>')">
              <?= icon('check', 'w-4 h-4') ?> Registrar el pago del IR-3
            </button>
          </form>
        <?php elseif ($ob['isr']['total'] <= 0): ?>
          <div class="px-5 py-4 border-t border-slate-100 text-sm text-slate-500">
            No se le retuvo ISR a nadie en <?= e($mes) ?>: no hay nada que declarar.
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card p-5">
      <h3 class="font-bold text-slate-800 mb-2">Por qué esto no salía en el resultado</h3>
      <p class="text-sm text-slate-600 leading-relaxed">
        Una nómina mueve tres cosas: el <strong>neto</strong>, que sale a la gente; las
        <strong>retenciones</strong>, que la empresa guarda y remite; y el <strong>aporte patronal</strong>,
        que sale íntegro de la empresa. Al pagar la nómina solo entraba al resultado el neto, porque
        <code>transacciones</code> es un libro de caja y ese otro dinero todavía no había salido.
        Sale al pagar la TSS y el IR-3, y es aquí donde se registra.
      </p>
      <p class="text-sm text-slate-500 mt-3 pt-3 border-t border-slate-100">
        Sobre las nóminas de <?= e($mes) ?>: el costo real fue
        <strong><?= money((float) qVal("SELECT COALESCE(SUM(total_bruto),0) FROM nominas
                                         WHERE estado IN ('procesada','pagada') AND tipo <> 'regalia'
                                           AND fecha_hasta BETWEEN ? AND ?", [$ob['desde'], $ob['hasta']])
                          + $ob['tss']['aporte_patronal']) ?></strong>
        y por la nómina entró solo el neto. La diferencia son estos <?= money($ob['total_general']) ?>.
      </p>
    </div>

  <?php endif; ?>

<?php else: ?>

  <?php $nov = tssNovedadesDelMes($mes); ?>
  <form method="get" class="card p-4 mb-5 flex flex-wrap items-end gap-3">
    <input type="hidden" name="tab" value="novedades">
    <div><label class="label">Mes</label><input type="month" name="mes" value="<?= e($mes) ?>" class="input w-auto"></div>
    <button class="btn btn-primary"><?= icon('filter', 'w-4 h-4') ?> Ver</button>
  </form>

  <?php
  $porTipo = [];
  foreach ($nov as $n) $porTipo[$n['tipo']] = ($porTipo[$n['tipo']] ?? 0) + 1;
  echo kpis([
      ['label' => 'Novedades del mes', 'valor' => number_format(count($nov)), 'icono' => 'history', 'color' => 'blue',
       'nota' => 'Hay que declararlas con el mes'],
      ['label' => 'Ingresos', 'valor' => number_format(($porTipo['ingreso'] ?? 0) + ($porTipo['reingreso'] ?? 0)),
       'icono' => 'arrow-down', 'color' => 'emerald'],
      ['label' => 'Salidas', 'valor' => number_format($porTipo['salida'] ?? 0), 'icono' => 'arrow-up',
       'color' => ($porTipo['salida'] ?? 0) > 0 ? 'rose' : 'slate'],
      ['label' => 'Cambios de salario', 'valor' => number_format($porTipo['cambio_salario'] ?? 0),
       'icono' => 'trending', 'color' => 'violet'],
  ], 4);
  ?>

  <div class="card overflow-hidden">
    <?= toolbar('<h3 class="font-bold text-slate-800">Movimientos de ' . e($mes) . '</h3>', toolbar_conteo(count($nov), 'novedad')) ?>
    <?php if (!$nov): ?>
      <?= empty_state('Sin novedades', 'Nadie entró, salió ni cambió de salario en ese mes.', 'check') ?>
    <?php else: ?>
      <div class="overflow-x-auto"><table class="data-table">
        <thead><tr><th>Fecha</th><th>Tipo</th><th>Empleado</th><th class="text-right">Salario</th><th>Motivo</th><th>Origen</th></tr></thead>
        <tbody>
          <?php $col = ['ingreso' => 'emerald', 'reingreso' => 'emerald', 'salida' => 'rose',
                        'cambio_salario' => 'violet', 'licencia' => 'amber']; ?>
          <?php foreach ($nov as $n): ?>
            <tr>
              <td class="text-slate-500 whitespace-nowrap"><?= fechaCorta($n['fecha']) ?></td>
              <td><?= badge(tssTiposNovedad()[$n['tipo']] ?? $n['tipo'], $col[$n['tipo']] ?? 'slate') ?></td>
              <td><p class="font-semibold text-slate-700"><?= e($n['empleado']) ?></p>
                  <p class="text-xs text-slate-400"><?= e($n['cedula'] ?: '—') ?></p></td>
              <td class="text-right"><?= $n['salario'] ? money($n['salario']) : '—' ?></td>
              <td class="text-slate-500 max-w-xs truncate"><?= e($n['motivo'] ?? '—') ?></td>
              <td class="text-xs text-slate-400"><?= e($n['origen']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>

<?php endif; ?>

<?php layout_end(); ?>
