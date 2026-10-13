<?php
declare(strict_types=1);

// Pruebas de logica pura (sin base de datos): php tests/run.php
require dirname(__DIR__) . '/src/autoload.php';

use CuantoCoesta\{Fechas, Formato, Semanas};

$fallos = 0;
$total = 0;
function igual(mixed $esperado, mixed $real, string $nombre): void
{
    global $fallos, $total;
    $total++;
    if ($esperado !== $real) {
        $fallos++;
        echo "FALLA $nombre\n  esperado: " . var_export($esperado, true) . "\n  real:     " . var_export($real, true) . "\n";
    }
}

// Fechas (los mismos casos que los tests de Python)
igual('Semana del 28 de septiembre al 4 de octubre de 2026', Fechas::textoSemana('2026-W40'), 'texto semana');
igual('Semana del 29 de diciembre de 2025 al 4 de enero de 2026', Fechas::textoSemana('2026-W01'), 'semana entre anios');
igual('2026-W39', Fechas::semanaAnterior('2026-W40'), 'semana anterior');
igual('2025-W52', Fechas::semanaAnterior('2026-W01'), 'semana anterior cruza anio');
igual('viernes 2 de octubre, 7:30 p. m.', Fechas::diaYHora(Fechas::fecha('2026-10-02 19:30')), 'dia y hora');
igual('12:00 a. m.', Fechas::horaCorta(Fechas::fecha('2026-10-02 00:00')), 'medianoche');
igual('7 p. m.', Fechas::horaTexto(19), 'hora texto');
igual('4 de octubre de 2026', Fechas::fechaLarga(Fechas::fecha('2026-10-04')), 'fecha larga');
igual('-05:00', Fechas::ahora()->format('P'), 'hora de Peru fija');
[$desde, $hasta] = Fechas::rangoAtajo('hidro', Fechas::fecha('2026-10-13'), null);
igual('2025-11-01', $desde->format('Y-m-d'), 'anio hidrologico en octubre');
[$desde] = Fechas::rangoAtajo('hidro', Fechas::fecha('2026-12-05'), null);
igual('2026-11-01', $desde->format('Y-m-d'), 'anio hidrologico en diciembre');

// Formato
igual('12,345.6', Formato::numero(12345.6, 1), 'numero');
igual('s/d', Formato::numero(null), 'numero nulo');
igual('12.3%', Formato::porcentaje(0.123), 'porcentaje');
igual('12.3%', Formato::porcentaje(0.123, 3), 'porcentaje maximo 1 decimal');
igual('▲ 3.2%', Formato::textoVariacion(0.032), 'variacion sube');
igual('▼ 3.2%', Formato::textoVariacion(-0.032), 'variacion baja');
igual(null, Formato::variacion(5.0, 0.0), 'variacion contra cero');
igual('&lt;b&gt;', Formato::h('<b>'), 'escape html');

// Paquetes
igual(true, Semanas::valida('2026-W40'), 'semana valida');
igual(false, Semanas::valida('2026-W54'), 'semana 54 no existe');
igual(false, Semanas::valida('../etc'), 'semana con ruta');
foreach (['no es gzip', gzencode('no es json'), gzencode('{"esquema":99,"semana":"2026-W40"}'), gzencode('{"esquema":1,"semana":"x"}')] as $i => $malo) {
    $lanzo = false;
    try {
        Semanas::decodificar($malo);
    } catch (InvalidArgumentException) {
        $lanzo = true;
    }
    igual(true, $lanzo, "paquete invalido $i");
}
igual('2026-W40', Semanas::decodificar(gzencode('{"esquema":1,"semana":"2026-W40"}'))['semana'], 'paquete valido');

echo ($fallos === 0 ? "OK" : "FALLARON $fallos") . " ($total pruebas)\n";
exit($fallos === 0 ? 0 : 1);
