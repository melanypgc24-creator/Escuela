<?php
declare(strict_types=1);

return [
    'id' => '20261118_002_tablas_mensualidades_pagos',
    'description' => 'Agrega tarifas, configuración, cargos, pagos aplicados e historial',
    'transactional' => false,
    'statements' => [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS tarifas_mensuales (
    id_tarifa INT(11) NOT NULL AUTO_INCREMENT,
    categoria ENUM('infantil','adulta') NOT NULL,
    importe DECIMAL(10,2) NOT NULL,
    vigente_desde DATE NULL,
    vigente_hasta DATE NULL,
    activa TINYINT(1) NOT NULL DEFAULT 1,
    categoria_activa VARCHAR(16) GENERATED ALWAYS AS (IF(activa = 1, categoria, NULL)) PERSISTENT,
    id_usuario_crea INT(20) NULL,
    creada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_tarifa),
    UNIQUE KEY uq_tarifa_categoria_activa (categoria_activa),
    KEY idx_tarifa_vigencia (categoria, vigente_desde, vigente_hasta),
    CONSTRAINT chk_tarifa_importe CHECK (importe > 0),
    CONSTRAINT chk_tarifa_vigencia CHECK (vigente_hasta IS NULL OR vigente_desde IS NULL OR vigente_hasta >= vigente_desde),
    CONSTRAINT fk_tarifa_usuario FOREIGN KEY (id_usuario_crea) REFERENCES usuarios (id_usuario) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS configuracion_mensualidades (
    id_configuracion INT(11) NOT NULL AUTO_INCREMENT,
    dia_vencimiento TINYINT UNSIGNED NULL,
    activa TINYINT(1) NOT NULL DEFAULT 0,
    configurada_por INT(20) NULL,
    creada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    configuracion_activa TINYINT GENERATED ALWAYS AS (IF(activa = 1, 1, NULL)) PERSISTENT,
    PRIMARY KEY (id_configuracion),
    UNIQUE KEY uq_configuracion_mensual_activa (configuracion_activa),
    CONSTRAINT chk_configuracion_dia_vencimiento CHECK (dia_vencimiento IS NULL OR dia_vencimiento BETWEEN 1 AND 31),
    CONSTRAINT fk_configuracion_mensual_usuario FOREIGN KEY (configurada_por) REFERENCES usuarios (id_usuario) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS cargos_mensuales (
    id_cargo INT(11) NOT NULL AUTO_INCREMENT,
    id_alumno INT(20) NOT NULL,
    periodo CHAR(7) NOT NULL,
    id_tarifa INT(11) NOT NULL,
    categoria ENUM('infantil','adulta') NOT NULL,
    importe_tarifa DECIMAL(10,2) NOT NULL,
    porcentaje DECIMAL(5,2) NOT NULL,
    importe DECIMAL(10,2) NOT NULL,
    fecha_vencimiento DATE NULL,
    estado ENUM('pendiente','pagado','vencido') NOT NULL DEFAULT 'pendiente',
    generado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    id_usuario_generador INT(20) NOT NULL,
    PRIMARY KEY (id_cargo),
    UNIQUE KEY uq_cargo_alumno_periodo (id_alumno, periodo),
    UNIQUE KEY uq_cargo_id_alumno (id_cargo, id_alumno),
    KEY idx_cargos_periodo_estado (periodo, estado),
    KEY idx_cargos_vencimiento (fecha_vencimiento, estado),
    CONSTRAINT chk_cargo_periodo CHECK (periodo REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$'),
    CONSTRAINT chk_cargo_importes CHECK (importe_tarifa > 0 AND porcentaje > 0 AND porcentaje <= 100 AND importe > 0),
    CONSTRAINT fk_cargo_alumno FOREIGN KEY (id_alumno) REFERENCES alumnos (id_alumno) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_cargo_tarifa FOREIGN KEY (id_tarifa) REFERENCES tarifas_mensuales (id_tarifa) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_cargo_usuario FOREIGN KEY (id_usuario_generador) REFERENCES usuarios (id_usuario) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci
SQL,
        <<<'SQL'
ALTER TABLE pagos
    MODIFY id_alumno INT(20) NULL,
    ADD COLUMN IF NOT EXISTS folio VARCHAR(32) NULL,
    ADD COLUMN IF NOT EXISTS tipo_pago ENUM('mensualidad','clase_privada','otro') NULL,
    ADD COLUMN IF NOT EXISTS estado ENUM('activo','cancelado') NULL,
    ADD COLUMN IF NOT EXISTS nombre_pagador VARCHAR(200) NULL,
    ADD COLUMN IF NOT EXISTS id_alumno_pagador INT(20) NULL,
    ADD COLUMN IF NOT EXISTS id_usuario_registra INT(20) NULL,
    ADD COLUMN IF NOT EXISTS id_sesion_privada INT(20) NULL,
    ADD UNIQUE INDEX IF NOT EXISTS uq_pagos_folio (folio),
    ADD UNIQUE INDEX IF NOT EXISTS uq_pagos_id_alumno (id_pago, id_alumno),
    ADD INDEX IF NOT EXISTS idx_pagos_tipo_estado_fecha (tipo_pago, estado, fecha_pago),
    ADD CONSTRAINT fk_pagos_alumno_pagador FOREIGN KEY (id_alumno_pagador) REFERENCES alumnos (id_alumno) ON UPDATE CASCADE ON DELETE SET NULL,
    ADD CONSTRAINT fk_pagos_usuario_registra FOREIGN KEY (id_usuario_registra) REFERENCES usuarios (id_usuario) ON UPDATE CASCADE ON DELETE RESTRICT
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS aplicaciones_pagos (
    id_aplicacion INT(11) NOT NULL AUTO_INCREMENT,
    id_pago INT(50) NOT NULL,
    id_cargo INT(11) NOT NULL,
    id_alumno INT(20) NOT NULL,
    importe_aplicado DECIMAL(10,2) NOT NULL,
    aplicada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_aplicacion),
    UNIQUE KEY uq_aplicacion_pago_cargo (id_pago, id_cargo),
    KEY idx_aplicaciones_cargo_alumno (id_cargo, id_alumno),
    KEY idx_aplicaciones_pago_alumno (id_pago, id_alumno),
    CONSTRAINT chk_aplicacion_importe CHECK (importe_aplicado > 0),
    CONSTRAINT fk_aplicacion_pago_alumno FOREIGN KEY (id_pago, id_alumno) REFERENCES pagos (id_pago, id_alumno) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_aplicacion_cargo_alumno FOREIGN KEY (id_cargo, id_alumno) REFERENCES cargos_mensuales (id_cargo, id_alumno) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS historial_pagos (
    id_historial INT(11) NOT NULL AUTO_INCREMENT,
    id_pago INT(50) NOT NULL,
    estado_anterior ENUM('activo','cancelado') NULL,
    estado_nuevo ENUM('activo','cancelado') NULL,
    importe_anterior DECIMAL(10,2) NULL,
    importe_nuevo DECIMAL(10,2) NULL,
    id_usuario_responsable INT(20) NOT NULL,
    fecha_hora DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    motivo VARCHAR(255) NOT NULL,
    PRIMARY KEY (id_historial),
    KEY idx_historial_pago_fecha (id_pago, fecha_hora),
    CONSTRAINT chk_historial_importes CHECK ((importe_anterior IS NULL OR importe_anterior >= 0) AND (importe_nuevo IS NULL OR importe_nuevo >= 0)),
    CONSTRAINT fk_historial_pago FOREIGN KEY (id_pago) REFERENCES pagos (id_pago) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_historial_usuario FOREIGN KEY (id_usuario_responsable) REFERENCES usuarios (id_usuario) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci
SQL,
        <<<'SQL'
INSERT INTO tarifas_mensuales (categoria, importe, vigente_desde, activa, id_usuario_crea)
SELECT 'infantil', 900.00, NULL, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM tarifas_mensuales WHERE categoria = 'infantil' AND activa = 1)
SQL,
        <<<'SQL'
INSERT INTO tarifas_mensuales (categoria, importe, vigente_desde, activa, id_usuario_crea)
SELECT 'adulta', 1800.00, NULL, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM tarifas_mensuales WHERE categoria = 'adulta' AND activa = 1)
SQL,
        <<<'SQL'
INSERT INTO configuracion_mensualidades (dia_vencimiento, activa, configurada_por)
SELECT NULL, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM configuracion_mensualidades WHERE activa = 1)
SQL,
    ],
];
