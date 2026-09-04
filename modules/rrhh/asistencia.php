<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/biotime.php';
require_perm('rrhh_asistencia.ver');

$estadosAsis = ['presente', 'ausente', 'tardanza', 'permiso', 'vacaciones', 'licencia'];

/** Calcula horas trabajadas y extra a partir de hora_entrada/hora_salida. */
function calcularHoras(?string $entrada, ?string $salida): array
{
    if (!$entrada || !$salida) return [0.0, 0.0];
    $t1 = strtotime($entrada);
    $t2 = strtotime($salida);
    if ($t1 === false || $t2 === false) return [0.0, 0.0];
    $diff = ($t2 - $t1) / 3600;          // diferencia en horas
    if ($diff < 0) $diff = 0;            // salida antes de entrada → 0
    $horas = round($diff, 2);
    $extra = $horas > 8 ? round($horas - 8, 2) : 0.0;
    return [$horas, $extra];
}

// ---------- Acciones ----------
if (isPost()) {
    verify_csrf();
    require_perm('rrhh_asistencia.registrar');
    $accion = post('accion');

    /* La asistencia de un día que no ha pasado no existe. El selector ya lo
       impide con `max`, pero eso es del navegador: quien mande el formulario a
       mano —o una pestaña abierta desde anoche— se lo salta. */
    $futuro = static function (string $f): bool {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && $f > date('Y-m-d');
    };

    /* -----------------------------------------------------------------------
     *  Corregir un día
     *
     *  Ya no se marca asistencia a mano: la registra el reloj. Lo que queda es
     *  ENMENDAR un día concreto —alguien olvidó ponchar la salida, el aparato
     *  estaba caído—, y eso no es ponchar, es corregir un hecho que ocurrió.
     *
     *  Exige escribir por qué. Una corrección sin motivo es indistinguible de
     *  un dato inventado tres meses después, cuando alguien reclame sus horas.
     * -------------------------------------------------------------------- */
    if ($accion === 'registrar') {
        $empleadoId = postInt('empleado_id');
        $fechaPost  = trim(post('fecha'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaPost)) $fechaPost = date('Y-m-d');
        if ($futuro($fechaPost)) {
            flash('error', 'No se puede registrar la asistencia de un día que todavía no ha pasado.');
            redirect('modules/rrhh/asistencia.php');
        }

        // El empleado debe existir, estar activo y dentro del alcance de sucursal.
        [$wScope, $pScope] = sucursalScope('sucursal_id');
        $emp = qOne(
            "SELECT id, sucursal_id FROM empleados WHERE id = ? AND estado = 'activo' AND $wScope",
            array_merge([$empleadoId], $pScope)
        );

        if (!$emp) {
            flash('error', 'Empleado no válido o sin permiso para esta sucursal.');
            redirect('modules/rrhh/asistencia.php?fecha=' . $fechaPost);
        }

        $estado = in_array(post('estado'), $estadosAsis, true) ? post('estado') : 'presente';

        $ya = qOne("SELECT id, hora_entrada, hora_salida FROM asistencias
                     WHERE empleado_id = ? AND fecha = ?", [$empleadoId, $fechaPost]);

        $entrada = trim(post('hora_entrada')) ?: null;
        $salida  = trim(post('hora_salida')) ?: null;
        [$horas, $extra] = calcularHoras($entrada, $salida);

        $notas = trim(post('notas'));
        if ($notas === '') {
            flash('error', 'Escribe por qué corriges este día. Sin motivo, dentro de tres meses '
                . 'nadie podrá distinguir una corrección legítima de un dato inventado.');
            redirect('modules/rrhh/asistencia.php?fecha=' . $fechaPost);
        }

        $datos = [
            'empleado_id'      => $empleadoId,
            'sucursal_id'      => $emp['sucursal_id'],
            'fecha'            => $fechaPost,
            'hora_entrada'     => $entrada,
            'hora_salida'      => $salida,
            'horas_trabajadas' => $horas,
            'horas_extra'      => $extra,
            'estado'           => $estado,
            'notas'            => $notas,
            // Lo escribe una persona, así que manda ella: la sincronización del
            // reloj respeta lo que tenga `origen = 'manual'`. Sin esta línea, una
            // salida corregida a mano volvía a la del reloj en la pasada
            // siguiente y la corrección se perdía sin que nadie lo viera.
            'origen'           => 'manual',
        ];

        // UPSERT por la clave única (empleado_id, fecha).
        $existe = (int) ($ya['id'] ?? 0);
        if ($existe) {
            unset($datos['empleado_id'], $datos['fecha']);   // no se reescribe la clave
            dbUpdate('asistencias', $datos, 'id = ?', [(int) $existe]);
            audit('rrhh_asistencia', 'corregir',
                "Día corregido (empleado #$empleadoId, $fechaPost): "
                . ($ya['hora_entrada'] ?: '—') . '–' . ($ya['hora_salida'] ?: '—')
                . ' pasa a ' . ($entrada ?: '—') . '–' . ($salida ?: '—') . ". Motivo: $notas",
                ['tabla' => 'asistencias', 'registro_id' => (int) $existe]);
        } else {
            $nid = dbInsert('asistencias', $datos);
            audit('rrhh_asistencia', 'corregir',
                "Día añadido a mano (empleado #$empleadoId, $fechaPost): "
                . ($entrada ?: '—') . '–' . ($salida ?: '—') . ". Motivo: $notas",
                ['tabla' => 'asistencias', 'registro_id' => $nid]);
        }
        flash('success', 'Día corregido. Queda anotado quién lo hizo y por qué.');
        redirect('modules/rrhh/asistencia.php?fecha=' . $fechaPost);
    }

    redirect('modules/rrhh/asistencia.php');
}

// ---------- Datos de la página ----------
$fecha = trim(get('fecha'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) $fecha = date('Y-m-d');
$puedeRegistrar = can('rrhh_asistencia.registrar');

[$wScope, $pScope] = sucursalScope('e.sucursal_id');
$empleados = qAll(
    "SELECT e.id, e.nombre, e.apellido, e.foto,
            p.nombre  AS puesto,
            d.nombre  AS departamento,
            a.id      AS asistencia_id,
            a.hora_entrada, a.hora_salida, a.horas_trabajadas, a.horas_extra,
            a.estado  AS estado_dia, a.notas, a.origen
       FROM empleados e
       LEFT JOIN puestos       p ON p.id = e.puesto_id
       LEFT JOIN departamentos d ON d.id = e.departamento_id
       LEFT JOIN asistencias   a ON a.empleado_id = e.id AND a.fecha = ?
      WHERE e.estado = 'activo' AND $wScope
      ORDER BY e.nombre, e.apellido",
    array_merge([$fecha], $pScope)
);

/* ---------------------------------------------------------------------------
 *  Las marcas del reloj de ese día
 *
 *  La fila de arriba dice «entró a las 10:23 y salió a las 18:02». Eso es el
 *  resumen; lo que de verdad ocurrió fueron cuatro marcas, y la de las 13:25
 *  —salir a almorzar— no aparece por ningún lado. Aquí se enseñan todas, que es
 *  lo único que permite contestar una reclamación.
 * ------------------------------------------------------------------------ */
$marcasDelDia = [];
foreach (qAll("SELECT empleado_id, hora, terminal, verificacion, desfase_min
                 FROM asistencia_marcas
                WHERE fecha = ? AND empleado_id IS NOT NULL
                ORDER BY hora", [$fecha]) as $m) {
    $marcasDelDia[(int) $m['empleado_id']][] = $m;
}

// Y las de quien el reloj registró pero nadie ha emparejado todavía: sus marcas
// existen, no son de nadie, y callarlas sería fingir que ese día no ponchó.
$huerfanas = qAll("SELECT emp_code, nombre_reloj, COUNT(*) n,
                          MIN(hora) AS primera, MAX(hora) AS ultima
                     FROM asistencia_marcas
                    WHERE fecha = ? AND empleado_id IS NULL
                    GROUP BY emp_code, nombre_reloj ORDER BY primera", [$fecha]);

/* ---------------------------------------------------------------------------
 *  El historial completo de marcas
 *
 *  Todo lo que el reloj ha registrado desde que existe, filtrable y paginado.
 *  No se recorta a los últimos N días a propósito: una reclamación de nómina
 *  llega semanas después, y un histórico que empieza «hace un mes» no sirve
 *  para contestarla.
 * ------------------------------------------------------------------------ */
$hDesde = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) get('h_desde')) ? get('h_desde') : '';
$hHasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) get('h_hasta')) ? get('h_hasta') : '';
$hQuien = (int) get('h_empleado');
$hVer   = get('h') === '1' || $hDesde !== '' || $hHasta !== '' || $hQuien > 0;

