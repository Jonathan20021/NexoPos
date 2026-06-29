<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('roles.ver');

$catalogo = permission_catalog();

// ---------- Acciones ----------
if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    if ($accion === 'guardar') {
        $id      = postInt('id');
        $nombre  = trim(post('nombre'));
        $desc    = trim(post('descripcion'));
        $activo  = postInt('activo', 1);
        $permisos = $_POST['permisos'] ?? [];
        if (!is_array($permisos)) $permisos = [];

        if ($nombre === '') {
            flash('error', 'El nombre del rol es obligatorio.');
            redirect('modules/admin/roles.php');
        }
        if (qVal("SELECT 1 FROM roles WHERE nombre = ? AND id <> ?", [$nombre, $id])) {
            flash('error', 'Ya existe un rol con ese nombre.');
            redirect('modules/admin/roles.php');
        }

        // Validar claves de permisos contra el catálogo (lista blanca).
        $validas = array_keys(permission_keys());
        $permisos = array_values(array_intersect($permisos, $validas));

        if ($id > 0) {
            require_perm('roles.editar');
            $esSuper = (int) qVal("SELECT es_super FROM roles WHERE id = ?", [$id]);
            dbUpdate('roles', ['nombre' => $nombre, 'descripcion' => $desc ?: null, 'activo' => $activo], 'id = ?', [$id]);
            // Los roles super tienen acceso total: no se gestiona su matriz.
            if (!$esSuper) {
                tx(function () use ($id, $permisos) {
                    q("DELETE FROM rol_permisos WHERE rol_id = ?", [$id]);
                    foreach ($permisos as $clave) {
                        $pid = (int) qVal("SELECT id FROM permisos WHERE clave = ?", [$clave]);
                        if ($pid > 0) {
                            q("INSERT IGNORE INTO rol_permisos (rol_id, permiso_id) VALUES (?, ?)", [$id, $pid]);
                        }
                    }
                });
            }
            audit('roles', 'editar', "Rol actualizado: $nombre", ['tabla' => 'roles', 'registro_id' => $id]);
            flash('success', 'Rol actualizado correctamente.');
        } else {
            require_perm('roles.crear');
            $nid = dbInsert('roles', ['nombre' => $nombre, 'descripcion' => $desc ?: null, 'activo' => $activo, 'es_super' => 0, 'es_sistema' => 0]);
            tx(function () use ($nid, $permisos) {
                foreach ($permisos as $clave) {
                    $pid = (int) qVal("SELECT id FROM permisos WHERE clave = ?", [$clave]);
                    if ($pid > 0) {
                        q("INSERT IGNORE INTO rol_permisos (rol_id, permiso_id) VALUES (?, ?)", [$nid, $pid]);
                    }
                }
            });
            audit('roles', 'crear', "Rol creado: $nombre", ['tabla' => 'roles', 'registro_id' => $nid]);
            flash('success', 'Rol creado correctamente.');
        }
        redirect('modules/admin/roles.php');
    }

    if ($accion === 'eliminar') {
        require_perm('roles.eliminar');
        $id = postInt('id');
        $rol = qOne("SELECT nombre, es_sistema FROM roles WHERE id = ?", [$id]);
        $nUsuarios = (int) qVal("SELECT COUNT(*) FROM usuarios WHERE rol_id = ?", [$id]);
        if (!$rol) {
            flash('error', 'El rol no existe.');
        } elseif ((int) $rol['es_sistema'] === 1) {
            flash('error', 'No se puede eliminar un rol del sistema.');
        } elseif ($nUsuarios > 0) {
            flash('error', "No se puede eliminar: $nUsuarios usuario(s) tienen este rol asignado.");
        } else {
            q("DELETE FROM roles WHERE id = ?", [$id]);
            audit('roles', 'eliminar', "Rol eliminado: {$rol['nombre']}", ['tabla' => 'roles', 'registro_id' => $id]);
            flash('success', 'Rol eliminado.');
        }
        redirect('modules/admin/roles.php');
    }
}

