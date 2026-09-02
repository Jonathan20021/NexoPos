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
/**
 * Lo que las nóminas confirmadas aportaron a UN MES, repartido por días.
 *
 * ── Por qué no basta con «las nóminas del mes» ──
 *
 * Antes se buscaban las que caben ENTERAS dentro del mes
 * (`fecha_desde >= día 1 AND fecha_hasta <= último día`). Un período a caballo
 * entre dos meses —del 26 de agosto al 10 de septiembre— no cabe entero en
 * ninguno de los dos, así que no salía ni en uno ni en otro: **desaparecía de
 * la TSS**. Y como la función cae al padrón cuando no encuentra nóminas, ese
 * mes se declaraba con el sueldo de las 57 personas en vez de con lo que de
 * verdad se pagó a 5. Catorce veces de más, y sin avisar.
 *
 * Ahora entra toda nómina que SOLAPE el mes, y su importe se reparte por los
 * días que caen dentro. Una quincena normal cae entera y se reparte al 100%,
 * así que el caso corriente no cambia ni un centavo.
 *
 * La regalía queda fuera SIEMPRE: no cotiza (arts. 219-222) y su período es el
 * año completo, así que con una ventana de solape aparecería en los doce meses.
 *
 * Devuelve [empleado_id => ['base','afp','sfs','isr','per_capita']] y aparte
 * cuántas nóminas la alimentaron.
 */
function tssLineasDelMes(string $anioMes): array
{
    $desde = $anioMes . '-01';
    $hasta = date('Y-m-t', strtotime($desde));

    $rows = qAll(
        "SELECT nd.empleado_id, n.id AS nomina_id, n.fecha_desde, n.fecha_hasta,
                COALESCE(SUM(nd.total_ingresos - nd.prima_vacacional),0) AS base,
                COALESCE(SUM(nd.afp),0) afp, COALESCE(SUM(nd.sfs),0) sfs,
                COALESCE(SUM(nd.isr),0) isr, COALESCE(SUM(nd.per_capita),0) per_capita
           FROM nomina_detalles nd
           JOIN nominas n ON n.id = nd.nomina_id
          WHERE n.estado IN ('procesada','pagada')
            AND n.tipo <> 'regalia'
            AND n.fecha_desde <= ? AND n.fecha_hasta >= ?
          GROUP BY nd.empleado_id, n.id, n.fecha_desde, n.fecha_hasta",
        [$hasta, $desde]
    );

    $porEmpleado = [];
    $nominas = [];
    foreach ($rows as $r) {
        $dentro = tssDiasSolape($r['fecha_desde'], $r['fecha_hasta'], $desde, $hasta);
        if ($dentro <= 0) continue;
        $largo = tssDiasSolape($r['fecha_desde'], $r['fecha_hasta'], $r['fecha_desde'], $r['fecha_hasta']);
        $parte = $largo > 0 ? $dentro / $largo : 1.0;

        $eid = (int) $r['empleado_id'];
        $porEmpleado[$eid] ??= ['base' => 0.0, 'afp' => 0.0, 'sfs' => 0.0, 'isr' => 0.0, 'per_capita' => 0.0];
        foreach (['base', 'afp', 'sfs', 'isr', 'per_capita'] as $k) {
            $porEmpleado[$eid][$k] += (float) $r[$k] * $parte;
        }
        $nominas[(int) $r['nomina_id']] = true;
    }
    foreach ($porEmpleado as $eid => $v) {
        $porEmpleado[$eid] = array_map(fn($x) => round($x, 2), $v);
    }

    return ['empleados' => $porEmpleado, 'nominas' => count($nominas),
            'desde' => $desde, 'hasta' => $hasta];
}

/** Días de solape entre dos rangos, ambos inclusive. 0 si no se tocan. */
function tssDiasSolape(string $aIni, string $aFin, string $bIni, string $bFin): int
{
    $ini = max(substr($aIni, 0, 10), substr($bIni, 0, 10));
    $fin = min(substr($aFin, 0, 10), substr($bFin, 0, 10));
    if ($ini > $fin) return 0;
    return (int) floor((strtotime($fin) - strtotime($ini)) / 86400) + 1;
}

