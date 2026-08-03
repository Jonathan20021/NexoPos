<?php
/**
 * Plantillas de mensaje: el texto que se reutiliza.
 *
 * Una plantilla guarda asunto, cuerpo, botón y versión de WhatsApp. Al crear una
 * campaña se copia (no se enlaza): así, editar la plantilla mañana no cambia lo
 * que ya se envió ayer.
 *
 * Las plantillas de fábrica (es_sistema) se pueden editar y duplicar, pero no
 * borrar: son el punto de partida de quien nunca ha hecho una campaña.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('marketing.plantillas');

if (!mkt_disponible()) {
    layout_start('Plantillas', 'Falta aplicar la migración');
    echo '<div class="card p-8 text-center">' . icon('alert', 'w-10 h-10 text-amber-500 mx-auto mb-3')
       . '<h3 class="font-bold text-slate-700">Módulo no disponible todavía</h3>'
       . '<p class="text-sm text-slate-500 mt-1">Aplica <code class="bg-slate-100 px-1.5 py-0.5 rounded">database/migracion_marketing_p9.sql</code>.</p></div>';
    layout_end();
    exit;
}

$categorias = [
    'promocion'  => 'Promoción',
    'temporada'  => 'Temporada',
    'bienvenida' => 'Bienvenida',
    'cumpleanos' => 'Cumpleaños',
    'recompra'   => 'Recompra / post-venta',
    'inactivo'   => 'Cliente dormido',
    'cobranza'   => 'Cobranza',
    'aviso'      => 'Aviso general',
];
$colorCat = [
    'promocion' => 'rose', 'temporada' => 'violet', 'bienvenida' => 'emerald', 'cumpleanos' => 'pink',
    'recompra' => 'sky', 'inactivo' => 'amber', 'cobranza' => 'slate', 'aviso' => 'indigo',
];

/* ---------- Vista previa: el correo tal cual lo recibiría el cliente ---------- */
if (($pid = (int) get('preview')) > 0) {
    $p = qOne("SELECT * FROM marketing_plantillas WHERE id = ?", [$pid]);
    if (!$p) { http_response_code(404); exit('Plantilla no encontrada.'); }

    $muestra = qOne("SELECT id, nombre, balance FROM clientes WHERE activo = 1 ORDER BY id LIMIT 1")
        ?: ['nombre' => 'María Rodríguez', 'balance' => 1500];

    header('Content-Type: text/html; charset=utf-8');
    echo mkt_html_correo([
        'contenido' => $p['contenido'], 'asunto' => $p['asunto'], 'preheader' => $p['preheader'],
        'cta_texto' => $p['cta_texto'], 'cta_url' => $p['cta_url'],
        'promocion_id' => null, 'imagen' => null, 'asunto_b' => null, 'whatsapp_texto' => $p['whatsapp_texto'],
    ], $muestra);
    exit;
}

/* ============================================================
 *  Acciones (POST · PRG)
 * ============================================================ */
if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    if ($accion === 'guardar') {
        $id = postInt('id');
        $nombre = trim(post('nombre'));
        $asunto = trim(post('asunto'));
        $contenido = trim(post('contenido'));
        try {
            if ($nombre === '') throw new RuntimeException('El nombre de la plantilla es obligatorio.');
            if ($asunto === '') throw new RuntimeException('El asunto es obligatorio.');
            if (mb_strlen(trim(strip_tags($contenido))) < 10) throw new RuntimeException('Escribe el cuerpo del mensaje.');

            $datos = [
                'nombre'    => mb_substr($nombre, 0, 120),
                'categoria' => array_key_exists(post('categoria'), $categorias) ? post('categoria') : 'promocion',
                'asunto'    => mb_substr($asunto, 0, 180),
                'preheader' => mb_substr(trim(post('preheader')), 0, 180) ?: null,
                'contenido' => mkt_html_seguro($contenido),
                'cta_texto' => mb_substr(trim(post('cta_texto')), 0, 60) ?: null,
                'cta_url'   => mb_substr(trim(post('cta_url')), 0, 255) ?: null,
                'whatsapp_texto' => trim(post('whatsapp_texto')) ?: null,
            ];

            if ($id > 0) {
                if (!qVal("SELECT 1 FROM marketing_plantillas WHERE id = ?", [$id])) throw new RuntimeException('Plantilla no encontrada.');
                dbUpdate('marketing_plantillas', $datos, 'id = ?', [$id]);
                audit('marketing', 'editar', "Plantilla actualizada: $nombre", ['tabla' => 'marketing_plantillas', 'registro_id' => $id]);
                flash('success', 'Plantilla actualizada.');
            } else {
                $nid = dbInsert('marketing_plantillas', $datos);
                audit('marketing', 'crear', "Plantilla creada: $nombre", ['tabla' => 'marketing_plantillas', 'registro_id' => $nid]);
                flash('success', 'Plantilla creada.');
            }
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/marketing/plantillas.php');
    }

    if ($accion === 'duplicar') {
        $p = qOne("SELECT * FROM marketing_plantillas WHERE id = ?", [postInt('id')]);
        if ($p) {
            unset($p['id'], $p['created_at']);
            $p['nombre'] = mb_substr('Copia de ' . $p['nombre'], 0, 120);
            $p['es_sistema'] = 0;
            $nid = dbInsert('marketing_plantillas', $p);
            audit('marketing', 'crear', "Plantilla duplicada: {$p['nombre']}", ['tabla' => 'marketing_plantillas', 'registro_id' => $nid]);
            flash('success', 'Plantilla duplicada. Edítala a tu gusto.');
        }
        redirect('modules/marketing/plantillas.php');
    }

    if ($accion === 'eliminar') {
        $id = postInt('id');
        $p = qOne("SELECT nombre, es_sistema FROM marketing_plantillas WHERE id = ?", [$id]);
        if ($p && (int) $p['es_sistema'] === 1) {
            flash('error', 'Las plantillas de fábrica no se eliminan. Puedes editarlas o duplicarlas.');
        } elseif ($p) {
            q("DELETE FROM marketing_plantillas WHERE id = ?", [$id]);
            audit('marketing', 'eliminar', "Plantilla eliminada: {$p['nombre']}", ['tabla' => 'marketing_plantillas', 'registro_id' => $id]);
            flash('success', 'Plantilla eliminada.');
        }
        redirect('modules/marketing/plantillas.php');
    }
}

