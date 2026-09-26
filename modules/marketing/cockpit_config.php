<?php
/**
 * Configuración del Promotion Cockpit y de los KPIs de campañas.
 *
 * Todo lo que el cockpit usa para clasificar y reportar se cambia aquí, sin
 * programador: tipos de descuento (y sus colores, y cuáles son motivos del
 * POS), canales de la marca y la regla que decide el canal de cada venta,
 * rubros de inversión, KPIs capturados a mano, parámetros generales y la
 * clasificación de los productos (segmento, línea, héroe).
 *
 * Una clave nunca se renombra: es lo que guardan las ventas y las
 * promociones. Se cambia el nombre, el color o se desactiva. Lo que ya se usó
 * no se borra — se desactiva, y el histórico conserva su nombre.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('cockpit.configurar');

$tabs = [
    'tipos'      => ['Tipos de descuento', 'percent'],
    'canales'    => ['Canales', 'megaphone'],
    'inversion'  => ['Rubros de inversión', 'wallet'],
    'kpis'       => ['KPIs de campañas', 'target'],
    'parametros' => ['Parámetros', 'settings'],
    'productos'  => ['Productos', 'package'],
    'estado'     => ['Estado', 'pulse'],
];
$tab = array_key_exists((string) get('tab'), $tabs) ? (string) get('tab') : 'tipos';

/** Panel «Estado de la instalación»: qué está vivo y qué falta, con el paso exacto. */
function cfg_panel_estado(): string
{
    $filas = cockpit_instalacion();
    $cap = cockpit_captura_reciente();
    $h = '<section class="card overflow-hidden mb-5"><div class="p-4 border-b border-slate-100">'
       . '<h3 class="font-bold text-slate-800">Estado de la instalación</h3>'
       . '<p class="text-sm text-slate-400">Lo que el Promotion Cockpit necesita en esta base de datos. Las migraciones se pueden repetir sin riesgo: solo añaden lo que falta.</p></div>'
       . '<ul class="divide-y divide-slate-100">';
    foreach ($filas as $f) {
        $icono = $f['ok'] ? '<span class="text-emerald-600">' . icon('check', 'w-5 h-5') . '</span>'
               : '<span class="' . ($f['grave'] ? 'text-rose-600' : 'text-amber-500') . '">' . icon('alert', 'w-5 h-5') . '</span>';
        $h .= '<li class="flex gap-3 px-4 py-3">' . $icono . '<div class="min-w-0"><p class="text-sm font-semibold text-slate-700">' . e($f['etiqueta']) . '</p>'
            . ($f['ok'] ? '<p class="text-xs text-slate-400">Listo</p>'
                : '<p class="text-xs text-slate-500">' . e($f['efecto']) . '</p><p class="text-xs font-semibold ' . ($f['grave'] ? 'text-rose-700' : 'text-amber-700') . ' mt-0.5">' . e($f['falta']) . '</p>')
            . '</div></li>';
    }
    // Las columnas pueden existir y un servidor seguir con el código viejo.
    if ($cap['pct'] !== null) {
        $bien = $cap['pct'] >= 95;
        $h .= '<li class="flex gap-3 px-4 py-3"><span class="' . ($bien ? 'text-emerald-600' : 'text-amber-500') . '">' . icon($bien ? 'check' : 'alert', 'w-5 h-5') . '</span>'
            . '<div><p class="text-sm font-semibold text-slate-700">Ventas de los últimos 7 días con rastro de promoción</p>'
            . '<p class="text-xs ' . ($bien ? 'text-slate-400' : 'text-amber-700 font-semibold') . '">' . number_format($cap['pct'], 1) . '% de ' . number_format($cap['lineas']) . ' líneas'
            . ($bien ? '' : '. Si la P39 ya corrió hace más de una semana, algún servidor o terminal sigue con el código anterior: actualízalo.') . '</p></div></li>';
    }
    return $h . '</ul></section>';
}

// Lee promociones.tipo_descuento y ventas.descuento_motivo: necesita las dos
// migraciones. Sin ellas solo se puede ver el estado, que dice qué falta.
if (!cockpit_disponible() || !cockpit_config_disponible()) {
    layout_start('Configuración del cockpit', 'Falta aplicar la actualización de la base de datos');
    echo cfg_panel_estado();
    layout_end();
    exit;
}

