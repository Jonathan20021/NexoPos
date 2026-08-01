<?php
/**
 * Activos fijos y depreciación.
 *
 * El mostrador, la nevera, la camioneta y las computadoras: patrimonio de la
 * empresa que se desgasta cada mes. Sin este registro el balance general
 * subestima el activo y el estado de resultados se salta la depreciación, que
 * es un gasto real aunque no salga dinero de la caja.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('activos.ver');

// La comprobación va ANTES de cualquier consulta: si el código se despliega
// antes de correr la migración, esta pantalla debe explicar qué falta, no
// reventar con un error de tabla inexistente.
if (!activosDisponible()) {
    layout_start('Activos fijos', 'Patrimonio de la empresa y su depreciación mensual');
    echo '<div class="card p-6 flex items-start gap-4 border-amber-200 bg-amber-50/50">'
        . '<span class="w-11 h-11 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center shrink-0">'
        . icon('alert', 'w-5 h-5') . '</span><div>'
        . '<h3 class="font-bold text-slate-800">Falta aplicar la migración</h3>'
        . '<p class="text-sm text-slate-600 mt-1 leading-relaxed">El módulo de activos fijos necesita dos tablas nuevas. '
        . 'Ejecuta <code class="px-1.5 py-0.5 rounded bg-white border border-amber-200 text-xs">database/migracion_activos_p6.sql</code> '
        . 'sobre la base de datos y vuelve a entrar. Mientras tanto, el resto del sistema funciona con normalidad.</p>'
        . '</div></div>';
    layout_end();
    return;
}

$hoy = date('Y-m');

if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    /* ---------- Registrar / editar ---------- */
    if ($accion === 'guardar') {
        $aid = postInt('id');
        try {
            $nombre = trim(post('nombre'));
            $costo  = postNum('costo');
            $residual = postNum('valor_residual');
            $vida   = postInt('vida_util_meses');
            $fecha  = post('fecha_adquisicion') ?: date('Y-m-d');
            $sucId  = postInt('sucursal_id') ?: null;
            $catDgii = postInt('categoria_dgii');
            $tipo   = post('tipo');

            if ($nombre === '')                       throw new RuntimeException('Ponle un nombre al activo.');
            if ($costo <= 0)                          throw new RuntimeException('El costo debe ser mayor que cero.');
            if ($residual < 0 || $residual >= $costo) throw new RuntimeException('El valor residual debe ser menor que el costo (puede ser cero).');
            if ($vida < 1 || $vida > 600)             throw new RuntimeException('La vida útil debe estar entre 1 y 600 meses.');
            if (!strtotime($fecha) || $fecha > date('Y-m-d')) throw new RuntimeException('La fecha de adquisición no puede ser futura.');
            if (!isset(activoCategoriasDgii()[$catDgii])) throw new RuntimeException('Categoría DGII inválida.');
            if (!isset(activoTipos()[$tipo]))         throw new RuntimeException('Tipo de activo inválido.');
            if ($sucId !== null) require_sucursal_access($sucId);

            $datos = [
                'nombre' => $nombre, 'descripcion' => trim(post('descripcion')) ?: null,
                'categoria_dgii' => $catDgii, 'tipo' => $tipo,
                'sucursal_id' => $sucId, 'proveedor_id' => postInt('proveedor_id') ?: null,
                'factura' => trim(post('factura')) ?: null,
                'fecha_adquisicion' => $fecha, 'costo' => $costo,
                'valor_residual' => $residual, 'vida_util_meses' => $vida,
                'notas' => trim(post('notas')) ?: null,
            ];

            if ($aid > 0) {
                require_perm('activos.editar');
                $orig = qOne("SELECT * FROM activos_fijos WHERE id = ?", [$aid]);
                if (!$orig) throw new RuntimeException('Activo no encontrado.');
                // Con depreciación ya registrada, tocar el costo o la vida útil
                // descuadraría los asientos que ya afectaron el resultado.
                if ((float) $orig['depreciacion_acumulada'] > 0
                    && (abs((float) $orig['costo'] - $costo) > 0.004
                        || (int) $orig['vida_util_meses'] !== $vida
                        || abs((float) $orig['valor_residual'] - $residual) > 0.004)) {
                    throw new RuntimeException('Este activo ya tiene depreciación registrada: no se puede cambiar su costo, vida útil ni valor residual. Da de baja el activo y registra uno nuevo si el dato estaba mal.');
                }
                dbUpdate('activos_fijos', $datos, 'id = ?', [$aid]);
                audit('activos', 'editar', 'Activo actualizado: ' . $nombre, ['tabla' => 'activos_fijos', 'registro_id' => $aid]);
                flash('success', 'Activo actualizado.');
            } else {
                require_perm('activos.crear');
                $nid = txReintentable(function () use ($datos) {
                    $datos['codigo'] = nextNumero('activos_fijos', 'codigo', 'AF');
                    $datos['estado'] = 'activo';
                    $datos['usuario_id'] = current_user()['id'] ?? null;
                    return dbInsert('activos_fijos', $datos);
                });
                audit('activos', 'crear', 'Activo registrado: ' . $nombre, ['tabla' => 'activos_fijos', 'registro_id' => $nid]);
                flash('success', 'Activo registrado. Se depreciará desde el mes siguiente a su adquisición.');
            }
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/finanzas/activos.php');
    }

    /* ---------- Correr la depreciación ---------- */
    if ($accion === 'depreciar') {
        require_perm('activos.depreciar');
        try {
            $hasta = post('hasta') ?: $hoy;
            if (!preg_match('/^\d{4}-\d{2}$/', $hasta)) throw new RuntimeException('Periodo inválido.');
            if ($hasta > $hoy) throw new RuntimeException('No se puede depreciar un mes que todavía no ha terminado.');

            $r = activosCorrerDepreciacion($hasta, current_sucursal_id());
            audit('activos', 'depreciar', 'Depreciación corrida hasta ' . $hasta . ': ' . $r['asientos'] . ' asiento(s) por ' . money($r['total']));

            if ($r['asientos'] === 0) {
                flash('info', 'No había depreciación pendiente hasta ' . $hasta . '.');
            } else {
                flash('success', 'Depreciación registrada: ' . $r['asientos'] . ' asiento(s) sobre '
                    . $r['activos'] . ' activo(s) por un total de ' . money($r['total']) . '.');
            }
            if ($r['errores']) {
                flash('warning', 'No se pudo depreciar: ' . implode(' · ', array_slice($r['errores'], 0, 3)));
            }
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/finanzas/activos.php');
    }

    /* ---------- Dar de baja / vender ---------- */
    if ($accion === 'baja') {
        require_perm('activos.baja');
        try {
            $aid = postInt('id');
            $motivo = trim(post('motivo_baja'));
            $vendido = post('destino') === 'vendido';
            $valorVenta = $vendido ? postNum('valor_venta') : null;
            if ($motivo === '') throw new RuntimeException('Indica el motivo de la baja.');
            if ($vendido && $valorVenta <= 0) throw new RuntimeException('Indica el valor de la venta.');

            txReintentable(function () use ($aid, $motivo, $vendido, $valorVenta) {
                $a = qOne("SELECT * FROM activos_fijos WHERE id = ? FOR UPDATE", [$aid]);
                if (!$a) throw new RuntimeException('Activo no encontrado.');
                if (in_array($a['estado'], ['baja', 'vendido'], true)) throw new RuntimeException('Este activo ya fue dado de baja.');

                dbUpdate('activos_fijos', [
                    'estado' => $vendido ? 'vendido' : 'baja',
                    'fecha_baja' => date('Y-m-d'),
                    'motivo_baja' => $motivo,
                    'valor_venta' => $vendido ? $valorVenta : null,
                ], 'id = ?', [$aid]);

                // La venta de un activo entra como ingreso; el valor en libros que
                // se pierde no se registra aquí para no duplicar la depreciación.
                if ($vendido && $valorVenta > 0) {
                    registrarTransaccion('ingreso', $valorVenta, [
                        'sucursal_id' => $a['sucursal_id'] !== null ? (int) $a['sucursal_id'] : null,
                        'cuenta_id' => cuentaFinancieraIdPorTipo('efectivo', $a['sucursal_id'] !== null ? (int) $a['sucursal_id'] : null),
                        'categoria_id' => categoriaFinancieraId('ingreso', 'Venta de activos'),
                        'descripcion' => 'Venta de activo ' . $a['codigo'] . ' · ' . $a['nombre'],
                        'referencia_tipo' => 'activo_venta', 'referencia_id' => $aid,
                    ]);
                }
            });

            audit('activos', 'baja', ($vendido ? 'Activo vendido: ' : 'Activo dado de baja: ') . $motivo, ['tabla' => 'activos_fijos', 'registro_id' => $aid]);
            flash('success', $vendido ? 'Activo vendido y el ingreso quedó registrado.' : 'Activo dado de baja.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/finanzas/activos.php');
    }
}

/* ============================================================
 *  Datos
 * ============================================================ */
[$scope, $sp] = sucursalFiltro('a.sucursal_id');
// Un activo sin sucursal es de la empresa: se ve desde cualquier alcance.
$scope = '(' . $scope . ' OR a.sucursal_id IS NULL)';

$estado = in_array(get('estado'), ['activo', 'depreciado', 'baja', 'vendido'], true) ? get('estado') : '';
$q = trim((string) get('q'));
$cond = [$scope];
$par  = $sp;
if ($estado !== '') { $cond[] = 'a.estado = ?'; $par[] = $estado; }
if ($q !== '')      { $cond[] = '(a.nombre LIKE ? OR a.codigo LIKE ?)'; array_push($par, "%$q%", "%$q%"); }
$where = implode(' AND ', $cond);

$resumen = qOne(
    "SELECT COUNT(*) n,
            COALESCE(SUM(CASE WHEN a.estado IN ('activo','depreciado') THEN a.costo ELSE 0 END),0) costo,
            COALESCE(SUM(CASE WHEN a.estado IN ('activo','depreciado') THEN a.depreciacion_acumulada ELSE 0 END),0) depreciacion,
            COALESCE(SUM(CASE WHEN a.estado = 'activo' THEN 1 ELSE 0 END),0) activos
       FROM activos_fijos a WHERE $scope",
    $sp
) ?: ['n' => 0, 'costo' => 0, 'depreciacion' => 0, 'activos' => 0];
$valorNeto = (float) $resumen['costo'] - (float) $resumen['depreciacion'];

// Depreciación del mes en curso ya registrada.
$depMes = (float) qVal(
    "SELECT COALESCE(SUM(d.monto),0) FROM depreciaciones d JOIN activos_fijos a ON a.id = d.activo_id
      WHERE d.periodo = ? AND $scope",
    array_merge([$hoy], $sp)
);

$pg = paginar((int) qVal("SELECT COUNT(*) FROM activos_fijos a WHERE $where", $par), 25);
$activos = qAll(
    "SELECT a.*, su.nombre AS sucursal, pr.nombre AS proveedor
       FROM activos_fijos a
       LEFT JOIN sucursales su ON su.id = a.sucursal_id
       LEFT JOIN proveedores pr ON pr.id = a.proveedor_id
      WHERE $where ORDER BY a.estado = 'activo' DESC, a.codigo DESC
      LIMIT {$pg['porPagina']} OFFSET {$pg['offset']}",
    $par
);

// Cuánta depreciación queda pendiente de registrar.
$pendientes = 0; $montoPendiente = 0.0;
foreach (qAll("SELECT * FROM activos_fijos a WHERE a.estado = 'activo' AND $scope", $sp) as $a) {
    $ps = activoPeriodosPendientes($a, $hoy);
    if ($ps) { $pendientes++; $montoPendiente += min(count($ps) * activoCuotaMensual($a), activoPendiente($a)); }
}

if (export_solicitado()) {
    $todos = qAll(
        "SELECT a.codigo, a.nombre, a.tipo, a.categoria_dgii, su.nombre sucursal, a.fecha_adquisicion,
                a.costo, a.valor_residual, a.vida_util_meses, a.depreciacion_acumulada, a.estado
           FROM activos_fijos a LEFT JOIN sucursales su ON su.id = a.sucursal_id
          WHERE $where ORDER BY a.codigo",
        $par
    );
    $filas = [];
    foreach ($todos as $t) {
        $cat = activoCategoriasDgii()[(int) $t['categoria_dgii']][0] ?? '';
        $filas[] = [$t['codigo'], $t['nombre'], activoTipos()[$t['tipo']] ?? $t['tipo'], $cat,
            $t['sucursal'] ?? 'Empresa', fechaCorta($t['fecha_adquisicion']),
            money($t['costo'], false), money($t['valor_residual'], false), $t['vida_util_meses'],
            money($t['depreciacion_acumulada'], false),
            money((float) $t['costo'] - (float) $t['depreciacion_acumulada'], false), $t['estado']];
    }
    export_tabla('activos_fijos_' . date('Y-m-d'),
        ['Código', 'Activo', 'Tipo', 'Categoría DGII', 'Sucursal', 'Adquisición', 'Costo', 'Valor residual',
         'Vida útil (meses)', 'Depreciación acumulada', 'Valor en libros', 'Estado'],
        $filas, 'Registro de activos fijos');
}

$sucursales = sucursales_visibles();
$proveedores = qAll("SELECT id, nombre FROM proveedores WHERE activo = 1 ORDER BY nombre");

$acciones = export_buttons();
if (can('activos.depreciar') && $pendientes > 0) {
    $acciones .= '<button onclick="' . jsEvent('af:depreciar') . '" class="btn btn-ghost no-print">' . icon('history', 'w-4 h-4') . ' Correr depreciación</button>';
}
if (can('activos.crear')) $acciones .= btn_nuevo('af:new', 'Nuevo activo');

layout_start('Activos fijos', 'Patrimonio de la empresa y su depreciación mensual', $acciones);
?>

<?= rep_kpis([
    ['label' => 'Costo de adquisición', 'valor' => money($resumen['costo']), 'icono' => 'building', 'color' => 'blue',
     'nota' => (int) $resumen['activos'] . ' activo(s) en uso de ' . (int) $resumen['n'] . ' registrados'],
    ['label' => 'Depreciación acumulada', 'valor' => money($resumen['depreciacion']), 'icono' => 'trending-down', 'color' => 'amber',
     'nota' => (float) $resumen['costo'] > 0 ? number_format((float) $resumen['depreciacion'] / (float) $resumen['costo'] * 100, 1) . '% del costo consumido' : '—'],
    ['label' => 'Valor en libros', 'valor' => money($valorNeto), 'icono' => 'bank', 'color' => 'emerald',
     'nota' => 'Lo que suma al activo del balance'],
    ['label' => 'Depreciación de ' . mesNombre((int) date('n')), 'valor' => money($depMes), 'icono' => 'calendar',
     'color' => $pendientes > 0 ? 'rose' : 'violet',
     'nota' => $pendientes > 0 ? $pendientes . ' activo(s) con meses pendientes' : 'Al día'],
]) ?>

<?php if ($pendientes > 0 && can('activos.depreciar')): ?>
  <div class="card p-4 mb-5 flex flex-col sm:flex-row sm:items-center gap-3 border-amber-200 bg-amber-50/40">
    <span class="w-10 h-10 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center shrink-0"><?= icon('alert', 'w-5 h-5') ?></span>
    <p class="text-sm text-slate-700 flex-1">
      <strong>Hay depreciación sin registrar.</strong>
      <?= $pendientes ?> activo(s) tienen meses pendientes, aproximadamente <?= money($montoPendiente) ?> que aún no
      han bajado la utilidad ni el valor en libros.
    </p>
    <button onclick="<?= jsEvent('af:depreciar') ?>" class="btn btn-primary btn-sm shrink-0 no-print"><?= icon('history', 'w-3.5 h-3.5') ?> Poner al día</button>
  </div>
<?php endif; ?>

<div class="card overflow-hidden">
  <div class="p-4 border-b border-slate-100 flex flex-wrap items-center gap-2">
    <?= search_box('Buscar activo o código...', array_filter(['estado' => $estado ?: null])) ?>
    <div class="flex flex-wrap items-center gap-1 p-1 bg-slate-100 rounded-xl">
      <?php foreach (['' => 'Todos', 'activo' => 'En uso', 'depreciado' => 'Depreciados', 'baja' => 'De baja', 'vendido' => 'Vendidos'] as $k => $lbl):
        $qs = array_filter(['estado' => $k ?: null, 'q' => $q ?: null]); ?>
        <a href="?<?= e(http_build_query($qs)) ?>"
           class="px-3 py-1.5 rounded-lg text-[13px] font-semibold transition <?= $estado === $k ? 'bg-white text-blue-700 shadow-sm' : 'text-slate-500 hover:text-slate-800' ?>"><?= e($lbl) ?></a>
      <?php endforeach; ?>
    </div>
    <span class="ml-auto text-sm text-slate-400"><?= number_format($pg['total']) ?> activo(s)</span>
  </div>

  <?php if (!$activos): ?>
    <?= empty_state(
        'Sin activos registrados',
        'Registra el mobiliario, los equipos y los vehículos de la empresa. El sistema calcula su depreciación cada mes y el balance general deja de subestimar el patrimonio.',
        'building',
        can('activos.crear') ? btn_nuevo('af:new', 'Registrar el primero') : ''
    ) ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr>
          <th>Activo</th><th>Categoría DGII</th><th class="text-center">Adquisición</th>
          <th class="text-right">Costo</th><th class="text-right">Depreciado</th>
          <th class="text-right">Valor en libros</th><th class="text-center">Avance</th>
          <th class="text-center">Estado</th><th class="text-right">Acciones</th>
        </tr></thead>
        <tbody>
          <?php foreach ($activos as $a):
            $neto = activoValorNeto($a);
            $deprec = activoDepreciable($a);
            $pct = $deprec > 0 ? min(100, (float) $a['depreciacion_acumulada'] / $deprec * 100) : 100;
            $cat = activoCategoriasDgii()[(int) $a['categoria_dgii']] ?? ['—', 0, ''];
            $estados = ['activo' => ['En uso', 'emerald'], 'depreciado' => ['Depreciado', 'slate'],
                        'baja' => ['De baja', 'rose'], 'vendido' => ['Vendido', 'violet']];
            [$eLbl, $eCol] = $estados[$a['estado']];
            $pendMeses = $a['estado'] === 'activo' ? count(activoPeriodosPendientes($a, $hoy)) : 0;
          ?>
            <tr>
              <td>
                <span class="font-semibold text-slate-700"><?= e($a['nombre']) ?></span>
                <span class="block text-[11.5px] text-slate-400">
                  <?= e($a['codigo']) ?> · <?= e(activoTipos()[$a['tipo']] ?? $a['tipo']) ?>
                  <?= $a['sucursal'] ? ' · ' . e($a['sucursal']) : ' · Empresa' ?>
                </span>
              </td>
              <td><span class="text-[12.5px] text-slate-600"><?= e($cat[0]) ?></span>
                  <span class="block text-[11px] text-slate-400"><?= number_format($cat[1], 0) ?>% fiscal anual</span></td>
              <td class="text-center text-slate-500 whitespace-nowrap"><?= fechaCorta($a['fecha_adquisicion']) ?>
                  <span class="block text-[11px] text-slate-400"><?= (int) $a['vida_util_meses'] ?> meses</span></td>
              <td class="text-right tabular-nums font-semibold text-slate-800"><?= money($a['costo']) ?></td>
              <td class="text-right tabular-nums text-amber-600"><?= money($a['depreciacion_acumulada']) ?>
                  <span class="block text-[11px] text-slate-400"><?= money(activoCuotaMensual($a), false) ?>/mes</span></td>
              <td class="text-right tabular-nums font-bold text-slate-800"><?= money($neto) ?></td>
              <td class="text-center">
                <div class="min-w-[90px] mx-auto">
                  <div class="h-1.5 rounded-full bg-slate-100 overflow-hidden">
                    <div class="h-full rounded-full <?= $pct >= 100 ? 'bg-slate-400' : 'bg-amber-500' ?>" style="width:<?= max($pct, 2) ?>%"></div>
                  </div>
                  <span class="block text-[11px] text-slate-400 mt-1"><?= number_format($pct, 0) ?>%
                    <?= $pendMeses > 0 ? '· ' . $pendMeses . ' mes(es) sin correr' : '' ?></span>
                </div>
              </td>
              <td class="text-center"><?= badge($eLbl, $eCol) ?></td>
              <td>
                <div class="flex items-center justify-end gap-1">
                  <?php if (can('activos.editar') && in_array($a['estado'], ['activo', 'depreciado'], true)): ?>
                    <button onclick="<?= jsEvent('af:edit', [
                        'id' => $a['id'], 'nombre' => $a['nombre'], 'descripcion' => $a['descripcion'],
                        'categoria_dgii' => $a['categoria_dgii'], 'tipo' => $a['tipo'],
                        'sucursal_id' => $a['sucursal_id'], 'proveedor_id' => $a['proveedor_id'],
                        'factura' => $a['factura'], 'fecha_adquisicion' => $a['fecha_adquisicion'],
                        'costo' => $a['costo'], 'valor_residual' => $a['valor_residual'],
                        'vida_util_meses' => $a['vida_util_meses'], 'notas' => $a['notas'],
                        'bloqueado' => (float) $a['depreciacion_acumulada'] > 0 ? 1 : 0,
                    ]) ?>" class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Editar"><?= icon('edit', 'w-4 h-4') ?></button>
                  <?php endif; ?>
                  <?php if (can('activos.baja') && in_array($a['estado'], ['activo', 'depreciado'], true)): ?>
                    <button onclick="<?= jsEvent('af:baja', ['id' => $a['id'], 'nombre' => $a['nombre'], 'neto' => activoValorNeto($a)]) ?>"
                            class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50" title="Dar de baja o vender"><?= icon('undo', 'w-4 h-4') ?></button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= paginacion($pg) ?>
  <?php endif; ?>
