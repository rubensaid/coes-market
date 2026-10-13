<?php
declare(strict_types=1);

namespace CuantoCoesta;

/** Numeros al estilo peruano: 12,345.6 (equivale a src/utils/formato.py). */
final class Formato
{
    public static function numero(?float $v, int $dec = 0): string
    {
        return $v === null ? 's/d' : number_format($v, $dec, '.', ',');
    }

    /** Fraccion -> '12.3%'. Maximo 1 decimal. */
    public static function porcentaje(?float $v, int $dec = 1): string
    {
        return $v === null ? 's/d' : number_format($v * 100, min($dec, 1), '.', ',') . '%';
    }

    public static function soles(?float $v, int $dec = 1): string
    {
        return $v === null ? 's/d' : 'S/ ' . self::numero($v, $dec);
    }

    public static function variacion(?float $actual, ?float $anterior): ?float
    {
        return ($actual === null || $anterior === null || $anterior == 0.0) ? null : $actual / $anterior - 1;
    }

    public static function flecha(?float $v): string
    {
        if ($v === null) {
            return '';
        }
        return abs($v) < 0.0005 ? '=' : ($v > 0 ? '▲' : '▼');
    }

    public static function textoVariacion(?float $v): string
    {
        return $v === null ? 's/d' : self::flecha($v) . ' ' . number_format(abs($v) * 100, 1, '.', ',') . '%';
    }

    /** Escapa texto para HTML. */
    public static function h(mixed $v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
