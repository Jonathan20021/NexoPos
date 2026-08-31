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
  Para firmar un evento de alguien que todavía no tiene sesión (login, OTP), pasa
  `['usuario_id'=>$id,'usuario_nombre'=>$n]`.

## Inicio de sesión en dos pasos (`includes/otp.php`) — ver `docs/OTP-LOGIN.md`
El login es de dos fases y **`$_SESSION['user']` no existe hasta el final**.

- `login_intentar($usuario,$password)` → `['estado' => 'ok'|'otp'|'error', 'mensaje' => ...]`.
  `otp` significa «se emitió el código, manda al usuario a `modules/auth/verificar.php`».
- `login_confirmar_otp($codigo, $recordarEquipo)` cierra el flujo.
- `login_establecer_sesion()` es el **único** sitio donde se escribe `$_SESSION['user']`.
  Si alguna vez hay que endurecer el acceso, se endurece ahí y no en cinco páginas.
- Entre paso y paso solo vive `$_SESSION['otp_login']`, que **no concede permisos**:
  nunca leas de ahí para decidir si alguien puede algo.
- Comprueba `otp_disponible()` antes de tocar `login_otp`, `login_intentos` o
  `login_dispositivos`: el código puede desplegarse antes que la migración P14.
- El código se guarda con `password_hash()`, jamás en claro, y el contador de intentos
  se incrementa con un UPDATE condicional **antes** de comparar (si no, dos peticiones
  simultáneas gastan el mismo intento).

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

## Cuentas por pagar, monedas y cotizaciones — ver `docs/CXP-MONEDAS-COTIZACIONES.md`
- **La contabilidad vive en pesos.** Los documentos guardan además lo pactado en otra moneda
  y su tasa. Nunca conviertas al vuelo en un reporte: el pasado cambiaría con el dólar.
- **Antes de registrar un movimiento nuevo en `transacciones`, decide dos cosas:** ¿es gasto
  operativo (`rep_where_gastos`)? ¿mueve efectivo (`rep_where_flujo`)? Un pago a proveedor es
  efectivo pero NO gasto (es inventario); una diferencia cambiaria es gasto pero NO efectivo.
- **Una compra a crédito no saca dinero el día de la compra.** El movimiento va al pagar.
- **Una deuda en dólares no es una deuda en pesos congelada:** pagar los mismos dólares con
  otra tasa salda la deuda igual y genera diferencia cambiaria. No es «pagar de más».
- `registrarVentaPOS()` acepta `$ctx['precios_pactados']` para respetar el precio de una
  cotización. Solo lo activa código del servidor que leyó ese precio de la base — jamás el
  navegador.

## Marketing (`includes/marketing.php` + `modules/marketing/`) — ver `docs/MARKETING.md`
Campañas por correo (Resend, automáticas) y por WhatsApp (wa.me, asistidas).

- **Un destinatario = una fila** en `campana_envios`. De ahí salen la reanudación tras un
  corte, el «nadie recibe dos veces» (`UNIQUE campana_id+canal+destino`), el rastreo por
  persona y la cola de WhatsApp. Nunca envíes recorriendo `clientes` directamente.
- **wa.me no envía**: abre la conversación con el texto escrito y una persona pulsa enviar.
  Lo automático es todo lo demás. No prometas envío masivo automático por WhatsApp.
- `mkt_url_abs()` para cualquier enlace que viaje en un correo: `url()` devuelve ruta
  relativa y en Gmail eso no resuelve.
- El redirector de clics (`t.php`) solo acepta destinos que la campaña publicó. Si añades
  otro enlace rastreado, pásalo por `mkt_destino_permitido()` o abres un phishing.
- Las automatizaciones **no envían**: encolan en la campaña del periodo. Para añadir una,
  escribe su caso en `mkt_auto_candidatos()` devolviendo `[cliente_id, …, periodo]` — el
  `periodo` es la clave que impide repetirle a la misma persona.
- El motor corre con el mismo enganche que las notificaciones (`mkt_tick_si_toca()`), sin
  cron. `modules/marketing/cron.php` es opcional, para envíos con nadie conectado.
- `mkt_segmento_sql()` solo cruza el histórico de ventas cuando alguna regla lo necesita.
  Si añades una regla que lo use, marca `$tieneHistorico`.

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

## ⚠ Interfaz: dos reglas que no se negocian

**1. Responsive SIEMPRE, y comprobado, no supuesto.** Una pantalla no está
terminada hasta que se ha mirado en 360, 390, 768, 1024 y 1440 px. Cuatro trampas
que solo aparecen midiendo:

