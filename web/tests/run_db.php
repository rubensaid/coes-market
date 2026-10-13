<?php
declare(strict_types=1);

// Pruebas con base de datos y datos de muestra: php tests/run_db.php
// Usa la base "coesta_test" del MySQL local (COESTA_PRUEBAS_DB_* en el entorno o los valores de config/local.php).
require dirname(__DIR__) . '/src/autoload.php';

use CuantoCoesta\{Ahora\Extractor, Ahora\Memoria, Ahora\Programa, Ahora\Repositorio, Bcrp, Config, Db, Fechas, Http, Instalador, Rarezas, Semanas, Xlsx};

$base = Config::local()['db'];
$base['nombre'] = getenv('COESTA_PRUEBAS_DB') ?: 'coesta_test';
Config::fijarLocal(['db' => $base] + Config::local());
Db::usar(null);
foreach (Db::filas('SHOW TABLES') as $t) {
    Db::pdo()->exec('DROP TABLE `' . array_values($t)[0] . '`');
}
Instalador::ejecutar();

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
function cerca(float $esperado, float $real, string $nombre, float $tol = 1e-6): void
{
    igual(true, abs($esperado - $real) <= $tol, "$nombre ($esperado vs $real)");
}

$muestras = dirname(__DIR__, 2) . '/data/samples';

// --- tabla de un proceso del COES ---
$html = (string)file_get_contents("$muestras/cmg_tiempo_real_proceso.html");
$filas = Extractor::leerTablaProceso($html, 123, Fechas::fecha('2026-10-10 10:00'));
igual(1, count($filas), 'solo la barra de referencia');
igual('SANTA ROSA 220', $filas[0]['barra'], 'barra');
cerca(942.020761, $filas[0]['cmg_total'], 'cmg total');
igual('2026-10-10 10:00', $filas[0]['fecha_hora'], 'fecha_hora');

// --- fin de dia 23:59 -> 00:00 del dia siguiente ---
igual('2026-10-11 00:00', Extractor::momento(Fechas::fecha('2026-10-10'), '23:59')->format('Y-m-d H:i'), 'momento 23:59');
igual('2026-10-10 10:30', Extractor::momento(Fechas::fecha('2026-10-10'), '10:30')->format('Y-m-d H:i'), 'momento normal');

// --- versionado: nuevo, igual (nada), corregido (version 2) ---
$f = ['fecha_hora' => '2026-10-10 10:00', 'cmg_total' => 100.0, 'cmg_energia' => 100.0, 'cmg_congestion' => 0.0, 'correlativo' => 1];
igual([1, 0], Repositorio::guardarCmg([$f], '2026-10-10 10:05:00'), 'primer guardado');
igual([0, 0], Repositorio::guardarCmg([$f], '2026-10-10 10:35:00'), 'mismo valor no crea version');
$f['cmg_total'] = 100.0000001;
igual([0, 0], Repositorio::guardarCmg([$f], '2026-10-10 10:36:00'), 'dentro de la tolerancia');
$f['cmg_total'] = 150.5;
igual([0, 1], Repositorio::guardarCmg([$f], '2026-10-10 11:05:00'), 'valor corregido');
igual(2, (int)Db::fila('SELECT COUNT(*) n FROM ahora_cmg')['n'], 'se conservan las dos versiones');
igual(150.5, (float)Repositorio::cmgVigente()[0]['total'], 'vigente es la ultima version');
igual(1, Repositorio::revisiones(), 'cuenta revisiones');

// --- demanda ---
$json = ['Chart' => ['Series' => [
    ['Name' => 'Ejecutado', 'Data' => [['Nombre' => '2026/10/10 10:00:00', 'Valor' => 7000], ['Nombre' => '2026/10/10 10:30:00', 'Valor' => 0]]],
    ['Name' => 'Programa diaria', 'Data' => [['Nombre' => '2026/10/10 10:00:00', 'Valor' => 6900]]],
    ['Name' => 'Otra cosa', 'Data' => [['Nombre' => '2026/10/10 10:00:00', 'Valor' => 1]]],
]]];
$filasDem = Extractor::filasDemanda($json);
igual(2, count($filasDem), 'demanda descarta ceros y series ajenas');
igual([2, 0], Repositorio::guardarDemanda($filasDem, '2026-10-10 10:05:00'), 'demanda nueva');

