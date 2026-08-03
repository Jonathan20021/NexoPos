<?php
/**
 * Editor y consola de una campaña.
 *
 * Aquí se redacta, se decide a quién le llega, se envía y se miden los
 * resultados. El envío no es un único POST que tarda cinco minutos: se despacha
 * en lotes por AJAX con una barra de progreso, así el navegador nunca se queda
 * colgado y un corte a mitad de camino no pierde nada (cada destinatario tiene
 * su propia fila y su propio estado).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('campanas.ver');

if (!mkt_disponible()) { redirect('modules/marketing/campanas.php'); }

$id = (int) get('id');
$c  = qOne("SELECT * FROM campanas WHERE id = ?", [$id]);
if (!$c) { flash('error', 'Campaña no encontrada.'); redirect('modules/marketing/campanas.php'); }

$canales = ['email' => 'Correo', 'whatsapp' => 'WhatsApp', 'ambos' => 'Correo y WhatsApp'];
$estados = [
    'borrador'   => ['Borrador', 'slate'],   'programada' => ['Programada', 'sky'],
    'enviando'   => ['Enviando', 'blue'],    'enviada'    => ['Enviada', 'emerald'],
    'parcial'    => ['Parcial', 'amber'],    'pausada'    => ['Pausada', 'amber'],
    'cancelada'  => ['Cancelada', 'rose'],
];
$editable = in_array($c['estado'], ['borrador', 'programada', 'pausada'], true);

/* ============================================================
 *  API de envío por lotes (JSON)
 * ============================================================ */
if (isPost() && post('accion') === 'api_lote') {
    verify_csrf();
    header('Content-Type: application/json; charset=utf-8');
    if (!can('campanas.enviar')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'No tienes permiso para enviar campañas.']);
        exit;
    }
    @set_time_limit(120);
    ignore_user_abort(true);
    try {
        $r = mkt_procesar_campana($id);
        $r['ok'] = $r['error'] === null;
        echo json_encode($r);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Error al enviar: ' . $e->getMessage(), 'pendientes' => 0]);
    }
    exit;
}

/* ============================================================
 *  Vista previa: el correo tal cual lo recibe el cliente
 * ============================================================ */
if (get('preview') === '1') {
    $muestra = qOne("SELECT id, nombre, balance FROM clientes WHERE activo = 1 AND email <> '' ORDER BY id LIMIT 1")
        ?: ['nombre' => 'María Rodríguez', 'balance' => 1500];
    header('Content-Type: text/html; charset=utf-8');
    echo mkt_html_correo($c, $muestra);
    exit;
}

/* ============================================================
 *  Acciones (POST · PRG)
 * ============================================================ */
