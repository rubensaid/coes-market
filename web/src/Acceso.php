<?php
declare(strict_types=1);

namespace CuantoCoesta;

/** Acceso por clave (token de config/local.php) para paginas de mantenimiento: instalar, diagnostico y cron por URL. */
final class Acceso
{
    public static function tokenValido(string $recibido): bool
    {
        $t = (string)(Config::local()['token'] ?? '');
        return strlen($t) >= 24 && !str_contains($t, 'CAMBIAR') && hash_equals($t, $recibido);
    }

    /** Pide la clave por formulario (POST, no queda en el historial ni en logs). Termina la ejecucion si no es valida. */
    public static function exigir(string $titulo): void
    {
        if (self::tokenValido((string)($_POST['clave'] ?? ''))) {
            return;
        }
        $mal = isset($_POST['clave']);
        if ($mal) {
            usleep(700000);
            http_response_code(401);
        }
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . Formato::h($titulo) . '</title><link rel="stylesheet" href="assets/estilo.css"></head><body class="mantenimiento">'
            . '<main class="caja"><h1>' . Formato::h($titulo) . '</h1>'
            . ($mal ? '<p class="aviso-malo">Clave incorrecta.</p>' : '')
            . '<form method="post"><label for="clave">Clave de mantenimiento (la de config/local.php)</label>'
            . '<input type="password" id="clave" name="clave" autocomplete="off" required autofocus>'
            . '<button type="submit">Entrar</button></form></main></body></html>';
        exit;
    }
}
