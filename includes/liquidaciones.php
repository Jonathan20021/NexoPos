<?php
/**
 * Liquidación de importaciones — el costo real puesto en almacén.
 *
 * Un embarque no cuesta lo que dice la factura del proveedor. Cuesta el FOB más
 * el flete, el seguro, el arancel, la agencia aduanal y el transporte interno.
 * Vender con el costo de la factura es venderse creyendo que se gana un 40%
 * cuando se gana un 22%. Este módulo reparte esos gastos entre los artículos y
 * deja el costo verdadero en el catálogo.
 *
 * TRES REGLAS QUE NO SE PUEDEN ROMPER
 *
 * 1. **Es un documento de costo, no de dinero.** No registra la deuda al
 *    proveedor ni el pago de la agencia aduanal: eso ya lo hacen Compras y
 *    Cuentas por Pagar. Si además lo hiciera aquí, los gastos del mes saldrían
 *    duplicados y la utilidad, hundida a la mitad.
 *
 * 2. **El ITBIS de aduana NO es costo.** Es un adelanto que se compensa contra
 *    el ITBIS cobrado en la venta. Meterlo al costo infla el inventario un 18% y
 *    hunde el margen. Por eso cada gasto tiene `al_costo`.
 *
 * 3. **Los centavos tienen que cuadrar.** La suma de lo prorrateado entre las
 *    líneas es EXACTAMENTE el total de gastos. Repartir con round() y ya deja
 *    diferencias de céntimos que, con 300 líneas, descuadran el inventario.
 *
 * Ver docs/TIENDAS-Y-DIRECCION.md.
 */

/** ¿Está aplicada la migración P16? */
function liq_disponible(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = (bool) qVal(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'liquidaciones'"
        );
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

/**
 * Cómo se reparten los gastos entre las líneas.
 *
 * Por valor es lo estándar (y lo que usa la aduana para el valor imponible).
 * Por peso o volumen es lo correcto cuando el flete manda: repartir por valor el
 * flete de mercancía barata y pesada le carga el costo al artículo caro y
 * liviano, que no es quien llenó el contenedor.
 */
function liq_prorrateos(): array
{
    return [
        'valor'    => 'Por valor FOB (estándar)',
        'cantidad' => 'Por cantidad de unidades',
        'peso'     => 'Por peso (kg)',
        'volumen'  => 'Por volumen (m³)',
    ];
}

/** Tipos de gasto de un embarque. `al_costo` es el valor por defecto sugerido. */
function liq_tipos_gasto(): array
{
    return [
        'flete'      => ['label' => 'Flete internacional', 'al_costo' => 1],
        'seguro'     => ['label' => 'Seguro de la carga',  'al_costo' => 1],
        'arancel'    => ['label' => 'Arancel / gravamen',  'al_costo' => 1],
        'aduana'     => ['label' => 'Agencia aduanal',     'al_costo' => 1],
        'transporte' => ['label' => 'Transporte interno',  'al_costo' => 1],
        'almacenaje' => ['label' => 'Almacenaje y demoras','al_costo' => 1],
        // El único que NO entra al costo por defecto: se compensa con el ITBIS
        // cobrado en la venta, no es un gasto de la mercancía.
        'itbis'      => ['label' => 'ITBIS de aduana (recuperable)', 'al_costo' => 0],
        'otros'      => ['label' => 'Otros gastos',        'al_costo' => 1],
    ];
}

/** Etiqueta legible de un tipo de gasto. */
function liq_gasto_label(string $tipo): string
{
    return liq_tipos_gasto()[$tipo]['label'] ?? ucfirst($tipo);
}

/**
 * Normaliza una fecha del formulario a 'Y-m-d', o devuelve el valor por defecto.
 *
 * Un `<input type="date">` vacío o manipulado llega como cadena suelta. En
 * MariaDB con sql_mode permisivo eso entra como '0000-00-00' y nadie se entera;
 * en el MySQL 8 del servidor revienta la página. Se limpia aquí, una vez.
 */
function liq_fecha($valor, ?string $porDefecto = null): ?string
{
    $s = trim((string) $valor);
    if ($s === '') return $porDefecto;

    // Lo que manda un <input type="date">. Se exige que la fecha EXISTA:
    // `strtotime('2026-02-30')` no falla, devuelve el 2 de marzo, y ese
    // desplazamiento silencioso es peor que rechazar el dato.
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $s : $porDefecto;
    }
    // Tecleado a mano: dd/mm/aaaa, que es como se escribe en RD.
    if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $s, $m)) {
        return checkdate((int) $m[2], (int) $m[1], (int) $m[3])
            ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1])
            : $porDefecto;
    }
    return $porDefecto;
}

