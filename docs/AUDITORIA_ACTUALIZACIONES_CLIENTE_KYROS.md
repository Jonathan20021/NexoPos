# Auditoría técnica — Actualizaciones solicitadas por la cliente

**Sistema:** NexoPOS (Kyros Solutions) · POS/ERP multi-sucursal
**Fecha:** 2026-07-10
**Alcance:** auditoría previa a implementación. No se modificó código en esta fase.

---

## 1. Stack y arquitectura reales

> El prompt asumía Laravel (`app/Models`, `routes/web.php`, Blade). **No es Laravel.**
> La matriz se adapta a la estructura real.

| Aspecto | Realidad del proyecto |
|---|---|
| Lenguaje | PHP 8 procedural (sin framework MVC) |
| Enrutado | Un archivo `.php` por pantalla bajo `modules/<área>/`. URLs limpias vía `.htaccess` (mod_rewrite) |
| Base de datos | MySQL/MariaDB. Acceso por PDO en `config/database.php`, helpers `q/qOne/qAll/qVal/dbInsert/dbUpdate/tx` en `app/helpers.php` |
| Esquema | `database/schema.sql` (fuente de verdad) + migraciones idempotentes en `database/migracion_*.sql`. **No hay ORM ni migraciones versionadas tipo Laravel** |
| Vistas | HTML + Tailwind (CDN) + Alpine.js embebidos en cada `.php`. Layout en `includes/layout/` |
| Lógica de negocio | Centralizada en `includes/operaciones.php` (`ajustarStock`, `registrarTransaccion`, `siguienteNCF`, `cajaSesionAbierta`…) |
| Autorización | `app/permissions.php` (catálogo) + `require_perm()` por página + filtro de menú en `includes/components.php::nav_groups()` |
| Correo | Resend vía `includes/mail.php` (ya en producción) |
| Bootstrap | `app/bootstrap.php` carga todo el núcleo |

**40 tablas** en producción. **35 pantallas** en 7 áreas: `admin, auth, dashboard, finanzas, inventario, pos, rrhh`.

---

## 2. Matriz de auditoría por requerimiento