$hCond = ['1=1']; $hPar = [];
if ($hDesde !== '') { $hCond[] = 'm.fecha >= ?'; $hPar[] = $hDesde; }
if ($hHasta !== '') { $hCond[] = 'm.fecha <= ?'; $hPar[] = $hHasta; }
if ($hQuien > 0)    { $hCond[] = 'm.empleado_id = ?'; $hPar[] = $hQuien; }

// El alcance de sucursal manda también aquí. Las marcas de quien todavía no
// está emparejado no pertenecen a ninguna sucursal, así que solo las ve quien
// puede verlas todas: si no, desaparecerían sin que nadie lo supiera.
[$wH, $pH] = sucursalScope('e.sucursal_id');
$hCond[] = "(e.id IS NULL AND " . (current_sucursal_id() === null ? '1=1' : '1=0') . " OR $wH)";
$hPar = array_merge($hPar, $pH);
$hWhere = implode(' AND ', $hCond);

$hJoin = "FROM asistencia_marcas m LEFT JOIN empleados e ON e.id = m.empleado_id WHERE $hWhere";
$hTotal = (int) qVal("SELECT COUNT(*) $hJoin", $hPar);
$hPg = paginar($hTotal, 100);
$historial = $hVer ? qAll(
    "SELECT m.*, CONCAT(e.nombre,' ',e.apellido) AS quien $hJoin
      ORDER BY m.fecha DESC, m.hora DESC, m.id DESC
      LIMIT {$hPg['porPagina']} OFFSET {$hPg['offset']}", $hPar) : [];

$hEmpleados = qAll("SELECT DISTINCT e.id, e.nombre, e.apellido
                      FROM asistencia_marcas m JOIN empleados e ON e.id = m.empleado_id
                     WHERE $wH ORDER BY e.nombre, e.apellido", $pH);
$hRango = qOne("SELECT MIN(fecha) AS d, MAX(fecha) AS h, COUNT(*) AS n FROM asistencia_marcas");

/* ---------------------------------------------------------------------------
 *  Por qué alguien no tiene marcas
 *
 *  Sin marcado a mano, una fila vacía solo dice «el reloj no registró nada». Y
 *  eso puede ser que no vino, que estaba de vacaciones, o que ponchó en un
 *  aparato que no tiene emparejada su ficha. Decir «ausente» sin saberlo sería
 *  inventarse una falta.
 *
 *  Nexo ya sabe quién estaba de permiso: se cruza con las solicitudes
 *  aprobadas para que la pantalla explique la ausencia en vez de dejarla muda.
 * ------------------------------------------------------------------------ */
$deLicencia = [];
foreach (qAll("SELECT v.empleado_id, v.tipo, v.subtipo
                 FROM vacaciones v
                WHERE v.estado IN ('aprobada','disfrutada')
                  AND ? BETWEEN v.fecha_desde AND v.fecha_hasta", [$fecha]) as $v) {
    $deLicencia[(int) $v['empleado_id']] = $v['tipo'] === 'vacaciones'
        ? 'Vacaciones' : ('Licencia' . ($v['subtipo'] ? ' · ' . $v['subtipo'] : ''));
}

// Quién ponchó pero su ficha no está emparejada: sus marcas existen y no le
// cuentan a nadie. Es la explicación más frecuente de una fila vacía.
$sinDuenoHoy = (int) qVal("SELECT COUNT(DISTINCT emp_code) FROM asistencia_marcas
                            WHERE fecha = ? AND empleado_id IS NULL", [$fecha]);

// KPIs de la fecha seleccionada
//
// Antes contaban «falta marcar», que era una tarea pendiente de una persona.
// Ya no hay nada que teclear: lo que importa es qué sabe el reloj y qué no.
$totalEmpleados = count($empleados);
$conMarcas = $incompletas = $conLicencia = $sinNada = 0;
foreach ($empleados as $emp) {
    $ms = $marcasDelDia[(int) $emp['id']] ?? [];
    if ($ms || $emp['hora_entrada']) {
        $conMarcas++;
        if (!$emp['hora_salida']) $incompletas++;
    } elseif (isset($deLicencia[(int) $emp['id']])) {
        $conLicencia++;
    } else {
        $sinNada++;
    }
}

/**
 * En qué grupo cae el día de esta persona.
 *
 *  Las cuatro categorías son las de los contadores, y salen de lo que el reloj
 *  sabe —no de lo que alguien tecleó—. «Sin marcas» NO es «ausente»: puede ser
 *  que no viniera, o que ponchara en un aparato cuya ficha no está emparejada.
 *  Afirmar una falta sin saberlo es inventarse una.
 */
function grupoDelDia(array $emp, array $marcas, array $deLicencia): string
{
    if ($marcas || $emp['hora_entrada']) return $emp['hora_salida'] ? 'conmarcas' : 'incompleta';
    return isset($deLicencia[(int) $emp['id']]) ? 'licencia' : 'sin';
}

/** El distintivo del día, dicho en lo que se sabe. */
function badgeDelDia(string $grupo, array $emp, array $deLicencia): string
{
    switch ($grupo) {
        case 'conmarcas':  return badge('Ponchó', 'emerald');
        case 'incompleta': return badge('Sin salida', 'amber');
        case 'licencia':   return badge($deLicencia[(int) $emp['id']], 'indigo');
        default:           return '<span class="badge badge-slate">Sin marcas</span>';
    }
}

/** Color del badge según el estado del día. */
function colorEstadoDia(?string $estado): string
{
    return [
        'presente'   => 'emerald',
        'ausente'    => 'rose',
        'tardanza'   => 'amber',
        'permiso'    => 'sky',
        'vacaciones' => 'indigo',
        'licencia'   => 'violet',
    ][$estado] ?? 'slate';
}

/**
 * La jornada de una persona, dibujada.
 *
 *  Antes eran cuatro columnas —entrada, salida, horas y marcas— que había que
 *  leer y restar de cabeza. Aquí van las horas y, debajo, dónde cayeron dentro
 *  del día: quien llegó tarde se ve sin leer, y quien tiene un solo punto es
 *  que no ponchó la salida.
 *
 *  Vive en una función porque la pintan dos sitios —la tabla del ordenador y
 *  las tarjetas del móvil— y dos copias del mismo dibujo se separan a la
 *  primera corrección.
 *
 *  La ventana va de las 5 a las 23; una marca fuera se pega al borde y se avisa
 *  en su título, en vez de desaparecer del dibujo.
 */
function pintarJornada(array $emp, array $marcas): string
{
    $INI = 5 * 60; $FIN = 23 * 60;
    $pos = static function (?string $hhmm) use ($INI, $FIN): ?float {
        if (!$hhmm) return null;
        [$h, $m] = array_map('intval', explode(':', $hhmm));
        return max(0.0, min(100.0, round((($h * 60 + $m) - $INI) / ($FIN - $INI) * 100, 2)));
    };
    $ent = $emp['hora_entrada'] ? substr((string) $emp['hora_entrada'], 0, 5) : null;
    $sal = $emp['hora_salida']  ? substr((string) $emp['hora_salida'], 0, 5)  : null;
    if (!$ent && !$marcas) return '<span class="text-sm text-slate-300">—</span>';

    $pEnt = $pos($ent); $pSal = $pos($sal);
    $h = '<div class="min-w-[11rem]">';

    // Hora de 24, también en el resumen. Antes el resumen decía «10:23 AM» y las
    // marcas «10:23» en la misma fila. En una pantalla donde se restan horas, el
    // am/pm es de donde salen los errores: un 8:00 que en realidad era de noche.
    $h .= '<div class="flex items-baseline gap-2 flex-wrap">';
    $h .= '<span class="font-semibold text-slate-800 tabular-nums">' . e($ent ?: '—') . '</span>';
    $h .= '<span class="text-slate-300">→</span>';
    if ($sal) {
        $h .= '<span class="font-semibold text-slate-800 tabular-nums">' . e($sal) . '</span>';
        if ((float) $emp['horas_trabajadas'] > 0) {
            $h .= '<span class="text-xs text-slate-500">' . e(qty($emp['horas_trabajadas'])) . ' h</span>';
        }
        if ((float) $emp['horas_extra'] > 0) {
            $h .= '<span class="badge badge-amber" title="Horas extra">+' . e(qty($emp['horas_extra'])) . '</span>';
        }
    } elseif ($ent) {
        // El caso más frecuente de este cliente: 10 de 12 días. Con dos rayas
        // parecía que no había pasado nada.
        $h .= '<span class="badge badge-amber">falta la salida</span>';
    } else {
        $h .= '<span class="text-slate-300">—</span>';
    }
    $h .= '</div>';

    if ($marcas || $pEnt !== null) {
        $h .= '<div class="relative h-5 mt-1.5" title="De 05:00 a 23:00">';
        $h .= '<div class="absolute inset-x-0 top-2 h-px bg-slate-200"></div>';
        if ($pEnt !== null && $pSal !== null && $pSal > $pEnt) {
            $h .= '<div class="absolute top-1.5 h-1 rounded-full bg-emerald-200" style="left:'
                . $pEnt . '%;width:' . round($pSal - $pEnt, 2) . '%"></div>';
        }
        foreach ($marcas as $m) {
            $raro = $m['desfase_min'] !== null && abs((int) $m['desfase_min'] + 240) > 15;
            $p = $pos(substr((string) $m['hora'], 0, 5));
            $fuera = $p === 0.0 || $p === 100.0;
            $h .= '<span class="absolute top-1 w-2 h-2 rounded-full ring-2 ring-white '
                . ($raro ? 'bg-amber-500' : 'bg-blue-500') . '" style="left:calc(' . $p . '% - 4px)" title="'
                . e(substr((string) $m['hora'], 0, 5) . ' · ' . ($m['terminal'] ?: 'sin aparato')
                    . ' · ' . bioVerificacion($m['verificacion'])
                    . ($raro ? ' · OJO: el aparato tenía la hora desajustada ' . (int) $m['desfase_min'] . ' min' : '')
                    . ($fuera ? ' · fuera de la franja dibujada' : '')) . '"></span>';
        }
        $h .= '</div><p class="text-[11px] text-slate-400 leading-tight">';
        $h .= $marcas
            ? count($marcas) . ' marca' . (count($marcas) === 1 ? '' : 's') . ': '
              . e(implode(' · ', array_map(fn($x) => substr((string) $x['hora'], 0, 5), $marcas)))
            : 'escrito a mano, sin marcas del reloj';
        $h .= '</p>';
    }
    return $h . '</div>';
}

/** Puesto y departamento, sin decir «sin puesto» cincuenta y siete veces. */
function bajoElNombre(array $emp): string
{
    $p = array_filter([$emp['puesto'] ?? '', $emp['departamento'] ?? '']);
    return $p ? e(implode(' · ', $p)) : '';
}

$esHoy = $fecha === date('Y-m-d');
$acc = can('rrhh_asistencia.registrar')
    ? '<a href="' . e(url('modules/rrhh/ponche.php')) . '" class="btn btn-soft">'
      . icon('pulse', 'w-4 h-4') . ' Reloj biométrico</a>' : '';
layout_start('Asistencia', 'Lo que registró el reloj biométrico, día a día', $acc);
?>

<?php /* Buscar y filtrar se hacen en el navegador: son 56 filas ya cargadas y
         el trabajo aquí es marcar a todo el mundo, no paginar. Recargar la
         página por cada filtro perdería el sitio donde ibas. */ ?>
<div x-data="{
       buscar: '',
       filtro: '',
       /* Se cuenta aplicando el MISMO filtro, no preguntando al DOM quién está
          escondido: `x-show` pone `display:none`, no el atributo `hidden`. Y
          solo las filas de la TABLA: en el móvil cada persona sale también como
          tarjeta, y contar las dos listas daría el doble. */
       get visibles() {
         return [...this.$root.querySelectorAll('tbody tr[data-busca]')]
                  .filter(f => this.coincide(f)).length;
       },
       coincide(fila) {
         const t = this.buscar.trim().toLowerCase();
         const okTexto  = t === '' || fila.dataset.busca.includes(t);
         const okEstado = this.filtro === '' || fila.dataset.estado === this.filtro;
         return okTexto && okEstado;
       }
     }">

<!-- Selector de fecha + KPIs -->
<div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-6">
  <form method="get" class="flex items-end gap-3">
    <div>
      <label class="label">Fecha</label>
      <input type="date" name="fecha" value="<?= e($fecha) ?>" max="<?= date('Y-m-d') ?>"
             class="input" onchange="this.form.submit()">
    </div>
    <button type="submit" class="btn btn-soft"><?= icon('calendar', 'w-4 h-4') ?> Ver</button>
  </form>

  <?php /* Marcar asistencia es ir día a día. Abrir el calendario para retroceder
           uno es un clic de más repetido todos los días. El de mañana no existe:
           la asistencia de un día que no ha pasado no se puede registrar. */ ?>
  <div class="flex items-center gap-1">
    <a href="<?= e(url('modules/rrhh/asistencia.php?fecha=' . date('Y-m-d', strtotime($fecha . ' -1 day')))) ?>"
       class="btn btn-ghost btn-sm" title="Día anterior"><?= icon('chevron-down', 'w-4 h-4 rotate-90') ?></a>
    <?php $manana = date('Y-m-d', strtotime($fecha . ' +1 day')); ?>
    <?php if ($manana <= date('Y-m-d')): ?>
      <a href="<?= e(url('modules/rrhh/asistencia.php?fecha=' . $manana)) ?>"
         class="btn btn-ghost btn-sm" title="Día siguiente"><?= icon('chevron-down', 'w-4 h-4 -rotate-90') ?></a>
    <?php else: ?>
      <span class="btn btn-ghost btn-sm opacity-30 cursor-not-allowed" title="Mañana todavía no"><?= icon('chevron-down', 'w-4 h-4 -rotate-90') ?></span>
    <?php endif; ?>
    <?php if (!$esHoy): ?>
      <a href="<?= url('modules/rrhh/asistencia.php') ?>" class="btn btn-ghost btn-sm">Hoy</a>
    <?php endif; ?>
    <?php /* El día de la semana, escrito. Marcar asistencia un domingo casi
             siempre es que te equivocaste de fecha, y «2026-09-06» no lo dice. */ ?>
    <?php $DIAS = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado']; ?>
    <span class="ml-2 text-sm <?= (int) date('w', strtotime($fecha)) === 0 ? 'text-amber-600 font-semibold' : 'text-slate-500' ?>">
      <?= e($DIAS[(int) date('w', strtotime($fecha))]) ?>
    </span>
  </div>
  </form>

  <?php /* Las tarjetas son además los filtros: la cifra que llama la atención
           es la que quieres aislar. Y ya no cuentan «lo que falta teclear»,
           porque no hay nada que teclear: cuentan lo que el reloj sabe. */ ?>
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
    <button type="button" @click="filtro = filtro === 'conmarcas' ? '' : 'conmarcas'"
            class="card px-4 py-3 text-left transition hover:border-emerald-300"
            :class="filtro === 'conmarcas' ? 'ring-2 ring-emerald-400 border-emerald-300' : ''">
      <div class="text-xs text-slate-400 font-medium">Poncharon</div>
      <div class="text-2xl font-bold text-emerald-600"><?= $conMarcas ?></div>
    </button>

    <button type="button" @click="filtro = filtro === 'incompleta' ? '' : 'incompleta'"
            class="card px-4 py-3 text-left transition hover:border-amber-300"
            :class="filtro === 'incompleta' ? 'ring-2 ring-amber-400 border-amber-300' : ''"
            title="Entraron pero no poncharon la salida: ese día no tiene horas">
      <div class="text-xs <?= $incompletas > 0 ? 'text-amber-600 font-semibold' : 'text-slate-400 font-medium' ?>">Sin salida</div>
      <div class="text-2xl font-bold <?= $incompletas > 0 ? 'text-amber-600' : 'text-slate-300' ?>"><?= $incompletas ?></div>
    </button>

    <button type="button" @click="filtro = filtro === 'licencia' ? '' : 'licencia'"
            class="card px-4 py-3 text-left transition hover:border-indigo-300"
            :class="filtro === 'licencia' ? 'ring-2 ring-indigo-400 border-indigo-300' : ''"
            title="De vacaciones o con licencia aprobada: por eso no hay marcas">
      <div class="text-xs text-slate-400 font-medium">De permiso</div>
      <div class="text-2xl font-bold text-indigo-600"><?= $conLicencia ?></div>
    </button>

    <button type="button" @click="filtro = filtro === 'sin' ? '' : 'sin'"
            class="card px-4 py-3 text-left transition hover:border-slate-300"
            :class="filtro === 'sin' ? 'ring-2 ring-slate-400 border-slate-300' : ''"
            title="El reloj no registró nada suyo. No quiere decir que no vinieran">
      <div class="text-xs text-slate-400 font-medium">Sin marcas</div>
      <div class="text-2xl font-bold text-slate-500"><?= $sinNada ?></div>
    </button>
  </div>
</div>

<?php if ($sinDuenoHoy > 0 && can('rrhh_asistencia.registrar')): ?>
  <div class="card p-4 mb-4 border-l-4 border-amber-400 flex items-start gap-3">
    <?= icon('alert', 'w-5 h-5 text-amber-500 shrink-0 mt-0.5') ?>
    <p class="text-sm text-slate-600">
      Hoy poncharon <strong><?= $sinDuenoHoy ?></strong> persona(s) cuyo código del reloj no
      está emparejado con nadie de Nexo, así que su asistencia no le cuenta a nadie.
      <a class="link" href="<?= e(url('modules/rrhh/ponche.php')) ?>">Emparejarlas</a>.
    </p>
  </div>
<?php endif; ?>

<div class="card overflow-hidden">
  <div class="p-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
    <h3 class="font-semibold text-slate-700">Asistencia del <?= e(fechaCorta($fecha)) ?></h3>
    <div class="flex items-center gap-3 flex-wrap">
      <div class="relative min-w-[220px]">
        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"><?= icon('search', 'w-4 h-4') ?></span>
        <input type="text" x-model="buscar" placeholder="Buscar empleado..." class="input pl-9" autocomplete="off">
      </div>
      <button type="button" x-show="filtro !== '' || buscar !== ''" x-cloak
              @click="filtro = ''; buscar = ''" class="btn btn-ghost btn-sm">Quitar filtros</button>
      <span class="text-sm text-slate-400 whitespace-nowrap">
        <span x-text="visibles"></span> de <?= $totalEmpleados ?>
      </span>
    </div>
  </div>

  <?php if (!$empleados): ?>
    <?= empty_state('Sin empleados activos', 'No hay empleados activos en esta sucursal para registrar asistencia.', 'id') ?>
  <?php else: ?>
    <?php /* En el móvil una tabla de cuatro columnas se va de lado y solo se ve
             el nombre: no se puede marcar a nadie sin desplazarse en horizontal, y
             esta pantalla se usa de pie, viendo llegar a la gente. De ahí para
             abajo se pinta en tarjetas, con los mismos badges y los mismos
             botones para que no parezca otra aplicación. */ ?>
    <div class="hidden md:block overflow-x-auto">
      <table class="data-table">
        <thead>
          <?php /* Eran nueve columnas y no cabían: la tabla se iba de lado y los
                   botones —que son a lo que se viene— quedaban fuera de pantalla.
                   Puesto y departamento bajan a subtítulo del nombre, y entrada,
                   salida, horas y marcas se juntan: son UNA cosa, la jornada. */ ?>
          <tr>
            <th>Empleado</th>
            <th class="w-32">Estado</th>
            <th>La jornada</th>
            <?php if ($puedeRegistrar): ?><th class="text-right w-24"></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($empleados as $emp): ?>
            <?php
              $nombreCompleto = $emp['nombre'] . ' ' . $emp['apellido'];
              $busca = mb_strtolower($nombreCompleto . ' ' . ($emp['puesto'] ?? '') . ' ' . ($emp['departamento'] ?? ''));
            ?>
            <?php $grupo = grupoDelDia($emp, $marcasDelDia[(int) $emp['id']] ?? [], $deLicencia); ?>
            <tr data-busca="<?= e($busca) ?>" data-estado="<?= e($grupo) ?>"
                data-id="<?= (int) $emp['id'] ?>" x-show="coincide($el)"
                >
              <td>
                <div class="flex items-center gap-3">
                  <?= avatar($nombreCompleto) ?>
                  <div class="min-w-0">
                    <p class="font-semibold text-slate-700 truncate"><?= e($nombreCompleto) ?></p>
                    <?php if ($sub = bajoElNombre($emp)): ?>
                      <p class="text-xs text-slate-400 truncate"><?= $sub ?></p>
                    <?php endif; ?>
                  </div>
                </div>
              </td>
              <td>
                <?= badgeDelDia($grupo, $emp, $deLicencia) ?>
                <?php if ($emp['origen'] === 'manual' && ($emp['hora_entrada'] || $emp['notas'])): ?>
                  <span class="block text-[11px] text-slate-400 mt-0.5" title="<?= e((string) $emp['notas']) ?>">corregido a mano</span>
                <?php endif; ?>
              </td>

              <td><?= pintarJornada($emp, $marcasDelDia[(int) $emp['id']] ?? []) ?></td>
              <?php if ($puedeRegistrar): ?>
                <?php /* Ya no se marca asistencia: la registra el reloj. Lo único
                         que queda es enmendar un día concreto, y eso exige motivo. */ ?>
                <td class="text-right">
                  <button onclick="<?= jsEvent('asis:detalle', [
                      'empleado_id'  => $emp['id'],
                      'nombre'       => $nombreCompleto,
                      'hora_entrada' => $emp['hora_entrada'] ? substr((string) $emp['hora_entrada'], 0, 5) : '',
                      'hora_salida'  => $emp['hora_salida'] ? substr((string) $emp['hora_salida'], 0, 5) : '',
                      'estado'       => $emp['estado_dia'] ?: 'presente',
                      'notas'        => $emp['notas'] ?: '',
                  ]) ?>" class="btn btn-ghost btn-sm" title="Corregir este día">
                    <?= icon('edit', 'w-4 h-4') ?> Corregir
                  </button>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="md:hidden divide-y divide-slate-100">
      <?php foreach ($empleados as $emp):
        $nombreCompleto = $emp['nombre'] . ' ' . $emp['apellido'];
        $busca = mb_strtolower($nombreCompleto . ' ' . ($emp['puesto'] ?? '') . ' ' . ($emp['departamento'] ?? '')); ?>
        <?php $grupo = grupoDelDia($emp, $marcasDelDia[(int) $emp['id']] ?? [], $deLicencia); ?>
        <div data-busca="<?= e($busca) ?>" data-estado="<?= e($grupo) ?>"
             x-show="coincide($el)" class="p-4"
             >
          <div class="flex items-start gap-3">
            <?= avatar($nombreCompleto) ?>
            <div class="min-w-0 flex-1">
              <div class="flex items-start justify-between gap-2">
                <p class="font-semibold text-slate-700 leading-tight"><?= e($nombreCompleto) ?></p>
                <span class="shrink-0"><?= badgeDelDia($grupo, $emp, $deLicencia) ?></span>
              </div>
              <?php if ($sub = bajoElNombre($emp)): ?>
                <p class="text-xs text-slate-400"><?= $sub ?></p>
              <?php endif; ?>
              <?php /* En la tarjeta, un «—» suelto solo deja un hueco entre el
                       departamento y los botones. En la tabla sí hace falta, porque
                       una celda vacía descuadra la fila. */ ?>
              <?php $ms = $marcasDelDia[(int) $emp['id']] ?? []; ?>
              <?php if ($emp['hora_entrada'] || $ms): ?>
                <div class="mt-2"><?= pintarJornada($emp, $ms) ?></div>
              <?php endif; ?>

              <?php if ($puedeRegistrar): ?>
                <div class="mt-3">
                  <button onclick="<?= jsEvent('asis:detalle', [
                      'empleado_id'  => $emp['id'],
                      'nombre'       => $nombreCompleto,
                      'hora_entrada' => $emp['hora_entrada'] ? substr((string) $emp['hora_entrada'], 0, 5) : '',
                      'hora_salida'  => $emp['hora_salida'] ? substr((string) $emp['hora_salida'], 0, 5) : '',
                      'estado'       => $emp['estado_dia'] ?: 'presente',
                      'notas'        => $emp['notas'] ?: '',
                  ]) ?>" class="btn btn-ghost btn-sm"><?= icon('edit', 'w-4 h-4') ?> Corregir este día</button>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div x-show="visibles === 0" x-cloak class="px-5 py-12 text-center">
      <p class="font-semibold text-slate-700">Ningún empleado coincide</p>
      <p class="text-sm text-slate-400 mt-1">Prueba con otro nombre o quita el filtro.</p>
      <button type="button" @click="filtro = ''; buscar = ''" class="btn btn-soft btn-sm mt-4">Quitar filtros</button>
    </div>
  <?php endif; ?>
