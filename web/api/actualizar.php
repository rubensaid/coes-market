<?php
declare(strict_types=1);

// Boton "Actualizar ahora": consulta al COES, con limite de una vez cada 5 minutos para no cargarle el servidor.
require dirname(__DIR__) . '/src/autoload.php';

header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Usa POST.']));
}
set_time_limit(280);
try {
    echo json_encode(['ok' => true] + CuantoCoesta\Ahora\Ciclo::correr(false, true), JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('[coesta] api/actualizar.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo actualizar.']);
}
