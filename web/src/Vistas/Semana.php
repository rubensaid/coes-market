<?php
declare(strict_types=1);

namespace CuantoCoesta\Vistas;

use CuantoCoesta\Config;
use CuantoCoesta\Fechas;
use CuantoCoesta\Formato;
use CuantoCoesta\Ui;
use CuantoCoesta\Visual\Graficos as G;

/** Secciones de la semana: Resumen, Precios, Demanda, Generacion, Embalses y Mapa. $c = ['p' => paquete, 'semana' => ..., 'semanas' => [...]]. */
final class Semana
{
    private static function ind(array $p, string $clave): array
    {
        return $p['indicadores'][$clave] ?? ['valor' => null, 'unidad' => '', 'anterior' => null, 'anio_anterior' => null, 'momento' => null,
            'es_puntos' => false, 'var_anterior' => null, 'var_anio' => null, 'comparable' => false, 'niveles' => null];
    }

    private static function rangoTitulo(array $p): string
    {
        return Fechas::rangoCorto($p['semana']) . ($p['es_preliminar'] ? '  ·  preliminar' : '');
    }

    private static function momento(?string $m): string
    {
        return $m ? Fechas::diaYHora(Fechas::fecha($m)) : '';
    }

    private static function texto(string $t): string
    {
        return '<section class="tarjeta"><p style="margin:0">' . Ui::h($t) . '</p></section>';
    }

    /** Selector de semana y descarga del PDF (solo en Resumen). */
    private static function controles(array $c): string
    {
        $opciones = '';
        foreach ($c['semanas'] as $s) {
            $opciones .= '<option value="' . Ui::h($s['semana']) . '"' . ($s['semana'] === $c['semana'] ? ' selected' : '') . '>' . Ui::h(Fechas::rangoCorto($s['semana'])) . '</option>';
        }
        $pdf = \CuantoCoesta\Semanas::tienePdf($c['semana'])
            ? '<a class="boton sec" href="descargar.php?tipo=pdf&amp;semana=' . Ui::h($c['semana']) . '" title="Informe de la semana en PDF">Descargar PDF</a>'
            : '<span class="boton sec" aria-disabled="true" title="Esta semana no tiene PDF publicado">PDF no disponible</span>';
        return '<form class="controles" method="get" action="index.php"><label for="semana">Semana</label>'
            . '<select id="semana" name="semana" data-auto>' . $opciones . '</select><noscript><button class="boton">Ver</button></noscript>' . $pdf . '</form>';
    }

    private static function bannerPreliminar(array $p): string
    {
        return $p['es_preliminar']
            ? '<div class="aviso-prelim">⏳ Datos preliminares: ' . (int)$p['dias_con_datos'] . ' de 7 días con datos completos. Los números pueden cambiar cuando el COES confirme el resto.</div>'
            : '';
    }

