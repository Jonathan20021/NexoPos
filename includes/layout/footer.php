<?php /** Cierre del layout. */ ?>
      </div><!-- /contenedor -->
    </main>
    <footer class="px-6 py-4 text-center text-xs text-slate-400 border-t border-slate-200/60">
      <?= e(APP_NAME) ?> · Sistema de Gestión Comercial Multi-Sucursal &copy; <?= date('Y') ?>
    </footer>
  </div><!-- /lg:pl -->
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
/* Registro del Service Worker (modo offline / PWA). Silencioso si no hay soporte. */
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('<?= e(url('sw.js')) ?>').catch(function () {});
  });
}
</script>
</body>
</html>
