<?php
declare(strict_types=1);

namespace CuantoCoesta\Visual;

use CuantoCoesta\Config;
use CuantoCoesta\Fechas;
use CuantoCoesta\Formato;

/**
 * Graficos editoriales en SVG (equivalen a src/visual/graficos.py): fondo blanco, una serie principal en azul y el dato
 * a mirar en naranja. Reciben los datos del paquete semanal (arreglos), no DataFrames. Los titulos viven en la tarjeta
 * que contiene el grafico y dicen la conclusion.
 */
final class Graficos
{
    private const AYER = '#B9C7D6';

    private static function p(): array
    {
        return Config::proyecto()['paleta'];
    }

    private static function miles(float $v): string
    {
        return number_format($v, 0, '.', ',');
    }

    private static function ts(string $t): int
    {
        return (int)strtotime($t);
    }

    /** Dia operativo de un fin de intervalo: (ts - 1 min). */
    private static function diaDe(int|float $ts): string
    {
        return date('Y-m-d', (int)$ts - 60);
    }

    /** Marcas del eje X al mediodia de cada dia, con "lun 28". @param int[] $segundos */
    private static function ejeDias(Lienzo $l, array $segundos): void
    {
        $dias = array_values(array_unique(array_map(fn($s) => self::diaDe($s), $segundos)));
        foreach ($dias as $d) {
            $f = Fechas::fecha($d);
            $l->texto($l->x(self::ts("$d 12:00")), $l->areaY1() + 16, Fechas::ABREV[Fechas::diaSemana($f)] . ' ' . (int)$f->format('j'), 'eje', 'middle');
        }
    }

    private static function textoSoles(string $t, float $v): string
    {
        return Fechas::diaYHora(Fechas::fecha($t)) . "\nS/ " . number_format($v, 1, '.', ',') . ' por MWh';
    }

    private static function rango(array $v, float $margen = 0.06, ?float $minimo = null): array
    {
        $v = array_values(array_filter($v, static fn($x) => $x !== null));
        if (!$v) {
            return [0.0, 1.0];
        }
        $a = $minimo ?? min($v);
        $b = max($v);
        $pad = ($b - $a) * $margen ?: 1;
        return [$minimo ?? $a - $pad, $b + $pad];
    }

    /** Puntos [segundos, valor] de una serie con marcas de tiempo. */
    private static function puntos(array $ts, array $valores): array
    {
        $p = [];
        foreach ($ts as $i => $t) {
            $p[] = [(float)self::ts($t), isset($valores[$i]) ? (float)$valores[$i] : null];
        }
        return $p;
    }

    // ---------- precios ----------

    /** Precio de la semana: linea azul sobre area celeste, el maximo en naranja. */
    public static function precioSemana(array $cmg, array $umbrales): string
    {
        $c = self::p();
        $l = new Lienzo();
        $pts = self::puntos($cmg['ts'], $cmg['valor']);
        if (!$pts) {
            return $l->svg('Sin datos de precio');
        }
        $segundos = array_column($pts, 0);
        [$y0, $y1] = self::rango(array_merge($cmg['valor'], [$umbrales['alto']]), 0.08, 0.0);
        $l->dominio(min($segundos), max($segundos), $y0, $y1)->ejeY(self::miles(...))->lineaBase();
        $l->area($pts, $c['celeste'])->linea($pts, $c['azul'], 1.8);
        $l->referenciaY((float)$umbrales['alto'], $c['naranja'], ' caro: más de S/ ' . $umbrales['alto'], $c['naranja_texto']);
        $imax = array_search(max(array_filter($cmg['valor'], static fn($v) => $v !== null)), $cmg['valor'], true);
        $l->punto($pts[$imax][0], $pts[$imax][1], $c['naranja'], 5.5);
        self::ejeDias($l, $segundos);
        foreach ($pts as $i => [$x, $y]) {
            if ($y !== null) {
                $l->hover($l->x($x), $l->y($y), self::textoSoles($cmg['ts'][$i], $y));
            }
        }
        return $l->svg('Precio del costo marginal durante la semana', 'serie');
    }