// --- dias a revisar ---
$hoy = Fechas::fecha('2026-10-13');
igual(['2026-10-12', '2026-10-13'], array_map(fn($d) => $d->format('Y-m-d'), Extractor::diasARevisar($hoy, null)), 'sin datos: desde ayer');
igual(['2026-10-13'], array_map(fn($d) => $d->format('Y-m-d'), Extractor::diasARevisar($hoy, '2026-10-13 09:00')), 'al dia: solo hoy');
igual(7, count(Extractor::diasARevisar($hoy, '2026-09-01 10:00')), 'tope de 7 dias');
igual('2026-10-11', Extractor::diasARevisar($hoy, '2026-10-12 00:00')[0]->format('Y-m-d'), '00:00 pertenece al dia anterior');

// --- Programa Diario (Excel de muestra) ---
$prog = Programa::leer(Xlsx::filas((string)file_get_contents("$muestras/programa_20261012.xlsx")), Fechas::fecha('2026-10-12'));
$sr = array_values(array_filter($prog, fn($p) => $p['barra'] === 'SANTA ROSA 220'));
igual(48, count($sr), 'programa: 48 medias horas en Santa Rosa');
igual('2026-10-12 00:30', $sr[0]['fecha_hora'], 'programa: primer intervalo');
igual('2026-10-13 00:00', $sr[47]['fecha_hora'], 'programa: ultimo intervalo es 00:00 del dia siguiente');
igual(true, count(array_unique(array_column($prog, 'barra'))) === 3, 'programa: tres barras');
igual('Operación/Programa de Operación/Programa Diario/2026/10_OCTUBRE/Día 12/Anexo1_Despacho_20261012.xlsx',
    Programa::rutaVisor(Fechas::fecha('2026-10-12')), 'ruta del visor (mes en mayusculas)');
igual('Operación/Programa de Operación/Programa Diario/2026/09_SETIEMBRE/Día 05/Anexo1_Despacho_20260905.xlsx',
    Programa::rutaVisor(Fechas::fecha('2026-09-05')), 'ruta del visor (Setiembre)');

// --- BCRP ---
igual('2026-09-25', Bcrp::leerPeriodo('25.Set.26'), 'periodo BCRP');
igual(['2026-09-25' => 3.75], Bcrp::interpretar('{"periods":[{"name":"25.Set.26","values":["3.75"]},{"name":"26.Set.26","values":["n.d."]}]}'), 'BCRP ignora n.d.');
$tabla = ['2026-10-01' => 3.70, '2026-10-05' => 3.80];
igual(['2026-09-30' => 3.70, '2026-10-03' => 3.70, '2026-10-09' => 3.80], Bcrp::tcPara(['2026-09-30', '2026-10-03', '2026-10-09'], $tabla), 'tc ffill/bfill');

// --- Rarezas ---
$historia = [];
for ($d = 1; $d <= 20; $d++) {
    $historia[sprintf('2026-09-%02d 19:00', $d)] = 200.0 + $d;     // 201..220
}
$momento = Fechas::fecha('2026-09-25 19:00');
$normal = Rarezas::evaluar(230.0, $momento, $historia);
igual(false, $normal['es_rara'], 'dentro del margen no es rara');
$rara = Rarezas::evaluar(500.0, $momento, $historia);
igual(true, $rara['es_rara'], 'por encima del maximo +10%');
igual(true, str_contains($rara['regla'], 'El precio más alto para las 19:00 en 4 semanas: S/ 500.0'), 'texto de la regla');
$pocos = Rarezas::evaluar(500.0, Fechas::fecha('2026-09-25 03:00'), $historia);
igual(true, !$pocos['es_rara'] && str_starts_with($pocos['regla'], 'Sin juicio'), 'sin historia no se juzga');
igual(true, Rarezas::saltoBrusco(400.0, 200.0) !== null, 'salto brusco');
igual(null, Rarezas::saltoBrusco(210.0, 200.0), 'sin salto');
igual([200.0, 'x'][0], Rarezas::mediana([100.0, 300.0, 200.0]), 'mediana impar');
igual(2.5, Rarezas::mediana([1.0, 2.0, 3.0, 4.0]), 'mediana par');

