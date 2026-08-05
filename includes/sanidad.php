<?php
/**
 * Cumplimiento sanitario y trazabilidad por lote.
 *
 * Importers TyE recibe inspecciones de Salud Pública (MSP / DIGEMAPS),
 * PROCONSUMIDOR, Ministerio de Agricultura e INDOCAL. Ninguna de esas entidades
 * publica un formato de archivo como los 606/607 de la DGII: la inspección es
 * DOCUMENTAL y se responde en el momento. Este módulo sostiene las tres
 * preguntas que siempre aparecen:
 *
 *   1. ¿Este producto tiene registro sanitario y está vigente?
 *   2. ¿Hay mercancía vencida a la venta?
 *   3. Si un lote sale malo, ¿a quién se le vendió?
 *
 * DÓNDE VIVE LA EXISTENCIA. `inventario_stock` sigue siendo la verdad para
 * vender y para todos los reportes de siempre. `lotes` DESGLOSA esa existencia
 * en los productos marcados con `controla_lote`. Las dos se mueven dentro de la
 * misma transacción desde `ajustarStock()`, así que no pueden separarse; aun
 * así, `san_descuadres()` las compara, porque un descuadre silencioso en un
 * control sanitario es peor que uno ruidoso.
 *
 * FEFO, no FIFO. Se despacha primero lo que ANTES vence, no lo que antes entró.
 * Es lo correcto con fecha de caducidad: un lote que entró después puede vencer
 * primero, y sacarlo más tarde garantiza que se dañe en el almacén.
 */

/** Días de antelación con que se avisa de un vencimiento. */
const SAN_DIAS_AVISO_LOTE     = 90;   // mercancía por vencer
const SAN_DIAS_AVISO_REGISTRO = 120;  // un registro sanitario tarda meses en renovarse

/** Código del lote donde cae la existencia sin identificar. */
const SAN_LOTE_SIN_IDENTIFICAR = 'SIN-LOTE';

/** Categorías sanitarias que maneja la empresa. */
function san_categorias(): array
{
    return [
        'cosmetico'  => 'Cosmético',
        'higiene'    => 'Higiene personal',
        'suplemento' => 'Suplemento alimenticio',
        'natural'    => 'Producto natural',
        'limpieza'   => 'Limpieza del hogar',
        'quimico'    => 'Químico de uso doméstico',
        'otro'       => 'Otro',
    ];
}

/** Entidades que emiten o fiscalizan. */
function san_entidades(): array
{
    return [
        'DIGEMAPS'     => 'DIGEMAPS · Salud Pública',
        'MSP'          => 'Ministerio de Salud Pública',
        'AGRICULTURA'  => 'Ministerio de Agricultura',
        'INDOCAL'      => 'INDOCAL · normas de calidad',
        'PROCONSUMIDOR'=> 'PROCONSUMIDOR',
        'OTRA'         => 'Otra entidad',
    ];
}

/* ============================================================
 *  Vigencia del registro sanitario
 * ============================================================ */

/**
 * Estado del registro sanitario de un producto.
 * Devuelve ['estado','etiqueta','color','dias'] donde `dias` es lo que falta
 * para vencer (negativo si ya venció).
 *
 * Un registro SIN fecha de vencimiento se considera vigente: hay resoluciones
 * que no la llevan, y marcarlas en rojo entrenaría al usuario a ignorar la
 * alerta que sí importa.
 */
function san_estado_registro(array $p): array
{
    if (empty($p['regulado'])) {
        return ['estado' => 'no_aplica', 'etiqueta' => 'No regulado', 'color' => 'slate', 'dias' => null];
    }
    if (trim((string) ($p['registro_sanitario'] ?? '')) === '') {
        return ['estado' => 'sin_registro', 'etiqueta' => 'Sin registro', 'color' => 'rose', 'dias' => null];
    }
    $venc = $p['registro_vencimiento'] ?? null;
    if (!$venc) {
        return ['estado' => 'vigente', 'etiqueta' => 'Vigente (sin fecha)', 'color' => 'emerald', 'dias' => null];
    }
    $dias = san_dias_hasta($venc);
    if ($dias < 0)  return ['estado' => 'vencido',    'etiqueta' => 'Vencido hace ' . abs($dias) . ' d.', 'color' => 'rose',    'dias' => $dias];
    if ($dias <= SAN_DIAS_AVISO_REGISTRO)
        return ['estado' => 'por_vencer', 'etiqueta' => 'Vence en ' . $dias . ' d.', 'color' => 'amber', 'dias' => $dias];
    return ['estado' => 'vigente', 'etiqueta' => 'Vigente', 'color' => 'emerald', 'dias' => $dias];
}