    /** Precio promedio por hora del dia: barras celestes, la hora mas cara en naranja. @param array<int, array{int, ?float, ?float}> $perfil */
    public static function perfilHorario(array $perfil): string
    {
        $c = self::p();
        $l = new Lienzo();
        $actual = array_map(static fn($f) => $f[1], $perfil);
        if (!array_filter($actual, static fn($v) => $v !== null)) {
            return $l->svg('Sin datos de perfil horario');
        }
        [, $y1] = self::rango(array_merge($actual, array_map(static fn($f) => $f[2], $perfil)), 0.08, 0.0);
        $l->dominio(-0.6, 23.6, 0, $y1)->ejeY(self::miles(...))->lineaBase();
        $imax = array_search(max(array_filter($actual, static fn($v) => $v !== null)), $actual, true);
        $ancho = ($l->x(1) - $l->x(0)) * 0.72;
        foreach ($perfil as $i => [$h, $v, $ant]) {
            if ($v === null) {
                continue;
            }
            $l->rect($l->x($h) - $ancho / 2, $l->y($v), $ancho, $l->y(0) - $l->y($v), $i === $imax ? $c['naranja'] : $c['azul_claro']);
            $l->hover($l->x($h), $l->y($v), "$h:00 h\nS/ " . number_format($v, 1, '.', ',') . ' por MWh');
        }
        $previa = array_map(static fn($f) => [(float)$f[0], $f[2]], $perfil);
        if (array_filter($previa, static fn($p) => $p[1] !== null)) {
            $l->linea($previa, $c['tinta'], 1.2, '2 2');
            $l->leyenda([[$c['tinta'], 'Semana anterior', 'guion']]);
        }
        $l->ejeXNumerico(range(0, 21, 3), static fn($h) => "$h h");
        return $l->svg('Precio promedio por hora del dia', 'barras');
    }

    /** Hoy (azul) frente a ayer (gris) y a lo programado por el COES (naranja punteado). @param array<int, array{string, float}> $hoy */
    public static function ahora(array $hoy, array $ayer, ?array $programado = null): string
    {
        $c = self::p();
        $l = new Lienzo(720, 260);
        if (!$hoy) {
            return $l->svg('Sin datos de hoy');
        }
        $dia = self::diaDe(self::ts(end($hoy)[0]));
        $i0 = self::ts("$dia 00:00");
        $valores = array_merge(array_column($hoy, 1), array_column($ayer, 1), $programado ? array_column($programado, 1) : []);
        [, $y1] = self::rango($valores, 0.08, 0.0);
        $l->dominio($i0, $i0 + 86400, 0, $y1)->ejeY(self::miles(...))->lineaBase();
        $items = [];
        if ($programado) {
            $l->linea(array_map(fn($f) => [(float)self::ts($f[0]), $f[1]], $programado), $c['naranja'], 1.6, '4 3');
            $items[] = [$c['naranja'], 'Programado', 'guion'];
        }
        if ($ayer) {
            $l->linea(array_map(fn($f) => [(float)self::ts($f[0]) + 86400, $f[1]], $ayer), self::AYER, 2);
            $items[] = [self::AYER, 'Ayer', 'linea'];
        }
        $l->linea(array_map(fn($f) => [(float)self::ts($f[0]), $f[1]], $hoy), $c['azul'], 2.6);
        $items[] = [$c['azul'], 'Hoy', 'linea'];
        $ultimo = end($hoy);
        $l->punto((float)self::ts($ultimo[0]), $ultimo[1], $c['naranja'], 6.5);
        foreach ($hoy as [$t, $v]) {
            $l->hover($l->x(self::ts($t)), $l->y($v), self::textoSoles($t, $v));
        }
        $l->ejeXNumerico(array_map(fn($h) => $i0 + $h * 3600, [0, 6, 12, 18, 24]), fn($s) => (int)(($s - $i0) / 3600) . ' h');
        $l->leyenda($items);
        return $l->svg('Precio de hoy frente a ayer y a lo programado', 'serie');
    }

    /** Mini curva para tarjetas. @param float[] $valores */
    public static function sparkline(array $valores, int $ancho = 260, int $alto = 56): string
    {
        $c = self::p();
        $l = new Lienzo($ancho, $alto, 4, 4, 4, 4);
        $n = count($valores);
        if ($n > 1) {
            [$y0, $y1] = self::rango($valores, 0.1);
            $l->dominio(0, $n - 1, $y0, $y1)->linea(array_map(static fn($v, $i) => [(float)$i, $v], $valores, array_keys($valores)), $c['azul'], 2);
        }
        return $l->svg('Tendencia reciente', 'sparkline');
    }

    // ---------- demanda y generacion ----------

