<?php
/**
 * Segundo paso del inicio de sesión: el código que llegó por correo.
 *
 * Esta pantalla NO tiene sesión iniciada. Lo único que existe es el paso
 * intermedio (`$_SESSION['otp_login']`), que no concede ningún permiso: si
 * alguien llega aquí sin haber pasado la contraseña, lo devuelve al login.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

// Esta pantalla no se guarda en ninguna caché: enseña el correo del usuario y,
// con el botón «atrás» tras entrar, mostraría un formulario con un token muerto.
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
}

if (is_logged_in()) {
    redirect('modules/dashboard/index.php');
}

$pend = login_pendiente();
if (!$pend) {
    redirect('modules/auth/login.php');
}

$cfg    = otp_config();
$error  = '';
$aviso  = '';

if (isPost()) {
    verify_csrf();
    $accion = post('accion', 'verificar');

    if ($accion === 'cancelar') {
        audit('auth', 'otp_cancelado', 'Verificación cancelada por el usuario',
            ['usuario_id' => (int) $pend['usuario_id'], 'usuario_nombre' => $pend['nombre']]);
        login_pendiente_limpiar();
        redirect('modules/auth/login.php');
    }

    if ($accion === 'reenviar') {
        $r = login_reenviar_otp();
        flash($r['ok'] ? 'success' : 'error', $r['mensaje']);
        redirect('modules/auth/verificar.php');
    }

    // El código puede venir de un solo campo (pegado) o de las seis casillas.
    $codigo = trim((string) post('codigo'));
    if ($codigo === '') {
        $d = post('d');
        $codigo = is_array($d) ? implode('', array_map(static fn($x) => trim((string) $x), $d)) : '';
    }

    $r = login_confirmar_otp($codigo, (bool) post('recordar'));
    if ($r['ok']) {
        redirect('modules/dashboard/index.php');
    }
    if ($r['reiniciar']) {
        flash('error', $r['mensaje']);
        redirect('modules/auth/login.php');
    }
    $error = $r['mensaje'];

    // El paso pudo cambiar (código anulado por intentos): se relee.
    $pend = login_pendiente();
    if (!$pend) redirect('modules/auth/login.php');
}

// ---------- Estado para pintar ----------
$info      = otp_info((int) $pend['otp_id']);
$restantes = $info ? max(0, (int) $info['max_intentos'] - (int) $info['intentos']) : 0;
$segVence  = max(0, (int) $pend['expira'] - time());
$ultimo    = otp_ultimo_evento('envio', 'user:' . (int) $pend['usuario_id']);
$segReenvio = $ultimo ? max(0, OTP_REENVIO_ESPERA - (time() - $ultimo)) : 0;
$mascara   = otp_email_mascara((string) $pend['email']);
$codigoDev = $pend['codigo_dev'] ?? null;   // solo en desarrollo sin correo configurado

if (!$error && !empty($pend['aviso'])) {
    $aviso = (string) $pend['aviso'];
}
if ($info && (int) $info['enviado'] !== 1 && !$aviso && !$error) {
    $aviso = 'El correo con el código no pudo salir. Pulsa «Enviar otro código» en unos segundos.';
}

$flashes   = get_flashes();
$logo      = setting('logo');
$tieneLogo = $logo && is_file(dirname(__DIR__, 2) . '/' . $logo);
$empresa   = setting('nombre', APP_NAME);
?>
<!doctype html>
<html lang="es" class="h-full">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Verificación en dos pasos · <?= e(APP_NAME) ?></title>
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
    entrar: { '0%': { opacity: 0, transform: 'translateY(14px)' }, '100%': { opacity: 1, transform: 'translateY(0)' } },
    brillo: { '0%,100%': { opacity: .35 }, '50%': { opacity: .6 } },
    latir:  { '0%,100%': { transform: 'scale(1)' }, '50%': { transform: 'scale(1.06)' } },
  },
  animation: {
    entrar: 'entrar .5s cubic-bezier(.22,1,.36,1) both',
    brillo: 'brillo 7s ease-in-out infinite',
    latir:  'latir 2.4s ease-in-out infinite',
  },
} } };
</script>
<style>
  [x-cloak]{display:none!important}
  body{font-family:'Inter',sans-serif}
  .trama{
    background-image:
      linear-gradient(rgba(255,255,255,.055) 1px, transparent 1px),
      linear-gradient(90deg, rgba(255,255,255,.055) 1px, transparent 1px);
    background-size: 46px 46px;
    mask-image: radial-gradient(ellipse 80% 60% at 50% 40%, #000 40%, transparent 100%);
  }
  /* Casillas del código: monoespaciado y grandes, se leen de un vistazo. */
  .digito{
    width:100%; aspect-ratio:1/1.15; max-height:4rem;
    border-radius:.9rem; border:1.5px solid #e2e8f0; background:#fff;
    text-align:center; font-size:1.6rem; font-weight:700; color:#0f172a;
    font-variant-numeric:tabular-nums; outline:none; transition:all .15s ease;
    -moz-appearance:textfield;
  }
  .digito::-webkit-outer-spin-button,.digito::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
  .digito:focus{border-color:#3b82f6; box-shadow:0 0 0 4px rgba(59,130,246,.14)}
  .digito.lleno{border-color:#93c5fd; background:#f8fbff}
  .digito.malo{border-color:#fda4af; background:#fff5f6; animation:sacudir .4s}
  @keyframes sacudir{10%,90%{transform:translateX(-2px)}30%,70%{transform:translateX(3px)}50%{transform:translateX(-3px)}}
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
    <div class="absolute -bottom-40 -left-24 w-[28rem] h-[28rem] bg-emerald-500/15 rounded-full blur-3xl animate-brillo" style="animation-delay:2s"></div>

    <div class="relative flex items-center gap-3 animate-entrar">
      <?php if ($tieneLogo): ?>
        <img src="<?= e(url($logo)) ?>" alt="" class="w-11 h-11 rounded-2xl object-contain bg-white p-1">
      <?php else: ?>
        <div class="w-11 h-11 rounded-2xl bg-white text-blue-700 flex items-center justify-center font-extrabold text-xl shadow-lg shadow-blue-900/40">N</div>
      <?php endif; ?>
      <span class="text-xl font-extrabold tracking-tight"><?= e(APP_NAME) ?></span>
    </div>

    <div class="relative animate-entrar" style="animation-delay:.08s">
      <span class="inline-flex items-center gap-2 rounded-full bg-emerald-400/10 backdrop-blur px-3.5 py-1.5 text-[12.5px] font-semibold text-emerald-200 ring-1 ring-emerald-300/20">
        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-latir"></span>
        Paso 2 de 2 · Verificación de identidad
      </span>

      <h1 class="mt-6 text-[2.6rem] xl:text-5xl font-extrabold leading-[1.08] tracking-tight">
        Tu contraseña sola<br>
        <span class="bg-gradient-to-r from-emerald-300 to-cyan-200 bg-clip-text text-transparent">ya no basta.</span>
      </h1>
      <p class="mt-5 text-blue-100/75 text-[15px] leading-relaxed max-w-md">
        Aquí se registran ventas, se mueve inventario y se emiten comprobantes fiscales.
        Por eso pedimos un código que solo llega a tu correo: aunque alguien averigüe tu
        contraseña, sin ese código no entra.
      </p>

      <ul class="mt-9 space-y-3.5 max-w-md">
        <?php foreach ([
            ['El código vence en pocos minutos', 'Después deja de servir aunque alguien lo vea.'],
            ['Sirve una sola vez', 'En cuanto entras, ese código muere.'],
            ['Queda registrado quién lo pidió', 'Fecha, equipo y dirección IP van en la bitácora.'],
        ] as [$t, $d]): ?>
          <li class="flex items-start gap-3">
            <span class="w-6 h-6 rounded-lg bg-emerald-400/15 text-emerald-300 flex items-center justify-center shrink-0 mt-0.5">
              <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="m5 13 4 4L19 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </span>
            <span>
              <span class="block text-[14.5px] font-semibold text-white/90"><?= e($t) ?></span>
              <span class="block text-[13px] text-blue-100/60 mt-0.5"><?= e($d) ?></span>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <div class="relative flex items-center gap-5 text-[12.5px] text-blue-200/60 animate-entrar" style="animation-delay:.16s">
      <span>© <?= date('Y') ?> <?= e(APP_NAME) ?></span>
      <span class="w-1 h-1 rounded-full bg-blue-200/30"></span>
      <span>Nunca te pediremos este código por teléfono ni por WhatsApp.</span>
    </div>
  </div>

  <!-- ============ Formulario ============ -->
  <div class="flex items-center justify-center px-5 py-10 sm:px-10 lg:px-12 min-h-screen lg:min-h-full bg-slate-50 lg:bg-white"
       x-data="verificacion(<?= (int) $segVence ?>, <?= (int) $segReenvio ?>, <?= $error ? 'true' : 'false' ?>)">
    <div class="w-full max-w-[26rem] animate-entrar">

      <div class="lg:hidden flex items-center justify-center gap-3 mb-9">
        <?php if ($tieneLogo): ?>
          <img src="<?= e(url($logo)) ?>" alt="" class="w-11 h-11 rounded-2xl object-contain bg-white border border-slate-200 p-1">
        <?php else: ?>
          <div class="w-11 h-11 rounded-2xl bg-blue-600 text-white flex items-center justify-center font-extrabold text-xl shadow-lg shadow-blue-600/25">N</div>
        <?php endif; ?>
        <span class="text-2xl font-extrabold text-slate-800 tracking-tight"><?= e(APP_NAME) ?></span>
      </div>

      <div class="w-12 h-12 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center mb-5">
        <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
          <rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 6 10-6"/>
        </svg>
      </div>

      <h2 class="text-[1.75rem] font-extrabold text-slate-800 tracking-tight">Revisa tu correo</h2>
      <p class="text-slate-500 mt-1.5 text-[14.5px] leading-relaxed">
        Enviamos un código de <?= OTP_LONGITUD ?> dígitos a
        <span class="font-semibold text-slate-700"><?= e($mascara) ?></span>
        para entrar a <span class="font-semibold text-slate-700"><?= e($empresa) ?></span>.
      </p>

      <?php foreach ($flashes as $f): ?>
        <?php $col = $f['tipo'] === 'success' ? ['bg-emerald-50', 'border-emerald-200', 'text-emerald-700'] : ['bg-rose-50', 'border-rose-200', 'text-rose-700']; ?>
        <div role="status" class="mt-5 rounded-xl <?= $col[0] ?> border <?= $col[1] ?> px-4 py-3">
          <p class="text-sm <?= $col[2] ?> font-medium leading-relaxed"><?= e($f['mensaje']) ?></p>
        </div>
      <?php endforeach; ?>

      <?php if ($error): ?>
        <div role="alert" class="mt-5 flex items-start gap-3 rounded-xl bg-rose-50 border border-rose-200 px-4 py-3.5">
          <svg class="w-5 h-5 shrink-0 text-rose-500 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
          <p class="text-sm text-rose-700 font-medium leading-relaxed"><?= e($error) ?></p>
        </div>
      <?php elseif ($aviso): ?>
        <div role="alert" class="mt-5 flex items-start gap-3 rounded-xl bg-amber-50 border border-amber-200 px-4 py-3.5">
          <svg class="w-5 h-5 shrink-0 text-amber-500 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4M12 17h.01"/></svg>
          <p class="text-sm text-amber-700 font-medium leading-relaxed"><?= e($aviso) ?></p>
        </div>
      <?php endif; ?>

      <?php if ($codigoDev !== null): ?>
        <div class="mt-5 rounded-xl bg-violet-50 border border-violet-200 px-4 py-3.5">
          <p class="text-[11px] font-bold uppercase tracking-wider text-violet-500">Modo desarrollo · sin correo configurado</p>
          <p class="text-2xl font-extrabold text-violet-700 tracking-[.35em] mt-1"><?= e($codigoDev) ?></p>
          <p class="text-[11.5px] text-violet-500 mt-1">Esto jamás se muestra en producción ni con Resend configurado.</p>
        </div>
      <?php endif; ?>

      <form method="post" class="mt-7" x-ref="form" @submit="enviando = true">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="verificar">
        <input type="hidden" name="codigo" x-model="codigo">

        <fieldset>
          <legend class="block text-[13.5px] font-semibold text-slate-600 mb-2">
            Código de verificación
          </legend>
          <div class="grid grid-cols-6 gap-2" @paste.prevent="pegar($event)">
            <?php for ($i = 0; $i < OTP_LONGITUD; $i++): ?>
              <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" name="d[]"
                     aria-label="Dígito <?= $i + 1 ?>"
                     autocomplete="<?= $i === 0 ? 'one-time-code' : 'off' ?>"
                     <?= $i === 0 ? 'autofocus' : '' ?>
                     x-ref="d<?= $i ?>"
                     class="digito"
                     :class="{ 'lleno': valores[<?= $i ?>] !== '', 'malo': fallo }"
                     x-model="valores[<?= $i ?>]"
                     @input="escribir(<?= $i ?>, $event)"
                     @keydown="teclas(<?= $i ?>, $event)"
                     @focus="$event.target.select()">
            <?php endfor; ?>
          </div>
        </fieldset>

        <div class="flex items-center justify-between gap-3 mt-3 text-[12.5px]">
          <p class="text-slate-400" x-show="restante > 0" x-cloak>
            Vence en <span class="font-semibold text-slate-600 tabular-nums" x-text="reloj"></span>
          </p>
          <p class="text-rose-600 font-semibold" x-show="restante <= 0" x-cloak>El código venció. Pide uno nuevo.</p>
          <?php if ($restantes > 0 && $restantes < OTP_MAX_INTENTOS): ?>
            <p class="text-amber-600 font-semibold shrink-0">
              <?= (int) $restantes ?> intento<?= $restantes === 1 ? '' : 's' ?> restante<?= $restantes === 1 ? '' : 's' ?>
            </p>
          <?php endif; ?>
        </div>

        <?php if ($cfg['permite_recordar']): ?>
          <label class="flex items-start gap-2.5 mt-5 text-[13.5px] text-slate-600 select-none cursor-pointer">
            <input type="checkbox" name="recordar" value="1" checked
                   class="rounded border-slate-300 text-blue-600 focus:ring-blue-500 w-4 h-4 mt-0.5">
            <span>
              No volver a pedirme el código en este equipo durante <?= (int) $cfg['recordar_dias'] ?> días.
              <span class="block text-slate-400 text-[12px] mt-0.5">Deja esto sin marcar si el equipo es compartido o prestado.</span>
            </span>
          </label>
        <?php endif; ?>

        <button type="submit" :disabled="enviando || codigo.length < <?= OTP_LONGITUD ?>"
                class="w-full flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 active:bg-blue-800
                       disabled:opacity-50 disabled:cursor-not-allowed text-white font-semibold px-5 py-3.5 rounded-xl
                       transition shadow-lg shadow-blue-600/25 mt-6 focus:outline-none focus-visible:ring-4 focus-visible:ring-blue-500/25">
          <svg x-show="enviando" x-cloak class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <path d="M21 12a9 9 0 1 1-6.2-8.6" stroke-linecap="round"/>
          </svg>
          <span x-text="enviando ? 'Verificando…' : 'Verificar y entrar'">Verificar y entrar</span>
        </button>
      </form>

      <div class="mt-6 pt-5 border-t border-slate-100 space-y-3">
        <form method="post" class="flex items-center justify-between gap-3">
          <?= csrf_field() ?>
          <input type="hidden" name="accion" value="reenviar">
          <p class="text-[13px] text-slate-500">¿No te llegó? Mira también en «Spam».</p>
          <button type="submit" :disabled="espera > 0"
                  class="shrink-0 text-[13px] font-semibold text-blue-600 hover:text-blue-700 disabled:text-slate-400 disabled:cursor-not-allowed">
            <span x-show="espera <= 0">Enviar otro código</span>
            <span x-show="espera > 0" x-cloak x-text="'Reenviar en ' + espera + 's'"></span>
          </button>
        </form>

        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="accion" value="cancelar">
          <button type="submit" class="text-[13px] font-medium text-slate-400 hover:text-slate-600 inline-flex items-center gap-1.5">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m12 19-7-7 7-7M19 12H5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Cancelar y usar otra cuenta
          </button>
        </form>
      </div>

      <p class="text-[11.5px] text-slate-400 mt-7 leading-relaxed">
        Si no intentaste entrar, alguien conoce tu contraseña: no uses el código y cámbiala
        en cuanto puedas.
      </p>
    </div>
  </div>
</div>

<script>
function verificacion(segundosVence, segundosReenvio, huboError) {
  return {
    valores: Array(<?= OTP_LONGITUD ?>).fill(''),
    codigo: '',
    enviando: false,
    fallo: huboError,
    restante: segundosVence,
    espera: segundosReenvio,
    reloj: '',

    init() {
      this.pintarReloj();
      setInterval(() => {
        if (this.restante > 0) { this.restante--; this.pintarReloj(); }
        if (this.espera > 0) this.espera--;
      }, 1000);

      // Al reintentar tras un error, las casillas se vacían y el foco vuelve al
      // principio: nadie quiere borrar seis dígitos a mano.
      if (huboError) this.$nextTick(() => this.$refs.d0?.focus());

      this.$watch('valores', () => {
        this.codigo = this.valores.join('');
        if (this.fallo) this.fallo = false;
      }, { deep: true });

      // Android y iOS ofrecen el código del SMS/correo: si el navegador lo
      // autocompleta de golpe en la primera casilla, se reparte solo.
      if ('OTPCredential' in window) {
        try {
          navigator.credentials.get({ otp: { transport: ['sms'] } })
            .then(o => { if (o && o.code) this.repartir(o.code); })
            .catch(() => {});
        } catch (e) {}
      }
    },

    pintarReloj() {
      var s = Math.max(0, this.restante);
      this.reloj = Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
    },

    repartir(texto) {
      var d = (texto || '').replace(/\D/g, '').slice(0, <?= OTP_LONGITUD ?>).split('');
      for (var i = 0; i < <?= OTP_LONGITUD ?>; i++) this.valores[i] = d[i] || '';
      this.codigo = this.valores.join('');
      var ultimo = Math.min(d.length, <?= OTP_LONGITUD - 1 ?>);
      this.$nextTick(() => {
        this.$refs['d' + ultimo]?.focus();
        if (this.codigo.length === <?= OTP_LONGITUD ?>) this.enviar();
      });
    },

    pegar(e) {
      this.repartir((e.clipboardData || window.clipboardData).getData('text'));
    },

    escribir(i, e) {
      var v = (e.target.value || '').replace(/\D/g, '');
      if (v.length > 1) { this.repartir(v); return; }   // teclado que suelta todo junto
      this.valores[i] = v;
      this.codigo = this.valores.join('');
      if (v && i < <?= OTP_LONGITUD - 1 ?>) this.$refs['d' + (i + 1)]?.focus();
      if (this.codigo.length === <?= OTP_LONGITUD ?>) this.enviar();
    },

    teclas(i, e) {
      if (e.key === 'Backspace' && !this.valores[i] && i > 0) {
        e.preventDefault();
        this.valores[i - 1] = '';
        this.$refs['d' + (i - 1)]?.focus();
      }
      if (e.key === 'ArrowLeft'  && i > 0) this.$refs['d' + (i - 1)]?.focus();
      if (e.key === 'ArrowRight' && i < <?= OTP_LONGITUD - 1 ?>) this.$refs['d' + (i + 1)]?.focus();
    },

    // Envío automático al completar: un dígito de más no debe costar un clic.
    enviar() {
      if (this.enviando || this.restante <= 0) return;
      this.enviando = true;
      this.$nextTick(() => this.$refs.form.submit());
    },
  };
}
</script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.10/dist/cdn.min.js"></script>
</body>
</html>
