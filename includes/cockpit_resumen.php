<?php
/**
 * Resumen del Promotion Cockpit por correo.
 *
 * Cada vista guardada puede pedir un resumen semanal (la semana cerrada, lunes a
 * domingo, cada lunes) o mensual (el mes cerrado, cada día 1): venta bruta,
 * descuento, venta neta y margen contra el año anterior, y lo más importante
 * que encontró el cockpit. Con un botón que abre la vista en ese periodo.
 *
 * Dos reglas que no se negocian:
 *   · Se calcula COMO EL DUEÑO de la vista (su sucursal, sus permisos), no como
 *     quien tenga la sesión abierta cuando se despierta el motor. Si no, una
 *     encargada de sucursal recibiría las cifras de toda la empresa.
 *   · Un periodo se envía una sola vez: `ultimo_periodo` se reclama con un
 *     UPDATE condicional antes de enviar (dos pestañas abiertas no duplican).
 *
 * El motor corre con el mismo enganche que las notificaciones (sin cron), como
 * máximo una vez por hora, y también desde modules/marketing/cron.php.
 */

const COCKPIT_RESUMEN_LOTE = 3;          // vistas por pasada: cada una cuesta ~0,5 s

function cockpit_resumen_disponible(): bool
{
    static $ok = null;
    return $ok ??= cockpit_vistas_disponible() && (bool) qVal(
        "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cockpit_vistas' AND COLUMN_NAME = 'frecuencia'");
}

/** ultimo_intento llegó después que la frecuencia: una P40 a medias sigue funcionando sin él. */
function cockpit_resumen_con_intento(): bool
{
    static $ok = null;
    return $ok ??= (bool) qVal(
        "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cockpit_vistas' AND COLUMN_NAME = 'ultimo_intento'");
}

function cockpit_resumen_frecuencias(): array
{
    return ['' => 'No enviar', 'semanal' => 'Cada lunes (semana cerrada)', 'mensual' => 'Cada día 1 (mes cerrado)'];
}

/** El último periodo CERRADO a la fecha: [desde, hasta], o null si la frecuencia no existe. */
function cockpit_resumen_periodo(string $frecuencia, ?string $hoy = null): ?array
{
    $hoy = $hoy ?? date('Y-m-d');
    if ($frecuencia === 'semanal') {
        // El domingo anterior a hoy (si hoy es domingo, la semana aún no cierra).
        $hasta = date('Y-m-d', strtotime('last sunday', strtotime($hoy)));
        return [date('Y-m-d', strtotime($hasta . ' -6 days')), $hasta];
    }
    if ($frecuencia === 'mensual') {
        $ini = date('Y-m-01', strtotime(date('Y-m-01', strtotime($hoy)) . ' -1 month'));
        return [$ini, date('Y-m-t', strtotime($ini))];
    }
    return null;
}

/**
 * Corre $fn con la sesión y la URL de otro usuario, y lo deja todo como estaba.
 * Solo para leer: nada de lo que se haga aquí dentro debe escribir en nombre de él.
 */
function cockpit_como_usuario(array $u, array $get, callable $fn)
{
    $claves = ['user', 'permisos', 'sucursal_activa'];
    $antes = [];
    foreach ($claves as $k) $antes[$k] = array_key_exists($k, $_SESSION ?? []) ? [$_SESSION[$k]] : null;
    $getAntes = $_GET;
    $restaurar = function () use ($antes) {
        foreach ($antes as $k => $v) {
            if ($v === null) unset($_SESSION[$k]); else $_SESSION[$k] = $v[0];
        }
    };
    // Un error fatal (tiempo o memoria agotados) se salta el finally, y PHP
    // guardaría la sesión con el usuario prestado: quien navegaba quedaría
    // dentro como el dueño de la vista. Las funciones de cierre corren ANTES
    // de que se escriba la sesión, así que ésta la deja siempre como estaba.
    $activo = true;
    register_shutdown_function(function () use (&$activo, $restaurar) { if ($activo) $restaurar(); });
    try {
        $_SESSION['user'] = ['id' => (int) $u['id'], 'nombre' => $u['nombre'], 'apellido' => $u['apellido'] ?? '', 'usuario' => $u['usuario'] ?? '',
                             'email' => $u['email'] ?? '', 'rol_id' => (int) $u['rol_id'], 'es_super' => (int) ($u['es_super'] ?? 0),
                             'sucursal_id' => $u['sucursal_id'] !== null ? (int) $u['sucursal_id'] : null];
        $_SESSION['permisos'] = load_permisos((int) $u['rol_id']);
        $_SESSION['sucursal_activa'] = $u['sucursal_id'] !== null ? (int) $u['sucursal_id'] : '';
        $_GET = $get;
        return $fn();
    } finally {
        $restaurar();
        $_GET = $getAntes;
        $activo = false;
    }
}

