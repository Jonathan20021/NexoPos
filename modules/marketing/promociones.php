<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('promociones.ver');

$tipos    = ['porcentaje' => 'Porcentaje (%)', 'monto' => 'Monto fijo (RD$)'];
$alcances = ['todos' => 'Todo el catálogo', 'categoria' => 'Una categoría', 'marca' => 'Una marca', 'producto' => 'Un producto'];
$canales  = ['ambos' => 'POS y tienda', 'pos' => 'Solo POS', 'tienda' => 'Solo tienda online'];

/* ============================================================
 *  Acciones (POST · PRG)
 * ============================================================ */
if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    if ($accion === 'guardar') {
        $id      = postInt('id');
        $nombre  = trim(post('nombre'));
        $tipo    = array_key_exists(post('tipo'), $tipos) ? post('tipo') : 'porcentaje';
        $valor   = round(max(0, postNum('valor')), 2);
        $alcance = array_key_exists(post('alcance'), $alcances) ? post('alcance') : 'todos';
        $canal   = array_key_exists(post('canal'), $canales) ? post('canal') : 'ambos';
        $ini     = trim(post('fecha_inicio'));
        $fin     = trim(post('fecha_fin'));
        $prio    = postInt('prioridad');
        $activo  = postInt('activo', 1);

        // El objetivo depende del alcance.
        $objetivo = null;
        if ($alcance === 'categoria') $objetivo = postInt('objetivo_categoria');
        elseif ($alcance === 'marca') $objetivo = postInt('objetivo_marca');
        elseif ($alcance === 'producto') $objetivo = postInt('objetivo_producto');

        try {
            if ($nombre === '') throw new RuntimeException('El nombre de la promoción es obligatorio.');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ini) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin)) {
                throw new RuntimeException('Las fechas de vigencia no son válidas.');
            }
            if ($ini > $fin) throw new RuntimeException('La fecha de inicio no puede ser posterior a la de fin.');
            if ($valor <= 0) throw new RuntimeException('El valor del descuento debe ser mayor que cero.');
            if ($tipo === 'porcentaje' && $valor > 100) throw new RuntimeException('El porcentaje no puede superar 100%.');
            if ($alcance !== 'todos' && !$objetivo) throw new RuntimeException('Selecciona el ' . $alcances[$alcance] . '.');
            // Validar que el objetivo exista.
            if ($alcance === 'categoria' && !qVal("SELECT 1 FROM categorias WHERE id=?", [$objetivo])) throw new RuntimeException('La categoría no existe.');
            if ($alcance === 'marca' && !qVal("SELECT 1 FROM marcas WHERE id=?", [$objetivo])) throw new RuntimeException('La marca no existe.');
            if ($alcance === 'producto' && !qVal("SELECT 1 FROM productos WHERE id=?", [$objetivo])) throw new RuntimeException('El producto no existe.');

            $datos = [
                'nombre' => $nombre, 'tipo' => $tipo, 'valor' => $valor,
                'alcance' => $alcance, 'objetivo_id' => $alcance === 'todos' ? null : $objetivo,
                'canal' => $canal, 'fecha_inicio' => $ini, 'fecha_fin' => $fin,
                'prioridad' => $prio, 'activo' => $activo,
            ];
            if ($id > 0) {
                require_perm('promociones.editar');
                if (!qVal("SELECT 1 FROM promociones WHERE id=?", [$id])) throw new RuntimeException('Promoción no encontrada.');
                dbUpdate('promociones', $datos, 'id = ?', [$id]);
                audit('promociones', 'editar', "Promoción actualizada: $nombre", ['tabla' => 'promociones', 'registro_id' => $id]);
                flash('success', 'Promoción actualizada.');
            } else {
                require_perm('promociones.crear');
                $datos['created_by'] = (int) current_user()['id'];
                $nid = dbInsert('promociones', $datos);
                audit('promociones', 'crear', "Promoción creada: $nombre", ['tabla' => 'promociones', 'registro_id' => $nid]);
                flash('success', 'Promoción creada.');
            }
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/marketing/promociones.php');
    }

    if ($accion === 'eliminar') {
        require_perm('promociones.eliminar');
        $id = postInt('id');
        $nombre = qVal("SELECT nombre FROM promociones WHERE id=?", [$id]);
        if ($nombre !== null) {
            q("DELETE FROM promociones WHERE id=?", [$id]);
            audit('promociones', 'eliminar', "Promoción eliminada: $nombre", ['tabla' => 'promociones', 'registro_id' => $id]);
            flash('success', 'Promoción eliminada.');
        }
        redirect('modules/marketing/promociones.php');
    }
}

