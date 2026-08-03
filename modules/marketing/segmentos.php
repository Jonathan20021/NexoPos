<?php
/**
 * Segmentos de clientes: a quién le hablas.
 *
 * Un segmento es un conjunto de reglas guardadas (recencia, frecuencia, monto,
 * deuda, cumpleaños, sucursal, categoría), no una lista congelada de personas.
 * Se evalúa en el momento del envío, así que un cliente que ayer no calificaba
 * y hoy sí, entra solo.
 *
 * El conteo de cada segmento se pide por AJAX: son consultas agregadas sobre el
 * histórico de ventas y calcular ocho de golpe al abrir la página la haría lenta
 * con datos reales.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('marketing.segmentos');

if (!mkt_disponible()) {
    layout_start('Segmentos de clientes', 'Falta aplicar la migración');
    echo '<div class="card p-8 text-center">'
       . icon('alert', 'w-10 h-10 text-amber-500 mx-auto mb-3')
       . '<h3 class="font-bold text-slate-700">Módulo no disponible todavía</h3>'
       . '<p class="text-sm text-slate-500 mt-1">Aplica <code class="bg-slate-100 px-1.5 py-0.5 rounded">database/migracion_marketing_p9.sql</code> para activar el módulo de marketing.</p>'
       . '</div>';
    layout_end();
    exit;
}

/* ---------- API: conteo en vivo de un segmento ---------- */
if (get('api') === 'conteo') {
    header('Content-Type: application/json; charset=utf-8');
    $canal = get('canal') === 'whatsapp' ? 'whatsapp' : 'email';
    $seg   = mkt_segmento((int) get('id'));
    try {
        echo json_encode(['ok' => true, 'n' => mkt_conteo($seg, $canal)]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'n' => 0, 'error' => 'No se pudo calcular.']);
    }
    exit;
}

$tiposCliente = ['cualquiera' => 'Cualquiera', 'contado' => 'Solo de contado', 'credito' => 'Solo a crédito'];
$deudas       = ['cualquiera' => 'No importa', 'con' => 'Con saldo pendiente', 'sin' => 'Sin deuda'];

$meses = [0 => 'No filtrar por cumpleaños', 13 => 'Cumplen este mes (se mueve solo)'];
for ($m = 1; $m <= 12; $m++) $meses[$m] = 'Cumplen en ' . mesNombre($m);

/* ============================================================
 *  Acciones (POST · PRG)
 * ============================================================ */
