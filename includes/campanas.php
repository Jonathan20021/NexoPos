<?php
/**
 * OBSOLETO — el envío de campañas vive ahora en includes/marketing.php.
 *
 * Este archivo se queda solo como puente: había código (y despliegues a medio
 * actualizar) que lo cargaba directamente. Borrarlo rompería esas rutas sin
 * avisar, así que redirige al motor nuevo y no define nada propio.
 *
 * Equivalencias:
 *   campanaSegmentos()    → sigue existiendo en marketing.php (segmentos viejos)
 *   campanaDestinatarios()→ mkt_destinatarios($segmento, $canal)
 *   campanaConteo()       → mkt_conteo($segmento, $canal)
 *   campanaEnviar()       → mkt_construir_audiencia() + mkt_procesar_campana()
 */

require_once __DIR__ . '/marketing.php';
