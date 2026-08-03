<?php
/**
 * Cotizaciones: el documento que va antes de la factura.
 *
 * En venta a empresas casi ninguna factura sale de la nada: primero el cliente
 * pide precio, se le manda una cotización con vigencia, y cuando la acepta se
 * convierte en factura. Este archivo cubre ese ciclo completo.
 *
 * Dos decisiones que conviene entender:
 *
 * · UNA COTIZACIÓN NO TOCA NADA. No mueve stock, no genera asientos, no reserva
 *   mercancía. Es una oferta. Todo eso ocurre al facturarla, y lo hace
 *   `registrarVentaPOS()`, la misma función del POS: así una factura nacida de
 *   una cotización es idéntica a cualquier otra (NCF, kardex, caja, comisiones).
 *
 * · EL PRECIO COTIZADO SE RESPETA. Si el precio de lista sube entre la
 *   cotización y la factura, el cliente paga lo que se le prometió. Los precios
 *   viajan desde la cotización guardada en la base, nunca desde el navegador.
 */

/** ¿Está aplicada la migración P11? */
function cot_disponible(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = (bool) qVal("SHOW TABLES LIKE 'cotizaciones'");
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

/** Etiqueta y color de cada estado. */
function cot_estados(): array
{
    return [
        'borrador'  => ['Borrador',  'slate'],
        'enviada'   => ['Enviada',   'sky'],
        'aceptada'  => ['Aceptada',  'emerald'],
        'rechazada' => ['Rechazada', 'rose'],
        'vencida'   => ['Vencida',   'amber'],
        'facturada' => ['Facturada', 'indigo'],
    ];
}

/**
 * Estado que se MUESTRA. Una cotización enviada cuya fecha de vigencia ya pasó
 * está vencida aunque en la base siga diciendo «enviada»: se calcula al vuelo
 * para no depender de que alguien corra un proceso todas las noches.
 */
function cot_estadoVisible(array $c): string
{
    if (in_array($c['estado'], ['borrador', 'enviada'], true) && $c['vence'] < date('Y-m-d')) {
        return 'vencida';
    }
    return $c['estado'];
}

/** ¿Se puede seguir editando? Una vez facturada, no. */
function cot_editable(array $c): bool
{
    return in_array($c['estado'], ['borrador', 'enviada', 'vencida', 'rechazada'], true);
}

/**
 * Recalcula los totales a partir de las líneas.
 *
 * El ITBIS se calcula línea a línea (cada producto decide si aplica) y el
 * descuento global se reparte proporcionalmente, igual que en el POS: si no,
 * la factura que sale de la cotización no cuadraría con ella.
 *
 * @param array $lineas [['producto_id','descripcion','cantidad','precio_unitario','itbis_aplica'], ...]
 * @return array ['subtotal','descuento','itbis','total','lineas']
 */
function cot_totales(array $lineas, float $descuento = 0.0): array
{
    $tasa = (float) setting('itbis_tasa', DEFAULT_ITBIS);
    $subtotal = 0.0;
    $itbisBruto = 0.0;
    $out = [];

    foreach ($lineas as $i => $l) {
        $cant   = max(0, round((float) ($l['cantidad'] ?? 0), 3));
        $precio = max(0, round((float) ($l['precio_unitario'] ?? 0), 2));
        if ($cant <= 0) continue;

        $base  = round($precio * $cant, 2);
        $itbis = !empty($l['itbis_aplica']) ? round($base * $tasa / 100, 2) : 0.0;

        $subtotal   += $base;
        $itbisBruto += $itbis;

        $out[] = [
            'producto_id'     => (int) ($l['producto_id'] ?? 0) ?: null,
            'descripcion'     => mb_substr(trim((string) ($l['descripcion'] ?? '')), 0, 255),
            'cantidad'        => $cant,
            'precio_unitario' => $precio,
            'itbis'           => $itbis,
            'subtotal'        => $base,
            'orden'           => $i,
        ];
    }

    $descuento = min(max(0.0, round($descuento, 2)), $subtotal);
    $factor    = $subtotal > 0 ? ($subtotal - $descuento) / $subtotal : 1;
    $itbis     = round($itbisBruto * $factor, 2);

    return [
        'subtotal'  => round($subtotal, 2),
        'descuento' => $descuento,
        'itbis'     => $itbis,
        'total'     => round(($subtotal - $descuento) + $itbis, 2),
        'lineas'    => $out,
    ];
}

/**
 * Crea o actualiza una cotización con sus líneas.
 * @return int id de la cotización
 */
function cot_guardar(array $datos, array $lineas): int
{
    if (!cot_disponible()) throw new RuntimeException('Falta aplicar la migración de cotizaciones.');

    $id        = (int) ($datos['id'] ?? 0);
    $clienteId = (int) ($datos['cliente_id'] ?? 0);
    if (!$clienteId || !qVal("SELECT 1 FROM clientes WHERE id = ? AND activo = 1", [$clienteId])) {
        throw new RuntimeException('Selecciona un cliente válido.');
    }

    $t = cot_totales($lineas, (float) ($datos['descuento'] ?? 0));
    if (!$t['lineas']) throw new RuntimeException('Agrega al menos una línea con cantidad y precio.');

    $monedaId = (int) ($datos['moneda_id'] ?? 0) ?: (int) monedaBase()['id'];
    $tasa     = max(0.000001, (float) ($datos['tasa_cambio'] ?? mon_tasa($monedaId)));
    $validez  = max(1, min(365, (int) ($datos['validez_dias'] ?? 15)));
    $fecha    = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($datos['fecha'] ?? '')) ? $datos['fecha'] : date('Y-m-d');

    $sid = (int) ($datos['sucursal_id'] ?? 0) ?: (int) (current_sucursal_id() ?? 0);
    if (!$sid) throw new RuntimeException('Selecciona una sucursal antes de cotizar.');

    $fila = [
        'cliente_id'   => $clienteId,
        'sucursal_id'  => $sid,
        'fecha'        => $fecha,
        'validez_dias' => $validez,
        'vence'        => date('Y-m-d', strtotime($fecha . ' +' . $validez . ' days')),
        'moneda_id'    => $monedaId,
        'tasa_cambio'  => $tasa,
        'subtotal'     => $t['subtotal'],
        'descuento'    => $t['descuento'],
        'itbis'        => $t['itbis'],
        'total'        => $t['total'],
        'total_base'   => mon_aBase($t['total'], $tasa),
        'condiciones'  => trim((string) ($datos['condiciones'] ?? '')) ?: null,
        'notas'        => mb_substr(trim((string) ($datos['notas'] ?? '')), 0, 500) ?: null,
        'updated_at'   => date('Y-m-d H:i:s'),
    ];

    return tx(function () use ($id, $fila, $t) {
        if ($id > 0) {
            $actual = qOne("SELECT estado FROM cotizaciones WHERE id = ?", [$id]);
            if (!$actual) throw new RuntimeException('Cotización no encontrada.');
            if (!cot_editable($actual)) throw new RuntimeException('Una cotización facturada ya no se puede editar.');
            dbUpdate('cotizaciones', $fila, 'id = ?', [$id]);
            q("DELETE FROM cotizacion_detalles WHERE cotizacion_id = ?", [$id]);
        } else {
            $fila['numero']     = nextNumero('cotizaciones', 'numero', 'COT');
            $fila['estado']     = 'borrador';
            $fila['usuario_id'] = (int) current_user()['id'];
            $id = dbInsert('cotizaciones', $fila);
        }

        foreach ($t['lineas'] as $l) {
            $l['cotizacion_id'] = $id;
            dbInsert('cotizacion_detalles', $l);
        }
        return $id;
    });
}

