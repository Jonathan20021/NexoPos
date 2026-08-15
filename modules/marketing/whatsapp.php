<?php
/**
 * Consola de envío por WhatsApp.
 *
 * Por qué esto no es un botón de «enviar todo»: wa.me es un enlace, no una API.
 * Abre la conversación con el mensaje ya escrito, pero el envío lo confirma una
 * persona desde su WhatsApp. Automatizarlo de verdad exigiría la API oficial de
 * WhatsApp Business (con plantillas aprobadas por Meta y costo por mensaje).
 *
 * Lo que sí se automatiza aquí es todo lo demás: a quién escribirle, el número
 * en formato internacional, el mensaje personalizado con su nombre y su promoción,
 * el enlace con rastreo y el orden de la cola. El operador solo pulsa «abrir» y
 * «enviar»: unos tres segundos por cliente en vez de dos minutos.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('campanas.whatsapp');

if (!mkt_disponible()) {
    layout_start('Envíos por WhatsApp', 'Falta aplicar la migración');
    echo '<div class="card p-8 text-center">' . icon('alert', 'w-10 h-10 text-amber-500 mx-auto mb-3')
       . '<h3 class="font-bold text-slate-700">Módulo no disponible todavía</h3>'
       . '<p class="text-sm text-slate-500 mt-1">Aplica <code class="bg-slate-100 px-1.5 py-0.5 rounded">database/migracion_marketing_p9.sql</code>.</p></div>';
    layout_end();
    exit;
}

/* ============================================================
 *  Marcar un envío (AJAX)
 * ============================================================ */
if (isPost() && post('accion') === 'marcar') {
    verify_csrf();
    header('Content-Type: application/json; charset=utf-8');
    $envioId = postInt('envio_id');
    $nuevo   = post('estado');
    if (!in_array($nuevo, ['enviado', 'omitido', 'pendiente'], true)) {
        echo json_encode(['ok' => false, 'error' => 'Estado inválido.']);
        exit;
    }
    $env = qOne("SELECT * FROM campana_envios WHERE id = ? AND canal = 'whatsapp'", [$envioId]);
    if (!$env) {
        echo json_encode(['ok' => false, 'error' => 'Envío no encontrado.']);
        exit;
    }
    dbUpdate('campana_envios', [
        'estado'     => $nuevo,
        'enviado_at' => $nuevo === 'enviado' ? date('Y-m-d H:i:s') : null,
        'error'      => $nuevo === 'omitido' ? 'Omitido por el operador' : null,
    ], 'id = ?', [$envioId]);
    mkt_recalcular((int) $env['campana_id']);

    $pend = (int) qVal("SELECT COUNT(*) FROM campana_envios WHERE campana_id = ? AND canal = 'whatsapp' AND estado = 'pendiente'",
                       [(int) $env['campana_id']]);
    echo json_encode(['ok' => true, 'pendientes' => $pend]);
    exit;
}

/* ============================================================
 *  Campaña activa
 * ============================================================ */
$conCola = qAll(
    "SELECT c.id, c.nombre, c.estado,
            SUM(e.estado = 'pendiente') pendientes,
            SUM(e.estado = 'enviado')   enviados,
            COUNT(*)                    total
       FROM campanas c
       JOIN campana_envios e ON e.campana_id = c.id AND e.canal = 'whatsapp'
      GROUP BY c.id, c.nombre, c.estado
      HAVING total > 0
      ORDER BY pendientes DESC, c.id DESC"
);

$campanaId = (int) get('campana');
if (!$campanaId && $conCola) $campanaId = (int) $conCola[0]['id'];

$c = $campanaId ? qOne("SELECT * FROM campanas WHERE id = ?", [$campanaId]) : null;

$acciones = '<a href="' . e(url('modules/marketing/campanas.php')) . '" class="btn btn-ghost">'
          . icon('arrow-left', 'w-4 h-4') . ' Campañas</a>';
layout_start('Envíos por WhatsApp', 'Cola preparada: un clic por cliente', $acciones);
?>

