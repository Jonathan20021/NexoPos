<?php
/**
 * TSS — Tesorería de la Seguridad Social (Ley 87-01).
 *
 * Aquí vive TODO lo que depende de un parámetro que puede cambiar por ley:
 * tasas, topes y el salario mínimo cotizable. El resto del sistema no debe
 * llevar ninguno de esos números escritos a mano.
 *
 * ============================================================================
 *  EL TOPE ES POR RÉGIMEN Y ES MENSUAL
 * ============================================================================
 *
 * Cada régimen deja de cotizar por encima de un múltiplo del salario mínimo
 * cotizable:
 *
 *      SFS  ..... 10 salarios mínimos     (salud, empleado + empresa)
 *      AFP  ..... 20                      (pensiones, empleado + empresa)
 *      SRL  .....  4                      (riesgos laborales, solo empresa)
 *      INFOTEP .. sin tope                (es sobre la nómina, no por sueldo)
 *
 * El tope es MENSUAL. En una nómina quincenal hay que partirlo por el mismo
 * factor que el sueldo, o a un sueldo alto se le cotizaría el doble de lo que
 * debe: media base contra un tope entero no lo corta nunca.
 */

/** Regímenes que llevan tope, con la columna que guarda su multiplicador. */
const TSS_REGIMENES = [
    'sfs' => 'tope_sfs_sm',
    'afp' => 'tope_afp_sm',
    'srl' => 'tope_srl_sm',
];

/**
 * Parámetros vigentes en una fecha.
 *
 * Se busca el juego con la `vigencia_desde` más reciente que no sea posterior a
 * la fecha pedida. Así la nómina de marzo sigue cotizando con el mínimo de
 * marzo aunque hoy sea otro: ante la TSS eso no se puede reescribir.
 *
 * Devuelve null si la tabla todavía no existe (migración P22 sin aplicar), para
 * que el sistema siga funcionando exactamente como antes.
 */
function tssParametros(?string $fecha = null): ?array
{
    static $cache = [];
    $fecha = $fecha ?: date('Y-m-d');
    if (array_key_exists($fecha, $cache)) return $cache[$fecha];

    try {
        $p = qOne(
            "SELECT * FROM tss_parametros WHERE vigencia_desde <= ? ORDER BY vigencia_desde DESC LIMIT 1",
            [$fecha]
        );
    } catch (Throwable $e) {
        return $cache[$fecha] = null;   // sin migración: el sistema no se cae
    }
    return $cache[$fecha] = ($p ?: null);
}

/** ¿Están los topes encendidos y con un salario mínimo utilizable? */
function tssTopesActivos(?array $p = null): bool
{
    $p = $p ?? tssParametros();
    return $p !== null && (int) $p['aplicar_topes'] === 1 && (float) $p['salario_minimo_cotizable'] > 0;
}

/**
 * Tope MENSUAL de un régimen, en pesos. 0 = sin tope.
 */
function tssTope(string $regimen, ?array $p = null): float
{
    $p = $p ?? tssParametros();
    if (!tssTopesActivos($p)) return 0.0;
    $col = TSS_REGIMENES[$regimen] ?? null;
    if ($col === null) return 0.0;
    return round((float) $p['salario_minimo_cotizable'] * (float) $p[$col], 2);
}

/**
 * Recorta una base al tope de su régimen.
 *
 * @param float $base    Base cotizable DEL PERÍODO.
 * @param float $factor  1 mensual, 0.5 quincenal… El tope se parte igual.
 */
function tssBaseTopada(float $base, string $regimen, float $factor = 1.0, ?array $p = null): float
{
    $tope = tssTope($regimen, $p);
    if ($tope <= 0) return $base;
    return min($base, round($tope * max(0.0, $factor), 2));
}

/**
 * Desglose completo de lo que se aporta sobre una base, con topes aplicados.
 *
 * Devuelve las dos mitades por separado —lo que se le retiene a la persona y lo
 * que pone la empresa— porque son cosas distintas que se confunden todo el
 * tiempo. El INFOTEP no lleva tope: se calcula sobre la base completa.
 */
