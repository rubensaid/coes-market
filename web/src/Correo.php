<?php
declare(strict_types=1);

namespace CuantoCoesta;

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

/** Envio del PDF semanal por SMTP (cuenta de correo creada en cPanel). */
final class Correo
{
    public static function configurado(): bool
    {
        $l = Config::local();
        $s = $l['smtp'] ?? [];
        return !empty($s['host']) && !empty($s['usuario']) && !empty($s['clave']) && !str_contains((string)$s['clave'], 'CAMBIAR')
            && !empty($l['correo']['destinatarios']);
    }

    /** Asunto y cuerpo en texto plano con los titulares de la semana. */
    public static function redactar(string $semana, array $datos): array
    {
        $p = Config::proyecto();
        $url = rtrim((string)(Config::local()['url_sitio'] ?? ''), '/');
        $asunto = $p['nombre'] . ': ' . ($datos['texto_semana'] ?? $semana) . ($datos['es_preliminar'] ?? false ? ' (preliminar)' : '');
        $lineas = ['Hola,', '', 'Adjunto el reporte semanal. ' . ($datos['texto_semana'] ?? $semana) . '.', ''];
        if (!empty($datos['es_preliminar'])) {
            $lineas[] = 'Ojo: es preliminar. Hoy solo hay ' . (int)($datos['dias_con_datos'] ?? 0) . ' de 7 días con datos completos.';
            $lineas[] = '';
        }
        $lineas[] = 'Lo más importante:';
        foreach ($datos['narrativa']['titulares'] ?? [] as $t) {
            $lineas[] = '- ' . $t;
        }
        if ($url !== '') {
            $lineas[] = '';
            $lineas[] = "Para ver el detalle: $url/?semana=$semana";
        }
        $lineas[] = '';
        $lineas[] = Config::aviso();
        return [$asunto, implode("\n", $lineas)];
    }

    /**
     * Envia el PDF de la semana a los destinatarios configurados.
     * @return array{ok: bool, detalle: string}
     */
    public static function enviarSemana(string $semana, ?array $datos = null, ?array $destinatarios = null): array
    {
        if (!Semanas::valida($semana)) {
            return ['ok' => false, 'detalle' => 'Semana no valida.'];
        }
        $destinatarios ??= Config::local()['correo']['destinatarios'] ?? [];
        if (!self::configurado()) {
            return self::registrar($semana, $destinatarios, false, 'Correo no configurado (secciones smtp y correo de config/local.php).');
        }
        $pdf = Config::rutaPdf($semana);
        if (!is_file($pdf)) {
            return self::registrar($semana, $destinatarios, false, 'No hay PDF para esa semana.');
        }
        $datos ??= Semanas::obtener($semana) ?? [];
        [$asunto, $cuerpo] = self::redactar($semana, $datos);
        try {
            $mail = self::crear();
            foreach ($destinatarios as $d) {
                $mail->addAddress($d);
            }
            $mail->Subject = $asunto;
            $mail->Body = $cuerpo;
            $mail->addAttachment($pdf, basename($pdf), PHPMailer::ENCODING_BASE64, 'application/pdf');
            $mail->send();
            return self::registrar($semana, $destinatarios, true, 'Enviado.');
        } catch (MailException $e) {
            return self::registrar($semana, $destinatarios, false, mb_substr($e->getMessage(), 0, 400));
        }
    }

    /** Prueba de SMTP sin adjunto: lo usa el panel de administracion y diagnostico.php. */
    public static function probar(): array
    {
        if (!self::configurado()) {
            return ['ok' => false, 'detalle' => 'Correo no configurado en config/local.php.'];
        }
        try {
            $mail = self::crear();
            foreach (Config::local()['correo']['destinatarios'] as $d) {
                $mail->addAddress($d);
            }
            $mail->Subject = Config::proyecto()['nombre'] . ': prueba de correo';
            $mail->Body = "Si lees esto, el envío del reporte semanal está bien configurado.\n\n" . Config::aviso();
            $mail->send();
            return ['ok' => true, 'detalle' => 'Correo de prueba enviado.'];
        } catch (MailException $e) {
            return ['ok' => false, 'detalle' => mb_substr($e->getMessage(), 0, 400)];
        }
    }

    private static function crear(): PHPMailer
    {
        $l = Config::local();
        $s = $l['smtp'];
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $s['host'];
        $mail->Port = (int)($s['puerto'] ?? 465);
        $mail->SMTPAuth = true;
        $mail->Username = $s['usuario'];
        $mail->Password = $s['clave'];
        $seguridad = $s['seguridad'] ?? 'ssl';
        if ($seguridad === 'none') {          // solo para pruebas locales
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
            $mail->SMTPAuth = false;
        } else {
            $mail->SMTPSecure = $seguridad === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
        }
        $mail->Timeout = 30;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->setFrom($s['remitente'] ?? $s['usuario'], $s['nombre_remitente'] ?? Config::proyecto()['nombre']);
        $mail->isHTML(false);
        return $mail;
    }

    private static function registrar(string $semana, array $destinatarios, bool $ok, string $detalle): array
    {
        try {
            Db::ejecutar('INSERT INTO envios_correo (semana, destinatarios, ok, detalle, enviado_en) VALUES (?, ?, ?, ?, ?)',
                [$semana, mb_substr(implode(', ', $destinatarios), 0, 500), $ok ? 1 : 0, mb_substr($detalle, 0, 500),
                    Fechas::ahora()->format('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            error_log('[coesta] no se pudo registrar el envio: ' . $e->getMessage());
        }
        return ['ok' => $ok, 'detalle' => $detalle];
    }
}
