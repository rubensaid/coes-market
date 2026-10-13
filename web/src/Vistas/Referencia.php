<?php
declare(strict_types=1);

namespace CuantoCoesta\Vistas;

use CuantoCoesta\Ahora\Repositorio;
use CuantoCoesta\Config;
use CuantoCoesta\Fechas;
use CuantoCoesta\Ui;

/** Fuentes, Glosario y Sobre el proyecto. */
final class Referencia
{
    public static function fuentes(array $c): string
    {
        $p = $c['p'] ?? null;
        $html = Ui::cabecera('Fuentes', 'De dónde salen los datos y cuándo se actualizaron');
        $vivo = Repositorio::estadoFuentes();
        $filas = '';
        foreach ($p['fuentes'] ?? [] as $f) {
            $ok = str_starts_with($f['clave'], 'ahora_') ? ($vivo[$f['clave']] ?? null) : $f['ultima_extraccion'];
            $ultima = $ok ? substr(str_replace('T', ' ', $ok), 0, 16) : 'pendiente';
            $filas .= '<tr><td><b>' . Ui::h($f['nombre']) . '</b><br><span style="color:var(--c-gris);font-size:12.5px">' . Ui::h($f['descripcion']) . '</span></td><td>' . Ui::h($f['frecuencia']) . '</td>'
                . '<td>' . Ui::pildora($f['estado_dato'], $f['estado_dato'] === 'confirmado' ? 'info' : 'neutro') . '</td><td style="color:' . ($ok ? 'inherit' : 'var(--c-gris)') . '">' . Ui::h($ultima) . '</td>'
                . '<td>' . ($f['url_fuente'] ? '<a href="' . Ui::h($f['url_fuente']) . '" rel="noopener" target="_blank">Verificar</a>' : '') . '</td></tr>';
        }
        $html .= Ui::tarjeta('<div class="tabla-scroll"><table class="tabla"><thead><tr><th>Dato</th><th>Frecuencia</th><th>Estado</th><th>Última actualización</th><th>Fuente pública</th></tr></thead><tbody>'
            . $filas . '</tbody></table></div>');
        $avisos = $p['avisos'] ?? [];
        $lista = $avisos ? '<ul>' . implode('', array_map(static fn($a) => '<li>' . Ui::h($a) . '</li>', $avisos)) . '</ul>' : '';
        $html .= '<div class="dos-col iguales">' . Ui::cifra(['etiqueta' => 'Avisos de calidad esta semana', 'valor' => (string)count($avisos),
            'cambio' => $avisos ? 'huecos o datos raros del COES' : 'sin problemas', 'tono' => $avisos ? 'alerta' : 'bueno'])
            . '<div class="caja-celeste">' . Ui::h(Config::aviso()) . '</div></div>';
        if ($lista) {
            $html .= Ui::tarjeta('<details class="que" open><summary>Detalle de los avisos (' . count($avisos) . ')</summary>' . $lista . '</details>');
        }
        return $html;
    }

    public static function glosario(): string
    {
        $g = Config::glosario();
        $entradas = array_merge(array_values($g['indicadores']), array_values($g['terminos']));
        usort($entradas, static fn($a, $b) => strcmp(self::sinTildes($a['nombre']), self::sinTildes($b['nombre'])));
        $html = Ui::cabecera('Glosario', 'Todo lo que ves en el sitio, explicado en simple');
        $dl = '';
        foreach ($entradas as $e) {
            $dl .= '<details class="que"><summary>' . Ui::h($e['nombre']) . '</summary><p>' . Ui::h(Ui::limpio($e['explicacion_simple'])) . '</p>'
                . (!empty($e['por_que_importa']) ? '<p><b>Por qué importa:</b> ' . Ui::h(Ui::limpio($e['por_que_importa'])) . '</p>' : '')
                . (!empty($e['ejemplo_cotidiano']) ? '<p><b>Para imaginarlo:</b> ' . Ui::h(Ui::limpio($e['ejemplo_cotidiano'])) . '</p>' : '')
                . (!empty($e['unidad']) ? '<p style="color:var(--c-gris);font-size:13px">Unidad: ' . Ui::h($e['unidad']) . '</p>' : '') . '</details>';
        }
        return $html . Ui::tarjeta($dl);
    }

    private static function sinTildes(string $t): string
    {
        return strtolower(strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']));
    }

    public static function proyecto(array $c): string
    {
        $p = $c['p'] ?? null;
        $nombre = Config::proyecto()['nombre'];
        $barra = $p ? ucwords(mb_strtolower($p['barra_referencia'])) : ucwords(mb_strtolower(Config::ahora()['barra_referencia']));
        $umbral = $p['umbrales_precio'] ?? Config::analisis()['umbrales_precio'];
        $html = Ui::cabecera('Sobre el proyecto', Config::proyecto()['tagline']);
        $html .= Ui::tarjeta('<p><b>' . Ui::h($nombre) . '</b> es un proyecto open source que descarga cada semana los datos públicos del COES, los ordena y los explica en lenguaje simple.</p>'
            . '<h3>De dónde salen los datos</h3><ul><li>Demanda y generación por tecnología: portal de información del COES.</li>'
            . '<li>Costo marginal: archivo diario del IEOD (Informe de Evaluación de la Operación Diaria). Si un día aún no se publica, se usa el costo marginal en tiempo real y se marca como preliminar.</li>'
            . '<li>Embalses: anexo del IEOD.</li></ul>'
            . '<h3>Cómo se calcula</h3><ul><li>El precio se sigue en la barra <b>' . Ui::h($barra) . ' kV</b> de Lima.</li>'
            . '<li>Precio alto: más de S/ ' . Ui::h($umbral['alto']) . ' por MWh. Precio bajo: menos de S/ ' . Ui::h($umbral['bajo']) . ' por MWh.</li>'
            . '<li>La variación de los embalses compara solo los embalses con dato en ambas semanas.</li>'
            . '<li>Los textos se arman con reglas fijas, sin inteligencia artificial.</li></ul>'
            . '<p><b>Aviso.</b> ' . Ui::h(Config::aviso()) . '</p>');
        if ($p) {
            $act = $p['generado_en'] ?? null;
            $html .= '<p class="nota" style="color:var(--c-gris);font-size:13px">Fuente: COES, www.coes.org.pe. Datos de esta semana actualizados el '
                . Ui::h($act ? Fechas::diaYHora(Fechas::fecha($act)) : 's/d') . ' (hora de Perú).</p>';
        }
        return $html;
    }
}
