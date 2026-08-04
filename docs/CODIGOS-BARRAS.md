# Códigos de barras y escaneo — NexoPOS

Cubre tres cosas que en la práctica son una sola: **identificar** cada producto con un
código, **imprimirlo** en una etiqueta y **leerlo** con un teléfono o una pistola.

Piezas:

| Archivo | Qué hace |
|---|---|
| `includes/barcode.php` | Valida, genera y dibuja los códigos (SVG, PHP puro) |
| `assets/js/escaner.js` | Escáner por cámara + lector de pistola USB/Bluetooth |
| `assets/js/vendor/zxing-browser.min.js` | Decodificador de respaldo (iPhone y Firefox) |
| `modules/inventario/escaner.php` | Terminal de almacén: el teléfono como lector |
| `modules/inventario/etiquetas.php` | Asignar códigos internos e imprimir etiquetas |
| `modules/inventario/api_escaner.php` | API JSON: buscar, mover stock, contar, generar código |
| `database/migracion_codigos_barras_p12.sql` | Índice único + permiso `productos.etiquetas` |

---

## 1. Un código, un producto (y por qué es único)

`productos.codigo_barras` tiene **índice UNIQUE**. No es un detalle de higiene: si dos
productos comparten código, al escanear en la caja el sistema no puede saber cuál te
dieron, y cobraría el equivocado. Los `NULL` no chocan entre sí en un índice único de
MySQL, así que la mercancía sin código (servicios, granel) convive sin problema — pero
la cadena vacía `''` **sí** choca consigo misma, por eso la migración la convierte a
`NULL` antes de crear el índice y `productos.php` guarda `NULL`, nunca `''`.

## 2. Qué se acepta y qué se rechaza

`barcode_validar()` es la única puerta. La regla, y su motivo:

- **13 dígitos con verificador malo → se RECHAZA.** EAN-13 es el estándar del comercio y
  ningún lector devuelve uno inválido: el propio lector comprueba el dígito antes de
  emitirlo. Un EAN-13 que no cuadra está tecleado a mano y está mal. El mensaje dice cuál
  sería el código correcto.
- **8, 12 o 14 dígitos con verificador malo → se ACEPTA**, con aviso. Muchísimas empresas
  arrastran códigos internos numéricos de ese largo desde su Excel de siempre, que no
  pretenden ser EAN-8 ni UPC-A. Se imprimen en Code 128 y se leen igual de bien.
  Rechazarlos obligaría a renumerar el catálogo entero solo para poder guardarlo.
- **Cualquier ASCII imprimible → Code 128.**
- **Vacío → válido.** El código es opcional.

## 3. Códigos internos (prefijo 200)

`barcode_generar_interno()` arma un EAN-13 con la estructura `200` + 9 dígitos de
correlativo + verificador.

GS1 reserva el rango **200–299** para «circulación restringida dentro de una empresa».
Son códigos legales para usar puertas adentro y, por diseño, **nunca chocarán con el de
un fabricante**. Inventar códigos en un rango ajeno es el error clásico y termina con dos
productos distintos leyendo igual el día que entra mercancía nueva.

El correlativo se reserva con el contador atómico (`UPDATE … LAST_INSERT_ID`), **nunca**
con `SELECT MAX()+1`: dos personas etiquetando a la vez en dos sucursales generarían el
mismo código y una moriría contra el UNIQUE. Ver `docs/CONCURRENCIA.md`.

## 4. Dibujo: SVG, no imagen

`barcode_svg($valor, ['alto'=>, 'modulo'=>, 'texto'=>])` devuelve un `<svg>`.

Se dibuja en vectorial a propósito. Una etiqueta de 38 mm impresa desde un PNG a 96 dpi
sale con los bordes difuminados y el lector falla una de cada tres veces. El SVG se
imprime nítido a cualquier tamaño y en cualquier impresora.

Detalles que no son decorativos:

- **La zona muda** (margen blanco lateral: 11 módulos a la izquierda y 7 a la derecha en
  EAN) va DENTRO del SVG. Sin ella el lector no encuentra dónde empieza el símbolo, y no
  se puede confiar en que la hoja de estilos deje aire alrededor.
- **`modulo`** es el ancho de la barra fina en px. Por debajo del equivalente a 0,25 mm
  hay lectores de gama baja que ya no leen; por eso `etiquetas.php` lo sube en los
  formatos grandes.
- En EAN el primer dígito se imprime **fuera** del símbolo, a la izquierda, y las barras
  de guarda bajan más que el resto. Es lo que le da al ojo y al escáner la referencia de
  dónde parte cada mitad.

`barcode_bits_code128()` alterna a subconjunto C en los tramos de dígitos (dos por
símbolo) para que el código quepa legible en una etiqueta pequeña.

## 5. Escáner: dos caminos y una pistola

`assets/js/escaner.js` expone `NexoEscaner`:

