# Reporte final — Implementación P0 (Kyros)

**Sistema:** NexoPOS · PHP 8 procedural · MySQL/MariaDB
**Fecha:** 2026-07-10
**Fase:** P0 (crítico). P1–P3 quedan planificadas en `PLAN_IMPLEMENTACION_ACTUALIZACIONES_CLIENTE_KYROS.md`.

---

## 1. Qué se implementó

| P0 | Resultado |
|---|---|
| P0.1 Muestras a RD$0.00 | **Nuevo.** Bandera de línea con permiso; precio 0; excluida de ingresos y comisiones; descuenta inventario; etiqueta "MUESTRA" en ticket y detalle |
| P0.2 Control de caja | **Ajustado.** Bloqueo de apertura por terminal (no solo por usuario); campo `turno`; filtros por cajero/turno/fecha/estado |
| P0.3 Permiso `ventas.muestra` | **Nuevo.** En el catálogo + concedido a Admin/Gerente; el Cajero NO lo tiene por defecto |
| P0.4 Validación de clientes | **Ajustado.** Nombre sin números, teléfono obligatorio, documento para crédito, anti-duplicados, `created_by` |
| P0.5 POS seguro | **Ya cumplía.** El POS ya exige caja abierta y no expone costo/utilidad. Verificado, sin cambios |
| P0.6 Metas KPI | **Base creada.** Tabla `metas_ventas` (sin UI). Documentada para P1 |

---

## 2. Migraciones creadas

- **`database/migracion_actualizaciones_cliente_p0.sql`** (idempotente, MySQL 8 / MariaDB):
  columnas de muestra en `venta_detalles`, `caja_sesiones.turno`, `clientes.created_by`,
  permiso `ventas.muestra` (+ concesión a Admin/Gerente), y tabla `metas_ventas`.
  Incluye verificación y reversión.

Aplicada en **local**. **Pendiente en producción** (ver §9).

## 3. Permisos agregados

| Permiso | Descripción | Roles con acceso |
|---|---|---|
| `ventas.muestra` | Facturar líneas como muestra (RD$0.00) | Super Admin (automático), Administrador, Gerente de Sucursal. **No** el Cajero por defecto |

Para que una cajera pueda dar muestras: **Roles y Permisos → Cajero → marcar "Facturar muestras"**.

## 4. Tablas / columnas agregadas

| Tabla | Cambio |
|---|---|
| `venta_detalles` | `es_muestra TINYINT(1)`, `precio_original DECIMAL(12,2)` |
| `caja_sesiones` | `turno VARCHAR(50)` |
| `clientes` | `created_by INT UNSIGNED` |
| `metas_ventas` | **tabla nueva** (P0.6) |

Todo reflejado también en `database/schema.sql` para instalaciones nuevas.

## 5. Archivos modificados

| Archivo | Cambio |
|---|---|
| `database/migracion_actualizaciones_cliente_p0.sql` | **nuevo** |
| `database/schema.sql` | columnas de muestra/turno/created_by + tabla `metas_ventas` |
| `install/index.php` | excluye `ventas.muestra` del Cajero por defecto |
| `app/permissions.php` | añade `ventas.muestra` al catálogo |
| `includes/operaciones.php` | `cajaAbiertaPorCaja()`, `validarAperturaCaja()` |
| `modules/pos/guardar_venta.php` | muestras: precio 0, sin ingreso/comisión, con costo y stock, trazabilidad |
| `modules/pos/index.php` | toggle "Marcar muestra" (con permiso), totales que excluyen muestras, envío de bandera |
| `modules/pos/ticket.php` | etiqueta `[MUESTRA]` en el ticket |
| `modules/pos/ventas.php` | badge "MUESTRA" en el detalle |
| `modules/pos/caja.php` | bloqueo por terminal, `turno`, filtros del historial |
| `modules/pos/clientes.php` | validación reforzada backend + frontend |

## 6. Cómo quedan muestras, caja, clientes

**Muestras.** El precio nunca viene del navegador: el servidor re-valida el permiso,
pone la línea en 0, la excluye del subtotal/ITBIS (por eso queda fuera de ingresos y
comisiones), pero **sí descuenta inventario** y **sí cuenta su costo** (es una entrega
real de producto). Guarda `precio_original` para saber cuánto se regaló. El ticket y
el detalle muestran "MUESTRA".

**Caja.** No se puede abrir una caja/terminal que otra persona dejó abierta; el mensaje
dice quién la tiene y desde cuándo. La fórmula de cierre **no se tocó**. Se agregó turno
opcional y filtros. `cajaSesionAbierta()` (por usuario) se conservó intacta.

**Clientes.** El backend es la fuente de verdad. El Cliente Genérico (id 1) queda exento
de teléfono/documento. Clientes viejos incompletos se consultan sin problema, pero al
**editarlos** deben completarse.

## 7. Impacto en comisiones y reportes

No se tocó `comisiones.php` ni `reportes.php`. Como las muestras quedan fuera de
`ventas.subtotal` y `ventas.total`:
- **Comisiones** (base = `SUM(subtotal - descuento)`) las excluye automáticamente.
- **Ingresos** en reportes (`SUM(total)`) no las cuentan.
- **Utilidad** baja por el costo de la muestra, que es lo correcto (producto regalado).

