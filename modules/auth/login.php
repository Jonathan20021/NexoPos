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
$logo = setting('logo') ?: marca_app_logo();
$tieneLogo = $logo && is_file(dirname(__DIR__, 2) . '/' . $logo);
$empresa = setting('nombre', APP_NAME);

/**
 * Lo que enseña el panel de marca: CAPACIDADES, no cifras.
 *
 * Antes había aquí una tarjeta con «ventas del mes RD$ 1,284,900», un margen y
 * un ticket promedio inventados. Números falsos en la única pantalla que ve
 * alguien que todavía no ha entrado: aunque se entiendan como maqueta, enseñar
 * dinero que no existe en la puerta de un sistema contable es justo el detalle
 * que hace dudar de todo lo demás. Y si alguien los tomara por reales, estaría
 * viendo la facturación del negocio sin haberse autenticado.
 */
$capacidades = [
    ['cart',   'Punto de venta',        'NCF, ticket térmico y caja con cierre'],
    ['layers', 'Inventario y costos',   'Existencias y costo real por sucursal'],
    ['id',     'Nómina dominicana',     'AFP, SFS e ISR calculados solos'],
    ['chart',  'Reportes de dirección', 'Comparativos, márgenes y formatos DGII'],
];
?>
<!doctype html>
<html lang="es" class="h-full">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Iniciar sesión · <?= e(APP_NAME) ?></title>
<link rel="icon" href="<?= e(asset('favicon.svg')) ?>" type="image/svg+xml">
<meta name="theme-color" content="<?= e(marca_app(950)) ?>">
<meta name="robots" content="noindex, nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = { theme: { extend: {
  fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'] },
  colors: { blue: <?= marca_app_tailwind() ?>, brand: <?= marca_app_tailwind() ?> },
  keyframes: {
    entrar: { '0%': { opacity: 0, transform: 'translateY(14px)' }, '100%': { opacity: 1, transform: 'translateY(0)' } },
    brillo: { '0%,100%': { opacity: .32 }, '50%': { opacity: .55 } },
  },
  animation: {
    entrar: 'entrar .5s cubic-bezier(.22,1,.36,1) both',
    brillo: 'brillo 8s ease-in-out infinite',
  },
} } };
</script>
<style>
  [x-cloak]{display:none!important}
  body{font-family:'Inter',sans-serif}

  /* Alto de la ventana SIN la barra del navegador móvil.
     `100vh` en un teléfono cuenta la barra de direcciones que se retrae, así
     que la pantalla queda ~60 px más alta de lo que se ve y aparece un scroll
     que no lleva a ninguna parte. `dvh` mide lo visible de verdad; la línea de
     `vh` que va antes es el respaldo para navegadores que no lo soportan. */
  .alto-pantalla{ min-height:100vh; min-height:100dvh }
  /* Las dos columnas llenan la ventana, pero NO se les fija la altura: con
     `height` fija, la fila de la rejilla se sigue midiendo por el contenido y
     la página acababa con un scroll de sobra encima de dos columnas que ya
     scrolleaban por dentro. Con `min-height` hay un solo scroll, el de la
     página, que es lo que espera cualquiera en una pantalla de acceso. */
  @media (min-width:1024px){ .lg-alto-min{ min-height:100vh; min-height:100dvh } }

  /* Trama sutil del panel de marca: da profundidad sin cargar la vista. */
  .trama{
    background-image:
      linear-gradient(rgba(255,255,255,.055) 1px, transparent 1px),
      linear-gradient(90deg, rgba(255,255,255,.055) 1px, transparent 1px);
    background-size: 46px 46px;
    -webkit-mask-image: radial-gradient(ellipse 80% 60% at 50% 40%, #000 40%, transparent 100%);
            mask-image: radial-gradient(ellipse 80% 60% at 50% 40%, #000 40%, transparent 100%);
  }

  .campo{
    width:100%; border-radius:.85rem; border:1px solid #e2e8f0; background:#fff;
    padding:.8rem 1rem; color:#334155; outline:none;
    transition:border-color .18s ease, box-shadow .18s ease;
    /* 16 px exactos: por debajo de eso iOS hace zoom al enfocar el campo y
       descuadra la pantalla. Del sm hacia arriba ya se puede afinar. */
    font-size:16px;
  }
  @media (min-width:640px){ .campo{ font-size:.925rem } }
  .campo::placeholder{color:#cbd5e1}
  .campo:focus{ border-color:<?= e(marca_app(500)) ?>; box-shadow:0 0 0 4px <?= e(marca_app(500)) ?>1f }

  /* Área de toque cómoda con el dedo (44 px es el mínimo habitual).
     El ojo de la contraseña se agranda solo en móvil: con el ratón 34 px se
     aciertan de sobra, pero con el pulgar no, y fallarlo abre el teclado en
     lugar de mostrar la clave. Cabe dentro del `pr-12` del campo. */
  .ojo{ display:flex; align-items:center; justify-content:center }
  @media (max-width:639px){
    .toque{ min-height:44px }
    .ojo  { width:44px; height:44px }
  }

  @media (prefers-reduced-motion: reduce){ *{animation:none!important; transition:none!important} }
</style>
</head>
<body class="h-full bg-slate-50 text-slate-700">
<div class="alto-pantalla lg:grid lg:grid-cols-2 xl:grid-cols-[1.06fr_1fr]">

  <!-- ============ Panel de marca (desde lg) ============ -->
  <aside class="relative hidden lg:flex lg-alto-min flex-col justify-between overflow-hidden
                p-8 xl:p-14 text-white
                bg-gradient-to-br from-slate-900 via-blue-950 to-slate-900">
    <div class="absolute inset-0 trama pointer-events-none"></div>
    <div class="absolute -top-32 -right-28 w-[26rem] h-[26rem] bg-blue-500/25 rounded-full blur-3xl animate-brillo pointer-events-none"></div>
    <div class="absolute -bottom-40 -left-24 w-[28rem] h-[28rem] bg-indigo-500/20 rounded-full blur-3xl animate-brillo pointer-events-none" style="animation-delay:2.5s"></div>

    <!-- Marca. El logotipo YA dice el nombre: repetirlo al lado y otra vez
         debajo en versalitas era lo que lo hacía leerse tres veces seguidas. -->
    <div class="relative animate-entrar">
      <?php if ($tieneLogo): ?>
        <span class="inline-flex items-center rounded-2xl bg-white px-4 py-2.5 shadow-lg shadow-blue-950/30">
          <img src="<?= e(url($logo)) ?>" alt="<?= e($empresa) ?>" class="h-7 max-w-[190px] object-contain">
        </span>
      <?php else: ?>
        <span class="inline-flex items-center gap-3">
          <span class="w-11 h-11 rounded-2xl bg-white text-blue-700 flex items-center justify-center font-extrabold text-xl shadow-lg shadow-blue-950/40">N</span>
          <span class="text-xl font-extrabold tracking-tight"><?= e(APP_NAME) ?></span>
        </span>
      <?php endif; ?>
    </div>

    <!-- Mensaje -->
    <div class="relative animate-entrar py-6 xl:py-8" style="animation-delay:.08s">
      <span class="inline-flex items-center gap-2 rounded-full bg-white/10 backdrop-blur px-3.5 py-1.5 text-[12.5px] font-semibold text-blue-100 ring-1 ring-white/15">
        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
        Multi-sucursal · Facturación con NCF
      </span>

      <h1 class="mt-6 text-[2.15rem] xl:text-[2.75rem] font-extrabold leading-[1.1] tracking-tight">
        Todo tu negocio,<br>
        <span class="bg-gradient-to-r from-blue-300 to-cyan-200 bg-clip-text text-transparent">en un solo tablero.</span>
      </h1>
      <p class="mt-5 text-blue-100/75 text-[15px] leading-relaxed max-w-md">
        Punto de venta, inventario por sucursal, nómina dominicana, CRM y reportes para
        dirección, finanzas y contabilidad. Sin hojas de cálculo sueltas.
      </p>

      <!-- Qué trae el sistema. Capacidades, nunca cifras del negocio. -->
      <ul class="mt-7 xl:mt-8 grid grid-cols-2 gap-2.5 xl:gap-3 max-w-xl">
        <?php foreach ($capacidades as [$ico, $titulo, $detalle]): ?>
          <li class="flex items-start gap-3 rounded-xl bg-white/[.06] ring-1 ring-white/10 px-3.5 py-3">
            <span class="w-8 h-8 rounded-lg bg-white/10 text-blue-200 flex items-center justify-center shrink-0">
              <?= icon($ico, 'w-4 h-4') ?>
            </span>
            <span class="min-w-0">
              <span class="block text-[13.5px] font-semibold leading-tight"><?= e($titulo) ?></span>
              <span class="block text-[12px] text-blue-100/60 leading-snug mt-0.5"><?= e($detalle) ?></span>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <!-- Pie -->
    <div class="relative flex flex-wrap items-center gap-x-5 gap-y-2 text-[12.5px] text-blue-200/60 animate-entrar" style="animation-delay:.16s">
      <span>© <?= date('Y') ?> <?= e(APP_NAME) ?></span>
      <span class="w-1 h-1 rounded-full bg-blue-200/30"></span>
      <span class="inline-flex items-center gap-1.5">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Datos cifrados en tránsito
      </span>
    </div>
  </aside>

  <!-- ============ Columna del formulario ============ -->
  <main class="flex flex-col lg-alto-min bg-slate-50 lg:bg-white" x-data="acceso()">

    <!-- Cabecera de marca en móvil y tablet.
         Debajo de lg el panel de la izquierda no cabe, pero dejar la pantalla
         en blanco con el logotipo suelto la volvía anónima. Esta franja usa el
         mismo degradado, así el sistema se ve igual en el teléfono del cajero
         que en la laptop de la oficina. -->
    <div class="lg:hidden relative overflow-hidden px-5 sm:px-8 pt-8 pb-7 text-white
                bg-gradient-to-br from-slate-900 via-blue-950 to-slate-900">
      <div class="absolute inset-0 trama pointer-events-none"></div>
      <div class="absolute -top-24 -right-16 w-64 h-64 bg-blue-500/25 rounded-full blur-3xl pointer-events-none"></div>
      <div class="relative flex items-center gap-3">
        <?php if ($tieneLogo): ?>
          <span class="inline-flex items-center rounded-xl bg-white px-3 py-2 shadow-lg shadow-blue-950/30">
            <img src="<?= e(url($logo)) ?>" alt="<?= e($empresa) ?>" class="h-6 max-w-[150px] object-contain">
          </span>
        <?php else: ?>
          <span class="w-10 h-10 rounded-xl bg-white text-blue-700 flex items-center justify-center font-extrabold text-lg shrink-0">N</span>
          <span class="text-lg font-extrabold tracking-tight"><?= e(APP_NAME) ?></span>
        <?php endif; ?>
      </div>
      <p class="relative mt-3.5 text-[12.5px] font-semibold text-blue-100/80 inline-flex items-center gap-2">
        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
        Multi-sucursal · Facturación con NCF
      </p>
    </div>

    <div class="flex-1 flex items-center justify-center px-5 py-9 sm:px-8 lg:px-10 xl:px-14">
      <div class="w-full max-w-[26rem] animate-entrar">

        <h2 class="text-[1.6rem] sm:text-[1.75rem] font-extrabold text-slate-800 tracking-tight">Bienvenido de nuevo</h2>
        <p class="text-slate-500 mt-1.5 text-[14.5px] leading-relaxed">
          Entra a <span class="font-semibold text-slate-700"><?= e($empresa) ?></span> para continuar.
        </p>

        <?php if ($error): ?>
          <div role="alert" class="mt-6 flex items-start gap-3 rounded-xl bg-rose-50 border border-rose-200 px-4 py-3.5">
            <svg class="w-5 h-5 shrink-0 text-rose-500 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4M12 17h.01"/></svg>
            <p class="text-sm text-rose-700 font-medium leading-relaxed"><?= e($error) ?></p>
          </div>
        <?php endif; ?>

        <form method="post" class="mt-7 space-y-4" @submit="enviando = true">
          <?= csrf_field() ?>

          <div>
            <label for="usuario" class="block text-[13.5px] font-semibold text-slate-600 mb-1.5">Usuario o correo</label>
            <div class="relative">
              <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              </span>
              <input type="text" id="usuario" name="usuario" x-model="usuario" required autofocus
                     autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false"
                     class="campo toque pl-11" placeholder="tu usuario">
            </div>
          </div>

          <div>
            <div class="flex items-baseline justify-between gap-3 mb-1.5">
              <label for="password" class="block text-[13.5px] font-semibold text-slate-600">Contraseña</label>
              <span x-show="mayusculas" x-cloak role="status" class="text-[11.5px] font-bold text-amber-600">Bloq Mayús activado</span>
            </div>
            <div class="relative">
              <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              </span>
              <input :type="ver ? 'text' : 'password'" id="password" name="password" x-model="password" required
                     autocomplete="current-password" @keyup="detectarMayusculas($event)" @keydown="detectarMayusculas($event)"
                     class="campo toque pl-11 pr-12" placeholder="••••••••">
              <button type="button" @click="ver = !ver" tabindex="-1"
                      :aria-label="ver ? 'Ocultar contraseña' : 'Mostrar contraseña'" :aria-pressed="ver.toString()"
                      class="ojo absolute right-1 sm:right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 transition p-2 rounded-lg
                             focus:outline-none focus-visible:ring-4 focus-visible:ring-blue-500/20">
                <svg x-show="!ver" class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg x-show="ver" x-cloak class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M10.6 10.6a3 3 0 0 0 4.2 4.2"/><path d="M17.9 17.9A10.4 10.4 0 0 1 12 19c-7 0-10-7-10-7a18.5 18.5 0 0 1 5.1-6M9.9 5.2A10.4 10.4 0 0 1 12 5c7 0 10 7 10 7a18.6 18.6 0 0 1-2.2 3.2"/><path d="m2 2 20 20"/></svg>
              </button>
            </div>
          </div>

          <label class="flex items-center gap-2.5 text-[13.5px] text-slate-600 select-none cursor-pointer py-1">
            <input type="checkbox" x-model="recordar" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500 w-4 h-4">
            Recordar mi usuario en este equipo
          </label>

          <button type="submit" :disabled="enviando" :aria-busy="enviando.toString()"
                  class="w-full flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 active:bg-blue-800
                         disabled:opacity-70 disabled:cursor-wait text-white font-semibold px-5 py-3.5 rounded-xl
                         transition shadow-lg shadow-blue-600/25 focus:outline-none focus-visible:ring-4 focus-visible:ring-blue-500/25">
            <svg x-show="enviando" x-cloak class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
              <path d="M21 12a9 9 0 1 1-6.2-8.6" stroke-linecap="round"/>
            </svg>
            <span x-text="enviando ? 'Entrando…' : 'Iniciar sesión'">Iniciar sesión</span>
          </button>
        </form>

        <?php if (otp_politica_activa() && otp_operativo()): ?>
          <p class="mt-4 flex items-start gap-2 text-[12.5px] text-slate-400 leading-relaxed">
            <svg class="w-4 h-4 shrink-0 mt-px text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
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
          © <?= date('Y') ?> <?= e(APP_NAME) ?> · Datos cifrados en tránsito
        </p>
      </div>
    </div>
  </main>
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
