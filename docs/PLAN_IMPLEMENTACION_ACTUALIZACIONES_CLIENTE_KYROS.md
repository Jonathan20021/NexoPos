# Plan de implementación — Actualizaciones cliente (Kyros)

**Sistema:** NexoPOS · PHP 8 procedural · MySQL/MariaDB
**Fuente:** `docs/AUDITORIA_ACTUALIZACIONES_CLIENTE_KYROS.md`
**Fecha:** 2026-07-10

---

## 1. Resumen ejecutivo

La cliente pidió 16 mejoras. La auditoría demostró que **el sistema ya tiene una base
madura**: roles/permisos, caja, cuentas financieras, comisiones, transferencias
multi-sucursal, reportes gerenciales y DGII (606/607/608) ya existen. Muchos
requerimientos son **ajustes**, no módulos nuevos.

Esta fase entrega el plan completo y **solo implementa P0**: los cambios de mayor
impacto operativo y menor riesgo (muestras a RD$0.00, refuerzo de caja, permiso de
muestra, validación de clientes, y verificación del POS seguro). P1–P3 quedan
documentados para etapas siguientes.

---

## 2. Confirmación de arquitectura real (NO Laravel)

| Elemento | Ubicación real |
|---|---|
| Pantalla | `modules/<área>/<pantalla>.php` |
| Lógica de negocio | `includes/operaciones.php` |
| Permisos | `app/permissions.php` + `require_perm()` + `can()` |
| Helpers BD | `app/helpers.php` (`q/qOne/qAll/qVal/dbInsert/dbUpdate/tx`) |
| Esquema | `database/schema.sql` + `database/migracion_*.sql` (idempotentes) |
| Layout / menú | `includes/layout/`, `includes/components.php::nav_groups()` |
| Front | HTML + Tailwind CDN + Alpine.js embebido |

No se usa ORM ni migraciones Laravel. Las migraciones son SQL idempotente.

---

## 3. Hallazgos clave de auditoría (que condicionan el plan)

1. **El precio de venta nunca viene del navegador** (`guardar_venta.php:37`). Las
   muestras a 0.00 exigen una **bandera de línea con permiso**, no precio libre.
2. **El POS ya exige caja abierta** (`index.php:14-24` y `guardar_venta.php:11-12`)
   y **no muestra costo ni utilidad**. P0.5 está casi resuelto → solo verificar.
3. **La caja bloquea apertura por usuario**, no por terminal (`caja.php:51` +
   `cajaSesionAbierta()` filtra por `usuario_id`). Falta el bloqueo por caja/terminal.
4. **Comisiones e ingresos se calculan sobre `ventas.subtotal`/`ventas.total`**
   (`comisiones.php:27`, `reportes.php:30`). Si las muestras se excluyen del subtotal,
   **quedan fuera de comisiones y de ingresos automáticamente**. No hay que tocar
   esos módulos para las muestras.
5. **El rol Cajero ya está bien acotado**: no tiene finanzas, reportes, compras,
   nómina, configuración, usuarios ni roles (verificado en BD). P0.3 se reduce a
   agregar `ventas.muestra` y confirmar el alcance.
6. **`can()` da acceso total al Super Admin** (`auth.php`, `is_super()`), así que
   los permisos nuevos no requieren asignarse al Super.

---

## 4. Matriz de prioridad

### P0 — Implementar ahora
| # | Tarea | Complejidad | Módulos que toca |
|---|---|---|---|
| P0.1 | Muestras a RD$0.00 (bandera de línea + permiso) | **Media** | `venta_detalles`, `guardar_venta.php`, `pos/index.php`, `ticket.php`, `ventas.php` |
| P0.2 | Bloqueo de apertura de caja por terminal + turno + filtros | **Baja** | `operaciones.php`, `caja.php`, `caja_sesiones` |
| P0.3 | Permiso `ventas.muestra` + verificación rol Cajero | **Baja** | `app/permissions.php`, tablas de permisos |
| P0.4 | Validación obligatoria de clientes | **Media** | `pos/clientes.php`, `tienda/index.php`, `clientes` |
| P0.5 | POS seguro (caja abierta + sin costo/utilidad) | **Baja** (ya casi hecho) | `pos/index.php`, `guardar_venta.php` |
| P0.6 | Base mínima de metas KPI (**solo tabla + documentación**) | **Baja** | `metas_ventas` (tabla nueva, sin UI) |

### P1 — Planificar (no implementar ahora)
- IT-1 (resumen fiscal derivado de `ventas`/`transacciones`).
- Conciliación bancaria (nuevo, sobre `cuentas_financieras`/`transacciones`).
- Cuentas financieras: tipos tarjeta/transferencia, saldo inicial formal.
- Comisiones: restar devoluciones parciales, estados pendiente/aprobada, export.
- Transferencias: estados borrador/rechazada, aprobación; evaluar "almacén" ≠ sucursal.
- Reportes gerenciales conectados con metas KPI.

