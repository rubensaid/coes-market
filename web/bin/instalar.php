<?php
declare(strict_types=1);

// php bin/instalar.php : crea las tablas desde la linea de comandos (SSH o cron de una sola vez)
if (PHP_SAPI !== 'cli') {
    exit('Solo linea de comandos. Desde el navegador usa instalar.php.');
}
require dirname(__DIR__) . '/src/autoload.php';

$fallo = false;
foreach (CuantoCoesta\Instalador::ejecutar() as [$texto, $ok, $detalle]) {
    echo ($ok ? '[ok] ' : '[ERROR] ') . $texto . ($detalle ? " · $detalle" : '') . "\n";
    $fallo = $fallo || !$ok;
}
exit($fallo ? 1 : 0);
