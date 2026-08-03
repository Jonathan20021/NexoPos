<?php
/**
 * Diseño del correo: colores, logo y pie.
 *
 * Es la última cosa que estaba escrita a fuego en el código. Un negocio con
 * marca azul no tenía forma de quitar el verde sin tocar PHP, que es justo lo
 * que este módulo trata de evitar.
 *
 * Afecta a TODOS los correos del sistema, no solo a las campañas: también a los
 * de pedidos de la tienda. Se avisa en pantalla, porque no es obvio.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('marketing.diseno');

$emp = $GLOBALS['empresa'] ?? [];

// La migración P10 puede no estar aplicada todavía.
$disponible = array_key_exists('mkt_color', $emp);

/* ---------- Vista previa en vivo (JSON) ---------- */
if (isPost() && post('accion') === 'api_preview') {
    verify_csrf();
    header('Content-Type: application/json; charset=utf-8');

    // Se dibuja con los colores del formulario, aunque no estén guardados.
    mail_diseno([
        'color'        => post('mkt_color'),
        'color_boton'  => post('mkt_color_boton'),
        'fondo'        => post('mkt_fondo'),
        'mostrar_logo' => postInt('mkt_mostrar_logo'),
        'pie'          => post('mkt_pie'),
    ]);

    $muestra = mkt_cliente_muestra();
    $promo   = qOne("SELECT * FROM promociones WHERE activo = 1 AND fecha_fin >= CURDATE() ORDER BY fecha_fin DESC LIMIT 1");

    $ejemplo = mkt_campana_previa([
        'asunto'    => '{{cliente}}, aprovecha {{descuento}} por tiempo limitado',
        'preheader' => 'Así se verán todos tus correos',
        'contenido' => '<p>Hola <strong>{{cliente}}</strong>,</p>'
                     . '<p>Este es un ejemplo para que veas cómo queda tu correo con estos colores. '
                     . 'El texto real lo escribes en cada campaña.</p>',
        'cta_texto' => 'Ver la promoción',
        'cta_url'   => mkt_url_abs('tienda/index.php'),
        'promocion_id' => $promo['id'] ?? 0,
    ]);

    $vars = mkt_variables($muestra, $promo);
    echo json_encode([
        'ok'        => true,
        'html'      => mkt_html_correo($ejemplo, $muestra),
        'whatsapp'  => 'El diseño solo afecta al correo. WhatsApp siempre va en texto plano.',
        'asunto'    => mkt_render($ejemplo['asunto'], $vars),
        'preheader' => $ejemplo['preheader'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- Guardar ---------- */
if (isPost() && post('accion') === 'guardar') {
    verify_csrf();
    if (!$disponible) {
        flash('error', 'Falta aplicar database/migracion_marketing_p10.sql.');
        redirect('modules/marketing/diseno.php');
    }
    $limpio = fn(string $campo, string $def) => mail_color(post($campo), $def);

    dbUpdate('empresa', [
        'mkt_color'        => $limpio('mkt_color', '#15803D'),
        'mkt_color_boton'  => $limpio('mkt_color_boton', '#15803D'),
        'mkt_fondo'        => $limpio('mkt_fondo', '#F1F5F9'),
        'mkt_mostrar_logo' => postInt('mkt_mostrar_logo'),
        'mkt_pie'          => mb_substr(trim(post('mkt_pie')), 0, 255) ?: null,
    ], 'id = ?', [(int) ($emp['id'] ?? 1)]);

    audit('marketing', 'editar', 'Diseño del correo actualizado', ['tabla' => 'empresa', 'registro_id' => (int) ($emp['id'] ?? 1)]);
    flash('success', 'Diseño guardado. Todos los correos saldrán así a partir de ahora.');
    redirect('modules/marketing/diseno.php');
}

$d = mail_diseno();

/* ---------- Paletas listas para usar ---------- */
$paletas = [
    ['Verde retail',  '#15803D', '#15803D'],
    ['Azul confianza','#1D4ED8', '#2563EB'],
    ['Negro elegante','#171717', '#404040'],
    ['Rojo enérgico', '#B91C1C', '#DC2626'],
    ['Morado premium','#6D28D9', '#7C3AED'],
    ['Naranja cálido','#C2410C', '#EA580C'],
    ['Turquesa',      '#0F766E', '#0D9488'],
    ['Rosa',          '#BE185D', '#DB2777'],
];

layout_start('Diseño del correo', 'Los colores con los que tus clientes te reconocen');
?>

<?php if (!$disponible): ?>
  <div class="card p-4 mb-5 flex items-start gap-3 border-amber-200 bg-amber-50">
    <?= icon('alert', 'w-5 h-5 text-amber-500 mt-0.5 shrink-0') ?>
    <p class="text-sm text-amber-800">
      Para guardar el diseño falta aplicar
      <code class="bg-amber-100 px-1 rounded">database/migracion_marketing_p10.sql</code>.
      Mientras tanto puedes probar colores aquí, pero el botón de guardar no funcionará.
    </p>
  </div>
<?php endif; ?>

<div class="card p-4 mb-5 flex items-start gap-3 bg-sky-50 border-sky-100">
  <?= icon('alert', 'w-5 h-5 text-sky-500 mt-0.5 shrink-0') ?>
  <p class="text-sm text-sky-800">
    Esto afecta a <strong>todos</strong> los correos que envía el sistema: campañas, promociones
    y también los avisos de pedidos de tu tienda en línea. Un solo color para todo.
  </p>
</div>

<div class="grid xl:grid-cols-2 gap-5 items-start">

  <form method="post" class="card" data-preview>
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="guardar">

    <div class="px-5 py-4 border-b border-slate-100">
      <h2 class="font-bold text-slate-800 flex items-center gap-2"><?= icon('percent', 'w-4 h-4 text-slate-400') ?> Colores de tu marca</h2>
    </div>

    <div class="p-5 space-y-6">

      <!-- Paletas -->
      <div>
        <label class="label">Empieza por una combinación lista</label>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
          <?php foreach ($paletas as [$nombre, $c1, $c2]): ?>
            <button type="button" class="js-paleta p-2.5 rounded-xl border border-slate-200 hover:border-blue-400 hover:shadow-sm transition text-left"
                    data-color="<?= e($c1) ?>" data-boton="<?= e($c2) ?>">
              <span class="flex gap-1 mb-1.5">
                <span class="w-5 h-5 rounded-md" style="background:<?= e($c1) ?>"></span>
                <span class="w-5 h-5 rounded-md" style="background:<?= e($c2) ?>"></span>
              </span>
              <span class="text-[11px] font-semibold text-slate-600 leading-tight block"><?= e($nombre) ?></span>
            </button>
          <?php endforeach; ?>
        </div>
        <p class="text-xs text-slate-400 mt-2">O elige los tuyos exactos abajo.</p>
      </div>

      <!-- Colores exactos -->
      <div class="space-y-4">
        <?php
        $campos = [
            ['mkt_color',       'Barra superior',  $d['color'],       'El color de la franja donde va tu nombre o logo.'],
            ['mkt_color_boton', 'Botones',         $d['color_boton'], 'El botón de acción («Ver la promoción»).'],
            ['mkt_fondo',       'Fondo del correo', $d['fondo'],      'El color alrededor de la tarjeta blanca. Mejor un tono muy claro.'],
        ];
        foreach ($campos as [$campo, $etiqueta, $valor, $ayuda]): ?>
          <div class="flex items-center gap-4">
            <input type="color" name="<?= e($campo) ?>" value="<?= e($valor) ?>"
                   class="w-14 h-14 rounded-xl border border-slate-200 cursor-pointer shrink-0 p-1 bg-white"
                   data-campo="<?= e($campo) ?>">
            <div class="min-w-0">
              <p class="font-semibold text-slate-700 text-sm"><?= e($etiqueta) ?></p>
              <p class="text-xs text-slate-400"><?= e($ayuda) ?></p>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Logo -->
      <div class="pt-4 border-t border-slate-100">
        <label class="flex items-start gap-3 cursor-pointer">
          <input type="hidden" name="mkt_mostrar_logo" value="0">
          <input type="checkbox" name="mkt_mostrar_logo" value="1" <?= $d['mostrar_logo'] ? 'checked' : '' ?>
                 class="rounded border-slate-300 text-blue-600 focus:ring-blue-500 mt-0.5">
          <span>
            <span class="font-semibold text-slate-700 text-sm block">Mostrar mi logo en el correo</span>
            <span class="text-xs text-slate-400">
              <?php if (!empty($emp['logo'])): ?>
                Se usa el logo de Configuración. Si lo desmarcas, sale el nombre de la empresa en texto.
              <?php else: ?>
                Todavía no has subido un logo: ve a <a href="<?= e(url('modules/admin/configuracion.php')) ?>" class="text-blue-600 hover:underline">Configuración</a>.
                Mientras tanto sale el nombre en texto.
              <?php endif; ?>
            </span>
          </span>
        </label>
        <?php if (!empty($emp['logo'])): ?>
          <img src="<?= e(url($emp['logo'])) ?>" alt="" class="h-10 mt-3 rounded-lg border border-slate-200 bg-white p-1">
        <?php endif; ?>
      </div>

      <!-- Pie -->
      <div class="pt-4 border-t border-slate-100">
        <label class="label">Texto del pie</label>
        <input type="text" name="mkt_pie" value="<?= e((string) $d['pie']) ?>" maxlength="255" class="input"
               placeholder="Este correo se envió automáticamente. No hace falta responderlo.">
        <p class="text-xs text-slate-400 mt-1">
          Va debajo del nombre y el teléfono. Si lo dejas vacío se usa el texto de siempre.
        </p>
      </div>
    </div>

    <div class="px-5 py-4 border-t border-slate-100 flex justify-end">
      <button type="submit" class="btn btn-primary" <?= $disponible ? '' : 'disabled' ?>>
        <?= icon('save', 'w-4 h-4') ?> Guardar diseño
      </button>
    </div>
  </form>

  <div class="xl:sticky xl:top-4">
    <?= preview_correo_panel(url('modules/marketing/diseno.php')) ?>
  </div>
</div>

<script>
  // Las paletas solo rellenan los selectores de color; el resto lo hace la
  // vista previa, que ya escucha los cambios del formulario.
  document.querySelectorAll('.js-paleta').forEach(b => b.addEventListener('click', () => {
    const set = (campo, valor) => {
      const el = document.querySelector(`input[data-campo="${campo}"]`);
      if (!el) return;
      el.value = valor;
      el.dispatchEvent(new Event('input', { bubbles: true }));
    };
    set('mkt_color', b.dataset.color);
    set('mkt_color_boton', b.dataset.boton);
  }));
</script>

<?php layout_end(); ?>