### P2 — Planificar (no implementar ahora)
- Facturación electrónica externa (interfaz `ProveedorFacturacionElectronica` + log + estados).
- Promociones por marca/categoría/temporada (`campanas` + reglas).
- Medición de marketing/Instagram (`canal_venta`/`campana_id` en `ventas`).
- Campañas por correo **sobre el motor Resend existente** (`includes/mail.php`).

### P3 — Planificar con cuidado (no implementar ahora)
- Modo offline / facturación sin internet. Afecta NCF, inventario y caja.
- Sincronización local↔servidor, cola de ventas, estrategia de NCF offline, conflictos.
  - **Fase 1 realista:** contingencia local (guardar venta en IndexedDB, sincronizar al volver).
  - **Fase 2 avanzada:** servidor local por sucursal o rango de NCF reservado offline.

---

## 5. Qué módulos NO deben duplicarse

- **Roles/permisos** — extender el catálogo, no reescribir.
- **Caja** — ajustar la apertura, no rehacer la fórmula de cierre.
- **Reportes gerenciales** — ya calculan utilidad/top/vendedor; no duplicar.
- **Cuentas financieras** — todo movimiento pasa por `registrarTransaccion()`.
- **Motor de correo** — Resend ya está en producción; campañas van encima.
- **Inventario** — `inventario_stock` + `ajustarStock()` son la única vía; no crear stock paralelo.
- **DGII 606/607/608** — completos; solo falta IT-1.

## 6. Archivos existentes a extender (P0)

| Archivo | Cambio |
|---|---|
| `modules/pos/guardar_venta.php` | Parsear bandera `muestra`, re-validar permiso, precio 0, excluir de subtotal/itbis, guardar `es_muestra`/`precio_original` |
| `modules/pos/index.php` | Toggle "Muestra" por línea (solo con permiso), etiqueta visual, totales en JS |
| `modules/pos/ticket.php` | Mostrar "MUESTRA" en líneas de muestra |
| `modules/pos/ventas.php` | Mostrar "MUESTRA" en el detalle |
| `includes/operaciones.php` | Nuevos helpers `cajaAbiertaPorCaja()`, `validarAperturaCaja()` |
| `modules/pos/caja.php` | Bloqueo por terminal en apertura + filtros + turno |
| `modules/pos/clientes.php` | Validación reforzada (backend fuente de verdad) |
| `app/permissions.php` | Añadir `ventas.muestra` |

## 7. Tablas existentes a extender (P0)

| Tabla | Columnas nuevas |
|---|---|
| `venta_detalles` | `es_muestra TINYINT(1) DEFAULT 0`, `precio_original DECIMAL(12,2) DEFAULT 0` |
| `caja_sesiones` | `turno VARCHAR(50) NULL` |
| `clientes` | `created_by INT UNSIGNED NULL` (trazabilidad de creador) |

## 8. Nuevas migraciones

- `database/migracion_actualizaciones_cliente_p0.sql` — idempotente:
  columnas de muestra, `turno`, `created_by`, permiso `ventas.muestra`
  (con concesión a Administrador y Gerente), y tabla `metas_ventas` (P0.6, sin UI).

## 9. Riesgos técnicos

| Riesgo | Mitigación |
|---|---|
| Una venta 100% muestra emite NCF con total 0 | Se permite (documenta la muestra); se marca como consideración fiscal para el contador. No bloquea. |
| Costo de la muestra afecta la utilidad en reportes | **Es correcto**: la muestra es una entrega real de inventario con costo. Se documenta. |
| Clientes viejos incompletos | La validación aplica al **guardar**; consultarlos sigue funcionando. El Cliente Genérico (id 1) queda exento. |
| Bloqueo de caja demasiado estricto | El bloqueo es por caja/terminal, no por sucursal, para no impedir varias cajas en paralelo. |
| Romper ventas al tocar `guardar_venta.php` | La ruta normal (sin muestras) queda idéntica; las muestras son un camino aparte con guardas. |

## 10. Orden recomendado de implementación (P0)

1. Migración idempotente (columnas + permiso + tabla metas).
2. P0.1 muestras (backend `guardar_venta.php` → luego POS → luego ticket/detalle).
3. P0.2 caja (helper + bloqueo + turno + filtros).
4. P0.3 permiso `ventas.muestra` en el catálogo.
5. P0.4 validación de clientes.
6. P0.5 verificar/reforzar POS seguro.
7. P0.6 documentar metas (tabla ya creada en la migración).
8. Lint + pruebas manuales + reporte final.

## 11. Criterios de prueba (P0)

Ver la lista completa de 18 pruebas en el reporte final. Las críticas:
muestra solo con permiso, venta normal no acepta 0.00, muestra descuenta stock
pero no suma ingreso ni comisión, ticket muestra "MUESTRA", no se abre una caja
ya abierta en la misma terminal, la venta exige caja abierta, y el cajero no ve
finanzas ni reportes.

## 12. Pendientes para fases futuras

Todo P1, P2 y P3 (sección 4). El punto de mayor complejidad y riesgo es el **modo
offline (P3)**: requiere decisión de arquitectura (PWA+IndexedDB vs. servidor local
por sucursal) y una política clara de NCF para evitar duplicados fiscales.