</div>

</div><!-- /buscador y filtros -->

<?php if ($puedeRegistrar): ?>
<!-- Modal detalle de asistencia -->
<div x-data="{open:false, form:{empleado_id:0, nombre:'', hora_entrada:'', hora_salida:'', estado:'presente', notas:''}}"
     @asis:detalle.window="form=$event.detail; open=true"
     @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-md" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="registrar">
        <input type="hidden" name="empleado_id" :value="form.empleado_id">
        <input type="hidden" name="fecha" value="<?= e($fecha) ?>">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <div>
            <h3 class="font-bold text-slate-800">Corregir el día</h3>
            <p class="text-xs text-slate-400" x-text="form.nombre + ' · <?= e(fechaCorta($fecha)) ?>'"></p>
          </div>
          <button type="button" @click="open=false" aria-label="Cerrar modal" title="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 space-y-4">
          <p class="text-xs text-slate-500 bg-slate-50 border border-slate-200 rounded-xl p-3">
            La asistencia la registra el reloj. Esto es para enmendar un día concreto
            —alguien olvidó ponchar la salida, el aparato estaba caído—, y queda anotado
            quién lo hizo y por qué.
          </p>
          <div>
            <label class="label">Estado del día</label>
            <select name="estado" x-model="form.estado" class="select">
              <option value="presente">Presente</option>
              <option value="ausente">Ausente</option>
              <option value="tardanza">Tardanza</option>
              <option value="permiso">Permiso</option>
              <option value="vacaciones">Vacaciones</option>
              <option value="licencia">Licencia</option>
            </select>
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="label">Hora de entrada</label>
              <input type="time" name="hora_entrada" x-model="form.hora_entrada" class="input">
            </div>
            <div>
              <label class="label">Hora de salida</label>
              <input type="time" name="hora_salida" x-model="form.hora_salida" class="input">
            </div>
          </div>
          <p class="text-xs text-slate-400">Las horas trabajadas y las horas extra (sobre 8h) se calculan automáticamente.</p>
          <div>
            <label class="label">Por qué lo corriges *</label>
            <textarea name="notas" x-model="form.notas" rows="2" class="input" required
                      placeholder="Ej. olvidó ponchar la salida; lo confirmó su encargada"></textarea>
            <p class="text-xs text-slate-400 mt-1">
              Sin motivo, dentro de tres meses nadie podrá distinguir una corrección
              legítima de un dato inventado.
            </p>
          </div>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button type="submit" class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>


