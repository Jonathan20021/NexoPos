<?php
/**
 * Editor de una plantilla, a pantalla completa.
 *
 * Antes esto era un modal con un textarea de HTML. No servía: quien redacta las
 * promociones es el dueño del negocio, no un programador. Ahora escribe como en
 * Word y ve el correo terminado al lado, con el nombre de un cliente real.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('marketing.plantillas');

if (!mkt_disponible()) { redirect('modules/marketing/plantillas.php'); }

$id = (int) get('id');
$p  = qOne("SELECT * FROM marketing_plantillas WHERE id = ?", [$id]);
if (!$p) { flash('error', 'Plantilla no encontrada.'); redirect('modules/marketing/plantillas.php'); }

$categorias = [
    'promocion'  => 'Promoción',      'temporada'  => 'Temporada',
    'bienvenida' => 'Bienvenida',     'cumpleanos' => 'Cumpleaños',
    'recompra'   => 'Recompra / post-venta', 'inactivo' => 'Cliente dormido',
    'cobranza'   => 'Cobranza',       'aviso'      => 'Aviso general',
];

/* ---------- Vista previa en vivo (JSON) ---------- */
if (isPost() && post('accion') === 'api_preview') {
    verify_csrf();
    header('Content-Type: application/json; charset=utf-8');

    $previa  = mkt_campana_previa([
        'asunto'         => post('asunto'),
        'preheader'      => post('preheader'),
        'contenido'      => post('contenido'),
        'cta_texto'      => post('cta_texto'),
        'cta_url'        => post('cta_url'),
        'promocion_id'   => postInt('promocion_id'),
        'whatsapp_texto' => post('whatsapp_texto'),
    ]);
    $muestra = mkt_cliente_muestra();
    $vars    = mkt_variables($muestra, mkt_promo($previa['promocion_id']));

    echo json_encode([
        'ok'        => true,
        'html'      => mkt_html_correo($previa, $muestra),
        'whatsapp'  => mkt_texto_whatsapp($previa, $muestra),
        'asunto'    => mkt_render($previa['asunto'], $vars),
        'preheader' => mkt_render($previa['preheader'], $vars),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- Guardar ---------- */
if (isPost()) {
    verify_csrf();
    if (post('accion') === 'guardar') {
        try {
            $nombre    = trim(post('nombre'));
            $asunto    = trim(post('asunto'));
            $contenido = trim(post('contenido'));
            if ($nombre === '') throw new RuntimeException('Ponle un nombre a la plantilla.');
            if ($asunto === '') throw new RuntimeException('El asunto es obligatorio: es lo primero que ve el cliente.');
            if (mb_strlen(trim(strip_tags($contenido))) < 10) throw new RuntimeException('Escribe el mensaje.');

            dbUpdate('marketing_plantillas', [
                'nombre'    => mb_substr($nombre, 0, 120),
                'categoria' => array_key_exists(post('categoria'), $categorias) ? post('categoria') : 'promocion',
                'asunto'    => mb_substr($asunto, 0, 180),
                'preheader' => mb_substr(trim(post('preheader')), 0, 180) ?: null,
                'contenido' => mkt_html_seguro($contenido),
                'cta_texto' => mb_substr(trim(post('cta_texto')), 0, 60) ?: null,
                'cta_url'   => mb_substr(trim(post('cta_url')), 0, 255) ?: null,
                'whatsapp_texto' => trim(post('whatsapp_texto')) ?: null,
            ], 'id = ?', [$id]);

            audit('marketing', 'editar', "Plantilla actualizada: $nombre", ['tabla' => 'marketing_plantillas', 'registro_id' => $id]);
            flash('success', 'Plantilla guardada.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/marketing/plantilla.php?id=' . $id);
    }
}

$promos = qAll("SELECT id, nombre, tipo, valor, fecha_fin FROM promociones
                 WHERE activo = 1 AND fecha_fin >= CURDATE() ORDER BY fecha_fin DESC");

$acciones = '<a href="' . e(url('modules/marketing/plantillas.php')) . '" class="btn btn-ghost">'
          . icon('arrow-left', 'w-4 h-4') . ' Plantillas</a>'
          . '<a href="' . e(url('modules/marketing/campanas.php?nueva=1&plantilla=' . $id)) . '" class="btn btn-soft">'
          . icon('mail', 'w-4 h-4') . ' Usar en una campaña</a>';

layout_start($p['nombre'], 'Plantilla de ' . strtolower($categorias[$p['categoria']] ?? 'mensaje'), $acciones);
?>

<div class="grid xl:grid-cols-2 gap-5 items-start">

  <!-- ---------- Edición ---------- -->
  <form method="post" class="card" data-preview>
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="guardar">
    <!-- La promoción no se guarda en la plantilla: solo sirve para ver cómo
         quedaría el cupón dentro del correo mientras se redacta. -->

    <div class="px-5 py-4 border-b border-slate-100">
      <h2 class="font-bold text-slate-800 flex items-center gap-2"><?= icon('edit', 'w-4 h-4 text-slate-400') ?> Contenido</h2>
    </div>

    <div class="p-5 space-y-4">
      <div class="grid sm:grid-cols-2 gap-4">
        <div>
          <label class="label">Nombre de la plantilla *</label>
          <input type="text" name="nombre" value="<?= e($p['nombre']) ?>" required class="input">
          <p class="text-xs text-slate-400 mt-1">Solo para ti. El cliente nunca lo ve.</p>
        </div>
        <div>
          <label class="label">¿Para qué sirve?</label>
          <select name="categoria" class="select">
            <?php foreach ($categorias as $v => $l): ?>
              <option value="<?= e($v) ?>" <?= $p['categoria'] === $v ? 'selected' : '' ?>><?= e($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div>
        <label class="label">Asunto del correo *</label>
        <input type="text" name="asunto" value="<?= e($p['asunto']) ?>" required maxlength="180" class="input">
        <p class="text-xs text-slate-400 mt-1">Lo primero que se lee en la bandeja de entrada. Corto y concreto funciona mejor.</p>
      </div>

      <div>
        <label class="label">Frase de anticipo</label>
        <input type="text" name="preheader" value="<?= e((string) $p['preheader']) ?>" maxlength="180" class="input">
        <p class="text-xs text-slate-400 mt-1">La línea gris que aparece junto al asunto, antes de abrir el correo.</p>
      </div>

      <div>
        <label class="label">Mensaje *</label>
        <?= editor_visual('contenido', $p['contenido'], ['alto' => '300px']) ?>
      </div>

      <div class="grid sm:grid-cols-2 gap-4">
        <div>
          <label class="label">Texto del botón</label>
          <input type="text" name="cta_texto" value="<?= e((string) $p['cta_texto']) ?>" maxlength="60" class="input" placeholder="Ver la promoción">
        </div>
        <div>
          <label class="label">A dónde lleva el botón</label>
          <input type="text" name="cta_url" value="<?= e((string) $p['cta_url']) ?>" maxlength="255" class="input" placeholder="{{tienda}}">
          <p class="text-xs text-slate-400 mt-1">Escribe <strong>{{tienda}}</strong> para tu tienda en línea.</p>
        </div>
      </div>

      <div>
        <label class="label flex items-center gap-2"><?= icon('phone', 'w-4 h-4 text-emerald-500') ?> Mensaje de WhatsApp</label>
        <textarea name="whatsapp_texto" rows="4" class="input"
                  placeholder="El mismo mensaje, pero corto y en texto plano."><?= e((string) $p['whatsapp_texto']) ?></textarea>
        <p class="text-xs text-slate-400 mt-1">Puedes usar las mismas etiquetas: <strong>{{cliente}}</strong>, <strong>{{empresa}}</strong>, <strong>{{promo}}</strong>…</p>
      </div>

      <div class="pt-2 border-t border-slate-100">
        <label class="label">Ver con esta promoción (solo para la vista previa)</label>
        <select name="promocion_id" class="select">
          <option value="">Sin promoción</option>
          <?php foreach ($promos as $pr):
            $et = $pr['tipo'] === 'porcentaje'
                ? rtrim(rtrim(number_format((float) $pr['valor'], 2), '0'), '.') . '%'
                : money((float) $pr['valor']); ?>
            <option value="<?= (int) $pr['id'] ?>"><?= e($pr['nombre']) ?> — <?= e($et) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="text-xs text-slate-400 mt-1">La promoción de verdad se elige al crear la campaña. Esto es solo para verlo.</p>
      </div>
    </div>

    <div class="px-5 py-4 border-t border-slate-100 flex justify-end">
      <button type="submit" class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar plantilla</button>
    </div>
  </form>

  <!-- ---------- Vista previa ---------- -->
  <div class="xl:sticky xl:top-4">
    <?= preview_correo_panel(url('modules/marketing/plantilla.php?id=' . $id)) ?>
  </div>
</div>

<?= editor_visual_assets() ?>
<?php layout_end(); ?>
