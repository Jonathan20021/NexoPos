<?php
/**
 * Automatizaciones: los mensajes que salen solos.
 *
 * Cada regla vigila una situación (cliente nuevo, cumpleaños, 90 días sin
 * comprar…) y cuando alguien la cumple, encola su mensaje. No envía directo:
 * crea una campaña del periodo y deja que el motor de campañas la despache, así
 * hereda reintentos, rastreo, bajas y métricas.
 *
 * Todas nacen APAGADAS. Nadie debe descubrir que su sistema empezó a escribirle
 * a sus clientes sin habérselo pedido.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('marketing.automatizar');

if (!mkt_disponible()) {
    layout_start('Automatizaciones', 'Falta aplicar la migración');
    echo '<div class="card p-8 text-center">' . icon('alert', 'w-10 h-10 text-amber-500 mx-auto mb-3')
       . '<h3 class="font-bold text-slate-700">Módulo no disponible todavía</h3>'
       . '<p class="text-sm text-slate-500 mt-1">Aplica <code class="bg-slate-100 px-1.5 py-0.5 rounded">database/migracion_marketing_p9.sql</code>.</p></div>';
    layout_end();
    exit;
}

$disparadores = mkt_disparadores();
$canales = ['email' => 'Correo', 'whatsapp' => 'WhatsApp', 'ambos' => 'Correo y WhatsApp'];

/* ============================================================
 *  Acciones (POST · PRG)
 * ============================================================ */
