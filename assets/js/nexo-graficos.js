/*
 * NexoGraficos — capa fina sobre Apache ECharts (assets/js/vendor/echarts.min.js).
 *
 * El servidor arma la «option» de ECharts en PHP (grafico() en includes/graficos.php)
 * y aquí se le pone lo que no viaja en JSON: formato de cifras, tooltips, ejes
 * abreviados, caja de herramientas (imagen, tabla, restaurar), clic para
 * profundizar, redimensionado y accesibilidad.
 *
 * Reglas de la casa (ver la guía de visualización):
 *   · un solo eje de valores por gráfico (nunca doble eje);
 *   · colores por entidad, en orden fijo; el año anterior va en gris;
 *   · el texto nunca va del color de la serie.
 */
(function () {
  'use strict';

  var PALETA = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'];
  var TINTA = { primaria: '#0f172a', secundaria: '#52514e', tenue: '#94a3b8', rejilla: '#e1e0d9', eje: '#c3c2b7' };

  /** Los nombres vienen de la base (productos, promociones): siempre escapados en HTML. */
  function esc(t) {
    return String(t === null || t === undefined ? '' : t).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function num(v, dec) {
    return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec });
  }

  /** Abrevia para ejes: 1.2M, 350K. */
  function abrev(v) {
    var a = Math.abs(v), s = v < 0 ? '-' : '';
    if (a >= 1e9) return s + num(a / 1e9, a >= 1e10 ? 0 : 1) + 'B';
    if (a >= 1e6) return s + num(a / 1e6, a >= 1e7 ? 0 : 1) + 'M';
    if (a >= 1e3) return s + num(a / 1e3, a >= 1e4 ? 0 : 1) + 'K';
    return s + num(a, a % 1 ? 1 : 0);
  }

  var NG = {
    moneda: 'RD$',
    esc: esc,
    paleta: PALETA,

    /** Formato de una cifra según su tipo. */
    fmt: function (v, tipo) {
      if (v === null || v === undefined || v === '' || isNaN(v)) return '—';
      v = Number(v);
      switch (tipo) {
        case 'money':  return NG.moneda + ' ' + num(v, 2);
        case 'money0': return NG.moneda + ' ' + num(v, 0);
        case 'pct':    return num(v, 1) + '%';
        case 'pts':    return (v >= 0 ? '+' : '−') + num(Math.abs(v), 1) + ' pts';
        case 'dec':    return num(v, 2);
        case 'x':      return num(v, 2) + 'x';
        default:       return num(v, 0);
      }
    },

    /** Formato corto para el eje. */
    fmtEje: function (v, tipo) {
      if (tipo === 'pct') return num(v, v % 1 ? 1 : 0) + '%';
      if (tipo === 'dec' || tipo === 'x') return num(v, Math.abs(v) < 10 ? 2 : 1);
      return abrev(v);
    },

    graficos: [],

    /**
     * @param {string} id      contenedor
     * @param {object} opt     option de ECharts
     * @param {object} cfg     formato ('money0'|'money'|'pct'|'num'|'dec'|'x'), titulo (para la imagen),
     *                         herramientas (bool), tipos (['line','bar'] para cambiar de forma)
     */
    montar: function (id, opt, cfg) {
      var el = document.getElementById(id);
      if (!el || !window.echarts) return null;
      cfg = cfg || {};
      var formato = cfg.formato || 'money0';
      var reducir = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

      opt.color = opt.color || PALETA;
      opt.animation = reducir ? false : (opt.animation !== undefined ? opt.animation : true);
      opt.textStyle = Object.assign({ fontFamily: 'Inter, ui-sans-serif, system-ui, sans-serif', color: TINTA.secundaria }, opt.textStyle || {});
      if (!opt.grid && !opt.series.some(function (s) { return ['pie', 'treemap', 'sunburst'].indexOf(s.type) >= 0; })) {
        opt.grid = { left: 8, right: 16, top: opt.legend ? 40 : 16, bottom: opt.dataZoom ? 56 : 8, containLabel: true };
      }
      if (opt.legend) {
        opt.legend = Object.assign({ top: 0, left: 0, icon: 'roundRect', itemWidth: 12, itemHeight: 12,
          textStyle: { color: TINTA.secundaria, fontSize: 12 } }, opt.legend);
      }

      // Tooltip: la cifra exacta siempre; si el punto trae su propio HTML (`tip`), se usa ése.
      opt.tooltip = Object.assign({
        trigger: opt.series.some(function (s) { return s.type === 'line' || s.type === 'bar'; }) && !opt.series.some(function (s) { return s.type === 'scatter'; }) ? 'axis' : 'item',
        confine: true,
        backgroundColor: '#ffffff', borderColor: 'rgba(11,11,11,0.10)', borderWidth: 1,
        textStyle: { color: TINTA.primaria, fontSize: 12 },
        extraCssText: 'box-shadow:0 8px 24px -12px rgba(15,23,42,.35);border-radius:10px;padding:8px 10px;',
        axisPointer: { type: 'shadow', shadowStyle: { color: 'rgba(15,23,42,0.04)' }, lineStyle: { color: TINTA.eje } },
        valueFormatter: function (v) { return NG.fmt(v, formato); }
      }, opt.tooltip || {});
      if (!opt.tooltip.formatter) {
        opt.tooltip.formatter = function (p) {
          var uno = Array.isArray(p) ? null : p;
          if (uno && uno.data && uno.data.tip) return uno.data.tip;
          if (uno) {
            var val = Array.isArray(uno.value) ? uno.value[uno.value.length - 1] : uno.value;
            return '<b>' + esc(uno.name) + '</b><br>' + uno.marker + (uno.seriesName && uno.seriesName.indexOf('series') !== 0 ? esc(uno.seriesName) + ': ' : '')
              + '<b>' + NG.fmt(val, formato) + '</b>' + (uno.percent !== undefined ? ' · ' + num(uno.percent, 1) + '%' : '');
          }
          var h = '<b>' + esc(p[0].axisValueLabel) + '</b>';
          p.forEach(function (s) {
            if (s.seriesName === '_base') return;   // barra invisible de la cascada
            h += '<br>' + s.marker + esc(s.seriesName) + ': <b>' + NG.fmt(Array.isArray(s.value) ? s.value[1] : s.value, formato) + '</b>';
          });
          return h;
        };
      }

      // Ejes: rejilla tenue y cifras abreviadas en los de valor.
      ['xAxis', 'yAxis'].forEach(function (k) {
        if (!opt[k]) return;
        (Array.isArray(opt[k]) ? opt[k] : [opt[k]]).forEach(function (ax) {
          ax.axisLine = Object.assign({ lineStyle: { color: TINTA.eje } }, ax.axisLine || {});
          ax.axisTick = Object.assign({ show: false }, ax.axisTick || {});
          ax.axisLabel = Object.assign({ color: TINTA.secundaria, fontSize: 11, hideOverlap: true }, ax.axisLabel || {});
          ax.splitLine = Object.assign({ lineStyle: { color: TINTA.rejilla, type: 'dashed' } }, ax.splitLine || {});
          if (ax.type === 'value' && !ax.axisLabel.formatter) {
            var f = ax.formato || formato;
            ax.axisLabel.formatter = function (v) { return NG.fmtEje(v, f); };
          }
          delete ax.formato;
        });
      });

      // Etiquetas directas con formato (label.formato en la serie).
      opt.series.forEach(function (s) {
        if (s.label && s.label.formato && !s.label.formatter) {
          var lf = s.label.formato, cn = !!s.label.conNombre;
          s.label.formatter = function (p) {
            var v = p.data && p.data.real !== undefined ? p.data.real : (Array.isArray(p.value) ? p.value[p.value.length - 1] : p.value);
            return (cn ? p.name + '\n' : '') + NG.fmt(v, lf);
          };
          delete s.label.formato; delete s.label.conNombre;
        }
        if (s.type === 'treemap' || s.type === 'sunburst') {
          s.label = Object.assign({ color: '#fff', fontSize: 12 }, s.label || {});
        }
      });

      if (cfg.herramientas !== false) {
        var feats = {
          saveAsImage: { title: 'Guardar imagen', name: cfg.titulo || 'grafico', pixelRatio: 2, backgroundColor: '#ffffff' },
          dataView: { title: 'Ver datos', readOnly: true, lang: ['Datos', 'Cerrar', 'Actualizar'],
            optionToContent: function (o) { return NG.tabla(o, formato); } },
          restore: { title: 'Restaurar' }
        };
        if (cfg.tipos) feats.magicType = { type: cfg.tipos, title: { line: 'Líneas', bar: 'Barras', stack: 'Apilar', tiled: 'Separar' } };
        if (opt.dataZoom) feats.dataZoom = { yAxisIndex: 'none', title: { zoom: 'Acercar', back: 'Deshacer acercamiento' } };
        opt.toolbox = Object.assign({ right: 10, top: 0, itemSize: 14, itemGap: 10, iconStyle: { borderColor: TINTA.tenue }, emphasis: { iconStyle: { borderColor: '#2a78d6' } }, feature: feats }, opt.toolbox || {});
      }

      var g = echarts.init(el, null, { renderer: 'svg' });
      g.setOption(opt);
      NG.graficos.push(g);

      // Profundizar: un punto con `url` lleva a su detalle.
      g.on('click', function (p) {
        var u = p.data && p.data.url;
        if (u) window.location.href = u;
      });
      if (opt.series.some(function (s) { return (s.data || []).some(function (d) { return d && d.url; }); })) {
        el.style.cursor = 'pointer';
      }

      // Tarjetas plegables o pestañas ocultas miden 0 al cargar: se redimensiona al aparecer.
      if (window.ResizeObserver) new ResizeObserver(function () { g.resize(); }).observe(el);
      return g;
    },

    /** Tabla accesible de la option (vista «Ver datos»). */
    tabla: function (o, formato) {
      var ejeCat = null;
      [].concat(o.xAxis || [], o.yAxis || []).forEach(function (a) { if (a && a.type === 'category') ejeCat = a; });
      var series = (o.series || []).filter(function (s) { return s.name !== '_base'; });
      var th = 'style="text-align:left;padding:6px 10px;border-bottom:1px solid #e2e8f0;font:600 12px Inter,sans-serif;color:#475569"';
      var td = 'style="padding:6px 10px;border-bottom:1px solid #f1f5f9;font:12px Inter,sans-serif;color:#0f172a"';
      var tdn = 'style="padding:6px 10px;border-bottom:1px solid #f1f5f9;font:12px Inter,sans-serif;color:#0f172a;text-align:right;font-variant-numeric:tabular-nums"';
      var h = '<div style="overflow:auto;max-height:100%"><table style="border-collapse:collapse;width:100%">';
      if (ejeCat) {
        h += '<tr><th ' + th + '></th>' + series.map(function (s) { return '<th ' + th + '>' + esc(s.name) + '</th>'; }).join('') + '</tr>';
        (ejeCat.data || []).forEach(function (c, i) {
          h += '<tr><td ' + td + '>' + esc(c && c.value !== undefined ? c.value : c) + '</td>' + series.map(function (s) {
            var d = (s.data || [])[i]; var v = d && typeof d === 'object' && !Array.isArray(d) ? d.value : d;
            return '<td ' + tdn + '>' + NG.fmt(Array.isArray(v) ? v[1] : v, formato) + '</td>';
          }).join('') + '</tr>';
        });
      } else {
        var filas = [];
        var recorrer = function (arr, pre) {
          (arr || []).forEach(function (d) {
            var n = (pre ? pre + ' › ' : '') + (d.name || '');
            var v = Array.isArray(d.value) ? d.value[d.value.length - 1] : d.value;
            filas.push([n, v]);
            if (d.children) recorrer(d.children, n);
          });
        };
        series.forEach(function (s) { recorrer(s.data, ''); });
        filas.forEach(function (f) { h += '<tr><td ' + td + '>' + esc(f[0]) + '</td><td ' + tdn + '>' + NG.fmt(f[1], formato) + '</td></tr>'; });
      }
      return h + '</table></div>';
    }
  };

  // Imprimir: ECharts en SVG se imprime nítido; basta con reajustar al ancho del papel.
  window.addEventListener('beforeprint', function () { NG.graficos.forEach(function (g) { g.resize(); }); });
  window.addEventListener('afterprint', function () { NG.graficos.forEach(function (g) { g.resize(); }); });

  window.NexoGraficos = NG;
})();
