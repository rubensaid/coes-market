<?php
declare(strict_types=1);

// Pagina principal: enruta la seccion (?s=) y la semana (?semana=).
require __DIR__ . '/src/autoload.php';

use CuantoCoesta\{Ahora\Memoria, Config, Db, Semanas, Ui};
use CuantoCoesta\Vistas\{Ahora, Evolucion, Operacion, Referencia, Semana};

header('Content-Type: text/html; charset=utf-8');

function pagina_error(string $titulo, string $mensaje, int $codigo = 200): never
{
    http_response_code($codigo);
    echo Ui::pagina($titulo, Ui::cabecera($titulo, '') . '<section class="tarjeta"><p>' . Ui::h($mensaje) . '</p></section>', 'resumen', null);
    exit;
}

$seccion = (string)($_GET['s'] ?? 'resumen');
if (!isset(Ui::SECCIONES[$seccion]) && $seccion !== 'proyecto') {
    $seccion = 'resumen';
}
$titulo = Ui::SECCIONES[$seccion][0] ?? 'Sobre el proyecto';

try {
    Db::pdo();
} catch (\Throwable $e) {
    error_log('[coesta] sin base de datos: ' . $e->getMessage());
    pagina_error('Falta terminar la instalación', 'No hay conexión con la base de datos. Revisa config/local.php y abre instalar.php y diagnostico.php.', 503);
}

try {
    $semanas = Semanas::listar();
    $semana = (string)($_GET['semana'] ?? '');
    if (!Semanas::valida($semana) || !in_array($semana, array_column($semanas, 'semana'), true)) {
        $semana = Semanas::porDefecto();
    }
    $paquete = $semana ? Semanas::obtener($semana) : null;
    $ahora = Memoria::estado();
    $c = ['p' => $paquete, 'semana' => $semana, 'semanas' => $semanas, 'get' => $_GET, 'ahora' => $ahora];

    $necesitaSemana = ['resumen', 'precios', 'demanda', 'generacion', 'embalses', 'eventos', 'centrales', 'mapa'];
    if (in_array($seccion, $necesitaSemana, true) && !$paquete) {
        $contenido = Ui::cabecera($titulo, 'Sin datos todavía')
            . Ui::tarjeta('<p><b>Todavía no hay una semana publicada.</b></p><p>La primera semana se sube desde Python con <code>python -m src.publicar</code> '
                . 'o con el workflow semanal de GitHub. Mientras tanto, la sección <a href="' . Ui::h(Ui::url('ahora')) . '">Ahora</a> ya funciona.</p>');
    } else {
        $contenido = match ($seccion) {
            'resumen' => Semana::resumen($c),
            'precios' => Semana::precios($c),
            'demanda' => Semana::demanda($c),
            'generacion' => Semana::generacion($c),
            'embalses' => Semana::embalses($c),
            'mapa' => Semana::mapa($c),
            'eventos' => Operacion::eventos($c),
            'centrales' => Operacion::centrales($c),
            'ahora' => Ahora::render($ahora),
            'evolucion' => Evolucion::render($_GET),
            'fuentes' => Referencia::fuentes($c),
            'glosario' => Referencia::glosario(),
            default => Referencia::proyecto($c),
        };
    }
    echo Ui::pagina($titulo, $contenido, $seccion, $semana ?: null, $ahora);
} catch (\Throwable $e) {
    error_log('[coesta] index.php: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    pagina_error('Algo salió mal', 'No pudimos mostrar esta sección. Si el problema sigue, avisa a quien administra el sitio.', 500);
}