    /** Consumo real (azul) frente a lo programado (gris), con el pico en naranja. */
    public static function demanda(array $dem): string
    {
        $c = self::p();
        $l = new Lienzo();
        if (empty($dem['ts'])) {
            return $l->svg('Sin datos de demanda');
        }
        $real = self::puntos($dem['ts'], $dem['ejecutada']);
        $prog = self::puntos($dem['ts'], $dem['prog_diaria']);
        $seg = array_column($real, 0);
        [$y0, $y1] = self::rango(array_merge($dem['ejecutada'], $dem['prog_diaria']), 0.08);
        $l->dominio(min($seg), max($seg), $y0, $y1)->ejeY(self::miles(...))->lineaBase();
        $l->linea($prog, self::AYER, 1.6)->linea($real, $c['azul'], 1.9);
        $validos = array_filter($dem['ejecutada'], static fn($v) => $v !== null);
        if ($validos) {
            $i = array_search(max($validos), $dem['ejecutada'], true);
            $l->punto($real[$i][0], $real[$i][1], $c['naranja'], 5.5);
        }
        foreach ($real as $i => [$x, $y]) {
            if ($y !== null) {
                $l->hover($l->x($x), $l->y($y), Fechas::diaYHora(Fechas::fecha($dem['ts'][$i])) . "\n" . self::miles($y) . ' MW');
            }
        }
        self::ejeDias($l, $seg);
        $l->leyenda([[self::AYER, 'Lo programado', 'linea'], [$c['azul'], 'Consumo real', 'linea']]);
        return $l->svg('Consumo real frente a lo programado', 'serie');
    }

    /** Generacion apilada con un color fijo por tecnologia. */
    public static function generacion(array $gen): string
    {
        $l = new Lienzo(720, 300);
        if (empty($gen['ts'])) {
            return $l->svg('Sin datos de generacion');
        }
        $orden = Config::proyecto()['orden_tecnologias'];
        $tecs = array_values(array_filter($orden, static fn($t) => isset($gen['tecnologias'][$t]) && array_sum(array_map(static fn($v) => max((float)$v, 0), $gen['tecnologias'][$t])) > 0));
        $n = count($gen['ts']);
        $seg = array_map([self::class, 'ts'], $gen['ts']);
        $acum = array_fill(0, $n, 0.0);
        $capas = [];
        foreach ($tecs as $t) {
            $abajo = $acum;
            for ($i = 0; $i < $n; $i++) {
                $acum[$i] += max((float)($gen['tecnologias'][$t][$i] ?? 0), 0);
            }
            $capas[] = [$t, $abajo, $acum];
        }
        $l->dominio(min($seg), max($seg), 0, max($acum) * 1.05)->ejeY(self::miles(...))->lineaBase();
        foreach ($capas as [$t, $abajo, $arriba]) {
            $l->area(array_map(static fn($s, $v) => [(float)$s, $v], $seg, $arriba), Config::colorTecnologia($t),
                array_map(static fn($s, $v) => [(float)$s, $v], $seg, $abajo));
        }
        foreach ($seg as $i => $s) {
            $l->hover($l->x($s), $l->y($acum[$i]), Fechas::diaYHora(Fechas::fecha($gen['ts'][$i])) . "\nTotal: " . self::miles($acum[$i]) . ' MW');
        }
        self::ejeDias($l, $seg);
        $l->leyenda(array_map(static fn($t) => [Config::colorTecnologia($t), Config::nombreTecnologia($t), 'caja'], $tecs));
        return $l->svg('Generacion por tecnologia', 'serie');
    }

    /** Barras horizontales por tecnologia: esta semana en color y la marca oscura de la semana anterior. */
    public static function mix(array $mix, array $anterior): string
    {
        $c = self::p();
        $tecs = array_filter($mix, static fn($v) => $v >= 0.001);
        asort($tecs);
        $tecs = array_keys($tecs);
        $alto = max(150, 38 * count($tecs) + 50);
        $l = new Lienzo(720, $alto, 28, 60, 8, 150);
        $l->dominio(0, 114, 0, count($tecs));
        $h = ($l->y(0) - $l->y(1)) * 0.42;
        foreach ($tecs as $i => $t) {
            $yc = $l->y($i + 0.5);
            $l->rect($l->x(0), $yc - $h / 2, $l->x(100) - $l->x(0), $h, '#EEF2F7');
            $l->rect($l->x(0), $yc - $h / 2, $l->x($mix[$t] * 100) - $l->x(0), $h, Config::colorTecnologia($t));
            $l->texto($l->l - 10, $yc + 4, Config::nombreTecnologia($t), 'eje', 'end', $c['tinta']);
            $l->texto($l->x(101.5), $yc + 4, number_format($mix[$t] * 100, 1) . '%', 'valor', 'start', $c['tinta']);
            if ($anterior) {
                $xa = $l->x(($anterior[$t] ?? 0) * 100);
                $l->agregar('<line x1="' . Lienzo::n($xa) . '" x2="' . Lienzo::n($xa) . '" y1="' . Lienzo::n($yc - 10) . '" y2="' . Lienzo::n($yc + 10) . '" stroke="' . $c['tinta'] . '" stroke-width="2.2"/>');
            }
            $l->hover($l->x($mix[$t] * 100), $yc, Config::nombreTecnologia($t) . "\n" . number_format($mix[$t] * 100, 1) . '% de la energía'
                . ($anterior ? ' (antes ' . number_format(($anterior[$t] ?? 0) * 100, 1) . '%)' : ''));
        }
        if ($anterior) {
            $l->leyenda([[$c['tinta'], 'Semana anterior', 'marca']]);
        }
        return $l->modoHover('y')->svg('Participacion de cada fuente en la generacion', 'barras');
    }