<?php /* ---------- Lo que el reloj registró y no es de nadie ---------- */ ?>
<?php if ($huerfanas): ?>
  <div class="card p-5 mt-6 border-l-4 border-amber-400">
    <h3 class="font-bold text-slate-800 mb-1">
      <?= icon('alert', 'w-5 h-5 inline -mt-1 text-amber-500') ?>
      El reloj registró marcas que no son de nadie
    </h3>
    <p class="text-sm text-slate-600 mb-3">
      Estas personas ponchan en el reloj pero no están emparejadas con nadie de Nexo,
      así que su asistencia no se está contando.
      <?php if (can('rrhh_asistencia.registrar')): ?>
        <a class="link" href="<?= e(url('modules/rrhh/ponche.php')) ?>">Emparejarlas</a>.
      <?php endif; ?>
    </p>
    <div class="flex flex-wrap gap-2">
      <?php foreach ($huerfanas as $o): ?>
        <span class="px-2.5 py-1 rounded-lg bg-amber-50 border border-amber-200 text-sm">
          <strong><?= e($o['nombre_reloj'] ?: 'código ' . $o['emp_code']) ?></strong>
          <span class="text-xs text-amber-700">
            · <?= (int) $o['n'] ?> marca(s) · <?= e(substr((string) $o['primera'], 0, 5)) ?>
            <?= $o['primera'] !== $o['ultima'] ? '→ ' . e(substr((string) $o['ultima'], 0, 5)) : '' ?>
          </span>
        </span>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<?php /* ---------- El histórico entero ---------- */ ?>
