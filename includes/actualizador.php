<?php
/**
 * Actualizador de la base de datos desde el propio sistema.
 *
 * El despliegue es «Update From Remote» en cPanel, y hasta ahora después había
 * que entrar en phpMyAdmin a importar la migración. Este módulo hace ese
 * segundo paso desde Administración → Actualizar base de datos: comprueba qué
 * le falta a la base, guarda un respaldo en el servidor y aplica el archivo de
 * la entrega, con la misma tabla de verificación que daría phpMyAdmin.
 *
 * Aplica SOLO los archivos de act_paquetes(), que viven en database/ y llegan
 * con el código: nunca SQL escrito en un formulario. Las migraciones son
 * idempotentes, así que repetir no hace daño.
 *
 * Ver DESPLIEGUE.md y docs/CXP-MONEDAS-COTIZACIONES.md.
 */

/** Carpeta de los respaldos automáticos (storage/ no se sirve por web). */
const ACT_DIR_RESPALDOS = __DIR__ . '/../storage/respaldos';

/**
 * Las entregas que sabe aplicar, en orden. Cada una dice qué archivo correr y
 * cómo saber si ya está aplicada (comprobaciones baratas sobre el esquema).
 */
function act_paquetes(): array
{
    return [
        'produccion_2026_10' => [
            'titulo'  => 'Entrega de octubre 2026 — Promotion Cockpit y catálogo en dólares',
            'archivo' => 'actualizacion_produccion_2026_10.sql',
            'checks'  => [
                ['Precios de productos en dólares (P39)',      fn() => act_columna('productos', 'moneda_id')],
                ['Historial de la tasa de cambio (P39)',       fn() => act_tabla('monedas_historial')],
                ['Rastro de promociones en la venta (P39)',    fn() => act_columna('venta_detalles', 'precio_lista')],
                ['KPIs de campañas (P39)',                     fn() => act_tabla('kpi_campanas')],
                ['Configuración del cockpit (P40)',            fn() => act_columna('cockpit_tipos', 'tope_desc_pct')],
                ['Tasa del dólar automática (P41)',            fn() => act_columna('monedas', 'venta_auto')],
                ['Viernes Santo y Corpus en su día (P42)',     fn() => act_feriados_pascua_ok()],
            ],
        ],
    ];
}

function act_tabla(string $tabla): bool
{
    return (int) qVal("SELECT COUNT(*) FROM information_schema.TABLES
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$tabla]) === 1;
}

function act_columna(string $tabla, string $columna): bool
{
    return (int) qVal("SELECT COUNT(*) FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?", [$tabla, $columna]) === 1;
}

/**
 * ¿Hay algún Viernes Santo o Corpus automático sembrado un día antes (el error
 * que corrige la P42)? Sin tabla de feriados no hay nada que corregir.
 */
