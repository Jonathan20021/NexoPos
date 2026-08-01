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
grid, mail, phone, map, minus, save, lock, sun, history, barcode, megaphone, bank, scale, file,
target, trending-down, card, coins, book, pulse.
(Un nombre inexistente cae en `box`, así que no rompe la página pero se nota.)

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
- `tiempoRelativo($fecha)` → «hace 5 min»; `mesNombre($n,$corto=false)`
- Los `flash()` se pintan como **avisos flotantes** arriba a la derecha y se van solos
  (los de tipo `error` se quedan hasta que el usuario los cierre).
- Las confirmaciones de borrado se muestran en un **modal**: basta con dejar el
  `onsubmit="return confirm('...')"` de siempre; `includes/layout/footer.php` lo
  reenruta al modal global. Sin JavaScript sigue funcionando el confirm nativo.

## Búsqueda global (`includes/busqueda.php`)
Un solo cuadro en la barra superior (`Ctrl`/`⌘` + `K`) que encuentra productos, clientes,
ventas, proveedores, compras, empleados y oportunidades, y además navega a cualquier
pantalla. **Respeta permisos y sucursal activa**: lo que no puedes ver en su módulo
tampoco sale aquí.

- `buscar_global($q, $tope)` → grupos con items `[titulo, subtitulo, etiqueta, etiqueta_color, url]`
- `buscar_navegacion($q)` → pantallas de `nav_groups()` filtradas por permiso
- `buscar_atajos()` → accesos rápidos del buscador vacío
- Endpoint JSON: `modules/busqueda/api.php` (401 si no hay sesión)
- Respaldo sin JavaScript: `modules/busqueda/index.php` (misma lógica, renderizada en servidor)

Para añadir una entidad, agrega su bloque en `buscar_global()` dentro de un `if (can(...))`
y aplica `sucursalScope()` cuando la tabla tenga `sucursal_id`. Limita siempre con `$tope`.

## Notificaciones (`includes/notificaciones.php`)
Una fila por **situación viva** del negocio, no por evento: la `clave` deduplica y,
cuando el problema se resuelve, la notificación pasa a `resuelta` sola. El barrido
corre solo (sin cron) como máximo cada `NOTIF_SCAN_MINUTOS`.

Para añadir una alerta nueva: escribe una función `notif_gen_<algo>()` que arme la
lista de items y llame a `notif_sync('<tipo>', $items)`, y regístrala en `notif_generar()`.
Cada item admite: `clave` (única), `titulo`, `mensaje`, `categoria`, `prioridad`
(`critica|alta|media|baja`), `url`, `icono`, `color`, `sucursal_id`, `usuario_id`, `permiso`.
El `permiso` decide quién la ve; `usuario_id` la dirige a una sola persona.

## Reportes (`includes/reportes.php` + `modules/reportes/`)
Todos los reportes comparten periodo, alcance por sucursal y estética:

```php
require_perm('reportes.finanzas');            // ver, ejecutivo, finanzas, contabilidad, operacion
$p = rep_periodo('mes');                      // desde/hasta/ini/fin/label + periodo anterior
[$scope, $scopeP] = rep_scope('v.sucursal_id');
if (export_solicitado()) { export_tabla(...); }   // Excel y PDF de cada reporte
layout_start('Título', rep_subtitulo($p), rep_barra_titulo());
echo rep_abrir('Título', $p, ['sucursal' => true]);   // impresión + barra de filtros
echo rep_kpis([...]);                          // tarjetas con variación vs. periodo anterior
echo rep_seccion('Bloque','subtítulo','icono','color') . rep_tabla($headers,$filas) . rep_fin();
layout_end();
```
Otros: `rep_delta()`, `rep_barra()`, `rep_color()`, `rep_color_nombre()`, `rep_meses_atras()`,
`rep_mes_label()`, `rep_alcance_sucursal()`. Para dar de alta un reporte nuevo, añádelo al
arreglo de `rep_catalogo()`: el hub y los permisos salen de ahí.

**Criterio contable (obligatorio para que los reportes cuadren entre sí):**
- Ingresos = `subtotal − descuento` (SIN ITBIS: el ITBIS se recauda para la DGII).
- Utilidad bruta = ingresos − `ventas.costo_total`.
- Gastos operativos: usa `rep_where_gastos()` / `rep_gastos_operativos()`. **Nunca**
  sumes `transacciones` de tipo gasto a secas: incluyen la compra de mercancía
  (que es inventario, no gasto) y las devoluciones (que ya restan del ingreso).
- Otros ingresos: `rep_where_otros_ingresos()` (excluye ventas y cobros de abonos).

## Gráficos (`includes/charts.php`, SVG puro, sin librerías)
`sparkline()`, `barChart()`, `barChartComparado()`, `lineChart($series,$labels,$opts)`,
`donutMulti($items,$centroTitulo,$centroValor)`, `barraApilada()`, `donut()`, `numAbrev($n)`.

`lineChart()` calcula el margen izquierdo a partir de la etiqueta más ancha del eje y
abrevia los valores (`200K`, `1.25M`); la cifra exacta va en el tooltip de cada punto.
No fijes márgenes a ojo: con cifras grandes el eje se recorta contra el borde.

## Distribución: nada de huecos en blanco
- Toda tarjeta que sea **celda directa de un grid** se estira sola a la altura de la fila
  (regla `.grid > .card:not([class*="h-"])` en el layout). Si la tarjeta necesita otra
  altura, declárala (`h-fit`) y la regla no interviene.
