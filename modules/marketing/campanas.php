<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('campanas.ver');

$segmentos = campanaSegmentos();

/* ---------- Vista previa del correo (sale del layout: es el email tal cual) ---------- */
if (($pid = (int) get('preview')) > 0) {
    $c = qOne("SELECT * FROM campanas WHERE id = ?", [$pid]);
    if (!$c) { http_response_code(404); exit('Campaña no encontrada.'); }
    $empresa = $GLOBALS['empresa'] ?? [];
    header('Content-Type: text/html; charset=utf-8');
    echo mail_plantilla($c['asunto'], $c['contenido'], $empresa, mb_substr(strip_tags($c['contenido']), 0, 120));
    exit;
}

/* ============================================================
 *  Acciones (POST · PRG)
 * ============================================================ */
if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    if ($accion === 'guardar') {
        $id       = postInt('id');
        $nombre   = trim(post('nombre'));
        $asunto   = trim(post('asunto'));
        $contenido = trim(post('contenido'));
        $segmento = array_key_exists(post('segmento'), $segmentos) ? post('segmento') : 'con_email';
        try {
            if ($nombre === '') throw new RuntimeException('El nombre de la campaña es obligatorio.');
            if ($asunto === '') throw new RuntimeException('El asunto es obligatorio.');
            if (mb_strlen($contenido) < 5) throw new RuntimeException('Escribe el contenido del correo.');
            $datos = ['nombre' => $nombre, 'asunto' => $asunto, 'contenido' => $contenido, 'segmento' => $segmento];
            if ($id > 0) {
                require_perm('campanas.editar');
                $c = qOne("SELECT estado FROM campanas WHERE id = ?", [$id]);
                if (!$c) throw new RuntimeException('Campaña no encontrada.');
                if ($c['estado'] !== 'borrador') throw new RuntimeException('Una campaña ya enviada no se puede editar.');
                dbUpdate('campanas', $datos, 'id = ?', [$id]);
                audit('campanas', 'editar', "Campaña actualizada: $nombre", ['tabla' => 'campanas', 'registro_id' => $id]);
                flash('success', 'Campaña actualizada.');
            } else {
                require_perm('campanas.crear');
                $datos['created_by'] = (int) current_user()['id'];
                $nid = dbInsert('campanas', $datos);
                audit('campanas', 'crear', "Campaña creada: $nombre", ['tabla' => 'campanas', 'registro_id' => $nid]);
                flash('success', 'Campaña guardada como borrador.');
            }
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/marketing/campanas.php');
    }

    if ($accion === 'enviar') {
        require_perm('campanas.enviar');
        $id = postInt('id');
        $r = campanaEnviar($id);
        if ($r['ok']) {
            $msg = "Campaña enviada: {$r['enviados']} de {$r['total']} correo(s).";
            if (!empty($r['fallidos'])) $msg .= " {$r['fallidos']} fallido(s).";
            audit('campanas', 'editar', "Campaña #$id enviada ({$r['enviados']}/{$r['total']})", ['tabla' => 'campanas', 'registro_id' => $id]);
            flash('success', $msg);
        } else {
            flash('error', $r['error'] ?? 'No se pudo enviar la campaña.');
        }
        redirect('modules/marketing/campanas.php');
    }

    if ($accion === 'eliminar') {
        require_perm('campanas.eliminar');
        $id = postInt('id');
        $nombre = qVal("SELECT nombre FROM campanas WHERE id = ?", [$id]);
        if ($nombre !== null) {
            q("DELETE FROM campanas WHERE id = ?", [$id]);
            audit('campanas', 'eliminar', "Campaña eliminada: $nombre", ['tabla' => 'campanas', 'registro_id' => $id]);
            flash('success', 'Campaña eliminada.');
        }
        redirect('modules/marketing/campanas.php');
    }
}

/* ============================================================
 *  Listado
 * ============================================================ */
$q = trim(get('q'));
$where = ''; $params = [];
if ($q !== '') { $where = "WHERE nombre LIKE ? OR asunto LIKE ?"; $params = ['%' . $q . '%', '%' . $q . '%']; }
$campanas = qAll("SELECT * FROM campanas $where ORDER BY id DESC", $params);

// Conteos por segmento (para mostrar cuántos recibirían antes de enviar).
$conteos = [];
foreach ($segmentos as $k => $_) $conteos[$k] = campanaConteo($k);

$estadoBadge = ['borrador' => ['Borrador', 'slate'], 'enviada' => ['Enviada', 'emerald'], 'parcial' => ['Parcial', 'amber']];

$acciones = can('campanas.crear') ? btn_nuevo('camp:new', 'Nueva campaña') : '';
layout_start('Campañas por correo', 'Envía promociones y avisos a tus clientes', $acciones);
?>

<?php if (!mail_configurado()): ?>
  <div class="card p-4 mb-5 flex items-start gap-3 border-amber-200 bg-amber-50">
    <?= icon('alert', 'w-5 h-5 text-amber-500 mt-0.5 shrink-0') ?>
    <div class="text-sm text-amber-800">
      <p class="font-semibold">El correo no está configurado.</p>
      <p>Para enviar campañas, configura la clave de Resend (RESEND_API_KEY) en el servidor. Puedes crear y guardar borradores, pero no enviarlos.</p>
    </div>
  </div>
<?php endif; ?>

