/*
 * NexoPOS · Motor de ventas offline (lado del navegador)
 * -------------------------------------------------------------------------
 * Guarda en IndexedDB las ventas que no se pudieron enviar (sin internet) y las
 * sincroniza sola cuando vuelve la conexión. El NCF y el número de venta SIEMPRE
 * los asigna el servidor al sincronizar; aquí nunca se generan comprobantes.
 *
 * La identidad de cada venta es un UUID generado en el navegador: reenviar la
 * misma venta (mismo UUID) NO crea un duplicado (el servidor es idempotente).
 *
 * API pública: window.PosOffline
 *   init({ syncUrl, csrf, onChange })   -> arranca el motor
 *   uuid()                              -> genera un UUID v4
 *   submitSale(payload)                 -> intenta enviar; si no hay red, encola
 *   flush()                             -> reintenta la cola ahora
 *   stats()                             -> Promise<{pending, errors}>
 *   listErrors()                        -> Promise<[registros con error]>
 *   dismissError(uuid)                  -> descarta una venta con error
 */
(function () {
  'use strict';

  var DB_NAME = 'nexopos';
  var DB_VER  = 1;
  var STORE   = 'ventas_pendientes';

  var cfg = { syncUrl: '', csrf: '', onChange: null };
  var _db = null;
  var _flushing = false;

  // ---- IndexedDB ---------------------------------------------------------
  function openDB() {
    if (_db) return Promise.resolve(_db);
    return new Promise(function (resolve, reject) {
      if (!('indexedDB' in window)) { reject(new Error('IndexedDB no disponible')); return; }
      var rq = indexedDB.open(DB_NAME, DB_VER);
      rq.onupgradeneeded = function () {
        var db = rq.result;
        if (!db.objectStoreNames.contains(STORE)) {
          db.createObjectStore(STORE, { keyPath: 'uuid' });
        }
      };
      rq.onsuccess = function () { _db = rq.result; resolve(_db); };
      rq.onerror   = function () { reject(rq.error); };
    });
  }

  function tx(mode) {
    return openDB().then(function (db) {
      return db.transaction(STORE, mode).objectStore(STORE);
    });
  }

  function put(record) {
    return tx('readwrite').then(function (store) {
      return new Promise(function (resolve, reject) {
        var rq = store.put(record);
        rq.onsuccess = function () { resolve(); };
        rq.onerror   = function () { reject(rq.error); };
      });
    });
  }

  function del(uuid) {
    return tx('readwrite').then(function (store) {
      return new Promise(function (resolve, reject) {
        var rq = store.delete(uuid);
        rq.onsuccess = function () { resolve(); };
        rq.onerror   = function () { reject(rq.error); };
      });
    });
  }

  function all() {
    return tx('readonly').then(function (store) {
      return new Promise(function (resolve, reject) {
        var rq = store.getAll();
        rq.onsuccess = function () { resolve(rq.result || []); };
        rq.onerror   = function () { reject(rq.error); };
      });
    });
  }

  // ---- Utilidades --------------------------------------------------------
  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    // Respaldo para contextos no seguros (http en LAN): UUID v4 con getRandomValues.
    var b = new Uint8Array(16);
    (window.crypto || {}).getRandomValues
      ? crypto.getRandomValues(b)
      : b.forEach(function (_, i) { b[i] = Math.floor(Math.random() * 256); });
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    var h = [];
    for (var i = 0; i < 16; i++) h.push((b[i] + 0x100).toString(16).slice(1));
    return h[0]+h[1]+h[2]+h[3]+'-'+h[4]+h[5]+'-'+h[6]+h[7]+'-'+h[8]+h[9]+'-'+h[10]+h[11]+h[12]+h[13]+h[14]+h[15];
  }

  function notify() {
    if (typeof cfg.onChange !== 'function') return;
    stats().then(cfg.onChange).catch(function () {});
  }

  function stats() {
    return all().then(function (rows) {
      var errors = rows.filter(function (r) { return r.status === 'error'; }).length;
      return { pending: rows.length - errors, errors: errors };
    });
  }

  function listErrors() {
    return all().then(function (rows) {
      return rows.filter(function (r) { return r.status === 'error'; });
    });
  }

  // ---- Envío -------------------------------------------------------------
  function post(payload) {
    return fetch(cfg.syncUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': cfg.csrf },
      body: JSON.stringify(payload),
      credentials: 'same-origin',
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        return { status: res.status, data: data };
      });
    });
  }

  function enqueue(payload) {
    return put({
      uuid: payload.uuid,
      payload: payload,
      status: 'pending',
      error: '',
      createdAt: new Date().toISOString(),
    }).then(notify);
  }

  /**
   * Camino único del botón "Confirmar venta".
   * Devuelve { outcome: 'online'|'queued'|'error', data?, reason?, error? }.
   */
  function submitSale(payload) {
    if (!navigator.onLine) {
      return enqueue(payload).then(function () { return { outcome: 'queued', reason: 'offline' }; });
    }
    return post(payload).then(function (r) {
      if (r.status === 200 && r.data && r.data.ok) {
        return { outcome: 'online', data: r.data };
      }
      if (r.status === 409) {
        // El servidor no tiene caja abierta: se encola y se reintenta luego.
        return enqueue(payload).then(function () { return { outcome: 'queued', reason: 'sin_caja' }; });
      }
      // Error de negocio (stock, NCF, permiso): NO se encola, se muestra al cajero.
      return { outcome: 'error', error: (r.data && r.data.error) || 'No se pudo registrar la venta.' };
    }).catch(function () {
      // Falló la red (se cayó a mitad): se encola. Idempotente por UUID al reintentar.
      return enqueue(payload).then(function () { return { outcome: 'queued', reason: 'sin_red' }; });
    });
  }

  function flush() {
    if (_flushing || !navigator.onLine) return Promise.resolve();
    _flushing = true;
    return all().then(function (rows) {
      var pend = rows.filter(function (r) { return r.status !== 'error'; });
      // Encadena secuencialmente para no competir por la misma caja/secuencia.
      return pend.reduce(function (chain, it) {
        return chain.then(function (stop) {
          if (stop) return true;
          return post(Object.assign({}, it.payload, { offline: 1 })).then(function (r) {
            if (r.status === 200 && r.data && r.data.ok) {
              return del(it.uuid).then(function () { return false; });   // registrada (o duplicada) -> fuera
            }
            if (r.status === 409) {
              return true;   // no hay caja abierta: cortar y reintentar más tarde
            }
            // 422 u otro: error de negocio, marcar para revisión manual.
            it.status = 'error';
            it.error  = (r.data && r.data.error) || 'No se pudo sincronizar.';
            return put(it).then(function () { return false; });
          }).catch(function () {
            return true;   // se volvió a caer la red: cortar, reintentar luego
          });
        });
      }, Promise.resolve(false));
    }).then(function () {
      _flushing = false;
      notify();
    }).catch(function () {
      _flushing = false;
      notify();
    });
  }

  function dismissError(id) {
    return del(id).then(notify);
  }

  // ---- Arranque ----------------------------------------------------------
  function init(options) {
    cfg.syncUrl  = options.syncUrl;
    cfg.csrf     = options.csrf;
    cfg.onChange = options.onChange || null;

    window.addEventListener('online', function () { flush(); });
    window.addEventListener('offline', notify);
    // Al volver el foco a la pestaña y al arrancar, intentar vaciar la cola.
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'visible') flush();
    });
    // Reintento periódico suave (por si 'online' no dispara de forma fiable).
    setInterval(function () { if (navigator.onLine) flush(); }, 30000);

    notify();
    if (navigator.onLine) flush();
  }

  window.PosOffline = {
    init: init, uuid: uuid, submitSale: submitSale, flush: flush,
    stats: stats, listErrors: listErrors, dismissError: dismissError,
  };
})();
