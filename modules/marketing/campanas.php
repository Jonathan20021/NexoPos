<?php
/**
 * Campañas: listado y creación.
 *
 * Crear una campaña aquí solo pide lo mínimo (nombre, canal, a quién) y lleva
 * directo al editor, que es donde se trabaja de verdad. Es a propósito: un
 * formulario de veinte campos como puerta de entrada hace que nadie cree la
 * primera campaña.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('campanas.ver');

if (!mkt_disponible()) {
    layout_start('Campañas', 'Falta aplicar la migración');
    echo '<div class="card p-8 text-center">' . icon('alert', 'w-10 h-10 text-amber-500 mx-auto mb-3')
       . '<h3 class="font-bold text-slate-700">Módulo no disponible todavía</h3>'
       . '<p class="text-sm text-slate-500 mt-1">Aplica <code class="bg-slate-100 px-1.5 py-0.5 rounded">database/migracion_marketing_p9.sql</code> para activar el módulo de marketing.</p></div>';
    layout_end();
    exit;
}

$canales = ['email' => 'Correo', 'whatsapp' => 'WhatsApp', 'ambos' => 'Correo y WhatsApp'];

$estados = [
    'borrador'   => ['Borrador', 'slate'],
    'programada' => ['Programada', 'sky'],
    'enviando'   => ['Enviando', 'blue'],
    'enviada'    => ['Enviada', 'emerald'],
    'parcial'    => ['Parcial', 'amber'],
    'pausada'    => ['Pausada', 'amber'],
    'cancelada'  => ['Cancelada', 'rose'],
];

/* ============================================================
 *  Acciones (POST · PRG)
 * ============================================================ */
if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    if ($accion === 'crear') {
        require_perm('campanas.crear');
        $nombre = trim(post('nombre'));
        try {
            if ($nombre === '') throw new RuntimeException('Ponle un nombre a la campaña.');

            $canal = array_key_exists(post('canal'), $canales) ? post('canal') : 'email';
            $segId = postInt('segmento_id') ?: null;
            $pltId = postInt('plantilla_id') ?: null;

            $datos = [
                'nombre'      => mb_substr($nombre, 0, 140),
                'asunto'      => mb_substr($nombre, 0, 180),
                'contenido'   => '<p>Hola <strong>{{cliente}}</strong>,</p><p>Escribe aquí tu mensaje.</p>',
                'canal'       => $canal,
                'segmento'    => 'con_email',
                'segmento_id' => $segId,
                'plantilla_id' => $pltId,
                'promocion_id' => postInt('promocion_id') ?: null,
                'estado'      => 'borrador',
                'created_by'  => (int) current_user()['id'],
            ];

            // Si viene de una plantilla, se COPIA su contenido: editar la
            // plantilla mañana no debe cambiar una campaña ya redactada.
            if ($pltId) {
                $p = qOne("SELECT * FROM marketing_plantillas WHERE id = ?", [$pltId]);
                if ($p) {
                    $datos['asunto']    = $p['asunto'];
                    $datos['preheader'] = $p['preheader'];
                    $datos['contenido'] = $p['contenido'];
                    $datos['cta_texto'] = $p['cta_texto'];
                    $datos['cta_url']   = $p['cta_url'];
                    $datos['whatsapp_texto'] = $p['whatsapp_texto'];
                }
            }

            $nid = dbInsert('campanas', $datos);
            audit('campanas', 'crear', "Campaña creada: $nombre", ['tabla' => 'campanas', 'registro_id' => $nid]);
            flash('success', 'Campaña creada. Ahora redáctala y revisa a quién le llega.');
            redirect('modules/marketing/campana.php?id=' . $nid);
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/marketing/campanas.php');
    }

    if ($accion === 'duplicar') {
        require_perm('campanas.crear');
        $c = qOne("SELECT * FROM campanas WHERE id = ?", [postInt('id')]);
        if ($c) {
            $nuevo = [
                'nombre' => mb_substr('Copia de ' . $c['nombre'], 0, 140),
                'asunto' => $c['asunto'], 'asunto_b' => $c['asunto_b'], 'preheader' => $c['preheader'],
                'contenido' => $c['contenido'], 'canal' => $c['canal'],
                'segmento' => $c['segmento'], 'segmento_id' => $c['segmento_id'],
                'plantilla_id' => $c['plantilla_id'], 'promocion_id' => $c['promocion_id'],
                'cta_texto' => $c['cta_texto'], 'cta_url' => $c['cta_url'], 'imagen' => $c['imagen'],
                'whatsapp_texto' => $c['whatsapp_texto'],
                'estado' => 'borrador', 'created_by' => (int) current_user()['id'],
            ];
            $nid = dbInsert('campanas', $nuevo);
            audit('campanas', 'crear', "Campaña duplicada: {$nuevo['nombre']}", ['tabla' => 'campanas', 'registro_id' => $nid]);
            flash('success', 'Campaña duplicada como borrador.');
            redirect('modules/marketing/campana.php?id=' . $nid);
        }
        redirect('modules/marketing/campanas.php');
    }

    if ($accion === 'eliminar') {
        require_perm('campanas.eliminar');
        $id = postInt('id');
        $c = qOne("SELECT nombre, estado FROM campanas WHERE id = ?", [$id]);
        if ($c) {
            q("DELETE FROM campana_envios WHERE campana_id = ?", [$id]);
            q("DELETE FROM campanas WHERE id = ?", [$id]);
            audit('campanas', 'eliminar', "Campaña eliminada: {$c['nombre']}", ['tabla' => 'campanas', 'registro_id' => $id]);
            flash('success', 'Campaña eliminada.');
        }
        redirect('modules/marketing/campanas.php');
    }
}

