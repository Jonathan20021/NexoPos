<?php
/**
 * Facturación Electrónica (e-CF) — configuración, diagnóstico y consola de pruebas.
 *
 * Esta pantalla es el sitio donde se certifica la integración: se cargan las
 * credenciales, se prueba la conexión, se genera la trama de una venta real
 * para revisarla ANTES de enviarla, y se sigue el estado de lo emitido.
 *
 * Mientras `ecf_config.activo` esté apagado, nada de esto altera la facturación
 * normal del POS: sirve para probar contra el ambiente de pruebas del proveedor.
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('ecf.ver');

$cfg      = ecfConfig(true);
$empresa  = $GLOBALS['empresa'] ?: [];
$pestanas = ['estado' => 'Estado', 'config' => 'Configuración', 'secuencias' => 'Secuencias',
             'consola' => 'Consola de pruebas', 'documentos' => 'Comprobantes', 'bitacora' => 'Bitácora'];
$tab = array_key_exists((string) get('tab'), $pestanas) ? (string) get('tab') : 'estado';

$volver = static function (string $tab): void {
    redirect('modules/finanzas/ecf.php?tab=' . $tab);
};

/* ============================================================
 *  ACCIONES
 * ============================================================ */
$resultadoPrueba = null;
$previsualizacion = null;

if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    // ---------- Guardar configuración ----------
    if ($accion === 'guardar_config') {
        require_perm('ecf.configurar');

        $datos = [
            'ambiente'         => post('ambiente') === 'produccion' ? 'produccion' : 'stage',
            'url_stage'        => trim(post('url_stage')) ?: 'https://rd.stage-api.tech-luganis.net',
            'url_produccion'   => trim(post('url_produccion')) ?: null,
            'usuario'          => trim(post('usuario')) ?: null,
            'device_id'        => trim(post('device_id')) ?: null,
            'app_version'      => trim(post('app_version')) ?: '1.0.0',
            'latitud'          => post('latitud')  !== '' ? (float) post('latitud')  : null,
            'longitud'         => post('longitud') !== '' ? (float) post('longitud') : null,
            'ip_publica'       => trim(post('ip_publica')) ?: null,
            'envio_automatico' => post('envio_automatico') ? 1 : 0,
            'reintentos_max'   => max(1, min(20, postInt('reintentos_max', 5))),
            'activo'           => post('activo') ? 1 : 0,
        ];
        // La clave solo se toca si escribieron una nueva: así no se borra al
        // guardar el resto del formulario.
        if (trim(post('clave')) !== '') $datos['clave'] = trim(post('clave'));

        // Cambiar de ambiente invalida la sesión: el token de pruebas no sirve
        // en producción y arrastrarlo produce un 401 desconcertante.
        if (($cfg['ambiente'] ?? 'stage') !== $datos['ambiente']) {
            $datos['access_token'] = null;
            $datos['refresh_token'] = null;
            $datos['token_expira'] = null;
        }

        ecfGuardarConfig($datos);
        audit('ecf', 'configurar', 'Configuración del proveedor actualizada (ambiente: ' . $datos['ambiente'] . ')');
        flash('success', 'Configuración guardada.');
        $volver('config');
    }

    // ---------- Probar conexión ----------
    if ($accion === 'probar_conexion') {
        require_perm('ecf.configurar');
        $r = ecfLogin();
        $resultadoPrueba = $r;
        audit('ecf', 'probar', 'Prueba de conexión: ' . ($r['ok'] ? 'exitosa' : 'fallida'));
        flash($r['ok'] ? 'success' : 'error', $r['mensaje']);
        if ($r['ok']) $volver('estado');
        $tab = 'estado';
    }

    // ---------- Cerrar sesión con el proveedor ----------
    if ($accion === 'logout') {
        require_perm('ecf.configurar');
        $r = ecfLogout();
        flash('success', $r['mensaje']);
        $volver('estado');
    }

    // ---------- Guardar una secuencia ----------
    if ($accion === 'guardar_secuencia') {
        require_perm('ecf.configurar');
        $tipo  = (string) post('tipo');
        if (!in_array($tipo, ecfTiposSoportados(), true)) {
            flash('error', 'Tipo de e-CF no soportado.');
            $volver('secuencias');
        }
        $desde = max(1, postInt('desde', 1));
        $hasta = max(0, postInt('hasta', 0));
        if ($hasta > 0 && $hasta < $desde) {
            flash('error', 'El fin del rango no puede ser menor que el inicio.');
            $volver('secuencias');
        }

        $fila = qOne("SELECT * FROM ncf_secuencias WHERE tipo = ?", ['E' . $tipo]);
        $datos = [
            'secuencia_hasta' => $hasta,
            'vencimiento'     => post('vencimiento') ?: null,
            'activo'          => post('activo_sec') ? 1 : 0,
        ];
        // El número en curso solo se mueve hacia ADELANTE y solo si nunca se
        // emitió con esta secuencia. Retroceder repetiría un e-NCF ya usado.
        $emitidos = (int) qVal("SELECT COUNT(*) FROM ecf_documentos WHERE tipo_ecf = ?", [$tipo]);
        if ($emitidos === 0) {
            $datos['secuencia_actual'] = $desde;
        } elseif ($desde > (int) $fila['secuencia_actual']) {
            $datos['secuencia_actual'] = $desde;
        }

        dbUpdate('ncf_secuencias', $datos, 'tipo = ?', ['E' . $tipo]);
        audit('ecf', 'configurar', "Secuencia E$tipo actualizada (rango hasta $hasta)");
        flash('success', "Secuencia E$tipo guardada.");
        $volver('secuencias');
    }

    // ---------- Previsualizar la trama de una venta ----------
    if ($accion === 'previsualizar') {
        $ventaId = postInt('venta_id');
        try {
            $tipoPrev = (string) qVal("SELECT tipo_comprobante FROM ventas WHERE id = ?", [$ventaId]);
            $doc = ecfDocumentoDeVenta($ventaId, ecfFormatearENCF(ecfTipoDesdeComprobante($tipoPrev), 0));
            $previsualizacion = [
                'venta_id' => $ventaId,
                'trama'    => ecfConstruirTrama($doc),
                'errores'  => ecfValidarDocumento($doc),
                'doc'      => $doc,
            ];
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        $tab = 'consola';
    }

    // ---------- Emitir de verdad ----------
    if ($accion === 'emitir') {
        require_perm('ecf.emitir');
        $ventaId = postInt('venta_id');
        $r = ecfEmitirVenta($ventaId);
        audit('ecf', 'emitir', "Emisión de la venta #$ventaId: " . ($r['ok'] ? $r['encf'] : 'fallida'));
        if ($r['errores']) {
            flash('error', $r['mensaje'] . ' ' . implode(' ', $r['errores']));
        } else {
            flash($r['ok'] ? 'success' : 'error', $r['mensaje']);
        }
        $volver($r['ok'] ? 'documentos' : 'consola');
    }

    // ---------- Reenviar / consultar un documento ----------
    if ($accion === 'reenviar') {
        require_perm('ecf.emitir');
        $r = ecfEnviarDocumento(postInt('documento_id'));
        flash($r['ok'] ? 'success' : 'error', $r['mensaje']);
        $volver('documentos');
    }
    if ($accion === 'consultar') {
        require_perm('ecf.emitir');
        $r = ecfActualizarEstado(postInt('documento_id'));
        flash($r['ok'] ? 'success' : 'error', $r['mensaje']);
        $volver('documentos');
    }
    if ($accion === 'procesar_cola') {
        require_perm('ecf.emitir');
        $r = ecfProcesarCola();
        flash('success', "Cola procesada: {$r['enviados']} enviados, {$r['fallidos']} con error, {$r['consultados']} consultados.");
        $volver('documentos');
    }
}