- Cuando hay un wrapper `<div>` entre el grid y la tarjeta, ponle `h-full` a la tarjeta.
  `rep_seccion()` ya lo trae y además reparte el cuerpo con `flex-1`.
- El contenido de una tarjeta con gráfico o barras va en
  `<div class="px-5 pb-5 flex-1 flex flex-col justify-center">` para que se centre en vez
  de quedarse pegado arriba.
- Una tarjeta sin datos usa `empty_state($titulo,$mensaje,$icono,$accionHtml)`, nunca un
  `<p>` suelto: el estado vacío debe llenar el espacio y ofrecer la acción que lo resuelve.
- Ajusta las columnas al contenido (`lg:grid-cols-5` con `col-span-3`/`col-span-2` para
  tabla + gráfico) en vez de dos mitades iguales con pesos distintos.

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

## ⚠ Desarrollo en MariaDB, producción en MySQL 8

El XAMPP local corre **MariaDB 10.4** con `sql_mode` permisivo. El servidor del cliente corre
**MySQL 8.0 con `ONLY_FULL_GROUP_BY`**. Hay consultas que aquí funcionan y allá revientan con
el error **1055**, y no te enteras hasta que el cliente abre la pantalla.

**La regla:** si agrupas por una **expresión**, todas las columnas no agregadas del SELECT
tienen que ir también en el GROUP BY.

```sql
-- ROMPE en producción (MySQL 8): p.nombre no depende de la expresión agrupada
SELECT COALESCE(p.nombre, vd.descripcion) AS producto, SUM(vd.cantidad)
  FROM venta_detalles vd LEFT JOIN productos p ON p.id = vd.producto_id
 GROUP BY COALESCE(p.id, vd.descripcion)

-- CORRECTO: la etiqueta mostrada entra en el GROUP BY
 GROUP BY COALESCE(p.id, vd.descripcion), COALESCE(p.nombre, vd.descripcion)
```

Agrupar por una **columna** (`GROUP BY v.sucursal_id` con `JOIN sucursales su ON su.id =
v.sucursal_id`) sí funciona: MySQL 8 deduce la dependencia a través de la igualdad del JOIN.

**No pongas `ONLY_FULL_GROUP_BY` en el `sql_mode` local para «igualar» los entornos.**
Se probó: MariaDB 10.4 no hace esa deducción por JOIN y rechaza consultas que MySQL 8 acepta,
así que genera decenas de falsas alarmas. La forma de verificar es ejecutar las páginas
contra una copia de la base de producción (o contra la de producción, en solo lectura).

## Concurrencia (OBLIGATORIO si tu página escribe) — ver `docs/CONCURRENCIA.md`
Varias sucursales operan a la vez. Estas reglas no son opcionales:

1. **Correlativos:** `nextNumero($tabla,$col,$prefijo)` reserva el número con un contador
   atómico. **Nunca** hagas `SELECT MAX(...)+1`: con dos cajas vendiendo a la vez genera el
   mismo número y una venta muere contra el UNIQUE. Para *mostrar* el próximo número en un
   formulario usa `previewNumero()`, que no lo consume.
2. **Orden de bloqueo:** si recorres varios productos moviendo stock, hazlo **siempre en
   orden de `producto_id`** (`ORDER BY producto_id` o `usort()`). Órdenes distintos entre dos
   procesos = interbloqueo.
3. **Transacciones:** usa `txReintentable(fn)` en vez de `tx(fn)` cuando la operación mueva
   stock o dinero. Reintenta solo los choques transitorios de InnoDB (1213/1205); los errores
   de negocio suben tal cual.
4. **Saldos:** siempre `UPDATE ... SET balance = balance ± ?`. Nunca leer, sumar en PHP y
   escribir: se pierden actualizaciones.
5. **Unicidad condicional** (una sesión de caja abierta por cajón): bloquea la fila padre con
   `SELECT ... FOR UPDATE` dentro de la misma transacción que inserta.
6. Las validaciones que dependen de un valor bloqueado van **después** del `FOR UPDATE`.

Comprobación permanente: Administración → **Integridad de datos**
(`modules/admin/integridad.php`), 14 chequeos de solo lectura.

## Conteo físico de inventario (`modules/inventario/conteos.php` y `conteo.php`)
La toma de inventario. Flujo: **abrir → capturar → aplicar** (o cancelar).

- Al abrir se congela `stock_teorico` y `costo_unitario` de cada producto del alcance en
  `conteo_detalles`. Solo puede haber **un conteo abierto por sucursal**.
- `stock_contado = NULL` significa «sin contar»; esos productos **no se tocan** al aplicar.
- Al aplicar se ajusta por la **diferencia** (`contado − teórico`), no por el valor absoluto.
  Es lo correcto porque la tienda sigue vendiendo mientras se cuenta: forzar el absoluto
  borraría del inventario las ventas hechas durante el conteo. La columna «Ahora» avisa
  cuándo el stock se movió desde la foto inicial.
- Si un ajuste dejaría la existencia en negativo, esa línea se **omite y se avisa**, en vez
  de abortar el conteo entero.
- `conteos.aplicar` y `conteos.cancelar` **no** se conceden automáticamente: aplicar es la
  firma que mueve el inventario y se otorga a mano desde Roles.

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