function tssAportes(float $base, float $factor = 1.0, ?array $p = null): array
{
    $p = $p ?? tssParametros();
    $base = max(0.0, $base);

    // Sin parámetros en base se usan las tasas de la ley general, que es lo que
    // el sistema hacía antes de la P22.
    $t = [
        'sfs_empleado'      => (float) ($p['sfs_empleado']      ?? 0.0304),
        'sfs_empleador'     => (float) ($p['sfs_empleador']     ?? 0.0709),
        'afp_empleado'      => (float) ($p['afp_empleado']      ?? 0.0287),
        'afp_empleador'     => (float) ($p['afp_empleador']     ?? 0.0710),
        'srl_empleador'     => (float) ($p['srl_empleador']     ?? 0.0110),
        'infotep_empleador' => (float) ($p['infotep_empleador'] ?? 0.0100),
    ];

    $baseSfs = tssBaseTopada($base, 'sfs', $factor, $p);
    $baseAfp = tssBaseTopada($base, 'afp', $factor, $p);
    $baseSrl = tssBaseTopada($base, 'srl', $factor, $p);

    $empleado = [
        'sfs' => round($baseSfs * $t['sfs_empleado'], 2),
        'afp' => round($baseAfp * $t['afp_empleado'], 2),
    ];
    $empleador = [
        'sfs'     => round($baseSfs * $t['sfs_empleador'], 2),
        'afp'     => round($baseAfp * $t['afp_empleador'], 2),
        'srl'     => round($baseSrl * $t['srl_empleador'], 2),
        'infotep' => round($base    * $t['infotep_empleador'], 2),
    ];

    return [
        'base'            => round($base, 2),
        'bases'           => ['sfs' => $baseSfs, 'afp' => $baseAfp, 'srl' => $baseSrl],
        'topado'          => ['sfs' => $baseSfs < $base, 'afp' => $baseAfp < $base, 'srl' => $baseSrl < $base],
        'empleado'        => $empleado,
        'empleador'       => $empleador,
        'total_empleado'  => round(array_sum($empleado), 2),
        'total_empleador' => round(array_sum($empleador), 2),
        'total'           => round(array_sum($empleado) + array_sum($empleador), 2),
        'topes_activos'   => tssTopesActivos($p),
    ];
}

/**
 * Cuánto cambiaría la nómina si se encendieran los topes.
 *
 * Es lo que la pantalla enseña ANTES de dejar encenderlos: nadie debería
 * cambiar lo que se le retiene a 57 personas sin ver primero el número.
 * Se calcula sobre el salario mensual del padrón activo.
 */
function tssSimularTopes(?array $p = null): array
{
    $p = $p ?? tssParametros();
    if ($p === null) return ['disponible' => false];

    // Se fuerza el cálculo CON topes aunque estén apagados: es una simulación.
    $conTopes = array_merge($p, ['aplicar_topes' => 1]);
    $sinTopes = array_merge($p, ['aplicar_topes' => 0]);

    $filas = [];
    $dif = ['empleado' => 0.0, 'empleador' => 0.0];
    foreach (qAll("SELECT id, nombre, apellido, salario FROM empleados WHERE estado = 'activo' ORDER BY salario DESC") as $e) {
        $s = (float) $e['salario'];
        $a = tssAportes($s, 1.0, $sinTopes);
        $b = tssAportes($s, 1.0, $conTopes);
        $dEmp = round($b['total_empleado'] - $a['total_empleado'], 2);
        $dPat = round($b['total_empleador'] - $a['total_empleador'], 2);
        if (abs($dEmp) < 0.01 && abs($dPat) < 0.01) continue;
        $dif['empleado']  += $dEmp;
        $dif['empleador'] += $dPat;
        $filas[] = [
            'empleado'  => trim($e['nombre'] . ' ' . $e['apellido']),
            'salario'   => $s,
            'dif_empleado'  => $dEmp,
            'dif_empleador' => $dPat,
            'regimenes' => array_keys(array_filter($b['topado'])),
        ];
    }

    return [
        'disponible'    => (float) $p['salario_minimo_cotizable'] > 0,
        'afectados'     => count($filas),
        'filas'         => $filas,
        'dif_empleado'  => round($dif['empleado'], 2),
        'dif_empleador' => round($dif['empleador'], 2),
        'topes'         => ['sfs' => tssTope('sfs', $conTopes), 'afp' => tssTope('afp', $conTopes), 'srl' => tssTope('srl', $conTopes)],
    ];
}