// --- lectura ---
igual(['Normal para esta hora', 'bueno'], Memoria::lectura(100.0, ['mediana' => 100.0, 'es_rara' => false]), 'lectura normal');
igual(['Unas 2 veces lo normal para esta hora', 'alerta'], Memoria::lectura(200.0, ['mediana' => 100.0, 'es_rara' => false]), 'lectura 2 veces');
igual(['Mucho más barato que lo normal', 'info'], Memoria::lectura(50.0, ['mediana' => 100.0, 'es_rara' => false]), 'lectura barato');
igual(['Sin historia suficiente para comparar', 'neutro'], Memoria::lectura(50.0, null), 'lectura sin historia');

// --- Extractor completo con un cliente HTTP falso (sin red) ---
class HttpFalso extends Http
{
    public array $pedidos = [];
    public function __construct(private string $html) {}
    public function pedir(string $metodo, string $ruta, array $params = []): string
    {
        $this->pedidos[] = [$ruta, $params];
        if (str_contains($ruta, 'listaproceso')) {
            return json_encode([['Cmgncorrelativo' => 900, 'FechaProceso' => '10:00'], ['Cmgncorrelativo' => 901, 'FechaProceso' => '10:30']]);
        }
        if (str_contains($ruta, 'costosmarginales/lista')) {
            return $this->html;
        }
        return json_encode(['Chart' => ['Series' => [['Name' => 'Ejecutado', 'Data' => [['Nombre' => '2026/10/13 10:00:00', 'Valor' => 7100]]]]]]);
    }
    public function archivoVisor(string $rutaVisor): ?string { return null; }
}
Db::ejecutar('DELETE FROM ahora_cmg');
$http = new HttpFalso($html);
$ext = new Extractor($http);
[$n, $r] = $ext->actualizarCmg([Fechas::fecha('2026-10-13')]);
igual([2, 0], [$n, $r], 'extractor: dos procesos nuevos');
$antes = count($http->pedidos);
[$n, $r] = $ext->actualizarCmg([Fechas::fecha('2026-10-13')]);
igual([0, 0], [$n, $r], 'extractor: repetir no crea versiones');
igual(3, count($http->pedidos) - $antes, 'extractor: reconsulta la lista y los ultimos 2 procesos');

// --- Semanas: guardar un paquete minimo y no pisar confirmado con preliminar ---
$paquete = ['esquema' => 1, 'semana' => '2026-W40', 'desde' => '2026-09-28', 'hasta' => '2026-10-04', 'es_preliminar' => true,
    'generado_en' => '2026-10-13 08:00', 'evolucion' => ['ts' => ['2026-10-01 00:30'], 'total' => [100.0], 'energia' => [100.0],
    'congestion' => [0.0], 'estado' => [0]], 'tipo_cambio' => ['2026-10-01' => 3.7]];
Semanas::guardar($paquete, gzencode(json_encode($paquete)), false);
$paquete['evolucion'] = ['ts' => ['2026-10-01 00:30'], 'total' => [999.0], 'energia' => [999.0], 'congestion' => [0.0], 'estado' => [1]];
Semanas::guardar($paquete, gzencode(json_encode($paquete)), false);
igual(100.0, (float)Db::fila('SELECT total FROM cmg_ref')['total'], 'preliminar no pisa a confirmado');
igual('2026-W40', Semanas::porDefecto(), 'semana por defecto');
igual('2026-W40', Semanas::obtener('2026-W40')['semana'], 'leer paquete guardado');

echo ($fallos === 0 ? 'OK' : "FALLARON $fallos") . " ($total pruebas)\n";
exit($fallos === 0 ? 0 : 1);
