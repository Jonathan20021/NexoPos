<?php
/**
 * Monedas y tasa de cambio.
 *
 * REGLA QUE NO SE ROMPE: la contabilidad de este sistema vive en pesos. Todos
 * los importes de `transacciones`, reportes, DGII e IT-1 siguen siendo RD$ igual
 * que antes de existir este archivo. Lo que se añade es la capacidad de
 * REGISTRAR lo que se pactó en otra moneda y a qué tasa, para dos cosas:
 *
 *   · saber cuánto se debe de verdad en dólares (una deuda en USD no se
 *     congela: sube y baja con el dólar);
 *   · calcular la diferencia cambiaria al pagar, que es un resultado real del
 *     negocio y hoy se estaba perdiendo.
 *
 * Por eso los documentos guardan SIEMPRE las dos caras: el importe en su moneda
 * y el equivalente en pesos a la tasa del día. Convertir al vuelo en los
 * reportes habría hecho que el pasado cambiara cada vez que se mueve el dólar.
 */

/** ¿Está aplicada la migración P11? */
function mon_disponible(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = (bool) qVal("SHOW TABLES LIKE 'monedas'");
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

/** Todas las monedas activas, indexadas por id. */
function monedas(bool $soloActivas = true, bool $refrescar = false): array
{
    static $cache = [];
    $k = $soloActivas ? 'act' : 'todas';
    // Tras escribir una tasa hay que releer: dentro de la misma petición, la
    // estática seguiría sirviendo la tasa vieja y se cobraría con ella.
    if ($refrescar) $cache = [];
    if (isset($cache[$k])) return $cache[$k];
    if (!mon_disponible()) return $cache[$k] = [];

    $where = $soloActivas ? 'WHERE activo = 1' : '';
    $out = [];
    foreach (qAll("SELECT * FROM monedas $where ORDER BY es_base DESC, codigo") as $m) {
        $out[(int) $m['id']] = $m;
    }
    return $cache[$k] = $out;
}

/** La moneda base (peso). Es la única con tasa 1 y la que usa la contabilidad. */
function monedaBase(): array
{
    foreach (monedas(false) as $m) {
        if ((int) $m['es_base'] === 1) return $m;
    }
    // Respaldo si la migración no está aplicada: el sistema sigue funcionando.
    return ['id' => 0, 'codigo' => 'DOP', 'nombre' => 'Peso dominicano',
            'simbolo' => setting('moneda', 'RD$'), 'tasa' => 1, 'es_base' => 1, 'activo' => 1];
}

/** Una moneda por id, o la base si no existe. */
function moneda(?int $id): array
{
    $todas = monedas(false);
    return ($id && isset($todas[$id])) ? $todas[$id] : monedaBase();
}

/** ¿El sistema tiene alguna moneda extranjera activa? Decide si se ven los selectores. */
function mon_hayExtranjeras(): bool
{
    foreach (monedas() as $m) {
        if ((int) $m['es_base'] !== 1) return true;
    }
    return false;
}

/** Tasa vigente de una moneda (pesos por unidad). */
function mon_tasa(?int $monedaId): float
{
    $m = moneda($monedaId);
    return max(0.000001, (float) $m['tasa']);
}

/** Convierte un importe de una moneda a pesos con la tasa indicada. */
function mon_aBase(float $monto, float $tasa): float
{
    return round($monto * max(0.000001, $tasa), 2);
}

/** Convierte de pesos a una moneda. */
function mon_desdeBase(float $montoBase, float $tasa): float
{
    return round($montoBase / max(0.000001, $tasa), 2);
}

/**
 * Formatea un importe en su moneda: `US$ 1,250.00`.
 * Para pesos usa money(), que ya respeta el símbolo configurado en Empresa.
 */
function mon_money(float $monto, ?int $monedaId = null): string
{
    $m = moneda($monedaId);
    if ((int) $m['es_base'] === 1) return money($monto);
    return $m['simbolo'] . ' ' . number_format($monto, 2, '.', ',');
}

/**
 * Etiqueta de equivalencia para poner debajo de un importe en moneda extranjera.
 * Ej.: «≈ RD$ 75,000.00 · tasa 60.00»
 */
function mon_equivalencia(float $monto, ?int $monedaId, float $tasa): string
{
    $m = moneda($monedaId);
    if ((int) $m['es_base'] === 1) return '';
    return '≈ ' . money(mon_aBase($monto, $tasa))
         . ' · tasa ' . rtrim(rtrim(number_format($tasa, 4, '.', ','), '0'), '.');
}

/**
 * Guarda la TASA DE VENTA de una moneda: la que cobra el POS y proponen los
 * documentos. Siempre es un acto humano, nunca la escribe el descargador.
 *
 * Además de guardarla sella `tasa_revisada_at`. Esa fecha es la que permite
 * avisar de que hace semanas que nadie mira la tasa, que es el verdadero
 * riesgo de tener una tasa fija: no que esté mal, sino que se olvide.
 *
 * Devuelve la tasa aplicada.
 */
function mon_actualizarTasa(int $monedaId, float $tasa, ?string $nota = null): float
{
    $m = moneda($monedaId);
    if ((int) $m['es_base'] === 1) return 1.0;   // la base no se toca nunca
    $tasa = max(0.000001, round($tasa, 6));

    $datos = ['tasa' => $tasa];
    if (mon_p39()) $datos['tasa_revisada_at'] = date('Y-m-d H:i:s');
    dbUpdate('monedas', $datos, 'id = ?', [$monedaId]);
    monedas(true, true);   // la caché estática ya no vale

    mon_historial_registrar($monedaId, $tasa, 'manual', 'venta', null, $nota);
    return $tasa;
}

/**
 * Confirma la tasa de venta sin cambiarla: «la miré y sigue bien».
 *
 * Existe porque la alerta de tasa rancia mide cuándo se revisó, no cuándo
 * cambió. Sin esto, a quien decide mantener la tasa no le quedaba más forma de
 * callar el aviso que escribir el mismo número otra vez.
 */
function mon_confirmarTasa(int $monedaId): void
{
    if (!mon_p39()) return;
    $m = moneda($monedaId);
    if ((int) $m['es_base'] === 1) return;
    dbUpdate('monedas', ['tasa_revisada_at' => date('Y-m-d H:i:s')], 'id = ?', [$monedaId]);
    mon_historial_registrar($monedaId, (float) $m['tasa'], 'manual', 'venta', null, 'Revisada sin cambios');
}

/* ============================================================
 *  P39 · LA MONEDA DEL PRODUCTO Y LA TASA DE MERCADO
 *
 *  Hay DOS tasas y conviene no confundirlas nunca:
 *
 *   · `tasa`         — la TASA DE VENTA. Es la que cobra el POS y la que
 *                      proponen los documentos. Se cambia a mano y NO se mueve
 *                      sola: el precio de góndola no puede cambiar cada mañana
 *                      porque el dólar se movió diez centavos, y una etiqueta
 *                      impresa dejaría de cuadrar con la caja el mismo día.
 *   · `tasa_mercado` — la REFERENCIA que se descarga sola. No cobra nada.
 *                      Existe para avisar cuando las dos se separan y para que
 *                      nadie tenga que ir a buscar el dólar a una web.
 * ============================================================ */

/** Cada cuántas horas se intenta refrescar la tasa de mercado. */
const MON_REFRESCO_HORAS = 12;
/** Diferencia entre tasa de venta y mercado que dispara el aviso (%). */
const MON_ALERTA_PCT = 3.0;
/** Días sin que nadie confirme la tasa de venta antes de avisar. */
const MON_ALERTA_DIAS = 20;
/** Cuánto puede alejarse una tasa descargada de la conocida antes de descartarla (%). */
const MON_TOPE_SALTO_PCT = 25.0;

/** ¿Está aplicada la P39? El módulo funciona sin ella, solo sin estas funciones. */
function mon_p39(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    if (!mon_disponible()) return $ok = false;
    try {
        $ok = (int) qVal("SELECT COUNT(*) FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'monedas'
                             AND COLUMN_NAME = 'tasa_mercado'") === 1;
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

/* ------------------------------------------------------------
 *  El precio de un producto
 * ------------------------------------------------------------ */

/**
 * Pasa a pesos el precio de un producto, según la moneda en que esté.
 *
 * Es la única puerta: cualquier sitio que muestre o cobre un precio de catálogo
 * tiene que pasar por aquí. Un producto sin `moneda_id` está en pesos y la
 * función lo devuelve intacto, que es como se comportaba todo antes de la P39.
 *
 * @param array|float $producto  la fila del producto, o el importe suelto
 * @param int|null    $monedaId  solo si se pasó un importe suelto
 */
function mon_precio_base($producto, ?int $monedaId = null): float
{
    if (is_array($producto)) {
        $monto    = (float) ($producto['precio_venta'] ?? 0);
        $monedaId = isset($producto['moneda_id']) && $producto['moneda_id'] !== null
            ? (int) $producto['moneda_id'] : null;
    } else {
        $monto = (float) $producto;
    }
    if (!$monedaId || !mon_disponible()) return round($monto, 2);
    $m = moneda($monedaId);
    if ((int) $m['es_base'] === 1) return round($monto, 2);
    return mon_aBase($monto, (float) $m['tasa']);
}

/** Igual que la anterior pero para el costo. */
function mon_costo_base($producto, ?int $monedaId = null): float
{
    if (is_array($producto)) {
        return mon_precio_base(
            (float) ($producto['precio_compra'] ?? 0),
            isset($producto['moneda_id']) && $producto['moneda_id'] !== null ? (int) $producto['moneda_id'] : null
        );
    }
    return mon_precio_base((float) $producto, $monedaId);
}

/**
 * El camino de vuelta: un costo en pesos (el de una compra o una liquidación)
 * pasado a la moneda en que está el producto, para guardarlo en
 * `productos.precio_compra`. Sin esto un producto en dólares acaba con el
 * costo en pesos escrito como si fueran dólares, y su margen se dispara.
 *
 * @param float|null $tasa la tasa pactada en el documento, si es de la misma moneda
 */
function mon_costo_a_producto(float $montoBase, ?int $monedaId, ?float $tasa = null): float
{
    if (!$monedaId || !mon_disponible()) return round($montoBase, 2);
    $m = moneda($monedaId);
    if ((int) $m['es_base'] === 1) return round($montoBase, 2);
    return round($montoBase / max(0.000001, $tasa ?: (float) $m['tasa']), 2);
}

/**
 * La moneda que una persona escribió en un archivo o un formulario: «USD»,
 * «US$», «dólares», «RD$», «DOP», «pesos»… Devuelve el id que se guarda en
 * productos.moneda_id: null para la moneda base (pesos, como guarda todo el
 * sistema) y false si no se reconoce. Solo monedas activas.
 *
 * @return int|null|false
 */
function mon_resolver(string $texto)
{
    $t = mb_strtolower(trim($texto));
    if ($t === '') return null;
    $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
    $sinons = ['dolar' => 'USD', 'dolares' => 'USD', 'dollar' => 'USD', 'dollars' => 'USD', 'us dollar' => 'USD', 'usd$' => 'USD', '$us' => 'USD', 'u$s' => 'USD',
               'euro' => 'EUR', 'euros' => 'EUR',
               'peso' => 'DOP', 'pesos' => 'DOP', 'peso dominicano' => 'DOP', 'pesos dominicanos' => 'DOP', 'rd' => 'DOP', 'rds' => 'DOP', 'rd $' => 'DOP'];
    $t = $sinons[$t] ?? $t;
    foreach (monedas() as $m) {
        foreach ([$m['codigo'], $m['simbolo'], $m['nombre']] as $v) {
            if ($v !== null && $v !== '' && mb_strtolower(trim((string) $v)) === mb_strtolower($t)) {
                return (int) $m['es_base'] === 1 ? null : (int) $m['id'];
            }
        }
    }
    return false;
}

/**
 * Declara en qué moneda están los precios de productos YA CARGADOS: los de un
 * lote de carga masiva o los de una marca. No convierte nada: el número que
 * hay en precio_venta y precio_compra se queda, y pasa a leerse en esa moneda
 * (a partir de ahí, el POS lo convierte al vender). La moneda base guarda NULL.
 * @return int productos actualizados
 */
function mon_asignar_productos(?int $monedaId, string $por, int $id): int
{
    if (!mon_p39()) throw new RuntimeException('Falta la migración de moneda por producto (database/migracion_moneda_producto_p39.sql).');
    if ($monedaId !== null && (int) moneda($monedaId)['es_base'] === 1) $monedaId = null;
    $col = ['lote' => 'importacion_id', 'marca' => 'marca_id'][$por] ?? null;
    if (!$col || $id <= 0) throw new RuntimeException('Indica el lote de carga o la marca.');
    return q("UPDATE productos SET moneda_id = ? WHERE $col = ? AND NOT (moneda_id <=> ?)", [$monedaId, $id, $monedaId])->rowCount();
}

/** ¿Este producto está en una moneda que no es el peso? */
function mon_producto_extranjero(array $producto): bool
{
    $id = $producto['moneda_id'] ?? null;
    if (!$id || !mon_disponible()) return false;
    return (int) moneda((int) $id)['es_base'] !== 1;
}

/* ------------------------------------------------------------
 *  Descarga de la tasa de mercado
 * ------------------------------------------------------------ */

/**
 * Fuentes, en cascada. Todas gratuitas y sin clave.
 *
 * NINGUNA es el Banco Central: el BCRD publica en PDF y no ofrece un API
 * gratuito (la DGII tampoco). Estas dan tasa de MERCADO, que para una
 * referencia sirve y para una declaración no. Por eso lo que se descarga no
 * cobra nada: solo avisa.
 */
function mon_fuentes(): array
{
    return [
        ['nombre' => 'currency-api',
         'url'    => 'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/%s.json',
         'leer'   => fn(array $j, string $de, string $a) => $j[strtolower($de)][strtolower($a)] ?? null],
        ['nombre' => 'currency-api (espejo)',
         'url'    => 'https://latest.currency-api.pages.dev/v1/currencies/%s.json',
         'leer'   => fn(array $j, string $de, string $a) => $j[strtolower($de)][strtolower($a)] ?? null],
        ['nombre' => 'exchangerate-api',
         'url'    => 'https://open.er-api.com/v6/latest/%s',
         'leer'   => fn(array $j, string $de, string $a) => $j['rates'][strtoupper($a)] ?? null],
    ];
}

/**
 * Pide la tasa a las fuentes hasta que una responda algo creíble.
 *
 * Nunca lanza y nunca tarda mucho: esto puede estar colgado de una carga de
 * página, y un proveedor caído no puede dejar a nadie mirando el reloj.
 *
 * @return array{tasa:float,fuente:string}|null
 */
function mon_tasa_remota(string $codigoDe, string $codigoA, ?float $referencia = null): ?array
{
    foreach (mon_fuentes() as $f) {
        try {
            $url = sprintf($f['url'], strtolower($codigoDe));
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => APP_NAME . '/1.0',
            ]);
            $raw  = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($http !== 200 || !is_string($raw) || $raw === '') continue;

            $json = json_decode($raw, true);
            if (!is_array($json)) continue;
            $tasa = ($f['leer'])($json, $codigoDe, $codigoA);
            if (!is_numeric($tasa) || (float) $tasa <= 0) continue;
            $tasa = round((float) $tasa, 6);

            // Freno de cordura. Una fuente puede devolver basura o cambiar de
            // formato sin avisar, y una tasa absurda guardada como referencia
            // dispararía la alerta todos los días hasta que alguien la mire.
            if ($referencia !== null && $referencia > 0) {
                $salto = abs($tasa - $referencia) / $referencia * 100;
                if ($salto > MON_TOPE_SALTO_PCT) continue;
            }
            return ['tasa' => $tasa, 'fuente' => $f['nombre']];
        } catch (Throwable $e) {
            continue;   // se prueba la siguiente
        }
    }
    return null;
}

/**
 * Refresca la tasa de mercado de las monedas marcadas para ello.
 * @return array{actualizadas:int,fallidas:int,detalle:array}
 */
function mon_refrescar_mercado(): array
{
    $r = ['actualizadas' => 0, 'fallidas' => 0, 'detalle' => []];
    if (!mon_p39()) return $r;

    $base = monedaBase();
    foreach (qAll("SELECT * FROM monedas WHERE es_base = 0 AND auto_actualizar = 1") as $m) {
        // La referencia para el freno: lo último que se supo, y si no, la de venta.
        $ref = (float) ($m['tasa_mercado'] ?: $m['tasa']);
        $res = mon_tasa_remota((string) $m['codigo'], (string) $base['codigo'], $ref);

        if (!$res) {
            $r['fallidas']++;
            $r['detalle'][] = $m['codigo'] . ': ninguna fuente respondió algo creíble';
            continue;
        }
        try {
            dbUpdate('monedas', [
                'tasa_mercado'        => $res['tasa'],
                'tasa_mercado_at'     => date('Y-m-d H:i:s'),
                'tasa_mercado_fuente' => $res['fuente'],
            ], 'id = ?', [(int) $m['id']]);
            mon_historial_registrar((int) $m['id'], $res['tasa'], 'automatica', 'mercado', $res['fuente']);
            $r['actualizadas']++;
            $r['detalle'][] = sprintf('%s: %s (%s)', $m['codigo'], number_format($res['tasa'], 4), $res['fuente']);
            monedas(true, true);
        } catch (Throwable $e) {
            $r['fallidas']++;
            $r['detalle'][] = $m['codigo'] . ': no se pudo guardar';
        }
    }
    return $r;
}

/**
 * Refresco oportunista, con el mismo enganche que las notificaciones y el
 * marketing: sin cron. Solo entra si toca por tiempo, y cualquier error se
 * traga — la página se pinta igual.
 */
function mon_tick_si_toca(): void
{
    if (!mon_p39()) return;
    try {
        $pendiente = qVal(
            "SELECT COUNT(*) FROM monedas
              WHERE es_base = 0 AND activo = 1 AND auto_actualizar = 1
                AND (tasa_mercado_at IS NULL OR tasa_mercado_at < DATE_SUB(NOW(), INTERVAL ? HOUR))",
            [MON_REFRESCO_HORAS]
        );
        if (!$pendiente) return;
        mon_refrescar_mercado();
    } catch (Throwable $e) {
        if (APP_ENV !== 'production') error_log('[monedas] ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------
 *  Historial y desfase
 * ------------------------------------------------------------ */

/** Anota un cambio de tasa. Nunca tumba a quien la llama. */
function mon_historial_registrar(int $monedaId, float $tasa, string $origen, string $campo, ?string $fuente = null, ?string $nota = null): void
{
    if (!mon_p39()) return;
    try {
        dbInsert('monedas_historial', [
            'moneda_id'  => $monedaId,
            'tasa'       => round($tasa, 6),
            'origen'     => in_array($origen, ['manual', 'automatica'], true) ? $origen : 'manual',
            'campo'      => in_array($campo, ['venta', 'mercado'], true) ? $campo : 'venta',
            'fuente'     => $fuente,
            'usuario_id' => current_user()['id'] ?? null,
            'nota'       => $nota !== null ? mb_substr($nota, 0, 255) : null,
        ]);
    } catch (Throwable $e) {
        // El historial es para mirar, no para operar: si falla, no para nada.
    }
}

/** Últimos movimientos de una moneda. */
function mon_historial(int $monedaId, string $campo = 'venta', int $tope = 30): array
{
    if (!mon_p39()) return [];
    $tope = max(1, min($tope, 200));
    return qAll("SELECT h.*, u.nombre, u.apellido FROM monedas_historial h
                   LEFT JOIN usuarios u ON u.id = h.usuario_id
                  WHERE h.moneda_id = ? AND h.campo = ?
                  ORDER BY h.created_at DESC LIMIT $tope",
        [$monedaId, $campo]);
}

/**
 * Qué tan separadas están la tasa de venta y la del mercado.
 *
 * @return array{hay:bool,venta:float,mercado:?float,pct:float,avisar:bool,
 *               dias:int,rancia:bool,fuente:?string,at:?string}
 */
function mon_desfase(int $monedaId): array
{
    $m = qOne("SELECT * FROM monedas WHERE id = ?", [$monedaId]) ?: moneda($monedaId);
    $venta   = (float) $m['tasa'];
    $mercado = isset($m['tasa_mercado']) && $m['tasa_mercado'] !== null ? (float) $m['tasa_mercado'] : null;
    $pct = ($mercado && $venta > 0) ? ($mercado - $venta) / $venta * 100 : 0.0;

    $revisada = $m['tasa_revisada_at'] ?? null;
    $dias = $revisada ? (int) floor((time() - strtotime((string) $revisada)) / 86400) : 0;

    return [
        'hay'     => $mercado !== null,
        'venta'   => $venta,
        'mercado' => $mercado,
        'pct'     => round($pct, 2),
        'avisar'  => $mercado !== null && abs($pct) >= MON_ALERTA_PCT,
        'dias'    => $dias,
        'rancia'  => $dias >= MON_ALERTA_DIAS,
        'fuente'  => $m['tasa_mercado_fuente'] ?? null,
        'at'      => $m['tasa_mercado_at'] ?? null,
    ];
}

/** Cuántos productos del catálogo están en una moneda extranjera. */
function mon_productos_en_moneda(int $monedaId): int
{
    if (!mon_p39()) return 0;
    try {
        return (int) qVal("SELECT COUNT(*) FROM productos WHERE moneda_id = ? AND activo = 1", [$monedaId]);
    } catch (Throwable $e) {
        return 0;
    }
}

/* ------------------------------------------------------------
 *  Conversión dentro de SQL
 *
 *  El inventario valorizado suma `cantidad * precio` en la base, sobre miles de
 *  filas. Traérselo a PHP para convertirlo sería cambiar una consulta por un
 *  bucle, que es justo el error de rendimiento que documenta CONVENCIONES-DEV.
 *  Estas dos funciones dejan que la conversión viaje dentro del SQL, y que
 *  viva en UN solo sitio en vez de copiada en cada agregado.
 * ------------------------------------------------------------ */

/** El LEFT JOIN que necesita mon_sql_precio(). Vacío si falta la P39. */
function mon_sql_join(string $aliasProd = 'p', string $alias = 'mo'): string
{
    if (!mon_p39()) return '';
    return " LEFT JOIN monedas $alias ON $alias.id = $aliasProd.moneda_id ";
}

/**
 * Envuelve una columna de precio para que salga en pesos.
 * `COALESCE(tasa,1)`: lo que no declara moneda ya está en pesos.
 */
function mon_sql_precio(string $col, string $alias = 'mo'): string
{
    if (!mon_p39()) return $col;
    return "($col * COALESCE($alias.tasa, 1))";
}