// ---------- Listado ----------
$roles = qAll(
    "SELECT r.*,
        (SELECT COUNT(*) FROM rol_permisos rp WHERE rp.rol_id = r.id) AS permisos,
        (SELECT COUNT(*) FROM usuarios u WHERE u.rol_id = r.id) AS usuarios
     FROM roles r ORDER BY r.nombre"
);

// Permisos asignados por rol (para precargar la matriz al editar).
$permisosPorRol = [];
foreach (qAll("SELECT rp.rol_id, p.clave FROM rol_permisos rp JOIN permisos p ON p.id = rp.permiso_id") as $row) {
    $permisosPorRol[(int) $row['rol_id']][] = $row['clave'];
}

$acciones = can('roles.crear') ? btn_nuevo('rol:new', 'Nuevo rol') : '';
layout_start('Roles y Permisos', 'Define qué puede hacer cada tipo de usuario', $acciones);
?>

<div class="card overflow-hidden">
  <div class="overflow-x-auto">
    <?php if (!$roles): ?>
      <?= empty_state('Sin roles', 'Crea el primer rol del sistema.', 'shield',
          can('roles.crear') ? btn_nuevo('rol:new', 'Nuevo rol') : '') ?>
    <?php else: ?>
      <table class="data-table">
        <thead><tr><th>Rol</th><th>Descripción</th><th class="text-center">Permisos</th><th class="text-center">Usuarios</th><th>Tipo</th><th class="text-right">Acciones</th></tr></thead>
        <tbody>
          <?php foreach ($roles as $r): ?>
            <tr>
              <td>
                <div class="flex items-center gap-3">
                  <span class="w-9 h-9 rounded-lg badge-indigo flex items-center justify-center"><?= icon('shield', 'w-4 h-4') ?></span>
                  <span class="font-semibold text-slate-700"><?= e($r['nombre']) ?></span>
                </div>
              </td>
              <td class="text-slate-500 max-w-xs truncate"><?= e($r['descripcion'] ?: '—') ?></td>
              <td class="text-center">
                <?php if ((int) $r['es_super'] === 1): ?>
                  <?= badge('Acceso total', 'emerald') ?>
                <?php else: ?>
                  <span class="badge badge-slate"><?= (int) $r['permisos'] ?></span>
                <?php endif; ?>
              </td>
              <td class="text-center"><span class="badge badge-slate"><?= (int) $r['usuarios'] ?></span></td>
              <td>
                <?php if ((int) $r['es_sistema'] === 1): ?>
                  <?= badge('Sistema', 'amber') ?>
                <?php else: ?>
                  <?= badge('Personalizado', 'sky') ?>
                <?php endif; ?>
              </td>
              <td>
                <div class="flex items-center justify-end gap-1">
                  <?php if (can('roles.editar')): ?>
                    <?php $perms = $permisosPorRol[(int) $r['id']] ?? []; ?>
                    <button onclick="<?= jsEvent('rol:edit', ['id' => $r['id'], 'nombre' => $r['nombre'], 'descripcion' => $r['descripcion'], 'activo' => $r['activo'], 'es_super' => $r['es_super'], 'permisos' => $perms]) ?>"
                            class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Editar"><?= icon('edit', 'w-4 h-4') ?></button>
                  <?php endif; ?>
                  <?php if (can('roles.eliminar') && (int) $r['es_sistema'] === 0): ?>
                    <form method="post" class="inline" onsubmit="return confirm('¿Eliminar el rol «<?= e($r['nombre']) ?>»?')">
                      <?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                      <button class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50" title="Eliminar"><?= icon('trash', 'w-4 h-4') ?></button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<!-- Modal crear/editar con matriz de permisos -->