/** Cotización con su cliente y su moneda. */
function cot_obtener(int $id): ?array
{
    return qOne(
        "SELECT c.*, cl.nombre AS cliente, cl.rnc_cedula, cl.email AS cliente_email,
                cl.telefono AS cliente_telefono, cl.direccion AS cliente_direccion,
                s.nombre AS sucursal, mo.codigo AS moneda_codigo, mo.simbolo AS moneda_simbolo,
                mo.es_base AS moneda_es_base, u.nombre AS vendedor, u.apellido AS vendedor_ape
           FROM cotizaciones c
           JOIN clientes cl   ON cl.id = c.cliente_id
           JOIN sucursales s  ON s.id = c.sucursal_id
           LEFT JOIN monedas mo ON mo.id = c.moneda_id
           LEFT JOIN usuarios u ON u.id = c.usuario_id
          WHERE c.id = ?", [$id]
    );
}

/** Líneas de una cotización. */
function cot_lineas(int $id): array
{
    return qAll("SELECT * FROM cotizacion_detalles WHERE cotizacion_id = ? ORDER BY orden, id", [$id]);
}

/**
 * Convierte una cotización aceptada en factura.
 *
 * No duplica ni una línea de la lógica de ventas: arma el carrito y llama a
 * `registrarVentaPOS()`, que es lo que ya asigna NCF, mueve stock, cuadra caja y
 * calcula comisiones. La diferencia es que los precios vienen de la cotización.
 *
 * @return array resultado de registrarVentaPOS
 */