if (isPost()) {
    verify_csrf();
    $accion = post('accion');
    $volver = 'modules/marketing/automatizaciones.php';

    if ($accion === 'guardar') {
        $id = postInt('id');
        try {
            $a = qOne("SELECT * FROM marketing_automatizaciones WHERE id = ?", [$id]);
            if (!$a) throw new RuntimeException('Automatización no encontrada.');

            $asunto = trim(post('asunto'));
            $contenido = trim(post('contenido'));
            if ($asunto === '') throw new RuntimeException('El asunto es obligatorio.');
            if (mb_strlen(trim(strip_tags($contenido))) < 10) throw new RuntimeException('Escribe el contenido del mensaje.');

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
        redirect($volver);
    }

    if ($accion === 'encender' || $accion === 'apagar') {
        $id = postInt('id');
        $a = qOne("SELECT * FROM marketing_automatizaciones WHERE id = ?", [$id]);
        if ($a) {
            $encender = $accion === 'encender';
            if ($encender && $a['canal'] !== 'whatsapp' && !mail_configurado()) {
                flash('error', 'No se puede encender: falta configurar el correo (RESEND_API_KEY).');
            } else {
                dbUpdate('marketing_automatizaciones', ['activo' => $encender ? 1 : 0], 'id = ?', [$id]);
                audit('marketing', 'editar', ($encender ? 'Automatización encendida: ' : 'Automatización apagada: ') . $a['nombre'],
                      ['tabla' => 'marketing_automatizaciones', 'registro_id' => $id]);
                flash('success', $encender
                    ? "«{$a['nombre']}» está encendida. Empezará a salir en la próxima corrida."
                    : "«{$a['nombre']}» apagada.");
            }
        }
        redirect($volver);
    }

    if ($accion === 'correr') {
        $id = postInt('id');
        $a = qOne("SELECT * FROM marketing_automatizaciones WHERE id = ?", [$id]);
        if (!$a) { redirect($volver); }
        if (empty($a['activo'])) {
            flash('error', 'Enciéndela antes de correrla.');
            redirect($volver);
        }
        try {
            $r = mkt_auto_correr($a);
            if ($r['encolados'] > 0) {
                flash('success', "{$r['encolados']} mensaje(s) encolados. Se despachan solos en unos minutos.");
            } else {
                flash('info', 'Ahora mismo no hay ningún cliente que cumpla la condición.');
            }
        } catch (Throwable $e) {
            flash('error', 'No se pudo correr: ' . $e->getMessage());
        }
        redirect($volver);
    }
}

/* ============================================================
 *  Datos
 * ============================================================ */
$autos  = qAll("SELECT * FROM marketing_automatizaciones ORDER BY activo DESC, disparador");
$promos = qAll("SELECT id, nombre, tipo, valor, fecha_fin FROM promociones
                 WHERE activo = 1 AND fecha_fin >= CURDATE() ORDER BY fecha_fin DESC");

// Cuántos clientes tienen fecha de nacimiento: sin ella, la regla de cumpleaños no dispara.
$conCumple = (int) qVal("SELECT COUNT(*) FROM clientes WHERE activo = 1 AND fecha_nacimiento IS NOT NULL");
$clientes  = (int) qVal("SELECT COUNT(*) FROM clientes WHERE activo = 1");
$activas   = count(array_filter($autos, fn($a) => (int) $a['activo'] === 1));

layout_start('Automatizaciones', 'Mensajes que salen solos cuando el cliente cumple una condición');
?>

<div class="grid sm:grid-cols-3 gap-4 mb-5">
  <div class="card p-5">
    <p class="text-xs uppercase tracking-wide text-slate-400 font-semibold">Encendidas</p>
    <p class="text-3xl font-bold text-slate-800 mt-1"><?= $activas ?> <span class="text-lg text-slate-400">/ <?= count($autos) ?></span></p>
  </div>
  <div class="card p-5">
    <p class="text-xs uppercase tracking-wide text-slate-400 font-semibold">Mensajes encolados</p>
    <p class="text-3xl font-bold text-slate-800 mt-1"><?= number_format(array_sum(array_map(fn($a) => (int) $a['enviados'], $autos))) ?></p>
  </div>
  <div class="card p-5">
    <p class="text-xs uppercase tracking-wide text-slate-400 font-semibold">Motor</p>
    <p class="text-sm text-slate-600 mt-2 leading-snug">
      Corre solo cada <?= MKT_TICK_MINUTOS ?> min mientras alguien use el sistema.
      Para que salga aunque nadie entre, configura el cron.
    </p>
  </div>
</div>

<?php if (!mail_configurado()): ?>
  <div class="card p-4 mb-5 flex items-start gap-3 border-amber-200 bg-amber-50">
    <?= icon('alert', 'w-5 h-5 text-amber-500 mt-0.5 shrink-0') ?>
    <p class="text-sm text-amber-800">
      <strong>El correo no está configurado.</strong> Las automatizaciones por correo no se pueden encender
      hasta que exista <code class="bg-amber-100 px-1 rounded">RESEND_API_KEY</code> en <code class="bg-amber-100 px-1 rounded">config/config.local.php</code>.
    </p>
  </div>
<?php endif; ?>

<div class="grid gap-4 lg:grid-cols-2">
  <?php foreach ($autos as $a):
    $d = $disparadores[$a['disparador']] ?? ['label' => $a['disparador'], 'dias' => 'Días', 'icono' => 'pulse', 'ayuda' => ''];
    $activo = (int) $a['activo'] === 1;
    $faltaCumple = $a['disparador'] === 'cumpleanos' && $conCumple === 0;
  ?>
    <div class="card p-5 <?= $activo ? 'ring-1 ring-emerald-200' : '' ?>">
      <div class="flex items-start justify-between gap-3 mb-3">
        <div class="flex items-start gap-3 min-w-0">
          <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 <?= $activo ? 'bg-emerald-100 text-emerald-600' : 'bg-slate-100 text-slate-400' ?>">
            <?= icon($d['icono'], 'w-5 h-5') ?>
          </div>
          <div class="min-w-0">
            <h3 class="font-bold text-slate-800 truncate"><?= e($a['nombre']) ?></h3>
            <p class="text-xs text-slate-400"><?= e($d['label']) ?></p>
          </div>
        </div>
        <form method="post" class="shrink-0">
          <?= csrf_field() ?>
          <input type="hidden" name="accion" value="<?= $activo ? 'apagar' : 'encender' ?>">
          <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
          <button class="relative w-12 h-6 rounded-full transition <?= $activo ? 'bg-emerald-500' : 'bg-slate-200' ?>"
                  title="<?= $activo ? 'Apagar' : 'Encender' ?>" aria-label="<?= $activo ? 'Apagar' : 'Encender' ?>">
            <span class="absolute top-0.5 w-5 h-5 rounded-full bg-white shadow transition-all <?= $activo ? 'left-6' : 'left-0.5' ?>"></span>
          </button>
        </form>
      </div>

      <p class="text-sm text-slate-500 mb-4"><?= e($d['ayuda']) ?></p>

      <div class="grid grid-cols-3 gap-2 py-3 border-y border-slate-100 text-center">
        <div>
          <p class="text-lg font-bold text-slate-800"><?= (int) $a['dias'] ?></p>
          <p class="text-[11px] text-slate-400 font-semibold leading-tight">días</p>
        </div>
        <div>
          <p class="text-lg font-bold text-slate-800"><?= e($canales[$a['canal']] ?? $a['canal']) ?></p>
          <p class="text-[11px] text-slate-400 font-semibold leading-tight">canal</p>
        </div>
        <div>
          <p class="text-lg font-bold text-slate-800"><?= number_format((int) $a['enviados']) ?></p>
          <p class="text-[11px] text-slate-400 font-semibold leading-tight">encolados</p>
        </div>
      </div>

      <?php if ($faltaCumple): ?>
        <p class="mt-3 text-xs text-amber-700 bg-amber-50 rounded-lg px-3 py-2">
          Ningún cliente tiene fecha de nacimiento registrada: esta regla no disparará.
          Añádela en la ficha del cliente.
        </p>
      <?php endif; ?>

      <p class="mt-3 text-xs text-slate-400">
        <?= $a['ultimo_run'] ? 'Última corrida: ' . e(tiempoRelativo($a['ultimo_run'])) : 'Todavía no ha corrido.' ?>
      </p>

      <div class="flex items-center gap-2 mt-4">
        <a href="<?= e(url('modules/marketing/automatizacion.php?id=' . (int) $a['id'])) ?>"
           class="btn btn-soft btn-sm flex-1"><?= icon('edit', 'w-3.5 h-3.5') ?> Configurar</a>

        <?php if ($activo): ?>
          <form method="post" onsubmit="return confirm('¿Correr «<?= e($a['nombre']) ?>» ahora? Encolará mensajes reales a los clientes que cumplan la condición.')">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="correr"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
            <button class="btn btn-ghost btn-sm" title="Correr ahora"><?= icon('pulse', 'w-3.5 h-3.5') ?> Correr</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card p-5 mt-5">
  <h3 class="font-bold text-slate-800 mb-2">Para que salgan aunque nadie esté conectado</h3>
  <p class="text-sm text-slate-500">
    El motor se despierta cuando alguien usa el sistema. Si tu negocio cierra de noche y quieres que un
    correo salga a las 6:00 a.m., añade este cron en cPanel (cada 5 minutos):
  </p>
  <pre class="mt-3 bg-slate-900 text-slate-100 rounded-xl p-4 text-xs overflow-x-auto">/usr/local/bin/php <?= e(dirname(__DIR__, 2)) ?>/modules/marketing/cron.php</pre>
  <p class="text-xs text-slate-400 mt-2">También funciona por URL con una clave: ver <code>modules/marketing/cron.php</code>.</p>
</div>

<?php layout_end(); ?>
