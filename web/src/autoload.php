<?php
declare(strict_types=1);

// Autocarga sin Composer para el codigo propio; PHPMailer viene en vendor/ (subido por FTP).
spl_autoload_register(static function (string $clase): void {
    $prefijo = 'CuantoCoesta\\';
    if (strncmp($clase, $prefijo, strlen($prefijo)) !== 0) {
        return;
    }
    $ruta = __DIR__ . '/' . str_replace('\\', '/', substr($clase, strlen($prefijo))) . '.php';
    if (is_file($ruta)) {
        require $ruta;
    }
});

$vendor = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendor)) {
    require_once $vendor;
}

// Peru no tiene horario de verano: America/Lima es UTC-5 todo el anio.
date_default_timezone_set('America/Lima');
mb_internal_encoding('UTF-8');
