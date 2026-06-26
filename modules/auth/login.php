<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

if (is_logged_in()) {
    redirect('modules/dashboard/index.php');
}

$error = '';
if (isPost()) {
    verify_csrf();
    [$ok, $msg] = login_user(trim(post('usuario')), post('password'));
    if ($ok) {
        redirect('modules/dashboard/index.php');
    }
    $error = $msg;
}
?>
<!doctype html>
<html lang="es" class="h-full">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Iniciar sesión · <?= e(APP_NAME) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>body{font-family:'Inter',sans-serif}</style>
</head>
<body class="h-full bg-slate-100">
<div class="min-h-full grid lg:grid-cols-2">

  <!-- Panel izquierdo -->
  <div class="hidden lg:flex relative flex-col justify-between p-12 bg-gradient-to-br from-blue-600 via-blue-700 to-indigo-800 text-white overflow-hidden">
    <div class="absolute -top-24 -right-24 w-96 h-96 bg-white/10 rounded-full blur-2xl"></div>
    <div class="absolute -bottom-32 -left-20 w-96 h-96 bg-indigo-400/20 rounded-full blur-3xl"></div>
    <div class="relative flex items-center gap-3">
      <div class="w-11 h-11 rounded-2xl bg-white text-blue-700 flex items-center justify-center font-extrabold text-xl">N</div>
      <span class="text-2xl font-extrabold"><?= e(APP_NAME) ?></span>
    </div>
    <div class="relative">
      <h1 class="text-4xl font-extrabold leading-tight">Gestiona todo tu<br>negocio en un solo lugar.</h1>
      <p class="mt-4 text-blue-100/90 text-lg max-w-md">Punto de venta, inventario por sucursal, recursos humanos y finanzas — automatizado y en tiempo real.</p>
      <div class="mt-10 grid grid-cols-2 gap-4 max-w-md">
        <?php foreach ([['cart', 'Punto de Venta'], ['box', 'Inventario'], ['wallet', 'Nómina RD'], ['chart', 'Reportes']] as $f): ?>
          <div class="flex items-center gap-3 bg-white/10 backdrop-blur rounded-xl px-4 py-3">
            <span class="w-9 h-9 rounded-lg bg-white/20 flex items-center justify-center"><?= icon($f[0], 'w-5 h-5') ?></span>
            <span class="font-semibold text-sm"><?= e($f[1]) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <p class="relative text-blue-200/70 text-sm">© <?= date('Y') ?> <?= e(APP_NAME) ?> · Sistema multi-sucursal</p>
  </div>

  <!-- Panel derecho: formulario -->
  <div class="flex items-center justify-center p-6 sm:p-12">
    <div class="w-full max-w-sm">
      <div class="lg:hidden flex items-center gap-3 mb-8 justify-center">
        <div class="w-11 h-11 rounded-2xl bg-blue-600 text-white flex items-center justify-center font-extrabold text-xl">N</div>
        <span class="text-2xl font-extrabold text-slate-800"><?= e(APP_NAME) ?></span>
      </div>

      <h2 class="text-2xl font-extrabold text-slate-800">Bienvenido de nuevo 👋</h2>
      <p class="text-slate-500 mt-1.5 text-sm">Ingresa tus credenciales para acceder al panel.</p>

      <?php if ($error): ?>
        <div class="mt-5 flex items-center gap-2 rounded-xl bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-700 font-medium">
          <svg class="w-5 h-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4M12 17h.01"/></svg>
          <?= e($error) ?>
        </div>
      <?php endif; ?>

      <form method="post" class="mt-7 space-y-4">
        <?= csrf_field() ?>
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1.5">Usuario o correo</label>
          <input type="text" name="usuario" autofocus required value="<?= e(post('usuario')) ?>"
                 class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition"
                 placeholder="admin">
        </div>
        <div x-data="{show:false}">
          <label class="block text-sm font-medium text-slate-600 mb-1.5">Contraseña</label>
          <div class="relative">
            <input :type="show ? 'text':'password'" name="password" required
                   class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 pr-11 text-sm text-slate-700 placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition"
                   placeholder="••••••••">
            <button type="button" @click="show=!show" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600">
              <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>
        <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold px-5 py-3 rounded-xl transition shadow-lg shadow-blue-600/25">Iniciar sesión</button>
      </form>

      <div class="mt-7 rounded-xl bg-slate-50 border border-slate-200 p-4">
        <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">Cuentas de demostración</p>
        <div class="space-y-1.5 text-sm text-slate-600">
          <div class="flex justify-between"><span>Super Admin</span><code class="text-slate-500">admin / admin123</code></div>
          <div class="flex justify-between"><span>Gerente</span><code class="text-slate-500">gerente / gerente123</code></div>
          <div class="flex justify-between"><span>Cajero</span><code class="text-slate-500">cajero / cajero123</code></div>
        </div>
      </div>
    </div>
  </div>
</div>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.10/dist/cdn.min.js"></script>
</body>
</html>