// ---------- Descarga de la trama de un documento ----------
if (get('descargar')) {
    $doc = qOne("SELECT * FROM ecf_documentos WHERE id = ?", [(int) get('descargar')]);
    if ($doc) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $doc['archivo'] . '"');
        header('Content-Length: ' . strlen($doc['trama']));
        echo $doc['trama'];
        exit;
    }
}

$cfg = ecfConfig(true);

/* ============================================================
 *  VISTA
 * ============================================================ */
$estadoBadge = ecfActivo()
    ? '<span class="badge badge-emerald">Activa</span>'
    : '<span class="badge badge-slate">Apagada</span>';
$ambienteBadge = ($cfg['ambiente'] ?? 'stage') === 'produccion'
    ? '<span class="badge badge-rose">Producción</span>'
    : '<span class="badge badge-amber">Pruebas</span>';

layout_start(
    'Facturación Electrónica',
    'Comprobantes Fiscales Electrónicos (e-CF) · Proveedor LUGANIS',
    $estadoBadge . ' ' . $ambienteBadge
);
?>

<!-- Pestañas -->
<div class="flex gap-1 overflow-x-auto pb-1 mb-5">
  <?php foreach ($pestanas as $clave => $etiqueta): ?>
    <a href="?tab=<?= $clave ?>"
       class="btn btn-sm whitespace-nowrap <?= $tab === $clave ? 'btn-primary' : 'btn-ghost' ?>">
      <?= e($etiqueta) ?>
    </a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'estado'): ?>
  <?php $checks = ecfDiagnostico(); ?>
  <div class="grid lg:grid-cols-3 gap-5">
    <div class="lg:col-span-2 card p-6">
      <h2 class="font-bold text-slate-800 mb-1">Lista de verificación</h2>
      <p class="text-sm text-slate-500 mb-5">Todo esto debe estar en verde antes de emitir un comprobante real.</p>

      <div class="space-y-3">
        <?php foreach ($checks as $c): ?>
          <?php
            $color = $c['ok'] ? 'emerald' : (!empty($c['aviso']) ? 'amber' : 'rose');
            $icono = $c['ok'] ? 'check' : ($color === 'amber' ? 'alert' : 'x');
          ?>
          <div class="flex items-start gap-3 p-3.5 rounded-xl bg-<?= $color ?>-50/50 border border-<?= $color ?>-100">
            <span class="mt-0.5 shrink-0 w-6 h-6 rounded-full bg-<?= $color ?>-100 text-<?= $color ?>-700 flex items-center justify-center">
              <?= icon($icono, 'w-3.5 h-3.5') ?>
            </span>
            <div class="min-w-0">
              <p class="text-sm font-semibold text-slate-800"><?= e($c['titulo']) ?></p>
              <p class="text-xs text-slate-500 mt-0.5"><?= e($c['detalle']) ?></p>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="space-y-5">
      <div class="card p-6">
        <h2 class="font-bold text-slate-800 mb-4">Conexión</h2>
        <dl class="text-sm space-y-2.5">
          <div class="flex justify-between gap-3">
            <dt class="text-slate-500">Ambiente</dt>
            <dd class="font-medium text-slate-700"><?= ($cfg['ambiente'] ?? 'stage') === 'produccion' ? 'Producción' : 'Pruebas' ?></dd>
          </div>
          <div class="flex justify-between gap-3">
            <dt class="text-slate-500">Servidor</dt>
            <dd class="font-mono text-[11px] text-slate-600 text-right break-all">
              <?= e(($cfg['ambiente'] ?? 'stage') === 'produccion' ? ($cfg['url_produccion'] ?: '—') : $cfg['url_stage']) ?>
            </dd>
          </div>
          <div class="flex justify-between gap-3">
            <dt class="text-slate-500">device-id</dt>
            <dd class="font-mono text-[11px] text-slate-600"><?= e($cfg['device_id'] ?: '—') ?></dd>
          </div>
          <div class="flex justify-between gap-3">
            <dt class="text-slate-500">Token</dt>
            <dd class="font-medium text-slate-700">
              <?php if (!empty($cfg['token_expira']) && strtotime($cfg['token_expira']) > time()): ?>
                <span class="badge badge-emerald">vence <?= e(date('H:i', strtotime($cfg['token_expira']))) ?></span>
              <?php else: ?>
                <span class="badge badge-slate">sin sesión</span>
              <?php endif; ?>
            </dd>
          </div>
        </dl>

        <?php if (can('ecf.configurar')): ?>
          <form method="post" class="mt-5 space-y-2">
            <?= csrf_field() ?>
            <button name="accion" value="probar_conexion" class="btn btn-primary w-full">
              <?= icon('pulse', 'w-4 h-4') ?> Probar conexión
            </button>
            <?php if (!empty($cfg['access_token'])): ?>
              <button name="accion" value="logout" class="btn btn-ghost w-full">Cerrar sesión con el proveedor</button>
            <?php endif; ?>
          </form>
        <?php endif; ?>
      </div>

      <div class="card p-6">
        <h2 class="font-bold text-slate-800 mb-3">Resumen</h2>
        <?php
          $totales = qOne("SELECT COUNT(*) n,
                                  SUM(estado='aceptado') aceptados,
                                  SUM(estado='enviado') enviados,
                                  SUM(estado IN ('pendiente','error')) problemas
                             FROM ecf_documentos") ?: [];
        ?>
        <div class="grid grid-cols-2 gap-3 text-center">
          <div class="p-3 rounded-xl bg-slate-50">
            <p class="text-2xl font-bold text-slate-800"><?= (int) ($totales['n'] ?? 0) ?></p>
            <p class="text-[11px] text-slate-500 uppercase tracking-wide">Emitidos</p>
          </div>
          <div class="p-3 rounded-xl bg-emerald-50">
            <p class="text-2xl font-bold text-emerald-700"><?= (int) ($totales['aceptados'] ?? 0) ?></p>
            <p class="text-[11px] text-emerald-600 uppercase tracking-wide">Aceptados</p>
          </div>
          <div class="p-3 rounded-xl bg-sky-50">
            <p class="text-2xl font-bold text-sky-700"><?= (int) ($totales['enviados'] ?? 0) ?></p>
            <p class="text-[11px] text-sky-600 uppercase tracking-wide">En proceso</p>
          </div>
          <div class="p-3 rounded-xl bg-rose-50">
            <p class="text-2xl font-bold text-rose-700"><?= (int) ($totales['problemas'] ?? 0) ?></p>
            <p class="text-[11px] text-rose-600 uppercase tracking-wide">Con problema</p>
          </div>
        </div>
      </div>
    </div>
  </div>

<?php elseif ($tab === 'config'): ?>
  <form method="post" class="grid lg:grid-cols-3 gap-5">
    <?= csrf_field() ?>
    <div class="lg:col-span-2 space-y-5">
      <div class="card p-6">
        <h2 class="font-bold text-slate-800 mb-5">Credenciales del proveedor</h2>
        <div class="grid sm:grid-cols-2 gap-4">
          <div>
            <label class="label">Usuario</label>
            <input name="usuario" class="input" value="<?= e($cfg['usuario'] ?? '') ?>"
                   placeholder="El que entrega LUGANIS" <?= can('ecf.configurar') ? '' : 'disabled' ?>>
          </div>
          <div>
            <label class="label">Clave</label>
            <input name="clave" type="password" class="input" autocomplete="new-password"
                   placeholder="<?= $cfg['clave'] ? 'Guardada — escribe para cambiarla' : 'Entre 8 y 16 caracteres' ?>"
                   <?= can('ecf.configurar') ? '' : 'disabled' ?>>
          </div>
        </div>
        <?php if (defined('ECF_USUARIO') || defined('ECF_CLAVE')): ?>
          <p class="text-xs text-amber-700 bg-amber-50 rounded-lg px-3 py-2 mt-4">
            Hay credenciales definidas en <code>config/config.local.php</code>. Esas mandan sobre lo que se guarde aquí.
          </p>
        <?php else: ?>
          <p class="text-xs text-slate-500 mt-4">
            Para producción conviene moverlas a <code>config/config.local.php</code>
            (<code>ECF_USUARIO</code> y <code>ECF_CLAVE</code>): ese archivo no se versiona ni entra en los respaldos de la base.
          </p>
        <?php endif; ?>
      </div>

      <div class="card p-6">
        <h2 class="font-bold text-slate-800 mb-1">Ambiente</h2>
        <p class="text-sm text-slate-500 mb-5">En producción los comprobantes son reales y se remiten a la DGII.</p>
        <div class="grid sm:grid-cols-2 gap-4">
          <div>
            <label class="label">Ambiente activo</label>
            <select name="ambiente" class="select" <?= can('ecf.configurar') ? '' : 'disabled' ?>>
              <option value="stage" <?= ($cfg['ambiente'] ?? '') === 'stage' ? 'selected' : '' ?>>Pruebas (stage)</option>
              <option value="produccion" <?= ($cfg['ambiente'] ?? '') === 'produccion' ? 'selected' : '' ?>>Producción</option>
            </select>
          </div>
          <div>
            <label class="label">Versión de la aplicación</label>
            <input name="app_version" class="input" value="<?= e($cfg['app_version'] ?? '1.0.0') ?>" <?= can('ecf.configurar') ? '' : 'disabled' ?>>
          </div>
          <div class="sm:col-span-2">
            <label class="label">URL de pruebas</label>
            <input name="url_stage" class="input font-mono text-xs" value="<?= e($cfg['url_stage'] ?? '') ?>" <?= can('ecf.configurar') ? '' : 'disabled' ?>>
          </div>
          <div class="sm:col-span-2">
            <label class="label">URL de producción</label>
            <input name="url_produccion" class="input font-mono text-xs" value="<?= e($cfg['url_produccion'] ?? '') ?>"
                   placeholder="El manual no la publica: la entrega el consultor" <?= can('ecf.configurar') ? '' : 'disabled' ?>>
          </div>
        </div>
      </div>

      <div class="card p-6">
        <h2 class="font-bold text-slate-800 mb-1">Identificación del servidor</h2>
        <p class="text-sm text-slate-500 mb-5">
          El login exige georreferencia e IP pública, y el <code>device-id</code> debe mantenerse estable:
          ata la sesión y viaja en todas las peticiones.
        </p>
        <div class="grid sm:grid-cols-3 gap-4">
          <div class="sm:col-span-3">
            <label class="label">device-id</label>
            <input name="device_id" class="input font-mono text-xs" value="<?= e($cfg['device_id'] ?? '') ?>" <?= can('ecf.configurar') ? '' : 'disabled' ?>>
          </div>
          <div>
            <label class="label">Latitud</label>
            <input name="latitud" class="input" value="<?= e($cfg['latitud'] ?? '') ?>" placeholder="18.486058" <?= can('ecf.configurar') ? '' : 'disabled' ?>>
          </div>
          <div>
            <label class="label">Longitud</label>
            <input name="longitud" class="input" value="<?= e($cfg['longitud'] ?? '') ?>" placeholder="-69.931212" <?= can('ecf.configurar') ? '' : 'disabled' ?>>
          </div>
          <div>
            <label class="label">IP pública</label>
            <input name="ip_publica" class="input" value="<?= e($cfg['ip_publica'] ?? '') ?>" placeholder="190.x.x.x" <?= can('ecf.configurar') ? '' : 'disabled' ?>>
          </div>
        </div>
      </div>
    </div>

    <div class="space-y-5">
      <div class="card p-6">
        <h2 class="font-bold text-slate-800 mb-5">Operación</h2>

        <label class="flex items-start gap-3 p-3.5 rounded-xl border border-slate-200 cursor-pointer hover:bg-slate-50">
          <input type="checkbox" name="activo" value="1" class="mt-0.5" <?= !empty($cfg['activo']) ? 'checked' : '' ?> <?= can('ecf.configurar') ? '' : 'disabled' ?>>
          <span>
            <span class="block text-sm font-semibold text-slate-800">Facturación electrónica activa</span>
            <span class="block text-xs text-slate-500 mt-0.5">
              Apagada, el POS sigue facturando con NCF preimpreso y nada cambia.
            </span>
          </span>
        </label>

        <label class="flex items-start gap-3 p-3.5 rounded-xl border border-slate-200 cursor-pointer hover:bg-slate-50 mt-3">
          <input type="checkbox" name="envio_automatico" value="1" class="mt-0.5" <?= !empty($cfg['envio_automatico']) ? 'checked' : '' ?> <?= can('ecf.configurar') ? '' : 'disabled' ?>>
          <span>
            <span class="block text-sm font-semibold text-slate-800">Enviar al cerrar la venta</span>
            <span class="block text-xs text-slate-500 mt-0.5">
              Si está apagado, los comprobantes quedan en cola y se envían desde esta pantalla.
            </span>
          </span>
        </label>

        <div class="mt-5">
          <label class="label">Reintentos máximos</label>
          <input name="reintentos_max" type="number" min="1" max="20" class="input"
                 value="<?= (int) ($cfg['reintentos_max'] ?? 5) ?>" <?= can('ecf.configurar') ? '' : 'disabled' ?>>
          <p class="text-xs text-slate-500 mt-1.5">La espera entre intentos se duplica: 1, 2, 4, 8… minutos.</p>
        </div>
      </div>

      <?php if (can('ecf.configurar')): ?>
        <button name="accion" value="guardar_config" class="btn btn-primary w-full">
          <?= icon('check', 'w-4 h-4') ?> Guardar configuración
        </button>
      <?php endif; ?>
    </div>
  </form>

<?php elseif ($tab === 'secuencias'): ?>
  <div class="card p-6 mb-5">
    <h2 class="font-bold text-slate-800 mb-1">Secuencias autorizadas (e-NCF)</h2>
    <p class="text-sm text-slate-500">
      Los rangos los asigna la DGII por tipo de comprobante. Nacen desactivados y con rango cero
      a propósito: emitir con una secuencia que no está autorizada obliga después a reportarla como anulada.
    </p>
  </div>

  <div class="grid md:grid-cols-2 gap-5">
    <?php foreach (ecfTiposSoportados() as $t): ?>
      <?php
        $sec = qOne("SELECT * FROM ncf_secuencias WHERE tipo = ?", ['E' . $t]) ?: [];
        $est = ecfEstadoSecuencia($t);
        $usados = (int) qVal("SELECT COUNT(*) FROM ecf_documentos WHERE tipo_ecf = ?", [$t]);
      ?>
      <form method="post" class="card p-6">
        <?= csrf_field() ?>
        <input type="hidden" name="tipo" value="<?= $t ?>">

        <div class="flex items-start justify-between gap-3 mb-4">
          <div>
            <h3 class="font-bold text-slate-800">E<?= $t ?></h3>
            <p class="text-xs text-slate-500"><?= e(ecfTiposComprobante()[$t]) ?></p>
          </div>
          <span class="badge <?= $est['ok'] ? 'badge-emerald' : 'badge-slate' ?>">
            <?= $est['ok'] ? 'Lista' : 'Sin configurar' ?>
          </span>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="label">Desde</label>
            <input name="desde" type="number" min="1" class="input"
                   value="<?= (int) ($sec['secuencia_actual'] ?? 1) ?>" <?= can('ecf.configurar') ? '' : 'disabled' ?>>
          </div>
          <div>
            <label class="label">Hasta</label>
            <input name="hasta" type="number" min="0" class="input"
                   value="<?= (int) ($sec['secuencia_hasta'] ?? 0) ?>" <?= can('ecf.configurar') ? '' : 'disabled' ?>>
          </div>
          <div class="col-span-2">
            <label class="label">Vencimiento de la secuencia</label>
            <input name="vencimiento" type="date" class="input"
                   value="<?= e($sec['vencimiento'] ?? '') ?>" <?= can('ecf.configurar') ? '' : 'disabled' ?>>
            <?php if ($t === '31' || $t === '33'): ?>
              <p class="text-xs text-amber-700 mt-1.5">Obligatoria: el tipo E<?= $t ?> la transmite en la trama.</p>
            <?php endif; ?>
          </div>
        </div>

        <label class="flex items-center gap-2.5 mt-4 text-sm text-slate-700 cursor-pointer">
          <input type="checkbox" name="activo_sec" value="1" <?= !empty($sec['activo']) ? 'checked' : '' ?> <?= can('ecf.configurar') ? '' : 'disabled' ?>>
          Secuencia activa
        </label>

        <p class="text-xs text-slate-500 mt-3"><?= e($est['mensaje']) ?></p>
        <?php if ($usados > 0): ?>
          <p class="text-xs text-slate-400 mt-1"><?= $usados ?> comprobante(s) emitido(s). El número en curso ya no se puede retroceder.</p>
        <?php endif; ?>

        <?php if (can('ecf.configurar')): ?>
          <button name="accion" value="guardar_secuencia" class="btn btn-ghost btn-sm w-full mt-4">Guardar</button>
        <?php endif; ?>
      </form>
    <?php endforeach; ?>
  </div>

<?php elseif ($tab === 'consola'): ?>
  <?php
    $ventas = qAll(
        "SELECT v.id, v.numero, v.fecha, v.total, v.ncf, v.tipo_comprobante, c.nombre AS cliente,
                (SELECT COUNT(*) FROM ecf_documentos d WHERE d.origen='venta' AND d.origen_id=v.id) AS emitido
           FROM ventas v LEFT JOIN clientes c ON c.id = v.cliente_id
          WHERE v.estado = 'completada'
          ORDER BY v.id DESC LIMIT 40"
    );
  ?>
  <div class="grid lg:grid-cols-5 gap-5">
    <div class="lg:col-span-2 card p-6">
      <h2 class="font-bold text-slate-800 mb-1">Generar desde una venta real</h2>
      <p class="text-sm text-slate-500 mb-5">
        Previsualizar arma la trama y la valida <strong>sin</strong> consumir secuencia ni enviar nada.
      </p>

      <div class="space-y-2 max-h-[28rem] overflow-y-auto -mx-2 px-2">
        <?php foreach ($ventas as $v): ?>
          <form method="post" class="flex items-center gap-3 p-3 rounded-xl border border-slate-200 hover:bg-slate-50">
            <?= csrf_field() ?>
            <input type="hidden" name="venta_id" value="<?= (int) $v['id'] ?>">
            <div class="min-w-0 flex-1">
              <p class="text-sm font-semibold text-slate-800 truncate">
                <?= e($v['numero']) ?>
                <span class="badge badge-slate ml-1"><?= $v['tipo_comprobante'] === 'credito_fiscal' ? 'E31' : 'E32' ?></span>
                <?php if ($v['emitido']): ?><span class="badge badge-emerald ml-1">emitido</span><?php endif; ?>
              </p>
              <p class="text-xs text-slate-500 truncate">
                <?= e($v['cliente'] ?: 'Sin cliente') ?> · <?= e(fechaCorta($v['fecha'])) ?> · <?= money($v['total']) ?>
              </p>
            </div>
            <button name="accion" value="previsualizar" class="btn btn-ghost btn-sm shrink-0">Ver trama</button>
          </form>
        <?php endforeach; ?>
        <?php if (!$ventas): ?>
          <?= empty_state('No hay ventas', 'Registra una venta para poder probar la generación de la trama.', 'receipt') ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="lg:col-span-3 space-y-5">
      <?php if ($previsualizacion): ?>
        <?php if ($previsualizacion['errores']): ?>
          <div class="card p-6 border-rose-200 bg-rose-50/40">
            <h3 class="font-bold text-rose-800 mb-3"><?= count($previsualizacion['errores']) ?> problema(s) que impiden emitir</h3>
            <ul class="text-sm text-rose-700 space-y-1.5 list-disc list-inside">
              <?php foreach ($previsualizacion['errores'] as $err): ?>
                <li><?= e($err) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php else: ?>
          <div class="card p-6 border-emerald-200 bg-emerald-50/40">
            <h3 class="font-bold text-emerald-800">La trama cumple todas las validaciones</h3>
            <p class="text-sm text-emerald-700 mt-1">
              El e-NCF de la vista previa es de relleno; al emitir se toma el siguiente real de la secuencia.
            </p>
          </div>
        <?php endif; ?>

        <div class="card p-6">
          <div class="flex items-center justify-between gap-3 mb-4">
            <h3 class="font-bold text-slate-800">Trama TXT</h3>
            <span class="text-xs text-slate-400">
              <?= strlen($previsualizacion['trama']) ?> bytes ·
              <?= count(explode("\r\n", $previsualizacion['trama'])) ?> líneas · UTF-8 sin BOM · CRLF
            </span>
          </div>
          <pre class="text-[11px] leading-relaxed font-mono bg-slate-900 text-slate-100 rounded-xl p-4 overflow-x-auto whitespace-pre"><?= e($previsualizacion['trama']) ?></pre>

          <?php if (!$previsualizacion['errores'] && can('ecf.emitir')): ?>
            <form method="post" class="mt-4"
                  onsubmit="return confirm('Esto consume un e-NCF de la secuencia autorizada y envía el comprobante al proveedor. ¿Continuar?');">
              <?= csrf_field() ?>
              <input type="hidden" name="venta_id" value="<?= (int) $previsualizacion['venta_id'] ?>">
              <button name="accion" value="emitir" class="btn btn-primary w-full">
                <?= icon('arrow-right', 'w-4 h-4') ?> Emitir y enviar al proveedor
              </button>
            </form>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="card p-10 text-center">
          <p class="text-slate-500 text-sm">Elige una venta de la izquierda para ver su trama.</p>
        </div>
      <?php endif; ?>
    </div>
  </div>

<?php elseif ($tab === 'documentos'): ?>
  <?php
    $pg = paginar((int) qVal("SELECT COUNT(*) FROM ecf_documentos"), 25);
    $docs = qAll(
        "SELECT d.*, s.nombre AS sucursal
           FROM ecf_documentos d LEFT JOIN sucursales s ON s.id = d.sucursal_id
          ORDER BY d.id DESC LIMIT {$pg['porPagina']} OFFSET {$pg['offset']}"
    );
    $colores = ['aceptado' => 'emerald', 'enviado' => 'sky', 'pendiente' => 'amber',
                'rechazado' => 'rose', 'error' => 'rose'];

    // Un documento «atascado» es el que salió hace rato y sigue sin veredicto.
    // No es lo mismo que uno recién enviado: si lleva más de una hora esperando
    // acuse, o la cola no está corriendo o el proveedor no contesta, y esa
    // factura no existe todavía para la DGII.
    $r = qOne(
        "SELECT COUNT(*) total,
                COALESCE(SUM(estado = 'aceptado'), 0) aceptados,
                COALESCE(SUM(estado IN ('rechazado','error')), 0) fallidos,
                COALESCE(SUM(estado IN ('pendiente','enviado')), 0) en_cola,
                COALESCE(SUM(estado IN ('pendiente','enviado')
                             AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)), 0) atascados
           FROM ecf_documentos"
    ) ?: ['total' => 0, 'aceptados' => 0, 'fallidos' => 0, 'en_cola' => 0, 'atascados' => 0];
    $tasa = (int) $r['total'] > 0 ? round((int) $r['aceptados'] / (int) $r['total'] * 100) : 0;

    echo kpis([
        ['label' => 'Aceptados por la DGII', 'valor' => number_format((int) $r['aceptados']), 'icono' => 'check',
         'color' => 'emerald', 'nota' => $tasa . '% de ' . number_format((int) $r['total']) . ' emitidos'],
        ['label' => 'Rechazados o con error', 'valor' => number_format((int) $r['fallidos']), 'icono' => 'alert',
         'color' => (int) $r['fallidos'] > 0 ? 'rose' : 'slate',
         'nota' => (int) $r['fallidos'] > 0 ? 'Esas facturas no existen para la DGII' : 'Ninguno'],
        ['label' => 'En cola', 'valor' => number_format((int) $r['en_cola']), 'icono' => 'clock',
         'color' => (int) $r['atascados'] > 0 ? 'amber' : ((int) $r['en_cola'] > 0 ? 'sky' : 'slate'),
         'nota' => (int) $r['atascados'] > 0
            ? number_format((int) $r['atascados']) . ' llevan más de una hora sin acuse'
            : ((int) $r['en_cola'] > 0 ? 'Esperando veredicto' : 'Nada pendiente')],
        ['label' => 'Ambiente', 'valor' => strtoupper((string) (ecfConfig()['ambiente'] ?? '—')),
         'icono' => 'shield', 'color' => (ecfConfig()['ambiente'] ?? '') === 'stage' ? 'amber' : 'violet',
         'nota' => (ecfConfig()['ambiente'] ?? '') === 'stage'
            ? 'Pruebas: no tiene valor fiscal' : 'Emisión real'],
    ], 4);
  ?>
  <?php if (can('ecf.emitir')): ?>
    <form method="post" class="mb-4">
      <?= csrf_field() ?>
      <button name="accion" value="procesar_cola" class="btn btn-ghost btn-sm">
        <?= icon('history', 'w-4 h-4') ?> Procesar cola (enviar pendientes y refrescar estados)
      </button>
    </form>
  <?php endif; ?>

  <div class="card overflow-hidden">
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead>
          <tr>
            <th>e-NCF</th><th>Origen</th><th>Comprador</th><th class="text-right">Total</th>
            <th>Estado</th><th>Ticket</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($docs as $d): ?>
            <tr>
              <td>
                <p class="font-mono text-xs font-semibold text-slate-800"><?= e($d['encf']) ?></p>
                <p class="text-[11px] text-slate-400"><?= e(fechaCorta($d['fecha_emision'])) ?> · <?= e($d['sucursal'] ?: '—') ?></p>
              </td>
              <td class="text-xs text-slate-600">
                <?= e(ucfirst($d['origen'])) ?><?= $d['origen_id'] ? ' #' . (int) $d['origen_id'] : '' ?>
              </td>
              <td class="text-xs text-slate-600">
                <?= e($d['razon_social_comprador'] ?: 'Consumidor final') ?>
                <?php if ($d['rnc_comprador']): ?>
                  <span class="block text-[11px] text-slate-400"><?= e($d['rnc_comprador']) ?></span>
                <?php endif; ?>
              </td>
              <td class="text-right font-semibold text-slate-800"><?= money($d['total']) ?></td>
              <td>
                <span class="badge badge-<?= $colores[$d['estado']] ?? 'slate' ?>"><?= e($d['estado']) ?></span>
                <?php if ($d['estado_detalle']): ?>
                  <?php
                    // El motivo de un rechazo es lo único que explica cómo arreglar la
                    // factura. Iba truncado a una línea con el texto completo solo en el
                    // «title»: en el móvil no hay dónde posar el ratón, así que quien
                    // trabaja desde el teléfono no llegaba a leerlo nunca.
                    $tonoDetalle = in_array($d['estado'], ['rechazado', 'error'], true)
                        ? 'text-rose-600 font-medium' : 'text-slate-400';
                  ?>
                  <span class="block text-[11px] <?= $tonoDetalle ?> mt-1 max-w-[20rem] leading-snug break-words">
                    <?= e($d['estado_detalle']) ?>
                  </span>
                <?php endif; ?>
              </td>
              <td class="font-mono text-[10px] text-slate-400"><?= e($d['track_id'] ?: '—') ?></td>
              <td>
                <div class="flex items-center gap-1.5 justify-end">
                  <a href="?descargar=<?= (int) $d['id'] ?>" class="btn btn-ghost btn-sm" title="Descargar la trama enviada">TXT</a>
                  <?php if (can('ecf.emitir')): ?>
                    <form method="post" class="inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="documento_id" value="<?= (int) $d['id'] ?>">
                      <?php if (in_array($d['estado'], ['pendiente', 'error'], true)): ?>
                        <button name="accion" value="reenviar" class="btn btn-soft btn-sm">Reenviar</button>
                      <?php elseif ($d['track_id']): ?>
                        <button name="accion" value="consultar" class="btn btn-ghost btn-sm">Consultar</button>
                      <?php endif; ?>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$docs): ?>
            <tr><td colspan="7" class="p-10">
              <?= empty_state('Todavía no se ha emitido ningún e-CF',
                              'Usa la consola de pruebas para generar el primero contra el ambiente de pruebas.', 'receipt') ?>
            </td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?= paginacion($pg) ?>