if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    /** Entero opcional: la cadena vacía es NULL, no cero. */
    $nInt = function (string $k): ?int {
        $v = trim((string) post($k));
        return $v === '' ? null : max(0, (int) $v);
    };
    $nNum = function (string $k): ?float {
        $v = trim((string) post($k));
        return $v === '' ? null : max(0, (float) str_replace(',', '', $v));
    };

    if ($accion === 'guardar') {
        $id     = postInt('id');
        $nombre = trim(post('nombre'));
        try {
            if ($nombre === '') throw new RuntimeException('El nombre del segmento es obligatorio.');

            $minD = $nInt('dias_sin_comprar_min');
            $maxD = $nInt('dias_sin_comprar_max');
            if ($minD !== null && $maxD !== null && $maxD < $minD) {
                throw new RuntimeException('«Compró en los últimos N días» no puede ser menor que «sin comprar hace N días»: ningún cliente cumpliría las dos.');
            }
            $gMin = $nNum('gasto_min');
            $gMax = $nNum('gasto_max');
            if ($gMin !== null && $gMax !== null && $gMax < $gMin) {
                throw new RuntimeException('El gasto máximo no puede ser menor que el mínimo.');
            }

            $datos = [
                'nombre'      => $nombre,
                'descripcion' => mb_substr(trim(post('descripcion')), 0, 255) ?: null,
                'requiere_email'    => postInt('requiere_email'),
                'requiere_telefono' => postInt('requiere_telefono'),
                'tipo_cliente' => array_key_exists(post('tipo_cliente'), $tiposCliente) ? post('tipo_cliente') : 'cualquiera',
                'deuda'        => array_key_exists(post('deuda'), $deudas) ? post('deuda') : 'cualquiera',
                'sucursal_id'  => $nInt('sucursal_id') ?: null,
                'categoria_id' => $nInt('categoria_id') ?: null,
                'dias_sin_comprar_min' => $minD,
                'dias_sin_comprar_max' => $maxD,
                'incluir_sin_compras'  => postInt('incluir_sin_compras'),
                'compras_min' => $nInt('compras_min'),
                'gasto_min'   => $gMin,
                'gasto_max'   => $gMax,
                'cumple_mes'  => array_key_exists(postInt('cumple_mes'), $meses) ? postInt('cumple_mes') : 0,
                'activo'      => postInt('activo', 1),
            ];

            if ($id > 0) {
                if (!qVal("SELECT 1 FROM marketing_segmentos WHERE id = ?", [$id])) throw new RuntimeException('Segmento no encontrado.');
                dbUpdate('marketing_segmentos', $datos, 'id = ?', [$id]);
                audit('marketing', 'editar', "Segmento actualizado: $nombre", ['tabla' => 'marketing_segmentos', 'registro_id' => $id]);
                flash('success', 'Segmento actualizado.');
            } else {
                $datos['created_by'] = (int) current_user()['id'];
                $nid = dbInsert('marketing_segmentos', $datos);
                audit('marketing', 'crear', "Segmento creado: $nombre", ['tabla' => 'marketing_segmentos', 'registro_id' => $nid]);
                flash('success', 'Segmento creado. Úsalo en tu próxima campaña.');
            }
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/marketing/segmentos.php');
    }

    if ($accion === 'duplicar') {
        $id = postInt('id');
        $s = qOne("SELECT * FROM marketing_segmentos WHERE id = ?", [$id]);
        if ($s) {
            unset($s['id'], $s['created_at']);
            $s['nombre'] = mb_substr('Copia de ' . $s['nombre'], 0, 120);
            $s['created_by'] = (int) current_user()['id'];
            $nid = dbInsert('marketing_segmentos', $s);
            audit('marketing', 'crear', "Segmento duplicado: {$s['nombre']}", ['tabla' => 'marketing_segmentos', 'registro_id' => $nid]);
            flash('success', 'Segmento duplicado.');
        }
        redirect('modules/marketing/segmentos.php');
    }

    if ($accion === 'eliminar') {
        $id = postInt('id');
        $nombre = qVal("SELECT nombre FROM marketing_segmentos WHERE id = ?", [$id]);
        if ($nombre !== null) {
            $usos = (int) qVal("SELECT COUNT(*) FROM campanas WHERE segmento_id = ?", [$id]);
            if ($usos > 0) {
                flash('error', "No se puede eliminar: $usos campaña(s) lo están usando. Desactívalo si ya no lo necesitas.");
            } else {
                q("DELETE FROM marketing_segmentos WHERE id = ?", [$id]);
                audit('marketing', 'eliminar', "Segmento eliminado: $nombre", ['tabla' => 'marketing_segmentos', 'registro_id' => $id]);
                flash('success', 'Segmento eliminado.');
            }
        }
        redirect('modules/marketing/segmentos.php');
    }
}

/* ============================================================
 *  Listado
 * ============================================================ */
$q = trim(get('q'));
$where = ''; $params = [];
if ($q !== '') { $where = "WHERE nombre LIKE ? OR descripcion LIKE ?"; $params = ['%' . $q . '%', '%' . $q . '%']; }
$segmentos = qAll("SELECT * FROM marketing_segmentos $where ORDER BY activo DESC, nombre", $params);

$sucursales = qAll("SELECT id, nombre FROM sucursales WHERE activo = 1 ORDER BY nombre");
$categorias = qAll("SELECT id, nombre FROM categorias ORDER BY nombre");

$defaults = mkt_segmento_defaults();

$acciones = btn_nuevo('seg:new', 'Nuevo segmento');
layout_start('Segmentos de clientes', 'Grupos que se recalculan solos cada vez que envías', $acciones);
?>

<div class="card p-4 mb-5 flex items-start gap-3 bg-sky-50 border-sky-100">
  <?= icon('users', 'w-5 h-5 text-sky-500 mt-0.5 shrink-0') ?>
  <p class="text-sm text-sky-800">
    Un segmento guarda <strong>reglas</strong>, no personas. Se evalúa en el momento del envío:
    si un cliente deja de comprar 90 días, entra solo al segmento de dormidos sin que nadie lo mueva.
  </p>