/** Clave válida: minúsculas, números y guion bajo. */
function cfg_clave(string $v, string $desde = ''): string
{
    $v = trim($v) !== '' ? $v : $desde;
    $v = strtr(mb_strtolower(trim($v)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
    $v = trim(preg_replace('/[^a-z0-9]+/', '_', $v), '_');
    return substr($v, 0, 40);
}

function cfg_color(string $v, string $def = '#64748b'): string
{
    return preg_match('/^#[0-9a-fA-F]{6}$/', trim($v)) ? strtolower(trim($v)) : $def;
}

/** Un porcentaje 0–100 con dos decimales, o null si viene vacío. */
function cfg_pct($v): ?float
{
    $v = trim(str_replace(',', '.', (string) $v));
    if ($v === '') return null;
    if (!is_numeric($v) || (float) $v < 0 || (float) $v > 100) throw new RuntimeException('Un porcentaje tiene que estar entre 0 y 100.');
    return round((float) $v, 2);
}

/** ¿Ya corrió la parte de la P40 que trae los topes por tipo? */
function cfg_con_tope(): bool
{
    static $ok = null;
    return $ok ??= (bool) qVal("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cockpit_tipos' AND COLUMN_NAME = 'tope_desc_pct'");
}

/** Texto recortado o null. */
function cfg_txt($v, int $max): ?string
{
    $v = trim((string) $v);
    return $v === '' ? null : mb_substr($v, 0, $max);
}

/* ============================================================
 *  Guardado
 * ============================================================ */
if (isPost()) {
    verify_csrf();
    $accion = (string) post('accion');
    $vuelta = 'modules/marketing/cockpit_config.php?tab=' . $tab;
    try {
        switch ($accion) {
            /* ---------- Tipos de descuento ---------- */
            case 'tipos_guardar':
                $filas = $_POST['t'] ?? [];
                $conTope = cfg_con_tope();
                tx(function () use ($filas, $conTope) {
                    foreach ((array) $filas as $clave => $f) {
                        if (!qVal("SELECT 1 FROM cockpit_tipos WHERE clave = ?", [$clave])) continue;
                        $nombre = cfg_txt($f['nombre'] ?? '', 80);
                        if (!$nombre) throw new RuntimeException("El tipo «{$clave}» necesita un nombre.");
                        dbUpdate('cockpit_tipos', [
                            'nombre'        => $nombre,
                            'color'         => cfg_color((string) ($f['color'] ?? '')),
                            'orden'         => (int) ($f['orden'] ?? 0),
                            'es_promocion'  => !empty($f['es_promocion']) ? 1 : 0,
                            'etiqueta_caja' => cfg_txt($f['etiqueta_caja'] ?? '', 80),
                        ] + ($conTope ? ['tope_desc_pct' => cfg_pct($f['tope'] ?? '')] : []) + [
                            // «Sin promoción» y compañía los usa el cálculo: siempre activos.
                            'activo'        => !empty($f['activo']) || (int) qVal("SELECT sistema FROM cockpit_tipos WHERE clave = ?", [$clave]) ? 1 : 0,
                        ], 'clave = ?', [$clave]);
                    }
                    // El descuento manual es el motivo por defecto del POS: no puede quedar sin etiqueta.
                    q("UPDATE cockpit_tipos SET etiqueta_caja = 'Descuento manual' WHERE clave = 'manual' AND (etiqueta_caja IS NULL OR etiqueta_caja = '')");
                });
                flash('success', 'Tipos de descuento guardados.');
                break;

            case 'tipo_nuevo':
                $nombre = cfg_txt(post('nombre'), 80);
                $clave = cfg_clave((string) post('clave'), (string) $nombre);
                if (!$nombre || $clave === '') throw new RuntimeException('Escribe el nombre del tipo.');
                if (qVal("SELECT 1 FROM cockpit_tipos WHERE clave = ?", [$clave])) throw new RuntimeException("Ya existe un tipo con la clave «{$clave}».");
                dbInsert('cockpit_tipos', [
                    'clave' => $clave, 'nombre' => $nombre, 'color' => cfg_color((string) post('color')),
                    'es_promocion' => postInt('es_promocion') ? 1 : 0, 'etiqueta_caja' => cfg_txt(post('etiqueta_caja'), 80),
                    'orden' => (int) qVal("SELECT COALESCE(MAX(orden),0) + 10 FROM cockpit_tipos"), 'activo' => 1,
                ]);
                flash('success', "Tipo «{$nombre}» creado.");
                break;

            case 'tipo_eliminar':
                $clave = (string) post('clave');
                $t = qOne("SELECT * FROM cockpit_tipos WHERE clave = ?", [$clave]);
                if (!$t) break;
                if ($t['sistema']) throw new RuntimeException('Ese tipo lo usa el cálculo del cockpit: se puede renombrar, no borrar.');
                $usos = (int) qVal("SELECT COUNT(*) FROM promociones WHERE tipo_descuento = ?", [$clave])
                      + (int) qVal("SELECT COUNT(*) FROM ventas WHERE descuento_motivo = ?", [$clave]);
                if ($usos) throw new RuntimeException("«{$t['nombre']}» ya se usó en {$usos} promoción(es) o venta(s). Desactívalo para que no se ofrezca más; el histórico conserva su nombre.");
                q("DELETE FROM cockpit_tipos WHERE clave = ?", [$clave]);
                flash('success', "Tipo «{$t['nombre']}» eliminado.");
                break;

            /* ---------- Canales ---------- */
            case 'canales_guardar':
                foreach ((array) ($_POST['c'] ?? []) as $clave => $f) {
                    $nombre = cfg_txt($f['nombre'] ?? '', 60);
                    if (!$nombre) throw new RuntimeException("El canal «{$clave}» necesita un nombre.");
                    if (empty($f['activo']) && $clave === (string) cockpit_param('canal_defecto', 'retail')) {
                        throw new RuntimeException("«{$nombre}» es el canal por defecto: elige otro en Parámetros antes de desactivarlo.");
                    }
                    dbUpdate('cockpit_canales', ['nombre' => $nombre, 'nombre_en' => cfg_txt($f['nombre_en'] ?? '', 60),
                        'orden' => (int) ($f['orden'] ?? 0), 'activo' => !empty($f['activo']) ? 1 : 0], 'clave = ?', [$clave]);
                }
                foreach ((array) ($_POST['r'] ?? []) as $id => $f) {
                    $canal = (string) ($f['canal'] ?? '');
                    if (!qVal("SELECT 1 FROM cockpit_canales WHERE clave = ?", [$canal])) continue;
                    dbUpdate('cockpit_canal_reglas', ['canal' => $canal, 'orden' => (int) ($f['orden'] ?? 0)], 'id = ?', [(int) $id]);
                }
                flash('success', 'Canales y reglas guardados.');
                break;

            case 'canal_nuevo':
                $nombre = cfg_txt(post('nombre'), 60);
                $clave = substr(cfg_clave((string) post('clave'), (string) $nombre), 0, 20);
                if (!$nombre || $clave === '') throw new RuntimeException('Escribe el nombre del canal.');
                if ($clave === 'total') throw new RuntimeException('«total» es una palabra reservada: el sistema la usa para la fila de totales.');
                if (qVal("SELECT 1 FROM cockpit_canales WHERE clave = ?", [$clave])) throw new RuntimeException("Ya existe el canal «{$clave}».");
                dbInsert('cockpit_canales', ['clave' => $clave, 'nombre' => $nombre, 'nombre_en' => cfg_txt(post('nombre_en'), 60),
                    'orden' => (int) qVal("SELECT COALESCE(MAX(orden),0) + 10 FROM cockpit_canales"), 'activo' => 1]);
                flash('success', "Canal «{$nombre}» creado. Añade la regla que le manda ventas.");
                break;

            case 'canal_eliminar':
                $clave = (string) post('clave');
                if ($clave === (string) cockpit_param('canal_defecto', 'retail')) throw new RuntimeException('Es el canal por defecto: elige otro en Parámetros antes de borrarlo.');
                if (qVal("SELECT COUNT(*) FROM cockpit_canal_reglas WHERE canal = ?", [$clave])) throw new RuntimeException('Tiene reglas que le mandan ventas: bórralas o cámbialas de canal primero.');
                if (qVal("SELECT COUNT(*) FROM kpi_campana_metas WHERE canal = ? AND meta > 0", [$clave])) throw new RuntimeException('Hay campañas con meta en ese canal. Desactívalo en vez de borrarlo.');
                q("DELETE FROM kpi_campana_metas WHERE canal = ?", [$clave]);
                q("DELETE FROM cockpit_canales WHERE clave = ?", [$clave]);
                flash('success', 'Canal eliminado.');
                break;

            case 'regla_nueva':
                $tipo = post('tipo') === 'comprobante' ? 'comprobante' : 'canal_venta';
                $valor = cfg_txt(post('valor'), 40);
                $canal = (string) post('canal');
                if (!$valor) throw new RuntimeException('Indica el valor que activa la regla.');
                if (!qVal("SELECT 1 FROM cockpit_canales WHERE clave = ?", [$canal])) throw new RuntimeException('Elige el canal de destino.');
                if (qVal("SELECT 1 FROM cockpit_canal_reglas WHERE tipo = ? AND valor = ?", [$tipo, $valor])) throw new RuntimeException('Ya hay una regla para ese valor: cámbiale el canal en la tabla.');
                dbInsert('cockpit_canal_reglas', ['tipo' => $tipo, 'valor' => $valor, 'canal' => $canal,
                    'orden' => (int) qVal("SELECT COALESCE(MAX(orden),0) + 10 FROM cockpit_canal_reglas")]);
                flash('success', 'Regla añadida.');
                break;

            case 'regla_eliminar':
                q("DELETE FROM cockpit_canal_reglas WHERE id = ?", [postInt('id')]);
                flash('success', 'Regla eliminada: esas ventas caen ahora en el canal por defecto.');
                break;

            /* ---------- Rubros ---------- */
            case 'rubros_guardar':
                foreach ((array) ($_POST['r'] ?? []) as $clave => $f) {
                    $nombre = cfg_txt($f['nombre'] ?? '', 120);
                    if (!$nombre) throw new RuntimeException("El rubro «{$clave}» necesita un nombre.");
                    dbUpdate('kpi_rubros', ['nombre' => $nombre, 'nombre_en' => cfg_txt($f['nombre_en'] ?? '', 120),
                        'orden' => (int) ($f['orden'] ?? 0), 'activo' => !empty($f['activo']) ? 1 : 0], 'clave = ?', [$clave]);
                }
                flash('success', 'Rubros guardados.');
                break;

            case 'rubro_nuevo':
                $nombre = cfg_txt(post('nombre'), 120);
                $clave = cfg_clave((string) post('clave'), (string) $nombre);
                if (!$nombre || $clave === '') throw new RuntimeException('Escribe el nombre del rubro.');
                if (qVal("SELECT 1 FROM kpi_rubros WHERE clave = ?", [$clave])) throw new RuntimeException("Ya existe el rubro «{$clave}».");
                dbInsert('kpi_rubros', ['clave' => $clave, 'nombre' => $nombre, 'nombre_en' => cfg_txt(post('nombre_en'), 120),
                    'orden' => (int) qVal("SELECT COALESCE(MAX(orden),0) + 10 FROM kpi_rubros"), 'activo' => 1]);
                flash('success', "Rubro «{$nombre}» creado.");
                break;

            case 'rubro_eliminar':
                $clave = (string) post('clave');
                if (qVal("SELECT COUNT(*) FROM kpi_campana_inversiones WHERE rubro = ?", [$clave])) throw new RuntimeException('Ese rubro tiene inversión registrada en alguna campaña: desactívalo en vez de borrarlo.');
                q("DELETE FROM kpi_rubros WHERE clave = ?", [$clave]);
                flash('success', 'Rubro eliminado.');
                break;

            /* ---------- KPIs capturados ---------- */
            case 'metricas_guardar':
                $roles = [];
                foreach ((array) ($_POST['m'] ?? []) as $clave => $f) {
                    $nombre = cfg_txt($f['nombre'] ?? '', 120);
                    if (!$nombre) throw new RuntimeException("El KPI «{$clave}» necesita un nombre.");
                    $rol = in_array($f['rol'] ?? '', ['trafico', 'sesiones_web'], true) ? $f['rol'] : null;
                    if ($rol && isset($roles[$rol])) throw new RuntimeException('Solo un KPI puede hacer de «' . ($rol === 'trafico' ? 'tráfico en tienda' : 'sesiones web') . '».');
                    if ($rol) $roles[$rol] = 1;
                    dbUpdate('kpi_metricas_def', [
                        'grupo' => cfg_txt($f['grupo'] ?? '', 60) ?? 'Generales', 'nombre' => $nombre,
                        'nombre_en' => cfg_txt($f['nombre_en'] ?? '', 120),
                        'unidad' => in_array($f['unidad'] ?? '', ['num', 'pct', 'money'], true) ? $f['unidad'] : 'num',
                        'rol' => $rol, 'menor_es_mejor' => !empty($f['menor_es_mejor']) ? 1 : 0,
                        'orden' => (int) ($f['orden'] ?? 0), 'activo' => !empty($f['activo']) ? 1 : 0,
                    ], 'clave = ?', [$clave]);
                }
                flash('success', 'KPIs guardados.');
                break;

            case 'metrica_nueva':
                $nombre = cfg_txt(post('nombre'), 120);
                $clave = cfg_clave((string) post('clave'), (string) $nombre);
                if (!$nombre || $clave === '') throw new RuntimeException('Escribe el nombre del KPI.');
                if (qVal("SELECT 1 FROM kpi_metricas_def WHERE clave = ?", [$clave])) throw new RuntimeException("Ya existe el KPI «{$clave}».");
                dbInsert('kpi_metricas_def', ['clave' => $clave, 'grupo' => cfg_txt(post('grupo'), 60) ?? 'Generales', 'nombre' => $nombre,
                    'nombre_en' => cfg_txt(post('nombre_en'), 120),
                    'unidad' => in_array(post('unidad'), ['num', 'pct', 'money'], true) ? post('unidad') : 'num',
                    'orden' => (int) qVal("SELECT COALESCE(MAX(orden),0) + 10 FROM kpi_metricas_def"), 'activo' => 1]);
                flash('success', "KPI «{$nombre}» creado.");
                break;

            case 'metrica_eliminar':
                $clave = (string) post('clave');
                if (qVal("SELECT COUNT(*) FROM kpi_campana_metricas WHERE metrica = ?", [$clave])) throw new RuntimeException('Ese KPI ya tiene valores capturados en alguna campaña: desactívalo en vez de borrarlo.');
                q("DELETE FROM kpi_metricas_def WHERE clave = ?", [$clave]);
                flash('success', 'KPI eliminado.');
                break;

            /* ---------- Parámetros ---------- */
            case 'parametros':
                foreach (cockpit_parametros_def() as $k => [$lbl, $tipo]) {
                    $v = trim((string) ($_POST['p'][$k] ?? ''));
                    switch ($tipo) {
                        case 'int':    $v = (string) max(1, min(500, (int) $v)); break;
                        case 'mes':    $v = (string) max(1, min(12, (int) $v)); break;
                        case 'pct':    $v = ($pv = cfg_pct($v)) === null ? '' : (string) $pv; break;
                        case 'select': $v = array_key_exists($v, cockpit_presets()) ? $v : 'ytd'; break;
                        case 'canal':  if (!array_key_exists($v, cockpit_canales())) throw new RuntimeException('El canal por defecto tiene que ser un canal activo.'); break;
                        case 'lineas':
                            $lineas = array_values(array_unique(array_filter(array_map(fn($l) => mb_substr(trim($l), 0, 40), preg_split('/\R/', $v)), fn($l) => $l !== '')));
                            if ($k === 'tasas_reporte') {
                                // Solo quedan las líneas CÓDIGO=tasa válidas; lo demás se descarta con aviso.
                                $validas = array_values(array_filter($lineas, fn($l) => preg_match('/^[A-Za-z]{3}\s*[=:]\s*[0-9]+([.,][0-9]+)?$/', $l)));
                                if (count($validas) < count($lineas)) flash('warning', 'Algunas tasas no tenían la forma USD=59.50 y se descartaron.');
                                $v = implode("\n", array_map(fn($l) => strtoupper(substr($l, 0, 3)) . substr($l, 3), $validas));
                                break;
                            }
                            if (!$lineas) throw new RuntimeException('Deja al menos un canal de captación: el POS lo necesita para vender.');
                            $v = implode("\n", $lineas);
                            break;
                    }
                    q("INSERT INTO cockpit_parametros (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)", [$k, $v]);
                }
                flash('success', 'Parámetros guardados.');
                break;

            /* ---------- Productos ---------- */
            case 'productos_guardar':
                require_perm('productos.editar');
                $n = 0;
                foreach ((array) ($_POST['pr'] ?? []) as $pid => $f) {
                    $n += dbUpdate('productos', [
                        'segmento' => cfg_txt($f['segmento'] ?? '', 60),
                        'linea'    => cfg_txt($f['linea'] ?? '', 60),
                        'es_heroe' => !empty($f['es_heroe']) ? 1 : 0,
                    ], 'id = ?', [(int) $pid]);
                }
                flash('success', "Clasificación guardada ({$n} producto(s) cambiados).");
                $vuelta .= '&' . http_build_query(array_intersect_key($_GET, array_flip(['q', 'filtro', 'p'])));
                break;

            case 'renombrar':
                // Renombrar un segmento o una línea en todos los productos a la vez.
                require_perm('productos.editar');
                $campo = post('campo') === 'linea' ? 'linea' : 'segmento';
                $de = trim((string) post('de')); $a = cfg_txt(post('a'), 60);
                if ($de === '') throw new RuntimeException('Elige qué renombrar.');
                $n = q("UPDATE productos SET $campo = ? WHERE $campo = ?", [$a, $de])->rowCount();
                flash('success', "«{$de}» → «" . ($a ?? 'vacío') . "» en {$n} producto(s).");
                break;
        }
        audit('cockpit', 'configurar', 'Configuración del cockpit: ' . $accion);
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect($vuelta);
}

/* ============================================================
 *  Pantalla
 * ============================================================ */
layout_start('Configuración del cockpit', 'Todo lo que el Promotion Cockpit y los KPIs de campañas usan para clasificar y reportar',
    '<a href="' . e(url('modules/marketing/cockpit.php')) . '" class="btn btn-ghost">' . icon('pie', 'w-4 h-4') . ' Ir al cockpit</a>');
$chk = fn(string $name, $on, bool $dis = false) => '<input type="hidden" name="' . e($name) . '" value="0"><input type="checkbox" name="' . e($name) . '" value="1"'
    . ($on ? ' checked' : '') . ($dis ? ' disabled' : '') . ' class="w-5 h-5 rounded border-slate-300 text-blue-600">';
$borrar = fn(string $accion, string $campo, string $valor, string $conf) => '<form method="post" class="inline" onsubmit="return confirm(' . e(json_encode($conf)) . ')">'
    . csrf_field() . '<input type="hidden" name="accion" value="' . e($accion) . '"><input type="hidden" name="' . e($campo) . '" value="' . e($valor) . '">'
    . '<button class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50" title="Eliminar">' . icon('trash', 'w-4 h-4') . '</button></form>';
?>

<nav class="flex gap-1 p-1 bg-slate-100 rounded-xl mb-5 overflow-x-auto no-print" aria-label="Secciones">
  <?php foreach ($tabs as $k => [$lbl, $ico]): ?>
    <a href="?tab=<?= e($k) ?>" class="shrink-0 inline-flex items-center gap-2 px-3.5 py-2 rounded-lg text-sm font-semibold transition <?= $k === $tab ? 'bg-white text-blue-700 shadow-sm' : 'text-slate-500 hover:text-slate-800' ?>" <?= $k === $tab ? 'aria-current="page"' : '' ?>>
      <?= icon($ico, 'w-4 h-4') ?> <?= e($lbl) ?>
    </a>
  <?php endforeach; ?>
</nav>

<?php
// Faltan partes opcionales: se avisa en todas las pestañas, sin estorbar.
$faltan = array_filter(cockpit_instalacion(), fn($f) => !$f['ok']);
if ($faltan && $tab !== 'estado'): ?>
  <a href="?tab=estado" class="card p-3 mb-5 flex items-center gap-2 text-sm text-amber-800 bg-amber-50/70 border-amber-200 hover:bg-amber-50 no-print">
    <?= icon('alert', 'w-4 h-4 text-amber-500 shrink-0') ?>
    <span><?= count($faltan) === 1 ? 'Falta 1 parte' : 'Faltan ' . count($faltan) . ' partes' ?> de la instalación: <?= e(implode(', ', array_map(fn($f) => mb_strtolower($f['etiqueta']), array_slice($faltan, 0, 3)))) ?><?= count($faltan) > 3 ? '…' : '' ?>.</span>
    <span class="ml-auto font-semibold whitespace-nowrap">Ver qué hacer →</span>
  </a>
<?php endif; ?>

<?php if ($tab === 'estado'): ?>
  <?= cfg_panel_estado() ?>

<?php elseif ($tab === 'tipos'):
  $conTope = cfg_con_tope();
  $tipos = qAll("SELECT t.*,
                        (SELECT COUNT(*) FROM promociones p WHERE p.tipo_descuento = t.clave) promos,
                        (SELECT COUNT(*) FROM ventas v WHERE v.descuento_motivo = t.clave) ventas
                   FROM cockpit_tipos t ORDER BY t.orden, t.clave");
?>
  <section class="card overflow-hidden mb-5">
    <div class="p-4 border-b border-slate-100">
      <h3 class="font-bold text-slate-800">Tipos de descuento</h3>
      <p class="text-sm text-slate-400">Cada tipo es una fila del cockpit y un color en los gráficos. «Familia de promoción» lo ofrece al crear una promoción; si le pones <strong>etiqueta en caja</strong>, aparece como motivo en el POS.</p>
    </div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="accion" value="tipos_guardar">
      <div class="overflow-x-auto">
        <table class="data-table text-[13px]">
          <thead><tr><th class="w-20">Orden</th><th>Nombre en el cockpit</th><th class="w-16">Color</th><th class="text-center">Familia de promoción</th><th>Etiqueta en caja (POS)</th>
            <?php if ($conTope): ?><th class="w-28" title="Descuento máximo que la marca acepta para este tipo, en % de su venta bruta. Vacío = sin tope.">Tope desc. %</th><?php endif; ?>
            <th class="text-center">Activo</th><th class="text-right">Uso</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($tipos as $t): $k = $t['clave']; ?>
            <tr class="<?= $t['activo'] ? '' : 'opacity-60' ?>">
              <td><input type="number" name="t[<?= e($k) ?>][orden]" value="<?= (int) $t['orden'] ?>" class="input py-1.5 w-20" aria-label="Orden"></td>
              <td>
                <input name="t[<?= e($k) ?>][nombre]" value="<?= e($t['nombre']) ?>" maxlength="80" required class="input py-1.5 min-w-[240px]" aria-label="Nombre">
                <p class="text-[11px] text-slate-400 mt-0.5 font-mono"><?= e($k) ?><?= $t['sistema'] ? ' · lo usa el cálculo' : '' ?></p>
              </td>
              <td><input type="color" name="t[<?= e($k) ?>][color]" value="<?= e($t['color']) ?>" class="w-11 h-11 rounded-lg border border-slate-200 cursor-pointer p-1" aria-label="Color"></td>
              <td class="text-center"><?= in_array($k, ['sin', 'muestra', 'negociado', 'promocion', 'manual'], true) ? '<span class="text-slate-300">—</span>' : $chk("t[$k][es_promocion]", $t['es_promocion']) ?></td>
              <td><?= in_array($k, ['sin', 'muestra', 'negociado', 'promocion'], true) ? '<span class="text-slate-300 text-xs">No aplica</span>'
                    : '<input name="t[' . e($k) . '][etiqueta_caja]" value="' . e((string) $t['etiqueta_caja']) . '" maxlength="80" class="input py-1.5 min-w-[200px]" placeholder="Vacío = no es motivo del POS" aria-label="Etiqueta en caja">' ?></td>
              <?php if ($conTope): ?>
              <td><?= $k === 'sin' ? '<span class="text-slate-300">—</span>'
                    : '<input type="number" name="t[' . e($k) . '][tope]" value="' . e($t['tope_desc_pct'] === null ? '' : rtrim(rtrim((string) $t['tope_desc_pct'], '0'), '.')) . '" min="0" max="100" step="0.1" class="input py-1.5 w-24" placeholder="Sin tope" aria-label="Tope de descuento">' ?></td>
              <?php endif; ?>
              <td class="text-center"><?= $chk("t[$k][activo]", $t['activo'], (bool) $t['sistema']) ?></td>
              <td class="text-right text-xs text-slate-500 whitespace-nowrap"><?= (int) $t['promos'] ?> promo · <?= number_format((int) $t['ventas']) ?> ventas</td>
              <td class="text-right"><?= !$t['sistema'] && !$t['promos'] && !$t['ventas'] ? $borrar('tipo_eliminar', 'clave', $k, '¿Eliminar el tipo «' . $t['nombre'] . '»?') : '' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="flex justify-end p-4 border-t border-slate-100"><button class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar tipos</button></div>
    </form>
  </section>
  <section class="card p-4">
    <h3 class="font-bold text-slate-800 mb-3">Nuevo tipo de descuento</h3>
    <form method="post" class="grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
      <?= csrf_field() ?><input type="hidden" name="accion" value="tipo_nuevo">
      <div class="md:col-span-2"><label class="label" for="tn_nombre">Nombre</label><input id="tn_nombre" name="nombre" required maxlength="80" class="input" placeholder="Ej. Día de las madres"></div>
      <div><label class="label" for="tn_color">Color</label><input id="tn_color" type="color" name="color" value="#2a78d6" class="w-full h-11 rounded-xl border border-slate-200 p-1"></div>
      <div class="md:col-span-2"><label class="label" for="tn_caja">Etiqueta en caja (opcional)</label><input id="tn_caja" name="etiqueta_caja" maxlength="80" class="input" placeholder="Si el cajero lo puede elegir"></div>
      <label class="flex items-center gap-2 text-sm text-slate-600 min-h-[44px]"><input type="hidden" name="es_promocion" value="0"><input type="checkbox" name="es_promocion" value="1" checked class="w-5 h-5 rounded border-slate-300 text-blue-600"> Familia de promoción</label>
      <div class="md:col-span-6 flex justify-end"><button class="btn btn-primary"><?= icon('plus', 'w-4 h-4') ?> Crear tipo</button></div>
    </form>
  </section>

<?php elseif ($tab === 'canales'):
  $canalesT = qAll("SELECT c.*, (SELECT COUNT(*) FROM cockpit_canal_reglas r WHERE r.canal = c.clave) reglas FROM cockpit_canales c ORDER BY c.orden, c.clave");
  $reglas = qAll("SELECT * FROM cockpit_canal_reglas ORDER BY orden, id");
  $canalOpc = array_column($canalesT, 'nombre', 'clave');
  // Vista previa: cómo se clasifica HOY lo que ya se vendió (últimos 24 meses).
  $vista = qAll("SELECT COALESCE(NULLIF(v.canal_venta,''),'(sin canal)') captacion, v.tipo_comprobante comp, " . cockpit_canal_sql('v') . " canal, COUNT(*) n
                   FROM ventas v WHERE " . rep_estados_venta('v') . " AND v.fecha >= ?
                  GROUP BY captacion, comp, canal ORDER BY n DESC LIMIT 40", [date('Y-m-d', strtotime('-24 months'))]);
  $comprobantes = ['no_consumidor' => 'Cualquiera que no sea consumidor final', 'credito_fiscal' => 'Crédito fiscal (B01 / E31)',
                   'gubernamental' => 'Gubernamental', 'regimen_especial' => 'Régimen especial', 'consumidor' => 'Consumidor final'];
?>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="accion" value="canales_guardar">
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-5 mb-5">
      <section class="card overflow-hidden">
        <div class="p-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800">Canales de la marca</h3>
          <p class="text-sm text-slate-400">Las columnas de «KPIs globales por canal». El nombre en inglés va en el Excel de la marca.</p>
        </div>
        <div class="overflow-x-auto">
          <table class="data-table text-[13px]">
            <thead><tr><th class="w-20">Orden</th><th>Nombre</th><th>En inglés</th><th class="text-center">Activo</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($canalesT as $c): $k = $c['clave']; ?>
              <tr>
                <td><input type="number" name="c[<?= e($k) ?>][orden]" value="<?= (int) $c['orden'] ?>" class="input py-1.5 w-20" aria-label="Orden"></td>
                <td><input name="c[<?= e($k) ?>][nombre]" value="<?= e($c['nombre']) ?>" required maxlength="60" class="input py-1.5 min-w-[180px]" aria-label="Nombre"><p class="text-[11px] text-slate-400 font-mono mt-0.5"><?= e($k) ?></p></td>
                <td><input name="c[<?= e($k) ?>][nombre_en]" value="<?= e((string) $c['nombre_en']) ?>" maxlength="60" class="input py-1.5 min-w-[140px]" aria-label="Nombre en inglés"></td>
                <td class="text-center"><?= $chk("c[$k][activo]", $c['activo']) ?></td>
                <td class="text-right"><?= !$c['reglas'] ? $borrar('canal_eliminar', 'clave', $k, '¿Eliminar el canal «' . $c['nombre'] . '»?') : '' ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="card overflow-hidden">
        <div class="p-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800">Reglas: a qué canal va cada venta</h3>
          <p class="text-sm text-slate-400">Se revisan en orden y gana la primera que se cumple. Lo que no cumple ninguna va al canal por defecto (<strong><?= e($canalOpc[cockpit_param('canal_defecto', 'retail')] ?? '—') ?></strong>, se cambia en Parámetros).</p>
        </div>
        <div class="overflow-x-auto">
          <table class="data-table text-[13px]">
            <thead><tr><th class="w-20">Orden</th><th>Si…</th><th>Va al canal</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($reglas as $r): ?>
              <tr>
                <td><input type="number" name="r[<?= (int) $r['id'] ?>][orden]" value="<?= (int) $r['orden'] ?>" class="input py-1.5 w-20" aria-label="Orden"></td>
                <td class="text-slate-600"><?= $r['tipo'] === 'comprobante'
                    ? 'el comprobante es <strong>' . e($comprobantes[$r['valor']] ?? $r['valor']) . '</strong>'
                    : 'el canal de captación es <strong>' . e($r['valor']) . '</strong>' ?></td>
                <td><select name="r[<?= (int) $r['id'] ?>][canal]" class="select py-1.5 min-w-[190px]" aria-label="Canal"><?php foreach ($canalOpc as $ck => $cn): ?><option value="<?= e($ck) ?>" <?= $ck === $r['canal'] ? 'selected' : '' ?>><?= e($cn) ?></option><?php endforeach; ?></select></td>
                <td class="text-right"><?= $borrar('regla_eliminar', 'id', (string) $r['id'], '¿Eliminar esta regla?') ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    </div>
    <div class="flex justify-end mb-5"><button class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar canales y reglas</button></div>
  </form>

  <div class="grid grid-cols-1 xl:grid-cols-2 gap-5">
    <section class="card p-4 space-y-5">
      <form method="post" class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-end">
        <?= csrf_field() ?><input type="hidden" name="accion" value="canal_nuevo">
        <p class="sm:col-span-3 font-bold text-slate-800">Nuevo canal</p>
        <div><label class="label" for="cn_n">Nombre</label><input id="cn_n" name="nombre" required maxlength="60" class="input" placeholder="Ej. Marketplace"></div>
        <div><label class="label" for="cn_en">En inglés</label><input id="cn_en" name="nombre_en" maxlength="60" class="input" placeholder="Ej. MKP"></div>
        <button class="btn btn-primary"><?= icon('plus', 'w-4 h-4') ?> Crear canal</button>
      </form>
      <form method="post" class="grid grid-cols-1 sm:grid-cols-2 gap-3 items-end border-t border-slate-100 pt-4" x-data="{tipo:'canal_venta'}">
        <?= csrf_field() ?><input type="hidden" name="accion" value="regla_nueva">
        <p class="sm:col-span-2 font-bold text-slate-800">Nueva regla</p>
        <div><label class="label" for="rn_t">Condición</label>
          <select id="rn_t" name="tipo" x-model="tipo" class="select"><option value="canal_venta">Canal de captación es…</option><option value="comprobante">Tipo de comprobante es…</option></select></div>
        <div>
          <label class="label" for="rn_v">Valor</label>
          <input id="rn_v" name="valor" x-show="tipo==='canal_venta'" :disabled="tipo!=='canal_venta'" list="rn_capt" maxlength="40" class="input" placeholder="Ej. TikTok">
          <datalist id="rn_capt"><?php foreach (array_unique(array_merge(canalesVenta(), ['Tienda online'], array_column($vista, 'captacion'))) as $cv): ?><option value="<?= e($cv) ?>"><?php endforeach; ?></datalist>
          <select name="valor" x-show="tipo==='comprobante'" x-cloak :disabled="tipo!=='comprobante'" class="select"><?php foreach ($comprobantes as $ck => $cn): ?><option value="<?= e($ck) ?>"><?= e($cn) ?></option><?php endforeach; ?></select>
        </div>
        <div><label class="label" for="rn_c">Va al canal</label><select id="rn_c" name="canal" class="select"><?php foreach ($canalOpc as $ck => $cn): ?><option value="<?= e($ck) ?>"><?= e($cn) ?></option><?php endforeach; ?></select></div>
        <button class="btn btn-primary"><?= icon('plus', 'w-4 h-4') ?> Añadir regla</button>
      </form>
    </section>
    <section class="card overflow-hidden">
      <div class="p-4 border-b border-slate-100">
        <h3 class="font-bold text-slate-800">Vista previa con las ventas reales</h3>
        <p class="text-sm text-slate-400">Cómo quedan clasificadas hoy las ventas de los últimos 24 meses con las reglas guardadas.</p>
      </div>
      <div class="overflow-x-auto max-h-[420px]">
        <table class="data-table text-[13px]">
          <thead><tr><th>Captación</th><th>Comprobante</th><th>Canal de la marca</th><th class="text-right">Ventas</th></tr></thead>
          <tbody>
          <?php foreach ($vista as $v): ?>
            <tr><td><?= e($v['captacion']) ?></td><td class="text-slate-500"><?= e($v['comp']) ?></td>
              <td><span class="badge badge-slate"><?= e($canalOpc[$v['canal']] ?? $v['canal']) ?></span></td>
              <td class="text-right tabular-nums"><?= number_format((int) $v['n']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>

<?php elseif ($tab === 'inversion'):
  $rubrosT = qAll("SELECT r.*, (SELECT COUNT(*) FROM kpi_campana_inversiones i WHERE i.rubro = r.clave) usos FROM kpi_rubros r ORDER BY r.orden, r.clave");
?>
  <section class="card overflow-hidden mb-5">
    <div class="p-4 border-b border-slate-100">
      <h3 class="font-bold text-slate-800">Rubros de inversión</h3>
      <p class="text-sm text-slate-400">Las filas de la hoja INVESTMENT. El nombre en inglés es el que sale en el Excel de la marca.</p>
    </div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="accion" value="rubros_guardar">
      <div class="overflow-x-auto">
        <table class="data-table text-[13px]">
          <thead><tr><th class="w-20">Orden</th><th>Nombre</th><th>En inglés (Excel)</th><th class="text-center">Activo</th><th class="text-right">Uso</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($rubrosT as $r): $k = $r['clave']; ?>
            <tr class="<?= $r['activo'] ? '' : 'opacity-60' ?>">
              <td><input type="number" name="r[<?= e($k) ?>][orden]" value="<?= (int) $r['orden'] ?>" class="input py-1.5 w-20" aria-label="Orden"></td>
              <td><input name="r[<?= e($k) ?>][nombre]" value="<?= e($r['nombre']) ?>" required maxlength="120" class="input py-1.5 min-w-[260px]" aria-label="Nombre"></td>
              <td><input name="r[<?= e($k) ?>][nombre_en]" value="<?= e((string) $r['nombre_en']) ?>" maxlength="120" class="input py-1.5 min-w-[260px]" aria-label="Nombre en inglés"></td>
              <td class="text-center"><?= $chk("r[$k][activo]", $r['activo']) ?></td>
              <td class="text-right text-xs text-slate-500"><?= (int) $r['usos'] ?> registro(s)</td>
              <td class="text-right"><?= !$r['usos'] ? $borrar('rubro_eliminar', 'clave', $k, '¿Eliminar el rubro «' . $r['nombre'] . '»?') : '' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="flex justify-end p-4 border-t border-slate-100"><button class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar rubros</button></div>
    </form>
  </section>
  <section class="card p-4">
    <form method="post" class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
      <?= csrf_field() ?><input type="hidden" name="accion" value="rubro_nuevo">
      <p class="md:col-span-3 font-bold text-slate-800">Nuevo rubro</p>
      <div><label class="label" for="rb_n">Nombre</label><input id="rb_n" name="nombre" required maxlength="120" class="input" placeholder="Ej. Sampling en consultorios"></div>
      <div><label class="label" for="rb_en">En inglés</label><input id="rb_en" name="nombre_en" maxlength="120" class="input" placeholder="Ej. SAMPLING"></div>
      <button class="btn btn-primary"><?= icon('plus', 'w-4 h-4') ?> Crear rubro</button>
    </form>
  </section>

<?php elseif ($tab === 'kpis'):
  $mets = qAll("SELECT m.*, (SELECT COUNT(*) FROM kpi_campana_metricas x WHERE x.metrica = m.clave) usos FROM kpi_metricas_def m ORDER BY m.orden, m.clave");
  $grupos = array_values(array_unique(array_column($mets, 'grupo')));
?>
  <section class="card overflow-hidden mb-5">
    <div class="p-4 border-b border-slate-100">
      <h3 class="font-bold text-slate-800">KPIs que se capturan a mano en cada campaña</h3>
      <p class="text-sm text-slate-400">El <strong>rol</strong> hace que el sistema calcule con ese dato: «Tráfico» da la conversión en tienda (facturas ÷ visitantes) y «Sesiones web» la conversión online.</p>
    </div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="accion" value="metricas_guardar">
      <datalist id="grupos_kpi"><?php foreach ($grupos as $g): ?><option value="<?= e($g) ?>"><?php endforeach; ?></datalist>
      <div class="overflow-x-auto">
        <table class="data-table text-[13px]">
          <thead><tr><th class="w-20">Orden</th><th>Grupo</th><th>Nombre</th><th>En inglés (Excel)</th><th>Unidad</th><th>Rol</th><th class="text-center" title="Bajar es bueno (ej. rotación)">Menor es mejor</th><th class="text-center">Activo</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($mets as $m): $k = $m['clave']; ?>
            <tr class="<?= $m['activo'] ? '' : 'opacity-60' ?>">
              <td><input type="number" name="m[<?= e($k) ?>][orden]" value="<?= (int) $m['orden'] ?>" class="input py-1.5 w-20" aria-label="Orden"></td>
              <td><input name="m[<?= e($k) ?>][grupo]" value="<?= e($m['grupo']) ?>" list="grupos_kpi" maxlength="60" class="input py-1.5 min-w-[150px]" aria-label="Grupo"></td>
              <td><input name="m[<?= e($k) ?>][nombre]" value="<?= e($m['nombre']) ?>" required maxlength="120" class="input py-1.5 min-w-[220px]" aria-label="Nombre"></td>
              <td><input name="m[<?= e($k) ?>][nombre_en]" value="<?= e((string) $m['nombre_en']) ?>" maxlength="120" class="input py-1.5 min-w-[180px]" aria-label="Nombre en inglés"></td>
              <td><select name="m[<?= e($k) ?>][unidad]" class="select py-1.5" aria-label="Unidad"><?php foreach (['num' => 'Número', 'pct' => 'Porcentaje', 'money' => 'Dinero'] as $u => $ul): ?><option value="<?= $u ?>" <?= $m['unidad'] === $u ? 'selected' : '' ?>><?= $ul ?></option><?php endforeach; ?></select></td>
              <td><select name="m[<?= e($k) ?>][rol]" class="select py-1.5" aria-label="Rol"><?php foreach (['' => '—', 'trafico' => 'Tráfico en tienda', 'sesiones_web' => 'Sesiones web'] as $rk => $rl): ?><option value="<?= $rk ?>" <?= (string) $m['rol'] === $rk ? 'selected' : '' ?>><?= $rl ?></option><?php endforeach; ?></select></td>
              <td class="text-center"><?= $chk("m[$k][menor_es_mejor]", $m['menor_es_mejor']) ?></td>
              <td class="text-center"><?= $chk("m[$k][activo]", $m['activo']) ?></td>
              <td class="text-right"><?= !$m['usos'] ? $borrar('metrica_eliminar', 'clave', $k, '¿Eliminar el KPI «' . $m['nombre'] . '»?') : '' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="flex justify-end p-4 border-t border-slate-100"><button class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar KPIs</button></div>
    </form>
  </section>
  <section class="card p-4">
    <form method="post" class="grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
      <?= csrf_field() ?><input type="hidden" name="accion" value="metrica_nueva">
      <p class="md:col-span-5 font-bold text-slate-800">Nuevo KPI</p>
      <div><label class="label" for="mn_g">Grupo</label><input id="mn_g" name="grupo" list="grupos_kpi" maxlength="60" class="input" placeholder="Ej. Influencer / PR"></div>
      <div><label class="label" for="mn_n">Nombre</label><input id="mn_n" name="nombre" required maxlength="120" class="input" placeholder="Ej. Menciones orgánicas"></div>
      <div><label class="label" for="mn_en">En inglés</label><input id="mn_en" name="nombre_en" maxlength="120" class="input" placeholder="Ej. Organic mentions"></div>
      <div><label class="label" for="mn_u">Unidad</label><select id="mn_u" name="unidad" class="select"><option value="num">Número</option><option value="pct">Porcentaje</option><option value="money">Dinero</option></select></div>
      <button class="btn btn-primary"><?= icon('plus', 'w-4 h-4') ?> Crear KPI</button>
    </form>
  </section>

<?php elseif ($tab === 'parametros'): ?>
  <section class="card overflow-hidden">
    <div class="p-4 border-b border-slate-100">
      <h3 class="font-bold text-slate-800">Parámetros generales</h3>
      <p class="text-sm text-slate-400">Afectan al cockpit, a las campañas y al POS.</p>
    </div>
    <form method="post" class="p-4 grid grid-cols-1 md:grid-cols-2 gap-5">
      <?= csrf_field() ?><input type="hidden" name="accion" value="parametros">
      <?php foreach (cockpit_parametros_def() as $k => [$lbl, $tipo, $ayuda, $def]): $val = (string) cockpit_param($k, $def); ?>
        <div class="<?= $tipo === 'lineas' ? 'md:row-span-3' : '' ?>">
          <label class="label" for="p_<?= e($k) ?>"><?= e($lbl) ?></label>
          <?php if ($tipo === 'int'): ?>
            <input id="p_<?= e($k) ?>" type="number" min="1" max="500" name="p[<?= e($k) ?>]" value="<?= e($val) ?>" class="input">
          <?php elseif ($tipo === 'mes'): ?>
            <select id="p_<?= e($k) ?>" name="p[<?= e($k) ?>]" class="select"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= (int) $val === $m ? 'selected' : '' ?>><?= e(mesNombre($m)) ?></option><?php endfor; ?></select>
          <?php elseif ($tipo === 'select'): ?>
            <select id="p_<?= e($k) ?>" name="p[<?= e($k) ?>]" class="select"><?php foreach (cockpit_presets() as $pk => [$pl]): ?><option value="<?= e($pk) ?>" <?= $val === $pk ? 'selected' : '' ?>><?= e($pl) ?></option><?php endforeach; ?></select>
          <?php elseif ($tipo === 'pct'): ?>
            <div class="relative"><input id="p_<?= e($k) ?>" type="number" min="0" max="100" step="0.1" name="p[<?= e($k) ?>]" value="<?= e($val) ?>" class="input pr-8" placeholder="Sin objetivo"><span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">%</span></div>
          <?php elseif ($tipo === 'canal'): ?>
            <select id="p_<?= e($k) ?>" name="p[<?= e($k) ?>]" class="select"><?php foreach (cockpit_canales() as $ck => $cn): ?><option value="<?= e($ck) ?>" <?= $val === $ck ? 'selected' : '' ?>><?= e($cn) ?></option><?php endforeach; ?></select>
          <?php else: ?>
            <textarea id="p_<?= e($k) ?>" name="p[<?= e($k) ?>]" rows="8" class="input font-mono text-sm"><?= e($val) ?></textarea>
          <?php endif; ?>
          <?php if ($ayuda): ?><p class="text-xs text-slate-400 mt-1"><?= e($ayuda) ?></p><?php endif; ?>
        </div>
      <?php endforeach; ?>
      <div class="md:col-span-2 flex justify-end"><button class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar parámetros</button></div>
    </form>
  </section>

<?php else: /* productos */
  $puedeProd = can('productos.editar');
  $q = trim((string) get('q'));
  $filtro = (string) get('filtro');
  $w = ['p.activo = 1']; $pp = [];
  if ($q !== '') { $w[] = '(p.codigo LIKE ? OR p.nombre LIKE ? OR p.codigo_barras LIKE ?)'; array_push($pp, "%$q%", "%$q%", "%$q%"); }
  if ($filtro === 'sin') $w[] = "(p.segmento IS NULL OR p.segmento = '' OR p.linea IS NULL OR p.linea = '')";
  if ($filtro === 'heroes') $w[] = 'p.es_heroe = 1';
  $where = implode(' AND ', $w);
  $pg = paginar((int) qVal("SELECT COUNT(*) FROM productos p WHERE $where", $pp), 50);
  $prods = qAll("SELECT p.id, p.codigo, p.nombre, p.segmento, p.linea, p.es_heroe, c.nombre categoria FROM productos p
                  LEFT JOIN categorias c ON c.id = p.categoria_id WHERE $where ORDER BY p.nombre LIMIT {$pg['porPagina']} OFFSET {$pg['offset']}", $pp);
  $segs = qCol("SELECT DISTINCT segmento FROM productos WHERE segmento IS NOT NULL AND segmento <> '' ORDER BY segmento");
  $lins = qCol("SELECT DISTINCT linea FROM productos WHERE linea IS NOT NULL AND linea <> '' ORDER BY linea");
  $resumen = qOne("SELECT COUNT(*) total, SUM(segmento IS NOT NULL AND segmento <> '') con_seg, SUM(linea IS NOT NULL AND linea <> '') con_lin, SUM(es_heroe) heroes FROM productos WHERE activo = 1");
?>
  <?= kpis([
      ['label' => 'Productos activos', 'valor' => number_format((int) $resumen['total']), 'icono' => 'package', 'color' => 'blue'],
      ['label' => 'Con segmento', 'valor' => number_format((int) $resumen['con_seg']), 'icono' => 'layers', 'color' => 'emerald', 'nota' => count($segs) . ' segmento(s)'],
      ['label' => 'Con línea', 'valor' => number_format((int) $resumen['con_lin']), 'icono' => 'tag', 'color' => 'violet', 'nota' => count($lins) . ' línea(s)'],
      ['label' => 'Productos héroe', 'valor' => number_format((int) $resumen['heroes']), 'icono' => 'target', 'color' => 'amber'],
  ]) ?>
  <section class="card overflow-hidden mb-5">
    <div class="p-4 border-b border-slate-100 flex flex-wrap items-center gap-3 justify-between">
      <form method="get" class="flex flex-wrap gap-2 items-center">
        <input type="hidden" name="tab" value="productos">
        <input name="q" value="<?= e($q) ?>" class="input w-64" placeholder="Buscar SKU o nombre…" aria-label="Buscar">
        <select name="filtro" class="select" aria-label="Filtro">
          <option value="">Todos</option><option value="sin" <?= $filtro === 'sin' ? 'selected' : '' ?>>Sin clasificar</option><option value="heroes" <?= $filtro === 'heroes' ? 'selected' : '' ?>>Solo héroes</option>
        </select>
        <button class="btn btn-ghost"><?= icon('filter', 'w-4 h-4') ?> Filtrar</button>
      </form>
      <?php if ($puedeProd): ?><a href="<?= e(url('modules/marketing/cockpit.php?tab=producto')) ?>" class="text-sm text-blue-600 hover:underline">Carga masiva pegando desde Excel →</a><?php endif; ?>
    </div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="accion" value="productos_guardar">
      <datalist id="segs"><?php foreach ($segs as $v): ?><option value="<?= e($v) ?>"><?php endforeach; ?></datalist>
      <datalist id="lins"><?php foreach ($lins as $v): ?><option value="<?= e($v) ?>"><?php endforeach; ?></datalist>
      <div class="overflow-x-auto">
        <table class="data-table text-[13px]">
          <thead><tr><th>SKU</th><th>Producto</th><th>Categoría</th><th>Segmento</th><th>Línea</th><th class="text-center">Héroe</th></tr></thead>
          <tbody>
          <?php foreach ($prods as $p): $id = (int) $p['id']; ?>
            <tr>
              <td class="font-mono text-xs text-slate-500"><?= e($p['codigo']) ?></td>
              <td class="text-slate-700 max-w-[280px] truncate"><?= e($p['nombre']) ?></td>
              <td class="text-slate-400"><?= e((string) $p['categoria']) ?></td>
              <td><input name="pr[<?= $id ?>][segmento]" value="<?= e((string) $p['segmento']) ?>" list="segs" maxlength="60" class="input py-1.5 min-w-[140px]" <?= $puedeProd ? '' : 'disabled' ?> aria-label="Segmento"></td>
              <td><input name="pr[<?= $id ?>][linea]" value="<?= e((string) $p['linea']) ?>" list="lins" maxlength="60" class="input py-1.5 min-w-[140px]" <?= $puedeProd ? '' : 'disabled' ?> aria-label="Línea"></td>
              <td class="text-center"><?= $chk("pr[$id][es_heroe]", $p['es_heroe'], !$puedeProd) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if (!$prods): ?><div class="p-6"><?= empty_state('Sin productos', 'Nada coincide con el filtro.', 'package') ?></div><?php endif; ?>
      <div class="flex flex-wrap justify-between items-center gap-3 p-4 border-t border-slate-100">
        <?= paginacion($pg) ?>
        <?php if ($puedeProd && $prods): ?><button class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar esta página</button><?php endif; ?>
      </div>
    </form>
  </section>
  <?php if ($puedeProd && ($segs || $lins)): ?>
  <section class="card p-4">
    <form method="post" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end" x-data="{campo:'segmento'}">
      <?= csrf_field() ?><input type="hidden" name="accion" value="renombrar">
      <p class="md:col-span-4 font-bold text-slate-800">Renombrar o unir un segmento / línea en todos los productos</p>
      <div><label class="label" for="rn_campo">Qué</label><select id="rn_campo" name="campo" x-model="campo" class="select"><option value="segmento">Segmento</option><option value="linea">Línea</option></select></div>
      <div><label class="label" for="rn_de">De</label>
        <select id="rn_de" name="de" class="select" x-show="campo==='segmento'" :disabled="campo!=='segmento'"><?php foreach ($segs as $v): ?><option><?= e($v) ?></option><?php endforeach; ?></select>
        <select name="de" class="select" x-show="campo==='linea'" x-cloak :disabled="campo!=='linea'"><?php foreach ($lins as $v): ?><option><?= e($v) ?></option><?php endforeach; ?></select></div>
      <div><label class="label" for="rn_a">A</label><input id="rn_a" name="a" maxlength="60" class="input" placeholder="Nuevo nombre (vacío = quitar)"></div>
      <button class="btn btn-primary" onclick="return confirm('¿Aplicar el cambio a todos los productos con ese valor?')"><?= icon('edit', 'w-4 h-4') ?> Renombrar</button>
    </form>
  </section>
  <?php endif; ?>
<?php endif; ?>

<?php layout_end(); ?>
