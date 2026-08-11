<?php
/**
 * Editor visual de mensajes (WYSIWYG) + variables como etiquetas.
 *
 * Por qué está escrito a mano y no se usa una librería: el proyecto no tiene
 * paso de build y la CSP solo admite dos CDN. Meter un editor de 300 KB por
 * cargar negrita y cursiva no compensa; esto son ~200 líneas sin dependencias.
 *
 * Lo importante para quien lo usa: el dueño del negocio NUNCA ve una etiqueta
 * HTML. Escribe como en Word, pulsa botones, y las variables se ven como
 * etiquetas de colores («Nombre del cliente»), no como `{{cliente}}`.
 *
 * Cómo viaja el contenido:
 *   BD  →  {{cliente}}  →  editorAVista()  →  etiqueta visual
 *   etiqueta visual  →  editorAHtml()  →  {{cliente}}  →  textarea oculto  →  BD
 *
 * El textarea oculto es el que se envía en el formulario, así que el servidor
 * sigue recibiendo exactamente lo mismo que antes y `mkt_html_seguro()` sigue
 * siendo la última palabra sobre lo que se guarda.
 */

/**
 * Pinta el editor.
 *
 * @param string $campo  nombre del campo del formulario (el textarea oculto)
 * @param string $valor  HTML actual
 * @param array  $opts   ['alto' => '260px', 'id' => 'ed1', 'placeholder' => '...']
 */