## 8. Pruebas realizadas (contra el código real, no teoría)

| # | Prueba | Resultado |
|---|---|---|
| 1 | Cliente sin nombre | ❌ rechazado |
| 2 | Nombre con números | ❌ rechazado |
| 3 | Cliente sin teléfono | ❌ rechazado |
| 4 | Teléfono < 10 dígitos | ❌ rechazado |
| 5 | Crédito sin RNC/cédula | ❌ rechazado |
| 6 | Documento duplicado | ❌ rechazado ("ya existe: Juan Pérez") |
| 7 | Cliente válido | ✅ creado con `created_by` |
| 8 | Cliente Genérico (id 1) sin teléfono | ✅ no se bloquea |
| 9 | Muestra sin permiso `ventas.muestra` | ❌ rechazada en el servidor |
| 10 | Muestra con permiso | ✅ registrada |
| 11 | Muestra: precio/subtotal/ITBIS en 0 | ✅ |
| 12 | Muestra: no suma ingreso ni comisión (subtotal solo la línea cobrada) | ✅ |
| 13 | Muestra: descuenta stock, kardex marca "Muestra" | ✅ |
| 14 | Muestra: guarda `precio_original` | ✅ (55.00) |
| 15 | Ticket/detalle muestran "MUESTRA" | ✅ |
| 16 | Abrir caja abierta por otro usuario en la misma terminal | ❌ bloqueado, con mensaje |
| 17 | Abrir caja libre | ✅ permitido |
| 18 | POS no expone costo; toggle de muestra oculto sin permiso | ✅ |
| 19 | `schema.sql` desde cero (45 tablas) + migración idempotente | ✅ |
| 20 | Regresión: 30+ páginas, POS y tienda renderizan; integridad "TODO CUADRA" | ✅ |

Herramienta: arnés PHP que ejecuta el código real de cada módulo con sesión simulada.

## 9. Pendientes / pasos para el despliegue

1. **Correr la migración en producción:** `database/migracion_actualizaciones_cliente_p0.sql`.
2. **Update From Remote** en cPanel para subir el código.
3. Decidir si el rol **Cajero** debe poder facturar muestras (Roles y Permisos).
4. Definir turnos si se usará ese campo.

## 10. Riesgos abiertos (documentados, no bloqueantes)

- **Venta 100% muestra emite NCF con total 0.** Se permite (documenta la entrega),
  pero conviene que el contador confirme el tratamiento fiscal de las muestras.
- **Duplicados de teléfono** ahora se bloquean; si la cliente comparte teléfonos entre
  familiares, habrá que relajar esa regla (fácil de ajustar).
- El resto de riesgos son de fases P1–P3 (offline, facturación electrónica, etc.).

## 11. Pendientes para P1 / P2 / P3

Ver `PLAN_IMPLEMENTACION_ACTUALIZACIONES_CLIENTE_KYROS.md` §4. Resumen:
- **P1:** IT-1, conciliación bancaria, cuentas financieras (tipos/saldo inicial),
  comisiones (restar devoluciones, estados), transferencias avanzadas, reportes↔metas.
- **P2:** facturación electrónica externa, promociones, marketing/Instagram, campañas por correo.
- **P3:** modo offline (arquitectura de mayor cuidado: NCF, inventario y caja).

---

## 12. Addendum — Metas de Venta / KPI (P1, IMPLEMENTADO)

Se construyó completo el módulo de metas que la cliente describió (meta mensual por
sucursal dividida entre vendedoras, cada una viendo cuánto lleva y cuánto le falta).

**Qué se hizo:**
- `includes/metas.php` — `metaProgreso()` deriva el avance de las ventas reales
  (venta NETA: resta devoluciones del período; las muestras ya no cuentan porque su
  total es 0; las anuladas se excluyen). `metaPersonalActiva()` y `metaColor()`.
- `modules/finanzas/metas.php` — pantalla de gestión: crear/editar metas por
  **sucursal, vendedor o global**, con barra de progreso en tiempo real, % alcanzado,
  monto faltante y días restantes. Filtro por estado. Cerrar meta.
- **Banner en el POS** (`modules/pos/index.php`): la vendedora ve su meta personal
  con barra de progreso apenas abre el punto de venta.
- Permisos `metas.ver` / `metas.gestionar` (Super/Admin/Gerente). El Cajero **no**
  tiene el módulo de gestión, pero **sí** ve su banner personal.
- `database/migracion_metas_kpi.sql` (permisos) + `metas_ventas` ya estaba en el schema.

**Aplicado en producción** (permisos + tabla). **Verificado:** progreso derivado de
ventas reales (sucursal 61.2%, vendedor 100%, global 37.9% en la data de prueba),
CRUD con validación (monto > 0, período válido, alcance coherente), banner del POS,
gating del cajero, y regresión completa (TODO CUADRA).

Con esto, de P1 queda pendiente: IT-1, conciliación bancaria, mejoras de cuentas
financieras, mejoras de comisiones (estados pendiente/aprobada — las muestras y
ventas anuladas YA se excluyen), y transferencias avanzadas.
