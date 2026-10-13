<?php
declare(strict_types=1);

namespace CuantoCoesta\Vistas;

use CuantoCoesta\Fechas;
use CuantoCoesta\Formato;
use CuantoCoesta\Ui;
use CuantoCoesta\Visual\Graficos as G;

/** Seccion Ahora: precio de hoy con memoria (frente a hace media hora, ayer y hace una semana) y lo programado por el COES. */
final class Ahora
{
    public static function render(?array $e): string
    {
        $cab = '<div class="controles" style="justify-content:space-between"><div class="cabecera" style="margin:0"><h1>Ahora</h1>';
        if ($e && empty($e['sin_datos'])) {
            $antig = (float)$e['antiguedad_min'];
            $viejo = $antig > 90;
            $texto = $viejo ? 'Sin datos nuevos del COES hace ' . number_format($antig / 60, 1) . ' h' : 'Último dato hace ' . (int)round($antig) . ' min · se actualiza cada 30 min';
            $cab .= '<p class="punto-estado' . ($viejo ? ' viejo' : '') . '"><b>●</b> ' . Ui::h($texto) . '</p>';
        }
        $cab .= '</div><div class="controles" style="margin:0"><label style="display:flex;gap:6px;align-items:center;font-weight:600"><input type="checkbox" id="auto-30" checked> Cada 30 min</label>'
            . '<button type="button" class="boton" data-actualizar-ahora>↻ Actualizar ahora</button></div></div>';
        if (!$e || !empty($e['sin_datos'])) {
            return '<div data-auto-ahora="30" data-viejo="1">' . $cab . '<p style="font-weight:700;font-size:17px">Aún no hay datos en vivo. Pulsa «Actualizar ahora».</p></div>';
        }
        $viejo40 = (float)$e['antiguedad_min'] > 40;
        $html = '<div data-auto-ahora="30" data-viejo="' . ($viejo40 ? '1' : '0') . '">' . $cab;

        $refs = '';
        foreach ($e['referencias'] as $r) {
            $var = $r['var'];
            $pil = $var === null ? Ui::pildora('s/d') : Ui::pildora(($var > 0 ? '▲ ' : '▼ ') . number_format(abs($var) * 100, 0) . '%', abs($var) < 0.05 ? 'neutro' : ($var > 0 ? 'malo' : 'bueno'));
            $refs .= Ui::tarjeta('<div class="ref"><div><div class="n">' . Ui::h(mb_strtoupper(mb_substr($r['nombre'], 0, 1)) . mb_substr($r['nombre'], 1)) . '</div><div class="v">'
                . Ui::h(Ui::soles($r['valor'])) . '</div></div>' . $pil . '</div>', 'ref-t');
        }
        $hero = '<div class="hero"><div class="t">Precio en Lima · ' . Ui::h(Ui::horaCorta($e['momento'])) . '</div><div class="cifra-grande">' . Ui::h(Ui::soles($e['valor']))
            . '<small>por MWh</small></div>' . Ui::pildora($e['lectura']['texto'], $e['lectura']['tono']) . '</div>';
        $html .= '<div class="dos-col" style="grid-template-columns:5fr 4fr">' . $hero . '<div class="refs">' . $refs . '</div></div>';

        $prog = $e['programa'] ?? ['hoy' => [], 'manana' => []];
        $lado = '';
        foreach ($prog['hoy'] as [$fh, $plan]) {
            if ($fh === $e['momento']) {
                $dif = $plan ? $e['valor'] / $plan - 1 : 0;
                $tono = $dif > 0.3 ? 'alerta' : ($dif < -0.3 ? 'info' : 'neutro');
                $lado .= Ui::cifra(['etiqueta' => 'Programado a esta hora', 'valor' => Ui::soles((float)$plan), 'unidad' => 'por MWh', 'tam' => 24,
                    'cambio' => 'El real va ' . number_format(abs($dif) * 100, 0) . '% ' . ($dif > 0 ? 'arriba' : 'abajo'), 'tono' => $tono,
                    'explicacion' => 'Costo marginal que el COES calculó el día anterior en su Programa Diario de Operación (modelo YUPANA, en dólares, convertido a soles con el tipo de cambio BCRP).']);
                break;
            }
        }
        if ($prog['manana']) {
            $valores = array_column($prog['manana'], 1);
            $imax = array_search(max($valores), $valores, true);
            $lado .= Ui::cifra(['etiqueta' => 'Mañana se espera', 'valor' => Ui::soles(array_sum($valores) / count($valores)), 'unidad' => 'por MWh en promedio', 'tam' => 24,
                'cambio' => 'pico de ' . Ui::soles((float)$valores[$imax]) . ' a las ' . Ui::horaCorta($prog['manana'][$imax][0]), 'tono' => 'info',
                'explicacion' => 'Programa Diario de Operación del COES para mañana. Es una previsión: el precio real puede ser muy distinto si cambian el agua, la demanda o salen centrales.']);
        } else {
            $lado .= '<p class="nota" style="color:var(--c-gris);font-size:13px">El programa de mañana lo publica el COES por la tarde.</p>';
        }
        if (!empty($e['demanda'])) {
            $d = $e['demanda'];
            $desvio = $d['desvio'];
            if ($desvio === null) {
                [$txt, $tono] = ['sin programa', 'neutro'];
            } elseif (abs($desvio) < 0.02) {
                [$txt, $tono] = ['Casi igual a lo previsto (' . sprintf('%+.1f', $desvio * 100) . '%)', 'neutro'];
            } else {
                [$txt, $tono] = [sprintf('%+.1f', $desvio * 100) . '% vs lo previsto', 'info'];
            }
            $lado .= Ui::cifra(['etiqueta' => 'Consumo del país', 'valor' => Formato::numero($d['valor']), 'unidad' => 'MW', 'cambio' => $txt, 'tono' => $tono, 'tam' => 24,
                'explicacion' => Ui::explicacion('demanda_maxima')]);
        }
        $lado .= '<div class="caja-celeste" style="margin-top:14px">Dato preliminar: el COES lo confirma en 1 o 2 días.</div>';
        if (!empty($e['alertas'])) {
            $lado .= '<section class="tarjeta" style="margin-top:14px"><b>Por qué se marca</b>' . implode('', array_map(static fn($a) => '<p style="margin:6px 0 0;font-size:13.5px">' . Ui::h($a) . '</p>', $e['alertas'])) . '</section>';
        }
        $html .= '<div class="dos-col" style="grid-template-columns:3fr 2fr">' . Ui::tarjetaGrafico('Hoy frente a ayer y a lo programado',
            G::ahora($e['serie_hoy'], $e['serie_ayer'], $prog['hoy'] ?: null)) . '<div class="apilado">' . $lado . '</div></div>';
        return $html . '</div>';
    }
}
