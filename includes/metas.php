<?php
/**
 * Metas de venta / KPI.
 *
 * El progreso se DERIVA de las ventas reales; no se guarda un acumulado que
 * pueda desincronizarse. Una muestra no aporta (su total es 0), una venta
 * anulada no cuenta, y las devoluciones del período se restan: el número es la
 * venta NETA, que es lo que la cliente quiere ver.
 */

/**
 * Calcula el avance de una meta.
 * @return array{vendido:float,objetivo:float,pct:float,falta:float,dias_restantes:int,bruto:float,devuelto:float}
 */
function metaProgreso(array $meta): array
{
    $ini = $meta['periodo_inicio'] . ' 00:00:00';
    $fin = $meta['periodo_fin'] . ' 23:59:59';

    // Ventas del período dentro del alcance de la meta.
    $condV = ["v.estado NOT IN ('anulada','devuelta')", 'v.fecha BETWEEN ? AND ?'];
    $pV = [$ini, $fin];
    if (!empty($meta['sucursal_id'])) { $condV[] = 'v.sucursal_id = ?'; $pV[] = $meta['sucursal_id']; }
    if (!empty($meta['usuario_id']))  { $condV[] = 'v.usuario_id = ?';  $pV[] = $meta['usuario_id']; }
    $bruto = (float) qVal("SELECT COALESCE(SUM(v.total),0) FROM ventas v WHERE " . implode(' AND ', $condV), $pV);

    // Devoluciones del período en el mismo alcance (venta neta).
    $condD = ['d.created_at BETWEEN ? AND ?'];
    $pD = [$ini, $fin];
    $joinD = '';
    if (!empty($meta['sucursal_id'])) { $condD[] = 'd.sucursal_id = ?'; $pD[] = $meta['sucursal_id']; }
    if (!empty($meta['usuario_id']))  { $joinD = 'JOIN ventas v ON v.id = d.venta_id'; $condD[] = 'v.usuario_id = ?'; $pD[] = $meta['usuario_id']; }
    $devuelto = (float) qVal("SELECT COALESCE(SUM(d.total),0) FROM devoluciones d $joinD WHERE " . implode(' AND ', $condD), $pD);

    $vendido  = max(0.0, round($bruto - $devuelto, 2));
    $objetivo = (float) $meta['monto_objetivo'];
    $pct      = $objetivo > 0 ? min(100.0, round($vendido / $objetivo * 100, 1)) : 0.0;
    $falta    = max(0.0, round($objetivo - $vendido, 2));

    $hoy = date('Y-m-d');
    if ($hoy > $meta['periodo_fin'])       $dias = 0;
    elseif ($hoy < $meta['periodo_inicio']) $dias = (int) ((strtotime($meta['periodo_fin']) - strtotime($meta['periodo_inicio'])) / 86400) + 1;
    else                                    $dias = (int) ((strtotime($meta['periodo_fin']) - strtotime($hoy)) / 86400) + 1;

    return [
        'vendido' => $vendido, 'objetivo' => $objetivo, 'pct' => $pct,
        'falta' => $falta, 'dias_restantes' => $dias, 'bruto' => $bruto, 'devuelto' => $devuelto,
    ];
}

/** Meta personal activa del usuario que cubre hoy (o null). Para el banner del POS. */
function metaPersonalActiva(int $usuarioId): ?array
{
    $hoy = date('Y-m-d');
    return qOne(
        "SELECT * FROM metas_ventas
          WHERE usuario_id = ? AND estado = 'activa' AND ? BETWEEN periodo_inicio AND periodo_fin
          ORDER BY periodo_fin ASC LIMIT 1",
        [$usuarioId, $hoy]
    );
}

/** Color del progreso según el avance. */
function metaColor(float $pct): string
{
    if ($pct >= 100) return 'emerald';
    if ($pct >= 70)  return 'blue';
    if ($pct >= 40)  return 'amber';
    return 'rose';
}

/**
 * Canales de captación de una venta (medición de marketing). Fuente única para
 * el POS y los reportes. 'Tienda online' lo pone el sistema, no el cajero.
 */
function canalesVenta(): array
{
    return ['Mostrador', 'Instagram', 'WhatsApp', 'Facebook', 'Referido', 'Otro'];
}
