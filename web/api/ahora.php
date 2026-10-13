<?php
declare(strict_types=1);

// Estado de "Ahora" en JSON (lo usa la pagina para refrescarse sin recargar). Solo lectura.
require dirname(__DIR__) . '/src/autoload.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    echo json_encode(CuantoCoesta\Ahora\Memoria::estado(), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
} catch (\Throwable $e) {
    error_log('[coesta] api/ahora.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo leer el estado.']);
}