/** Días desde hoy hasta una fecha (negativo si ya pasó). Sin horas de por medio. */
function san_dias_hasta(?string $fecha): int
{
    if (!$fecha) return PHP_INT_MAX;
    $hoy = new DateTimeImmutable('today');
    $f   = new DateTimeImmutable(substr($fecha, 0, 10));
    return (int) $hoy->diff($f)->format('%r%a');
}

/** Estado de vencimiento de un lote, con el mismo vocabulario que el registro. */
function san_estado_lote(array $l): array
{
    if (!empty($l['bloqueado'])) {
        return ['estado' => 'bloqueado', 'etiqueta' => 'Bloqueado', 'color' => 'violet', 'dias' => null];
    }
    if (empty($l['fecha_vencimiento'])) {
        return ['estado' => 'sin_fecha', 'etiqueta' => 'Sin fecha', 'color' => 'slate', 'dias' => null];
    }
    $dias = san_dias_hasta($l['fecha_vencimiento']);
    if ($dias < 0)  return ['estado' => 'vencido',    'etiqueta' => 'Vencido hace ' . abs($dias) . ' d.', 'color' => 'rose',  'dias' => $dias];
    if ($dias <= SAN_DIAS_AVISO_LOTE)
        return ['estado' => 'por_vencer', 'etiqueta' => 'Vence en ' . $dias . ' d.', 'color' => 'amber', 'dias' => $dias];
    return ['estado' => 'vigente', 'etiqueta' => 'Vigente', 'color' => 'emerald', 'dias' => $dias];
}

/* ============================================================
 *  Lotes — movimiento de existencias
 * ============================================================ */

/** ¿Este producto exige lote? Se cachea: `ajustarStock` lo pregunta en bucle. */
function san_controla_lote(int $productoId): bool
{
    static $cache = [];
    if (!array_key_exists($productoId, $cache)) {
        $cache[$productoId] = (bool) qVal("SELECT controla_lote FROM productos WHERE id = ?", [$productoId]);
    }
    return $cache[$productoId];
}

/** Limpia la caché anterior (tras cambiar la ficha de un producto). */
function san_olvidar_cache(): void
{
    // La caché es estática dentro de la función; recargar la página la vacía.
    // Este hueco existe para dejarlo explícito en el código que lo necesite.
}

/**
 * Localiza (o crea) un lote y lo deja BLOQUEADO para actualizar.
 * Debe llamarse dentro de una transacción.
 */
function san_lote_para_actualizar(int $productoId, int $sucursalId, string $codigo, array $datos = []): array
{
    $codigo = trim($codigo) !== '' ? trim($codigo) : SAN_LOTE_SIN_IDENTIFICAR;

    // Se garantiza la fila antes de bloquearla: si no, dos entradas simultáneas
    // del mismo lote intentarían insertarla las dos y una moriría contra el
    // UNIQUE (mismo patrón que `ajustarStock` con inventario_stock).
    q(
        "INSERT INTO lotes (producto_id, sucursal_id, codigo, fecha_vencimiento, fecha_fabricacion,
                            cantidad, costo_unitario, proveedor_id, compra_id, registro_sanitario)
         VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)",
        [
            $productoId, $sucursalId, $codigo,
            $datos['fecha_vencimiento'] ?? null,
            $datos['fecha_fabricacion'] ?? null,
            (float) ($datos['costo_unitario'] ?? 0),
            $datos['proveedor_id'] ?? null,
            $datos['compra_id'] ?? null,
            $datos['registro_sanitario'] ?? null,
        ]
    );

    $lote = qOne("SELECT * FROM lotes WHERE producto_id = ? AND sucursal_id = ? AND codigo = ? FOR UPDATE",
        [$productoId, $sucursalId, $codigo]);
    if (!$lote) throw new RuntimeException('No se pudo reservar el lote ' . $codigo . '.');

    // Si el lote ya existía sin fecha y ahora llega con una, se completa. Nunca
    // se PISA una fecha existente: cambiar la caducidad de mercancía ya recibida
    // es exactamente lo que una auditoría busca.
    if (empty($lote['fecha_vencimiento']) && !empty($datos['fecha_vencimiento'])) {
        dbUpdate('lotes', ['fecha_vencimiento' => $datos['fecha_vencimiento']], 'id = ?', [(int) $lote['id']]);
        $lote['fecha_vencimiento'] = $datos['fecha_vencimiento'];
    }
    return $lote;
}