- **Los campos de formulario van a 16 px en móvil.** Por debajo de eso, iOS hace
  zoom al enfocar el campo y descuadra la pantalla entera. Del `sm` hacia arriba
  ya se puede afinar el tamaño.
- **`100vh` miente en el teléfono.** Cuenta la barra de direcciones que se
  retrae, así que la pantalla queda ~60 px más alta de lo visible y aparece un
  scroll que no lleva a ninguna parte. Usa `min-height:100vh` seguido de
  `min-height:100dvh` (la primera línea es el respaldo).
- **Áreas de toque de 44 px.** Un icono de 34 px se acierta con el ratón, no con
  el pulgar. Vale sobre todo para botones metidos dentro de un campo.
- **Toda tabla ancha va dentro de `overflow-x-auto`.** Nunca debe scrollear la
  página en horizontal: scrollea la tabla dentro de su tarjeta. Comprobación
  rápida en la consola del navegador:
  `document.documentElement.scrollWidth - document.documentElement.clientWidth`
  tiene que dar **0** en todos los anchos.

Alturas fijas dentro de una rejilla son otra fuente de scroll fantasma: con
`height` fija, la fila se sigue midiendo por el contenido. Usa `min-height`.

**2. Cero datos de negocio inventados en la interfaz.** Ni de maqueta, ni de
relleno, ni «para que se vea lleno». La pantalla de acceso llegó a mostrar una
tarjeta con «ventas del mes RD$ 1,284,900», un margen y un ticket promedio
falsos: en la puerta de un sistema contable eso hace dudar de todo lo demás, y si
alguien lo tomara por real sería la facturación a la vista sin autenticarse.
Cuando un espacio pide contenido visual, se llena con **capacidades** («Punto de
venta con NCF», «Inventario por sucursal»), nunca con cifras.

Un estado vacío se resuelve con `empty_state()`, que además ofrece la acción que
lo llena — no con números de ejemplo.

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

## ⚠ Rendimiento: cuatro errores que no se ven con datos de demo

Medido con **60.000 ventas, 180.000 líneas y 180.000 movimientos** (unos dos años con
varias sucursales). Con los 67 registros de la demo ninguno de los cuatro se nota.

**Cómo medir.** Define `SQL_PROFILE` antes de cargar `app/bootstrap.php` y luego llama a
`sqlPerfil()`: devuelve cada consulta de la página con su tiempo, de la más lenta a la más
rápida. Es la única forma fiable de saber qué cuesta; a ojo se acierta poco. Dos avisos:
la **primera** ejecución mide el arranque en frío de PHP y no sirve (una vez creí que
Auditoría tardaba 4,87 s y en caliente eran 0,05 s), y `SHOW PROFILES` del cliente de
MySQL da números que no se parecen a los de la aplicación — mide siempre desde PHP.

**1. `DATE(columna)` en un WHERE anula el índice.** `ventas.fecha` es DATETIME e indexada;
envolverla en `DATE()` obliga a recorrer la tabla entera: medimos **59.558 filas escaneadas
frente a 85** usando un rango. El dashboard lo hacía 19 veces y tardaba 5,5 segundos en abrir.

```php
// MAL: recorre toda la tabla
"... AND DATE(v.fecha) >= '$inicioMes'"
// BIEN: usa idx_v_fecha
"... AND v.fecha BETWEEN '$inicioMes 00:00:00' AND '$finMes 23:59:59'"
```
Cuidado al convertir: el límite superior necesita ` 23:59:59` o se pierde el último día.
`DATE()` en el SELECT o el GROUP BY sí es correcto — lo que importa es que el WHERE filtre
por la columna cruda.

**2. Una consulta por iteración.** El dashboard pedía la serie de 14 días con 14 consultas
en un bucle. Una sola agrupada da lo mismo. Igual la verificación de integridad: una
subconsulta correlacionada por fila tardaba 4.255 ms; agrupando una vez y cruzando, 200 ms.

**3. `ORDER BY ... LIMIT` sobre una consulta con JOIN ordena *después* de unir.** «Las 6
últimas ventas» con cuatro JOIN tardaba **387 ms**: el motor unía las 60.000 filas y
ordenaba al final. Se elige primero por índice y se une después:

```sql
-- MAL: filesort sobre todo el histórico para devolver 6 filas
SELECT v.*, su.nombre, cl.nombre FROM ventas v JOIN ... ORDER BY v.fecha DESC LIMIT 6
-- BIEN: 1 ms. idx_v_fecha se lee al revés y para en la sexta
FROM (SELECT id FROM ventas WHERE ... ORDER BY fecha DESC LIMIT 6) ult
JOIN ventas v ON v.id = ult.id JOIN ...
```