/* ============================================================
 *  Listado
 * ============================================================ */
$q = trim(get('q'));
$where = ''; $params = [];
if ($q !== '') { $where = "WHERE nombre LIKE ?"; $params[] = '%' . $q . '%'; }
$promos = qAll("SELECT * FROM promociones $where ORDER BY activo DESC, fecha_fin DESC, id DESC", $params);

// Mapas para resolver el objetivo por nombre.
$catMap = []; foreach (qAll("SELECT id, nombre FROM categorias") as $r) $catMap[(int) $r['id']] = $r['nombre'];
$marMap = []; foreach (qAll("SELECT id, nombre FROM marcas") as $r) $marMap[(int) $r['id']] = $r['nombre'];
$proMap = []; foreach (qAll("SELECT id, nombre FROM productos WHERE activo=1 ORDER BY nombre") as $r) $proMap[(int) $r['id']] = $r['nombre'];

$categorias = qAll("SELECT id, nombre FROM categorias ORDER BY nombre");
$marcas     = qAll("SELECT id, nombre FROM marcas ORDER BY nombre");
$hoy = date('Y-m-d');

function promo_objetivo_label(array $p, array $catMap, array $marMap, array $proMap): string
{
    $oid = (int) $p['objetivo_id'];
    switch ($p['alcance']) {
        case 'todos':     return 'Todo el catálogo';
        case 'categoria': return 'Categoría: ' . ($catMap[$oid] ?? '—');
        case 'marca':     return 'Marca: ' . ($marMap[$oid] ?? '—');
        case 'producto':  return 'Producto: ' . ($proMap[$oid] ?? '—');
    }
    return '—';
}

$acciones = can('promociones.crear') ? btn_nuevo('promo:new', 'Nueva promoción') : '';
layout_start('Promociones', 'Descuentos automáticos por temporada, categoría, marca o producto', $acciones);
?>

<div class="card overflow-hidden">
  <div class="p-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
    <?= search_box('Buscar promoción...') ?>
    <span class="text-sm text-slate-400"><?= count($promos) ?> promoción(es)</span>
  </div>

  <?php if (!$promos): ?>
    <?= empty_state('Sin promociones', 'Crea tu primera promoción por temporada, categoría, marca o producto.', 'percent',
        can('promociones.crear') ? btn_nuevo('promo:new', 'Nueva promoción') : '') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr><th>Promoción</th><th>Descuento</th><th>Aplica a</th><th>Canal</th><th>Vigencia</th><th class="text-center">Estado</th><th class="text-right">Acciones</th></tr></thead>
        <tbody>
          <?php foreach ($promos as $p):
            if (!$p['activo'])              [$et, $col] = ['Inactiva', 'slate'];
            elseif ($p['fecha_fin'] < $hoy) [$et, $col] = ['Vencida', 'slate'];
            elseif ($p['fecha_inicio'] > $hoy) [$et, $col] = ['Programada', 'amber'];
            else                            [$et, $col] = ['Activa', 'emerald'];
            $desc = $p['tipo'] === 'porcentaje'
                ? rtrim(rtrim(number_format((float) $p['valor'], 2), '0'), '.') . '%'
                : money((float) $p['valor']);
          ?>
            <tr>
              <td class="font-semibold text-slate-700"><?= e($p['nombre']) ?></td>
              <td><span class="badge badge-rose"><?= e($desc) ?></span></td>
              <td class="text-slate-600 text-sm"><?= e(promo_objetivo_label($p, $catMap, $marMap, $proMap)) ?></td>
              <td class="text-slate-500 text-sm"><?= e($canales[$p['canal']] ?? $p['canal']) ?></td>
              <td class="text-slate-500 text-sm whitespace-nowrap"><?= e(fechaCorta($p['fecha_inicio'])) ?> — <?= e(fechaCorta($p['fecha_fin'])) ?></td>
              <td class="text-center"><?= badge($et, $col) ?></td>
              <td>
                <div class="flex items-center justify-end gap-1">
                  <?php if (can('promociones.editar')): ?>
                    <button onclick="<?= jsEvent('promo:edit', [
                        'id' => (int) $p['id'], 'nombre' => $p['nombre'], 'tipo' => $p['tipo'],
                        'valor' => (float) $p['valor'], 'alcance' => $p['alcance'],
                        'objetivo_id' => $p['objetivo_id'] !== null ? (int) $p['objetivo_id'] : '',
                        'canal' => $p['canal'], 'fecha_inicio' => $p['fecha_inicio'], 'fecha_fin' => $p['fecha_fin'],
                        'prioridad' => (int) $p['prioridad'], 'activo' => (int) $p['activo'],
                    ]) ?>" class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Editar"><?= icon('edit', 'w-4 h-4') ?></button>
                  <?php endif; ?>
                  <?php if (can('promociones.eliminar')): ?>
                    <form method="post" class="inline" onsubmit="return confirm('¿Eliminar la promoción «<?= e($p['nombre']) ?>»?')">
                      <?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
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

