<?php
declare(strict_types=1);

// Descargas: PDF semanal (tipo=pdf&semana=AAAA-Www) y Excel de Evolucion (tipo=excel&desde=&hasta=&barra=&moneda=).
require __DIR__ . '/src/autoload.php';

use CuantoCoesta\{Bcrp, Config, Semanas, Serie};
use CuantoCoesta\Vistas\Evolucion;

function no_encontrado(string $mensaje): never
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit($mensaje);
}

$tipo = (string)($_GET['tipo'] ?? '');
try {
    if ($tipo === 'pdf') {
        $semana = (string)($_GET['semana'] ?? '');
        if (!Semanas::tienePdf($semana)) {
            no_encontrado('Esta semana no tiene PDF publicado.');
        }
        $ruta = Config::rutaPdf($semana);
        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($ruta));
        header('Content-Disposition: attachment; filename="' . basename($ruta) . '"');
        readfile($ruta);
        exit;
    }
    if ($tipo === 'excel') {
        [$desde, $hasta, $barra, $moneda] = Evolucion::parametros($_GET);
        if ((strtotime($hasta) - strtotime($desde)) / 86400 > 1100) {
            no_encontrado('El rango es demasiado largo para exportar (maximo 3 años).');
        }
        $serie = Serie::cmgRango($desde, $hasta, $barra);
        if (!$serie) {
            no_encontrado('No hay datos para exportar en este rango.');
        }
        if ($moneda === 'USD') {
            Bcrp::actualizar($desde, $hasta);
            $serie = Serie::enDolares($serie);
        }
        $nombre = 'cmg_' . strtolower(str_replace(' ', '_', $barra)) . "_{$desde}_{$hasta}.xlsx";
        $contenido = Serie::exportarXlsx($serie, $barra, $moneda);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Length: ' . strlen($contenido));
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        echo $contenido;
        exit;
    }
    no_encontrado('Descarga desconocida.');
} catch (\Throwable $e) {
    error_log('[coesta] descargar.php: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No se pudo preparar la descarga.';
}
