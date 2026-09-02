<?php
/**
 * Regalía pascual — el «salario de Navidad» (Código de Trabajo, arts. 219-222).
 *
 * ============================================================================
 *  LO QUE DICE LA LEY, Y LO QUE DECIDE EL CONTADOR
 * ============================================================================
 *
 * De la ley salen tres cosas que aquí están fijas:
 *
 *   · Se paga **a más tardar el 20 de diciembre** (art. 219).
 *   · Es **una duodécima parte del salario ordinario devengado en el año
 *     calendario**, del 1 de enero al 31 de diciembre (art. 219). Quien no
 *     trabajó el año entero cobra lo proporcional, y eso sale solo: se divide
 *     entre doce lo que de verdad ganó.
 *   · **No paga ISR** (art. 222) **ni cotiza a la TSS**. Por eso una nómina de
 *     regalía se guarda con AFP, SFS e ISR en cero: no es un olvido.
 *
 * Lo que la ley NO resuelve en una lista cerrada es qué es «salario ordinario».
 * El art. 220 deja fuera el salario extraordinario, así que las **horas extra no
 * entran**, y tampoco entran los reembolsos —que no son salario, son devolver un
 * gasto— ni la prima vacacional. Lo que sí discute cada contador es si entran
 * las comisiones y las bonificaciones. Aquí:
 *
 *   ENTRAN     sueldo del período, comisiones, vacaciones (diferencial)
 *   NO ENTRAN  horas extra, reembolso, prima vacacional, bonificaciones, otros
 *
 * Es un criterio, no un dogma: la pantalla lo dice y deja corregir el monto de
 * cada persona antes de generar nada.
 *
 * ============================================================================
 *  DE DÓNDE SALE EL DEVENGADO, Y POR QUÉ SE MIDE EN DÍAS
 * ============================================================================
 *
 * De las nóminas CONFIRMADAS del año. Pero un sistema que arrancó a mitad de
 * año no tiene las nóminas de enero a junio y la gente sí las cobró: calcular
 * solo con lo que hay en base le pagaría a todo el mundo la mitad de lo que le
 * toca. Por eso lo que falta se puede **completar con el sueldo del padrón**.
 *
 * Y no basta con mirar «¿hay nómina en julio?», porque puede haber SOLO UNA
 * QUINCENA. Si esa media nómina marcara julio como cubierto, se perdería la
 * otra mitad del mes sin que nadie lo viera. Así que se cuentan **días**: cada
 * nómina cubre los días de su período, y lo que queda descubierto —y la persona
 * sí trabajó— se completa con el sueldo del padrón prorrateado a esos días.
 *
 * Cada fila dice cuánto vino de nómina y cuánto de padrón. Nunca se completa a
 * ciegas ni se esconde.
 */

/** Conceptos de `nomina_detalles` que cuentan como salario ordinario. */
function regaliaConceptosOrdinarios(): array
{
    return ['salario_base', 'comisiones', 'vacaciones_diferencial'];
}

/** Los que se dejan fuera, con el porqué. Se enseña en pantalla. */
function regaliaConceptosExcluidos(): array
{
    return [
        'Horas extra'        => 'el art. 220 excluye el salario extraordinario',
        'Reembolsos'         => 'no son salario, es devolver un gasto',
        'Prima vacacional'   => 'no es salario ordinario del mes',
        'Bonificaciones'     => 'participación en beneficios, no salario ordinario',
        'Otros ingresos'     => 'concepto suelto; se excluye salvo que el contador diga lo contrario',
    ];
}

/** Fecha tope legal para pagarla. */
function regaliaFechaTope(int $anio): string
{
    return sprintf('%04d-12-20', $anio);
}

/** Días de solape entre dos rangos de fechas, ambos inclusive. 0 si no se tocan. */
function regaliaDiasSolape(string $aIni, string $aFin, string $bIni, string $bFin): int
{
    $ini = max($aIni, $bIni);
    $fin = min($aFin, $bFin);
    if ($ini > $fin) return 0;
    return (int) floor((strtotime($fin) - strtotime($ini)) / 86400) + 1;
}

/**
 * Lo que cada nómina confirmada del año pagó a cada persona, con su período.
 *
 * Se devuelve el período completo —no solo el mes— porque una nómina mensual o
 * una semana a caballo entre dos meses hay que repartirla por días.
 */