/** Estados y su color de badge. */
function liq_estados(): array
{
    return [
        'borrador' => ['label' => 'Borrador',    'color' => 'slate',   'ayuda' => 'Se puede editar. No toca inventario ni costos.'],
        'transito' => ['label' => 'En tránsito', 'color' => 'amber',   'ayuda' => 'El embarque viene en camino. Sigue sin tocar inventario.'],
        'aplicada' => ['label' => 'Aplicada',    'color' => 'emerald', 'ayuda' => 'La mercancía entró y el costo quedó fijado en el catálogo.'],
        'anulada'  => ['label' => 'Anulada',     'color' => 'rose',    'ayuda' => 'Se revirtió: el stock y el costo volvieron a como estaban.'],
    ];
}

function liq_badge(string $estado): string
{
    $e = liq_estados()[$estado] ?? ['label' => $estado, 'color' => 'slate'];
    return badge($e['label'], $e['color']);
}

/** ¿Se puede editar el contenido de la liquidación? */
function liq_editable(array $liq): bool
{
    return in_array($liq['estado'], ['borrador', 'transito'], true);
}

/* ============================================================
 *  Carga y cálculo
 * ============================================================ */

/**
 * Carga una liquidación completa: encabezado, líneas y gastos.
 * Devuelve null si no existe.
 */
function liq_cargar(int $id): ?array
{
    if (!liq_disponible()) return null;
    $liq = qOne(
        "SELECT l.*, s.nombre AS sucursal, pr.nombre AS proveedor, t.nombre AS tienda,
                c.numero AS compra_numero,
                CONCAT(u.nombre,' ',u.apellido) AS usuario
           FROM liquidaciones l
           JOIN sucursales s   ON s.id = l.sucursal_id
           LEFT JOIN proveedores pr ON pr.id = l.proveedor_id
           LEFT JOIN tiendas t      ON t.id = l.tienda_id
           LEFT JOIN compras c      ON c.id = l.compra_id
           LEFT JOIN usuarios u     ON u.id = l.usuario_id
          WHERE l.id = ?",
        [$id]
    );
    if (!$liq) return null;

    $liq['detalles'] = qAll(
        "SELECT d.*, p.codigo AS sku, p.nombre AS producto, p.precio_venta, p.precio_compra AS costo_actual,
                p.controla_lote, p.regulado
           FROM liquidacion_detalles d
           JOIN productos p ON p.id = d.producto_id
          WHERE d.liquidacion_id = ?
          ORDER BY p.nombre",
        [$id]
    );
    $liq['gastos'] = qAll("SELECT * FROM liquidacion_gastos WHERE liquidacion_id = ? ORDER BY orden, id", [$id]);
    return $liq;
}

/**
 * Reparte los gastos entre las líneas y devuelve el cálculo.
 *
 * Función PURA: no toca la base. Así se puede usar para la vista previa de un
 * borrador y para el asiento definitivo con la certeza de que dan lo mismo.
 *
 * @return array{fob:float,gastos:float,gastos_no_costo:float,costo_total:float,unidades:float,lineas:array}
 */
