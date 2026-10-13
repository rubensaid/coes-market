<?php
declare(strict_types=1);

namespace CuantoCoesta\Vistas;

use CuantoCoesta\Config;
use CuantoCoesta\Fechas;
use CuantoCoesta\Formato;
use CuantoCoesta\Ui;
use CuantoCoesta\Visual\Graficos as G;

/** Eventos (salidas de servicio) y Centrales (generadoras y empresas). */
final class Operacion
{
    private static function fechaCorta(string $fh): string
    {
        return Fechas::fecha($fh)->format('d/m H:i');
    }

    public static function eventos(array $c): string
    {
        $p = $c['p'];
        $ev = $p['eventos'] ?? ['hay' => false];
        $html = Ui::cabecera('Eventos', 'Salidas de servicio, mantenimientos y fallas · ' . Fechas::rangoCorto($p['semana']));
        if (empty($ev['hay'])) {
            return $html . '<p>No hay eventos registrados para esta semana.</p>';
        }
        $html .= Ui::cifras([
            ['etiqueta' => 'Salidas forzadas', 'valor' => (string)$ev['forzadas'], 'unidad' => 'de generación',
                'cambio' => $ev['forzadas'] ? Formato::numero($ev['forzadas_mw']) . ' MW en total' : 'ninguna', 'tono' => $ev['forzadas'] ? 'alerta' : 'bueno',
                'explicacion' => 'Centrales o unidades que salieron de servicio sin estar programado (fallas, emergencias).'],
            ['etiqueta' => 'Mantenimientos programados', 'valor' => (string)$ev['programadas'], 'unidad' => 'de generación', 'explicacion' => 'Salidas avisadas con anticipación al COES.'],
            ['etiqueta' => 'Máximo fuera de servicio', 'valor' => Formato::numero($ev['maximo_fuera_mw']), 'unidad' => 'MW en un día',
                'explicacion' => 'Suma de la potencia de las unidades de generación que estuvieron fuera ese día.'],
            ['etiqueta' => 'Eventos en la red', 'valor' => (string)$ev['eventos_red'], 'unidad' => 'desconexiones',
                'explicacion' => 'Desconexiones de líneas, transformadores o generadores reportadas.'],
        ]);
        $html .= Ui::tarjetaGrafico('Generación fuera de servicio por día', G::indisponibilidad($ev['por_dia']), 'MW');
        $filas = '';
        foreach ($ev['grandes'] as $f) {
            $nombre = mb_convert_case((string)$f['ubicacion'], MB_CASE_TITLE) . (in_array($f['equipo'], ['CENTRAL', ''], true) ? '' : ' · ' . $f['equipo']);
            $filas .= '<tr><td><b>' . Ui::h($nombre) . '</b></td><td>' . Ui::h(mb_substr(mb_convert_case((string)$f['empresa'], MB_CASE_TITLE), 0, 28)) . '</td>'
                . '<td>' . Ui::h(self::fechaCorta($f['inicio']) . ' → ' . self::fechaCorta($f['final'])) . '</td><td><b>' . Formato::numero($f['mw']) . '</b></td>'
                . '<td>' . Ui::pildora($f['programado'] ? 'Programada' : 'Forzada', $f['programado'] ? 'neutro' : 'malo') . '</td>'
                . '<td>' . Ui::h($f['precio'] ? Ui::soles((float)$f['precio']) : 's/d') . '</td></tr>';
        }
        $html .= Ui::tarjeta('<div class="cab"><h2>Las salidas más grandes de la semana</h2></div><p class="nota" style="color:var(--c-gris);font-size:13px;margin:0 0 8px">'
            . 'El precio es el promedio en ' . Ui::h(ucwords(mb_strtolower($p['barra_referencia']))) . ' mientras duró la salida: muestra coincidencia, no causa.</p>'
            . '<div class="tabla-scroll"><table class="tabla"><thead><tr><th>Central / unidad</th><th>Empresa</th><th>Desde → hasta</th><th>MW</th><th>Tipo</th><th>Precio mientras</th></tr></thead><tbody>'
            . $filas . '</tbody></table></div>');
        if (!empty($ev['red'])) {
            $li = '';
            foreach ($ev['red'] as $f) {
                $li .= '<li style="justify-content:flex-start;align-items:flex-start"><b style="color:var(--c-gris);min-width:92px">' . Ui::h(self::fechaCorta($f['inicio'])) . '</b><span>'
                    . Ui::h(mb_convert_case((string)$f['ubicacion'], MB_CASE_TITLE) . ' · ' . $f['equipo'] . ': ' . mb_convert_case(mb_substr((string)$f['descripcion'], 0, 90), MB_CASE_LOWER)) . '</span></li>';
            }
            $html .= Ui::tarjeta('<div class="cab"><h2>Últimos eventos en la red</h2></div><ul class="lista-simple">' . $li . '</ul>');
        }
        return $html;
    }

