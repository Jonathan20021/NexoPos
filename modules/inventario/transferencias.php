<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('transferencias.ver');

/** Valida origen/destino/líneas de un formulario de transferencia. @return array{origen,destino,fecha,det} */
function transferenciaValidarEntrada(): array
{
    $origen = postInt('sucursal_origen_id');
    $destino = postInt('sucursal_destino_id');
    $fecha = post('fecha') ?: date('Y-m-d');
    // La dirección lo pidió: no sale mercancía de una tienda sin que quede
    // escrito por qué. Vale para el borrador y para todo lo que venga después.
    $motivo = trim(post('notas'));
    if ($motivo === '') throw new RuntimeException('Escribe el motivo de la transferencia: por qué sale esa mercancía.');
    $lineas = json_decode(post('lineas', '[]'), true);
    if ($origen <= 0 || $destino <= 0 || $origen === $destino || !is_array($lineas) || !$lineas) {
        throw new RuntimeException('Selecciona origen y destino distintos y agrega productos.');
    }
    require_sucursal_access($origen);
    if (!qVal("SELECT 1 FROM sucursales WHERE id=? AND activo=1", [$destino])) {
        throw new RuntimeException('La sucursal de destino no es válida.');
    }
    $det = [];
    foreach ($lineas as $l) {
        $pid = (int) ($l['producto_id'] ?? 0); $cant = (float) ($l['cantidad'] ?? 0);
        if ($pid <= 0 || $cant <= 0) continue;
        $det[$pid] = ['pid' => $pid, 'cant' => ($det[$pid]['cant'] ?? 0) + $cant];
    }
    if (!$det) throw new RuntimeException('No hay líneas válidas.');
    return ['origen' => $origen, 'destino' => $destino, 'fecha' => $fecha,
            'motivo' => mb_substr($motivo, 0, 255), 'det' => array_values($det)];
}

