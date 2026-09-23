<?php
/**
 * KPIs de campañas — listado, alta y edición de los datos de la campaña.
 * El tablero de cada una vive en kpi_campana.php.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('kpi_campanas.ver');

if (!cockpit_disponible()) {
    layout_start('KPIs de campañas', 'Holiday, Black Friday y cada activación');
    echo '<div class="card p-6">' . empty_state(
        'Falta aplicar la actualización de la base de datos',
        'Ejecuta database/migracion_cockpit_promociones_p39.sql para habilitar el seguimiento de campañas.',
        'alert'
    ) . '</div>';
    layout_end();
    exit;
}

/** Lee y valida el formulario de la campaña. Lanza con un mensaje claro. */
function kpi_form_campana(): array
{
    $fecha = function (string $k, string $lbl): string {
        $v = trim((string) post($k));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !strtotime($v)) throw new RuntimeException("La fecha «$lbl» no es válida.");
        return $v;
    };
    $d = [
        'nombre'       => mb_substr(trim((string) post('nombre')), 0, 120),
        'descripcion'  => mb_substr(trim((string) post('descripcion')), 0, 255) ?: null,
        'fecha_inicio' => $fecha('fecha_inicio', 'inicio'),
        'fecha_fin'    => $fecha('fecha_fin', 'fin'),
        'ly_inicio'    => trim((string) post('ly_inicio')),
        'ly_fin'       => trim((string) post('ly_fin')),
        'sucursal_id'  => postInt('sucursal_id') ?: null,
        'tienda_id'    => postInt('tienda_id') ?: null,
        'meta_ventas'  => max(0, round(postNum('meta_ventas'), 2)),
        'tasa_eur'     => postNum('tasa_eur') > 0 ? round(postNum('tasa_eur'), 4) : null,
        'notas'        => trim((string) post('notas')) ?: null,
    ];
    if ($d['nombre'] === '') throw new RuntimeException('El nombre de la campaña es obligatorio.');
    if ($d['fecha_inicio'] > $d['fecha_fin']) throw new RuntimeException('La campaña no puede terminar antes de empezar.');
    // Sin fechas del año anterior se toman las mismas un año antes.
    $d['ly_inicio'] = $d['ly_inicio'] !== '' ? $fecha('ly_inicio', 'año anterior desde') : cockpit_un_anio_antes($d['fecha_inicio']);
    $d['ly_fin']    = $d['ly_fin'] !== ''    ? $fecha('ly_fin', 'año anterior hasta')    : cockpit_un_anio_antes($d['fecha_fin']);
    if ($d['ly_inicio'] > $d['ly_fin']) throw new RuntimeException('El periodo del año anterior está al revés.');
    if ($d['sucursal_id'] && !can_access_sucursal($d['sucursal_id'])) throw new RuntimeException('No tienes acceso a esa sucursal.');
    if ($d['tienda_id'] && !array_key_exists($d['tienda_id'], tiendas_opciones())) $d['tienda_id'] = null;
    return $d;
}