/** Anota un movimiento en el libro de trazabilidad y actualiza el saldo del lote. */
function san_mover_lote(array $lote, float $delta, string $tipo, ?string $refTipo, ?int $refId, string $motivo): float
{
    $anterior = (float) $lote['cantidad'];
    $nuevo    = round($anterior + $delta, 3);
    if ($nuevo < -0.0001) {
        throw new RuntimeException('El lote ' . $lote['codigo'] . ' no tiene esa existencia.');
    }
    $nuevo = max(0, $nuevo);

    q("UPDATE lotes SET cantidad = ? WHERE id = ?", [$nuevo, (int) $lote['id']]);

    dbInsert('lote_movimientos', [
        'lote_id'        => (int) $lote['id'],
        'producto_id'    => (int) $lote['producto_id'],
        'sucursal_id'    => (int) $lote['sucursal_id'],
        'tipo'           => $tipo,
        'cantidad'       => round($delta, 3),
        'saldo_anterior' => $anterior,
        'saldo_nuevo'    => $nuevo,
        'referencia_tipo'=> $refTipo,
        'referencia_id'  => $refId,
        'motivo'         => $motivo !== '' ? mb_substr($motivo, 0, 255) : null,
        'usuario_id'     => current_user()['id'] ?? null,
        'created_at'     => date('Y-m-d H:i:s'),
    ]);
    return $nuevo;
}

/**
 * Reparte un movimiento de stock entre los lotes del producto.
 * La llama `ajustarStock()`; si el producto no controla lote, no hace nada.
 *
 * $lote acepta:
 *   null                      → entrada sin identificar / salida FEFO
 *   ['codigo'=>…, 'fecha_vencimiento'=>…, …]
 *   'CODIGO'                  (cadena)
 */