function liq_calcular(string $metodo, array $detalles, array $gastos): array
{
    $fob = 0.0; $unidades = 0.0;
    $gastosCosto = 0.0; $gastosNoCosto = 0.0;

    foreach ($gastos as $g) {
        if ((int) $g['al_costo'] === 1) $gastosCosto   += (float) $g['monto'];
        else                            $gastosNoCosto += (float) $g['monto'];
    }

    // Base de reparto de cada línea.
    $bases = []; $baseTotal = 0.0;
    foreach ($detalles as $i => $d) {
        $cant = (float) $d['cantidad'];
        $unidades += $cant;
        $fob += round($cant * (float) $d['costo_fob'], 2);
        $base = match ($metodo) {
            'cantidad' => $cant,
            'peso'     => $cant * (float) ($d['peso'] ?? 0),
            'volumen'  => $cant * (float) ($d['volumen'] ?? 0),
            default    => $cant * (float) $d['costo_fob'],
        };
        $bases[$i] = max(0.0, $base);
        $baseTotal += $bases[$i];
    }

    // Sin base utilizable (todo peso 0, o mercancía a costo cero) se reparte por
    // cantidad; si tampoco hay, en partes iguales. Nunca se deja de repartir: los
    // gastos existen aunque la base elegida no sirva.
    if ($baseTotal <= 0) {
        $baseTotal = 0.0;
        foreach ($detalles as $i => $d) {
            $bases[$i] = max(0.0, (float) $d['cantidad']) ?: 1.0;
            $baseTotal += $bases[$i];
        }
    }

    $fob = round($fob, 2);
    $lineas = []; $asignado = 0.0; $mayor = null; $mayorBase = -1.0;

    foreach ($detalles as $i => $d) {
        $cant  = (float) $d['cantidad'];
        $parte = $baseTotal > 0 ? round($gastosCosto * ($bases[$i] / $baseTotal), 2) : 0.0;
        $asignado += $parte;
        if ($bases[$i] > $mayorBase) { $mayorBase = $bases[$i]; $mayor = $i; }
        $lineas[$i] = [
            'id'          => $d['id'] ?? null,
            'producto_id' => (int) $d['producto_id'],
            'cantidad'    => $cant,
            'costo_fob'   => (float) $d['costo_fob'],
            'fob_linea'   => round($cant * (float) $d['costo_fob'], 2),
            'base'        => $bases[$i],
            'prorrateo'   => $parte,
        ];
    }

    // El céntimo que sobra o falta va a la línea de mayor base: es donde menos
    // distorsiona y garantiza que la suma cuadre al centavo con el total.
    $resto = round($gastosCosto - $asignado, 2);
    if ($mayor !== null && abs($resto) >= 0.01) {
        $lineas[$mayor]['prorrateo'] = round($lineas[$mayor]['prorrateo'] + $resto, 2);
    }

    foreach ($lineas as $i => $l) {
        $lineas[$i]['costo_linea']  = round($l['fob_linea'] + $lineas[$i]['prorrateo'], 2);
        $lineas[$i]['costo_final']  = $l['cantidad'] > 0
            ? round($lineas[$i]['costo_linea'] / $l['cantidad'], 4)
            : 0.0;
        // Cuánto encarece el gasto a esta línea. Es la cifra que mira la
        // dirección: «el flete me subió este artículo un 31%».
        $lineas[$i]['recargo_pct'] = $l['fob_linea'] > 0
            ? round($lineas[$i]['prorrateo'] / $l['fob_linea'] * 100, 2)
            : 0.0;
    }

    return [
        'fob'             => $fob,
        'gastos'          => round($gastosCosto, 2),
        'gastos_no_costo' => round($gastosNoCosto, 2),
        'costo_total'     => round($fob + $gastosCosto, 2),
        'unidades'        => $unidades,
        'recargo_pct'     => $fob > 0 ? round($gastosCosto / $fob * 100, 2) : 0.0,
        'lineas'          => array_values($lineas),
    ];
}

/**
 * Recalcula y GUARDA el reparto de una liquidación.
 * Se llama después de tocar líneas o gastos. Devuelve el cálculo.
 */
function liq_recalcular(int $id): array
{
    $liq = liq_cargar($id);
    if (!$liq) throw new RuntimeException('La liquidación no existe.');

    $calc = liq_calcular($liq['prorrateo'], $liq['detalles'], $liq['gastos']);

    foreach ($calc['lineas'] as $l) {
        if (empty($l['id'])) continue;
        dbUpdate('liquidacion_detalles', [
            'prorrateo'   => $l['prorrateo'],
            'costo_final' => $l['costo_final'],
        ], 'id = ?', [$l['id']]);
    }
    dbUpdate('liquidaciones', [
        'fob'             => $calc['fob'],
        'gastos'          => $calc['gastos'],
        'gastos_no_costo' => $calc['gastos_no_costo'],
        'costo_total'     => $calc['costo_total'],
    ], 'id = ?', [$id]);

    return $calc;
}

/* ============================================================
 *  Aplicar y anular
 * ============================================================ */

/**
 * Aplica la liquidación: la mercancía entra al inventario al costo real y el
 * catálogo queda con ese costo.
 *
 * `modo = entrada`  → entra la cantidad al almacén.
 * `modo = recosteo` → la compra ya entró la mercancía; aquí solo se corrige el
 *                     costo. NO se mueve ni una unidad.
 *
 * Devuelve ['lineas' => n, 'unidades' => n, 'costo_total' => n].
 */