**4. Un índice que no cubre obliga a ir a buscar la fila.** Los reportes entran por
`ventas` filtrando fecha y saltan a `venta_detalles` por `venta_id`; con un mes de 10.800
ventas son ~32.000 saltos aleatorios a disco. El top de productos del dashboard tardaba
**3.039 ms**. Metiendo las columnas que se leen dentro del propio índice
(`idx_vd_venta_cobertura`, ver `database/migracion_rendimiento_p8.sql`) la unión se
resuelve sin tocar la tabla: **318 ms**. En el `EXPLAIN` se ve como `Using index`.

**Cuidado con SUM sobre un JOIN que multiplica filas.** Sumar `v.subtotal` cruzando con
`venta_detalles` cuenta el total de la venta una vez por línea: en el comparativo daba
**137,3 millones donde el ingreso real era 45,8** — el triple exacto, con 3 líneas por
factura. Si la consulta entra por el detalle, suma el detalle (`vd.subtotal`).

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

**Y al revés: MariaDB rechaza el alias de una función de grupo dentro de `HAVING`.**
MySQL lo acepta; MariaDB 10.4 responde `1247 Reference 'costo' not supported (reference to
group function)`. Repite la expresión:

```sql
-- ROMPE en MariaDB
SELECT SUM(vd.cantidad * vd.costo_unitario) AS costo ... HAVING costo > ingresos
-- CORRECTO en ambos
... HAVING SUM(vd.cantidad * vd.costo_unitario) > SUM(vd.subtotal - vd.descuento)
```
En `ORDER BY` el alias sí vale en los dos motores; el problema es solo `HAVING`.

**No pongas `ONLY_FULL_GROUP_BY` en el `sql_mode` local para «igualar» los entornos.**
Se probó: MariaDB 10.4 no hace esa deducción por JOIN y rechaza consultas que MySQL 8 acepta,
así que genera decenas de falsas alarmas. La forma de verificar es ejecutar las páginas
contra una copia de la base de producción (o contra la de producción, en solo lectura).

## Códigos de barras y escaneo (`includes/barcode.php`) — ver `docs/CODIGOS-BARRAS.md`
- `productos.codigo_barras` es **UNIQUE**. Guarda `NULL` cuando no hay código, nunca `''`:
  los NULL no chocan entre sí en un índice único, la cadena vacía sí.
- `barcode_validar($v)` es la única puerta de entrada. Solo rechaza un **EAN-13** con el
  verificador malo (ningún lector emite uno inválido, así que está tecleado a mano). Los
  numéricos de 8/12/14 se aceptan: son códigos internos heredados de Excel y se imprimen
  en Code 128.
- `barcode_svg($v, $opts)` dibuja en SVG. Nunca uses una imagen: a 38 mm un PNG sale
  difuminado y el lector falla.
- `barcode_generar_interno()` da un EAN-13 con prefijo **200** (rango GS1 de circulación
  restringida: jamás choca con un fabricante). Usa el contador atómico, no `MAX()+1`.
- En el navegador: `escaner_script()` en la página y `NexoEscaner.abrir({onLeer})`.
  Para pistolas USB, `NexoEscaner.teclado({onCodigo})`. **`data-escaner` en un campo
  significa «este campo atiende él mismo el disparo»** y el oyente global se calla; sin
  esa separación un disparo se registra dos veces.
- La cámara exige **HTTPS** y `Permissions-Policy: camera=(self)` en el `.htaccess`.

## Cumplimiento sanitario y lotes (`includes/sanidad.php`) — ver `docs/SANIDAD-Y-AUDITORIAS.md`
- El control se activa **producto a producto** (`regulado`, `controla_lote`). Un producto no
  regulado se comporta exactamente igual que antes del módulo.
- **Todo movimiento de stock pasa por `ajustarStock()`**, que ahora acepta un 9.º parámetro
  `$lote`. Si escribes directo en `inventario_stock`, rompes la trazabilidad en silencio.
- Salidas por **FEFO** (primero lo que antes vence), no FIFO. Si no hay existencia apta
  (todo vencido o bloqueado), la operación **lanza y revierte entera**: antes eso que vender
  producto vencido.
- Una entrada de mercancía con `controla_lote` **exige** número de lote.
- `lote_movimientos` es el libro de trazabilidad: una fila por lote tocado, porque una línea
  de venta puede consumir dos lotes y eso no cabe en una columna.
