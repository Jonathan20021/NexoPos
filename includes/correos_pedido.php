<?php
/**
 * Correos automáticos de los pedidos de la tienda en línea.
 *
 * Nada de aquí lanza excepciones ni bloquea la operación que lo dispara:
 * si Resend está caído, el pedido igual se registra y el fallo queda anotado
 * en `correos_enviados`.
 */

/** Datos completos del pedido para armar cualquier correo. */
function correoPedidoDatos(int $pedidoId): ?array
{
    $p = qOne(
        "SELECT p.*, s.nombre AS sucursal, s.direccion, s.telefono AS suc_telefono,
                s.email AS suc_email, s.horario
           FROM pedidos p JOIN sucursales s ON s.id = p.sucursal_id
          WHERE p.id = ?",
        [$pedidoId]
    );
    if (!$p) return null;
    $p['detalles'] = qAll("SELECT * FROM pedido_detalles WHERE pedido_id = ? ORDER BY id", [$pedidoId]);
    return $p;
}

/** A dónde avisamos de un pedido nuevo: la sucursal, y si no tiene, la empresa. */
function correoDestinoSucursal(array $p, array $emp): ?string
{
    foreach ([$p['suc_email'] ?? '', $emp['email'] ?? ''] as $c) {
        if ($c !== '' && filter_var($c, FILTER_VALIDATE_EMAIL)) return $c;
    }
    return null;
}

