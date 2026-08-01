<?php
/**
 * Activos fijos y depreciación.
 *
 * Método contable: línea recta. La cuota mensual es
 *   (costo − valor residual) ÷ vida útil en meses
 * y se registra desde el mes SIGUIENTE al de adquisición (un activo comprado el
 * 20 de agosto no se desgastó durante agosto).
 *
 * La depreciación es un gasto REAL pero NO monetario: reduce la utilidad sin que
 * salga dinero de la caja. Por eso el asiento se registra sin cuenta asociada —
 * así entra al estado de resultados y no ensucia el flujo de efectivo.
 *
 * La categoría DGII (art. 287 del Código Tributario) se guarda aparte porque el
 * cálculo fiscal dominicano usa saldo decreciente sobre categorías, y difiere
 * del contable. Aquí se lleva el libro contable; el fiscal lo arma la contadora
 * con ese dato.
 */

/** Categorías fiscales dominicanas: [etiqueta, tasa anual, descripción]. */
function activoCategoriasDgii(): array
{
    return [
        1 => ['Categoría 1 · Edificaciones', 5.0, 'Edificios y construcciones'],
        2 => ['Categoría 2 · Vehículos y equipos', 25.0, 'Automóviles, equipos de oficina, computadoras'],
        3 => ['Categoría 3 · Otros bienes', 15.0, 'Mobiliario, maquinaria y cualquier otro bien'],
    ];
}

/** Tipos de activo para clasificar el registro. */
function activoTipos(): array
{
    return [
        'edificacion'   => 'Edificación / local',
        'mobiliario'    => 'Mobiliario',
        'equipo_oficina'=> 'Equipo de oficina',
        'computadora'   => 'Computadora / tecnología',
        'vehiculo'      => 'Vehículo',
        'maquinaria'    => 'Maquinaria / refrigeración',
        'otros'         => 'Otros',
    ];
}

/** Monto máximo que se puede depreciar en toda la vida del activo. */
function activoDepreciable(array $a): float
{
    return max(0.0, round((float) $a['costo'] - (float) $a['valor_residual'], 2));
}

/** Cuota de depreciación de un mes (línea recta). */
function activoCuotaMensual(array $a): float
{
    $meses = max(1, (int) $a['vida_util_meses']);
    return round(activoDepreciable($a) / $meses, 2);
}

/** Valor en libros: lo que vale hoy según la contabilidad. */
function activoValorNeto(array $a): float
{
    return round((float) $a['costo'] - (float) $a['depreciacion_acumulada'], 2);
}

/** Cuánto queda por depreciar. */
function activoPendiente(array $a): float
{
    return max(0.0, round(activoDepreciable($a) - (float) $a['depreciacion_acumulada'], 2));
}

/** Primer periodo depreciable: el mes siguiente al de adquisición. */
function activoPrimerPeriodo(array $a): string
{
    return date('Y-m', strtotime($a['fecha_adquisicion'] . ' first day of next month'));
}

/**
 * Periodos 'YYYY-MM' que este activo tiene pendientes hasta $hasta inclusive.
 * Excluye los ya registrados en `depreciaciones`.
 */
function activoPeriodosPendientes(array $a, string $hasta): array
{
    if ($a['estado'] !== 'activo' || activoPendiente($a) <= 0.004) return [];

    $ya = qCol("SELECT periodo FROM depreciaciones WHERE activo_id = ?", [(int) $a['id']]);
    $ya = array_flip($ya);

    $out = [];
    $p = activoPrimerPeriodo($a);
    // Tope defensivo: nunca más de 600 meses (50 años) por activo.
    for ($i = 0; $i < 600 && $p <= $hasta; $i++) {
        if (!isset($ya[$p])) $out[] = $p;
        $p = date('Y-m', strtotime($p . '-01 +1 month'));
    }
    return $out;
}

/**
 * Registra la depreciación de un activo en un periodo. Debe llamarse DENTRO de
 * una transacción. Devuelve el monto aplicado (0 si no correspondía).
 */