/** El dueño de una vista, con lo necesario para calcular como él. null si ya no puede verla. */
function cockpit_resumen_dueno(int $usuarioId): ?array
{
    $u = qOne("SELECT u.id, u.nombre, u.apellido, u.usuario, u.email, u.rol_id, u.sucursal_id, u.activo, r.es_super
                 FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = ?", [$usuarioId]);
    if (!$u || (int) $u['activo'] !== 1) return null;
    if (!(int) $u['es_super'] && !in_array('cockpit.ver', load_permisos((int) $u['rol_id']), true)) return null;
    return $u;
}

/**
 * Arma el correo de una vista para un periodo. [asunto, html]
 * Los filtros de la vista (canal, marca, sucursal, moneda…) se respetan; las
 * fechas no: el periodo es el cerrado que toca.
 */
function cockpit_resumen_correo(array $vista, array $u, array $periodo): array
{
    parse_str((string) $vista['query'], $q);
    unset($q['tab'], $q['periodo'], $q['ly_desde'], $q['ly_hasta'], $q['ly_manual'], $q['vista'], $q['tipo']);
    foreach (array_keys($q) as $k) if (str_starts_with((string) $k, 's_')) unset($q[$k]);
    $q['ty_desde'] = $periodo[0];
    $q['ty_hasta'] = $periodo[1];

    return cockpit_como_usuario($u, $q, function () use ($vista, $u, $periodo, $q) {
        $f = cockpit_filtros();
        $r = cockpit_resumen_datos($f);
        $hz = cockpit_hallazgos($r['total'], $r['filas'], $r['pct_promo_ty'], $r['pct_promo_ly'], 5);
        [$monCod, $sim] = cockpit_moneda();
        $t = $r['total']['ty']; $l = $r['total']['ly'];

        $fuente = 'font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;';
        $var = function (float $ty, float $ly, bool $tasa, bool $invertir = false) use ($fuente) {
            if ($tasa) { $d = $ty - $ly; $txt = ($d >= 0 ? '+' : '−') . number_format(abs($d), 1) . ' pts'; }
            else { if ($ly == 0.0) return '<span style="color:#94A3B8">—</span>'; $d = ($ty - $ly) / abs($ly) * 100; $txt = ($d >= 0 ? '+' : '−') . number_format(abs($d), 1) . '%'; }
            $bueno = $invertir ? $d < 0 : $d > 0;
            $color = abs($d) < 0.05 ? '#94A3B8' : ($bueno ? '#059669' : '#E11D48');
            return '<span style="color:' . $color . ';font-weight:600">' . $txt . '</span>';
        };
        $dinero = fn(float $v) => e($sim) . ' ' . number_format($v, 0);
        $filas = [
            ['Venta bruta', $dinero($t['gs']), $dinero($l['gs']), $var($t['gs'], $l['gs'], false)],
            ['Tasa de descuento', number_format($t['desc_pct'], 1) . '%', number_format($l['desc_pct'], 1) . '%', $var($t['desc_pct'], $l['desc_pct'], true, true)],
            ['Venta en promoción', number_format($r['pct_promo_ty'], 1) . '%', number_format($r['pct_promo_ly'], 1) . '%', $var($r['pct_promo_ty'], $r['pct_promo_ly'], true, true)],
            ['Venta neta', $dinero($t['ns']), $dinero($l['ns']), $var($t['ns'], $l['ns'], false)],
            ['Margen', $dinero($t['margen']), $dinero($l['margen']), $var($t['margen'], $l['margen'], false)],
            ['Margen % venta neta', number_format($t['margen_ns'], 1) . '%', number_format($l['margen_ns'], 1) . '%', $var($t['margen_ns'], $l['margen_ns'], true)],
        ];
        $th = 'style="padding:0 0 8px;' . $fuente . 'font-size:11px;font-weight:600;color:#94A3B8;text-transform:uppercase;letter-spacing:.04em"';
        $td = 'style="padding:9px 0;border-top:1px solid #F1F5F9;' . $fuente . 'font-size:14px;color:#334155"';
        $tabla = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:18px 0 6px">'
            . '<tr><th align="left" ' . $th . '></th><th align="right" ' . $th . '>Este año</th><th align="right" ' . $th . '>Año anterior</th><th align="right" ' . $th . '>Var.</th></tr>';
        foreach ($filas as [$n, $a, $b, $c]) {
            $tabla .= '<tr><td ' . $td . '>' . e($n) . '</td><td align="right" ' . $td . '><strong>' . $a . '</strong></td><td align="right" ' . $td . '>' . $b . '</td><td align="right" ' . $td . '>' . $c . '</td></tr>';
        }
        $tabla .= '</table>';

        $puntos = '';
        foreach ($hz as $h) {
            $color = ['malo' => '#E11D48', 'bueno' => '#059669'][$h['tono']] ?? '#64748B';
            // El texto del hallazgo ya viene escapado (con <b> propios) desde cockpit_hallazgos().
            $puntos .= '<tr><td valign="top" style="padding:6px 10px 6px 0"><span style="display:inline-block;width:8px;height:8px;border-radius:4px;background:' . $color . '"></span></td>'
                . '<td style="padding:4px 0;' . $fuente . 'font-size:14px;line-height:1.55;color:#334155">' . $h['texto'] . '</td></tr>';
        }

        $alcance = [];
        if (!empty($q['sucursal_id'])) $alcance[] = (string) qVal("SELECT nombre FROM sucursales WHERE id = ?", [(int) $q['sucursal_id']]);
        elseif ($u['sucursal_id'] !== null) $alcance[] = (string) qVal("SELECT nombre FROM sucursales WHERE id = ?", [(int) $u['sucursal_id']]);
        if ($f['canal']) $alcance[] = cockpit_canales()[$f['canal']] ?? $f['canal'];
        if ($f['marca']) $alcance[] = (string) qVal("SELECT nombre FROM marcas WHERE id = ?", [$f['marca']]);
        if ($f['segmento']) $alcance[] = $f['segmento'];
        if ($f['linea']) $alcance[] = $f['linea'];
        if ($monCod !== '') $alcance[] = 'en ' . $monCod;

        $enlace = (function_exists('mkt_url_abs') ? mkt_url_abs('modules/marketing/cockpit.php') : url('modules/marketing/cockpit.php'))
            . '?' . http_build_query(['tab' => 'resumen'] + $q);
        $rango = fechaCorta($periodo[0]) . ' al ' . fechaCorta($periodo[1]);
        $contenido = '<p style="margin:0 0 4px;' . $fuente . 'font-size:12px;font-weight:600;color:#D97706;text-transform:uppercase;letter-spacing:.06em">'
            . ($vista['frecuencia'] === 'mensual' ? 'Resumen del mes' : 'Resumen de la semana') . '</p>'
            . '<h1 style="margin:0 0 6px;' . $fuente . 'font-size:22px;line-height:1.3;color:#0F172A">' . e($vista['nombre']) . '</h1>'
            . '<p style="margin:0;' . $fuente . 'font-size:14px;color:#64748B">Del ' . e($rango) . ' contra el ' . e(fechaCorta($f['ly'][0]) . ' al ' . fechaCorta($f['ly'][1]))
            . ($alcance ? ' · ' . e(implode(' · ', array_filter($alcance))) : '') . '</p>'
            . $tabla
            . ($puntos ? '<p style="margin:22px 0 8px;' . $fuente . 'font-size:12px;font-weight:700;color:#D97706;text-transform:uppercase;letter-spacing:.06em">Lo más importante</p>'
                . '<table role="presentation" cellpadding="0" cellspacing="0">' . $puntos . '</table>' : '')
            . mail_boton('Abrir en el cockpit', $enlace)
            . '<p style="margin:18px 0 0;' . $fuente . 'font-size:12px;line-height:1.6;color:#94A3B8">Recibes este correo porque pediste el resumen de la vista «' . e($vista['nombre'])
            . '». Para dejar de recibirlo: Promotion Cockpit → Vistas → «Sin resumen por correo».</p>';
        $asunto = 'Promotion Cockpit · ' . $vista['nombre'] . ' · ' . $rango;
        $pre = 'Venta neta ' . $dinero($t['ns']) . ' · descuento ' . number_format($t['desc_pct'], 1) . '% · margen ' . $dinero($t['margen']);
        return [$asunto, mail_plantilla($asunto, $contenido, $GLOBALS['empresa'] ?? [], html_entity_decode(strip_tags($pre)))];
    });
}

/**
 * Envía los resúmenes que toquen. Devuelve [enviados, fallidos].
 * $hoy y $enviar son para las pruebas; en uso normal, hoy y mail_enviar().
 */
function cockpit_resumen_tick(int $lote = COCKPIT_RESUMEN_LOTE, ?string $hoy = null, ?callable $enviar = null): array
{
    $res = ['enviados' => 0, 'fallidos' => 0];
    if (!cockpit_resumen_disponible() || (!$enviar && !mail_configurado())) return $res;
    $enviar ??= 'mail_enviar';
    // Las que llevan más tiempo sin intentarse, primero; una que acaba de fallar
    // espera 6 horas. Así una dirección rechazada no acapara todas las pasadas.
    $conIntento = cockpit_resumen_con_intento();
    foreach (qAll("SELECT * FROM cockpit_vistas WHERE frecuencia IN ('semanal','mensual')"
                  . ($conIntento ? " AND (ultimo_intento IS NULL OR ultimo_intento < NOW() - INTERVAL 6 HOUR) ORDER BY ultimo_intento IS NOT NULL, ultimo_intento, id" : " ORDER BY id")) as $v) {
        if ($res['enviados'] + $res['fallidos'] >= $lote) break;
        $per = cockpit_resumen_periodo($v['frecuencia'], $hoy);
        if (!$per || ($v['ultimo_periodo'] !== null && $v['ultimo_periodo'] >= $per[1])) continue;
        $u = cockpit_resumen_dueno((int) $v['usuario_id']);
        if (!$u || !filter_var($u['email'], FILTER_VALIDATE_EMAIL)) continue;
        // Reclamar el periodo ANTES de enviar: otra petición a la vez no lo repite.
        $tomado = q("UPDATE cockpit_vistas SET ultimo_periodo = ?" . ($conIntento ? ", ultimo_intento = NOW()" : '')
                    . " WHERE id = ? AND frecuencia = ? AND (ultimo_periodo IS NULL OR ultimo_periodo < ?)",
                    [$per[1], $v['id'], $v['frecuencia'], $per[1]])->rowCount();
        if (!$tomado) continue;
        try {
            [$asunto, $html] = cockpit_resumen_correo($v, $u, $per);
            $r = $enviar($u['email'], $asunto, $html);
        } catch (Throwable $e) {
            $r = ['ok' => false, 'error' => $e->getMessage()];
        }
        if (!empty($r['ok'])) {
            $res['enviados']++;
        } else {
            // Se devuelve el periodo para reintentar, salvo que el dueño haya
            // cambiado la vista mientras tanto (su cambio manda).
            q("UPDATE cockpit_vistas SET ultimo_periodo = ? WHERE id = ? AND frecuencia = ? AND ultimo_periodo = ?",
              [$v['ultimo_periodo'], $v['id'], $v['frecuencia'], $per[1]]);
            $res['fallidos']++;
            error_log('Resumen del cockpit (vista ' . $v['id'] . '): ' . ($r['error'] ?? 'error desconocido'));
        }
    }
    return $res;
}

/** Enganche sin cron: como máximo una pasada por hora entre todos los usuarios. */
function cockpit_resumen_tick_si_toca(): void
{
    // Sin correo configurado no cuesta ni una consulta: corre en cada página.
    if (!mail_configurado()) return;
    // Como el motor de marketing: nunca debe tumbar una página. Y en la web va
    // de una vista en una: el resto lo recogen las pasadas siguientes o el cron.
    try {
        if (!cockpit_resumen_disponible()) return;
        cockpit_resumen_tick_si_toca_();
    } catch (Throwable $e) {
        error_log('[cockpit resumen] ' . $e->getMessage());
    }
}

function cockpit_resumen_tick_si_toca_(): void
{
    $ahora = time();
    $ultimo = qVal("SELECT valor FROM cockpit_parametros WHERE clave = 'resumen_ultimo_tick'");
    if ($ultimo !== null && $ultimo !== false && (int) $ultimo >= $ahora - 3600) return;
    if ($ultimo === null || $ultimo === false) q("INSERT IGNORE INTO cockpit_parametros (clave, valor) VALUES ('resumen_ultimo_tick', '0')");
    $toca = q("UPDATE cockpit_parametros SET valor = ? WHERE clave = 'resumen_ultimo_tick' AND CAST(valor AS UNSIGNED) < ?",
              [(string) $ahora, $ahora - 3600])->rowCount();
    if ($toca) {
        @set_time_limit(60);
        ignore_user_abort(true);
        cockpit_resumen_tick(1);
    }
}
