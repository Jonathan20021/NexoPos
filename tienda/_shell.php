<?php
/**
 * Shell de la tienda pública. Es independiente del layout administrativo:
 * no muestra sidebar, no exige sesión y no filtra por permisos.
 *
 * LA IDENTIDAD LA PONE LA SUCURSAL, NO LA CASA
 *
 * Importers distribuye varias marcas y cada local es el escaparate de una:
 * quien entra a «Inglot Punta Cana» viene buscando INGLOT, no a su
 * distribuidor. Por eso el color, el logotipo y el nombre salen de la marca de
 * la sucursal elegida —`sucursales.tienda_id`, migración P20— y toda la paleta
 * se deriva de ese color en variables CSS.
 *
 * El emisor fiscal sigue siendo la empresa, y eso se dice en el pie: la marca
 * pone la cara, no la declaración.
 *
 * Una oficina sin marca asignada cae a la identidad de Importers, que es el
 * comportamiento que había antes de todo esto.
 *
 * Tipografía: Rubik (títulos) + Nunito Sans (cuerpo).
 */

function tienda_empresa(): array
{
    return $GLOBALS['empresa'] ?: ['nombre' => APP_NAME];
}

/** Sucursales que el cliente puede ver en la tienda. */
function tienda_sucursales(): array
{
    return qAll("SELECT id, nombre, direccion, telefono, whatsapp, horario, tienda_id
                   FROM sucursales
                  WHERE activo = 1 AND tienda_activa = 1
                  ORDER BY nombre");
}

/**
 * La marca con la que se presenta una sucursal.
 *
 * Devuelve siempre algo utilizable: si el local no tiene marca asignada, si el
 * módulo de tiendas no está, o si la migración P20 aún no se ha aplicado, cae a
 * la identidad de la empresa. Una tienda pública no puede quedarse sin cabecera
 * porque falte un dato de configuración.
 */
function tienda_marca_de(?array $sucursal): array
{
    $emp = tienda_empresa();
    $porDefecto = [
        'id'         => null,
        'nombre'     => $emp['nombre'] ?? APP_NAME,
        'color'      => marca_app(),
        'logo'       => $emp['logo'] ?? null,
        'encabezado' => null,
        'es_empresa' => true,
    ];

    $tid = (int) ($sucursal['tienda_id'] ?? 0);
    if (!$tid || !function_exists('tienda') || !tiendas_disponible()) return $porDefecto;

    $t = tienda($tid);
    if (!$t) return $porDefecto;

    return [
        'id'         => (int) $t['id'],
        'nombre'     => $t['nombre'],
        'color'      => preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $t['color']) ? $t['color'] : marca_app(),
        'logo'       => $t['logo'] ?: null,
        'encabezado' => $t['encabezado'] ?: null,
        'es_empresa' => false,
    ];
}

/**
 * Paleta completa a partir del color de la marca.
 *
 * Se deriva en vez de pedirle seis colores a quien da de alta una marca: nadie
 * quiere elegir un «hover» y un «muy claro» a mano, y elegidos a ojo salen
 * combinaciones que no contrastan. Con un solo color de marca, el resto sale
 * por construcción y el contraste está garantizado.
 */
function tienda_paleta(string $hex): array
{
    [$r, $g, $b] = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    $mezcla = function (float $f) use ($r, $g, $b) {
        // f > 0 aclara hacia el blanco, f < 0 oscurece hacia el negro.
        $m = fn($c) => (int) round($f >= 0 ? $c + (255 - $c) * $f : $c * (1 + $f));
        return sprintf('#%02X%02X%02X', $m($r), $m($g), $m($b));
    };
    // Luminancia relativa: decide si el texto sobre la marca va blanco o negro.
    $lum = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;

    return [
        'marca'    => $hex,
        'oscuro'   => $mezcla(-0.22),      // hover de los botones
        'claro'    => $mezcla(0.55),
        'suave'    => $mezcla(0.93),       // fondos de realce
        'borde'    => $mezcla(0.80),
        'sobre'    => $lum > 0.62 ? '#1A1A1A' : '#FFFFFF',
        'texto'    => $mezcla(-0.55),
        'escala'   => [
            50 => $mezcla(0.95), 100 => $mezcla(0.88), 200 => $mezcla(0.74),
            300 => $mezcla(0.55), 400 => $mezcla(0.28), 500 => $mezcla(0.12),
            600 => $hex, 700 => $mezcla(-0.18), 800 => $mezcla(-0.34),
            900 => $mezcla(-0.50), 950 => $mezcla(-0.66),
        ],
    ];
}