/* ============================================================
 *  NOVEDADES
 * ============================================================ */

/** Etiquetas de las novedades que la TSS espera en el período. */
function tssTiposNovedad(): array
{
    return [
        'ingreso'        => 'Ingreso de un nuevo empleado',
        'reingreso'      => 'Reingreso',
        'salida'         => 'Salida definitiva',
        'cambio_salario' => 'Cambio de salario',
        'licencia'       => 'Licencia o suspensión',
    ];
}

/**
 * Deja constancia de un movimiento del período.
 *
 * Idempotente por (empleado, tipo, fecha): registrar dos veces el mismo ingreso
 * no lo declara dos veces ante la TSS.
 */
function tssNovedad(int $empleadoId, string $tipo, string $fecha, array $extra = []): ?int
{
    if (!array_key_exists($tipo, tssTiposNovedad())) return null;
    try {
        $ya = qVal("SELECT id FROM tss_novedades WHERE empleado_id=? AND tipo=? AND fecha=?",
                   [$empleadoId, $tipo, $fecha]);
        if ($ya) return (int) $ya;
        return dbInsert('tss_novedades', [
            'empleado_id'     => $empleadoId,
            'tipo'            => $tipo,
            'fecha'           => $fecha,
            'salario_antes'   => $extra['salario_antes']   ?? null,
            'salario_despues' => $extra['salario_despues'] ?? null,
            'dias'            => $extra['dias']            ?? null,
            'motivo'          => $extra['motivo']          ?? null,
            'usuario_id'      => current_user()['id'] ?? null,
        ]);
    } catch (Throwable $e) {
        return null;   // sin migración aplicada, no se rompe nada
    }
}

/**
 * Novedades del padrón que la TSS esperaría en un mes y NO están registradas.
 *
 * Se deducen de `empleados`: quien ingresó dentro del mes y quien salió. Sirve
 * para que el archivo no se mande cojo aunque nadie se acordara de anotarlas.
 */