<?php elseif ($tab === 'bitacora'): ?>
  <?php
    $pgl = paginar((int) qVal("SELECT COUNT(*) FROM ecf_log"), 30);
    $logs = qAll("SELECT * FROM ecf_log ORDER BY id DESC LIMIT {$pgl['porPagina']} OFFSET {$pgl['offset']}");
  ?>
  <div class="card p-6 mb-5">
    <h2 class="font-bold text-slate-800 mb-1">Bitácora de llamadas</h2>
    <p class="text-sm text-slate-500">
      Cada petición al proveedor con su respuesta cruda. El manual no publica el catálogo de códigos de error,
      así que esto es lo que permite aprender qué responde de verdad. La clave y los tokens se guardan ocultos.
    </p>
  </div>

  <div class="space-y-3">
    <?php foreach ($logs as $l): ?>
      <details class="card p-4">
        <summary class="cursor-pointer flex items-center gap-3 flex-wrap">
          <span class="badge <?= $l['http_code'] === 200 ? 'badge-emerald' : ($l['http_code'] ? 'badge-rose' : 'badge-slate') ?>">
            <?= (int) $l['http_code'] ?: 'sin respuesta' ?>
          </span>
          <span class="text-xs font-semibold text-slate-700 uppercase tracking-wide"><?= e($l['operacion']) ?></span>
          <span class="font-mono text-[11px] text-slate-400 truncate"><?= e($l['metodo']) ?> <?= e($l['url']) ?></span>
          <span class="ml-auto text-[11px] text-slate-400"><?= (int) $l['ms'] ?> ms · <?= e(fechaHora($l['created_at'])) ?></span>
        </summary>
        <?php if ($l['error']): ?>
          <p class="mt-3 text-xs text-rose-700 bg-rose-50 rounded-lg px-3 py-2"><?= e($l['error']) ?></p>
        <?php endif; ?>
        <?php foreach (['peticion' => 'Petición', 'respuesta' => 'Respuesta'] as $campo => $titulo): ?>
          <?php if (!empty($l[$campo])): ?>
            <p class="text-[11px] font-semibold text-slate-500 uppercase tracking-wide mt-3 mb-1.5"><?= $titulo ?></p>
            <pre class="text-[11px] font-mono bg-slate-50 rounded-lg p-3 overflow-x-auto max-h-64 whitespace-pre-wrap break-all"><?= e(mb_substr($l[$campo], 0, 4000)) ?></pre>
          <?php endif; ?>
        <?php endforeach; ?>
      </details>
    <?php endforeach; ?>
    <?php if (!$logs): ?>
      <div class="card p-10">
        <?= empty_state('Sin llamadas registradas', 'Prueba la conexión desde la pestaña Estado.', 'pulse') ?>
      </div>
    <?php endif; ?>
  </div>
  <?= paginacion($pgl) ?>
<?php endif; ?>

<?php layout_end(); ?>