/** Número de WhatsApp en formato wa.me (solo dígitos). */
function wa_numero(?string $tel): string
{
    return preg_replace('/\D+/', '', (string) $tel);
}

/** Enlace wa.me con mensaje predefinido. */
function wa_link(?string $telefono, string $mensaje): string
{
    $n = wa_numero($telefono);
    if ($n === '') return '';
    return 'https://wa.me/' . $n . '?text=' . rawurlencode($mensaje);
}

function tienda_start(string $titulo, string $descripcion = '', ?array $marca = null): void
{
    $emp = tienda_empresa();
    $marca = $marca ?: tienda_marca_de(null);
    $pal = tienda_paleta($marca['color']);
    ?><!doctype html>
<html lang="es" class="h-full">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?> · <?= e($marca['nombre']) ?></title>
<meta name="theme-color" content="<?= e($pal['marca']) ?>">
<?php if ($descripcion): ?><meta name="description" content="<?= e($descripcion) ?>"><?php endif; ?>
<link rel="icon" href="<?= e(url('assets/favicon.svg')) ?>">
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = {
  theme: {
    extend: {
      colors: {
        // Todo cuelga de la variable CSS: cambiar de sucursal cambia la marca
        // sin volver a generar clases.
        marca:  { DEFAULT: 'var(--marca)', claro: 'var(--marca-claro)',
                  muy: 'var(--marca-suave)', texto: 'var(--marca-texto)',
                  borde: 'var(--marca-borde)', sobre: 'var(--sobre-marca)' },
        accion: { DEFAULT: 'var(--marca-oscuro)', hover: 'var(--marca)' },
        // La tienda nació en verde y arrastra 78 clases `emerald-*` repartidas
        // por sus plantillas. Se pisa la paleta en vez de reescribirlas una a
        // una, igual que con `blue` en el panel. Antes se pisaba con la paleta
        // fija de la casa; ahora con la escala derivada de la MARCA del local,
        // que es lo que hace que la tienda entera cambie de identidad.
        emerald: <?= json_encode($pal['escala'], JSON_UNESCAPED_SLASHES) ?>,
      },
      fontFamily: {
        sans:    ['"Nunito Sans"', 'system-ui', 'sans-serif'],
        display: ['Rubik', 'system-ui', 'sans-serif'],
      },
    },
  },
};
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400;600;700&family=Rubik:wght@500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --marca:        <?= e($pal['marca']) ?>;
    --marca-oscuro: <?= e($pal['oscuro']) ?>;
    --marca-claro:  <?= e($pal['claro']) ?>;
    --marca-suave:  <?= e($pal['suave']) ?>;
    --marca-borde:  <?= e($pal['borde']) ?>;
    --marca-texto:  <?= e($pal['texto']) ?>;
    --sobre-marca:  <?= e($pal['sobre']) ?>;
  }
  body { background: #FAFAFB; color: #23252B; }
  .btn-accion {
    background: var(--marca); color: var(--sobre-marca); font-weight: 600;
    transition: background-color .2s ease, transform .12s ease;
  }
  .btn-accion:hover { background: var(--marca-oscuro); }
  .btn-accion:active { transform: scale(.98); }
  .btn-accion:focus-visible { outline: 3px solid var(--marca-claro); outline-offset: 2px; }
  .btn-marca { background: var(--marca); color: var(--sobre-marca); font-weight: 600; transition: background-color .2s ease; }
  .btn-marca:hover { background: var(--marca-oscuro); }
  .btn-marca:focus-visible { outline: 3px solid var(--marca-claro); outline-offset: 2px; }
  .campo {
    width: 100%; border: 1px solid #DDDEE3; border-radius: .75rem;
    padding: .625rem .875rem; background: #fff; color: #23252B;
    transition: border-color .2s ease, box-shadow .2s ease;
  }
  .campo:focus { outline: none; border-color: var(--marca); box-shadow: 0 0 0 3px var(--marca-borde); }

  /* El logotipo de cada marca es un wordmark BLANCO: va sobre la banda de
     color, nunca sobre fondo claro. El ancho máximo evita que un wordmark de
     una sola línea (INGLOT) se vea el doble de grande que uno de dos
     (L'OCCITANE EN PROVENCE) al igualarlos solo por altura. */
  .logo-marca { height: 30px; max-width: 190px; object-fit: contain; object-position: left center; display: block; }
  @media (min-width: 640px) { .logo-marca { height: 34px; max-width: 230px; } }

  .tarjeta-producto { transition: box-shadow .2s ease, border-color .2s ease; }
  .tarjeta-producto:hover { box-shadow: 0 8px 24px rgba(20,22,30,.09); border-color: var(--marca-borde); }

  [x-cloak] { display: none !important; }
  @media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { animation-duration: .01ms !important; transition-duration: .01ms !important; }
  }