- Comprueba `san_disponible()` antes de tocar sus tablas: el código puede desplegarse antes
  que la migración.

## Tiendas, costeo de importaciones y Dirección — ver `docs/TIENDAS-Y-DIRECCION.md`

**Tiendas** (`includes/tiendas.php`) — la marca con la que se factura, no un local:
- `tiendas_hay()` es el **interruptor**: sin ninguna tienda creada el sistema se comporta
  igual que antes de la migración. Compruébalo antes de exigir marca en cualquier pantalla.
- El **emisor fiscal es la empresa**: `tienda_marca()` devuelve siempre el RNC de la empresa.
  La tienda aporta logo, nombre comercial, dirección impresa y política de devolución.
- `ventas.tienda_id` está **congelado**. Nunca deduzcas la marca de una factura emitida a
  partir del producto: reimprimirla mañana daría otro logo.
- Una factura lleva **un solo logo**: un carrito con artículos de dos marcas se rechaza.
- Para imprimir con marca: `pdf_brand_header($titulo, $sub, $marca)`. Sin el tercer
  parámetro se comporta como siempre.

**Liquidaciones** (`includes/liquidaciones.php`) — el costo real puesto en almacén:
- Es un documento de **costo, no de dinero**: no registra la deuda al proveedor ni el pago
  de los gastos. Duplicarlo inflaría los gastos del mes.
- El **ITBIS de aduana no es costo** (`al_costo = 0`): se compensa con el ITBIS de la venta.
- El reparto cuadra al centavo: el resto se le suma a la línea de mayor base.
- Aplicar recorre las líneas **en orden de `producto_id`** y guarda `costo_anterior`, que es
  lo único que permite anular sin dejar el margen torcido para siempre.

**Dirección** (`includes/direccion.php`) — usa el mismo criterio contable que los reportes.
`dir_scope()` combina sucursal + tienda. La carga histórica **no mueve stock, no consume NCF
y no genera movimientos de caja**, y todo lo cargado lleva su `importacion_id` para poder
revertir el lote entero.

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

## Activos fijos y depreciación (`includes/activos.php`, `modules/finanzas/activos.php`)
Método **línea recta**: cuota mensual = (costo − valor residual) ÷ vida útil en meses, desde
el **mes siguiente** al de adquisición. La última cuota se ajusta para no pasarse del valor
residual.

- La depreciación se registra como gasto **sin `cuenta_id`**: baja la utilidad pero **no mueve
  efectivo**. Por eso el flujo de caja la excluye con `rep_where_flujo()`. Si añades otro
  movimiento no monetario (provisiones, amortizaciones), añádelo a esa función.
- `UNIQUE (activo_id, periodo)` impide depreciar dos veces el mismo mes. La corrida releé el
  activo con `FOR UPDATE` y comprueba el periodo antes de insertar.
- Un activo con depreciación registrada **no permite cambiar** costo, vida útil, valor residual
  ni fecha: descuadraría asientos que ya afectaron el resultado. Se da de baja y se registra otro.
- `activosResumen()` alimenta el activo fijo del balance general (costo − depreciación).
- La **categoría DGII** (art. 287) se guarda solo como referencia: el cálculo fiscal dominicano
  usa saldo decreciente por categorías y difiere del contable, que es el que lleva el sistema.
- `activosDisponible()` se comprueba **antes de cualquier consulta** en la página, para que
  desplegar el código antes que la migración muestre un aviso y no un error de tabla inexistente.

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

## Pruebas

```bash
php pruebas/nomina.php
php pruebas/ecf.php
```

`pruebas/nomina.php` cubre el cálculo de nómina dominicana (`includes/nomina.php`):
tasas de AFP y SFS, la escala del ISR y sus bordes, el prorrateo por días y los
casos que ya se rompieron una vez. Son funciones puras: no tocan la base ni
necesitan sesión. Devuelve 0 si todo pasa.

**Si tocas el cálculo de nómina, corre esto antes de commitear.** Un error ahí no
se ve en pantalla: se ve cuando alguien cobra de menos.

`pruebas/ecf.php` cubre la lectura de las respuestas del proveedor de e-CF
(`includes/ecf_api.php`). Las tramas de prueba son respuestas reales del
cliente. Protege una trampa concreta: **la respuesta trae dos dictámenes**. El
del sobre (`status.code` 0, «Transacción exitosa») solo dice que la consulta
llegó y volvió; el del comprobante (`data.responseCode`, `data.responseMessage`)
es el de la DGII. Leer el primero hacía que una factura rechazada apareciera en
pantalla con el motivo «Transacción exitosa».
