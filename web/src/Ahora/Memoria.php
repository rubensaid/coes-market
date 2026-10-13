<?php
declare(strict_types=1);

namespace CuantoCoesta\Ahora;

use CuantoCoesta\Config;
use CuantoCoesta\Db;
use CuantoCoesta\Fechas;
use CuantoCoesta\Formato;
use CuantoCoesta\Rarezas;

/**
 * El precio tiene memoria: ultimo dato frente a tres referencias, antiguedad y alertas (src/ahora/memoria.py).
 * Si el COES no publico nada nuevo se muestra el ultimo dato con su antiguedad: nunca se finge una actualizacion.
 */
final class Memoria
{
    /**
     * Historia de la barra de referencia: confirmado > preliminar > en vivo.
     * @return array<string, float> 'Y-m-d H:i' => cmg_total
     */
    public static function historia(string $desde): array
    {
        $h = [];
        foreach (Repositorio::cmgVigente($desde) as $f) {
            if ($f['total'] !== null) {
                $h[substr($f['fecha_hora'], 0, 16)] = (float)$f['total'];
            }
        }
        // los datos de mayor prioridad se asignan despues y pisan a los anteriores
        foreach ([1, 0] as $estado) {
            foreach (Db::filas('SELECT DATE_FORMAT(fecha_hora, "%Y-%m-%d %H:%i") AS fh, total FROM cmg_ref WHERE estado = ? AND fecha_hora >= ?', [$estado, $desde]) as $f) {
                if ($f['total'] !== null) {
                    $h[$f['fh']] = (float)$f['total'];
                }
            }
        }
        ksort($h);
        return $h;
    }

    /** Lectura en palabras del precio actual: [texto, tono]. */
    public static function lectura(float $valor, ?array $ev): array
    {
        if ($ev === null || empty($ev['mediana'])) {
            return ['Sin historia suficiente para comparar', 'neutro'];
        }
        $razon = $valor / $ev['mediana'];
        if ($ev['es_rara']) {
            return ['Inusual para esta hora', 'alerta'];
        }
        if ($razon >= 1.8) {
            $veces = round($razon * 2, 0, PHP_ROUND_HALF_EVEN) / 2;
            $texto = floor($veces) == $veces ? number_format($veces, 0) : number_format($veces, 1);
            return ["Unas $texto veces lo normal para esta hora", 'alerta'];
        }
        if ($razon <= 0.6) {
            return ['Mucho más barato que lo normal', 'info'];
        }
        return ['Normal para esta hora', 'bueno'];
    }

    /** @return array<string, mixed> todo lo que muestra la seccion Ahora */
    public static function estado(?\DateTimeImmutable $ahora = null): array
    {
        $ahora ??= Fechas::ahora();
        $barra = Config::ahora()['barra_referencia'];
        $ultima = Repositorio::ultimaExtraccion('costo_marginal');
        $tr = Repositorio::cmgVigente($ahora->modify('-3 days')->format('Y-m-d'));
        if (!$tr) {
            return ['barra' => $barra, 'sin_datos' => true, 'alertas' => ['Aún no hay datos. Pulsa Actualizar.'], 'ultima_extraccion' => $ultima,
                'programa' => Programa::enSoles(Fechas::hoy())];
        }
        $ultimo = end($tr);
        $momento = Fechas::fecha($ultimo['fecha_hora']);
        $valor = (float)$ultimo['total'];
        $historia = self::historia($momento->modify('-29 days')->format('Y-m-d'));

        $referencias = [];
        foreach ([['media hora antes', '-30 minutes'], ['ayer a esta hora', '-1 day'], ['hace una semana', '-7 days']] as [$nombre, $delta]) {
            $ref = $historia[$momento->modify($delta)->format('Y-m-d H:i')] ?? null;
            $referencias[] = ['nombre' => $nombre, 'valor' => $ref, 'var' => Formato::variacion($valor, $ref)];
        }
        $ev = Rarezas::evaluar($valor, $momento, $historia);
        $alertas = [];
        $salto = Rarezas::saltoBrusco($valor, $referencias[0]['valor']);
        if ($salto) {
            $alertas[] = $salto;
        }
        if ($ev['es_rara']) {
            $alertas[] = $ev['regla'];
        }
        $hoy0 = $momento->setTime(0, 0);
        $serie = static function (\DateTimeImmutable $desde, \DateTimeImmutable $hasta) use ($historia): array {
            $out = [];
            foreach ($historia as $fh => $v) {
                if ($fh > $desde->format('Y-m-d H:i') && $fh <= $hasta->format('Y-m-d H:i')) {
                    $out[] = [$fh, $v];
                }
            }
            return $out;
        };
        [$texto, $tono] = self::lectura($valor, $ev);
        $antiguedad = ($ahora->getTimestamp() - $momento->getTimestamp()) / 60;
        return [
            'barra' => $barra, 'sin_datos' => false, 'valor' => $valor, 'momento' => $momento->format('Y-m-d H:i'),
            'extraido_en' => $ultimo['extraido_en'], 'antiguedad_min' => $antiguedad, 'referencias' => $referencias,
            'evaluacion' => $ev, 'lectura' => ['texto' => $texto, 'tono' => $tono], 'alertas' => $alertas,
            'demanda' => self::estadoDemanda($momento->modify('-1 day')->format('Y-m-d')),
            'serie_hoy' => $serie($hoy0, $hoy0->modify('+1 day')), 'serie_ayer' => $serie($hoy0->modify('-1 day'), $hoy0),
            'revisiones' => Repositorio::revisiones(), 'ultima_extraccion' => $ultima,
            'programa' => Programa::enSoles(Fechas::hoy()),
        ];
    }

    /** Ultima demanda ejecutada, lo programado para ese intervalo y la misma hora de ayer. */
    public static function estadoDemanda(string $desde): array
    {
        $dem = Repositorio::demandaVigente($desde);
        $ejec = array_filter($dem, static fn($f) => ($f['ejecutada'] ?? null) !== null);
        if (!$ejec) {
            return [];
        }
        $fh = array_key_last($ejec);
        $valor = (float)$ejec[$fh]['ejecutada'];
        $prog = $dem[$fh]['programa_diario'] ?? null;
        $ayer = Fechas::fecha($fh)->modify('-1 day')->format('Y-m-d H:i:s');
        return ['valor' => $valor, 'momento' => substr($fh, 0, 16), 'programado' => $prog, 'desvio' => Formato::variacion($valor, $prog),
            'ayer' => isset($dem[$ayer]['ejecutada']) ? (float)$dem[$ayer]['ejecutada'] : null];
    }
}
