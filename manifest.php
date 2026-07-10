<?php
/**
 * Manifest PWA generado dinámicamente para respetar la base de la instalación
 * (en local /proyecto-inventario-pos/, en producción /). Se enlaza desde el
 * <head> como <link rel="manifest" href="/…/manifest.php">.
 */
require_once __DIR__ . '/app/bootstrap.php';
header('Content-Type: application/manifest+json; charset=utf-8');

$nombre = defined('APP_NAME') ? APP_NAME : 'NexoPOS';

echo json_encode([
    'name'             => $nombre,
    'short_name'       => 'NexoPOS',
    'description'      => 'Punto de venta y gestión comercial multi-sucursal',
    'start_url'        => url('modules/pos/index.php'),
    'scope'            => base_url() . '/',
    'display'          => 'standalone',
    'orientation'      => 'any',
    'background_color' => '#f1f5f9',
    'theme_color'      => '#2563eb',
    'lang'             => 'es',
    'icons'            => [
        ['src' => asset('icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => asset('icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => asset('icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