| # | Requerimiento | Estado actual | Evidencia encontrada en el código | Archivos / tablas relacionados | Riesgo de duplicidad | Acción recomendada |
|---|---|---|---|---|---|---|
| 1 | **Facturación de muestras en RD$0.00** | **No implementado** | `productos.tipo` es `enum('producto','servicio')` — no existe tipo `muestra`. `guardar_venta.php:37` fija el precio SIEMPRE desde `productos.precio_venta`; no hay línea manual ni bandera de muestra. Por eso la cliente usa 0.01 | `modules/pos/guardar_venta.php`, `modules/pos/index.php`, tabla `productos`, `venta_detalles` | **Medio** — no crear tabla nueva; extender `venta_detalles` con `es_muestra` y `productos.tipo` | Añadir bandera de muestra a nivel de línea; permitir precio 0.00 solo con permiso `ventas.muestra`; marcar "MUESTRA" en ticket/detalle; excluir de ingresos y comisiones; reporte aparte |
| 2 | **Control de caja por turno/usuario/sucursal** | **Existe pero necesita ajustes** | `caja_sesiones` (27 filas) con apertura/cierre, monto esperado, diferencia, notas. `cajas` (3) = terminales por sucursal. `operaciones.php::cajaSesionAbierta()`, `modules/pos/caja.php` con fórmula de cierre real. **Falta:** bloqueo global "no abrir si otra caja de esa terminal quedó abierta", concepto explícito de turno, filtros avanzados | `modules/pos/caja.php`, tabla `caja_sesiones`, `caja_movimientos`, `cajas`, `operaciones.php` | **Alto** — el módulo ya existe y es sólido; NO duplicar | Añadir regla de una sola sesión abierta por caja/terminal; campo `turno` opcional; filtros por usuario/turno/estado; permiso para corregir cierres |
| 3 | **Roles y permisos por usuario** | **Implementado** | `app/permissions.php` con catálogo por grupos; 75 permisos, 271 asignaciones `rol_permisos`, 6 roles (incluye "Cajero"). `require_perm()` en cada módulo; menú filtrado en `components.php:32` (`array_filter … can($it[3])`) | `app/permissions.php`, `app/auth.php`, `includes/components.php`, tablas `roles, permisos, rol_permisos` | **Crítico** — sistema maduro; solo extender | NO duplicar. Verificar/afinar el rol "Cajero" para que solo vea POS+caja+clientes; agregar permiso `ventas.muestra` cuando se haga el req.1 |
| 4 | **Finanzas y reportes DGII (606/607/608/IT-1)** | **Parcialmente implementado** | 606/607/608 completos: `includes/dgii_reportes.php`, `modules/finanzas/dgii.php`, tabla `comprobantes_anulados`, campos fiscales en `ventas`/`compras`. **Falta IT-1** (no existe). No hay catálogo de cuentas contable formal ni conciliación bancaria ni CxP | `modules/finanzas/dgii.php`, `includes/dgii_reportes.php`, `modules/finanzas/index.php`, `cuentas.php`, tablas `transacciones, cuentas_financieras, categorias_financieras, ncf_secuencias` | **Alto** en DGII (no rehacer); **Bajo** en IT-1/conciliación (no existen) | 606/607/608: no tocar salvo ajustes. IT-1: diseñar como resumen derivado de `ventas`/`transacciones`. Conciliación y CxP: fase posterior. La cliente NO hace 609 |
| 5 | **Facturación electrónica externa ("Luganes"/proveedor)** | **No implementado** | Cero referencias a e-CF, proveedor externo, webhooks o estados de comprobante electrónico. El sistema solo emite NCF internos (`operaciones.php::siguienteNCF`) | — (no existe) | **Nulo** | Diseñar capa adaptable (interfaz `ProveedorFacturacionElectronica`, tabla de config + log request/response + estados). NO implementar integración real sin credenciales |
| 6 | **Cuentas financieras conectadas a caja/ventas** | **Existe pero necesita ajustes** | `cuentas_financieras` (3) con tipo efectivo/banco, balance. `registrarTransaccion()` mueve el balance atómicamente desde ventas/compras/abonos. **Falta:** tipo tarjeta/transferencia explícito, saldo inicial formal, vínculo directo caja↔cuenta | `modules/finanzas/cuentas.php`, `operaciones.php::registrarTransaccion/cuentaFinancieraIdPorTipo`, tablas `cuentas_financieras, transacciones` | **Alto** — no duplicar | Extender enum de tipos; añadir saldo inicial; reporte por cuenta/período; base para conciliación |
| 7 | **Comisiones de vendedores** | **Parcialmente implementado** | `usuarios.comision_pct`, `modules/finanzas/comisiones.php` calcula sobre `SUM(subtotal-descuento)` de ventas `completada`, con estado pagada y registro en finanzas. **Falta:** excluir devoluciones parciales y muestras; estados pendiente/aprobada; exportación | `modules/finanzas/comisiones.php`, columna `usuarios.comision_pct`, tabla `ventas` | **Alto** — no duplicar | Restar `devolucion_detalles` de la base; excluir líneas de muestra (dep. req.1); añadir estados y exportación |
| 8 | **Sucursales / almacenes / terminales / transferencias** | **Parcialmente implementado** | `sucursales` (2), `cajas`=terminales, `transferencias`+`transferencia_detalles` con estados enviada/recibida/anulada y kardex vía `ajustarStock`. `inventario_stock` es **por sucursal**. **Falta:** concepto de "almacén" separado de sucursal; estados borrador/rechazada; aprobación explícita | `modules/inventario/transferencias.php`, `stock.php`, `movimientos.php`, tablas `sucursales, cajas, transferencias, transferencia_detalles, inventario_stock, movimientos_inventario` | **Alto** — inventario multi-sucursal ya existe | Evaluar si "almacén" es realmente distinto de "sucursal" para esta cliente; añadir estados de transferencia faltantes; NO rehacer el kardex |
| 9 | **Modo offline / facturar sin internet** | **No implementado** | Cero PWA, service worker, IndexedDB o cola de sincronización. La app es 100% servidor-dependiente (cada venta es un POST que emite NCF y descuenta stock en el servidor) | — (no existe); afecta `guardar_venta.php`, `operaciones.php`, `ncf_secuencias` | **Nulo** técnico, **Crítico** de arquitectura | Requiere diseño en 2 fases (ver PLAN). Afecta NCF, inventario y caja: no hacer superficial. Es el punto de mayor complejidad |
| 10 | **Vista de ventas solo para facturación** | **Existe pero necesita ajustes** | `modules/pos/index.php` es un POS Alpine dedicado, protegido por `pos.ver`. Ya está separado del admin. **Falta:** garantizar que la vendedora no vea costos/utilidad y condicionar a caja abierta | `modules/pos/index.php`, `guardar_venta.php` | **Medio** | Reforzar gating del rol Cajero; ocultar costo/utilidad; exigir caja abierta; mostrar meta personal (dep. req.11) |
| 11 | **Metas de venta / KPI por empleado y sucursal** | **No implementado** | El "meta" hallado en grep es CSS (`ticket.php:46`, `pdf.php:43`), no un módulo. No existen tablas ni pantallas de metas | — (no existe) | **Nulo** | Crear módulo nuevo: tabla `metas` (sucursal/empleado, período, objetivo, moneda); progreso derivado de `ventas`; vista simple para vendedora y panel para gerencia; excluir anuladas/devoluciones/muestras |
| 12 | **Reportes gerenciales** | **Implementado** | `modules/finanzas/reportes.php` ya calcula Estado de Resultados, Costo de venta, Utilidad, Top 10 productos, ventas por vendedor/sucursal. `dashboard/index.php` con KPIs. Usa `includes/charts.php`, `pdf.php`, `excel.php` | `modules/finanzas/reportes.php`, `modules/dashboard/index.php`, `includes/charts.php, pdf.php, excel.php` | **Crítico** — no duplicar | Solo mejorar filtros (categoría/marca), permisos y conexión con metas (req.11) |
| 13 | **Validación obligatoria de clientes** | **Parcialmente implementado** | `modules/pos/clientes.php` solo valida `nombre === ''` y formato de email. **Falta:** teléfono obligatorio, prohibir números en el nombre, documento obligatorio, anti-duplicados por documento/teléfono, registro de creador | `modules/pos/clientes.php`, `tienda/index.php` (cliente de la tienda), tabla `clientes` | **Bajo** — solo endurecer validación existente | Añadir validación backend (fuente de verdad) + frontend; estrategia gradual para clientes viejos incompletos; NO bloquear al "Cliente Genérico" (id 1) |
| 14 | **Promociones por marca/temporada/categoría/género** | **No implementado** | Cero referencias a promociones/campañas/descuentos por regla. El descuento actual es manual por venta (`ventas.descuento`) | — (no existe); relacionado con `productos, marcas, categorias` | **Nulo** | Módulo nuevo: `campanas` + `campana_productos`/reglas por marca/categoría, vigencia, sucursal; aplicar descuento en POS; reporte por campaña |
| 15 | **Medición de marketing / Instagram** | **No implementado** | Los hits "origen/canal" son de transferencias y del origen contable de transacciones (`finanzas/index.php:175`), no del canal de captación de una venta | — (no existe); afecta `ventas`/`clientes` | **Nulo** | Añadir campo `canal_venta`/`campana_id` a `ventas` (o `clientes`); reporte de ventas por canal/campaña; ROI manual |
| 16 | **Campañas automáticas por correo** | **Parcialmente implementado (infraestructura)** | Ya existe envío por Resend (`includes/mail.php`, `correos_pedido.php`, tabla `correos_enviados`). **Falta:** segmentación por historial, plantillas de campaña, listas, automatización | `includes/mail.php`, `includes/correos_pedido.php`, tabla `correos_enviados` | **Medio** — reutilizar el motor de correo existente, NO crear otro | Construir módulo de campañas sobre `mail.php`; segmentar por compras (`ventas`/`venta_detalles`); registrar en `correos_enviados`; NO hardcodear SMTP |

