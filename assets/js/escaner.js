/*
 * NexoPOS · Escáner de códigos de barras
 * ---------------------------------------------------------------------------
 * Convierte cualquier teléfono en un lector de códigos. Dos caminos, según el
 * navegador, y la pantalla se ve igual en ambos:
 *
 *   1. BarcodeDetector — el decodificador que trae el propio sistema. Es el que
 *      usa Chrome en Android: no descarga nada, lee al instante y gasta poca
 *      batería. Este es el camino normal en el almacén.
 *   2. ZXing (assets/js/vendor/) — respaldo para iPhone (Safari no implementa
 *      BarcodeDetector) y Firefox. Son 395 KB que SOLO se descargan si hacen
 *      falta; en Android nunca se piden. Va servido desde el propio dominio,
 *      así que también funciona con la app instalada y sin internet.
 *
 * También expone un lector de "teclado" (NexoEscaner.teclado) para las pistolas
 * USB y Bluetooth, que no son cámaras: se comportan como un teclado que escribe
 * el código muy rápido y termina con Enter.
 *
 * AVISO IMPORTANTE: la cámara solo está disponible en HTTPS (o en localhost).
 * Es una regla del navegador, no del sistema. En http:// el navegador ni
 * siquiera pregunta: niega el acceso en silencio. Por eso se detecta antes y se
 * explica, en vez de dejar una pantalla negra sin motivo.
 */
'use strict';

