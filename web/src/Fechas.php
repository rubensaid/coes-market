<?php
declare(strict_types=1);

namespace CuantoCoesta;

use DateTimeImmutable;
use DateTimeInterface;

/** Fechas legibles en espanol y semanas ISO (equivale a src/utils/fechas.py). */
final class Fechas
{
    public const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre',
        'octubre', 'noviembre', 'diciembre'];
    public const DIAS = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
    public const ABREV = ['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'];

    public static function ahora(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new \DateTimeZone('America/Lima'));
    }

    public static function hoy(): DateTimeImmutable
    {
        return self::ahora()->setTime(0, 0);
    }

    public static function fecha(string $texto): DateTimeImmutable
    {
        return new DateTimeImmutable($texto, new \DateTimeZone('America/Lima'));
    }

    /** 0 = lunes ... 6 = domingo (como weekday() de Python). */
    public static function diaSemana(DateTimeInterface $f): int
    {
        return (int)$f->format('N') - 1;
    }

    public static function semanaIso(DateTimeInterface $f): string
    {
        return $f->format('o') . '-W' . $f->format('W');
    }

    /** '2026-W40' -> [lunes, domingo] */
    public static function rangoSemana(string $codigo): array
    {
        [$anio, $semana] = explode('-W', strtoupper($codigo));
        $lunes = (new DateTimeImmutable('now', new \DateTimeZone('America/Lima')))->setISODate((int)$anio, (int)$semana, 1)->setTime(0, 0);
        return [$lunes, $lunes->modify('+6 days')];
    }

    public static function semanaAnterior(string $codigo, int $n = 1): string
    {
        return self::semanaIso(self::rangoSemana($codigo)[0]->modify("-$n weeks"));
    }

    public static function fechaLarga(DateTimeInterface $f): string
    {
        return (int)$f->format('j') . ' de ' . self::MESES[(int)$f->format('n') - 1] . ' de ' . $f->format('Y');
    }

    public static function diaTexto(DateTimeInterface $f): string
    {
        return self::DIAS[self::diaSemana($f)] . ' ' . (int)$f->format('j') . ' de ' . self::MESES[(int)$f->format('n') - 1];
    }

    public static function horaTexto(int $hora): string
    {
        return (($hora % 12) ?: 12) . ' ' . ($hora < 12 ? 'a. m.' : 'p. m.');
    }

    /** 'jueves 2 de octubre, 7:30 p. m.' */
    public static function diaYHora(DateTimeInterface $m): string
    {
        $h = (int)$m->format('G');
        return self::diaTexto($m) . ', ' . (($h % 12) ?: 12) . ':' . $m->format('i') . ' ' . ($h < 12 ? 'a. m.' : 'p. m.');
    }

    /** '7:30 p. m.' */
    public static function horaCorta(DateTimeInterface $m): string
    {
        $h = (int)$m->format('G');
        return (($h % 12) ?: 12) . ':' . $m->format('i') . ' ' . ($h < 12 ? 'a. m.' : 'p. m.');
    }

    /** '28 de septiembre al 4 de octubre de 2026' (rango corto de la app de escritorio). */
    public static function rangoCorto(string $semana): string
    {
        [$a, $b] = self::rangoSemana($semana);
        if ($a->format('n') === $b->format('n')) {
            return (int)$a->format('j') . ' al ' . (int)$b->format('j') . ' de ' . self::MESES[(int)$b->format('n') - 1] . ' de ' . $b->format('Y');
        }
        if ($a->format('Y') === $b->format('Y')) {
            return (int)$a->format('j') . ' de ' . self::MESES[(int)$a->format('n') - 1] . ' al ' . (int)$b->format('j') . ' de '
                . self::MESES[(int)$b->format('n') - 1] . ' de ' . $b->format('Y');
        }
        return self::fechaLarga($a) . ' al ' . self::fechaLarga($b);
    }

    public static function textoSemana(string $codigo): string
    {
        return 'Semana del ' . self::rangoCorto($codigo);
    }

    /** Atajos de rango de la seccion Evolucion: 7d, 30d, mes, hidro, anio, todo. */
    public static function rangoAtajo(string $atajo, DateTimeImmutable $hoy, ?DateTimeImmutable $primera): array
    {
        switch ($atajo) {
            case '7d':
                return [$hoy->modify('-6 days'), $hoy];
            case '30d':
                return [$hoy->modify('-29 days'), $hoy];
            case 'mes':
                return [$hoy->modify('first day of this month'), $hoy];
            case 'hidro':
                $mes = (int)Config::analisis()['mes_inicio_ano_hidrologico'];
                $anio = (int)$hoy->format('Y') - ((int)$hoy->format('n') >= $mes ? 0 : 1);
                return [self::fecha(sprintf('%04d-%02d-01', $anio, $mes)), $hoy];
            case 'anio':
                return [self::fecha($hoy->format('Y') . '-01-01'), $hoy];
            default:
                return [$primera ?? $hoy->modify('-29 days'), $hoy];
        }
    }
}
