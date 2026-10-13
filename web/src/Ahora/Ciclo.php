<?php
declare(strict_types=1);

namespace CuantoCoesta\Ahora;

use CuantoCoesta\Config;
use CuantoCoesta\Db;
use CuantoCoesta\Fechas;

/** Una pasada de Ahora con candado (cron y boton "Actualizar ahora" no corren a la vez) y limite de frecuencia. */
final class Ciclo
{
    public const ESPERA_MINIMA_SEG = 300;

    /**
     * @param bool $respetarEspera true en el boton publico: si ya se consulto hace menos de 5 min no vuelve a pedir al COES
     * @return array<string, mixed>
     */
    public static function correr(bool $desdeAyer = false, bool $respetarEspera = false): array
    {
        $ultima = Db::fila('SELECT creado_en FROM extracciones ORDER BY id DESC LIMIT 1');
        if ($respetarEspera && $ultima) {
            $hace = Fechas::ahora()->getTimestamp() - Fechas::fecha($ultima['creado_en'])->getTimestamp();
            if ($hace < self::ESPERA_MINIMA_SEG) {
                return ['omitido' => true, 'detalle' => 'Se consultó hace ' . $hace . ' segundos. El COES publica cada 30 minutos.'];
            }
        }
        $candado = fopen(Config::storage() . '/ahora.lock', 'c');
        if (!$candado || !flock($candado, LOCK_EX | LOCK_NB)) {
            return ['omitido' => true, 'detalle' => 'Ya hay una consulta en curso.'];
        }
        try {
            $resumen = (new Extractor())->actualizar($desdeAyer);
            $resumen['omitido'] = false;
            return $resumen;
        } finally {
            flock($candado, LOCK_UN);
            fclose($candado);
        }
    }
}