    // ---------- hidrologia ----------

    /** Volumen total de agua por dia. */
    public static function embalses(array $emb): string
    {
        $l = new Lienzo();
        $n = count($emb['fecha'] ?? []);
        if (!$n) {
            return $l->svg('Sin datos de embalses');
        }
        $hidro = Config::colorTecnologia('hidro');
        $valores = array_map('floatval', $emb['valor']);
        $base = min($valores) * 0.98;
        [, $y1] = self::rango($valores, 0.06);
        $l->dominio(-0.3, $n - 0.7, $base, $y1)->ejeY(self::miles(...))->lineaBase();
        $pts = array_map(static fn($v, $i) => [(float)$i, $v], $valores, array_keys($valores));
        $l->area($pts, $hidro, $base, 0.15)->linea($pts, $hidro, 2.4);
        foreach ($pts as $i => [$x, $y]) {
            $f = Fechas::fecha($emb['fecha'][$i]);
            $etiqueta = Fechas::ABREV[Fechas::diaSemana($f)] . ' ' . (int)$f->format('j');
            $l->punto($x, $y, $hidro, 4.5)->texto($l->x($x), $l->areaY1() + 16, $etiqueta, 'eje', 'middle');
            $l->hover($l->x($x), $l->y($y), "$etiqueta\n" . number_format($y, 1, '.', ',') . ' Hm3');
        }
        return $l->svg('Agua util en embalses por dia', 'serie');
    }

    /** Barras horizontales (ranking) con marca de la semana anterior. @param string[] $nombres @param float[] $valores */
    public static function barrasHorizontales(array $nombres, array $valores, ?array $anteriores = null, ?callable $formato = null, string $color = '', string $marca = ''): string
    {
        $c = self::p();
        $color = $color ?: $c['azul'];
        $marca = $marca ?: $c['naranja'];
        $formato ??= static fn(float $v): string => number_format($v * 100, 1) . '%';
        $n = count($nombres);
        $l = new Lienzo(720, max(120, 30 * $n + 40), 26, 70, 6, 190);
        $tope = $valores ? max($valores) : 1.0;
        $tope = $tope > 0 ? $tope : 1.0;
        $l->dominio(0, $tope * 1.18, 0, $n);
        $h = ($l->y(0) - $l->y(1)) * 0.55;
        $usaAnt = $anteriores !== null && array_filter($anteriores, static fn($v) => $v !== null);
        foreach ($nombres as $i => $nombre) {
            $yc = $l->y($n - $i - 0.5);
            $l->rect($l->x(0), $yc - $h / 2, $l->x((float)$valores[$i]) - $l->x(0), $h, $color);
            $etiqueta = mb_strlen($nombre) > 34 ? mb_substr($nombre, 0, 33) . '…' : $nombre;
            $l->texto($l->l - 10, $yc + 4, mb_convert_case($etiqueta, MB_CASE_TITLE), 'eje', 'end', $c['tinta']);
            $l->texto($l->x((float)$valores[$i]) + 6, $yc + 4, $formato((float)$valores[$i]), 'valor', 'start', $c['tinta']);
            if ($usaAnt && ($anteriores[$i] ?? null) !== null) {
                $xa = $l->x((float)$anteriores[$i]);
                $l->agregar('<line x1="' . Lienzo::n($xa) . '" x2="' . Lienzo::n($xa) . '" y1="' . Lienzo::n($yc - 9) . '" y2="' . Lienzo::n($yc + 9) . '" stroke="' . $marca . '" stroke-width="2.4"/>');
            }
            $l->hover($l->x((float)$valores[$i]), $yc, $nombre . "\n" . $formato((float)$valores[$i]));
        }
        if ($usaAnt) {
            $l->leyenda([[$marca, 'Semana anterior', 'marca']]);
        }
        return $l->modoHover('y')->svg('Ranking', 'barras');
    }

    /** Los n embalses con mas agua: esta semana (azul) y la anterior (marca oscura). @param array<int, array<string, mixed>> $detalle */
    public static function embalsesDetalle(array $detalle, int $n = 8): string
    {
        $c = self::p();
        $top = array_filter($detalle, static fn($d) => $d['actual'] !== null);
        usort($top, static fn($a, $b) => $b['actual'] <=> $a['actual']);
        $top = array_slice($top, 0, $n);
        return self::barrasHorizontales(array_column($top, 'entidad'), array_map('floatval', array_column($top, 'actual')),
            array_map(static fn($d) => $d['anterior'] === null ? null : (float)$d['anterior'], $top),
            static fn(float $v): string => number_format($v, 1) . ' Hm3', Config::colorTecnologia('hidro'), $c['tinta']);
    }