/* ============================================================
 *  Listado
 * ============================================================ */
$q      = trim(get('q'));
$fEstado = array_key_exists(get('estado'), $estados) ? get('estado') : '';

$where = []; $params = [];
if ($q !== '')       { $where[] = "(c.nombre LIKE ? OR c.asunto LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; }
if ($fEstado !== '') { $where[] = "c.estado = ?"; $params[] = $fEstado; }
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$campanas = qAll(
    "SELECT c.*, s.nombre AS segmento_nombre, a.nombre AS automatizacion_nombre,
            (SELECT COUNT(*) FROM campana_envios e WHERE e.campana_id = c.id AND e.canal = 'whatsapp' AND e.estado = 'pendiente') AS wa_pendientes
       FROM campanas c
       LEFT JOIN marketing_segmentos s ON s.id = c.segmento_id
       LEFT JOIN marketing_automatizaciones a ON a.id = c.automatizacion_id
       $w
      ORDER BY c.id DESC",
    $params
);

$segmentos = qAll("SELECT id, nombre FROM marketing_segmentos WHERE activo = 1 ORDER BY nombre");
$plantillas = qAll("SELECT id, nombre, categoria FROM marketing_plantillas ORDER BY nombre");

// Prefijado al llegar desde un segmento o una plantilla.
$preSeg = (int) get('segmento');
$prePlt = (int) get('plantilla');
$abrirNueva = get('nueva') === '1';

$acciones = can('campanas.crear') ? btn_nuevo('camp:new', 'Nueva campaña') : '';
layout_start('Campañas', 'Promociones por correo y WhatsApp, con resultados medidos', $acciones);
?>

<?php if (!mail_configurado()): ?>
  <div class="card p-4 mb-5 flex items-start gap-3 border-amber-200 bg-amber-50">
    <?= icon('alert', 'w-5 h-5 text-amber-500 mt-0.5 shrink-0') ?>
    <div class="text-sm text-amber-800">
      <p class="font-semibold">El correo no está configurado.</p>
      <p>Falta <code class="bg-amber-100 px-1 rounded">RESEND_API_KEY</code> en <code class="bg-amber-100 px-1 rounded">config/config.local.php</code>.
         Puedes preparar campañas y usar la consola de WhatsApp, pero los correos no saldrán.</p>
    </div>
  </div>
<?php endif; ?>

<div class="card overflow-hidden">
  <div class="p-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
    <?= search_box('Buscar campaña...', $fEstado !== '' ? ['estado' => $fEstado] : []) ?>
    <div class="flex items-center gap-1.5 flex-wrap">
      <a href="<?= e(url('modules/marketing/campanas.php')) ?>"
         class="text-xs font-semibold px-3 py-1.5 rounded-lg <?= $fEstado === '' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-500 hover:bg-slate-200' ?>">Todas</a>
      <?php foreach ($estados as $v => [$l, $col]): ?>
        <a href="<?= e(url('modules/marketing/campanas.php?estado=' . $v)) ?>"
           class="text-xs font-semibold px-3 py-1.5 rounded-lg <?= $fEstado === $v ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-500 hover:bg-slate-200' ?>"><?= e($l) ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if (!$campanas): ?>
    <?= empty_state('Sin campañas', 'Crea tu primera campaña para enviar promociones a tus clientes.', 'mail',
        can('campanas.crear') ? btn_nuevo('camp:new', 'Nueva campaña') : '') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr>
          <th>Campaña</th><th>Canal</th><th>Audiencia</th><th class="text-center">Estado</th>
          <th class="text-center">Progreso</th><th class="text-center">Aperturas</th><th class="text-center">Clics</th>
          <th>Fecha</th><th class="text-right">Acciones</th>
        </tr></thead>
        <tbody>
          <?php foreach ($campanas as $c):
            [$et, $col] = $estados[$c['estado']] ?? ['—', 'slate'];
            $total    = (int) $c['total'];
            $enviados = (int) $c['enviados'];
            $pct      = $total > 0 ? min(100, round($enviados * 100 / $total)) : 0;
            $tasaAp   = $enviados > 0 ? round((int) $c['aperturas'] * 100 / $enviados) : 0;
            $tasaCl   = $enviados > 0 ? round((int) $c['clics'] * 100 / $enviados) : 0;
          ?>
            <tr>
              <td>
                <a href="<?= e(url('modules/marketing/campana.php?id=' . (int) $c['id'])) ?>" class="font-semibold text-slate-700 hover:text-blue-600">
                  <?= e($c['nombre']) ?>
                </a>
                <p class="text-xs text-slate-400 truncate max-w-[260px]"><?= e($c['asunto']) ?></p>
                <?php if ($c['automatizacion_nombre']): ?>
                  <p class="text-[11px] text-violet-600 font-semibold mt-0.5 flex items-center gap-1">
                    <?= icon('pulse', 'w-3 h-3') ?> Automática
                  </p>
                <?php endif; ?>
              </td>
              <td class="text-sm text-slate-600 whitespace-nowrap">
                <?php if ($c['canal'] === 'email'): ?><?= icon('mail', 'w-4 h-4 inline text-slate-400') ?> Correo
                <?php elseif ($c['canal'] === 'whatsapp'): ?><?= icon('phone', 'w-4 h-4 inline text-emerald-500') ?> WhatsApp
                <?php else: ?><?= icon('layers', 'w-4 h-4 inline text-indigo-500') ?> Ambos<?php endif; ?>
                <?php if ((int) $c['wa_pendientes'] > 0): ?>
                  <a href="<?= e(url('modules/marketing/whatsapp.php?campana=' . (int) $c['id'])) ?>"
                     class="block text-[11px] font-semibold text-emerald-600 hover:underline"><?= (int) $c['wa_pendientes'] ?> por enviar</a>
                <?php endif; ?>
              </td>
              <td class="text-sm text-slate-500"><?= e($c['segmento_nombre'] ?? 'Todos los contactables') ?></td>
              <td class="text-center"><?= badge($et, $col) ?></td>
              <td class="text-center">
                <?php if ($total === 0): ?>
                  <span class="text-slate-300">—</span>
                <?php else: ?>
                  <div class="flex items-center gap-2 min-w-[120px]">
                    <div class="flex-1 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                      <div class="h-full rounded-full bg-blue-500" style="width: <?= $pct ?>%"></div>
                    </div>
                    <span class="text-xs text-slate-500 whitespace-nowrap tabular-nums"><?= number_format($enviados) ?>/<?= number_format($total) ?></span>
                  </div>
                  <?php if ((int) $c['fallidos'] > 0): ?>
                    <span class="text-[11px] text-rose-500"><?= (int) $c['fallidos'] ?> fallidos</span>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
              <td class="text-center">
                <?php if ($enviados > 0): ?>
                  <span class="font-semibold text-slate-700"><?= $tasaAp ?>%</span>
                  <span class="block text-[11px] text-slate-400"><?= (int) $c['aperturas'] ?></span>
                <?php else: ?><span class="text-slate-300">—</span><?php endif; ?>
              </td>
              <td class="text-center">
                <?php if ($enviados > 0): ?>
                  <span class="font-semibold text-slate-700"><?= $tasaCl ?>%</span>
                  <span class="block text-[11px] text-slate-400"><?= (int) $c['clics'] ?></span>
                <?php else: ?><span class="text-slate-300">—</span><?php endif; ?>
              </td>
              <td class="text-slate-500 text-sm whitespace-nowrap">
                <?= e(fechaCorta($c['enviada_at'] ?: ($c['programada_at'] ?: $c['created_at']))) ?>
              </td>
              <td>
                <div class="flex items-center justify-end gap-1">
                  <a href="<?= e(url('modules/marketing/campana.php?id=' . (int) $c['id'])) ?>"
                     class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Abrir"><?= icon('eye', 'w-4 h-4') ?></a>
                  <?php if (can('campanas.crear')): ?>
                    <form method="post" class="inline">
                      <?= csrf_field() ?><input type="hidden" name="accion" value="duplicar"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                      <button class="p-2 rounded-lg text-slate-400 hover:text-indigo-600 hover:bg-indigo-50" title="Duplicar"><?= icon('layers', 'w-4 h-4') ?></button>
                    </form>
                  <?php endif; ?>
                  <?php if (can('campanas.eliminar')): ?>
                    <form method="post" class="inline" onsubmit="return confirm('¿Eliminar la campaña «<?= e($c['nombre']) ?>» y todo su historial de envíos?')">
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

<?php if (can('campanas.crear')): ?>
<!-- Modal: crear campaña (lo mínimo, el resto se hace en el editor) -->
<div x-data="{open:<?= $abrirNueva ? 'true' : 'false' ?>, f:{nombre:'', canal:'email', segmento_id:'<?= $preSeg ?: '' ?>', plantilla_id:'<?= $prePlt ?: '' ?>'}}"
     @camp:new.window="f={nombre:'', canal:'email', segmento_id:'', plantilla_id:''}; open=true"
     @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-lg" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="crear">
        <input type="hidden" name="promocion_id" value="<?= (int) get('promocion') ?>">

        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800">Nueva campaña</h3>
          <button type="button" @click="open=false" aria-label="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
        </div>

        <div class="p-6 space-y-4">
          <div>
            <label class="label">Nombre de la campaña *</label>
            <input type="text" name="nombre" x-model="f.nombre" required class="input" placeholder="Ej. Promo Día de las Madres">
            <p class="text-xs text-slate-400 mt-1">Es el nombre interno: el cliente nunca lo ve.</p>
          </div>

          <div>
            <label class="label">¿Por dónde se envía? *</label>
            <div class="grid grid-cols-3 gap-2">
              <?php foreach ($canales as $v => $l): ?>
                <label class="cursor-pointer">
                  <input type="radio" name="canal" value="<?= e($v) ?>" x-model="f.canal" class="peer sr-only">
                  <div class="p-3 rounded-xl border border-slate-200 text-center text-sm text-slate-600 peer-checked:border-blue-500 peer-checked:bg-blue-50 peer-checked:text-blue-700 peer-checked:font-semibold hover:bg-slate-50">
                    <?= e($l) ?>
                  </div>
                </label>
              <?php endforeach; ?>
            </div>
            <p class="text-xs text-slate-400 mt-2">
              El correo sale solo. El de WhatsApp se prepara aquí y se despacha desde la consola con un clic por cliente
              (wa.me no permite envío automático).
            </p>
          </div>

          <div>
            <label class="label">¿A quién?</label>
            <select name="segmento_id" x-model="f.segmento_id" class="select">
              <option value="">Todos los clientes contactables</option>
              <?php foreach ($segmentos as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['nombre']) ?></option><?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="label">Partir de una plantilla</label>
            <select name="plantilla_id" x-model="f.plantilla_id" class="select">
              <option value="">Empezar en blanco</option>
              <?php foreach ($plantillas as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['nombre']) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button type="submit" class="btn btn-primary"><?= icon('arrow-right', 'w-4 h-4') ?> Crear y redactar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php layout_end(); ?>
