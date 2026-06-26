# NexoPOS — Sistema de Gestión Comercial Multi-Sucursal

Sistema completo en **PHP 8 + MySQL/MariaDB + Tailwind CSS** para administrar un negocio con
varias sucursales: **Punto de Venta (POS)**, **Inventario por categoría**, **Recursos Humanos**,
**Finanzas**, control de **roles y permisos**, y **auditoría** de todas las acciones.

Diseñado para la República Dominicana: moneda **RD$**, impuesto **ITBIS (18%)**, comprobantes
fiscales **NCF (B01/B02)** y **nómina dominicana** (TSS: AFP/SFS + ISR por escala).

---

## 🚀 Instalación en XAMPP (desarrollo)

1. Copia la carpeta del proyecto en `C:\xampp\htdocs\proyecto-inventario-pos`.
2. Inicia **Apache** y **MySQL** desde el panel de XAMPP.
3. Abre en el navegador:
   **http://localhost/proyecto-inventario-pos/install/index.php**
4. Pulsa **«Instalar ahora»**. Se crea la base de datos `inventario_pos`, todas las tablas y
   datos de demostración (sucursales, productos, empleados y ventas de ejemplo).
5. Entra en **http://localhost/proyecto-inventario-pos/**

> El instalador también funciona por consola: `php install/index.php`

### Cuentas de demostración

| Rol               | Usuario   | Contraseña   | Acceso                                  |
|-------------------|-----------|--------------|-----------------------------------------|
| Super Admin       | `admin`   | `admin123`   | Todo el sistema, todas las sucursales   |
| Gerente           | `gerente` | `gerente123` | Su sucursal: ventas, inventario, reportes |
| Cajero            | `cajero`  | `cajero123`  | POS, caja, ventas, clientes             |

---

## 📦 Módulos

- **Dashboard** — KPIs en tiempo real, gráficos de ventas, productos con stock bajo.
- **Punto de Venta (POS)** — venta rápida con búsqueda, carrito, ITBIS, NCF, ticket imprimible.
- **Caja** — apertura, ingresos/egresos de efectivo y **cierre con cuadre** automático.
- **Ventas / Devoluciones** — historial, detalle, anulación, devoluciones (reponen stock) y **factura PDF con logo**.
- **Cuentas por Cobrar** — ventas a crédito que generan deuda del cliente (con límite) y registro de **abonos**.
- **Inventario** — productos por categoría, **stock por sucursal**, kardex de movimientos,
  ajustes, compras a proveedores y **transferencias entre sucursales**.
- **Recursos Humanos** — empleados, departamentos/puestos, asistencia, vacaciones/licencias y
  **nómina** con cálculo automático de **AFP (2.87%)**, **SFS (3.04%)** e **ISR**.
- **Finanzas** — ingresos y gastos (se registran solos desde ventas/compras/nómina), cuentas,
  **comisiones de vendedores** (cálculo por % y pago) y **reportes gerenciales** (estado de
  resultados, ventas por categoría/sucursal/vendedor, etc.) exportables a **PDF profesional**.
- **Administración** — sucursales, usuarios (con asignación de sucursal y **% de comisión**),
  **roles y permisos por acción**, configuración de empresa/NCF/métodos de pago, **subida de logo**,
  **auditoría** (logs) y **respaldo de la base de datos** descargable (.sql).
- **Exportaciones** — todos los listados y reportes se exportan a **Excel (.xlsx)** y **PDF
  profesional con la marca/logo** de la empresa (factura, nómina, reportes y cualquier listado).
- **Mi Perfil** — cada usuario actualiza sus datos y **cambia su contraseña**.

Todo está **automatizado**: cada venta descuenta stock y registra el ingreso; cada compra/
transferencia/ajuste mueve el inventario; la nómina pagada y las devoluciones impactan finanzas;
y cada acción queda registrada en la auditoría.

---

## 📚 Dependencias (Composer)

El proyecto usa **Dompdf** (PDF) y **PhpSpreadsheet** (Excel). Ya están instaladas en `vendor/`.
Si clonas el proyecto sin esa carpeta, instálalas con:

```bash
composer install        # o:  composer require dompdf/dompdf phpoffice/phpspreadsheet
```

Requiere las extensiones PHP: `gd`, `zip`, `mbstring`, `dom`, `fileinfo` (XAMPP ya las trae).

## 🌐 Despliegue a producción

1. Sube los archivos a tu servidor (Apache/Nginx con PHP 8+), **incluyendo la carpeta `vendor/`**
   (o ejecuta `composer install` en el servidor).
2. Edita **`config/config.php`**:
   - `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` → credenciales de tu base de datos.
   - `APP_ENV` → `'production'` (oculta errores).
   - `APP_URL` → déjalo `''` (se autodetecta) o fíjalo si usas una subcarpeta.
3. Ejecuta el instalador una sola vez (`/install/index.php`) y luego **elimina o protege** la
   carpeta `install/`.
4. Recomendado: servir por **HTTPS** y usar una contraseña fuerte para MySQL.

La ruta base se **autodetecta**, por lo que el sistema funciona igual en una subcarpeta de XAMPP
o en la raíz de un dominio, sin cambios.

---

## 🗂️ Estructura

```
proyecto-inventario-pos/
├── index.php              Punto de entrada (redirige a login/dashboard)
├── config/                Configuración y conexión PDO
├── app/                   bootstrap, helpers, auth/RBAC, auditoría, iconos, permisos
├── includes/              Layout (sidebar/topbar), componentes, operaciones, gráficos
├── modules/               Páginas por módulo (auth, dashboard, pos, inventario, rrhh, finanzas, admin)
├── database/schema.sql    Esquema completo (39 tablas)
├── install/index.php      Instalador (crea BD + datos demo)
└── docs/                  Convenciones de desarrollo
```

## 🛠️ Tecnología
PHP 8.2 · MySQL/MariaDB (PDO, sentencias preparadas) · Tailwind CSS · Alpine.js · SVG nativo para
gráficos. Sin dependencias de compilación: listo para correr en XAMPP.