    // ---------- mapa ----------

    /** Interpola una lista de colores hex en t de 0 a 1. @param string[] $paradas */
    public static function color(array $paradas, float $t): string
    {
        $t = max(0.0, min(1.0, $t)) * (count($paradas) - 1);
        $i = min((int)floor($t), count($paradas) - 2);
        $f = $t - $i;
        $a = sscanf($paradas[$i], '#%02x%02x%02x');
        $b = sscanf($paradas[$i + 1], '#%02x%02x%02x');
        return sprintf('#%02X%02X%02X', ...array_map(static fn($x, $y) => (int)round($x + ($y - $x) * $f), $a, $b));
    }

    /** Peru con cada barra coloreada por su costo marginal (azul barato, naranja caro). @param array<int, array<string, mixed>> $barras */
    public static function mapa(array $barras): string
    {
        $c = self::p();
        $geo = json_decode((string)file_get_contents(Config::raiz() . '/assets/peru.geo.json'), true);
        $g = $geo['features'][0]['geometry'];
        $anillos = $g['type'] === 'Polygon' ? $g['coordinates'] : array_merge(...$g['coordinates']);
        $lons = $lats = [];
        foreach ($anillos as $a) {
            foreach ($a as [$lon, $lat]) {
                $lons[] = $lon;
                $lats[] = $lat;
            }
        }
        [$lo0, $lo1, $la0, $la1] = [min($lons), max($lons), min($lats), max($lats)];
        // proporcion real: un grado de longitud mide casi lo mismo que uno de latitud cerca del ecuador
        $ancho = 430.0;
        $alto = $ancho * ($la1 - $la0) / ($lo1 - $lo0);
        $l = new Lienzo($ancho + 90, $alto + 20, 10, 80, 10, 10);
        $l->dominio($lo0, $lo1, $la0, $la1);
        foreach ($anillos as $a) {
            $d = '';
            foreach ($a as $i => [$lon, $lat]) {
                $d .= ($i ? 'L' : 'M') . Lienzo::n($l->x($lon)) . ' ' . Lienzo::n($l->y($lat));
            }
            $l->agregar('<path d="' . $d . 'Z" fill="#EEF3F9" stroke="' . $c['linea'] . '" stroke-width="1.2"/>');
        }
        $con = array_values(array_filter($barras, static fn($b) => $b['latitud'] !== null && $b['longitud'] !== null && $b['cmg_promedio'] !== null));
        if (!$con) {
            return $l->svg('Sin datos por zona');
        }
        $precios = array_column($con, 'cmg_promedio');
        [$p0, $p1] = [min($precios), max($precios)];
        $paradas = [$c['azul'], '#F5C518', $c['naranja']];
        foreach ($con as $b) {
            $t = $p1 > $p0 ? ($b['cmg_promedio'] - $p0) / ($p1 - $p0) : 0.5;
            $l->agregar('<circle cx="' . Lienzo::n($l->x($b['longitud'])) . '" cy="' . Lienzo::n($l->y($b['latitud'])) . '" r="5.2" fill="' . self::color($paradas, $t) . '" stroke="#FFFFFF" stroke-width="1"/>');
            $l->hover($l->x($b['longitud']), $l->y($b['latitud']), mb_convert_case($b['barra'], MB_CASE_TITLE) . "\nS/ " . number_format($b['cmg_promedio'], 1, '.', ',') . ' por MWh');
        }
        usort($con, static fn($a, $b) => $a['cmg_promedio'] <=> $b['cmg_promedio']);
        $puestas = [];
        foreach ([$con[0], $con[count($con) - 1], $con[max(count($con) - 2, 0)]] as $b) {
            $px = $l->x($b['longitud']) + 8;
            $py = $l->y($b['latitud']) - 2;
            foreach ($puestas as [$qx, $qy]) {
                if (abs($px - $qx) < 90 && abs($py - $qy) < 26) {
                    $py = $qy + 26;     // evita que dos etiquetas cercanas se pisen
                }
            }
            $puestas[] = [$px, $py];
            $l->texto($px, $py, mb_convert_case($b['barra'], MB_CASE_TITLE), 'etiqueta-mapa', 'start', $c['tinta']);
            $l->texto($px, $py + 11, 'S/ ' . number_format($b['cmg_promedio'], 0), 'etiqueta-mapa', 'start', $c['tinta']);
        }
        // barra de color
        $x = $ancho + 36;
        $y0 = $alto * 0.25;
        $h = $alto * 0.5;
        $l->agregar('<defs><linearGradient id="gradMapa" x1="0" y1="1" x2="0" y2="0"><stop offset="0" stop-color="' . $paradas[0] . '"/><stop offset="0.5" stop-color="' . $paradas[1]
            . '"/><stop offset="1" stop-color="' . $paradas[2] . '"/></linearGradient></defs>');
        $l->agregar('<rect x="' . $x . '" y="' . Lienzo::n($y0) . '" width="10" height="' . Lienzo::n($h) . '" fill="url(#gradMapa)" rx="2"/>');
        $l->texto($x + 16, $y0 + 8, number_format($p1, 0), 'eje');
        $l->texto($x + 16, $y0 + $h, number_format($p0, 0), 'eje');
        $l->texto($x - 4, $y0 - 8, 'S/ por MWh', 'eje');
        return $l->modoHover('cerca', ['radio' => 14])->svg('Mapa del Peru con el precio promedio por barra', 'mapa');
    }

