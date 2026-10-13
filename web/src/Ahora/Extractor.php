<?php
declare(strict_types=1);

namespace CuantoCoesta\Ahora;

use CuantoCoesta\Config;
use CuantoCoesta\Fechas;
use CuantoCoesta\Http;

/**
 * Extractor ligero de "Ahora": costo marginal y demanda en tiempo real de la barra de referencia.
 * Pide solo los procesos que faltan y vuelve a mirar los ultimos para detectar revisiones del COES.
 * Equivale a src/ahora/extractor.py.
 */
final class Extractor
{
    public function __construct(private ?Http $http = null)
    {
        $this->http ??= new Http();
    }

    private function cfg(): array
    {
        return Config::ahora()['ahora'];
    }

    private static function fechaCoes(\DateTimeInterface $f): string
    {
        return $f->format('d/m/Y');
    }

    /** '10:00' -> fin de intervalo; '23:59' es el fin del dia (00:00 del dia siguiente). */
    public static function momento(\DateTimeInterface $fecha, string $horaTexto): \DateTimeImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $horaTexto));
        $momento = \DateTimeImmutable::createFromInterface($fecha)->setTime($h, $m);
        return $m === 59 ? $momento->modify('+1 minute') : $momento;
    }

    /** Quita U+FFFD y colapsa espacios (como limpiar_texto de Python). */
    public static function limpiarTexto(string $texto): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', str_replace("\u{FFFD}", '', $texto)));
    }

    /** @return array<int, array{int, \DateTimeImmutable}> [correlativo, fin_de_intervalo] publicados ese dia */
    public function procesosDelDia(\DateTimeInterface $fecha): array
    {
        $cfg = $this->cfg()['costo_marginal'];
        $crudo = $this->http->pedir('POST', $cfg['url_procesos'], ['fecha' => self::fechaCoes($fecha)]);
        $procesos = json_decode(ltrim($crudo, "\xEF\xBB\xBF"), true);
        if (!is_array($procesos)) {
            throw new \RuntimeException('El COES devolvio algo que no es JSON al pedir los procesos.');
        }
        $salida = [];
        foreach ($procesos as $p) {
            if (!empty($p['FechaProceso'])) {
                $salida[] = [(int)$p['Cmgncorrelativo'], self::momento($fecha, (string)$p['FechaProceso'])];
            }
        }
        usort($salida, static fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return $salida;
    }

    /**
     * HTML de la tabla del COES -> filas de la barra de referencia (hora, barra, energia, congestion, total).
     * @return array<int, array<string, mixed>>
     */
    public static function leerTablaProceso(string $html, int $correlativo, \DateTimeInterface $momento, ?string $barraRef = null): array
    {
        $barraRef ??= Config::ahora()['barra_referencia'];
        mb_substitute_character(0xFFFD);
        $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');
        $celda = '<td>(.*?)</td>';
        preg_match_all('#<tr>\s*' . implode('\s*', array_fill(0, 5, $celda)) . '\s*</tr>#s', $html, $m, PREG_SET_ORDER);
        $filas = [];
        foreach ($m as $f) {
            $barra = self::limpiarTexto(html_entity_decode($f[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($barra !== $barraRef) {
                continue;
            }
            [$energia, $congestion, $total] = [trim($f[3]), trim($f[4]), trim($f[5])];
            if (!is_numeric($energia) || !is_numeric($congestion) || !is_numeric($total)) {
                continue;
            }
            $filas[] = ['fecha_hora' => $momento->format('Y-m-d H:i'), 'barra' => $barra, 'cmg_energia' => (float)$energia,
                'cmg_congestion' => (float)$congestion, 'cmg_total' => (float)$total, 'correlativo' => $correlativo];
        }
        return $filas;
    }

    /** @return array{int, int} [nuevos, revisados] */
    public function actualizarCmg(array $dias): array
    {
        $cfg = $this->cfg()['costo_marginal'];
        $ya = Repositorio::horasConCmg();
        $procesos = [];
        foreach ($dias as $dia) {
            $procesos = array_merge($procesos, $this->procesosDelDia($dia));
        }
        usort($procesos, static fn($a, $b) => $a[1] <=> $b[1]);
        $recientes = array_map(static fn($p) => $p[0], array_slice($procesos, -(int)$cfg['revisar_ultimos']));
        $nuevos = $revisados = 0;
        $extraidoEn = Fechas::ahora()->format('Y-m-d H:i:s');
        foreach ($procesos as [$correlativo, $momento]) {
            if (isset($ya[$momento->format('Y-m-d H:i')]) && !in_array($correlativo, $recientes, true)) {
                continue;
            }
            $html = $this->http->pedir('POST', $cfg['url_lista'], ['correlativo' => $correlativo, 'defecto' => $cfg['defecto']]);
            $filas = self::leerTablaProceso($html, $correlativo, $momento);
            if (!$filas) {
                error_log("[coesta] Proceso $correlativo (" . $momento->format('Y-m-d H:i') . ') vino sin la barra de referencia');
                continue;
            }
            [$n, $r] = Repositorio::guardarCmg($filas, $extraidoEn);
            $nuevos += $n;
            $revisados += $r;
        }
        return [$nuevos, $revisados];
    }

    /** Demanda ejecutada (solo intervalos ya ocurridos) y programa del dia. @return array{int, int} */
    public function actualizarDemanda(array $dias): array
    {
        $cfg = $this->cfg()['demanda'];
        $extraidoEn = Fechas::ahora()->format('Y-m-d H:i:s');
        $nuevos = $revisados = 0;
        foreach ($dias as $dia) {
            $crudo = $this->http->pedir('POST', $cfg['url'], ['fechaInicial' => self::fechaCoes($dia), 'fechaFinal' => self::fechaCoes($dia)]);
            $datos = json_decode(ltrim($crudo, "\xEF\xBB\xBF"), true);
            $filas = self::filasDemanda($datos ?? []);
            [$n, $r] = Repositorio::guardarDemanda($filas, $extraidoEn);
            $nuevos += $n;
            $revisados += $r;
        }
        return [$nuevos, $revisados];
    }

    /** JSON de /Demanda -> filas (descarta los 0/None con que el COES rellena los intervalos futuros). */
    public static function filasDemanda(array $datos): array
    {
        $filas = [];
        foreach ($datos['Chart']['Series'] ?? [] as $serie) {
            $nombre = mb_strtolower((string)$serie['Name']);
            $variable = str_starts_with($nombre, 'ejecutado') ? 'ejecutada' : (str_contains($nombre, 'diaria') ? 'programa_diario' : null);
            if ($variable === null) {
                continue;
            }
            foreach ($serie['Data'] ?? [] as $punto) {
                if (empty($punto['Valor'])) {
                    continue;
                }
                $momento = \DateTimeImmutable::createFromFormat('Y/m/d H:i:s', (string)$punto['Nombre']);
                if ($momento) {
                    $filas[] = ['fecha_hora' => $momento->format('Y-m-d H:i'), 'variable' => $variable, 'valor' => (float)$punto['Valor']];
                }
            }
        }
        return $filas;
    }

    /**
     * Dias a consultar: desde el ultimo dato guardado hasta hoy (rellena huecos hasta $maximo dias atras).
     * @return \DateTimeImmutable[]
     */
    public static function diasARevisar(\DateTimeImmutable $hoy, ?string $ultimoMomento, bool $desdeAyer = false, int $maximo = 7): array
    {
        $ayer = $hoy->modify('-1 day');
        $inicio = $ultimoMomento ? Fechas::fecha($ultimoMomento)->modify('-1 minute')->setTime(0, 0) : $ayer;
        if ($desdeAyer && $ayer < $inicio) {
            $inicio = $ayer;
        }
        $limite = $hoy->modify('-' . ($maximo - 1) . ' days');
        if ($inicio < $limite) {
            $inicio = $limite;
        }
        $dias = [];
        for ($d = $inicio; $d <= $hoy; $d = $d->modify('+1 day')) {
            $dias[] = $d;
        }
        return $dias;
    }

    /** Una pasada completa. Si una fuente falla se registra y sigue la otra. @return array<string, array<string, mixed>> */
    public function actualizar(bool $desdeAyer = false): array
    {
        $hoy = Fechas::hoy();
        $dias = self::diasARevisar($hoy, Repositorio::ultimoMomento(), $desdeAyer);
        $resumen = [];
        foreach (['costo_marginal' => 'actualizarCmg', 'demanda' => 'actualizarDemanda'] as $fuente => $metodo) {
            $inicio = Fechas::ahora()->format('Y-m-d H:i:s');
            try {
                [$nuevos, $revisados] = $this->$metodo($dias);
                Repositorio::registrarExtraccion($fuente, $inicio, true, $nuevos, $revisados);
                $resumen[$fuente] = ['ok' => true, 'nuevos' => $nuevos, 'revisados' => $revisados];
            } catch (\Throwable $e) {
                error_log("[coesta] Ahora $fuente fallo: " . $e->getMessage());
                Repositorio::registrarExtraccion($fuente, $inicio, false, 0, 0, $e->getMessage());
                $resumen[$fuente] = ['ok' => false, 'error' => $e->getMessage()];
            }
        }
        $inicio = Fechas::ahora()->format('Y-m-d H:i:s');
        try {
            $resumen['programa'] = (new Programa($this->http))->actualizar($hoy);
            $resumen['programa']['ok'] = true;
        } catch (\Throwable $e) {
            error_log('[coesta] Programa Diario fallo: ' . $e->getMessage());
            $resumen['programa'] = ['ok' => false, 'error' => $e->getMessage()];
        }
        return $resumen;
    }
}
