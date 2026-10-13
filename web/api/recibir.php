<?php
declare(strict_types=1);

// Recibe el paquete semanal (y el PDF) que sube Python: python -m src.publicar
require dirname(__DIR__) . '/src/autoload.php';

use CuantoCoesta\{Config, Correo, Semanas};

header('Content-Type: application/json; charset=utf-8');

function responder(int $codigo, array $cuerpo): never
{
    http_response_code($codigo);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    responder(405, ['ok' => false, 'error' => 'Usa POST.']);
}

$esperado = (string)(Config::local()['token'] ?? '');
if (strlen($esperado) < 24 || str_contains($esperado, 'CAMBIAR')) {
    responder(503, ['ok' => false, 'error' => 'El token no esta configurado en config/local.php.']);
}
$recibido = (string)($_SERVER['HTTP_X_COESTA_TOKEN'] ?? '');
if ($recibido === '' && preg_match('/^Bearer\s+(.+)$/i', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''), $m)) {
    $recibido = trim($m[1]);
}
if (!hash_equals($esperado, $recibido)) {
    usleep(700000);   // frena adivinanzas
    responder(401, ['ok' => false, 'error' => 'Token incorrecto.']);
}

$maxPaquete = 20 * 1024 * 1024;
$maxPdf = 15 * 1024 * 1024;
$paq = $_FILES['paquete'] ?? null;
if (!$paq || $paq['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($paq['tmp_name'])) {
    responder(400, ['ok' => false, 'error' => 'Falta el archivo "paquete" o supera el limite de subida del hosting.']);
}
if ($paq['size'] > $maxPaquete) {
    responder(413, ['ok' => false, 'error' => 'El paquete es demasiado grande.']);
}

try {
    $gz = (string)file_get_contents($paq['tmp_name']);
    $datos = Semanas::decodificar($gz);
    $semana = $datos['semana'];
    if (isset($_POST['semana']) && $_POST['semana'] !== $semana) {
        responder(400, ['ok' => false, 'error' => 'La semana indicada no coincide con el paquete.']);
    }

    $tienePdf = false;
    $pdf = $_FILES['pdf'] ?? null;
    if ($pdf && $pdf['error'] === UPLOAD_ERR_OK && is_uploaded_file($pdf['tmp_name'])) {
        if ($pdf['size'] > $maxPdf || file_get_contents($pdf['tmp_name'], false, null, 0, 5) !== '%PDF-') {
            responder(400, ['ok' => false, 'error' => 'El PDF no es valido o es muy grande.']);
        }
        $destino = Config::rutaPdf($semana);
        $tmp = $destino . '.tmp';
        if (!move_uploaded_file($pdf['tmp_name'], $tmp) || !rename($tmp, $destino)) {
            responder(500, ['ok' => false, 'error' => 'No se pudo guardar el PDF (permisos de storage/).']);
        }
        $tienePdf = true;
    } else {
        $tienePdf = Semanas::tienePdf($semana);
    }

    $escrito = Semanas::guardar($datos, $gz, $tienePdf);
    $respuesta = ['ok' => true, 'semana' => $semana, 'pdf' => $tienePdf, 'filas' => $escrito, 'recortadas' => Semanas::limpiar()];

    $quiereCorreo = ($_POST['enviar_correo'] ?? '1') === '1';
    if ($quiereCorreo && $tienePdf) {
        $respuesta['correo'] = Correo::enviarSemana($semana, $datos);
    } else {
        $respuesta['correo'] = ['ok' => false, 'detalle' => $tienePdf ? 'Envio desactivado en la subida.' : 'No hay PDF para enviar.'];
    }
    responder(200, $respuesta);
} catch (\InvalidArgumentException $e) {
    responder(400, ['ok' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('[coesta] recibir.php: ' . $e->getMessage());
    responder(500, ['ok' => false, 'error' => 'Error interno al guardar la semana.']);
}
