# Guía de despliegue a producción (cPanel + GitHub)

> **Regla de oro de seguridad:** las credenciales de la base de datos viven en
> `config/config.local.php`, que está en `.gitignore` y **NUNCA** se sube a GitHub.
> El repositorio público no contiene contraseñas.

---

## 1. Subir el proyecto a GitHub

El repositorio ya está listo (con `.gitignore` que protege los secretos). Solo conéctalo a tu repo:

```bash
git remote add origin https://github.com/TU_USUARIO/TU_REPO.git
git branch -M main
git push -u origin main
```

Lo que **NO** se sube (protegido por `.gitignore`): `config/config.local.php`, `assets/uploads/*`,
`install/installed.lock`, respaldos `.sql`, logs. Lo que **sí** se sube: el código y la carpeta
`vendor/` (para que el despliegue funcione sin ejecutar Composer en el servidor).

---

## 2. En cPanel (una sola vez)

1. **Base de datos** (ya creada): base, usuario y contraseña asignados en *MySQL Databases*.
2. **Git Version Control** → clona tu repo. Cada actualización futura es **Update From Remote**.

---

## 3. Después del primer «Update From Remote» — qué debes cambiar

### 3.1. Crear `config/config.local.php` (con tus credenciales)

En **Administrador de archivos** de cPanel, dentro de la carpeta `config/`, crea el archivo
`config.local.php` (puedes copiar `config.local.example.php`). Contenido:

```php
<?php
define('DB_HOST', 'localhost');          // en cPanel casi siempre es 'localhost'
define('DB_NAME', 'TU_BASE_DE_DATOS');
define('DB_USER', 'TU_USUARIO');
define('DB_PASS', 'TU_CONTRASEÑA');
define('DB_CHARSET', 'utf8mb4');
define('APP_ENV', 'production');         // oculta los errores al público
```

> **Importante:** dentro del servidor, el host es **`localhost`** (la app y MySQL están en la
> misma máquina). La IP pública solo serviría para conexiones remotas, que no usamos.

### 3.2. Instalar la base de datos

Abre en el navegador: `https://TU_DOMINIO/install/index.php`
- Marca la casilla **«Instalación de producción»** (instala solo lo esencial, sin datos demo).
- Pulsa **Instalar ahora**.

### 3.3. Asegurar (¡obligatorio!)

1. Inicia sesión con `admin` / `admin123` y **cambia la contraseña** en *menú usuario → Mi perfil*.
2. **Elimina la carpeta `/install`** del servidor (o renómbrala).
3. En *Configuración → Empresa*, sube tu **logo** y completa RNC, dirección, etc.

---

## 4. Actualizaciones futuras

Solo haz **Update From Remote** en cPanel. Tus archivos `config.local.php`, el logo subido y los
datos **no se tocan** (están ignorados por git). El esquema solo cambia si vuelves a correr el
instalador (no es necesario para actualizaciones normales de código).

---

## 5. Seguridad ya incluida

- Credenciales fuera del repositorio (`config.local.php` git-ignorado).
- `.htaccess`: cabeceras de seguridad, sin listado de directorios, bloqueo de `config/`, `app/`,
  `includes/`, `database/`, `vendor/`, de archivos sensibles (`.sql`, `.local.php`, dotfiles) y de
  ejecución de scripts en `assets/uploads/`.
- Sesiones endurecidas (HttpOnly, SameSite, `Secure` automático bajo HTTPS) y cabeceras CSP/anticlickjacking.
- Contraseñas con `bcrypt`, CSRF en todos los formularios, consultas preparadas (anti-inyección),
  control de permisos por acción y auditoría de todo.
- Instalador con bloqueo post-instalación y validación de subidas (tipo/tamaño de imagen).

### Recomendado en producción
- Forzar **HTTPS** y descomentar la línea `Strict-Transport-Security` en `.htaccess`.
- Hacer respaldos periódicos desde *Administración → Respaldo*.
