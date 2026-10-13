<?php
declare(strict_types=1);

namespace CuantoCoesta\Visual;

use CuantoCoesta\Formato;

/** Lienzo SVG con escalas lineales, ejes y datos para el hover. Sin GD ni librerias externas. */
final class Lienzo
{
    /** @var string[] */
    private array $partes = [];
    /** @var array<int, array{float, float, string}> */
    private array $hover = [];
    private string $modo = 'x';
    private array $extra = [];
    private array $d = ['x0' => 0.0, 'x1' => 1.0, 'y0' => 0.0, 'y1' => 1.0];

    public function __construct(
        public readonly float $w = 720,
        public readonly float $h = 280,
        public readonly float $t = 30,
        public readonly float $r = 14,
        public readonly float $b = 28,
        public readonly float $l = 54,
    ) {
    }

    public function dominio(float $x0, float $x1, float $y0, float $y1): self
    {
        $this->d = ['x0' => $x0, 'x1' => $x1 > $x0 ? $x1 : $x0 + 1, 'y0' => $y0, 'y1' => $y1 > $y0 ? $y1 : $y0 + 1];
        return $this;
    }

    public function x(float $v): float
    {
        return $this->l + ($v - $this->d['x0']) / ($this->d['x1'] - $this->d['x0']) * ($this->w - $this->l - $this->r);
    }

    public function y(float $v): float
    {
        return $this->h - $this->b - ($v - $this->d['y0']) / ($this->d['y1'] - $this->d['y0']) * ($this->h - $this->t - $this->b);
    }

    public function areaX0(): float
    {
        return $this->l;
    }

    public function areaX1(): float
    {
        return $this->w - $this->r;
    }

    public function areaY0(): float
    {
        return $this->t;
    }

    public function areaY1(): float
    {
        return $this->h - $this->b;
    }

    public function agregar(string $svg): self
    {
        $this->partes[] = $svg;
        return $this;
    }

