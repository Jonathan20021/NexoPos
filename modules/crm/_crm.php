<?php
/**
 * Helpers compartidos del módulo CRM.
 * Se incluye después de app/bootstrap.php en cada página del CRM.
 * No toca infraestructura compartida: todo lo específico del CRM vive aquí.
 */

/** Etapas del embudo: clave => [etiqueta, color badge, probabilidad sugerida %]. */
function crm_etapas(): array
{
    return [
        'prospecto'   => ['Prospecto',   'slate',   10],
        'contactado'  => ['Contactado',  'sky',     25],
        'propuesta'   => ['Propuesta',   'indigo',  50],
        'negociacion' => ['Negociación', 'amber',   75],
        'ganada'      => ['Ganada',      'emerald', 100],
        'perdida'     => ['Perdida',     'rose',    0],
    ];
}

/** Solo las etapas activas del embudo (las columnas del tablero Kanban). */
function crm_etapas_abiertas(): array
{
    return ['prospecto', 'contactado', 'propuesta', 'negociacion'];
}

/** Badge de una etapa. */
function crm_etapa_badge(string $etapa): string
{
    [$label, $color] = crm_etapas()[$etapa] ?? [ucfirst($etapa), 'slate'];
    return badge($label, $color);
}

/** Tipos de interacción: clave => [etiqueta, icono]. */
function crm_tipos(): array
{
    return [
        'llamada'  => ['Llamada',  'phone'],
        'whatsapp' => ['WhatsApp', 'phone'],
        'email'    => ['Email',    'mail'],
        'visita'   => ['Visita',   'map'],
        'reunion'  => ['Reunión',  'calendar'],
        'nota'     => ['Nota',     'edit'],
    ];
}

/** Prioridades de tarea: clave => [etiqueta, color]. */
function crm_prioridades(): array
{
    return ['alta' => ['Alta', 'rose'], 'media' => ['Media', 'sky'], 'baja' => ['Baja', 'slate']];
}

/**
 * Sucursal fija del usuario (int) o null si opera en "todas".
 * Cuando es null, los formularios muestran un selector de sucursal.
 */
function crm_sucursal_fija(): ?int
{
    return current_sucursal_id();
}

/** Sucursales activas a las que el usuario tiene acceso (para los selectores). */
function crm_sucursales_visibles(): array
{
    $fija = crm_sucursal_fija();
    if ($fija !== null) {
        return qAll("SELECT id, nombre FROM sucursales WHERE id = ? AND activo = 1", [$fija]);
    }
    return qAll("SELECT id, nombre FROM sucursales WHERE activo = 1 ORDER BY nombre");
}

/**
 * Resuelve y valida la sucursal a guardar. Fuerza la del usuario si está fijado
 * a una; si opera en "todas", toma la del formulario. Lanza si no es válida.
 */
function crm_resolver_sucursal(): int
{
    $fija = crm_sucursal_fija();
    $sid  = $fija ?? postInt('sucursal_id');
    if ($sid <= 0) {
        throw new RuntimeException('Selecciona la sucursal.');
    }
    if (!can_access_sucursal($sid)) {
        throw new RuntimeException('No tienes acceso a esa sucursal.');
    }
    if (!qVal("SELECT 1 FROM sucursales WHERE id = ? AND activo = 1", [$sid])) {
        throw new RuntimeException('La sucursal seleccionada no es válida.');
    }
    return $sid;
}

/** Lista de clientes activos para selectores (excluye el Cliente Genérico id 1). */
function crm_clientes_lista(): array
{
    return qAll("SELECT id, nombre, codigo FROM clientes WHERE activo = 1 AND id <> 1 ORDER BY nombre");
}

/** Usuarios activos para asignar responsables/tareas. */
function crm_usuarios_lista(): array
{
    return qAll("SELECT id, CONCAT(nombre,' ',apellido) AS nombre FROM usuarios WHERE activo = 1 ORDER BY nombre, apellido");
}

/** ¿La tarea está vencida? (pendiente y con fecha de vencimiento pasada) */
function crm_tarea_vencida(array $t): bool
{
    return $t['estado'] === 'pendiente' && !empty($t['vence_at']) && strtotime($t['vence_at']) < time();
}