---

## 3. Resumen por estado

| Estado | Requerimientos |
|---|---|
| **Implementado** (no tocar, solo afinar) | #3 Roles/permisos · #12 Reportes gerenciales |
| **Existe pero necesita ajustes** | #2 Caja · #6 Cuentas financieras · #10 Vista POS |
| **Parcialmente implementado** | #4 DGII (falta IT-1/contabilidad) · #7 Comisiones · #8 Sucursales/transferencias · #13 Validación clientes · #16 Correo (motor listo) |
| **No implementado** | #1 Muestras 0.00 · #5 Fact. electrónica externa · #9 Offline · #11 Metas/KPI · #14 Promociones · #15 Marketing/Instagram |
| **No se pudo confirmar** | — (todo verificado con evidencia directa) |

---

## 4. Hallazgos críticos para no romper nada

1. **`inventario_stock` ya es por sucursal.** Cualquier trabajo de "almacén" debe partir de ahí, no crear un stock paralelo.
2. **El precio de venta nunca viene del cliente** (`guardar_venta.php:37`): las muestras a 0.00 exigen una bandera de línea con permiso, no permitir precio libre.
3. **`registrarTransaccion()` es la única vía que mueve balances de cuentas.** Todo lo financiero nuevo debe pasar por ahí para no descuadrar.
4. **La caja ya tiene su fórmula de cierre exacta** (`caja.php`): el bloqueo de apertura se añade encima, sin rehacerla.
5. **El motor de correo (Resend) ya está en producción.** El módulo de campañas (#16) lo reutiliza; no se crea otro.
6. **Comisiones ya descuenta ventas anuladas** pero no devoluciones parciales ni muestras: es un ajuste, no un módulo nuevo.
7. **La cliente NO hace 609.** El módulo DGII correctamente solo cubre 606/607/608; IT-1 es lo único fiscal que falta.

---

## 5. Próximos entregables

- `docs/PLAN_IMPLEMENTACION_ACTUALIZACIONES_CLIENTE_KYROS.md` — plan por fases con prioridades P0–P3.
- `docs/REPORTE_FINAL_ACTUALIZACIONES_CLIENTE_KYROS.md` — al cerrar cada fase implementada.