    // ---------- evolucion (rango libre) ----------

    /**
     * Promedio diario (azul), banda minimo a maximo, punta (naranja) y promedio del periodo; los dias sin datos quedan en blanco
     * y los no confirmados llevan un punto hueco.
     * @param array<int, array<string, mixed>> $dia fecha, promedio, minimo, maximo, punta, confirmado
     */
    public static function evolucionDiaria(array $dia, string $unidad = 'S/'): string
    {
        $c = self::p();
        $l = new Lienzo();
        if (!$dia) {
            return $l->svg('Sin datos en el rango');
        }
        $por = array_column($dia, null, 'fecha');
        $primero = Fechas::fecha(min(array_keys($por)));
        $ultimo = Fechas::fecha(max(array_keys($por)));
        $n = (int)$primero->diff($ultimo)->days + 1;
        $fechas = [];
        for ($i = 0; $i < $n; $i++) {
            $fechas[] = $primero->modify("+$i days")->format('Y-m-d');
        }
        $get = static fn(string $campo): array => array_map(static fn($f, $i) => [(float)$i, isset($por[$f][$campo]) ? (float)$por[$f][$campo] : null], $fechas, array_keys($fechas));
        [$y0, $y1] = self::rango(array_merge(array_column($dia, 'minimo'), array_column($dia, 'maximo')), 0.06, 0.0);
        $l->dominio(0, max($n - 1, 1), $y0, $y1)->ejeY(self::miles(...))->lineaBase();
        $l->area($get('maximo'), $c['celeste'], $get('minimo'));
        $l->linea($get('punta'), $c['naranja'], 1.4)->linea($get('promedio'), $c['azul'], 2.2);
        $media = array_sum(array_column($dia, 'promedio')) / count($dia);
        $l->referenciaY($media, $c['tinta'], 'prom. ' . self::miles($media) . ' ', $c['tinta'], 'end');
        $paso = max(1, (int)ceil($n / 9));
        foreach ($fechas as $i => $f) {
            $fd = Fechas::fecha($f);
            if ($i % $paso === 0) {
                $l->texto($l->x($i), $l->areaY1() + 16, (int)$fd->format('j') . '/' . (int)$fd->format('n'), 'eje', 'middle');
            }
            if (isset($por[$f]) && !$por[$f]['confirmado'] && $n <= 120) {
                $l->punto((float)$i, (float)$por[$f]['promedio'], $c['azul'], 3.6, true);
            }
            if (isset($por[$f])) {
                $l->hover($l->x($i), $l->y((float)$por[$f]['promedio']), $fd->format('d/m/Y') . "\nPromedio: $unidad " . number_format((float)$por[$f]['promedio'], 1, '.', ',') . ' por MWh');
            }
        }
        $l->leyenda([[$c['celeste'], 'Mínimo a máximo', 'caja'], [$c['naranja'], 'Punta (6 a 11 p. m.)', 'linea'], [$c['azul'], 'Promedio del día', 'linea'],
            [$c['azul'], 'Preliminar', 'punto']]);
        return $l->svg('Precio diario en el periodo', 'serie');
    }

