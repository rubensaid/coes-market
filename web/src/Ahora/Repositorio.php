<?php
declare(strict_types=1);

namespace CuantoCoesta\Ahora;

use CuantoCoesta\Db;
use CuantoCoesta\Fechas;

/** Base de "Ahora" en MySQL: cada observacion se guarda versionada (equivale a src/ahora/db.py). Solo la barra de referencia. */
final class Repositorio
{
    public const TOLERANCIA = 1e-6;

    /**
     * Inserta solo lo nuevo o lo que cambio. Si el COES corrige un valor se agrega una version y se conserva la anterior.
     * @param array<int, array<string, mixed>> $filas
     * @return array{int, int} [nuevos, revisados]
     */
    private static function versionado(string $tabla, array $claves, array $valores, array $extras, array $filas, string $extraidoEn): array
    {
        $nuevos = $revisados = 0;
        $filtro = implode(' AND ', array_map(static fn($c) => "$c = ?", $claves));
        $sel = Db::pdo()->prepare("SELECT version, " . implode(', ', $valores) . " FROM $tabla WHERE $filtro ORDER BY version DESC LIMIT 1");
        $columnas = array_merge($claves, ['version'], $valores, $extras, ['extraido_en']);
        $ins = Db::pdo()->prepare("INSERT INTO $tabla (" . implode(', ', $columnas) . ') VALUES (' . implode(', ', array_fill(0, count($columnas), '?')) . ')');
        foreach ($filas as $fila) {
            $clave = array_map(static fn($c) => $fila[$c], $claves);
            $sel->execute($clave);
            $previo = $sel->fetch(\PDO::FETCH_NUM);
            $actuales = array_map(static fn($v) => $fila[$v] ?? null, $valores);
            if ($previo === false) {
                $version = 1;
                $nuevos++;
            } else {
                $iguales = true;
                foreach ($actuales as $i => $a) {
                    $b = $previo[$i + 1];
                    if (!(($a === null && $b === null) || ($a !== null && $b !== null && abs((float)$a - (float)$b) <= self::TOLERANCIA))) {
                        $iguales = false;
                        break;
                    }
                }
                if ($iguales) {
                    continue;
                }
                $version = (int)$previo[0] + 1;
                $revisados++;
            }
            $ins->execute(array_merge($clave, [$version], $actuales, array_map(static fn($c) => $fila[$c] ?? null, $extras), [$extraidoEn]));
        }
        return [$nuevos, $revisados];
    }

    /** @param array<int, array{fecha_hora: string, cmg_total: float, cmg_energia: float, cmg_congestion: float, correlativo: int}> $filas */
    public static function guardarCmg(array $filas, string $extraidoEn): array
    {
        return self::versionado('ahora_cmg', ['fecha_hora'], ['cmg_total', 'cmg_energia', 'cmg_congestion'], ['correlativo'], $filas, $extraidoEn);
    }

    /** @param array<int, array{fecha_hora: string, variable: string, valor: float}> $filas */
    public static function guardarDemanda(array $filas, string $extraidoEn): array
    {
        return self::versionado('ahora_demanda', ['fecha_hora', 'variable'], ['valor'], [], $filas, $extraidoEn);
    }

    /** @return array<string, true> fecha_hora ('Y-m-d H:i') ya guardadas */
    public static function horasConCmg(): array
    {
        $r = [];
        foreach (Db::filas("SELECT DISTINCT DATE_FORMAT(fecha_hora, '%Y-%m-%d %H:%i') AS h FROM ahora_cmg") as $f) {
            $r[$f['h']] = true;
        }
        return $r;
    }

    public static function ultimoMomento(): ?string
    {
        $f = Db::fila("SELECT DATE_FORMAT(MAX(fecha_hora), '%Y-%m-%d %H:%i') AS m FROM ahora_cmg");
        return $f['m'] ?? null;
    }

