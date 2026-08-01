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
            audit('auth', 'editar', 'Cambió su contraseña');
            flash('success', 'Contraseña actualizada correctamente.');
        }
        redirect('modules/auth/perfil.php');
    }
}

$u = qOne("SELECT u.*, r.nombre AS rol, s.nombre AS sucursal FROM usuarios u JOIN roles r ON r.id=u.rol_id LEFT JOIN sucursales s ON s.id=u.sucursal_id WHERE u.id=?", [$uid]);

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
            de caja que hagas. Cámbiala si crees que alguien más la conoce.
          </p>
          <button onclick="<?= jsEvent('perfil:password') ?>" class="btn btn-soft mt-4"><?= icon('lock', 'w-4 h-4') ?> Cambiar contraseña</button>
        </div>
      </div>
    </div>

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