<div x-data="rolModal()"
     @rol:new.window="abrir({id:0,nombre:'',descripcion:'',activo:1,es_super:0,permisos:[]})"
     @rol:edit.window="abrir($event.detail)"
     @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-3xl flex flex-col" @click.stop>
      <form method="post" class="flex flex-col min-h-0">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="id" :value="form.id">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800" x-text="form.id ? 'Editar rol' : 'Nuevo rol'"></h3>
          <button type="button" @click="open=false" aria-label="Cerrar modal" title="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
        </div>

        <div class="p-6 overflow-y-auto space-y-5">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="label">Nombre *</label>
              <input type="text" name="nombre" x-model="form.nombre" required class="input" placeholder="Ej. Cajero">
            </div>
            <div>
              <label class="label">Descripción</label>
              <input type="text" name="descripcion" x-model="form.descripcion" class="input" placeholder="Opcional">
            </div>
          </div>
          <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="hidden" name="activo" value="0">
            <input type="checkbox" name="activo" value="1" :checked="form.activo==1" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"> Rol activo
          </label>

          <!-- Aviso para roles con acceso total -->
          <div x-show="form.es_super==1" class="flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            <?= icon('shield', 'w-5 h-5 shrink-0 mt-0.5') ?>
            <span>Este rol tiene <strong>acceso total</strong> al sistema. Sus permisos no se gestionan manualmente.</span>
          </div>

          <!-- Matriz de permisos (oculta para super) -->
          <div x-show="form.es_super!=1" class="space-y-4">
            <h4 class="text-sm font-semibold text-slate-700">Permisos del rol</h4>
            <?php foreach ($catalogo as $grupo => $modulos): ?>
              <div class="rounded-xl border border-slate-200 overflow-hidden">
                <div class="flex items-center justify-between px-4 py-2.5 bg-slate-50 border-b border-slate-100">
                  <span class="text-sm font-semibold text-slate-700"><?= e($grupo) ?></span>
                  <label class="flex items-center gap-2 text-xs text-slate-500 cursor-pointer">
                    <input type="checkbox" @change="toggleGrupo($event, <?= e(json_encode($grupo)) ?>)"
                           class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"> Seleccionar todo
                  </label>
                </div>
                <div class="p-4 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
                  <?php foreach ($modulos as $mod => $cfg): ?>
                    <div>
                      <div class="text-xs font-semibold text-slate-600 mb-1.5"><?= e($cfg['label']) ?></div>
                      <div class="flex flex-wrap gap-x-4 gap-y-1.5">
                        <?php foreach ($cfg['acciones'] as $acc => $etiqueta): ?>
                          <?php $clave = $mod . '.' . $acc; ?>
                          <label class="flex items-center gap-1.5 text-sm text-slate-600 cursor-pointer">
                            <input type="checkbox" name="permisos[]" value="<?= e($clave) ?>"
                                   data-grupo="<?= e($grupo) ?>"
                                   :checked="tienePermiso('<?= e($clave) ?>')"
                                   class="perm-check rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                            <?= e($etiqueta) ?>
                          </label>
                        <?php endforeach; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
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

<script>
function rolModal() {
  return {
    open: false,
    form: {id:0, nombre:'', descripcion:'', activo:1, es_super:0, permisos:[]},
    abrir(data) {
      this.form = {
        id: data.id || 0,
        nombre: data.nombre || '',
        descripcion: data.descripcion || '',
        activo: (data.activo == 1 || data.activo === undefined) ? 1 : 0,
        es_super: data.es_super == 1 ? 1 : 0,
        permisos: Array.isArray(data.permisos) ? data.permisos : []
      };
      this.open = true;
    },
    tienePermiso(clave) {
      return this.form.permisos.indexOf(clave) !== -1;
    },
    toggleGrupo(ev, grupo) {
      const checked = ev.target.checked;
      this.$root.querySelectorAll('.perm-check[data-grupo="' + grupo + '"]').forEach(cb => { cb.checked = checked; });
    }
  };
}
</script>

<?php layout_end(); ?>