function tssDeclaracionMes(string $anioMes): array
{
    $desde = $anioMes . '-01';
    $hasta = date('Y-m-t', strtotime($desde));
    $p = tssParametros($hasta);

    $lin = tssLineasDelMes($anioMes);

    $filas = [];
    if ($lin['nominas'] > 0) {
        $ids = array_keys($lin['empleados']);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $rows = [];
        foreach (qAll("SELECT id, nombre, apellido, cedula, salario FROM empleados
                        WHERE id IN ($ph) ORDER BY nombre, apellido", $ids) as $e) {
            $e['base'] = $lin['empleados'][(int) $e['id']]['base'];
            $rows[] = $e;
        }
        $fuente = 'nóminas confirmadas del mes';
    } else {
        // Sin nómina no hay nada declarado: se cae al padrón para poder SIMULAR
        // el mes, y se dice bien claro de dónde salió. `confirmada` queda en
        // false para que nadie registre un pago sobre una simulación.
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
        'confirmada'=> $lin['nominas'] > 0,
        'nominas'   => $lin['nominas'],
        'parametros'=> $p,
        'filas'     => $filas,
        'totales'   => $tot,
        'novedades' => tssNovedadesDelMes($anioMes),
    ];
}

/* ============================================================
 *  LO QUE HAY QUE PAGAR CADA MES, Y A QUIÉN
 * ============================================================
 *
 * Una nómina genera TRES flujos de dinero y hasta la P31 el sistema solo
 * registraba uno:
 *
 *   1. el NETO, que sale a la gente          → se registraba al pagar la nómina
 *   2. las RETENCIONES (AFP, SFS e ISR)      → la empresa las guarda y las remite
 *   3. el APORTE PATRONAL                     → sale íntegro del bolsillo de la empresa
 *
 * Los dos últimos no aparecían por ningún lado: sobre la segunda quincena de
 * julio de 2026 del padrón real, el costo era 1,105,895.70 y en el resultado
 * entraban 877,721.39 — faltaba el 20.6%.
 *
 * Y no van al mismo sitio: AFP y SFS (del empleado y de la empresa) más riesgos
 * laborales e INFOTEP van a la TESORERÍA; el ISR retenido va a la DGII, en el
 * IR-3. Son dos pagos con dos plazos distintos, así que se llevan por separado.
 */

/** ¿Está aplicada la migración de pagos? */
function tssPagosDisponible(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try { qVal("SELECT 1 FROM tss_pagos LIMIT 1"); return $ok = true; }
    catch (Throwable $e) { return $ok = false; }
}

/**
 * Lo que se debe por un mes, y lo que ya se pagó.
 *
 * ── Por qué se comparan dos cifras de retención ──
 *
 * Lo RETENIDO es lo que las nóminas del mes le quitaron de verdad a la gente
 * (`nomina_detalles.afp + sfs`). Lo DECLARADO es lo que sale de aplicar los
 * parámetros vigentes sobre la base del mes completo. Normalmente coinciden,
 * pero no tienen por qué: el tope de la TSS es MENSUAL y la nómina lo aplica
 * quincena a quincena, así que en cuanto los topes se enciendan un sueldo alto
 * puede dar distinto. A la Tesorería se le paga lo declarado; la diferencia con
 * lo retenido es un ajuste que alguien tiene que ver, no esconder.
 */
function tssObligacionesMes(string $anioMes): array
{
    $desde = $anioMes . '-01';
    $hasta = date('Y-m-t', strtotime($desde));

    // Lo retenido sale de la MISMA fuente prorrateada que la declaración. Con
    // dos consultas distintas —una por «fecha_hasta dentro del mes» y otra por
    // solape— una nómina a caballo entre dos meses contaba en un lado y no en el
    // otro, y la comparación de abajo acusaba una diferencia que no existía.
    $lin = tssLineasDelMes($anioMes);
    $ret = ['afp' => 0.0, 'sfs' => 0.0, 'isr' => 0.0, 'per_capita' => 0.0,
            'nominas' => $lin['nominas'], 'lineas' => count($lin['empleados'])];
    foreach ($lin['empleados'] as $v) {
        foreach (['afp', 'sfs', 'isr', 'per_capita'] as $k) $ret[$k] += $v[$k];
    }

    // Y lo que dice la declaración del mes, que es lo que se le paga a la TSS.
    $d = tssDeclaracionMes($anioMes);
    $t = $d['totales'];

    $retenidoTss  = round((float) $ret['afp'] + (float) $ret['sfs'], 2);
    $declaradoTss = round((float) $t['empleado'], 2);
    $patronal     = round((float) $t['empleador'], 2);
    $isr          = round((float) $ret['isr'], 2);
    // La per cápita adicional —el plan de salud de los dependientes— se le
    // retiene al empleado y la empresa la remite junto con el SFS. No sale de
    // tssAportes() porque no es una tasa de ley: es lo que cada quien contrató.
    $perCapita    = round((float) $ret['per_capita'], 2);

    $pagos = [];
    if (tssPagosDisponible()) {
        foreach (qAll("SELECT * FROM tss_pagos WHERE periodo = ?", [$anioMes]) as $p) {
            $pagos[$p['tipo']] = $p;
        }
    }

    return [
        'periodo'    => $anioMes,
        'desde'      => $desde,
        'hasta'      => $hasta,
        'nominas'    => (int) $ret['nominas'],
        'confirmada' => $d['confirmada'],
        'fuente'     => $d['fuente'],
        'tss' => [
            'retencion_empleado' => $declaradoTss,
            'retenido_en_nomina' => $retenidoTss,
            'diferencia'         => round($declaradoTss - $retenidoTss, 2),
            'aporte_patronal'    => $patronal,
            'desglose_patronal'  => ['sfs' => $t['sfs_p'], 'afp' => $t['afp_p'],
                                     'srl' => $t['srl'], 'infotep' => $t['infotep']],
            'per_capita'         => $perCapita,
            'total'              => round($declaradoTss + $perCapita + $patronal, 2),
            'pago'               => $pagos['tss'] ?? null,
        ],
        'isr' => [
            'total' => $isr,
            'pago'  => $pagos['isr'] ?? null,
        ],
        'total_general' => round($declaradoTss + $perCapita + $patronal + $isr, 2),
    ];
}

/**
 * Registra que se pagó la TSS o el ISR de un mes.
 *
 * Deja el gasto en el libro de caja: es AQUÍ donde entra al resultado el costo
 * que la nómina no registraba. Idempotente por (periodo, tipo) gracias al índice
 * único; si ya está pagado, lo dice en vez de duplicarlo.
 */
function tssPagoRegistrar(string $anioMes, string $tipo, array $datos): int
{
    if (!in_array($tipo, ['tss', 'isr'], true)) throw new InvalidArgumentException('Tipo de pago no válido.');
    if (!tssPagosDisponible()) throw new RuntimeException('Falta aplicar database/migracion_tss_pagos_p31.sql.');

    $o = tssObligacionesMes($anioMes);

    // Sin nómina confirmada en el mes no hay obligación que pagar. Hace falta
    // decirlo aquí porque tssDeclaracionMes() CAE AL PADÓN cuando no encuentra
    // nóminas —para que se pueda simular— y sin este corte se podía registrar
    // el pago de un mes de hace tres años calculado con los sueldos de hoy, y
    // meter ese gasto inventado en el resultado.
    if ((int) $o['nominas'] === 0 || !$o['confirmada']) {
        // `confirmada` en false significa que la declaración se armó con el
        // padrón para poder simular. Registrar un pago sobre eso metería en los
        // libros un gasto que nadie hizo, calculado con los sueldos de hoy.
        throw new RuntimeException('No hay nada que pagar en ' . $anioMes
            . ': ninguna nómina de ese mes está confirmada.');
    }

    $monto = round((float) ($datos['monto'] ?? ($tipo === 'tss' ? $o['tss']['total'] : $o['isr']['total'])), 2);
    if ($monto <= 0) throw new RuntimeException('No hay nada que pagar en ' . $anioMes . '.');

    if (qVal("SELECT 1 FROM tss_pagos WHERE periodo = ? AND tipo = ?", [$anioMes, $tipo])) {
        throw new RuntimeException('El ' . strtoupper($tipo) . ' de ' . $anioMes . ' ya figura pagado.');
    }

    $cuentaId = (int) ($datos['cuenta_id'] ?? 0) ?: null;
    $fecha    = $datos['fecha_pago'] ?? date('Y-m-d');

    $id = dbInsert('tss_pagos', [
        'periodo'         => $anioMes,
        'tipo'            => $tipo,
        'monto'           => $monto,
        'ret_empleado'    => $tipo === 'tss'
                                 ? round($o['tss']['retencion_empleado'] + $o['tss']['per_capita'], 2)
                                 : $o['isr']['total'],
        'aporte_patronal' => $tipo === 'tss' ? $o['tss']['aporte_patronal'] : 0,
        'fecha_pago'      => $fecha,
        'cuenta_id'       => $cuentaId,
        'referencia'      => trim((string) ($datos['referencia'] ?? '')) ?: null,
        'notas'           => trim((string) ($datos['notas'] ?? '')) ?: null,
        'usuario_id'      => current_user()['id'] ?? null,
    ]);

    registrarTransaccion('gasto', $monto, [
        'sucursal_id'     => null,
        'cuenta_id'       => $cuentaId,
        'categoria_id'    => categoriaFinancieraId('gasto',
                                $tipo === 'tss' ? 'Seguridad Social (TSS)' : 'Retenciones e impuestos'),
        'descripcion'     => ($tipo === 'tss' ? 'Pago a la TSS · ' : 'ISR retenido a asalariados (IR-3) · ') . $anioMes,
        'referencia_tipo' => 'tss_pago',
        'referencia_id'   => $id,
        'fecha'           => $fecha,
    ]);

    return $id;
}
