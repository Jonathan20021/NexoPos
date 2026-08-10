<?php
/**
 * Carga histórica de clientes y ventas.
 *
 * Flujo en tres pasos, y el del medio es el importante:
 *   1. Subir el archivo (CSV o Excel).
 *   2. **Revisar**: el sistema dice exactamente qué va a entrar, qué va a
 *      rechazar y por qué. Nada se escribe todavía.
 *   3. Cargar. Y si algo salió mal, revertir el lote entero con un botón.
 *
 * Lo que se carga NO mueve inventario, NO consume NCF y NO genera movimientos
 * de caja: son operaciones que ya ocurrieron en otro sistema. Ver la cabecera de
 * includes/importador.php.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('direccion.importar');

if (!imp_disponible()) {
    layout_start('Cargar datos históricos', 'Clientes y ventas de años anteriores');
    echo empty_state('Falta aplicar la migración',
        'Ejecuta database/migracion_tiendas_p16.sql para habilitar la carga histórica.', 'alert');
    layout_end();
    return;
}

imp_limpiar_archivos();

const IMP_SESION = 'imp_carga';
$paso = 1;
$prev = null;

// ---------- Acciones ----------
if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    /* -------- 1) Subir el archivo -------- */
    if ($accion === 'subir') {
        $tipo = post('tipo') === 'clientes' ? 'clientes' : 'ventas';
        $r = imp_guardar_archivo('archivo');
        if (!$r['ok']) {
            flash('error', $r['error']);
        } else {
            try {
                $lec = imp_leer($r['path'], 15);
                if (!$lec['headers'] || $lec['total'] === 0) {
                    @unlink($r['path']);
                    flash('error', 'El archivo no tiene filas de datos bajo el encabezado.');
                } else {
                    $_SESSION[IMP_SESION] = [
                        'tipo'    => $tipo,
                        'path'    => $r['path'],
                        'nombre'  => $r['nombre'],
                        'headers' => $lec['headers'],
                        'total'   => $lec['total'],
                    ];
                    flash('success', 'Archivo leído: ' . number_format($lec['total']) . ' fila(s). Revisa el mapeo de columnas.');
                }
            } catch (Throwable $e) {
                @unlink($r['path']);
                flash('error', $e->getMessage());
            }
        }
        redirect('modules/direccion/importar.php');
    }

    /* -------- 2) Vista previa (no escribe nada) -------- */
    if ($accion === 'previsualizar' || $accion === 'cargar') {
        $ses = $_SESSION[IMP_SESION] ?? null;
        if (!$ses || !is_file($ses['path'])) {
            flash('error', 'La sesión de carga expiró. Vuelve a subir el archivo.');
            unset($_SESSION[IMP_SESION]);
            redirect('modules/direccion/importar.php');
        }

        $mapa = [];
        foreach ((array) post('mapa', []) as $campo => $idx) {
            if ($idx === '' || !array_key_exists($campo, imp_campos($ses['tipo']))) continue;
            $mapa[$campo] = (int) $idx;
        }
        $opts = [
            'mapa'                  => $mapa,
            'sucursal_id'           => postInt('sucursal_id'),
            'tienda_id'             => postInt('tienda_id'),
            'itbis_modo'            => in_array(post('itbis_modo'), ['incluido', 'excluido', 'ninguno'], true) ? post('itbis_modo') : 'ninguno',
            'costo_catalogo'        => post('costo_catalogo') === '1',
            'crear_clientes'        => post('crear_clientes') === '1',
            'actualizar_existentes' => post('actualizar_existentes') === '1',
        ];

        // Faltan obligatorios: se avisa antes de recorrer 12.000 filas.
        $faltan = [];
        foreach (imp_campos($ses['tipo']) as $campo => $cfg) {
            if (!empty($cfg['req']) && !isset($mapa[$campo])) $faltan[] = $cfg['label'];
        }
        if ($ses['tipo'] === 'ventas' && !isset($mapa['subtotal']) && !isset($mapa['total']) && !isset($mapa['precio'])) {
            $faltan[] = 'algún importe (subtotal, total o precio)';
        }
        if ($ses['tipo'] === 'ventas' && $opts['sucursal_id'] <= 0 && !isset($mapa['sucursal'])) {
            $faltan[] = 'la sucursal (elige una por defecto o mapea la columna)';
        }

        if ($faltan) {
            flash('error', 'Falta indicar: ' . implode(', ', $faltan) . '.');
            $_SESSION[IMP_SESION]['mapa'] = $mapa;
            $_SESSION[IMP_SESION]['opts'] = $opts;
            redirect('modules/direccion/importar.php');
        }

        try {
            $lec  = imp_leer($ses['path']);
            $prev = imp_analizar($ses['tipo'], $lec['filas'], $mapa, $opts);
        } catch (Throwable $e) {
            flash('error', 'No se pudo analizar el archivo: ' . $e->getMessage());
            redirect('modules/direccion/importar.php');
        }

        if ($accion === 'cargar') {
            if (!$prev['docs']) {
                flash('error', 'No hay ninguna fila válida que cargar.');
                redirect('modules/direccion/importar.php');
            }
            try {
                $impId = imp_ejecutar($ses['tipo'], $prev, $opts, $ses['nombre']);
                audit('direccion', 'importar',
                    'Carga histórica de ' . $ses['tipo'] . ': ' . count($prev['docs']) . ' registro(s) desde ' . $ses['nombre'],
                    ['tabla' => 'importaciones', 'registro_id' => $impId]);
                @unlink($ses['path']);
                unset($_SESSION[IMP_SESION]);
                flash('success', 'Carga completada. Se registraron ' . number_format(count($prev['docs'])) . ' '
                    . ($ses['tipo'] === 'clientes' ? 'cliente(s).' : 'venta(s) por ' . money($prev['resumen']['monto']) . '.'));
            } catch (Throwable $e) {
                flash('error', 'La carga se detuvo: ' . $e->getMessage());
            }
            redirect('modules/direccion/importar.php');
        }

        // Vista previa: se guarda el mapeo para repintar la pantalla igual.
        $_SESSION[IMP_SESION]['mapa'] = $mapa;
        $_SESSION[IMP_SESION]['opts'] = $opts;
        $paso = 3;
    }

    /* -------- Descartar / revertir -------- */
    if ($accion === 'descartar') {
        if (!empty($_SESSION[IMP_SESION]['path'])) @unlink($_SESSION[IMP_SESION]['path']);
        unset($_SESSION[IMP_SESION]);
        flash('info', 'Carga descartada. No se escribió nada.');
        redirect('modules/direccion/importar.php');
    }

    if ($accion === 'revertir') {
        try {
            $r = imp_revertir(postInt('id'));
            audit('direccion', 'importar', 'Lote de carga revertido #' . postInt('id'),
                ['tabla' => 'importaciones', 'registro_id' => postInt('id')]);
            $msg = 'Lote revertido: se eliminaron ' . number_format($r['ventas']) . ' venta(s) y '
                . number_format($r['clientes']) . ' cliente(s).';
            if ($r['clientes_conservados'] > $r['clientes']) {
                $msg .= ' Se conservaron ' . number_format($r['clientes_conservados'] - $r['clientes'])
                     . ' cliente(s) que ya tienen movimientos propios.';
            }
            flash('success', $msg);
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/direccion/importar.php');
    }
}