if (isPost()) {
    verify_csrf();
    $accion = post('accion');
    try {
        if ($accion === 'guardar') {
            $id = postInt('id');
            $d = kpi_form_campana();
            if ($id > 0) {
                require_perm('kpi_campanas.editar');
                if (!kpi_campana($id)) throw new RuntimeException('Campaña no encontrada.');
                dbUpdate('kpi_campanas', $d, 'id = ?', [$id]);
                audit('kpi_campanas', 'editar', 'Campaña actualizada: ' . $d['nombre'], ['tabla' => 'kpi_campanas', 'registro_id' => $id]);
                flash('success', 'Campaña actualizada.');
                redirect('modules/marketing/kpi_campana.php?id=' . $id);
            }
            require_perm('kpi_campanas.crear');
            $id = dbInsert('kpi_campanas', $d + ['created_by' => (int) current_user()['id']]);
            audit('kpi_campanas', 'crear', 'Campaña creada: ' . $d['nombre'], ['tabla' => 'kpi_campanas', 'registro_id' => $id]);
            flash('success', 'Campaña creada. Ahora añade sus SKUs foco, metas e inversión.');
            redirect('modules/marketing/kpi_campana.php?id=' . $id);
        }
        if ($accion === 'eliminar') {
            require_perm('kpi_campanas.eliminar');
            $id = postInt('id');
            $c = kpi_campana($id);
            if ($c) {
                q("DELETE FROM kpi_campanas WHERE id = ?", [$id]);
                audit('kpi_campanas', 'eliminar', 'Campaña eliminada: ' . $c['nombre'], ['tabla' => 'kpi_campanas', 'registro_id' => $id]);
                flash('success', 'Campaña eliminada.');
            }
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('modules/marketing/kpi_campanas.php');
}

$q = trim((string) get('q'));
$where = '1=1'; $params = [];
if ($q !== '') { $where = 'k.nombre LIKE ?'; $params[] = '%' . $q . '%'; }
$campanas = qAll(
    "SELECT k.*, su.nombre sucursal,
            (SELECT COALESCE(SUM(monto),0) FROM kpi_campana_inversiones i WHERE i.campana_id = k.id) inversion,
            (SELECT COUNT(*) FROM kpi_campana_productos p WHERE p.campana_id = k.id) skus
       FROM kpi_campanas k LEFT JOIN sucursales su ON su.id = k.sucursal_id
      WHERE $where ORDER BY k.fecha_inicio DESC, k.id DESC",
    $params
);
$hoy = date('Y-m-d');

$acciones = (can('cockpit.configurar') ? '<a href="' . e(url('modules/marketing/cockpit_config.php?tab=kpis')) . '" class="btn btn-ghost">' . icon('settings', 'w-4 h-4') . ' Configurar KPIs y rubros</a>' : '')
    . (can('kpi_campanas.crear') ? btn_nuevo('kc:new', 'Nueva campaña') : '');
layout_start('KPIs de campañas', 'Holiday, Black Friday y cada activación: venta por canal y por día, SKUs foco, inversión y retorno', $acciones);
?>

<div class="card overflow-hidden">
  <?= toolbar(search_box('Buscar campaña...'), toolbar_conteo(count($campanas), 'campaña')) ?>
  <?php if (!$campanas): ?>
    <div class="p-6"><?= empty_state('Aún no hay campañas', 'Crea la primera (por ejemplo «Holiday 2026» o «Black Friday 2026») y el sistema calcula solo sus KPIs contra el año anterior.', 'target',
        can('kpi_campanas.crear') ? btn_nuevo('kc:new', 'Nueva campaña') : '') ?></div>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr><th>Campaña</th><th>Este año</th><th>Comparable</th><th>Alcance</th><th class="text-right">Meta</th><th class="text-right">Inversión</th><th class="text-center">Estado</th><th class="text-right">Acciones</th></tr></thead>
        <tbody>
        <?php foreach ($campanas as $c):
          [$et, $col] = $c['fecha_fin'] < $hoy ? ['Cerrada', 'slate'] : ($c['fecha_inicio'] > $hoy ? ['Programada', 'amber'] : ['En curso', 'emerald']); ?>
          <tr>
            <td>
              <a href="<?= e(url('modules/marketing/kpi_campana.php?id=' . (int) $c['id'])) ?>" class="font-semibold text-slate-700 hover:text-blue-600"><?= e($c['nombre']) ?></a>
              <p class="text-xs text-slate-400"><?= (int) $c['skus'] ?> SKU(s) foco<?= $c['descripcion'] ? ' · ' . e($c['descripcion']) : '' ?></p>
            </td>
            <td class="text-sm text-slate-600 whitespace-nowrap"><?= e(fechaCorta($c['fecha_inicio'])) ?> — <?= e(fechaCorta($c['fecha_fin'])) ?></td>
            <td class="text-sm text-slate-400 whitespace-nowrap"><?= e(fechaCorta($c['ly_inicio'])) ?> — <?= e(fechaCorta($c['ly_fin'])) ?></td>
            <td class="text-sm text-slate-500"><?= e($c['sucursal'] ?: 'Todas las sucursales') ?><?= $c['tienda_id'] ? ' · ' . e(tiendas_opciones()[(int) $c['tienda_id']] ?? '') : '' ?></td>
            <td class="text-right tabular-nums"><?= (float) $c['meta_ventas'] > 0 ? money($c['meta_ventas']) : '<span class="text-slate-300">—</span>' ?></td>
            <td class="text-right tabular-nums"><?= (float) $c['inversion'] > 0 ? money($c['inversion']) : '<span class="text-slate-300">—</span>' ?></td>
            <td class="text-center"><?= badge($et, $col) ?></td>
            <td>
              <div class="flex items-center justify-end gap-1">
                <a href="<?= e(url('modules/marketing/kpi_campana.php?id=' . (int) $c['id'])) ?>" class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Ver KPIs"><?= icon('chart', 'w-4 h-4') ?></a>
                <?php if (can('kpi_campanas.editar')): ?>
                  <button onclick="<?= jsEvent('kc:edit', array_intersect_key($c, array_flip(['id', 'nombre', 'descripcion', 'fecha_inicio', 'fecha_fin', 'ly_inicio', 'ly_fin', 'sucursal_id', 'tienda_id', 'meta_ventas', 'tasa_eur', 'notas']))) ?>"
                          class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Editar"><?= icon('edit', 'w-4 h-4') ?></button>
                <?php endif; ?>
                <?php if (can('kpi_campanas.eliminar')): ?>
                  <form method="post" class="inline" onsubmit="return confirm('¿Eliminar la campaña «<?= e($c['nombre']) ?>» con su inversión y sus KPIs capturados? Las ventas no se tocan.')">
                    <?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                    <button class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50" title="Eliminar"><?= icon('trash', 'w-4 h-4') ?></button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/_kpi_campana_form.php'; ?>

<?php layout_end(); ?>