<div class="card p-4 mb-5 flex items-start gap-3 bg-emerald-50 border-emerald-100">
  <?= icon('phone', 'w-5 h-5 text-emerald-600 mt-0.5 shrink-0') ?>
  <div class="text-sm text-emerald-900">
    <p class="font-semibold">Cómo funciona</p>
    <p>
      Cada botón abre WhatsApp con el mensaje ya escrito y personalizado; tú solo pulsas «enviar» allí.
      Al abrirlo, la cola marca ese cliente como enviado y salta al siguiente.
      <strong>wa.me no permite envío automático</strong>: para eso hace falta la API oficial de WhatsApp Business.
    </p>
  </div>
</div>

<?php if (!$conCola): ?>
  <div class="card">
    <?= empty_state(
        'No hay nada en cola',
        'Crea una campaña con canal WhatsApp y pulsa «Preparar la cola» para que aparezca aquí.',
        'phone',
        can('campanas.crear') ? '<a href="' . e(url('modules/marketing/campanas.php')) . '" class="btn btn-primary">' . icon('plus', 'w-4 h-4') . ' Ir a campañas</a>' : ''
    ) ?>
  </div>
  <?php layout_end(); exit; ?>
<?php endif; ?>

<!-- Selector de campaña -->
<div class="card p-4 mb-5">
  <div class="flex items-center gap-3 flex-wrap">
    <span class="text-sm font-semibold text-slate-600">Campaña:</span>
    <?php foreach ($conCola as $cc): ?>
      <a href="<?= e(url('modules/marketing/whatsapp.php?campana=' . (int) $cc['id'])) ?>"
         class="text-sm font-semibold px-3.5 py-2 rounded-xl flex items-center gap-2 <?= $campanaId === (int) $cc['id'] ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
        <?= e($cc['nombre']) ?>
        <?php if ((int) $cc['pendientes'] > 0): ?>
          <span class="text-[11px] font-bold px-1.5 py-0.5 rounded-md <?= $campanaId === (int) $cc['id'] ? 'bg-white/25' : 'bg-emerald-100 text-emerald-700' ?>">
            <?= (int) $cc['pendientes'] ?>
          </span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<?php
if (!$c) { echo '<div class="card p-6 text-slate-500">Campaña no encontrada.</div>'; layout_end(); exit; }

/* ---------- Cola de esta campaña ---------- */
$filas = qAll(
    "SELECT e.id, e.destino, e.token, e.estado, e.enviado_at, e.clic_at,
            COALESCE(cl.nombre, e.nombre) AS nombre, cl.balance, cl.id AS cliente_id
       FROM campana_envios e
       LEFT JOIN clientes cl ON cl.id = e.cliente_id
      WHERE e.campana_id = ? AND e.canal = 'whatsapp'
      ORDER BY FIELD(e.estado, 'pendiente', 'omitido', 'enviado'), e.id
      LIMIT 500", [$campanaId]
);

// Esta pantalla es una lista de trabajo manual: el avance es lo único que
// importa mientras se recorre. Sin él hay que contar filas a ojo para saber
// cuánto falta.
$nPend = 0; $nEnv = 0; $nOmit = 0; $nClic = 0;
foreach ($filas as $f) {
    if ($f['estado'] === 'pendiente') $nPend++;
    elseif ($f['estado'] === 'omitido') $nOmit++;
    else $nEnv++;
    if ($f['clic_at']) $nClic++;
}
$totalCola = $nPend + $nEnv + $nOmit;