if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    // Crear: guarda como borrador y, si se pidió, lo envía en el mismo paso.
    if ($accion === 'guardar') {
        require_perm('transferencias.crear');
        $enviarYa = post('modo') === 'enviar';
        if ($enviarYa) require_perm('transferencias.enviar');
        try {
            $in = transferenciaValidarEntrada();
            $tid = txReintentable(function () use ($in, $enviarYa) {
                $numero = nextNumero('transferencias', 'numero', 'TRF');
                $tid = dbInsert('transferencias', ['numero' => $numero, 'sucursal_origen_id' => $in['origen'], 'sucursal_destino_id' => $in['destino'], 'fecha' => $in['fecha'], 'notas' => $in['motivo'], 'estado' => 'borrador', 'usuario_id' => current_user()['id']]);
                foreach ($in['det'] as $d) {
                    dbInsert('transferencia_detalles', ['transferencia_id' => $tid, 'producto_id' => $d['pid'], 'cantidad' => $d['cant']]);
                }
                if ($enviarYa) transferenciaSolicitar($tid);
                return $tid;
            });
            // El aviso va FUERA de la transacción: la solicitud ya está guardada y
            // un servidor de correo caído no puede deshacerla.
            $avisoCorreo = $enviarYa ? transferenciaAvisarAprobadores($tid) : null;
            audit('transferencias', $enviarYa ? 'enviar' : 'crear', ($enviarYa ? 'Transferencia enviada a aprobación' : 'Borrador de transferencia creado') . " #$tid", ['tabla' => 'transferencias', 'registro_id' => $tid]);
            flash('success', $enviarYa
                ? 'Enviada a aprobación. La mercancía NO sale del origen hasta que alguien la apruebe.'
                : 'Borrador guardado. Puedes editarlo y mandarlo a aprobación cuando esté listo.');
            if ($avisoCorreo) flash(...transferenciaFlashAviso($avisoCorreo));
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/inventario/transferencias.php');
    }

    // Editar un borrador: reemplaza sus líneas (no toca stock, sigue en borrador).
    if ($accion === 'editar') {
        require_perm('transferencias.crear');
        $id = postInt('id');
        try {
            $in = transferenciaValidarEntrada();
            txReintentable(function () use ($id, $in) {
                $t = qOne("SELECT * FROM transferencias WHERE id=? FOR UPDATE", [$id]);
                if (!$t || $t['estado'] !== 'borrador') throw new RuntimeException('Solo se puede editar un borrador.');
                if (!can_access_sucursal($t['sucursal_origen_id'])) deny_access();
                dbUpdate('transferencias', ['sucursal_origen_id' => $in['origen'], 'sucursal_destino_id' => $in['destino'], 'fecha' => $in['fecha'], 'notas' => $in['motivo']], 'id=?', [$id]);
                q("DELETE FROM transferencia_detalles WHERE transferencia_id=?", [$id]);
                foreach ($in['det'] as $d) {
                    dbInsert('transferencia_detalles', ['transferencia_id' => $id, 'producto_id' => $d['pid'], 'cantidad' => $d['cant']]);
                }
            });
            audit('transferencias', 'editar', "Borrador de transferencia editado #$id", ['tabla' => 'transferencias', 'registro_id' => $id]);
            flash('success', 'Borrador actualizado.');
        } catch (Throwable $e) { flash('error', $e->getMessage()); }
        redirect('modules/inventario/transferencias.php');
    }

    // Eliminar un borrador (nunca movió stock).
    if ($accion === 'eliminar') {
        require_perm('transferencias.crear');
        $id = postInt('id');
        try {
            txReintentable(function () use ($id) {
                $t = qOne("SELECT * FROM transferencias WHERE id=? FOR UPDATE", [$id]);
                if (!$t || $t['estado'] !== 'borrador') throw new RuntimeException('Solo se puede eliminar un borrador.');
                if (!can_access_sucursal($t['sucursal_origen_id'])) deny_access();
                q("DELETE FROM transferencia_detalles WHERE transferencia_id=?", [$id]);
                q("DELETE FROM transferencias WHERE id=?", [$id]);
            });
            audit('transferencias', 'eliminar', "Borrador de transferencia eliminado #$id");
            flash('success', 'Borrador eliminado.');
        } catch (Throwable $e) { flash('error', $e->getMessage()); }
        redirect('modules/inventario/transferencias.php');
    }

    // Mandar un borrador a aprobación. NO mueve stock todavía.
    if ($accion === 'enviar') {
        require_perm('transferencias.enviar');
        $id = postInt('id');
        try {
            txReintentable(fn() => transferenciaSolicitar($id));
            $avisoCorreo = transferenciaAvisarAprobadores($id);
            audit('transferencias', 'enviar', "Transferencia #$id enviada a aprobación", ['tabla' => 'transferencias', 'registro_id' => $id]);
            flash('success', 'Enviada a aprobación. La mercancía sigue en el origen hasta que alguien la apruebe.');
            flash(...transferenciaFlashAviso($avisoCorreo));
        } catch (Throwable $e) { flash('error', $e->getMessage()); }
        redirect('modules/inventario/transferencias.php');
    }

    // Aprobar: AQUÍ sale la mercancía.
    if ($accion === 'aprobar') {
        require_perm('transferencias.aprobar');
        $id = postInt('id');
        try {
            // Descuenta stock de varios productos a la vez. Si choca con una venta
            // simultánea de los mismos, toca reintentar en vez de soltarle el error
            // de InnoDB a quien solo estaba aprobando una salida.
            txReintentable(fn() => transferenciaEnviar($id));
            audit('transferencias', 'enviar', "Transferencia #$id aprobada: la mercancía salió del origen", ['tabla' => 'transferencias', 'registro_id' => $id]);
            flash('success', 'Aprobada. Stock descontado del origen.');
        } catch (Throwable $e) { flash('error', $e->getMessage()); }
        redirect('modules/inventario/transferencias.php');
    }

    // Devolver a borrador con el motivo de por qué no se aprueba.
    if ($accion === 'devolver') {
        require_perm('transferencias.aprobar');
        $id = postInt('id');
        try {
            $motivo = trim(post('motivo_rechazo'));
            txReintentable(fn() => transferenciaDevolverABorrador($id, $motivo));
            audit('transferencias', 'editar', "Transferencia #$id devuelta a borrador: $motivo", ['tabla' => 'transferencias', 'registro_id' => $id]);
            flash('success', 'Devuelta a borrador. Quien la solicitó verá el motivo.');
        } catch (Throwable $e) { flash('error', $e->getMessage()); }
        redirect('modules/inventario/transferencias.php');
    }

    // Recibir: entra al destino lo que LLEGO, que no siempre es lo que salió.
    // Si salieron 10 y llegaron 8, dar por recibidas las 10 crea un fantasma
    // que no aparece hasta el conteo físico siguiente y ya sin rastro de dónde
    // se perdió. La diferencia se guarda con su motivo y sale en el informe de
    // ajustes y mermas.
    if ($accion === 'recibir') {
        require_perm('transferencias.recibir');
        $id      = postInt('id');
        $llegado = (array) ($_POST['recibida'] ?? []);
        $motivo  = trim((string) post('notas_recepcion'));
        try {
            $faltantes = [];
            txReintentable(function () use ($id, $llegado, $motivo, &$faltantes) {
                $t = qOne("SELECT * FROM transferencias WHERE id=? FOR UPDATE", [$id]);
                if (!$t || $t['estado'] !== 'enviada') throw new RuntimeException('La transferencia no se puede recibir.');
                if (!can_access_sucursal($t['sucursal_destino_id'])) throw new RuntimeException('Solo la sucursal de destino puede recibir esta transferencia.');

                $det = qAll("SELECT * FROM transferencia_detalles WHERE transferencia_id=? ORDER BY producto_id", [$id]);

                // Primero se decide, y solo después se mueve nada: así un
                // formulario mal llenado no deja media transferencia recibida.
                $plan = [];
                foreach ($det as $d) {
                    $env = (float) $d['cantidad'];
                    // Sin el campo (recepción de toda la vida, o botón simple)
                    // se recibe entero: no se puede cambiar lo que ya hacía.
                    $rec = array_key_exists((string) $d['id'], $llegado)
                        ? round((float) $llegado[(string) $d['id']], 3) : $env;
                    if ($rec < 0)   throw new RuntimeException('Las cantidades recibidas no pueden ser negativas.');
                    if ($rec > $env + 0.0001) {
                        throw new RuntimeException('No se puede recibir más de lo que se envió: '
                            . qty($rec) . ' de ' . qty($env) . ' enviadas.');
                    }
                    $plan[] = ['d' => $d, 'rec' => $rec, 'falta' => round($env - $rec, 3)];
                }
                $faltaTotal = array_sum(array_column($plan, 'falta'));
                $recTotal   = array_sum(array_column($plan, 'rec'));

                // Recibir cero de todo no es recibir: es rechazar. Y rechazar
                // devuelve el stock al origen, que es MUY distinto de perderlo.
                if ($recTotal <= 0.0001) {
                    throw new RuntimeException('No llegó nada de esta transferencia. Usa «Rechazar» en vez de «Recibir»: '
                        . 'así el stock vuelve al origen en lugar de darse por perdido.');
                }
                if ($faltaTotal > 0.0001 && $motivo === '') {
                    throw new RuntimeException('Faltó mercancía por el camino. Escribe qué pasó antes de recibir: '
                        . 'esas unidades salieron del origen y no van a entrar en ningún sitio.');
                }

                foreach ($plan as $pz) {
                    $d = $pz['d'];
                    dbUpdate('transferencia_detalles', ['cantidad_recibida' => $pz['rec']], 'id=?', [(int) $d['id']]);
                    if ($pz['rec'] <= 0.0001) continue;
                    // La mercancía conserva su lote al cambiar de almacén: se
                    // recrean en destino los mismos lotes que FEFO sacó del origen.
                    // Sin esto, un producto trazable dejaba de serlo justo al
                    // cruzar de sucursal.
                    san_mover_conservando_lotes(
                        (int) $d['producto_id'], (int) $t['sucursal_destino_id'], $pz['rec'],
                        'transferencia_entrada', 'transferencia', $id, (int) $t['sucursal_origen_id'],
                        0, 'Transferencia ' . $t['numero'] . ' (entrada)'
                    );
                    if ($pz['falta'] > 0.0001) $faltantes[] = $pz['falta'];
                }
                foreach ($plan as $pz) if ($pz['rec'] <= 0.0001) $faltantes[] = $pz['falta'];

                dbUpdate('transferencias', [
                    'estado' => 'recibida', 'recibida_por' => current_user()['id'],
                    'recibida_at' => date('Y-m-d H:i:s'),
                    'notas_recepcion' => $motivo !== '' ? mb_substr($motivo, 0, 500) : null,
                ], 'id=?', [$id]);
            });
            audit('transferencias', 'recibir', "Transferencia recibida #$id"
                . ($faltantes ? ' con ' . count($faltantes) . ' faltante(s)' : ''),
                ['tabla' => 'transferencias', 'registro_id' => $id]);
            if ($faltantes) {
                flash('warning', 'Transferencia recibida con ' . count($faltantes) . ' faltante(s). '
                    . 'Solo entró al destino lo que llegó; la diferencia queda registrada y sale en «Ajustes y mermas».');
            } else {
                flash('success', 'Transferencia recibida completa. Stock agregado al destino.');
            }
        } catch (Throwable $e) { flash('error', $e->getMessage()); }
        redirect('modules/inventario/transferencias.php');
    }

    // Rechazar: el destino no acepta una enviada; el stock vuelve al origen.
    if ($accion === 'rechazar') {
        require_perm('transferencias.rechazar');
        $id = postInt('id');
        $motivo = trim(post('motivo_rechazo'));
        try {
            if ($motivo === '') throw new RuntimeException('Indica el motivo del rechazo.');
            txReintentable(function () use ($id, $motivo) {
                $t = qOne("SELECT * FROM transferencias WHERE id=? FOR UPDATE", [$id]);
                if (!$t || $t['estado'] !== 'enviada') throw new RuntimeException('Solo se puede rechazar una transferencia enviada.');
                if (!can_access_sucursal($t['sucursal_destino_id'])) throw new RuntimeException('Solo la sucursal de destino puede rechazar esta transferencia.');
                transferenciaDevolverStock($t);
                dbUpdate('transferencias', ['estado' => 'rechazada', 'motivo_rechazo' => $motivo, 'recibida_por' => current_user()['id'], 'recibida_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
            });
            audit('transferencias', 'rechazar', "Transferencia rechazada #$id: $motivo", ['tabla' => 'transferencias', 'registro_id' => $id]);
            flash('success', 'Transferencia rechazada. Stock devuelto al origen.');
        } catch (Throwable $e) { flash('error', $e->getMessage()); }
        redirect('modules/inventario/transferencias.php');
    }

    if ($accion === 'anular') {
        require_perm('transferencias.anular');
        $id = postInt('id');
        try {
            txReintentable(function () use ($id) {
                $t = qOne("SELECT * FROM transferencias WHERE id=? FOR UPDATE", [$id]);
                if (!$t || $t['estado'] !== 'enviada') throw new RuntimeException('Solo se pueden anular transferencias enviadas (no recibidas).');
                if (!can_access_sucursal($t['sucursal_origen_id'])) throw new RuntimeException('Solo la sucursal de origen puede anular esta transferencia.');
                transferenciaDevolverStock($t);
                dbUpdate('transferencias', ['estado' => 'anulada'], 'id=?', [$id]);
            });
            audit('transferencias', 'anular', "Transferencia anulada #$id", ['tabla' => 'transferencias', 'registro_id' => $id]);
            flash('success', 'Transferencia anulada. Stock devuelto al origen.');
        } catch (Throwable $e) { flash('error', $e->getMessage()); }
        redirect('modules/inventario/transferencias.php');
    }
}

// ----- Detalle -----
$verId = (int) get('ver');
if ($verId) {
    $t = qOne("SELECT t.*, so.nombre AS origen, sd.nombre AS destino, u.nombre AS usuario FROM transferencias t JOIN sucursales so ON so.id=t.sucursal_origen_id JOIN sucursales sd ON sd.id=t.sucursal_destino_id LEFT JOIN usuarios u ON u.id=t.usuario_id WHERE t.id=?", [$verId]);
    if (!$t) { flash('error', 'Transferencia no encontrada.'); redirect('modules/inventario/transferencias.php'); }
    if (!can_access_sucursal($t['sucursal_origen_id']) && !can_access_sucursal($t['sucursal_destino_id'])) {
        deny_access();
    }
    $det = qAll("SELECT td.*, p.nombre AS producto, p.codigo FROM transferencia_detalles td JOIN productos p ON p.id=td.producto_id WHERE td.transferencia_id=?", [$verId]);
    layout_start('Transferencia ' . e($t['numero']), 'Detalle', '<a href="' . url('modules/inventario/transferencias.php') . '" class="btn btn-ghost">' . icon('arrow-left', 'w-4 h-4') . ' Volver</a>');
    ?>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
      <?php
      // Si llegó todo, sobra la columna de faltantes: una tabla con una columna
      // de ceros solo hace ruido. Se enseña únicamente cuando hubo diferencia.
      $huboFaltante = false;
      foreach ($det as $d) {
          if ($d['cantidad_recibida'] !== null
              && (float) $d['cantidad_recibida'] < (float) $d['cantidad'] - 0.0001) $huboFaltante = true;
      }
      ?>
      <div class="card lg:col-span-2 overflow-hidden">
        <div class="overflow-x-auto">
        <table class="data-table">
          <thead><tr>
            <th>Producto</th>
            <th class="text-right"><?= $huboFaltante ? 'Enviado' : 'Cantidad' ?></th>
            <?php if ($huboFaltante): ?><th class="text-right">Llegó</th><th class="text-right">Faltó</th><?php endif; ?>
          </tr></thead>
          <tbody><?php foreach ($det as $d):
            $rec   = $d['cantidad_recibida'] === null ? null : (float) $d['cantidad_recibida'];
            $falta = $rec === null ? 0.0 : round((float) $d['cantidad'] - $rec, 3); ?>
            <tr>
              <td><p class="font-semibold text-slate-700"><?= e($d['producto']) ?></p><p class="text-xs text-slate-400"><?= e($d['codigo']) ?></p></td>
              <td class="text-right font-bold text-slate-800"><?= qty($d['cantidad']) ?></td>
              <?php if ($huboFaltante): ?>
                <td class="text-right font-semibold text-slate-700"><?= $rec === null ? '—' : qty($rec) ?></td>
                <td class="text-right font-bold <?= $falta > 0.0001 ? 'text-amber-600' : 'text-slate-300' ?>"><?= $falta > 0.0001 ? qty($falta) : '—' ?></td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?></tbody>
        </table>
        </div>
      </div>
      <div class="card p-5 h-fit space-y-3">
        <div class="flex items-center justify-between"><span class="text-xs text-slate-400">Estado</span><?= badgeFor($t['estado']) ?></div>
        <div class="flex items-center gap-2 text-sm"><span class="badge badge-slate"><?= e($t['origen']) ?></span><?= icon('arrow-right', 'w-4 h-4 text-slate-300') ?><span class="badge badge-blue"><?= e($t['destino']) ?></span></div>
        <div><p class="text-xs text-slate-400">Fecha</p><p class="font-semibold text-slate-700"><?= fechaCorta($t['fecha']) ?></p></div>
        <div><p class="text-xs text-slate-400">Creada por</p><p class="font-semibold text-slate-700"><?= e($t['usuario'] ?: '—') ?></p></div>
        <?php if (!empty($t['notas_recepcion'])): ?>
          <div class="rounded-xl bg-amber-50 border border-amber-100 p-3">
            <p class="text-xs text-amber-600 font-semibold">Qué pasó al recibir</p>
            <p class="text-sm text-slate-700 mt-0.5"><?= e($t['notas_recepcion']) ?></p>
          </div>
        <?php endif; ?>
        <?php if ($t['estado'] === 'rechazada' && !empty($t['motivo_rechazo'])): ?>
          <div class="rounded-xl bg-rose-50 border border-rose-100 p-3">
            <p class="text-xs text-rose-500 font-semibold">Motivo del rechazo</p>
            <p class="text-sm text-slate-700 mt-0.5"><?= e($t['motivo_rechazo']) ?></p>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php layout_end(); return;
}

// ----- Listado -----
// Una transferencia toca dos sucursales, así que el alcance mira origen y destino.
$sid = current_sucursal_id();
$scope = $sid === null ? '1=1' : "(t.sucursal_origen_id = $sid OR t.sucursal_destino_id = $sid)";

$q = trim(get('q'));
$estadoT = in_array(get('estado'), ['borrador', 'enviada', 'recibida', 'rechazada', 'anulada'], true) ? get('estado') : '';
$cond = [$scope];
$params = [];
if ($q !== '')       { $cond[] = "(t.numero LIKE ? OR so.nombre LIKE ? OR sd.nombre LIKE ?)"; array_push($params, "%$q%", "%$q%", "%$q%"); }
if ($estadoT !== '') { $cond[] = "t.estado = ?"; $params[] = $estadoT; }
$where = implode(' AND ', $cond);

$joinT = "FROM transferencias t JOIN sucursales so ON so.id=t.sucursal_origen_id JOIN sucursales sd ON sd.id=t.sucursal_destino_id WHERE $where";
$pg = paginar((int) qVal("SELECT COUNT(*) $joinT", $params), 25);
$transferencias = qAll("SELECT t.*, so.nombre AS origen, sd.nombre AS destino $joinT ORDER BY t.id DESC LIMIT {$pg['porPagina']} OFFSET {$pg['offset']}", $params);

$productosJs = array_map(fn($p) => ['id' => (int) $p['id'], 'nombre' => $p['nombre']], qAll("SELECT id, nombre FROM productos WHERE activo=1 AND tipo='producto' ORDER BY nombre"));
$sucursales = qAll("SELECT id, nombre FROM sucursales WHERE activo=1 ORDER BY nombre");

// Líneas de los borradores visibles, para poder editarlos desde el modal sin otra consulta por fila.
$lineasPorTrf = [];
$idsBorrador = array_values(array_map(fn($t) => (int) $t['id'], array_filter($transferencias, fn($t) => $t['estado'] === 'borrador')));
if ($idsBorrador) {
    $ph = implode(',', array_fill(0, count($idsBorrador), '?'));
    foreach (qAll("SELECT td.transferencia_id, td.producto_id, td.cantidad, p.nombre FROM transferencia_detalles td JOIN productos p ON p.id=td.producto_id WHERE td.transferencia_id IN ($ph)", $idsBorrador) as $r) {
        $lineasPorTrf[(int) $r['transferencia_id']][] = ['producto_id' => (int) $r['producto_id'], 'nombre' => $r['nombre'], 'cantidad' => (float) $r['cantidad']];
    }
}

// Líneas de lo que está EN CAMINO hacia una sucursal que quien mira puede
// recibir: hacen falta en la pantalla para poder contar bulto por bulto lo que
// llegó de verdad, en vez de dar por bueno lo que dice el papel.
$lineasRecepcion = [];
$idsEnviada = array_values(array_map(fn($t) => (int) $t['id'], array_filter(
    $transferencias,
    fn($t) => $t['estado'] === 'enviada' && can_access_sucursal((int) $t['sucursal_destino_id'])
)));
if ($idsEnviada && can('transferencias.recibir')) {
    $phR = implode(',', array_fill(0, count($idsEnviada), '?'));
    foreach (qAll("SELECT td.id, td.transferencia_id, td.cantidad, p.nombre, p.codigo
                     FROM transferencia_detalles td
                     JOIN productos p ON p.id = td.producto_id
                    WHERE td.transferencia_id IN ($phR)
                    ORDER BY p.nombre", $idsEnviada) as $r) {
        $lineasRecepcion[(int) $r['transferencia_id']][] = [
            'detalle_id' => (int) $r['id'], 'nombre' => $r['nombre'],
            'codigo' => (string) $r['codigo'], 'enviada' => (float) $r['cantidad'],
        ];
    }
}

/* ---------------------------------------------------------------------------
 *  Propuesta que llega del informe «Qué mover y a dónde»
 *
 *  El informe calcula qué mover y de dónde a dónde; retecleaqr veinte líneas a
 *  mano es justo donde se cuela el número equivocado. Llega en la URL como
 *  `sug=idProducto:cantidad,...` y aquí se convierte en las líneas del
 *  formulario, VALIDÁNDOLAS: el producto tiene que existir y estar activo, la
 *  cantidad ser positiva, y el origen y el destino ser sucursales que quien
 *  mira pueda tocar. Lo que llega por la URL es una sugerencia, no una orden.
 * ------------------------------------------------------------------------ */
$sugerencia = null;
if (can('transferencias.crear') && trim((string) get('sug')) !== '') {
    $sugOrigen  = (int) get('origen');
    $sugDestino = (int) get('destino');
    $pares = [];
    foreach (explode(',', (string) get('sug')) as $par) {
        [$pid, $cant] = array_pad(explode(':', $par, 2), 2, null);
        $pid = (int) $pid; $cant = round((float) $cant, 3);
        if ($pid > 0 && $cant > 0) $pares[$pid] = ($pares[$pid] ?? 0) + $cant;
    }
    $okSuc = $sugOrigen > 0 && $sugDestino > 0 && $sugOrigen !== $sugDestino
        && can_access_sucursal($sugOrigen)
        && qVal("SELECT 1 FROM sucursales WHERE id = ? AND activo = 1", [$sugDestino]);

    if ($pares && $okSuc) {
        $ph2 = implode(',', array_fill(0, count($pares), '?'));
        $lineasSug = [];
        foreach (qAll("SELECT id, nombre FROM productos WHERE id IN ($ph2) AND activo = 1 AND tipo = 'producto'",
                      array_keys($pares)) as $p) {
            $lineasSug[] = ['producto_id' => (int) $p['id'], 'nombre' => $p['nombre'],
                            'cantidad' => $pares[(int) $p['id']]];
        }
        if ($lineasSug) {
            $sugerencia = ['id' => 0, 'origen' => $sugOrigen, 'destino' => $sugDestino,
                           'fecha' => date('Y-m-d'),
                           'notas' => 'Reposición sugerida por el sistema según lo que vende cada tienda.',
                           'lineas' => $lineasSug];
            $descartadas = count($pares) - count($lineasSug);
            if ($descartadas > 0) {
                flash('warning', $descartadas . ' línea(s) de la sugerencia se descartaron: '
                    . 'ese producto ya no existe o está inactivo.');
            }
        }
    } elseif ($pares) {
        flash('error', 'La sugerencia no se pudo cargar: revisa que el origen y el destino sean '
            . 'sucursales distintas a las que tengas acceso.');
    }
}

$acciones = (can('transferencias.crear') && count($sucursales) > 1) ? '<button onclick="' . jsEvent('trf:new') . '" class="btn btn-primary">' . icon('transfer', 'w-4 h-4') . ' Nueva transferencia</button>' : '';
layout_start('Transferencias', 'Movimiento de inventario entre sucursales', $acciones);

if (count($sucursales) < 2):
    echo empty_state('Se necesitan al menos 2 sucursales', 'Crea otra sucursal para transferir inventario entre ellas.', 'transfer');
    layout_end(); return;
endif;

// El indicador que importa es «enviada»: esa mercancía ya salió del almacén de
// origen y todavía no la ha recibido nadie. Está en el limbo, y cuanto más
// tiempo pase más difícil es saber si se perdió o si nadie pulsó «recibir».
// Se mide sobre el alcance de sucursal, no sobre el filtro de pantalla.
$resumen = qOne(
    "SELECT COALESCE(SUM(t.estado = 'borrador'), 0) borradores,
            COALESCE(SUM(t.estado = 'pendiente'), 0) por_aprobar,
            COALESCE(SUM(t.estado = 'enviada'), 0)  en_camino,
            COALESCE(SUM(t.estado = 'enviada' AND t.enviada_at < DATE_SUB(NOW(), INTERVAL 7 DAY)), 0) varadas,
            COALESCE(SUM(t.estado = 'recibida' AND t.recibida_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')), 0) recibidas_mes
       FROM transferencias t WHERE $scope"
) ?: ['borradores' => 0, 'por_aprobar' => 0, 'en_camino' => 0, 'varadas' => 0, 'recibidas_mes' => 0];

echo kpis([
    ['label' => 'En camino', 'valor' => number_format((int) $resumen['en_camino']), 'icono' => 'truck',
     'color' => (int) $resumen['en_camino'] > 0 ? 'sky' : 'slate',
     'nota' => (int) $resumen['varadas'] > 0
        ? number_format((int) $resumen['varadas']) . ' llevan más de 7 días sin recibirse'
        : 'Ninguna atrasada',
     'href' => (int) $resumen['en_camino'] > 0 ? '?estado=enviada' : ''],
    ['label' => 'Por aprobar', 'valor' => number_format((int) $resumen['por_aprobar']), 'icono' => 'clock',
     'color' => (int) $resumen['por_aprobar'] > 0 ? 'amber' : 'slate',
     'nota' => 'La mercancía sigue en el origen',
     'href' => (int) $resumen['por_aprobar'] > 0 ? '?estado=pendiente' : ''],
    ['label' => 'Recibidas este mes', 'valor' => number_format((int) $resumen['recibidas_mes']), 'icono' => 'check',
     'color' => 'emerald', 'nota' => 'Ya sumaron al destino', 'href' => '?estado=recibida'],
], 3);
?>

<div class="card overflow-hidden">
  <form method="get" class="p-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
    <div class="flex items-center gap-2 flex-wrap">
      <input type="hidden" name="p" value="1">
      <input type="search" name="q" data-buscar value="<?= e($q) ?>" placeholder="Número o sucursal..." aria-label="Buscar transferencia" autocomplete="off" class="input w-64">
      <select name="estado" aria-label="Estado" class="select cursor-pointer">
        <option value="">Todos los estados</option>
        <?php foreach (['borrador' => 'Borrador', 'pendiente' => 'Por aprobar', 'enviada' => 'Enviada', 'recibida' => 'Recibida', 'rechazada' => 'Rechazada', 'anulada' => 'Anulada'] as $k => $v): ?>
          <option value="<?= $k ?>" <?= $estadoT === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-primary cursor-pointer" aria-label="Aplicar filtros" title="Filtrar"><?= icon('filter', 'w-4 h-4') ?></button>
    </div>
    <span class="text-sm text-slate-400"><?= number_format($pg['total']) ?> transferencias</span>
  </form>

  <?php if (!$transferencias): ?>
    <?= empty_state('Sin transferencias', $q !== '' || $estadoT !== '' ? 'Ninguna transferencia coincide con los filtros.' : 'Crea una transferencia para mover stock entre sucursales.', 'transfer', $acciones) ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr><th>Número</th><th>Origen</th><th>Destino</th><th>Fecha</th><th>Estado</th><th class="text-right">Acciones</th></tr></thead>
        <tbody>
          <?php foreach ($transferencias as $t): ?>
            <tr>
              <td class="font-semibold text-slate-700"><?= e($t['numero']) ?></td>
              <td class="text-slate-600"><?= e($t['origen']) ?></td>
              <td class="text-slate-600"><?= e($t['destino']) ?></td>
              <td class="text-slate-500"><?= fechaCorta($t['fecha']) ?></td>
              <td><?= badgeFor($t['estado']) ?></td>
              <td>
                <div class="flex items-center justify-end gap-1">
                  <a href="?ver=<?= (int) $t['id'] ?>" class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Ver"><?= icon('eye', 'w-4 h-4') ?></a>

                  <?php // ---- Borrador: editar, enviar, eliminar (solo el origen) ----
                  $esOrigen = can_access_sucursal($t['sucursal_origen_id']);
                  $esDestino = can_access_sucursal($t['sucursal_destino_id']);
                  if ($t['estado'] === 'borrador' && $esOrigen): ?>
                    <?php if (can('transferencias.crear')): ?>
                      <button type="button" onclick="<?= jsEvent('trf:edit', ['id' => (int) $t['id'], 'origen' => (int) $t['sucursal_origen_id'], 'destino' => (int) $t['sucursal_destino_id'], 'fecha' => $t['fecha'], 'notas' => (string) $t['notas'], 'lineas' => $lineasPorTrf[$t['id']] ?? []]) ?>" class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Editar borrador"><?= icon('edit', 'w-4 h-4') ?></button>
                    <?php endif; ?>
                    <?php if (can('transferencias.enviar')): ?>
                      <form method="post" class="inline" onsubmit="return confirm('¿Enviar la transferencia? Se descontará el stock del origen.')"><?= csrf_field() ?><input type="hidden" name="accion" value="enviar"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="p-2 rounded-lg text-blue-500 hover:text-blue-600 hover:bg-blue-50" title="Enviar"><?= icon('transfer', 'w-4 h-4') ?></button></form>
                    <?php endif; ?>
                    <?php if (can('transferencias.crear')): ?>
                      <form method="post" class="inline" onsubmit="return confirm('¿Eliminar este borrador?')"><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50" title="Eliminar borrador"><?= icon('trash', 'w-4 h-4') ?></button></form>
                    <?php endif; ?>
                  <?php endif; ?>

                  <?php // ---- Pendiente: la mercancía TODAVÍA no ha salido ----
                  if ($t['estado'] === 'pendiente'): ?>
                    <?php if (can('transferencias.aprobar')): ?>
                      <form method="post" class="inline" onsubmit="return confirm('¿Aprobar la salida? El stock se descuenta del origen ahora.')"><?= csrf_field() ?><input type="hidden" name="accion" value="aprobar"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="p-2 rounded-lg text-emerald-500 hover:text-emerald-600 hover:bg-emerald-50" title="Aprobar la salida"><?= icon('check', 'w-4 h-4') ?></button></form>
                      <button type="button" onclick="<?= jsEvent('trf:devolver', ['id' => (int) $t['id'], 'numero' => $t['numero']]) ?>" class="p-2 rounded-lg text-slate-400 hover:text-amber-600 hover:bg-amber-50" title="Devolver a borrador"><?= icon('undo', 'w-4 h-4') ?></button>
                    <?php else: ?>
                      <span class="px-2 text-xs text-slate-400" title="Hace falta alguien con permiso de aprobación">esperando aprobación</span>
                    <?php endif; ?>
                  <?php endif; ?>

                  <?php // ---- Enviada: recibir/rechazar (destino), anular (origen) ----
                  if ($t['estado'] === 'enviada'): ?>
                    <?php if (can('transferencias.recibir') && $esDestino): ?>
                      <?php // Se abre el conteo: entra lo que llegó, no lo que dice el papel. ?>
                      <button type="button" onclick="<?= jsEvent('trf:recibir', ['id' => (int) $t['id'], 'numero' => $t['numero'], 'lineas' => $lineasRecepcion[(int) $t['id']] ?? []]) ?>" class="p-2 rounded-lg text-emerald-500 hover:text-emerald-600 hover:bg-emerald-50" title="Recibir"><?= icon('check', 'w-4 h-4') ?></button>
                    <?php endif; ?>
                    <?php if (can('transferencias.rechazar') && $esDestino): ?>
                      <button type="button" onclick="<?= jsEvent('trf:rechazar', ['id' => (int) $t['id'], 'numero' => $t['numero']]) ?>" class="p-2 rounded-lg text-slate-400 hover:text-amber-600 hover:bg-amber-50" title="Rechazar"><?= icon('undo', 'w-4 h-4') ?></button>
                    <?php endif; ?>
                    <?php if (can('transferencias.anular') && $esOrigen): ?>
                      <form method="post" class="inline" onsubmit="return confirm('¿Anular la transferencia? El stock volverá al origen.')"><?= csrf_field() ?><input type="hidden" name="accion" value="anular"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50" title="Anular"><?= icon('x', 'w-4 h-4') ?></button></form>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= paginacion($pg) ?>
  <?php endif; ?>
</div>

<!-- Modal crear / editar transferencia -->
<div x-data="trfForm()" @trf:new.window="reset(); open=true" @trf:edit.window="openEdit($event.detail)" @keydown.escape.window="open=false" x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
  <div class="modal-panel bg-white rounded-2xl shadow-pop max-w-2xl" @click.stop>
    <form method="post" @submit="document.getElementById('trfLineas').value=JSON.stringify(lineas)">
      <?= csrf_field() ?><input type="hidden" name="accion" :value="id ? 'editar' : 'guardar'"><input type="hidden" name="id" :value="id"><input type="hidden" name="lineas" id="trfLineas">
      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100"><h3 class="font-bold text-slate-800" x-text="id ? 'Editar borrador' : 'Nueva transferencia'"></h3><button type="button" @click="open=false" aria-label="Cerrar modal" title="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button></div>
      <div class="p-6 space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div><label class="label">Origen *</label><select name="sucursal_origen_id" x-model.number="origen" required class="select"><?php foreach ($sucursales as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['nombre']) ?></option><?php endforeach; ?></select></div>
          <div><label class="label">Destino *</label><select name="sucursal_destino_id" x-model.number="destino" required class="select"><?php foreach ($sucursales as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['nombre']) ?></option><?php endforeach; ?></select></div>
          <div><label class="label">Fecha</label><input type="date" name="fecha" x-model="fecha" class="input"></div>
        </div>
        <p x-show="origen===destino" class="text-sm text-rose-600">El origen y el destino deben ser distintos.</p>
        <div>
          <label class="label">Motivo de la salida *</label>
          <input name="notas" x-model="notas" required maxlength="255" class="input"
                 placeholder="Ej. Reposición de vitrina, pedido de cliente, traslado por cierre de tienda...">
          <p class="mt-1 text-xs text-slate-500">Queda en el kardex junto al movimiento, para que meses después se sepa por qué salió esa mercancía.</p>
        </div>
        <div class="flex items-end gap-2">
          <div class="flex-1"><label class="label">Agregar producto</label><select x-model.number="nuevoProd" class="select"><option value="0">Selecciona...</option><?php foreach ($productosJs as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['nombre']) ?></option><?php endforeach; ?></select></div>
          <button type="button" @click="addLinea()" class="btn btn-soft"><?= icon('plus', 'w-4 h-4') ?> Agregar</button>
        </div>
        <div class="border border-slate-200 rounded-xl overflow-hidden">
          <table class="w-full text-sm"><thead class="bg-slate-50"><tr><th class="text-left px-3 py-2 text-xs font-semibold text-slate-400 uppercase">Producto</th><th class="px-2 py-2 text-xs font-semibold text-slate-400 uppercase w-28">Cantidad</th><th class="w-10"></th></tr></thead>
            <tbody>
              <template x-for="(l,i) in lineas" :key="i"><tr class="border-t border-slate-100"><td class="px-3 py-2 font-medium text-slate-700" x-text="l.nombre"></td><td class="px-2 py-2"><input type="number" step="0.001" min="0.001" x-model.number="l.cantidad" aria-label="Cantidad a transferir" class="input py-1.5 px-2 text-sm"></td><td class="px-2 py-2"><button type="button" @click="lineas.splice(i,1)" aria-label="Quitar producto" title="Quitar" class="text-rose-400 hover:text-rose-600 p-2"><?= icon('trash', 'w-4 h-4') ?></button></td></tr></template>
              <tr x-show="lineas.length===0"><td colspan="3" class="text-center text-slate-400 py-6 text-sm">Agrega productos a transferir.</td></tr>
            </tbody>
          </table>
        </div>
        <p class="text-xs text-slate-500">Ni el <strong>borrador</strong> ni la solicitud descuentan stock. La mercancía sale de la tienda cuando alguien con permiso <strong>aprueba</strong> la salida, y no antes.</p>
      </div>
      <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
        <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
        <!-- El modo viaja como name/value del botón submit (evita el desfase de :value en Alpine). -->
        <template x-if="id">
          <button type="submit" name="modo" value="borrador" :disabled="lineas.length===0 || origen===destino" class="btn btn-primary disabled:opacity-50"><?= icon('save', 'w-4 h-4') ?> Guardar cambios</button>
        </template>
        <template x-if="!id">
          <span class="flex gap-2">
            <button type="submit" name="modo" value="borrador" :disabled="lineas.length===0 || origen===destino" class="btn btn-ghost disabled:opacity-50"><?= icon('save', 'w-4 h-4') ?> Guardar borrador</button>
            <?php if (can('transferencias.enviar')): ?>
              <button type="submit" name="modo" value="enviar" :disabled="lineas.length===0 || origen===destino" class="btn btn-primary disabled:opacity-50"><?= icon('transfer', 'w-4 h-4') ?> Mandar a aprobación</button>
            <?php endif; ?>
          </span>
        </template>
      </div>
    </form>
  </div>
</div>

<!-- Modal devolver a borrador (no se aprueba la salida) -->
<div x-data="{ open:false, id:0, numero:'' }" @trf:devolver.window="id=$event.detail.id; numero=$event.detail.numero; open=true" @keydown.escape.window="open=false" x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
  <div class="modal-panel bg-white rounded-2xl shadow-pop max-w-md" @click.stop>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="accion" value="devolver"><input type="hidden" name="id" :value="id">
      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100"><h3 class="font-bold text-slate-800">No aprobar <span x-text="numero"></span></h3><button type="button" @click="open=false" aria-label="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button></div>
      <div class="p-6 space-y-3">
        <p class="text-sm text-slate-500">Vuelve a borrador y quien la solicitó verá el motivo. La mercancía no se ha movido.</p>
        <div><label class="label">Por qué no se aprueba *</label><input name="motivo_rechazo" required maxlength="255" class="input" placeholder="Ej. Falta la autorización de la gerencia, cantidad equivocada..."></div>
      </div>
      <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
        <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
        <button class="btn btn-primary"><?= icon('undo', 'w-4 h-4') ?> Devolver a borrador</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal rechazar -->
<div x-data="{ open:false, id:0, numero:'' }" @trf:rechazar.window="id=$event.detail.id; numero=$event.detail.numero; open=true" @keydown.escape.window="open=false" x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
  <div class="modal-panel bg-white rounded-2xl shadow-pop max-w-md" @click.stop>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="accion" value="rechazar"><input type="hidden" name="id" :value="id">
      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100"><h3 class="font-bold text-slate-800">Rechazar transferencia <span x-text="numero"></span></h3><button type="button" @click="open=false" aria-label="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button></div>
      <div class="p-6 space-y-3">
        <p class="text-sm text-slate-600">El stock volverá al origen. Indica por qué rechazas la transferencia.</p>
        <div><label class="label">Motivo *</label><input type="text" name="motivo_rechazo" required maxlength="255" class="input" placeholder="Ej. Llegó incompleta / producto dañado" x-ref="motivo" x-effect="open && $nextTick(() => $refs.motivo.focus())"></div>
      </div>
      <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100"><button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button><button type="submit" class="btn btn-danger"><?= icon('undo', 'w-4 h-4') ?> Rechazar</button></div>
    </form>
  </div>
</div>

<?php // ---- Recibir: se cuenta bulto por bulto lo que llegó ---- ?>
<div x-data="trfRecibir()" @trf:recibir.window="abrir($event.detail)" @keydown.escape.window="open=false" x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
  <div class="modal-panel bg-white rounded-2xl shadow-pop max-w-2xl" @click.stop>
    <form method="post" @submit="if (!valida($event)) $event.preventDefault()">
      <?= csrf_field() ?><input type="hidden" name="accion" value="recibir"><input type="hidden" name="id" :value="id">
      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <h3 class="font-bold text-slate-800">Recibir <span x-text="numero"></span></h3>
        <button type="button" @click="open=false" aria-label="Cerrar" title="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
      </div>
      <div class="p-6 space-y-4">
        <p class="text-sm text-slate-600">Cuenta lo que llegó de verdad. Lo que falte no entra al inventario del destino:
          esas unidades ya salieron del origen y hay que decir qué pasó con ellas.</p>

        <div class="overflow-x-auto -mx-2 px-2">
          <table class="data-table">
            <thead><tr><th>Producto</th><th class="text-right w-24">Enviado</th><th class="text-right w-32">Llegó</th><th class="text-right w-24">Faltó</th></tr></thead>
            <tbody>
              <template x-for="(l, i) in lineas" :key="l.detalle_id">
                <tr>
                  <td><p class="font-semibold text-slate-700" x-text="l.nombre"></p><p class="text-xs text-slate-400" x-text="l.codigo"></p></td>
                  <td class="text-right text-slate-500" x-text="fmt(l.enviada)"></td>
                  <td class="text-right">
                    <input type="number" step="0.001" min="0" :max="l.enviada" :name="'recibida[' + l.detalle_id + ']'"
                           x-model.number="l.recibida" required
                           class="input text-right py-1.5 w-28 ml-auto"
                           :class="falta(l) > 0.0001 ? 'border-amber-300 bg-amber-50' : ''">
                  </td>
                  <td class="text-right font-semibold" :class="falta(l) > 0.0001 ? 'text-amber-600' : 'text-slate-300'"
                      x-text="falta(l) > 0.0001 ? fmt(falta(l)) : '—'"></td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>

        <div x-show="hayFaltante()" x-transition class="rounded-xl bg-amber-50 border border-amber-200 p-3 space-y-2">
          <p class="text-sm text-amber-800 font-semibold"><?= icon('alert', 'w-4 h-4 inline -mt-0.5') ?>
            Faltan <span x-text="fmt(totalFalta())"></span> unidades por el camino.</p>
          <p class="text-xs text-amber-700">Si no llegó absolutamente nada, cierra esto y usa <strong>Rechazar</strong>:
            así el stock vuelve al origen en vez de darse por perdido.</p>
          <div>
            <label class="label">¿Qué pasó? *</label>
            <input type="text" name="notas_recepcion" maxlength="500" class="input"
                   x-model="motivo" placeholder="Ej. La caja llegó abierta / el suplidor cargó de menos / se rompió en el camino">
          </div>
        </div>
      </div>
      <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
        <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
        <button type="submit" class="btn btn-primary"><?= icon('check', 'w-4 h-4') ?> Recibir</button>
      </div>
    </form>
  </div>
</div>

<script>
function trfRecibir() {
  return {
    open: false, id: 0, numero: '', lineas: [], motivo: '',
    abrir(d) {
      this.id = d.id; this.numero = d.numero; this.motivo = '';
      // Se abre con TODO recibido: lo normal es que llegue completo, y así
      // quien recibe solo toca los renglones donde de verdad faltó algo.
      this.lineas = (d.lineas || []).map(l => ({ ...l, recibida: l.enviada }));
      this.open = true;
    },
    fmt(n) { return (Math.round(n * 1000) / 1000).toLocaleString('es-DO', { maximumFractionDigits: 3 }); },
    falta(l) { const r = Number(l.recibida); return isNaN(r) ? 0 : l.enviada - r; },
    hayFaltante() { return this.lineas.some(l => this.falta(l) > 0.0001); },
    totalFalta() { return this.lineas.reduce((s, l) => s + Math.max(0, this.falta(l)), 0); },
    totalRecibido() { return this.lineas.reduce((s, l) => s + Math.max(0, Number(l.recibida) || 0), 0); },
    valida(e) {
      if (this.totalRecibido() <= 0.0001) {
        alert('No llegó nada de esta transferencia. Usa «Rechazar» en vez de «Recibir»: así el stock vuelve al origen en lugar de darse por perdido.');
        return false;
      }
      if (this.hayFaltante() && !this.motivo.trim()) {
        alert('Faltó mercancía por el camino. Escribe qué pasó antes de recibir.');
        return false;
      }
      return true;
    },
  };
}

function trfForm() {
  const DEF_ORIGEN = <?= (int) ($sucursales[0]['id'] ?? 0) ?>, DEF_DESTINO = <?= (int) ($sucursales[1]['id'] ?? 0) ?>;
  return {
    open: false, id: 0, nuevoProd: 0, lineas: [], origen: DEF_ORIGEN, destino: DEF_DESTINO, fecha: '<?= date('Y-m-d') ?>', notas: '',
    productos: <?= json_encode($productosJs, JSON_UNESCAPED_UNICODE) ?>,
    reset() { this.id = 0; this.lineas = []; this.nuevoProd = 0; this.origen = DEF_ORIGEN; this.destino = DEF_DESTINO; this.fecha = '<?= date('Y-m-d') ?>'; this.notas = ''; },
    openEdit(d) {
      this.id = d.id; this.origen = d.origen; this.destino = d.destino; this.fecha = d.fecha; this.notas = d.notas || '';
      this.lineas = (d.lineas || []).map(l => ({ producto_id: l.producto_id, nombre: l.nombre, cantidad: l.cantidad }));
      this.nuevoProd = 0; this.open = true;
    },
    addLinea() { const p = this.productos.find(x => x.id === this.nuevoProd); if (!p || this.lineas.find(l => l.producto_id === p.id)) return; this.lineas.push({ producto_id: p.id, nombre: p.nombre, cantidad: 1 }); this.nuevoProd = 0; },
  };
}
</script>

<?php if ($sugerencia): ?>
  <?php /* Se reutiliza el mismo evento que edita un borrador: con id 0 el
           formulario guarda uno nuevo. Así no hay dos caminos que mantener. */ ?>
  <script>
    document.addEventListener('alpine:initialized', function () {
      window.dispatchEvent(new CustomEvent('trf:edit', { detail: <?= json_encode($sugerencia, JSON_UNESCAPED_UNICODE) ?> }));
    });
  </script>
<?php endif; ?>

<?php layout_end(); ?>
