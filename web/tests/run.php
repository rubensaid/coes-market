<?php
declare(strict_types=1);

// Pruebas de logica pura (sin base de datos): php tests/run.php
require dirname(__DIR__) . '/src/autoload.php';

use CuantoCoesta\{Fechas, Formato, Semanas, Serie};
use CuantoCoesta\Visual\{Graficos as G, Lienzo};

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

// Marcas de ejes y graficos (humo: producen SVG valido, sin NaN ni INF)
igual([0.0, 200.0, 400.0, 600.0, 800.0], Lienzo::marcas(0, 800, 4), 'marcas redondas');
igual('#1565C0', G::color(['#1565C0', '#F28C1E'], 0.0), 'color inicial');
igual('#F28C1E', G::color(['#1565C0', '#F28C1E'], 1.0), 'color final');
$ts = [];
$v = [];
for ($i = 1; $i <= 96; $i++) {
    $ts[] = date('Y-m-d H:i', strtotime('2026-10-01 00:00') + $i * 1800);
    $v[] = 150 + 100 * sin($i / 8);
}
$svgs = [
    'precio' => G::precioSemana(['ts' => $ts, 'valor' => $v], ['bajo' => 100, 'alto' => 500]),
    'perfil' => G::perfilHorario(array_map(fn($h) => [$h, 100.0 + $h, $h > 3 ? 90.0 : null], range(0, 23))),
    'demanda' => G::demanda(['ts' => $ts, 'ejecutada' => $v, 'prog_diaria' => $v]),
    'generacion' => G::generacion(['ts' => $ts, 'tecnologias' => ['hidro' => $v, 'gas' => $v]]),
    'mix' => G::mix(['gas' => 0.6, 'hidro' => 0.4], ['gas' => 0.5]),
    'embalses' => G::embalses(['fecha' => ['2026-10-01', '2026-10-02'], 'valor' => [500.0, 510.0]]),
    'ranking' => G::barrasHorizontales(['A', 'B'], [1.0, 2.0], [null, 1.5]),
    'diaria' => G::evolucionDiaria([['fecha' => '2026-10-01', 'promedio' => 200.0, 'minimo' => 100.0, 'maximo' => 300.0, 'punta' => 250.0, 'confirmado' => true],
        ['fecha' => '2026-10-04', 'promedio' => 210.0, 'minimo' => 110.0, 'maximo' => 310.0, 'punta' => null, 'confirmado' => false]]),
    'calor' => G::mapaCalor(['fechas' => ['2026-10-01'], 'filas' => [array_map(fn($i) => $i % 5 ? 100.0 + $i : null, range(0, 47))]]),
    'mensual' => G::mensualBarras([['mes' => '2026-09', 'promedio' => 200.0, 'punta' => 300.0], ['mes' => '2026-10', 'promedio' => 210.0, 'punta' => null]]),
    'central' => G::generacionCentral($ts, $v),
    'indisp' => G::indisponibilidad([['fecha' => '2026-10-01', 'programado' => 10.0, 'forzado' => 5.0]]),
    'vacio' => G::precioSemana(['ts' => [], 'valor' => []], ['alto' => 500]),
];
foreach ($svgs as $nombre => $svg) {
    igual(true, str_starts_with($svg, '<svg') && str_ends_with($svg, '</svg>') && !preg_match('/NAN|INF|nan|inf(?!o)/', $svg), "grafico $nombre valido");
}

// Serie continua con datos sinteticos
$serie = [];
foreach ($ts as $i => $t) {
    $serie[] = ['fh' => $t, 'fecha' => Serie::fechaDe($t), 'total' => $v[$i], 'energia' => $v[$i], 'congestion' => 0.0, 'estado' => $i < 48 ? 0 : 1];
}
igual('2026-10-01', Serie::fechaDe('2026-10-02 00:00'), 'fin de intervalo 00:00 es del dia anterior');
igual(true, Serie::esPunta('2026-10-01 19:00') && !Serie::esPunta('2026-10-01 23:30') && Serie::esPunta('2026-10-01 23:00'), 'punta 18:00 a 23:00 por hora de inicio');
$resumen = Serie::resumir($serie, '2026-10-01', '2026-10-02');
igual(96, array_sum($resumen['estados']), 'resumen cuenta estados');
igual(1, $resumen['dias_preliminares'], 'dias preliminares');
igual(2, count(Serie::diario($serie)), 'diario');
igual(48, count(Serie::matrizCalor($serie)['filas'][0]), 'matriz de 48 columnas');
igual(1, count(Serie::mensual($serie)), 'mensual');
igual('2026-W41', Fechas::semanaIso(Fechas::fecha('2026-10-07')), 'semana iso');

echo ($fallos === 0 ? "OK" : "FALLARON $fallos") . " ($total pruebas)\n";
exit($fallos === 0 ? 0 : 1);
