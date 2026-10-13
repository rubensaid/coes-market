<?php
declare(strict_types=1);

namespace CuantoCoesta;

use CuantoCoesta\Ahora\Memoria;

/** Piezas de interfaz reutilizables (tarjetas, cifras, pildoras) y la plantilla general de la pagina. */
final class Ui
{
    public const SECCIONES = [
        'resumen' => ['Resumen', '▦'], 'ahora' => ['Ahora', '◷'], 'evolucion' => ['Evolución', '≋'], 'precios' => ['Precios', '↗'],
        'demanda' => ['Demanda', '∿'], 'generacion' => ['Generación', '☀'], 'embalses' => ['Embalses', '◍'], 'eventos' => ['Eventos', '⚠'],
        'centrales' => ['Centrales', '⌁'], 'mapa' => ['Mapa', '⌖'], 'fuentes' => ['Fuentes', '≡'], 'glosario' => ['Glosario', '?'],
    ];

    public static function h(mixed $v): string
    {
        return Formato::h($v);
    }

    /** Una frase limpia: espacios colapsados. */
    public static function limpio(string $texto): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', $texto));
    }

    public static function soles(?float $v): string
    {
        return $v === null ? 's/d' : 'S/ ' . Formato::numero($v, 1);
    }

    public static function pildora(string $texto, string $tono = 'neutro'): string
    {
        return '<span class="pildora ' . self::h($tono) . '">' . self::h($texto) . '</span>';
    }

    public static function info(string $texto): string
    {
        return $texto === '' ? '' : '<button type="button" class="btn-info" aria-label="Qué significa" data-tip="' . self::h($texto) . '">i</button>';
    }

    /** Explicacion corta del glosario para el boton "i". */
    public static function explicacion(string $clave): string
    {
        $g = Config::glosario();
        $e = $g['indicadores'][$clave] ?? $g['terminos'][$clave] ?? null;
        return $e ? self::limpio($e['explicacion_simple']) : '';
    }

    /** Variacion contra la semana anterior: [texto, tono]. */
    public static function cambio(?array $ind, bool $invertir = false, bool $puntos = false, bool $neutral = false): array
    {
        if (!$ind || $ind['var_anterior'] === null) {
            return ['sin comparación', 'neutro'];
        }
        $v = (float)$ind['var_anterior'];
        $cifra = number_format(abs($v) * 100, 1) . ($puntos ? ' pp' : '%');
        $texto = ($v > 0 ? '▲' : '▼') . " $cifra vs semana pasada";
        if ($neutral || abs($v) < 0.001) {
            return [$texto, 'neutro'];
        }
        $bueno = $invertir ? $v < 0 : $v > 0;
        return [$texto, $bueno ? 'bueno' : 'malo'];
    }

    /** Tarjeta con una cifra grande. Claves: etiqueta, valor, unidad, cambio, tono, detalle, explicacion, tam (20, 24 o vacio). */
    public static function cifra(array $c): string
    {
        $valor = '<div class="valor ' . (isset($c['tam']) ? 't' . (int)$c['tam'] : '') . '">' . self::h($c['valor'] ?? 's/d')
            . (!empty($c['unidad']) ? '<span class="unidad">' . self::h($c['unidad']) . '</span>' : '') . '</div>';
        return '<div class="tarjeta cifra"><div class="cab-c"><span class="etiqueta">' . self::h($c['etiqueta']) . '</span>' . self::info($c['explicacion'] ?? '') . '</div>'
            . $valor . (!empty($c['cambio']) ? self::pildora($c['cambio'], $c['tono'] ?? 'neutro') : '')
            . (!empty($c['detalle']) ? '<div class="detalle">' . self::h($c['detalle']) . '</div>' : '') . '</div>';
    }

    public static function cifras(array $celdas): string
    {
        return '<div class="cifras">' . implode('', array_map([self::class, 'cifra'], $celdas)) . '</div>';
    }

    public static function tarjeta(string $contenido, string $clase = ''): string
    {
        return '<section class="tarjeta ' . $clase . '">' . $contenido . '</section>';
    }

    /** Tarjeta con titulo (que dice la conclusion) y un grafico SVG. */
    public static function tarjetaGrafico(string $titulo, string $svg, string $nota = '', string $extra = ''): string
    {
        return self::tarjeta('<div class="cab"><h2>' . self::h($titulo) . '</h2>' . ($nota !== '' ? '<span class="nota">' . self::h($nota) . '</span>' : '') . '</div>' . $svg . $extra);
    }

    public static function cabecera(string $titulo, string $subtitulo): string
    {
        return '<header class="cabecera"><h1>' . self::h($titulo) . '</h1><p>' . self::h($subtitulo) . '</p></header>';
    }

    /** Desplegable "Que significa esto" con varios niveles (lectura rapida, por que importa, para imaginarlo, nota tecnica). */
    public static function queSignifica(?array $paquete, string $clave): string
    {
        $g = Config::glosario()['indicadores'][$clave] ?? null;
        if (!$g) {
            return '';
        }
        $niveles = $paquete['indicadores'][$clave]['niveles'] ?? null;
        $html = '';
        if ($niveles && $niveles['rapida']) {
            $html .= '<p><b>' . self::h($niveles['rapida']) . '</b> ' . self::h($niveles['intermedia']) . '</p>';
        }
        $html .= '<p>' . self::h(self::limpio($g['explicacion_simple'])) . '</p>';
        if (!empty($g['por_que_importa'])) {
            $html .= '<p><b>Por qué importa:</b> ' . self::h(self::limpio($g['por_que_importa'])) . '</p>';
        }
        if (!empty($g['ejemplo_cotidiano'])) {
            $html .= '<p><b>Para imaginarlo:</b> ' . self::h(self::limpio($g['ejemplo_cotidiano'])) . '</p>';
        }
        if (!empty($g['nota_tecnica'])) {
            $html .= '<p><b>Nota técnica:</b> ' . self::h(self::limpio($g['nota_tecnica'])) . '</p>';
        }
        return '<details class="que"><summary>¿Qué significa «' . self::h($g['nombre']) . '»?</summary>' . $html . '</details>';
    }

    /** URL de una seccion conservando la semana elegida. */
    public static function url(string $seccion, ?string $semana = null, array $extra = []): string
    {
        $q = array_filter(['s' => $seccion === 'resumen' ? null : $seccion, 'semana' => $semana] + $extra, static fn($v) => $v !== null && $v !== '');
        return 'index.php' . ($q ? '?' . http_build_query($q) : '');
    }

    /** Hora de un fin de intervalo, como "11:00 a. m.". */
    public static function horaCorta(string $fh): string
    {
        return Fechas::horaCorta(Fechas::fecha($fh));
    }

    /** CSS con la paleta del proyecto como variables (una paleta, un solo lugar). */
    private static function variablesPaleta(): string
    {
        $css = ':root{';
        foreach (Config::proyecto()['paleta'] as $k => $v) {
            $css .= '--c-' . preg_replace('/[^a-z_]/', '', $k) . ':' . $v . ';';
        }
        return $css . '}';
    }

    /** Sello de frescura del menu: hora del ultimo dato y su antiguedad. @return array{string, bool} [texto, viejo] */
    public static function sello(?array $ahora): array
    {
        if (!$ahora || ($ahora['sin_datos'] ?? true)) {
            return ['Sin datos en vivo', true];
        }
        $min = (float)$ahora['antiguedad_min'];
        $cuando = $min < 120 ? 'hace ' . (int)round($min) . ' min' : 'hace ' . (int)round($min / 60) . ' h';
        return ['● Último dato ' . self::horaCorta($ahora['momento']) . ' · ' . $cuando . ' · preliminar', $min > 60];
    }

    /** Plantilla general: menu lateral, contenido y pie con el aviso de no afiliacion. */
    public static function pagina(string $titulo, string $contenido, string $seccion, ?string $semana, ?array $ahora = null, string $extraHead = ''): string
    {
        $p = Config::proyecto();
        $palabras = explode(' ', $p['nombre'], 2);
        $logo = '<a class="logo" href="index.php"><span class="rayo" aria-hidden="true">⚡</span><span>' . self::h($palabras[0])
            . (isset($palabras[1]) ? ' <span class="dos">' . self::h($palabras[1]) . '</span>' : '') . '</span></a>';
        $nav = '';
        foreach (self::SECCIONES as $clave => [$nombre, $icono]) {
            $nav .= '<a href="' . self::h(self::url($clave, in_array($clave, ['ahora', 'evolucion', 'fuentes', 'glosario'], true) ? null : $semana)) . '"'
                . ($clave === $seccion ? ' aria-current="page"' : '') . '><span class="ico" aria-hidden="true">' . $icono . '</span>' . self::h($nombre) . '</a>';
        }
        [$sello, $viejo] = self::sello($ahora);
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/';
        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . self::h($titulo . ' · ' . $p['nombre']) . '</title><meta name="description" content="' . self::h($p['tagline']) . '">'
            . '<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 64 64%27%3E%3Crect width=%2764%27 height=%2764%27 rx=%2714%27 fill=%27%23F28C1E%27/%3E%3Cpath d=%27M36 8 16 36h14l-4 20 22-30H34z%27 fill=%27%23fff%27/%3E%3C/svg%3E">'
            . '<link rel="stylesheet" href="assets/estilo.css"><style>' . self::variablesPaleta() . '</style>' . $extraHead . '</head>'
            . '<body data-base="' . self::h($base) . '"><input type="checkbox" id="abrir-menu" hidden>'
            . '<div class="barra-movil"><span class="nombre">⚡ ' . self::h($p['nombre']) . '</span><label for="abrir-menu" aria-label="Abrir menú">☰</label></div>'
            . '<div class="app"><aside class="menu">' . $logo . '<nav aria-label="Secciones">' . $nav . '</nav>'
            . '<div class="vivo"><p class="sello' . ($viejo ? ' viejo' : '') . '">' . self::h($sello) . '</p>'
            . '<button type="button" class="boton menu-b" data-actualizar-ahora title="Trae el último precio y la demanda del COES">↻ Actualizar ahora</button>'
            . '<p class="pie-menu">Datos públicos del COES. Proyecto independiente · v' . self::h($p['version'] ?? '') . '<br><a href="'
            . self::h(self::url('proyecto')) . '">Sobre el proyecto</a></p></div></aside>'
            . '<main class="principal"><div class="contenido">' . $contenido . '</div>'
            . '<footer class="barra-estado"><span>' . self::h(Config::aviso()) . '</span><span>No afiliado al COES</span></footer></main></div>'
            . '<script src="assets/app.js" defer></script></body></html>';
    }
}
