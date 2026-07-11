<?php
/**
 * Promociones: descuentos automáticos por vigencia (temporada) aplicables a todo
 * el catálogo, a una categoría, a una marca o a un producto puntual.
 *
 * Fuente ÚNICA del cálculo de precio con promoción. La usan el POS
 * (registrarVentaPOS) y la tienda online, siempre en el SERVIDOR: el navegador
 * nunca decide el descuento.
 *
 * Regla de selección: entre todas las promociones vigentes que aplican a un
 * producto, gana la que deja el MENOR precio (mejor para el cliente); a igual
 * descuento, la de mayor 'prioridad'. No se acumulan.
 */

/** Promociones vigentes hoy para un canal ('pos' | 'tienda'). Cache por canal. */
function promocionesVigentes(string $canal = 'pos'): array
{
    static $cache = [];
    if (isset($cache[$canal])) return $cache[$canal];

    $hoy = date('Y-m-d');
    $rows = qAll(
        "SELECT id, nombre, tipo, valor, alcance, objetivo_id, canal, prioridad
           FROM promociones
          WHERE activo = 1
            AND fecha_inicio <= ? AND fecha_fin >= ?
            AND (canal = 'ambos' OR canal = ?)
          ORDER BY prioridad DESC, id DESC",
        [$hoy, $hoy, $canal]
    );
    return $cache[$canal] = $rows;
}

/** ¿La promoción aplica a este producto? ($prod requiere id, categoria_id, marca_id). */
function promoAplica(array $promo, array $prod): bool
{
    switch ($promo['alcance']) {
        case 'todos':     return true;
        case 'categoria': return (int) $promo['objetivo_id'] === (int) ($prod['categoria_id'] ?? 0);
        case 'marca':     return (int) $promo['objetivo_id'] === (int) ($prod['marca_id'] ?? 0);
        case 'producto':  return (int) $promo['objetivo_id'] === (int) ($prod['id'] ?? 0);
    }
    return false;
}

/** Precio ya con el descuento de una promoción concreta (nunca por debajo de 0). */
function promoPrecioUnitario(float $base, array $promo): float
{
    if ($promo['tipo'] === 'monto') {
        return round(max(0.0, $base - (float) $promo['valor']), 2);
    }
    $pct = max(0.0, min(100.0, (float) $promo['valor']));
    return round($base * (1 - $pct / 100), 2);
}

/**
 * Aplica la mejor promoción vigente a un producto.
 *
 * @param float $base  Precio de lista (productos.precio_venta).
 * @param array $prod  ['id','categoria_id','marca_id'].
 * @return array ['precio','original','descuento','promo'=>row|null,'etiqueta'=>string]
 */
function aplicarPromocion(float $base, array $prod, string $canal = 'pos'): array
{
    $mejorPrecio = $base;
    $mejor = null;
    foreach (promocionesVigentes($canal) as $promo) {
        if (!promoAplica($promo, $prod)) continue;
        $precio = promoPrecioUnitario($base, $promo);
        if ($precio < $mejorPrecio) {   // menor precio = mejor descuento
            $mejorPrecio = $precio;
            $mejor = $promo;
        }
    }
    $etiqueta = '';
    if ($mejor) {
        $etiqueta = $mejor['tipo'] === 'porcentaje'
            ? '-' . rtrim(rtrim(number_format((float) $mejor['valor'], 2), '0'), '.') . '%'
            : '-' . money((float) $mejor['valor']);
    }
    return [
        'precio'    => $mejorPrecio,
        'original'  => round($base, 2),
        'descuento' => round($base - $mejorPrecio, 2),
        'promo'     => $mejor,
        'etiqueta'  => $etiqueta,
    ];
}
