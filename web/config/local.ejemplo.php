<?php
// Copia este archivo como config/local.php y completa tus datos. Nunca lo subas a git.
// Tambien guarda aqui la clave que usara Python (COESTA_HOSTING_TOKEN en el workflow o en tu PC).
return [
    // Base de datos creada en cPanel > Bases de datos MySQL (el nombre lleva el prefijo de tu cuenta)
    'db' => [
        'host' => 'localhost',
        'puerto' => 3306,
        'nombre' => 'micuenta_coesta',
        'usuario' => 'micuenta_coesta',
        'clave' => 'CAMBIAR',
    ],

    // Clave larga y aleatoria (24 caracteres o mas) para subidas, instalador, diagnostico y cron por URL.
    // Genera una con: php -r "echo bin2hex(random_bytes(24));"
    'token' => 'CAMBIAR_POR_UNA_CLAVE_LARGA_Y_ALEATORIA',

    // Panel de administracion (/admin.php). Genera el hash con:
    //   php -r "echo password_hash('tu_clave', PASSWORD_DEFAULT);"
    'admin' => [
        'usuario' => 'admin',
        'clave_hash' => 'CAMBIAR',
    ],

    // Correo del PDF semanal: cuenta creada en cPanel > Cuentas de correo (datos en "Configurar cliente de correo")
    'smtp' => [
        'host' => 'mail.tudominio.com',
        'puerto' => 465,
        'seguridad' => 'ssl',            // ssl (465) o tls (587)
        'usuario' => 'reportes@tudominio.com',
        'clave' => 'CAMBIAR',
        'remitente' => 'reportes@tudominio.com',
        'nombre_remitente' => 'Cuánto COESta',
    ],
    'correo' => [
        'destinatarios' => ['tu_correo@ejemplo.com'],
    ],

    // Direccion publica del sitio (para el enlace del correo). Sin barra final.
    'url_sitio' => 'https://tudominio.com/coesta',

    // 0 = conservar todas las semanas; N = solo las ultimas N semanas
    'conservar_semanas' => 0,
];