function activoDepreciarPeriodo(array $a, string $periodo): float
{
    $pendiente = activoPendiente($a);
    if ($pendiente <= 0.004) return 0.0;

    // La última cuota se ajusta para no pasarse del valor depreciable: si no,
    // el activo terminaría valiendo menos que su valor residual.
    $monto = min(activoCuotaMensual($a), $pendiente);
    if ($monto <= 0.004) return 0.0;

    $antes   = (float) $a['depreciacion_acumulada'];
    $despues = round($antes + $monto, 2);

    // Gasto SIN cuenta: afecta el resultado pero no mueve efectivo.
    $trId = registrarTransaccion('gasto', $monto, [
        'sucursal_id'     => $a['sucursal_id'] !== null ? (int) $a['sucursal_id'] : null,
        'cuenta_id'       => null,
        'categoria_id'    => categoriaFinancieraId('gasto', 'Depreciación'),
        'descripcion'     => 'Depreciación ' . $periodo . ' · ' . $a['nombre'],
        'referencia_tipo' => 'depreciacion',
        'referencia_id'   => (int) $a['id'],
        'fecha'           => date('Y-m-t', strtotime($periodo . '-01')),
    ]);

    dbInsert('depreciaciones', [
        'activo_id' => (int) $a['id'], 'periodo' => $periodo, 'monto' => $monto,
        'acumulado_antes' => $antes, 'acumulado_despues' => $despues,
        'valor_neto' => round((float) $a['costo'] - $despues, 2),
        'transaccion_id' => $trId, 'usuario_id' => current_user()['id'] ?? null,
    ]);

    $datos = ['depreciacion_acumulada' => $despues];
    // Al llegar al valor residual el activo deja de depreciarse.
    if ($despues >= activoDepreciable($a) - 0.004) $datos['estado'] = 'depreciado';
    dbUpdate('activos_fijos', $datos, 'id = ?', [(int) $a['id']]);

    return $monto;
}

/**
 * Corre la depreciación de todos los activos hasta el periodo indicado.
 * Cada activo va en su propia transacción: si uno falla, los demás quedan bien.
 *
 * @return array{activos:int,asientos:int,total:float,errores:array}
 */
function activosCorrerDepreciacion(string $hasta, ?int $sucursalId = null): array
{
    $cond = ["estado = 'activo'"];
    $par  = [];
    if ($sucursalId !== null) { $cond[] = 'sucursal_id = ?'; $par[] = $sucursalId; }
    $activos = qAll("SELECT * FROM activos_fijos WHERE " . implode(' AND ', $cond) . " ORDER BY id", $par);

    $nActivos = 0; $nAsientos = 0; $total = 0.0; $errores = [];
    foreach ($activos as $a) {
        $periodos = activoPeriodosPendientes($a, $hasta);
        if (!$periodos) continue;
        $aplicoAlguno = false;

        foreach ($periodos as $periodo) {
            try {
                $monto = txReintentable(function () use ($a, $periodo) {
                    // Se relee bloqueado: el acumulado cambia en cada periodo y
                    // otra corrida simultánea no puede duplicar el asiento.
                    $act = qOne("SELECT * FROM activos_fijos WHERE id = ? FOR UPDATE", [(int) $a['id']]);
                    if (!$act || $act['estado'] !== 'activo') return 0.0;
                    if (qVal("SELECT 1 FROM depreciaciones WHERE activo_id = ? AND periodo = ?", [(int) $a['id'], $periodo])) {
                        return 0.0;   // ya registrado por otra corrida
                    }
                    return activoDepreciarPeriodo($act, $periodo);
                });
                if ($monto > 0) { $nAsientos++; $total += $monto; $aplicoAlguno = true; }
            } catch (Throwable $e) {
                $errores[] = $a['codigo'] . ' (' . $periodo . '): ' . $e->getMessage();
            }
        }
        if ($aplicoAlguno) $nActivos++;
    }
    return ['activos' => $nActivos, 'asientos' => $nAsientos, 'total' => round($total, 2), 'errores' => $errores];
}

/**
 * Totales de activos fijos para el balance general.
 * @return array{costo:float,depreciacion:float,neto:float,cantidad:int}
 */
function activosResumen(?int $sucursalId = null): array
{
    if (!activosDisponible()) return ['costo' => 0.0, 'depreciacion' => 0.0, 'neto' => 0.0, 'cantidad' => 0];

    $cond = ["estado IN ('activo','depreciado')"];
    $par  = [];
    if ($sucursalId !== null) { $cond[] = '(sucursal_id = ? OR sucursal_id IS NULL)'; $par[] = $sucursalId; }
    $r = qOne(
        "SELECT COUNT(*) cantidad, COALESCE(SUM(costo),0) costo,
                COALESCE(SUM(depreciacion_acumulada),0) depreciacion
           FROM activos_fijos WHERE " . implode(' AND ', $cond),
        $par
    ) ?: [];
    $costo = (float) ($r['costo'] ?? 0);
    $dep   = (float) ($r['depreciacion'] ?? 0);
    return ['costo' => $costo, 'depreciacion' => $dep, 'neto' => round($costo - $dep, 2), 'cantidad' => (int) ($r['cantidad'] ?? 0)];
}

/** ¿Está aplicada la migración? Evita romper una base sin actualizar. */
function activosDisponible(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = (bool) qVal("SHOW TABLES LIKE 'activos_fijos'");
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}
