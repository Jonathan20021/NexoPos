<?php
/**
 * Seguridad de acceso: política de verificación en dos pasos, estado por
 * usuario, equipos de confianza y cuentas bloqueadas por fuerza bruta.
 *
 * Ver es `configuracion.ver`; cambiar cualquier cosa exige `seguridad.gestionar`,
 * que se otorga aparte: quien puede eximir a un usuario del segundo factor puede
 * debilitar el sistema entero.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('configuracion.ver');

$instalado = otp_disponible();

if (isPost()) {
    verify_csrf();
    require_perm('seguridad.gestionar');
    $accion = post('accion');

    if (!$instalado) {
        flash('error', 'Falta aplicar la migración migracion_otp_login_p14.sql.');
        redirect('modules/admin/seguridad.php');
    }

    if ($accion === 'politica') {
        $modo = (string) post('otp_modo');
        if (!in_array($modo, ['siempre', 'dispositivo_nuevo', 'nunca'], true)) {
            flash('error', 'Modo de verificación no válido.');
            redirect('modules/admin/seguridad.php');
        }
        $vigencia = max(2, min(60, postInt('otp_vigencia_min', 10)));
        $dias     = max(0, min(365, postInt('otp_recordar_dias', 30)));

        $antes = [
            'otp_modo'          => setting('otp_modo', 'siempre'),
            'otp_vigencia_min'  => (int) setting('otp_vigencia_min', 10),
            'otp_recordar_dias' => (int) setting('otp_recordar_dias', 30),
        ];
        $despues = ['otp_modo' => $modo, 'otp_vigencia_min' => $vigencia, 'otp_recordar_dias' => $dias];
        dbUpdate('empresa', $despues, 'id = ?', [1]);

        // Bajar el modo a «nunca» deja de exigir código: es la clase de cambio que
        // hay que poder rastrear después, con quién y cuándo.
        audit('seguridad', 'politica', 'Política de verificación en dos pasos: ' . $modo,
            ['tabla' => 'empresa', 'registro_id' => 1, 'antes' => $antes, 'despues' => $despues]);
        flash('success', 'Política de acceso actualizada.');
        redirect('modules/admin/seguridad.php');
    }

    if ($accion === 'usuario_otp') {
        $id     = postInt('id');
        $activo = postInt('valor') === 1 ? 1 : 0;
        $u = qOne("SELECT id, usuario, nombre, apellido FROM usuarios WHERE id = ?", [$id]);
        if (!$u) {
            flash('error', 'Ese usuario no existe.');
        } else {
            dbUpdate('usuarios', ['otp_activo' => $activo], 'id = ?', [$id]);
            if (!$activo) otp_anular_vivos($id, 'login', 'exento');
            audit('seguridad', 'usuario_otp',
                ($activo ? 'Activó' : 'Eximió de') . ' la verificación en dos pasos a ' . $u['usuario'],
                ['tabla' => 'usuarios', 'registro_id' => $id]);
            flash('success', $activo
                ? 'La cuenta «' . $u['usuario'] . '» pedirá código al entrar.'
                : 'La cuenta «' . $u['usuario'] . '» quedó exenta del código.');
        }
        redirect('modules/admin/seguridad.php');
    }

    if ($accion === 'revocar_equipos') {
        $id = postInt('id');
        $u  = qOne("SELECT usuario FROM usuarios WHERE id = ?", [$id]);
        $n  = $u ? otp_revocar_todos($id) : 0;
        audit('seguridad', 'dispositivos', "Retiró $n equipo(s) de confianza de " . ($u['usuario'] ?? '?'),
            ['tabla' => 'login_dispositivos', 'registro_id' => $id]);
        flash($n > 0 ? 'success' : 'info', $n > 0
            ? "Se retiraron $n equipo" . ($n === 1 ? '' : 's') . ' de confianza.'
            : 'Ese usuario no tenía equipos de confianza.');
        redirect('modules/admin/seguridad.php');
    }

    if ($accion === 'desbloquear') {
        // Se borran los fallos de la ventana: es lo que levanta el bloqueo.
        $clave = trim((string) post('clave'));
        if ($clave === '') {
            flash('error', 'No se indicó qué desbloquear.');
        } else {
            $n = q("DELETE FROM login_intentos WHERE tipo = 'password' AND clave = ? AND exito = 0", [$clave])->rowCount();
            audit('seguridad', 'desbloquear', "Desbloqueó el acceso de «$clave» ($n intentos borrados)");
            flash('success', 'Acceso desbloqueado. Ya puede intentar de nuevo.');
        }
        redirect('modules/admin/seguridad.php');
    }
}

// ---------------- Datos ----------------
$cfg       = otp_config();
$operativo = otp_operativo();
$apagadoPorConfig = defined('OTP_DESACTIVADO') && OTP_DESACTIVADO;
$puedeGestionar   = can('seguridad.gestionar');

$usuarios = $instalado ? qAll(
    "SELECT u.id, u.nombre, u.apellido, u.usuario, u.email, u.activo, u.ultimo_acceso,
            COALESCE(u.otp_activo, 1) AS otp_activo, r.nombre AS rol_nombre,
            (SELECT COUNT(*) FROM login_dispositivos d
              WHERE d.usuario_id = u.id AND d.revocado_en IS NULL AND d.expira_en > NOW()) AS equipos
       FROM usuarios u JOIN roles r ON r.id = u.rol_id
      ORDER BY u.activo DESC, u.nombre, u.apellido"
) : [];

$kpi = ['con2fa' => 0, 'sin2fa' => 0, 'equipos' => 0, 'codigos' => 0, 'fallos' => 0];
if ($instalado) {
    foreach ($usuarios as $u) {
        if ((int) $u['activo'] !== 1) continue;
        (int) $u['otp_activo'] === 1 ? $kpi['con2fa']++ : $kpi['sin2fa']++;
    }
    $kpi['equipos'] = (int) qVal("SELECT COUNT(*) FROM login_dispositivos WHERE revocado_en IS NULL AND expira_en > NOW()");
    $kpi['codigos'] = (int) qVal("SELECT COUNT(*) FROM login_otp WHERE created_at >= NOW() - INTERVAL 1 DAY");
    $kpi['fallos']  = (int) qVal("SELECT COUNT(*) FROM login_intentos
                                   WHERE tipo = 'password' AND exito = 0 AND clave LIKE 'login:%'
                                     AND created_at >= NOW() - INTERVAL 1 DAY");
}

// Cuentas e IP con el contador disparado ahora mismo.
$bloqueos = [];
if ($instalado) {
    $candidatos = qAll(
        "SELECT clave, COUNT(*) AS fallos, MAX(created_at) AS ultimo
           FROM login_intentos
          WHERE tipo = 'password' AND exito = 0 AND created_at >= NOW() - INTERVAL ? MINUTE
          GROUP BY clave HAVING fallos >= 3 ORDER BY ultimo DESC LIMIT 20",
        [LOGIN_VENTANA_MIN]
    );
    foreach ($candidatos as $c) {
        $esIp  = str_starts_with((string) $c['clave'], 'ip:');
        $tope  = $esIp ? LOGIN_MAX_FALLOS_IP : LOGIN_MAX_FALLOS_CUENTA;
        $reales = otp_fallos('password', (string) $c['clave']);
        if ($reales === 0) continue;
        $bloqueos[] = [
            'clave'     => (string) $c['clave'],
            'etiqueta'  => $esIp ? substr((string) $c['clave'], 3) : substr((string) $c['clave'], 6),
            'es_ip'     => $esIp,
            'fallos'    => $reales,
            'tope'      => $tope,
            'bloqueado' => $reales >= $tope,
            'ultimo'    => (string) $c['ultimo'],
        ];
    }
}

// Últimos códigos emitidos.
$codigos = $instalado ? qAll(
    "SELECT o.id, o.destino, o.enviado, o.error_envio, o.intentos, o.usado_en, o.anulado_en,
            o.expira_en, o.ip, o.created_at, u.usuario
       FROM login_otp o JOIN usuarios u ON u.id = o.usuario_id
      ORDER BY o.id DESC LIMIT 12") : [];

layout_start('Seguridad de acceso',
    'Verificación en dos pasos, equipos de confianza y defensa contra fuerza bruta');
?>

<?php if (!$instalado): ?>
  <div class="card p-6 border-amber-200 bg-amber-50 mb-5">
    <div class="flex items-start gap-4">
      <span class="w-11 h-11 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center shrink-0"><?= icon('alert', 'w-5 h-5') ?></span>
      <div>
        <h3 class="font-bold text-amber-900">Falta instalar el módulo</h3>
        <p class="text-sm text-amber-800 mt-1 leading-relaxed">
          Aplica <span class="font-mono">database/migracion_otp_login_p14.sql</span> sobre la base de datos.
          Mientras tanto el sistema entra solo con usuario y contraseña, exactamente como antes.
        </p>
      </div>
    </div>
  </div>
<?php else: ?>

  <?php if ($apagadoPorConfig): ?>
    <div class="card p-5 border-rose-200 bg-rose-50 mb-5 flex items-start gap-4">
      <span class="w-10 h-10 rounded-xl bg-rose-100 text-rose-600 flex items-center justify-center shrink-0"><?= icon('alert', 'w-5 h-5') ?></span>
      <div>
        <h3 class="font-bold text-rose-900">Verificación apagada desde el servidor</h3>
        <p class="text-sm text-rose-800 mt-1 leading-relaxed">
          <span class="font-mono">config.local.php</span> define <span class="font-mono">OTP_DESACTIVADO</span>.
          Es la llave de emergencia y manda sobre todo lo de esta pantalla: nadie recibirá código
          hasta quitarla.
        </p>
      </div>
    </div>
  <?php elseif (!$operativo && $cfg['modo'] !== 'nunca'): ?>
    <div class="card p-5 border-rose-200 bg-rose-50 mb-5 flex items-start gap-4">
      <span class="w-10 h-10 rounded-xl bg-rose-100 text-rose-600 flex items-center justify-center shrink-0"><?= icon('alert', 'w-5 h-5') ?></span>
      <div>
        <h3 class="font-bold text-rose-900">La política está encendida pero NO se está aplicando</h3>
        <p class="text-sm text-rose-800 mt-1 leading-relaxed">
          No hay correo saliente configurado (<span class="font-mono">RESEND_API_KEY</span> en
          <span class="font-mono">config/config.local.php</span>), así que no hay forma de entregar
          los códigos. Se deja entrar solo con contraseña para no dejar al negocio fuera de su
          sistema. Configura Resend y la verificación empieza a exigirse sola.
        </p>
      </div>
    </div>
  <?php endif; ?>

  <?php
  echo kpis([
      ['label' => 'Cuentas protegidas', 'valor' => number_format($kpi['con2fa']), 'icono' => 'shield',
       'color' => 'emerald', 'nota' => 'Con verificación en dos pasos'],
      ['label' => 'Cuentas exentas', 'valor' => number_format($kpi['sin2fa']), 'icono' => 'alert',
       'color' => $kpi['sin2fa'] > 0 ? 'amber' : 'slate', 'nota' => 'Entran solo con contraseña'],
      ['label' => 'Equipos de confianza', 'valor' => number_format($kpi['equipos']), 'icono' => 'lock',
       'color' => 'blue', 'nota' => 'No piden código'],
      // Diez fallos en 24 horas ya no son dedos torpes: o alguien olvidó su
      // contraseña y nadie le ayudó, o están probando a entrar.
      ['label' => 'Fallos de contraseña', 'valor' => number_format($kpi['fallos']), 'icono' => 'x',
       'color' => $kpi['fallos'] > 10 ? 'rose' : 'slate',
       'nota' => $kpi['fallos'] > 10
          ? 'Demasiados en 24 horas: conviene mirar quién'
          : 'En las últimas 24 horas'],
  ], 4);
  ?>

  <div class="grid grid-cols-1 xl:grid-cols-5 gap-5">

    <!-- Política -->
    <div class="xl:col-span-2">
      <div class="card p-6 h-full">
        <h3 class="font-bold text-slate-800">Política de verificación</h3>
        <p class="text-sm text-slate-500 mt-1 leading-relaxed">
          Decide cuándo se pide el código de <?= OTP_LONGITUD ?> dígitos que llega por correo.
        </p>

        <form method="post" class="mt-5 space-y-4">
          <?= csrf_field() ?>
          <input type="hidden" name="accion" value="politica">

          <!--
            El estado seleccionado se pinta con `peer-checked:` y no con Alpine:
            así la tarjeta marcada se ve bien aunque el JavaScript no cargue.
          -->
          <div class="space-y-2.5">
            <?php foreach ([
                ['siempre', 'En cada inicio de sesión', 'Lo más seguro. Un equipo robado no sirve sin el correo.'],
                ['dispositivo_nuevo', 'Solo en equipos nuevos', 'El personal marca su caja como de confianza y no repite el código a diario.'],
                ['nunca', 'Nunca (desactivado)', 'Se entra solo con usuario y contraseña. No recomendado.'],
            ] as [$valor, $titulo, $desc]): ?>
              <label class="relative block <?= $puedeGestionar ? 'cursor-pointer' : 'cursor-not-allowed' ?>">
                <input type="radio" name="otp_modo" value="<?= $valor ?>" class="peer sr-only"
                       <?= $cfg['modo'] === $valor ? 'checked' : '' ?> <?= $puedeGestionar ? '' : 'disabled' ?>>
                <!-- Marca de seleccionado: hermana del input, que es lo que exige `peer-checked:`. -->
                <span class="absolute right-3.5 top-3.5 w-5 h-5 rounded-full bg-blue-600 text-white
                             hidden peer-checked:flex items-center justify-center z-10">
                  <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5"><path d="m5 13 4 4L19 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
                <span class="flex items-start gap-3 rounded-xl border border-slate-200 px-4 py-3 pr-11 transition
                             hover:border-slate-300 peer-checked:border-blue-400 peer-checked:bg-blue-50/60
                             peer-focus-visible:ring-4 peer-focus-visible:ring-blue-500/20">
                  <span class="min-w-0">
                    <span class="block text-[14.5px] font-semibold text-slate-700"><?= e($titulo) ?></span>
                    <span class="block text-[12.5px] text-slate-500 mt-0.5 leading-relaxed"><?= e($desc) ?></span>
                  </span>
                </span>
              </label>
            <?php endforeach; ?>
          </div>

          <div class="grid grid-cols-2 gap-3 pt-1">
            <div>
              <label class="label" for="sg_vig">El código vence en</label>
              <div class="flex items-center gap-2">
                <input type="number" id="sg_vig" name="otp_vigencia_min" min="2" max="60" class="input"
                       value="<?= (int) $cfg['vigencia_min'] ?>" <?= $puedeGestionar ? '' : 'disabled' ?>>
                <span class="text-sm text-slate-400 shrink-0">min</span>
              </div>
            </div>
            <div>
              <label class="label" for="sg_dias">Equipo de confianza</label>
              <div class="flex items-center gap-2">
                <input type="number" id="sg_dias" name="otp_recordar_dias" min="0" max="365" class="input"
                       value="<?= (int) $cfg['recordar_dias'] ?>" <?= $puedeGestionar ? '' : 'disabled' ?>>
                <span class="text-sm text-slate-400 shrink-0">días</span>
              </div>
            </div>
          </div>
          <p class="text-[11.5px] text-slate-400 leading-relaxed">
            0 días desactiva la opción de recordar equipos. Tras un cambio de contraseña, los equipos
            de esa persona se retiran solos.
          </p>

          <?php if ($puedeGestionar): ?>
            <button class="btn btn-primary w-full"><?= icon('save', 'w-4 h-4') ?> Guardar política</button>
          <?php else: ?>
            <p class="text-[12.5px] text-slate-400">Necesitas el permiso «Seguridad de acceso» para cambiar esto.</p>
          <?php endif; ?>
        </form>

        <div class="mt-6 pt-5 border-t border-slate-100">
          <h4 class="font-semibold text-slate-700 text-[14.5px]">Defensas siempre activas</h4>
          <ul class="mt-2.5 space-y-2 text-[12.5px] text-slate-500">
            <?php foreach ([
                'El código se guarda cifrado; ni un volcado de la base lo revela.',
                'Sirve una sola vez y muere a los ' . OTP_MAX_INTENTOS . ' intentos fallidos.',
                LOGIN_MAX_FALLOS_CUENTA . ' fallos de contraseña bloquean la cuenta ' . LOGIN_VENTANA_MIN . ' minutos.',
                LOGIN_MAX_FALLOS_IP . ' fallos desde una misma IP la bloquean entera.',
                'Máximo ' . OTP_MAX_ENVIOS_HORA . ' códigos por hora y ' . OTP_REENVIO_ESPERA . ' s entre uno y otro.',
            ] as $linea): ?>
              <li class="flex items-start gap-2">
                <span class="text-emerald-500 mt-0.5 shrink-0"><?= icon('check', 'w-3.5 h-3.5') ?></span>
                <span><?= e($linea) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    </div>

    <!-- Usuarios -->
    <div class="xl:col-span-3 space-y-5">
      <div class="card overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
          <div>
            <h3 class="font-bold text-slate-800">Estado por usuario</h3>
            <p class="text-[12.5px] text-slate-400 mt-0.5">Una cuenta exenta entra solo con contraseña.</p>
          </div>
          <span class="text-sm text-slate-400"><?= count($usuarios) ?> cuentas</span>
        </div>
        <div class="overflow-x-auto">
          <table class="data-table">
            <thead><tr><th>Usuario</th><th>Rol</th><th>Correo</th><th class="text-center">Equipos</th><th>Verificación</th><th class="text-right">Acciones</th></tr></thead>
            <tbody>
              <?php foreach ($usuarios as $u): ?>
                <?php
                  $nombreCompleto = trim($u['nombre'] . ' ' . $u['apellido']);
                  $on = (int) $u['otp_activo'] === 1;
                  $correoOk = filter_var((string) $u['email'], FILTER_VALIDATE_EMAIL) !== false;
                ?>
                <tr class="<?= (int) $u['activo'] === 1 ? '' : 'opacity-50' ?>">
                  <td>
                    <div class="flex items-center gap-3">
                      <?= avatar($nombreCompleto) ?>
                      <span class="min-w-0">
                        <span class="block font-semibold text-slate-700 truncate"><?= e($nombreCompleto) ?></span>
                        <span class="block text-[11.5px] text-slate-400 font-mono"><?= e($u['usuario']) ?></span>
                      </span>
                    </div>
                  </td>
                  <td><?= badge($u['rol_nombre'], 'indigo') ?></td>
                  <td class="text-slate-500 text-sm">
                    <?= e($u['email']) ?>
                    <?php if (!$correoOk): ?><span class="block text-[11px] text-rose-600 font-semibold">Correo inválido: no puede recibir el código</span><?php endif; ?>
                  </td>
                  <td class="text-center">
                    <?php if ((int) $u['equipos'] > 0): ?>
                      <span class="font-semibold text-slate-700"><?= (int) $u['equipos'] ?></span>
                    <?php else: ?>
                      <span class="text-slate-300">—</span>
                    <?php endif; ?>
                  </td>
                  <td><?= $on ? badge('Activa', 'emerald') : badge('Exenta', 'amber') ?></td>
                  <td>
                    <div class="flex items-center justify-end gap-1">
                      <?php if ($puedeGestionar): ?>
                        <?php if ((int) $u['equipos'] > 0): ?>
                          <form method="post" class="inline" onsubmit="return confirm('¿Retirar los equipos de confianza de «<?= e($u['usuario']) ?>»?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="revocar_equipos">
                            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                            <button class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50" title="Retirar equipos de confianza"><?= icon('trash', 'w-4 h-4') ?></button>
                          </form>
                        <?php endif; ?>
                        <form method="post" class="inline"
                              <?= $on ? 'onsubmit="return confirm(\'Eximir a esta cuenta la deja entrar solo con contraseña. ¿Continuar?\')"' : '' ?>>
                          <?= csrf_field() ?>
                          <input type="hidden" name="accion" value="usuario_otp">
                          <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                          <input type="hidden" name="valor" value="<?= $on ? 0 : 1 ?>">
                          <button class="btn btn-sm <?= $on ? 'btn-ghost' : 'btn-soft' ?>">
                            <?= $on ? 'Eximir' : 'Activar' ?>
                          </button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Bloqueos -->
      <div class="card overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800">Intentos fallidos en curso</h3>
          <p class="text-[12.5px] text-slate-400 mt-0.5">Ventana de <?= LOGIN_VENTANA_MIN ?> minutos. Los bloqueos se levantan solos al vencer.</p>
        </div>
        <?php if (!$bloqueos): ?>
          <?= empty_state('Sin intentos fallidos', 'Nadie está fallando la contraseña ahora mismo.', 'check') ?>
        <?php else: ?>
          <div class="overflow-x-auto">
            <table class="data-table">
              <thead><tr><th>Origen</th><th>Tipo</th><th class="text-center">Fallos</th><th>Último</th><th class="text-right">Acción</th></tr></thead>
              <tbody>
                <?php foreach ($bloqueos as $b): ?>
                  <tr>
                    <td class="font-mono text-sm text-slate-700"><?= e($b['etiqueta']) ?></td>
                    <td><?= $b['es_ip'] ? badge('Dirección IP', 'slate') : badge('Cuenta', 'blue') ?></td>
                    <td class="text-center">
                      <span class="font-bold <?= $b['bloqueado'] ? 'text-rose-600' : 'text-amber-600' ?>"><?= (int) $b['fallos'] ?></span>
                      <span class="text-slate-400">/ <?= (int) $b['tope'] ?></span>
                    </td>
                    <td class="text-slate-500 text-sm"><?= e(tiempoRelativo($b['ultimo'])) ?></td>
                    <td class="text-right">
                      <?php if ($b['bloqueado']): ?>
                        <?= badge('Bloqueado', 'rose') ?>
                      <?php endif; ?>
                      <?php if ($puedeGestionar): ?>
                        <form method="post" class="inline ml-1">
                          <?= csrf_field() ?>
                          <input type="hidden" name="accion" value="desbloquear">
                          <input type="hidden" name="clave" value="<?= e($b['clave']) ?>">
                          <button class="btn btn-sm btn-ghost">Desbloquear</button>
                        </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <!-- Últimos códigos -->
      <div class="card overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
          <div>
            <h3 class="font-bold text-slate-800">Últimos códigos emitidos</h3>
            <p class="text-[12.5px] text-slate-400 mt-0.5">Se guardan <?= OTP_PURGA_DIAS ?> días. El código en sí nunca se almacena.</p>
          </div>
          <span class="text-sm text-slate-400"><?= number_format($kpi['codigos']) ?> en 24 h</span>
        </div>
        <?php if (!$codigos): ?>
          <?= empty_state('Todavía no se ha emitido ningún código', 'Aparecerán aquí en cuanto alguien inicie sesión.', 'mail') ?>
        <?php else: ?>
          <div class="overflow-x-auto">
            <table class="data-table">
              <thead><tr><th>Usuario</th><th>Enviado a</th><th>Origen</th><th>Estado</th><th>Cuándo</th></tr></thead>
              <tbody>
                <?php foreach ($codigos as $c): ?>
                  <?php
                    if ($c['usado_en'])                        [$txt, $col] = ['Usado', 'emerald'];
                    elseif ($c['anulado_en'])                  [$txt, $col] = ['Anulado', 'slate'];
                    elseif (!(int) $c['enviado'])              [$txt, $col] = ['Fallo de envío', 'rose'];
                    elseif (strtotime($c['expira_en']) <= time()) [$txt, $col] = ['Vencido', 'slate'];
                    else                                       [$txt, $col] = ['Vigente', 'blue'];
                  ?>
                  <tr>
                    <td class="font-mono text-sm text-slate-700"><?= e($c['usuario']) ?></td>
                    <td class="text-slate-500 text-sm"><?= e(otp_email_mascara((string) $c['destino'])) ?></td>
                    <td class="text-slate-400 text-sm font-mono"><?= e($c['ip'] ?: '—') ?></td>
                    <td>
                      <?= badge($txt, $col) ?>
                      <?php if ((int) $c['intentos'] > 0 && !$c['usado_en']): ?>
                        <span class="text-[11.5px] text-amber-600 font-semibold ml-1"><?= (int) $c['intentos'] ?> intento(s)</span>
                      <?php endif; ?>
                      <?php if ($c['error_envio']): ?>
                        <span class="block text-[11px] text-rose-500 mt-0.5 truncate max-w-[16rem]" title="<?= e($c['error_envio']) ?>"><?= e($c['error_envio']) ?></span>
                      <?php endif; ?>
                    </td>
                    <td class="text-slate-500 text-sm"><?= e(fechaHora($c['created_at'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php layout_end(); ?>