function san_aplicar_lote(int $productoId, int $sucursalId, float $delta, string $tipo,
                          ?string $refTipo, ?int $refId, float $costo, string $motivo, $lote = null): void
{
    if (abs($delta) < 0.0001) return;
    // Si el código se desplegara antes que la migración, el sistema sigue
    // vendiendo exactamente como antes en vez de romperse en la caja.
    if (!san_disponible()) return;
    if (!san_controla_lote($productoId)) return;

    $datos = is_array($lote) ? $lote : (is_string($lote) && trim($lote) !== '' ? ['codigo' => $lote] : []);
    if ($costo > 0 && empty($datos['costo_unitario'])) $datos['costo_unitario'] = $costo;

    /* ---------- Entrada ---------- */
    if ($delta > 0) {
        $codigo = trim((string) ($datos['codigo'] ?? '')) ?: SAN_LOTE_SIN_IDENTIFICAR;
        $l = san_lote_para_actualizar($productoId, $sucursalId, $codigo, $datos);
        san_mover_lote($l, $delta, $tipo, $refTipo, $refId, $motivo);
        return;
    }

    /* ---------- Salida ---------- */
    $pendiente = abs($delta);

    // Salida contra un lote concreto (devolución de una venta trazada, baja de
    // mercancía vencida): se respeta el lote que indica quien llama.
    if (!empty($datos['codigo'])) {
        $l = san_lote_para_actualizar($productoId, $sucursalId, (string) $datos['codigo'], $datos);
        san_mover_lote($l, -$pendiente, $tipo, $refTipo, $refId, $motivo);
        return;
    }

    // FEFO. Una baja SÍ puede tocar lotes vencidos o bloqueados: precisamente
    // sirve para sacarlos del inventario. Cualquier otra salida, no.
    $incluirNoAptos = in_array($tipo, ['baja', 'ajuste'], true);
    $cond = $incluirNoAptos ? '' : ' AND bloqueado = 0 AND (fecha_vencimiento IS NULL OR fecha_vencimiento >= CURDATE())';

    $candidatos = qAll(
        "SELECT * FROM lotes
          WHERE producto_id = ? AND sucursal_id = ? AND cantidad > 0 $cond
          ORDER BY (fecha_vencimiento IS NULL), fecha_vencimiento ASC, id ASC
          FOR UPDATE",
        [$productoId, $sucursalId]
    );

    $disponible = array_sum(array_map(fn($l) => (float) $l['cantidad'], $candidatos));
    if ($disponible + 0.0001 < $pendiente) {
        $nombre = qVal("SELECT nombre FROM productos WHERE id = ?", [$productoId]) ?: 'el producto';
        throw new RuntimeException(
            'No hay existencia apta de «' . $nombre . '»: se piden ' . qty($pendiente)
            . ' y solo hay ' . qty($disponible) . ' en lotes vigentes y no bloqueados. '
            . 'Revisa los lotes vencidos o bloqueados en Cumplimiento sanitario.'
        );
    }

    foreach ($candidatos as $l) {
        if ($pendiente <= 0.0001) break;
        $toma = min((float) $l['cantidad'], $pendiente);
        san_mover_lote($l, -$toma, $tipo, $refTipo, $refId, $motivo);
        $pendiente = round($pendiente - $toma, 3);
    }
}

/* ============================================================
 *  Activar el control de lote sobre un producto que ya tiene stock
 * ============================================================ */

/**
 * Al marcar `controla_lote`, la existencia que ya había no pertenece a ningún
 * lote. Se deposita en un lote SIN-LOTE por sucursal para que la venta siga
 * funcionando desde el minuto uno, y los reportes la señalan como pendiente de
 * identificar. La alternativa —bloquear la venta hasta que alguien capture los
 * lotes— dejaría la tienda sin poder vender.
 */
function san_sembrar_lote_inicial(int $productoId): int
{
    $filas = qAll(
        "SELECT sucursal_id, cantidad FROM inventario_stock WHERE producto_id = ? AND cantidad > 0 ORDER BY sucursal_id",
        [$productoId]
    );
    $n = 0;
    foreach ($filas as $f) {
        $sid = (int) $f['sucursal_id'];
        $yaEnLotes = (float) qVal("SELECT COALESCE(SUM(cantidad),0) FROM lotes WHERE producto_id = ? AND sucursal_id = ?", [$productoId, $sid]);
        $falta = round((float) $f['cantidad'] - $yaEnLotes, 3);
        if ($falta <= 0.0001) continue;

        $l = san_lote_para_actualizar($productoId, $sid, SAN_LOTE_SIN_IDENTIFICAR, []);
        san_mover_lote($l, $falta, 'ajuste', 'sanidad', null,
            'Existencia previa al control de lote · pendiente de identificar');
        $n++;
    }
    return $n;
}

/* ============================================================
 *  Consultas para pantallas y reportes
 * ============================================================ */