function liq_aplicar(int $id, int $usuarioId): array
{
    return txReintentable(function () use ($id, $usuarioId) {
        // Bloquear y releer el estado DENTRO de la transacción: dos personas
        // pulsando «Aplicar» a la vez entrarían la mercancía dos veces.
        $liq = qOne("SELECT * FROM liquidaciones WHERE id = ? FOR UPDATE", [$id]);
        if (!$liq) throw new RuntimeException('La liquidación no existe.');
        if ($liq['estado'] === 'aplicada') throw new RuntimeException('Esta liquidación ya se aplicó.');
        if ($liq['estado'] === 'anulada')  throw new RuntimeException('Una liquidación anulada no se puede aplicar.');

        // El reparto se recalcula aquí, con las líneas bloqueadas: aplicar tiene
        // que usar los números de ahora, no los que se vieron en pantalla hace
        // diez minutos.
        $detalles = qAll(
            "SELECT d.*, p.nombre AS producto FROM liquidacion_detalles d
               JOIN productos p ON p.id = d.producto_id
              WHERE d.liquidacion_id = ? ORDER BY d.producto_id FOR UPDATE",
            [$id]
        );
        if (!$detalles) throw new RuntimeException('La liquidación no tiene líneas: no hay nada que aplicar.');
        $gastos = qAll("SELECT * FROM liquidacion_gastos WHERE liquidacion_id = ?", [$id]);

        $calc = liq_calcular($liq['prorrateo'], $detalles, $gastos);
        $porProducto = [];
        foreach ($calc['lineas'] as $l) $porProducto[$l['producto_id']] = $l;

        $sucursalId = (int) $liq['sucursal_id'];
        $esEntrada  = $liq['modo'] === 'entrada';
        $unidades   = 0.0;

        // SIEMPRE en orden de producto_id: dos procesos moviendo los mismos
        // artículos en distinto orden se bloquean en cruz. Ver docs/CONCURRENCIA.md.
        usort($detalles, fn($a, $b) => (int) $a['producto_id'] <=> (int) $b['producto_id']);

        foreach ($detalles as $d) {
            $pid  = (int) $d['producto_id'];
            $cant = (float) $d['cantidad'];
            $l    = $porProducto[$pid] ?? null;
            if (!$l) continue;
            $costoFinal = (float) $l['costo_final'];

            // El costo que tenía el producto ANTES. Sin esto, anular no puede
            // devolver el catálogo a como estaba.
            $costoAnterior = (float) qVal("SELECT precio_compra FROM productos WHERE id = ? FOR UPDATE", [$pid]);

            if ($esEntrada) {
                $lote = null;
                if (!empty($d['lote'])) {
                    $lote = [
                        'codigo'            => (string) $d['lote'],
                        'fecha_vencimiento' => $d['vencimiento'] ?: null,
                        'proveedor_id'      => $liq['proveedor_id'] ? (int) $liq['proveedor_id'] : null,
                    ];
                }
                ajustarStock(
                    $pid, $sucursalId, $cant, 'compra', 'liquidacion', $id,
                    $costoFinal, 'Liquidación ' . $liq['numero'], $lote
                );
                $unidades += $cant;
            }

            // El costo del catálogo pasa a ser el real puesto en almacén. Las
            // ventas ya emitidas conservan el suyo (venta_detalles.costo_unitario
            // está congelado): recostear el pasado reescribiría márgenes ya
            // reportados.
            dbUpdate('productos', ['precio_compra' => round($costoFinal, 2)], 'id = ?', [$pid]);
            dbUpdate('liquidacion_detalles', [
                'prorrateo'      => $l['prorrateo'],
                'costo_final'    => $costoFinal,
                'costo_anterior' => $costoAnterior,
            ], 'id = ?', [(int) $d['id']]);
        }

        dbUpdate('liquidaciones', [
            'fob'             => $calc['fob'],
            'gastos'          => $calc['gastos'],
            'gastos_no_costo' => $calc['gastos_no_costo'],
            'costo_total'     => $calc['costo_total'],
            'estado'          => 'aplicada',
            'aplicada_at'     => date('Y-m-d H:i:s'),
            'aplicada_por'    => $usuarioId,
        ], 'id = ?', [$id]);

        return [
            'lineas'      => count($detalles),
            'unidades'    => $unidades,
            'costo_total' => $calc['costo_total'],
            'modo'        => $liq['modo'],
        ];
    });
}

