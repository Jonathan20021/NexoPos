<?php
/**
 * Página no encontrada (ErrorDocument 404 de Apache).
 *
 * Apache la sirve para cualquier ruta inexistente, incluida gente sin sesión, así
 * que no puede asumir usuario ni base de datos: se pinta sola, sin layout.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

http_response_code(404);
$logueado = is_logged_in();
$destino  = $logueado ? url('modules/dashboard/index.php') : url('modules/auth/login.php');
$ruta     = mb_substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 120);
?>
<!doctype html>
<html lang="es" class="h-full">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Página no encontrada · <?= e(APP_NAME) ?></title>
<link rel="icon" href="<?= e(asset('favicon.svg')) ?>" type="image/svg+xml">
<meta name="robots" content="noindex">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<style>body{font-family:'Inter',sans-serif}</style>
</head>
<body class="h-full bg-slate-100 text-slate-700">
<div class="min-h-full flex items-center justify-center p-6">
  <div class="w-full max-w-lg text-center">

    <div class="inline-flex items-center gap-2.5 mb-8">
      <div class="w-10 h-10 rounded-2xl bg-blue-600 text-white flex items-center justify-center font-extrabold text-lg shadow-lg shadow-blue-600/25">N</div>
      <span class="text-xl font-extrabold text-slate-800 tracking-tight"><?= e(APP_NAME) ?></span>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-[0_4px_24px_-8px_rgba(15,23,42,.12)] p-8 sm:p-10">
      <p class="text-[64px] leading-none font-extrabold bg-gradient-to-br from-blue-600 to-indigo-500 bg-clip-text text-transparent">404</p>
      <h1 class="text-xl font-extrabold text-slate-800 mt-3">Esta página no existe</h1>
      <p class="text-slate-500 mt-2 text-sm leading-relaxed">
        La dirección que abriste no corresponde a ninguna pantalla del sistema.
        Puede que el enlace esté viejo o que la sección se haya movido.
      </p>

      <?php if ($ruta): ?>
        <p class="mt-4 text-[11.5px] text-slate-400 font-mono break-all bg-slate-50 border border-slate-100 rounded-lg px-3 py-2"><?= e($ruta) ?></p>
      <?php endif; ?>

      <div class="flex flex-col sm:flex-row gap-2.5 justify-center mt-7">
        <a href="<?= e($destino) ?>"
           class="inline-flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-semibold px-5 py-2.5 rounded-xl transition shadow-lg shadow-blue-600/25">
          <?= icon($logueado ? 'dashboard' : 'lock', 'w-4 h-4') ?>
          <?= $logueado ? 'Ir al panel' : 'Iniciar sesión' ?>
        </a>
        <?php if ($logueado): ?>
          <a href="<?= e(url('modules/busqueda/index.php')) ?>"
             class="inline-flex items-center justify-center gap-2 bg-white text-slate-700 border border-slate-200 hover:bg-slate-50 font-semibold px-5 py-2.5 rounded-xl transition">
            <?= icon('search', 'w-4 h-4') ?> Buscar en el sistema
          </a>
        <?php endif; ?>
      </div>
    </div>

    <p class="text-xs text-slate-400 mt-6">© <?= date('Y') ?> <?= e(APP_NAME) ?> · Sistema de Gestión Comercial Multi-Sucursal</p>
  </div>
</div>
</body>
</html>