window.NexoEscaner = (function () {

  var cfg = {
    vendor: '',          // ruta a zxing-browser.min.js (la fija el layout)
    formatos: ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf', 'codabar', 'qr_code'],
  };

  var estado = {
    abierto: false,
    stream: null,
    track: null,
    detector: null,
    zxing: null,
    zxingCtl: null,
    raf: null,
    camaras: [],
    camaraIdx: 0,
    linterna: false,
    ultimo: '',
    ultimoAt: 0,
    opts: null,
  };

  var el = {};   // nodos del overlay, creados una sola vez

  /* ======================================================================
   *  Utilidades
   * ====================================================================== */

  function seguro() {
    return window.isSecureContext === true
        || location.protocol === 'https:'
        || location.hostname === 'localhost'
        || location.hostname === '127.0.0.1';
  }

  function hayCamara() {
    return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
  }

  /** ¿Se puede escanear con la cámara en este dispositivo? */
  function soportado() {
    return hayCamara() && seguro();
  }

  /** Por qué NO se puede, en un mensaje que el usuario pueda accionar. */
  function motivoNoSoportado() {
    if (!hayCamara()) return 'Este navegador no permite usar la cámara. Prueba con Chrome o Safari actualizado.';
    if (!seguro()) {
      return 'La cámara solo funciona con conexión segura (https://). '
           + 'Abre el sistema por https y vuelve a intentarlo, o usa una pistola lectora USB.';
    }
    return '';
  }

  /** Pitido corto de confirmación. Se sintetiza: no hace falta ningún archivo. */
  function pitar(exito) {
    try {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) return;
      if (!estado.audio) estado.audio = new Ctx();
      var ctx = estado.audio;
      if (ctx.state === 'suspended') ctx.resume();
      var osc = ctx.createOscillator();
      var gain = ctx.createGain();
      osc.type = 'square';
      osc.frequency.value = exito ? 1760 : 320;
      gain.gain.setValueAtTime(0.0001, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.12, ctx.currentTime + 0.01);
      gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + (exito ? 0.09 : 0.22));
      osc.connect(gain); gain.connect(ctx.destination);
      osc.start();
      osc.stop(ctx.currentTime + (exito ? 0.1 : 0.24));
    } catch (e) { /* sin sonido no pasa nada */ }
    try { if (navigator.vibrate) navigator.vibrate(exito ? 35 : [60, 50, 60]); } catch (e) {}
  }

  function cargarScript(src) {
    return new Promise(function (res, rej) {
      var s = document.createElement('script');
      s.src = src;
      s.onload = res;
      s.onerror = function () { rej(new Error('No se pudo cargar el lector de respaldo.')); };
      document.head.appendChild(s);
    });
  }

  /* ======================================================================
   *  Overlay
   * ====================================================================== */

  function construir() {
    if (el.raiz) return;

    var d = document.createElement('div');
    d.className = 'nx-scan-overlay';
    d.setAttribute('role', 'dialog');
    d.setAttribute('aria-modal', 'true');
    d.innerHTML = ''
      + '<style>'
      + '.nx-scan-overlay{position:fixed;inset:0;z-index:9999;background:#0f172a;display:none;flex-direction:column;color:#fff}'
      + '.nx-scan-overlay.abierto{display:flex}'
      + '.nx-scan-top{display:flex;align-items:center;gap:.75rem;padding:.85rem 1rem;background:rgba(15,23,42,.92)}'
      + '.nx-scan-top h2{font-size:1rem;font-weight:700;margin:0;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
      + '.nx-scan-btn{background:rgba(255,255,255,.12);border:0;color:#fff;border-radius:.75rem;padding:.55rem .7rem;font:inherit;font-size:.8rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:.35rem}'
      + '.nx-scan-btn:hover{background:rgba(255,255,255,.22)}'
      + '.nx-scan-btn[aria-pressed="true"]{background:#f59e0b;color:#1e293b}'
      + '.nx-scan-vista{position:relative;flex:1;overflow:hidden;background:#000}'
      + '.nx-scan-vista video{width:100%;height:100%;object-fit:cover;display:block}'
      + '.nx-scan-mira{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none}'
      + '.nx-scan-marco{width:min(80vw,340px);height:min(42vw,190px);border-radius:1rem;box-shadow:0 0 0 100vmax rgba(15,23,42,.55);border:2px solid rgba(255,255,255,.85);position:relative}'
      + '.nx-scan-linea{position:absolute;left:6%;right:6%;height:2px;background:#ef4444;box-shadow:0 0 8px #ef4444;top:50%;animation:nxScan 2s ease-in-out infinite}'
      + '@keyframes nxScan{0%,100%{top:14%}50%{top:86%}}'
      + '@media (prefers-reduced-motion:reduce){.nx-scan-linea{animation:none;top:50%}}'
      + '.nx-scan-pie{padding:.85rem 1rem 1.15rem;background:rgba(15,23,42,.92);display:flex;flex-direction:column;gap:.6rem}'
      + '.nx-scan-ayuda{font-size:.8rem;color:#cbd5e1;margin:0;text-align:center;min-height:1.1em}'
      + '.nx-scan-manual{display:flex;gap:.5rem}'
      + '.nx-scan-manual input{flex:1;min-width:0;border-radius:.75rem;border:1px solid rgba(255,255,255,.25);background:rgba(255,255,255,.1);color:#fff;padding:.6rem .8rem;font:inherit;font-size:.95rem}'
      + '.nx-scan-manual input::placeholder{color:#94a3b8}'
      + '.nx-scan-aviso{margin:1.25rem;padding:1rem;border-radius:1rem;background:rgba(251,191,36,.15);border:1px solid rgba(251,191,36,.45);color:#fde68a;font-size:.9rem;line-height:1.5}'
      + '.nx-scan-ultimo{position:absolute;left:0;right:0;bottom:0;padding:.6rem 1rem;background:rgba(16,185,129,.92);color:#04241a;font-weight:700;font-size:.9rem;text-align:center;transform:translateY(100%);transition:transform .18s ease}'
      + '.nx-scan-ultimo.ver{transform:translateY(0)}'
      + '</style>'
      + '<div class="nx-scan-top">'
      +   '<h2 data-t></h2>'
      +   '<button type="button" class="nx-scan-btn" data-linterna hidden aria-pressed="false" title="Linterna">Luz</button>'
      +   '<button type="button" class="nx-scan-btn" data-cambiar hidden title="Cambiar de cámara">Girar</button>'
      +   '<button type="button" class="nx-scan-btn" data-cerrar aria-label="Cerrar el escáner">Cerrar</button>'
      + '</div>'
      + '<div class="nx-scan-vista" data-vista>'
      +   '<video playsinline muted autoplay></video>'
      +   '<div class="nx-scan-mira"><div class="nx-scan-marco"><span class="nx-scan-linea"></span></div></div>'
      +   '<div class="nx-scan-ultimo" data-ultimo></div>'
      + '</div>'
      + '<div class="nx-scan-pie">'
      +   '<p class="nx-scan-ayuda" data-ayuda></p>'
      +   '<form class="nx-scan-manual" data-form>'
      +     '<input type="text" data-manual placeholder="…o escribe el código a mano" autocomplete="off" inputmode="text" enterkeyhint="done">'
      +     '<button type="submit" class="nx-scan-btn">Buscar</button>'
      +   '</form>'
      + '</div>';

    document.body.appendChild(d);
    el.raiz     = d;
    el.titulo   = d.querySelector('[data-t]');
    el.video    = d.querySelector('video');
    el.vista    = d.querySelector('[data-vista]');
    el.ayuda    = d.querySelector('[data-ayuda]');
    el.ultimo   = d.querySelector('[data-ultimo]');
    el.manual   = d.querySelector('[data-manual]');
    el.form     = d.querySelector('[data-form]');
    el.linterna = d.querySelector('[data-linterna]');
    el.cambiar  = d.querySelector('[data-cambiar]');

    d.querySelector('[data-cerrar]').addEventListener('click', cerrar);
    el.linterna.addEventListener('click', alternarLinterna);
    el.cambiar.addEventListener('click', siguienteCamara);
    el.form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var v = el.manual.value.trim();
      if (!v) return;
      el.manual.value = '';
      entregar(v, 'manual');
    });
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && estado.abierto) cerrar();
    });
  }

  function avisar(html) {
    el.vista.insertAdjacentHTML('afterbegin', '<div class="nx-scan-aviso">' + html + '</div>');
  }

  function mostrarUltimo(txt) {
    el.ultimo.textContent = txt;
    el.ultimo.classList.add('ver');
    clearTimeout(estado.tUltimo);
    estado.tUltimo = setTimeout(function () { el.ultimo.classList.remove('ver'); }, 1600);
  }

  /* ======================================================================
   *  Cámara
   * ====================================================================== */

  async function listarCamaras() {
    try {
      var ds = await navigator.mediaDevices.enumerateDevices();
      estado.camaras = ds.filter(function (d) { return d.kind === 'videoinput'; });
    } catch (e) { estado.camaras = []; }
    el.cambiar.hidden = estado.camaras.length < 2;
  }

  async function arrancarCamara(deviceId) {
    pararCamara();
    var constraints = deviceId
      ? { video: { deviceId: { exact: deviceId } }, audio: false }
      // `environment` = cámara trasera. En un almacén siempre se apunta hacia afuera.
      : { video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false };

    estado.stream = await navigator.mediaDevices.getUserMedia(constraints);
    estado.track = estado.stream.getVideoTracks()[0];
    el.video.srcObject = estado.stream;
    await el.video.play().catch(function () {});

    // La linterna solo existe en algunos teléfonos; se pregunta antes de ofrecerla.
    var caps = {};
    try { caps = estado.track.getCapabilities ? estado.track.getCapabilities() : {}; } catch (e) {}
    el.linterna.hidden = !caps.torch;
    estado.linterna = false;
    el.linterna.setAttribute('aria-pressed', 'false');
  }

  function pararCamara() {
    if (estado.stream) {
      estado.stream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} });
    }
    estado.stream = null;
    estado.track = null;
    el.video.srcObject = null;
  }

  async function alternarLinterna() {
    if (!estado.track) return;
    estado.linterna = !estado.linterna;
    try {
      await estado.track.applyConstraints({ advanced: [{ torch: estado.linterna }] });
      el.linterna.setAttribute('aria-pressed', estado.linterna ? 'true' : 'false');
    } catch (e) {
      estado.linterna = false;
      el.linterna.hidden = true;
    }
  }

  async function siguienteCamara() {
    if (estado.camaras.length < 2) return;
    estado.camaraIdx = (estado.camaraIdx + 1) % estado.camaras.length;
    detenerDecodificador();
    await arrancarCamara(estado.camaras[estado.camaraIdx].deviceId);
    arrancarDecodificador();
  }

  /* ======================================================================
   *  Decodificación
   * ====================================================================== */

  function entregar(codigo, formato) {
    codigo = String(codigo || '').trim();
    if (!codigo) return;

    // Antirrebote: la cámara ve el mismo código 15 veces por segundo. Sin esto,
    // un solo artículo entraría quince veces en el inventario.
    var ahora = Date.now();
    if (formato !== 'manual' && codigo === estado.ultimo && (ahora - estado.ultimoAt) < 1800) return;
    estado.ultimo = codigo;
    estado.ultimoAt = ahora;

    pitar(true);
    mostrarUltimo(codigo);

    var seguir = estado.opts && estado.opts.continuo;
    try {
      if (estado.opts && typeof estado.opts.onLeer === 'function') {
        estado.opts.onLeer(codigo, formato || '');
      }
    } catch (e) {
      console.error('[escaner] fallo en onLeer', e);
    }
    if (!seguir) cerrar();
  }

  async function arrancarConBarcodeDetector() {
    var formatos = cfg.formatos;
    try {
      var disponibles = await window.BarcodeDetector.getSupportedFormats();
      formatos = cfg.formatos.filter(function (f) { return disponibles.indexOf(f) !== -1; });
      if (!formatos.length) formatos = disponibles;
    } catch (e) { /* si no se puede preguntar, se prueba con la lista completa */ }

    estado.detector = new window.BarcodeDetector({ formats: formatos });

    var ultimoIntento = 0;
    var bucle = function () {
      if (!estado.abierto) return;
      estado.raf = requestAnimationFrame(bucle);

      // ~10 lecturas por segundo: de sobra para leer al vuelo y mucho más
      // amable con la batería que hacerlo en cada fotograma.
      var t = Date.now();
      if (t - ultimoIntento < 100) return;
      ultimoIntento = t;
      if (el.video.readyState < 2) return;

      estado.detector.detect(el.video).then(function (res) {
        if (res && res.length) entregar(res[0].rawValue, res[0].format);
      }).catch(function () { /* un fotograma ilegible no es un error */ });
    };
    estado.raf = requestAnimationFrame(bucle);
  }

  async function arrancarConZXing() {
    if (!window.ZXingBrowser) {
      el.ayuda.textContent = 'Preparando el lector…';
      await cargarScript(cfg.vendor);
    }
    if (!estado.zxing) estado.zxing = new window.ZXingBrowser.BrowserMultiFormatReader();
    estado.zxingCtl = await estado.zxing.decodeFromVideoElement(el.video, function (res) {
      if (res) entregar(res.getText(), 'zxing');
    });
  }

  async function arrancarDecodificador() {
    if ('BarcodeDetector' in window) {
      await arrancarConBarcodeDetector();
      el.ayuda.textContent = estado.opts.ayuda || 'Apunta al código de barras.';
      return;
    }
    await arrancarConZXing();
    el.ayuda.textContent = estado.opts.ayuda || 'Apunta al código de barras.';
  }

  function detenerDecodificador() {
    if (estado.raf) { cancelAnimationFrame(estado.raf); estado.raf = null; }
    estado.detector = null;
    if (estado.zxingCtl) {
      try { estado.zxingCtl.stop(); } catch (e) {}
      estado.zxingCtl = null;
    }
  }

  /* ======================================================================
   *  API pública
   * ====================================================================== */

  /**
   * Abre el escáner.
   * opts = {
   *   titulo:  texto de la barra superior
   *   ayuda:   línea de instrucciones bajo el visor
   *   continuo:true para seguir leyendo (almacén); false cierra tras el primer código
   *   onLeer(codigo, formato)
   *   onCerrar()
   * }
   */
  async function abrir(opts) {
    construir();

    // Abrir dos veces sin cerrar dejaría el bucle de lectura anterior corriendo
    // contra la cámara nueva: dos decodificadores sobre el mismo vídeo, el doble
    // de batería y lecturas duplicadas. Se cierra lo anterior primero.
    if (estado.abierto) {
      detenerDecodificador();
      pararCamara();
      estado.abierto = false;
    }

    estado.opts = opts || {};
    estado.ultimo = '';
    estado.ultimoAt = 0;

    el.titulo.textContent = estado.opts.titulo || 'Escanear código';
    el.ayuda.textContent = '';
    el.manual.value = '';
    Array.prototype.forEach.call(el.vista.querySelectorAll('.nx-scan-aviso'), function (n) { n.remove(); });
    el.raiz.classList.add('abierto');
    estado.abierto = true;
    document.body.style.overflow = 'hidden';

    if (!soportado()) {
      // Sin cámara la pantalla NO se cierra: la escritura a mano y la pistola
      // USB siguen sirviendo, que es justo lo que salva el día en una bodega
      // con un teléfono viejo.
      avisar('<strong>No se puede usar la cámara.</strong><br>' + motivoNoSoportado()
           + '<br><br>Puedes escribir el código abajo o usar una pistola lectora.');
      el.manual.focus();
      return;
    }

    try {
      await arrancarCamara(null);
      await listarCamaras();
      await arrancarDecodificador();
    } catch (e) {
      var msg = (e && e.name === 'NotAllowedError')
        ? 'Diste «bloquear» al permiso de cámara. Ábrelo desde el candado de la barra de direcciones y recarga.'
        : (e && e.name === 'NotFoundError')
          ? 'Este dispositivo no tiene cámara disponible.'
          : 'No se pudo abrir la cámara: ' + ((e && e.message) || e);
      avisar('<strong>Cámara no disponible.</strong><br>' + msg
           + '<br><br>Puedes escribir el código abajo o usar una pistola lectora.');
      el.manual.focus();
    }
  }

  function cerrar() {
    if (!estado.abierto) return;
    estado.abierto = false;
    detenerDecodificador();
    pararCamara();
    el.raiz.classList.remove('abierto');
    document.body.style.overflow = '';
    if (estado.opts && typeof estado.opts.onCerrar === 'function') {
      try { estado.opts.onCerrar(); } catch (e) {}
    }
  }

  /**
   * Lector de pistola USB / Bluetooth.
   *
   * Estos aparatos no son cámaras: son teclados. Escriben el código carácter a
   * carácter a una velocidad imposible para una persona y cierran con Enter. Se
   * distinguen justo por eso: una pausa de más de 120 ms entre teclas rompe el
   * acumulado, así que teclear a mano nunca llega a formar un código completo.
   *
   * Con el foco dentro de un campo de texto NO se hace nada: el disparo de la
   * pistola entra en ese campo y lo atiende él (marca esos campos con
   * `data-escaner` para documentarlo). Si se procesara además aquí, un solo
   * disparo se registraría dos veces — una por el campo y otra por este oyente.
   *
   * Devuelve una función para dejar de escuchar.
   */
  function teclado(opts) {
    opts = opts || {};
    var minLargo = opts.minLargo || 4;
    var buffer = '';
    var ultimaTecla = 0;

    function onKey(ev) {
      if (ev.ctrlKey || ev.altKey || ev.metaKey) return;

      var a = document.activeElement;
      var enCampo = a && (a.tagName === 'INPUT' || a.tagName === 'TEXTAREA' || a.isContentEditable);
      if (enCampo) return;          // lo atiende el propio campo
      if (estado.abierto) return;   // el overlay tiene su propio campo

      var t = Date.now();
      if (t - ultimaTecla > 120) buffer = '';   // pausa larga = empieza de nuevo
      ultimaTecla = t;

      if (ev.key === 'Enter') {
        var cod = buffer;
        buffer = '';
        if (cod.length >= minLargo) {
          ev.preventDefault();
          pitar(true);
          opts.onCodigo(cod, 'wedge');
        }
        return;
      }
      if (ev.key.length === 1) buffer += ev.key;
    }

    document.addEventListener('keydown', onKey, true);
    return function () { document.removeEventListener('keydown', onKey, true); };
  }

  function configurar(o) {
    if (o && o.vendor) cfg.vendor = o.vendor;
  }

  return {
    configurar: configurar,
    abrir: abrir,
    cerrar: cerrar,
    soportado: soportado,
    motivoNoSoportado: motivoNoSoportado,
    teclado: teclado,
    pitar: pitar,
  };
})();
