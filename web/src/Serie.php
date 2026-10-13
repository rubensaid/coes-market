<?php
declare(strict_types=1);

namespace CuantoCoesta;

use CuantoCoesta\Ahora\Repositorio;

/**
 * Serie continua del costo marginal (seccion Evolucion): cualquier rango de fechas, no solo semanas.
 * Para cada media hora usa la mejor version: confirmado (IEOD) > preliminar (tiempo real) > en vivo (Ahora).
 * Equivale a src/analysis/serie.py. Cada fila: fh ('Y-m-d H:i', fin de intervalo), fecha (dia operativo), total, energia,
 * congestion, estado (0 confirmado, 1 preliminar, 2 en vivo).
 */
final class Serie
{
    public const ESTADOS = ['confirmado', 'preliminar', 'en vivo'];

    public static function barraReferencia(): string
    {
        return Config::ahora()['barra_referencia'];
    }

    /** Dia operativo de un fin de intervalo. */
    public static function fechaDe(string $fh): string
    {
        return date('Y-m-d', (int)strtotime($fh) - 60);
    }

    /** @return array<int, array<string, mixed>> */
    public static function cmgRango(string $desde, string $hasta, ?string $barra = null): array
    {
        $barra ??= self::barraReferencia();
        $inicio = $desde . ' 00:00:00';
        $fin = Fechas::fecha($hasta)->modify('+1 day')->format('Y-m-d 00:00:00');
        $filas = [];
        if ($barra === self::barraReferencia()) {
            foreach (Repositorio::cmgVigente($desde) as $f) {
                if ($f['fecha_hora'] > $inicio && $f['fecha_hora'] <= $fin) {
                    $fh = substr($f['fecha_hora'], 0, 16);
                    $filas[$fh] = ['fh' => $fh, 'total' => self::num($f['total']), 'energia' => self::num($f['energia']),
                        'congestion' => self::num($f['congestion']), 'estado' => 2];
                }
            }
            foreach (Db::filas('SELECT DATE_FORMAT(fecha_hora, "%Y-%m-%d %H:%i") AS fh, total, energia, congestion, estado FROM cmg_ref
                                WHERE fecha_hora > ? AND fecha_hora <= ? ORDER BY fecha_hora', [$inicio, $fin]) as $f) {
                $filas[$f['fh']] = ['fh' => $f['fh'], 'total' => self::num($f['total']), 'energia' => self::num($f['energia']),
                    'congestion' => self::num($f['congestion']), 'estado' => (int)$f['estado']];
            }
        } else {
            foreach (Db::filas('SELECT semana FROM semanas WHERE hasta >= ? AND desde <= ? ORDER BY semana', [$desde, $hasta]) as $s) {
                $p = Semanas::obtener($s['semana']);
                $ev = $p['evolucion'] ?? [];
                $valores = $ev['otras_barras'][$barra] ?? null;
                if (!$valores) {
                    continue;
                }
                foreach ($ev['ts'] as $i => $t) {
                    if ($t . ':00' > $inicio && $t . ':00' <= $fin && ($valores[$i] ?? null) !== null) {
                        $filas[$t] = ['fh' => $t, 'total' => (float)$valores[$i], 'energia' => null, 'congestion' => null, 'estado' => (int)($ev['estado'][$i] ?? 0)];
                    }
                }
            }
        }
        ksort($filas);
        foreach ($filas as &$f) {
            $f['fecha'] = self::fechaDe($f['fh']);
        }
        return array_values($filas);
    }

    private static function num(mixed $v): ?float
    {
        return $v === null ? null : (float)$v;
    }

    /** Barras que se pueden elegir: la de referencia y las guardadas en la ultima semana publicada. @return string[] */
    public static function barrasDisponibles(): array
    {
        $ultima = Semanas::porDefecto();
        $p = $ultima ? Semanas::obtener($ultima) : null;
        $otras = array_keys($p['evolucion']['otras_barras'] ?? []);
        sort($otras);
        return array_merge([self::barraReferencia()], $otras);
    }

    /** Primer dia con datos de costo marginal. */
    public static function primeraFecha(): ?string
    {
        $f = Db::fila('SELECT MIN(fecha_hora) AS m FROM cmg_ref');
        return $f && $f['m'] ? self::fechaDe(substr($f['m'], 0, 16)) : null;
    }

    /** USD por MWh con el tipo de cambio de cada dia. @param array<int, array<string, mixed>> $serie */
    public static function enDolares(array $serie): array
    {
        $tc = Bcrp::tcPara(array_values(array_unique(array_column($serie, 'fecha'))));
        foreach ($serie as &$f) {
            $cambio = $tc[$f['fecha']] ?? null;
            if (!$cambio) {
                continue;
            }
            foreach (['total', 'energia', 'congestion'] as $c) {
                $f[$c] = $f[$c] === null ? null : $f[$c] / $cambio;
            }
            $f['tc'] = $cambio;
        }
        return $serie;
    }

    /** Hora de inicio del intervalo: el que termina 00:30 empieza a las 00:00. */
    public static function horaInicio(string $fh): int
    {
        return (int)date('G', (int)strtotime($fh) - 1800);
    }

    public static function esPunta(string $fh): bool
    {
        $b = Config::analisis()['bloques'];
        $h = self::horaInicio($fh);
        return $h >= $b['punta_desde'] && $h < $b['punta_hasta'];
    }

    private static function media(array $v): ?float
    {
        $v = array_filter($v, static fn($x) => $x !== null);
        return $v ? array_sum($v) / count($v) : null;
    }

    /** Base / punta / fuera de punta, extremos y calidad del rango. */
    public static function resumir(array $serie, string $desde, string $hasta): array
    {
        $r = ['desde' => $desde, 'hasta' => $hasta, 'base' => null, 'punta' => null, 'fuera_punta' => null, 'maximo' => null, 'momento_max' => null,
            'minimo' => null, 'momento_min' => null, 'congestion_media' => null, 'cobertura' => 0.0, 'dias' => 0, 'dias_preliminares' => 0, 'estados' => []];
        $con = array_values(array_filter($serie, static fn($f) => $f['total'] !== null));
        if (!$con) {
            return $r;
        }
        $punta = $fuera = [];
        foreach ($con as $f) {
            if (self::esPunta($f['fh'])) {
                $punta[] = $f['total'];
            } else {
                $fuera[] = $f['total'];
            }
        }
        $r['base'] = self::media(array_column($con, 'total'));
        $r['punta'] = self::media($punta);
        $r['fuera_punta'] = self::media($fuera);
        $imax = array_search(max(array_column($con, 'total')), array_column($con, 'total'), true);
        $imin = array_search(min(array_column($con, 'total')), array_column($con, 'total'), true);
        [$r['maximo'], $r['momento_max']] = [$con[$imax]['total'], $con[$imax]['fh']];
        [$r['minimo'], $r['momento_min']] = [$con[$imin]['total'], $con[$imin]['fh']];
        $r['congestion_media'] = self::media(array_column($con, 'congestion'));
        $esperadas = ((int)Fechas::fecha($desde)->diff(Fechas::fecha($hasta))->days + 1) * 48;
        $r['cobertura'] = $esperadas ? count($con) / $esperadas : 0.0;
        $porDia = [];
        foreach ($con as $f) {
            $porDia[$f['fecha']] = ($porDia[$f['fecha']] ?? false) || $f['estado'] !== 0;
        }
        $r['dias'] = count($porDia);
        $r['dias_preliminares'] = count(array_filter($porDia));
        foreach ($con as $f) {
            $nombre = self::ESTADOS[$f['estado']];
            $r['estados'][$nombre] = ($r['estados'][$nombre] ?? 0) + 1;
        }
        return $r;
    }

    /** Por dia: promedio, minimo, maximo, punta y si todo el dia esta confirmado. */
    public static function diario(array $serie): array
    {
        $dias = [];
        foreach ($serie as $f) {
            if ($f['total'] === null) {
                continue;
            }
            $d = &$dias[$f['fecha']];
            $d['t'][] = $f['total'];
            if (self::esPunta($f['fh'])) {
                $d['p'][] = $f['total'];
            }
            $d['c'] = ($d['c'] ?? true) && $f['estado'] === 0;
            unset($d);
        }
        ksort($dias);
        $salida = [];
        foreach ($dias as $fecha => $d) {
            $salida[] = ['fecha' => $fecha, 'promedio' => array_sum($d['t']) / count($d['t']), 'minimo' => min($d['t']), 'maximo' => max($d['t']),
                'punta' => self::media($d['p'] ?? []), 'medias_horas' => count($d['t']), 'confirmado' => $d['c']];
        }
        return $salida;
    }

    /** Promedio por mes calendario (base y punta). */
    public static function mensual(array $serie): array
    {
        $meses = [];
        foreach ($serie as $f) {
            if ($f['total'] === null) {
                continue;
            }
            $m = substr($f['fecha'], 0, 7);
            $meses[$m]['t'][] = $f['total'];
            $meses[$m]['d'][$f['fecha']] = true;
            if (self::esPunta($f['fh'])) {
                $meses[$m]['p'][] = $f['total'];
            }
        }
        ksort($meses);
        $salida = [];
        foreach ($meses as $m => $d) {
            $salida[] = ['mes' => $m, 'promedio' => array_sum($d['t']) / count($d['t']), 'punta' => self::media($d['p'] ?? []), 'dias' => count($d['d'])];
        }
        return $salida;
    }

    /** Filas = dias, columnas = media hora del dia (0 a 47). @return array{fechas: string[], filas: array<int, array<int, ?float>>} */
    public static function matrizCalor(array $serie): array
    {
        $m = [];
        foreach ($serie as $f) {
            if ($f['total'] === null) {
                continue;
            }
            $inicio = (int)strtotime($f['fh']) - 1800;
            $col = (int)date('G', $inicio) * 2 + intdiv((int)date('i', $inicio), 30);
            $m[$f['fecha']][$col] = $f['total'];
        }
        ksort($m);
        $filas = [];
        foreach ($m as $dia) {
            $fila = [];
            for ($c = 0; $c < 48; $c++) {
                $fila[] = $dia[$c] ?? null;
            }
            $filas[] = $fila;
        }
        return ['fechas' => array_keys($m), 'filas' => $filas];
    }

    /** Excel con las medias horas, el resumen diario y una nota de fuentes. @return string contenido .xlsx */
    public static function exportarXlsx(array $serie, string $barra, string $moneda = 'S/'): string
    {
        $unidad = "$moneda por MWh";
        $detalle = [['fecha_hora_fin_intervalo', 'fecha', "cmg_total ($unidad)", "cmg_energia ($unidad)", "cmg_congestion ($unidad)", 'estado']];
        foreach ($serie as $f) {
            $detalle[] = [$f['fh'], $f['fecha'], $f['total'], $f['energia'], $f['congestion'], self::ESTADOS[$f['estado']]];
        }
        $dia = [['fecha', 'promedio', 'minimo', 'maximo', 'punta', 'medias_horas', 'confirmado']];
        foreach (self::diario($serie) as $d) {
            $dia[] = [$d['fecha'], $d['promedio'], $d['minimo'], $d['maximo'], $d['punta'], $d['medias_horas'], $d['confirmado'] ? 'sí' : 'no'];
        }
        $nota = [['Campo', 'Detalle'], ['Barra', $barra], ['Unidad', $unidad],
            ['Estados', 'confirmado = IEOD del COES; preliminar = tiempo real del portal; en vivo = consulta cada 30 min'],
            ['Fuente', 'Datos públicos de www.coes.org.pe' . ($moneda !== 'S/' ? '; tipo de cambio BCRP (venta SBS)' : '')],
            ['Aviso', Config::aviso()]];
        return Xlsx::escribir(['medias_horas' => $detalle, 'diario' => $dia, 'nota' => $nota]);
    }
}
