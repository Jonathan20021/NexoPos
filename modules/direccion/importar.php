<?php
/**
 * Carga masiva desde Excel o CSV.
 *
 * Flujo en tres pasos, y el del medio es el importante:
 *   1. Subir el archivo (CSV o Excel).
 *   2. **Revisar**: el sistema dice exactamente qué va a entrar, qué va a
 *      rechazar y por qué. Nada se escribe todavía.
 *   3. Cargar. Y si algo salió mal, revertir el lote entero con un botón.
 *
 * Cinco tipos: clientes, ventas, catálogo, existencias y packing list. Cada uno
 * tiene sus campos, sus opciones y su forma de revertirse; la pantalla los saca
 * de `imp_tipos()` en vez de decidirlos aquí, para que añadir el sexto no
 * obligue a encontrar una docena de condicionales repartidos por la vista.
 *
 * Nada de lo que se carga escribe stock. Las existencias dejan un conteo en
 * borrador y el ajuste entra al kardex cuando alguien lo aplica. Ver la
 * cabecera de includes/importador.php.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('direccion.importar');

if (!imp_disponible()) {
    layout_start('Cargar datos', 'Catálogo, existencias, embarques e histórico');
    echo empty_state('Falta aplicar la migración',
        'Ejecuta database/migracion_tiendas_p16.sql para habilitar la carga de archivos.', 'alert');
    layout_end();
    return;
}
$masiva = imp_masiva_disponible();

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
        $tipo = imp_tipo_valido(post('tipo'));
        if (!$masiva && !in_array($tipo, ['clientes', 'ventas'], true)) {
            flash('error', 'Falta aplicar database/migracion_carga_masiva_p19.sql para cargar ' . imp_tipo($tipo)['etiqueta'] . '.');
            redirect('modules/direccion/importar.php');
        }
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
            // Tipos de inventario
            'crear_catalogos'       => post('crear_catalogos') === '1',
            'crear_productos'       => post('crear_productos') === '1',
            'cotejar_por_nombre'    => post('cotejar_por_nombre') === '1',
            'liquidacion_id'        => postInt('liquidacion_id'),
            'descripcion'           => trim((string) post('descripcion')),
        ];

        // Faltan obligatorios: se avisa antes de recorrer 12.000 filas.
        $faltan = [];
        foreach (imp_campos($ses['tipo']) as $campo => $cfg) {
            if (!empty($cfg['req']) && !isset($mapa[$campo])) $faltan[] = $cfg['label'];
        }
        if ($ses['tipo'] === 'ventas' && !isset($mapa['subtotal']) && !isset($mapa['total']) && !isset($mapa['precio'])) {
            $faltan[] = 'algún importe (subtotal, total o precio)';
        }
        if (imp_tipo($ses['tipo'])['sucursal'] === 'req' && $opts['sucursal_id'] <= 0 && !isset($mapa['sucursal'])) {
            $faltan[] = 'la sucursal (elige una por defecto o mapea la columna)';
        }
        if ($ses['tipo'] === 'existencias' && $opts['sucursal_id'] <= 0 && !isset($mapa['sucursal'])) {
            $faltan[] = 'la sucursal: sin ella no se sabe de qué almacén son esas cantidades';
        }
        if (imp_tipo($ses['tipo'])['destino'] === 'liquidacion' && $opts['liquidacion_id'] <= 0) {
            $faltan[] = 'la liquidación destino';
        }
        if ($ses['tipo'] === 'productos' && !isset($mapa['codigo']) && !isset($mapa['codigo_barras'])) {
            $faltan[] = 'el código o el código de barras (sin uno de los dos no se puede saber qué producto ya existe)';
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

                $meta = imp_tipo($ses['tipo']);
                $msg = 'Carga completada. Se registraron ' . number_format(count($prev['docs'])) . ' ' . $meta['crea'] . '.';
                if ($ses['tipo'] === 'ventas')      $msg .= ' Ingreso neto: ' . money($prev['resumen']['monto']) . '.';
                if ($ses['tipo'] === 'embarque')    $msg .= ' FOB del embarque: ' . number_format($prev['resumen']['monto'], 2) . '.';
                if ($ses['tipo'] === 'existencias') {
                    $msg .= ' NO se movió stock: revisa cada conteo y aplícalo para que el ajuste entre al kardex.';
                }
                flash('success', $msg);
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

            // Cada tipo deshace cosas distintas; se cuenta lo que devolvió.
            $partes = [];
            if (isset($r['ventas']))        $partes[] = number_format($r['ventas']) . ' venta(s)';
            if (isset($r['clientes']))      $partes[] = number_format($r['clientes']) . ' cliente(s)';
            if (isset($r['productos']))     $partes[] = number_format($r['productos']) . ' producto(s)';
            if (isset($r['conteos']))       $partes[] = number_format($r['conteos']) . ' conteo(s)';
            if (isset($r['lineas']))        $partes[] = number_format($r['lineas']) . ' línea(s) de embarque';
            $msg = 'Lote revertido: se eliminaron ' . ($partes ? implode(', ', $partes) : 'los registros del lote') . '.';

            $conservados = 0;
            if (isset($r['clientes_conservados']))  $conservados += max(0, $r['clientes_conservados'] - ($r['clientes'] ?? 0));
            if (isset($r['productos_conservados'])) $conservados += (int) $r['productos_conservados'];
            if ($conservados > 0) {
                $msg .= ' Se conservaron ' . number_format($conservados)
                     . ' registro(s) que ya tienen movimientos propios: borrarlos se llevaría datos reales por delante.';
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

$tipo    = imp_tipo_valido($ses['tipo'] ?? 'ventas');
$meta    = imp_tipo($tipo);
$campos  = imp_campos($tipo);
$headers = $ses['headers'] ?? [];
$mapa    = $ses['mapa'] ?? ($headers ? imp_automapear($headers, $tipo) : []);
$opts    = $ses['opts'] ?? [];
$sucursales = sucursales_visibles();
$lotes   = imp_lotes(15);

// Liquidaciones que todavía admiten líneas, para el packing list.
$liqAbiertas = [];
if ($masiva && $tipo === 'embarque') {
    $liqAbiertas = qAll(
        "SELECT l.id, l.numero, l.referencia, l.fecha, l.estado, p.nombre AS proveedor
           FROM liquidaciones l LEFT JOIN proveedores p ON p.id = l.proveedor_id
          WHERE l.estado IN ('borrador','transito')
          ORDER BY l.id DESC LIMIT 50"
    );
}

layout_start('Cargar datos', 'Catálogo, existencias, embarques e histórico — desde Excel o CSV');
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
        <div x-data="{tipo:'productos'}">
          <span class="label">¿Qué vas a cargar?</span>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-1">
            <?php foreach (imp_tipos() as $k => $t):
              $bloqueado = !$masiva && !in_array($k, ['clientes', 'ventas'], true); ?>
              <label class="rounded-xl border p-4 transition <?= $bloqueado ? 'opacity-50 cursor-not-allowed border-slate-200' : 'cursor-pointer' ?>"
                     <?= $bloqueado ? '' : ':class="tipo===\'' . $k . '\' ? \'border-blue-500 bg-blue-50/50 ring-1 ring-blue-200\' : \'border-slate-200 hover:border-slate-300\'"' ?>>
                <input type="radio" name="tipo" value="<?= e($k) ?>" x-model="tipo" class="sr-only" <?= $bloqueado ? 'disabled' : '' ?>>
                <p class="font-semibold text-slate-800 flex items-center gap-2">
                  <?= icon($t['icono'], 'w-4 h-4') ?> <?= e($t['etiqueta']) ?>
                </p>
                <p class="text-xs text-slate-500 mt-1"><?= e($t['ayuda']) ?></p>
                <?php if ($bloqueado): ?>
                  <p class="text-xs text-amber-600 mt-1.5 font-semibold">Falta aplicar la migración P19.</p>
                <?php endif; ?>
              </label>
            <?php endforeach; ?>
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
        <li class="flex gap-2"><span class="text-emerald-600 shrink-0"><?= icon('check', 'w-4 h-4') ?></span> Antes de escribir nada te dice cuántas filas entran, cuántas se rechazan y por qué.</li>
        <li class="flex gap-2"><span class="text-emerald-600 shrink-0"><?= icon('check', 'w-4 h-4') ?></span> Adivina el mapeo de columnas: reconoce los encabezados en español y en inglés.</li>
        <li class="flex gap-2"><span class="text-emerald-600 shrink-0"><?= icon('check', 'w-4 h-4') ?></span> Todo queda marcado con su lote y se puede revertir de un golpe.</li>
        <li class="flex gap-2"><span class="text-emerald-600 shrink-0"><?= icon('check', 'w-4 h-4') ?></span> Reimportar el mismo archivo no duplica: lo que ya está se actualiza o se omite.</li>
        <li class="flex gap-2"><span class="text-slate-400 shrink-0"><?= icon('x', 'w-4 h-4') ?></span> <strong>No</strong> escribe existencias. Las cantidades del almacén dejan un <strong>conteo en borrador</strong> para revisar y aplicar, y así el ajuste queda en el kardex con su motivo.</li>
        <li class="flex gap-2"><span class="text-slate-400 shrink-0"><?= icon('x', 'w-4 h-4') ?></span> <strong>No</strong> consume NCF ni mueve caja: el histórico ya ocurrió en otro sistema.</li>
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
          <h3 class="font-bold text-slate-800">Mapear columnas · <?= e($meta['etiqueta']) ?></h3>
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
        <?php elseif ($tipo === 'clientes'): ?>
          <label class="flex items-start gap-2 text-sm text-slate-600 cursor-pointer sm:col-span-2">
            <input type="hidden" name="actualizar_existentes" value="0">
            <input type="checkbox" name="actualizar_existentes" value="1" <?= !empty($opts['actualizar_existentes']) ? 'checked' : '' ?> class="rounded border-slate-300 text-blue-600 mt-0.5">
            <span>Actualizar los clientes que ya existan<br>
              <span class="text-xs text-slate-500">Solo se completan los campos que el archivo traiga con dato; nunca se borra lo que ya está.</span></span>
          </label>

        <?php elseif ($tipo === 'productos'): ?>
          <label class="flex items-start gap-2 text-sm text-slate-600 cursor-pointer sm:col-span-2 xl:col-span-1">
            <input type="hidden" name="crear_catalogos" value="0">
            <input type="checkbox" name="crear_catalogos" value="1" <?= !empty($opts['crear_catalogos']) ? 'checked' : '' ?> class="rounded border-slate-300 text-blue-600 mt-0.5">
            <span>Crear las categorías, marcas y unidades que falten<br>
              <span class="text-xs text-slate-500">Si no, esos productos entran sin clasificar y hay que asignarlos a mano.</span></span>
          </label>
          <label class="flex items-start gap-2 text-sm text-slate-600 cursor-pointer sm:col-span-2 xl:col-span-1">
            <input type="hidden" name="actualizar_existentes" value="0">
            <input type="checkbox" name="actualizar_existentes" value="1" <?= !empty($opts['actualizar_existentes']) ? 'checked' : '' ?> class="rounded border-slate-300 text-blue-600 mt-0.5">
            <span>Actualizar los productos que ya existan<br>
              <span class="text-xs text-slate-500">Actualiza precios y datos, pero <strong>nunca pisa el nombre</strong> que ya tenías ni borra lo que el archivo no trae.</span></span>
          </label>

        <?php elseif ($tipo === 'existencias'): ?>
          <div>
            <label class="label" for="imp_suc">Sucursal / almacén</label>
            <select id="imp_suc" name="sucursal_id" class="select">
              <option value="">— Usar la columna del archivo —</option>
              <?php foreach ($sucursales as $s): ?>
                <option value="<?= (int) $s['id'] ?>" <?= (int) ($opts['sucursal_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="mt-1 text-xs text-slate-500">Se genera un conteo por cada sucursal que aparezca.</p>
          </div>
          <div>
            <label class="label" for="imp_desc">Nombre del conteo</label>
            <input type="text" id="imp_desc" name="descripcion" class="input" maxlength="120"
                   value="<?= e($opts['descripcion'] ?? '') ?>" placeholder="Inventario inicial 2026">
            <p class="mt-1 text-xs text-slate-500">Se le añade el nombre de la sucursal.</p>
          </div>
          <label class="flex items-start gap-2 text-sm text-slate-600 cursor-pointer sm:col-span-2 xl:col-span-1">
            <input type="hidden" name="cotejar_por_nombre" value="0">
            <input type="checkbox" name="cotejar_por_nombre" value="1" <?= !empty($opts['cotejar_por_nombre']) ? 'checked' : '' ?> class="rounded border-slate-300 text-blue-600 mt-0.5">
            <span>Buscar también por nombre exacto<br>
              <span class="text-xs text-slate-500">Solo si el nombre no se repite en el catálogo. Lo seguro es cotejar por código o por barras.</span></span>
          </label>

        <?php elseif ($tipo === 'embarque'): ?>
          <div class="sm:col-span-2">
            <label class="label" for="imp_liq">Liquidación destino *</label>
            <?php if (!$liqAbiertas): ?>
              <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-xl p-3">
                No hay ninguna liquidación abierta. Créala primero en
                <a class="underline font-semibold" href="<?= e(url('modules/inventario/liquidaciones.php')) ?>">Liquidaciones</a>
                con su proveedor, moneda y tasa: el packing list se vuelca dentro de una que ya exista.
              </p>
            <?php else: ?>
              <select id="imp_liq" name="liquidacion_id" class="select" required>
                <option value="">— Escoge la liquidación —</option>
                <?php foreach ($liqAbiertas as $l): ?>
                  <option value="<?= (int) $l['id'] ?>" <?= (int) ($opts['liquidacion_id'] ?? 0) === (int) $l['id'] ? 'selected' : '' ?>>
                    <?= e($l['numero']) ?> · <?= e($l['proveedor'] ?: 'sin proveedor') ?> · <?= fechaCorta($l['fecha']) ?> (<?= e($l['estado']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
              <p class="mt-1 text-xs text-slate-500">Solo las que siguen en borrador o en tránsito. El costo se convierte con la tasa de esa liquidación.</p>
            <?php endif; ?>
          </div>
          <label class="flex items-start gap-2 text-sm text-slate-600 cursor-pointer sm:col-span-2 xl:col-span-1">
            <input type="hidden" name="crear_productos" value="0">
            <input type="checkbox" name="crear_productos" value="1" <?= !empty($opts['crear_productos']) ? 'checked' : '' ?> class="rounded border-slate-300 text-blue-600 mt-0.5">
            <span>Dar de alta los productos que no existan<br>
              <span class="text-xs text-slate-500">Lo normal en un contenedor con artículos nuevos. Nacen con el código y el costo del embarque.</span></span>
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

        <?php
        // Las cuatro cifras que importan cambian con el tipo: en un catálogo lo
        // relevante es cuántos son nuevos y cuántos se actualizan; en un almacén,
        // cuántos conteos salen y cuántos difieren de lo que dice el sistema.
        switch ($tipo) {
            case 'clientes':
                $tarjeta1 = [number_format($r['validos']), 'cliente(s)'];
                $tarjeta2 = ['Ya existían', number_format($r['existentes']), 'se actualizan o se omiten'];
                $tarjeta4 = ['A actualizar', number_format($r['existentes']), ''];
                break;
            case 'productos':
                $tarjeta1 = [number_format($r['nuevos']), 'producto(s) nuevo(s)'];
                $tarjeta2 = ['Ya en el catálogo', number_format($r['existentes']), 'se actualizan'];
                $tarjeta4 = ['Se van a escribir', number_format($r['validos']), 'filas válidas en total'];
                break;
            case 'existencias':
                $tarjeta1 = [number_format($r['validos']), 'línea(s) de conteo'];
                $tarjeta2 = ['Conteos a crear', number_format($r['nuevos']), 'uno por sucursal, en borrador'];
                $tarjeta4 = ['Difieren del sistema', number_format($r['con_diferencia'] ?? 0), 'esas son las que ajustan'];
                break;
            case 'embarque':
                $tarjeta1 = [number_format($r['validos']), 'línea(s) del embarque'];
                $tarjeta2 = ['Productos nuevos', number_format($r['nuevos']), 'se dan de alta'];
                $tarjeta4 = ['FOB del archivo', number_format($r['monto'], 2), 'en la moneda del embarque'];
                break;
            default:
                $tarjeta1 = [number_format($r['validos']), 'venta(s)'];
                $tarjeta2 = ['Ya registradas', number_format($r['existentes']), 'se omiten'];
                $tarjeta4 = ['Ingreso neto', money($r['monto']), 'subtotal − descuento'];
        }
        ?>
        <div class="p-5 grid grid-cols-2 lg:grid-cols-4 gap-4">
          <div class="rounded-xl border border-emerald-200 bg-emerald-50/60 p-4">
            <p class="text-xs font-bold uppercase tracking-wide text-emerald-700">Se cargarán</p>
            <p class="text-2xl font-extrabold text-emerald-700 mt-1 tabular-nums"><?= $tarjeta1[0] ?></p>
            <p class="text-xs text-emerald-600/80 mt-0.5"><?= e($tarjeta1[1]) ?></p>
          </div>
          <div class="rounded-xl border border-slate-200 p-4">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-400"><?= e($tarjeta2[0]) ?></p>
            <p class="text-2xl font-extrabold text-slate-700 mt-1 tabular-nums"><?= $tarjeta2[1] ?></p>
            <p class="text-xs text-slate-400 mt-0.5"><?= e($tarjeta2[2]) ?></p>
          </div>
          <div class="rounded-xl border <?= $r['rechazados'] > 0 ? 'border-rose-200 bg-rose-50/50' : 'border-slate-200' ?> p-4">
            <p class="text-xs font-bold uppercase tracking-wide <?= $r['rechazados'] > 0 ? 'text-rose-700' : 'text-slate-400' ?>">Rechazadas</p>
            <p class="text-2xl font-extrabold mt-1 tabular-nums <?= $r['rechazados'] > 0 ? 'text-rose-700' : 'text-slate-700' ?>"><?= number_format($r['rechazados']) ?></p>
            <p class="text-xs text-slate-400 mt-0.5">de <?= number_format($r['filas']) ?> fila(s)</p>
          </div>
          <div class="rounded-xl border border-slate-200 p-4">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-400"><?= e($tarjeta4[0]) ?></p>
            <p class="text-2xl font-extrabold text-slate-800 mt-1 tabular-nums"><?= $tarjeta4[1] ?></p>
            <?php if ($tarjeta4[2] !== ''): ?>
              <p class="text-xs text-slate-400 mt-0.5"><?= e($tarjeta4[2]) ?></p>
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
                <?php elseif ($tipo === 'productos'): ?>
                  <thead><tr><th>Código</th><th>Nombre</th><th>Categoría</th><th class="text-right">Costo</th><th class="text-right">Precio</th><th></th></tr></thead>
                  <tbody>
                    <?php foreach (array_slice($prev['docs'], 0, 8) as $d): ?>
                      <tr>
                        <td class="font-mono text-sm"><?= e($d['codigo'] ?: '(automático)') ?></td>
                        <td class="font-medium text-slate-700"><?= e($d['nombre']) ?></td>
                        <td class="text-slate-500"><?= e($d['categoria'] ?: '—') ?></td>
                        <td class="text-right tabular-nums text-slate-500"><?= money($d['precio_compra'], false) ?></td>
                        <td class="text-right tabular-nums font-semibold"><?= money($d['precio_venta'], false) ?></td>
                        <td><?= $d['existente_id'] ? badge('actualiza', 'slate') : badge('nuevo', 'emerald') ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>

                <?php elseif ($tipo === 'existencias'): ?>
                  <thead><tr><th>Sucursal</th><th class="text-center">Productos</th><th class="text-right">Unidades contadas</th><th class="text-right">Difieren</th><th></th></tr></thead>
                  <tbody>
                    <?php foreach (array_slice($prev['docs'], 0, 12) as $d):
                      $u = array_sum(array_column($d['lineas'], 'stock_contado'));
                      $dif = count(array_filter($d['lineas'], fn($l) => abs($l['stock_teorico'] - $l['stock_contado']) > 0.0001)); ?>
                      <tr>
                        <td class="font-medium text-slate-700"><?= e($d['sucursal']) ?></td>
                        <td class="text-center"><span class="badge badge-slate"><?= count($d['lineas']) ?></span></td>
                        <td class="text-right tabular-nums"><?= number_format($u, 2) ?></td>
                        <td class="text-right tabular-nums <?= $dif ? 'text-amber-700 font-semibold' : 'text-slate-400' ?>"><?= number_format($dif) ?></td>
                        <td><?= badge('conteo en borrador', 'amber') ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>

                <?php elseif ($tipo === 'embarque'): ?>
                  <thead><tr><th>Código</th><th>Descripción</th><th class="text-right">Cantidad</th><th class="text-right">Costo unit.</th><th class="text-right">FOB línea</th><th></th></tr></thead>
                  <tbody>
                    <?php foreach (array_slice($prev['docs'], 0, 8) as $d): ?>
                      <tr>
                        <td class="font-mono text-sm"><?= e($d['codigo']) ?></td>
                        <td class="text-slate-600"><?= e($d['nombre']) ?>
                          <?php if (count($d['filas']) > 1): ?>
                            <span class="badge badge-slate ml-1"><?= count($d['filas']) ?> filas agrupadas</span>
                          <?php endif; ?></td>
                        <td class="text-right tabular-nums"><?= number_format($d['cantidad'], 2) ?></td>
                        <td class="text-right tabular-nums text-slate-500"><?= number_format($d['costo_moneda'], 4) ?></td>
                        <td class="text-right tabular-nums font-semibold"><?= number_format($d['cantidad'] * $d['costo_moneda'], 2) ?></td>
                        <td><?php if (!$d['producto_id']) { echo badge('alta nueva', 'emerald'); }
                                elseif ($d['ya_estaba']) { echo badge('reemplaza', 'amber'); } ?></td>
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
          <p class="text-sm <?= $tipo === 'existencias' ? 'text-amber-700 font-semibold' : 'text-slate-500' ?>">
            <?php if ($tipo === 'ventas'): ?>
              No se moverá inventario, no se consumirá ningún NCF y no se registrará movimiento de caja.
            <?php elseif ($tipo === 'clientes'): ?>
              Los clientes entran sin balance: las cuentas por cobrar no se ven afectadas.
            <?php elseif ($tipo === 'productos'): ?>
              El catálogo entra sin existencia: el stock se carga aparte, con un conteo.
            <?php elseif ($tipo === 'existencias'): ?>
              Esto NO escribe el stock. Deja los conteos en borrador; el ajuste se mueve cuando los apliques.
            <?php elseif ($tipo === 'embarque'): ?>
              Las líneas entran en la liquidación, que sigue en borrador: el costo no llega al catálogo hasta que la apliques.
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
                <p class="text-xs text-slate-400"><?= e(imp_tipo($l['tipo'])['etiqueta']) ?></p></td>
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