/** URL pública absoluta del pedido, la misma que ve el cliente. */
function correoUrlPedido(array $p): string
{
    $raiz = rtrim((string) (getenv('APP_PUBLIC_URL') ?: ''), '/');
    if ($raiz === '') {
        $esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $raiz = $esquema . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    // url() ya devuelve la ruta limpia, sin la extensión .php.
    return $raiz . url('tienda/pedido.php?token=' . $p['token']);
}

// ---------------------------------------------------------------------------
//  Eventos
// ---------------------------------------------------------------------------

/** Pedido recién creado: confirma al cliente y avisa a la sucursal. */
function correoPedidoNuevo(int $pedidoId): void
{
    $emp = $GLOBALS['empresa'] ?: [];
    $p = correoPedidoDatos($pedidoId);
    if (!$p) return;

    $url = correoUrlPedido($p);
    $tabla = mail_tabla_pedido($p, $p['detalles']);
    $pago = $p['metodo_pago'] === 'link_pago'
        ? 'Te enviaremos el <strong>link de pago</strong> por WhatsApp al ' . e($p['cliente_telefono']) . '.'
        : 'Pagas <strong>al retirar</strong>, en efectivo o con tarjeta.';

    // ---- Al cliente ----
    if (filter_var((string) $p['cliente_email'], FILTER_VALIDATE_EMAIL)) {
        $html = mail_plantilla('Pedido ' . $p['numero'], '
            <p style="margin:0 0 6px;font-size:20px;font-weight:700;">¡Recibimos tu pedido!</p>
            <p style="margin:0 0 18px;color:#4B7A5A;">Hola ' . e($p['cliente_nombre']) . ', tu pedido
               <strong>' . e($p['numero']) . '</strong> quedó registrado. Todavía no se ha cobrado nada.</p>
            ' . $tabla . '
            <p style="margin:18px 0 4px;font-weight:600;">Retiras en ' . e($p['sucursal']) . '</p>
            <p style="margin:0;color:#4B7A5A;">' . e($p['direccion'] ?: '') .
              ($p['horario'] ? '<br>' . e($p['horario']) : '') . '</p>
            <p style="margin:18px 0 0;">' . $pago . '</p>
            ' . mail_boton('Ver mi pedido', $url) . '
            <p style="margin:0;color:#4B7A5A;font-size:13px;">Confirmamos disponibilidad antes de facturar.</p>',
            $emp, 'Pedido ' . $p['numero'] . ' por ' . money($p['total'])
        );
        $r = mail_enviar($p['cliente_email'], 'Recibimos tu pedido ' . $p['numero'], $html);
        mail_registrar($pedidoId, 'nuevo_cliente', $p['cliente_email'], 'Recibimos tu pedido ' . $p['numero'], $r);
    }

    // ---- A la sucursal ----
    $destino = correoDestinoSucursal($p, $emp);
    if ($destino) {
        $doc = $p['cliente_documento'] ? '<br>RNC/Cédula: ' . e($p['cliente_documento']) : '';
        $nota = $p['notas'] ? '<p style="margin:14px 0 0;padding:10px 12px;background:#F0FDF4;border-radius:8px;">
                                 <strong>Nota del cliente:</strong> ' . e($p['notas']) . '</p>' : '';
        $html = mail_plantilla('Nuevo pedido ' . $p['numero'], '
            <p style="margin:0 0 6px;font-size:20px;font-weight:700;">Nuevo pedido en línea</p>
            <p style="margin:0 0 18px;color:#4B7A5A;"><strong>' . e($p['numero']) . '</strong> &middot; '
              . e($p['sucursal']) . ' &middot; ' . ($p['metodo_pago'] === 'link_pago' ? 'Pide link de pago' : 'Paga al retirar') . '</p>
            <p style="margin:0 0 4px;font-weight:600;">' . e($p['cliente_nombre']) . '</p>
            <p style="margin:0;color:#4B7A5A;">' . e($p['cliente_telefono'])
              . ($p['cliente_email'] ? '<br>' . e($p['cliente_email']) : '') . $doc . '</p>
            ' . $tabla . $nota . '
            ' . mail_boton('Abrir el pedido', $url, '#15803D'),
            $emp, $p['numero'] . ' &middot; ' . money($p['total'])
        );
        $r = mail_enviar($destino, 'Nuevo pedido ' . $p['numero'] . ' · ' . money($p['total']), $html);
        mail_registrar($pedidoId, 'nuevo_sucursal', $destino, 'Nuevo pedido ' . $p['numero'], $r);
    }
}

/** El operador cargó (o cambió) el link de pago: se lo mandamos al cliente. */
function correoPedidoLinkPago(int $pedidoId, string $link): void
{
    $emp = $GLOBALS['empresa'] ?: [];
    $p = correoPedidoDatos($pedidoId);
    if (!$p || !filter_var((string) $p['cliente_email'], FILTER_VALIDATE_EMAIL)) return;

    $html = mail_plantilla('Link de pago · ' . $p['numero'], '
        <p style="margin:0 0 6px;font-size:20px;font-weight:700;">Ya puedes pagar tu pedido</p>
        <p style="margin:0 0 18px;color:#4B7A5A;">Hola ' . e($p['cliente_nombre']) . ', aquí tienes el enlace
           para pagar tu pedido <strong>' . e($p['numero']) . '</strong>.</p>
        ' . mail_tabla_pedido($p, $p['detalles']) . '
        ' . mail_boton('Pagar ' . money($p['total']), $link) . '
        <p style="margin:0;color:#4B7A5A;font-size:13px;">Si el botón no abre, copia este enlace:<br>
           <span style="word-break:break-all;">' . e($link) . '</span></p>',
        $emp, 'Paga ' . money($p['total']) . ' de tu pedido ' . $p['numero']
    );
    $asunto = 'Link de pago de tu pedido ' . $p['numero'];
    $r = mail_enviar($p['cliente_email'], $asunto, $html);
    mail_registrar($pedidoId, 'link_pago', $p['cliente_email'], $asunto, $r);
}

/** Cambió el estado del pedido: se le avisa al cliente. */
function correoPedidoEstado(int $pedidoId, string $estado): void
{
    $emp = $GLOBALS['empresa'] ?: [];
    $p = correoPedidoDatos($pedidoId);
    if (!$p || !filter_var((string) $p['cliente_email'], FILTER_VALIDATE_EMAIL)) return;

    // «pendiente» y «confirmado» no le dicen nada útil al cliente: no se notifican.
    $textos = [
        'listo'     => ['Tu pedido está listo', 'Puedes pasar a retirarlo en ' . e($p['sucursal']) . '.'],
        'entregado' => ['¡Gracias por tu compra!', 'Tu pedido fue entregado. Esperamos verte pronto.'],
        'cancelado' => ['Tu pedido fue cancelado', 'Si crees que es un error, respóndenos o escríbenos por WhatsApp.'],
    ];
    if (!isset($textos[$estado])) return;
    [$titulo, $detalle] = $textos[$estado];

    $extra = $estado === 'listo' && $p['horario'] ? '<p style="margin:0;color:#4B7A5A;">' . e($p['horario']) . '</p>' : '';
    $html = mail_plantilla($titulo . ' · ' . $p['numero'], '
        <p style="margin:0 0 6px;font-size:20px;font-weight:700;">' . $titulo . '</p>
        <p style="margin:0 0 18px;color:#4B7A5A;">Hola ' . e($p['cliente_nombre']) . ', ' . $detalle . '</p>
        ' . mail_tabla_pedido($p, $p['detalles']) . $extra . '
        ' . mail_boton('Ver mi pedido', correoUrlPedido($p), '#15803D'),
        $emp, $titulo . ' — ' . $p['numero']
    );
    $asunto = $titulo . ' · ' . $p['numero'];
    $r = mail_enviar($p['cliente_email'], $asunto, $html);
    mail_registrar($pedidoId, 'estado_' . $estado, $p['cliente_email'], $asunto, $r);
}
