<?php
declare(strict_types=1);

namespace CuantoCoesta\Vistas;

use CuantoCoesta\Config;
use CuantoCoesta\Fechas;
use CuantoCoesta\Serie;
use CuantoCoesta\Ui;
use CuantoCoesta\Visual\Graficos as G;

/** Evolucion del precio en un rango libre de fechas: base/punta/fuera de punta, diario, mapa de calor y mensual. */
final class Evolucion
{
    /** Lee y valida los parametros del formulario. @return array{string, string, string, string} desde, hasta, barra, moneda */
    public static function parametros(array $get): array
    {
        $hoy = Fechas::hoy();
        if (!empty($get['atajo'])) {
            [$d, $h] = Fechas::rangoAtajo((string)$get['atajo'], $hoy, ($pf = Serie::primeraFecha()) ? Fechas::fecha($pf) : null);
        } else {
            $ok = static fn($t) => is_string($t) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) && strtotime($t) !== false;
            if ($ok($get['desde'] ?? null) && $ok($get['hasta'] ?? null)) {
                [$d, $h] = [Fechas::fecha($get['desde']), Fechas::fecha($get['hasta'])];
            } else {
                [$d, $h] = Fechas::rangoAtajo('30d', $hoy, null);
            }
        }
        if ($h < $d) {
            [$d, $h] = [$h, $d];
        }
        $barras = Serie::barrasDisponibles();
        $barra = in_array($get['barra'] ?? '', $barras, true) ? $get['barra'] : Serie::barraReferencia();
        $moneda = ($get['moneda'] ?? 'S/') === 'USD' ? 'USD' : 'S/';
        return [$d->format('Y-m-d'), $h->format('Y-m-d'), $barra, $moneda];
    }

    public static function render(array $get): string
    {
        [$desde, $hasta, $barra, $moneda] = self::parametros($get);
        $unidad = $moneda === 'USD' ? 'USD' : 'S/';
        $q = static fn(array $extra = []) => http_build_query(['s' => 'evolucion', 'desde' => $desde, 'hasta' => $hasta, 'barra' => $barra, 'moneda' => $moneda] + $extra);
        $fmt = static fn(?float $v): string => $v === null ? 's/d' : "$unidad " . number_format($v, 1, '.', ',');

        $html = Ui::cabecera('Evolución del precio', ucwords(mb_strtolower($barra)) . ' · del ' . Fechas::fecha($desde)->format('d/m/Y') . ' al ' . Fechas::fecha($hasta)->format('d/m/Y'));
        $atajos = '';
        foreach (['7d' => '7 días', '30d' => '30 días', 'mes' => 'Este mes', 'hidro' => 'Año hidrológico', 'anio' => 'Este año', 'todo' => 'Todo'] as $k => $nombre) {
            $atajos .= '<a class="boton sec" href="index.php?' . Ui::h(http_build_query(['s' => 'evolucion', 'atajo' => $k, 'barra' => $barra, 'moneda' => $moneda])) . '">' . Ui::h($nombre) . '</a>';
        }
        $opcionesBarra = '';
        foreach (Serie::barrasDisponibles() as $b) {
            $opcionesBarra .= '<option value="' . Ui::h($b) . '"' . ($b === $barra ? ' selected' : '') . '>' . Ui::h(ucwords(mb_strtolower($b))) . '</option>';
        }
        $botonesMoneda = '';
        foreach (['S/', 'USD'] as $m) {
            $botonesMoneda .= '<button class="boton' . ($m === $moneda ? '' : ' sec') . '" name="moneda" value="' . Ui::h($m) . '">' . Ui::h($m) . '</button>';
        }
        $html .= Ui::tarjeta('<div class="controles">' . $atajos . '<a class="boton" style="margin-left:auto" href="descargar.php?tipo=excel&amp;' . Ui::h(http_build_query(['desde' => $desde, 'hasta' => $hasta, 'barra' => $barra, 'moneda' => $moneda]))
            . '" title="Guarda cada media hora del rango y el resumen diario en un Excel">Exportar a Excel</a></div>'
            . '<form class="controles" method="get" action="index.php" style="margin:0"><input type="hidden" name="s" value="evolucion">'
            . '<label for="desde">Desde</label><input type="date" id="desde" name="desde" value="' . Ui::h($desde) . '"><label for="hasta">Hasta</label><input type="date" id="hasta" name="hasta" value="' . Ui::h($hasta) . '">'
            . '<button class="boton" name="moneda" value="' . Ui::h($moneda) . '">Ver</button><label for="barra">Barra</label><select id="barra" name="barra" data-auto>' . $opcionesBarra . '</select>' . $botonesMoneda . '</form>');

        $serie = Serie::cmgRango($desde, $hasta, $barra);
        if ($moneda === 'USD') {
            \CuantoCoesta\Bcrp::actualizar($desde, $hasta);
            $serie = Serie::enDolares($serie);
        }
        if (!$serie) {
            return $html . '<p>No hay datos en este rango. Prueba otro rango.</p>';
        }
        $r = Serie::resumir($serie, $desde, $hasta);
        $dif = ($r['punta'] && $r['base']) ? ($r['punta'] / $r['base'] - 1) * 100 : null;
        $html .= Ui::cifras([
            ['etiqueta' => 'Promedio', 'valor' => $fmt($r['base']), 'unidad' => 'por MWh (todo el día)', 'tam' => 20, 'explicacion' => 'Promedio de todas las medias horas del rango.'],
            ['etiqueta' => 'Punta (6 a 11 p. m.)', 'valor' => $fmt($r['punta']), 'unidad' => 'por MWh', 'tam' => 20,
                'cambio' => $dif === null ? '' : sprintf('%+.0f', $dif) . '% vs promedio', 'tono' => ($r['punta'] && $r['base'] && $r['punta'] > $r['base'] * 1.1) ? 'alerta' : 'neutro',
                'explicacion' => 'Las horas de mayor consumo. Los contratos y la potencia se miran en este bloque.'],
            ['etiqueta' => 'Fuera de punta', 'valor' => $fmt($r['fuera_punta']), 'unidad' => 'por MWh', 'tam' => 20, 'explicacion' => 'Todas las horas menos las de punta.'],
            ['etiqueta' => 'Más alto', 'valor' => $fmt($r['maximo']), 'tam' => 20, 'detalle' => $r['momento_max'] ? Fechas::diaYHora(Fechas::fecha($r['momento_max'])) : '', 'explicacion' => Ui::explicacion('cmg_maximo')],
            ['etiqueta' => 'Más bajo', 'valor' => $fmt($r['minimo']), 'tam' => 20, 'detalle' => $r['momento_min'] ? Fechas::diaYHora(Fechas::fecha($r['momento_min'])) : '', 'explicacion' => Ui::explicacion('cmg_minimo')],
        ]);
        $calidad = number_format($r['cobertura'] * 100, 0) . '% de las medias horas con dato · ' . $r['dias'] . ' días · ' . $r['dias_preliminares']
            . ' días con datos preliminares (punto hueco en el gráfico)';
        if ($r['congestion_media'] !== null) {
            $calidad .= " · congestión media $unidad " . number_format($r['congestion_media'], 1);
        }
        if ($moneda === 'USD') {
            $calidad .= ' · tipo de cambio BCRP (venta)';
        }
        $html .= '<p class="nota" style="color:var(--c-gris);font-size:13px;margin:0 0 12px">' . Ui::h($calidad) . '</p>';

        $html .= Ui::tarjetaGrafico('Precio día por día', G::evolucionDiaria(Serie::diario($serie), $unidad), "$unidad por MWh");
        $html .= Ui::tarjetaGrafico('Mapa de calor: cada día, cada media hora', G::mapaCalor(Serie::matrizCalor($serie), $unidad), 'más oscuro = más caro');
        $mes = Serie::mensual($serie);
        if (count($mes) > 1) {
            $html .= Ui::tarjetaGrafico('Promedio por mes', G::mensualBarras($mes, $unidad), "$unidad por MWh");
        }
        return $html;
    }
}