echo kpis([
    ['label' => 'Por mandar', 'valor' => number_format($nPend), 'icono' => 'phone',
     'color' => $nPend > 0 ? 'amber' : 'emerald',
     'nota' => $totalCola > 0
        ? 'De ' . number_format($totalCola) . ' en la cola'
        : 'La cola está vacía'],
    ['label' => 'Ya enviados', 'valor' => number_format($nEnv), 'icono' => 'check', 'color' => 'emerald',
     'nota' => $totalCola > 0 ? round($nEnv / $totalCola * 100) . '% de la cola' : ''],
    ['label' => 'Omitidos', 'valor' => number_format($nOmit), 'icono' => 'x',
     'color' => $nOmit > 0 ? 'slate' : 'slate', 'nota' => 'Saltados a propósito'],
    ['label' => 'Han hecho clic', 'valor' => number_format($nClic), 'icono' => 'target',
     'color' => $nClic > 0 ? 'violet' : 'slate',
     'nota' => $nEnv > 0 ? round($nClic / $nEnv * 100) . '% de los enviados' : 'Nada enviado todavía'],
], 4);

// Mensaje y enlace ya resueltos para cada destinatario: el navegador no calcula nada.
$cola = [];
foreach ($filas as $f) {
    $cliente = ['nombre' => $f['nombre'], 'balance' => (float) ($f['balance'] ?? 0)];
    $texto = mkt_texto_whatsapp($c, $cliente, ['token' => $f['token']]);
    $cola[] = [
        'id'      => (int) $f['id'],
        'nombre'  => $f['nombre'] ?: 'Cliente',
        'tel'     => mkt_telefono_bonito($f['destino']),
        'estado'  => $f['estado'],
        'enviado' => $f['enviado_at'] ? fechaHora($f['enviado_at']) : '',
        'texto'   => $texto,
        'link'    => mkt_wa_link($f['destino'], $texto),
        'ficha'   => $f['cliente_id'] ? url('modules/crm/cliente.php?id=' . (int) $f['cliente_id']) : '',
    ];
}

$pend = count(array_filter($cola, fn($x) => $x['estado'] === 'pendiente'));
$env  = count(array_filter($cola, fn($x) => $x['estado'] === 'enviado'));
?>