    public static function resumen(array $c): string
    {
        $p = $c['p'];
        $n = $p['narrativa'];
        $html = Ui::cabecera('Resumen de la semana', self::rangoTitulo($p)) . self::controles($c) . self::bannerPreliminar($p);
        $primero = $p['hechos'][0] ?? null;
        if ($primero && $primero['tipo'] === 'variacion') {
            $icono = $primero['sentido'] === 'sube' ? '▲' : '▼';
        } else {
            $icono = $primero && $primero['tipo'] === 'record' ? '★' : '●';
        }
        $html .= '<div class="banda"><span class="icono" aria-hidden="true">' . $icono . '</span><p>' . Ui::h($n['titulares'][0] ?? $p['texto_semana']) . '</p></div>';

        $prom = self::ind($p, 'cmg_promedio');
        $dm = self::ind($p, 'demanda_maxima');
        $ren = self::ind($p, 'participacion_renovable');
        $emb = self::ind($p, 'volumen_embalses');
        [$tProm, $toProm] = Ui::cambio($prom, true);
        [$tDm, $toDm] = Ui::cambio($dm, false, false, true);
        [$tRen, $toRen] = Ui::cambio($ren, false, true);
        [$tEmb, $toEmb] = Ui::cambio($emb);
        $html .= Ui::cifras([
            ['etiqueta' => 'Precio promedio', 'valor' => Ui::soles($prom['valor']), 'unidad' => 'por MWh', 'cambio' => $tProm, 'tono' => $toProm, 'explicacion' => Ui::explicacion('cmg_promedio')],
            ['etiqueta' => 'Demanda máxima', 'valor' => Formato::numero($dm['valor']), 'unidad' => 'MW', 'cambio' => $tDm, 'tono' => $toDm, 'explicacion' => Ui::explicacion('demanda_maxima')],
            ['etiqueta' => 'Energía renovable', 'valor' => Formato::porcentaje($ren['valor']), 'cambio' => $tRen, 'tono' => $toRen, 'explicacion' => Ui::explicacion('participacion_renovable')],
            ['etiqueta' => 'Agua en embalses', 'valor' => $emb['valor'] ? Formato::numero($emb['valor']) : 's/d', 'unidad' => 'Hm³', 'cambio' => $tEmb, 'tono' => $toEmb, 'explicacion' => Ui::explicacion('volumen_embalses')],
        ]);

        $perfil = $p['series']['perfil'] ?? [];
        $actual = array_filter(array_column($perfil, 1), static fn($v) => $v !== null);
        $izq = '';
        if ($actual) {
            $imax = array_search(max($actual), array_column($perfil, 1), true);
            $hora = (int)$perfil[$imax][0];
            $nota = 'Lo más caro: ' . Fechas::horaTexto($hora) . ', ' . Ui::soles((float)$perfil[$imax][1]);
            $izq = Ui::tarjetaGrafico('Precio por hora del día', G::perfilHorario($perfil), $nota);
        }
        $html .= '<div class="dos-col">' . $izq . self::tarjetaMezcla($p) . '</div>';

        $iconos = ['variacion' => ['↕', 'info'], 'record' => ['★', 'alerta'], 'rareza' => ['!', 'alerta'], 'anomalia' => ['!', 'alerta'], 'dato' => ['•', 'neutro']];
        $items = '';
        foreach ($p['hechos'] as $h) {
            [$sim, $tono] = $iconos[$h['tipo']] ?? ['•', 'neutro'];
            $items .= '<div><div class="destacado"><span class="sim ' . $tono . '" aria-hidden="true">' . $sim . '</span><span>' . Ui::h($h['titular']) . '</span></div>'
                . '<details class="que"><summary>Por qué lo marcamos' . ($h['categoria'] ? ' (' . Ui::h(mb_strtolower($h['categoria'])) . ')' : '') . '</summary><p>' . Ui::h($h['explicacion']) . '</p></details></div>';
        }
        $html .= Ui::tarjeta('<div class="cab"><h2>Lo destacado</h2></div><div class="destacados">' . $items . '</div>');
        return $html;
    }

    private static function tarjetaMezcla(array $p): string
    {
        $mix = $p['mix'] ?? [];
        arsort($mix);
        $tecs = array_keys(array_filter($mix, static fn($v) => $v >= 0.005));
        $barra = $lista = '';
        foreach ($tecs as $t) {
            $color = Config::colorTecnologia($t);
            $barra .= '<span style="width:' . number_format($mix[$t] * 100, 2, '.', '') . '%;background:' . $color . '" title="' . Ui::h(Config::nombreTecnologia($t)) . '"></span>';
            $lista .= '<li><span class="chip" style="background:' . $color . '"></span><span>' . Ui::h(Config::nombreTecnologia($t)) . '</span><span class="pct">' . Formato::porcentaje($mix[$t]) . '</span></li>';
        }
        $sin = self::ind($p, 'participacion_renovable_sin_hidro');
        return Ui::tarjeta('<div class="cab"><h2>De dónde vino la energía</h2></div><div class="mezcla" role="img" aria-label="Participación de cada fuente">' . $barra
            . '</div><ul class="lista-mezcla">' . $lista . '</ul><p class="nota" style="margin:12px 0 0;color:var(--c-gris);font-size:13px">Renovable sin hidro: '
            . Formato::porcentaje($sin['valor']) . '</p>');
    }