    public static function centrales(array $c): string
    {
        $p = $c['p'];
        $cen = $p['centrales'] ?? ['hay' => false];
        $html = Ui::cabecera('Centrales y empresas', 'Generación por central · ' . Fechas::rangoCorto($p['semana']));
        if (empty($cen['hay'])) {
            return $html . '<p>No hay datos por central para esta semana.</p>';
        }
        $detalle = $cen['detalle'];
        $elegida = (string)($c['get']['central'] ?? '');
        if (!isset($detalle[$elegida])) {
            $elegida = (string)($cen['por_defecto'] ?? array_key_first($detalle));
        }
        $opciones = '';
        foreach (array_keys($detalle) as $nombre) {
            $opciones .= '<option value="' . Ui::h($nombre) . '"' . ($nombre === $elegida ? ' selected' : '') . '>' . Ui::h(mb_convert_case($nombre, MB_CASE_TITLE)) . '</option>';
        }
        $html .= '<form class="controles" method="get" action="index.php"><input type="hidden" name="s" value="centrales"><input type="hidden" name="semana" value="' . Ui::h($p['semana']) . '">'
            . '<label for="central">Central</label><select id="central" name="central" data-auto>' . $opciones . '</select><noscript><button class="boton">Ver</button></noscript></form>';
        $d = $detalle[$elegida] ?? null;
        if ($d) {
            $vs = '';
            if ($d['precio_capturado'] && $d['precio_promedio']) {
                $dif = $d['precio_capturado'] / $d['precio_promedio'] - 1;
                $vs = number_format(abs($dif) * 100, 0) . '% ' . ($dif > 0 ? 'sobre' : 'bajo') . ' el promedio';
            }
            $html .= Ui::cifras([
                ['etiqueta' => 'Energía de la semana', 'valor' => Formato::numero($d['energia_mwh']), 'unidad' => 'MWh', 'detalle' => mb_convert_case((string)$d['empresa'], MB_CASE_TITLE) . ' · ' . Config::nombreTecnologia((string)$d['tecnologia'])],
                ['etiqueta' => 'Potencia máxima', 'valor' => Formato::numero($d['mw_max']), 'unidad' => 'MW', 'detalle' => 'generó ' . Formato::numero($d['horas_generando']) . ' de 168 horas'],
                ['etiqueta' => 'Precio capturado', 'valor' => Ui::soles($d['precio_capturado']), 'unidad' => 'por MWh', 'cambio' => $vs,
                    'explicacion' => 'Precio promedio ponderado por lo que generó la central en cada media hora.'],
                ['etiqueta' => 'Ingreso spot estimado', 'valor' => 'S/ ' . Formato::numero(($d['ingreso_estimado'] ?? 0) / 1e6, 2) . ' M',
                    'cambio' => 'con precio de ' . mb_convert_case((string)$d['barra'], MB_CASE_TITLE) . ($d['aproximada'] ? ' (aproximado)' : ''), 'tono' => $d['aproximada'] ? 'neutro' : 'info',
                    'explicacion' => 'Generación por costo marginal, media hora a media hora. Es una estimación: no incluye potencia, contratos ni retiros; no es la valorización oficial del COES.'],
            ]);
            $html .= Ui::tarjetaGrafico(mb_convert_case($elegida, MB_CASE_TITLE) . ': generación de la semana', G::generacionCentral($d['ts'], $d['serie']), 'MW');
        }
        $emp = $cen['empresas'];
        $top = $cen['top'];
        $html .= '<div class="dos-col iguales">'
            . Ui::tarjetaGrafico('Participación por empresa', G::barrasHorizontales(array_column($emp, 'empresa'), array_map('floatval', array_column($emp, 'cuota')),
                array_map(static fn($e) => $e['cuota_anterior'] === null ? null : (float)$e['cuota_anterior'], $emp)), '% de la energía')
            . Ui::tarjetaGrafico('Centrales con más energía', G::barrasHorizontales(array_column($top, 'central'), array_map(static fn($v) => $v / 1000, array_column($top, 'energia_mwh')),
                null, static fn(float $v): string => number_format($v, 1) . ' GWh'), 'GWh') . '</div>';
        return $html;
    }
}
