<?php
/**
 * Configurar una automatización, a pantalla completa y con vista previa.
 *
 * Es el mensaje que va a salir solo, muchas veces, sin que nadie lo revise. Con
 * más razón que en una campaña normal, aquí hay que poder verlo terminado antes
 * de encender el interruptor.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('marketing.automatizar');

if (!mkt_disponible()) { redirect('modules/marketing/automatizaciones.php'); }

$id = (int) get('id');
$a  = qOne("SELECT * FROM marketing_automatizaciones WHERE id = ?", [$id]);
if (!$a) { flash('error', 'Automatización no encontrada.'); redirect('modules/marketing/automatizaciones.php'); }

$canales = ['email' => 'Solo correo', 'whatsapp' => 'Solo WhatsApp', 'ambos' => 'Correo y WhatsApp'];
$disp    = mkt_disparadores()[$a['disparador']] ?? ['label' => $a['disparador'], 'dias' => 'Días', 'icono' => 'pulse', 'ayuda' => ''];

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
if (isPost() && post('accion') === 'guardar') {
    verify_csrf();
    try {
        $asunto    = trim(post('asunto'));
        $contenido = trim(post('contenido'));
        if ($asunto === '') throw new RuntimeException('El asunto es obligatorio.');
        if (mb_strlen(trim(strip_tags($contenido))) < 10) throw new RuntimeException('Escribe el mensaje.');

        dbUpdate('marketing_automatizaciones', [
            'nombre'    => mb_substr(trim(post('nombre')) ?: $a['nombre'], 0, 140),
            'dias'      => max(0, postInt('dias')),
            'canal'     => array_key_exists(post('canal'), $canales) ? post('canal') : 'email',
            'asunto'    => mb_substr($asunto, 0, 180),
            'preheader' => mb_substr(trim(post('preheader')), 0, 180) ?: null,
            'contenido' => mkt_html_seguro($contenido),
            'cta_texto' => mb_substr(trim(post('cta_texto')), 0, 60) ?: null,
            'cta_url'   => mb_substr(trim(post('cta_url')), 0, 255) ?: null,
            'whatsapp_texto' => trim(post('whatsapp_texto')) ?: null,
            'promocion_id'   => postInt('promocion_id') ?: null,
            'tope_dia'  => max(1, min(1000, postInt('tope_dia', 200))),
        ], 'id = ?', [$id]);

        audit('marketing', 'editar', "Automatización actualizada: {$a['nombre']}", ['tabla' => 'marketing_automatizaciones', 'registro_id' => $id]);
        flash('success', 'Automatización guardada.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('modules/marketing/automatizacion.php?id=' . $id);
}

$promos    = qAll("SELECT id, nombre, tipo, valor, fecha_fin FROM promociones
                    WHERE activo = 1 AND fecha_fin >= CURDATE() ORDER BY fecha_fin DESC");
$candidatos = count(mkt_auto_candidatos($a, 200));
$activo     = (int) $a['activo'] === 1;

$acciones = '<a href="' . e(url('modules/marketing/automatizaciones.php')) . '" class="btn btn-ghost">'
          . icon('arrow-left', 'w-4 h-4') . ' Automatizaciones</a>';

layout_start($a['nombre'], $disp['label'], $acciones);
?>

<div class="card p-4 mb-5 flex items-start gap-3 <?= $activo ? 'bg-emerald-50 border-emerald-100' : 'bg-slate-50' ?>">
  <?= icon($activo ? 'pulse' : 'clock', 'w-5 h-5 mt-0.5 shrink-0 ' . ($activo ? 'text-emerald-600' : 'text-slate-400')) ?>
  <div class="text-sm <?= $activo ? 'text-emerald-900' : 'text-slate-600' ?>">
    <p class="font-semibold"><?= $activo ? 'Encendida: este mensaje sale solo.' : 'Apagada: no se envía nada todavía.' ?></p>
    <p><?= e($disp['ayuda']) ?>
       <?php if ($candidatos > 0): ?>
         Ahora mismo <strong><?= $candidatos ?> cliente(s)</strong> cumplen la condición.
       <?php else: ?>
         Ahora mismo ningún cliente cumple la condición.
       <?php endif; ?>
    </p>
  </div>
</div>

<div class="grid xl:grid-cols-2 gap-5 items-start">

  <form method="post" class="card" data-preview>
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="guardar">

    <div class="px-5 py-4 border-b border-slate-100">
      <h2 class="font-bold text-slate-800 flex items-center gap-2"><?= icon('edit', 'w-4 h-4 text-slate-400') ?> Mensaje y condiciones</h2>
    </div>

    <div class="p-5 space-y-4">
      <div>
        <label class="label">Nombre</label>
        <input type="text" name="nombre" value="<?= e($a['nombre']) ?>" class="input">
      </div>

      <div class="grid sm:grid-cols-3 gap-4">
        <div>
          <label class="label"><?= e($disp['dias']) ?></label>
          <input type="number" min="0" name="dias" value="<?= (int) $a['dias'] ?>" class="input">
        </div>
        <div>
          <label class="label">¿Por dónde?</label>
          <select name="canal" class="select">
            <?php foreach ($canales as $v => $l): ?>
              <option value="<?= e($v) ?>" <?= $a['canal'] === $v ? 'selected' : '' ?>><?= e($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="label">Máximo por corrida</label>
          <input type="number" min="1" max="1000" name="tope_dia" value="<?= (int) $a['tope_dia'] ?>" class="input">
        </div>
      </div>
      <p class="text-xs text-slate-400 -mt-2">
        El máximo es un freno de seguridad: aunque mil clientes cumplan la condición, nunca saldrán más de esos de una vez.
      </p>

      <div>
        <label class="label">Asunto del correo *</label>
        <input type="text" name="asunto" value="<?= e($a['asunto']) ?>" required maxlength="180" class="input">
      </div>

      <div>
        <label class="label">Frase de anticipo</label>
        <input type="text" name="preheader" value="<?= e((string) $a['preheader']) ?>" maxlength="180" class="input">
      </div>

      <div>
        <label class="label">Mensaje *</label>
        <?= editor_visual('contenido', $a['contenido'], ['alto' => '260px']) ?>
      </div>

      <div>
        <label class="label">Promoción que se anuncia</label>
        <select name="promocion_id" class="select">
          <option value="">Ninguna</option>
          <?php foreach ($promos as $pr):
            $et = $pr['tipo'] === 'porcentaje'
                ? rtrim(rtrim(number_format((float) $pr['valor'], 2), '0'), '.') . '%'
                : money((float) $pr['valor']); ?>
            <option value="<?= (int) $pr['id'] ?>" <?= (int) $a['promocion_id'] === (int) $pr['id'] ? 'selected' : '' ?>>
              <?= e($pr['nombre']) ?> — <?= e($et) ?> (hasta <?= e(fechaCorta($pr['fecha_fin'])) ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <p class="text-xs text-amber-700 mt-1">
          Ojo: esta regla sigue enviando cuando la promoción venza. Revísala en esa fecha.
        </p>
      </div>

      <div class="grid sm:grid-cols-2 gap-4">
        <div>
          <label class="label">Texto del botón</label>
          <input type="text" name="cta_texto" value="<?= e((string) $a['cta_texto']) ?>" maxlength="60" class="input">
        </div>
        <div>
          <label class="label">A dónde lleva</label>
          <input type="text" name="cta_url" value="<?= e((string) $a['cta_url']) ?>" maxlength="255" class="input" placeholder="{{tienda}}">
        </div>
      </div>

      <div>
        <label class="label flex items-center gap-2"><?= icon('phone', 'w-4 h-4 text-emerald-500') ?> Mensaje de WhatsApp</label>
        <textarea name="whatsapp_texto" rows="3" class="input"><?= e((string) $a['whatsapp_texto']) ?></textarea>
        <p class="text-xs text-slate-400 mt-1">
          Los de WhatsApp se encolan en la consola de envío: alguien tiene que despacharlos con un clic.
        </p>
      </div>
    </div>

    <div class="px-5 py-4 border-t border-slate-100 flex justify-end">
      <button type="submit" class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar</button>
    </div>
  </form>

  <div class="xl:sticky xl:top-4">
    <?= preview_correo_panel(url('modules/marketing/automatizacion.php?id=' . $id)) ?>
  </div>
</div>

<?= editor_visual_assets() ?>
<?php layout_end(); ?>