/* ============================================================
 *  Listado
 * ============================================================ */
$q   = trim(get('q'));
$cat = array_key_exists(get('cat'), $categorias) ? get('cat') : '';

$where = []; $params = [];
if ($q !== '')   { $where[] = "(nombre LIKE ? OR asunto LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; }
if ($cat !== '') { $where[] = "categoria = ?"; $params[] = $cat; }
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$plantillas = qAll("SELECT * FROM marketing_plantillas $w ORDER BY es_sistema DESC, nombre", $params);

$acciones = btn_nuevo('plt:new', 'Nueva plantilla');
layout_start('Plantillas de mensaje', 'Textos listos para reutilizar en campañas y automatizaciones', $acciones);
?>

<div class="card overflow-hidden mb-5">
  <div class="p-4 flex items-center justify-between gap-3 flex-wrap">
    <?= search_box('Buscar plantilla...', $cat !== '' ? ['cat' => $cat] : []) ?>
    <div class="flex items-center gap-2 flex-wrap">
      <a href="<?= e(url('modules/marketing/plantillas.php')) ?>"
         class="text-xs font-semibold px-3 py-1.5 rounded-lg <?= $cat === '' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-500 hover:bg-slate-200' ?>">Todas</a>
      <?php foreach ($categorias as $v => $l): ?>
        <a href="<?= e(url('modules/marketing/plantillas.php?cat=' . $v)) ?>"
           class="text-xs font-semibold px-3 py-1.5 rounded-lg <?= $cat === $v ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-500 hover:bg-slate-200' ?>"><?= e($l) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php if (!$plantillas): ?>
  <div class="card">
    <?= empty_state('Sin plantillas', 'Crea una plantilla para no reescribir el mismo mensaje cada mes.', 'file',
        btn_nuevo('plt:new', 'Nueva plantilla')) ?>
  </div>
<?php else: ?>
  <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
    <?php foreach ($plantillas as $p): ?>
      <div class="card p-5 flex flex-col">
        <div class="flex items-start justify-between gap-2 mb-3">
          <?= badge($categorias[$p['categoria']] ?? $p['categoria'], $colorCat[$p['categoria']] ?? 'slate') ?>
          <?php if ($p['es_sistema']): ?><span class="text-[11px] text-slate-400 font-medium">De fábrica</span><?php endif; ?>
        </div>

        <h3 class="font-bold text-slate-800"><?= e($p['nombre']) ?></h3>
        <p class="text-sm text-slate-500 mt-1 font-medium"><?= e($p['asunto']) ?></p>
        <p class="text-xs text-slate-400 mt-2 flex-1 line-clamp-3">
          <?= e(mb_substr(trim(html_entity_decode(strip_tags($p['contenido']), ENT_QUOTES, 'UTF-8')), 0, 160)) ?>…
        </p>

        <?php if ($p['whatsapp_texto']): ?>
          <p class="mt-3 text-[11px] font-semibold text-emerald-600 flex items-center gap-1">
            <?= icon('phone', 'w-3.5 h-3.5') ?> Incluye versión para WhatsApp
          </p>
        <?php endif; ?>

        <div class="flex items-center justify-between gap-1 pt-4 mt-3 border-t border-slate-100">
          <a href="<?= e(url('modules/marketing/campanas.php?nueva=1&plantilla=' . (int) $p['id'])) ?>"
             class="btn btn-soft btn-sm"><?= icon('mail', 'w-3.5 h-3.5') ?> Usar</a>
          <div class="flex items-center gap-1">
            <a href="<?= e(url('modules/marketing/plantillas.php?preview=' . (int) $p['id'])) ?>" target="_blank"
               class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Vista previa"><?= icon('eye', 'w-4 h-4') ?></a>
            <button onclick="<?= jsEvent('plt:edit', [
                'id' => (int) $p['id'], 'nombre' => $p['nombre'], 'categoria' => $p['categoria'],
                'asunto' => $p['asunto'], 'preheader' => (string) $p['preheader'], 'contenido' => $p['contenido'],
                'cta_texto' => (string) $p['cta_texto'], 'cta_url' => (string) $p['cta_url'],
                'whatsapp_texto' => (string) $p['whatsapp_texto'],
            ]) ?>" class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Editar"><?= icon('edit', 'w-4 h-4') ?></button>
            <form method="post" class="inline">
              <?= csrf_field() ?><input type="hidden" name="accion" value="duplicar"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <button class="p-2 rounded-lg text-slate-400 hover:text-indigo-600 hover:bg-indigo-50" title="Duplicar"><?= icon('layers', 'w-4 h-4') ?></button>
            </form>
            <?php if (!$p['es_sistema']): ?>
              <form method="post" class="inline" onsubmit="return confirm('¿Eliminar la plantilla «<?= e($p['nombre']) ?>»?')">
                <?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <button class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50" title="Eliminar"><?= icon('trash', 'w-4 h-4') ?></button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- Modal crear/editar -->