<div x-data="colaWa(<?= htmlspecialchars(json_encode($cola), ENT_QUOTES) ?>)" class="grid lg:grid-cols-3 gap-5 items-start">

  <!-- ---------- Panel de despacho ---------- -->
  <div class="lg:col-span-2 space-y-5">
    <div class="card overflow-hidden">
      <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between gap-3">
        <h2 class="font-bold text-slate-800"><?= e($c['nombre']) ?></h2>
        <span class="text-sm text-slate-400" x-text="`${pendientes()} pendiente(s)`"></span>
      </div>

      <template x-if="actual()">
        <div class="p-6">
          <div class="flex items-start justify-between gap-4 mb-5">
            <div class="min-w-0">
              <p class="text-xs uppercase tracking-wide text-slate-400 font-semibold mb-1">Siguiente en la cola</p>
              <h3 class="text-2xl font-bold text-slate-800 truncate" x-text="actual().nombre"></h3>
              <p class="text-sm text-slate-500 mt-0.5" x-text="actual().tel"></p>
            </div>
            <span class="text-sm text-slate-400 whitespace-nowrap tabular-nums" x-text="`${indice + 1} / ${items.length}`"></span>
          </div>

          <div class="rounded-2xl bg-emerald-50 border border-emerald-100 p-4 mb-5">
            <p class="text-sm text-emerald-900 whitespace-pre-wrap leading-relaxed" x-text="actual().texto"></p>
          </div>

          <div class="flex flex-wrap gap-2">
            <a :href="actual().link" target="_blank" rel="noopener"
               @click="marcar(actual().id, 'enviado', true)"
               class="btn btn-success flex-1 min-w-[220px] justify-center">
              <?= icon('phone', 'w-4 h-4') ?> Abrir WhatsApp y enviar
            </a>
            <button type="button" @click="copiar(actual().texto)" class="btn btn-soft">
              <?= icon('list', 'w-4 h-4') ?> <span x-text="copiado ? '¡Copiado!' : 'Copiar texto'"></span>
            </button>
            <button type="button" @click="marcar(actual().id, 'omitido', true)" class="btn btn-ghost">
              <?= icon('x', 'w-4 h-4') ?> Omitir
            </button>
            <template x-if="actual().ficha">
              <a :href="actual().ficha" target="_blank" class="btn btn-ghost"><?= icon('user', 'w-4 h-4') ?> Ficha</a>
            </template>
          </div>

          <p class="text-xs text-slate-400 mt-4">
            Atajos: <kbd class="px-1.5 py-0.5 rounded bg-slate-100 font-mono">Enter</kbd> abrir y enviar ·
            <kbd class="px-1.5 py-0.5 rounded bg-slate-100 font-mono">→</kbd> omitir.
          </p>
        </div>
      </template>

      <template x-if="!actual()">
        <div class="p-10 text-center">
          <div class="w-14 h-14 rounded-2xl bg-emerald-100 text-emerald-600 flex items-center justify-center mx-auto mb-4">
            <?= icon('check', 'w-7 h-7') ?>
          </div>
          <h3 class="font-bold text-slate-700">Cola terminada</h3>
          <p class="text-sm text-slate-500 mt-1">No queda nadie pendiente en esta campaña.</p>
        </div>
      </template>
    </div>

    <!-- ---------- Listado completo ---------- -->
    <div class="card overflow-hidden">
      <div class="px-5 py-4 border-b border-slate-100">
        <h2 class="font-bold text-slate-800">Toda la cola</h2>
      </div>
      <div class="overflow-x-auto max-h-[520px]">
        <table class="data-table">
          <thead><tr><th>Cliente</th><th>Teléfono</th><th class="text-center">Estado</th><th class="text-right">Acción</th></tr></thead>
          <tbody>
            <template x-for="(it, i) in items" :key="it.id">
              <tr :class="i === indice ? 'bg-emerald-50/60' : ''">
                <td class="font-medium text-slate-700" x-text="it.nombre"></td>
                <td class="text-sm text-slate-500" x-text="it.tel"></td>
                <td class="text-center">
                  <span class="badge" :class="{
                        'badge-slate': it.estado === 'pendiente',
                        'badge-emerald': it.estado === 'enviado',
                        'badge-amber': it.estado === 'omitido'}"
                        x-text="{pendiente:'En cola', enviado:'Enviado', omitido:'Omitido'}[it.estado]"></span>
                </td>
                <td class="text-right">
                  <div class="flex items-center justify-end gap-1">
                    <a :href="it.link" target="_blank" rel="noopener" @click="marcar(it.id, 'enviado', false)"
                       class="p-2 rounded-lg text-slate-400 hover:text-emerald-600 hover:bg-emerald-50" title="Abrir WhatsApp">
                      <?= icon('phone', 'w-4 h-4') ?>
                    </a>
                    <button type="button" x-show="it.estado !== 'pendiente'" @click="marcar(it.id, 'pendiente', false)"
                            class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Devolver a la cola">
                      <?= icon('history', 'w-4 h-4') ?>
                    </button>
                    <button type="button" x-show="it.estado === 'pendiente'" @click="marcar(it.id, 'omitido', false)"
                            class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50" title="Omitir">
                      <?= icon('x', 'w-4 h-4') ?>
                    </button>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ---------- Progreso ---------- -->
  <div class="space-y-5">
    <div class="card p-5">
      <h3 class="font-bold text-slate-800 mb-4">Progreso</h3>
      <div class="h-2 rounded-full bg-slate-100 overflow-hidden mb-4">
        <div class="h-full rounded-full bg-emerald-500 transition-all duration-500" :style="`width:${pct()}%`"></div>
      </div>
      <div class="space-y-3">
        <div class="flex items-center justify-between">
          <span class="text-sm text-slate-500">Enviados</span>
          <span class="font-semibold text-emerald-600" x-text="enviados()"></span>
        </div>
        <div class="flex items-center justify-between">
          <span class="text-sm text-slate-500">Pendientes</span>
          <span class="font-semibold text-slate-800" x-text="pendientes()"></span>
        </div>
        <div class="flex items-center justify-between">
          <span class="text-sm text-slate-500">Omitidos</span>
          <span class="font-semibold text-amber-600" x-text="omitidos()"></span>
        </div>
      </div>
      <p class="text-xs text-slate-400 mt-4">
        En WhatsApp Web con la sesión abierta, el ritmo real es de unos 15–20 clientes por minuto.
      </p>
    </div>

    <div class="card p-5">
      <h3 class="font-bold text-slate-800 mb-2">Antes de empezar</h3>
      <ul class="text-sm text-slate-500 space-y-2 list-disc pl-4">
        <li>Abre <a href="https://web.whatsapp.com" target="_blank" rel="noopener" class="text-blue-600 hover:underline">web.whatsapp.com</a> y deja la sesión iniciada: si no, cada clic te pedirá el código QR.</li>
        <li>Los números se envían en formato internacional (809/829/849 con el 1 delante). Un cliente sin número válido no entra en la cola.</li>
        <li>Manda tandas cortas y espaciadas. WhatsApp restringe cuentas que envían cientos de mensajes idénticos de golpe.</li>
      </ul>
    </div>

    <div class="card p-5">
      <h3 class="font-bold text-slate-800 mb-2">Rastreo</h3>
      <p class="text-sm text-slate-500">
        El enlace del mensaje pasa por el rastreador del sistema, así que los clics de WhatsApp
        también cuentan en los resultados de la campaña.
      </p>
      <a href="<?= e(url('modules/marketing/campana.php?id=' . $campanaId)) ?>" class="btn btn-soft w-full mt-3">
        <?= icon('chart', 'w-4 h-4') ?> Ver resultados
      </a>
    </div>
  </div>