// ---------- Estado de la pantalla ----------
$ses = $_SESSION[IMP_SESION] ?? null;
if ($ses && is_file($ses['path'])) {
    if ($paso < 3) $paso = 2;
} elseif ($ses) {
    unset($_SESSION[IMP_SESION]);
    $ses = null;
}

$tipo    = $ses['tipo'] ?? 'ventas';
$campos  = imp_campos($tipo);
$headers = $ses['headers'] ?? [];
$mapa    = $ses['mapa'] ?? ($headers ? imp_automapear($headers, $tipo) : []);
$opts    = $ses['opts'] ?? [];
$sucursales = sucursales_visibles();
$lotes   = imp_lotes(15);

layout_start('Cargar datos históricos', 'Clientes y ventas de años anteriores, sin tocar el inventario ni la caja');
?>

<!-- Pasos -->
<div class="flex items-center gap-2 mb-5 text-sm">
  <?php foreach ([1 => 'Subir archivo', 2 => 'Mapear columnas', 3 => 'Revisar y cargar'] as $n => $lbl): ?>
    <div class="flex items-center gap-2">
      <span class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold
                   <?= $paso >= $n ? 'bg-blue-600 text-white' : 'bg-slate-200 text-slate-500' ?>"><?= $n ?></span>
      <span class="<?= $paso >= $n ? 'font-semibold text-slate-700' : 'text-slate-400' ?>"><?= e($lbl) ?></span>
    </div>
    <?php if ($n < 3): ?><span class="flex-1 h-px bg-slate-200 max-w-[40px]"></span><?php endif; ?>
  <?php endforeach; ?>
