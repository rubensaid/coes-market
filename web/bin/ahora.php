<?php
declare(strict_types=1);

// Cron cada 30 minutos (cPanel > Tareas Cron):  */30 * * * *  /usr/local/bin/php /home/TU_USUARIO/public_html/coesta/bin/ahora.php
// Opcion --desde-ayer vuelve a revisar desde ayer. Termina con codigo 1 si fallo alguna fuente.
if (PHP_SAPI !== 'cli') {
    exit('Solo linea de comandos.');
}
require dirname(__DIR__) . '/src/autoload.php';
set_time_limit(280);

$r = CuantoCoesta\Ahora\Ciclo::correr(in_array('--desde-ayer', $argv, true));
echo date('c') . ' ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
$fallo = false;
foreach (['costo_marginal', 'demanda'] as $fuente) {
    $fallo = $fallo || (isset($r[$fuente]) && !$r[$fuente]['ok']);
}
exit($fallo ? 1 : 0);