function regaliaPagosDelAnio(int $anio): array
{
    $sum = implode(' + ', array_map(fn($c) => "nd.$c", regaliaConceptosOrdinarios()));
    return qAll(
        "SELECT nd.empleado_id, n.id AS nomina_id, n.fecha_desde, n.fecha_hasta,
                SUM($sum) AS monto
           FROM nomina_detalles nd
           JOIN nominas n ON n.id = nd.nomina_id
          WHERE n.estado IN ('procesada','pagada')
            AND n.tipo <> 'regalia'
            AND n.fecha_hasta >= ? AND n.fecha_desde <= ?
          GROUP BY nd.empleado_id, n.id, n.fecha_desde, n.fecha_hasta",
        [sprintf('%04d-01-01', $anio), sprintf('%04d-12-31', $anio)]
    );
}

/**
 * La regalía de UNA persona, con el desglose de dónde salió cada peso.
 *
 * Vive aparte porque la liquidación de un empleado que se va necesita
 * exactamente esto para su regalía proporcional, y calcular las 57 filas para
 * quedarse con una sería tirar el trabajo.
 *
 * Devuelve null si esa persona no trabajó ningún día del año.
 *
 * @param array $e      Fila de `empleados` (hace falta salario, fecha_ingreso, fecha_salida).
 * @param array $pagos  Sus líneas de nómina del año (de regaliaPagosDelAnio()).
 */
function regaliaDeEmpleado(array $e, int $anio, string $corte, array $pagos, bool $completarConPadron = true): ?array
{
    $anioIni = sprintf('%04d-01-01', $anio);

    // Ventana en la que esta persona estuvo empleada dentro del año.
    $vIni = max($anioIni, (string) ($e['fecha_ingreso'] ?: $anioIni));
    $vFin = min($corte, (string) ($e['fecha_salida'] ?: $corte));
    if ($vIni > $vFin) return null;

    $deNomina = 0.0; $diasNomina = 0;
    foreach ($pagos as $p) {
        $d = regaliaDiasSolape($p['fecha_desde'], $p['fecha_hasta'], $vIni, $vFin);
        if ($d <= 0) continue;
        $largo = regaliaDiasSolape($p['fecha_desde'], $p['fecha_hasta'], $p['fecha_desde'], $p['fecha_hasta']);
        // Si el período se sale de la ventana, solo entra la parte de dentro.
        $deNomina   += (float) $p['monto'] * ($largo > 0 ? $d / $largo : 1);
        $diasNomina += $d;
    }

    $diasVentana = regaliaDiasSolape($vIni, $vFin, $vIni, $vFin);
    $diasSueltos = max(0, $diasVentana - $diasNomina);

    // Lo que ninguna nómina cubre, al sueldo del padrón prorrateado por día de
    // calendario (365/12 = 30.4167 días por mes, que es como se prorratea un
    // sueldo mensual en un tramo suelto).
    $dePadron = $completarConPadron && $diasSueltos > 0
        ? round((float) $e['salario'] / (365 / 12) * $diasSueltos, 2)
        : 0.0;

    $devengado = round($deNomina + $dePadron, 2);

    return [
        'empleado_id'   => (int) $e['id'],
        'codigo'        => $e['codigo'] ?? null,
        'nombre'        => trim(($e['nombre'] ?? '') . ' ' . ($e['apellido'] ?? '')),
        'cedula'        => $e['cedula'] ?? null,
        'grupo'         => ($e['sucursal'] ?? null) ?: (($e['departamento'] ?? null) ?: 'Sin ubicación'),
        'salario'       => (float) $e['salario'],
        'fecha_ingreso' => $e['fecha_ingreso'] ?? null,
        'fecha_salida'  => $e['fecha_salida'] ?? null,
        'estado'        => $e['estado'] ?? null,
        'ventana'       => [$vIni, $vFin],
        'dias_ventana'  => $diasVentana,
        'dias_nomina'   => $diasNomina,
        'dias_padron'   => $completarConPadron ? $diasSueltos : 0,
        'dias_sin'      => $completarConPadron ? 0 : $diasSueltos,
        'de_nomina'     => round($deNomina, 2),
        'de_padron'     => $dePadron,
        'devengado'     => $devengado,
        'regalia'       => round($devengado / 12, 2),
    ];
}

/**
 * El cuadro completo de la regalía de un año.
 *
 * @param bool $completarConPadron Rellenar con el sueldo del padrón los días
 *                                 trabajados que ninguna nómina cubre.
 */
