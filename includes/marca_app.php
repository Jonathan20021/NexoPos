<?php
/**
 * Identidad visual de la aplicación: el azul de Importers.
 *
 * UN SOLO SITIO. La app pinta 568 clases `blue-*` de Tailwind repartidas por
 * todas las pantallas; recolorearlas a mano sería inviable y quedaría desparejo
 * al primer descuido. En vez de eso se sobrescribe la paleta `blue` de Tailwind
 * con esta escala, y todas las pantallas cambian a la vez sin tocar ni una
 * clase. Lo mismo para el PDF, los gráficos y el color de tema del móvil, que
 * no pasan por Tailwind y leen `marca_app()`.
 *
 * Para vestir la app con otra marca basta cambiar la escala de aquí abajo.
 *
 * De dónde salen los valores: el 600 es el azul exacto del logotipo
 * (`assets/logo-importers.png`, muestreado del archivo original: #3B4A83). El
 * resto es una escala generada alrededor de él manteniendo el tono (H=227,5°) y
 * moviendo luminosidad y saturación con el mismo perfil que usa Tailwind.
 *
 * Contrastes verificados contra WCAG AA — todos los pares que la app usa de
 * verdad pasan, y con margen sobre el azul anterior:
 *
 *   blanco sobre 600 (btn-primary)   8,42:1
 *   blanco sobre 700 (hover)        10,41:1
 *   700 sobre 50 (btn-soft, badges)  9,56:1
 *   600 sobre blanco (enlaces)       8,42:1
 *   500 sobre blanco (foco, bordes)  6,58:1
 */

/** La escala completa. Incluye el 950 porque la app lo usa. */
const MARCA_APP_ESCALA = [
    50  => '#F4F5FB',
    100 => '#E7EAF6',
    200 => '#D0D6EC',
    300 => '#B0BADD',
    400 => '#6476B9',
    500 => '#47599E',
    600 => '#3B4A83',   // el azul del logotipo
    700 => '#2F3D6F',
    800 => '#26315C',
    900 => '#1D274C',
    950 => '#131B37',
];

/** El gris del logotipo, para acentos secundarios. */
const MARCA_APP_GRIS = '#828387';

/** Un tono de la marca. Sin argumentos, el color principal. */
function marca_app(int $tono = 600): string
{
    return MARCA_APP_ESCALA[$tono] ?? MARCA_APP_ESCALA[600];
}

/**
 * La escala como objeto JS, para inyectarla en `tailwind.config`.
 *
 * Se emite ya codificada: las claves son enteros y los valores hexadecimales de
 * una constante, así que no hay nada que escapar más allá de lo que hace
 * json_encode.
 */
function marca_app_tailwind(): string
{
    return json_encode(MARCA_APP_ESCALA, JSON_UNESCAPED_SLASHES);
}

/**
 * Logotipo de la aplicación cuando la empresa no tiene uno cargado.
 *
 * Devuelve la ruta relativa, o null si el archivo no está. Se usa como último
 * respaldo en la cadena marca de tienda → logo de la empresa → este.
 */
function marca_app_logo(): ?string
{
    $rel = 'assets/logo-importers.png';
    return is_file(dirname(__DIR__) . '/' . $rel) ? $rel : null;
}