if (isPost()) {
    verify_csrf();
    $accion = post('accion');
    $volver = 'modules/marketing/campana.php?id=' . $id;

    if ($accion === 'guardar') {
        require_perm('campanas.editar');
        try {
            if (!$editable) throw new RuntimeException('Una campaña en envío o ya enviada no se puede editar.');

            $nombre = trim(post('nombre'));
            $asunto = trim(post('asunto'));
            $contenido = trim(post('contenido'));
            if ($nombre === '') throw new RuntimeException('El nombre de la campaña es obligatorio.');
            if ($asunto === '') throw new RuntimeException('El asunto es obligatorio.');
            if (mb_strlen(trim(strip_tags($contenido))) < 10) throw new RuntimeException('Escribe el contenido del mensaje.');

            $datos = [
                'nombre'      => mb_substr($nombre, 0, 140),
                'asunto'      => mb_substr($asunto, 0, 180),
                'asunto_b'    => mb_substr(trim(post('asunto_b')), 0, 180) ?: null,
                'preheader'   => mb_substr(trim(post('preheader')), 0, 180) ?: null,
                'contenido'   => mkt_html_seguro($contenido),
                'canal'       => array_key_exists(post('canal'), $canales) ? post('canal') : 'email',
                'segmento_id' => postInt('segmento_id') ?: null,
                'promocion_id' => postInt('promocion_id') ?: null,
                'cta_texto'   => mb_substr(trim(post('cta_texto')), 0, 60) ?: null,
                'cta_url'     => mb_substr(trim(post('cta_url')), 0, 255) ?: null,
                'whatsapp_texto' => trim(post('whatsapp_texto')) ?: null,
                'imagen'      => guardar_imagen('imagen', 'campanas', $c['imagen']),
                'updated_at'  => date('Y-m-d H:i:s'),
            ];

            dbUpdate('campanas', $datos, 'id = ?', [$id]);
            audit('campanas', 'editar', "Campaña actualizada: $nombre", ['tabla' => 'campanas', 'registro_id' => $id]);
            flash('success', 'Campaña guardada.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect($volver);
    }

    if ($accion === 'quitar_imagen') {
        require_perm('campanas.editar');
        dbUpdate('campanas', ['imagen' => null], 'id = ?', [$id]);
        flash('success', 'Imagen quitada.');
        redirect($volver);
    }

    if ($accion === 'audiencia') {
        require_perm('campanas.editar');
        try {
            $r = mkt_construir_audiencia($id);
            flash('success', "Audiencia calculada: {$r['total']} destinatario(s)"
                . ($r['nuevos'] !== $r['total'] ? " ({$r['nuevos']} nuevos)" : '') . '.');
        } catch (Throwable $e) {
            flash('error', 'No se pudo calcular la audiencia: ' . $e->getMessage());
        }
        redirect($volver);
    }

    if ($accion === 'prueba') {
        require_perm('campanas.enviar');
        $para = trim(post('email_prueba')) ?: (string) (current_user()['email'] ?? '');
        if (!filter_var($para, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Escribe un correo válido para la prueba.');
        } elseif (!mail_configurado()) {
            flash('error', 'El correo no está configurado (falta la clave de Resend).');
        } else {
            $muestra = ['nombre' => current_user()['nombre'] ?? 'Cliente de prueba', 'balance' => 1500];
            $r = mail_enviar($para, '[PRUEBA] ' . $c['asunto'], mkt_html_correo($c, $muestra));
            $r['ok']
                ? flash('success', "Correo de prueba enviado a $para.")
                : flash('error', 'No se pudo enviar la prueba: ' . $r['error']);
        }
        redirect($volver);
    }

    if ($accion === 'enviar') {
        require_perm('campanas.enviar');
        try {
            if (in_array($c['estado'], ['enviada', 'enviando'], true)) throw new RuntimeException('Esta campaña ya está enviándose.');
            $r = mkt_construir_audiencia($id);
            if ($r['total'] === 0) throw new RuntimeException('No hay destinatarios: revisa el segmento y el canal.');

            dbUpdate('campanas', ['estado' => 'enviando', 'programada_at' => null,
                                  'enviada_at' => $c['enviada_at'] ?: date('Y-m-d H:i:s')], 'id = ?', [$id]);
            audit('campanas', 'editar', "Campaña #$id puesta en envío ({$r['total']} destinatarios)", ['tabla' => 'campanas', 'registro_id' => $id]);
            flash('success', 'Enviando. Puedes cerrar esta pantalla: el envío continúa solo.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect($volver);
    }

    if ($accion === 'programar') {
        require_perm('campanas.enviar');
        $cuando = trim(post('programada_at'));
        try {
            $ts = strtotime($cuando);
            if (!$ts) throw new RuntimeException('La fecha y hora no son válidas.');
            if ($ts < time() - 60) throw new RuntimeException('Esa fecha ya pasó.');

            $r = mkt_construir_audiencia($id);
            if ($r['total'] === 0) throw new RuntimeException('No hay destinatarios: revisa el segmento y el canal.');

            dbUpdate('campanas', ['estado' => 'programada', 'programada_at' => date('Y-m-d H:i:s', $ts)], 'id = ?', [$id]);
            audit('campanas', 'editar', "Campaña #$id programada para " . date('Y-m-d H:i', $ts), ['tabla' => 'campanas', 'registro_id' => $id]);
            flash('success', 'Campaña programada para el ' . fechaHora(date('Y-m-d H:i:s', $ts)) . '.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect($volver);
    }

    if ($accion === 'pausar') {
        require_perm('campanas.enviar');
        dbUpdate('campanas', ['estado' => 'pausada'], 'id = ?', [$id]);
        audit('campanas', 'editar', "Campaña #$id pausada", ['tabla' => 'campanas', 'registro_id' => $id]);
        flash('success', 'Envío pausado. Los pendientes se quedan como están.');
        redirect($volver);
    }

    if ($accion === 'reanudar') {
        require_perm('campanas.enviar');
        dbUpdate('campanas', ['estado' => 'enviando'], 'id = ?', [$id]);
        flash('success', 'Envío reanudado.');
        redirect($volver);
    }

    if ($accion === 'cancelar') {
        require_perm('campanas.enviar');
        q("UPDATE campana_envios SET estado = 'omitido', error = 'Campaña cancelada'
            WHERE campana_id = ? AND estado = 'pendiente'", [$id]);
        dbUpdate('campanas', ['estado' => 'cancelada'], 'id = ?', [$id]);
        mkt_recalcular($id);
        audit('campanas', 'editar', "Campaña #$id cancelada", ['tabla' => 'campanas', 'registro_id' => $id]);
        flash('success', 'Campaña cancelada. No saldrá ningún envío pendiente.');
        redirect($volver);
    }

    if ($accion === 'reintentar') {
        require_perm('campanas.enviar');
        $n = mkt_reintentar_fallidos($id);
        flash($n > 0 ? 'success' : 'info', $n > 0 ? "$n envío(s) vuelven a la cola." : 'No hay envíos fallidos que reintentar.');
        redirect($volver);
    }

    if ($accion === 'aplicar_plantilla') {
        require_perm('campanas.editar');
        $p = qOne("SELECT * FROM marketing_plantillas WHERE id = ?", [postInt('plantilla_id')]);
        if ($p && $editable) {
            dbUpdate('campanas', [
                'asunto' => $p['asunto'], 'preheader' => $p['preheader'], 'contenido' => $p['contenido'],
                'cta_texto' => $p['cta_texto'], 'cta_url' => $p['cta_url'],
                'whatsapp_texto' => $p['whatsapp_texto'], 'plantilla_id' => (int) $p['id'],
            ], 'id = ?', [$id]);
            flash('success', 'Plantilla aplicada. Revisa el texto antes de enviar.');
        }
        redirect($volver);
    }
}

/* ============================================================
 *  Datos de la pantalla
 * ============================================================ */
$segmentos  = qAll("SELECT id, nombre FROM marketing_segmentos WHERE activo = 1 ORDER BY nombre");
$plantillas = qAll("SELECT id, nombre FROM marketing_plantillas ORDER BY nombre");
$promos     = qAll("SELECT id, nombre, tipo, valor, fecha_fin FROM promociones
                     WHERE activo = 1 AND fecha_fin >= CURDATE() ORDER BY fecha_fin DESC");

$seg = mkt_segmento($c['segmento_id'] ? (int) $c['segmento_id'] : null);

// Cifras de la audiencia ya construida (rápido: cuenta filas propias).
$audiencia = qOne(
    "SELECT COUNT(*) total,
            SUM(canal = 'email') emails,
            SUM(canal = 'whatsapp') whats,
            SUM(estado = 'pendiente' AND canal = 'email') pend_email,
            SUM(estado = 'pendiente' AND canal = 'whatsapp') pend_wa,
            SUM(estado = 'enviado') enviados,
            SUM(estado = 'fallido') fallidos,
            SUM(estado = 'omitido') omitidos
       FROM campana_envios WHERE campana_id = ?", [$id]
) ?: [];

$m = mkt_metricas($c);

// Estimación de a cuánta gente llegaría si se recalcula ahora (sin escribir nada).
$estimado = ['email' => null, 'whatsapp' => null];
if ($editable) {
    try {
        foreach (($c['canal'] === 'ambos' ? ['email', 'whatsapp'] : [$c['canal']]) as $ca) {
            $estimado[$ca] = mkt_conteo($seg, $ca);
        }
    } catch (Throwable $e) {
        // Un fallo al estimar no debe impedir abrir la campaña.
    }
}

// Últimos envíos para la tabla de resultados.
$fEnv = in_array(get('env'), ['pendiente', 'enviado', 'fallido', 'omitido'], true) ? get('env') : '';
$wEnv = $fEnv !== '' ? 'AND e.estado = ?' : '';
$pEnv = $fEnv !== '' ? [$id, $fEnv] : [$id];
$envios = qAll(
    "SELECT e.*, cl.nombre AS cliente_nombre
       FROM campana_envios e
       LEFT JOIN clientes cl ON cl.id = e.cliente_id
      WHERE e.campana_id = ? $wEnv
      ORDER BY (e.estado = 'fallido') DESC, e.clic_at DESC, e.id
      LIMIT 300", $pEnv
);

[$etEstado, $colEstado] = $estados[$c['estado']] ?? ['—', 'slate'];
$pendEmail = (int) ($audiencia['pend_email'] ?? 0);
$pendWa    = (int) ($audiencia['pend_wa'] ?? 0);

$acciones = '<a href="' . e(url('modules/marketing/campanas.php')) . '" class="btn btn-ghost">'
          . icon('arrow-left', 'w-4 h-4') . ' Volver</a>'
          . '<a href="' . e(url('modules/marketing/campana.php?id=' . $id . '&preview=1')) . '" target="_blank" class="btn btn-soft">'
          . icon('eye', 'w-4 h-4') . ' Vista previa</a>';

layout_start($c['nombre'], 'Campaña ' . strtolower($etEstado) . ' · ' . ($canales[$c['canal']] ?? $c['canal']), $acciones);
?>

<div class="grid lg:grid-cols-3 gap-5 items-start">

  <!-- ============================================================
       Columna izquierda: contenido
       ============================================================ -->
  <div class="lg:col-span-2 space-y-5">

    <?php if (!$editable): ?>
      <div class="card p-4 flex items-start gap-3 bg-slate-50">
        <?= icon('lock', 'w-5 h-5 text-slate-400 mt-0.5 shrink-0') ?>
        <p class="text-sm text-slate-600">
          Esta campaña está <strong><?= e(strtolower($etEstado)) ?></strong> y ya no se puede editar: cambiar el texto
          ahora dejaría un historial que no coincide con lo que recibieron tus clientes.
          <?php if (can('campanas.crear')): ?>Duplícala si quieres una versión nueva.<?php endif; ?>
        </p>
      </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="card">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="guardar">

      <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between gap-3">
        <h2 class="font-bold text-slate-800 flex items-center gap-2"><?= icon('edit', 'w-4 h-4 text-slate-400') ?> Mensaje</h2>
        <?php if ($editable && $plantillas): ?>
          <div class="flex items-center gap-2">
            <select id="pltSel" class="select text-sm py-1.5">
              <option value="">Aplicar plantilla…</option>
              <?php foreach ($plantillas as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['nombre']) ?></option><?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>
      </div>

      <div class="p-5 space-y-4" <?= $editable ? '' : 'style="opacity:.65;pointer-events:none"' ?>>
        <div>
          <label class="label">Nombre interno *</label>
          <input type="text" name="nombre" value="<?= e($c['nombre']) ?>" required class="input">
        </div>

        <div class="grid sm:grid-cols-2 gap-4">
          <div>
            <label class="label">Canal *</label>
            <select name="canal" class="select">
              <?php foreach ($canales as $v => $l): ?>
                <option value="<?= e($v) ?>" <?= $c['canal'] === $v ? 'selected' : '' ?>><?= e($l) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="label">Audiencia (segmento)</label>
            <select name="segmento_id" class="select">
              <option value="">Todos los clientes contactables</option>
              <?php foreach ($segmentos as $s): ?>
                <option value="<?= (int) $s['id'] ?>" <?= (int) $c['segmento_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div>
          <label class="label">Asunto del correo *</label>
          <input type="text" name="asunto" value="<?= e($c['asunto']) ?>" required maxlength="180" class="input"
                 placeholder="{{cliente}}, 20% de descuento esta semana">
        </div>

        <div>
          <label class="label">Asunto alternativo (prueba A/B)</label>
          <input type="text" name="asunto_b" value="<?= e((string) $c['asunto_b']) ?>" maxlength="180" class="input"
                 placeholder="Déjalo vacío si no quieres probar dos asuntos">
          <p class="text-xs text-slate-400 mt-1">
            Si lo llenas, la mitad de tus clientes recibe uno y la mitad el otro. En resultados verás cuál se abrió más.
          </p>
        </div>

        <div>
          <label class="label">Texto de anticipo (preheader)</label>
          <input type="text" name="preheader" value="<?= e((string) $c['preheader']) ?>" maxlength="180" class="input"
                 placeholder="La línea gris junto al asunto en la bandeja de entrada">
        </div>

        <div>
          <label class="label">Cuerpo del mensaje *</label>
          <textarea name="contenido" rows="10" required class="input font-mono text-xs"><?= e($c['contenido']) ?></textarea>
          <div class="flex flex-wrap gap-1.5 mt-2">
            <?php foreach (mkt_variables_catalogo() as $v => $desc): ?>
              <button type="button" title="<?= e($desc) ?>" data-var="<?= e($v) ?>"
                      class="js-var text-[11px] font-mono px-2 py-1 rounded-lg bg-slate-100 text-slate-600 hover:bg-blue-100 hover:text-blue-700"><?= e($v) ?></button>
            <?php endforeach; ?>
          </div>
          <p class="text-xs text-slate-400 mt-2">HTML sencillo: <code>&lt;p&gt;</code>, <code>&lt;strong&gt;</code>, <code>&lt;a href&gt;</code>. Nada de scripts.</p>
        </div>

        <div class="grid sm:grid-cols-2 gap-4">
          <div>
            <label class="label">Promoción destacada</label>
            <select name="promocion_id" class="select">
              <option value="">Ninguna</option>
              <?php foreach ($promos as $p):
                $et = $p['tipo'] === 'porcentaje'
                    ? rtrim(rtrim(number_format((float) $p['valor'], 2), '0'), '.') . '%'
                    : money((float) $p['valor']); ?>
                <option value="<?= (int) $p['id'] ?>" <?= (int) $c['promocion_id'] === (int) $p['id'] ? 'selected' : '' ?>>
                  <?= e($p['nombre']) ?> — <?= e($et) ?> (hasta <?= e(fechaCorta($p['fecha_fin'])) ?>)
                </option>
              <?php endforeach; ?>
            </select>
            <p class="text-xs text-slate-400 mt-1">Se dibuja como un cupón dentro del correo y llena <code>{{promo}}</code>, <code>{{descuento}}</code> y <code>{{vigencia}}</code>.</p>
          </div>
          <div>
            <label class="label">Imagen de cabecera</label>
            <input type="file" name="imagen" accept="image/*" class="input py-2">
            <?php if ($c['imagen']): ?>
              <div class="flex items-center gap-2 mt-2">
                <img src="<?= e(url($c['imagen'])) ?>" alt="" class="h-10 rounded-lg border border-slate-200">
                <button type="button" onclick="document.getElementById('frmQuitarImg').submit()"
                        class="text-xs text-rose-600 hover:underline">Quitar</button>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="grid sm:grid-cols-2 gap-4">
          <div>
            <label class="label">Texto del botón</label>
            <input type="text" name="cta_texto" value="<?= e((string) $c['cta_texto']) ?>" maxlength="60" class="input" placeholder="Ver la promoción">
          </div>
          <div>
            <label class="label">Enlace del botón</label>
            <input type="text" name="cta_url" value="<?= e((string) $c['cta_url']) ?>" maxlength="255" class="input" placeholder="{{tienda}}">
            <p class="text-xs text-slate-400 mt-1">Los clics en este botón son los que se cuentan.</p>
          </div>
        </div>

        <div>
          <label class="label flex items-center gap-2"><?= icon('phone', 'w-4 h-4 text-emerald-500') ?> Mensaje de WhatsApp</label>
          <textarea name="whatsapp_texto" rows="4" class="input"
                    placeholder="Texto plano. Si lo dejas vacío se usa el cuerpo del correo sin etiquetas."><?= e((string) $c['whatsapp_texto']) ?></textarea>
          <p class="text-xs text-slate-400 mt-1">
            Se personaliza por cliente igual que el correo. El enlace del botón se añade al final automáticamente.
          </p>
        </div>
      </div>

      <?php if ($editable && can('campanas.editar')): ?>
        <div class="px-5 py-4 border-t border-slate-100 flex justify-end gap-2">
          <button type="submit" class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar cambios</button>
        </div>
      <?php endif; ?>
    </form>

    <?php if ($editable): ?>
      <form method="post" id="frmQuitarImg" class="hidden">
        <?= csrf_field() ?><input type="hidden" name="accion" value="quitar_imagen">
      </form>
      <form method="post" id="frmPlantilla" class="hidden">
        <?= csrf_field() ?><input type="hidden" name="accion" value="aplicar_plantilla">
        <input type="hidden" name="plantilla_id" id="pltId">
      </form>
    <?php endif; ?>

    <!-- ---------- Resultados por destinatario ---------- -->
    <?php if ((int) ($audiencia['total'] ?? 0) > 0): ?>
      <div class="card overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
          <h2 class="font-bold text-slate-800 flex items-center gap-2"><?= icon('list', 'w-4 h-4 text-slate-400') ?> Destinatarios</h2>
          <div class="flex items-center gap-1.5 flex-wrap">
            <?php
            $filtros = ['' => 'Todos', 'enviado' => 'Enviados', 'pendiente' => 'Pendientes', 'fallido' => 'Fallidos', 'omitido' => 'Omitidos'];
            foreach ($filtros as $v => $l): ?>
              <a href="<?= e(url('modules/marketing/campana.php?id=' . $id . ($v !== '' ? '&env=' . $v : ''))) ?>"
                 class="text-xs font-semibold px-3 py-1.5 rounded-lg <?= $fEnv === $v ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-500 hover:bg-slate-200' ?>"><?= e($l) ?></a>
            <?php endforeach; ?>
          </div>
        </div>

        <?php if (!$envios): ?>
          <?= empty_state('Sin envíos en este filtro', 'Prueba con otro estado.', 'list') ?>
        <?php else: ?>
          <div class="overflow-x-auto">
            <table class="data-table">
              <thead><tr>
                <th>Cliente</th><th>Destino</th><th class="text-center">Canal</th><th class="text-center">Estado</th>
                <th class="text-center">Abrió</th><th class="text-center">Clic</th><th>Enviado</th>
              </tr></thead>
              <tbody>
                <?php foreach ($envios as $en):
                  $badgeEnv = [
                      'pendiente' => ['En cola', 'slate'], 'enviado' => ['Enviado', 'emerald'],
                      'fallido' => ['Fallido', 'rose'], 'omitido' => ['Omitido', 'amber'],
                  ][$en['estado']] ?? ['—', 'slate'];
                ?>
                  <tr>
                    <td class="font-medium text-slate-700"><?= e($en['cliente_nombre'] ?? $en['nombre'] ?? '—') ?>
                      <?php if (!empty($c['asunto_b']) && $en['canal'] === 'email'): ?>
                        <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-slate-100 text-slate-500 ml-1"><?= e($en['variante']) ?></span>
                      <?php endif; ?>
                    </td>
                    <td class="text-sm text-slate-500">
                      <?= $en['canal'] === 'whatsapp' ? e(mkt_telefono_bonito($en['destino'])) : e($en['destino']) ?>
                    </td>
                    <td class="text-center"><?= icon($en['canal'] === 'whatsapp' ? 'phone' : 'mail', 'w-4 h-4 inline text-slate-400') ?></td>
                    <td class="text-center">
                      <?= badge($badgeEnv[0], $badgeEnv[1]) ?>
                      <?php if ($en['error']): ?>
                        <p class="text-[11px] text-rose-500 mt-1 max-w-[200px] truncate" title="<?= e($en['error']) ?>"><?= e($en['error']) ?></p>
                      <?php endif; ?>
                    </td>
                    <td class="text-center"><?= $en['abierto_at'] ? icon('check', 'w-4 h-4 inline text-emerald-500') : '<span class="text-slate-300">—</span>' ?></td>
                    <td class="text-center"><?= $en['clic_at'] ? icon('check', 'w-4 h-4 inline text-blue-500') : '<span class="text-slate-300">—</span>' ?></td>
                    <td class="text-sm text-slate-400 whitespace-nowrap"><?= $en['enviado_at'] ? e(fechaHora($en['enviado_at'])) : '—' ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php if (count($envios) === 300): ?>
            <p class="px-5 py-3 text-xs text-slate-400 border-t border-slate-100">Se muestran los primeros 300. Usa los filtros para acotar.</p>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- ============================================================
       Columna derecha: audiencia, envío y resultados
       ============================================================ -->
  <div class="space-y-5">

    <!-- Estado y envío -->
    <div class="card p-5" x-data="envio()">
      <div class="flex items-center justify-between mb-4">
        <span class="text-xs font-bold uppercase tracking-wide text-slate-400">Estado</span>
        <?= badge($etEstado, $colEstado) ?>
      </div>

      <?php if ($c['estado'] === 'enviando' || $pendEmail > 0): ?>
        <div class="mb-4">
          <div class="flex items-center justify-between text-sm mb-1.5">
            <span class="font-semibold text-slate-700" x-text="corriendo ? 'Enviando…' : 'Progreso'"></span>
            <span class="text-slate-500 tabular-nums" x-text="`${enviados} / ${total}`"></span>
          </div>
          <div class="h-2 rounded-full bg-slate-100 overflow-hidden">
            <div class="h-full rounded-full bg-blue-500 transition-all duration-500" :style="`width:${pct}%`"></div>
          </div>
          <p class="text-xs text-slate-400 mt-2" x-text="mensaje"></p>
        </div>
      <?php endif; ?>

      <div class="space-y-2">
        <?php if (can('campanas.enviar')): ?>

          <?php if ($c['estado'] === 'enviando'): ?>
            <button type="button" @click="arrancar()" x-show="!corriendo" class="btn btn-primary w-full">
              <?= icon('mail', 'w-4 h-4') ?> Continuar el envío ahora
            </button>
            <button type="button" @click="corriendo=false" x-show="corriendo" class="btn btn-ghost w-full">
              Detener en este lote
            </button>
            <form method="post" onsubmit="return confirm('¿Pausar el envío? Los pendientes se quedan en cola.')">
              <?= csrf_field() ?><input type="hidden" name="accion" value="pausar">
              <button class="btn btn-soft w-full"><?= icon('clock', 'w-4 h-4') ?> Pausar</button>
            </form>

          <?php elseif ($c['estado'] === 'pausada'): ?>
            <form method="post">
              <?= csrf_field() ?><input type="hidden" name="accion" value="reanudar">
              <button class="btn btn-primary w-full"><?= icon('arrow-right', 'w-4 h-4') ?> Reanudar envío</button>
            </form>

          <?php elseif (in_array($c['estado'], ['borrador', 'programada'], true)): ?>
            <?php if ($c['canal'] !== 'whatsapp'): ?>
              <form method="post" onsubmit="return confirm('¿Enviar «<?= e($c['nombre']) ?>» ahora? No se puede deshacer.')">
                <?= csrf_field() ?><input type="hidden" name="accion" value="enviar">
                <button class="btn btn-primary w-full" <?= mail_configurado() ? '' : 'disabled title="Correo no configurado"' ?>>
                  <?= icon('mail', 'w-4 h-4') ?> Enviar ahora
                </button>
              </form>
            <?php else: ?>
              <form method="post">
                <?= csrf_field() ?><input type="hidden" name="accion" value="audiencia">
                <button class="btn btn-primary w-full"><?= icon('users', 'w-4 h-4') ?> Preparar la cola de WhatsApp</button>
              </form>
            <?php endif; ?>

            <div x-data="{prog:false}">
              <button type="button" @click="prog=!prog" class="btn btn-soft w-full">
                <?= icon('calendar', 'w-4 h-4') ?> <?= $c['programada_at'] ? 'Cambiar programación' : 'Programar envío' ?>
              </button>
              <form method="post" x-show="prog" x-transition style="display:none" class="mt-2 space-y-2">
                <?= csrf_field() ?><input type="hidden" name="accion" value="programar">
                <input type="datetime-local" name="programada_at" required class="input"
                       value="<?= e($c['programada_at'] ? date('Y-m-d\TH:i', strtotime($c['programada_at'])) : date('Y-m-d\TH:i', strtotime('+1 hour'))) ?>">
                <button class="btn btn-success w-full btn-sm"><?= icon('check', 'w-4 h-4') ?> Confirmar programación</button>
              </form>
            </div>
          <?php endif; ?>

          <?php if (in_array($c['estado'], ['enviando', 'programada', 'pausada'], true)): ?>
            <form method="post" onsubmit="return confirm('¿Cancelar la campaña? Los pendientes NO se enviarán.')">
              <?= csrf_field() ?><input type="hidden" name="accion" value="cancelar">
              <button class="btn btn-ghost w-full text-rose-600"><?= icon('x', 'w-4 h-4') ?> Cancelar campaña</button>
            </form>
          <?php endif; ?>

          <?php if ((int) $c['fallidos'] > 0): ?>
            <form method="post">
              <?= csrf_field() ?><input type="hidden" name="accion" value="reintentar">
              <button class="btn btn-soft w-full"><?= icon('history', 'w-4 h-4') ?> Reintentar <?= (int) $c['fallidos'] ?> fallido(s)</button>
            </form>
          <?php endif; ?>
        <?php endif; ?>

        <?php if ($pendWa > 0 && can('campanas.whatsapp')): ?>
          <a href="<?= e(url('modules/marketing/whatsapp.php?campana=' . $id)) ?>" class="btn btn-success w-full">
            <?= icon('phone', 'w-4 h-4') ?> Enviar <?= $pendWa ?> por WhatsApp
          </a>
        <?php endif; ?>
      </div>

      <?php if ($c['programada_at'] && $c['estado'] === 'programada'): ?>
        <p class="mt-4 text-sm text-sky-700 bg-sky-50 rounded-xl px-3 py-2.5 flex items-start gap-2">
          <?= icon('clock', 'w-4 h-4 mt-0.5 shrink-0') ?>
          <span>Saldrá el <strong><?= e(fechaHora($c['programada_at'])) ?></strong>, aunque nadie esté conectado.</span>
        </p>
      <?php endif; ?>

      <script>
        function envio() {
          return {
            corriendo: false,
            enviados: <?= (int) $c['enviados'] ?>,
            total: <?= max(1, (int) $c['total']) ?>,
            mensaje: '<?= $pendEmail > 0 ? $pendEmail . ' correo(s) en cola.' : 'Sin correos en cola.' ?>',
            get pct() { return this.total > 0 ? Math.min(100, Math.round(this.enviados * 100 / this.total)) : 0; },
            init() { if (<?= $c['estado'] === 'enviando' && $pendEmail > 0 ? 'true' : 'false' ?>) this.arrancar(); },
            async arrancar() {
              if (this.corriendo) return;
              this.corriendo = true;
              while (this.corriendo) {
                const cuerpo = new FormData();
                cuerpo.append('accion', 'api_lote');
                cuerpo.append('_csrf', '<?= e(csrf_token()) ?>');
                let j;
                try {
                  const r = await fetch(window.location.pathname + '?id=<?= $id ?>', { method: 'POST', body: cuerpo });
                  j = await r.json();
                } catch (e) {
                  this.mensaje = 'Se perdió la conexión. El envío continúa en el servidor.';
                  this.corriendo = false;
                  break;
                }
                if (!j.ok && j.error) { this.mensaje = j.error; this.corriendo = false; break; }
                this.enviados += (j.enviados || 0);
                this.mensaje = j.pendientes > 0
                  ? `${j.pendientes} en cola…`
                  : 'Envío terminado.';
                if (!j.pendientes) { this.corriendo = false; setTimeout(() => window.location.reload(), 900); }
              }
            }
          };
        }
      </script>
    </div>

    <!-- Audiencia -->
    <div class="card p-5">
      <h3 class="font-bold text-slate-800 mb-1 flex items-center gap-2"><?= icon('users', 'w-4 h-4 text-slate-400') ?> Audiencia</h3>
      <p class="text-sm text-slate-500 mb-4"><?= e($seg['nombre'] ?: 'Todos los clientes contactables') ?></p>

      <div class="flex flex-wrap gap-1.5 mb-4">
        <?php foreach (mkt_segmento_reglas($seg) as $r): ?>
          <span class="text-[11px] font-medium px-2 py-1 rounded-lg bg-slate-100 text-slate-600"><?= e($r) ?></span>
        <?php endforeach; ?>
      </div>

      <?php if ((int) ($audiencia['total'] ?? 0) > 0): ?>
        <div class="grid grid-cols-2 gap-3 text-center py-3 border-y border-slate-100">
          <div>
            <p class="text-2xl font-bold text-slate-800"><?= number_format((int) $audiencia['emails']) ?></p>
            <p class="text-[11px] uppercase tracking-wide text-slate-400 font-semibold">Correos</p>
          </div>
          <div>
            <p class="text-2xl font-bold text-slate-800"><?= number_format((int) $audiencia['whats']) ?></p>
            <p class="text-[11px] uppercase tracking-wide text-slate-400 font-semibold">WhatsApp</p>
          </div>
        </div>
      <?php else: ?>
        <div class="py-3 border-y border-slate-100 text-center">
          <p class="text-sm text-slate-400">Audiencia sin calcular todavía.</p>
        </div>
      <?php endif; ?>

      <?php if ($editable): ?>
        <div class="mt-4 text-sm text-slate-500 space-y-1">
          <?php foreach (['email' => 'por correo', 'whatsapp' => 'por WhatsApp'] as $ca => $lbl): ?>
            <?php if ($estimado[$ca] !== null): ?>
              <p class="flex items-center justify-between">
                <span>Calificarían hoy <?= e($lbl) ?></span>
                <strong class="text-slate-700"><?= number_format($estimado[$ca]) ?></strong>
              </p>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
        <?php if (can('campanas.editar')): ?>
          <form method="post" class="mt-3">
            <?= csrf_field() ?><input type="hidden" name="accion" value="audiencia">
            <button class="btn btn-soft w-full btn-sm"><?= icon('history', 'w-4 h-4') ?> Recalcular audiencia</button>
          </form>
          <p class="text-xs text-slate-400 mt-2">
            Recalcular solo <strong>añade</strong> a quien falte. A nadie ya enviado se le repite el mensaje.
          </p>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <!-- Resultados -->
    <?php if ($m['enviados'] > 0): ?>
      <div class="card p-5">
        <h3 class="font-bold text-slate-800 mb-4 flex items-center gap-2"><?= icon('chart', 'w-4 h-4 text-slate-400') ?> Resultados</h3>

        <div class="space-y-3">
          <?php
          $filas = [
              ['Enviados',   number_format($m['enviados']), '', 'slate'],
              ['Aperturas',  number_format($m['aperturas']), $m['tasa_apertura'] . '%', 'emerald'],
              ['Clics',      number_format($m['clics']), $m['tasa_clic'] . '%', 'blue'],
              ['Fallidos',   number_format($m['fallidos']), '', 'rose'],
              ['Bajas',      number_format($m['bajas']), '', 'amber'],
          ];
          foreach ($filas as [$lbl, $val, $pctv, $col]): ?>
            <div class="flex items-center justify-between">
              <span class="text-sm text-slate-500"><?= e($lbl) ?></span>
              <span class="font-semibold text-slate-800">
                <?= e($val) ?>
                <?php if ($pctv !== ''): ?><span class="text-xs font-bold text-<?= $col ?>-600 ml-1"><?= e($pctv) ?></span><?php endif; ?>
              </span>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="mt-5 pt-4 border-t border-slate-100">
          <p class="text-xs font-bold uppercase tracking-wide text-slate-400 mb-2">Ventas atribuidas</p>
          <p class="text-2xl font-bold text-emerald-600"><?= e(money($m['monto'])) ?></p>
          <p class="text-sm text-slate-500"><?= number_format($m['ventas']) ?> venta(s) de clientes que recibieron esta campaña</p>
          <p class="text-xs text-slate-400 mt-2">
            Compras hechas dentro de los <?= MKT_ATRIBUCION_DIAS ?> días siguientes al envío. Es una correlación por
            ventana de tiempo, útil para comparar campañas — no prueba que la venta ocurriera por el correo.
          </p>
        </div>
      </div>
    <?php endif; ?>

    <!-- Prueba -->
    <?php if (can('campanas.enviar')): ?>
      <div class="card p-5">
        <h3 class="font-bold text-slate-800 mb-1 flex items-center gap-2"><?= icon('check', 'w-4 h-4 text-slate-400') ?> Probar antes</h3>
        <p class="text-sm text-slate-500 mb-3">Envíate el correo a ti mismo y míralo en tu teléfono antes de soltarlo.</p>
        <form method="post" class="flex gap-2">
          <?= csrf_field() ?><input type="hidden" name="accion" value="prueba">
          <input type="email" name="email_prueba" class="input" placeholder="tu@correo.com"
                 value="<?= e((string) (current_user()['email'] ?? '')) ?>">
          <button class="btn btn-soft shrink-0" <?= mail_configurado() ? '' : 'disabled' ?>><?= icon('mail', 'w-4 h-4') ?></button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
  // Insertar variables en el cuerpo, en la posición del cursor.
  document.querySelectorAll('.js-var').forEach(b => b.addEventListener('click', () => {
    const ta = document.querySelector('textarea[name="contenido"]');
    if (!ta) return;
    const v = b.dataset.var, i = ta.selectionStart ?? ta.value.length;
    ta.value = ta.value.slice(0, i) + v + ta.value.slice(ta.selectionEnd ?? i);
    ta.focus();
    ta.selectionStart = ta.selectionEnd = i + v.length;
  }));

  // Aplicar plantilla desde el selector de la cabecera.
  const pltSel = document.getElementById('pltSel');
  if (pltSel) pltSel.addEventListener('change', () => {
    if (!pltSel.value) return;
    if (!confirm('Se reemplazarán el asunto y el cuerpo por los de la plantilla. ¿Continuar?')) { pltSel.value = ''; return; }
    document.getElementById('pltId').value = pltSel.value;
    document.getElementById('frmPlantilla').submit();
  });
</script>

<?php layout_end(); ?>
