<?php
declare(strict_types=1);

namespace CuantoCoesta;

/**
 * Detector de rarezas: un valor es raro si rompe el rango visto a esa misma hora del dia en las 4 semanas previas
 * (por encima del maximo o por debajo del minimo, con margen). Equivale a src/analysis/rarezas.py.
 * La historia es un mapa 'Y-m-d H:i' => valor.
 */
final class Rarezas
{
    public static function mediana(array $v): float
    {
        sort($v);
        $n = count($v);
        $m = intdiv($n, 2);
        return $n % 2 ? (float)$v[$m] : ((float)$v[$m - 1] + (float)$v[$m]) / 2;
    }

    /** @return array<string, mixed> valor, mediana, minimo, maximo, n, es_rara, regla, intensidad */
    public static function evaluar(float $valor, \DateTimeImmutable $momento, array $historia, ?array $cfg = null): array
    {
        $cfg ??= Config::analisis()['rarezas'];
        $inicio = $momento->modify('-' . (int)$cfg['dias_historia'] . ' days')->format('Y-m-d H:i');
        $fin = $momento->format('Y-m-d H:i');
        $horaMin = $momento->format('H:i');
        $mismaHora = [];
        foreach ($historia as $fh => $v) {
            if ($v !== null && $fh < $fin && $fh >= $inicio && substr($fh, 11, 5) === $horaMin) {
                $mismaHora[] = (float)$v;
            }
        }
        $n = count($mismaHora);
        $hora = (int)$momento->format('G') . ':' . $momento->format('i');
        $base = ['valor' => $valor, 'mediana' => null, 'minimo' => null, 'maximo' => null, 'n' => $n, 'es_rara' => false, 'intensidad' => 0.0];
        if ($n < (int)$cfg['minimo_observaciones']) {
            return $base + ['regla' => "Sin juicio: solo hay $n días previos con dato a las $hora (se piden {$cfg['minimo_observaciones']})."];
        }
        $mediana = self::mediana($mismaHora);
        $minimo = min($mismaHora);
        $maximo = max($mismaHora);
        $margen = (float)$cfg['margen_rango'];
        $lejos = abs($valor - $mediana) >= (float)$cfg['diferencia_minima'];
        $semanas = (int)round($cfg['dias_historia'] / 7, 0, PHP_ROUND_HALF_EVEN);
        $r = ['valor' => $valor, 'mediana' => $mediana, 'minimo' => $minimo, 'maximo' => $maximo, 'n' => $n];
        $f1 = static fn(float $x): string => number_format($x, 1, '.', ',');
        $pct = number_format($margen * 100, 0);
        if ($valor > $maximo * (1 + $margen) && $lejos) {
            $regla = "El precio más alto para las $hora en $semanas semanas: S/ {$f1($valor)}, cuando el máximo previo era S/ {$f1($maximo)} "
                . "y lo habitual S/ {$f1($mediana)}. Regla: superar el máximo de $n días en al menos $pct%.";
            return $r + ['es_rara' => true, 'regla' => $regla, 'intensidad' => $valor / max($maximo, 1) - 1];
        }
        if ($valor < $minimo * (1 - $margen) && $lejos) {
            $regla = "El precio más bajo para las $hora en $semanas semanas: S/ {$f1($valor)}, cuando el mínimo previo era S/ {$f1($minimo)} "
                . "y lo habitual S/ {$f1($mediana)}. Regla: bajar del mínimo de $n días en al menos $pct%.";
            return $r + ['es_rara' => true, 'regla' => $regla, 'intensidad' => 1 - $valor / max($minimo, 1)];
        }
        $regla = "Dentro de lo visto a las $hora en $semanas semanas (entre S/ {$f1($minimo)} y S/ {$f1($maximo)}; lo habitual, S/ {$f1($mediana)}).";
        return $r + ['es_rara' => false, 'regla' => $regla, 'intensidad' => 0.0];
    }

    /** Texto si el cambio frente a la media hora anterior es grande, o null. */
    public static function saltoBrusco(float $valor, ?float $anterior, ?array $cfg = null): ?string
    {
        $cfg ??= Config::analisis()['rarezas'];
        if ($anterior === null || $anterior == 0.0) {
            return null;
        }
        $cambio = $valor - $anterior;
        if (abs($cambio) >= $cfg['salto_intervalo_min'] && abs($cambio / $anterior) >= $cfg['salto_intervalo_pct']) {
            return 'Salto brusco: ' . ($cambio > 0 ? 'subió' : 'bajó') . ' S/ ' . number_format(abs($cambio), 1) . ' ('
                . number_format(abs($cambio / $anterior) * 100, 0) . '%) en media hora. Regla: cambio de al menos '
                . number_format($cfg['salto_intervalo_pct'] * 100, 0) . '% y S/ ' . $cfg['salto_intervalo_min'] . '.';
        }
        return null;
    }
}