    public static function precios(array $c): string
    {
        $p = $c['p'];
        $n = $p['narrativa'];
        $html = Ui::cabecera('Precios', $n['titulos_graficos']['precios_serie'] ?? Fechas::rangoCorto($p['semana'])) . self::bannerPreliminar($p) . self::texto($n['parrafos']['precios'] ?? '');
        $prom = self::ind($p, 'cmg_promedio');
        $mx = self::ind($p, 'cmg_maximo');
        $mn = self::ind($p, 'cmg_minimo');
        $alto = self::ind($p, 'horas_precio_alto');
        $bajo = self::ind($p, 'horas_precio_bajo');
        [$tProm, $toProm] = Ui::cambio($prom, true);
        [$tAlto, $toAlto] = Ui::cambio($alto, true);
        [$tBajo, $toBajo] = Ui::cambio($bajo);
        $html .= Ui::cifras([
            ['etiqueta' => 'Promedio', 'valor' => Ui::soles($prom['valor']), 'unidad' => 'por MWh', 'cambio' => $tProm, 'tono' => $toProm, 'explicacion' => Ui::explicacion('cmg_promedio')],
            ['etiqueta' => 'Más alto', 'valor' => Ui::soles($mx['valor']), 'detalle' => self::momento($mx['momento']), 'explicacion' => Ui::explicacion('cmg_maximo')],
            ['etiqueta' => 'Más bajo', 'valor' => Ui::soles($mn['valor']), 'detalle' => self::momento($mn['momento']), 'explicacion' => Ui::explicacion('cmg_minimo')],
            ['etiqueta' => 'Horas caras', 'valor' => Formato::numero($alto['valor'], 1), 'unidad' => 'h', 'cambio' => $tAlto, 'tono' => $toAlto, 'explicacion' => Ui::explicacion('horas_precio_alto')],
            ['etiqueta' => 'Horas baratas', 'valor' => Formato::numero($bajo['valor'], 1), 'unidad' => 'h', 'cambio' => $tBajo, 'tono' => $toBajo, 'explicacion' => Ui::explicacion('horas_precio_bajo')],
        ]);
        if (!empty($p['series']['cmg']['ts'])) {
            $html .= Ui::tarjetaGrafico('Precio de la semana', G::precioSemana($p['series']['cmg'], $p['umbrales_precio']), 'S/ por MWh · ' . ucwords(mb_strtolower($p['barra_referencia'])) . ' kV',
                Ui::queSignifica($p, 'cmg_promedio') . Ui::queSignifica($p, 'horas_precio_alto'));
        }
        $lado = Ui::cifra(['etiqueta' => 'Precios inusuales', 'valor' => (string)(int)$p['rarezas']['n'], 'unidad' => 'medias horas',
            'cambio' => $p['rarezas']['n'] ? 'fuera de lo normal' : 'todo dentro de lo normal', 'tono' => $p['rarezas']['n'] ? 'alerta' : 'bueno', 'explicacion' => Ui::explicacion('rarezas')]);
        foreach (array_slice($p['rarezas']['top'] ?? [], 0, 3) as $r) {
            $lado .= '<section class="tarjeta"><b>' . Ui::h(Fechas::diaYHora(Fechas::fecha($r['fecha_hora']))) . '</b><p style="margin:6px 0 0;font-size:13.5px">' . Ui::h($r['regla']) . '</p></section>';
        }
        if (!empty($p['revision'])) {
            $lado .= Ui::cifra(['etiqueta' => 'Cambio al confirmarse', 'valor' => Formato::porcentaje((float)$p['revision']['diferencia_media_pct']),
                'cambio' => 'preliminar vs confirmado', 'tono' => 'info', 'explicacion' => Ui::explicacion('revision_cmg')]);
        }
        $html .= '<div class="dos-col">' . Ui::tarjetaGrafico($n['titulos_graficos']['precios_perfil'] ?? 'Por hora del día', G::perfilHorario($p['series']['perfil'] ?? []), '', Ui::queSignifica($p, 'perfil_horario'))
            . '<div class="apilado">' . $lado . '</div></div>';
        return $html;
    }

