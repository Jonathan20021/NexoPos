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
 * Fase 2: el terminal reserva NCF por adelantado (mientras hay internet) y, estando
 * offline, imprime el comprobante fiscal DEFINITIVO tomando un número de esa reserva
 * local. El servidor valida ese NCF contra la reserva del terminal al sincronizar.
 *
 * API pública: window.PosOffline
 *   init({ syncUrl, termUrl, csrf, onChange })  -> arranca el motor
 *   uuid()                              -> genera un UUID v4
 *   submitSale(payload)                 -> intenta enviar; si no hay red, encola
 *   flush()                             -> reintenta la cola ahora
 *   stats()                             -> Promise<{pending, errors, ncf}>
 *   listErrors()                        -> Promise<[registros con error]>
 *   dismissError(uuid)                  -> descarta una venta con error
 *   ncfStock()                          -> Promise<{B02, B01}> NCF offline disponibles
 */
(function () {
  'use strict';

  var DB_NAME = 'nexopos';
  var DB_VER  = 2;                       // v2: agrega el almacén de NCF reservados
  var STORE   = 'ventas_pendientes';
  var POOL    = 'ncf_pool';              // un registro por tipo: { tipo, list:[ncf...] }
  var TOKEN_KEY = 'nexopos_terminal';    // token de dispositivo en localStorage

  // Colchón de NCF por tipo: se rellena cuando baja de 'low', hasta 'target'.
  var TARGETS = { B02: { target: 40, low: 15 }, B01: { target: 12, low: 4 } };

  var cfg = { syncUrl: '', termUrl: '', csrf: '', onChange: null, deviceToken: '' };
  var _db = null;
  var _flushing = false;
  var _reserving = false;

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
        if (!db.objectStoreNames.contains(POOL)) {
          db.createObjectStore(POOL, { keyPath: 'tipo' });
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

  // ---- Almacén de NCF reservados (POOL) ----------------------------------
  function poolStore(mode) {
    return openDB().then(function (db) {
      return db.transaction(POOL, mode).objectStore(POOL);
    });
  }

  function poolGet(tipo) {
    return poolStore('readonly').then(function (store) {
      return new Promise(function (resolve) {
        var rq = store.get(tipo);
        rq.onsuccess = function () { resolve((rq.result && rq.result.list) || []); };
        rq.onerror   = function () { resolve([]); };
      });
    });
  }

  // Toma (y consume) el primer NCF del tipo de forma atómica. null si no hay.
  function takeNcf(tipo) {
    return poolStore('readwrite').then(function (store) {
      return new Promise(function (resolve) {
        var rq = store.get(tipo);
        rq.onsuccess = function () {
          var rec = rq.result || { tipo: tipo, list: [] };
          if (!rec.list.length) { resolve(null); return; }
          var ncf = rec.list.shift();
          var wr = store.put(rec);
          wr.onsuccess = function () { resolve(ncf); };
          wr.onerror   = function () { resolve(null); };
        };
        rq.onerror = function () { resolve(null); };
      });
    });
  }

  // Agrega NCF nuevos al final de la cola del tipo (sin duplicar los ya presentes).
  function poolAppend(tipo, ncfs) {
    if (!ncfs || !ncfs.length) return Promise.resolve();
    return poolStore('readwrite').then(function (store) {
      return new Promise(function (resolve) {
        var rq = store.get(tipo);
        rq.onsuccess = function () {
          var rec = rq.result || { tipo: tipo, list: [] };
          var vistos = {};
          rec.list.forEach(function (n) { vistos[n] = 1; });
          ncfs.forEach(function (n) { if (!vistos[n]) { rec.list.push(n); vistos[n] = 1; } });
          var wr = store.put(rec);
          wr.onsuccess = function () { resolve(); };
          wr.onerror   = function () { resolve(); };
        };
        rq.onerror = function () { resolve(); };
      });
    });
  }

  function ncfStock() {
    return Promise.all([poolGet('B02'), poolGet('B01')]).then(function (r) {
      return { B02: r[0].length, B01: r[1].length };
    });
  }

  // ---- Identidad del terminal -------------------------------------------
  function deviceToken() {
    var t = '';
    try { t = localStorage.getItem(TOKEN_KEY) || ''; } catch (e) { t = ''; }
    if (!t) {
      t = uuid();
      try { localStorage.setItem(TOKEN_KEY, t); } catch (e) {}
    }
    return t;
  }

  // Rellena el colchón de NCF desde el servidor si estamos online y hace falta.
  function ensurePool() {
    if (_reserving || !navigator.onLine || !cfg.termUrl) return Promise.resolve();
    _reserving = true;
    return ncfStock().then(function (stock) {
      var need = {};
      Object.keys(TARGETS).forEach(function (tipo) {
        var have = stock[tipo] || 0;
        if (have <= TARGETS[tipo].low) need[tipo] = TARGETS[tipo].target - have;
      });
      if (!Object.keys(need).length) { _reserving = false; return; }
      return fetch(cfg.termUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF': cfg.csrf },
        body: JSON.stringify({ device_token: cfg.deviceToken, need: need }),
        credentials: 'same-origin',
      }).then(function (res) { return res.json().catch(function () { return {}; }); })
        .then(function (data) {
          if (!data || !data.ok || !data.ncfs) return;
          return Promise.all([
            poolAppend('B02', data.ncfs.B02),
            poolAppend('B01', data.ncfs.B01),
          ]);
        })
        .then(function () { _reserving = false; notify(); })
        .catch(function () { _reserving = false; });
    }).catch(function () { _reserving = false; });
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
    return Promise.all([all(), ncfStock()]).then(function (res) {
      var rows = res[0];
      var errors = rows.filter(function (r) { return r.status === 'error'; }).length;
      return { pending: rows.length - errors, errors: errors, ncf: res[1] };
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
      // Offline real: se toma un NCF de la reserva del terminal para imprimir el
      // comprobante fiscal DEFINITIVO. Si el colchón se agotó, cae a provisional.
      var tipo = payload.comprobante === 'credito_fiscal' ? 'B01' : 'B02';
      return takeNcf(tipo).then(function (ncf) {
        if (ncf) { payload.ncf = ncf; payload.device_token = cfg.deviceToken; }
        return enqueue(payload).then(function () {
          return { outcome: 'queued', reason: 'offline', ncf: ncf || null };
        });
      });
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
            // Condiciones TEMPORALES: no se pierde la venta, se corta y se reintenta luego.
            //  409 = no hay caja abierta · 401 = sesión expirada · 419 = token vencido
            //  5xx = error momentáneo del servidor.
            // Tras volver a iniciar sesión, la página recarga con un token fresco y el
            // reintento periódico vacía la cola solo.
            if (r.status === 409 || r.status === 401 || r.status === 419 || r.status >= 500) {
              return true;
            }
            // Error DEFINITIVO de negocio (422 stock/NCF, 403 permiso, 400 datos):
            // se marca para revisión manual y se sigue con las demás.
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
      ensurePool();   // tras sincronizar, repone el colchón de NCF consumido offline
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
    cfg.syncUrl     = options.syncUrl;
    cfg.termUrl     = options.termUrl || '';
    cfg.csrf        = options.csrf;
    cfg.onChange    = options.onChange || null;
    cfg.deviceToken = deviceToken();

    window.addEventListener('online', function () { flush(); ensurePool(); });
    window.addEventListener('offline', notify);
    // Al volver el foco a la pestaña y al arrancar, intentar vaciar la cola.
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'visible') { flush(); ensurePool(); }
    });
    // Reintento periódico suave (por si 'online' no dispara de forma fiable).
    setInterval(function () { if (navigator.onLine) { flush(); ensurePool(); } }, 30000);

    notify();
    if (navigator.onLine) { flush(); ensurePool(); }
  }

  window.PosOffline = {
    init: init, uuid: uuid, submitSale: submitSale, flush: flush,
    stats: stats, listErrors: listErrors, dismissError: dismissError,
    ncfStock: ncfStock, deviceToken: deviceToken,
  };
})();
