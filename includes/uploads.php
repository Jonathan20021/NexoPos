<?php
/**
 * Subida de imágenes (logo de empresa, fotos de productos/empleados).
 * Devuelve la ruta relativa al proyecto para guardar en BD, o $actual si no hay archivo nuevo.
 */

/**
 * Carpetas cuyas fotos vienen con el despliegue y NO se borran al reemplazarlas.
 *
 * Los catálogos de las marcas (INGLOT y los que vengan) están versionados en
 * git porque es la única vía que tienen de llegar al servidor. Si al cambiar la
 * foto de un producto se borrara el archivo original, el árbol del servidor
 * quedaría con un archivo eliminado que el siguiente `git pull` vuelve a traer:
 * un fantasma que nadie entiende. Se deja el original donde está y el producto
 * apunta a la foto nueva.
 */
function imagen_versionada(string $rel): bool
{
    foreach (['assets/uploads/productos/inglot/', 'assets/uploads/tiendas/'] as $carpeta) {
        if (str_starts_with(str_replace('\\', '/', $rel), $carpeta)) return true;
    }
    return false;
}
function guardar_imagen(string $campo, string $subdir, ?string $actual = null): ?string
{
    if (empty($_FILES[$campo]) || ($_FILES[$campo]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $actual;
    }
    $f = $_FILES[$campo];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        flash('error', 'Error al subir la imagen.');
        return $actual;
    }
    $permitidos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $mime = function_exists('mime_content_type') ? (mime_content_type($f['tmp_name']) ?: '') : ($f['type'] ?? '');
    if (!isset($permitidos[$mime])) {
        flash('error', 'Formato no permitido. Usa JPG, PNG, GIF o WEBP.');
        return $actual;
    }
    if ($f['size'] > 3 * 1024 * 1024) {
        flash('error', 'La imagen supera el máximo de 3 MB.');
        return $actual;
    }
    $root = dirname(__DIR__);
    $dir = $root . '/assets/uploads/' . $subdir;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        flash('error', 'No se pudo crear la carpeta de imágenes.');
        return $actual;
    }
    $nombre = $subdir . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $permitidos[$mime];
    $rel = 'assets/uploads/' . $subdir . '/' . $nombre;
    if (!move_uploaded_file($f['tmp_name'], $root . '/' . $rel)) {
        flash('error', 'No se pudo guardar la imagen.');
        return $actual;
    }
    if ($actual && $actual !== $rel && !imagen_versionada($actual) && is_file($root . '/' . $actual)) {
        @unlink($root . '/' . $actual);
    }
    return $rel;
}
