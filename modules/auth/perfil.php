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

layout_start('Mi Perfil', 'Actualiza tus datos y contraseña');
?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
  <div class="card p-6 h-fit text-center">
    <?= avatar($u['nombre'] . ' ' . $u['apellido'], 'w-20 h-20 text-2xl mx-auto') ?>
    <h3 class="font-bold text-slate-800 mt-3 text-lg"><?= e($u['nombre'] . ' ' . $u['apellido']) ?></h3>
    <p class="text-sm text-slate-400"><?= e($u['email']) ?></p>
    <div class="flex flex-wrap gap-2 justify-center mt-3">
      <?= badge($u['rol'], 'blue') ?>
      <?= badge($u['sucursal'] ?? 'Todas las sucursales', 'slate') ?>
    </div>
    <div class="mt-4 pt-4 border-t border-slate-100 text-sm text-slate-500">
      <p>Usuario: <span class="font-semibold text-slate-700"><?= e($u['usuario']) ?></span></p>
      <?php if ($u['ultimo_acceso']): ?><p class="mt-1">Último acceso: <?= fechaHora($u['ultimo_acceso']) ?></p><?php endif; ?>
    </div>
  </div>

  <div class="lg:col-span-2 space-y-5">
    <div class="card p-6">
      <h3 class="font-bold text-slate-800 mb-4">Datos personales</h3>
      <form method="post" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <?= csrf_field() ?><input type="hidden" name="accion" value="datos">
        <div><label class="label">Nombre</label><input name="nombre" value="<?= e($u['nombre']) ?>" required class="input"></div>
        <div><label class="label">Apellido</label><input name="apellido" value="<?= e($u['apellido']) ?>" required class="input"></div>
        <div><label class="label">Correo</label><input type="email" name="email" value="<?= e($u['email']) ?>" required class="input"></div>
        <div><label class="label">Teléfono</label><input name="telefono" value="<?= e($u['telefono']) ?>" class="input"></div>
        <div class="sm:col-span-2 flex justify-end"><button class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar datos</button></div>
      </form>
    </div>

    <div class="card p-6">
      <h3 class="font-bold text-slate-800 mb-4">Cambiar contraseña</h3>
      <form method="post" class="grid grid-cols-1 sm:grid-cols-3 gap-4" x-data="{a:false,b:false}">
        <?= csrf_field() ?><input type="hidden" name="accion" value="password">
        <div><label class="label">Contraseña actual</label><input type="password" name="password_actual" required class="input"></div>
        <div><label class="label">Nueva contraseña</label><input type="password" name="password_nueva" required minlength="6" class="input"></div>
        <div><label class="label">Confirmar</label><input type="password" name="password_confirmar" required minlength="6" class="input"></div>
        <div class="sm:col-span-3 flex justify-end"><button class="btn btn-primary"><?= icon('lock', 'w-4 h-4') ?> Cambiar contraseña</button></div>
      </form>
    </div>
  </div>
</div>
<?php layout_end(); ?>
