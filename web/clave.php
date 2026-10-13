<?php
declare(strict_types=1);

// Genera el valor de 'clave_hash' para el panel de administracion cuando no hay terminal en el hosting.
// Pide la clave de mantenimiento (token). Borra este archivo cuando termines.
require __DIR__ . '/src/autoload.php';

use CuantoCoesta\{Acceso, Formato};

Acceso::exigir('Generar clave del panel');
$hash = '';
if (!empty($_POST['nueva'])) {
    $hash = password_hash((string)$_POST['nueva'], PASSWORD_DEFAULT);
}
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Generar clave</title><link rel="stylesheet" href="assets/estilo.css"></head>
<body class="mantenimiento"><main class="caja">
<h1>Generar clave del panel</h1>
<form method="post"><input type="hidden" name="clave" value="<?= Formato::h($_POST['clave'] ?? '') ?>">
<label for="nueva">Clave nueva para admin.php</label><input type="password" id="nueva" name="nueva" autocomplete="new-password" required minlength="10">
<button type="submit">Generar</button></form>
<?php if ($hash): ?>
<p>Copia esto en <code>config/local.php</code>, en <code>'admin' => ['clave_hash' => ...]</code>:</p>
<p><code style="word-break:break-all"><?= Formato::h($hash) ?></code></p>
<?php endif; ?>
</main></body></html>
