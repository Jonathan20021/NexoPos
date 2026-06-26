# Convenciones de desarrollo — NexoPOS

Lee este documento + `modules/inventario/categorias.php` (patrón CRUD de referencia) +
`database/schema.sql` (columnas exactas) antes de crear páginas.

## Estructura de una página de módulo
Las páginas viven en `modules/<grupo>/<archivo>.php` (profundidad 2).

```php
<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('<modulo>.ver');

if (isPost()) {
    verify_csrf();
    $accion = post('accion');
    if ($accion === 'guardar') { /* ... */ redirect('modules/<grupo>/<archivo>.php'); }
    if ($accion === 'eliminar') { /* ... */ redirect('modules/<grupo>/<archivo>.php'); }
}

// consultas ...
$acciones = can('<modulo>.crear') ? btn_nuevo('x:new', 'Nuevo') : '';
layout_start('Título', 'Subtítulo', $acciones);
?>
<!-- HTML de la página -->
<?php layout_end(); ?>
```
Patrón PRG: tras procesar un POST siempre `redirect(...)`. Usa `flash('success'|'error'|'warning'|'info', 'msg')`.

## Base de datos (PDO, sentencias preparadas SIEMPRE)
- `q($sql, $params=[])` → PDOStatement
- `qAll($sql, $params=[])` → array de filas
- `qOne($sql, $params=[])` → ?array (una fila o null)
- `qVal($sql, $params=[])` → primer valor escalar o null
- `qCol($sql, $params=[])` → array plano de la primera columna
- `dbInsert($tabla, $assoc)` → int id insertado
- `dbUpdate($tabla, $assoc, $where, $whereParams=[])` → filas afectadas
- `tx(fn($pdo){...})` → ejecuta en transacción (commit/rollback automático)
- `nextNumero($tabla,$col,$prefijo)` → genera correlativo tipo `VTA-000123`

## Formato y escape
- `money($n)` → `RD$ 1,234.50`; `money($n,false)` → `1,234.50`
- `qty($n)` cantidades; `pct($n)`; `fechaCorta($d)`, `fechaHora($d)`, `fechaLarga($d)`
- `e($s)` escapar SIEMPRE la salida de datos del usuario/BD en HTML
- `setting('moneda','RD$')`, `setting('itbis_tasa',18)`

## Sesión y permisos (RBAC)
- `current_user()` → array con: id, nombre, apellido, email, rol_nombre, es_super, sucursal_id (int|null), sucursal_nombre
- `can('modulo.accion')` bool; `can_any([...])`; `require_perm('modulo.accion')` (corta con 403)
- `is_super()` bool
- `current_sucursal_id()` → int de la sucursal activa, o **null = todas las sucursales**
- `sucursalScope('alias.sucursal_id')` → `[$whereFrag, $params]` para filtrar por sucursal activa
  (devuelve `['1=1', []]` cuando es "todas"). Ejemplo:
  ```php
  [$w,$p] = sucursalScope('v.sucursal_id');
  $rows = qAll("SELECT ... FROM ventas v WHERE $w ORDER BY ...", $p);
  ```

## Seguridad
- En cada formulario: `<?= csrf_field() ?>`. Al inicio del bloque POST: `verify_csrf();`
- Verificar permiso de la acción específica antes de escribir: `require_perm('modulo.crear')` etc.
- `audit('modulo','accion','descripción', ['tabla'=>'t','registro_id'=>$id])` tras crear/editar/eliminar.

## Iconos — `icon('nombre','w-5 h-5')`
dashboard, store, box, tag, layers, truck, cart, receipt, cash, undo, users, user, shield,
briefcase, calendar, clock, wallet, chart, pie, settings, logout, search, bell, plus, edit, trash,
eye, check, x, filter, chevron-down, chevron-right, menu, download, print, arrow-up, arrow-down,
arrow-left, arrow-right, transfer, trending, package, alert, building, id, dollar, percent, list,
grid, mail, phone, map, minus, save, lock, sun, history, barcode.