<?php $vacio = ['id' => 0, 'nombre' => '', 'categoria' => 'promocion', 'asunto' => '', 'preheader' => '',
                'contenido' => '', 'cta_texto' => '', 'cta_url' => '', 'whatsapp_texto' => '']; ?>
<div x-data="{open:false, f:<?= htmlspecialchars(json_encode($vacio), ENT_QUOTES) ?>, vacio:<?= htmlspecialchars(json_encode($vacio), ENT_QUOTES) ?>}"
     @plt:new.window="f=JSON.parse(JSON.stringify(vacio)); open=true"
     @plt:edit.window="f=$event.detail; open=true"
     @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-2xl" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="id" :value="f.id">

        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800" x-text="f.id ? 'Editar plantilla' : 'Nueva plantilla'"></h3>
          <button type="button" @click="open=false" aria-label="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
        </div>

        <div class="p-6 space-y-4 max-h-[72vh] overflow-y-auto">
          <div class="grid sm:grid-cols-2 gap-4">
            <div>
              <label class="label">Nombre *</label>
              <input type="text" name="nombre" x-model="f.nombre" required class="input" placeholder="Ej. Promo fin de mes">
            </div>
            <div>
              <label class="label">Categoría</label>
              <select name="categoria" x-model="f.categoria" class="select">
                <?php foreach ($categorias as $v => $l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>

          <div>
            <label class="label">Asunto del correo *</label>
            <input type="text" name="asunto" x-model="f.asunto" required class="input" maxlength="180"
                   placeholder="{{cliente}}, 20% de descuento esta semana">
          </div>

          <div>
            <label class="label">Texto de anticipo (preheader)</label>
            <input type="text" name="preheader" x-model="f.preheader" class="input" maxlength="180"
                   placeholder="La línea gris que se ve junto al asunto en la bandeja">
          </div>

          <div>
            <label class="label">Cuerpo del mensaje *</label>
            <textarea name="contenido" x-model="f.contenido" rows="8" required class="input font-mono text-xs"
                      placeholder="&lt;p&gt;Hola &lt;strong&gt;{{cliente}}&lt;/strong&gt;,&lt;/p&gt;"></textarea>
            <div class="flex flex-wrap gap-1.5 mt-2">
              <?php foreach (mkt_variables_catalogo() as $v => $desc): ?>
                <button type="button" title="<?= e($desc) ?>"
                        @click="f.contenido = (f.contenido || '') + '<?= e($v) ?>'"
                        class="text-[11px] font-mono px-2 py-1 rounded-lg bg-slate-100 text-slate-600 hover:bg-blue-100 hover:text-blue-700"><?= e($v) ?></button>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="grid sm:grid-cols-2 gap-4">
            <div>
              <label class="label">Texto del botón</label>
              <input type="text" name="cta_texto" x-model="f.cta_texto" class="input" maxlength="60" placeholder="Ver la promoción">
            </div>
            <div>
              <label class="label">Enlace del botón</label>
              <input type="text" name="cta_url" x-model="f.cta_url" class="input" maxlength="255" placeholder="{{tienda}}">
            </div>
          </div>

          <div>
            <label class="label">Versión para WhatsApp</label>
            <textarea name="whatsapp_texto" x-model="f.whatsapp_texto" rows="4" class="input"
                      placeholder="Texto plano, sin HTML. Si lo dejas vacío se usa el cuerpo del correo sin etiquetas."></textarea>
            <p class="text-xs text-slate-400 mt-1">WhatsApp no admite HTML: escribe el mensaje corto y directo, como lo escribirías tú.</p>
          </div>
        </div>

        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button type="submit" class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar plantilla</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php layout_end(); ?>