<div class="card overflow-hidden">
  <div class="p-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
    <?= search_box('Buscar campaña...') ?>
    <span class="text-sm text-slate-400"><?= count($campanas) ?> campaña(s)</span>
  </div>

  <?php if (!$campanas): ?>
    <?= empty_state('Sin campañas', 'Crea tu primera campaña para enviar promociones a tus clientes por correo.', 'mail',
        can('campanas.crear') ? btn_nuevo('camp:new', 'Nueva campaña') : '') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr><th>Campaña</th><th>Segmento</th><th class="text-center">Estado</th><th class="text-center">Enviados</th><th>Fecha</th><th class="text-right">Acciones</th></tr></thead>
        <tbody>
          <?php foreach ($campanas as $c):
            [$et, $col] = $estadoBadge[$c['estado']] ?? ['—', 'slate'];
          ?>
            <tr>
              <td>
                <p class="font-semibold text-slate-700"><?= e($c['nombre']) ?></p>
                <p class="text-xs text-slate-400"><?= e($c['asunto']) ?></p>
              </td>
              <td class="text-slate-600 text-sm"><?= e($segmentos[$c['segmento']] ?? $c['segmento']) ?></td>
              <td class="text-center"><?= badge($et, $col) ?></td>
              <td class="text-center text-slate-500 text-sm">
                <?= $c['estado'] === 'borrador' ? '—' : (int) $c['enviados'] . ' / ' . (int) $c['total'] ?>
                <?php if ((int) $c['fallidos'] > 0): ?><span class="text-rose-500">(<?= (int) $c['fallidos'] ?> fallidos)</span><?php endif; ?>
              </td>
              <td class="text-slate-500 text-sm whitespace-nowrap"><?= $c['enviada_at'] ? e(fechaCorta($c['enviada_at'])) : e(fechaCorta($c['created_at'])) ?></td>
              <td>
                <div class="flex items-center justify-end gap-1">
                  <a href="<?= e(url('modules/marketing/campanas.php?preview=' . (int) $c['id'])) ?>" target="_blank" class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Vista previa"><?= icon('eye', 'w-4 h-4') ?></a>
                  <?php if ($c['estado'] === 'borrador' && can('campanas.editar')): ?>
                    <button onclick="<?= jsEvent('camp:edit', [
                        'id' => (int) $c['id'], 'nombre' => $c['nombre'], 'asunto' => $c['asunto'],
                        'contenido' => $c['contenido'], 'segmento' => $c['segmento'],
                    ]) ?>" class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Editar"><?= icon('edit', 'w-4 h-4') ?></button>
                  <?php endif; ?>
                  <?php if ($c['estado'] === 'borrador' && can('campanas.enviar')): ?>
                    <form method="post" class="inline" onsubmit="return confirm('¿Enviar «<?= e($c['nombre']) ?>» a <?= (int) ($conteos[$c['segmento']] ?? 0) ?> cliente(s)? Esta acción no se puede deshacer.')">
                      <?= csrf_field() ?><input type="hidden" name="accion" value="enviar"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                      <button class="btn btn-success btn-sm" <?= mail_configurado() ? '' : 'disabled title="Correo no configurado"' ?>><?= icon('mail', 'w-3.5 h-3.5') ?> Enviar</button>
                    </form>
                  <?php endif; ?>
                  <?php if (can('campanas.eliminar')): ?>
                    <form method="post" class="inline" onsubmit="return confirm('¿Eliminar la campaña «<?= e($c['nombre']) ?>»?')">
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

<!-- Modal crear/editar borrador -->
<div x-data="{open:false, f:{id:0,nombre:'',asunto:'',contenido:'',segmento:'con_email'}, conteos:<?= htmlspecialchars(json_encode($conteos), ENT_QUOTES) ?>}"
     @camp:new.window="f={id:0,nombre:'',asunto:'',contenido:'',segmento:'con_email'}; open=true"
     @camp:edit.window="f=$event.detail; open=true"
     @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-lg" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="id" :value="f.id">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800" x-text="f.id ? 'Editar campaña' : 'Nueva campaña'"></h3>
          <button type="button" @click="open=false" aria-label="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 space-y-4 max-h-[70vh] overflow-y-auto">
          <div>
            <label class="label">Nombre interno *</label>
            <input type="text" name="nombre" x-model="f.nombre" required class="input" placeholder="Ej. Promo Navidad">
          </div>
          <div>
            <label class="label">Asunto del correo *</label>
            <input type="text" name="asunto" x-model="f.asunto" required class="input" placeholder="Ej. 🎄 20% de descuento esta semana">
          </div>
          <div>
            <label class="label">Contenido *</label>
            <textarea name="contenido" x-model="f.contenido" rows="7" required class="input" placeholder="Escribe tu mensaje. Puedes usar HTML sencillo: <b>negrita</b>, <a href='...'>enlaces</a>, saltos de línea."></textarea>
            <p class="text-xs text-slate-400 mt-1">Se envía dentro de la plantilla del sistema (con el encabezado de tu empresa). Guarda y usa «Vista previa» para verlo.</p>
          </div>
          <div>
            <label class="label">Enviar a *</label>
            <select name="segmento" x-model="f.segmento" class="select">
              <?php foreach ($segmentos as $v => $l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?>
            </select>
            <p class="text-xs text-slate-400 mt-1"><span class="font-semibold" x-text="conteos[f.segmento] ?? 0"></span> cliente(s) recibirían esta campaña.</p>
          </div>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button type="submit" class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar borrador</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php layout_end(); ?>
