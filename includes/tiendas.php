<?php
/**
 * Tiendas — las marcas comerciales con las que se factura.
 *
 * Importers TyE distribuye varias marcas y cada una se presenta al cliente con
 * su propia cara. Una tienda NO es un local ni una razón social: es la
 * identidad que se imprime (logo, colores, dirección del punto de venta,
 * mensaje del ticket, política de devolución).
 *
 * Tres reglas que sostienen todo el módulo:
 *
 *  1. **Tienda y sucursal son independientes.** Sucursal = dónde se vende
 *     (stock, caja, usuarios). Tienda = con qué marca se vende. Un local puede
 *     atender dos marcas y una marca puede estar en varios locales.
 *
 *  2. **El emisor fiscal sigue siendo la empresa.** Un solo RNC y una sola
 *     secuencia de NCF. La tienda pone la marca en el papel, no en la
 *     declaración. Por eso `tienda_marca()` cae SIEMPRE al RNC de la empresa.
 *
 *  3. **Sin tiendas creadas, el sistema se comporta como antes.** `tiendas_hay()`
 *     es el interruptor: mientras no exista ninguna, el POS no pide elegir
 *     marca, el catálogo no se filtra y los tickets salen con los datos de la
 *     empresa. Así se puede desplegar sin migrar el catálogo de golpe.
 */

const TIENDA_SESION = 'tienda_activa';

/* ============================================================
 *  Disponibilidad y catálogo
 * ============================================================ */

/**
 * ¿Está aplicada la migración P16?
 *
 * Se comprueba ANTES de cualquier consulta: el código puede desplegarse antes
 * que la migración y una tabla inexistente tiene que mostrar un aviso, no un
 * error de SQL en la cara del cajero.
 */
