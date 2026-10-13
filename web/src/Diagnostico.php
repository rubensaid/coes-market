<?php
declare(strict_types=1);

namespace CuantoCoesta;

/** Comprueba que el hosting cumple lo que necesita el sitio (versiones, extensiones, hora, base, salida a internet, SMTP). */
final class Diagnostico
{
    public const OK = 'ok';
    public const AVISO = 'aviso';
    public const ERROR = 'error';

    /** @return array<int, array{string, string, string}> [prueba, estado, detalle] */
    public static function correr(bool $conRed = true): array
    {
        $r = [];
        $r[] = ['PHP 8.1 o superior', version_compare(PHP_VERSION, '8.1.0', '>=') ? self::OK : self::ERROR, PHP_VERSION];
        foreach (['pdo_mysql', 'mbstring', 'curl', 'zlib', 'json', 'openssl'] as $ext) {
            $r[] = ["Extension $ext", extension_loaded($ext) ? self::OK : self::ERROR,
                extension_loaded($ext) ? '' : 'Activala en cPanel > Seleccionar version de PHP > Extensiones.'];
        }
        $r[] = ['Zona horaria de PHP', date('P') === '-05:00' ? self::OK : self::ERROR, date_default_timezone_get() . ' (' . date('P') . ')'];
        $r[] = ['memory_limit', self::OK, (string)ini_get('memory_limit')];
        $maxExec = (int)ini_get('max_execution_time');
        $r[] = ['max_execution_time', ($maxExec === 0 || $maxExec >= 60) ? self::OK : self::AVISO,
            $maxExec . ' s (los cron de Ahora y de la cola necesitan 60 s o mas; en CLI no aplica)'];
        $r[] = ['upload_max_filesize / post_max_size', self::OK, ini_get('upload_max_filesize') . ' / ' . ini_get('post_max_size')];
        foreach (['ahora', 'analisis', 'glosario', 'proyecto'] as $c) {
            $r[] = ["config/$c.json", is_file(Config::raiz() . "/config/$c.json") ? self::OK : self::ERROR,
                is_file(Config::raiz() . "/config/$c.json") ? '' : 'Falta: corre python -m src.publicar --config-web y vuelve a subir.'];
        }
        $local = Config::local();
        $token = (string)($local['token'] ?? '');
        $r[] = ['config/local.php con token', (strlen($token) >= 24 && !str_contains($token, 'CAMBIAR')) ? self::OK : self::ERROR,
            $local ? '' : 'No existe config/local.php (copia local.ejemplo.php).'];
        foreach (['pdf', 'logs'] as $sub) {
            $r[] = ["storage/$sub escribible", is_writable(Config::storage($sub)) ? self::OK : self::ERROR, ''];
        }
        try {
            $v = Db::fila('SELECT VERSION() AS v, @@session.time_zone AS tz, NOW() AS ahora');
            $r[] = ['Conexion a MySQL', self::OK, $v['v']];
            $r[] = ['Hora de la sesion MySQL (debe ser Peru, -05:00)', $v['tz'] === '-05:00' ? self::OK : self::ERROR, $v['tz'] . ' · ' . $v['ahora']];
            $faltan = array_diff(['semanas', 'cmg_ref', 'tipo_cambio', 'ahora_cmg', 'ahora_demanda', 'ahora_programa', 'extracciones',
                'estado_fuentes', 'envios_correo', 'ajustes'], array_column(Db::filas('SHOW TABLES'), 'Tables_in_' . (Config::local()['db']['nombre'] ?? '')));
            $r[] = ['Tablas del esquema', $faltan ? self::ERROR : self::OK, $faltan ? 'Faltan: ' . implode(', ', $faltan) . ' (abre instalar.php)' : ''];
        } catch (\Throwable $e) {
            $r[] = ['Conexion a MySQL', self::ERROR, $e->getMessage()];
        }
        if ($conRed) {
            $r[] = self::probarCoes();
            $r[] = self::probarSmtp();
        }
        return $r;
    }

    private static function probarCoes(): array
    {
        $base = Config::ahora()['portal'] ?? 'https://www.coes.org.pe/Portal';
        if (!function_exists('curl_init')) {
            return ['Salida a coes.org.pe', self::ERROR, 'Falta la extension curl.'];
        }
        $ch = curl_init($base . '/mercadomayorista/costosmarginales');
        curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => Config::ahora()['cliente']['user_agent'] ?? 'CuantoCOESta']);
        curl_exec($ch);
        $codigo = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        return ['Salida a coes.org.pe', ($codigo >= 200 && $codigo < 400) ? self::OK : self::ERROR,
            $codigo ? "HTTP $codigo" : ($error ?: 'Sin respuesta (el hosting puede bloquear conexiones salientes).')];
    }

    private static function probarSmtp(): array
    {
        $s = Config::local()['smtp'] ?? [];
        if (empty($s['host'])) {
            return ['Servidor SMTP', self::AVISO, 'No configurado (el PDF no se enviara por correo).'];
        }
        $destino = (($s['seguridad'] ?? 'ssl') === 'ssl' ? 'ssl://' : '') . $s['host'];
        $f = @fsockopen($destino, (int)($s['puerto'] ?? 465), $errno, $errstr, 10);
        if ($f) {
            fclose($f);
        }
        return ['Servidor SMTP alcanzable', $f ? self::OK : self::ERROR, $f ? $s['host'] . ':' . ($s['puerto'] ?? 465) : "$errstr ($errno)"];
    }
}
