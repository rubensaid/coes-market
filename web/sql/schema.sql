-- Esquema de Cuanto COESta en MySQL / MariaDB. Se ejecuta con instalar.php (es idempotente).
-- Poco espacio: una fila comprimida por semana, la serie CMg de la barra de referencia y Ahora de esa barra.

CREATE TABLE IF NOT EXISTS semanas (
    semana CHAR(8) NOT NULL PRIMARY KEY,           -- 2026-W40
    desde DATE NOT NULL,
    hasta DATE NOT NULL,
    es_preliminar TINYINT(1) NOT NULL DEFAULT 0,
    generado_en DATETIME NULL,                      -- cuando Python armo el paquete (hora Peru)
    recibido_en DATETIME NOT NULL,
    tiene_pdf TINYINT(1) NOT NULL DEFAULT 0,
    paquete MEDIUMBLOB NOT NULL                     -- JSON comprimido con gzip
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- CMg de la barra de referencia cada 30 min (fin del intervalo). estado: 0 confirmado, 1 preliminar
CREATE TABLE IF NOT EXISTS cmg_ref (
    fecha_hora DATETIME NOT NULL PRIMARY KEY,
    total DECIMAL(10,2) NULL,
    energia DECIMAL(10,2) NULL,
    congestion DECIMAL(10,2) NULL,
    estado TINYINT NOT NULL DEFAULT 0,
    semana CHAR(8) NOT NULL,
    KEY idx_cmg_ref_semana (semana)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tipo_cambio (
    fecha DATE NOT NULL PRIMARY KEY,
    tc DECIMAL(8,4) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- "Ahora": datos en vivo versionados (si el COES cambia un valor, se agrega una version nueva)
CREATE TABLE IF NOT EXISTS ahora_cmg (
    fecha_hora DATETIME NOT NULL,
    version SMALLINT NOT NULL,
    cmg_total DECIMAL(10,2) NULL,
    cmg_energia DECIMAL(10,2) NULL,
    cmg_congestion DECIMAL(10,2) NULL,
    correlativo INT NULL,
    extraido_en DATETIME NOT NULL,
    PRIMARY KEY (fecha_hora, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ahora_demanda (
    fecha_hora DATETIME NOT NULL,
    variable VARCHAR(20) NOT NULL,                  -- ejecutada, programa_diario
    version SMALLINT NOT NULL,
    valor DECIMAL(10,2) NULL,
    extraido_en DATETIME NOT NULL,
    PRIMARY KEY (fecha_hora, variable, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Costo marginal programado (hoy y manana) en US$/MWh; se reemplaza en cada consulta
CREATE TABLE IF NOT EXISTS ahora_programa (
    fecha_hora DATETIME NOT NULL PRIMARY KEY,
    cmg_usd DECIMAL(10,2) NULL,
    actualizado_en DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS extracciones (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    fuente VARCHAR(40) NOT NULL,
    inicio DATETIME NULL,
    fin DATETIME NULL,
    ok TINYINT(1) NOT NULL,
    nuevos INT NOT NULL DEFAULT 0,
    revisados INT NOT NULL DEFAULT 0,
    detalle VARCHAR(500) NOT NULL DEFAULT '',
    creado_en DATETIME NOT NULL,
    KEY idx_extracciones_fuente (fuente, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS estado_fuentes (
    fuente VARCHAR(40) NOT NULL PRIMARY KEY,
    ultimo_intento DATETIME NULL,
    ultima_extraccion_exitosa DATETIME NULL,
    ultimo_error VARCHAR(500) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS envios_correo (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    semana CHAR(8) NOT NULL,
    destinatarios VARCHAR(500) NOT NULL,
    ok TINYINT(1) NOT NULL,
    detalle VARCHAR(500) NOT NULL DEFAULT '',
    enviado_en DATETIME NOT NULL,
    KEY idx_envios_semana (semana)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ajustes (
    clave VARCHAR(40) NOT NULL PRIMARY KEY,
    valor VARCHAR(500) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
