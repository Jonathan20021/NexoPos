<?php
/**
 * Temario de entrenamiento — OPERACIÓN DIARIA
 *
 * Fundamentos, punto de venta, inventario, compras, traslados y clientes/CRM.
 * La segunda mitad (RRHH, finanzas, reportes, marketing, sanidad y
 * administración) vive en `entrenamiento_temario_gestion.php`.
 *
 * ---------------------------------------------------------------------------
 * FORMATO DE UNA LECCIÓN — léelo antes de añadir contenido
 * ---------------------------------------------------------------------------
 *   'clave' => [
 *     'titulo'     => string
 *     'resumen'    => string          una línea: qué sabrá hacer al terminar
 *     'permiso'    => null | 'x.y' | ['x.y','z.w']   null = todo el mundo;
 *                                                    arreglo = basta con uno
 *     'minutos'    => int             duración estimada, honesta
 *     'pantalla'   => 'modules/…'     pantalla real que enseña (opcional)
 *     'objetivos'  => string[]
 *     'pasos'      => [ ['t'=>…, 'd'=>…, 'lista'=>[], 'campos'=>[], 'aviso'=>…, 'tip'=>…, 'ruta'=>…] ]
 *     'errores'    => [ ['sintoma'=>…, 'causa'=>…, 'solucion'=>…] ]
 *     'quiz'       => [ ['p'=>…, 'ops'=>[…], 'ok'=>int, 'exp'=>…] ]
 *   ]
 *
 * Reglas del contenido:
 *  - Cifras de negocio inventadas, NUNCA. Los ejemplos usan importes obviamente
 *    didácticos (RD$ 100.00) o hablan en términos relativos.
 *  - Cada lección apunta a la pantalla real con `pantalla`/`ruta`: entrenar sin
 *    poder abrir lo que se explica no entrena a nadie.
 *  - El `permiso` de la lección es el mismo que abre la pantalla que enseña. Si
 *    no coinciden, alguien estudiará algo que no puede usar.
 */

