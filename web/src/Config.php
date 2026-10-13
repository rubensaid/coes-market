<?php
declare(strict_types=1);

namespace CuantoCoesta;

/** Lee config/*.json (exportados desde config/*.yaml) y config/local.php (secretos, fuera de git). */
final class Config
{
    private static array $cache = [];
    private static ?array $local = null;

    public static function raiz(): string
    {
        return dirname(__DIR__);
    }

    public static function json(string $nombre): array
    {
        if (!isset(self::$cache[$nombre])) {
            $ruta = self::raiz() . "/config/$nombre.json";
            if (!is_file($ruta)) {
                throw new \RuntimeException("Falta config/$nombre.json (python -m src.publicar --config-web).");
            }
            self::$cache[$nombre] = json_decode((string)file_get_contents($ruta), true, 512, JSON_THROW_ON_ERROR);
        }
        return self::$cache[$nombre];
    }

    /** Sustituye la configuracion local (solo para pruebas). */
    public static function fijarLocal(array $local): void
    {
        self::$local = $local;
    }

    public static function local(): array
    {
        if (self::$local === null) {
            $ruta = self::raiz() . '/config/local.php';
            self::$local = is_file($ruta) ? (array)require $ruta : [];
        }
        return self::$local;
    }

    public static function proyecto(): array
    {
        return self::json('proyecto');
    }

    public static function glosario(): array
    {
        return self::json('glosario');
    }

    public static function analisis(): array
    {
        return self::json('analisis');
    }

    public static function ahora(): array
    {
        return self::json('ahora');
    }

    public static function aviso(): string
    {
        return trim((string)preg_replace('/\s+/', ' ', self::proyecto()['aviso']));
    }

    public static function colorTecnologia(string $clave): string
    {
        $t = self::proyecto()['tecnologias'];
        return ($t[$clave] ?? $t['otros'])['color'];
    }

    public static function nombreTecnologia(string $clave): string
    {
        $t = self::proyecto()['tecnologias'];
        return ($t[$clave] ?? $t['otros'])['nombre'];
    }

    public static function storage(string $sub = ''): string
    {
        $ruta = self::raiz() . '/storage' . ($sub !== '' ? "/$sub" : '');
        if (!is_dir($ruta)) {
            mkdir($ruta, 0750, true);
        }
        return $ruta;
    }

    public static function rutaPdf(string $semana): string
    {
        return self::storage('pdf') . '/' . self::proyecto()['slug'] . "_$semana.pdf";
    }
}
