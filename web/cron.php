<?php
declare(strict_types=1);

// Para hostings que solo permiten cron con "wget/curl": https://tudominio.com/coesta/cron.php?tarea=ahora&clave=TOKEN
// Tareas: ahora (cada 30 min).  Prefiere bin/ahora.php por linea de comandos cuando se pueda.
require __DIR__ . '/src/autoload.php';

use CuantoCoesta\Acceso;
use CuantoCoesta\Ahora\Ciclo;

header('Content-Type: application/json; charset=utf-8');
if (!Acceso::tokenValido((string)($_GET['clave'] ?? ''))) {
    usleep(700000);
    http_response_code(401);
    exit(json_encode(['ok' => false, 'error' => 'Clave incorrecta.']));
}
set_time_limit(280);
$tarea = (string)($_GET['tarea'] ?? 'ahora');
if ($tarea !== 'ahora') {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Tarea desconocida.']));
}
echo json_encode(['ok' => true] + Ciclo::correr(isset($_GET['desde_ayer'])), JSON_UNESCAPED_UNICODE);