<!-- Modal crear/editar -->
<div x-data="{open:false, f:{id:0,nombre:'',tipo:'porcentaje',valor:0,alcance:'todos',objetivo_id:'',canal:'ambos',fecha_inicio:'<?= $hoy ?>',fecha_fin:'<?= $hoy ?>',prioridad:0,activo:1}}"
     @promo:new.window="f={id:0,nombre:'',tipo:'porcentaje',valor:0,alcance:'todos',objetivo_id:'',canal:'ambos',fecha_inicio:'<?= $hoy ?>',fecha_fin:'<?= $hoy ?>',prioridad:0,activo:1}; open=true"
     @promo:edit.window="f=$event.detail; open=true"
     @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-lg" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="id" :value="f.id">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800" x-text="f.id ? 'Editar promoción' : 'Nueva promoción'"></h3>
          <button type="button" @click="open=false" aria-label="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 space-y-4 max-h-[70vh] overflow-y-auto">
          <div>
            <label class="label">Nombre *</label>
            <input type="text" name="nombre" x-model="f.nombre" required class="input" placeholder="Ej. Navidad 20%">
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="label">Tipo de descuento *</label>
              <select name="tipo" x-model="f.tipo" class="select">
                <?php foreach ($tipos as $v => $l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="label"><span x-text="f.tipo==='porcentaje' ? 'Porcentaje (%)' : 'Monto (RD$)'"></span> *</label>
              <input type="number" step="0.01" min="0" name="valor" x-model.number="f.valor" required class="input">
            </div>
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="label">Aplica a *</label>
              <select name="alcance" x-model="f.alcance" class="select">
                <?php foreach ($alcances as $v => $l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="label">Canal *</label>
              <select name="canal" x-model="f.canal" class="select">
                <?php foreach ($canales as $v => $l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
          <!-- Objetivo dinámico según alcance (los tres comparten f.objetivo_id) -->
          <div x-show="f.alcance==='categoria'">
            <label class="label">Categoría *</label>
            <select name="objetivo_categoria" class="select" x-model="f.objetivo_id" :required="f.alcance==='categoria'">
              <option value="">Selecciona…</option>
              <?php foreach ($categorias as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['nombre']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div x-show="f.alcance==='marca'">
            <label class="label">Marca *</label>
            <select name="objetivo_marca" class="select" x-model="f.objetivo_id" :required="f.alcance==='marca'">
              <option value="">Selecciona…</option>
              <?php foreach ($marcas as $m): ?><option value="<?= (int) $m['id'] ?>"><?= e($m['nombre']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div x-show="f.alcance==='producto'">
            <label class="label">Producto *</label>
            <select name="objetivo_producto" class="select" x-model="f.objetivo_id" :required="f.alcance==='producto'">
              <option value="">Selecciona…</option>
              <?php foreach ($proMap as $pid => $pnom): ?><option value="<?= (int) $pid ?>"><?= e($pnom) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="label">Desde *</label>
              <input type="date" name="fecha_inicio" x-model="f.fecha_inicio" required class="input">
            </div>
            <div>
              <label class="label">Hasta *</label>
              <input type="date" name="fecha_fin" x-model="f.fecha_fin" required class="input">
            </div>
          </div>
          <div class="grid grid-cols-2 gap-4 items-end">
            <div>
              <label class="label">Prioridad</label>
              <input type="number" name="prioridad" x-model.number="f.prioridad" class="input" placeholder="0">
              <p class="text-xs text-slate-400 mt-1">Desempata si varias aplican con igual descuento.</p>
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-600 pb-2.5">
              <input type="hidden" name="activo" value="0">
              <input type="checkbox" name="activo" value="1" :checked="f.activo==1" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"> Activa
            </label>
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

<?php layout_end(); ?>
