<?php
/**
 * Shell de la tienda pública. Es independiente del layout administrativo:
 * no muestra sidebar, no exige sesión y no filtra por permisos.
 *
 * Paleta: verde de retail + azul de confianza para las llamadas a la acción.
 * Tipografía: Rubik (títulos) + Nunito Sans (cuerpo).
 */

function tienda_empresa(): array
{
    return $GLOBALS['empresa'] ?: ['nombre' => APP_NAME];
}

/** Sucursales que el cliente puede ver en la tienda. */
function tienda_sucursales(): array
{
    return qAll("SELECT id, nombre, direccion, telefono, whatsapp, horario
                   FROM sucursales
                  WHERE activo = 1 AND tienda_activa = 1
                  ORDER BY nombre");
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

function tienda_start(string $titulo, string $descripcion = ''): void
{
    $emp = tienda_empresa();
    ?><!doctype html>
<html lang="es" class="h-full">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?> · <?= e($emp['nombre']) ?></title>
<?php if ($descripcion): ?><meta name="description" content="<?= e($descripcion) ?>"><?php endif; ?>
<link rel="icon" href="<?= e(url('assets/favicon.svg')) ?>">
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = {
  theme: {
    extend: {
      colors: {
        marca:  { DEFAULT: '#15803D', claro: '#22C55E', muy: '#F0FDF4', texto: '#14532D' },
        accion: { DEFAULT: '#0369A1', hover: '#075985' },
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
  body { background: #F0FDF4; color: #14532D; }
  .btn-accion {
    background: #0369A1; color: #fff; font-weight: 600;
    transition: background-color .2s ease;
  }
  .btn-accion:hover { background: #075985; }
  .btn-accion:focus-visible { outline: 3px solid #7DD3FC; outline-offset: 2px; }
  .btn-marca { background: #15803D; color: #fff; font-weight: 600; transition: background-color .2s ease; }
  .btn-marca:hover { background: #166534; }
  .btn-marca:focus-visible { outline: 3px solid #86EFAC; outline-offset: 2px; }
  .campo {
    width: 100%; border: 1px solid #D1D5DB; border-radius: .75rem;
    padding: .625rem .875rem; background: #fff; color: #14532D;
    transition: border-color .2s ease, box-shadow .2s ease;
  }
  .campo:focus { outline: none; border-color: #15803D; box-shadow: 0 0 0 3px rgba(21,128,61,.15); }
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

function tienda_end(): void
{
    $emp = tienda_empresa();
    ?>
<footer class="mt-16 border-t border-emerald-200 bg-white">
  <div class="max-w-6xl mx-auto px-4 py-8 text-sm text-emerald-900/70">
    <p class="font-display font-semibold text-marca-texto text-base"><?= e($emp['nombre']) ?></p>
    <?php if (!empty($emp['telefono'])): ?><p class="mt-1">Tel. <?= e($emp['telefono']) ?></p><?php endif; ?>
    <p class="mt-3 text-emerald-900/50">Ordena en línea y retira en la sucursal que prefieras.</p>
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