function cot_facturar(int $id, int $metodoPagoId): array
{
    $c = cot_obtener($id);
    if (!$c) throw new RuntimeException('Cotización no encontrada.');
    if ($c['estado'] === 'facturada') throw new RuntimeException('Esta cotización ya se facturó.');

    $lineas = cot_lineas($id);
    if (!$lineas) throw new RuntimeException('La cotización no tiene líneas.');

    $tasa = max(0.000001, (float) $c['tasa_cambio']);

    // El carrito lleva el precio pactado. Si la cotización está en dólares, se
    // convierte a pesos con la tasa DE LA COTIZACIÓN: es el precio que se
    // prometió, no el de hoy.
    $cart = [];
    foreach ($lineas as $l) {
        if (!$l['producto_id']) {
            throw new RuntimeException('La línea «' . $l['descripcion'] . '» no está enlazada a un producto del catálogo y no se puede facturar. Edita la cotización y elige el producto.');
        }
        $cart[] = [
            'id'     => (int) $l['producto_id'],
            'cant'   => (float) $l['cantidad'],
            'precio' => mon_aBase((float) $l['precio_unitario'], $tasa),
        ];
    }

    $sid    = (int) $c['sucursal_id'];
    $uid    = (int) current_user()['id'];
    $sesion = cajaSesionAbierta($sid, $uid);

    $r = registrarVentaPOS([
        'cart'           => $cart,
        'descuento'      => mon_aBase((float) $c['descuento'], $tasa),
        'cliente_id'     => (int) $c['cliente_id'],
        'comprobante'    => !empty($c['rnc_cedula']) ? 'credito_fiscal' : 'consumidor',
        'metodo_pago_id' => $metodoPagoId,
        'canal'          => 'Cotización',
    ], [
        'sid' => $sid, 'uid' => $uid, 'sesion' => $sesion,
        'puede_muestra' => false,
        'precios_pactados' => true,     // ← autorizado aquí, con precios leídos de la base
    ]);

    dbUpdate('cotizaciones', [
        'estado'     => 'facturada',
        'venta_id'   => (int) $r['id'],
        'updated_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$id]);

    audit('cotizaciones', 'editar', "Cotización {$c['numero']} facturada como {$r['numero']}",
          ['tabla' => 'cotizaciones', 'registro_id' => $id]);

    return $r;
}

/* ============================================================
 *  PDF
 * ============================================================ */

/**
 * Documento PDF de la cotización, con la marca de la empresa.
 * Usa el mismo motor (Dompdf) y la misma hoja de estilos que la factura.
 */
function cot_pdf_html(array $c, array $lineas): string
{
    $esBase  = (int) ($c['moneda_es_base'] ?? 1) === 1;
    $moneda  = fn(float $v) => $esBase ? money($v) : ($c['moneda_simbolo'] . ' ' . number_format($v, 2, '.', ','));
    $vencida = cot_estadoVisible($c) === 'vencida';

    $h = pdf_brand_header('COTIZACIÓN', $c['numero']);

    // Cliente y datos del documento.
    $h .= '<table style="width:100%; margin-bottom:8px;"><tr>'
        . '<td style="vertical-align:top; width:55%;"><div class="box">'
        . '<strong>Cliente:</strong> ' . htmlspecialchars($c['cliente'])
        . (!empty($c['rnc_cedula'])       ? '<br><strong>RNC/Cédula:</strong> ' . htmlspecialchars($c['rnc_cedula']) : '')
        . (!empty($c['cliente_telefono']) ? '<br><strong>Teléfono:</strong> ' . htmlspecialchars($c['cliente_telefono']) : '')
        . (!empty($c['cliente_direccion'])? '<br>' . htmlspecialchars($c['cliente_direccion']) : '')
        . '</div></td>'
        . '<td style="vertical-align:top; padding-left:8px;"><div class="box">'
        . '<strong>Cotización:</strong> ' . htmlspecialchars($c['numero'])
        . '<br><strong>Fecha:</strong> ' . fechaCorta($c['fecha'])
        . '<br><strong>Válida hasta:</strong> ' . fechaCorta($c['vence'])
        . ($vencida ? ' <span class="badge" style="background:#fef3c7;color:#b45309;">VENCIDA</span>' : '')
        . '<br><strong>Sucursal:</strong> ' . htmlspecialchars($c['sucursal'])
        . (!$esBase ? '<br><strong>Moneda:</strong> ' . htmlspecialchars($c['moneda_codigo'])
                    . ' (tasa ' . rtrim(rtrim(number_format((float) $c['tasa_cambio'], 4, '.', ','), '0'), '.') . ')' : '')
        . '</div></td></tr></table>';

    // Líneas.
    $h .= '<table class="tbl"><thead><tr>'
        . '<th>Descripción</th><th class="num">Cant.</th><th class="num">Precio</th>'
        . '<th class="num">ITBIS</th><th class="num">Importe</th></tr></thead><tbody>';
    foreach ($lineas as $l) {
        $h .= '<tr><td>' . htmlspecialchars($l['descripcion']) . '</td>'
            . '<td class="num">' . qty($l['cantidad']) . '</td>'
            . '<td class="num">' . $moneda((float) $l['precio_unitario']) . '</td>'
            . '<td class="num">' . $moneda((float) $l['itbis']) . '</td>'
            . '<td class="num">' . $moneda((float) $l['subtotal']) . '</td></tr>';
    }
    $h .= '</tbody></table>';

    // Totales.
    $h .= '<table style="width:48%; margin-left:52%; margin-top:12px;" class="totales">'
        . '<tr><td class="lbl">Subtotal</td><td class="val">' . $moneda((float) $c['subtotal']) . '</td></tr>'
        . ((float) $c['descuento'] > 0 ? '<tr><td class="lbl">Descuento</td><td class="val">-' . $moneda((float) $c['descuento']) . '</td></tr>' : '')
        . '<tr><td class="lbl">ITBIS</td><td class="val">' . $moneda((float) $c['itbis']) . '</td></tr>'
        . '<tr><td class="lbl total-final">TOTAL</td><td class="val total-final">' . $moneda((float) $c['total']) . '</td></tr>';
    if (!$esBase) {
        $h .= '<tr><td class="lbl" style="font-size:9px;">Equivalente</td>'
            . '<td class="val" style="font-size:9px;font-weight:normal;">' . money((float) $c['total_base']) . '</td></tr>';
    }
    $h .= '</table>';

    if (!empty($c['condiciones'])) {
        $h .= '<h3>Condiciones</h3><div class="box" style="font-size:10px;white-space:pre-wrap;">'
            . htmlspecialchars($c['condiciones']) . '</div>';
    }
    if (!empty($c['notas'])) {
        $h .= '<p class="meta" style="font-size:10px;color:#374151;margin-top:10px;">' . htmlspecialchars($c['notas']) . '</p>';
    }

    $h .= '<p class="meta" style="margin-top:18px;">Esta cotización tiene validez hasta el '
        . fechaCorta($c['vence']) . '. Los precios pueden variar después de esa fecha.'
        . (!$esBase ? ' El importe en pesos se calculará a la tasa vigente el día de la facturación.' : '')
        . '</p>';

    if (!empty($c['vendedor'])) {
        $h .= '<p class="meta">Preparada por ' . htmlspecialchars(trim($c['vendedor'] . ' ' . $c['vendedor_ape'])) . '</p>';
    }

    return $h;
}

/**
 * Envía la cotización al cliente por correo, con el PDF adjunto.
 * Nunca lanza excepción: un correo que falla no puede tumbar la pantalla.
 *
 * @return array{ok:bool, error:?string}
 */
function cot_enviarCorreo(int $id): array
{
    $c = cot_obtener($id);
    if (!$c) return ['ok' => false, 'error' => 'Cotización no encontrada.'];

    $para = trim((string) $c['cliente_email']);
    if (!filter_var($para, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'El cliente no tiene un correo válido en su ficha.'];
    }
    if (!mail_configurado()) {
        return ['ok' => false, 'error' => 'El correo no está configurado (falta la clave de Resend).'];
    }

    $emp     = $GLOBALS['empresa'] ?? [];
    $esBase  = (int) ($c['moneda_es_base'] ?? 1) === 1;
    $totalTx = $esBase ? money((float) $c['total'])
                       : $c['moneda_simbolo'] . ' ' . number_format((float) $c['total'], 2, '.', ',');

    $cuerpo = '<p>Hola <strong>' . e($c['cliente']) . '</strong>,</p>'
        . '<p>Le enviamos la cotización <strong>' . e($c['numero']) . '</strong> por un total de <strong>'
        . e($totalTx) . '</strong>.</p>'
        . '<p>Es válida hasta el <strong>' . e(fechaCorta($c['vence'])) . '</strong>. '
        . 'Encontrará el detalle en el archivo adjunto.</p>'
        . '<p>Cualquier duda, responda a este correo'
        . (!empty($emp['telefono']) ? ' o llámenos al ' . e($emp['telefono']) : '') . '.</p>';

    $html = mail_plantilla('Cotización ' . $c['numero'], $cuerpo, $emp,
                           'Cotización ' . $c['numero'] . ' · válida hasta ' . fechaCorta($c['vence']));

    $adjunto = null;
    if (function_exists('pdf_bytes')) {
        try {
            $adjunto = [[
                'filename' => 'cotizacion_' . $c['numero'] . '.pdf',
                'content'  => base64_encode(pdf_bytes(cot_pdf_html($c, cot_lineas($id)), 'portrait')),
            ]];
        } catch (Throwable $e) {
            $adjunto = null;   // sin adjunto es peor, pero mejor que no enviar nada
        }
    }

    $r = mail_enviar($para, 'Cotización ' . $c['numero'] . ' · ' . ($emp['nombre'] ?? APP_NAME), $html,
                     $adjunto ? ['attachments' => $adjunto] : []);

    mail_registrar(null, 'cotizacion', $para, 'Cotización ' . $c['numero'], $r);

    if ($r['ok'] && $c['estado'] === 'borrador') {
        dbUpdate('cotizaciones', ['estado' => 'enviada', 'enviada_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
    } elseif ($r['ok']) {
        dbUpdate('cotizaciones', ['enviada_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
    }

    return ['ok' => $r['ok'], 'error' => $r['error']];
}

/** Mensaje de WhatsApp para mandarle la cotización al cliente. */
function cot_textoWhatsapp(array $c): string
{
    $emp    = $GLOBALS['empresa'] ?? [];
    $esBase = (int) ($c['moneda_es_base'] ?? 1) === 1;
    $total  = $esBase ? money((float) $c['total'])
                      : $c['moneda_simbolo'] . ' ' . number_format((float) $c['total'], 2, '.', ',');

    return 'Hola ' . $c['cliente'] . ', le escribo de ' . ($emp['nombre'] ?? APP_NAME) . '. '
         . 'Le preparé la cotización ' . $c['numero'] . ' por ' . $total . ', válida hasta el '
         . fechaCorta($c['vence']) . '. Se la envío por aquí. ¿Le parece bien?';
}
