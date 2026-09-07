<?php
/**
 * Temario de entrenamiento — GESTIÓN
 *
 * Recursos Humanos, finanzas y fiscal, reportes y dirección, marketing,
 * cumplimiento sanitario y administración del sistema.
 *
 * La primera mitad (fundamentos, POS, inventario, compras, traslados y
 * clientes/CRM) vive en `entrenamiento_temario_operacion.php`. El formato de
 * una lección está documentado allí.
 *
 * Nota sobre las cifras legales dominicanas (AFP, SFS, ISR, regalía): el
 * temario explica el CRITERIO, no memoriza porcentajes que la ley cambia. Los
 * valores vigentes los tiene el sistema en Recursos Humanos → TSS, y ahí es
 * donde hay que mirarlos. Un manual con una tasa vieja es peor que ninguno.
 */

/** Rutas de gestión. @return array<string,array> */
function ent_temario_gestion(): array
{
    return [

        /* =====================================================================
         *  7. RECURSOS HUMANOS Y NÓMINA
         * ===================================================================== */
        'rrhh' => [
            'titulo'      => 'Recursos Humanos y nómina',
            'descripcion' => 'El padrón de empleados, la asistencia, la quincena con AFP, SFS e ISR, y todo lo que la ley dominicana exige alrededor.',
            'icono'       => 'id',
            'color'       => 'rose',
            'nivel'       => 'Avanzado',
            'permiso'     => ['rrhh_empleados.ver', 'rrhh_nomina.ver', 'rrhh_asistencia.ver', 'tss.ver'],
            'para_quien'  => 'Recursos Humanos, quien procesa la nómina y la administración.',
            'lecciones'   => [

                'empleados' => [
                    'titulo'   => 'El padrón de empleados',
                    'resumen'  => 'La ficha de cada persona: lo que se usa para pagarle, cotizarle y liquidarle.',
                    'permiso'  => 'rrhh_empleados.ver',
                    'minutos'  => 8,
                    'pantalla' => 'modules/rrhh/empleados.php',
                    'objetivos' => [
                        'Crear una ficha completa y saber qué campo alimenta qué cálculo.',
                        'Entender la diferencia entre sucursal y departamento.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Los datos que mueven dinero',
                            'd' => 'La mitad de los campos de la ficha son informativos; estos no lo son:',
                            'campos' => [
                                'Cédula'          => 'Identifica a la persona ante la TSS y ante la DGII. Va como texto: si se guarda como número pierde el cero inicial y el archivo del banco la rechaza.',
                                'Fecha de ingreso'=> 'De ella salen la antigüedad, el derecho a vacaciones, la regalía proporcional y la cesantía. Un error aquí se paga literalmente.',
                                'Salario'         => 'La base del cálculo. Cambiarlo afecta a las nóminas futuras, no a las ya cerradas.',
                                'Forma de pago'   => 'Efectivo o transferencia. Decide si entra o no en el archivo del banco.',
                                'Cuenta bancaria' => 'Solo se usa si cobra por transferencia. En República Dominicana son 11 dígitos.',
                                'Sucursal'        => 'Dónde trabaja. Reparte el costo de la plantilla por local.',
                                'Departamento y puesto' => 'Para qué área trabaja. Es otra dimensión, no un sinónimo de sucursal.',
                            ],
                            'ruta' => 'modules/rrhh/empleados.php',
                        ],
                        [
                            't' => 'Sucursal y departamento no son lo mismo',
                            'd' => 'La sucursal es el local; el departamento es el área funcional. La misma persona de contabilidad puede estar asignada a un local concreto y pertenecer al departamento administrativo. El costo del personal se puede leer por cualquiera de los dos ejes, y por eso hacen falta los dos.',
                        ],
                        [
                            't' => 'Un método de pago mal puesto se paga dos veces',
                            'd' => 'Caso real: a alguien le cierran la cuenta y se le pasa a efectivo, pero nadie borra el número de cuenta viejo de la ficha. Si el archivo del banco se armara mirando solo si hay cuenta, esa persona entraría en la transferencia Y cobraría en caja.',
                            'aviso' => 'Por eso el sistema arma el archivo del banco mirando la FORMA DE PAGO, no la existencia de cuenta, y nombra en el pie a todos los que quedaron fuera. Ese pie hay que leerlo siempre.',
                        ],
                        [
                            't' => 'Dar de baja no es borrar',
                            'd' => 'Un empleado que sale se marca como inactivo con su fecha y su causa de salida. No se elimina: su histórico de nóminas, sus vacaciones y su liquidación tienen que seguir existiendo. Además, la causa de salida es lo que determina qué prestaciones corresponden.',
                        ],
                    ],
                    'errores' => [
                        ['sintoma' => 'La cuenta bancaria sale con menos de 11 dígitos',
                         'causa'   => 'Se cargó desde un Excel donde estaba guardada como número y perdió el cero inicial.',
                         'solucion'=> 'Corrige la ficha escribiéndola completa. El sistema marca esas cuentas como «REVISAR» en el archivo del banco en vez de mandarlas mal.'],
                    ],
                    'quiz' => [
                        [
                            'p'   => '¿Cuál de estos campos NO afecta directamente a un cálculo de dinero?',
                            'ops' => ['Fecha de ingreso', 'Salario', 'Correo personal', 'Forma de pago'],
                            'ok'  => 2,
                            'exp' => 'La fecha de ingreso rige antigüedad, vacaciones y cesantía; el salario es la base; la forma de pago decide el archivo del banco. El correo es informativo.',
                        ],
                    ],
                ],

                'asistencia' => [
                    'titulo'   => 'Asistencia y reloj biométrico',
                    'resumen'  => 'De dónde salen los días y las horas que después cobra la nómina.',
                    'permiso'  => 'rrhh_asistencia.ver',
                    'minutos'  => 6,
                    'pantalla' => 'modules/rrhh/asistencia.php',
                    'objetivos' => [
                        'Registrar y corregir asistencia.',
                        'Entender cómo entran las marcas del reloj biométrico.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Para qué sirve realmente',
                            'd' => 'No es un control de puntualidad: es el origen de los días trabajados, las ausencias y las horas extra que la nómina va a pagar o descontar. Lo que no esté aquí, la nómina no lo sabe.',
                            'ruta' => 'modules/rrhh/asistencia.php',
                        ],
                        [
                            't' => 'El reloj biométrico',
                            'd' => 'Si el local tiene reloj, sus marcas se importan y se convierten en asistencia. La pantalla del reloj muestra las marcas crudas y permite revisarlas antes de que alimenten la nómina.',
                            'ruta' => 'modules/rrhh/ponche.php',
                            'tip' => 'Revisa las marcas ANTES de procesar la quincena, no después. Corregir una asistencia con la nómina ya confirmada obliga a regenerarla.',
                        ],
                        [
                            't' => 'Las correcciones se hacen antes de procesar',
                            'd' => 'Un olvido de marcar, un permiso, una licencia: se corrige en la asistencia, con su nota. Una vez que la nómina se confirma, esos días quedan congelados en el documento.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => '¿Cuándo hay que corregir un error de asistencia?',
                            'ops' => ['Después de pagar', 'Antes de procesar la quincena', 'Da igual', 'Al cierre del mes'],
                            'ok'  => 1,
                            'exp' => 'La nómina toma los días de la asistencia. Corregir después obliga a regenerar la nómina completa.',
                        ],
                    ],
                ],

                'nomina' => [
                    'titulo'   => 'Procesar la quincena',
                    'resumen'  => 'El ciclo completo: generar, revisar línea por línea, confirmar, pagar y exportar el archivo del banco.',
                    'permiso'  => 'rrhh_nomina.ver',
                    'minutos'  => 12,
                    'pantalla' => 'modules/rrhh/nomina.php',
                    'objetivos' => [
                        'Recorrer los estados de una nómina.',
                        'Revisar los casos que el sistema avisa antes de confirmar.',
                        'Generar el Excel del contador y el archivo del banco.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Los estados: borrador, procesada, pagada',
                            'd' => 'Una nómina nace en borrador (se puede editar y eliminar), pasa a procesada al confirmarla (ya cuenta para la TSS y para el costo del personal) y a pagada cuando se registra el pago. No existe «anulada»: para liberar un período hay que eliminar la nómina mientras se pueda.',
                            'ruta' => 'modules/rrhh/nomina.php',
                        ],
                        [
                            't' => 'Generar',
                            'd' => 'Se elige el tipo de período (quincenal, mensual, semanal) y sus fechas. El sistema arma una línea por empleado activo con su sueldo del período, sus horas extra, sus incentivos y sus descuentos.',
                            'aviso' => 'Una nómina del mismo período exacto se bloquea nombrando la que ya existe. Una que solo se SOLAPA se avisa: dos nóminas sobre el mismo mes hacen que esa gente cotice doble ante la TSS y que el costo de personal se cuente dos veces.',
                        ],
                        [
                            't' => 'Qué calcula el sistema',
                            'd' => 'Sobre la base cotizable del período:',
                            'lista' => [
                                'AFP y SFS con las tasas y los topes vigentes de la Ley 87-01, que se consultan y se mantienen en la pantalla de TSS.',
                                'ISR sobre el equivalente MENSUAL, prorrateado al período. No se anualiza la quincena: eso daría la mitad de la renta real y bajaría de tramo a casi todo el mundo.',
                                'Cuota de préstamo, cobrada hasta donde alcance el sueldo. Lo que no cupo se reporta aparte en vez de darse por cobrado.',
                            ],
                        ],
                        [
                            't' => 'Revisar antes de confirmar — los tres casos clásicos',
                            'd' => 'Estos son los que se cuelan si nadie mira:',
                            'campos' => [
                                'Quien entró a mitad de período' => 'Debe cobrar los días que trabajó, no la jornada completa. El sistema prorratea, pero verifica las altas del mes.',
                                'Quien salió a mitad de período' => 'Igual, y además puede tener liquidación aparte.',
                                'Un neto negativo o casi cero'   => 'Señal de descuentos acumulados (préstamo + ausencias). Revísalo: puede ser correcto, pero nunca debe sorprender el día de pago.',
                            ],
                        ],
                        [
                            't' => 'Confirmar',
                            'd' => 'Confirmar cierra el cálculo. A partir de ahí la nómina cuenta para la declaración de la TSS, para el aporte patronal y para el costo del personal en el estado de resultados.',
                        ],
                        [
                            't' => 'El Excel del contador',
                            'd' => 'Reproduce las columnas de la hoja que el contador ya conoce, agrupadas por sucursal y con totales. Las cédulas y las cuentas salen como texto para que no pierdan el cero inicial. El sueldo mensual se congela al generar: reexportar una quincena cerrada tiene que dar exactamente lo mismo aunque después haya habido un aumento.',
                        ],
                        [
                            't' => 'El archivo del banco',
                            'd' => 'El CSV de transferencias. Entra quien cobra por transferencia y tiene cuenta; el resto sale NOMBRADO en el pie del archivo, nunca callado: sin cuenta, cuenta con formato dudoso, o método de pago que no es transferencia.',
                            'tip' => 'Lee siempre el pie del archivo antes de subirlo al banco. Es la lista de quién no va a cobrar por ahí y hay que pagar aparte.',
                        ],
                        [
                            't' => 'El volante de pago',
                            'd' => 'Cada persona recibe su volante con el desglose de lo devengado y lo descontado. Es un documento que se entrega, y es también la forma más rápida de que un error salte antes de que se convierta en un reclamo.',
                            'ruta' => 'modules/rrhh/volante.php',
                        ],
                    ],
                    'errores' => [
                        ['sintoma' => 'Alguien que entró el día 11 cobró la quincena completa',
                         'causa'   => 'La línea no tomó los días reales trabajados.',
                         'solucion'=> 'Corrige los días en la línea antes de confirmar. Si ya está confirmada, hay que regenerar.'],
                        ['sintoma' => 'Una persona aparece cotizando dos veces en el mes',
                         'causa'   => 'Hay dos nóminas que se solapan sobre el mismo período.',
                         'solucion'=> 'Revisa las nóminas del mes. El sistema avisa del solapamiento y dice quién cotizaría doble.'],
                    ],
                    'quiz' => [
                        [
                            'p'   => '¿Por qué el ISR se calcula sobre el equivalente mensual y no anualizando la quincena?',
                            'ops' => ['Por rapidez', 'Porque anualizar medio mes da la mitad de la renta real y baja de tramo a casi todos', 'Porque la ley lo prohíbe expresamente', 'Porque la quincena no paga ISR'],
                            'ok'  => 1,
                            'exp' => 'La escala del ISR es anual y progresiva. Multiplicar una quincena por 12 subestima la renta y coloca a casi todo el mundo en un tramo más bajo del que le toca.',
                        ],
                        [
                            'p'   => 'Procesas dos veces la nómina del 1 al 15 y confirmas las dos. ¿Qué se rompe?',
                            'ops' => ['Nada, se suman', 'La TSS, el aporte patronal y el costo de personal cuentan doble', 'Solo el volante', 'Solo el archivo del banco'],
                            'ok'  => 1,
                            'exp' => 'Todo lo que suma por período las contaría a las dos. Es un error caro y silencioso: por eso el sistema bloquea el período idéntico y avisa del solapamiento.',
                        ],
                    ],
                ],

                'tss' => [
                    'titulo'   => 'TSS: aportes, novedades y el IR-3',
                    'resumen'  => 'Los parámetros de la seguridad social, quién los puede cambiar y qué se declara cada mes.',
                    'permiso'  => 'tss.ver',
                    'minutos'  => 8,
                    'pantalla' => 'modules/rrhh/tss.php',
                    'objetivos' => [
                        'Consultar los aportes del período.',
                        'Entender qué son los topes y por qué se configuran aparte.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Qué muestra la pantalla',
                            'd' => 'Los aportes del período por empleado y en total: lo que descuenta el trabajador (AFP y SFS) y lo que aporta el empleador. También las novedades del mes: quién entró, quién salió y quién cambió de salario, que es lo que la TSS necesita saber.',
                            'ruta' => 'modules/rrhh/tss.php',
                        ],
                        [
                            't' => 'Los parámetros: salario mínimo cotizable, tasas y topes',
                            'd' => 'La Ley 87-01 fija tasas y topes de cotización, y esos valores CAMBIAN. Por eso no están escritos en el código: están en esta pantalla y se actualizan cuando la ley cambia. Modificarlos requiere el permiso `tss.configurar`, separado de `tss.ver`.',
                            'aviso' => 'Cambiar una tasa afecta a todas las nóminas que se procesen desde ese momento. No se cambian las ya cerradas — y así debe ser: una nómina pasada se calculó con la ley que estaba vigente.',
                        ],
                        [
                            't' => 'El pago de la TSS y el IR-3',
                            'd' => 'La pestaña de pagos reúne lo que hay que pagar cada mes a la TSS y el resumen del IR-3, la declaración de retenciones de asalariados ante la DGII. Salen de las nóminas confirmadas del período.',
                        ],
                        [
                            't' => 'La constancia de ISR del empleado',
                            'd' => 'Cada persona puede pedir su constancia de retenciones para su propia declaración. El sistema la genera desde el histórico de nóminas.',
                            'ruta' => 'modules/rrhh/constancia_isr.php',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Cambia por ley el tope de cotización. ¿Qué pasa con las nóminas ya cerradas?',
                            'ops' => ['Se recalculan solas', 'No cambian: se calcularon con la ley vigente en su momento', 'Hay que eliminarlas', 'Se recalculan solo las del año'],
                            'ok'  => 1,
                            'exp' => 'Un documento cerrado refleja la ley de su fecha. Recalcularlo hacia atrás lo invalidaría como respaldo.',
                        ],
                    ],
                ],

                'regalia' => [
                    'titulo'   => 'Regalía pascual',
                    'resumen'  => 'El salario de Navidad: cómo se calcula, quién tiene derecho y qué pasa con quien entró a mitad de año.',
                    'permiso'  => 'rrhh_nomina.ver',
                    'minutos'  => 6,
                    'pantalla' => 'modules/rrhh/regalia.php',
                    'objetivos' => [
                        'Generar la regalía del año.',
                        'Entender el prorrateo y qué se incluye en la base.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Qué es',
                            'd' => 'El salario de Navidad que el Código de Trabajo obliga a pagar a más tardar el 20 de diciembre. Le corresponde a todo el que trabajó durante el año, haya salido o no.',
                            'ruta' => 'modules/rrhh/regalia.php',
                        ],
                        [
                            't' => 'El prorrateo',
                            'd' => 'Quien no trabajó el año completo cobra la parte proporcional a los meses trabajados. Por eso la fecha de ingreso de la ficha tiene que ser correcta: de ahí sale el cálculo.',
                        ],
                        [
                            't' => 'La base del cálculo',
                            'd' => 'No es cualquier cosa que se le haya pagado a la persona. La regalía se calcula sobre el salario ordinario; hay conceptos que la ley excluye expresamente. El sistema aplica ese criterio, y el resultado se puede revisar línea por línea antes de pagar.',
                            'tip' => 'Revisa la regalía en noviembre, no el 19 de diciembre. Corregir una fecha de ingreso a tiempo evita un reclamo.',
                        ],
                        [
                            't' => 'La provisión',
                            'd' => 'La regalía se va devengando todo el año aunque se pague en diciembre. El informe de provisiones laborales muestra cuánto se debe hoy por ese concepto, para que el resultado del mes no ignore un pasivo que ya existe.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Un empleado entró en julio. En diciembre, ¿cuánta regalía le toca?',
                            'ops' => ['Ninguna, no cumplió el año', 'La proporcional a los meses trabajados', 'La completa', 'La mitad, siempre'],
                            'ok'  => 1,
                            'exp' => 'La regalía se prorratea por los meses trabajados en el año. De ahí que la fecha de ingreso tenga que estar bien.',
                        ],
                    ],
                ],

                'vacaciones' => [
                    'titulo'   => 'Vacaciones y licencias',
                    'resumen'  => 'El derecho que se acumula por antigüedad, su saldo y cómo se registra el disfrute.',
                    'permiso'  => 'rrhh_vacaciones.ver',
                    'minutos'  => 6,
                    'pantalla' => 'modules/rrhh/vacaciones.php',
                    'objetivos' => [
                        'Consultar el saldo de vacaciones de una persona.',
                        'Registrar y aprobar un período de vacaciones.',
                    ],
                    'pasos' => [
                        [
                            't' => 'El derecho crece con la antigüedad',
                            'd' => 'El Código de Trabajo dominicano fija los días de vacaciones según el tiempo en la empresa. El sistema calcula el derecho desde la fecha de ingreso y lleva el saldo: lo devengado menos lo disfrutado.',
                            'ruta' => 'modules/rrhh/vacaciones.php',
                        ],
                        [
                            't' => 'Solicitar y aprobar',
                            'd' => 'Se registra el período solicitado y alguien con `rrhh_vacaciones.aprobar` lo autoriza. Al aprobarse, esos días se descuentan del saldo y se reflejan en la asistencia del período.',
                        ],
                        [
                            't' => 'Vacaciones no disfrutadas',
                            'd' => 'Son un pasivo: la empresa las debe aunque nadie las haya tomado. El informe de provisiones laborales las suma junto con la regalía devengada y la cesantía, para que el balance refleje lo que de verdad se debe.',
                            'aviso' => 'Acumular vacaciones sin disfrutar durante años no es un ahorro: es una deuda creciente que se paga entera el día que la persona sale.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Las vacaciones que nadie tomó, ¿qué son contablemente?',
                            'ops' => ['Un ahorro', 'Un pasivo: se deben aunque no se hayan disfrutado', 'Nada hasta que se pidan', 'Un gasto ya registrado'],
                            'ok'  => 1,
                            'exp' => 'Se van devengando con el tiempo trabajado. Por eso entran en el informe de provisiones laborales.',
                        ],
                    ],
                ],

                'prestamos' => [
                    'titulo'   => 'Préstamos a empleados',
                    'resumen'  => 'Otorgar, descontar por nómina y respetar el tope legal.',
                    'permiso'  => 'prestamos.ver',
                    'minutos'  => 6,
                    'pantalla' => 'modules/rrhh/prestamos.php',
                    'objetivos' => [
                        'Registrar un préstamo con su plan de descuento.',
                        'Entender el tope y qué pasa cuando la cuota no cabe.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Otorgar',
                            'd' => 'Se registra el monto, el número de cuotas y desde qué período empieza a descontarse. Requiere `prestamos.crear`, que es también la autorización del descuento.',
                            'ruta' => 'modules/rrhh/prestamos.php',
                        ],
                        [
                            't' => 'El descuento por nómina',
                            'd' => 'Cada nómina cobra la cuota que toca y baja el saldo. El histórico queda por si hay que reconstruir cuánto se ha cobrado.',
                        ],
                        [
                            't' => 'El tope legal',
                            'd' => 'La ley limita cuánto se le puede descontar a alguien de su salario. El sistema respeta ese tope, que se configura con el permiso `prestamos.configurar`.',
                            'aviso' => 'Si la cuota es mayor que lo que la persona devengó en el período, se cobra hasta donde alcanza y lo que NO cupo se reporta aparte. Nunca se da por cobrado lo que no se pudo descontar, y jamás se genera un neto negativo.',
                        ],
                        [
                            't' => 'Anular o condonar',
                            'd' => 'Requiere `prestamos.anular`. Queda registrado: condonar una deuda es una decisión con impacto y no puede pasar en silencio.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'La cuota del préstamo es mayor que lo devengado en la quincena. ¿Qué hace el sistema?',
                            'ops' => ['Genera un neto negativo', 'Cobra hasta donde alcanza y reporta lo que no cupo', 'Cobra la cuota completa igual', 'Cancela el préstamo'],
                            'ok'  => 1,
                            'exp' => 'Un neto negativo no existe. Se cobra lo posible y lo pendiente se avisa en vez de darse por cobrado.',
                        ],
                    ],
                ],

                'disciplina' => [
                    'titulo'   => 'Amonestaciones y prestaciones laborales',
                    'resumen'  => 'El expediente disciplinario y el cálculo de una liquidación.',
                    'permiso'  => ['amonestaciones.ver', 'prestaciones.ver'],
                    'minutos'  => 8,
                    'pantalla' => 'modules/rrhh/amonestaciones.php',
                    'objetivos' => [
                        'Levantar una amonestación con su documento.',
                        'Entender qué cambia en una liquidación según la causa de salida.',
                    ],
                    'pasos' => [
                        [
                            't' => 'La amonestación',
                            'd' => 'Se levanta con su fecha, su causa y su descripción, y genera el documento que se le entrega y se le hace firmar a la persona. Requiere `amonestaciones.crear`.',
                            'ruta' => 'modules/rrhh/amonestaciones.php',
                            'aviso' => 'El régimen disciplinario dominicano tiene plazos: una falta tiene que sancionarse dentro del plazo legal o la sanción pierde validez. El sistema vigila esos plazos; ignorar el aviso es quedarse sin la sanción.',
                        ],
                        [
                            't' => 'Para qué sirve el expediente',
                            'd' => 'Un despido por causa justificada se sostiene con el expediente. Si no hay nada escrito, ante las autoridades de trabajo la falta no ocurrió, y la salida pasa a tratarse como un desahucio con todas sus consecuencias económicas.',
                        ],
                        [
                            't' => 'Las prestaciones: preaviso y cesantía',
                            'd' => 'Cuando alguien sale, el sistema calcula lo que corresponde según la causa: preaviso, cesantía, vacaciones no disfrutadas, regalía proporcional y el salario pendiente.',
                            'ruta' => 'modules/rrhh/prestaciones.php',
                            'campos' => [
                                'Desahucio del empleador' => 'Corresponden preaviso y cesantía completos.',
                                'Renuncia'                => 'No corresponden preaviso ni cesantía, sí lo devengado: vacaciones, regalía proporcional y salario pendiente.',
                                'Despido justificado'     => 'Se sostiene con el expediente disciplinario. Sin él, difícilmente se sostiene.',
                            ],
                        ],
                        [
                            't' => 'El documento de liquidación',
                            'd' => 'El sistema genera el documento con el desglose completo, que es el que se firma al pagar. Guardarlo es lo que protege a la empresa después.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => '¿Por qué importa el expediente disciplinario al momento de una salida?',
                            'ops' => ['Es un requisito administrativo interno', 'Sin él, un despido justificado difícilmente se sostiene y la salida se trata como desahucio', 'Sirve para calcular la regalía', 'No influye en la liquidación'],
                            'ok'  => 1,
                            'exp' => 'La causa de salida cambia lo que se paga. Y una causa justificada sin nada escrito no se puede probar.',
                        ],
                    ],
                ],
            ],
        ],

        /* =====================================================================
         *  8. FINANZAS Y FISCAL
         * ===================================================================== */
        'finanzas' => [
            'titulo'      => 'Finanzas y obligaciones fiscales',
            'descripcion' => 'Ingresos y gastos con criterio contable, cuentas, conciliación, activos fijos y los formatos de la DGII.',
            'icono'       => 'dollar',
            'color'       => 'emerald',
            'nivel'       => 'Avanzado',
            'permiso'     => ['finanzas.ver', 'conciliacion.ver', 'dgii.ver', 'activos.ver', 'comisiones.ver', 'metas.ver', 'ecf.ver'],
            'para_quien'  => 'Administración, contabilidad y quien declara ante la DGII.',
            'lecciones'   => [

                'transacciones' => [
                    'titulo'   => 'Ingresos y gastos: la regla que hay que entender antes de registrar nada',
                    'resumen'  => 'Dos preguntas por cada movimiento: ¿es gasto operativo? ¿mueve efectivo? No siempre coinciden.',
                    'permiso'  => 'finanzas.ver',
                    'minutos'  => 9,
                    'pantalla' => 'modules/finanzas/index.php',
                    'objetivos' => [
                        'Clasificar correctamente un movimiento.',
                        'Entender por qué gasto y salida de efectivo no son lo mismo.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Las dos preguntas',
                            'd' => 'Antes de registrar cualquier movimiento hay que responder dos cosas independientes: ¿es un GASTO OPERATIVO (afecta al resultado)? y ¿MUEVE EFECTIVO (afecta a la caja)? Se pueden dar las cuatro combinaciones.',
                            'ruta' => 'modules/finanzas/index.php',
                            'campos' => [
                                'Gasto y efectivo'       => 'Pagar la luz. Afecta al resultado y sale dinero.',
                                'Efectivo pero NO gasto' => 'Pagar una factura de mercancía a un proveedor. Sale dinero, pero es inventario: el gasto vendrá cuando se venda, como costo de lo vendido.',
                                'Gasto pero NO efectivo' => 'La depreciación de un activo, o una diferencia cambiaria. Afecta al resultado sin que salga un solo peso ese día.',
                                'Ni gasto ni efectivo'   => 'Una compra a crédito el día que se registra: crea una deuda y nada más.',
                            ],
                        ],
                        [
                            't' => 'Por qué esto importa tanto',
                            'd' => 'Porque de aquí salen dos informes distintos que la gente confunde: el estado de resultados (¿ganamos?) y el flujo de efectivo (¿tenemos dinero?). Un negocio puede ganar dinero y quedarse sin efectivo el mismo mes, y esos dos informes son los que lo muestran.',
                            'aviso' => 'El error clásico es sumar todas las transacciones de tipo gasto para saber cuánto se gastó. Ese total incluye la compra de mercancía (que es inventario) y las devoluciones (que ya restaron del ingreso). El sistema tiene un criterio propio para los gastos operativos, y los reportes lo usan.',
                        ],
                        [
                            't' => 'Registrar un movimiento',
                            'd' => 'Se elige tipo (ingreso o gasto), categoría, cuenta, fecha, monto y descripción. La categoría es lo que después agrupa el análisis de gastos, así que vale la pena mantener una lista corta y limpia.',
                        ],
                        [
                            't' => 'Otros ingresos',
                            'd' => 'Un ingreso que no es una venta ni el cobro de un abono: alquiler de un espacio, venta de un activo, un reembolso. Se registran aquí y el estado de resultados los muestra por separado de las ventas, porque no son la actividad del negocio.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Pagas RD$ 500,000 a un proveedor por mercancía comprada el mes pasado. ¿Cómo se clasifica?',
                            'ops' => ['Gasto operativo y salida de efectivo', 'Salida de efectivo pero NO gasto operativo', 'Gasto pero no efectivo', 'Ninguna de las dos'],
                            'ok'  => 1,
                            'exp' => 'El dinero sale, pero la mercancía es inventario. Se convierte en gasto (costo de lo vendido) cuando se venda, no cuando se pague.',
                        ],
                        [
                            'p'   => 'La depreciación mensual de un vehículo, ¿qué es?',
                            'ops' => ['Gasto que no mueve efectivo', 'Salida de efectivo', 'Ninguna de las dos', 'Un ingreso'],
                            'ok'  => 0,
                            'exp' => 'Reduce el resultado del mes sin que salga un peso: el dinero se pagó cuando se compró el vehículo.',
                        ],
                    ],
                ],

                'cuentas' => [
                    'titulo'   => 'Cuentas de banco y efectivo',
                    'resumen'  => 'Dónde está el dinero y por qué cada movimiento tiene que apuntar a la cuenta correcta.',
                    'permiso'  => 'finanzas.ver',
                    'minutos'  => 5,
                    'pantalla' => 'modules/finanzas/cuentas.php',
                    'objetivos' => [
                        'Crear y mantener las cuentas financieras.',
                        'Entender el efecto de elegir mal la cuenta.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Qué es una cuenta',
                            'd' => 'Un contenedor de dinero: la caja chica, el banco X en pesos, el banco Y en dólares, la cuenta de tarjetas. Cada movimiento de efectivo del sistema apunta a una.',
                            'ruta' => 'modules/finanzas/cuentas.php',
                        ],
                        [
                            't' => 'El saldo se construye, no se escribe',
                            'd' => 'El saldo sale del saldo inicial más todos los movimientos. No se teclea a mano. Si el saldo no cuadra con el banco, la respuesta está en un movimiento que falta o que sobra, y eso es lo que la conciliación bancaria encuentra.',
                        ],
                        [
                            't' => 'Elegir mal la cuenta',
                            'd' => 'Es el error más caro de esta pantalla porque no da ningún síntoma inmediato: el pago se registró, la deuda bajó, todo parece bien. Aparece semanas después, cuando la conciliación de ese mes no cuadra y hay que revisar cien movimientos para encontrar uno.',
                            'tip' => 'Al registrar un pago, la cuenta es el campo que hay que verificar dos veces. El monto salta a la vista si está mal; la cuenta no.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => '¿Cuándo se nota que un pago se registró en la cuenta equivocada?',
                            'ops' => ['Inmediatamente, el sistema avisa', 'Al conciliar ese mes, cuando ya hay cientos de movimientos', 'Nunca', 'Al cerrar el año'],
                            'ok'  => 1,
                            'exp' => 'No hay síntoma inmediato: la deuda bajó y el movimiento existe. El error solo aparece en la conciliación.',
                        ],
                    ],
                ],

                'conciliacion' => [
                    'titulo'   => 'Conciliación bancaria',
                    'resumen'  => 'Cruzar los libros contra el estado de cuenta del banco, y por qué un corte con diferencia no se cierra.',
                    'permiso'  => 'conciliacion.ver',
                    'minutos'  => 9,
                    'pantalla' => 'modules/finanzas/conciliacion.php',
                    'objetivos' => [
                        'Hacer una conciliación completa.',
                        'Interpretar la diferencia y saber qué buscar.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Qué se concilia',
                            'd' => 'Solo las cuentas que tienen estado de cuenta: banco, tarjeta y transferencia. El EFECTIVO no se concilia aquí — su arqueo es el cierre de caja, que ya cuenta el dinero físico. Meterlo aquí daría dos verdades distintas para lo mismo.',
                            'ruta' => 'modules/finanzas/conciliacion.php',
                        ],
                        [
                            't' => 'La aritmética',
                            'd' => 'Es la conciliación clásica. «En tránsito» es lo que ya está en libros pero el banco todavía no refleja, es decir, los movimientos aún no marcados:',
                            'lista' => [
                                'Saldo según el banco (del estado de cuenta)',
                                '+ Depósitos en tránsito (el banco aún no los acreditó)',
                                '− Pagos en tránsito (el banco aún no los debitó)',
                                '= Saldo bancario ajustado, que debe ser igual al saldo según libros',
                            ],
                        ],
                        [
                            't' => 'La diferencia es el objetivo, no el obstáculo',
                            'd' => 'Si queda diferencia, falta registrar un movimiento o alguno está mal marcado. Encontrarla es exactamente para lo que sirve el ejercicio.',
                            'campos' => [
                                'Falta un movimiento en el sistema' => 'Un cargo del banco (comisión, ITBIS bancario) que nadie registró.',
                                'Sobra un movimiento en el sistema' => 'Un cheque registrado dos veces, o uno que nunca se cobró.',
                                'Movimiento mal marcado'            => 'Se marcó como conciliado algo que el banco no refleja en ese corte.',
                            ],
                        ],
                        [
                            't' => 'Un corte con diferencia NO se cierra',
                            'd' => 'El botón de cerrar solo aparece con diferencia cero. Una conciliación con diferencia no está conciliada, y cerrarla escondería el problema en vez de resolverlo.',
                            'aviso' => 'Al cerrar, los movimientos marcados quedan bloqueados y ya no se pueden desmarcar: un período conciliado es un hecho cerrado. Los cortes siguientes solo toman los movimientos que aún no pertenecen a ninguno.',
                        ],
                        [
                            't' => 'El saldo en libros es a la fecha de corte',
                            'd' => 'Se recalcula como saldo inicial más los movimientos hasta el corte. No se usa el saldo de hoy: una conciliación siempre es a una fecha pasada.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Tu conciliación queda con RD$ 1,200 de diferencia y el jefe quiere cerrar el mes. ¿Qué corresponde?',
                            'ops' => ['Cerrar y anotarlo', 'Buscar la causa: el sistema no deja cerrar con diferencia', 'Ajustar el saldo en libros', 'Registrar un gasto de RD$ 1,200'],
                            'ok'  => 1,
                            'exp' => 'El botón de cerrar solo aparece con diferencia cero, precisamente para que nadie cierre encima de un problema.',
                        ],
                        [
                            'p'   => '¿Por qué el efectivo no se concilia en esta pantalla?',
                            'ops' => ['Por rendimiento', 'Porque su arqueo es el cierre de caja y duplicarlo daría dos verdades', 'Porque no tiene movimientos', 'Porque no lo permite la DGII'],
                            'ok'  => 1,
                            'exp' => 'El cierre de caja ya cuenta el dinero físico. Conciliar el efectivo aquí sería una segunda lógica para lo mismo.',
                        ],
                    ],
                ],

                'activos' => [
                    'titulo'   => 'Activos fijos y depreciación',
                    'resumen'  => 'Lo que la empresa posee, cómo pierde valor con el tiempo y cómo se da de baja.',
                    'permiso'  => 'activos.ver',
                    'minutos'  => 7,
                    'pantalla' => 'modules/finanzas/activos.php',
                    'objetivos' => [
                        'Registrar un activo con su vida útil.',
                        'Correr la depreciación mensual y entender su efecto.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Qué es un activo fijo',
                            'd' => 'Algo que la empresa compra para usarlo durante años, no para venderlo: vehículos, mobiliario, equipos, mejoras al local. Su compra no es un gasto del mes: es una inversión que se va gastando a lo largo de su vida útil.',
                            'ruta' => 'modules/finanzas/activos.php',
                        ],
                        [
                            't' => 'Registrarlo',
                            'd' => 'Descripción, fecha de adquisición, costo, vida útil y valor residual. De ahí sale la cuota de depreciación.',
                        ],
                        [
                            't' => 'Correr la depreciación mensual',
                            'd' => 'Requiere `activos.depreciar`. Cada mes, el sistema reconoce la porción de valor consumida. Es un gasto que NO mueve efectivo: reduce el resultado sin sacar dinero, porque el dinero salió el día de la compra.',
                            'aviso' => 'Córrela todos los meses, no una vez al año. Si se salta, el resultado de esos meses sale inflado y el de diciembre, hundido, sin que la operación haya cambiado en nada.',
                        ],
                        [
                            't' => 'Dar de baja o vender',
                            'd' => 'Requiere `activos.baja`. Al venderlo, la diferencia entre lo que se recibió y el valor que le quedaba en libros es una ganancia o una pérdida, y se registra como tal.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Compras un vehículo de RD$ 1,500,000 para reparto. ¿Cómo entra en el estado de resultados del mes?',
                            'ops' => ['Como gasto de RD$ 1,500,000', 'No entra: entra su depreciación mensual', 'Como ingreso', 'Como costo de lo vendido'],
                            'ok'  => 1,
                            'exp' => 'Es una inversión, no un gasto del mes. Se reconoce por partes a lo largo de su vida útil.',
                        ],
                    ],
                ],

                'comisiones' => [
                    'titulo'   => 'Comisiones y metas de venta',
                    'resumen'  => 'Calcular lo que gana el equipo por vender, con tres firmas distintas de por medio.',
                    'permiso'  => ['comisiones.ver', 'metas.ver'],
                    'minutos'  => 6,
                    'pantalla' => 'modules/finanzas/comisiones.php',
                    'objetivos' => [
                        'Recorrer el ciclo generar → aprobar → pagar.',
                        'Fijar metas y leer su cumplimiento.',
                    ],
                    'pasos' => [
                        [
                            't' => 'De dónde sale la comisión',
                            'd' => 'Del porcentaje configurado en la ficha del usuario, aplicado a sus ventas del período. El sistema la calcula; nadie la teclea.',
                            'ruta' => 'modules/finanzas/comisiones.php',
                        ],
                        [
                            't' => 'Tres permisos, tres personas',
                            'd' => 'Generar, aprobar y pagar son permisos separados a propósito: quien calcula lo suyo no debería aprobárselo, y quien lo aprueba no necesariamente es quien firma el pago.',
                        ],
                        [
                            't' => 'Metas de venta',
                            'd' => 'Se fijan por vendedor, por sucursal o por período. El cumplimiento aparece en el panel ejecutivo y en el informe de desempeño del equipo.',
                            'ruta' => 'modules/finanzas/metas.php',
                            'tip' => 'Una meta sirve si es alcanzable y se revisa a mitad de período. Una meta que nadie mira hasta fin de mes es un número decorativo.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => '¿Por qué generar, aprobar y pagar comisiones son permisos distintos?',
                            'ops' => ['Por rendimiento del sistema', 'Para que quien calcula lo suyo no se lo apruebe solo', 'Por requisito de la DGII', 'Para poder exportar'],
                            'ok'  => 1,
                            'exp' => 'Es control interno: separar el cálculo, la autorización y el pago del dinero.',
                        ],
                    ],
                ],

                'dgii' => [
                    'titulo'   => 'Reportes 606, 607 y 608',
                    'resumen'  => 'Los formatos que la Norma 07-2018 obliga a remitir cada mes, a más tardar el día 15.',
                    'permiso'  => 'dgii.ver',
                    'minutos'  => 9,
                    'pantalla' => 'modules/finanzas/dgii.php',
                    'objetivos' => [
                        'Generar los tres formatos del período.',
                        'Saber qué revisar antes de enviarlos.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Los tres formatos',
                            'd' => 'Se remiten mensualmente, a más tardar el día 15 del mes siguiente.',
                            'campos' => [
                                '606' => 'Compras de bienes y servicios. Sale de las compras registradas con NCF que no estén anuladas.',
                                '607' => 'Ventas de bienes y servicios. Sale de las ventas con NCF más las notas de crédito de las devoluciones.',
                                '608' => 'Comprobantes anulados.',
                            ],
                            'ruta' => 'modules/finanzas/dgii.php',
                        ],
                        [
                            't' => 'Qué revisar ANTES de generar',
                            'd' => 'El archivo sale de lo que está registrado. Si falta algo en el sistema, falta en la declaración:',
                            'lista' => [
                                'Compras del mes registradas todas, con su NCF y el RNC del proveedor.',
                                'Ventas sin cliente identificado cuando el comprobante exigía RNC.',
                                'Devoluciones con su nota de crédito emitida.',
                                'Comprobantes anulados registrados como tales.',
                            ],
                        ],
                        [
                            't' => 'Antes del primer envío: pre-validar',
                            'd' => 'La DGII define con precisión las columnas y sus valores, pero no documenta oficialmente la estructura del archivo de texto: da por sentado que se llena su plantilla de Excel con macros. Por eso, antes del primer envío real, pasa el archivo generado por la herramienta de pre-validación de la Oficina Virtual.',
                            'tip' => 'Si tu contador tiene un archivo ya aceptado por la DGII, compáralo contra el que genera el sistema. Es la mejor verificación posible. Una vez que te acepten un período, los siguientes salen igual.',
                        ],
                        [
                            't' => 'Descargar',
                            'd' => 'Generar el archivo requiere `dgii.generar`, separado de `dgii.ver`. Consultar el reporte en pantalla no es lo mismo que producir el archivo que se declara.',
                        ],
                    ],
                    'errores' => [
                        ['sintoma' => 'El 606 sale con menos compras de las esperadas',
                         'causa'   => 'Compras sin NCF capturado, o registradas fuera del período, o anuladas.',
                         'solucion'=> 'Revisa el listado de compras del mes filtrando por las que no tienen NCF antes de generar.'],
                    ],
                    'quiz' => [
                        [
                            'p'   => '¿Hasta qué día se remiten los formatos 606, 607 y 608?',
                            'ops' => ['El último día del mes', 'El día 15 del mes siguiente', 'El día 20 del mismo mes', 'Trimestralmente'],
                            'ok'  => 1,
                            'exp' => 'La Norma General 07-2018 fija el día 15 del mes siguiente como fecha límite.',
                        ],
                    ],
                ],

                'it1' => [
                    'titulo'   => 'IT-1 · Declaración del ITBIS',
                    'resumen'  => 'El ITBIS cobrado contra el adelantado, y por qué el ITBIS nunca fue tuyo.',
                    'permiso'  => 'dgii.ver',
                    'minutos'  => 6,
                    'pantalla' => 'modules/finanzas/it1.php',
                    'objetivos' => [
                        'Leer el resumen del IT-1.',
                        'Entender por qué el ITBIS no es ingreso.',
                    ],
                    'pasos' => [
                        [
                            't' => 'El ITBIS que cobras no es tuyo',
                            'd' => 'Se recauda para la DGII. Por eso, en todos los reportes de este sistema, el ingreso es el subtotal menos el descuento: SIN ITBIS. Contarlo como ingreso infla la venta y hace que el margen parezca mayor de lo que es.',
                            'ruta' => 'modules/finanzas/it1.php',
                        ],
                        [
                            't' => 'La cuenta del IT-1',
                            'd' => 'ITBIS cobrado en las ventas menos ITBIS adelantado en las compras, más las retenciones que correspondan. El resultado es lo que se paga o el saldo a favor que queda.',
                        ],
                        [
                            't' => 'Qué revisar',
                            'd' => 'Que los productos tengan bien marcada su condición de gravado o exento, y que las compras tengan capturado su ITBIS. Un producto exento marcado como gravado hace pagar impuesto de más; al revés, hace declarar de menos.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Una venta de RD$ 1,180 con RD$ 180 de ITBIS. ¿Cuánto ingreso registra el sistema?',
                            'ops' => ['RD$ 1,180', 'RD$ 1,000', 'RD$ 180', 'Depende del método de pago'],
                            'ok'  => 1,
                            'exp' => 'El ingreso es el subtotal sin ITBIS. Los RD$ 180 se recaudan para la DGII: nunca fueron del negocio.',
                        ],
                    ],
                ],

                'ecf' => [
                    'titulo'   => 'Facturación electrónica (e-CF)',
                    'resumen'  => 'Cómo funciona la emisión electrónica, y por qué el interruptor no se enciende a la ligera.',
                    'permiso'  => 'ecf.ver',
                    'minutos'  => 8,
                    'pantalla' => 'modules/finanzas/ecf.php',
                    'objetivos' => [
                        'Leer el panel de comprobantes electrónicos.',
                        'Entender qué significa activar el e-CF.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Cómo llega el comprobante a la DGII',
                            'd' => 'No se envía directamente. Va a un proveedor certificado que arma el documento, lo firma con certificado digital, lo remite a la DGII y al comprador, y devuelve un acuse. El panel muestra el estado de cada comprobante enviado.',
                            'ruta' => 'modules/finanzas/ecf.php',
                        ],
                        [
                            't' => 'Convive con el NCF preimpreso, no lo reemplaza',
                            'd' => 'Con el e-CF apagado —que es el valor de fábrica— el punto de venta factura exactamente como siempre. El corte a comprobantes electrónicos es una decisión fiscal con fecha, que se toma cuando la certificación está aprobada y los rangos autorizados están cargados.',
                            'aviso' => 'Encender el e-CF sin los rangos autorizados de la DGII hace que los comprobantes se queden atascados. No es un interruptor de prueba: cámbialo solo cuando la certificación esté cerrada.',
                        ],
                        [
                            't' => 'Los tres permisos',
                            'd' => 'Están separados a propósito.',
                            'campos' => [
                                'ecf.ver'        => 'Ver el panel y los comprobantes emitidos.',
                                'ecf.emitir'     => 'Emitir, reenviar y consultar comprobantes.',
                                'ecf.configurar' => 'Cambiar credenciales, ambiente y secuencias. Quien tiene esto puede emitir comprobantes fiscales reales: es el permiso más delicado del módulo.',
                            ],
                        ],
                        [
                            't' => 'La cola',
                            'd' => 'Los envíos se encolan y se reintentan solos. Si un comprobante queda rechazado, el panel muestra el motivo devuelto por el proveedor. Un rechazo casi siempre es un dato del cliente o del documento, no un fallo técnico.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Con el e-CF desactivado, ¿qué pasa en el punto de venta?',
                            'ops' => ['No se puede facturar', 'Factura con NCF preimpreso, exactamente como siempre', 'Factura sin comprobante', 'Se encola todo'],
                            'ok'  => 1,
                            'exp' => 'El e-CF convive con el NCF preimpreso. Apagado, el sistema funciona igual que antes de la integración.',
                        ],
                    ],
                ],
            ],
        ],

        /* =====================================================================
         *  9. REPORTES Y DIRECCIÓN
         * ===================================================================== */
        'reportes' => [
            'titulo'      => 'Reportes y dirección',
            'descripcion' => 'Cómo leer los informes, el criterio contable que los hace cuadrar entre sí, y el tablero de la dirección.',
            'icono'       => 'chart',
            'color'       => 'blue',
            'nivel'       => 'Intermedio',
            'permiso'     => ['reportes.ver', 'direccion.ver'],
            'para_quien'  => 'Encargados, administración, contabilidad y dirección.',
            'lecciones'   => [

                'centro' => [
                    'titulo'   => 'El centro de reportes',
                    'resumen'  => 'Más de treinta informes con el mismo periodo, el mismo alcance y la misma cara.',
                    'permiso'  => 'reportes.ver',
                    'minutos'  => 6,
                    'pantalla' => 'modules/reportes/index.php',
                    'objetivos' => [
                        'Moverse por los bloques de reportes.',
                        'Usar el selector de periodo y el alcance por sucursal.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Los bloques',
                            'd' => 'Dirección general, finanzas, contabilidad, operación y ventas, cumplimiento sanitario. Cada bloque tiene su permiso, así que verás solo los que te tocan.',
                            'ruta' => 'modules/reportes/index.php',
                        ],
                        [
                            't' => 'Periodo y alcance: los dos filtros de todo',
                            'd' => 'Todos los reportes comparten la misma barra: un selector de periodo (hoy, ayer, esta semana, este mes, mes pasado, trimestre, año, o un rango a medida) y el alcance por sucursal. Cambiarlos en un reporte y saltar a otro conserva la selección.',
                            'tip' => 'Casi todos los reportes comparan contra el periodo anterior equivalente. Ese porcentaje de variación suele decir más que la cifra absoluta.',
                        ],
                        [
                            't' => 'Un informe puede tener permiso propio',
                            'd' => 'Algunos informes están dentro de un bloque pero llevan su propio permiso: comparar sucursales, existencias, vencimientos, nómina. Así una encargada de local puede ver el comparativo de sucursales sin que se le abra toda la utilidad de la empresa.',
                        ],
                        [
                            't' => 'Todo se exporta',
                            'd' => 'Excel para seguir trabajándolo, PDF con el logo para enviarlo a la contabilidad externa, al banco o a una inspección.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Ves un solo informe dentro de un bloque de reportes. ¿Qué significa?',
                            'ops' => ['El bloque está incompleto', 'Tienes el permiso propio de ese informe, pero no el del bloque completo', 'Hay un error', 'Falta filtrar'],
                            'ok'  => 1,
                            'exp' => 'Ciertos informes tienen permiso propio para no obligar a abrir todo el bloque a quien solo necesita uno.',
                        ],
                    ],
                ],

                'criterio' => [
                    'titulo'   => 'El criterio contable: por qué las cifras cuadran entre sí',
                    'resumen'  => 'Las cuatro reglas que todos los informes respetan. Si no las conoces, vas a creer que los números están mal.',
                    'permiso'  => 'reportes.ver',
                    'minutos'  => 8,
                    'objetivos' => [
                        'Definir ingreso, utilidad bruta y gasto operativo como los define el sistema.',
                        'Explicar por qué la venta del POS no es igual al ingreso del reporte.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Regla 1 — El ingreso NO incluye ITBIS',
                            'd' => 'Ingreso = subtotal − descuento. El ITBIS se recauda para la DGII y nunca fue del negocio. Por eso el total del punto de venta (que sí lleva ITBIS) es mayor que el ingreso del reporte. No es un error: son dos cifras distintas midiendo cosas distintas.',
                        ],
                        [
                            't' => 'Regla 2 — La utilidad bruta usa el costo congelado de la venta',
                            'd' => 'Utilidad bruta = ingresos − costo de lo vendido, y ese costo es el que tenía el producto EL DÍA de la venta, no el de hoy. Si se usara el costo actual, el margen de todo el año pasado cambiaría cada vez que llega una importación con otro precio.',
                        ],
                        [
                            't' => 'Regla 3 — Los gastos operativos no son «todas las transacciones de tipo gasto»',
                            'd' => 'Ese total incluiría la compra de mercancía (que es inventario, no gasto) y las devoluciones (que ya restaron del ingreso). El sistema tiene un criterio propio para los gastos operativos y todos los informes lo usan, y por eso el análisis de gastos, el estado de resultados y el panel ejecutivo dan el mismo número.',
                            'aviso' => 'Si sumas a mano las transacciones de gasto y te da distinto que el reporte, el reporte tiene razón. Esa es exactamente la diferencia.',
                        ],
                        [
                            't' => 'Regla 4 — Un cobro no es un ingreso',
                            'd' => 'Cuando un cliente abona una venta a crédito de la semana pasada, entra efectivo pero NO hay ingreso nuevo: el ingreso se registró el día de la venta. Contarlo dos veces duplicaría la operación. Por eso el flujo de efectivo y el estado de resultados nunca dan lo mismo, y está bien que así sea.',
                        ],
                        [
                            't' => 'La consecuencia práctica',
                            'd' => 'Cuatro informes distintos van a dar cuatro cifras distintas para «cuánto vendimos este mes», y las cuatro son correctas:',
                            'campos' => [
                                'Total facturado (POS)' => 'Con ITBIS. Es lo que cobró la caja.',
                                'Ingreso (reportes)'    => 'Sin ITBIS y sin descuentos. Es lo que ganó el negocio por vender.',
                                'Entrada de efectivo'   => 'Solo lo cobrado en efectivo y los abonos. Excluye el crédito.',
                                'Utilidad bruta'        => 'Ingreso menos el costo de la mercancía vendida.',
                            ],
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'El POS dice que hoy se facturaron RD$ 118,000 y el reporte de ingresos dice RD$ 100,000. ¿Qué pasa?',
                            'ops' => ['Faltan ventas por registrar', 'El reporte excluye el ITBIS, que no es ingreso del negocio', 'Hay ventas anuladas', 'El reporte está mal'],
                            'ok'  => 1,
                            'exp' => 'El total facturado lleva ITBIS; el ingreso no. Son dos medidas distintas de la misma jornada.',
                        ],
                        [
                            'p'   => '¿Por qué el flujo de efectivo del mes no coincide con la utilidad?',
                            'ops' => ['Por un error de cálculo', 'Porque las ventas a crédito son ingreso sin efectivo, y los pagos a proveedores son efectivo sin gasto', 'Porque el flujo incluye ITBIS', 'Porque se calculan en fechas distintas'],
                            'ok'  => 1,
                            'exp' => 'Ganar dinero y tener dinero son cosas distintas. Los dos informes existen precisamente para mostrar esa diferencia.',
                        ],
                    ],
                ],

                'ejecutivo' => [
                    'titulo'   => 'El panel ejecutivo',
                    'resumen'  => 'La foto del negocio en una pantalla: KPIs, tendencia, márgenes, metas y alertas.',
                    'permiso'  => 'reportes.ejecutivo',
                    'minutos'  => 7,
                    'pantalla' => 'modules/reportes/ejecutivo.php',
                    'objetivos' => [
                        'Leer los KPIs y sus variaciones.',
                        'Saber por dónde profundizar cuando algo se sale de lo normal.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Qué muestra',
                            'd' => 'Ventas del periodo, utilidad bruta y su porcentaje, gastos operativos, resultado neto, la tendencia de los últimos doce meses, el cumplimiento de metas y las alertas abiertas. Todo con la variación contra el periodo anterior.',
                            'ruta' => 'modules/reportes/ejecutivo.php',
                        ],
                        [
                            't' => 'Cómo se lee',
                            'd' => 'Empieza por el porcentaje de margen, no por la venta. Vender más con menos margen puede dar menos utilidad que vender menos con buen margen, y es una situación que la cifra de ventas sola esconde.',
                        ],
                        [
                            't' => 'Cuando algo se sale de lo normal',
                            'd' => 'El panel dice QUÉ pasó; los informes de detalle dicen POR QUÉ.',
                            'campos' => [
                                'Cayó el margen'    => 'Rentabilidad (¿qué se vendió con poco margen?) y Ajustes y mermas.',
                                'Subieron gastos'   => 'Análisis de gastos por categoría y sucursal.',
                                'Cayó la venta'     => 'Desempeño de productos, comparativo de sucursales y desempeño del equipo.',
                                'Falta efectivo'    => 'Flujo de efectivo y cuentas por cobrar.',
                            ],
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Las ventas suben 15% y la utilidad baja. ¿Por dónde empiezas a investigar?',
                            'ops' => ['El flujo de efectivo', 'El margen: rentabilidad por producto y descuentos', 'Las cuentas por pagar', 'La nómina'],
                            'ok'  => 1,
                            'exp' => 'Vender más con peor margen es el caso clásico. Rentabilidad y descuentos lo explican casi siempre.',
                        ],
                    ],
                ],

                'direccion' => [
                    'titulo'   => 'El área de Dirección',
                    'resumen'  => 'Año contra año, reportería de costos y mercancía en camino: el tablero de quien decide.',
                    'permiso'  => 'direccion.ver',
                    'minutos'  => 8,
                    'pantalla' => 'modules/direccion/index.php',
                    'objetivos' => [
                        'Leer el comparativo interanual.',
                        'Usar la reportería de costos para detectar ventas bajo costo.',
                    ],
                    'pasos' => [
                        [
                            't' => 'El panel',
                            'd' => 'Año contra año, mes contra mes, ventas por marca y mercancía en camino, en una sola pantalla.',
                            'ruta' => 'modules/direccion/index.php',
                        ],
                        [
                            't' => 'Año contra año',
                            'd' => 'La matriz de doce meses con los dos años lado a lado y la variación de cada mes, desglosable por tienda, sucursal y categoría. Es la vista que responde «¿vamos mejor o peor que el año pasado, y en qué exactamente?».',
                            'ruta' => 'modules/direccion/comparativo.php',
                        ],
                        [
                            't' => 'Reportería de costos',
                            'd' => 'Costo de lo vendido, margen real, inventario a costo, recargo de importación y —la más importante— los artículos que se están vendiendo BAJO COSTO.',
                            'ruta' => 'modules/direccion/costos.php',
                            'aviso' => 'Vender bajo costo casi nunca es una decisión: es un costo de importación que se aplicó tarde, un precio que no se actualizó tras una subida del dólar, o un descuento excesivo. Ese informe existe para encontrarlos antes de que pase un trimestre.',
                        ],
                        [
                            't' => 'Cargar datos históricos',
                            'd' => 'Permite subir un año entero de clientes y ventas de un sistema anterior, y también revertirlo. Por eso `direccion.importar` es un permiso separado de `direccion.ver`: sube y borra datos masivamente.',
                            'ruta' => 'modules/direccion/importar.php',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Un artículo aparece vendiéndose bajo costo. ¿Cuál NO es una causa típica?',
                            'ops' => ['Una liquidación de importación aplicada después de empezar a vender', 'El precio no se actualizó tras subir el dólar', 'Un descuento excesivo', 'El sistema calculó mal el ITBIS'],
                            'ok'  => 3,
                            'exp' => 'Las tres primeras son las causas reales. El ITBIS no entra en el margen: el ingreso se mide sin él.',
                        ],
                    ],
                ],
            ],
        ],

        /* =====================================================================
         *  10. MARKETING
         * ===================================================================== */
        'marketing' => [
            'titulo'      => 'Marketing',
            'descripcion' => 'Campañas por correo automáticas, envíos asistidos por WhatsApp, segmentos, plantillas y promociones.',
            'icono'       => 'megaphone',
            'color'       => 'pink',
            'nivel'       => 'Intermedio',
            'permiso'     => ['marketing.ver', 'campanas.ver', 'promociones.ver'],
            'para_quien'  => 'Quien lleva la comunicación con clientes y las promociones.',
            'lecciones'   => [

                'panel' => [
                    'titulo'   => 'Cómo funciona el marketing aquí',
                    'resumen'  => 'Dos canales con reglas muy distintas: el correo se envía solo, WhatsApp no.',
                    'permiso'  => 'marketing.ver',
                    'minutos'  => 6,
                    'pantalla' => 'modules/marketing/index.php',
                    'objetivos' => [
                        'Distinguir qué es automático y qué es asistido.',
                        'Leer los resultados de una campaña.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Correo: automático de verdad',
                            'd' => 'Las campañas por correo se envían solas, se reanudan si se corta el envío a medias y registran quién abrió y quién hizo clic.',
                            'ruta' => 'modules/marketing/index.php',
                        ],
                        [
                            't' => 'WhatsApp: asistido, no automático',
                            'd' => 'Esto hay que tenerlo claro antes de prometer nada. El sistema prepara la conversación con el texto ya escrito y una persona pulsa enviar, uno por uno. Todo lo demás está automatizado —la lista, el orden, el texto personalizado, el registro de a quién ya se le mandó— pero el envío en sí lo hace una persona.',
                            'aviso' => 'No prometas «envío masivo automático por WhatsApp». No existe por esta vía, y prometerlo genera una expectativa que después hay que desmentir.',
                        ],
                        [
                            't' => 'Un destinatario, una fila',
                            'd' => 'Cada persona a la que se le va a escribir tiene su propio registro dentro de la campaña. De ahí salen tres cosas: que el envío se reanude tras un corte, que nadie reciba dos veces lo mismo, y la cola de WhatsApp que va marcando a quién ya se le escribió.',
                        ],
                        [
                            't' => 'Los resultados',
                            'd' => 'Enviados, entregados, abiertos y clics por campaña. Sirven para comparar asuntos y horarios entre campañas, no para juzgar una sola.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'El dueño pide «mandar la promoción por WhatsApp a los 800 clientes automáticamente». ¿Qué respondes?',
                            'ops' => ['Que se puede programar y sale solo', 'Que el sistema prepara cada conversación con el texto listo, pero una persona pulsa enviar', 'Que WhatsApp no se puede usar', 'Que hay que hacerlo a mano desde cero'],
                            'ok'  => 1,
                            'exp' => 'La vía que usa el sistema abre la conversación con el mensaje escrito; el envío lo hace una persona. Todo lo demás sí está automatizado.',
                        ],
                    ],
                ],

                'segmentos' => [
                    'titulo'   => 'Segmentos de clientes',
                    'resumen'  => 'A quién le hablas: reglas que arman la lista sola y se mantienen al día.',
                    'permiso'  => 'marketing.segmentos',
                    'minutos'  => 6,
                    'pantalla' => 'modules/marketing/segmentos.php',
                    'objetivos' => [
                        'Crear un segmento con reglas.',
                        'Elegir criterios que produzcan listas útiles.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Un segmento es una regla, no una lista',
                            'd' => 'No se escriben nombres: se escribe la condición («clientes que compraron en los últimos 90 días», «clientes con saldo pendiente», «clientes de esta sucursal que nunca han comprado esta categoría»). La lista se recalcula cada vez que se usa.',
                            'ruta' => 'modules/marketing/segmentos.php',
                        ],
                        [
                            't' => 'Segmentos que funcionan',
                            'd' => 'Los que se pueden accionar. «Todos los clientes» no es un segmento, es una lista de correo.',
                            'campos' => [
                                'Recuperación'   => 'Compraron alguna vez y llevan meses sin volver.',
                                'Fidelización'   => 'Compran seguido: merecen un trato distinto al de un desconocido.',
                                'Cumpleaños'     => 'Con la fecha en la ficha, la felicitación se automatiza.',
                                'Por categoría'  => 'Quien compra una línea concreta y podría interesarse por otra.',
                            ],
                        ],
                        [
                            't' => 'Antes de enviar, revisa el tamaño',
                            'd' => 'El segmento muestra cuántos clientes cumplen. Si da cero o da todos, la regla está mal planteada.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => '¿Por qué un segmento se define con reglas y no con una lista de nombres?',
                            'ops' => ['Por rapidez', 'Para que se mantenga al día solo cuando cambian los datos del cliente', 'Por limitación técnica', 'Para poder exportarlo'],
                            'ok'  => 1,
                            'exp' => 'Una lista fija envejece el mismo día que se hace. Una regla se recalcula sola cada vez que se usa.',
                        ],
                    ],
                ],

                'campanas' => [
                    'titulo'   => 'Campañas por correo',
                    'resumen'  => 'Armar, probar y enviar, sin quemar la lista.',
                    'permiso'  => 'campanas.ver',
                    'minutos'  => 7,
                    'pantalla' => 'modules/marketing/campanas.php',
                    'objetivos' => [
                        'Crear una campaña completa y enviarla.',
                        'Interpretar los resultados y respetar las bajas.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Los cuatro elementos',
                            'd' => 'Una campaña necesita: un segmento (a quién), una plantilla (qué se ve), un asunto (si se abre o no) y un momento de envío.',
                            'ruta' => 'modules/marketing/campanas.php',
                        ],
                        [
                            't' => 'Prueba antes de enviar',
                            'd' => 'Manda siempre una prueba a tu propio correo y ábrela en el teléfono. La mayoría de la gente lee el correo ahí, y un diseño que se ve bien en la computadora puede ser ilegible en una pantalla de cinco pulgadas.',
                            'tip' => 'Revisa también que los enlaces lleven a donde deben. Un enlace roto en una campaña de 800 personas no se puede corregir después de enviarla.',
                        ],
                        [
                            't' => 'Enviar',
                            'd' => 'Requiere `campanas.enviar`. El envío avanza solo y se puede seguir en la pantalla. Si se corta, se reanuda desde donde iba: nadie recibe dos veces.',
                        ],
                        [
                            't' => 'La baja es sagrada',
                            'd' => 'Cada correo lleva su enlace para darse de baja. Quien se da de baja deja de recibir, y eso no se revierte a mano.',
                            'aviso' => 'Ignorar las bajas o comprar listas es la forma más rápida de que el dominio del negocio termine marcado como spam. A partir de ahí ni siquiera las facturas por correo llegan.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Se corta el envío de una campaña a mitad de camino. ¿Qué pasa al reanudarla?',
                            'ops' => ['Empieza de nuevo desde el primero', 'Sigue desde donde iba, sin repetir a nadie', 'Hay que crearla otra vez', 'Se cancela'],
                            'ok'  => 1,
                            'exp' => 'Cada destinatario tiene su propio registro, y eso es lo que permite reanudar sin duplicar.',
                        ],
                    ],
                ],

                'automatizaciones' => [
                    'titulo'   => 'Automatizaciones y promociones',
                    'resumen'  => 'Correos que se disparan solos con un hecho del negocio, y las promociones que aplica el punto de venta.',
                    'permiso'  => ['marketing.automatizar', 'promociones.ver'],
                    'minutos'  => 7,
                    'pantalla' => 'modules/marketing/automatizaciones.php',
                    'objetivos' => [
                        'Encender una automatización con criterio.',
                        'Configurar una promoción que el POS aplique sola.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Qué es una automatización',
                            'd' => 'Un correo que se dispara con un hecho: un cumpleaños, un cliente que lleva meses sin volver, una primera compra. No hay que acordarse de nada: se enciende una vez y funciona.',
                            'ruta' => 'modules/marketing/automatizaciones.php',
                        ],
                        [
                            't' => 'Las automatizaciones no envían: encolan',
                            'd' => 'Detectan a quién le toca y lo meten en la campaña del periodo. Cada persona tiene un «periodo» que impide repetirle: nadie recibe dos veces la felicitación del mismo cumpleaños aunque el proceso corra varias veces.',
                        ],
                        [
                            't' => 'Promociones',
                            'd' => 'Son otra cosa: descuentos que el punto de venta aplica solo cuando se cumple la condición (por producto, por categoría, por monto, por fecha). No se le pide al cajero que se acuerde.',
                            'ruta' => 'modules/marketing/promociones.php',
                            'aviso' => 'Una promoción baja el margen de cada venta que toca. Antes de encenderla, mira el margen actual de esos artículos en la reportería de costos: hay promociones que dejan el artículo por debajo del costo.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'El proceso de automatizaciones corre tres veces en un día. ¿Alguien recibe tres correos?',
                            'ops' => ['Sí', 'No: el «periodo» de cada persona impide repetirle', 'Solo si tiene dos correos', 'Depende del segmento'],
                            'ok'  => 1,
                            'exp' => 'La clave de periodo por persona es justamente lo que impide la repetición.',
                        ],
                    ],
                ],
            ],
        ],

        /* =====================================================================
         *  11. CUMPLIMIENTO SANITARIO
         * ===================================================================== */
        'sanidad' => [
            'titulo'      => 'Cumplimiento sanitario',
            'descripcion' => 'La evidencia que piden Salud Pública, PROCONSUMIDOR, Agricultura e INDOCAL — y cómo tenerla lista.',
            'icono'       => 'shield',
            'color'       => 'rose',
            'nivel'       => 'Avanzado',
            'permiso'     => ['sanidad.ver', 'reportes.sanidad', 'reportes.vencimientos'],
            'para_quien'  => 'Almacén, calidad y quien atiende una inspección.',
            'lecciones'   => [

                'inspeccion' => [
                    'titulo'   => 'Qué pide una inspección y dónde está cada respuesta',
                    'resumen'  => 'Una inspección sanitaria es documental: piden ver, en el momento. No hay archivo que enviar.',
                    'permiso'  => 'sanidad.ver',
                    'minutos'  => 7,
                    'pantalla' => 'modules/inventario/lotes.php',
                    'objetivos' => [
                        'Responder las tres preguntas que siempre aparecen.',
                        'Saber por qué aquí no hay «archivo oficial» como en la DGII.',
                    ],
                    'pasos' => [
                        [
                            't' => 'No hay formato oficial, hay evidencia',
                            'd' => 'A diferencia de la DGII —que publica formatos de archivo—, ninguna de estas entidades define un formato digital oficial. Una inspección es documental: el inspector pide VER papeles y datos en el momento. Por eso el módulo no genera «el archivo de Salud Pública»: ordena la evidencia para poder mostrarla e imprimirla.',
                        ],
                        [
                            't' => 'Las tres preguntas de siempre',
                            'd' => 'Aparecen en casi todas las inspecciones, y cada una tiene su pantalla:',
                            'campos' => [
                                '¿Este producto tiene registro sanitario vigente?' => 'Reportes → Registros sanitarios',
                                '¿Hay mercancía vencida a la venta?'               => 'Reportes → Control de vencimientos',
                                'Si un lote sale malo, ¿a quién se le vendió?'     => 'Reportes → Trazabilidad de lote',
                            ],
                        ],
                        [
                            't' => 'El expediente de auditoría',
                            'd' => 'Reúne todo lo anterior en un solo documento con un semáforo de cumplimiento: registros, vencidos, proveedores y sus licencias. Es lo que se imprime y se entrega cuando llega una inspección sin avisar.',
                            'ruta' => 'modules/reportes/expediente_auditoria.php',
                            'tip' => 'Genera el expediente una vez al mes aunque no haya inspección. Es la forma de descubrir los huecos con tiempo, y no con el inspector delante.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => '¿Por qué no existe un «archivo de Salud Pública» como el 606 de la DGII?',
                            'ops' => ['Está pendiente de implementar', 'Porque ninguna de esas entidades define un formato digital: la inspección es documental', 'Porque no aplica a este negocio', 'Porque lo genera el contador'],
                            'ok'  => 1,
                            'exp' => 'Piden ver datos y papeles en el momento. Por eso el módulo produce evidencia ordenada e imprimible, no un archivo de envío.',
                        ],
                    ],
                ],

                'lotes-control' => [
                    'titulo'   => 'Bloquear un lote y dar de baja mercancía vencida',
                    'resumen'  => 'Retirar del mercado y sacar del stock lo que no se puede vender.',
                    'permiso'  => 'sanidad.ver',
                    'minutos'  => 6,
                    'pantalla' => 'modules/inventario/lotes.php',
                    'objetivos' => [
                        'Bloquear y liberar un lote.',
                        'Dar de baja mercancía vencida correctamente.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Bloquear un lote',
                            'd' => 'Requiere `sanidad.bloquear`. Un lote bloqueado no se puede vender: el punto de venta lo impide. Se usa cuando el fabricante hace un retiro del mercado o cuando hay una sospecha de calidad.',
                            'ruta' => 'modules/inventario/lotes.php',
                            'aviso' => 'Bloquear es inmediato y para todos los locales. Es la acción correcta ante una duda: liberar después cuesta un clic, vender un lote retirado cuesta mucho más.',
                        ],
                        [
                            't' => 'Dar de baja lo vencido',
                            'd' => 'Requiere `sanidad.baja`. Saca la mercancía del stock y registra la pérdida con su costo. Queda en el informe de ajustes y mermas.',
                        ],
                        [
                            't' => 'Lo vencido no se «ajusta»',
                            'd' => 'Usa la baja sanitaria, no un ajuste genérico de inventario. La diferencia importa: la baja deja constancia de POR QUÉ salió esa mercancía, y esa constancia es lo que se muestra en una inspección.',
                        ],
                        [
                            't' => 'Antes de que venza',
                            'd' => 'El informe de vencimientos muestra lo que está por caducar con el dinero inmovilizado. Eso todavía se puede rotar hacia el local que más lo mueve, promocionar o devolver al proveedor si el acuerdo lo permite. Vencido ya no hay nada que hacer.',
                            'ruta' => 'modules/reportes/vencimientos.php',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Sospechas de un lote pero no estás seguro. ¿Qué haces?',
                            'ops' => ['Esperar la confirmación antes de tocar nada', 'Bloquearlo: liberar después es fácil, vender un lote malo no tiene vuelta', 'Darlo de baja', 'Bajarle el precio'],
                            'ok'  => 1,
                            'exp' => 'El bloqueo es reversible e inmediato. Ante la duda se bloquea, y se libera cuando se confirma que está bien.',
                        ],
                    ],
                ],
            ],
        ],

        /* =====================================================================
         *  12. ADMINISTRACIÓN DEL SISTEMA
         * ===================================================================== */
        'admin' => [
            'titulo'      => 'Administración del sistema',
            'descripcion' => 'Sucursales, marcas, usuarios, roles, seguridad, auditoría, integridad y respaldo.',
            'icono'       => 'settings',
            'color'       => 'slate',
            'nivel'       => 'Avanzado',
            'permiso'     => ['usuarios.ver', 'roles.ver', 'configuracion.ver', 'sucursales.ver', 'tiendas.ver', 'auditoria.ver'],
            'para_quien'  => 'Administradores del sistema y responsables de seguridad.',
            'lecciones'   => [

                'sucursales' => [
                    'titulo'   => 'Sucursales y tiendas',
                    'resumen'  => 'Crear locales y marcas comerciales, y por qué son cosas independientes.',
                    'permiso'  => ['sucursales.ver', 'tiendas.ver'],
                    'minutos'  => 7,
                    'pantalla' => 'modules/admin/sucursales.php',
                    'objetivos' => [
                        'Crear una sucursal con sus cajas.',
                        'Configurar una tienda con su identidad visual.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Sucursal: el local',
                            'd' => 'Nombre, dirección, teléfono y sus cajas. Es un límite de seguridad: gobierna el stock, la caja, los usuarios y los permisos. Un usuario asignado a una sucursal no ve las otras, y eso no se puede burlar desde la URL.',
                            'ruta' => 'modules/admin/sucursales.php',
                        ],
                        [
                            't' => 'Tienda: la marca',
                            'd' => 'Logo, colores, dirección impresa y política de devolución que salen en el comprobante. NO es un límite de seguridad: solo cambia el papel.',
                            'ruta' => 'modules/admin/tiendas.php',
                        ],
                        [
                            't' => 'El interruptor: mientras no haya tiendas, nada cambia',
                            'd' => 'Sin ninguna tienda creada, el punto de venta no pide elegir marca, el catálogo no se filtra y los comprobantes salen con los datos de la empresa. Se pueden crear tiendas cuando el negocio lo necesite, no antes.',
                        ],
                        [
                            't' => 'El emisor fiscal sigue siendo la empresa',
                            'd' => 'Un solo RNC y una sola secuencia de comprobantes, aunque haya diez marcas. La tienda pone la marca en el papel, no en la declaración.',
                            'aviso' => 'Si algún día cada marca fuera una razón social distinta, habría que rehacer los NCF, los reportes 606/607 y la facturación electrónica. Hoy no es el caso, y la configuración actual asume que no lo es.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'La empresa distribuye cuatro marcas. ¿Cuántos RNC y secuencias de NCF usa el sistema?',
                            'ops' => ['Cuatro de cada uno', 'Uno de cada uno: el emisor fiscal es la empresa', 'Uno por sucursal', 'Depende de la tienda'],
                            'ok'  => 1,
                            'exp' => 'La tienda cambia el logo y los datos impresos. El emisor fiscal, el RNC y la secuencia siguen siendo los de la empresa.',
                        ],
                    ],
                ],

                'usuarios' => [
                    'titulo'   => 'Usuarios',
                    'resumen'  => 'Crear cuentas, asignar rol y sucursal, y dar de baja bien.',
                    'permiso'  => 'usuarios.ver',
                    'minutos'  => 6,
                    'pantalla' => 'modules/admin/usuarios.php',
                    'objetivos' => [
                        'Crear un usuario con el alcance correcto.',
                        'Entender qué significa dejar la sucursal vacía.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Los tres campos que definen el acceso',
                            'd' => 'Todo lo demás es contacto. Estos tres deciden hasta dónde llega la persona:',
                            'campos' => [
                                'Rol'       => 'El paquete de permisos. Determina qué puede hacer.',
                                'Sucursal'  => 'Su alcance. Una sucursal concreta lo limita a ese local; VACÍA significa acceso a TODAS las sucursales.',
                                'Activo'    => 'Si puede entrar. Desactivar es la forma correcta de dar de baja.',
                            ],
                            'ruta' => 'modules/admin/usuarios.php',
                            'aviso' => 'Dejar la sucursal vacía es la equivocación más frecuente y la más silenciosa: se le da alcance global a un cajero sin querer, y nadie lo nota hasta que ve las cifras de todos los locales.',
                        ],
                        [
                            't' => 'Dar de baja: desactivar, nunca borrar',
                            'd' => 'Un usuario que se elimina se lleva por delante la trazabilidad: sus ventas, sus ajustes y sus registros de auditoría quedan sin dueño. Desactivar le cierra la puerta y conserva todo el historial.',
                        ],
                        [
                            't' => 'Cuando alguien sale de la empresa',
                            'd' => 'Tres cosas, el mismo día: desactivar el usuario, revocar sus equipos de confianza desde Seguridad de acceso, y revisar si tenía una caja abierta sin cerrar.',
                        ],
                        [
                            't' => 'Comisión',
                            'd' => 'Si la persona vende, su porcentaje de comisión se configura aquí. De ahí sale el cálculo en el módulo de comisiones.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => 'Creas un cajero y dejas el campo de sucursal vacío. ¿Qué acabas de hacer?',
                            'ops' => ['Asignarlo a la sucursal principal', 'Darle acceso a TODAS las sucursales', 'Dejarlo sin acceso', 'Nada, es opcional'],
                            'ok'  => 1,
                            'exp' => 'Sucursal vacía significa alcance global. Es el error más silencioso de esta pantalla.',
                        ],
                    ],
                ],

                'roles' => [
                    'titulo'   => 'Roles y permisos',
                    'resumen'  => 'Armar paquetes de permisos con criterio, sin abrir de más ni dejar a la gente sin trabajar.',
                    'permiso'  => 'roles.ver',
                    'minutos'  => 9,
                    'pantalla' => 'modules/admin/roles.php',
                    'objetivos' => [
                        'Crear un rol partiendo del trabajo real de un puesto.',
                        'Respetar las separaciones de funciones que el sistema propone.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Cómo se arma un rol',
                            'd' => 'La pantalla lista todos los permisos agrupados por módulo, con su acción y una descripción de lo que abre. Se marcan los que el puesto necesita.',
                            'ruta' => 'modules/admin/roles.php',
                        ],
                        [
                            't' => 'El método: parte del trabajo, no de la pantalla',
                            'd' => 'Escribe primero, en una frase, qué hace esa persona en el día. Después marca solo lo que esa frase necesita. Ir marcando módulos «por si acaso» produce roles que abren la mitad del sistema.',
                            'campos' => [
                                'Cajero'          => 'Vender, abrir y cerrar su caja, ver y crear clientes, crear devoluciones. Nada de costos, compras ni reportes financieros.',
                                'Encargado de local' => 'Lo del cajero más stock, ajustes, recibir transferencias, ver el comparativo de sucursales.',
                                'Almacén'         => 'Productos, stock, movimientos, conteos, compras, transferencias. Nada de finanzas.',
                                'Contabilidad'    => 'Finanzas, reportes contables, DGII. Nada de vender ni de ajustar stock.',
                                'RRHH'            => 'Empleados, asistencia, nómina, TSS, vacaciones, préstamos. Nada de inventario ni ventas.',
                            ],
                        ],
                        [
                            't' => 'Las separaciones que conviene no romper',
                            'd' => 'El sistema separa ciertos permisos a propósito. Dárselos todos a la misma persona anula el control:',
                            'lista' => [
                                'Crear transferencia y aprobarla.',
                                'Capturar un conteo y aplicarlo.',
                                'Crear una liquidación y aplicarla.',
                                'Generar comisiones, aprobarlas y pagarlas.',
                                'Ver el e-CF y configurar sus secuencias.',
                                'Configurar el sistema y cambiar la política de seguridad de acceso.',
                            ],
                            'aviso' => 'En un negocio pequeño a veces no hay más remedio que juntar dos de estas en una persona. Si es el caso, que sea una decisión consciente y documentada, no el resultado de marcar todo.',
                        ],
                        [
                            't' => 'El rol de super administrador',
                            'd' => 'Tiene todo, siempre, sin necesidad de marcar nada. Debe existir, pero deberían tenerlo el menor número posible de personas: para el trabajo diario, incluso el dueño está mejor con un rol amplio pero acotado.',
                        ],
                        [
                            't' => 'Probar el rol',
                            'd' => 'Después de crear un rol, pídele a alguien que lo use un día antes de asignarlo a diez personas. Es la única forma de descubrir el permiso que falta — y también el que sobra.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => '¿Cuál de estas combinaciones anula un control interno?',
                            'ops' => ['Ver stock y ver productos', 'Crear transferencias y aprobarlas', 'Vender y ver clientes', 'Ver reportes y exportarlos'],
                            'ok'  => 1,
                            'exp' => 'Quien pide el traslado no debería autorizarlo: si es la misma persona, la aprobación deja de ser un control.',
                        ],
                    ],
                ],

                'configuracion' => [
                    'titulo'   => 'Configuración y seguridad de acceso',
                    'resumen'  => 'Los datos de la empresa, el logo, y la política de verificación en dos pasos.',
                    'permiso'  => 'configuracion.ver',
                    'minutos'  => 7,
                    'pantalla' => 'modules/admin/configuracion.php',
                    'objetivos' => [
                        'Configurar los datos que salen en los comprobantes.',
                        'Ajustar la política de segundo factor y revocar equipos.',
                    ],
                    'pasos' => [
                        [
                            't' => 'Los datos de la empresa',
                            'd' => 'Nombre, RNC, dirección, teléfono, correo y logo. Es lo que sale impreso en facturas, cotizaciones y reportes en PDF.',
                            'ruta' => 'modules/admin/configuracion.php',
                            'aviso' => 'El RNC de la empresa es el emisor fiscal de todos los comprobantes. Cambiarlo con documentos ya emitidos es una decisión fiscal, no un ajuste de configuración.',
                        ],
                        [
                            't' => 'Parámetros de operación',
                            'd' => 'Moneda, tasa de ITBIS por defecto, y otros valores que el sistema usa como punto de partida. Cambiarlos afecta a lo nuevo, no a lo ya registrado.',
                        ],
                        [
                            't' => 'Seguridad de acceso',
                            'd' => 'Pantalla aparte con su propio permiso `seguridad.gestionar`, separado de la configuración general. Desde aquí se define cuándo se pide el segundo factor, se exime a un usuario y se revocan los equipos marcados como de confianza.',
                            'ruta' => 'modules/admin/seguridad.php',
                            'aviso' => 'Está separada a propósito: quien puede eximir a un usuario del segundo factor puede debilitar el acceso de todos. No es lo mismo que cambiar el logo.',
                        ],
                        [
                            't' => 'Revocar equipos',
                            'd' => 'Cuando alguien pierde un teléfono, deja la empresa o hay sospecha de acceso indebido, se revocan sus equipos de confianza. La próxima vez que entre desde ahí, tendrá que verificar con código otra vez.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => '¿Por qué la seguridad de acceso tiene un permiso distinto al de configuración?',
                            'ops' => ['Por organización de la pantalla', 'Porque quien exime a alguien del segundo factor puede debilitar el acceso de todos', 'Porque la usa otro módulo', 'Por rendimiento'],
                            'ok'  => 1,
                            'exp' => 'Es un permiso de otra categoría: no toca la apariencia del sistema, toca quién puede entrar y cómo.',
                        ],
                    ],
                ],

                'auditoria' => [
                    'titulo'   => 'Auditoría, integridad y respaldo',
                    'resumen'  => 'El rastro de todo lo que pasó, la verificación de coherencia y la copia de seguridad.',
                    'permiso'  => ['auditoria.ver', 'configuracion.ver'],
                    'minutos'  => 8,
                    'pantalla' => 'modules/admin/auditoria.php',
                    'objetivos' => [
                        'Investigar un hecho con la auditoría.',
                        'Correr la verificación de integridad y hacer un respaldo.',
                    ],
                    'pasos' => [
                        [
                            't' => 'La auditoría',
                            'd' => 'Registra quién hizo qué, cuándo y desde dónde: inicios de sesión, creaciones, cambios, anulaciones, aprobaciones, cambios de permisos. No se puede editar ni borrar desde la aplicación.',
                            'ruta' => 'modules/admin/auditoria.php',
                        ],
                        [
                            't' => 'Cómo se investiga algo',
                            'd' => 'Filtra por usuario, por módulo o por rango de fechas. La secuencia que casi siempre funciona: parte del documento o el dato que está mal, busca su registro de auditoría, y desde ahí sigue al usuario y a la hora.',
                            'tip' => 'La auditoría también sirve para lo contrario: demostrar que alguien NO hizo algo. Es tan útil para exculpar como para encontrar.',
                        ],
                        [
                            't' => 'Verificación de integridad',
                            'd' => 'Revisa la coherencia interna de los datos: ventas sin detalle, stock que no cuadra con sus movimientos, documentos huérfanos, secuencias de comprobantes con saltos. Correrla una vez al mes detecta problemas mientras todavía se pueden explicar.',
                            'ruta' => 'modules/admin/integridad.php',
                        ],
                        [
                            't' => 'Respaldo',
                            'd' => 'Genera una copia de la base de datos que se descarga. La regla es simple y no negociable:',
                            'lista' => [
                                'Antes de cualquier cambio grande: una migración, una carga masiva, aplicar una liquidación importante, limpiar datos.',
                                'De forma periódica, aunque no pase nada.',
                                'Guardada FUERA del servidor. Un respaldo que vive en el mismo sitio que el original no es un respaldo.',
                            ],
                            'ruta' => 'modules/admin/respaldo.php',
                            'aviso' => 'Un respaldo que nunca se ha restaurado es una suposición, no un respaldo. Prueba la restauración al menos una vez, en un entorno de prueba.',
                        ],
                    ],
                    'quiz' => [
                        [
                            'p'   => '¿Cuándo hay que hacer un respaldo?',
                            'ops' => ['Solo cuando el sistema avisa', 'Antes de cualquier cambio grande, y periódicamente, guardándolo fuera del servidor', 'Una vez al año', 'Solo antes de una actualización'],
                            'ok'  => 1,
                            'exp' => 'Antes de tocar algo grande y de forma periódica. Y fuera del servidor: si vive junto al original, no protege de nada.',
                        ],
                        [
                            'p'   => '¿Se puede borrar un registro de auditoría desde la aplicación?',
                            'ops' => ['Sí, con permiso de administrador', 'Sí, el super administrador puede', 'No: es un registro histórico y no se edita', 'Solo los de más de un año'],
                            'ok'  => 2,
                            'exp' => 'Un rastro que se puede borrar no es un rastro. La auditoría es de solo lectura desde la aplicación.',
                        ],
                    ],
                ],
            ],
        ],
    ];
}