</div>

<script>
  function colaWa(items) {
    return {
      items,
      indice: 0,
      copiado: false,
      token: '<?= e(csrf_token()) ?>',

      init() {
        this.saltarAlPendiente();
        window.addEventListener('keydown', (ev) => {
          if (['INPUT', 'TEXTAREA', 'SELECT'].includes(ev.target.tagName)) return;
          const it = this.actual();
          if (!it) return;
          if (ev.key === 'Enter')      { ev.preventDefault(); window.open(it.link, '_blank', 'noopener'); this.marcar(it.id, 'enviado', true); }
          if (ev.key === 'ArrowRight') { ev.preventDefault(); this.marcar(it.id, 'omitido', true); }
        });
      },

      actual()     { return this.items[this.indice] || null; },
      pendientes() { return this.items.filter(i => i.estado === 'pendiente').length; },
      enviados()   { return this.items.filter(i => i.estado === 'enviado').length; },
      omitidos()   { return this.items.filter(i => i.estado === 'omitido').length; },
      pct()        { return this.items.length ? Math.round((this.enviados() + this.omitidos()) * 100 / this.items.length) : 0; },

      saltarAlPendiente() {
        const i = this.items.findIndex(x => x.estado === 'pendiente');
        this.indice = i >= 0 ? i : this.items.length;
      },

      async copiar(texto) {
        try { await navigator.clipboard.writeText(texto); } catch (e) { /* sin portapapeles: no pasa nada */ }
        this.copiado = true;
        setTimeout(() => this.copiado = false, 1500);
      },

      async marcar(id, estado, avanzar) {
        const it = this.items.find(x => x.id === id);
        if (it) it.estado = estado;          // respuesta inmediata; el servidor confirma detrás
        if (avanzar) this.saltarAlPendiente();

        const cuerpo = new FormData();
        cuerpo.append('accion', 'marcar');
        cuerpo.append('_csrf', this.token);
        cuerpo.append('envio_id', id);
        cuerpo.append('estado', estado);
        try {
          await fetch(window.location.pathname + '?campana=<?= $campanaId ?>', { method: 'POST', body: cuerpo });
        } catch (e) { /* se reintenta al recargar la página */ }
      }
    };
  }
</script>

<?php layout_end(); ?>
