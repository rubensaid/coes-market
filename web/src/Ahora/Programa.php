<?php
declare(strict_types=1);

namespace CuantoCoesta\Ahora;

use CuantoCoesta\Bcrp;
use CuantoCoesta\Config;
use CuantoCoesta\Db;
use CuantoCoesta\Fechas;
use CuantoCoesta\Http;
use CuantoCoesta\Xlsx;

/**
 * Costo marginal PROGRAMADO por el COES (Programa Diario, Anexo 1, modelo YUPANA, US$/MWh) para hoy y manana.
 * El de manana se publica por la tarde. Equivale a src/extract/programa.py.
 */
final class Programa
{
    private const BARRAS = ['SANTA ROSA' => 'SANTA ROSA 220', 'TRUJILLO' => 'TRUJILLO 220', 'SOCABAYA' => 'SOCABAYA 220'];

    public function __construct(private ?Http $http = null)
    {
        $this->http ??= new Http();
    }

    public static function rutaVisor(\DateTimeInterface $fecha): string
    {
        $meses = Config::ahora()['meses_ieod'];
        return sprintf('Operación/Programa de Operación/Programa Diario/%d/%02d_%s/Día %02d/Anexo1_Despacho_%s.xlsx',
            (int)$fecha->format('Y'), (int)$fecha->format('n'), mb_strtoupper($meses[(int)$fecha->format('n') - 1]),
            (int)$fecha->format('j'), $fecha->format('Ymd'));
    }

    /**
     * Excel del programa -> [['fecha_hora' => 'Y-m-d H:i', 'barra' => ..., 'cmg_usd' => ...]].
     * @param array<int, array<int, string|float>> $filas
     */
    public static function leer(array $filas, \DateTimeInterface $fecha): array
    {
        $cab = null;
        foreach ($filas as $i => $f) {
            if (isset($f[1]) && mb_strtolower(trim((string)$f[1])) === 'día hora') {
                $cab = $i;
                break;
            }
        }
        if ($cab === null) {
            return [];
        }
        $columnas = [];
        foreach ($filas[$cab] as $j => $v) {
            if (is_string($v) && str_starts_with(mb_strtoupper(trim($v)), 'CMG ')) {
                $nombre = trim(explode(' YUPANA', str_replace('CMG ', '', mb_strtoupper($v)))[0]);
                $columnas[$j] = self::BARRAS[$nombre] ?? $nombre;
            }
        }
        $salida = [];
        $total = count($filas);
        for ($i = $cab + 1; $i < $total; $i++) {
            $hora = (string)($filas[$i][1] ?? '');
            if (!preg_match('/^\d{1,2}:\d{2}$/', $hora)) {
                continue;
            }
            $momento = Extractor::momento($fecha, $hora);
            foreach ($columnas as $j => $barra) {
                if (isset($filas[$i][$j]) && is_numeric($filas[$i][$j])) {
                    $salida[] = ['fecha_hora' => $momento->format('Y-m-d H:i'), 'barra' => $barra, 'cmg_usd' => (float)$filas[$i][$j]];
                }
            }
        }
        return $salida;
    }

    /** Descarga y guarda el programa de hoy y manana (solo lo que falta). @return array<string, mixed> */
    public function actualizar(\DateTimeImmutable $hoy): array
    {
        $barraRef = Config::ahora()['barra_referencia'];
        $ahora = Fechas::ahora();
        $resultado = [];
        foreach (['hoy' => $hoy, 'manana' => $hoy->modify('+1 day')] as $clave => $dia) {
            $inicio = $dia->format('Y-m-d 00:00:01');
            $fin = $dia->modify('+1 day')->format('Y-m-d 00:00:00');
            $ya = Db::fila('SELECT COUNT(*) AS n, MAX(actualizado_en) AS u FROM ahora_programa WHERE fecha_hora BETWEEN ? AND ?', [$inicio, $fin]);
            if ((int)$ya['n'] > 0 && ($clave === 'manana' || substr((string)$ya['u'], 0, 10) === $ahora->format('Y-m-d'))) {
                $resultado[$clave] = 'ya guardado';
                continue;
            }
            if ($clave === 'manana' && (int)$ahora->format('G') < 12) {
                $resultado[$clave] = 'aun no se publica';
                continue;
            }
            $contenido = $this->http->archivoVisor(self::rutaVisor($dia));
            if ($contenido === null || !str_starts_with($contenido, 'PK')) {
                $resultado[$clave] = 'aun no publicado';
                continue;
            }
            $filas = array_values(array_filter(self::leer(Xlsx::filas($contenido), $dia), static fn($f) => $f['barra'] === $barraRef));
            Db::pdo()->beginTransaction();
            Db::ejecutar('DELETE FROM ahora_programa WHERE fecha_hora BETWEEN ? AND ?', [$inicio, $fin]);
            $st = Db::pdo()->prepare('INSERT INTO ahora_programa (fecha_hora, cmg_usd, actualizado_en) VALUES (?, ?, ?)');
            foreach ($filas as $f) {
                $st->execute([$f['fecha_hora'] . ':00', $f['cmg_usd'], $ahora->format('Y-m-d H:i:s')]);
            }
            Db::pdo()->commit();
            $resultado[$clave] = count($filas) . ' filas';
        }
        Db::ejecutar('DELETE FROM ahora_programa WHERE fecha_hora < ?', [$hoy->modify('-1 day')->format('Y-m-d 00:00:00')]);
        Bcrp::actualizar($hoy->modify('-10 days')->format('Y-m-d'), $hoy->format('Y-m-d'), $this->http);
        return $resultado;
    }

    /**
     * Programa de hoy y manana en soles: ['hoy' => [[fecha_hora, cmg_soles]...], 'manana' => ...].
     * El tipo de cambio es el del dia del intervalo (o el ultimo conocido).
     */
    public static function enSoles(\DateTimeImmutable $hoy): array
    {
        $filas = Db::filas('SELECT DATE_FORMAT(fecha_hora, "%Y-%m-%d %H:%i") AS fh, cmg_usd FROM ahora_programa WHERE fecha_hora > ? ORDER BY fecha_hora',
            [$hoy->format('Y-m-d 00:00:00')]);
        $dias = array_values(array_unique(array_map(static fn($f) => substr(Fechas::fecha($f['fh'])->modify('-1 minute')->format('Y-m-d'), 0, 10), $filas)));
        $tc = Bcrp::tcPara($dias);
        $salida = ['hoy' => [], 'manana' => []];
        $limiteHoy = $hoy->modify('+1 day')->format('Y-m-d 00:00');
        foreach ($filas as $f) {
            $dia = Fechas::fecha($f['fh'])->modify('-1 minute')->format('Y-m-d');
            $cambio = $tc[$dia] ?? null;
            if ($cambio === null) {
                continue;
            }
            $salida[$f['fh'] <= $limiteHoy ? 'hoy' : 'manana'][] = [$f['fh'], round((float)$f['cmg_usd'] * $cambio, 2)];
        }
        return $salida;
    }
}
