<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();

$uid = (int) current_user()['id'];

if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    if ($accion === 'datos') {
        $nombre = trim(post('nombre'));
        $apellido = trim(post('apellido'));
        $email = trim(post('email'));
        $telefono = trim(post('telefono'));
        if ($nombre === '' || $apellido === '' || $email === '') {
            flash('error', 'Nombre, apellido y correo son obligatorios.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'El correo electrónico no es válido.');
        } elseif (qVal("SELECT 1 FROM usuarios WHERE email = ? AND id <> ?", [$email, $uid])) {
            flash('error', 'Ese correo ya está en uso por otro usuario.');
        } else {
            dbUpdate('usuarios', ['nombre' => $nombre, 'apellido' => $apellido, 'email' => $email, 'telefono' => $telefono ?: null], 'id = ?', [$uid]);
            $_SESSION['user']['nombre'] = $nombre;
            $_SESSION['user']['apellido'] = $apellido;
            $_SESSION['user']['email'] = $email;
            audit('auth', 'editar', 'Actualizó sus datos de perfil');
            flash('success', 'Datos actualizados.');
        }
        redirect('modules/auth/perfil.php');
    }

    if ($accion === 'password') {
        $actual = post('password_actual');
        $nueva = post('password_nueva');
        $confirmar = post('password_confirmar');
        $hash = qVal("SELECT password_hash FROM usuarios WHERE id = ?", [$uid]);
        if (!password_verify($actual, $hash)) {
            flash('error', 'La contraseña actual es incorrecta.');
        } elseif (strlen($nueva) < 6) {
            flash('error', 'La nueva contraseña debe tener al menos 6 caracteres.');
        } elseif ($nueva !== $confirmar) {
            flash('error', 'La confirmación no coincide.');
        } else {
            dbUpdate('usuarios', ['password_hash' => password_hash($nueva, PASSWORD_DEFAULT)], 'id = ?', [$uid]);
            // Cambiar la contraseña es lo que se hace cuando se sospecha que alguien
            // la conoce. Dejar equipos «de confianza» vivos después de eso sería
            // dejarle a esa persona una puerta abierta sin segundo factor.
            $equipos = otp_revocar_todos($uid);
            otp_anular_vivos($uid, 'login', 'cambio_password');
            audit('auth', 'editar', 'Cambió su contraseña'
                . ($equipos > 0 ? " (se retiraron $equipos equipo" . ($equipos === 1 ? '' : 's') . ' de confianza)' : ''));
            flash('success', 'Contraseña actualizada correctamente.'
                . ($equipos > 0 ? ' Por seguridad se retiraron tus equipos de confianza.' : ''));
        }
        redirect('modules/auth/perfil.php');
    }

    if ($accion === 'otp_activar') {
        if (!otp_disponible()) {
            flash('error', 'La verificación en dos pasos no está instalada en este servidor.');
        } else {
            dbUpdate('usuarios', ['otp_activo' => 1], 'id = ?', [$uid]);
            audit('auth', 'otp_activado', 'Activó la verificación en dos pasos en su cuenta');
            flash('success', 'Verificación en dos pasos activada para tu cuenta.');
        }
        redirect('modules/auth/perfil.php');
    }

    // Solo se revocan equipos PROPIOS: otp_revocar_dispositivo() filtra por usuario.
    if ($accion === 'revocar_equipo') {
        if (otp_revocar_dispositivo(postInt('id'), $uid)) {
            audit('auth', 'dispositivo_revocado', 'Retiró un equipo de confianza');
            flash('success', 'Equipo retirado. La próxima vez pedirá código en ese dispositivo.');
        } else {
            flash('error', 'Ese equipo ya no estaba registrado.');
        }
        redirect('modules/auth/perfil.php');
    }

    if ($accion === 'revocar_equipos') {
        $n = otp_revocar_todos($uid);
        audit('auth', 'dispositivo_revocado', "Retiró todos sus equipos de confianza ($n)");
        flash($n > 0 ? 'success' : 'info', $n > 0
            ? "Se retiraron $n equipo" . ($n === 1 ? '' : 's') . ' de confianza.'
            : 'No tenías equipos de confianza registrados.');
        redirect('modules/auth/perfil.php');
    }
}

$u = qOne("SELECT u.*, r.nombre AS rol, s.nombre AS sucursal FROM usuarios u JOIN roles r ON r.id=u.rol_id LEFT JOIN sucursales s ON s.id=u.sucursal_id WHERE u.id=?", [$uid]);

// ---------- Verificación en dos pasos ----------
$otpCfg      = otp_config();
$otpInstalado = otp_disponible();
$otpUsuario  = (int) ($u['otp_activo'] ?? 1) === 1;
$otpVigente  = otp_politica_activa() && otp_operativo() && $otpUsuario;
$equipos     = $otpInstalado ? otp_dispositivos($uid) : [];
$equipoActual = $otpInstalado ? otp_dispositivo_actual_id($uid) : null;