</div>

<div class="card p-5 mt-5 bg-slate-50/60">
  <h3 class="font-bold text-slate-800 mb-2">Cómo se calcula</h3>
  <p class="text-[13px] text-slate-600 leading-relaxed">
    Método <strong>línea recta</strong>: la cuota mensual es (costo − valor residual) ÷ vida útil en meses, y empieza
    el <strong>mes siguiente</strong> al de adquisición. La depreciación se registra como gasto <strong>sin cuenta asociada</strong>:
    baja la utilidad del periodo, pero no sale dinero de la caja, así que no aparece en el flujo de efectivo.
    La <strong>categoría DGII</strong> (art. 287 del Código Tributario) se guarda como referencia para el cálculo fiscal,
    que en República Dominicana usa saldo decreciente por categorías y difiere del contable.
  </p>
</div>

<?php if (can('activos.crear') || can('activos.editar')): ?>
<!-- Modal: registrar / editar -->
<div x-data="{open:false, form:{id:0,nombre:'',descripcion:'',categoria_dgii:3,tipo:'otros',sucursal_id:'',proveedor_id:'',factura:'',fecha_adquisicion:'<?= date('Y-m-d') ?>',costo:0,valor_residual:0,vida_util_meses:60,notas:'',bloqueado:0},
      get cuota() {
        var d = (parseFloat(this.form.costo)||0) - (parseFloat(this.form.valor_residual)||0);
        var m = parseInt(this.form.vida_util_meses)||1;
        return d > 0 && m > 0 ? d / m : 0;
      }}"
     @af:new.window="form={id:0,nombre:'',descripcion:'',categoria_dgii:3,tipo:'otros',sucursal_id:'',proveedor_id:'',factura:'',fecha_adquisicion:'<?= date('Y-m-d') ?>',costo:0,valor_residual:0,vida_util_meses:60,notas:'',bloqueado:0}; open=true"
     @af:edit.window="form=$event.detail; open=true"
     @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-2xl" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="id" :value="form.id">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800" x-text="form.id ? 'Editar activo' : 'Registrar activo fijo'"></h3>
          <button type="button" @click="open=false" aria-label="Cerrar modal" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="sm:col-span-2">
            <label class="label" for="af_nombre">Nombre del activo *</label>
            <input id="af_nombre" name="nombre" x-model="form.nombre" required class="input" placeholder="Ej. Nevera exhibidora 2 puertas">
          </div>
          <div>
            <label class="label" for="af_tipo">Tipo</label>
            <select id="af_tipo" name="tipo" x-model="form.tipo" class="select">
              <?php foreach (activoTipos() as $k => $lbl): ?><option value="<?= e($k) ?>"><?= e($lbl) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="label" for="af_cat">Categoría fiscal (DGII)</label>
            <select id="af_cat" name="categoria_dgii" x-model="form.categoria_dgii" class="select">
              <?php foreach (activoCategoriasDgii() as $k => $c): ?>
                <option value="<?= $k ?>"><?= e($c[0]) ?> — <?= number_format($c[1], 0) ?>%</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="label" for="af_fecha">Fecha de adquisición *</label>
            <input type="date" id="af_fecha" name="fecha_adquisicion" x-model="form.fecha_adquisicion" required max="<?= date('Y-m-d') ?>" class="input" :disabled="form.bloqueado==1">
          </div>
          <div>
            <label class="label" for="af_suc">Sucursal</label>
            <select id="af_suc" name="sucursal_id" x-model="form.sucursal_id" class="select">
              <option value="">De la empresa (sin sucursal)</option>
              <?php foreach ($sucursales as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['nombre']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="label" for="af_costo">Costo de adquisición *</label>
            <input type="number" step="0.01" min="0.01" id="af_costo" name="costo" x-model="form.costo" required class="input" :disabled="form.bloqueado==1">
          </div>
          <div>
            <label class="label" for="af_res">Valor residual</label>
            <input type="number" step="0.01" min="0" id="af_res" name="valor_residual" x-model="form.valor_residual" class="input" :disabled="form.bloqueado==1">
          </div>
          <div>
            <label class="label" for="af_vida">Vida útil (meses) *</label>
            <input type="number" min="1" max="600" id="af_vida" name="vida_util_meses" x-model="form.vida_util_meses" required class="input" :disabled="form.bloqueado==1">
          </div>
          <div class="flex items-end">
            <div class="w-full rounded-xl bg-slate-50 border border-slate-200 px-3.5 py-2.5">
              <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">Cuota mensual</span>
              <span class="block text-lg font-extrabold text-slate-800 tabular-nums"
                    x-text="'<?= e(setting('moneda', DEFAULT_MONEDA)) ?> ' + cuota.toLocaleString('es-DO',{minimumFractionDigits:2, maximumFractionDigits:2})"></span>
            </div>
          </div>
          <div>
            <label class="label" for="af_prov">Proveedor</label>
            <select id="af_prov" name="proveedor_id" x-model="form.proveedor_id" class="select">
              <option value="">—</option>
              <?php foreach ($proveedores as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['nombre']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="label" for="af_factura">Factura / NCF</label>
            <input id="af_factura" name="factura" x-model="form.factura" class="input" placeholder="Opcional">
          </div>
          <div class="sm:col-span-2">
            <label class="label" for="af_notas">Notas</label>
            <textarea id="af_notas" name="notas" x-model="form.notas" rows="2" class="input" placeholder="Serie, ubicación, garantía…"></textarea>
          </div>
          <div x-show="form.bloqueado==1" x-cloak class="sm:col-span-2 flex items-start gap-2.5 rounded-xl bg-amber-50 border border-amber-200 p-3">
            <?= icon('alert', 'w-4 h-4 text-amber-600 shrink-0 mt-0.5') ?>
            <p class="text-[12.5px] text-slate-700 leading-relaxed">
              Este activo ya tiene depreciación registrada, así que el costo, la vida útil, el valor residual y la fecha
              quedan fijos: cambiarlos descuadraría los asientos que ya afectaron el resultado. Si el dato estaba mal,
              da de baja el activo y registra uno nuevo.
            </p>
          </div>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button type="submit" class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (can('activos.depreciar')): ?>
<!-- Modal: correr depreciación -->
<div x-data="{open:false}" @af:depreciar.window="open=true" @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-md" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="depreciar">
        <div class="p-6 text-center">
          <div class="w-14 h-14 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center mx-auto mb-4"><?= icon('history', 'w-7 h-7') ?></div>
          <h3 class="text-lg font-bold text-slate-800">Correr la depreciación</h3>
          <p class="text-sm text-slate-500 mt-2 leading-relaxed">
            Se registrarán todos los meses pendientes hasta el periodo que elijas, un asiento por activo y mes.
            Un mes ya registrado nunca se duplica.
          </p>
        </div>
        <div class="px-6 pb-2">
          <label class="label" for="af_hasta">Depreciar hasta el periodo</label>
          <input type="month" id="af_hasta" name="hasta" value="<?= e($hoy) ?>" max="<?= e($hoy) ?>" class="input">
          <p class="text-xs text-slate-400 mt-1.5">
            Pendiente ahora mismo: <strong><?= $pendientes ?></strong> activo(s), unos <strong><?= money($montoPendiente) ?></strong>.
          </p>
        </div>
        <div class="mx-6 mt-3 flex items-start gap-2.5 rounded-xl bg-slate-50 p-3">
          <?= icon('alert', 'w-4 h-4 text-slate-400 shrink-0 mt-0.5') ?>
          <p class="text-[12.5px] text-slate-600 leading-relaxed">
            Esto baja la utilidad del periodo y el valor en libros. No mueve efectivo ni aparece en el flujo de caja.
          </p>
        </div>
        <div class="flex gap-2 p-6 pt-4">
          <button type="button" @click="open=false" class="btn btn-ghost flex-1">Cancelar</button>
          <button type="submit" class="btn btn-primary flex-1"><?= icon('check', 'w-4 h-4') ?> Registrar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (can('activos.baja')): ?>
<!-- Modal: baja / venta -->
<div x-data="{open:false, form:{id:0,nombre:'',neto:0}, destino:'baja'}"
     @af:baja.window="form=$event.detail; destino='baja'; open=true" @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-md" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="baja">
        <input type="hidden" name="id" :value="form.id">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800">Dar de baja el activo</h3>
          <button type="button" @click="open=false" aria-label="Cerrar modal" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 space-y-4">
          <div class="rounded-xl bg-slate-50 p-3.5">
            <p class="font-semibold text-slate-700" x-text="form.nombre"></p>
            <p class="text-[12.5px] text-slate-500 mt-0.5">Valor en libros:
              <strong x-text="'<?= e(setting('moneda', DEFAULT_MONEDA)) ?> ' + Number(form.neto).toLocaleString('es-DO',{minimumFractionDigits:2, maximumFractionDigits:2})"></strong></p>
          </div>
          <div>
            <label class="label">¿Qué pasó con el activo?</label>
            <div class="grid grid-cols-2 gap-2">
              <label class="cursor-pointer">
                <input type="radio" name="destino" value="baja" x-model="destino" class="sr-only peer">
                <span class="block rounded-xl border-2 border-slate-200 peer-checked:border-blue-500 peer-checked:bg-blue-50/50 px-3 py-2.5 text-center text-sm font-semibold text-slate-600 transition">Se retiró</span>
              </label>
              <label class="cursor-pointer">
                <input type="radio" name="destino" value="vendido" x-model="destino" class="sr-only peer">
                <span class="block rounded-xl border-2 border-slate-200 peer-checked:border-emerald-500 peer-checked:bg-emerald-50/50 px-3 py-2.5 text-center text-sm font-semibold text-slate-600 transition">Se vendió</span>
              </label>
            </div>
          </div>
          <div x-show="destino==='vendido'" x-cloak>
            <label class="label" for="af_venta">Valor de la venta *</label>
            <input type="number" step="0.01" min="0.01" id="af_venta" name="valor_venta" class="input" placeholder="0.00">
            <p class="text-xs text-slate-400 mt-1.5">Se registra como ingreso en la cuenta de efectivo de la sucursal.</p>
          </div>
          <div>
            <label class="label" for="af_motivo">Motivo *</label>
            <textarea id="af_motivo" name="motivo_baja" rows="2" required class="input" placeholder="Ej. Equipo dañado sin reparación posible"></textarea>
          </div>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button type="submit" class="btn btn-danger"><?= icon('undo', 'w-4 h-4') ?> Dar de baja</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php layout_end(); ?>