    public static function demanda(array $c): string
    {
        $p = $c['p'];
        $n = $p['narrativa'];
        $html = Ui::cabecera('Demanda', $n['titulos_graficos']['demanda'] ?? Fechas::rangoCorto($p['semana'])) . self::bannerPreliminar($p) . self::texto($n['parrafos']['demanda'] ?? '');
        $dm = self::ind($p, 'demanda_maxima');
        $ene = self::ind($p, 'energia_semana');
        $err = self::ind($p, 'error_programa_diario');
        [$tDm, $toDm] = Ui::cambio($dm, false, false, true);
        [$tEne, $toEne] = Ui::cambio($ene, false, false, true);
        $html .= Ui::cifras([
            ['etiqueta' => 'Demanda máxima', 'valor' => Formato::numero($dm['valor']), 'unidad' => 'MW', 'cambio' => $tDm, 'tono' => $toDm, 'explicacion' => Ui::explicacion('demanda_maxima')],
            ['etiqueta' => 'Energía de la semana', 'valor' => Formato::numero($ene['valor']), 'unidad' => 'GWh', 'cambio' => $tEne, 'tono' => $toEne, 'explicacion' => Ui::explicacion('energia_semana')],
            ['etiqueta' => 'Diferencia con lo previsto', 'valor' => Formato::porcentaje($err['valor']), 'cambio' => 'pronóstico del COES', 'tono' => 'neutro', 'explicacion' => Ui::explicacion('error_programa_diario')],
        ]);
        if (!empty($p['series']['demanda']['ts'])) {
            $html .= Ui::tarjetaGrafico('Consumo real vs previsto', G::demanda($p['series']['demanda']), 'MW',
                Ui::queSignifica($p, 'demanda_maxima') . Ui::queSignifica($p, 'energia_semana') . Ui::queSignifica($p, 'error_programa_diario'));
        }
        return $html;
    }

    public static function generacion(array $c): string
    {
        $p = $c['p'];
        $n = $p['narrativa'];
        $html = Ui::cabecera('Generación', $n['titulos_graficos']['generacion'] ?? Fechas::rangoCorto($p['semana'])) . self::bannerPreliminar($p) . self::texto($n['parrafos']['generacion'] ?? '');
        $ren = self::ind($p, 'participacion_renovable');
        $sin = self::ind($p, 'participacion_renovable_sin_hidro');
        $sol = self::ind($p, 'precio_capturado_solar');
        $eol = self::ind($p, 'precio_capturado_eolica');
        $prom = self::ind($p, 'cmg_promedio');
        [$tRen, $toRen] = Ui::cambio($ren, false, true);
        [$tSin, $toSin] = Ui::cambio($sin, false, true);
        $vs = static function (array $i) use ($prom): string {
            if (!$i['valor'] || !$prom['valor']) {
                return '';
            }
            $dif = $i['valor'] / $prom['valor'] - 1;
            return number_format(abs($dif) * 100, 0) . '% ' . ($dif < 0 ? 'bajo' : 'sobre') . ' el promedio';
        };
        $html .= Ui::cifras([
            ['etiqueta' => 'Renovable', 'valor' => Formato::porcentaje($ren['valor']), 'cambio' => $tRen, 'tono' => $toRen, 'explicacion' => Ui::explicacion('participacion_renovable')],
            ['etiqueta' => 'Renovable sin hidro', 'valor' => Formato::porcentaje($sin['valor']), 'cambio' => $tSin, 'tono' => $toSin, 'explicacion' => Ui::explicacion('participacion_renovable_sin_hidro')],
            ['etiqueta' => 'Precio de la solar', 'valor' => Ui::soles($sol['valor']), 'unidad' => 'por MWh', 'cambio' => $vs($sol), 'explicacion' => Ui::explicacion('precio_capturado_solar')],
            ['etiqueta' => 'Precio de la eólica', 'valor' => Ui::soles($eol['valor']), 'unidad' => 'por MWh', 'cambio' => $vs($eol), 'explicacion' => Ui::explicacion('precio_capturado_eolica')],
        ]);
        if (!empty($p['mix'])) {
            $html .= Ui::tarjetaGrafico('Generación por fuente', G::generacion($p['series']['generacion'] ?? []), 'MW', Ui::queSignifica($p, 'mix_generacion') . Ui::queSignifica($p, 'participacion_renovable'));
            $html .= Ui::tarjetaGrafico('Participación de cada fuente', G::mix($p['mix'], $p['mix_anterior'] ?? []), 'la marca oscura es la semana anterior',
                Ui::queSignifica($p, 'participacion_renovable_sin_hidro') . Ui::queSignifica($p, 'precio_capturado_solar'));
        }
        return $html;
    }