function editor_visual(string $campo, string $valor, array $opts = []): string
{
    static $n = 0;
    $id     = $opts['id'] ?? ('editor' . (++$n));
    $alto   = $opts['alto'] ?? '260px';
    $place  = $opts['placeholder'] ?? 'Escribe aquí tu mensaje…';
    $vars   = mkt_variables_catalogo();

    $botones = [
        ['negrita',   'bold',      'Negrita',        'B',  'font-bold'],
        ['cursiva',   'italic',    'Cursiva',        'I',  'italic font-serif'],
        ['subrayado', 'underline', 'Subrayado',      'U',  'underline'],
    ];

    $h  = '<div class="editor-wrap" data-editor="' . e($id) . '">';

    /* ---------- Barra de herramientas ---------- */
    $h .= '<div class="flex flex-wrap items-center gap-1 p-2 rounded-t-xl border border-slate-200 bg-slate-50">';

    foreach ($botones as [$k, $cmd, $titulo, $letra, $clase]) {
        $h .= '<button type="button" data-cmd="' . e($cmd) . '" title="' . e($titulo) . '"
                 class="ed-btn w-8 h-8 rounded-lg text-slate-600 hover:bg-white hover:text-blue-600 hover:shadow-sm text-sm ' . $clase . '">'
            . e($letra) . '</button>';
    }

    $h .= '<span class="w-px h-5 bg-slate-200 mx-1"></span>';

    // Tamaños de texto: se traducen a <h3>/<p>, que es lo que entienden Gmail y Outlook.
    $h .= '<select data-cmd="formatBlock" title="Tamaño del texto"
             class="ed-sel h-8 rounded-lg border border-slate-200 bg-white text-xs text-slate-600 px-2">
             <option value="p">Texto normal</option>
             <option value="h3">Título</option>
             <option value="h4">Subtítulo</option>
           </select>';

    $h .= '<span class="w-px h-5 bg-slate-200 mx-1"></span>';

    foreach ([['insertUnorderedList', 'Lista con viñetas', '•'], ['insertOrderedList', 'Lista numerada', '1.']] as [$cmd, $t, $ico]) {
        $h .= '<button type="button" data-cmd="' . $cmd . '" title="' . e($t) . '"
                 class="ed-btn w-8 h-8 rounded-lg text-slate-600 hover:bg-white hover:text-blue-600 hover:shadow-sm text-sm">' . $ico . '</button>';
    }

    foreach ([['justifyLeft', 'Alinear a la izquierda', '&#8676;'], ['justifyCenter', 'Centrar', '&#8596;'], ['justifyRight', 'Alinear a la derecha', '&#8677;']] as [$cmd, $t, $ico]) {
        $h .= '<button type="button" data-cmd="' . $cmd . '" title="' . e($t) . '"
                 class="ed-btn w-8 h-8 rounded-lg text-slate-600 hover:bg-white hover:text-blue-600 hover:shadow-sm text-sm">' . $ico . '</button>';
    }

    $h .= '<span class="w-px h-5 bg-slate-200 mx-1"></span>';

    $h .= '<button type="button" data-accion="enlace" title="Insertar enlace"
             class="ed-btn h-8 px-2 rounded-lg text-slate-600 hover:bg-white hover:text-blue-600 hover:shadow-sm text-xs font-semibold">Enlace</button>';
    $h .= '<button type="button" data-accion="color" title="Color del texto"
             class="ed-btn h-8 px-2 rounded-lg text-slate-600 hover:bg-white hover:text-blue-600 hover:shadow-sm text-xs font-semibold">Color</button>';
    $h .= '<input type="color" class="ed-color sr-only" value="#14532D">';
    $h .= '<button type="button" data-cmd="removeFormat" title="Quitar formato"
             class="ed-btn h-8 px-2 rounded-lg text-slate-600 hover:bg-white hover:text-rose-600 hover:shadow-sm text-xs font-semibold">Limpiar</button>';

    $h .= '</div>';

    /* ---------- Zona editable ---------- */
    $h .= '<div class="ed-area w-full px-4 py-3 border-x border-b border-slate-200 rounded-b-xl bg-white
                       text-[15px] leading-relaxed text-slate-700 overflow-y-auto focus:outline-none
                       focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                contenteditable="true" role="textbox" aria-multiline="true"
                data-placeholder="' . e($place) . '"
                style="min-height:' . e($alto) . '"></div>';

    /* ---------- Variables ---------- */
    $h .= '<div class="mt-3">';
    $h .= '<p class="text-xs font-semibold text-slate-500 mb-1.5">Datos que se rellenan solos con la información de cada cliente:</p>';
    $h .= '<div class="flex flex-wrap gap-1.5">';
    foreach ($vars as $clave => $etiqueta) {
        $h .= '<button type="button" class="ed-var text-xs font-medium px-2.5 py-1.5 rounded-lg bg-blue-50 text-blue-700
                 border border-blue-100 hover:bg-blue-100 transition"
                 data-var="' . e(trim($clave, '{}')) . '">+ ' . e($etiqueta) . '</button>';
    }
    $h .= '</div>';
    $h .= '<p class="text-xs text-slate-400 mt-2">Al enviar, cada etiqueta se cambia por el dato real de la persona que recibe el correo.</p>';
    $h .= '</div>';

    /* ---------- Campo real del formulario ---------- */
    $h .= '<textarea name="' . e($campo) . '" class="ed-fuente sr-none" hidden>' . e($valor) . '</textarea>';
    $h .= '</div>';

    return $h;
}

/**
 * Panel «así lo recibirá tu cliente»: bandeja de entrada simulada + el correo
 * real dentro de un iframe, y la versión de WhatsApp en una pestaña aparte.
 *
 * No dibuja el correo en JavaScript: se lo pide al servidor, que lo genera con
 * `mkt_html_correo()` — la misma función que envía. Cuesta 300 ms de retraso y
 * a cambio la vista previa no puede mentir.
 *
 * @param string $endpoint URL que devuelve {html, whatsapp, asunto, preheader}
 * @param string $form     selector del formulario que se vigila
 */