/** Rutas de operación diaria. @return array<string,array> */
function ent_temario_operacion(): array
{
    return [

        /* =====================================================================
         *  1. FUNDAMENTOS — lo que todo el mundo necesita, tenga el rol que tenga
         * ===================================================================== */
        'fundamentos' => [
            'titulo'      => 'Primeros pasos',
            'descripcion' => 'Cómo entrar, cómo se lee cualquier pantalla y por qué no ves lo mismo que tu compañero.',
            'icono'       => 'target',
            'color'       => 'blue',
            'nivel'       => 'Básico',
            'permiso'     => null,
            'para_quien'  => 'Todo el mundo, sin excepción. Es la primera ruta y la única obligatoria.',
            'lecciones'   => [

                'que-es' => [
                    'titulo'  => 'Qué es este sistema y cómo está organizado',
                    'resumen' => 'El mapa completo antes de tocar nada: qué hace cada módulo y cómo se conectan entre sí.',
                    'permiso' => null,
                    'minutos' => 6,
                    'objetivos' => [
                        'Nombrar los grandes bloques del sistema y para qué sirve cada uno.',
                        'Entender que todo lo que se registra alimenta a otra cosa.',
                        'Saber a qué módulo ir según lo que necesites hacer.',
                    ],
                    'pasos' => [
                        [
                            't' => 'No es un programa de facturar: es el sistema donde vive el negocio',
                            'd' => 'La caja registradora es solo la puerta de entrada. Cada venta que se cobra baja el inventario, calcula el costo de lo vendido, consume un comprobante fiscal, alimenta la comisión del vendedor, mueve el saldo del cliente si fue a crédito y aparece esa misma noche en el reporte de la dirección. Nada se escribe dos veces.',
                        ],
                        [
                            't' => 'Los bloques del sistema',
                            'd' => 'El menú de la izquierda está agrupado por bloques. Estos son, con una frase cada uno:',
                            'campos' => [
                                'Ventas'            => 'El punto de venta, la caja, las facturas, las devoluciones, los clientes y las cotizaciones. Es el día a día del piso.',
                                'Inventario'        => 'El catálogo de productos, las existencias por sucursal, las compras, los proveedores, los traslados entre tiendas y el conteo físico.',
                                'Recursos Humanos'  => 'Los empleados, la asistencia, la nómina quincenal con AFP/SFS/ISR, vacaciones, préstamos, amonestaciones y prestaciones laborales.',
                                'Finanzas'          => 'Ingresos y gastos, cuentas de banco y efectivo, conciliación bancaria, comisiones, metas, activos fijos y los formatos de la DGII.',
                                'CRM'               => 'El seguimiento comercial: oportunidades, llamadas, visitas y tareas pendientes con cada cliente.',
                                'Marketing'         => 'Campañas por correo y por WhatsApp, segmentos de clientes, plantillas y promociones.',
                                'Reportes'          => 'Todo lo anterior, leído. Más de treinta informes con el mismo criterio contable para que las cifras cuadren entre sí.',
                                'Dirección'         => 'El tablero de la dueña del negocio: año contra año, costos reales y carga de datos históricos.',
                                'Administración'    => 'Sucursales, marcas, usuarios, roles y permisos, configuración, auditoría y respaldo.',
                            ],
                        ],
                        [
                            't' => 'Todo deja rastro',
                            'd' => 'Cada vez que alguien crea, cambia o borra algo importante, el sistema anota quién fue, cuándo, desde qué equipo y qué cambió. Eso vive en Administración → Auditoría y no se puede editar. No es desconfianza: es lo que permite reconstruir qué pasó cuando una cifra no cuadra.',
                            'tip' => 'Trabaja siempre con TU usuario. Si entras con el de otra persona, el rastro apunta a ella y el error se le cobra a ella.',
                        ],
                        [
                            't' => 'Dos conceptos que se confunden todo el tiempo: sucursal y tienda',
                            'd' => 'No son lo mismo y mezclarlos causa la mitad de las dudas de los primeros días.',
                            'lista' => [
                                'SUCURSAL responde a «¿dónde se vende?». Es un local físico. Gobierna el stock, la caja, los usuarios y los permisos. Es un límite de seguridad real: si tu usuario pertenece a una sucursal, no ves las otras.',
                                'TIENDA responde a «¿con qué marca se vende?». Es una identidad comercial: su logo, sus colores, su dirección impresa en el ticket. NO es un límite de seguridad, solo cambia el papel.',
                                'Un mismo local puede atender dos marcas, y una marca puede estar en varios locales. Por eso son independientes.',
                            ],
                            'aviso' => 'El emisor fiscal siempre es la empresa, con un solo RNC y una sola secuencia de comprobantes. La tienda pone la marca en el papel, nunca en la declaración de impuestos.',
                        ],
                        [
                            't' => 'Cómo decidir a dónde ir',
                            'd' => 'Cuando no sepas dónde está algo, hazte la pregunta en voz alta y sigue esta tabla:',
                            'campos' => [
                                '«Necesito cobrarle a alguien»'                => 'Ventas → Punto de Venta',
                                '«¿Cuánto me queda de este producto?»'         => 'Inventario → Stock',
                                '«Llegó mercancía del proveedor»'              => 'Inventario → Compras',
                                '«Me falta producto y en la otra tienda sobra»'=> 'Inventario → Transferencias',
                                '«¿Quién me debe y desde cuándo?»'             => 'Ventas → Cuentas por Cobrar',
                                '«Hay que pagar la quincena»'                  => 'Recursos Humanos → Nómina',
                                '«¿Cómo va el mes?»'                           => 'Reportes → Panel ejecutivo',
                                '«No encuentro nada de esto»'                  => 'El buscador global: Ctrl + K desde cualquier pantalla',
                            ],
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Un cliente compra en el local del centro un artículo de la marca L\'Occitane. ¿Qué determina el logo que sale impreso en la factura?',
                            'ops' => ['La sucursal donde se vendió', 'La tienda (marca) del artículo', 'El usuario que cobró', 'La configuración de la empresa'],
                            'ok'  => 1,
                            'exp' => 'La sucursal dice DÓNDE se vendió; la tienda dice CON QUÉ MARCA. El logo del comprobante lo pone la tienda.',
                        ],
                        [
                            'p'   => '¿Qué pasa con el inventario cuando se cobra una venta en el punto de venta?',
                            'ops' => ['Nada, hay que descontarlo aparte', 'Baja solo, y además calcula el costo de lo vendido', 'Baja al cerrar la caja', 'Baja solo si el producto tiene código de barras'],
                            'ok'  => 1,
                            'exp' => 'La venta baja el stock en el mismo movimiento, congela el costo y alimenta los reportes. No hay un segundo paso manual.',
                        ],
                    ],
                ],

                'acceso' => [
                    'titulo'   => 'Entrar al sistema y la verificación en dos pasos',
                    'resumen'  => 'Tu usuario, tu contraseña, el código que llega por correo y qué hacer cuando la cuenta se bloquea.',
                    'permiso'  => null,
                    'minutos'  => 5,
                    'pantalla' => 'modules/auth/login.php',
                    'objetivos' => [
                        'Iniciar sesión con usuario o con correo.',
                        'Superar la verificación en dos pasos y saber cuándo marcar un equipo de confianza.',
                        'Reaccionar bien a un bloqueo por intentos fallidos.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Paso 1 — usuario y contraseña',
                            'd' => 'En la pantalla de acceso puedes escribir tu nombre de usuario o tu correo, cualquiera de los dos sirve. La contraseña distingue mayúsculas de minúsculas.',
                            'aviso' => 'Si te equivocas varias veces seguidas, la cuenta se bloquea unos minutos por seguridad. El sistema te avisa cuando te quedan uno o dos intentos: cuando veas ese aviso, detente y verifica bien la contraseña en vez de seguir probando.',
                        ],
                        [
                            't' => 'Paso 2 — el código que llega al correo',
                            'd' => 'Si tu cuenta tiene activada la verificación en dos pasos, después de la contraseña el sistema te manda un código de seis dígitos al correo registrado. Escríbelo en la pantalla de verificación. El código caduca a los pocos minutos y solo sirve una vez.',
                            'lista' => [
                                'El código no llega: revisa la carpeta de correo no deseado y usa el botón de reenviar (tiene una espera corta entre envíos, es normal).',
                                'Si el código caducó, vuelve a iniciar sesión desde el principio: no se puede «revivir» un código vencido.',
                                'Mientras estás entre el paso 1 y el paso 2 todavía NO estás dentro del sistema. Cerrar el navegador ahí te devuelve al inicio.',
                            ],
                        ],
                        [
                            't' => 'Equipo de confianza: qué significa marcarlo',
                            'd' => 'Al escribir el código puedes marcar la casilla de recordar este equipo. A partir de ahí, desde ESE navegador y ESA computadora no te volverá a pedir el código durante un tiempo. Sigue pidiendo la contraseña siempre.',
                            'aviso' => 'Nunca marques un equipo de confianza en una computadora compartida, en el navegador de un cliente o en un equipo del piso de venta que usan varias personas. Marcarlo ahí es dejarle a cualquiera media puerta abierta.',
                        ],
                        [
                            't' => 'Cerrar sesión de verdad',
                            'd' => 'Usa el botón de cerrar sesión (abajo del menú lateral o en el menú de tu nombre). Cerrar la pestaña no cierra la sesión. En un equipo compartido, cerrar sesión al terminar el turno es parte del trabajo, igual que contar la caja.',
                        ],
                    ],
                    'errores' => [
                        ['sintoma' => '«Usuario o contraseña incorrectos» y estás seguro de la contraseña',
                         'causa'   => 'Casi siempre es el bloqueo de mayúsculas o un espacio pegado al copiar y pegar.',
                         'solucion'=> 'Escríbela a mano, sin copiar. Si sigue fallando, pide a un administrador que te la restablezca desde Administración → Usuarios.'],
                        ['sintoma' => '«Demasiados intentos fallidos»',
                         'causa'   => 'Se superó el límite de intentos seguidos. Es una protección contra alguien probando contraseñas.',
                         'solucion'=> 'Espera el tiempo que indica el mensaje. No sirve cambiar de navegador ni de computadora.'],
                        ['sintoma' => '«Esta cuenta está desactivada»',
                         'causa'   => 'Un administrador desactivó el usuario (baja del empleado, cambio de puesto).',
                         'solucion'=> 'Solo un administrador puede reactivarla desde Administración → Usuarios.'],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Estás por entrar desde la computadora del mostrador que usan los tres cajeros del turno. ¿Marcas «recordar este equipo»?',
                            'ops' => ['Sí, así el turno entra más rápido', 'No: en un equipo compartido eso debilita el acceso de todos', 'Solo si eres el supervisor', 'Da igual, la contraseña sigue protegiendo'],
                            'ok'  => 1,
                            'exp' => 'Marcar confianza en un equipo compartido quita el segundo factor para cualquiera que se siente ahí. La contraseña sola es exactamente lo que el segundo factor viene a reforzar.',
                        ],
                    ],
                ],

                'pantalla' => [
                    'titulo'  => 'Cómo se lee cualquier pantalla del sistema',
                    'resumen' => 'Todas las pantallas están construidas igual. Aprende una y sabes usarlas todas.',
                    'permiso' => null,
                    'minutos' => 7,
                    'objetivos' => [
                        'Identificar las zonas fijas de la interfaz y para qué sirve cada una.',
                        'Filtrar, buscar y exportar en cualquier listado.',
                        'Reconocer los avisos, los estados de color y los modales de confirmación.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Las cuatro zonas fijas',
                            'd' => 'No importa en qué módulo estés, la pantalla siempre tiene las mismas cuatro zonas:',
                            'campos' => [
                                'Menú lateral (izquierda)' => 'Los módulos agrupados. Solo aparecen los que tu rol puede abrir. Se contrae con la flecha para ganar ancho en pantallas pequeñas.',
                                'Barra superior'           => 'El buscador global, el selector de sucursal, la fecha, la campana de notificaciones y tu usuario.',
                                'Cabecera de la página'    => 'El título, un subtítulo que explica qué estás viendo y, a la derecha, los botones de acción de esa pantalla.',
                                'Cuerpo'                   => 'Tarjetas con cifras arriba, y debajo el listado, el formulario o el gráfico.',
                            ],
                        ],
                        [
                            't' => 'Los listados: buscar, filtrar y paginar',
                            'd' => 'Todo listado grande trae un cuadro de búsqueda y, casi siempre, filtros por estado, fecha o sucursal. Los filtros se aplican al escribir o al elegir, y quedan escritos en la dirección del navegador: eso significa que puedes guardar esa dirección en favoritos y volver mañana al mismo filtro.',
                            'tip' => 'En pantallas anchas, la tabla se desliza sola en horizontal dentro de su tarjeta. Si la página entera se mueve de lado, eso es un fallo: repórtalo.',
                        ],
                        [
                            't' => 'Exportar: Excel y PDF',
                            'd' => 'Los listados y todos los reportes traen botones de exportación. El Excel sale con los datos crudos para seguir trabajándolos; el PDF sale con el logo y los datos de la empresa, listo para enviar a la contabilidad externa, al banco o a una inspección.',
                            'lista' => [
                                'Lo que exportas es exactamente lo que estás viendo, con los filtros puestos. Si el filtro dice «este mes», el archivo trae este mes.',
                                'Antes de exportar, revisa la sucursal activa en la barra superior: es el filtro que más se olvida.',
                            ],
                        ],
                        [
                            't' => 'Los avisos y los colores',
                            'd' => 'Después de guardar algo aparece un aviso flotante arriba a la derecha. Los verdes y azules se van solos; los rojos se quedan hasta que los cierres, porque significan que algo NO se hizo.',
                            'campos' => [
                                'Verde'  => 'Salió bien / está al día / aprobado / pagado.',
                                'Ámbar'  => 'Atención: pendiente, por vencer, bajo el mínimo.',
                                'Rojo'   => 'Problema real: vencido, anulado, rechazado, sin existencia.',
                                'Gris'   => 'Neutro o inactivo: borrador, sin movimiento.',
                            ],
                        ],
                        [
                            't' => 'Confirmaciones: nada importante se borra de un clic',
                            'd' => 'Eliminar, anular, aprobar o aplicar siempre abre una ventana de confirmación que dice exactamente qué se va a hacer. Léela: en muchos casos el texto te dice también qué NO se puede deshacer después.',
                            'aviso' => 'Anular no es lo mismo que eliminar. Anular deja el documento con su número y su rastro, marcado como anulado — que es lo que exige la contabilidad. Eliminar solo está disponible donde el documento aún no tuvo efecto (un borrador).',
                        ],
                        [
                            't' => 'El sistema en el teléfono',
                            'd' => 'Todas las pantallas funcionan en teléfono y tableta. El menú lateral se convierte en un menú que se abre con el botón de las tres rayas y las tablas se deslizan de lado. El punto de venta y el escáner de almacén están pensados para usarse así.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Exportas a Excel el listado de ventas y sale menos de lo que esperabas. ¿Qué revisas primero?',
                            'ops' => ['El permiso de exportación', 'Los filtros aplicados y la sucursal activa', 'La versión de Excel', 'El tamaño de la pantalla'],
                            'ok'  => 1,
                            'exp' => 'La exportación entrega exactamente lo que estás viendo. El filtro que más se olvida es el selector de sucursal de la barra superior.',
                        ],
                    ],
                ],

                'sucursal' => [
                    'titulo'  => 'La sucursal activa: el filtro que manda sobre todo',
                    'resumen' => 'El selector de la barra superior decide qué datos ves en todo el sistema. Es la causa número uno de «me falta información».',
                    'permiso' => null,
                    'minutos' => 5,
                    'objetivos' => [
                        'Entender la diferencia entre la sucursal de tu usuario y la sucursal activa.',
                        'Saber cuándo conviene ponerse en «Todas las sucursales» y cuándo no.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Dos cosas distintas: tu sucursal y la sucursal activa',
                            'd' => 'Tu usuario tiene una sucursal asignada, y eso es un límite de seguridad: si tienes asignado un local, jamás verás los datos de otro, hagas lo que hagas. Además existe la sucursal ACTIVA, el selector de la barra superior, que solo aparece si tu usuario tiene alcance sobre más de un local.',
                            'lista' => [
                                'Usuario con una sucursal asignada: ve solo esa. El selector no le cambia nada.',
                                'Usuario con alcance global (sin sucursal asignada): puede elegir un local concreto o «Todas las sucursales».',
                            ],
                        ],
                        [
                            't' => 'Qué cambia cuando cambias de sucursal activa',
                            'd' => 'Prácticamente todo: el stock que ves, las ventas del listado, las cifras del dashboard, los reportes, las notificaciones y hasta lo que devuelve el buscador global. El sistema no mezcla locales por su cuenta.',
                            'aviso' => 'Antes de decir «falta una venta» o «este producto no tiene existencia», mira el selector de la barra superior. Nueve de cada diez veces está ahí la explicación.',
                        ],
                        [
                            't' => 'Cuándo usar «Todas las sucursales»',
                            'd' => 'Sirve para mirar, no para operar.',
                            'campos' => [
                                'Sí, úsalo para' => 'Reportes consolidados, comparar locales, buscar un cliente o una factura sin saber de qué local salió, revisar el total de la empresa.',
                                'No lo uses para'=> 'Vender, abrir o cerrar caja, ajustar stock o hacer un conteo. Esas acciones necesitan saber en qué local estás parado, y el sistema te lo va a exigir.',
                            ],
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Un cajero jura que su venta de ayer desapareció del listado. Tiene alcance global y está en «Todas las sucursales». ¿Cuál NO es una causa posible?',
                            'ops' => ['El filtro de fechas del listado', 'La venta fue anulada', 'La sucursal activa la está ocultando', 'El filtro de estado del listado'],
                            'ok'  => 2,
                            'exp' => 'En «Todas las sucursales» el filtro de local no oculta nada. Las otras tres sí explican una venta que no aparece.',
                        ],
                    ],
                ],

                'buscador' => [
                    'titulo'   => 'El buscador global (Ctrl + K)',
                    'resumen'  => 'Un solo cuadro que encuentra productos, clientes, ventas, proveedores, empleados y cualquier pantalla del sistema.',
                    'permiso'  => null,
                    'minutos'  => 4,
                    'pantalla' => 'modules/busqueda/index.php',
                    'objetivos' => [
                        'Abrir el buscador con el teclado y navegar sin el ratón.',
                        'Saber qué encuentra y por qué respeta tus permisos.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Ábrelo con el teclado',
                            'd' => 'Pulsa Ctrl + K (o ⌘ + K en Mac) desde cualquier pantalla. También puedes hacer clic en el cuadro de la barra superior. Escribe, muévete con las flechas y abre con Enter. Escape lo cierra.',
                        ],
                        [
                            't' => 'Qué encuentra',
                            'd' => 'Busca a la vez en varias familias de datos y en el menú completo del sistema:',
                            'lista' => [
                                'Productos por nombre, código interno o código de barras.',
                                'Clientes y proveedores por nombre, RNC/cédula o teléfono.',
                                'Ventas por número de factura o por NCF.',
                                'Compras, empleados y oportunidades del CRM.',
                                'Pantallas: escribe «nómina», «conciliación» o «roles» y te lleva ahí sin buscar en el menú.',
                            ],
                        ],
                        [
                            't' => 'Respeta tus permisos y tu sucursal',
                            'd' => 'El buscador no es una puerta trasera. Lo que no puedes ver en su módulo tampoco aparece aquí, y lo que pertenece a otra sucursal fuera de tu alcance tampoco. Si esperabas un resultado y no sale, o no tienes el permiso o estás en la sucursal equivocada.',
                            'tip' => 'Con el cuadro vacío te muestra accesos rápidos a lo que más se usa. Es la forma más corta de llegar al punto de venta desde cualquier sitio.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Buscas un empleado por su cédula y no aparece, aunque existe. ¿Qué explicación es la correcta?',
                            'ops' => ['El buscador solo busca productos', 'Tu rol no tiene permiso para ver empleados', 'Hay que buscar siempre por nombre', 'El buscador necesita al menos cinco letras'],
                            'ok'  => 1,
                            'exp' => 'El buscador filtra por permisos exactamente igual que el módulo. Sin `rrhh_empleados.ver`, los empleados no salen.',
                        ],
                    ],
                ],

                'notificaciones' => [
                    'titulo'   => 'El centro de alertas',
                    'resumen'  => 'La campana no avisa de eventos pasados: avisa de situaciones vivas que alguien tiene que resolver.',
                    'permiso'  => null,
                    'minutos'  => 5,
                    'pantalla' => 'modules/notificaciones/index.php',
                    'objetivos' => [
                        'Entender por qué una alerta desaparece sola.',
                        'Priorizar por criticidad y actuar desde la propia alerta.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Una alerta = una situación viva, no un evento',
                            'd' => 'La diferencia importa. «Se vendió el último frasco» sería un evento y llenaría la campana de ruido. Lo que el sistema levanta es «este producto está bajo el mínimo», y esa alerta se resuelve SOLA en cuanto entra mercancía. No hay que ir marcando cosas como leídas una por una.',
                        ],
                        [
                            't' => 'Qué vigila',
                            'd' => 'Entre otras cosas: existencias bajo el mínimo, mercancía vencida o por vencer, cuentas por cobrar vencidas, cuentas por pagar próximas, cajas que quedaron abiertas, secuencias de comprobantes fiscales agotándose, conteos sin aplicar y transferencias esperando aprobación.',
                        ],
                        [
                            't' => 'Prioridad y permiso',
                            'd' => 'Cada alerta tiene una prioridad (crítica, alta, media, baja) y un permiso. Solo la ve quien tiene ese permiso: al cajero no le llegan las alertas de la nómina y al de RRHH no le llegan las de stock. Algunas van dirigidas a una sola persona.',
                            'tip' => 'Empieza siempre por las críticas y por las rojas. Cada alerta trae un botón que te lleva directo a la pantalla donde se resuelve.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Repones un producto que estaba bajo el mínimo. ¿Qué hay que hacer con su alerta?',
                            'ops' => ['Marcarla como leída', 'Eliminarla', 'Nada: se resuelve sola al desaparecer la situación', 'Pedirle a un administrador que la cierre'],
                            'ok'  => 2,
                            'exp' => 'Las alertas describen situaciones. Cuando la situación deja de existir, la alerta pasa a resuelta por su cuenta.',
                        ],
                    ],
                ],

                'perfil' => [
                    'titulo'   => 'Tu perfil, tu contraseña y tus equipos',
                    'resumen'  => 'Lo poco que puedes cambiar de tu propia cuenta, y por qué el resto lo cambia un administrador.',
                    'permiso'  => null,
                    'minutos'  => 4,
                    'pantalla' => 'modules/auth/perfil.php',
                    'objetivos' => [
                        'Cambiar tu contraseña correctamente.',
                        'Saber qué depende de ti y qué depende del administrador.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Lo que cambias tú',
                            'd' => 'Desde tu nombre en la barra superior → Mi perfil puedes actualizar tus datos de contacto, tu foto y tu contraseña. Para cambiar la contraseña hay que escribir la actual: así, si alguien se sienta en tu sesión abierta, no puede dejarte fuera de tu propia cuenta.',
                        ],
                        [
                            't' => 'Lo que NO cambias tú',
                            'd' => 'Tu rol, tus permisos y tu sucursal asignada solo los cambia un administrador. No es burocracia: son las tres cosas que definen hasta dónde llega tu acceso, y si cada quien se las pudiera ajustar no habría control de ningún tipo.',
                        ],
                        [
                            't' => 'Una contraseña que sirva',
                            'd' => 'Larga antes que rara. Una frase de cuatro palabras que solo tú entiendas es más segura y más fácil de recordar que ocho caracteres con símbolos.',
                            'aviso' => 'Nunca compartas tu contraseña, ni con tu supervisor. Todo lo que se haga con tu usuario queda registrado a tu nombre en la auditoría, y ahí no hay forma de demostrar que fue otro.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Necesitas ver un módulo que no aparece en tu menú. ¿Qué haces?',
                            'ops' => ['Cambiarlo desde Mi perfil', 'Entrar con el usuario de un compañero que sí lo tiene', 'Pedir a un administrador que ajuste tu rol', 'Buscarlo con Ctrl+K, ahí sí sale'],
                            'ok'  => 2,
                            'exp' => 'Los permisos los asigna un administrador desde Roles. Ni el perfil ni el buscador saltan un permiso — y usar la cuenta de otro deja el rastro a nombre de esa persona.',
                        ],
                    ],
                ],

                'permisos' => [
                    'titulo'  => 'Por qué no ves lo mismo que tu compañero',
                    'resumen' => 'Cómo funciona el control de acceso: roles, permisos por acción y alcance por sucursal.',
                    'permiso' => null,
                    'minutos' => 5,
                    'objetivos' => [
                        'Explicar la diferencia entre ver, crear, aprobar y anular.',
                        'Entender por qué el sistema separa quien pide de quien autoriza.',
                    ],
                    'pasos' => [
                        [
                            't' => 'El permiso no es por módulo, es por acción',
                            'd' => 'No existe «acceso a inventario». Existen permisos separados para ver el stock, para ajustarlo, para crear una transferencia, para aprobarla y para recibirla. Tu rol es un paquete de esas acciones concretas.',
                        ],
                        [
                            't' => 'Por qué se separa pedir de autorizar',
                            'd' => 'Hay parejas de permisos que están separadas a propósito, y son justo las que mueven dinero o mercancía:',
                            'campos' => [
                                'Transferencias'  => 'Quien solicita el traslado no debería ser quien lo aprueba. La mercancía sale del local en la aprobación, no en la solicitud.',
                                'Conteo físico'   => 'Capturar cantidades y aplicar el ajuste al stock son permisos distintos. Aplicar es lo que reescribe la existencia.',
                                'Liquidaciones'   => 'Crear el borrador es cálculo; aplicarlo entra la mercancía y reescribe el costo del catálogo entero.',
                                'Comisiones'      => 'Generar, aprobar y pagar son tres permisos: quien calcula lo suyo no debería aprobárselo.',
                                'Facturación electrónica' => 'Ver los comprobantes es una cosa; cambiar el ambiente o los rangos de secuencia es emitir documentos fiscales reales.',
                            ],
                        ],
                        [
                            't' => 'Si te falta un permiso',
                            'd' => 'Verás la pantalla de acceso denegado, o directamente el módulo no aparecerá en tu menú. No es un error: pide el permiso concreto a un administrador explicando qué necesitas hacer. Cuanto más concreta la petición, menos permisos de más te darán.',
                            'tip' => 'Pide «necesito recibir transferencias», no «necesito acceso a inventario». La primera abre una acción; la segunda abre quince.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Creaste una transferencia hacia la otra tienda pero la mercancía sigue sin salir de tu stock. ¿Por qué?',
                            'ops' => ['Falta correr un proceso nocturno', 'La mercancía no sale hasta que alguien con permiso la aprueba', 'Hay que exportarla primero', 'El stock se actualiza al recibirla'],
                            'ok'  => 1,
                            'exp' => 'La solicitud solo pide permiso. El stock sale del origen cuando alguien con `transferencias.aprobar` autoriza la salida.',
                        ],
                    ],
                ],
            ],
        ],

        /* =====================================================================
         *  2. PUNTO DE VENTA
         * ===================================================================== */
        'pos' => [
            'titulo'      => 'Punto de venta y caja',
            'descripcion' => 'Todo el ciclo del mostrador: abrir la caja, vender con comprobante fiscal, cobrar, devolver y cuadrar al cierre.',
            'icono'       => 'cart',
            'color'       => 'emerald',
            'nivel'       => 'Básico',
            'permiso'     => ['pos.ver', 'caja.ver', 'ventas.ver'],
            'para_quien'  => 'Cajeros, vendedores de piso y encargados de local.',
            'lecciones'   => [

                'caja-abrir' => [
                    'titulo'   => 'Abrir la caja del turno',
                    'resumen'  => 'Sin caja abierta no se puede cobrar. Cómo abrirla con el fondo correcto.',
                    'permiso'  => 'caja.abrir',
                    'minutos'  => 5,
                    'pantalla' => 'modules/pos/caja.php',
                    'objetivos' => [
                        'Abrir una sesión de caja con su fondo inicial.',
                        'Entender qué es una sesión de caja y por qué solo puede haber una abierta.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Qué es una sesión de caja',
                            'd' => 'Es el turno: se abre con un fondo en efectivo, durante el turno acumula ventas, ingresos y egresos, y se cierra contando el dinero. Todo lo que se cobre queda amarrado a esa sesión, con su cajero y su sucursal.',
                            'ruta' => 'modules/pos/caja.php',
                        ],
                        [
                            't' => 'Abrir',
                            'd' => 'Entra a Ventas → Caja y pulsa abrir. El sistema pide:',
                            'campos' => [
                                'Caja'          => 'Cuál de las cajas del local vas a usar. Solo salen las de tu sucursal.',
                                'Fondo inicial' => 'El efectivo con el que empiezas. Cuéntalo antes de escribirlo, no lo pongas de memoria.',
                                'Nota'          => 'Opcional, para dejar constancia de algo raro (por ejemplo, que el fondo llegó incompleto).',
                            ],
                            'aviso' => 'Un fondo mal declarado se convierte en un descuadre al cierre que nadie sabrá explicar tres días después. Contar antes de abrir cuesta un minuto.',
                        ],
                        [
                            't' => 'Una caja, una sesión abierta',
                            'd' => 'La misma caja no puede tener dos sesiones abiertas a la vez. Si el turno anterior no cerró, el sistema te lo dirá al intentar abrir: hay que cerrar esa sesión primero, con quien corresponda.',
                        ],
                        [
                            't' => 'Movimientos durante el turno',
                            'd' => 'Además de las ventas, la caja registra entradas y salidas de efectivo que no son ventas: un cambio que se pidió al banco, un pago menor de mensajería, un retiro parcial a la caja fuerte. Se registran con el botón de movimiento (ingreso o egreso) y SIEMPRE con un concepto escrito.',
                            'tip' => 'Un egreso sin concepto es un faltante disfrazado. El concepto es lo que después permite defender el arqueo.',
                        ],
                    ],
                    'errores' => [
                        ['sintoma' => 'El punto de venta no deja cobrar',
                         'causa'   => 'No hay una sesión de caja abierta para tu usuario en esta sucursal.',
                         'solucion'=> 'Abre la caja en Ventas → Caja. Si dice que ya hay una abierta, es del turno anterior: hay que cerrarla.'],
                        ['sintoma' => 'No aparece ninguna caja en la lista al abrir',
                         'causa'   => 'La sucursal activa no es la tuya, o el local no tiene cajas creadas.',
                         'solucion'=> 'Ajusta la sucursal activa. Si de verdad no hay cajas, un administrador las crea en Administración → Sucursales.'],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Sales a llevar RD$ 5,000 de la caja a la caja fuerte a media tarde. ¿Cómo se registra?',
                            'ops' => ['No se registra, se descuenta al cerrar', 'Como un egreso de caja con su concepto', 'Como una venta anulada', 'Como un ajuste de inventario'],
                            'ok'  => 1,
                            'exp' => 'Es un movimiento de efectivo que no es una venta: egreso con concepto escrito. Si no se registra, aparece como faltante en el arqueo.',
                        ],
                    ],
                ],

                'vender' => [
                    'titulo'   => 'Hacer una venta',
                    'resumen'  => 'El flujo completo del mostrador: buscar, agregar, descontar, cobrar e imprimir.',
                    'permiso'  => 'pos.vender',
                    'minutos'  => 10,
                    'pantalla' => 'modules/pos/index.php',
                    'objetivos' => [
                        'Armar un carrito con teclado, ratón o pistola de código de barras.',
                        'Elegir cliente y comprobante fiscal correctamente.',
                        'Cobrar en efectivo, con tarjeta, mixto o a crédito.',
                    ],
                    'pasos' => [
                        [
                            't' => 'La pantalla',
                            'd' => 'A la izquierda el catálogo con su buscador y sus categorías; a la derecha el carrito con los totales y el botón de cobrar. En teléfono el carrito se abre desde abajo.',
                            'ruta' => 'modules/pos/index.php',
                        ],
                        [
                            't' => 'Agregar productos',
                            'd' => 'Tres formas, todas válidas a la vez:',
                            'lista' => [
                                'Escribe en el buscador (nombre, código interno o código de barras) y pulsa Enter.',
                                'Dispara con la pistola de códigos de barras: el artículo entra solo al carrito, sin tocar nada.',
                                'Haz clic en el artículo del catálogo.',
                            ],
                            'tip' => 'Si un artículo ya está en el carrito, volver a agregarlo sube la cantidad en vez de duplicar la línea.',
                        ],
                        [
                            't' => 'Ajustar el carrito',
                            'd' => 'Sobre cada línea puedes cambiar la cantidad, aplicar un descuento y quitarla. El descuento se puede poner por línea o sobre el total de la factura, según cómo esté configurado el local.',
                            'aviso' => 'Los descuentos reducen el ingreso y salen en el informe de desempeño del equipo. No es una penalización: es que la dirección necesita saber cuánto se está dejando en descuentos y quién los da.',
                        ],
                        [
                            't' => 'El cliente',
                            'd' => 'Puedes vender sin cliente (consumidor final) o elegir uno. Elegir cliente es obligatorio si vas a vender a crédito, y necesario si el comprobante requiere RNC.',
                            'lista' => [
                                'Si el cliente no existe, se puede crear desde el mismo punto de venta sin perder el carrito.',
                                'Un cliente con crédito muestra su límite y su saldo actual.',
                            ],
                        ],
                        [
                            't' => 'El comprobante fiscal (NCF)',
                            'd' => 'Antes de cobrar se elige el tipo de comprobante. El sistema toma el siguiente número de la secuencia autorizada por la DGII y lo consume de forma atómica: dos cajas cobrando a la vez nunca reciben el mismo número.',
                            'aviso' => 'Un NCF consumido no se recicla. Si te equivocas de tipo de comprobante, la corrección es una nota de crédito, no borrar y repetir.',
                        ],
                        [
                            't' => 'Cobrar',
                            'd' => 'El botón de cobrar abre la pantalla de pago con el total. Ahí eliges el método:',
                            'campos' => [
                                'Efectivo' => 'Escribe con cuánto paga el cliente y el sistema calcula el cambio.',
                                'Tarjeta / transferencia' => 'Se registra con su referencia si el método lo pide.',
                                'Pago mixto' => 'Parte en efectivo y parte con tarjeta: se agregan varias líneas de pago hasta cubrir el total.',
                                'Crédito' => 'No entra dinero hoy. El total se suma al balance del cliente y aparece en Cuentas por Cobrar. Requiere cliente y respeta su límite.',
                            ],
                        ],
                        [
                            't' => 'Después de cobrar',
                            'd' => 'La venta queda registrada y el sistema, en el mismo movimiento: baja el stock de la sucursal, congela el costo de lo vendido, consume el NCF, registra la comisión del vendedor, mueve el saldo del cliente si fue a crédito y suma a la sesión de caja. Se imprime el ticket o la factura.',
                            'tip' => 'El ticket se puede reimprimir después desde Ventas → Ventas. No hay que apurarse a imprimir dos por si acaso.',
                        ],
                    ],
                    'errores' => [
                        ['sintoma' => '«Sin existencia suficiente»',
                         'causa'   => 'El stock de esa sucursal no alcanza para la cantidad pedida.',
                         'solucion'=> 'Verifica en Inventario → Stock. Si la mercancía está físicamente pero no en el sistema, falta recibir una compra o una transferencia — no lo arregles vendiendo en negativo.'],
                        ['sintoma' => 'El carrito mezcla artículos de dos marcas y no deja cobrar',
                         'causa'   => 'Una factura lleva un solo logo. El sistema para en vez de adivinar cuál imprimir.',
                         'solucion'=> 'Separa la venta en dos facturas, una por marca.'],
                        ['sintoma' => 'No aparecen productos en el catálogo',
                         'causa'   => 'La tienda (marca) activa filtra el catálogo, o la sucursal activa no es la tuya.',
                         'solucion'=> 'Cambia la tienda activa desde el propio punto de venta y revisa la sucursal en la barra superior.'],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Cobraste con el tipo de comprobante equivocado y ya imprimiste. ¿Qué corresponde hacer?',
                            'ops' => ['Borrar la venta y repetirla', 'Emitir una nota de crédito y volver a facturar', 'Cambiar el NCF a mano en la factura', 'Anular la secuencia completa'],
                            'ok'  => 1,
                            'exp' => 'Un NCF consumido no se recicla ni se edita. La corrección de un comprobante emitido es una nota de crédito.',
                        ],
                        [
                            'p'   => 'Vendes a crédito por RD$ 3,000. ¿Qué pasa con la caja del turno?',
                            'ops' => ['Suma RD$ 3,000 al efectivo esperado', 'No entra dinero: la venta va al saldo del cliente', 'Suma la mitad', 'Queda pendiente hasta el cierre'],
                            'ok'  => 1,
                            'exp' => 'Una venta a crédito no mueve efectivo hoy. Va al balance del cliente y se cobra después en Cuentas por Cobrar.',
                        ],
                    ],
                ],

                'ticket' => [
                    'titulo'   => 'Buscar, reimprimir y anular una venta',
                    'resumen'  => 'Qué hacer con una factura ya emitida, y cuál es la diferencia entre anular y devolver.',
                    'permiso'  => 'ventas.ver',
                    'minutos'  => 6,
                    'pantalla' => 'modules/pos/ventas.php',
                    'objetivos' => [
                        'Localizar una venta por número, NCF, cliente o fecha.',
                        'Distinguir anulación de devolución y elegir la correcta.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Encontrar la venta',
                            'd' => 'Ventas → Ventas lista todas las facturas con sus filtros de fecha, estado, método de pago, vendedor y sucursal. También la encuentras desde el buscador global escribiendo el número de factura o el NCF.',
                            'ruta' => 'modules/pos/ventas.php',
                        ],
                        [
                            't' => 'Reimprimir',
                            'd' => 'Desde el detalle de la venta se vuelve a imprimir el ticket o la factura, con el mismo logo y los mismos datos que salieron el día de la venta — aunque el producto haya cambiado de marca desde entonces.',
                        ],
                        [
                            't' => 'Anular vs. devolver: no son lo mismo',
                            'd' => 'Elegir mal aquí es lo que después descuadra el inventario y los reportes.',
                            'campos' => [
                                'Anular'   => 'La venta no debió existir: se cobró dos veces, se facturó al cliente equivocado, se equivocó todo. Revierte la operación completa. Requiere el permiso `ventas.anular`.',
                                'Devolver' => 'La venta fue correcta pero el cliente trae mercancía de vuelta, entera o en parte. Se registra una devolución que devuelve el producto al stack y el dinero (o el crédito) al cliente.',
                            ],
                            'aviso' => 'Nunca anules una venta para «arreglar» una devolución parcial: pierdes el rastro de lo que sí se vendió y el reporte del vendedor queda mal.',
                        ],
                        [
                            't' => 'Qué queda después de anular',
                            'd' => 'La venta no desaparece: queda marcada como anulada, con su número, su NCF y el registro de quién la anuló y cuándo. Eso es lo que exige la contabilidad. El stock vuelve y el saldo del cliente se corrige.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Un cliente compró 5 unidades y devuelve 2 al día siguiente. ¿Qué haces?',
                            'ops' => ['Anular la venta y facturar 3', 'Registrar una devolución por 2 unidades', 'Ajustar el inventario a mano', 'Emitir una venta negativa'],
                            'ok'  => 1,
                            'exp' => 'La venta de 5 fue real y así debe quedar. La devolución parcial de 2 se registra como devolución: repone stock y deja el rastro correcto.',
                        ],
                    ],
                ],

                'devoluciones' => [
                    'titulo'   => 'Devoluciones y notas de crédito',
                    'resumen'  => 'Devolver mercancía, reponer el stock y emitir el comprobante fiscal que corresponde.',
                    'permiso'  => 'devoluciones.crear',
                    'minutos'  => 7,
                    'pantalla' => 'modules/pos/devoluciones.php',
                    'objetivos' => [
                        'Registrar una devolución total o parcial contra su factura.',
                        'Saber cuándo hace falta una nota de crédito fiscal.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Siempre contra la factura original',
                            'd' => 'Una devolución nace de una venta concreta. Busca la factura, elige las líneas que vuelven y las cantidades. El sistema no deja devolver más de lo que se vendió ni devolver dos veces la misma unidad.',
                            'ruta' => 'modules/pos/devoluciones.php',
                        ],
                        [
                            't' => 'Qué pasa con la mercancía',
                            'd' => 'Vuelve al stock de la sucursal donde se registra la devolución. Si el artículo vuelve dañado y no se puede revender, NO lo devuelvas al stock vendible: regístralo y luego dale de baja con un ajuste con su nota, o por el circuito de mercancía dañada.',
                            'aviso' => 'Devolver al stock algo que no se puede vender infla el inventario y hace que el sistema prometa existencia que no hay.',
                        ],
                        [
                            't' => 'Qué pasa con el dinero',
                            'd' => 'Según cómo se pagó la venta: se devuelve efectivo de la caja (queda como egreso de la sesión), se revierte el cobro con tarjeta, o se descuenta del saldo del cliente si la venta fue a crédito.',
                        ],
                        [
                            't' => 'La nota de crédito',
                            'd' => 'Cuando la venta original llevó un comprobante fiscal, la devolución necesita su propia nota de crédito con su secuencia. Ese es el documento que la DGII espera ver para cuadrar lo que se anuló contra lo que se facturó, y es lo que sale en el reporte 607.',
                            'ruta' => 'modules/pos/nota_credito.php',
                        ],
                    ],
                    'errores' => [
                        ['sintoma' => 'No deja devolver: «cantidad mayor a la vendida»',
                         'causa'   => 'Ya hay una devolución previa sobre esa misma línea.',
                         'solucion'=> 'Revisa el historial de devoluciones de esa factura antes de insistir.'],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'El cliente devuelve un artículo roto que no se puede revender. ¿Qué NO debes hacer?',
                            'ops' => ['Registrar la devolución', 'Devolverle su dinero', 'Dejarlo entrar al stock vendible sin más', 'Dar de baja la unidad con su nota'],
                            'ok'  => 2,
                            'exp' => 'Si vuelve al stock vendible, el sistema promete una existencia que no se puede vender. Se registra la devolución y después la baja, con su nota.',
                        ],
                    ],
                ],

                'caja-cerrar' => [
                    'titulo'   => 'Cerrar la caja y cuadrar el turno',
                    'resumen'  => 'El arqueo: contar, declarar, entender el descuadre y cerrar.',
                    'permiso'  => 'caja.cerrar',
                    'minutos'  => 8,
                    'pantalla' => 'modules/pos/caja.php',
                    'objetivos' => [
                        'Hacer un arqueo honesto y entender de dónde sale el efectivo esperado.',
                        'Reaccionar bien ante un sobrante o un faltante.',
                    ],
                    'pasos' => [
                        [
                            't' => 'De dónde sale el «efectivo esperado»',
                            'd' => 'No es la venta del día. Es esta cuenta:',
                            'lista' => [
                                'Fondo inicial declarado al abrir',
                                '+ ventas cobradas EN EFECTIVO durante el turno',
                                '+ ingresos de efectivo registrados (cambio del banco, abonos de clientes en efectivo)',
                                '− egresos de efectivo registrados (retiros, gastos menores)',
                                '− devoluciones pagadas en efectivo',
                            ],
                            'tip' => 'Las ventas con tarjeta y las ventas a crédito NO entran en el efectivo esperado. Ese es el malentendido más común del primer cierre.',
                        ],
                        [
                            't' => 'Contar antes de mirar',
                            'd' => 'Cuenta el dinero físico primero y anótalo. Después ábrelo en el sistema y declara lo contado. Mirar el esperado antes de contar hace que la cuenta «se acomode» sola sin querer.',
                        ],
                        [
                            't' => 'El descuadre',
                            'd' => 'La diferencia entre lo contado y lo esperado queda registrada con su cajero, su sesión y su fecha. No se puede maquillar.',
                            'campos' => [
                                'Faltante' => 'Falta dinero. Causas frecuentes: un cambio mal dado, un egreso no registrado, una venta en efectivo cobrada como tarjeta.',
                                'Sobrante' => 'Sobra dinero. Suele ser una venta cobrada de menos, un cambio no entregado o un ingreso no registrado.',
                            ],
                            'aviso' => 'Un descuadre pequeño y explicado no es un problema. Lo que sí lo es: cerrar sin nota, o «cuadrar» el conteo para que dé cero. Eso convierte un error de RD$ 50 en un problema de confianza.',
                        ],
                        [
                            't' => 'Cerrar',
                            'd' => 'Al cerrar, la sesión queda bloqueada: no se le pueden agregar más ventas ni movimientos. El sistema genera el resumen del turno (ventas por método de pago, movimientos, arqueo y descuadre) y se puede imprimir para entregarlo con el dinero.',
                        ],
                    ],
                    'errores' => [
                        ['sintoma' => 'El esperado es mucho mayor que lo contado y no hay explicación',
                         'causa'   => 'Casi siempre, ventas con tarjeta registradas como efectivo.',
                         'solucion'=> 'Revisa el desglose por método de pago del turno antes de declarar el faltante.'],
                        ['sintoma' => 'Quedó una caja abierta de ayer',
                         'causa'   => 'Nadie cerró el turno.',
                         'solucion'=> 'Ciérrala con el conteo real de lo que haya y una nota explicando la situación. No la borres.'],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'El turno vendió RD$ 40,000: RD$ 25,000 en efectivo, RD$ 10,000 con tarjeta y RD$ 5,000 a crédito. El fondo fue RD$ 2,000 y no hubo movimientos. ¿Cuánto efectivo debería haber?',
                            'ops' => ['RD$ 42,000', 'RD$ 27,000', 'RD$ 37,000', 'RD$ 40,000'],
                            'ok'  => 1,
                            'exp' => 'Fondo (2,000) + ventas en efectivo (25,000) = 27,000. Ni la tarjeta ni el crédito ponen billetes en la gaveta.',
                        ],
                        [
                            'p'   => 'Te faltan RD$ 200 y no sabes por qué. ¿Qué haces?',
                            'ops' => ['Poner RD$ 200 de tu bolsillo para que cuadre', 'Declarar lo contado real y dejar la nota', 'Declarar el esperado y anotarlo mañana', 'No cerrar hasta encontrarlo'],
                            'ok'  => 1,
                            'exp' => 'El arqueo vale por ser real. Un faltante declarado y explicado se investiga; uno tapado envenena todos los cierres siguientes.',
                        ],
                    ],
                ],

                'cotizaciones' => [
                    'titulo'   => 'Cotizaciones',
                    'resumen'  => 'Ofertar precios sin comprometer inventario, y convertir la cotización en factura cuando el cliente acepta.',
                    'permiso'  => 'cotizaciones.ver',
                    'minutos'  => 6,
                    'pantalla' => 'modules/pos/cotizaciones.php',
                    'objetivos' => [
                        'Armar y enviar una cotización con vigencia.',
                        'Convertirla en factura respetando el precio pactado.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Una cotización no mueve nada',
                            'd' => 'No baja stock, no consume comprobante fiscal, no crea deuda. Es una propuesta de precio con una fecha de vigencia. Por eso se puede cotizar algo que todavía no ha llegado.',
                            'ruta' => 'modules/pos/cotizaciones.php',
                        ],
                        [
                            't' => 'Armarla',
                            'd' => 'Elige el cliente, agrega las líneas con sus cantidades y precios, y fija la validez. Se puede descargar en PDF con el logo de la empresa para enviarla por correo o por WhatsApp.',
                        ],
                        [
                            't' => 'Convertirla en factura',
                            'd' => 'Cuando el cliente acepta, el botón de facturar la convierte en una venta real: ahí sí baja el stock, se consume el NCF y se cobra. Requiere el permiso `cotizaciones.facturar`.',
                            'aviso' => 'La factura respeta el PRECIO PACTADO en la cotización, aunque el precio de lista haya subido desde entonces. Eso es intencional: se cumple lo que se ofreció por escrito.',
                        ],
                        [
                            't' => 'Cotizaciones vencidas',
                            'd' => 'Una cotización fuera de vigencia se marca como vencida. Se puede duplicar para generar una nueva con precios actualizados en vez de reescribirla desde cero.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Cotizaste un artículo a RD$ 1,000. Hoy el precio de lista es RD$ 1,200 y el cliente acepta la cotización aún vigente. ¿A cuánto se factura?',
                            'ops' => ['RD$ 1,200, es el precio actual', 'RD$ 1,000, el precio pactado', 'El promedio', 'Hay que rehacer la cotización'],
                            'ok'  => 1,
                            'exp' => 'La conversión respeta el precio pactado mientras la cotización esté vigente. Es lo que se ofreció por escrito.',
                        ],
                    ],
                ],

                'pedidos' => [
                    'titulo'   => 'Pedidos en línea',
                    'resumen'  => 'Atender los pedidos que entran por la tienda en línea y convertirlos en venta.',
                    'permiso'  => 'pedidos.ver',
                    'minutos'  => 5,
                    'pantalla' => 'modules/pos/pedidos.php',
                    'objetivos' => [
                        'Mover un pedido por sus estados.',
                        'Saber en qué momento baja el inventario.',
                    ],
                    'pasos' => [
                        [
                            't' => 'De dónde vienen',
                            'd' => 'De la tienda en línea. Entran con los datos del cliente, lo que pidió y cómo quiere recibirlo. Cada cambio de estado le avisa al cliente por correo.',
                            'ruta' => 'modules/pos/pedidos.php',
                        ],
                        [
                            't' => 'Los estados',
                            'd' => 'Un pedido recorre: recibido → confirmado → preparado → entregado. También puede cancelarse. Cambiar el estado requiere el permiso `pedidos.gestionar`.',
                        ],
                        [
                            't' => 'Cuándo baja el stock',
                            'd' => 'El pedido no descuenta inventario por existir. El descuento ocurre cuando el pedido se factura, igual que cualquier otra venta. Si la marca del artículo no está clara, el sistema la deduce del propio producto.',
                            'tip' => 'Confirma solo lo que de verdad puedes despachar. Un pedido confirmado que no tiene existencia es una promesa que el cliente ya recibió por correo.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => '¿En qué momento un pedido en línea baja el inventario?',
                            'ops' => ['Al entrar el pedido', 'Al confirmarlo', 'Al facturarlo', 'Al entregarlo'],
                            'ok'  => 2,
                            'exp' => 'El pedido es una intención. El inventario baja cuando se convierte en venta facturada.',
                        ],
                    ],
                ],

                'offline' => [
                    'titulo'   => 'Terminales offline y comprobantes reservados',
                    'resumen'  => 'Seguir vendiendo cuando se cae el internet, sin repetir un solo número de comprobante.',
                    'permiso'  => 'pos.terminales',
                    'minutos'  => 7,
                    'pantalla' => 'modules/pos/terminales.php',
                    'objetivos' => [
                        'Entender cómo se reservan comprobantes por terminal.',
                        'Sincronizar las ventas hechas sin conexión.',
                    ],
                    'pasos' => [
                        [
                            't' => 'El problema que resuelve',
                            'd' => 'Sin internet no se puede pedir el siguiente número de comprobante al servidor. Si cada terminal inventara su número, dos cajas emitirían el mismo NCF y eso es un problema fiscal serio.',
                            'ruta' => 'modules/pos/terminales.php',
                        ],
                        [
                            't' => 'La solución: reservar por adelantado',
                            'd' => 'Cada terminal registrada recibe un bloque de números reservados solo para ella. Mientras esté sin conexión usa los suyos y ninguna otra terminal puede tocarlos. Al volver la conexión, las ventas suben con los números que ya tenían.',
                        ],
                        [
                            't' => 'La rutina diaria',
                            'd' => 'Tres hábitos que evitan el 90% de los problemas:',
                            'lista' => [
                                'Al empezar el turno, con conexión, abre el punto de venta: así la terminal renueva su reserva.',
                                'Vigila cuántos comprobantes reservados le quedan a la terminal. Si se agotan estando sin conexión, no se puede facturar.',
                                'En cuanto vuelva la conexión, verifica que las ventas pendientes se hayan sincronizado.',
                            ],
                            'aviso' => 'No borres los datos del navegador ni cierres sesión mientras haya ventas sin sincronizar: viven en ese navegador hasta que suben.',
                        ],
                    ],
                    'errores' => [
                        ['sintoma' => 'Volvió el internet y las ventas no suben',
                         'causa'   => 'La terminal perdió su registro, o el navegador se limpió.',
                         'solucion'=> 'Abre el punto de venta con conexión y espera la sincronización. Si aparecen conflictos, se revisan una por una desde la pantalla de terminales.'],
                    ],
                    'quiz' => [
                        [
                            'p'   => '¿Por qué cada terminal offline tiene su propio bloque de comprobantes reservados?',
                            'ops' => ['Para repartir el trabajo', 'Para que dos terminales no emitan el mismo NCF', 'Para que sea más rápido', 'Para poder anular después'],
                            'ok'  => 1,
                            'exp' => 'Sin reserva previa, dos terminales sin conexión inventarían el mismo número. Repetir un NCF es un problema fiscal, no un detalle técnico.',
                        ],
                    ],
                ],
            ],
        ],

        /* =====================================================================
         *  3. INVENTARIO Y ALMACÉN
         * ===================================================================== */
        'inventario' => [
            'titulo'      => 'Inventario y almacén',
            'descripcion' => 'El catálogo, las existencias por local, el kardex, los ajustes con nota obligatoria y el conteo físico.',
            'icono'       => 'box',
            'color'       => 'amber',
            'nivel'       => 'Intermedio',
            'permiso'     => ['productos.ver', 'inventario.ver', 'conteos.ver'],
            'para_quien'  => 'Encargados de almacén, compradores y supervisores de local.',
            'lecciones'   => [

                'catalogo' => [
                    'titulo'   => 'Cómo se arma el catálogo',
                    'resumen'  => 'Categorías, marcas y unidades: las tres piezas que hay que tener antes de crear productos.',
                    'permiso'  => 'productos.ver',
                    'minutos'  => 6,
                    'pantalla' => 'modules/inventario/categorias.php',
                    'objetivos' => [
                        'Distinguir categoría, marca y unidad.',
                        'Entender que la categoría gobierna cómo se lee el negocio en los reportes.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Las tres piezas',
                            'd' => 'Un producto se apoya en tres catálogos previos:',
                            'campos' => [
                                'Categoría' => 'La familia comercial (cuidado facial, fragancias, hogar). Es lo que después agrupa las ventas, el margen y el inventario valorizado en los reportes.',
                                'Marca'     => 'El fabricante o la línea. Sirve para filtrar y analizar. Cuidado: es distinta de la «tienda», que es la identidad con la que se factura.',
                                'Unidad'    => 'Cómo se mide y se vende: unidad, caja, litro, kilo.',
                            ],
                            'ruta' => 'modules/inventario/categorias.php',
                        ],
                        [
                            't' => 'La categoría no es una etiqueta cualquiera',
                            'd' => 'Es el eje por el que la dirección lee el negocio. Si todo cae en «General», el reporte de rentabilidad por categoría no dice nada y el análisis de qué línea sostiene el local se vuelve imposible.',
                            'tip' => 'Pocas categorías bien pensadas valen más que treinta improvisadas. Si dudas entre dos, pregúntate cuál usarías para decidir qué comprar el mes que viene.',
                        ],
                        [
                            't' => 'Marcas y unidades',
                            'd' => 'Se administran en Inventario → Marcas y Unidades. Son listas simples pero conviene mantenerlas limpias: «L\'Occitane», «Loccitane» y «L Occitane» como tres marcas distintas rompen cualquier análisis.',
                            'ruta' => 'modules/inventario/catalogos.php',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => '¿Cuál es la diferencia entre la «marca» de un producto y la «tienda»?',
                            'ops' => ['Son sinónimos', 'La marca es del fabricante y sirve para analizar; la tienda es la identidad con la que se factura', 'La tienda es el local físico', 'La marca define el precio'],
                            'ok'  => 1,
                            'exp' => 'La marca clasifica el producto. La tienda decide el logo, los colores y la dirección que salen impresos en el comprobante.',
                        ],
                    ],
                ],

                'producto' => [
                    'titulo'   => 'Dar de alta un producto correctamente',
                    'resumen'  => 'Campo por campo, con las decisiones que después son difíciles de deshacer.',
                    'permiso'  => 'productos.crear',
                    'minutos'  => 9,
                    'pantalla' => 'modules/inventario/productos.php',
                    'objetivos' => [
                        'Crear un producto con todos sus datos comerciales y de control.',
                        'Fijar bien el costo, el precio y el stock mínimo.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Los datos de identidad',
                            'd' => 'Lo que define QUÉ es el artículo.',
                            'campos' => [
                                'Nombre'          => 'Como lo busca el cajero, no como viene en la factura del proveedor. Si el vendedor lo llama «crema de manos karité 30ml», ese es el nombre.',
                                'Código interno'  => 'Tu código. Si no pones uno, el sistema genera uno.',
                                'Código de barras'=> 'El que trae el empaque. Es único en todo el sistema: dos productos no pueden compartirlo.',
                                'Categoría, marca y unidad' => 'De los catálogos previos.',
                                'Tienda (marca comercial)'  => 'Opcional. Si se pone, el artículo se vende bajo esa identidad; si se deja vacío, se puede vender desde cualquiera.',
                            ],
                            'ruta' => 'modules/inventario/productos.php',
                        ],
                        [
                            't' => 'Costo y precio',
                            'd' => 'El costo es lo que te cuesta puesto en almacén; el precio es a cuánto lo vendes. El sistema calcula el margen y lo muestra al escribir.',
                            'aviso' => 'El costo no es un dato decorativo: de él salen la utilidad bruta, el inventario valorizado y el informe de artículos que se venden bajo costo. Un costo en cero hace que todo lo que vendas parezca 100% de ganancia.',
                        ],
                        [
                            't' => 'ITBIS',
                            'd' => 'Indica si el artículo lleva impuesto y a qué tasa. Es lo que después determina el ITBIS de cada factura y lo que se declara en el IT-1. Un producto exento mal marcado como gravado hace que el negocio pague impuesto de más.',
                        ],
                        [
                            't' => 'Stock mínimo',
                            'd' => 'El umbral de reposición. Cuando la existencia cae a ese número o por debajo, el producto aparece en rojo en el menú, salta la alerta y entra en el informe de reposición.',
                            'tip' => 'Un mínimo razonable es lo que vendes en el tiempo que tarda el proveedor en traerlo, más un margen. Ponerlo en cero es renunciar a la alerta.',
                        ],
                        [
                            't' => 'Control sanitario (si aplica)',
                            'd' => 'Si el artículo es regulado, se marca como tal y se le registra su número de registro sanitario y su vigencia. Si además hay que controlar lotes, se activa esa opción: a partir de ahí el sistema pedirá lote y vencimiento al recibir mercancía.',
                            'aviso' => 'Activar el control de lote sobre un producto que ya tiene existencia sin lote requiere un conteo o un ajuste para asignarle lote a lo que hay. No lo actives un viernes por la tarde.',
                        ],
                        [
                            't' => 'La imagen',
                            'd' => 'Ayuda al cajero a identificar el artículo de un vistazo en el punto de venta, sobre todo cuando hay varias presentaciones parecidas del mismo producto.',
                        ],
                    ],
                    'errores' => [
                        ['sintoma' => '«El código de barras ya existe»',
                         'causa'   => 'Otro producto lo tiene. El código de barras es único en todo el sistema.',
                         'solucion'=> 'Búscalo en el buscador global: casi siempre es el mismo artículo dado de alta dos veces. Unifica en vez de duplicar.'],
                        ['sintoma' => 'El producto no aparece en el punto de venta',
                         'causa'   => 'Está inactivo, o pertenece a una tienda distinta de la activa, o no tiene existencia en esa sucursal.',
                         'solucion'=> 'Revisa esas tres cosas en ese orden.'],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Creas un producto y le dejas el costo en cero porque «después se ajusta». ¿Qué se rompe?',
                            'ops' => ['Nada, el costo es informativo', 'La utilidad bruta, el inventario valorizado y el margen se calculan mal', 'Solo el reporte de compras', 'El código de barras'],
                            'ok'  => 1,
                            'exp' => 'El costo alimenta la utilidad bruta, el inventario valorizado y la detección de ventas bajo costo. En cero, todo lo vendido parece ganancia pura.',
                        ],
                    ],
                ],

                'barras' => [
                    'titulo'   => 'Códigos de barras y etiquetas',
                    'resumen'  => 'Asignar códigos, generar los internos e imprimir etiquetas que el lector realmente lea.',
                    'permiso'  => 'productos.etiquetas',
                    'minutos'  => 6,
                    'pantalla' => 'modules/inventario/etiquetas.php',
                    'objetivos' => [
                        'Asignar o generar el código de barras de un producto.',
                        'Imprimir etiquetas legibles y saber por qué se imprimen en vectorial.',
                    ],
                    'pasos' => [
                        [
                            't' => 'El código que trae el empaque',
                            'd' => 'Si el artículo viene con su código de fábrica, se captura tal cual — se puede escribir o disparar con la pistola sobre el campo. El sistema valida que un EAN-13 tenga su dígito verificador correcto: si lo rechaza, es que está mal tecleado, porque ningún lector emite uno inválido.',
                        ],
                        [
                            't' => 'Cuando no trae código',
                            'd' => 'El sistema genera uno interno. Usa un rango reservado internacionalmente para circulación restringida, así que nunca va a chocar con el código de un fabricante real.',
                            'ruta' => 'modules/inventario/etiquetas.php',
                        ],
                        [
                            't' => 'Imprimir etiquetas',
                            'd' => 'En Inventario → Etiquetas eliges los productos, cuántas etiquetas de cada uno y el tamaño de la hoja. Se imprime desde el navegador.',
                            'tip' => 'Imprime siempre una hoja de prueba y pásale el lector antes de tirar doscientas. Ahorra papel y ahorra un día de trabajo.',
                        ],
                        [
                            't' => 'Por qué las barras no son una imagen',
                            'd' => 'Se dibujan en vectorial. Una imagen a tamaño de etiqueta sale difuminada y el lector falla; en vectorial las barras salen nítidas a cualquier tamaño.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'El sistema rechaza un código EAN-13 que estás escribiendo. ¿Qué es lo más probable?',
                            'ops' => ['El producto ya existe', 'Está mal tecleado: el dígito verificador no cuadra', 'Falta permiso', 'Ese tipo de código no se acepta'],
                            'ok'  => 1,
                            'exp' => 'La validación solo rechaza un EAN-13 con verificador incorrecto, y eso solo pasa tecleando a mano. Con la pistola no ocurre.',
                        ],
                    ],
                ],

                'stock' => [
                    'titulo'   => 'Leer el stock por sucursal',
                    'resumen'  => 'Dónde está cada cosa, qué está bajo mínimo y qué significa realmente «existencia disponible».',
                    'permiso'  => 'inventario.ver',
                    'minutos'  => 5,
                    'pantalla' => 'modules/inventario/stock.php',
                    'objetivos' => [
                        'Consultar existencias filtrando por local y categoría.',
                        'Identificar quiebres y excesos.',
                    ],
                    'pasos' => [
                        [
                            't' => 'El stock es por sucursal, siempre',
                            'd' => 'No existe «el stock del producto»: existe el stock de ese producto EN ese local. La pantalla muestra el de la sucursal activa. Para ver todos los locales lado a lado, el informe de existencias por tienda hace exactamente eso.',
                            'ruta' => 'modules/inventario/stock.php',
                        ],
                        [
                            't' => 'Las señales',
                            'd' => 'La pantalla marca en color lo que necesita acción: en rojo lo que está en cero o bajo el mínimo, y el contador del menú lateral lleva la cuenta de cuántos artículos están así en la sucursal activa.',
                        ],
                        [
                            't' => 'Cuando el sistema y el estante no coinciden',
                            'd' => 'Pasa, y la respuesta correcta casi nunca es «ajustar el número». Antes de ajustar, revisa por este orden: ¿hay una compra recibida a medias? ¿una transferencia enviada y no recibida? ¿una venta sin registrar? El ajuste es el último recurso, no el primero.',
                            'aviso' => 'Ajustar el número sin buscar la causa esconde el problema y lo repite el mes siguiente.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'En el estante hay 15 unidades y el sistema dice 20. ¿Qué revisas primero?',
                            'ops' => ['Ajusto el stock a 15 y sigo', 'Transferencias enviadas sin recibir, compras a medias y ventas sin registrar', 'Vuelvo a contar mañana', 'Doy de baja 5 unidades'],
                            'ok'  => 1,
                            'exp' => 'Una diferencia casi siempre tiene un documento detrás. Ajustar sin buscar la causa esconde el problema, no lo resuelve.',
                        ],
                    ],
                ],

                'ajustes' => [
                    'titulo'   => 'Ajustar existencias (con nota obligatoria)',
                    'resumen'  => 'El único camino para cambiar una existencia sin un documento detrás — y por qué siempre exige explicación escrita.',
                    'permiso'  => 'inventario.ajustar',
                    'minutos'  => 6,
                    'pantalla' => 'modules/inventario/stock.php',
                    'objetivos' => [
                        'Registrar un ajuste correctamente motivado.',
                        'Saber que todo ajuste sale en un informe que la dirección revisa.',
                    ],
                    'pasos' => [
                        [
                            't' => 'La regla que pidió la dirección',
                            'd' => 'Ninguna existencia baja sin que quede escrito quién lo autorizó y por qué. Textualmente: «si había veinte y ahora hay quince», tiene que haber una nota que lo explique. Por eso el campo de motivo no es opcional.',
                        ],
                        [
                            't' => 'Cómo se registra',
                            'd' => 'Desde el stock, sobre el producto, se elige ajustar. Se indica la cantidad final o la diferencia, según la pantalla, y se escribe el motivo. Queda registrado con tu usuario, la fecha, la sucursal y el costo del movimiento.',
                            'campos' => [
                                'Motivo útil'   => '«Merma por rotura en almacén, 2 frascos, acta del 3/9» — dice qué, cuánto y dónde verificarlo.',
                                'Motivo inútil' => '«Ajuste», «corrección», «diferencia». No explican nada y obligan a preguntar.',
                            ],
                        ],
                        [
                            't' => 'Dónde se revisa',
                            'd' => 'Todo ajuste aparece en el informe «Ajustes y mermas», con su nota, su responsable y lo que costó. Ese informe existe precisamente para responder la pregunta de la dirección.',
                            'aviso' => 'Los ajustes repetidos sobre el mismo producto son la señal clásica de un problema de proceso (recepciones mal hechas, robo, o un producto mal configurado). El informe los saca a la luz.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => '¿Por qué el motivo de un ajuste es obligatorio?',
                            'ops' => ['Por costumbre', 'Porque toda baja de existencia sin venta detrás tiene que quedar explicada y auditable', 'Para llenar el reporte', 'Solo es obligatorio en productos regulados'],
                            'ok'  => 1,
                            'exp' => 'Es una regla de control interno: el informe de ajustes y mermas debe poder responder quién bajó qué y por qué.',
                        ],
                    ],
                ],

                'movimientos' => [
                    'titulo'   => 'El kardex: historia de cada artículo',
                    'resumen'  => 'Todo lo que le pasó a un producto, en orden, con su documento y su responsable.',
                    'permiso'  => 'inventario.ver',
                    'minutos'  => 5,
                    'pantalla' => 'modules/inventario/movimientos.php',
                    'objetivos' => [
                        'Reconstruir por qué la existencia de un artículo es la que es.',
                        'Usar el kardex para resolver diferencias.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Qué es',
                            'd' => 'Un registro cronológico de cada entrada y salida: compras recibidas, ventas, devoluciones, transferencias enviadas y recibidas, ajustes, conteos aplicados y bajas. Cada línea trae el documento que la originó y quién la hizo.',
                            'ruta' => 'modules/inventario/movimientos.php',
                        ],
                        [
                            't' => 'Cómo se usa para investigar',
                            'd' => 'Filtra por producto y por rango de fechas y lee de arriba abajo. La pregunta «¿dónde se fueron 12 unidades?» siempre se responde aquí: o hay una salida que no reconoces, o falta una entrada que creías registrada.',
                            'tip' => 'Empieza por el último día en que el número era correcto y avanza desde ahí. Es más rápido que revisar todo el mes.',
                        ],
                        [
                            't' => 'No se edita',
                            'd' => 'El kardex es histórico: no se corrige una línea vieja. Si algo está mal, se corrige con un movimiento nuevo (una devolución, un ajuste con su nota) que deja el rastro completo de qué pasó y cómo se arregló.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Descubres una salida errónea de hace dos semanas en el kardex. ¿Qué haces?',
                            'ops' => ['Editar esa línea', 'Borrarla', 'Registrar un movimiento nuevo que la corrija, con su nota', 'Pedir que un técnico toque la base de datos'],
                            'ok'  => 2,
                            'exp' => 'El histórico no se reescribe. La corrección es un movimiento nuevo, y así queda el rastro completo del error y de su arreglo.',
                        ],
                    ],
                ],

                'escaner' => [
                    'titulo'   => 'El escáner de almacén',
                    'resumen'  => 'Consultar y mover inventario desde el teléfono, con la cámara o con una pistola.',
                    'permiso'  => 'inventario.ver',
                    'minutos'  => 5,
                    'pantalla' => 'modules/inventario/escaner.php',
                    'objetivos' => [
                        'Usar la cámara del teléfono como lector.',
                        'Saber por qué a veces la cámara no arranca.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Para qué sirve',
                            'd' => 'Es una terminal de almacén: disparas un código y ves al instante qué producto es, su existencia en la sucursal activa, su costo y su precio. Sirve para verificar el estante sin volver a la computadora.',
                            'ruta' => 'modules/inventario/escaner.php',
                        ],
                        [
                            't' => 'Cámara o pistola',
                            'd' => 'En el teléfono usa la cámara. Con una pistola USB conectada a la computadora, funciona igual: el disparo escribe el código y el sistema responde.',
                        ],
                        [
                            't' => 'La cámara exige HTTPS',
                            'd' => 'Los navegadores solo dan acceso a la cámara en conexiones seguras. Si abres el sistema por una dirección sin candado, la cámara no arranca y el navegador ni siquiera pregunta. No es un fallo del sistema.',
                            'aviso' => 'Si la cámara no aparece: verifica que la dirección empiece por https, y que al preguntar el navegador le hayas dado permiso a la cámara. Si dijiste que no una vez, hay que volver a habilitarlo desde los ajustes del navegador para ese sitio.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Abres el escáner en el teléfono y la cámara no enciende ni pide permiso. ¿Causa más probable?',
                            'ops' => ['El teléfono no tiene cámara', 'Estás entrando por http en vez de https', 'Falta permiso de inventario', 'El producto no tiene código'],
                            'ok'  => 1,
                            'exp' => 'Sin conexión segura el navegador bloquea la cámara en seco, sin preguntar. Es una regla del navegador, no del sistema.',
                        ],
                    ],
                ],

                'conteo' => [
                    'titulo'   => 'Conteo físico de inventario',
                    'resumen'  => 'El proceso completo: abrir, contar, revisar diferencias y aplicar el ajuste al stock.',
                    'permiso'  => 'conteos.ver',
                    'minutos'  => 10,
                    'pantalla' => 'modules/inventario/conteos.php',
                    'objetivos' => [
                        'Recorrer las cuatro fases de un conteo.',
                        'Entender por qué capturar y aplicar son permisos distintos.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Las cuatro fases',
                            'd' => 'Un conteo no es escribir números: es un procedimiento con control.',
                            'campos' => [
                                '1. Abrir'    => 'Se define qué se va a contar (todo el local, una categoría, un pasillo) y queda abierto un conteo con su fecha y su responsable. Requiere `conteos.crear`.',
                                '2. Capturar' => 'Se recorre el físico y se escriben las cantidades contadas, a mano o con el lector. Requiere `conteos.contar`. Se puede hacer entre varias personas y en varios ratos.',
                                '3. Revisar'  => 'El sistema muestra las diferencias contra el sistema, en unidades y en dinero, ordenadas por lo que más impacta.',
                                '4. Aplicar'  => 'Se ajusta el stock a lo contado. Requiere `conteos.aplicar` y una justificación escrita. Aquí es donde el inventario cambia de verdad.',
                            ],
                            'ruta' => 'modules/inventario/conteos.php',
                        ],
                        [
                            't' => 'Por qué contar y aplicar son permisos distintos',
                            'd' => 'Contar lo puede hacer cualquiera del equipo. Aplicar reescribe la existencia del local y genera un ajuste con impacto en el costo del inventario: eso lo firma un responsable. Es la misma lógica que separa a quien pide un traslado de quien lo autoriza.',
                        ],
                        [
                            't' => 'Antes de contar',
                            'd' => 'Cuanto más quieto esté el inventario, más limpio sale el conteo.',
                            'lista' => [
                                'Termina de recibir las compras que estén a medias.',
                                'Cierra las transferencias en tránsito o anótalas aparte.',
                                'Cuenta fuera del horario de venta, o congela el movimiento del área que estés contando.',
                            ],
                        ],
                        [
                            't' => 'Al aplicar',
                            'd' => 'Cada diferencia se convierte en un movimiento de inventario con su justificación, y todo eso sale en el informe de ajustes y mermas. Un conteo aplicado no se deshace: si algo quedó mal, se corrige con un ajuste nuevo.',
                            'aviso' => 'No apliques un conteo con diferencias enormes sin investigarlas antes. Una diferencia de 200 unidades casi nunca es merma: suele ser una recepción sin registrar o un producto duplicado en el catálogo.',
                        ],
                    ],
                    'errores' => [
                        ['sintoma' => 'Todas las líneas dan diferencia negativa',
                         'causa'   => 'Casi siempre se contó una sola ubicación de un producto que está en dos sitios, o se contó con el local vendiendo.',
                         'solucion'=> 'Recuenta el área antes de aplicar. Cancelar el conteo y repetirlo es preferible a aplicar un conteo dudoso.'],
                    ],
                    'quiz' => [
                        [
                            'p'   => '¿En qué momento del conteo cambia realmente el inventario?',
                            'ops' => ['Al abrir el conteo', 'Al capturar las cantidades', 'Al aplicar', 'Al exportar el resultado'],
                            'ok'  => 2,
                            'exp' => 'Capturar es registrar lo contado. Aplicar es lo que reescribe la existencia, y por eso lleva permiso propio y justificación.',
                        ],
                    ],
                ],

                'lotes' => [
                    'titulo'   => 'Lotes y vencimientos',
                    'resumen'  => 'Controlar mercancía por lote, vigilar caducidades y saber a quién se le vendió cada lote.',
                    'permiso'  => 'sanidad.ver',
                    'minutos'  => 7,
                    'pantalla' => 'modules/inventario/lotes.php',
                    'objetivos' => [
                        'Registrar lotes al recibir mercancía.',
                        'Leer el semáforo de vencimientos y actuar a tiempo.',
                    ],
                    'pasos' => [
                        [
                            't' => 'El control se activa producto a producto',
                            'd' => 'No es global. Un producto sin control de lote se comporta exactamente igual que siempre. Solo los marcados con control de lote piden número de lote y fecha de vencimiento al recibir.',
                            'ruta' => 'modules/inventario/lotes.php',
                        ],
                        [
                            't' => 'Registrar el lote',
                            'd' => 'Al recibir una compra de un producto con control de lote, el sistema pide el número de lote y su vencimiento. Ese dato viaja con la mercancía: cuando se vende, queda constancia de qué lote salió y en qué factura.',
                        ],
                        [
                            't' => 'El semáforo',
                            'd' => 'La pantalla de lotes ordena por urgencia: vencido, por vencer dentro del plazo de aviso, y vigente. También muestra el dinero inmovilizado en lo que está por vencer, que es la cifra que mueve a actuar.',
                            'tip' => 'Lo que está por vencer todavía se puede vender, rotar hacia el local que más lo mueve o promocionar. Vencido ya no: eso solo se da de baja y es pérdida.',
                        ],
                        [
                            't' => 'Trazabilidad: el retiro del mercado',
                            'd' => 'Si un fabricante retira un lote, el informe de trazabilidad responde en un minuto de qué proveedor entró ese lote y a qué clientes salió, con sus facturas. Eso es lo que se entrega en una inspección.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Un producto NO tiene activado el control de lote. ¿Qué pasa al recibirlo?',
                            'ops' => ['El sistema pide lote igual', 'Se comporta como siempre, sin pedir lote', 'No se puede recibir', 'Se le asigna un lote automático'],
                            'ok'  => 1,
                            'exp' => 'El control es producto a producto, precisamente para no entorpecer el resto del catálogo.',
                        ],
                    ],
                ],
            ],
        ],

        /* =====================================================================
         *  4. COMPRAS, PROVEEDORES Y PAGOS
         * ===================================================================== */
        'compras' => [
            'titulo'      => 'Compras, proveedores y cuentas por pagar',
            'descripcion' => 'Comprar, recibir, deber y pagar. Incluye compras en dólares y el costeo real de una importación.',
            'icono'       => 'truck',
            'color'       => 'indigo',
            'nivel'       => 'Intermedio',
            'permiso'     => ['compras.ver', 'proveedores.ver', 'cxp.ver', 'liquidaciones.ver'],
            'para_quien'  => 'Compradores, almacén y administración.',
            'lecciones'   => [

                'proveedores' => [
                    'titulo'   => 'Proveedores',
                    'resumen'  => 'La ficha del proveedor y por qué sus datos fiscales importan más de lo que parece.',
                    'permiso'  => 'proveedores.ver',
                    'minutos'  => 5,
                    'pantalla' => 'modules/inventario/proveedores.php',
                    'objetivos' => [
                        'Crear una ficha de proveedor completa.',
                        'Entender la relación entre el RNC del proveedor y el reporte 606.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Los datos que sí importan',
                            'd' => 'Nombre comercial, RNC, contacto, teléfono, correo y condiciones de pago. El RNC no es opcional en la práctica: es lo que la DGII espera ver en el reporte 606 de compras, y sin él la declaración sale incompleta.',
                            'ruta' => 'modules/inventario/proveedores.php',
                        ],
                        [
                            't' => 'Condiciones de pago',
                            'd' => 'Definen si se compra de contado o a crédito y a cuántos días. Eso alimenta el vencimiento de cada factura en Cuentas por Pagar y las alertas de pagos próximos.',
                        ],
                        [
                            't' => 'Ficha sanitaria',
                            'd' => 'Si el proveedor surte productos regulados, su licencia sanitaria y su vigencia se registran aquí. Es lo que pide una inspección junto con los registros de los productos.',
                            'tip' => 'Un proveedor duplicado parte su historial en dos y arruina el análisis de a quién se le compra más. Antes de crear uno, búscalo.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => '¿Por qué el RNC del proveedor no es un dato opcional?',
                            'ops' => ['Por orden interno', 'Porque el reporte 606 de la DGII lo exige', 'Para el correo', 'Para calcular el ITBIS de venta'],
                            'ok'  => 1,
                            'exp' => 'El 606 reporta las compras con el RNC de cada proveedor. Sin él la declaración queda incompleta.',
                        ],
                    ],
                ],

                'orden' => [
                    'titulo'   => 'Registrar una compra',
                    'resumen'  => 'De la factura del proveedor al inventario, con su NCF de compra y su condición de pago.',
                    'permiso'  => 'compras.crear',
                    'minutos'  => 9,
                    'pantalla' => 'modules/inventario/compras.php',
                    'objetivos' => [
                        'Capturar una compra completa con sus impuestos.',
                        'Entender qué mueve una compra de contado frente a una a crédito.',
                    ],
                    'pasos' => [
                        [
                            't' => 'La cabecera',
                            'd' => 'Proveedor, fecha, número de factura del proveedor, su NCF de compra, la sucursal que recibe y la condición (contado o crédito).',
                            'ruta' => 'modules/inventario/compras.php',
                            'aviso' => 'La sucursal de la compra es la que recibe la mercancía. Equivocarla mete el inventario en el local que no es, y arreglarlo después obliga a una transferencia.',
                        ],
                        [
                            't' => 'Las líneas',
                            'd' => 'Producto, cantidad, costo unitario y su ITBIS. Si el producto lleva control de lote, aquí se captura el lote y su vencimiento.',
                            'tip' => 'El costo que escribas aquí es el que después se usa para valorar el inventario y calcular el margen de cada venta. Cópialo de la factura, no lo redondees.',
                        ],
                        [
                            't' => 'Contado o crédito: la diferencia contable',
                            'd' => 'Esta es la parte que más se equivoca, y afecta a los reportes financieros:',
                            'campos' => [
                                'Contado' => 'Sale el dinero hoy. Se registra el movimiento de efectivo contra la cuenta que corresponda.',
                                'Crédito' => 'NO sale dinero hoy. Se genera una cuenta por pagar con su vencimiento. El movimiento de efectivo ocurre el día que se paga, no el día de la compra.',
                            ],
                            'aviso' => 'Comprar mercancía nunca es un «gasto» operativo: es inventario. Cuando esa mercancía se vende, se convierte en costo de lo vendido. Por eso los reportes de gastos no suman las compras.',
                        ],
                        [
                            't' => 'Al guardar',
                            'd' => 'La mercancía entra al stock de la sucursal indicada, se actualiza el costo del producto, queda el movimiento en el kardex y —si fue a crédito— nace la cuenta por pagar.',
                        ],
                        [
                            't' => 'Recepción parcial',
                            'd' => 'Cuando el proveedor manda menos de lo facturado, se recibe lo que llegó y la compra queda parcialmente recibida. Lo pendiente sigue visible hasta que llegue o se cierre. Así el inventario refleja lo que hay en el estante y no lo que decía el papel.',
                        ],
                    ],
                    'errores' => [
                        ['sintoma' => 'El inventario subió en el local equivocado',
                         'causa'   => 'La compra se registró con otra sucursal.',
                         'solucion'=> 'Anula la compra y vuelve a registrarla en la sucursal correcta, o mueve la mercancía con una transferencia si ya hubo movimiento.'],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Registras una compra de mercancía a crédito por RD$ 100,000. ¿Qué aparece HOY en el flujo de efectivo?',
                            'ops' => ['Una salida de RD$ 100,000', 'Nada: el efectivo se mueve el día del pago', 'La mitad', 'Un gasto operativo de RD$ 100,000'],
                            'ok'  => 1,
                            'exp' => 'Una compra a crédito crea una deuda, no una salida de dinero. Y aunque fuera de contado no sería un «gasto»: es inventario.',
                        ],
                    ],
                ],

                'cxp' => [
                    'titulo'   => 'Cuentas por pagar',
                    'resumen'  => 'Qué se debe, a quién, desde cuándo, y cómo registrar un pago.',
                    'permiso'  => 'cxp.ver',
                    'minutos'  => 6,
                    'pantalla' => 'modules/inventario/cuentas_pagar.php',
                    'objetivos' => [
                        'Leer la antigüedad de la deuda.',
                        'Registrar pagos totales y parciales.',
                    ],
                    'pasos' => [
                        [
                            't' => 'La pantalla',
                            'd' => 'Lista cada factura de compra a crédito con su saldo, su vencimiento y los días transcurridos. Se agrupa por proveedor y se ordena por urgencia.',
                            'ruta' => 'modules/inventario/cuentas_pagar.php',
                        ],
                        [
                            't' => 'Registrar un pago',
                            'd' => 'Requiere `cxp.pagar`. Se elige la factura, el monto (total o parcial), la cuenta de la que sale el dinero y la referencia (número de cheque, transferencia). El saldo baja y el movimiento de efectivo se registra en ESE momento.',
                            'aviso' => 'El pago sale de una cuenta concreta. Si eliges la cuenta equivocada, la conciliación bancaria de ese mes no va a cuadrar y encontrarlo después cuesta el doble.',
                        ],
                        [
                            't' => 'Pagos parciales',
                            'd' => 'Se pueden registrar tantos abonos como haga falta. La factura queda pendiente con su saldo restante hasta cubrirla del todo.',
                        ],
                        [
                            't' => 'Deudas en dólares',
                            'd' => 'Si la compra se pactó en dólares, la deuda vive en dólares y el pago se registra con la tasa del día del pago. Si la tasa cambió, se genera una diferencia cambiaria — y eso es normal, no es «pagar de más».',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Debes US$ 1,000 comprados a tasa 58 y pagas cuando la tasa está en 60. ¿Qué ocurre?',
                            'ops' => ['Sigues debiendo la diferencia', 'La deuda se salda y se registra una diferencia cambiaria', 'Hay que renegociar la factura', 'El sistema rechaza el pago'],
                            'ok'  => 1,
                            'exp' => 'Pagar los mismos dólares salda la deuda. La diferencia en pesos es una diferencia cambiaria: es un gasto financiero, no un saldo pendiente.',
                        ],
                    ],
                ],

                'monedas' => [
                    'titulo'   => 'Dólares, tasa de cambio y diferencia cambiaria',
                    'resumen'  => 'La regla que sostiene toda la contabilidad: los libros viven en pesos.',
                    'permiso'  => 'monedas.gestionar',
                    'minutos'  => 6,
                    'pantalla' => 'modules/admin/monedas.php',
                    'objetivos' => [
                        'Actualizar la tasa de cambio.',
                        'Entender por qué un documento pasado nunca se reconvierte.',
                    ],
                    'pasos' => [
                        [
                            't' => 'La contabilidad vive en pesos',
                            'd' => 'Un documento pactado en dólares guarda TRES cosas: el monto en dólares, la tasa del día y el equivalente en pesos. Ese equivalente queda congelado.',
                            'ruta' => 'modules/admin/monedas.php',
                        ],
                        [
                            't' => 'Por qué no se reconvierte al vuelo',
                            'd' => 'Si un reporte convirtiera los dólares a la tasa de hoy, la utilidad del año pasado cambiaría cada mañana con el dólar. Un cierre contable dejaría de ser un cierre. Por eso el pasado se lee con la tasa que tenía.',
                            'aviso' => 'Nunca «actualices» a mano la tasa de un documento viejo para que cuadre con hoy. Estarías reescribiendo un resultado ya cerrado.',
                        ],
                        [
                            't' => 'Actualizar la tasa',
                            'd' => 'En Administración → Monedas y tasa se registra la tasa vigente. Desde ese momento los documentos nuevos la usan. Los anteriores conservan la suya.',
                            'tip' => 'Actualiza la tasa el día que cambia, no a fin de mes. Registrar quince compras con una tasa vieja obliga a corregirlas una a una.',
                        ],
                        [
                            't' => 'La diferencia cambiaria',
                            'd' => 'Aparece cuando se paga una deuda en dólares a una tasa distinta de la de la compra. Es un resultado financiero: gasto si la tasa subió, ingreso si bajó. El sistema la calcula y la registra por su cuenta.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'La tasa subió mucho este mes. ¿Qué pasa con el estado de resultados del año pasado?',
                            'ops' => ['Se recalcula automáticamente', 'No cambia: cada documento conserva la tasa de su fecha', 'Hay que actualizarlo a mano', 'Se recalcula solo si hay deudas abiertas'],
                            'ok'  => 1,
                            'exp' => 'Si el pasado se reconvirtiera, un cierre contable cambiaría cada día. Cada documento se lee con su propia tasa.',
                        ],
                    ],
                ],

                'liquidacion' => [
                    'titulo'   => 'Liquidación de importaciones',
                    'resumen'  => 'Cuánto cuesta de verdad la mercancía puesta en almacén: flete, aduana, seguro y todo lo demás repartido sobre cada artículo.',
                    'permiso'  => 'liquidaciones.ver',
                    'minutos'  => 10,
                    'pantalla' => 'modules/inventario/liquidaciones.php',
                    'objetivos' => [
                        'Armar una liquidación con sus gastos asociados.',
                        'Entender qué cambia en el sistema cuando se aplica.',
                    ],
                    'pasos' => [
                        [
                            't' => 'El problema',
                            'd' => 'La factura del proveedor extranjero dice US$ 10 por unidad. Pero esa unidad, puesta en el almacén, costó además flete internacional, seguro, arancel, ITBIS de aduana, agente aduanal y transporte local. Vender con el costo de la factura es venderse por debajo del costo real sin darse cuenta.',
                            'ruta' => 'modules/inventario/liquidaciones.php',
                        ],
                        [
                            't' => 'Armar el borrador',
                            'd' => 'Se registra la mercancía que viene (con sus cantidades y su costo de factura) y todos los gastos del embarque, cada uno con su tipo. El sistema los reparte entre los artículos según el criterio configurado (por valor, por peso, por unidades) y calcula el costo final puesto en almacén.',
                        ],
                        [
                            't' => 'Aplicar: aquí cambia todo',
                            'd' => 'Aplicar la liquidación hace dos cosas grandes a la vez, y por eso lleva un permiso propio (`liquidaciones.aplicar`):',
                            'lista' => [
                                'Entra la mercancía al inventario de la sucursal indicada.',
                                'Reescribe el costo de esos productos en el catálogo con el costo real de importación.',
                            ],
                            'aviso' => 'Ese segundo efecto es el importante: a partir de ahí, el margen de cada venta y el inventario valorizado usan el costo verdadero. Aplicar una liquidación con gastos incompletos deja el costo bajo y hace que el negocio parezca más rentable de lo que es.',
                        ],
                        [
                            't' => 'Antes de aplicar, revisa',
                            'd' => 'Verifica que estén todos los gastos del embarque y que las cantidades coincidan con lo que físicamente llegó. Una liquidación aplicada se puede anular, pero anularla después de haber vendido de ese lote deja el margen de esas ventas calculado con el costo viejo.',
                        ],
                        [
                            't' => 'Mercancía en camino',
                            'd' => 'Mientras la liquidación está en borrador, esa mercancía se ve como «en camino» en el panel de Dirección. Es lo que permite planificar sin haberla recibido todavía.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => '¿Qué hace «aplicar» una liquidación además de entrar la mercancía?',
                            'ops' => ['Nada más', 'Reescribe el costo de esos productos en el catálogo', 'Genera la factura al cliente', 'Cierra el mes contable'],
                            'ok'  => 1,
                            'exp' => 'Ese es el punto del módulo: fijar el costo real puesto en almacén, del que después salen el margen y el inventario valorizado.',
                        ],
                    ],
                ],
            ],
        ],

        /* =====================================================================
         *  5. TRASLADOS ENTRE TIENDAS
         * ===================================================================== */
        'traslados' => [
            'titulo'      => 'Traslados entre tiendas',
            'descripcion' => 'Mover mercancía de un local a otro con autorización, motivo escrito y rastro completo.',
            'icono'       => 'transfer',
            'color'       => 'cyan',
            'nivel'       => 'Intermedio',
            'permiso'     => ['transferencias.ver', 'transferencias.aprobar', 'transferencias.recibir'],
            'para_quien'  => 'Encargados de local, almacén y quien autoriza las salidas.',
            'lecciones'   => [

                'flujo' => [
                    'titulo'   => 'Los cuatro estados de un traslado',
                    'resumen'  => 'Borrador, pendiente, enviada y recibida — y el único momento en que el stock se mueve.',
                    'permiso'  => 'transferencias.ver',
                    'minutos'  => 6,
                    'pantalla' => 'modules/inventario/transferencias.php',
                    'objetivos' => [
                        'Nombrar los cuatro estados y qué ocurre en cada paso.',
                        'Saber exactamente cuándo sale y cuándo entra la mercancía.',
                    ],
                    'pasos' => [
                        [
                            't' => 'El circuito',
                            'd' => 'borrador → (solicitar) → pendiente → (aprobar) → enviada → (recibir) → recibida. Desde pendiente también se puede devolver a borrador con un motivo, o rechazar.',
                            'ruta' => 'modules/inventario/transferencias.php',
                        ],
                        [
                            't' => 'Dónde se mueve el stock — y solo ahí',
                            'd' => 'Es la pregunta que más confusión causa:',
                            'campos' => [
                                'Solicitar'  => 'No mueve nada. Solo pide permiso. Exige motivo escrito, al menos un producto y existencia suficiente en el origen.',
                                'Aprobar'    => 'AQUÍ SALE el stock del local de origen. La mercancía queda en tránsito.',
                                'Recibir'    => 'AQUÍ ENTRA el stock al local de destino.',
                                'Entre una y otra' => 'La mercancía está en tránsito: no está en ningún local. Es correcto y es lo que permite detectar lo que se perdió por el camino.',
                            ],
                        ],
                        [
                            't' => 'Por qué antes esto era un problema',
                            'd' => 'Antes, crear y enviar era un solo paso y la mercancía salía sin que nadie más se enterara. La dirección lo pidió por escrito: nada sale de un local sin una aprobación y una nota que lo explique. De ahí los cuatro estados.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Una transferencia está en estado «enviada». ¿Dónde está el inventario?',
                            'ops' => ['En el origen', 'En el destino', 'En tránsito: ya salió del origen y aún no entra al destino', 'Duplicado en los dos'],
                            'ok'  => 2,
                            'exp' => 'El stock sale al aprobar y entra al recibir. En medio está en tránsito, que es justo lo que permite detectar faltantes de camino.',
                        ],
                    ],
                ],

                'solicitar' => [
                    'titulo'   => 'Solicitar un traslado',
                    'resumen'  => 'Armar la solicitud y mandarla a aprobación con un motivo que sirva.',
                    'permiso'  => 'transferencias.crear',
                    'minutos'  => 5,
                    'pantalla' => 'modules/inventario/transferencias.php',
                    'objetivos' => [
                        'Crear un borrador y solicitarlo correctamente.',
                        'Escribir motivos que el aprobador pueda evaluar.',
                    ],
                    'pasos' => [
                        [
                            't' => 'El borrador',
                            'd' => 'Elige el local de origen y el de destino, agrega los productos con sus cantidades y guarda. Mientras sea borrador se puede editar y eliminar libremente: todavía no ha pedido nada a nadie.',
                        ],
                        [
                            't' => 'Solicitar',
                            'd' => 'Al solicitar, el sistema exige un motivo escrito, verifica que haya al menos un producto y comprueba que el origen tenga existencia suficiente. Pasa a pendiente y le aparece a quien aprueba.',
                        ],
                        [
                            't' => 'Motivos que sirven',
                            'd' => 'El aprobador tiene que poder decidir sin llamarte por teléfono.',
                            'campos' => [
                                'Sirve'    => '«Sucursal Norte lleva 6 días en cero de este artículo y aquí hay 40 con rotación baja.»',
                                'No sirve' => '«Traslado.» / «Lo pidieron.» / «Falta allá.»',
                            ],
                            'tip' => 'Un motivo concreto se aprueba el mismo día. Uno vago se queda pendiente hasta que alguien tenga tiempo de preguntar.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Solicitas un traslado de 10 unidades pero en el origen solo hay 6. ¿Qué pasa?',
                            'ops' => ['Se envía y el stock queda negativo', 'El sistema no deja solicitar por falta de existencia', 'Se envía y se completa después', 'Se aprueba automáticamente'],
                            'ok'  => 1,
                            'exp' => 'La solicitud verifica existencia suficiente en el origen antes de pasar a pendiente.',
                        ],
                    ],
                ],

                'aprobar' => [
                    'titulo'   => 'Aprobar o rechazar una salida',
                    'resumen'  => 'La firma que de verdad saca la mercancía del local. Qué mirar antes de darla.',
                    'permiso'  => 'transferencias.aprobar',
                    'minutos'  => 6,
                    'pantalla' => 'modules/inventario/aprobaciones.php',
                    'objetivos' => [
                        'Evaluar una solicitud con criterio.',
                        'Usar el rechazo y la devolución a borrador correctamente.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Tu pantalla es Autorizaciones',
                            'd' => 'Quien aprueba no entra al listado general: entra a Inventario → Autorizaciones, donde están las solicitudes esperando su firma. Requiere `transferencias.aprobar`.',
                            'ruta' => 'modules/inventario/aprobaciones.php',
                        ],
                        [
                            't' => 'Qué revisar antes de aprobar',
                            'd' => 'Cuatro preguntas, en este orden:',
                            'lista' => [
                                '¿El motivo justifica el movimiento, o es un «lo pidieron»?',
                                '¿El origen se queda sin existencia para su propia venta?',
                                '¿La cantidad es la que hace falta, o es «por si acaso»?',
                                '¿Hay ya otro traslado en tránsito hacia ese mismo destino con lo mismo?',
                            ],
                            'aviso' => 'Al aprobar, la mercancía SALE del origen inmediatamente. Aprobar por inercia es sacar mercancía sin control, exactamente lo que este circuito vino a evitar.',
                        ],
                        [
                            't' => 'Rechazar o devolver a borrador',
                            'd' => 'Son cosas distintas. Rechazar cierra la solicitud (no procede). Devolver a borrador se la regresa a quien la pidió para que la corrija — por ejemplo, para bajar la cantidad o escribir un motivo mejor. Las dos piden explicación.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'La solicitud está bien pero pide 50 unidades y con 20 basta. ¿Qué haces?',
                            'ops' => ['Aprobar y que devuelvan el resto', 'Rechazar', 'Devolverla a borrador con el motivo, para que la corrijan', 'Aprobar y avisar por teléfono'],
                            'ok'  => 2,
                            'exp' => 'Devolver a borrador permite corregir sin cerrar la solicitud. Aprobar de más saca mercancía que después hay que traer de vuelta con otro traslado.',
                        ],
                    ],
                ],

                'recibir' => [
                    'titulo'   => 'Recibir mercancía trasladada',
                    'resumen'  => 'Contar contra el documento y qué hacer cuando falta algo.',
                    'permiso'  => 'transferencias.recibir',
                    'minutos'  => 5,
                    'pantalla' => 'modules/inventario/transferencias.php',
                    'objetivos' => [
                        'Recibir correctamente y cerrar el circuito.',
                        'Reaccionar ante una diferencia entre lo enviado y lo llegado.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Contar contra el documento',
                            'd' => 'Antes de dar por recibida la transferencia, cuenta físicamente lo que llegó y compáralo con el detalle. Recibir es una firma: dice «esto llegó completo».',
                        ],
                        [
                            't' => 'Al recibir',
                            'd' => 'La mercancía entra al stock del local de destino, se cierra el circuito y queda el movimiento en el kardex de ambos locales.',
                        ],
                        [
                            't' => 'Si falta algo',
                            'd' => 'No lo recibas como si estuviera completo «para no complicar». Registra lo que realmente llegó y avisa. Una transferencia recibida de más tapa una pérdida real y hace que el faltante aparezca semanas después, sin forma de saber dónde ocurrió.',
                            'aviso' => 'Lo que queda en tránsito sin recibir es visible en el informe de movimiento entre tiendas. Ese informe existe para que nada se quede a medio camino en silencio.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Llegan 18 de 20 unidades enviadas. ¿Qué haces?',
                            'ops' => ['Recibir las 20 y ajustar después', 'Recibir lo que llegó realmente y reportar la diferencia', 'No recibir nada', 'Devolver el traslado completo'],
                            'ok'  => 1,
                            'exp' => 'Recibir de más tapa la pérdida y la vuelve imposible de rastrear. Se recibe lo real y se reporta la diferencia mientras se puede investigar.',
                        ],
                    ],
                ],
            ],
        ],

        /* =====================================================================
         *  6. CLIENTES, CRÉDITO Y CRM
         * ===================================================================== */
        'clientes' => [
            'titulo'      => 'Clientes, crédito y CRM',
            'descripcion' => 'La ficha del cliente, la cuenta por cobrar y el seguimiento comercial: oportunidades, contactos y tareas.',
            'icono'       => 'users',
            'color'       => 'violet',
            'nivel'       => 'Intermedio',
            'permiso'     => ['clientes.ver', 'crm.ver'],
            'para_quien'  => 'Vendedores, cobranzas y quien lleva la relación comercial.',
            'lecciones'   => [

                'ficha' => [
                    'titulo'   => 'La ficha del cliente',
                    'resumen'  => 'Datos, condiciones de crédito y todo lo que el sistema sabe de esa persona en una sola pantalla.',
                    'permiso'  => 'clientes.ver',
                    'minutos'  => 6,
                    'pantalla' => 'modules/pos/clientes.php',
                    'objetivos' => [
                        'Crear un cliente con sus datos fiscales correctos.',
                        'Configurar límite de crédito y condiciones.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Datos fiscales',
                            'd' => 'Nombre o razón social, RNC o cédula, dirección, teléfono y correo. El RNC es lo que permite emitirle un comprobante con valor fiscal completo, y es lo que la DGII espera ver en el reporte 607 de ventas.',
                            'ruta' => 'modules/pos/clientes.php',
                        ],
                        [
                            't' => 'Crédito',
                            'd' => 'Si al cliente se le vende a crédito, se le fija un límite y unas condiciones (días de plazo). El punto de venta respeta ese límite: cuando el saldo lo alcanza, no deja seguir vendiendo a crédito.',
                            'aviso' => 'El límite de crédito es una decisión de negocio, no un trámite. Subirlo para poder cerrar una venta de hoy es la forma más común de que una cuenta se vuelva incobrable.',
                        ],
                        [
                            't' => 'La ficha 360°',
                            'd' => 'Desde el CRM, la ficha del cliente reúne todo en una pantalla: sus compras, su saldo, sus oportunidades abiertas, las últimas llamadas y visitas, y las tareas pendientes con él. Es lo que hay que mirar antes de llamarlo.',
                            'ruta' => 'modules/crm/cliente.php',
                        ],
                        [
                            't' => 'Correo y marketing',
                            'd' => 'El correo del cliente es lo que permite incluirlo en campañas y automatizaciones. Un cliente sin correo simplemente no recibe nada: no rompe la campaña, se queda fuera.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Un cliente llegó a su límite de crédito y quiere llevar más mercancía. ¿Qué corresponde?',
                            'ops' => ['Subir el límite en el momento', 'Cobrarle de contado o gestionar el aumento como decisión de negocio', 'Vender igual, el límite es informativo', 'Crear otro cliente con el mismo nombre'],
                            'ok'  => 1,
                            'exp' => 'El límite existe para frenar exactamente esta situación. Subirlo en el mostrador para cerrar la venta de hoy es como nacen las cuentas incobrables.',
                        ],
                    ],
                ],

                'cxc' => [
                    'titulo'   => 'Cuentas por cobrar y abonos',
                    'resumen'  => 'Quién debe, desde cuándo, y cómo se registra un abono.',
                    'permiso'  => 'clientes.ver',
                    'minutos'  => 7,
                    'pantalla' => 'modules/pos/cuentas_cobrar.php',
                    'objetivos' => [
                        'Leer la antigüedad de saldos.',
                        'Registrar abonos totales y parciales.',
                    ],
                    'pasos' => [
                        [
                            't' => 'De dónde sale la deuda',
                            'd' => 'De las ventas cobradas con un método marcado como crédito. Esa venta no entra dinero: suma al balance del cliente y aparece aquí.',
                            'ruta' => 'modules/pos/cuentas_cobrar.php',
                        ],
                        [
                            't' => 'La antigüedad de saldos',
                            'd' => 'El informe agrupa lo que se debe por tramos: 0-30, 31-60, 61-90 y más de 90 días. Es la herramienta de cobranza: lo que pasa de 90 días rara vez se cobra solo.',
                            'tip' => 'Llama cuando la deuda entra en 31-60, no cuando llega a 90. La probabilidad de cobrar cae con cada tramo.',
                        ],
                        [
                            't' => 'Registrar un abono',
                            'd' => 'Se elige el cliente, el monto, la forma de pago y la cuenta que recibe el dinero. El saldo del cliente baja y el ingreso de efectivo queda registrado con esa fecha.',
                            'aviso' => 'Un abono cobrado en efectivo en el mostrador entra a la caja del turno. Si se registra en el sistema pero el dinero no entra a la gaveta, el cierre va a dar sobrante y nadie sabrá por qué.',
                        ],
                        [
                            't' => 'El abono no es un ingreso nuevo',
                            'd' => 'La venta ya se contabilizó como ingreso el día que se hizo. El abono solo convierte una deuda en efectivo. Por eso los reportes de ingresos no lo suman otra vez: si lo hicieran, la misma venta contaría dos veces.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Un cliente abona RD$ 5,000 de una venta a crédito de la semana pasada. ¿Cómo entra en los reportes?',
                            'ops' => ['Como un ingreso nuevo de RD$ 5,000', 'Como entrada de efectivo, pero NO como ingreso: la venta ya se contabilizó', 'No entra en ningún reporte', 'Como una venta adicional'],
                            'ok'  => 1,
                            'exp' => 'La venta ya fue ingreso el día que se hizo. Contar el abono como ingreso duplicaría la misma operación.',
                        ],
                    ],
                ],

                'embudo' => [
                    'titulo'   => 'El embudo de ventas',
                    'resumen'  => 'Oportunidades por etapa: qué hay en juego, qué se está ganando y qué se está perdiendo.',
                    'permiso'  => 'crm.ver',
                    'minutos'  => 7,
                    'pantalla' => 'modules/crm/index.php',
                    'objetivos' => [
                        'Crear y mover oportunidades por el embudo.',
                        'Leer el tablero como indicador comercial.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Qué es una oportunidad',
                            'd' => 'Una venta posible que todavía no ocurrió: un cliente interesado, una cotización enviada, una negociación abierta. Tiene un cliente, un valor estimado, una etapa y un responsable.',
                            'ruta' => 'modules/crm/index.php',
                        ],
                        [
                            't' => 'Las etapas',
                            'd' => 'La oportunidad avanza por etapas hasta ganarse o perderse. Mover de etapa requiere el permiso `crm.avanzar`, igual que marcarla como ganada o perdida.',
                            'tip' => 'Registrar por qué se perdió es más valioso que registrar las ganadas. El patrón de las pérdidas (precio, plazo, competencia, falta de existencia) es lo que dice qué arreglar.',
                        ],
                        [
                            't' => 'Cómo se lee el tablero',
                            'd' => 'Tres lecturas rápidas: cuánto dinero hay en juego por etapa, cuánto tiempo lleva parada cada oportunidad y qué proporción se está ganando. Una oportunidad que lleva semanas en la misma etapa normalmente ya se perdió y nadie la ha cerrado.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => '¿Cuál es el dato más útil de una oportunidad perdida?',
                            'ops' => ['Su valor estimado', 'El motivo de la pérdida', 'La fecha de creación', 'El vendedor'],
                            'ok'  => 1,
                            'exp' => 'El patrón de motivos —precio, plazo, competencia, falta de existencia— es lo único que permite corregir algo.',
                        ],
                    ],
                ],

                'seguimiento' => [
                    'titulo'   => 'Interacciones y tareas',
                    'resumen'  => 'Dejar registro de cada llamada y visita, y no perder ningún compromiso.',
                    'permiso'  => 'crm.ver',
                    'minutos'  => 6,
                    'pantalla' => 'modules/crm/interacciones.php',
                    'objetivos' => [
                        'Registrar una interacción útil.',
                        'Manejar la lista de tareas y seguimientos.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Registrar la interacción',
                            'd' => 'Cada llamada, visita, correo o mensaje se registra con su fecha, su tipo y una nota de lo que se habló. Requiere `crm.crear`.',
                            'ruta' => 'modules/crm/interacciones.php',
                        ],
                        [
                            't' => 'Por qué vale la pena escribirlo',
                            'd' => 'Porque el cliente no es de una persona, es de la empresa. Cuando el vendedor está de vacaciones o deja el puesto, la relación se sostiene si el historial está escrito. Una nota de dos líneas hoy vale más que la memoria de nadie en seis meses.',
                        ],
                        [
                            't' => 'Tareas y seguimientos',
                            'd' => 'Un compromiso con fecha: «volver a llamar el martes», «enviar la cotización». Aparecen en su lista y las vencidas se marcan. Es la diferencia entre hacer seguimiento y acordarse de vez en cuando.',
                            'ruta' => 'modules/crm/tareas.php',
                            'tip' => 'Al cerrar una interacción, crea de una vez la tarea del siguiente paso. Es el hábito que sostiene todo el CRM.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => '¿Por qué conviene registrar las llamadas aunque «uno se acuerde»?',
                            'ops' => ['Para llenar el sistema', 'Porque el cliente es de la empresa y el historial debe sobrevivir a la persona', 'Para calcular la comisión', 'Para el reporte de la DGII'],
                            'ok'  => 1,
                            'exp' => 'Cuando el vendedor falta o cambia de puesto, lo único que sostiene la relación es lo que quedó escrito.',
                        ],
                    ],
                ],
            ],
        ],
    ];
}
