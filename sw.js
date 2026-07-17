/*
 * NexoPOS · Service Worker
 * -------------------------------------------------------------------------
 * Objetivo Fase 1 (modo offline): que el Punto de Venta siga abriendo y
 * funcionando aunque se caiga el internet. La venta en sí se encola en
 * IndexedDB desde la página (pos-offline.js); aquí solo cacheamos la "cáscara"
 * de la app para que la pantalla cargue sin conexión.
 *
 * Reglas:
 *  - Nunca se interceptan peticiones que no sean GET (los POST de venta pasan
 *    directo a la red; si falla, la página los guarda en cola).
 *  - SOLO el Punto de Venta funciona offline. Su pantalla se cachea (network-first
 *    con respaldo a la copia guardada) para que abra sin conexión.
 *  - Cualquier OTRA pantalla es solo-red: si no hay internet se muestra un aviso
 *    claro de "sin conexión". NUNCA se sirve una copia vieja de reportes, listados,
 *    finanzas, etc. (mostrar datos desactualizados como si fueran actuales sería
 *    peligroso en una app de gestión).
 *  - CDNs (Tailwind, Alpine, Google Fonts): cache-first, son estables.
 *  - Recursos propios (js, css, favicon): stale-while-revalidate.
 */
'use strict';

const VERSION    = 'nexopos-v3';
const SHELL      = 'nexopos-shell-' + VERSION;
const RUNTIME    = 'nexopos-runtime-' + VERSION;
const CDN_CACHE  = 'nexopos-cdn-' + VERSION;

// Alcance de la instalación (…/ en local es /proyecto-inventario-pos/, en prod /).
const SCOPE = new URL(self.registration ? self.registration.scope : self.location).pathname;

self.addEventListener('install', (event) => {
  // Activamos de inmediato la nueva versión sin esperar a que se cierren pestañas.
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    // Borrar cachés de versiones anteriores.
    const keys = await caches.keys();
    await Promise.all(keys.map((k) => {
      if (![SHELL, RUNTIME, CDN_CACHE].includes(k)) return caches.delete(k);
    }));
    await self.clients.claim();
  })());
});

// ¿Es un CDN que queremos cachear para offline?
function esCDN(url) {
  return /(^https:\/\/cdn\.tailwindcss\.com)|(^https:\/\/cdn\.jsdelivr\.net)|(^https:\/\/fonts\.(googleapis|gstatic)\.com)/.test(url);
}

// ¿La navegación es la PANTALLA del Punto de Venta? (única que opera offline).
// Coincide con /modules/pos, /modules/pos/ y /modules/pos/index(.php).
function esPantallaPOS(pathname) {
  return /\/modules\/pos\/?(index(\.php)?)?$/.test(pathname);
}

self.addEventListener('fetch', (event) => {
  const req = event.request;

  // Solo GET. Todo POST (ventas, sync, formularios) pasa directo a la red.
  if (req.method !== 'GET') return;

  const url = new URL(req.url);

  // CDNs de terceros: cache-first (son versionados/estables).
  if (esCDN(req.url)) {
    event.respondWith(cacheFirst(req, CDN_CACHE));
    return;
  }

  // Solo gestionamos el mismo origen de aquí en adelante.
  if (url.origin !== self.location.origin) return;

  // No cachear endpoints dinámicos de datos (sincronización, API, exportaciones).
  if (/\/(sync_venta|guardar_venta|api|export|logout)/.test(url.pathname)) return;

  // Navegaciones (abrir una pantalla):
  //  - POS: network-first con respaldo a la copia cacheada (funciona offline).
  //  - Resto: solo-red; si no hay internet, aviso de "sin conexión" (sin datos viejos).
  if (req.mode === 'navigate') {
    event.respondWith(esPantallaPOS(url.pathname) ? posShell(req) : soloRed(req));
    return;
  }

  // Recursos propios estáticos: stale-while-revalidate.
  if (/\.(js|css|svg|png|jpg|jpeg|webp|woff2?|ico|json|webmanifest)$/i.test(url.pathname)) {
    event.respondWith(staleWhileRevalidate(req, RUNTIME));
    return;
  }
});

async function cacheFirst(req, cacheName) {
  const cache = await caches.open(cacheName);
  const hit = await cache.match(req);
  if (hit) return hit;
  try {
    const res = await fetch(req, { mode: req.mode === 'no-cors' ? 'no-cors' : undefined });
    if (res && (res.ok || res.type === 'opaque')) cache.put(req, res.clone());
    return res;
  } catch (e) {
    return hit || Response.error();
  }
}

// Pantalla del POS: red primero; si no hay, la copia cacheada (así vende offline).
async function posShell(req) {
  const cache = await caches.open(SHELL);
  try {
    const res = await fetch(req);
    if (res && res.ok) cache.put(req, res.clone());
    return res;
  } catch (e) {
    const hit = await cache.match(req);
    if (hit) return hit;
    // Respaldo: cualquier copia del POS guardada (por si cambió la URL limpia).
    const pos = await cache.match(SCOPE + 'modules/pos/') || await cache.match(SCOPE + 'modules/pos/index.php');
    if (pos) return pos;
    return avisoSinConexion();
  }
}

// Resto de la app: solo-red y SIN caché. 'no-store' evita que el navegador sirva
// una copia vieja del caché HTTP cuando no hay internet: si falla, aviso claro.
async function soloRed(req) {
  try {
    return await fetch(req, { cache: 'no-store' });
  } catch (e) {
    return avisoSinConexion();
  }
}

function avisoSinConexion() {
  return new Response(
    '<!doctype html><html lang="es"><head><meta charset="utf-8">' +
    '<meta name="viewport" content="width=device-width, initial-scale=1"><title>Sin conexión</title></head>' +
    '<body style="margin:0;font-family:system-ui,-apple-system,sans-serif;background:#f1f5f9;color:#334155">' +
    '<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.5rem">' +
    '<div style="max-width:26rem;text-align:center;background:#fff;border:1px solid #e2e8f0;border-radius:1rem;padding:2.5rem 2rem;box-shadow:0 10px 40px -12px rgba(15,23,42,.25)">' +
    '<div style="width:3.5rem;height:3.5rem;border-radius:1rem;background:#fef3c7;color:#d97706;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.5rem;font-weight:700">!</div>' +
    '<h1 style="font-size:1.25rem;margin:0 0 .5rem;color:#0f172a">Sin conexión</h1>' +
    '<p style="font-size:.9rem;line-height:1.6;margin:0 0 1.5rem">Esta pantalla necesita internet para mostrar información actualizada. Solo el <b>Punto de Venta</b> funciona sin conexión.</p>' +
    '<button onclick="location.reload()" style="background:#2563eb;color:#fff;border:0;font-weight:600;padding:.7rem 1.4rem;border-radius:.75rem;cursor:pointer">Reintentar</button>' +
    '</div></div></body></html>',
    { headers: { 'Content-Type': 'text/html; charset=utf-8' }, status: 503 }
  );
}

async function staleWhileRevalidate(req, cacheName) {
  const cache = await caches.open(cacheName);
  const hit = await cache.match(req);
  const fetching = fetch(req).then((res) => {
    if (res && res.ok) cache.put(req, res.clone());
    return res;
  }).catch(() => hit);
  return hit || fetching;
}
