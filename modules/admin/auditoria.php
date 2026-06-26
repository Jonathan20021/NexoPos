<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('auditoria.ver');

// ---------- Filtros (GET) ----------
$modulo = trim(get('modulo'));
$q      = trim(get('q'));
$desde  = trim(get('desde'));
$hasta  = trim(get('hasta'));
$pagina = max(1, (int) get('p', 1));
$porPagina = 30;
$offset = ($pagina - 1) * $porPagina;

// Construcción dinámica del WHERE (sentencias preparadas).
$cond = [];
$params = [];
if ($modulo !== '') {
    $cond[] = "a.modulo = ?";
    $params[] = $modulo;
}
if ($q !== '') {
    $cond[] = "(a.descripcion LIKE ? OR a.usuario_nombre LIKE ?)";
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if ($desde !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
    $cond[] = "a.created_at >= ?";
    $params[] = $desde . ' 00:00:00';
}
if ($hasta !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
    $cond[] = "a.created_at <= ?";
    $params[] = $hasta . ' 23:59:59';
}
$where = $cond ? 'WHERE ' . implode(' AND ', $cond) : '';

// Total y datos paginados.
$total = (int) qVal("SELECT COUNT(*) FROM auditoria a $where", $params);
$totalPaginas = max(1, (int) ceil($total / $porPagina));

$registros = qAll(
    "SELECT a.* FROM auditoria a $where ORDER BY a.created_at DESC LIMIT $porPagina OFFSET $offset",
    $params
);

if (export_solicitado()) {
    $rows = qAll("SELECT a.created_at, a.usuario_nombre, a.modulo, a.accion, a.descripcion, a.ip FROM auditoria a $where ORDER BY a.created_at DESC", $params);
    export_tabla('auditoria', ['Fecha', 'Usuario', 'Módulo', 'Acción', 'Descripción', 'IP'],
        array_map(fn($a) => [$a['created_at'], $a['usuario_nombre'], $a['modulo'], $a['accion'], $a['descripcion'], $a['ip']], $rows));
}

// Módulos disponibles para el filtro.
$modulos = qCol("SELECT DISTINCT modulo FROM auditoria ORDER BY modulo");

// Colores de badge por acción.
$colorAccion = ['crear' => 'emerald', 'editar' => 'blue', 'eliminar' => 'rose', 'anular' => 'amber', 'login' => 'indigo', 'logout' => 'slate'];

layout_start('Auditoría', 'Registro de actividad del sistema', export_buttons());
?>

<div class="card overflow-hidden">
  <!-- Filtros -->
  <div class="p-4 border-b border-slate-100">
    <form method="get" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">
      <div>
        <label class="label">Módulo</label>
        <select name="modulo" class="select">
          <option value="">Todos</option>
          <?php foreach ($modulos as $m): ?>
            <option value="<?= e($m) ?>" <?= $modulo === $m ? 'selected' : '' ?>><?= e($m) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="label">Buscar</label>
        <input type="text" name="q" value="<?= e($q) ?>" class="input" placeholder="Descripción o usuario…">
      </div>
      <div>
        <label class="label">Desde</label>
        <input type="date" name="desde" value="<?= e($desde) ?>" class="input">
      </div>
      <div>
        <label class="label">Hasta</label>
        <input type="date" name="hasta" value="<?= e($hasta) ?>" class="input">
      </div>
      <div class="flex items-center gap-2">
        <button type="submit" class="btn btn-primary"><?= icon('filter', 'w-4 h-4') ?> Filtrar</button>
        <?php if ($modulo !== '' || $q !== '' || $desde !== '' || $hasta !== ''): ?>
          <a href="<?= e(url('modules/admin/auditoria.php')) ?>" class="btn btn-ghost">Limpiar</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="px-4 py-2.5 border-b border-slate-100 flex items-center justify-between text-sm text-slate-400">
    <span><?= number_format($total) ?> registro(s)</span>
    <span>Página <?= $pagina ?> de <?= $totalPaginas ?></span>
  </div>

  <?php if (!$registros): ?>
    <?= empty_state('Sin registros', 'No hay actividad que coincida con los filtros.', 'history') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr><th>Fecha</th><th>Usuario</th><th>Módulo</th><th>Acción</th><th>Descripción</th><th>IP</th></tr></thead>
        <tbody>
          <?php foreach ($registros as $a): ?>
            <tr>
              <td class="text-slate-500 text-sm whitespace-nowrap"><?= e(fechaHora($a['created_at'])) ?></td>
              <td class="font-medium text-slate-700"><?= e($a['usuario_nombre'] ?: 'Sistema') ?></td>
              <td><?= badge($a['modulo'], 'slate') ?></td>
              <td><?= badge($a['accion'], $colorAccion[$a['accion']] ?? 'sky') ?></td>
              <td class="text-slate-600 max-w-md truncate"><?= e($a['descripcion'] ?: '—') ?></td>
              <td class="text-slate-400 text-sm font-mono"><?= e($a['ip'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Paginación -->
    <?php if ($totalPaginas > 1): ?>
      <?php
        $baseParams = array_filter(['modulo' => $modulo, 'q' => $q, 'desde' => $desde, 'hasta' => $hasta], fn($v) => $v !== '');
        $linkPagina = function (int $p) use ($baseParams) {
            return url('modules/admin/auditoria.php') . '?' . http_build_query($baseParams + ['p' => $p]);
        };
      ?>
      <div class="flex items-center justify-between px-4 py-3 border-t border-slate-100">
        <?php if ($pagina > 1): ?>
          <a href="<?= e($linkPagina($pagina - 1)) ?>" class="btn btn-ghost btn-sm"><?= icon('arrow-left', 'w-4 h-4') ?> Anterior</a>
        <?php else: ?>
          <span class="btn btn-ghost btn-sm opacity-40 pointer-events-none"><?= icon('arrow-left', 'w-4 h-4') ?> Anterior</span>
        <?php endif; ?>
        <span class="text-sm text-slate-400">Página <?= $pagina ?> de <?= $totalPaginas ?></span>
        <?php if ($pagina < $totalPaginas): ?>
          <a href="<?= e($linkPagina($pagina + 1)) ?>" class="btn btn-ghost btn-sm">Siguiente <?= icon('arrow-right', 'w-4 h-4') ?></a>
        <?php else: ?>
          <span class="btn btn-ghost btn-sm opacity-40 pointer-events-none">Siguiente <?= icon('arrow-right', 'w-4 h-4') ?></span>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php layout_end(); ?>