    public static function embalses(array $c): string
    {
        $p = $c['p'];
        $n = $p['narrativa'];
        $html = Ui::cabecera('Embalses', $n['titulos_graficos']['embalses'] ?? Fechas::rangoCorto($p['semana'])) . self::bannerPreliminar($p) . self::texto($n['parrafos']['hidrologia'] ?? '');
        $emb = self::ind($p, 'volumen_embalses');
        [$tEmb, $toEmb] = Ui::cambio($emb);
        $tAnio = $emb['var_anio'] === null ? 'sin comparación' : ($emb['var_anio'] > 0 ? '▲' : '▼') . ' ' . number_format(abs((float)$emb['var_anio']) * 100, 1) . '%';
        $anioPrev = (int)substr($p['desde'], 0, 4) - 1;
        $html .= Ui::cifras([
            ['etiqueta' => 'Agua guardada', 'valor' => $emb['valor'] ? Formato::numero($emb['valor'], 1) : 's/d', 'unidad' => 'Hm³', 'cambio' => $tEmb, 'tono' => $toEmb, 'explicacion' => Ui::explicacion('volumen_embalses')],
            ['etiqueta' => 'Frente a hace un año', 'valor' => $tAnio, 'cambio' => "misma semana de $anioPrev", 'tono' => 'neutro', 'explicacion' => Ui::explicacion('volumen_embalses')],
        ]);
        if (!empty($p['series']['embalses']['fecha'])) {
            $html .= '<div class="dos-col iguales">' . Ui::tarjetaGrafico('Día a día', G::embalses($p['series']['embalses']), 'Hm³', Ui::queSignifica($p, 'volumen_embalses'))
                . Ui::tarjetaGrafico('Los que más agua tienen', G::embalsesDetalle($p['series']['embalses_detalle'] ?? []), 'marca oscura: semana anterior') . '</div>';
            $html .= '<p class="nota" style="color:var(--c-gris);font-size:13px">Se muestran los embalses que reportan volumen útil en el IEOD del COES. Algunos solo reportan nivel (metros sobre el nivel del mar) y no entran en la suma.</p>';
        }
        return $html;
    }

    public static function mapa(array $c): string
    {
        $p = $c['p'];
        $html = Ui::cabecera('Mapa', $p['narrativa']['titulos_graficos']['mapa'] ?? 'Precio por zona');
        $barras = array_values(array_filter($p['mapa'] ?? [], static fn($b) => $b['latitud'] !== null && $b['cmg_promedio'] !== null));
        if (!$barras) {
            return $html . '<p>No hay datos por zona esta semana.</p>';
        }
        usort($barras, static fn($a, $b) => $a['cmg_promedio'] <=> $b['cmg_promedio']);
        $lista = static function (array $filas, string $tono): string {
            $h = '<ul class="lista-simple">';
            foreach ($filas as $f) {
                $h .= '<li><span>' . Ui::h(mb_convert_case($f['barra'], MB_CASE_TITLE)) . '</span>' . Ui::pildora(Ui::soles((float)$f['cmg_promedio']), $tono) . '</li>';
            }
            return $h . '</ul>';
        };
        $caras = array_reverse(array_slice($barras, -5));
        $html .= '<div class="dos-col tres-dos">' . Ui::tarjetaGrafico('Precio promedio por zona', G::mapa($p['mapa']), 'pasa el mouse por un punto')
            . '<div class="apilado">' . Ui::tarjeta('<div class="cab"><h2>Más caras</h2></div>' . $lista($caras, 'malo'))
            . Ui::tarjeta('<div class="cab"><h2>Más baratas</h2></div>' . $lista(array_slice($barras, 0, 5), 'bueno')) . '</div></div>';
        return $html . Ui::tarjeta(Ui::queSignifica($p, 'mapa_cmg') ?: '');
    }
}