function tiendas_disponible(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = (bool) qVal(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tiendas'"
        );
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

/** ¿Hay al menos una tienda activa? Es el interruptor de todo el módulo. */
function tiendas_hay(): bool
{
    return count(tiendas_activas()) > 0;
}

/** Tiendas activas, en el orden en que deben aparecer en los selectores. */
function tiendas_activas(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    if (!tiendas_disponible()) return $cache = [];
    $cache = qAll("SELECT * FROM tiendas WHERE activo = 1 ORDER BY orden, nombre");
    return $cache;
}

/** Todas las tiendas, incluidas las inactivas (para la pantalla de administración). */
function tiendas_todas(): array
{
    if (!tiendas_disponible()) return [];
    return qAll("SELECT * FROM tiendas ORDER BY orden, nombre");
}

/**
 * Una tienda por id. Devuelve null si no existe o si no hay módulo.
 *
 * Cachea también las inactivas: una venta vieja puede apuntar a una marca que
 * ya no se usa y su ticket tiene que seguir imprimiéndose igual.
 */
function tienda(?int $id): ?array
{
    static $cache = [];
    if (!$id || !tiendas_disponible()) return null;
    if (array_key_exists($id, $cache)) return $cache[$id];
    return $cache[$id] = qOne("SELECT * FROM tiendas WHERE id = ?", [$id]);
}

/** [id => nombre] de las tiendas activas. Para <select> y mapeos rápidos. */
function tiendas_opciones(): array
{
    $out = [];
    foreach (tiendas_activas() as $t) $out[(int) $t['id']] = $t['nombre'];
    return $out;
}

/* ============================================================
 *  Tienda activa (la que está usando el cajero)
 * ============================================================ */

/**
 * Tienda activa de la sesión.
 *
 * Si no hay ninguna elegida, se toma la primera activa: el cajero abre el POS y
 * vende, no se le pide una decisión antes de poder trabajar. Si la guardada se
 * desactivó, se descarta y se vuelve a la primera.
 */
function tienda_actual(): ?array
{
    $tiendas = tiendas_activas();
    if (!$tiendas) return null;

    $id = (int) ($_SESSION[TIENDA_SESION] ?? 0);
    if ($id > 0) {
        foreach ($tiendas as $t) {
            if ((int) $t['id'] === $id) return $t;
        }
    }
    return $tiendas[0];
}

/** Id de la tienda activa, o null cuando no hay tiendas creadas. */
function tienda_actual_id(): ?int
{
    $t = tienda_actual();
    return $t ? (int) $t['id'] : null;
}

/**
 * Cambia la tienda activa. Solo acepta tiendas activas existentes: un id
 * inventado en la URL no puede dejar la sesión apuntando a la nada.
 */
function tienda_set(int $id): bool
{
    foreach (tiendas_activas() as $t) {
        if ((int) $t['id'] === $id) {
            $_SESSION[TIENDA_SESION] = $id;
            return true;
        }
    }
    return false;
}

/* ============================================================
 *  Marca: lo que se imprime
 * ============================================================ */

/**
 * Resuelve la identidad a imprimir en un comprobante.
 *
 * Devuelve SIEMPRE un arreglo completo: cada campo que la tienda no define cae
 * al de la empresa. Así el ticket nunca sale con un hueco y el código que
 * imprime no tiene que preguntar «¿hay tienda?» en cada línea.
 *
 * `rnc` es la excepción deliberada: el emisor fiscal es la empresa, así que el
 * RNC de la empresa manda aunque la tienda tenga uno propio anotado. El de la
 * tienda se guarda solo como referencia administrativa.
 */
function tienda_marca(?int $tiendaId = null): array
{
    $e = $GLOBALS['empresa'] ?? [];
    $t = tienda($tiendaId);

    $tomar = static function (string $campo, $porDefecto = null) use ($t) {
        if (!$t) return $porDefecto;
        $v = $t[$campo] ?? null;
        return ($v === null || $v === '') ? $porDefecto : $v;
    };

    return [
        'id'           => $t ? (int) $t['id'] : null,
        'nombre'       => $tomar('nombre', $e['nombre'] ?? APP_NAME),
        'razon_social' => $tomar('razon_social', $e['nombre'] ?? APP_NAME),
        // El emisor fiscal es la empresa. Siempre.
        'rnc'          => $e['rnc'] ?? null,
        'rnc_tienda'   => $t['rnc'] ?? null,
        'direccion'    => $tomar('direccion', $e['direccion'] ?? null),
        'ciudad'       => $tomar('ciudad'),
        'telefono'     => $tomar('telefono', $e['telefono'] ?? null),
        'whatsapp'     => $tomar('whatsapp'),
        'email'        => $tomar('email', $e['email'] ?? null),
        'sitio_web'    => $tomar('sitio_web'),
        'logo'         => $tomar('logo', $e['logo'] ?? null),
        'color'        => $tomar('color', '#2563eb'),
        'encabezado'   => $tomar('encabezado'),
        'mensaje'      => $tomar('mensaje_ticket', $e['mensaje_ticket'] ?? '¡Gracias por su compra!'),
        'politica'     => $tomar('politica_devolucion'),
        'pie'          => $tomar('pie_factura'),
    ];
}

/**
 * La marca de una venta ya registrada.
 *
 * Se lee de `ventas.tienda_id`, que quedó congelado al facturar. Deducirla del
 * producto reimprimiría con otro logo una factura que el cliente ya tiene en la
 * mano si mañana el artículo cambia de marca.
 */
function tienda_marca_de_venta(array $venta): array
{
    return tienda_marca(isset($venta['tienda_id']) ? (int) $venta['tienda_id'] : null);
}

/** Ruta absoluta en disco del logo de una marca, o null si no hay o no existe. */
function tienda_logo_path(array $marca): ?string
{
    $logo = $marca['logo'] ?? null;
    if (!$logo) return null;
    $path = dirname(__DIR__) . '/' . ltrim((string) $logo, '/');
    return is_file($path) ? $path : null;
}

/** URL pública del logo de una marca (para el HTML), o null. */
function tienda_logo_url(array $marca): ?string
{
    return tienda_logo_path($marca) ? url((string) $marca['logo']) : null;
}

/**
 * El logo como data-URI base64, para incrustarlo en un PDF.
 *
 * Dompdf sale a la red para resolver un `src` remoto: con el servidor detrás de
 * un proxy o sin salida, la factura se genera sin logo y nadie se entera hasta
 * que el cliente la recibe. Incrustado, siempre está.
 */
function tienda_logo_datauri(array $marca): ?string
{
    $path = tienda_logo_path($marca);
    if (!$path) return null;
    $bin = @file_get_contents($path);
    if ($bin === false) return null;
    $mime = function_exists('mime_content_type') ? (mime_content_type($path) ?: 'image/png') : 'image/png';
    return 'data:' . $mime . ';base64,' . base64_encode($bin);
}

/**
 * Iniciales de la marca, para el cuadrito de color cuando no hay logo.
 * Dos palabras → dos letras («Casa Blanca» → CB); una → una.
 */
function tienda_iniciales(string $nombre): string
{
    $partes = preg_split('/[\s\-·]+/u', trim($nombre)) ?: [];
    $partes = array_values(array_filter($partes, fn($p) => $p !== ''));
    if (!$partes) return '?';
    if (count($partes) === 1) return mb_strtoupper(mb_substr($partes[0], 0, 1));
    return mb_strtoupper(mb_substr($partes[0], 0, 1) . mb_substr($partes[1], 0, 1));
}

/* ============================================================
 *  Filtros por tienda (listados y reportes)
 * ============================================================ */

/** La tienda elegida en el filtro `?tienda_id=` de la URL, o null. */
function tiendaFiltroActual(): ?int
{
    $id = (int) get('tienda_id');
    if ($id <= 0 || !tiendas_disponible()) return null;
    foreach (tiendas_activas() as $t) {
        if ((int) $t['id'] === $id) return $id;
    }
    return null;
}

/**
 * Fragmento WHERE del filtro por tienda. Devuelve ['1=1', []] cuando no aplica.
 *
 * A diferencia de la sucursal, la tienda NO es un límite de seguridad: es una
 * lente para mirar los mismos datos por marca. Por eso no hay «tienda activa
 * del usuario» que restrinja lo que puede ver.
 */
function tiendaScope(string $col = 'tienda_id'): array
{
    $id = tiendaFiltroActual();
    return $id === null ? ['1=1', []] : ["$col = ?", [$id]];
}

/**
 * <select> de tiendas para los filtros. Cadena vacía cuando no hay ninguna:
 * un filtro sin opciones es ruido en la barra.
 */
function selectTiendaFiltro(string $etiqueta = 'Todas las tiendas'): string
{
    $tiendas = tiendas_activas();
    if (!$tiendas) return '';
    $actual = tiendaFiltroActual();
    $h  = '<select name="tienda_id" aria-label="Filtrar por tienda" class="select cursor-pointer">';
    $h .= '<option value="">' . e($etiqueta) . '</option>';
    foreach ($tiendas as $t) {
        $sel = $actual === (int) $t['id'] ? ' selected' : '';
        $h .= '<option value="' . (int) $t['id'] . '"' . $sel . '>' . e($t['nombre']) . '</option>';
    }
    return $h . '</select>';
}

/**
 * Chip con el logo (o las iniciales) y el nombre de una marca.
 * Se usa en listados para reconocer la marca de un vistazo.
 */
function tienda_chip(?int $tiendaId, string $clase = ''): string
{
    $t = tienda($tiendaId);
    if (!$t) return '<span class="text-slate-300">—</span>';
    $marca = tienda_marca($tiendaId);
    $logo  = tienda_logo_url($marca);
    $img = $logo
        ? '<img src="' . e($logo) . '" alt="" class="w-6 h-6 rounded object-contain bg-white border border-slate-200">'
        : '<span class="w-6 h-6 rounded text-[10px] font-bold text-white flex items-center justify-center shrink-0"'
          . ' style="background:' . e($marca['color']) . '">' . e(tienda_iniciales($marca['nombre'])) . '</span>';
    return '<span class="inline-flex items-center gap-2 ' . e($clase) . '">' . $img
        . '<span class="font-medium text-slate-700">' . e($t['nombre']) . '</span></span>';
}
