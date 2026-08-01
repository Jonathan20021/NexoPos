<?php /** Cierre del layout. */ ?>
      </div><!-- /contenedor -->
    </main>
    <footer class="px-6 py-4 text-center text-xs text-slate-400 border-t border-slate-200/60">
      <?= e(APP_NAME) ?> · Sistema de Gestión Comercial Multi-Sucursal &copy; <?= date('Y') ?>
    </footer>
  </div><!-- /lg:pl -->
</div>

<!--
  Modal de confirmación global.

  Sustituye al confirm() nativo del navegador en TODO el sistema sin tocar cada
  página: el script de abajo lee el onsubmit="return confirm('…')" de cada
  formulario, se lo quita y lo enruta por aquí. Si el JavaScript falla, el
  onsubmit original sigue en pie y el navegador pregunta como siempre.
-->
<div id="confirmar-modal" class="modal-overlay" style="display:none" role="dialog" aria-modal="true" aria-labelledby="confirmar-titulo">
  <div class="modal-panel bg-white rounded-2xl shadow-pop max-w-sm">
    <div class="p-6 text-center">
      <div id="confirmar-icono" class="w-14 h-14 rounded-2xl bg-rose-50 text-rose-600 flex items-center justify-center mx-auto mb-4">
        <?= icon('alert', 'w-7 h-7') ?>
      </div>
      <h3 id="confirmar-titulo" class="text-lg font-bold text-slate-800">¿Confirmas esta acción?</h3>
      <p id="confirmar-mensaje" class="text-sm text-slate-500 mt-2 leading-relaxed"></p>
    </div>
    <div class="flex gap-2 px-6 pb-6">
      <button type="button" id="confirmar-cancelar" class="btn btn-ghost flex-1">Cancelar</button>
      <button type="button" id="confirmar-aceptar" class="btn btn-danger flex-1"><?= icon('check', 'w-4 h-4') ?> Sí, continuar</button>
    </div>
  </div>
</div>

<script>
/**
 * Búsqueda en tiempo real para cualquier <input data-buscar> dentro de un <form method="get">.
 * Sin JavaScript el formulario sigue funcionando: se envía con Enter.
 */
(function () {
  'use strict';
  var RETARDO = 350;   // ms tras la última tecla
  var CLAVE_FOCO = 'nexopos:buscando';

  document.querySelectorAll('input[data-buscar]').forEach(function (input) {
    var form = input.form;
    if (!form) return;

    var temporizador = null;
    var ultimoEnviado = input.value;

    function enviar() {
      if (input.value === ultimoEnviado) return;   // no recargar si no cambió nada
      ultimoEnviado = input.value;
      // Toda búsqueda nueva vuelve a la página 1: si no, la "página 7" de otro filtro sale vacía.
      var p = form.querySelector('input[name="p"]');
      if (p) p.value = '1';
      try { sessionStorage.setItem(CLAVE_FOCO, input.name); } catch (e) {}
      form.submit();
    }

    input.addEventListener('input', function () {
      clearTimeout(temporizador);
      temporizador = setTimeout(enviar, RETARDO);
    });

    // Enter envía de inmediato, sin esperar el retardo.
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { clearTimeout(temporizador); ultimoEnviado = null; }
    });

    // La "x" nativa del <input type="search"> dispara 'search' en algunos navegadores.
    input.addEventListener('search', function () { clearTimeout(temporizador); enviar(); });
  });

  // Devuelve el cursor al buscador tras la recarga, para poder seguir escribiendo.
  try {
    var nombre = sessionStorage.getItem(CLAVE_FOCO);
    if (nombre) {
      sessionStorage.removeItem(CLAVE_FOCO);
      var campo = document.querySelector('input[data-buscar][name="' + nombre + '"]');
      if (campo) {
        campo.focus();
        var v = campo.value;
        campo.value = '';
        campo.value = v;   // deja el cursor al final
      }
    }
  } catch (e) {}
})();
</script>

<script>
/**
 * Confirmaciones con modal en lugar del cuadro gris del navegador.
 *
 * Recorre los formularios con onsubmit="return confirm('…')", les extrae el
 * mensaje y les quita el atributo: a partir de ahí la confirmación pasa por el
 * modal. Si este script no llega a ejecutarse, el onsubmit original sigue
 * intacto y la protección contra borrados accidentales no se pierde nunca.
 */
(function () {
  'use strict';

  var overlay  = document.getElementById('confirmar-modal');
  if (!overlay) return;
  var elMensaje = document.getElementById('confirmar-mensaje');
  var btnOk     = document.getElementById('confirmar-aceptar');
  var btnNo     = document.getElementById('confirmar-cancelar');
  var pendiente = null;
  var focoPrevio = null;

  function abrir(mensaje, alAceptar) {
    pendiente = alAceptar;
    focoPrevio = document.activeElement;
    elMensaje.textContent = mensaje;
    overlay.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    btnOk.focus();
  }

  function cerrar() {
    overlay.style.display = 'none';
    document.body.style.overflow = '';
    pendiente = null;
    if (focoPrevio && focoPrevio.focus) focoPrevio.focus();
  }

  btnNo.addEventListener('click', cerrar);
  overlay.addEventListener('click', function (e) { if (e.target === overlay) cerrar(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && overlay.style.display === 'flex') cerrar();
  });
  btnOk.addEventListener('click', function () {
    var accion = pendiente;
    cerrar();
    if (accion) accion();
  });

  // Traslada cada confirm() inline al modal.
  document.querySelectorAll('form[onsubmit]').forEach(function (form) {
    var inline = form.getAttribute('onsubmit') || '';
    var m = inline.match(/confirm\((['"])([\s\S]*?)\1\)/);
    if (!m) return;

    form.setAttribute('data-confirmar', m[2]);
    form.removeAttribute('onsubmit');

    form.addEventListener('submit', function (e) {
      if (form.dataset.confirmado === '1') return;   // ya pasó por el modal
      e.preventDefault();
      // Se conserva el botón que envió: si lleva name/value (accion=eliminar),
      // perderlo mandaría el formulario sin la acción y no haría nada.
      var boton = e.submitter || null;
      abrir(form.getAttribute('data-confirmar'), function () {
        form.dataset.confirmado = '1';
        // requestSubmit respeta la validación nativa; submit() no.
        if (form.requestSubmit) form.requestSubmit(boton); else form.submit();
      });
    });
  });
})();
</script>

<script>
/* Registro del Service Worker (modo offline / PWA). Silencioso si no hay soporte. */
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('<?= e(url('sw.js')) ?>').catch(function () {});
  });
}
</script>
</body>
</html>
