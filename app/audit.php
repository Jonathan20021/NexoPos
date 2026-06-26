<?php
/**
 * Registro de auditoría. Guarda cada acción relevante en la tabla `auditoria`.
 */
function audit(string $modulo, string $accion, string $descripcion = '', array $opts = []): void
{
    try {
        $u = current_user();
        dbInsert('auditoria', [
            'usuario_id'      => $u['id'] ?? null,
            'usuario_nombre'  => $u ? ($u['nombre'] . ' ' . $u['apellido']) : 'Sistema',
            'sucursal_id'     => $opts['sucursal_id'] ?? ($u['sucursal_id'] ?? null),
            'modulo'          => $modulo,
            'accion'          => $accion,
            'descripcion'     => $descripcion,
            'tabla_afectada'  => $opts['tabla'] ?? null,
            'registro_id'     => $opts['registro_id'] ?? null,
            'datos_anteriores'=> isset($opts['antes']) ? json_encode($opts['antes'], JSON_UNESCAPED_UNICODE) : null,
            'datos_nuevos'    => isset($opts['despues']) ? json_encode($opts['despues'], JSON_UNESCAPED_UNICODE) : null,
            'ip'              => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent'      => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            'created_at'      => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        // La auditoría nunca debe romper la operación principal.
        error_log('Audit error: ' . $e->getMessage());
    }
}