    public static function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    /** Marcas "redondas" para un eje: 1, 2, 2.5, 5 x 10^k. @return float[] */
    public static function marcas(float $min, float $max, int $n = 5): array
    {
        if ($max <= $min) {
            return [$min];
        }
        $bruto = ($max - $min) / max($n, 1);
        $pot = 10 ** floor(log10($bruto));
        $paso = $pot;
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            $paso = $m * $pot;
            if ($paso >= $bruto) {
                break;
            }
        }
        $marcas = [];
        for ($v = ceil($min / $paso - 1e-9) * $paso; $v <= $max + 1e-9; $v += $paso) {
            $marcas[] = round($v, 10);
        }
        return $marcas;
    }

    /** Rejilla horizontal y etiquetas del eje Y. */
    public function ejeY(?callable $formato = null, int $n = 5): self
    {
        $formato ??= static fn(float $v): string => number_format($v, 0, '.', ',');
        foreach (self::marcas($this->d['y0'], $this->d['y1'], $n) as $v) {
            $y = $this->y($v);
            $this->partes[] = '<line class="rejilla" x1="' . self::n($this->l) . '" x2="' . self::n($this->areaX1()) . '" y1="' . self::n($y) . '" y2="' . self::n($y) . '"/>';
            $this->partes[] = '<text class="eje" x="' . self::n($this->l - 8) . '" y="' . self::n($y + 3.5) . '" text-anchor="end">' . Formato::h($formato($v)) . '</text>';
        }
        return $this;
    }

    /** Rejilla vertical y etiquetas del eje X numerico. */
    public function ejeXNumerico(array $marcas, ?callable $etiqueta = null, bool $rejilla = false): self
    {
        foreach ($marcas as $v) {
            $x = $this->x((float)$v);
            if ($rejilla) {
                $this->partes[] = '<line class="rejilla" x1="' . self::n($x) . '" x2="' . self::n($x) . '" y1="' . self::n($this->areaY0()) . '" y2="' . self::n($this->areaY1()) . '"/>';
            }
            $this->partes[] = '<text class="eje" x="' . self::n($x) . '" y="' . self::n($this->h - $this->b + 16) . '" text-anchor="middle">'
                . Formato::h($etiqueta ? $etiqueta($v) : (string)$v) . '</text>';
        }
        return $this;
    }

    /** Linea base del eje X. */
    public function lineaBase(): self
    {
        $this->partes[] = '<line class="base" x1="' . self::n($this->l) . '" x2="' . self::n($this->areaX1()) . '" y1="' . self::n($this->areaY1()) . '" y2="' . self::n($this->areaY1()) . '"/>';
        return $this;
    }

    /**
     * Camino de una serie; los nulos cortan la linea.
     * @param array<int, array{float, ?float}> $puntos [x, y] en unidades de datos
     */
    public function camino(array $puntos): string
    {
        $d = '';
        $abierto = false;
        foreach ($puntos as [$x, $y]) {
            if ($y === null) {
                $abierto = false;
                continue;
            }
            $d .= ($abierto ? 'L' : 'M') . self::n($this->x($x)) . ' ' . self::n($this->y($y));
            $abierto = true;
        }
        return $d;
    }

    public function linea(array $puntos, string $color, float $grosor = 1.8, ?string $guion = null, string $clase = ''): self
    {
        $this->partes[] = '<path class="' . $clase . '" d="' . $this->camino($puntos) . '" fill="none" stroke="' . $color . '" stroke-width="' . $grosor
            . '" stroke-linejoin="round" stroke-linecap="round"' . ($guion ? ' stroke-dasharray="' . $guion . '"' : '') . '/>';
        return $this;
    }

    /**
     * Area entre la serie y una base constante (o entre dos series alineadas). Los huecos (nulos) cortan el area en tramos.
     * @param array<int, array{float, ?float}> $puntos
     * @param float|array<int, array{float, ?float}> $base
     */
    public function area(array $puntos, string $color, float|array $base = 0.0, float $opacidad = 1.0): self
    {
        $tramo = [];
        $tramos = [];
        foreach ($puntos as $i => $p) {
            $b = is_array($base) ? ($base[$i][1] ?? null) : $base;
            if ($p[1] === null || $b === null) {
                if ($tramo) {
                    $tramos[] = $tramo;
                }
                $tramo = [];
                continue;
            }
            $tramo[] = [$p[0], $p[1], $b];
        }
        if ($tramo) {
            $tramos[] = $tramo;
        }
        foreach ($tramos as $t) {
            if (count($t) < 2) {
                continue;
            }
            $d = 'M' . self::n($this->x($t[0][0])) . ' ' . self::n($this->y($t[0][1]));
            foreach (array_slice($t, 1) as [$x, $y]) {
                $d .= 'L' . self::n($this->x($x)) . ' ' . self::n($this->y($y));
            }
            foreach (array_reverse($t) as [$x, , $b]) {
                $d .= 'L' . self::n($this->x($x)) . ' ' . self::n($this->y($b));
            }
            $this->partes[] = '<path d="' . $d . 'Z" fill="' . $color . '" fill-opacity="' . $opacidad . '" stroke="none"/>';
        }
        return $this;
    }

    public function punto(float $x, float $y, string $color, float $radio = 5, bool $hueco = false, ?string $borde = null): self
    {
        $this->partes[] = '<circle cx="' . self::n($this->x($x)) . '" cy="' . self::n($this->y($y)) . '" r="' . $radio . '" fill="' . ($hueco ? '#FFFFFF' : $color)
            . '" stroke="' . ($hueco ? $color : '#FFFFFF') . '" stroke-width="' . ($hueco ? 1.6 : 2) . '"' . ($borde ? ' data-borde="' . $borde . '"' : '') . '/>';
        return $this;
    }

    public function rect(float $x, float $y, float $ancho, float $alto, string $color, float $opacidad = 1.0, string $extra = ''): self
    {
        $this->partes[] = '<rect x="' . self::n($x) . '" y="' . self::n($y) . '" width="' . self::n(max($ancho, 0)) . '" height="' . self::n(max($alto, 0))
            . '" fill="' . $color . '"' . ($opacidad < 1 ? ' fill-opacity="' . $opacidad . '"' : '') . ($extra ? ' ' . $extra : '') . '/>';
        return $this;
    }

    public function texto(float $x, float $y, string $texto, string $clase = 'eje', string $ancla = 'start', string $color = '', string $extra = ''): self
    {
        $this->partes[] = '<text class="' . $clase . '" x="' . self::n($x) . '" y="' . self::n($y) . '" text-anchor="' . $ancla . '"'
            . ($color ? ' fill="' . $color . '"' : '') . ($extra ? ' ' . $extra : '') . '>' . Formato::h($texto) . '</text>';
        return $this;
    }

    /** Linea horizontal punteada con etiqueta, en unidades de datos. */
    public function referenciaY(float $valor, string $color, string $etiqueta = '', string $colorTexto = '', string $ancla = 'start'): self
    {
        $y = $this->y($valor);
        $this->partes[] = '<line x1="' . self::n($this->l) . '" x2="' . self::n($this->areaX1()) . '" y1="' . self::n($y) . '" y2="' . self::n($y)
            . '" stroke="' . $color . '" stroke-width="1" stroke-dasharray="3 3"/>';
        if ($etiqueta !== '') {
            $this->texto($ancla === 'end' ? $this->areaX1() - 2 : $this->l + 2, $y - 4, $etiqueta, 'nota', $ancla, $colorTexto ?: $color);
        }
        return $this;
    }

    /** Leyenda arriba a la derecha: [[color, texto, forma]] con forma 'linea', 'caja' o 'punto'. */
    public function leyenda(array $items): self
    {
        $x = $this->areaX1();
        foreach (array_reverse($items) as [$color, $texto, $forma]) {
            $ancho = mb_strlen($texto) * 6.3 + 34;
            $x -= $ancho;
            $y = 12.0;
            $this->partes[] = match ($forma) {
                'linea' => '<line x1="' . self::n($x) . '" x2="' . self::n($x + 14) . '" y1="' . $y . '" y2="' . $y . '" stroke="' . $color . '" stroke-width="2.4" stroke-linecap="round"/>',
                'guion' => '<line x1="' . self::n($x) . '" x2="' . self::n($x + 14) . '" y1="' . $y . '" y2="' . $y . '" stroke="' . $color . '" stroke-width="1.6" stroke-dasharray="3 2"/>',
                'punto' => '<circle cx="' . self::n($x + 7) . '" cy="' . $y . '" r="4" fill="#FFFFFF" stroke="' . $color . '" stroke-width="1.6"/>',
                'marca' => '<line x1="' . self::n($x + 7) . '" x2="' . self::n($x + 7) . '" y1="' . ($y - 6) . '" y2="' . ($y + 6) . '" stroke="' . $color . '" stroke-width="2"/>',
                default => '<rect x="' . self::n($x) . '" y="' . ($y - 5) . '" width="12" height="10" rx="2" fill="' . $color . '"/>',
            };
            $this->texto($x + 18, $y + 3.5, $texto, 'leyenda');
        }
        return $this;
    }

    /** Registra un punto para el hover (coordenadas ya en pixeles del lienzo). */
    public function hover(float $px, float $py, string $texto): self
    {
        $this->hover[] = [round($px, 1), round($py, 1), $texto];
        return $this;
    }

    public function modoHover(string $modo, array $extra = []): self
    {
        $this->modo = $modo;
        $this->extra = $extra;
        return $this;
    }

    public function svg(string $descripcion, string $clase = ''): string
    {
        $atributos = ' class="grafico ' . $clase . '" viewBox="0 0 ' . self::n($this->w) . ' ' . self::n($this->h) . '" role="img" aria-label="' . Formato::h($descripcion) . '"';
        if ($this->hover || $this->modo !== 'x') {
            $atributos .= ' data-modo="' . $this->modo . '"';
        }
        if ($this->hover) {
            $atributos .= ' data-hover="' . Formato::h(json_encode($this->hover, JSON_UNESCAPED_UNICODE)) . '"';
        }
        foreach ($this->extra as $k => $v) {
            $atributos .= ' data-' . $k . '="' . Formato::h(is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string)$v) . '"';
        }
        return '<svg' . $atributos . ' xmlns="http://www.w3.org/2000/svg">' . implode('', $this->partes) . '</svg>';
    }
}