// Últimos accesos: es la señal con la que una persona detecta un acceso ajeno.
// Cada intento deja dos filas (una por cuenta y otra por IP); aquí solo la de la
// cuenta, o la lista saldría duplicada.
$accesos = $otpInstalado ? qAll(
    "SELECT exito, ip, detalle, created_at FROM login_intentos
      WHERE tipo = 'password' AND usuario_id = ? AND clave LIKE 'login:%'
      ORDER BY id DESC LIMIT 6", [$uid]) : [];

// Actividad reciente del usuario (da contexto a la ficha).
$actividad = qAll(
    "SELECT modulo, accion, descripcion, created_at FROM auditoria
      WHERE usuario_id = ? ORDER BY id DESC LIMIT 8",
    [$uid]
);

$acciones = '<button onclick="' . jsEvent('perfil:datos') . '" class="btn btn-ghost">' . icon('edit', 'w-4 h-4') . ' Editar datos</button>'
    . '<button onclick="' . jsEvent('perfil:password') . '" class="btn btn-primary">' . icon('lock', 'w-4 h-4') . ' Cambiar contraseña</button>';

layout_start('Mi Perfil', 'Tus datos, tu acceso y tu actividad reciente', $acciones);
?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
  <!-- Ficha -->
  <div class="card p-6 h-fit text-center">
    <?= avatar($u['nombre'] . ' ' . $u['apellido'], 'w-20 h-20 text-2xl mx-auto') ?>
    <h3 class="font-bold text-slate-800 mt-3 text-lg"><?= e($u['nombre'] . ' ' . $u['apellido']) ?></h3>
    <p class="text-sm text-slate-400"><?= e($u['email']) ?></p>
    <div class="flex flex-wrap gap-2 justify-center mt-3">
      <?= badge($u['rol'], 'blue') ?>
      <?= badge($u['sucursal'] ?? 'Todas las sucursales', 'slate') ?>
    </div>
    <div class="mt-4 pt-4 border-t border-slate-100 text-sm text-slate-500 space-y-1">
      <p>Usuario: <span class="font-semibold text-slate-700"><?= e($u['usuario']) ?></span></p>
      <?php if ($u['telefono']): ?><p>Teléfono: <span class="font-semibold text-slate-700"><?= e($u['telefono']) ?></span></p><?php endif; ?>
      <?php if ($u['ultimo_acceso']): ?><p>Último acceso: <?= fechaHora($u['ultimo_acceso']) ?></p><?php endif; ?>
    </div>
    <button onclick="<?= jsEvent('perfil:datos') ?>" class="btn btn-ghost w-full mt-5"><?= icon('edit', 'w-4 h-4') ?> Editar mis datos</button>
  </div>

  <div class="lg:col-span-2 space-y-5">
    <!-- Seguridad -->
    <div class="card p-6">
      <div class="flex items-start gap-4">
        <span class="w-11 h-11 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0"><?= icon('lock', 'w-5 h-5') ?></span>
        <div class="flex-1 min-w-0">
          <h3 class="font-bold text-slate-800">Seguridad de la cuenta</h3>
          <p class="text-sm text-slate-500 mt-1 leading-relaxed">
            Tu contraseña es personal: con ella quedan firmadas las ventas, los ajustes de inventario y los movimientos
            de caja que hagas. Cámbiala si crees que alguien más la conoce; al hacerlo se retiran también tus equipos
            de confianza.
          </p>
          <button onclick="<?= jsEvent('perfil:password') ?>" class="btn btn-soft mt-4"><?= icon('lock', 'w-4 h-4') ?> Cambiar contraseña</button>
        </div>
      </div>
    </div>

    <!-- Verificación en dos pasos -->
    <div class="card p-6">
      <div class="flex items-start gap-4">
        <span class="w-11 h-11 rounded-xl shrink-0 flex items-center justify-center <?= $otpVigente ? 'bg-emerald-50 text-emerald-600' : 'bg-amber-50 text-amber-600' ?>">
          <?= icon('shield', 'w-5 h-5') ?>
        </span>
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2.5 flex-wrap">
            <h3 class="font-bold text-slate-800">Verificación en dos pasos</h3>
            <?= $otpVigente ? badge('Activa', 'emerald') : badge('Inactiva', 'amber') ?>
          </div>

          <?php if (!$otpInstalado): ?>
            <p class="text-sm text-slate-500 mt-1.5 leading-relaxed">
              Este servidor todavía no tiene instalada la verificación en dos pasos.
              El administrador debe aplicar la migración <span class="font-mono text-[12.5px]">migracion_otp_login_p14.sql</span>.
            </p>
          <?php elseif ($otpVigente): ?>
            <p class="text-sm text-slate-500 mt-1.5 leading-relaxed">
              Cada vez que entres<?= $otpCfg['modo'] === 'dispositivo_nuevo' ? ' desde un equipo nuevo' : '' ?>
              te enviaremos un código de <?= OTP_LONGITUD ?> dígitos a
              <span class="font-semibold text-slate-700"><?= e(otp_email_mascara((string) $u['email'])) ?></span>.
              Aunque alguien averigüe tu contraseña, sin ese código no entra.
            </p>
          <?php elseif (!$otpUsuario): ?>
            <p class="text-sm text-slate-500 mt-1.5 leading-relaxed">
              Tu cuenta está exenta del código de verificación. Actívala: es la protección más
              efectiva que puedes ponerle a tu usuario y no cuesta nada.
            </p>
            <form method="post" class="mt-4">
              <?= csrf_field() ?>
              <input type="hidden" name="accion" value="otp_activar">
              <button class="btn btn-primary"><?= icon('shield', 'w-4 h-4') ?> Activar en mi cuenta</button>
            </form>
          <?php elseif (!otp_operativo()): ?>
            <p class="text-sm text-slate-500 mt-1.5 leading-relaxed">
              La política está encendida, pero el correo saliente no está configurado en el servidor,
              así que ahora mismo no se puede entregar ningún código. Avisa al administrador.
            </p>
          <?php else: ?>
            <p class="text-sm text-slate-500 mt-1.5 leading-relaxed">
              La empresa tiene la verificación en dos pasos desactivada para todos los usuarios.
            </p>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($otpInstalado && $otpCfg['recordar_dias'] > 0): ?>
        <div class="mt-5 pt-5 border-t border-slate-100">
          <div class="flex items-center justify-between gap-3 flex-wrap mb-3">
            <div>
              <h4 class="font-semibold text-slate-700 text-[14.5px]">Equipos de confianza</h4>
              <p class="text-[12.5px] text-slate-400 mt-0.5">Aquí no se pide el código durante <?= (int) $otpCfg['recordar_dias'] ?> días.</p>
            </div>
            <?php if ($equipos): ?>
              <form method="post" onsubmit="return confirm('¿Retirar todos tus equipos de confianza? La próxima vez pedirá código en todos.')">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="revocar_equipos">
                <button class="btn btn-ghost btn-sm text-rose-600"><?= icon('trash', 'w-4 h-4') ?> Retirar todos</button>
              </form>
            <?php endif; ?>
          </div>

          <?php if (!$equipos): ?>
            <p class="text-sm text-slate-400 rounded-xl bg-slate-50 border border-slate-100 px-4 py-3">
              No tienes equipos marcados como de confianza. Se te pedirá el código en cada acceso.
            </p>
          <?php else: ?>
            <ul class="divide-y divide-slate-100 border border-slate-100 rounded-xl overflow-hidden">
              <?php foreach ($equipos as $d): ?>
                <li class="flex items-center gap-3 px-4 py-3 <?= (int) $d['id'] === $equipoActual ? 'bg-emerald-50/40' : '' ?>">
                  <span class="w-8 h-8 rounded-lg bg-slate-50 text-slate-400 flex items-center justify-center shrink-0"><?= icon('lock', 'w-4 h-4') ?></span>
                  <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-slate-700 flex items-center gap-2 flex-wrap">
                      <?= e($d['nombre']) ?>
                      <?php if ((int) $d['id'] === $equipoActual): ?><?= badge('Este equipo', 'emerald') ?><?php endif; ?>
                    </p>
                    <p class="text-[11.5px] text-slate-400 mt-0.5">
                      <?= e($d['ip'] ?: 'IP desconocida') ?> ·
                      último uso <?= e(tiempoRelativo($d['ultimo_uso'] ?: $d['created_at'])) ?> ·
                      vence <?= e(fechaCorta($d['expira_en'])) ?>
                    </p>
                  </div>
                  <form method="post" class="shrink-0" onsubmit="return confirm('¿Retirar «<?= e($d['nombre']) ?>» de tus equipos de confianza?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="revocar_equipo">
                    <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                    <button class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50" title="Retirar equipo"><?= icon('trash', 'w-4 h-4') ?></button>
                  </form>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($accesos): ?>
      <!-- Últimos accesos -->
      <div class="card overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800">Últimos intentos de acceso a tu cuenta</h3>
          <p class="text-[12.5px] text-slate-400 mt-0.5">Si ves un intento que no reconoces, cambia tu contraseña.</p>
        </div>
        <ul class="divide-y divide-slate-100">
          <?php foreach ($accesos as $a): ?>
            <li class="flex items-center gap-3 px-6 py-3">
              <span class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 <?= $a['exito'] ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-600' ?>">
                <?= icon($a['exito'] ? 'check' : 'x', 'w-4 h-4') ?>
              </span>
              <div class="min-w-0 flex-1">
                <p class="text-sm text-slate-700"><?= $a['exito'] ? 'Contraseña correcta' : e(ucfirst((string) ($a['detalle'] ?: 'Intento fallido'))) ?></p>
                <p class="text-[11.5px] text-slate-400 mt-0.5"><?= e($a['ip'] ?: 'IP desconocida') ?> · <?= e(fechaHora($a['created_at'])) ?></p>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <!-- Actividad -->
    <div class="card overflow-hidden">
      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <h3 class="font-bold text-slate-800">Tu actividad reciente</h3>
        <?php if (can('auditoria.ver')): ?>
          <a href="<?= e(url('modules/admin/auditoria.php')) ?>" class="text-sm font-semibold text-blue-600 hover:text-blue-700">Ver bitácora →</a>
        <?php endif; ?>
      </div>
      <?php if (!$actividad): ?>
        <?= empty_state('Sin actividad registrada', 'Cuando registres ventas, ajustes o cambios, aparecerán aquí.', 'history') ?>
      <?php else: ?>
        <ul class="divide-y divide-slate-100">
          <?php foreach ($actividad as $a): ?>
            <li class="flex items-start gap-3 px-6 py-3.5">
              <span class="w-8 h-8 rounded-lg bg-slate-50 text-slate-400 flex items-center justify-center shrink-0 mt-0.5"><?= icon('history', 'w-4 h-4') ?></span>
              <div class="min-w-0 flex-1">
                <p class="text-sm text-slate-700"><?= e($a['descripcion'] ?: ucfirst($a['accion'])) ?></p>
                <p class="text-[11.5px] text-slate-400 mt-0.5"><?= e(ucfirst($a['modulo'])) ?> · <?= e(tiempoRelativo($a['created_at'])) ?></p>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Modal: datos personales -->
<div x-data="{open:false}" @perfil:datos.window="open=true" @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-lg" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="datos">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800">Editar mis datos</h3>
          <button type="button" @click="open=false" aria-label="Cerrar modal" title="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div><label class="label" for="p_nombre">Nombre *</label><input id="p_nombre" name="nombre" value="<?= e($u['nombre']) ?>" required class="input"></div>
          <div><label class="label" for="p_apellido">Apellido *</label><input id="p_apellido" name="apellido" value="<?= e($u['apellido']) ?>" required class="input"></div>
          <div class="sm:col-span-2"><label class="label" for="p_email">Correo electrónico *</label><input type="email" id="p_email" name="email" value="<?= e($u['email']) ?>" required class="input"></div>
          <div class="sm:col-span-2"><label class="label" for="p_telefono">Teléfono</label><input id="p_telefono" name="telefono" value="<?= e($u['telefono']) ?>" class="input" placeholder="809-000-0000"></div>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button type="submit" class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar cambios</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: cambiar contraseña -->
<div x-data="{open:false, ver:false, nueva:'', confirmar:''}"
     @perfil:password.window="open=true; ver=false; nueva=''; confirmar=''"
     @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-md" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="password">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800">Cambiar contraseña</h3>
          <button type="button" @click="open=false" aria-label="Cerrar modal" title="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 space-y-4">
          <div>
            <label class="label" for="pw_actual">Contraseña actual *</label>
            <input type="password" id="pw_actual" name="password_actual" required autocomplete="current-password" class="input">
          </div>
          <div>
            <label class="label" for="pw_nueva">Nueva contraseña *</label>
            <div class="relative">
              <input :type="ver ? 'text' : 'password'" id="pw_nueva" name="password_nueva" x-model="nueva" required minlength="6"
                     autocomplete="new-password" class="input pr-10">
              <button type="button" @click="ver = !ver" :aria-label="ver ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                      class="absolute right-2.5 top-1/2 -translate-y-1/2 p-1 text-slate-400 hover:text-slate-700"><?= icon('eye', 'w-4 h-4') ?></button>
            </div>
            <p class="text-xs text-slate-400 mt-1.5">Mínimo 6 caracteres. Mezcla letras, números y algún símbolo.</p>
          </div>
          <div>
            <label class="label" for="pw_conf">Confirmar nueva contraseña *</label>
            <input :type="ver ? 'text' : 'password'" id="pw_conf" name="password_confirmar" x-model="confirmar" required minlength="6"
                   autocomplete="new-password" class="input">
            <p x-show="confirmar.length > 0 && nueva !== confirmar" x-cloak class="text-xs text-rose-600 mt-1.5 font-medium">
              Las dos contraseñas no coinciden.
            </p>
          </div>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button type="submit" class="btn btn-primary" :disabled="nueva.length < 6 || nueva !== confirmar">
            <?= icon('lock', 'w-4 h-4') ?> Cambiar contraseña
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php layout_end(); ?>