</div>

<div class="card overflow-hidden mb-5">
  <div class="p-4 flex items-center justify-between gap-3 flex-wrap">
    <?= search_box('Buscar segmento...') ?>
    <span class="text-sm text-slate-400"><?= count($segmentos) ?> segmento(s)</span>
  </div>
</div>

<?php if (!$segmentos): ?>
  <div class="card">
    <?= empty_state('Sin segmentos', 'Crea tu primer segmento para dirigir las campañas a quien te interesa.', 'users',
        btn_nuevo('seg:new', 'Nuevo segmento')) ?>
  </div>
<?php else: ?>
  <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3" x-data="segmentosConteo()">
    <?php foreach ($segmentos as $s): ?>
      <div class="card p-5 flex flex-col">
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0">
            <h3 class="font-bold text-slate-800 truncate"><?= e($s['nombre']) ?></h3>
            <?php if ($s['descripcion']): ?>
              <p class="text-xs text-slate-400 mt-0.5 line-clamp-2"><?= e($s['descripcion']) ?></p>
            <?php endif; ?>
          </div>
          <?= $s['activo'] ? badge('Activo', 'emerald') : badge('Inactivo', 'slate') ?>
        </div>

        <div class="flex flex-wrap gap-1.5 my-4 flex-1 content-start">
          <?php foreach (mkt_segmento_reglas($s) as $r): ?>
            <span class="text-[11px] font-medium px-2 py-1 rounded-lg bg-slate-100 text-slate-600"><?= e($r) ?></span>
          <?php endforeach; ?>
        </div>

        <div class="grid grid-cols-2 gap-3 py-3 border-y border-slate-100">
          <div>
            <p class="text-[11px] uppercase tracking-wide text-slate-400 font-semibold">Por correo</p>
            <p class="text-2xl font-bold text-slate-800" x-text="fmt(conteos['e<?= (int) $s['id'] ?>'])">…</p>
          </div>
          <div>
            <p class="text-[11px] uppercase tracking-wide text-slate-400 font-semibold">Por WhatsApp</p>
            <p class="text-2xl font-bold text-slate-800" x-text="fmt(conteos['w<?= (int) $s['id'] ?>'])">…</p>
          </div>
        </div>

        <div class="flex items-center justify-between gap-1 pt-3">
          <button type="button" @click="cargar(<?= (int) $s['id'] ?>)"
                  class="text-xs font-semibold text-blue-600 hover:text-blue-700 flex items-center gap-1">
            <?= icon('history', 'w-3.5 h-3.5') ?> Recalcular
          </button>
          <div class="flex items-center gap-1">
            <a href="<?= e(url('modules/marketing/campanas.php?nueva=1&segmento=' . (int) $s['id'])) ?>"
               class="p-2 rounded-lg text-slate-400 hover:text-emerald-600 hover:bg-emerald-50" title="Crear campaña para este segmento">
              <?= icon('mail', 'w-4 h-4') ?>
            </a>
            <button onclick="<?= jsEvent('seg:edit', [
                'id' => (int) $s['id'], 'nombre' => $s['nombre'], 'descripcion' => (string) $s['descripcion'],
                'requiere_email' => (int) $s['requiere_email'], 'requiere_telefono' => (int) $s['requiere_telefono'],
                'tipo_cliente' => $s['tipo_cliente'], 'deuda' => $s['deuda'],
                'sucursal_id' => (string) $s['sucursal_id'], 'categoria_id' => (string) $s['categoria_id'],
                'dias_sin_comprar_min' => (string) $s['dias_sin_comprar_min'],
                'dias_sin_comprar_max' => (string) $s['dias_sin_comprar_max'],
                'incluir_sin_compras' => (int) $s['incluir_sin_compras'],
                'compras_min' => (string) $s['compras_min'],
                'gasto_min' => (string) $s['gasto_min'], 'gasto_max' => (string) $s['gasto_max'],
                'cumple_mes' => (int) $s['cumple_mes'], 'activo' => (int) $s['activo'],
            ]) ?>" class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Editar">
              <?= icon('edit', 'w-4 h-4') ?>
            </button>
            <form method="post" class="inline">
              <?= csrf_field() ?><input type="hidden" name="accion" value="duplicar"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <button class="p-2 rounded-lg text-slate-400 hover:text-indigo-600 hover:bg-indigo-50" title="Duplicar"><?= icon('layers', 'w-4 h-4') ?></button>
            </form>
            <form method="post" class="inline" onsubmit="return confirm('¿Eliminar el segmento «<?= e($s['nombre']) ?>»?')">
              <?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <button class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50" title="Eliminar"><?= icon('trash', 'w-4 h-4') ?></button>
            </form>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <script>
    function segmentosConteo() {
      return {
        conteos: {},
        ids: <?= json_encode(array_map(fn($s) => (int) $s['id'], $segmentos)) ?>,
        init() { this.ids.forEach(id => this.cargar(id)); },
        fmt(v) { return v === undefined ? '…' : (v === null ? '—' : new Intl.NumberFormat('es-DO').format(v)); },
        async cargar(id) {
          for (const [canal, pre] of [['email', 'e'], ['whatsapp', 'w']]) {
            this.conteos[pre + id] = undefined;
            try {
              const r = await fetch(`?api=conteo&id=${id}&canal=${canal}`, { headers: { 'X-Requested-With': 'fetch' } });
              const j = await r.json();
              this.conteos[pre + id] = j.ok ? j.n : null;
            } catch (e) { this.conteos[pre + id] = null; }
          }
        }
      };
    }
  </script>