    /**
     * Mapa de calor: dias (filas) por media hora (columnas); el color es el precio.
     * @param array{fechas: string[], filas: array<int, array<int, ?float>>} $m
     */
    public static function mapaCalor(array $m, string $unidad = 'S/'): string
    {
        $c = self::p();
        $filas = $m['filas'];
        $fechas = $m['fechas'];
        $n = count($filas);
        $alto = min(max(170, 11 * $n + 60), 520);
        $l = new Lienzo(720, $alto, 12, 70, 28, 46);
        if (!$n) {
            return $l->svg('Sin datos en el rango');
        }
        // muchos dias: se promedian grupos de dias para no dibujar miles de celdas
        $grupo = (int)max(1, ceil($n / 180));
        if ($grupo > 1) {
            $nuevas = $nf = [];
            for ($i = 0; $i < $n; $i += $grupo) {
                $fila = [];
                for ($col = 0; $col < 48; $col++) {
                    $vals = array_filter(array_map(static fn($k) => $filas[$k][$col] ?? null, range($i, min($i + $grupo, $n) - 1)), static fn($v) => $v !== null);
                    $fila[] = $vals ? array_sum($vals) / count($vals) : null;
                }
                $nuevas[] = $fila;
                $nf[] = $fechas[$i];
            }
            [$filas, $fechas, $n] = [$nuevas, $nf, count($nuevas)];
        }
        $todos = [];
        foreach ($filas as $f) {
            foreach ($f as $v) {
                if ($v !== null) {
                    $todos[] = $v;
                }
            }
        }
        if (!$todos) {
            return $l->svg('Sin datos en el rango');
        }
        [$v0, $v1] = [min($todos), max($todos)];
        $paradas = ['#EAF2FB', $c['azul_claro'], '#F5C518', $c['naranja'], '#B23A22'];
        $cw = ($l->areaX1() - $l->areaX0()) / 48;
        $ch = ($l->areaY1() - $l->areaY0()) / $n;
        $niveles = 32;
        foreach ($filas as $i => $fila) {
            $col = 0;
            while ($col < 48) {
                if ($fila[$col] === null) {
                    $col++;
                    continue;
                }
                $q = (int)round(($v1 > $v0 ? ($fila[$col] - $v0) / ($v1 - $v0) : 0.5) * ($niveles - 1));
                $fin = $col + 1;
                while ($fin < 48 && $fila[$fin] !== null && (int)round(($v1 > $v0 ? ($fila[$fin] - $v0) / ($v1 - $v0) : 0.5) * ($niveles - 1)) === $q) {
                    $fin++;
                }
                $l->rect($l->areaX0() + $col * $cw, $l->areaY0() + $i * $ch, ($fin - $col) * $cw + 0.4, $ch + 0.4, self::color($paradas, $q / ($niveles - 1)));
                $col = $fin;
            }
        }
        foreach ([0, 12, 24, 36, 47] as $k => $col) {
            $l->texto($l->areaX0() + ($col === 47 ? 48 : $col) * $cw, $l->areaY1() + 16, [0, 6, 12, 18, 24][$k] . ' h', 'eje', 'middle');
        }
        $paso = max(1, intdiv($n, 10));
        for ($i = 0; $i < $n; $i += $paso) {
            $f = Fechas::fecha($fechas[$i]);
            $l->texto($l->areaX0() - 6, $l->areaY0() + ($i + 0.5) * $ch + 3, (int)$f->format('j') . '/' . (int)$f->format('n'), 'eje', 'end');
        }
        // barra de color
        $x = $l->areaX1() + 14;
        $l->agregar('<defs><linearGradient id="gradCalor" x1="0" y1="1" x2="0" y2="0">' . implode('', array_map(
            static fn($p, $i) => '<stop offset="' . ($i / 4) . '" stop-color="' . $p . '"/>', $paradas, array_keys($paradas))) . '</linearGradient></defs>');
        $l->agregar('<rect x="' . Lienzo::n($x) . '" y="' . Lienzo::n($l->areaY0()) . '" width="9" height="' . Lienzo::n($l->areaY1() - $l->areaY0()) . '" fill="url(#gradCalor)" rx="2"/>');
        $l->texto($x + 13, $l->areaY0() + 8, number_format($v1, 0), 'eje');
        $l->texto($x + 13, $l->areaY1(), number_format($v0, 0), 'eje');
        $redondeo = array_map(static fn($f) => array_map(static fn($v) => $v === null ? null : (int)round($v), $f), $filas);
        return $l->modoHover('calor', [
            'x0' => round($l->areaX0(), 2), 'y0' => round($l->areaY0(), 2), 'cw' => round($cw, 3), 'ch' => round($ch, 3), 'filas' => $redondeo,
            'fechas' => array_map(static fn($f) => (int)Fechas::fecha($f)->format('j') . '/' . (int)Fechas::fecha($f)->format('n'), $fechas), 'unidad' => $unidad,
        ])->svg('Mapa de calor del precio: cada dia y cada media hora', 'calor');
    }