function preview_correo_panel(string $endpoint, string $form = 'form[data-preview]'): string
{
    $emp    = $GLOBALS['empresa'] ?? [];
    $de     = $emp['nombre'] ?? APP_NAME;
    $inicial = mb_strtoupper(mb_substr(trim((string) $de), 0, 1));

    return '
<div class="card overflow-hidden" data-preview-panel>
  <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between gap-3">
    <div>
      <h2 class="font-bold text-slate-800 flex items-center gap-2">' . icon('eye', 'w-4 h-4 text-slate-400') . ' Así lo recibirá tu cliente</h2>
      <p class="text-xs text-slate-400">Se actualiza solo mientras escribes</p>
    </div>
    <div class="flex items-center gap-1 bg-slate-100 rounded-xl p-1">
      <button type="button" data-vista="email"
              class="pv-tab px-3 py-1.5 rounded-lg text-xs font-semibold bg-white text-blue-600 shadow-sm">Correo</button>
      <button type="button" data-vista="whatsapp"
              class="pv-tab px-3 py-1.5 rounded-lg text-xs font-semibold text-slate-500">WhatsApp</button>
    </div>
  </div>

  <!-- Vista de correo -->
  <div data-panel="email">
    <div class="px-5 py-3 bg-slate-50 border-b border-slate-100 flex items-start gap-3">
      <div class="w-9 h-9 rounded-full bg-emerald-600 text-white flex items-center justify-center font-bold text-sm shrink-0">' . e($inicial) . '</div>
      <div class="min-w-0 flex-1">
        <p class="text-sm font-semibold text-slate-700 truncate">' . e($de) . '</p>
        <p class="text-sm text-slate-800 font-medium truncate" data-pv-asunto>—</p>
        <p class="text-xs text-slate-400 truncate" data-pv-preheader></p>
      </div>
      <span class="text-xs text-slate-400 shrink-0">ahora</span>
    </div>
    <div class="relative bg-slate-100">
      <iframe data-pv-frame title="Vista previa del correo" sandbox="allow-same-origin"
              class="w-full block bg-white" style="height:520px;border:0"></iframe>
      <div data-pv-cargando class="absolute inset-0 bg-white/60 hidden items-center justify-center">
        <span class="text-xs font-semibold text-slate-500 bg-white px-3 py-1.5 rounded-lg shadow">Actualizando…</span>
      </div>
    </div>
  </div>

  <!-- Vista de WhatsApp -->
  <div data-panel="whatsapp" class="hidden">
    <div class="p-5" style="background:#ECE5DD;min-height:520px">
      <div class="max-w-sm mx-auto">
        <div class="rounded-2xl rounded-tl-sm px-4 py-3 shadow-sm" style="background:#fff">
          <p class="text-sm text-slate-800 whitespace-pre-wrap leading-relaxed" data-pv-whatsapp>—</p>
          <p class="text-[10px] text-slate-400 text-right mt-1">ahora ✓✓</p>
        </div>
        <p class="text-xs text-center mt-4" style="color:#5b6b63">
          Este mensaje se abre ya escrito en WhatsApp. Tú solo pulsas enviar.
        </p>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  const panel = document.querySelector("[data-preview-panel]");
  const form  = document.querySelector("' . $form . '");
  if (!panel || !form) return;

  const frame     = panel.querySelector("[data-pv-frame]");
  const asunto    = panel.querySelector("[data-pv-asunto]");
  const pre       = panel.querySelector("[data-pv-preheader]");
  const wa        = panel.querySelector("[data-pv-whatsapp]");
  const cargando  = panel.querySelector("[data-pv-cargando]");

  panel.querySelectorAll(".pv-tab").forEach(t => t.addEventListener("click", () => {
    panel.querySelectorAll(".pv-tab").forEach(o => {
      const on = o === t;
      o.classList.toggle("bg-white", on);
      o.classList.toggle("text-blue-600", on);
      o.classList.toggle("shadow-sm", on);
      o.classList.toggle("text-slate-500", !on);
    });
    panel.querySelector("[data-panel=email]").classList.toggle("hidden", t.dataset.vista !== "email");
    panel.querySelector("[data-panel=whatsapp]").classList.toggle("hidden", t.dataset.vista !== "whatsapp");
  }));

  let temporizador = null, enVuelo = null;

  async function refrescar() {
    const datos = new FormData(form);
    datos.set("accion", "api_preview");
    // Los archivos no viajan: la vista previa no sube imágenes.
    datos.delete("imagen");

    if (enVuelo) enVuelo.abort();
    enVuelo = new AbortController();
    cargando.classList.remove("hidden"); cargando.classList.add("flex");

    try {
      const r = await fetch("' . $endpoint . '", { method: "POST", body: datos, signal: enVuelo.signal });
      const j = await r.json();
      if (!j.ok) return;
      frame.srcdoc  = j.html;
      asunto.textContent = j.asunto || "(sin asunto)";
      pre.textContent    = j.preheader || "";
      wa.textContent     = j.whatsapp || "(sin mensaje de WhatsApp)";
    } catch (e) {
      if (e.name !== "AbortError") { /* un fallo de red no debe romper la edición */ }
    } finally {
      cargando.classList.add("hidden"); cargando.classList.remove("flex");
    }
  }

  function programar() { clearTimeout(temporizador); temporizador = setTimeout(refrescar, 350); }

  form.addEventListener("input",  programar);
  form.addEventListener("change", programar);
  refrescar();
})();
</script>';
}