<?php endif; ?>

<!-- Modal crear/editar -->
<?php $vacio = [
  'id' => 0, 'nombre' => '', 'descripcion' => '',
  'requiere_email' => 1, 'requiere_telefono' => 0,
  'tipo_cliente' => 'cualquiera', 'deuda' => 'cualquiera',
  'sucursal_id' => '', 'categoria_id' => '',
  'dias_sin_comprar_min' => '', 'dias_sin_comprar_max' => '', 'incluir_sin_compras' => 1,
  'compras_min' => '', 'gasto_min' => '', 'gasto_max' => '', 'cumple_mes' => 0, 'activo' => 1,
]; ?>
<div x-data="{open:false, f:<?= htmlspecialchars(json_encode($vacio), ENT_QUOTES) ?>, vacio:<?= htmlspecialchars(json_encode($vacio), ENT_QUOTES) ?>}"
     @seg:new.window="f=JSON.parse(JSON.stringify(vacio)); open=true"
     @seg:edit.window="f=$event.detail; open=true"
     @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-2xl" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="id" :value="f.id">

        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800" x-text="f.id ? 'Editar segmento' : 'Nuevo segmento'"></h3>
          <button type="button" @click="open=false" aria-label="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
        </div>

        <div class="p-6 space-y-6 max-h-[72vh] overflow-y-auto">
          <div class="grid md:grid-cols-2 gap-4">
            <div>
              <label class="label">Nombre *</label>
              <input type="text" name="nombre" x-model="f.nombre" required class="input" placeholder="Ej. Clientes VIP de Santiago">
            </div>
            <div>
              <label class="label">Descripción</label>
              <input type="text" name="descripcion" x-model="f.descripcion" class="input" placeholder="Para qué sirve este grupo">
            </div>
          </div>

          <!-- Contactabilidad -->
          <div>
            <p class="text-xs font-bold uppercase tracking-wide text-slate-400 mb-3">Cómo se le puede escribir</p>
            <div class="grid sm:grid-cols-2 gap-3">
              <label class="flex items-center gap-2.5 p-3 rounded-xl border border-slate-200 cursor-pointer hover:bg-slate-50">
                <input type="hidden" name="requiere_email" value="0">
                <input type="checkbox" name="requiere_email" value="1" :checked="f.requiere_email==1"
                       class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                <span class="text-sm text-slate-600">Debe tener correo válido</span>
              </label>
              <label class="flex items-center gap-2.5 p-3 rounded-xl border border-slate-200 cursor-pointer hover:bg-slate-50">
                <input type="hidden" name="requiere_telefono" value="0">
                <input type="checkbox" name="requiere_telefono" value="1" :checked="f.requiere_telefono==1"
                       class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                <span class="text-sm text-slate-600">Debe tener teléfono (WhatsApp)</span>
              </label>
            </div>
            <p class="text-xs text-slate-400 mt-2">
              Al enviar, el canal manda: una campaña por correo exige correo aunque aquí no lo marques.
            </p>
          </div>

          <!-- Estado comercial -->
          <div>
            <p class="text-xs font-bold uppercase tracking-wide text-slate-400 mb-3">Estado comercial</p>
            <div class="grid sm:grid-cols-2 gap-4">
              <div>
                <label class="label">Tipo de cliente</label>
                <select name="tipo_cliente" x-model="f.tipo_cliente" class="select">
                  <?php foreach ($tiposCliente as $v => $l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="label">Saldo pendiente</label>
                <select name="deuda" x-model="f.deuda" class="select">
                  <?php foreach ($deudas as $v => $l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="label">Compró alguna vez en</label>
                <select name="sucursal_id" x-model="f.sucursal_id" class="select">
                  <option value="">Cualquier sucursal</option>
                  <?php foreach ($sucursales as $su): ?><option value="<?= (int) $su['id'] ?>"><?= e($su['nombre']) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="label">Compró productos de</label>
                <select name="categoria_id" x-model="f.categoria_id" class="select">
                  <option value="">Cualquier categoría</option>
                  <?php foreach ($categorias as $ca): ?><option value="<?= (int) $ca['id'] ?>"><?= e($ca['nombre']) ?></option><?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>

          <!-- Comportamiento de compra -->
          <div>
            <p class="text-xs font-bold uppercase tracking-wide text-slate-400 mb-1">Comportamiento de compra</p>
            <p class="text-xs text-slate-400 mb-3">Deja en blanco lo que no quieras filtrar.</p>
            <div class="grid sm:grid-cols-2 gap-4">
              <div>
                <label class="label">Sin comprar hace al menos</label>
                <div class="flex items-center gap-2">
                  <input type="number" min="0" name="dias_sin_comprar_min" x-model="f.dias_sin_comprar_min" class="input" placeholder="90">
                  <span class="text-sm text-slate-400 whitespace-nowrap">días</span>
                </div>
                <p class="text-xs text-slate-400 mt-1">Clientes dormidos: los que más venta recuperan.</p>
              </div>
              <div>
                <label class="label">Compró en los últimos</label>
                <div class="flex items-center gap-2">
                  <input type="number" min="0" name="dias_sin_comprar_max" x-model="f.dias_sin_comprar_max" class="input" placeholder="30">
                  <span class="text-sm text-slate-400 whitespace-nowrap">días</span>
                </div>
                <p class="text-xs text-slate-400 mt-1">Clientes calientes: ideales para venta cruzada.</p>
              </div>
              <div>
                <label class="label">Número de compras (mínimo)</label>
                <input type="number" min="0" name="compras_min" x-model="f.compras_min" class="input" placeholder="3">
              </div>
              <div>
                <label class="label">Cumpleaños</label>
                <select name="cumple_mes" x-model.number="f.cumple_mes" class="select">
                  <?php foreach ($meses as $v => $l): ?><option value="<?= (int) $v ?>"><?= e($l) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="label">Ha gastado desde (RD$)</label>
                <input type="number" step="0.01" min="0" name="gasto_min" x-model="f.gasto_min" class="input" placeholder="10000">
              </div>
              <div>
                <label class="label">Ha gastado hasta (RD$)</label>
                <input type="number" step="0.01" min="0" name="gasto_max" x-model="f.gasto_max" class="input" placeholder="">
              </div>
            </div>
            <p class="text-xs text-slate-400 mt-2">El gasto es el total facturado con ITBIS: lo que el cliente realmente pagó.</p>

            <label class="flex items-center gap-2.5 mt-4 p-3 rounded-xl border border-slate-200 cursor-pointer hover:bg-slate-50">
              <input type="hidden" name="incluir_sin_compras" value="0">
              <input type="checkbox" name="incluir_sin_compras" value="1" :checked="f.incluir_sin_compras==1"
                     class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
              <span class="text-sm text-slate-600">Incluir también a clientes que nunca han comprado</span>
            </label>
          </div>

          <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="hidden" name="activo" value="0">
            <input type="checkbox" name="activo" value="1" :checked="f.activo==1" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
            Segmento activo
          </label>
        </div>

        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button type="submit" class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar segmento</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php layout_end(); ?>