<div class="card mt-6" id="historial">
  <div class="px-5 py-4 border-b border-slate-100 flex items-start justify-between gap-4 flex-wrap">
    <div class="flex items-center gap-3">
      <span class="w-10 h-10 rounded-xl bg-slate-100 text-slate-600 flex items-center justify-center shrink-0"><?= icon('history', 'w-5 h-5') ?></span>
      <div>
        <h3 class="font-bold text-slate-800">Historial de marcas</h3>
        <p class="text-xs text-slate-500">
          Cada ponche tal como lo registró el aparato, sin resumir.
          <?php if ($hRango && $hRango['n'] > 0): ?>
            <?= number_format((int) $hRango['n']) ?> marca(s) desde el <?= e(fechaCorta($hRango['d'])) ?>.
          <?php else: ?>
            Todavía no hay ninguna.
          <?php endif; ?>
        </p>
      </div>
    </div>
  </div>

  <form method="get" class="px-5 py-4 border-b border-slate-100 flex flex-wrap items-end gap-3">
    <input type="hidden" name="fecha" value="<?= e($fecha) ?>">
    <input type="hidden" name="h" value="1">
    <div><label class="label">Desde</label><input type="date" name="h_desde" value="<?= e($hDesde) ?>" class="input"></div>
    <div><label class="label">Hasta</label><input type="date" name="h_hasta" value="<?= e($hHasta) ?>" class="input"></div>
    <div>
      <label class="label">Empleado</label>
      <select name="h_empleado" class="input min-w-56">
        <option value="0">Todos</option>
        <?php foreach ($hEmpleados as $he): ?>
          <option value="<?= (int) $he['id'] ?>" <?= $hQuien === (int) $he['id'] ? 'selected' : '' ?>>
            <?= e(trim($he['nombre'] . ' ' . $he['apellido'])) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn-primary"><?= icon('search', 'w-4 h-4') ?> Consultar</button>
    <?php if ($hVer): ?>
      <a href="<?= e(url('modules/rrhh/asistencia.php?fecha=' . $fecha)) ?>" class="btn btn-ghost">Limpiar</a>
    <?php endif; ?>
  </form>

  <?php if (!$hVer): ?>
    <p class="px-5 py-8 text-center text-sm text-slate-400">
      Elige un rango o una persona y pulsa «Consultar».
      <a class="link" href="<?= e(url('modules/rrhh/asistencia.php?fecha=' . $fecha . '&h=1')) ?>">O mira las últimas 100 marcas</a>.
    </p>
  <?php elseif (!$historial): ?>
    <p class="px-5 py-8 text-center text-sm text-slate-400">No hay marcas con ese filtro.</p>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr>
          <th>Fecha</th><th>Hora</th><th>Empleado</th><th>Aparato</th>
          <th>Cómo se identificó</th><th>Hora del aparato</th>
        </tr></thead>
        <tbody>
          <?php foreach ($historial as $m):
            $raro = $m['desfase_min'] !== null && abs((int) $m['desfase_min'] + 240) > 15; ?>
            <tr>
              <td class="whitespace-nowrap text-slate-600"><?= e(fechaCorta($m['fecha'])) ?></td>
              <td class="font-semibold text-slate-800"><?= e(substr((string) $m['hora'], 0, 5)) ?></td>
              <td>
                <?php if ($m['quien']): ?>
                  <span class="text-slate-700"><?= e($m['quien']) ?></span>
                <?php else: ?>
                  <span class="text-amber-700"><?= e($m['nombre_reloj'] ?: '—') ?></span>
                  <span class="badge badge-amber ml-1">sin emparejar</span>
                <?php endif; ?>
                <span class="block text-xs text-slate-400">código <?= e($m['emp_code']) ?></span>
              </td>
              <td class="text-slate-500 text-sm"><?= e($m['terminal'] ?: '—') ?></td>
              <td class="text-slate-500 text-sm"><?= e(bioVerificacion($m['verificacion'])) ?></td>
              <td>
                <?php if ($m['desfase_min'] === null): ?>
                  <span class="text-xs text-slate-300">—</span>
                <?php elseif ($raro): ?>
                  <span class="badge badge-amber" title="Debería ser −240 min (UTC−4). Este aparato tenía la hora mal puesta, así que esta marca no es de fiar.">
                    desajustado <?= (int) $m['desfase_min'] > 0 ? '+' : '' ?><?= (int) $m['desfase_min'] ?> min
                  </span>
                <?php else: ?>
                  <span class="text-xs text-emerald-600">en hora</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= paginacion($hPg) ?>
    <p class="px-5 py-3 text-xs text-slate-400 border-t border-slate-100">
      «Hora del aparato» compara la hora de la marca con la de subida al servidor.
      En República Dominicana la diferencia tiene que ser de −240 minutos; cuando no lo es,
      ese aparato tenía el reloj mal puesto y su hora no se puede dar por buena.
    </p>
  <?php endif; ?>
</div>

<?php layout_end(); ?>
