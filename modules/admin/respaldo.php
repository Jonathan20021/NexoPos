<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('configuracion.ver');

if (get('descargar') === '1') {
    require_perm('configuracion.editar');
    audit('configuracion', 'editar', 'Descargó un respaldo de la base de datos');
    backup_sql_download();
}

$stats = backup_stats();
$totalFilas = array_sum(array_map(fn($r) => (int) $r['filas'], $stats));
$totalKb = array_sum(array_map(fn($r) => (float) $r['kb'], $stats));

layout_start('Respaldo de Base de Datos', 'Genera y descarga una copia de seguridad completa');
?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
  <div class="lg:col-span-2 space-y-5">
    <div class="card p-6">
      <div class="flex items-start gap-4">
        <div class="w-12 h-12 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0"><?= icon('download', 'w-6 h-6') ?></div>
        <div class="flex-1">
          <h3 class="font-bold text-slate-800 text-lg">Descargar respaldo (.sql)</h3>
          <p class="text-sm text-slate-500 mt-1">Genera un archivo SQL con la estructura y todos los datos de la base
            <code class="bg-slate-100 px-1.5 py-0.5 rounded"><?= e(DB_NAME) ?></code>. Guárdalo en un lugar seguro.</p>
          <div class="flex flex-wrap gap-4 mt-4 text-sm">
            <div class="rounded-xl bg-slate-50 px-4 py-2"><span class="text-slate-400">Tablas</span><p class="font-bold text-slate-800 text-lg"><?= count($stats) ?></p></div>
            <div class="rounded-xl bg-slate-50 px-4 py-2"><span class="text-slate-400">Registros (aprox.)</span><p class="font-bold text-slate-800 text-lg"><?= number_format($totalFilas) ?></p></div>
            <div class="rounded-xl bg-slate-50 px-4 py-2"><span class="text-slate-400">Tamaño</span><p class="font-bold text-slate-800 text-lg"><?= number_format($totalKb / 1024, 2) ?> MB</p></div>
          </div>
          <?php if (can('configuracion.editar')): ?>
            <a href="?descargar=1" class="btn btn-primary mt-5 inline-flex"><?= icon('download', 'w-4 h-4') ?> Descargar respaldo ahora</a>
          <?php else: ?>
            <p class="text-sm text-rose-600 mt-5">No tienes permiso para descargar respaldos.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="card p-6 bg-amber-50/40 border-amber-200">
      <h3 class="font-bold text-slate-800 flex items-center gap-2"><?= icon('alert', 'w-5 h-5 text-amber-500') ?> Cómo restaurar</h3>
      <ol class="text-sm text-slate-600 space-y-2 mt-3 list-decimal list-inside">
        <li>Abre <strong>phpMyAdmin</strong> (http://localhost/phpmyadmin) o tu gestor de base de datos.</li>
        <li>Selecciona la base <code class="bg-white px-1 rounded border"><?= e(DB_NAME) ?></code> (o créala si no existe).</li>
        <li>Ve a <strong>Importar</strong> y selecciona el archivo <code class="bg-white px-1 rounded border">.sql</code> descargado.</li>
        <li>Ejecuta. El respaldo reemplaza las tablas con los datos guardados.</li>
      </ol>
      <p class="text-xs text-slate-400 mt-3">Por consola: <code class="bg-white px-1.5 py-0.5 rounded border">mysql -u root <?= e(DB_NAME) ?> &lt; respaldo.sql</code></p>
    </div>
  </div>

  <div class="card overflow-hidden h-fit">
    <div class="p-5 pb-3"><h3 class="font-bold text-slate-800">Tablas</h3></div>
    <div class="overflow-x-auto max-h-[520px] overflow-y-auto">
      <table class="data-table">
        <thead><tr><th>Tabla</th><th class="text-right">Filas</th><th class="text-right">KB</th></tr></thead>
        <tbody>
          <?php foreach ($stats as $s): ?>
            <tr>
              <td class="font-medium text-slate-600 text-xs"><?= e($s['tabla']) ?></td>
              <td class="text-right text-slate-500"><?= number_format((int) $s['filas']) ?></td>
              <td class="text-right text-slate-400"><?= number_format((float) $s['kb'], 1) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php layout_end(); ?>
