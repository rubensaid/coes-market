<?php
declare(strict_types=1);

// Panel de administracion: estado, reenviar el PDF por correo, probar el correo, actualizar Ahora y borrar semanas.
require __DIR__ . '/src/autoload.php';

use CuantoCoesta\{Ahora\Ciclo, Config, Correo, Db, Fechas, Semanas, Ui};

session_name('coesta_admin');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
header('Content-Type: text/html; charset=utf-8');

$admin = Config::local()['admin'] ?? [];
$mensaje = '';
$accion = (string)($_POST['accion'] ?? '');

function pantalla(string $titulo, string $cuerpo): never
{
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . Ui::h($titulo)
        . '</title><link rel="stylesheet" href="assets/estilo.css"></head><body class="mantenimiento"><main class="caja ancha admin">' . $cuerpo . '</main></body></html>';
    exit;
}

if ($accion === 'entrar') {
    $ok = !empty($admin['clave_hash']) && !str_contains((string)$admin['clave_hash'], 'CAMBIAR')
        && hash_equals((string)($admin['usuario'] ?? ''), (string)($_POST['usuario'] ?? '')) && password_verify((string)($_POST['clave'] ?? ''), (string)$admin['clave_hash']);
    if ($ok) {
        session_regenerate_id(true);
        $_SESSION['ok'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    } else {
        usleep(800000);
        $mensaje = 'Usuario o clave incorrectos.';
    }
}

if (empty($_SESSION['ok'])) {
    pantalla('Administración', '<h1>Administración</h1>' . ($mensaje ? '<p class="aviso-malo">' . Ui::h($mensaje) . '</p>' : '')
        . '<form method="post"><input type="hidden" name="accion" value="entrar"><label for="u">Usuario</label><input type="text" id="u" name="usuario" autocomplete="username" required>'
        . '<label for="c">Clave</label><input type="password" id="c" name="clave" autocomplete="current-password" required><button type="submit">Entrar</button></form>');
}

if ($accion !== '' && $accion !== 'entrar') {
    if (!hash_equals((string)$_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        pantalla('Administración', '<p class="aviso-malo">La sesión expiró. Recarga la página.</p>');
    }
    $semana = (string)($_POST['semana'] ?? '');
    try {
        switch ($accion) {
            case 'salir':
                session_destroy();
                header('Location: admin.php');
                exit;
            case 'reenviar':
                $r = Semanas::valida($semana) ? Correo::enviarSemana($semana) : ['ok' => false, 'detalle' => 'Semana no válida.'];
                $mensaje = ($r['ok'] ? 'Correo enviado: ' : 'No se pudo enviar: ') . $r['detalle'];
                break;
            case 'probar':
                $r = Correo::probar();
                $mensaje = ($r['ok'] ? '' : 'Falló: ') . $r['detalle'];
                break;
            case 'ahora':
                $r = Ciclo::correr(false, false);
                $mensaje = 'Consulta de Ahora: ' . json_encode($r, JSON_UNESCAPED_UNICODE);
                break;
            case 'borrar':
                if (Semanas::valida($semana)) {
                    Db::ejecutar('DELETE FROM semanas WHERE semana = ?', [$semana]);
                    Db::ejecutar('DELETE FROM cmg_ref WHERE semana = ?', [$semana]);
                    $pdf = Config::rutaPdf($semana);
                    if (is_file($pdf)) {
                        unlink($pdf);
                    }
                    $mensaje = "Semana $semana borrada.";
                }
                break;
        }
    } catch (\Throwable $e) {
        error_log('[coesta] admin: ' . $e->getMessage());
        $mensaje = 'Ocurrió un error: ' . $e->getMessage();
    }
}

$csrf = Ui::h($_SESSION['csrf']);
$form = static fn(string $acc, string $texto, string $semana = '', string $clase = '') => '<form method="post" class="en-linea"><input type="hidden" name="csrf" value="' . $csrf
    . '"><input type="hidden" name="accion" value="' . $acc . '">' . ($semana ? '<input type="hidden" name="semana" value="' . Ui::h($semana) . '">' : '')
    . '<button class="boton ' . $clase . '" type="submit">' . Ui::h($texto) . '</button></form>';

$semanas = Semanas::listar();
$filas = '';
foreach ($semanas as $s) {
    $filas .= '<tr><td><b>' . Ui::h($s['semana']) . '</b></td><td>' . Ui::h($s['desde']) . ' a ' . Ui::h($s['hasta']) . '</td><td>' . ($s['es_preliminar'] ? 'preliminar' : 'completa') . '</td>'
        . '<td>' . (Semanas::tienePdf($s['semana']) ? 'sí' : 'no') . '</td><td>' . Ui::h($s['recibido_en']) . '</td><td>'
        . $form('reenviar', 'Reenviar PDF', $s['semana'], 'sec') . $form('borrar', 'Borrar', $s['semana'], 'sec') . '</td></tr>';
}
$ext = '';
foreach (Db::filas('SELECT fuente, ok, nuevos, revisados, detalle, creado_en FROM extracciones ORDER BY id DESC LIMIT 10') as $e) {
    $ext .= '<tr class="' . ($e['ok'] ? '' : 'error') . '"><td>' . Ui::h($e['creado_en']) . '</td><td>' . Ui::h($e['fuente']) . '</td><td>' . ($e['ok'] ? 'ok' : 'falló') . '</td><td>'
        . (int)$e['nuevos'] . ' nuevos, ' . (int)$e['revisados'] . ' revisados</td><td>' . Ui::h($e['detalle']) . '</td></tr>';
}
$env = '';
foreach (Db::filas('SELECT semana, destinatarios, ok, detalle, enviado_en FROM envios_correo ORDER BY id DESC LIMIT 10') as $e) {
    $env .= '<tr class="' . ($e['ok'] ? '' : 'error') . '"><td>' . Ui::h($e['enviado_en']) . '</td><td>' . Ui::h($e['semana']) . '</td><td>' . Ui::h($e['destinatarios']) . '</td><td>'
        . ($e['ok'] ? 'enviado' : 'falló') . '</td><td>' . Ui::h($e['detalle']) . '</td></tr>';
}
pantalla('Administración', '<h1>Administración</h1><p><a href="index.php">Ver el sitio</a> · <a href="diagnostico.php">Diagnóstico</a></p>'
    . ($mensaje ? '<p class="aviso-bueno">' . Ui::h($mensaje) . '</p>' : '')
    . '<p>Correo del reporte: ' . (Correo::configurado() ? 'configurado (' . Ui::h(implode(', ', Config::local()['correo']['destinatarios'])) . ')' : '<b>no configurado</b>') . '</p>'
    . $form('probar', 'Enviar correo de prueba', '', 'sec') . $form('ahora', 'Consultar Ahora al COES', '', 'sec') . $form('salir', 'Cerrar sesión', '', 'sec')
    . '<h2>Semanas publicadas</h2><div class="tabla-scroll"><table class="tabla admin-tabla"><thead><tr><th>Semana</th><th>Rango</th><th>Estado</th><th>PDF</th><th>Recibida</th><th></th></tr></thead><tbody>' . $filas . '</tbody></table></div>'
    . '<h2>Últimas consultas al COES</h2><div class="tabla-scroll"><table class="tabla"><thead><tr><th>Cuándo</th><th>Fuente</th><th></th><th>Resultado</th><th>Detalle</th></tr></thead><tbody>' . $ext . '</tbody></table></div>'
    . '<h2>Últimos correos</h2><div class="tabla-scroll"><table class="tabla"><thead><tr><th>Cuándo</th><th>Semana</th><th>Para</th><th></th><th>Detalle</th></tr></thead><tbody>' . $env . '</tbody></table></div>'
    . '<p class="nota" style="color:var(--c-gris)">' . Ui::h(Config::aviso()) . '</p>');