    /** Promedio por mes (azul) y su punta (naranja). @param array<int, array{mes: string, promedio: float, punta: ?float}> $mes */
    public static function mensualBarras(array $mes, string $unidad = 'S/'): string
    {
        $c = self::p();
        $l = new Lienzo(720, 260);
        if (!$mes) {
            return $l->svg('Sin datos mensuales');
        }
        $nombres = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'set', 'oct', 'nov', 'dic'];
        $n = count($mes);
        [, $y1] = self::rango(array_merge(array_column($mes, 'promedio'), array_filter(array_column($mes, 'punta'), static fn($v) => $v !== null)), 0.1, 0.0);
        $l->dominio(-0.6, $n - 0.4, 0, $y1)->ejeY(self::miles(...))->lineaBase();
        $ancho = ($l->x(1) - $l->x(0)) * 0.36;
        foreach ($mes as $i => $m) {
            [$anio, $mm] = explode('-', $m['mes']);
            $etiqueta = $nombres[(int)$mm - 1] . ' ' . substr($anio, 2);
            $l->rect($l->x($i - 0.18) - $ancho / 2, $l->y($m['promedio']), $ancho, $l->y(0) - $l->y($m['promedio']), $c['azul']);
            if ($m['punta'] !== null) {
                $l->rect($l->x($i + 0.18) - $ancho / 2, $l->y($m['punta']), $ancho, $l->y(0) - $l->y($m['punta']), $c['naranja']);
            }
            $l->texto($l->x($i - 0.18), $l->y($m['promedio']) - 4, self::miles($m['promedio']), 'valor-chico', 'middle', $c['tinta'])
                ->texto($l->x($i), $l->areaY1() + 16, $etiqueta, 'eje', 'middle');
            $l->hover($l->x($i), $l->y($m['promedio']), "$etiqueta\nPromedio: $unidad " . number_format($m['promedio'], 1, '.', ',') . ' por MWh');
        }
        $l->leyenda([[$c['azul'], 'Promedio', 'caja'], [$c['naranja'], 'Punta', 'caja']]);
        return $l->svg('Precio promedio por mes', 'barras');
    }

    // ---------- generadora ----------

    /** MW de una central cada media hora. @param string[] $ts @param float[] $serie */
    public static function generacionCentral(array $ts, array $serie): string
    {
        $c = self::p();
        $l = new Lienzo(720, 250);
        if (!$ts) {
            return $l->svg('Sin datos de la central');
        }
        $valores = array_map(static fn($v) => max((float)$v, 0.0), $serie);
        $pts = self::puntos($ts, $valores);
        $seg = array_column($pts, 0);
        [, $y1] = self::rango($valores, 0.08, 0.0);
        $l->dominio(min($seg), max($seg), 0, $y1)->ejeY(self::miles(...))->lineaBase();
        $l->area($pts, $c['celeste'])->linea($pts, $c['azul'], 1.6);
        foreach ($pts as $i => [$x, $y]) {
            $l->hover($l->x($x), $l->y($y), Fechas::diaYHora(Fechas::fecha($ts[$i])) . "\n" . self::miles($y) . ' MW');
        }
        self::ejeDias($l, $seg);
        return $l->svg('Generacion de la central en la semana', 'serie');
    }

    /** MW de generacion fuera de servicio por dia: programado (gris azulado) y forzado (naranja). @param array<int, array{fecha: string, programado: float, forzado: float}> $dia */
    public static function indisponibilidad(array $dia): string
    {
        $c = self::p();
        $l = new Lienzo(720, 250);
        if (!$dia) {
            return $l->svg('Sin datos de indisponibilidad');
        }
        $n = count($dia);
        $totales = array_map(static fn($d) => $d['programado'] + $d['forzado'], $dia);
        $l->dominio(-0.6, $n - 0.4, 0, max(max($totales) * 1.1, 1))->ejeY(self::miles(...))->lineaBase();
        $ancho = ($l->x(1) - $l->x(0)) * 0.6;
        foreach ($dia as $i => $d) {
            $f = Fechas::fecha($d['fecha']);
            $etiqueta = Fechas::ABREV[Fechas::diaSemana($f)] . ' ' . (int)$f->format('j');
            $l->rect($l->x($i) - $ancho / 2, $l->y($d['programado']), $ancho, $l->y(0) - $l->y($d['programado']), self::AYER);
            $l->rect($l->x($i) - $ancho / 2, $l->y($totales[$i]), $ancho, $l->y($d['programado']) - $l->y($totales[$i]), $c['naranja']);
            $l->texto($l->x($i), $l->areaY1() + 16, $etiqueta, 'eje', 'middle');
            $l->hover($l->x($i), $l->y($totales[$i]), "$etiqueta\n" . self::miles($totales[$i]) . ' MW fuera de servicio');
        }
        $l->leyenda([[self::AYER, 'Programado', 'caja'], [$c['naranja'], 'Forzado', 'caja']]);
        return $l->svg('Generacion fuera de servicio por dia', 'barras');
    }
}