function tssNovedadesDelMes(string $anioMes): array
{
    $desde = $anioMes . '-01';
    $hasta = date('Y-m-t', strtotime($desde));
    $out = [];

    foreach (qAll("SELECT id, nombre, apellido, cedula, fecha_ingreso, salario FROM empleados
                    WHERE fecha_ingreso BETWEEN ? AND ? ORDER BY fecha_ingreso", [$desde, $hasta]) as $e) {
        $out[] = ['tipo' => 'ingreso', 'empleado_id' => (int) $e['id'],
                  'empleado' => trim($e['nombre'] . ' ' . $e['apellido']), 'cedula' => $e['cedula'],
                  'fecha' => $e['fecha_ingreso'], 'salario' => (float) $e['salario'], 'origen' => 'padrón'];
    }
    foreach (qAll("SELECT id, nombre, apellido, cedula, fecha_salida, salario FROM empleados
                    WHERE fecha_salida BETWEEN ? AND ? ORDER BY fecha_salida", [$desde, $hasta]) as $e) {
        $out[] = ['tipo' => 'salida', 'empleado_id' => (int) $e['id'],
                  'empleado' => trim($e['nombre'] . ' ' . $e['apellido']), 'cedula' => $e['cedula'],
                  'fecha' => $e['fecha_salida'], 'salario' => (float) $e['salario'], 'origen' => 'padrón'];
    }

    try {
        foreach (qAll("SELECT n.*, e.nombre, e.apellido, e.cedula FROM tss_novedades n
                         JOIN empleados e ON e.id = n.empleado_id
                        WHERE n.fecha BETWEEN ? AND ? ORDER BY n.fecha", [$desde, $hasta]) as $n) {
            $out[] = ['tipo' => $n['tipo'], 'empleado_id' => (int) $n['empleado_id'],
                      'empleado' => trim($n['nombre'] . ' ' . $n['apellido']), 'cedula' => $n['cedula'],
                      'fecha' => $n['fecha'], 'salario' => (float) ($n['salario_despues'] ?? 0),
                      'motivo' => $n['motivo'], 'origen' => 'registrada'];
        }
    } catch (Throwable $e) { /* sin tabla, solo van las del padrón */ }

    usort($out, fn($a, $b) => [$a['fecha'], $a['empleado']] <=> [$b['fecha'], $b['empleado']]);
    return $out;
}

/* ============================================================
 *  DECLARACIÓN DEL MES
 * ============================================================ */

/**
 * Lo que hay que declarar por cada persona en un mes.
 *
 * La base sale de las nóminas CONFIRMADAS del mes —no de los borradores, que
 * todavía se pueden tocar—. Si no hay ninguna confirmada, cae al salario del
 * padrón y lo dice, para que nadie mande un archivo creyendo que salió de la
 * nómina real.
 */
function tssDeclaracionMes(string $anioMes): array
{
    $desde = $anioMes . '-01';
    $hasta = date('Y-m-t', strtotime($desde));
    $p = tssParametros($hasta);

    $nominas = qCol("SELECT id FROM nominas WHERE estado IN ('procesada','pagada')
                       AND fecha_desde >= ? AND fecha_hasta <= ?", [$desde, $hasta]);

    $filas = [];
    if ($nominas) {
        $ph = implode(',', array_fill(0, count($nominas), '?'));
        // La base cotizable del mes es la SUMA de las quincenas del mes: el tope
        // es mensual, así que se topa una sola vez sobre el total, no en cada
        // quincena por separado.
        $rows = qAll(
            "SELECT e.id, e.nombre, e.apellido, e.cedula, e.salario,
                    SUM(nd.total_ingresos - nd.prima_vacacional) AS base
               FROM nomina_detalles nd
               JOIN empleados e ON e.id = nd.empleado_id
              WHERE nd.nomina_id IN ($ph)
              GROUP BY e.id, e.nombre, e.apellido, e.cedula, e.salario
              ORDER BY e.nombre, e.apellido", $nominas);
        $fuente = 'nóminas confirmadas del mes';
    } else {
        $rows = qAll("SELECT id, nombre, apellido, cedula, salario, salario AS base
                        FROM empleados WHERE estado='activo' ORDER BY nombre, apellido");
        $fuente = 'salario del padrón (no hay nómina confirmada en el mes)';
    }

    $tot = ['base' => 0.0, 'sfs_e' => 0.0, 'afp_e' => 0.0, 'sfs_p' => 0.0, 'afp_p' => 0.0, 'srl' => 0.0, 'infotep' => 0.0];
    foreach ($rows as $r) {
        $a = tssAportes((float) $r['base'], 1.0, $p);
        $filas[] = [
            'empleado_id' => (int) $r['id'],
            'nombre'   => trim($r['nombre'] . ' ' . $r['apellido']),
            'cedula'   => $r['cedula'],
            'base'     => $a['base'],
            'bases'    => $a['bases'],
            'topado'   => $a['topado'],
            'empleado' => $a['empleado'],
            'empleador'=> $a['empleador'],
            'total'    => $a['total'],
        ];
        $tot['base']    += $a['base'];
        $tot['sfs_e']   += $a['empleado']['sfs'];
        $tot['afp_e']   += $a['empleado']['afp'];
        $tot['sfs_p']   += $a['empleador']['sfs'];
        $tot['afp_p']   += $a['empleador']['afp'];
        $tot['srl']     += $a['empleador']['srl'];
        $tot['infotep'] += $a['empleador']['infotep'];
    }
    $tot = array_map(fn($v) => round($v, 2), $tot);
    $tot['empleado']  = round($tot['sfs_e'] + $tot['afp_e'], 2);
    $tot['empleador'] = round($tot['sfs_p'] + $tot['afp_p'] + $tot['srl'] + $tot['infotep'], 2);
    $tot['general']   = round($tot['empleado'] + $tot['empleador'], 2);

    return [
        'periodo'   => $anioMes,
        'desde'     => $desde,
        'hasta'     => $hasta,
        'fuente'    => $fuente,
        'confirmada'=> (bool) $nominas,
        'parametros'=> $p,
        'filas'     => $filas,
        'totales'   => $tot,
        'novedades' => tssNovedadesDelMes($anioMes),
    ];
}
