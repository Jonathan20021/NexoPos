<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

// Sin caché: con el botón «atrás» tras cerrar sesión, una copia guardada
// mostraría el formulario con un token CSRF ya muerto.
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
}

if (is_logged_in()) {
    redirect('modules/dashboard/index.php');
}

// Si hay una verificación a medias, la pantalla que toca es la del código.
if (login_pendiente()) {
    redirect('modules/auth/verificar.php');
}

$error = '';
if (isPost()) {
    verify_csrf();
    $r = login_intentar(trim((string) post('usuario')), (string) post('password'));

    // Patrón PRG: nada de reenviar la contraseña al recargar.
    if ($r['estado'] === 'ok')  redirect('modules/dashboard/index.php');
    if ($r['estado'] === 'otp') redirect('modules/auth/verificar.php');

    $error = $r['mensaje'];
}

// Las credenciales de demostración solo se muestran fuera de producción.
$mostrarDemo = APP_ENV !== 'production';
$logo = setting('logo');
$tieneLogo = $logo && is_file(dirname(__DIR__, 2) . '/' . $logo);
$empresa = setting('nombre', APP_NAME);
?>
<!doctype html>
<html lang="es" class="h-full">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Iniciar sesión · <?= e(APP_NAME) ?></title>
<link rel="icon" href="<?= e(asset('favicon.svg')) ?>" type="image/svg+xml">
<meta name="theme-color" content="#0f172a">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = { theme: { extend: {
  fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'] },
  keyframes: {
    flotar:  { '0%,100%': { transform: 'translateY(0)' }, '50%': { transform: 'translateY(-10px)' } },
    entrar:  { '0%': { opacity: 0, transform: 'translateY(14px)' }, '100%': { opacity: 1, transform: 'translateY(0)' } },
    brillo:  { '0%,100%': { opacity: .35 }, '50%': { opacity: .6 } },
  },
  animation: {
    flotar: 'flotar 6s ease-in-out infinite',
    entrar: 'entrar .5s cubic-bezier(.22,1,.36,1) both',
    brillo: 'brillo 7s ease-in-out infinite',
  },
} } };
</script>
<style>
  [x-cloak]{display:none!important}
  body{font-family:'Inter',sans-serif}
  /* Trama sutil del panel de marca: da profundidad sin cargar la vista. */
  .trama{
    background-image:
      linear-gradient(rgba(255,255,255,.055) 1px, transparent 1px),
      linear-gradient(90deg, rgba(255,255,255,.055) 1px, transparent 1px);
    background-size: 46px 46px;
    mask-image: radial-gradient(ellipse 80% 60% at 50% 40%, #000 40%, transparent 100%);
  }
  .campo{
    width:100%; border-radius:.85rem; border:1px solid #e2e8f0; background:#fff;
    padding:.8rem 1rem; font-size:.925rem; color:#334155; transition:all .18s ease; outline:none;
  }
  .campo::placeholder{color:#cbd5e1}
  .campo:focus{border-color:#3b82f6; box-shadow:0 0 0 4px rgba(59,130,246,.12)}
  @media (prefers-reduced-motion: reduce){ *{animation:none!important; transition:none!important} }
</style>
</head>
<body class="h-full bg-white text-slate-700">
<div class="min-h-full lg:grid lg:grid-cols-[1.05fr_1fr] xl:grid-cols-[1.15fr_1fr]">

  <!-- ============ Panel de marca ============ -->
  <div class="relative hidden lg:flex flex-col justify-between p-12 xl:p-14 overflow-hidden
              bg-gradient-to-br from-slate-900 via-blue-950 to-slate-900 text-white">
    <div class="absolute inset-0 trama"></div>
    <div class="absolute -top-32 -right-28 w-[26rem] h-[26rem] bg-blue-500/25 rounded-full blur-3xl animate-brillo"></div>
    <div class="absolute -bottom-40 -left-24 w-[28rem] h-[28rem] bg-indigo-500/20 rounded-full blur-3xl animate-brillo" style="animation-delay:2s"></div>

    <!-- Marca -->
    <div class="relative flex items-center gap-3 animate-entrar">
      <?php if ($tieneLogo): ?>
        <img src="<?= e(url($logo)) ?>" alt="" class="w-11 h-11 rounded-2xl object-contain bg-white p-1">
      <?php else: ?>
        <div class="w-11 h-11 rounded-2xl bg-white text-blue-700 flex items-center justify-center font-extrabold text-xl shadow-lg shadow-blue-900/40">N</div>
      <?php endif; ?>
      <span class="text-xl font-extrabold tracking-tight"><?= e(APP_NAME) ?></span>
    </div>

    <!-- Mensaje + vista previa -->
    <div class="relative animate-entrar" style="animation-delay:.08s">
      <span class="inline-flex items-center gap-2 rounded-full bg-white/10 backdrop-blur px-3.5 py-1.5 text-[12.5px] font-semibold text-blue-100 ring-1 ring-white/15">
        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
        Multi-sucursal · Facturación con NCF
      </span>

      <h1 class="mt-6 text-[2.6rem] xl:text-5xl font-extrabold leading-[1.08] tracking-tight">
        Todo tu negocio,<br>
        <span class="bg-gradient-to-r from-blue-300 to-cyan-200 bg-clip-text text-transparent">en un solo tablero.</span>
      </h1>
      <p class="mt-5 text-blue-100/75 text-[15px] leading-relaxed max-w-md">
        Punto de venta, inventario por sucursal, nómina dominicana, CRM y reportes para
        dirección, finanzas y contabilidad. Sin hojas de cálculo sueltas.
      </p>

      <!-- Vista previa del panel -->
      <div class="relative mt-9 max-w-md animate-flotar">
        <div class="rounded-2xl bg-white/[.07] backdrop-blur-md ring-1 ring-white/15 p-4 shadow-2xl shadow-blue-950/50">
          <div class="flex items-center justify-between mb-3.5">
            <span class="text-[11px] font-bold uppercase tracking-wider text-blue-200/70">Ventas del mes</span>
            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-400/15 px-2 py-0.5 text-[11px] font-bold text-emerald-300">
              <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m18 15-6-6-6 6"/></svg>
              18.4%
            </span>
          </div>
          <p class="text-3xl font-extrabold tracking-tight">RD$ 1,284,900</p>

          <!-- Mini gráfico -->
          <svg viewBox="0 0 320 74" class="w-full h-16 mt-4" preserveAspectRatio="none" aria-hidden="true">
            <defs>
              <linearGradient id="lgLogin" x1="0" x2="0" y1="0" y2="1">
                <stop offset="0%" stop-color="#60a5fa" stop-opacity=".55"/>
                <stop offset="100%" stop-color="#60a5fa" stop-opacity="0"/>
              </linearGradient>
            </defs>
            <polygon points="0,74 0,54 40,48 80,56 120,34 160,40 200,22 240,28 280,12 320,18 320,74" fill="url(#lgLogin)"/>
            <polyline points="0,54 40,48 80,56 120,34 160,40 200,22 240,28 280,12 320,18"
                      fill="none" stroke="#93c5fd" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>

          <div class="grid grid-cols-3 gap-2 mt-4 pt-4 border-t border-white/10">
            <?php foreach ([['Margen', '38.2%'], ['Ticket', 'RD$ 2,410'], ['Sucursales', '2']] as [$k, $v]): ?>
              <div>
                <p class="text-[11px] text-blue-200/60 font-medium"><?= e($k) ?></p>
                <p class="text-sm font-bold mt-0.5"><?= e($v) ?></p>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Pie -->
    <div class="relative flex items-center gap-5 text-[12.5px] text-blue-200/60 animate-entrar" style="animation-delay:.16s">
      <span>© <?= date('Y') ?> <?= e(APP_NAME) ?></span>
      <span class="w-1 h-1 rounded-full bg-blue-200/30"></span>
      <span class="inline-flex items-center gap-1.5">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Datos cifrados en tránsito
      </span>
    </div>
  </div>

  <!-- ============ Formulario ============ -->
  <div class="flex items-center justify-center px-5 py-10 sm:px-10 lg:px-12 min-h-screen lg:min-h-full bg-slate-50 lg:bg-white"
       x-data="acceso()">
    <div class="w-full max-w-[26rem] animate-entrar">

      <!-- Marca en móvil -->
      <div class="lg:hidden flex items-center justify-center gap-3 mb-9">
        <?php if ($tieneLogo): ?>
          <img src="<?= e(url($logo)) ?>" alt="" class="w-11 h-11 rounded-2xl object-contain bg-white border border-slate-200 p-1">
        <?php else: ?>
          <div class="w-11 h-11 rounded-2xl bg-blue-600 text-white flex items-center justify-center font-extrabold text-xl shadow-lg shadow-blue-600/25">N</div>
        <?php endif; ?>
        <span class="text-2xl font-extrabold text-slate-800 tracking-tight"><?= e(APP_NAME) ?></span>
      </div>

      <h2 class="text-[1.75rem] font-extrabold text-slate-800 tracking-tight">Bienvenido de nuevo</h2>
      <p class="text-slate-500 mt-1.5 text-[14.5px]">Entra a <span class="font-semibold text-slate-700"><?= e($empresa) ?></span> para continuar.</p>

      <?php if ($error): ?>
        <div role="alert" class="mt-6 flex items-start gap-3 rounded-xl bg-rose-50 border border-rose-200 px-4 py-3.5">
          <svg class="w-5 h-5 shrink-0 text-rose-500 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4M12 17h.01"/></svg>
          <p class="text-sm text-rose-700 font-medium leading-relaxed"><?= e($error) ?></p>
        </div>
      <?php endif; ?>

      <form method="post" class="mt-7 space-y-4" @submit="enviando = true">
        <?= csrf_field() ?>

        <div>
          <label for="usuario" class="block text-[13.5px] font-semibold text-slate-600 mb-1.5">Usuario o correo</label>
          <div class="relative">
            <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
              <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </span>
            <input type="text" id="usuario" name="usuario" x-model="usuario" required autofocus
                   autocomplete="username" autocapitalize="none" spellcheck="false"
                   class="campo pl-11" placeholder="tu usuario">
          </div>
        </div>

        <div>
          <div class="flex items-baseline justify-between mb-1.5">
            <label for="password" class="block text-[13.5px] font-semibold text-slate-600">Contraseña</label>
            <span x-show="mayusculas" x-cloak class="text-[11.5px] font-bold text-amber-600">Bloq Mayús activado</span>
          </div>
          <div class="relative">
            <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
              <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </span>
            <input :type="ver ? 'text' : 'password'" id="password" name="password" x-model="password" required
                   autocomplete="current-password" @keyup="detectarMayusculas($event)" @keydown="detectarMayusculas($event)"
                   class="campo pl-11 pr-11" placeholder="••••••••">
            <button type="button" @click="ver = !ver" tabindex="-1"
                    :aria-label="ver ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 transition p-1">
              <svg x-show="!ver" class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
              <svg x-show="ver" x-cloak class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M10.6 10.6a3 3 0 0 0 4.2 4.2"/><path d="M17.9 17.9A10.4 10.4 0 0 1 12 19c-7 0-10-7-10-7a18.5 18.5 0 0 1 5.1-6M9.9 5.2A10.4 10.4 0 0 1 12 5c7 0 10 7 10 7a18.6 18.6 0 0 1-2.2 3.2"/><path d="m2 2 20 20"/></svg>
            </button>
          </div>
        </div>

        <label class="flex items-center gap-2.5 text-[13.5px] text-slate-600 select-none cursor-pointer pt-0.5">
          <input type="checkbox" x-model="recordar" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500 w-4 h-4">
          Recordar mi usuario en este equipo
        </label>

        <button type="submit" :disabled="enviando"
                class="w-full flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 active:bg-blue-800
                       disabled:opacity-70 disabled:cursor-wait text-white font-semibold px-5 py-3.5 rounded-xl
                       transition shadow-lg shadow-blue-600/25 focus:outline-none focus-visible:ring-4 focus-visible:ring-blue-500/25">
          <svg x-show="enviando" x-cloak class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <path d="M21 12a9 9 0 1 1-6.2-8.6" stroke-linecap="round"/>
          </svg>
          <span x-text="enviando ? 'Entrando…' : 'Iniciar sesión'">Iniciar sesión</span>
        </button>
      </form>

      <?php if (otp_politica_activa() && otp_operativo()): ?>
        <p class="mt-4 flex items-start gap-2 text-[12.5px] text-slate-400 leading-relaxed">
          <svg class="w-4 h-4 shrink-0 mt-px text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>
          </svg>
          <span>Verificación en dos pasos activa: al validar tu contraseña te enviaremos un código a tu correo.</span>
        </p>
      <?php endif; ?>

      <?php if ($mostrarDemo): ?>
        <div class="mt-8">
          <div class="flex items-center gap-3 mb-3">
            <span class="h-px flex-1 bg-slate-200"></span>
            <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Cuentas de prueba</span>
            <span class="h-px flex-1 bg-slate-200"></span>
          </div>
          <div class="grid gap-2">
            <?php foreach ([
                ['admin', 'admin123', 'Super Administrador', 'Acceso total al sistema', 'blue'],
                ['gerente', 'gerente123', 'Gerente de Sucursal', 'Ventas, inventario y reportes', 'emerald'],
                ['cajero', 'cajero123', 'Cajero', 'Solo punto de venta y caja', 'amber'],
            ] as [$usr, $pwd, $rol, $desc, $col]):
              $estilos = ['blue' => 'bg-blue-50 text-blue-600', 'emerald' => 'bg-emerald-50 text-emerald-600', 'amber' => 'bg-amber-50 text-amber-600'];
            ?>
              <button type="button" @click="usar('<?= $usr ?>', '<?= $pwd ?>')"
                      class="group flex items-center gap-3 w-full text-left rounded-xl border border-slate-200 bg-white px-3.5 py-2.5
                             hover:border-blue-300 hover:bg-blue-50/40 transition focus:outline-none focus-visible:ring-4 focus-visible:ring-blue-500/15">
                <span class="w-9 h-9 rounded-lg <?= $estilos[$col] ?> flex items-center justify-center shrink-0 text-[13px] font-extrabold">
                  <?= strtoupper(substr($usr, 0, 1)) ?>
                </span>
                <span class="min-w-0 flex-1">
                  <span class="block text-[13.5px] font-semibold text-slate-700 leading-tight"><?= e($rol) ?></span>
                  <span class="block text-[11.5px] text-slate-400 truncate"><?= e($desc) ?></span>
                </span>
                <span class="text-[11px] font-semibold text-slate-400 group-hover:text-blue-600 transition shrink-0">Usar</span>
              </button>
            <?php endforeach; ?>
          </div>
          <p class="text-[11.5px] text-slate-400 mt-3 leading-relaxed">
            Estas credenciales solo aparecen fuera de producción. Cambia la contraseña del
            administrador antes de poner el sistema en manos del cliente.
          </p>
        </div>
      <?php endif; ?>

      <p class="lg:hidden text-center text-[12px] text-slate-400 mt-9">
        © <?= date('Y') ?> <?= e(APP_NAME) ?> · Sistema multi-sucursal
      </p>
    </div>
  </div>
</div>

<script>
function acceso() {
  return {
    usuario: <?= json_encode((string) post('usuario')) ?>,
    password: '',
    ver: false,
    recordar: false,
    enviando: false,
    mayusculas: false,

    init() {
      // Recuerda solo el usuario, nunca la contraseña.
      try {
        var guardado = localStorage.getItem('nexopos.usuario');
        if (guardado && !this.usuario) { this.usuario = guardado; this.recordar = true; }
      } catch (e) {}

      this.$watch('recordar', (v) => {
        try { v ? localStorage.setItem('nexopos.usuario', this.usuario) : localStorage.removeItem('nexopos.usuario'); } catch (e) {}
      });
      this.$watch('usuario', (v) => {
        try { if (this.recordar) localStorage.setItem('nexopos.usuario', v); } catch (e) {}
      });

      // Si el usuario ya viene puesto, el foco va directo a la contraseña.
      if (this.usuario) this.$nextTick(() => document.getElementById('password')?.focus());
    },

    detectarMayusculas(e) {
      if (typeof e.getModifierState === 'function') this.mayusculas = e.getModifierState('CapsLock');
    },

    usar(u, p) {
      this.usuario = u;
      this.password = p;
      this.$nextTick(() => document.querySelector('button[type="submit"]')?.focus());
    },
  };
}
</script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.10/dist/cdn.min.js"></script>
</body>
</html>
