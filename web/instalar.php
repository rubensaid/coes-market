<?php
declare(strict_types=1);

// Crea las tablas. Abrelo una vez en el navegador (o por cron/SSH: php bin/instalar.php). Pide la clave de config/local.php.
require __DIR__ . '/src/autoload.php';

use CuantoCoesta\{Acceso, Formato, Instalador};

Acceso::exigir('Instalar Cuánto COESta');
$pasos = Instalador::ejecutar();
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Instalación</title><link rel="stylesheet" href="assets/estilo.css"></head>
<body class="mantenimiento"><main class="caja">
<h1>Instalación</h1>
<ul class="pasos">
<?php foreach ($pasos as [$texto, $ok, $detalle]): ?>
<li class="<?= $ok ? 'ok' : 'error' ?>"><?= $ok ? '✔' : '✖' ?> <?= Formato::h($texto) ?><?= $detalle ? ' · ' . Formato::h($detalle) : '' ?></li>
<?php endforeach; ?>
</ul>
<p>Siguiente paso: abre <a href="diagnostico.php">diagnostico.php</a> y, cuando todo esté en verde, sube la primera semana desde Python.
Por seguridad, borra <code>instalar.php</code> cuando termines.</p>
</main></body></html>