## Clases de UI (Tailwind con @apply, ya definidas en el layout)
- `card` (contenedor blanco redondeado con borde y sombra)
- Botones: `btn` + `btn-primary | btn-ghost | btn-soft | btn-danger | btn-success` (+ `btn-sm`)
- Formularios: `input`, `select`, `label`
- `badge` + `badge-<color>` (emerald, amber, rose, slate, sky, blue, indigo, cyan, pink, violet)
- Tablas: `<table class="data-table"><thead><tr><th>..</th></tr></thead><tbody><tr><td>..</td></tr></tbody></table>`
- Usa Tailwind utilitario libremente para el resto (grids, spacing, etc.). Mantén el estilo limpio de Dokani:
  cards `rounded-2xl`, fondos `bg-slate-50`, texto `text-slate-700/500/400`, acento `blue-600`.

## Componentes/Helpers de UI
- `btn_nuevo('evento:new','Etiqueta')` → botón primario de cabecera que abre un modal (despacha CustomEvent)
- `search_box('Buscar...')` → formulario GET de búsqueda (lee `$_GET['q']`)
- `badge($txt,$color)`, `badgeFor($estado)`, `avatar($nombreCompleto, 'w-9 h-9')`
- `empty_state($titulo,$mensaje,$icono,$accionHtml='')`

## Patrón de modal (Alpine.js ya está cargado globalmente)
Cabecera: `btn_nuevo('cli:new','Nuevo cliente')`.
Fila (editar): `<button onclick="<?= jsEvent('cli:edit', $fila) ?>" class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50"><?= icon('edit','w-4 h-4') ?></button>`
Modal (al final de la página):
```html
<div x-data="{open:false, form:{id:0, nombre:'', activo:1}}"
     @cli:new.window="form={id:0, nombre:'', activo:1}; open=true"
     @cli:edit.window="form=$event.detail; open=true"
     @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="fixed inset-0 bg-slate-900/40 z-50 flex items-center justify-center p-4" @click.self="open=false">
    <div class="bg-white rounded-2xl shadow-pop w-full max-w-md" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="id" :value="form.id">
        <!-- header con título dinámico: <h3 x-text="form.id ? 'Editar' : 'Nuevo'"></h3> -->
        <!-- campos: <input name="nombre" x-model="form.nombre" class="input"> -->
        <!-- checkbox 0/1: <input type="hidden" name="activo" value="0"><input type="checkbox" name="activo" value="1" :checked="form.activo==1"> -->
        <!-- footer: botón Cancelar (@click="open=false") + submit btn-primary -->
      </form>
    </div>
  </div>
</div>
```
`jsEvent('evt', $assoc)` genera el `onclick` que despacha el evento con los datos de la fila.

## Eliminar
```html
<form method="post" class="inline" onsubmit="return confirm('¿Eliminar «<?= e($x['nombre']) ?>»?')">
  <?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>">
  <button class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50"><?= icon('trash','w-4 h-4') ?></button>
</form>
```

## Operaciones de negocio (solo si tu página mueve stock/dinero) — usar DENTRO de `tx()`
- `ajustarStock($productoId,$sucursalId,$delta,$tipo,$refTipo,$refId,$costo,$motivo)` — $delta + entra, − sale; registra kardex. $tipo ∈ entrada,salida,ajuste,compra,venta,devolucion,transferencia_salida,transferencia_entrada
- `stockActual($productoId,$sucursalId)` → float
- `registrarTransaccion('ingreso'|'gasto', $monto, ['sucursal_id'=>,'cuenta_id'=>,'categoria_id'=>,'descripcion'=>,'referencia_tipo'=>,'referencia_id'=>])`
- `categoriaFinancieraId('ingreso'|'gasto','Nombre')` → int
- `siguienteNCF('B01'|'B02')` → string|null

## Reglas
- NO modifiques archivos compartidos (`config/`, `app/`, `includes/`). Crea SOLO las páginas asignadas.
- Todo el texto de la UI en español (República Dominicana). Moneda RD$, impuesto ITBIS 18%.
- Escapa SIEMPRE con `e()` los datos dinámicos en el HTML.
- Respeta los permisos por acción (ver/crear/editar/eliminar y especiales).
- Sin dependencias externas nuevas (solo Tailwind CDN + Alpine, ya cargados).
