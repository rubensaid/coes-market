<?php
declare(strict_types=1);

namespace CuantoCoesta;

/** Semanas publicadas: guardado del paquete comprimido y lecturas. */
final class Semanas
{
    public const ESQUEMA = 1;
    private const MAX_DESCOMPRIMIDO = 64 * 1024 * 1024;

    public static function valida(string $semana): bool
    {
        return (bool)preg_match('/^\d{4}-W(0[1-9]|[1-4]\d|5[0-3])$/', $semana);
    }

    /** Decodifica y valida un paquete gzip. Lanza InvalidArgumentException si no sirve. */
    public static function decodificar(string $gz): array
    {
        $crudo = @gzdecode($gz, self::MAX_DESCOMPRIMIDO);
        if ($crudo === false) {
            throw new \InvalidArgumentException('El paquete no es un gzip valido.');
        }
        try {
            $datos = json_decode($crudo, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('El paquete no es JSON valido.');
        }
        if (!is_array($datos) || ($datos['esquema'] ?? null) !== self::ESQUEMA) {
            throw new \InvalidArgumentException('Version de paquete no compatible (esquema ' . json_encode($datos['esquema'] ?? null) . ').');
        }
        if (!self::valida((string)($datos['semana'] ?? ''))) {
            throw new \InvalidArgumentException('El paquete no trae una semana valida.');
        }
        return $datos;
    }

    /** Guarda (o reemplaza) la semana y su serie de CMg. Devuelve un resumen de lo escrito. */
    public static function guardar(array $datos, string $gz, bool $tienePdf): array
    {
        $pdo = Db::pdo();
        $semana = $datos['semana'];
        $pdo->beginTransaction();
        try {
            Db::ejecutar(
                'REPLACE INTO semanas (semana, desde, hasta, es_preliminar, generado_en, recibido_en, tiene_pdf, paquete)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$semana, $datos['desde'], $datos['hasta'], $datos['es_preliminar'] ? 1 : 0, $datos['generado_en'] ?? null,
                    Fechas::ahora()->format('Y-m-d H:i:s'), $tienePdf ? 1 : 0, $gz]
            );
            $filas = self::guardarCmgRef($semana, $datos['evolucion'] ?? []);
            $tc = self::guardarTipoCambio($datos['tipo_cambio'] ?? []);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['cmg_ref' => $filas, 'tipo_cambio' => $tc];
    }

    /** Una fila por media hora; si ya hay un dato mas firme (confirmado) no se pisa con uno preliminar. */
    private static function guardarCmgRef(string $semana, array $ev): int
    {
        $ts = $ev['ts'] ?? [];
        $sql = 'INSERT INTO cmg_ref (fecha_hora, total, energia, congestion, estado, semana) VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                  total = IF(VALUES(estado) <= estado, VALUES(total), total),
                  energia = IF(VALUES(estado) <= estado, VALUES(energia), energia),
                  congestion = IF(VALUES(estado) <= estado, VALUES(congestion), congestion),
                  semana = IF(VALUES(estado) <= estado, VALUES(semana), semana),
                  estado = LEAST(VALUES(estado), estado)';
        $st = Db::pdo()->prepare($sql);
        foreach ($ts as $i => $t) {
            if (($ev['estado'][$i] ?? 2) > 1) {   // lo "en vivo" vive en ahora_cmg
                continue;
            }
            $st->execute([$t . ':00', $ev['total'][$i] ?? null, $ev['energia'][$i] ?? null, $ev['congestion'][$i] ?? null,
                $ev['estado'][$i] ?? 0, $semana]);
        }
        return count($ts);
    }

    private static function guardarTipoCambio(array $tc): int
    {
        $st = Db::pdo()->prepare('INSERT INTO tipo_cambio (fecha, tc) VALUES (?, ?) ON DUPLICATE KEY UPDATE tc = VALUES(tc)');
        $n = 0;
        foreach ($tc as $fecha => $valor) {
            if ($valor !== null) {
                $st->execute([$fecha, $valor]);
                $n++;
            }
        }
        return $n;
    }

    /** Semanas publicadas, de la mas reciente a la mas antigua. */
    public static function listar(): array
    {
        return Db::filas('SELECT semana, desde, hasta, es_preliminar, tiene_pdf, recibido_en FROM semanas ORDER BY semana DESC');
    }

    /** La semana publicada mas reciente. */
    public static function porDefecto(): ?string
    {
        $f = Db::fila('SELECT semana FROM semanas ORDER BY semana DESC LIMIT 1');
        return $f['semana'] ?? null;
    }

    public static function obtener(string $semana): ?array
    {
        if (!self::valida($semana)) {
            return null;
        }
        $f = Db::fila('SELECT paquete FROM semanas WHERE semana = ?', [$semana]);
        return $f ? self::decodificar($f['paquete']) : null;
    }

    public static function tienePdf(string $semana): bool
    {
        return self::valida($semana) && is_file(Config::rutaPdf($semana));
    }

    /** Borra semanas antiguas segun 'conservar_semanas' de la configuracion local. */
    public static function limpiar(): int
    {
        $n = (int)(Config::local()['conservar_semanas'] ?? 0);
        if ($n <= 0) {
            return 0;
        }
        $viejas = Db::filas('SELECT semana FROM semanas ORDER BY semana DESC LIMIT 1000 OFFSET ' . $n);
        foreach ($viejas as $v) {
            Db::ejecutar('DELETE FROM semanas WHERE semana = ?', [$v['semana']]);
            $pdf = Config::rutaPdf($v['semana']);
            if (is_file($pdf)) {
                unlink($pdf);
            }
        }
        return count($viejas);
    }
}
