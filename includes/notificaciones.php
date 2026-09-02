<?php
/**
 * Centro de Notificaciones.
 *
 * Modelo: una fila por «situación viva» del negocio, no por evento. Si el stock
 * sigue bajo mañana no nace otra notificación: se actualiza la misma (la `clave`
 * deduplica). Cuando la situación desaparece, la fila pasa a 'resuelta' sola.
 * Así la campana muestra la realidad de hoy y nunca se llena de basura vieja.
 *
 * El motor corre solo, sin cron: la primera visita después de N minutos reclama
 * el turno con un UPDATE atómico (tabla `sistema_estado`) y hace el barrido.
 */

const NOTIF_SCAN_MINUTOS = 5;

/* ============================================================
 *  Infraestructura
 * ============================================================ */

/** ¿Está aplicada la migración? Evita romper una base sin actualizar. */
function notif_disponible(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = (bool) qVal("SHOW TABLES LIKE 'notificaciones'");
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

/**
 * Reclama el turno del barrido de forma atómica. Devuelve true UNA sola vez
 * cada NOTIF_SCAN_MINUTOS aunque entren diez peticiones en el mismo segundo.
 */
function notif_reclamar_turno(int $minutos = NOTIF_SCAN_MINUTOS): bool
{
    $st = q(
        "INSERT INTO sistema_estado (clave, valor, updated_at)
         VALUES ('notificaciones_scan', UNIX_TIMESTAMP(), NOW())
         ON DUPLICATE KEY UPDATE
            valor      = IF(updated_at < (NOW() - INTERVAL ? MINUTE), UNIX_TIMESTAMP(), valor),
            updated_at = IF(updated_at < (NOW() - INTERVAL ? MINUTE), NOW(), updated_at)",
        [$minutos, $minutos]
    );
    return $st->rowCount() > 0;
}

/** Corre el barrido si toca. Nunca debe tumbar una página: silencia errores. */
function notif_scan_si_toca(): void
{
    if (!notif_disponible()) return;
    try {
        if (notif_reclamar_turno()) notif_generar();
    } catch (Throwable $e) {
        if (APP_ENV !== 'production') error_log('[notificaciones] ' . $e->getMessage());
    }
}

/**
 * Sincroniza una familia de notificaciones.
 *
 * $items: lista de arreglos con al menos clave/titulo. Las notificaciones
 * activas de esa familia que ya no estén en $items se marcan como resueltas.
 */
function notif_sync(string $tipo, array $items): void
{
    $claves = [];
    foreach ($items as $n) {
        $clave = (string) $n['clave'];
        $claves[] = $clave;
        $fila = [
            'clave'           => $clave,
            'tipo'            => $tipo,
            'categoria'       => $n['categoria']  ?? 'sistema',
            'prioridad'       => $n['prioridad']  ?? 'media',
            'titulo'          => mb_substr((string) $n['titulo'], 0, 150),
            'mensaje'         => isset($n['mensaje']) ? mb_substr((string) $n['mensaje'], 0, 300) : null,
            'url'             => $n['url']        ?? null,
            'icono'           => $n['icono']      ?? 'bell',
            'color'           => $n['color']      ?? 'blue',
            'sucursal_id'     => $n['sucursal_id'] ?? null,
            'usuario_id'      => $n['usuario_id']  ?? null,
            'permiso'         => $n['permiso']     ?? null,
            'referencia_tipo' => $n['referencia_tipo'] ?? null,
            'referencia_id'   => $n['referencia_id']   ?? null,
        ];

        // La fila se reclama de forma atómica. Antes se consultaba y luego se
        // insertaba: si dos barridos llegaban a la vez, el INSERT del segundo
        // chocaba contra uq_notif_clave y tumbaba el barrido completo.
        q("INSERT IGNORE INTO notificaciones (clave, tipo, titulo) VALUES (?, ?, ?)",
            [$clave, $tipo, $fila['titulo']]);

        $existe = qOne("SELECT id, titulo, mensaje, estado FROM notificaciones WHERE clave = ?", [$clave]);
        if (!$existe) continue;   // otra petición la borró justo ahora; no pasa nada

        // Si el texto cambió (subió el número de productos en falta, por ejemplo)
        // se reabre para que el usuario la vuelva a ver.
        $cambio = $existe['titulo'] !== $fila['titulo'] || $existe['mensaje'] !== $fila['mensaje'];
        $datos = $fila;
        $datos['estado'] = 'activa';
        $datos['resuelta_at'] = null;
        unset($datos['clave']);
        dbUpdate('notificaciones', $datos, 'id = ?', [(int) $existe['id']]);
        if ($cambio || $existe['estado'] !== 'activa') {
            q("DELETE FROM notificacion_lecturas WHERE notificacion_id = ?", [(int) $existe['id']]);
        }
    }

    // Resolver las que ya no aplican.
    if ($claves) {
        $ph = implode(',', array_fill(0, count($claves), '?'));
        q("UPDATE notificaciones SET estado = 'resuelta', resuelta_at = NOW()
            WHERE tipo = ? AND estado = 'activa' AND clave NOT IN ($ph)",
            array_merge([$tipo], $claves));
    } else {
        q("UPDATE notificaciones SET estado = 'resuelta', resuelta_at = NOW()
            WHERE tipo = ? AND estado = 'activa'", [$tipo]);
    }
}

/* ============================================================
 *  Consulta para la UI
 * ============================================================ */

/**
 * Fragmento WHERE + parámetros con las reglas de visibilidad del usuario
 * actual: su sucursal, sus permisos y las dirigidas a él.
 */
function notif_where_visible(): array
{
    $u    = current_user();
    $uid  = (int) ($u['id'] ?? 0);
    $cond = ["n.estado = 'activa'"];
    $par  = [];

    // Dirigidas a un usuario concreto.
    $cond[] = '(n.usuario_id IS NULL OR n.usuario_id = ?)';
    $par[]  = $uid;

    // Sucursal: la activa manda; si es «todas», las que el usuario alcanza.
    $sid = current_sucursal_id();
    if ($sid !== null) {
        $cond[] = '(n.sucursal_id IS NULL OR n.sucursal_id = ?)';
        $par[]  = $sid;
    } elseif (!is_super()) {
        $ids = array_map(fn($s) => (int) $s['id'], sucursales_visibles());
        if ($ids) {
            $cond[] = '(n.sucursal_id IS NULL OR n.sucursal_id IN (' . implode(',', $ids) . '))';
        } else {
            $cond[] = 'n.sucursal_id IS NULL';
        }
    }

    // Permiso requerido.
    if (!is_super()) {
        $perms = $_SESSION['permisos'] ?? [];
        if ($perms) {
            $ph = implode(',', array_fill(0, count($perms), '?'));
            $cond[] = "(n.permiso IS NULL OR n.permiso IN ($ph))";
            $par = array_merge($par, array_values($perms));
        } else {
            $cond[] = 'n.permiso IS NULL';
        }
    }

    return [implode(' AND ', $cond), $par];
}

/** Notificaciones visibles. $opts: limit, solo_no_leidas, categoria. */
function notif_listar(array $opts = []): array
{
    if (!notif_disponible()) return [];
    $uid = (int) (current_user()['id'] ?? 0);
    [$where, $par] = notif_where_visible();

    $sql = "SELECT n.*, su.nombre AS sucursal_nombre,
                   (l.usuario_id IS NOT NULL) AS leida
              FROM notificaciones n
              LEFT JOIN sucursales su ON su.id = n.sucursal_id
              LEFT JOIN notificacion_lecturas l ON l.notificacion_id = n.id AND l.usuario_id = ?
             WHERE $where";
    $par = array_merge([$uid], $par);

    if (!empty($opts['solo_no_leidas'])) $sql .= ' AND l.usuario_id IS NULL';
    if (!empty($opts['categoria'])) { $sql .= ' AND n.categoria = ?'; $par[] = $opts['categoria']; }

    $sql .= " ORDER BY FIELD(n.prioridad,'critica','alta','media','baja'), n.updated_at DESC";
    $sql .= ' LIMIT ' . max(1, (int) ($opts['limit'] ?? 50));

    try {
        return qAll($sql, $par);
    } catch (Throwable $e) {
        return [];
    }
}

/** Cuántas notificaciones sin leer tiene el usuario actual. */
function notif_no_leidas(): int
{
    if (!notif_disponible()) return 0;
    $uid = (int) (current_user()['id'] ?? 0);
    [$where, $par] = notif_where_visible();
    try {
        return (int) qVal(
            "SELECT COUNT(*) FROM notificaciones n
              LEFT JOIN notificacion_lecturas l ON l.notificacion_id = n.id AND l.usuario_id = ?
              WHERE $where AND l.usuario_id IS NULL",
            array_merge([$uid], $par)
        );
    } catch (Throwable $e) {
        return 0;
    }
}

/** Resumen por prioridad para la cabecera del panel. */
function notif_resumen(): array
{
    if (!notif_disponible()) return ['critica' => 0, 'alta' => 0, 'media' => 0, 'baja' => 0];
    [$where, $par] = notif_where_visible();
    $base = ['critica' => 0, 'alta' => 0, 'media' => 0, 'baja' => 0];
    try {
        foreach (qAll("SELECT n.prioridad, COUNT(*) c FROM notificaciones n WHERE $where GROUP BY n.prioridad", $par) as $r) {
            $base[$r['prioridad']] = (int) $r['c'];
        }
    } catch (Throwable $e) {
    }
    return $base;
}

/** Marca una notificación como leída por el usuario actual. */
function notif_marcar_leida(int $id): void
{
    if (!notif_disponible() || $id <= 0) return;
    $uid = (int) (current_user()['id'] ?? 0);
    if (!$uid) return;
    q("INSERT IGNORE INTO notificacion_lecturas (notificacion_id, usuario_id) VALUES (?, ?)", [$id, $uid]);
}

/** Marca como leídas TODAS las notificaciones visibles del usuario actual. */
function notif_marcar_todas(): int
{
    if (!notif_disponible()) return 0;
    $uid = (int) (current_user()['id'] ?? 0);
    if (!$uid) return 0;
    [$where, $par] = notif_where_visible();
    $st = q(
        "INSERT IGNORE INTO notificacion_lecturas (notificacion_id, usuario_id)
         SELECT n.id, ? FROM notificaciones n WHERE $where",
        array_merge([$uid], $par)
    );
    return $st->rowCount();
}

/** Estilos de la notificación según su color. */
function notif_estilo(string $color): array
{
    $mapa = [
        'rose'    => ['bg-rose-50 text-rose-600', 'bg-rose-500'],
        'amber'   => ['bg-amber-50 text-amber-600', 'bg-amber-500'],
        'emerald' => ['bg-emerald-50 text-emerald-600', 'bg-emerald-500'],
        'blue'    => ['bg-blue-50 text-blue-600', 'bg-blue-500'],
        'indigo'  => ['bg-indigo-50 text-indigo-600', 'bg-indigo-500'],
        'violet'  => ['bg-violet-50 text-violet-600', 'bg-violet-500'],
        'cyan'    => ['bg-cyan-50 text-cyan-600', 'bg-cyan-500'],
        'slate'   => ['bg-slate-100 text-slate-500', 'bg-slate-400'],
    ];
    return $mapa[$color] ?? $mapa['blue'];
}

/** Etiqueta legible de la categoría. */
function notif_categoria_label(string $cat): string
{
    return [
        'inventario' => 'Inventario', 'ventas' => 'Ventas', 'finanzas' => 'Finanzas',
        'fiscal' => 'Fiscal / DGII', 'crm' => 'CRM', 'rrhh' => 'Recursos Humanos',
        'sistema' => 'Sistema',
    ][$cat] ?? ucfirst($cat);
}

/* ============================================================
 *  Motor: reglas de negocio que generan las notificaciones
 * ============================================================ */

function notif_generar(): void
{
    notif_gen_stock();
    notif_gen_cuentas_por_cobrar();
    notif_gen_ncf();
    notif_gen_caja();
    notif_gen_transferencias();
    notif_gen_transferencias_por_aprobar();
    notif_gen_conteos();
    notif_gen_crm();
    notif_gen_pedidos();
    notif_gen_metas();
    notif_gen_aprobaciones();
    notif_gen_fiscal();
    notif_gen_rrhh();
    notif_gen_nomina_calendario();
    notif_gen_margenes();
    notif_gen_sanidad();
    notif_gen_seguridad();
    notif_gen_ecf();
}

/**
 * Comprobantes Fiscales Electrónicos que no llegaron a la DGII.
 *
 * Un e-CF atascado no se nota: la venta se cobró, el cliente se fue con su
 * ticket y todo parece normal. Lo único que falta es lo que la DGII no ve, y sin
 * este aviso nadie se entera hasta que llega una revisión.
 *
 * Se avisa de tres situaciones distintas porque se resuelven distinto:
 *   · En error   → hay que corregir el dato y reenviar, o anular formalmente.
 *   · Atorados   → el proveedor lleva rato sin aceptar: puede ser la red.
 *   · Sin acusar → se transmitió pero la DGII no ha resuelto en 24 horas.
 */
function notif_gen_ecf(): void
{
    // Si el módulo no está instalado o está apagado, no hay nada que vigilar y
    // tampoco conviene consultar tablas que podrían no existir.
    if (!function_exists('ecfDisponible') || !ecfDisponible() || !ecfActivo()) {
        notif_sync('ecf', []);
        return;
    }

    $items = [];

    $enError = (int) qVal("SELECT COUNT(*) FROM ecf_documentos WHERE estado = 'error'");
    if ($enError > 0) {
        $items[] = [
            'clave' => 'ecf_error', 'categoria' => 'fiscal', 'prioridad' => 'critica',
            'titulo' => $enError === 1
                ? '1 comprobante electrónico con error'
                : $enError . ' comprobantes electrónicos con error',
            'mensaje' => 'Se les asignó un e-NCF pero no se pudieron transmitir. '
                       . 'Hay que corregirlos y reenviarlos, o anularlos ante la DGII.',
            'url' => 'modules/finanzas/ecf.php?tab=documentos', 'icono' => 'receipt',
            'color' => 'rose', 'permiso' => 'ecf.ver',
        ];
    }

    // Pendientes de hace más de una hora: el reintento con espera creciente ya
    // debería haberlos despachado.
    $atorados = (int) qVal(
        "SELECT COUNT(*) FROM ecf_documentos
          WHERE estado = 'pendiente' AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)"
    );
    if ($atorados > 0) {
        $items[] = [
            'clave' => 'ecf_atorados', 'categoria' => 'fiscal', 'prioridad' => 'alta',
            'titulo' => $atorados . ' comprobante(s) sin transmitir hace más de una hora',
            'mensaje' => 'La cola no está avanzando. Revisa la conexión con el proveedor '
                       . 'y que la tarea programada esté corriendo.',
            'url' => 'modules/finanzas/ecf.php?tab=documentos', 'icono' => 'alert',
            'color' => 'amber', 'permiso' => 'ecf.ver',
        ];
    }

    // Transmitidos hace más de un día sin acuse. Puede ser normal en un corte
    // del servicio, pero pasadas 24 horas merece una mirada.
    $sinAcuse = (int) qVal(
        "SELECT COUNT(*) FROM ecf_documentos
          WHERE estado = 'enviado' AND enviado_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    );
    if ($sinAcuse > 0) {
        $items[] = [
            'clave' => 'ecf_sin_acuse', 'categoria' => 'fiscal', 'prioridad' => 'media',
            'titulo' => $sinAcuse . ' comprobante(s) sin respuesta de la DGII',
            'mensaje' => 'Se transmitieron hace más de 24 horas y siguen sin acuse. '
                       . 'Consúltalo con el proveedor si no se resuelve.',
            'url' => 'modules/finanzas/ecf.php?tab=documentos', 'icono' => 'clock',
            'color' => 'amber', 'permiso' => 'ecf.ver',
        ];
    }

    notif_sync('ecf', $items);
}

/**
 * Seguridad de acceso.
 *
 * La alerta que importa es la primera: la politica de verificacion en dos pasos
 * encendida mientras el correo saliente no esta configurado. El sistema deja
 * entrar solo con contrasena para no dejar al negocio fuera de su propio ERP,
 * pero eso significa que la proteccion NO se esta aplicando, y sin este aviso
 * nadie se entera.
 */
function notif_gen_seguridad(): void
{
    if (!otp_disponible()) return;

    $items = [];
    $cfg = otp_config();

    if ($cfg['modo'] !== 'nunca' && !otp_operativo()) {
        $items[] = [
            'clave' => 'seg_otp_sin_correo', 'categoria' => 'sistema', 'prioridad' => 'critica',
            'titulo' => 'La verificacion en dos pasos no se esta aplicando',
            'mensaje' => 'La politica esta encendida pero no hay correo saliente configurado, '
                       . 'asi que no hay forma de entregar los codigos. Configura Resend en config.local.php.',
            'url' => 'modules/admin/seguridad.php', 'icono' => 'shield', 'color' => 'rose',
            'permiso' => 'configuracion.ver',
        ];
    }

    if ($cfg['modo'] !== 'nunca') {
        $exentos = (int) qVal("SELECT COUNT(*) FROM usuarios WHERE activo = 1 AND COALESCE(otp_activo,1) = 0");
        if ($exentos > 0) {
            $items[] = [
                'clave' => 'seg_otp_exentos', 'categoria' => 'sistema', 'prioridad' => 'media',
                'titulo' => $exentos . ' cuenta' . ($exentos === 1 ? '' : 's') . ' sin verificacion en dos pasos',
                'mensaje' => 'Esas cuentas entran solo con contrasena. Revisalas y activa la verificacion '
                           . 'en las que no tengan un motivo real para estar exentas.',
                'url' => 'modules/admin/seguridad.php', 'icono' => 'user', 'color' => 'amber',
                'permiso' => 'configuracion.ver',
            ];
        }
    }

    notif_sync('seguridad_acceso', $items);
}

/**
 * Cumplimiento sanitario: registros que caducan y mercancia vencida.
 *
 * Son las dos cosas que sancionan en una inspeccion, y las dos avisan con
 * antelacion suficiente para poder actuar: un registro sanitario tarda meses en
 * renovarse, y un lote por vencer todavia se puede mover o devolver.
 */
function notif_gen_sanidad(): void
{
    if (!san_disponible()) return;

    /* ---- Registros sanitarios ---- */
    $items = [];
    $r = san_resumen();

    if (($r['registro_vencido'] ?? 0) > 0) {
        $n = (int) $r['registro_vencido'];
        $items[] = [
            'clave' => 'san_registro_vencido', 'categoria' => 'cumplimiento', 'prioridad' => 'critica',
            'titulo' => $n . ' registro' . ($n === 1 ? '' : 's') . ' sanitario' . ($n === 1 ? '' : 's') . ' vencido' . ($n === 1 ? '' : 's'),
            'mensaje' => 'Comercializar con el registro vencido es una infraccion. Inicia la renovacion ante DIGEMAPS.',
            'url' => 'modules/reportes/registros_sanitarios.php?estado=vencido',
            'icono' => 'shield', 'color' => 'rose', 'permiso' => 'reportes.sanidad',
        ];
    }
    if (($r['sin_registro'] ?? 0) > 0) {
        $n = (int) $r['sin_registro'];
        $items[] = [
            'clave' => 'san_sin_registro', 'categoria' => 'cumplimiento', 'prioridad' => 'alta',
            'titulo' => $n . ' producto' . ($n === 1 ? '' : 's') . ' regulado' . ($n === 1 ? '' : 's') . ' sin registro',
            'mensaje' => 'Sin numero de registro no se pueden justificar ante una inspeccion.',
            'url' => 'modules/reportes/registros_sanitarios.php?estado=sin_registro',
            'icono' => 'alert', 'color' => 'rose', 'permiso' => 'reportes.sanidad',
        ];
    }
    if (($r['registro_por_vencer'] ?? 0) > 0) {
        $n = (int) $r['registro_por_vencer'];
        $items[] = [
            'clave' => 'san_registro_por_vencer', 'categoria' => 'cumplimiento', 'prioridad' => 'media',
            'titulo' => $n . ' registro' . ($n === 1 ? '' : 's') . ' sanitario' . ($n === 1 ? '' : 's') . ' por vencer',
            'mensaje' => 'Vencen en los proximos ' . SAN_DIAS_AVISO_REGISTRO . ' dias. Una renovacion tarda meses: empieza el tramite.',
            'url' => 'modules/reportes/registros_sanitarios.php?estado=por_vencer',
            'icono' => 'clock', 'color' => 'amber', 'permiso' => 'reportes.sanidad',
        ];
    }
    notif_sync('sanidad_registro', $items);

    /* ---- Lotes, por sucursal: quien tiene que retirarlos trabaja en una ---- */
    $lotes = qAll(
        "SELECT l.sucursal_id, su.nombre AS sucursal,
                SUM(CASE WHEN l.fecha_vencimiento < CURDATE() THEN 1 ELSE 0 END) AS vencidos,
                COALESCE(SUM(CASE WHEN l.fecha_vencimiento < CURDATE() THEN l.cantidad * l.costo_unitario END),0) AS valor_vencido,
                SUM(CASE WHEN l.fecha_vencimiento >= CURDATE()
                          AND l.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS proximos
           FROM lotes l
           JOIN sucursales su ON su.id = l.sucursal_id AND su.activo = 1
          WHERE l.cantidad > 0 AND l.fecha_vencimiento IS NOT NULL
          GROUP BY l.sucursal_id, su.nombre"
    );
    $venc = []; $prox = [];
    foreach ($lotes as $x) {
        $sid = (int) $x['sucursal_id'];
        if ((int) $x['vencidos'] > 0) {
            $n = (int) $x['vencidos'];
            $venc[] = [
                'clave' => "san_lote_vencido:$sid", 'categoria' => 'cumplimiento', 'prioridad' => 'critica',
                'titulo' => $n . ' lote' . ($n === 1 ? '' : 's') . ' vencido' . ($n === 1 ? '' : 's') . ' en existencia',
                'mensaje' => 'En ' . $x['sucursal'] . ' hay ' . money($x['valor_vencido']) . ' en mercancia vencida. '
                           . 'El sistema ya impide venderla, pero tenerla en el area de venta se sanciona: retirala y da la baja.',
                'url' => 'modules/inventario/lotes.php?estado=vencido',
                'icono' => 'alert', 'color' => 'rose', 'sucursal_id' => $sid, 'permiso' => 'sanidad.ver',
            ];
        }
        if ((int) $x['proximos'] > 0) {
            $n = (int) $x['proximos'];
            $prox[] = [
                'clave' => "san_lote_proximo:$sid", 'categoria' => 'cumplimiento', 'prioridad' => 'alta',
                'titulo' => $n . ' lote' . ($n === 1 ? '' : 's') . ' vence' . ($n === 1 ? '' : 'n') . ' en 30 dias',
                'mensaje' => 'En ' . $x['sucursal'] . '. Muevelos con promocion o devuelvelos al proveedor mientras tienen vida util.',
                'url' => 'modules/inventario/lotes.php?estado=por_vencer',
                'icono' => 'clock', 'color' => 'amber', 'sucursal_id' => $sid, 'permiso' => 'sanidad.ver',
            ];
        }
    }
    notif_sync('sanidad_lote_vencido', $venc);
    notif_sync('sanidad_lote_proximo', $prox);
}

/** Stock agotado y stock bajo, agrupado por sucursal (una alerta por sucursal). */
function notif_gen_stock(): void
{
    $rows = qAll(
        "SELECT s.sucursal_id, su.nombre AS sucursal,
                SUM(CASE WHEN s.cantidad <= 0 THEN 1 ELSE 0 END) AS agotados,
                SUM(CASE WHEN s.cantidad > 0 AND p.stock_minimo > 0 AND s.cantidad <= p.stock_minimo THEN 1 ELSE 0 END) AS bajos
           FROM inventario_stock s
           JOIN productos p   ON p.id = s.producto_id AND p.activo = 1 AND p.tipo = 'producto'
           JOIN sucursales su ON su.id = s.sucursal_id AND su.activo = 1
          GROUP BY s.sucursal_id"
    );

    $agotado = [];
    $bajo    = [];
    foreach ($rows as $r) {
        $sid = (int) $r['sucursal_id'];
        if ((int) $r['agotados'] > 0) {
            $n = (int) $r['agotados'];
            $agotado[] = [
                'clave' => "stock_agotado:$sid", 'categoria' => 'inventario', 'prioridad' => 'critica',
                'titulo' => $n . ' producto' . ($n === 1 ? '' : 's') . ' sin existencia',
                'mensaje' => 'En ' . $r['sucursal'] . ' hay productos activos en cero. No se pueden vender.',
                'url' => 'modules/inventario/stock.php?sucursal_id=' . $sid . '&filtro=agotado',
                'icono' => 'alert', 'color' => 'rose', 'sucursal_id' => $sid, 'permiso' => 'inventario.ver',
            ];
        }
        if ((int) $r['bajos'] > 0) {
            $n = (int) $r['bajos'];
            $bajo[] = [
                'clave' => "stock_bajo:$sid", 'categoria' => 'inventario', 'prioridad' => 'alta',
                'titulo' => $n . ' producto' . ($n === 1 ? '' : 's') . ' bajo el mínimo',
                'mensaje' => 'En ' . $r['sucursal'] . '. Conviene reponer antes de quedarte sin inventario.',
                'url' => 'modules/inventario/stock.php?sucursal_id=' . $sid . '&filtro=bajo',
                'icono' => 'package', 'color' => 'amber', 'sucursal_id' => $sid, 'permiso' => 'inventario.ver',
            ];
        }
    }
    notif_sync('stock_agotado', $agotado);
    notif_sync('stock_bajo', $bajo);
}

/** Cuentas por cobrar vencidas y clientes por encima de su límite de crédito. */
function notif_gen_cuentas_por_cobrar(): void
{
    // Vencidas: la venta a crédito más antigua sin saldar pasa de 30 días.
    $venc = qOne(
        "SELECT COUNT(*) AS clientes, COALESCE(SUM(t.balance),0) AS total FROM (
            SELECT c.id, c.balance, MIN(v.fecha) AS mas_vieja
              FROM clientes c
              JOIN ventas v          ON v.cliente_id = c.id AND v.estado = 'completada'
              JOIN venta_pagos vp    ON vp.venta_id = v.id
              JOIN metodos_pago mp   ON mp.id = vp.metodo_pago_id AND mp.es_credito = 1
             WHERE c.balance > 0
             GROUP BY c.id
            HAVING mas_vieja < (NOW() - INTERVAL 30 DAY)
         ) t"
    );
    $items = [];
    if ($venc && (int) $venc['clientes'] > 0) {
        $n = (int) $venc['clientes'];
        $items[] = [
            'clave' => 'cxc_vencida', 'categoria' => 'finanzas', 'prioridad' => 'alta',
            'titulo' => $n . ' cliente' . ($n === 1 ? '' : 's') . ' con saldo vencido',
            'mensaje' => money($venc['total']) . ' por cobrar con más de 30 días de antigüedad.',
            'url' => 'modules/reportes/cxc.php', 'icono' => 'wallet', 'color' => 'rose',
            'permiso' => 'clientes.ver',
        ];
    }
    notif_sync('cxc_vencida', $items);

    // Sobre el límite de crédito autorizado.
    $exc = qOne(
        "SELECT COUNT(*) AS n, COALESCE(SUM(balance - limite_credito),0) AS exceso
           FROM clientes WHERE activo = 1 AND limite_credito > 0 AND balance > limite_credito"
    );
    $items = [];
    if ($exc && (int) $exc['n'] > 0) {
        $n = (int) $exc['n'];
        $items[] = [
            'clave' => 'credito_excedido', 'categoria' => 'finanzas', 'prioridad' => 'alta',
            'titulo' => $n . ' cliente' . ($n === 1 ? '' : 's') . ' sobre su límite de crédito',
            'mensaje' => 'Exceso acumulado de ' . money($exc['exceso']) . '. Revisa antes de seguir facturando a crédito.',
            'url' => 'modules/pos/cuentas_cobrar.php', 'icono' => 'alert', 'color' => 'rose',
            'permiso' => 'clientes.ver',
        ];
    }
    notif_sync('credito_excedido', $items);
}

/** Secuencias de NCF por agotarse o por vencer (obligación DGII). */
function notif_gen_ncf(): void
{
    $secs = qAll("SELECT * FROM ncf_secuencias WHERE activo = 1");
    $agot = [];
    $venc = [];
    foreach ($secs as $s) {
        $tipo      = $s['tipo'];
        $restantes = (int) $s['secuencia_hasta'] - (int) $s['secuencia_actual'] + 1;
        if ($restantes <= 200) {
            $agot[] = [
                'clave' => 'ncf_agotandose:' . $tipo, 'categoria' => 'fiscal',
                'prioridad' => $restantes <= 0 ? 'critica' : ($restantes <= 50 ? 'alta' : 'media'),
                'titulo' => $restantes <= 0
                    ? 'Secuencia NCF ' . $tipo . ' agotada'
                    : 'Quedan ' . number_format($restantes) . ' NCF ' . $tipo,
                'mensaje' => $restantes <= 0
                    ? 'No se pueden emitir más comprobantes ' . $tipo . '. Solicita una nueva secuencia a la DGII.'
                    : 'Solicita el próximo rango a la DGII antes de agotar la secuencia.',
                'url' => 'modules/admin/configuracion.php#ncf', 'icono' => 'receipt',
                'color' => $restantes <= 50 ? 'rose' : 'amber', 'permiso' => 'configuracion.ver',
            ];
        }
        if (!empty($s['vencimiento'])) {
            $dias = (int) floor((strtotime($s['vencimiento']) - strtotime(date('Y-m-d'))) / 86400);
            if ($dias <= 45) {
                $venc[] = [
                    'clave' => 'ncf_vencimiento:' . $tipo, 'categoria' => 'fiscal',
                    'prioridad' => $dias < 0 ? 'critica' : ($dias <= 15 ? 'alta' : 'media'),
                    'titulo' => $dias < 0
                        ? 'Secuencia NCF ' . $tipo . ' vencida'
                        : 'NCF ' . $tipo . ' vence en ' . $dias . ' día' . ($dias === 1 ? '' : 's'),
                    'mensaje' => 'Vencimiento de la autorización: ' . fechaCorta($s['vencimiento']) . '.',
                    'url' => 'modules/admin/configuracion.php#ncf', 'icono' => 'calendar',
                    'color' => $dias <= 15 ? 'rose' : 'amber', 'permiso' => 'configuracion.ver',
                ];
            }
        }
    }
    notif_sync('ncf_agotandose', $agot);
    notif_sync('ncf_vencimiento', $venc);
}

/** Cajas que quedaron abiertas de días anteriores. */
function notif_gen_caja(): void
{
    $rows = qAll(
        "SELECT cs.id, cs.sucursal_id, cs.abierta_at, su.nombre AS sucursal,
                CONCAT(u.nombre,' ',u.apellido) AS cajero, c.nombre AS caja
           FROM caja_sesiones cs
           JOIN sucursales su ON su.id = cs.sucursal_id
           JOIN usuarios u    ON u.id = cs.usuario_id
           JOIN cajas c       ON c.id = cs.caja_id
          WHERE cs.estado = 'abierta' AND cs.abierta_at < CURDATE()
          ORDER BY cs.abierta_at ASC LIMIT 20"
    );
    $items = [];
    foreach ($rows as $r) {
        $dias = (int) floor((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', strtotime($r['abierta_at'])))) / 86400);
        $items[] = [
            'clave' => 'caja_abierta:' . (int) $r['id'], 'categoria' => 'ventas',
            'prioridad' => $dias >= 2 ? 'alta' : 'media',
            'titulo' => 'Caja sin cerrar: ' . $r['caja'],
            'mensaje' => 'Abierta por ' . $r['cajero'] . ' desde ' . fechaCorta($r['abierta_at'])
                . ' (' . $dias . ' día' . ($dias === 1 ? '' : 's') . ') en ' . $r['sucursal'] . '.',
            'url' => 'modules/pos/caja.php', 'icono' => 'cash', 'color' => 'amber',
            'sucursal_id' => (int) $r['sucursal_id'], 'permiso' => 'caja.ver',
            'referencia_tipo' => 'caja_sesion', 'referencia_id' => (int) $r['id'],
        ];
    }
    notif_sync('caja_abierta', $items);
}

/** Transferencias enviadas que la sucursal destino no ha recibido. */
/**
 * Transferencias esperando que alguien apruebe la salida.
 *
 * Sin esto, la mercancía se queda parada en el origen hasta que a quien
 * aprueba se le ocurra entrar a mirar. Se avisa a la sucursal de ORIGEN, que es
 * de donde va a salir, y solo a quien puede aprobar.
 *
 * Prioridad alta desde el primer día: una transferencia parada es una tienda
 * esperando mercancía que cree que ya salió.
 */
function notif_gen_transferencias_por_aprobar(): void
{
    $rows = qAll(
        "SELECT t.sucursal_origen_id AS sid, su.nombre AS sucursal, COUNT(*) AS n,
                MIN(t.created_at) AS mas_vieja
           FROM transferencias t
           JOIN sucursales su ON su.id = t.sucursal_origen_id
          WHERE t.estado = 'pendiente'
          GROUP BY t.sucursal_origen_id, su.nombre"
    );
    $items = [];
    foreach ($rows as $r) {
        $n = (int) $r['n'];
        $items[] = [
            'clave' => 'transferencia_por_aprobar:' . (int) $r['sid'], 'categoria' => 'inventario',
            'prioridad' => 'alta',
            'titulo' => $n . ' transferencia' . ($n === 1 ? '' : 's') . ' esperando aprobación',
            'mensaje' => 'Mercancía que quiere salir de ' . $r['sucursal']
                . ($r['mas_vieja'] ? ' desde ' . fechaCorta($r['mas_vieja']) : '')
                . '. No se mueve hasta que se apruebe.',
            'url' => 'modules/inventario/transferencias.php?estado=pendiente',
            'icono' => 'clock', 'color' => 'amber',
            'sucursal_id' => (int) $r['sid'], 'permiso' => 'transferencias.aprobar',
        ];
    }
    notif_sync('transferencia_por_aprobar', $items);
}

function notif_gen_transferencias(): void
{
    $rows = qAll(
        "SELECT t.sucursal_destino_id AS sid, su.nombre AS sucursal, COUNT(*) AS n,
                MIN(t.enviada_at) AS mas_vieja
           FROM transferencias t
           JOIN sucursales su ON su.id = t.sucursal_destino_id
          WHERE t.estado = 'enviada'
          GROUP BY t.sucursal_destino_id"
    );
    $items = [];
    foreach ($rows as $r) {
        $n = (int) $r['n'];
        $items[] = [
            'clave' => 'transferencia_pendiente:' . (int) $r['sid'], 'categoria' => 'inventario',
            'prioridad' => 'alta',
            'titulo' => $n . ' transferencia' . ($n === 1 ? '' : 's') . ' por recibir',
            'mensaje' => 'Mercancía en tránsito hacia ' . $r['sucursal']
                . ($r['mas_vieja'] ? ' desde ' . fechaCorta($r['mas_vieja']) : '') . '.',
            'url' => 'modules/inventario/transferencias.php?estado=enviada',
            'icono' => 'transfer', 'color' => 'indigo',
            'sucursal_id' => (int) $r['sid'], 'permiso' => 'transferencias.recibir',
        ];
    }
    notif_sync('transferencia_pendiente', $items);
}

/**
 * Conteos físicos empezados y sin aplicar.
 *
 * Un conteo abierto es trabajo a medias que además ENVEJECE: la existencia
 * teórica que se guardó al abrirlo se va separando de la real con cada venta,
 * así que cuanto más tarde en aplicarse, menos significa la diferencia que
 * calcule. Y mientras tanto nadie ve el descuadre que se encontró.
 *
 * Se avisa desde el primer día, pero solo sube a prioridad alta a los tres:
 * un conteo grande se hace en varias jornadas y no hay que darle la lata a
 * quien lo está haciendo bien.
 */
function notif_gen_conteos(): void
{
    $rows = qAll(
        "SELECT c.id, c.numero, c.sucursal_id AS sid, su.nombre AS sucursal, c.created_at,
                COUNT(d.id) AS lineas,
                COALESCE(SUM(d.stock_contado IS NOT NULL), 0) AS contadas,
                COALESCE(SUM(d.stock_contado IS NOT NULL
                             AND ABS(d.stock_contado - d.stock_teorico) > 0.0001), 0) AS con_diferencia
           FROM conteos c
           JOIN sucursales su ON su.id = c.sucursal_id
           LEFT JOIN conteo_detalles d ON d.conteo_id = c.id
          WHERE c.estado = 'abierto'
          GROUP BY c.id, c.numero, c.sucursal_id, su.nombre, c.created_at"
    );
    $items = [];
    foreach ($rows as $r) {
        $dias   = max(0, (int) floor((time() - strtotime((string) $r['created_at'])) / 86400));
        $faltan = (int) $r['lineas'] - (int) $r['contadas'];
        $dif    = (int) $r['con_diferencia'];
        $items[] = [
            'clave' => 'conteo_abierto:' . (int) $r['id'], 'categoria' => 'inventario',
            'prioridad' => $dias >= 3 ? 'alta' : 'media',
            'titulo' => 'Conteo ' . $r['numero'] . ' sin aplicar',
            'mensaje' => $r['sucursal'] . ' · '
                . ($faltan > 0 ? $faltan . ' artículo(s) sin contar' : 'contado entero')
                . ($dif > 0 ? ' · ' . $dif . ' con diferencia' : ' · sin diferencias')
                . ($dias > 0 ? ' · lleva ' . $dias . ' día(s) abierto' : '')
                . '. Mientras no se aplique, el inventario no se corrige.',
            'url' => 'modules/inventario/conteo.php?id=' . (int) $r['id'],
            'icono' => 'clipboard', 'color' => $dias >= 3 ? 'amber' : 'sky',
            'sucursal_id' => (int) $r['sid'], 'permiso' => 'conteos.ver',
        ];
    }
    notif_sync('conteo_abierto', $items);
}

/** Tareas del CRM vencidas, dirigidas al responsable. */
function notif_gen_crm(): void
{
    $rows = qAll(
        "SELECT t.asignado_a, t.sucursal_id, COUNT(*) AS n, MIN(t.vence_at) AS mas_vieja
           FROM crm_tareas t
          WHERE t.estado = 'pendiente' AND t.vence_at IS NOT NULL AND t.vence_at < NOW()
          GROUP BY t.asignado_a, t.sucursal_id"
    );
    $items = [];
    foreach ($rows as $r) {
        $n   = (int) $r['n'];
        $uid = $r['asignado_a'] !== null ? (int) $r['asignado_a'] : null;
        $items[] = [
            'clave' => 'crm_tarea_vencida:' . ($uid ?? 'sin') . ':' . (int) $r['sucursal_id'],
            'categoria' => 'crm', 'prioridad' => 'alta',
            'titulo' => $n . ' tarea' . ($n === 1 ? '' : 's') . ' de seguimiento vencida' . ($n === 1 ? '' : 's'),
            'mensaje' => ($uid ? 'Asignadas a ti' : 'Sin responsable asignado')
                . '. La más atrasada venció el ' . fechaCorta($r['mas_vieja']) . '.',
            'url' => 'modules/crm/tareas.php?estado=pendiente', 'icono' => 'clock', 'color' => 'violet',
            'sucursal_id' => (int) $r['sucursal_id'], 'usuario_id' => $uid, 'permiso' => 'crm.ver',
        ];
    }
    notif_sync('crm_tarea_vencida', $items);
}

/** Pedidos de la tienda en línea sin atender. */
function notif_gen_pedidos(): void
{
    $rows = qAll(
        "SELECT p.sucursal_id, su.nombre AS sucursal, COUNT(*) AS n
           FROM pedidos p JOIN sucursales su ON su.id = p.sucursal_id
          WHERE p.estado = 'pendiente'
          GROUP BY p.sucursal_id"
    );
    $items = [];
    foreach ($rows as $r) {
        $n = (int) $r['n'];
        $items[] = [
            'clave' => 'pedido_pendiente:' . (int) $r['sucursal_id'], 'categoria' => 'ventas',
            'prioridad' => 'alta',
            'titulo' => $n . ' pedido' . ($n === 1 ? '' : 's') . ' en línea por confirmar',
            'mensaje' => 'Pedidos esperando confirmación en ' . $r['sucursal'] . '.',
            'url' => 'modules/pos/pedidos.php?estado=pendiente', 'icono' => 'store', 'color' => 'cyan',
            'sucursal_id' => (int) $r['sucursal_id'], 'permiso' => 'pedidos.ver',
        ];
    }
    notif_sync('pedido_pendiente', $items);
}

/** Metas de venta en riesgo: el periodo avanza más rápido que el cumplimiento. */
function notif_gen_metas(): void
{
    $hoy   = date('Y-m-d');
    $metas = qAll(
        "SELECT m.*, CONCAT(u.nombre,' ',u.apellido) AS vendedor, su.nombre AS sucursal
           FROM metas_ventas m
           LEFT JOIN usuarios u   ON u.id = m.usuario_id
           LEFT JOIN sucursales su ON su.id = m.sucursal_id
          WHERE m.estado = 'activa' AND ? BETWEEN m.periodo_inicio AND m.periodo_fin",
        [$hoy]
    );
    $items = [];
    foreach ($metas as $m) {
        $ini   = strtotime($m['periodo_inicio']);
        $fin   = strtotime($m['periodo_fin']);
        $total = max(1, ($fin - $ini) / 86400 + 1);
        $trans = min($total, max(0, (strtotime($hoy) - $ini) / 86400 + 1));
        $pctTiempo = $trans / $total * 100;
        if ($pctTiempo < 50) continue;                 // muy temprano para juzgar

        $p = metaProgreso($m);
        if ($p['pct'] >= $pctTiempo - 10) continue;    // va a ritmo aceptable

        $quien = $m['vendedor'] ?: ($m['sucursal'] ?: 'Global');
        $items[] = [
            'clave' => 'meta_riesgo:' . (int) $m['id'], 'categoria' => 'ventas', 'prioridad' => 'media',
            'titulo' => 'Meta en riesgo: ' . $quien,
            'mensaje' => round($p['pct']) . '% cumplido con ' . round($pctTiempo) . '% del periodo transcurrido. Faltan '
                . money($p['falta']) . ' en ' . $p['dias_restantes'] . ' día' . ($p['dias_restantes'] === 1 ? '' : 's') . '.',
            'url' => 'modules/finanzas/metas.php', 'icono' => 'trending', 'color' => 'amber',
            'sucursal_id' => $m['sucursal_id'] !== null ? (int) $m['sucursal_id'] : null,
            'permiso' => 'metas.ver', 'referencia_tipo' => 'meta', 'referencia_id' => (int) $m['id'],
        ];
    }
    notif_sync('meta_riesgo', $items);
}

/** Cosas esperando la firma de alguien: comisiones, vacaciones, nóminas. */
function notif_gen_aprobaciones(): void
{
    $com = qOne("SELECT COUNT(*) n, COALESCE(SUM(monto),0) t FROM comisiones WHERE estado = 'pendiente'");
    $items = [];
    if ($com && (int) $com['n'] > 0) {
        $n = (int) $com['n'];
        $items[] = [
            'clave' => 'comision_pendiente', 'categoria' => 'finanzas', 'prioridad' => 'media',
            'titulo' => $n . ' comisión' . ($n === 1 ? '' : 'es') . ' por aprobar',
            'mensaje' => money($com['t']) . ' en comisiones de vendedores esperando aprobación.',
            'url' => 'modules/finanzas/comisiones.php', 'icono' => 'percent', 'color' => 'blue',
            'permiso' => 'comisiones.aprobar',
        ];
    }
    notif_sync('comision_pendiente', $items);

    $vac = (int) qVal("SELECT COUNT(*) FROM vacaciones WHERE estado = 'solicitada'");
    $items = [];
    if ($vac > 0) {
        $items[] = [
            'clave' => 'vacacion_pendiente', 'categoria' => 'rrhh', 'prioridad' => 'media',
            'titulo' => $vac . ' solicitud' . ($vac === 1 ? '' : 'es') . ' de vacaciones por aprobar',
            'mensaje' => 'Hay personal esperando respuesta a su solicitud.',
            'url' => 'modules/rrhh/vacaciones.php?estado=solicitada', 'icono' => 'sun', 'color' => 'cyan',
            'permiso' => 'rrhh_vacaciones.aprobar',
        ];
    }
    notif_sync('vacacion_pendiente', $items);

    $nom = qAll("SELECT id, descripcion, sucursal_id FROM nominas WHERE estado = 'borrador' ORDER BY id DESC LIMIT 10");
    $items = [];
    foreach ($nom as $n) {
        $items[] = [
            'clave' => 'nomina_borrador:' . (int) $n['id'], 'categoria' => 'rrhh', 'prioridad' => 'media',
            'titulo' => 'Nómina en borrador: ' . $n['descripcion'],
            'mensaje' => 'Está sin procesar. Procésala para generar los recibos y el gasto contable.',
            'url' => 'modules/rrhh/nomina.php', 'icono' => 'wallet', 'color' => 'amber',
            'sucursal_id' => $n['sucursal_id'] !== null ? (int) $n['sucursal_id'] : null,
            'permiso' => 'rrhh_nomina.procesar',
            'referencia_tipo' => 'nomina', 'referencia_id' => (int) $n['id'],
        ];
    }
    notif_sync('nomina_borrador', $items);
}

/** Recordatorios fiscales: 606/607 e IT-1 vencen el día 20 de cada mes. */
function notif_gen_fiscal(): void
{
    $dia = (int) date('j');
    $items = [];
    if ($dia <= 20) {
        $mes    = date('Y-m', strtotime('first day of last month'));
        $nombre = strftime_es($mes);
        $faltan = 20 - $dia;
        $hayMov = (int) qVal(
            "SELECT COUNT(*) FROM ventas WHERE estado <> 'anulada' AND DATE_FORMAT(fecha,'%Y-%m') = ?",
            [$mes]
        );
        if ($hayMov > 0) {
            $items[] = [
                'clave' => 'dgii_periodo:' . $mes, 'categoria' => 'fiscal',
                'prioridad' => $faltan <= 5 ? 'alta' : 'media',
                'titulo' => 'Declaración de ' . $nombre . ' pendiente',
                'mensaje' => 'Formatos 606/607/608 e IT-1 del periodo ' . $nombre . '. Vence el día 20 ('
                    . ($faltan === 0 ? 'hoy' : 'faltan ' . $faltan . ' día' . ($faltan === 1 ? '' : 's')) . ').',
                'url' => 'modules/finanzas/dgii.php?periodo=' . $mes, 'icono' => 'shield',
                'color' => $faltan <= 5 ? 'rose' : 'blue', 'permiso' => 'dgii.ver',
            ];
        }
    }
    notif_sync('dgii_periodo', $items);
}

/** Recordatorios de personal: cumpleaños y contratos temporales por vencer. */
/**
 * El calendario de quien lleva la nómina y la TSS.
 *
 * Cuatro cosas que hoy solo se saben si alguien se acuerda de mirar, y que
 * cuando se olvidan cuestan dinero o una multa:
 *
 *   · Los topes de cotización de la Ley 87-01 siguen apagados porque falta el
 *     salario mínimo cotizable. Mientras tanto, un sueldo alto cotiza completo.
 *   · La regalía pascual vence el 20 de diciembre y no se ha generado.
 *   · Cerró un mes con nómina confirmada y su autodeterminación está lista.
 *   · Hay cédulas que no pasan el dígito verificador: ante la TSS esa persona
 *     no cotiza.
 */
function notif_gen_nomina_calendario(): void
{
    $items = [];
    $hoy   = date('Y-m-d');

    /* ---------- 1) Topes de la TSS sin encender ---------- */
    if (function_exists('tssParametros')) {
        $p = tssParametros();
        if ($p !== null && !tssTopesActivos($p)) {
            $alto = (float) qVal("SELECT COALESCE(MAX(salario),0) FROM empleados WHERE estado = 'activo'");
            $items[] = [
                'clave' => 'tss_sin_topes', 'categoria' => 'rrhh', 'prioridad' => 'media',
                'titulo' => 'Los topes de cotización de la TSS siguen apagados',
                'mensaje' => (float) $p['salario_minimo_cotizable'] <= 0
                    ? 'Falta el salario mínimo cotizable vigente: sin él no se pueden aplicar los topes de la '
                      . 'Ley 87-01 y el sueldo más alto del padrón (' . money($alto) . ') cotiza completo. '
                      . 'Lo confirma el contador.'
                    : 'Están configurados pero desactivados. Míralos antes de la próxima nómina.',
                'url' => 'modules/rrhh/tss.php?tab=parametros',
                'icono' => 'shield', 'color' => 'amber', 'permiso' => 'tss.configurar',
            ];
        }
    }

    /* ---------- 2) Regalía pascual ---------- */
    if (function_exists('regaliaNominaDelAnio')) {
        $anio  = (int) date('Y');
        $tope  = regaliaFechaTope($anio);
        $faltan = (int) floor((strtotime($tope) - strtotime($hoy)) / 86400);
        // Se avisa desde 75 días antes: generarla necesita revisar 57 fichas y
        // cuadrar el devengado del año, no es cosa de la víspera.
        if ($faltan <= 75) {
            $ya = regaliaNominaDelAnio($anio);
            if (!$ya || $ya['estado'] === 'borrador') {
                $items[] = [
                    'clave' => 'regalia_pendiente:' . $anio, 'categoria' => 'rrhh',
                    'prioridad' => $faltan <= 15 ? 'critica' : ($faltan <= 40 ? 'alta' : 'media'),
                    'titulo' => $faltan < 0
                        ? 'La regalía pascual ' . $anio . ' venció el 20 de diciembre'
                        : 'Regalía pascual ' . $anio . ': quedan ' . $faltan . ' día(s)',
                    'mensaje' => ($ya
                        ? 'Está generada como borrador por ' . money($ya['total_neto']) . ', pero sin confirmar ni pagar. '
                        : 'Todavía no se ha generado. ')
                        . 'El art. 219 obliga a pagarla a más tardar el 20 de diciembre.',
                    'url' => 'modules/rrhh/regalia.php?anio=' . $anio,
                    'icono' => 'sun', 'color' => $faltan <= 15 ? 'rose' : 'amber',
                    'permiso' => 'rrhh_nomina.ver',
                ];
            }
        }
    }

    /* ---------- 3) La TSS del mes que cerró ---------- */
    // Solo se avisa si ese mes tuvo nómina confirmada: sin nómina no hay nada
    // que declarar y el aviso sería ruido.
    $mesAnterior = date('Y-m-01', strtotime('first day of last month'));
    $finAnterior = date('Y-m-t', strtotime($mesAnterior));
    $conNomina = (int) qVal(
        "SELECT COUNT(*) FROM nominas
          WHERE estado IN ('procesada','pagada') AND tipo <> 'regalia'
            AND fecha_hasta BETWEEN ? AND ?", [$mesAnterior, $finAnterior]);
    if ($conNomina > 0) {
        $dia = (int) date('j');
        $items[] = [
            'clave' => 'tss_mes:' . substr($mesAnterior, 0, 7), 'categoria' => 'rrhh',
            'prioridad' => $dia >= 10 ? 'alta' : 'media',
            'titulo' => 'TSS de ' . mesNombre((int) substr($mesAnterior, 5, 2)) . ' ' . substr($mesAnterior, 0, 4),
            'mensaje' => 'El mes cerró con ' . $conNomina . ' nómina(s) confirmada(s) y su autodeterminación '
                . 'ya se puede sacar: cédula, base cotizable por régimen y aportes de las dos partes.',
            'url' => 'modules/rrhh/tss.php?tab=declaracion&mes=' . substr($mesAnterior, 0, 7),
            'icono' => 'shield', 'color' => $dia >= 10 ? 'rose' : 'sky',
            'permiso' => 'tss.ver',
        ];
    }

    /* ---------- 4) Cédulas que no cuadran ---------- */
    if (function_exists('dgiiDocumentoValido')) {
        $malas = [];
        foreach (qAll("SELECT id, nombre, apellido, cedula FROM empleados
                        WHERE estado = 'activo' AND cedula IS NOT NULL AND cedula <> ''") as $e) {
            if (!dgiiDocumentoValido((string) $e['cedula'])) $malas[] = $e;
        }
        if ($malas) {
            $nombres = array_slice(array_map(fn($x) => trim($x['nombre'] . ' ' . $x['apellido']), $malas), 0, 3);
            $items[] = [
                'clave' => 'cedulas_invalidas', 'categoria' => 'rrhh', 'prioridad' => 'alta',
                'titulo' => count($malas) . ' cédula(s) no pasan el dígito verificador',
                'mensaje' => 'En la TSS la cédula identifica a la persona: si no cuadra, ese empleado se queda '
                    . 'sin cotizar. ' . implode(', ', $nombres)
                    . (count($malas) > 3 ? ' y ' . (count($malas) - 3) . ' más' : '') . '.',
                'url' => 'modules/rrhh/empleados.php',
                'icono' => 'id', 'color' => 'rose', 'permiso' => 'rrhh_empleados.ver',
            ];
        }
    }

    /* ---------- 5) Fechas de ingreso que son un marcador de carga ---------- */
    if (function_exists('regaliaIngresosSospechosos')) {
        foreach (regaliaIngresosSospechosos((int) date('Y')) as $s) {
            $items[] = [
                'clave' => 'ingreso_marcador:' . $s['fecha'], 'categoria' => 'rrhh', 'prioridad' => 'media',
                'titulo' => $s['empleados'] . ' empleados con la misma fecha de ingreso',
                'mensaje' => 'Todos figuran ingresando el ' . fechaCorta($s['fecha']) . ', que casi seguro es la '
                    . 'fecha con la que se cargó el padrón. De ella salen la antigüedad, la regalía, las '
                    . 'vacaciones y las prestaciones: mientras no se corrija, esos cálculos salen cortos.',
                'url' => 'modules/rrhh/empleados.php',
                'icono' => 'calendar', 'color' => 'amber', 'permiso' => 'rrhh_empleados.ver',
            ];
        }
    }

    notif_sync('nomina_calendario', $items);
}

function notif_gen_rrhh(): void
{
    $cumple = qAll(
        "SELECT id, nombre, apellido, sucursal_id FROM empleados
          WHERE estado = 'activo' AND fecha_nacimiento IS NOT NULL
            AND DATE_FORMAT(fecha_nacimiento,'%m-%d') = DATE_FORMAT(CURDATE(),'%m-%d')"
    );
    $items = [];
    foreach ($cumple as $c) {
        $items[] = [
            'clave' => 'cumpleanos:' . (int) $c['id'] . ':' . date('Y'), 'categoria' => 'rrhh',
            'prioridad' => 'baja',
            'titulo' => 'Hoy cumple años ' . $c['nombre'] . ' ' . $c['apellido'],
            'mensaje' => 'Un detalle del equipo siempre cae bien.',
            'url' => 'modules/rrhh/empleados.php', 'icono' => 'sun', 'color' => 'violet',
            'sucursal_id' => $c['sucursal_id'] !== null ? (int) $c['sucursal_id'] : null,
            'permiso' => 'rrhh_empleados.ver',
            'referencia_tipo' => 'empleado', 'referencia_id' => (int) $c['id'],
        ];
    }
    notif_sync('cumpleanos', $items);
}

/** Productos que se venden por debajo del costo: fuga silenciosa de utilidad. */
function notif_gen_margenes(): void
{
    $r = qOne(
        "SELECT COUNT(*) n FROM productos
          WHERE activo = 1 AND precio_compra > 0 AND precio_venta > 0 AND precio_venta <= precio_compra"
    );
    $items = [];
    if ($r && (int) $r['n'] > 0) {
        $n = (int) $r['n'];
        $items[] = [
            'clave' => 'margen_negativo', 'categoria' => 'finanzas', 'prioridad' => 'media',
            'titulo' => $n . ' producto' . ($n === 1 ? '' : 's') . ' con precio bajo el costo',
            'mensaje' => 'Se están vendiendo sin margen o con pérdida. Revisa la lista de precios.',
            'url' => 'modules/reportes/rentabilidad.php', 'icono' => 'trending', 'color' => 'rose',
            'permiso' => 'productos.ver',
        ];
    }
    notif_sync('margen_negativo', $items);
}

/** Nombre del mes en español a partir de un 'YYYY-MM'. */
function strftime_es(string $ym): string
{
    $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
              'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $t = strtotime($ym . '-01');
    return ($meses[(int) date('n', $t)] ?? '') . ' ' . date('Y', $t);
}
