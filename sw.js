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
 *  - Navegaciones (abrir una página): primero red, si no hay, se sirve la copia
 *    en caché; como último recurso, la última copia del POS.
 *  - CDNs (Tailwind, Alpine, Google Fonts): cache-first, son estables.
 *  - Recursos propios (js, css, favicon): stale-while-revalidate.
 */
'use strict';

const VERSION    = 'nexopos-v1';
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

  // Navegaciones (abrir una pantalla): network-first con respaldo a caché.
  if (req.mode === 'navigate') {
    event.respondWith(networkFirst(req));
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

async function networkFirst(req) {
  const cache = await caches.open(SHELL);
  try {
    const res = await fetch(req);
    if (res && res.ok) cache.put(req, res.clone());
    return res;
  } catch (e) {
    const hit = await cache.match(req);
    if (hit) return hit;
    // Último recurso: cualquier copia del POS que tengamos guardada.
    const pos = await cache.match(SCOPE + 'modules/pos/');
    if (pos) return pos;
    const posIdx = await cache.match(SCOPE + 'modules/pos/index.php');
    if (posIdx) return posIdx;
    return new Response(
      '<!doctype html><meta charset="utf-8"><title>Sin conexión</title>' +
      '<div style="font-family:system-ui;text-align:center;padding:3rem;color:#334155">' +
      '<h1 style="font-size:1.25rem">Sin conexión</h1>' +
      '<p>Esta pantalla no está disponible sin internet. Abre el Punto de Venta, que sí funciona offline.</p>' +
      '</div>',
      { headers: { 'Content-Type': 'text/html; charset=utf-8' }, status: 503 }
    );
  }
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
