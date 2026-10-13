<?php
declare(strict_types=1);

// Revisa extensiones, hora de Peru, base de datos, salida al COES y SMTP en TU hosting. Pide la clave de config/local.php.
require __DIR__ . '/src/autoload.php';

use CuantoCoesta\{Acceso, Config, Correo, Diagnostico, Formato};

Acceso::exigir('Diagnóstico del hosting');
$mensaje = null;
if (($_POST['accion'] ?? '') === 'correo') {
    $mensaje = Correo::probar();
}
$pruebas = Diagnostico::correr();
$errores = count(array_filter($pruebas, static fn($p) => $p[1] === Diagnostico::ERROR));
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Diagnóstico</title><link rel="stylesheet" href="assets/estilo.css"></head>
<body class="mantenimiento"><main class="caja ancha">
<h1>Diagnóstico del hosting</h1>
<p class="<?= $errores ? 'aviso-malo' : 'aviso-bueno' ?>"><?= $errores ? "$errores prueba(s) con error. Corrígelas antes de seguir." : 'Todo listo.' ?></p>
<table class="tabla"><thead><tr><th>Prueba</th><th>Estado</th><th>Detalle</th></tr></thead><tbody>
<?php foreach ($pruebas as [$nombre, $estado, $detalle]): ?>
<tr class="<?= Formato::h($estado) ?>"><td><?= Formato::h($nombre) ?></td>
<td><?= ['ok' => '✔ bien', 'aviso' => '! aviso', 'error' => '✖ error'][$estado] ?></td><td><?= Formato::h($detalle) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<?php if ($mensaje): ?><p class="<?= $mensaje['ok'] ? 'aviso-bueno' : 'aviso-malo' ?>"><?= Formato::h($mensaje['detalle']) ?></p><?php endif; ?>
<form method="post"><input type="hidden" name="clave" value="<?= Formato::h($_POST['clave'] ?? '') ?>">
<button name="accion" value="correo">Enviar correo de prueba</button></form>
<p class="nota"><?= Formato::h(Config::aviso()) ?></p>
</main></body></html>