/** Lotes con existencia, con su estado, filtrables. */
function san_lotes(array $f = []): array
{
    $cond = ['1=1'];
    $par  = [];
    if (!empty($f['sucursal_id'])) { $cond[] = 'l.sucursal_id = ?'; $par[] = (int) $f['sucursal_id']; }
    if (!empty($f['producto_id'])) { $cond[] = 'l.producto_id = ?'; $par[] = (int) $f['producto_id']; }
    if (!empty($f['codigo']))      { $cond[] = 'l.codigo LIKE ?';   $par[] = '%' . $f['codigo'] . '%'; }
    if (!empty($f['con_stock']))   { $cond[] = 'l.cantidad > 0'; }
    if (!empty($f['estado'])) {
        if ($f['estado'] === 'vencido')    $cond[] = 'l.fecha_vencimiento IS NOT NULL AND l.fecha_vencimiento < CURDATE()';
        if ($f['estado'] === 'por_vencer') $cond[] = 'l.fecha_vencimiento IS NOT NULL AND l.fecha_vencimiento >= CURDATE() AND l.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL ' . SAN_DIAS_AVISO_LOTE . ' DAY)';
        if ($f['estado'] === 'bloqueado')  $cond[] = 'l.bloqueado = 1';
        if ($f['estado'] === 'sin_fecha')  $cond[] = 'l.fecha_vencimiento IS NULL';
        if ($f['estado'] === 'sin_lote')   $cond[] = "l.codigo = '" . SAN_LOTE_SIN_IDENTIFICAR . "'";
    }
    $where = implode(' AND ', $cond);
    $limite = (int) ($f['limite'] ?? 500);

    return qAll(
        "SELECT l.*, p.nombre AS producto, p.codigo AS sku, p.registro_sanitario AS registro_producto,
                p.registro_vencimiento, p.registro_categoria, p.regulado,
                s.nombre AS sucursal, pr.nombre AS proveedor, c.numero AS compra_numero,
                u.abreviatura AS unidad
           FROM lotes l
           JOIN productos p   ON p.id = l.producto_id
           JOIN sucursales s  ON s.id = l.sucursal_id
           LEFT JOIN proveedores pr ON pr.id = l.proveedor_id
           LEFT JOIN compras c      ON c.id = l.compra_id
           LEFT JOIN unidades u     ON u.id = p.unidad_id
          WHERE $where
          ORDER BY (l.fecha_vencimiento IS NULL), l.fecha_vencimiento ASC, p.nombre
          LIMIT $limite",
        $par
    );
}

/**
 * Trazabilidad de un lote: a qué clientes salió.
 * Es la respuesta a la pregunta del inspector en un retiro del mercado.
 */
function san_trazabilidad(int $loteId): array
{
    $lote = qOne(
        "SELECT l.*, p.nombre AS producto, p.codigo AS sku, p.registro_sanitario,
                s.nombre AS sucursal, pr.nombre AS proveedor, pr.rnc AS proveedor_rnc,
                c.numero AS compra_numero, c.fecha AS compra_fecha
           FROM lotes l
           JOIN productos p  ON p.id = l.producto_id
           JOIN sucursales s ON s.id = l.sucursal_id
           LEFT JOIN proveedores pr ON pr.id = l.proveedor_id
           LEFT JOIN compras c      ON c.id = l.compra_id
          WHERE l.id = ?",
        [$loteId]
    );
    if (!$lote) return [];

    // Salidas a venta, con el cliente detrás. El JOIN a ventas es el que
    // convierte un movimiento de almacén en «a quién se lo vendimos».
    $ventas = qAll(
        "SELECT lm.cantidad, lm.created_at, v.id AS venta_id, v.numero, v.fecha, v.ncf,
                cl.nombre AS cliente, cl.telefono, cl.email, cl.rnc_cedula,
                su.nombre AS sucursal
           FROM lote_movimientos lm
           JOIN ventas v     ON v.id = lm.referencia_id AND lm.referencia_tipo = 'venta'
           LEFT JOIN clientes cl ON cl.id = v.cliente_id
           LEFT JOIN sucursales su ON su.id = v.sucursal_id
          WHERE lm.lote_id = ? AND lm.cantidad < 0
          ORDER BY v.fecha DESC",
        [$loteId]
    );

    $movimientos = qAll(
        "SELECT lm.*, CONCAT(u.nombre,' ',u.apellido) AS usuario
           FROM lote_movimientos lm
           LEFT JOIN usuarios u ON u.id = lm.usuario_id
          WHERE lm.lote_id = ? ORDER BY lm.id DESC LIMIT 300",
        [$loteId]
    );

    $vendido = 0.0;
    foreach ($ventas as $v) $vendido += abs((float) $v['cantidad']);

    return [
        'lote'        => $lote,
        'ventas'      => $ventas,
        'movimientos' => $movimientos,
        'vendido'     => $vendido,
        'en_stock'    => (float) $lote['cantidad'],
        'clientes'    => count(array_unique(array_filter(array_column($ventas, 'cliente')))),
    ];
}