</div>

<?php if ($paso === 1): ?>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 items-start">
    <div class="lg:col-span-2 card p-6">
      <h3 class="font-bold text-slate-800 mb-1">Sube el archivo</h3>
      <p class="text-sm text-slate-500 mb-5">CSV o Excel (.xlsx), hasta 25 MB. La primera fila debe ser el encabezado con el nombre de cada columna.</p>
      <form method="post" enctype="multipart/form-data" class="space-y-5">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="subir">
        <div x-data="{tipo:'ventas'}">
          <span class="label">¿Qué vas a cargar?</span>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-1">
            <label class="rounded-xl border p-4 cursor-pointer transition"
                   :class="tipo==='ventas' ? 'border-blue-500 bg-blue-50/50 ring-1 ring-blue-200' : 'border-slate-200 hover:border-slate-300'">
              <input type="radio" name="tipo" value="ventas" x-model="tipo" class="sr-only">
              <p class="font-semibold text-slate-800 flex items-center gap-2"><?= icon('receipt', 'w-4 h-4') ?> Ventas</p>
              <p class="text-xs text-slate-500 mt-1">Facturas de años anteriores. Alimentan comparativos, márgenes y ranking de clientes.</p>
            </label>
            <label class="rounded-xl border p-4 cursor-pointer transition"
                   :class="tipo==='clientes' ? 'border-blue-500 bg-blue-50/50 ring-1 ring-blue-200' : 'border-slate-200 hover:border-slate-300'">
              <input type="radio" name="tipo" value="clientes" x-model="tipo" class="sr-only">
              <p class="font-semibold text-slate-800 flex items-center gap-2"><?= icon('users', 'w-4 h-4') ?> Clientes</p>
              <p class="text-xs text-slate-500 mt-1">La cartera completa: nombre, RNC, teléfono, correo y dirección.</p>
            </label>
          </div>
        </div>
        <div>
          <label class="label" for="imp_archivo">Archivo *</label>
          <input type="file" id="imp_archivo" name="archivo" accept=".csv,.txt,.xlsx,.xls" required
                 class="block w-full text-sm text-slate-500 file:mr-3 file:py-2.5 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 cursor-pointer">
        </div>
        <button class="btn btn-primary"><?= icon('download', 'w-4 h-4') ?> Leer archivo</button>
      </form>
    </div>

    <div class="card p-5 bg-slate-50/60">
      <h4 class="font-bold text-slate-800 text-sm mb-2">Qué hace y qué no hace</h4>
      <ul class="text-sm text-slate-600 space-y-2 leading-snug">
        <li class="flex gap-2"><span class="text-emerald-600 shrink-0"><?= icon('check', 'w-4 h-4') ?></span> Alimenta ventas, márgenes, comparativos y el ranking de clientes.</li>
        <li class="flex gap-2"><span class="text-emerald-600 shrink-0"><?= icon('check', 'w-4 h-4') ?></span> Reimportar el mismo archivo no duplica nada: las facturas ya registradas se omiten.</li>
        <li class="flex gap-2"><span class="text-emerald-600 shrink-0"><?= icon('check', 'w-4 h-4') ?></span> Todo queda marcado con su lote y se puede revertir de un golpe.</li>
        <li class="flex gap-2"><span class="text-slate-400 shrink-0"><?= icon('x', 'w-4 h-4') ?></span> <strong>No</strong> mueve inventario: el stock de hoy ya es el real.</li>
        <li class="flex gap-2"><span class="text-slate-400 shrink-0"><?= icon('x', 'w-4 h-4') ?></span> <strong>No</strong> consume NCF: esos comprobantes ya se emitieron.</li>
        <li class="flex gap-2"><span class="text-slate-400 shrink-0"><?= icon('x', 'w-4 h-4') ?></span> <strong>No</strong> genera movimientos de caja ni cuentas por cobrar.</li>
      </ul>
    </div>
  </div>