/** Estilos + lógica. Se imprime UNA vez por página, después de los editores. */
function editor_visual_assets(): string
{
    $vars = [];
    foreach (mkt_variables_catalogo() as $clave => $etiqueta) {
        $vars[trim($clave, '{}')] = $etiqueta;
    }

    return '
<style>
  .ed-area:empty:before { content: attr(data-placeholder); color: #94a3b8; }
  .ed-area h3 { font-size: 1.25rem; font-weight: 700; margin: .6em 0 .3em; color:#1e293b; }
  .ed-area h4 { font-size: 1.05rem; font-weight: 700; margin: .6em 0 .3em; color:#334155; }
  .ed-area p  { margin: 0 0 .7em; }
  .ed-area ul { list-style: disc;   padding-left: 1.4em; margin: 0 0 .7em; }
  .ed-area ol { list-style: decimal;padding-left: 1.4em; margin: 0 0 .7em; }
  .ed-area a  { color:#3B4A83; text-decoration: underline; }
  .ed-var-chip {
    display:inline-block; padding:.05em .5em; margin:0 .1em; border-radius:.5rem;
    background:#E7EAF6; color:#2F3D6F; font-size:.85em; font-weight:600;
    white-space:nowrap; user-select:all; cursor:default;
  }
  .ed-btn.activo { background:#fff; color:#3B4A83; box-shadow:0 1px 2px rgba(0,0,0,.06); }
  textarea.ed-fuente { display:none !important; }
</style>
<script>
(function () {
  const ETIQUETAS = ' . json_encode($vars, JSON_UNESCAPED_UNICODE) . ';

  // {{cliente}} → etiqueta visual
  function aVista(html) {
    return String(html || "").replace(/\{\{(\w+)\}\}/g, (m, k) =>
      ETIQUETAS[k]
        ? `<span class="ed-var-chip" contenteditable="false" data-var="${k}">${ETIQUETAS[k]}</span>`
        : m);
  }

  // etiqueta visual → {{cliente}}, y limpieza de lo que no debe viajar en un correo
  function aHtml(area) {
    const copia = area.cloneNode(true);
    copia.querySelectorAll("span.ed-var-chip").forEach(ch => {
      ch.replaceWith(document.createTextNode("{{" + ch.dataset.var + "}}"));
    });
    copia.querySelectorAll("script,style,iframe,object,embed,form,meta,link").forEach(n => n.remove());
    copia.querySelectorAll("*").forEach(n => {
      [...n.attributes].forEach(a => {
        const permitido = ["href","style","target","rel","src","alt"].includes(a.name.toLowerCase());
        if (!permitido || /^on/i.test(a.name)) n.removeAttribute(a.name);
      });
      const href = n.getAttribute && n.getAttribute("href");
      if (href && /^\s*javascript:/i.test(href)) n.setAttribute("href", "#");
    });
    return copia.innerHTML.trim();
  }

  function iniciar(wrap) {
    if (wrap.dataset.listo) return;
    wrap.dataset.listo = "1";

    const area   = wrap.querySelector(".ed-area");
    const fuente = wrap.querySelector(".ed-fuente");
    const color  = wrap.querySelector(".ed-color");
    const form   = wrap.closest("form");

    area.innerHTML = aVista(fuente.value) || "<p><br></p>";

    const sincronizar = () => {
      fuente.value = aHtml(area);
      fuente.dispatchEvent(new Event("input", { bubbles: true }));
    };

    // Pegar SIEMPRE como texto plano: evita que Word/Docs metan basura que
    // Outlook luego renderiza torcido.
    area.addEventListener("paste", (ev) => {
      ev.preventDefault();
      const txt = (ev.clipboardData || window.clipboardData).getData("text/plain");
      document.execCommand("insertText", false, txt);
    });

    area.addEventListener("input", sincronizar);
    area.addEventListener("blur", sincronizar);

    // Enter crea párrafo nuevo, no <div> ni <br> sueltos.
    try { document.execCommand("defaultParagraphSeparator", false, "p"); } catch (e) {}

    wrap.querySelectorAll(".ed-btn[data-cmd]").forEach(b => {
      b.addEventListener("click", () => {
        area.focus();
        document.execCommand(b.dataset.cmd, false, null);
        sincronizar(); estado();
      });
    });

    const sel = wrap.querySelector(".ed-sel");
    if (sel) sel.addEventListener("change", () => {
      area.focus();
      document.execCommand("formatBlock", false, sel.value);
      sincronizar();
    });

    wrap.querySelector("[data-accion=enlace]").addEventListener("click", () => {
      const texto = String(window.getSelection());
      const url = window.prompt(
        texto ? `Enlace para «${texto}»:` : "Dirección del enlace (se insertará como texto):",
        "https://"
      );
      if (!url || url === "https://") return;
      area.focus();
      if (texto) document.execCommand("createLink", false, url);
      else document.execCommand("insertHTML", false, `<a href="${url}">${url}</a>`);
      sincronizar();
    });

    wrap.querySelector("[data-accion=color]").addEventListener("click", () => color.click());
    color.addEventListener("input", () => {
      area.focus();
      document.execCommand("foreColor", false, color.value);
      sincronizar();
    });

    wrap.querySelectorAll(".ed-var").forEach(b => {
      b.addEventListener("click", () => {
        area.focus();
        const k = b.dataset.var;
        document.execCommand("insertHTML", false,
          `<span class="ed-var-chip" contenteditable="false" data-var="${k}">${ETIQUETAS[k]}</span>&nbsp;`);
        sincronizar();
      });
    });

    // Resaltar los botones activos según dónde esté el cursor.
    function estado() {
      wrap.querySelectorAll(".ed-btn[data-cmd]").forEach(b => {
        let on = false;
        try { on = document.queryCommandState(b.dataset.cmd); } catch (e) {}
        b.classList.toggle("activo", !!on);
      });
    }
    area.addEventListener("keyup", estado);
    area.addEventListener("mouseup", estado);

    // Última sincronización antes de enviar, por si el foco nunca salió del área.
    if (form) form.addEventListener("submit", sincronizar);
  }

  function iniciarTodos() { document.querySelectorAll(".editor-wrap").forEach(iniciar); }
  document.addEventListener("DOMContentLoaded", iniciarTodos);
  if (document.readyState !== "loading") iniciarTodos();
  // Los editores dentro de modales aparecen después: se re-inicia al abrirlos.
  document.addEventListener("editor:refrescar", iniciarTodos);
})();
</script>';
}
