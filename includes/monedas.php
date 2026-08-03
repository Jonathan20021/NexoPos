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
function monedas(bool $soloActivas = true): array
{
    static $cache = [];
    $k = $soloActivas ? 'act' : 'todas';
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

/** Guarda la tasa de una moneda. Devuelve la tasa aplicada. */
function mon_actualizarTasa(int $monedaId, float $tasa): float
{
    $m = moneda($monedaId);
    if ((int) $m['es_base'] === 1) return 1.0;   // la base no se toca nunca
    $tasa = max(0.000001, round($tasa, 6));
    dbUpdate('monedas', ['tasa' => $tasa], 'id = ?', [$monedaId]);
    return $tasa;
}