/**
 * Descuadres entre `inventario_stock` y la suma de lotes.
 * No debería haber ninguno: las dos se mueven en la misma transacción. Se
 * comprueba igual porque un control sanitario que miente es peor que no tenerlo.
 */
function san_descuadres(): array
{
    return qAll(
        "SELECT p.id, p.codigo, p.nombre, s.nombre AS sucursal, i.sucursal_id,
                i.cantidad AS stock, COALESCE(SUM(l.cantidad),0) AS en_lotes,
                i.cantidad - COALESCE(SUM(l.cantidad),0) AS diferencia
           FROM productos p
           JOIN inventario_stock i ON i.producto_id = p.id
           JOIN sucursales s       ON s.id = i.sucursal_id
           LEFT JOIN lotes l       ON l.producto_id = p.id AND l.sucursal_id = i.sucursal_id
          WHERE p.controla_lote = 1
          GROUP BY p.id, p.codigo, p.nombre, s.nombre, i.sucursal_id, i.cantidad
         HAVING ABS(i.cantidad - COALESCE(SUM(l.cantidad),0)) > 0.0001
          ORDER BY p.nombre"
    );
}

/** Resumen para el panel y el expediente de auditoría. */
function san_resumen(): array
{
    $r = qOne(
        "SELECT
            (SELECT COUNT(*) FROM productos WHERE regulado = 1 AND activo = 1) AS regulados,
            (SELECT COUNT(*) FROM productos WHERE regulado = 1 AND activo = 1
               AND (registro_sanitario IS NULL OR registro_sanitario = '')) AS sin_registro,
            (SELECT COUNT(*) FROM productos WHERE regulado = 1 AND activo = 1
               AND registro_vencimiento IS NOT NULL AND registro_vencimiento < CURDATE()) AS registro_vencido,
            (SELECT COUNT(*) FROM productos WHERE regulado = 1 AND activo = 1
               AND registro_vencimiento IS NOT NULL AND registro_vencimiento >= CURDATE()
               AND registro_vencimiento <= DATE_ADD(CURDATE(), INTERVAL " . SAN_DIAS_AVISO_REGISTRO . " DAY)) AS registro_por_vencer,
            (SELECT COUNT(*) FROM lotes WHERE cantidad > 0
               AND fecha_vencimiento IS NOT NULL AND fecha_vencimiento < CURDATE()) AS lotes_vencidos,
            (SELECT COALESCE(SUM(cantidad * costo_unitario),0) FROM lotes WHERE cantidad > 0
               AND fecha_vencimiento IS NOT NULL AND fecha_vencimiento < CURDATE()) AS valor_vencido,
            (SELECT COUNT(*) FROM lotes WHERE cantidad > 0
               AND fecha_vencimiento IS NOT NULL AND fecha_vencimiento >= CURDATE()
               AND fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL " . SAN_DIAS_AVISO_LOTE . " DAY)) AS lotes_por_vencer,
            (SELECT COUNT(*) FROM lotes WHERE bloqueado = 1 AND cantidad > 0) AS lotes_bloqueados,
            (SELECT COUNT(*) FROM lotes WHERE cantidad > 0 AND codigo = '" . SAN_LOTE_SIN_IDENTIFICAR . "') AS sin_identificar,
            (SELECT COUNT(*) FROM proveedores WHERE activo = 1
               AND licencia_vencimiento IS NOT NULL AND licencia_vencimiento < CURDATE()) AS proveedor_licencia_vencida"
    ) ?: [];
    return array_map(fn($v) => is_numeric($v) ? $v + 0 : $v, $r);
}

/** ¿Está el módulo disponible? (el código puede desplegarse antes que la migración) */
function san_disponible(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = (bool) qVal("SELECT 1 FROM information_schema.TABLES
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lotes'");
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}