function regaliaCalcular(int $anio, bool $completarConPadron = true): array
{
    $anioIni = sprintf('%04d-01-01', $anio);
    $anioFin = sprintf('%04d-12-31', $anio);
    // En el año en curso solo tiene sentido mirar hasta hoy: los meses que aún
    // no han pasado no se han devengado.
    $corte = ((int) date('Y') === $anio) ? date('Y-m-d') : $anioFin;

    $pagos = [];
    foreach (regaliaPagosDelAnio($anio) as $p) {
        $pagos[(int) $p['empleado_id']][] = $p;
    }

    // Entra quien trabajó ALGÚN día del año, esté hoy activo o no: a quien se
    // fue en septiembre también le corresponde su parte.
    $empleados = qAll(
        "SELECT e.id, e.codigo, e.nombre, e.apellido, e.cedula, e.salario,
                e.fecha_ingreso, e.fecha_salida, e.estado, e.banco, e.cuenta_bancaria,
                dep.nombre AS departamento, su.nombre AS sucursal
           FROM empleados e
           LEFT JOIN departamentos dep ON dep.id = e.departamento_id
           LEFT JOIN sucursales su     ON su.id  = e.sucursal_id
          WHERE (e.estado = 'activo' OR YEAR(e.fecha_salida) = ?)
          ORDER BY e.nombre, e.apellido",
        [$anio]
    );

    $filas = [];
    $tot = ['devengado' => 0.0, 'de_nomina' => 0.0, 'de_padron' => 0.0, 'regalia' => 0.0,
            'dias_nomina' => 0, 'dias_padron' => 0, 'con_relleno' => 0];

    foreach ($empleados as $e) {
        $f = regaliaDeEmpleado($e, $anio, $corte, $pagos[(int) $e['id']] ?? [], $completarConPadron);
        if ($f === null) continue;   // no trabajó ningún día del año
        $filas[] = $f;

        $diasNomina  = $f['dias_nomina'];
        $diasSueltos = $f['dias_padron'] + $f['dias_sin'];
        $deNomina    = $f['de_nomina'];
        $demPadron   = $f['de_padron'];
        $devengado   = $f['devengado'];
        $regalia     = $f['regalia'];

        $tot['devengado']  += $devengado;
        $tot['de_nomina']  += $deNomina;
        $tot['de_padron']  += $demPadron;
        $tot['regalia']    += $regalia;
        $tot['dias_nomina'] += $diasNomina;
        $tot['dias_padron'] += $completarConPadron ? $diasSueltos : 0;
        if ($diasSueltos > 0) $tot['con_relleno']++;
    }

    foreach (['devengado', 'de_nomina', 'de_padron', 'regalia'] as $k) $tot[$k] = round($tot[$k], 2);

    return [
        'anio'           => $anio,
        'corte'          => $corte,
        'completado'     => $completarConPadron,
        'filas'          => $filas,
        'totales'        => $tot,
        'fecha_tope'     => regaliaFechaTope($anio),
        'dias_para_tope' => (int) floor((strtotime(regaliaFechaTope($anio)) - strtotime(date('Y-m-d'))) / 86400),
    ];
}

/**
 * ¿Ya existe la nómina de regalía de este año?
 *
 * Se busca por tipo y año, no por descripción: el nombre lo escribe una persona
 * y dos años seguidos se llamarían igual con una errata de diferencia.
 */
function regaliaNominaDelAnio(int $anio): ?array
{
    try {
        return qOne("SELECT * FROM nominas WHERE tipo = 'regalia' AND YEAR(fecha_hasta) = ? ORDER BY id DESC LIMIT 1",
                    [$anio]) ?: null;
    } catch (Throwable $e) {
        return null;   // sin la migración aplicada, el tipo todavía no existe
    }
}

/**
 * Cuántas fechas de ingreso son sospechosas de ser un marcador de carga.
 *
 * Al importar un padrón desde una hoja de cálculo es normal que las 58 filas
 * queden con la MISMA fecha de ingreso, que no es la real de nadie. Para la
 * regalía eso importa muchísimo: la fecha decide desde qué día se devenga, y
 * con un marcador de julio todo el mundo cobraría media regalía.
 */
function regaliaIngresosSospechosos(int $anio): array
{
    $filas = qAll(
        "SELECT fecha_ingreso, COUNT(*) n FROM empleados
          WHERE estado = 'activo' AND fecha_ingreso IS NOT NULL
          GROUP BY fecha_ingreso HAVING COUNT(*) >= 5 ORDER BY n DESC"
    );
    $out = [];
    foreach ($filas as $f) {
        // Solo molesta si esa fecha cae DENTRO del año que se está calculando:
        // una fecha repetida de 2019 no recorta la regalía de 2026.
        if ((int) substr((string) $f['fecha_ingreso'], 0, 4) !== $anio) continue;
        $out[] = ['fecha' => $f['fecha_ingreso'], 'empleados' => (int) $f['n']];
    }
    return $out;
}
