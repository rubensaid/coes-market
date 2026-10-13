<?php
declare(strict_types=1);

namespace CuantoCoesta;

/** Tipo de cambio del BCRP (S/ por US$, venta SBS). Se guarda en la tabla tipo_cambio y solo se piden los dias que faltan. */
final class Bcrp
{
    private const SERIE = 'PD04640PD';
    private const MESES = ['ene' => 1, 'feb' => 2, 'mar' => 3, 'abr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'ago' => 8,
        'set' => 9, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dic' => 12];

    /** '25.Set.26' -> '2026-09-25' */
    public static function leerPeriodo(string $nombre): string
    {
        [$dia, $mes, $anio] = explode('.', $nombre);
        return sprintf('%04d-%02d-%02d', 2000 + (int)$anio, self::MESES[strtolower(substr($mes, 0, 3))], (int)$dia);
    }

    /** JSON del BCRP -> [fecha => tc] sin los dias 'n.d.' */
    public static function interpretar(string $json): array
    {
        $datos = json_decode(ltrim($json, "\xEF\xBB\xBF"), true) ?: [];
        $salida = [];
        foreach ($datos['periods'] ?? [] as $p) {
            $valor = $p['values'][0] ?? null;
            if (is_numeric($valor)) {
                try {
                    $salida[self::leerPeriodo($p['name'])] = (float)$valor;
                } catch (\Throwable) {
                    continue;
                }
            }
        }
        return $salida;
    }

    /** Baja lo que falte entre $desde y $hasta (Y-m-d). Si el BCRP no responde, sigue con lo guardado. */
    public static function actualizar(string $desde, string $hasta, ?Http $http = null): void
    {
        $f = Db::fila('SELECT MIN(fecha) AS primero, MAX(fecha) AS ultimo FROM tipo_cambio');
        $pedirDesde = $desde;
        if ($f && $f['ultimo'] !== null) {
            $corte = (new \DateTimeImmutable($hasta))->modify('-3 days')->format('Y-m-d');
            if ($f['primero'] <= $desde && $f['ultimo'] >= $corte) {
                return;   // al dia: los ultimos dias suelen ser 'n.d.' hasta que el BCRP publica
            }
            if ($f['primero'] <= $desde) {
                $pedirDesde = max($desde, (new \DateTimeImmutable($f['ultimo']))->modify('-5 days')->format('Y-m-d'));
            }
        }
        $http ??= new Http();
        try {
            $url = "https://estadisticas.bcrp.gob.pe/estadisticas/series/api/" . self::SERIE . "/json/$pedirDesde/$hasta";
            $nuevos = self::interpretar($http->pedirUrl('GET', $url));
        } catch (\Throwable $e) {
            error_log('[coesta] BCRP: ' . $e->getMessage());
            return;
        }
        $st = Db::pdo()->prepare('INSERT INTO tipo_cambio (fecha, tc) VALUES (?, ?) ON DUPLICATE KEY UPDATE tc = VALUES(tc)');
        foreach ($nuevos as $fecha => $tc) {
            $st->execute([$fecha, $tc]);
        }
    }

    /**
     * Tipo de cambio de cada fecha (Y-m-d); los dias sin dato toman el ultimo conocido (o el siguiente al inicio).
     * @param string[] $fechas
     * @return array<string, float|null>
     */
    public static function tcPara(array $fechas, ?array $tabla = null): array
    {
        if ($tabla === null) {
            $tabla = array_column(Db::filas('SELECT fecha, tc FROM tipo_cambio ORDER BY fecha'), 'tc', 'fecha');
        }
        ksort($tabla);
        $conocidas = array_keys($tabla);
        $salida = [];
        foreach ($fechas as $f) {
            if (!$conocidas) {
                $salida[$f] = null;
                continue;
            }
            $previa = null;
            foreach ($conocidas as $c) {
                if ($c <= $f) {
                    $previa = $c;
                } else {
                    break;
                }
            }
            $salida[$f] = (float)$tabla[$previa ?? $conocidas[0]];
        }
        return $salida;
    }
}