    /** Ultima version de cada media hora desde $desde (Y-m-d). @return array<int, array<string, mixed>> */
    public static function cmgVigente(?string $desde = null): array
    {
        $donde = $desde ? 'WHERE fecha_hora >= ?' : '';
        return Db::filas("SELECT c.fecha_hora, c.cmg_total AS total, c.cmg_energia AS energia, c.cmg_congestion AS congestion, c.extraido_en
            FROM ahora_cmg c JOIN (SELECT fecha_hora, MAX(version) v FROM ahora_cmg $donde GROUP BY fecha_hora) u
              ON c.fecha_hora = u.fecha_hora AND c.version = u.v ORDER BY c.fecha_hora", $desde ? [$desde] : []);
    }

    /** Demanda vigente en formato ancho: fecha_hora => [ejecutada, programa_diario]. */
    public static function demandaVigente(?string $desde = null): array
    {
        $donde = $desde ? 'WHERE fecha_hora >= ?' : '';
        $filas = Db::filas("SELECT d.fecha_hora, d.variable, d.valor FROM ahora_demanda d
            JOIN (SELECT fecha_hora, variable, MAX(version) v FROM ahora_demanda $donde GROUP BY fecha_hora, variable) u
              ON d.fecha_hora = u.fecha_hora AND d.variable = u.variable AND d.version = u.v ORDER BY d.fecha_hora", $desde ? [$desde] : []);
        $ancho = [];
        foreach ($filas as $f) {
            $ancho[$f['fecha_hora']][$f['variable']] = $f['valor'] === null ? null : (float)$f['valor'];
        }
        return $ancho;
    }

    /** Cuantas medias horas corrigio el COES despues de publicarlas. */
    public static function revisiones(): int
    {
        return (int)(Db::fila('SELECT COUNT(*) AS n FROM (SELECT fecha_hora FROM ahora_cmg GROUP BY fecha_hora HAVING MAX(version) > 1) x')['n'] ?? 0);
    }

    public static function registrarExtraccion(string $fuente, string $inicio, bool $ok, int $nuevos = 0, int $revisados = 0, string $detalle = ''): void
    {
        $ahora = Fechas::ahora()->format('Y-m-d H:i:s');
        Db::ejecutar('INSERT INTO extracciones (fuente, inicio, fin, ok, nuevos, revisados, detalle, creado_en) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$fuente, $inicio, $ahora, $ok ? 1 : 0, $nuevos, $revisados, mb_substr($detalle, 0, 500), $ahora]);
        $momento = $ahora;
        if ($ok) {
            Db::ejecutar('INSERT INTO estado_fuentes (fuente, ultimo_intento, ultima_extraccion_exitosa, ultimo_error) VALUES (?, ?, ?, NULL)
                ON DUPLICATE KEY UPDATE ultimo_intento = VALUES(ultimo_intento), ultima_extraccion_exitosa = VALUES(ultima_extraccion_exitosa), ultimo_error = NULL',
                ["ahora_$fuente", $momento, $momento]);
        } else {
            Db::ejecutar('INSERT INTO estado_fuentes (fuente, ultimo_intento, ultimo_error) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE ultimo_intento = VALUES(ultimo_intento), ultimo_error = VALUES(ultimo_error)',
                ["ahora_$fuente", $momento, mb_substr($detalle, 0, 500)]);
        }
    }

    public static function ultimaExtraccion(string $fuente, bool $soloOk = true): ?array
    {
        return Db::fila('SELECT * FROM extracciones WHERE fuente = ?' . ($soloOk ? ' AND ok = 1' : '') . ' ORDER BY id DESC LIMIT 1', [$fuente]);
    }

    /** Estado por fuente para la vista Fuentes: fuente => ultima extraccion exitosa (Y-m-d H:i:s) */
    public static function estadoFuentes(): array
    {
        return array_column(Db::filas('SELECT fuente, ultima_extraccion_exitosa FROM estado_fuentes'), 'ultima_extraccion_exitosa', 'fuente');
    }
}
