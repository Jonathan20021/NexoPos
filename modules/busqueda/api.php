<?php
/**
 * Endpoint JSON de la búsqueda global (lo consume el buscador de la barra).
 * Solo lectura y siempre bajo sesión: devuelve exactamente lo que el usuario
 * puede ver en los módulos.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'sesion', 'grupos' => []]);
    exit;
}

$q = trim((string) get('q'));
$q = mb_substr($q, 0, 60);

try {
    $grupos = $q === '' ? [] : buscar_global($q);
    echo json_encode([
        'q'      => $q,
        'total'  => buscar_total($grupos),
        'grupos' => $grupos,
        'url_todo' => url('modules/busqueda/index.php?q=' . urlencode($q)),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error'  => 'busqueda',
        'grupos' => [],
        'detalle' => APP_ENV === 'production' ? null : $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