<?php else: ?>

  <!-- Mapeo (pasos 2 y 3) -->
  <form method="post" class="space-y-5">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="previsualizar" id="imp_accion">

    <div class="card">
      <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
        <div>
          <h3 class="font-bold text-slate-800">Mapear columnas · <?= $tipo === 'clientes' ? 'Clientes' : 'Ventas' ?></h3>
          <p class="text-xs text-slate-500 mt-0.5">
            <?= e($ses['nombre']) ?> · <?= number_format((int) $ses['total']) ?> fila(s) · <?= count($headers) ?> columna(s)
          </p>
        </div>
        <button type="submit" form="frm_descartar" class="btn btn-ghost btn-sm">Descartar y empezar de nuevo</button>
      </div>

      <div class="p-5 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
        <?php foreach ($campos as $campo => $cfg):
          $sel = $mapa[$campo] ?? null; ?>
          <div>
            <label class="label" for="map_<?= e($campo) ?>">
              <?= e($cfg['label']) ?><?= !empty($cfg['req']) ? ' *' : '' ?>
            </label>
            <select id="map_<?= e($campo) ?>" name="mapa[<?= e($campo) ?>]"
                    class="select <?= !empty($cfg['req']) && $sel === null ? 'border-rose-300' : '' ?>">
              <option value="">— No está en el archivo —</option>
              <?php foreach ($headers as $i => $h): ?>
                <option value="<?= (int) $i ?>" <?= $sel === (int) $i ? 'selected' : '' ?>><?= e($h !== '' ? $h : 'Columna ' . ($i + 1)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Opciones -->
      <div class="px-5 pb-5 pt-1 border-t border-slate-100 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
        <?php if ($tipo === 'ventas'): ?>
          <div>
            <label class="label" for="imp_suc">Sucursal por defecto</label>
            <select id="imp_suc" name="sucursal_id" class="select">
              <option value="">— Usar la columna del archivo —</option>
              <?php foreach ($sucursales as $s): ?>
                <option value="<?= (int) $s['id'] ?>" <?= (int) ($opts['sucursal_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="mt-1 text-xs text-slate-500">Se usa cuando la fila no dice a qué sucursal pertenece.</p>
          </div>
          <?php if (tiendas_hay()): ?>
            <div>
              <label class="label" for="imp_tie">Tienda por defecto</label>
              <select id="imp_tie" name="tienda_id" class="select">
                <option value="">— Sin marca —</option>
                <?php foreach (tiendas_activas() as $t): ?>
                  <option value="<?= (int) $t['id'] ?>" <?= (int) ($opts['tienda_id'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>
          <div>
            <label class="label" for="imp_itbis">Los importes del archivo…</label>
            <select id="imp_itbis" name="itbis_modo" class="select">
              <option value="ninguno"  <?= ($opts['itbis_modo'] ?? '') === 'ninguno'  ? 'selected' : '' ?>>No llevan ITBIS</option>
              <option value="incluido" <?= ($opts['itbis_modo'] ?? '') === 'incluido' ? 'selected' : '' ?>>Ya incluyen el ITBIS (se desglosa)</option>
              <option value="excluido" <?= ($opts['itbis_modo'] ?? '') === 'excluido' ? 'selected' : '' ?>>No lo incluyen (se calcula y se suma)</option>
            </select>
            <p class="mt-1 text-xs text-slate-500">Si mapeaste una columna de ITBIS, esa manda.</p>
          </div>
          <label class="flex items-start gap-2 text-sm text-slate-600 cursor-pointer sm:col-span-2 xl:col-span-1">
            <input type="hidden" name="costo_catalogo" value="0">
            <input type="checkbox" name="costo_catalogo" value="1" <?= !empty($opts['costo_catalogo']) ? 'checked' : '' ?> class="rounded border-slate-300 text-blue-600 mt-0.5">
            <span>Cuando el archivo no traiga el costo, usar el <strong>costo actual del producto</strong><br>
              <span class="text-xs text-slate-500">Sin costo, el margen del histórico saldrá al 100%.</span></span>
          </label>
          <label class="flex items-start gap-2 text-sm text-slate-600 cursor-pointer sm:col-span-2 xl:col-span-1">
            <input type="hidden" name="crear_clientes" value="0">
            <input type="checkbox" name="crear_clientes" value="1" <?= !empty($opts['crear_clientes']) ? 'checked' : '' ?> class="rounded border-slate-300 text-blue-600 mt-0.5">
            <span>Crear los clientes que no existan<br>
              <span class="text-xs text-slate-500">Si no, esas ventas quedan como consumidor final.</span></span>
          </label>
        <?php else: ?>
          <label class="flex items-start gap-2 text-sm text-slate-600 cursor-pointer sm:col-span-2">
            <input type="hidden" name="actualizar_existentes" value="0">
            <input type="checkbox" name="actualizar_existentes" value="1" <?= !empty($opts['actualizar_existentes']) ? 'checked' : '' ?> class="rounded border-slate-300 text-blue-600 mt-0.5">
            <span>Actualizar los clientes que ya existan<br>
              <span class="text-xs text-slate-500">Solo se completan los campos que el archivo traiga con dato; nunca se borra lo que ya está.</span></span>
          </label>
        <?php endif; ?>
      </div>

      <div class="px-5 py-4 border-t border-slate-100 flex justify-end">
        <button type="submit" class="btn btn-primary"><?= icon('eye', 'w-4 h-4') ?> Revisar qué va a entrar</button>
      </div>
    </div>

    <!-- Resultado de la revisión -->
    <?php if ($paso === 3 && $prev): $r = $prev['resumen']; ?>
      <div class="card">
        <div class="px-5 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800">Revisión</h3>
          <p class="text-xs text-slate-500 mt-0.5">Todavía no se ha escrito nada en el sistema.</p>
        </div>

        <div class="p-5 grid grid-cols-2 lg:grid-cols-4 gap-4">
          <div class="rounded-xl border border-emerald-200 bg-emerald-50/60 p-4">
            <p class="text-xs font-bold uppercase tracking-wide text-emerald-700">Se cargarán</p>
            <p class="text-2xl font-extrabold text-emerald-700 mt-1 tabular-nums"><?= number_format($r['validos']) ?></p>
            <p class="text-xs text-emerald-600/80 mt-0.5"><?= $tipo === 'clientes' ? 'cliente(s) nuevo(s)' : 'venta(s)' ?></p>
          </div>
          <div class="rounded-xl border border-slate-200 p-4">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-400"><?= $tipo === 'clientes' ? 'Ya existían' : 'Ya registradas' ?></p>
            <p class="text-2xl font-extrabold text-slate-700 mt-1 tabular-nums"><?= number_format($r['existentes']) ?></p>
            <p class="text-xs text-slate-400 mt-0.5">se omiten</p>
          </div>
          <div class="rounded-xl border <?= $r['rechazados'] > 0 ? 'border-rose-200 bg-rose-50/50' : 'border-slate-200' ?> p-4">
            <p class="text-xs font-bold uppercase tracking-wide <?= $r['rechazados'] > 0 ? 'text-rose-700' : 'text-slate-400' ?>">Rechazadas</p>
            <p class="text-2xl font-extrabold mt-1 tabular-nums <?= $r['rechazados'] > 0 ? 'text-rose-700' : 'text-slate-700' ?>"><?= number_format($r['rechazados']) ?></p>
            <p class="text-xs text-slate-400 mt-0.5">de <?= number_format($r['filas']) ?> fila(s)</p>
          </div>
          <div class="rounded-xl border border-slate-200 p-4">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-400"><?= $tipo === 'clientes' ? 'A actualizar' : 'Ingreso neto' ?></p>
            <p class="text-2xl font-extrabold text-slate-800 mt-1 tabular-nums">
              <?= $tipo === 'clientes' ? number_format($r['existentes']) : money($r['monto']) ?>
            </p>
            <?php if ($tipo === 'ventas'): ?>
              <p class="text-xs text-slate-400 mt-0.5">subtotal − descuento</p>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($prev['avisos']): ?>
          <div class="px-5 pb-4">
            <div class="rounded-xl border border-amber-200 bg-amber-50/60 p-4">
              <p class="text-sm font-bold text-amber-800 mb-1.5">Avisos</p>
              <ul class="text-sm text-amber-700 space-y-1 list-disc list-inside">
                <?php foreach (array_slice($prev['avisos'], 0, 12) as $a): ?>
                  <li><?= $a['fila'] ? 'Fila ' . (int) $a['fila'] . ': ' : '' ?><?= e($a['motivo']) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($prev['errores']): ?>
          <div class="px-5 pb-4">
            <div class="rounded-xl border border-rose-200 bg-rose-50/50 p-4">
              <p class="text-sm font-bold text-rose-800 mb-1.5">
                Filas que no se cargarán (<?= count($prev['errores']) ?>)
              </p>
              <ul class="text-sm text-rose-700 space-y-1 list-disc list-inside max-h-52 overflow-y-auto">
                <?php foreach (array_slice($prev['errores'], 0, 40) as $er): ?>
                  <li>Fila <?= (int) $er['fila'] ?>: <?= e($er['motivo']) ?></li>
                <?php endforeach; ?>
              </ul>
              <?php if (count($prev['errores']) > 40): ?>
                <p class="text-xs text-rose-600 mt-2">…y <?= count($prev['errores']) - 40 ?> más. Corrige el archivo y vuelve a subirlo si son muchas.</p>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- Muestra de lo que va a entrar -->
        <?php if ($prev['docs']): ?>
          <div class="px-5 pb-5">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-400 mb-2">Primeros registros</p>
            <div class="overflow-x-auto rounded-xl border border-slate-200">
              <table class="data-table">
                <?php if ($tipo === 'clientes'): ?>
                  <thead><tr><th>Nombre</th><th>RNC/Cédula</th><th>Teléfono</th><th>Correo</th><th>Tipo</th></tr></thead>
                  <tbody>
                    <?php foreach (array_slice($prev['docs'], 0, 8) as $d): ?>
                      <tr>
                        <td class="font-medium text-slate-700"><?= e($d['nombre']) ?>
                          <?= $d['existente_id'] ? '<span class="badge badge-slate ml-1">ya existe</span>' : '' ?></td>
                        <td class="text-slate-500"><?= e($d['rnc_cedula'] ?: '—') ?></td>
                        <td class="text-slate-500"><?= e($d['telefono'] ?: '—') ?></td>
                        <td class="text-slate-500"><?= e($d['email'] ?: '—') ?></td>
                        <td><?= badge($d['tipo'] === 'credito' ? 'Crédito' : 'Contado', $d['tipo'] === 'credito' ? 'amber' : 'slate') ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                <?php else: ?>
                  <thead><tr><th>Factura</th><th>Fecha</th><th>Cliente</th><th class="text-center">Líneas</th><th class="text-right">Subtotal</th><th class="text-right">ITBIS</th><th class="text-right">Total</th></tr></thead>
                  <tbody>
                    <?php foreach (array_slice($prev['docs'], 0, 8) as $d): ?>
                      <tr>
                        <td class="font-mono text-sm"><?= e($d['numero'] ?: '(automático)') ?></td>
                        <td class="text-slate-500"><?= fechaCorta($d['fecha']) ?></td>
                        <td class="text-slate-500"><?= e($d['cliente'] ?: 'Consumidor final') ?></td>
                        <td class="text-center"><span class="badge badge-slate"><?= count($d['lineas']) ?></span></td>
                        <td class="text-right tabular-nums"><?= money($d['subtotal'], false) ?></td>
                        <td class="text-right tabular-nums text-slate-500"><?= money($d['itbis'], false) ?></td>
                        <td class="text-right tabular-nums font-semibold"><?= money($d['subtotal'] - $d['descuento'] + $d['itbis'], false) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                <?php endif; ?>
              </table>
            </div>
          </div>
        <?php endif; ?>

        <div class="px-5 py-4 border-t border-slate-100 flex items-center justify-between gap-3 flex-wrap">
          <p class="text-sm text-slate-500">
            <?php if ($tipo === 'ventas'): ?>
              No se moverá inventario, no se consumirá ningún NCF y no se registrará movimiento de caja.
            <?php else: ?>
              Los clientes entran sin balance: las cuentas por cobrar no se ven afectadas.
            <?php endif; ?>
          </p>
          <button type="submit" onclick="document.getElementById('imp_accion').value='cargar'"
                  class="btn btn-success" <?= $prev['docs'] ? '' : 'disabled' ?>>
            <?= icon('check', 'w-4 h-4') ?> Cargar <?= number_format(count($prev['docs'])) ?> registro(s)
          </button>
        </div>
      </div>
    <?php endif; ?>
  </form>

  <form method="post" id="frm_descartar" class="hidden">
    <?= csrf_field() ?><input type="hidden" name="accion" value="descartar">
  </form>

<?php endif; ?>

<!-- Historial de lotes -->
<div class="card overflow-hidden mt-5">
  <div class="px-5 py-4 border-b border-slate-100">
    <h3 class="font-bold text-slate-800">Cargas anteriores</h3>
    <p class="text-xs text-slate-500 mt-0.5">Cada lote se puede revertir por completo.</p>
  </div>
  <?php if (!$lotes): ?>
    <p class="px-5 py-8 text-center text-sm text-slate-400">Todavía no se ha cargado ningún archivo.</p>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr>
          <th>Lote</th><th>Archivo</th><th class="text-center">Filas</th>
          <th class="text-center">Creados</th><th class="text-right">Monto</th>
          <th>Quién y cuándo</th><th>Estado</th><th class="text-right"></th>
        </tr></thead>
        <tbody>
          <?php foreach ($lotes as $l): ?>
            <tr class="<?= $l['estado'] === 'revertida' ? 'opacity-60' : '' ?>">
              <td><span class="font-mono text-sm">#<?= (int) $l['id'] ?></span>
                <p class="text-xs text-slate-400"><?= $l['tipo'] === 'clientes' ? 'Clientes' : 'Ventas' ?></p></td>
              <td class="text-slate-500 text-sm max-w-[220px] truncate" title="<?= e($l['archivo']) ?>"><?= e($l['archivo'] ?: '—') ?></td>
              <td class="text-center tabular-nums"><?= number_format((int) $l['filas']) ?></td>
              <td class="text-center">
                <span class="badge badge-emerald"><?= number_format((int) $l['creados']) ?></span>
                <?php if ((int) $l['actualizados'] > 0): ?>
                  <p class="text-[11px] text-slate-400 mt-0.5"><?= number_format((int) $l['actualizados']) ?> actualizados</p>
                <?php endif; ?>
              </td>
              <td class="text-right tabular-nums"><?= (float) $l['monto'] > 0 ? money($l['monto'], false) : '—' ?></td>
              <td class="text-slate-500 text-sm"><?= e($l['usuario'] ?: '—') ?><br>
                <span class="text-xs text-slate-400"><?= fechaHora($l['created_at']) ?></span></td>
              <td><?= $l['estado'] === 'revertida' ? badge('Revertida', 'rose') : badge('Procesada', 'emerald') ?></td>
              <td class="text-right">
                <?php if ($l['estado'] !== 'revertida'): ?>
                  <form method="post" class="inline"
                        onsubmit="return confirm('¿Revertir el lote #<?= (int) $l['id'] ?>? Se eliminarán los registros que entraron con él.')">
                    <?= csrf_field() ?><input type="hidden" name="accion" value="revertir"><input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                    <button class="btn btn-ghost btn-sm text-rose-600 hover:bg-rose-50"><?= icon('undo', 'w-3.5 h-3.5') ?> Revertir</button>
                  </form>
                <?php else: ?>
                  <span class="text-xs text-slate-400"><?= fechaCorta($l['revertida_at']) ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php layout_end(); ?>