</style>
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
</head>
<body class="min-h-full font-sans antialiased">
<?php
}

function tienda_end(?array $marca = null): void
{
    $emp = tienda_empresa();
    ?>
<footer class="mt-20 border-t border-slate-200 bg-white">
  <div class="max-w-6xl mx-auto px-4 py-10 text-sm text-slate-500">
    <?php if ($marca && empty($marca['es_empresa'])): ?>
      <p class="font-display font-semibold text-slate-800 text-base"><?= e($marca['nombre']) ?></p>
      <!-- Quien compra ve la marca; quien recibe la factura tiene que saber
           quién factura. Un solo RNC para todas las marcas. -->
      <p class="mt-1">Distribuido por <?= e($emp['nombre']) ?><?= !empty($emp['rnc']) ? ' · RNC ' . e($emp['rnc']) : '' ?></p>
    <?php else: ?>
      <p class="font-display font-semibold text-slate-800 text-base"><?= e($emp['nombre']) ?></p>
      <?php if (!empty($emp['rnc'])): ?><p class="mt-1">RNC <?= e($emp['rnc']) ?></p><?php endif; ?>
    <?php endif; ?>
    <?php if (!empty($emp['telefono'])): ?><p class="mt-1">Tel. <?= e($emp['telefono']) ?></p><?php endif; ?>
    <p class="mt-4 text-slate-400">Ordena en línea y retira en la sucursal que prefieras.</p>
  </div>
</footer>
</body>
</html>
<?php
}

/** Ícono SVG en línea. Nunca emojis. */
function ticon(string $name, string $classes = 'w-5 h-5'): string
{
    $paths = [
        'cart'     => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>',
        'store'    => '<path d="M2 7l1-3h18l1 3"/><path d="M4 7v13h16V7"/><path d="M2 7h20"/><path d="M9 20v-6h6v6"/>',
        'search'   => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
        'minus'    => '<path d="M5 12h14"/>',
        'trash'    => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'check'    => '<path d="M20 6 9 17l-5-5"/>',
        'whatsapp' => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>',
        'pin'      => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/>',
        'clock'    => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'box'      => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.27 6.96 12 12l8.73-5.04"/><path d="M12 22V12"/>',
        'arrow-left' => '<path d="M19 12H5"/><path d="m12 19-7-7 7-7"/>',
    ];
    $p = $paths[$name] ?? $paths['box'];
    return '<svg xmlns="http://www.w3.org/2000/svg" class="' . $classes . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $p . '</svg>';
}
