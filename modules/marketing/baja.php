<?php
/**
 * Página pública de baja (opt-out). La abre el cliente desde el pie del correo.
 *
 * La baja se confirma con un POST, no con el simple clic del enlace: los
 * antivirus y los escáneres de correo abren TODOS los enlaces de un mensaje, y
 * con una baja por GET darían de baja a gente que nunca lo pidió.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$token = preg_replace('/[^a-f0-9]/i', '', (string) input('t'));
$envio = null;
$campana = null;

if ($token !== '' && mkt_disponible()) {
    $envio = qOne("SELECT * FROM campana_envios WHERE token = ?", [$token]);
    if ($envio) $campana = qOne("SELECT id, nombre FROM campanas WHERE id = ?", [(int) $envio['campana_id']]);
}

$empresa = $GLOBALS['empresa'] ?? [];
$nombreEmpresa = $empresa['nombre'] ?? APP_NAME;
$hecho = false;
$yaEstaba = false;

if (isPost() && $envio) {
    $motivo = mb_substr(trim((string) post('motivo')), 0, 180);
    $yaEstaba = mkt_dado_de_baja($envio['canal'], $envio['destino']);
    if (!$yaEstaba) {
        mkt_dar_de_baja(
            $envio['canal'],
            $envio['destino'],
            $envio['cliente_id'] ? (int) $envio['cliente_id'] : null,
            (int) $envio['campana_id'],
            $motivo
        );
    }
    $hecho = true;
} elseif ($envio) {
    $yaEstaba = mkt_dado_de_baja($envio['canal'], $envio['destino']);
}

$destinoVisible = $envio ? $envio['destino'] : '';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Preferencias de correo · <?= e($nombreEmpresa) ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-emerald-50 flex items-center justify-center p-6 font-sans text-slate-700">
  <div class="w-full max-w-md bg-white rounded-2xl shadow-xl border border-emerald-100 overflow-hidden">
    <div class="bg-emerald-700 px-6 py-5">
      <p class="text-white font-bold text-lg"><?= e($nombreEmpresa) ?></p>
    </div>

    <div class="p-7">
      <?php if (!$envio): ?>
        <h1 class="text-xl font-bold text-slate-800">Enlace no válido</h1>
        <p class="mt-2 text-sm text-slate-500">
          Este enlace de baja ya no existe o fue copiado incompleto. Si no quieres recibir
          más promociones, responde al correo que recibiste y lo gestionamos a mano.
        </p>

      <?php elseif ($hecho || $yaEstaba): ?>
        <div class="w-12 h-12 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center mb-4">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
        </div>
        <h1 class="text-xl font-bold text-slate-800">Listo, no te escribimos más</h1>
        <p class="mt-2 text-sm text-slate-500">
          Hemos dado de baja <strong class="text-slate-700"><?= e($destinoVisible) ?></strong> de nuestras
          promociones. Puede que recibas algún correo ya en camino, pero no habrá más.
        </p>
        <p class="mt-4 text-sm text-slate-500">
          Los correos de tus pedidos y compras <strong>sí</strong> seguirán llegando: son parte del servicio,
          no publicidad.
        </p>

      <?php else: ?>
        <h1 class="text-xl font-bold text-slate-800">¿Dejar de recibir promociones?</h1>
        <p class="mt-2 text-sm text-slate-500">
          Vamos a dar de baja <strong class="text-slate-700"><?= e($destinoVisible) ?></strong>.
          No volverás a recibir campañas ni promociones de <?= e($nombreEmpresa) ?>.
        </p>
        <?php if ($campana): ?>
          <p class="mt-1 text-xs text-slate-400">Campaña: <?= e($campana['nombre']) ?></p>
        <?php endif; ?>

        <form method="post" class="mt-6 space-y-4">
          <input type="hidden" name="t" value="<?= e($token) ?>">
          <div>
            <label class="block text-sm font-medium text-slate-600 mb-1">¿Nos dices por qué? (opcional)</label>
            <select name="motivo" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
              <option value="">Prefiero no decirlo</option>
              <option value="Demasiados correos">Recibo demasiados correos</option>
              <option value="No me interesa el contenido">El contenido no me interesa</option>
              <option value="Nunca me suscribí">Nunca me suscribí</option>
              <option value="Ya no soy cliente">Ya no soy cliente</option>
            </select>
          </div>
          <button type="submit"
                  class="w-full bg-emerald-700 hover:bg-emerald-800 text-white font-semibold px-5 py-3 rounded-xl transition">
            Confirmar la baja
          </button>
          <a href="<?= e(url('tienda/index.php')) ?>"
             class="block text-center text-sm text-slate-400 hover:text-slate-600">
            Mejor sigo recibiéndolas
          </a>
        </form>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
