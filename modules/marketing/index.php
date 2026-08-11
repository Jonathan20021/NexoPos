<?php
/**
 * Panel de Marketing: qué se envió, qué se abrió y qué vendió.
 *
 * La cifra que manda es la última: ventas atribuidas. Una campaña con 60% de
 * apertura y cero ventas es una campaña bonita que no sirvió.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('marketing.ver');

if (!mkt_disponible()) {
    layout_start('Panel de Marketing', 'Falta aplicar la migración');
    echo '<div class="card p-8 text-center">' . icon('alert', 'w-10 h-10 text-amber-500 mx-auto mb-3')
       . '<h3 class="font-bold text-slate-700">Módulo no disponible todavía</h3>'
       . '<p class="text-sm text-slate-500 mt-1">Aplica <code class="bg-slate-100 px-1.5 py-0.5 rounded">database/migracion_marketing_p9.sql</code> '
       . 'para activar segmentos, automatizaciones, rastreo y envíos por WhatsApp.</p></div>';
    layout_end();
    exit;
}

$dias = in_array((int) get('dias'), [7, 30, 90, 365], true) ? (int) get('dias') : 30;
$r = mkt_resumen($dias);

/* ---------- Serie diaria de envíos, aperturas y clics ---------- */
$desde = date('Y-m-d', strtotime('-' . ($dias - 1) . ' days'));
$serie = qAll(
    "SELECT DATE(enviado_at) d,
            COUNT(*) enviados,
            SUM(abierto_at IS NOT NULL) aperturas,
            SUM(clic_at IS NOT NULL) clics
       FROM campana_envios
      WHERE estado = 'enviado' AND enviado_at >= ?
      GROUP BY DATE(enviado_at)
      ORDER BY d", [$desde . ' 00:00:00']
);
$porDia = [];
foreach ($serie as $s) $porDia[$s['d']] = $s;

$labels = []; $vEnv = []; $vAp = []; $vCl = [];
for ($i = $dias - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $labels[] = date('d/m', strtotime($d));
    $vEnv[] = (int) ($porDia[$d]['enviados']  ?? 0);
    $vAp[]  = (int) ($porDia[$d]['aperturas'] ?? 0);
    $vCl[]  = (int) ($porDia[$d]['clics']     ?? 0);
}

/* ---------- Últimas campañas con sus cifras ---------- */
$ultimas = qAll(
    "SELECT c.*, s.nombre segmento_nombre
       FROM campanas c
       LEFT JOIN marketing_segmentos s ON s.id = c.segmento_id
      WHERE c.enviados > 0 OR c.estado IN ('enviando','programada')
      ORDER BY COALESCE(c.enviada_at, c.created_at) DESC
      LIMIT 6"
);

/* ---------- Quién hizo clic y todavía no ha comprado: llamadas que valen ---------- */
$calientes = qAll(
    "SELECT cl.id, cl.nombre, cl.telefono, cl.email, ce.clic_at, c.nombre campana
       FROM campana_envios ce
       JOIN campanas c  ON c.id = ce.campana_id
       JOIN clientes cl ON cl.id = ce.cliente_id
      WHERE ce.clic_at IS NOT NULL
        AND ce.clic_at >= (NOW() - INTERVAL 30 DAY)
        AND NOT EXISTS (SELECT 1 FROM ventas v
                         WHERE v.cliente_id = cl.id AND v.estado = 'completada' AND v.fecha >= ce.clic_at)
      ORDER BY ce.clic_at DESC
      LIMIT 8"
);

/* ---------- Cumpleaños del mes ---------- */
$cumples = qAll(
    "SELECT id, nombre, telefono, email, fecha_nacimiento
       FROM clientes
      WHERE activo = 1 AND fecha_nacimiento IS NOT NULL
        AND MONTH(fecha_nacimiento) = MONTH(CURDATE())
      ORDER BY DAY(fecha_nacimiento)
      LIMIT 8"
);