```js
NexoEscaner.abrir({ titulo, ayuda, continuo, onLeer(codigo, formato), onCerrar });
NexoEscaner.cerrar();
NexoEscaner.soportado();          // ¿hay cámara utilizable?
NexoEscaner.motivoNoSoportado();  // por qué no, en un mensaje accionable
NexoEscaner.teclado({ onCodigo }); // pistola USB/Bluetooth; devuelve la función para dejar de escuchar
```

**Camino 1 — `BarcodeDetector`.** El decodificador del propio sistema. Es el que usa
Chrome en Android: no descarga nada, lee al instante y gasta poca batería. Este es el
camino normal en el almacén.

**Camino 2 — ZXing autoalojado.** Safari (iPhone) y Firefox no implementan
`BarcodeDetector`. Los 395 KB del respaldo **solo se descargan si hacen falta**; en
Android nunca se piden. Va servido desde el propio dominio, no desde un CDN, así que
también funciona con la app instalada y sin internet.

**La pistola no es una cámara: es un teclado.** Escribe el código carácter a carácter a
una velocidad imposible para una persona y cierra con Enter. `NexoEscaner.teclado()` la
distingue justo por eso (más de 120 ms entre teclas = alguien escribiendo, y no se
interviene).

> **`data-escaner` en un campo significa «este campo atiende él mismo el disparo».**
> El oyente global se calla cuando el foco está dentro de cualquier campo de texto. Si
> además procesara ahí, un solo disparo se registraría **dos veces** — una por el campo y
> otra por el oyente.

### Dos requisitos del entorno que no son del sistema

1. **HTTPS obligatorio.** `getUserMedia` solo existe en contexto seguro (o en
   `localhost`). En `http://` el navegador **no pregunta**: niega en silencio. Por eso se
   detecta antes y se explica, en vez de dejar una pantalla negra sin motivo.
2. **`Permissions-Policy` debe permitir la cámara.** El `.htaccess` la declara como
   `camera=(self)`. Con `camera=()` — que es como estaba — el navegador la niega en seco
   aunque la página esté en HTTPS y el usuario acepte el permiso.

## 6. Dónde se escanea

| Pantalla | Qué hace cada lectura |
|---|---|
| **POS** (`modules/pos/index.php`) | Suma la unidad al carrito. Solo coincidencia **exacta**: en una caja, «parecido» no sirve. Si el código no está, avisa y no toca el carrito. |
| **Escáner de almacén** (`modules/inventario/escaner.php`) | Según el modo: consulta ficha, suma stock, resta stock, o suma a un conteo abierto. |
| **Conteo físico** (`modules/inventario/conteo.php`) | Con pistola, suma 1 al producto **visible en la página**. Fuera de la página avisa: la lista se pagina de 50 en 50 y sumárselo al que no era sería peor. |
| **Producto** (`modules/inventario/productos.php`) | Rellena el campo del código de barras. |

**El terminal de almacén guarda cada lectura en el servidor al momento**, no acumula una
lista para «guardar al final». En un almacén el teléfono se queda sin batería, se bloquea
o alguien cierra la pestaña; lo ya contado no se puede perder. El historial en pantalla es
solo para que el operario vea lo que lleva hecho.

Un lector puede devolver el mismo artículo de varias formas, así que tanto
`barcode_buscar_producto()` (servidor) como el POS (navegador) prueben en orden: código de
barras exacto → SKU exacto → UPC-A de 12 dígitos contra el EAN-13 guardado con el 0
delante, y al revés.

## 7. Etiquetas

`modules/inventario/etiquetas.php`. Cuatro formatos (tres de rollo térmico y hoja A4 3×8),
con medidas reales en milímetros (`@page` + unidades `mm`), así que lo que sale de la
impresora mide lo que dice el nombre y calza en el adhesivo.

La hoja se genera como **documento aparte** (`?vista=hoja`, POST con `cant[id]`), no
ocultando el resto de la aplicación con CSS. El contenido de una página va anidado dentro
del armazón (barra lateral, cabecera, `main`…) y «esconder todo menos esto» es frágil;
además arrastra el `@page { margin: 12mm }` del layout, que arruina una etiqueta de 38 mm.

El botón **«Asignar a los N de esta lista»** le pone código interno de golpe a la
mercancía que no lo trae. Relee cada producto con `FOR UPDATE` dentro de la transacción,
así que si otro usuario ya le asignó uno mientras la pantalla estaba abierta, no se le pisa.

## 8. Permisos

- `productos.etiquetas` — imprimir etiquetas (lo concede la migración a quien ya tenga
  `productos.editar`).
- `inventario.ver` — abrir el terminal de almacén (modo consulta).
- `inventario.ajustar` — modos entrada y salida.
- `conteos.contar` — modo conteo.

Un modo que el usuario no puede usar se degrada a **Consultar** en vez de dar un 403:
llegar ahí desde un enlace guardado no debería ser una pared.