function act_feriados_pascua_ok(): bool
{
    if (!act_tabla('feriados')) return true;
    require_once __DIR__ . '/jornadas.php';
    foreach (qAll("SELECT fecha, nombre FROM feriados
                    WHERE automatico = 1 AND nombre IN ('Viernes Santo', 'Corpus Christi')") as $f) {
        $bien = array_search($f['nombre'], jorFeriadosRD((int) substr($f['fecha'], 0, 4)), true);
        if ($bien !== false && $bien !== $f['fecha']) return false;
    }
    return true;
}

/**
 * Estado de cada entrega: sus comprobaciones y si falta algo.
 * @return array<string, array{titulo:string, archivo:string, checks:array, pendiente:bool, existe:bool}>
 */
function act_estado(): array
{
    $out = [];
    foreach (act_paquetes() as $clave => $p) {
        $checks = [];
        $pendiente = false;
        foreach ($p['checks'] as [$nombre, $fn]) {
            try { $ok = (bool) $fn(); } catch (Throwable $e) { $ok = false; }
            $checks[] = ['nombre' => $nombre, 'ok' => $ok];
            if (!$ok) $pendiente = true;
        }
        $out[$clave] = [
            'titulo'    => $p['titulo'],
            'archivo'   => $p['archivo'],
            'checks'    => $checks,
            'pendiente' => $pendiente,
            'existe'    => is_file(act_ruta_archivo($p['archivo'])),
        ];
    }
    return $out;
}

/** ¿Le falta algo a la base? Cacheado unos minutos en la sesión: lo mira el aviso de cada página. */
function act_pendiente(bool $refrescar = false): bool
{
    $c = $_SESSION['act_pendiente'] ?? null;
    if (!$refrescar && is_array($c) && $c['t'] > time() - 600) return $c['v'];
    $v = false;
    try {
        foreach (act_estado() as $e) if ($e['pendiente']) { $v = true; break; }
    } catch (Throwable $e) {
        $v = false;   // el aviso nunca tumba una página
    }
    $_SESSION['act_pendiente'] = ['t' => time(), 'v' => $v];
    return $v;
}

function act_ruta_archivo(string $archivo): string
{
    return dirname(__DIR__) . '/database/' . basename($archivo);
}

/* ============================================================
 *  Lectura del archivo SQL
 * ============================================================ */

/**
 * Parte un archivo .sql en sentencias, como lo haría el cliente mysql:
 * respeta DELIMITER (los procedimientos llevan «;» dentro), comillas simples,
 * dobles e invertidas con sus escapes, y quita los comentarios -- , # y /* *\/.
 *
 * @return string[]
 */
function act_partir_sql(string $sql): array
{
    $sentencias = [];
    $actual = '';
    $delim = ';';
    $n = strlen($sql);
    $i = 0;
    $inicioLinea = true;

    while ($i < $n) {
        $c = $sql[$i];

        // DELIMITER solo cuenta al principio de una línea y fuera de todo.
        if ($inicioLinea && trim($actual) === '') {
            if (preg_match('/\G[ \t]*DELIMITER[ \t]+(\S+)[^\n]*(\n|$)/Ai', $sql, $m, 0, $i)) {
                $delim = $m[1];
                $i += strlen($m[0]);
                $actual = '';
                continue;
            }
        }

        // Comentario de línea: «-- » (con espacio o fin de línea) o «#».
        if (($c === '-' && substr($sql, $i, 2) === '--' && ($i + 2 >= $n || ctype_space($sql[$i + 2]))) || $c === '#') {
            $fin = strpos($sql, "\n", $i);
            $i = $fin === false ? $n : $fin;
            continue;
        }
        // Comentario de bloque.
        if ($c === '/' && substr($sql, $i, 2) === '/*') {
            $fin = strpos($sql, '*/', $i + 2);
            $i = $fin === false ? $n : $fin + 2;
            $actual .= ' ';
            continue;
        }
        // Cadenas: se copian enteras, con sus escapes.
        if ($c === "'" || $c === '"' || $c === '`') {
            $j = $i + 1;
            while ($j < $n) {
                if ($sql[$j] === '\\' && $c !== '`') { $j += 2; continue; }
                if ($sql[$j] === $c) {
                    if ($j + 1 < $n && $sql[$j + 1] === $c) { $j += 2; continue; }   // '' dentro de la cadena
                    break;
                }
                $j++;
            }
            $actual .= substr($sql, $i, $j - $i + 1);
            $i = $j + 1;
            $inicioLinea = false;
            continue;
        }
        // Fin de sentencia.
        if (substr($sql, $i, strlen($delim)) === $delim) {
            if (trim($actual) !== '') $sentencias[] = trim($actual);
            $actual = '';
            $i += strlen($delim);
            $inicioLinea = false;
            continue;
        }

        $actual .= $c;
        $inicioLinea = $c === "\n" ? true : ($inicioLinea && ($c === ' ' || $c === "\t"));
        $i++;
    }
    if (trim($actual) !== '') $sentencias[] = trim($actual);
    return $sentencias;
}

/* ============================================================
 *  Aplicar
 * ============================================================ */

/**
 * Guarda un respaldo comprimido en storage/respaldos y devuelve su nombre.
 */
function act_respaldar(): array
{
    if (!is_dir(ACT_DIR_RESPALDOS) && !@mkdir(ACT_DIR_RESPALDOS, 0750, true)) {
        throw new RuntimeException('No se pudo crear la carpeta storage/respaldos. Revisa los permisos de storage/.');
    }
    $nombre = 'antes_de_actualizar_' . date('Ymd_His') . '.sql.gz';
    $bytes = backup_sql_a_archivo(ACT_DIR_RESPALDOS . '/' . $nombre);
    if ($bytes < 100) throw new RuntimeException('El respaldo salió vacío; no se aplica nada sin respaldo.');
    return ['nombre' => $nombre, 'bytes' => $bytes];
}

/** Respaldos automáticos guardados, del más reciente al más viejo. */
function act_respaldos(): array
{
    $out = [];
    foreach (glob(ACT_DIR_RESPALDOS . '/*.sql.gz') ?: [] as $f) {
        $out[] = ['nombre' => basename($f), 'bytes' => (int) filesize($f), 'fecha' => (int) filemtime($f)];
    }
    usort($out, fn($a, $b) => $b['fecha'] <=> $a['fecha']);
    return $out;
}

/**
 * Aplica un archivo de database/ sentencia a sentencia.
 *
 * Usa una conexión propia con prepares EMULADOS: el archivo usa PREPARE /
 * EXECUTE de MySQL, que el protocolo de sentencias preparadas no admite, y la
 * conexión normal del sistema los tiene nativos.
 *
 * Se para en el primer error y dice cuál fue. Lo ya aplicado se queda (el DDL
 * de MySQL no se puede deshacer en una transacción), pero como todo es
 * idempotente, corregir y volver a pulsar termina el trabajo.
 *
 * @return array{ok:bool, sentencias:int, ejecutadas:int, tablas:array, error:?string, sql:?string, segundos:float}
 */
function act_aplicar(string $archivo, ?PDO $pdo = null): array
{
    $ruta = act_ruta_archivo($archivo);
    if (!is_file($ruta)) throw new RuntimeException('No está el archivo database/' . basename($archivo) . '. ¿Se hizo «Update From Remote»?');

    $sentencias = act_partir_sql((string) file_get_contents($ruta));
    // $pdo solo lo pasan las pruebas, para aplicarlo sobre una base de ensayo.
    $pdo ??= act_conexion(DB_NAME);

    // Dos personas pulsando a la vez no pueden aplicar el archivo dos veces en paralelo.
    if ((int) $pdo->query("SELECT GET_LOCK('nexopos_actualizar_bd', 1)")->fetchColumn() !== 1) {
        throw new RuntimeException('Ya hay una actualización en curso. Espera a que termine.');
    }

    $r = ['ok' => true, 'sentencias' => count($sentencias), 'ejecutadas' => 0, 'tablas' => [],
          'error' => null, 'sql' => null, 'segundos' => 0.0];
    $t0 = microtime(true);
    try {
        foreach ($sentencias as $sql) {
            $st = $pdo->query($sql);
            do {
                if ($st->columnCount() > 0) {
                    $filas = $st->fetchAll();
                    if (act_tabla_interesa($filas)) $r['tablas'][] = $filas;
                }
            } while ($st->nextRowset());
            $st->closeCursor();
            $r['ejecutadas']++;
        }
    } catch (Throwable $e) {
        $r['ok'] = false;
        $r['error'] = $e->getMessage();
        $r['sql'] = mb_substr($sql ?? '', 0, 400);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('nexopos_actualizar_bd')");
        $r['segundos'] = round(microtime(true) - $t0, 1);
    }
    return $r;
}

/** Conexión con prepares emulados y la zona horaria del sistema. */
function act_conexion(string $base): PDO
{
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $base . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES   => true,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("SET time_zone = '-04:00'");
    return $pdo;
}

/**
 * Qué resultados del archivo se enseñan. Las migraciones imprimen mensajes
 * sueltos de una celda («promociones.codigo ya existe») que en phpMyAdmin son
 * ruido; las tablas de requisitos y de verificación sí interesan.
 */
function act_tabla_interesa(array $filas): bool
{
    if (!$filas) return false;
    if (count($filas) === 1 && count($filas[0]) === 1) return false;
    return true;
}

/** ¿Alguna celda de los resultados dice FALTA? */
function act_hay_falta(array $tablas): bool
{
    foreach ($tablas as $t) foreach ($t as $fila) foreach ($fila as $v) {
        if (is_string($v) && str_starts_with($v, 'FALTA')) return true;
    }
    return false;
}