$automatizaciones = qAll("SELECT nombre, disparador, activo, enviados, ultimo_run
                            FROM marketing_automatizaciones ORDER BY activo DESC, disparador");
$disparadores = mkt_disparadores();

$acciones = can('campanas.crear')
    ? '<a href="' . e(url('modules/marketing/campanas.php?nueva=1')) . '" class="btn btn-primary">' . icon('plus', 'w-4 h-4') . ' Nueva campaña</a>'
    : '';

layout_start('Panel de Marketing', 'Últimos ' . $dias . ' días', $acciones);
?>

<!-- Selector de periodo -->
<div class="flex items-center justify-between gap-3 flex-wrap mb-5">
  <div class="flex items-center gap-1.5">
    <?php foreach ([7 => '7 días', 30 => '30 días', 90 => '90 días', 365 => '1 año'] as $v => $l): ?>
      <a href="<?= e(url('modules/marketing/index.php?dias=' . $v)) ?>"
         class="text-xs font-semibold px-3 py-1.5 rounded-lg <?= $dias === $v ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-500 hover:bg-slate-200' ?>"><?= e($l) ?></a>
    <?php endforeach; ?>
  </div>
  <?php if ($r['pendientes_wa'] > 0 && can('campanas.whatsapp')): ?>
    <a href="<?= e(url('modules/marketing/whatsapp.php')) ?>" class="btn btn-success btn-sm">
      <?= icon('phone', 'w-4 h-4') ?> <?= number_format($r['pendientes_wa']) ?> WhatsApp en cola
    </a>
  <?php endif; ?>
</div>

<?php if (!mail_configurado()): ?>
  <div class="card p-4 mb-5 flex items-start gap-3 border-amber-200 bg-amber-50">
    <?= icon('alert', 'w-5 h-5 text-amber-500 mt-0.5 shrink-0') ?>
    <p class="text-sm text-amber-800">
      <strong>El correo no está configurado.</strong> Falta <code class="bg-amber-100 px-1 rounded">RESEND_API_KEY</code>
      en <code class="bg-amber-100 px-1 rounded">config/config.local.php</code>. Puedes preparar campañas y usar WhatsApp,
      pero ningún correo saldrá.
    </p>
  </div>
<?php endif; ?>

<!-- KPIs -->
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 mb-5">
  <?php
  $kpis = [
      ['Mensajes enviados', number_format($r['enviados']), 'mail', 'blue',
       $r['contactables'] . ' clientes contactables'],
      ['Aperturas', $r['tasa_apertura'] . '%', 'eye', 'emerald',
       number_format($r['aperturas']) . ' de ' . number_format($r['enviados'])],
      ['Clics', $r['tasa_clic'] . '%', 'target', 'indigo',
       number_format($r['clics']) . ' clics registrados'],
      ['Ventas atribuidas', money($r['monto']), 'trending', 'violet',
       number_format($r['ventas']) . ' venta(s) tras el envío'],
  ];
  foreach ($kpis as [$lbl, $val, $ic, $col, $sub]): ?>
    <div class="card p-5">
      <div class="flex items-start justify-between mb-3">
        <p class="text-xs uppercase tracking-wide text-slate-400 font-semibold"><?= e($lbl) ?></p>
        <div class="w-9 h-9 rounded-xl bg-<?= $col ?>-50 text-<?= $col ?>-600 flex items-center justify-center">
          <?= icon($ic, 'w-4.5 h-4.5') ?>
        </div>
      </div>
      <p class="text-3xl font-bold text-slate-800"><?= e($val) ?></p>
      <p class="text-xs text-slate-400 mt-1"><?= e($sub) ?></p>
    </div>
  <?php endforeach; ?>
</div>

<div class="grid lg:grid-cols-5 gap-5 mb-5 items-start">

  <!-- Evolución -->
  <div class="card lg:col-span-3 h-full flex flex-col">
    <div class="px-5 py-4 border-b border-slate-100">
      <h2 class="font-bold text-slate-800">Actividad de envíos</h2>
      <p class="text-xs text-slate-400">Enviados, aperturas y clics por día</p>
    </div>
    <div class="px-5 pb-5 pt-4 flex-1 flex flex-col justify-center">
      <?php if (array_sum($vEnv) === 0): ?>
        <?= empty_state('Todavía no has enviado nada', 'Cuando salga tu primera campaña, aquí verás cómo responde la gente.', 'chart',
            can('campanas.crear') ? '<a href="' . e(url('modules/marketing/campanas.php?nueva=1')) . '" class="btn btn-primary">' . icon('plus', 'w-4 h-4') . ' Crear campaña</a>' : '') ?>
      <?php else: ?>
        <?= lineChart([
            ['nombre' => 'Enviados',  'color' => '#47599E', 'valores' => $vEnv, 'area' => true],
            ['nombre' => 'Aperturas', 'color' => '#10B981', 'valores' => $vAp],
            ['nombre' => 'Clics',     'color' => '#6366F1', 'valores' => $vCl],
        ], $labels, ['alto' => 260, 'formato' => 'num', 'leyenda' => true]) ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Embudo -->
  <div class="card lg:col-span-2 h-full flex flex-col">
    <div class="px-5 py-4 border-b border-slate-100">
      <h2 class="font-bold text-slate-800">Del envío a la venta</h2>
      <p class="text-xs text-slate-400">Últimos <?= $dias ?> días</p>
    </div>
    <div class="px-5 pb-5 pt-4 flex-1 flex flex-col justify-center">
      <?php
      $base = max(1, $r['enviados']);
      $pasos = [
          ['Enviados',  $r['enviados'],  'bg-blue-500'],
          ['Abiertos',  $r['aperturas'], 'bg-emerald-500'],
          ['Con clic',  $r['clics'],     'bg-indigo-500'],
          ['Compraron', $r['ventas'],    'bg-violet-500'],
      ];
      foreach ($pasos as [$lbl, $val, $color]):
        $pct = round($val * 100 / $base, 1); ?>
        <div class="mb-4 last:mb-0">
          <div class="flex items-center justify-between text-sm mb-1.5">
            <span class="text-slate-600 font-medium"><?= e($lbl) ?></span>
            <span class="text-slate-800 font-bold"><?= number_format($val) ?>
              <span class="text-xs text-slate-400 font-semibold ml-1"><?= $pct ?>%</span>
            </span>
          </div>
          <div class="h-2.5 rounded-full bg-slate-100 overflow-hidden">
            <div class="h-full rounded-full <?= $color ?>" style="width: <?= max(1.5, min(100, $pct)) ?>%"></div>
          </div>
        </div>
      <?php endforeach; ?>

      <div class="mt-5 pt-4 border-t border-slate-100">
        <p class="text-xs text-slate-400">Ventas atribuidas</p>
        <p class="text-2xl font-bold text-emerald-600"><?= e(money($r['monto'])) ?></p>
        <p class="text-xs text-slate-400 mt-1">
          Compras hechas dentro de los <?= MKT_ATRIBUCION_DIAS ?> días siguientes al envío.
        </p>
      </div>
    </div>
  </div>
</div>

<div class="grid lg:grid-cols-3 gap-5 mb-5 items-start">

  <!-- Últimas campañas -->
  <div class="card lg:col-span-2 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
      <h2 class="font-bold text-slate-800">Últimas campañas</h2>
      <a href="<?= e(url('modules/marketing/campanas.php')) ?>" class="text-sm font-semibold text-blue-600 hover:text-blue-700">Ver todas</a>
    </div>

    <?php if (!$ultimas): ?>
      <?= empty_state('Sin campañas enviadas', 'Cuando envíes la primera, sus resultados aparecerán aquí.', 'mail',
          can('campanas.crear') ? '<a href="' . e(url('modules/marketing/campanas.php?nueva=1')) . '" class="btn btn-primary">' . icon('plus', 'w-4 h-4') . ' Nueva campaña</a>' : '') ?>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="data-table">
          <thead><tr><th>Campaña</th><th class="text-center">Enviados</th><th class="text-center">Aperturas</th><th class="text-center">Clics</th><th>Fecha</th></tr></thead>
          <tbody>
            <?php foreach ($ultimas as $u):
              $en = (int) $u['enviados'];
              $ta = $en > 0 ? round((int) $u['aperturas'] * 100 / $en) : 0;
              $tc = $en > 0 ? round((int) $u['clics'] * 100 / $en) : 0; ?>
              <tr>
                <td>
                  <a href="<?= e(url('modules/marketing/campana.php?id=' . (int) $u['id'])) ?>" class="font-semibold text-slate-700 hover:text-blue-600">
                    <?= e($u['nombre']) ?>
                  </a>
                  <p class="text-xs text-slate-400"><?= e($u['segmento_nombre'] ?? 'Todos los contactables') ?></p>
                </td>
                <td class="text-center font-semibold text-slate-700"><?= number_format($en) ?></td>
                <td class="text-center">
                  <span class="font-semibold text-slate-700"><?= $ta ?>%</span>
                  <span class="block text-[11px] text-slate-400"><?= (int) $u['aperturas'] ?></span>
                </td>
                <td class="text-center">
                  <span class="font-semibold text-slate-700"><?= $tc ?>%</span>
                  <span class="block text-[11px] text-slate-400"><?= (int) $u['clics'] ?></span>
                </td>
                <td class="text-sm text-slate-500 whitespace-nowrap"><?= e(fechaCorta($u['enviada_at'] ?: $u['created_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Automatizaciones -->
  <div class="card h-full flex flex-col">
    <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
      <h2 class="font-bold text-slate-800">Automatizaciones</h2>
      <?php if (can('marketing.automatizar')): ?>
        <a href="<?= e(url('modules/marketing/automatizaciones.php')) ?>" class="text-sm font-semibold text-blue-600 hover:text-blue-700">Configurar</a>
      <?php endif; ?>
    </div>
    <div class="p-5 flex-1 space-y-3">
      <?php foreach ($automatizaciones as $a):
        $d = $disparadores[$a['disparador']] ?? ['icono' => 'pulse']; ?>
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 <?= $a['activo'] ? 'bg-emerald-100 text-emerald-600' : 'bg-slate-100 text-slate-400' ?>">
            <?= icon($d['icono'], 'w-4 h-4') ?>
          </div>
          <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold text-slate-700 truncate"><?= e($a['nombre']) ?></p>
            <p class="text-xs text-slate-400"><?= number_format((int) $a['enviados']) ?> enviados</p>
          </div>
          <?= $a['activo'] ? badge('On', 'emerald') : badge('Off', 'slate') ?>
        </div>
      <?php endforeach; ?>
      <?php if (!$automatizaciones): ?>
        <p class="text-sm text-slate-400">No hay automatizaciones configuradas.</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="grid lg:grid-cols-2 gap-5">

  <!-- Clientes calientes -->
  <div class="card overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100">
      <h2 class="font-bold text-slate-800">Hicieron clic y no han comprado</h2>
      <p class="text-xs text-slate-400">Interés demostrado en los últimos 30 días. Estas son las llamadas que valen.</p>
    </div>
    <?php if (!$calientes): ?>
      <?= empty_state('Nadie pendiente', 'Cuando alguien haga clic en una campaña y no compre, aparecerá aquí.', 'target') ?>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="data-table">
          <thead><tr><th>Cliente</th><th>Campaña</th><th>Clic</th><th class="text-right">Contactar</th></tr></thead>
          <tbody>
            <?php foreach ($calientes as $cl):
              $tel = mkt_telefono($cl['telefono']);
              $msg = 'Hola ' . $cl['nombre'] . ', le escribo de ' . ($GLOBALS['empresa']['nombre'] ?? APP_NAME)
                   . '. Vi que le interesó nuestra promoción, ¿le doy más detalles?'; ?>
              <tr>
                <td class="font-medium text-slate-700"><?= e($cl['nombre']) ?></td>
                <td class="text-sm text-slate-500 truncate max-w-[160px]"><?= e($cl['campana']) ?></td>
                <td class="text-sm text-slate-400 whitespace-nowrap"><?= e(tiempoRelativo($cl['clic_at'])) ?></td>
                <td>
                  <div class="flex items-center justify-end gap-1">
                    <?php if ($tel !== ''): ?>
                      <a href="<?= e(mkt_wa_link($tel, $msg)) ?>" target="_blank" rel="noopener"
                         class="p-2 rounded-lg text-slate-400 hover:text-emerald-600 hover:bg-emerald-50" title="Escribir por WhatsApp">
                        <?= icon('phone', 'w-4 h-4') ?>
                      </a>
                    <?php endif; ?>
                    <?php if (can('crm.ver')): ?>
                      <a href="<?= e(url('modules/crm/cliente.php?id=' . (int) $cl['id'])) ?>"
                         class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Ficha del cliente">
                        <?= icon('user', 'w-4 h-4') ?>
                      </a>
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

  <!-- Cumpleaños -->
  <div class="card overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100">
      <h2 class="font-bold text-slate-800">Cumpleaños de <?= e(mesNombre((int) date('n'))) ?></h2>
      <p class="text-xs text-slate-400">La excusa más barata para que un cliente vuelva</p>
    </div>
    <?php if (!$cumples): ?>
      <?= empty_state('Sin cumpleaños registrados',
          'Añade la fecha de nacimiento en la ficha de tus clientes para usar la felicitación automática.', 'calendar') ?>
    <?php else: ?>
      <div class="divide-y divide-slate-100">
        <?php foreach ($cumples as $cu):
          $tel = mkt_telefono($cu['telefono']);
          $dia = (int) date('j', strtotime($cu['fecha_nacimiento']));
          $hoy = $dia === (int) date('j');
          $msg = '¡Feliz cumpleaños, ' . $cu['nombre'] . '! 🎉 De parte de todo el equipo de '
               . ($GLOBALS['empresa']['nombre'] ?? APP_NAME) . '.'; ?>
          <div class="px-5 py-3 flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl flex flex-col items-center justify-center shrink-0 <?= $hoy ? 'bg-pink-100 text-pink-600' : 'bg-slate-100 text-slate-500' ?>">
              <span class="text-sm font-bold leading-none"><?= $dia ?></span>
            </div>
            <div class="min-w-0 flex-1">
              <p class="font-medium text-slate-700 truncate"><?= e($cu['nombre']) ?></p>
              <p class="text-xs text-slate-400"><?= $hoy ? '¡Hoy!' : 'Día ' . $dia ?></p>
            </div>
            <?php if ($tel !== ''): ?>
              <a href="<?= e(mkt_wa_link($tel, $msg)) ?>" target="_blank" rel="noopener"
                 class="p-2 rounded-lg text-slate-400 hover:text-emerald-600 hover:bg-emerald-50" title="Felicitar por WhatsApp">
                <?= icon('phone', 'w-4 h-4') ?>
              </a>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Accesos rápidos -->
<div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 mt-5">
  <?php
  $atajos = [
      ['Campañas', 'Crear, enviar y medir', 'mail', 'blue', url('modules/marketing/campanas.php'), 'campanas.ver'],
      ['Segmentos', 'A quién le hablas', 'users', 'emerald', url('modules/marketing/segmentos.php'), 'marketing.segmentos'],
      ['Plantillas', 'Textos reutilizables', 'file', 'indigo', url('modules/marketing/plantillas.php'), 'marketing.plantillas'],
      ['Promociones', 'Descuentos del POS y la tienda', 'percent', 'rose', url('modules/marketing/promociones.php'), 'promociones.ver'],
  ];
  foreach ($atajos as [$t, $s, $ic, $col, $u, $perm]):
    if (!can($perm)) continue; ?>
    <a href="<?= e($u) ?>" class="card p-5 hover:shadow-md transition group">
      <div class="w-10 h-10 rounded-xl bg-<?= $col ?>-50 text-<?= $col ?>-600 flex items-center justify-center mb-3 group-hover:scale-105 transition">
        <?= icon($ic, 'w-5 h-5') ?>
      </div>
      <p class="font-bold text-slate-800"><?= e($t) ?></p>
      <p class="text-xs text-slate-400 mt-0.5"><?= e($s) ?></p>
    </a>
  <?php endforeach; ?>
</div>

<?php layout_end(); ?>