/**
 * Anula una liquidación.
 *
 * Si estaba aplicada, deshace lo que hizo: saca del inventario lo que metió y
 * devuelve a cada producto el costo que tenía. Si parte de esa mercancía ya se
 * vendió, la salida dejaría el stock en negativo y la operación se cae entera
 * con un mensaje claro — que es lo correcto: no se puede «des-importar» algo
 * que ya salió por la puerta.
 */
function liq_anular(int $id, int $usuarioId, string $motivo = ''): void
{
    txReintentable(function () use ($id, $usuarioId, $motivo) {
        $liq = qOne("SELECT * FROM liquidaciones WHERE id = ? FOR UPDATE", [$id]);
        if (!$liq) throw new RuntimeException('La liquidación no existe.');
        if ($liq['estado'] === 'anulada') throw new RuntimeException('Esta liquidación ya está anulada.');

        if ($liq['estado'] === 'aplicada') {
            $detalles = qAll(
                "SELECT * FROM liquidacion_detalles WHERE liquidacion_id = ? ORDER BY producto_id FOR UPDATE",
                [$id]
            );
            foreach ($detalles as $d) {
                $pid = (int) $d['producto_id'];
                if ($liq['modo'] === 'entrada') {
                    ajustarStock(
                        $pid, (int) $liq['sucursal_id'], -(float) $d['cantidad'], 'ajuste',
                        'liquidacion', $id, (float) $d['costo_final'],
                        'Anulación de la liquidación ' . $liq['numero'],
                        !empty($d['lote']) ? (string) $d['lote'] : null
                    );
                }
                if ($d['costo_anterior'] !== null) {
                    dbUpdate('productos', ['precio_compra' => (float) $d['costo_anterior']], 'id = ?', [$pid]);
                }
            }
        }

        $nota = trim((string) $liq['notas'] . "\n" . 'Anulada el ' . date('d/m/Y H:i')
            . ($motivo !== '' ? ': ' . $motivo : '.'));
        dbUpdate('liquidaciones', [
            'estado' => 'anulada',
            'notas'  => mb_substr($nota, 0, 500),
        ], 'id = ?', [$id]);
    });
}

/* ============================================================
 *  Consultas para el panel de Dirección
 * ============================================================ */

/**
 * Resumen de la mercancía que viene en camino y de la que ya se costeó.
 * Alimenta la tarjeta «mercancía en tránsito» del panel de la CEO.
 */
function liq_resumen(?int $tiendaId = null): array
{
    if (!liq_disponible()) {
        return ['transito' => 0, 'transito_valor' => 0.0, 'borradores' => 0,
                'aplicadas_mes' => 0, 'costo_mes' => 0.0, 'recargo_pct' => 0.0];
    }
    $wT = $tiendaId ? ' AND tienda_id = ' . (int) $tiendaId : '';
    $mesIni = date('Y-m-01');
    $mesFin = date('Y-m-t');

    $t = qOne("SELECT COUNT(*) n, COALESCE(SUM(costo_total),0) v FROM liquidaciones WHERE estado='transito' $wT") ?: [];
    $b = (int) qVal("SELECT COUNT(*) FROM liquidaciones WHERE estado='borrador' $wT");
    $a = qOne(
        "SELECT COUNT(*) n, COALESCE(SUM(costo_total),0) c, COALESCE(SUM(fob),0) f, COALESCE(SUM(gastos),0) g
           FROM liquidaciones
          WHERE estado='aplicada' AND fecha BETWEEN ? AND ? $wT",
        [$mesIni, $mesFin]
    ) ?: [];

    $fobMes = (float) ($a['f'] ?? 0);
    return [
        'transito'       => (int) ($t['n'] ?? 0),
        'transito_valor' => (float) ($t['v'] ?? 0),
        'borradores'     => $b,
        'aplicadas_mes'  => (int) ($a['n'] ?? 0),
        'costo_mes'      => (float) ($a['c'] ?? 0),
        'recargo_pct'    => $fobMes > 0 ? round((float) ($a['g'] ?? 0) / $fobMes * 100, 1) : 0.0,
    ];
}
