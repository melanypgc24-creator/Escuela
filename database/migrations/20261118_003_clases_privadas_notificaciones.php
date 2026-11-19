<?php
declare(strict_types=1);

return [
    'id' => '20261118_003_clases_privadas_notificaciones',
    'description' => 'Agrega sesiones privadas, participantes y notificaciones internas',
    'transactional' => false,
    'statements' => [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS clases_privadas (
    id_sesion INT(20) NOT NULL AUTO_INCREMENT,
    id_profesor INT(20) NOT NULL,
    fecha_hora DATETIME NOT NULL,
    precio_unitario DECIMAL(10,2) NOT NULL,
    cantidad_participantes INT(11) UNSIGNED NOT NULL,
    total_historico DECIMAL(10,2) NOT NULL,
    observaciones TEXT NULL,
    id_usuario_registra INT(20) NOT NULL,
    PRIMARY KEY (id_sesion),
    KEY idx_clases_privadas_fecha (fecha_hora),
    CONSTRAINT chk_clase_privada_precio CHECK (precio_unitario = 150.00),
    CONSTRAINT chk_clase_privada_participantes CHECK (cantidad_participantes > 0),
    CONSTRAINT chk_clase_privada_total CHECK (total_historico = precio_unitario * cantidad_participantes),
    CONSTRAINT fk_clase_privada_profesor FOREIGN KEY (id_profesor) REFERENCES profesor (id_profesor) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_clase_privada_usuario FOREIGN KEY (id_usuario_registra) REFERENCES usuarios (id_usuario) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS clases_privadas_alumnos (
    id_participante INT(11) NOT NULL AUTO_INCREMENT,
    id_sesion INT(20) NOT NULL,
    id_alumno INT(20) NULL,
    nombre_participante VARCHAR(200) NOT NULL,
    precio_historico DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (id_participante),
    KEY idx_privadas_participante_sesion (id_sesion),
    KEY idx_privadas_participante_alumno (id_alumno),
    CONSTRAINT chk_privada_participante_precio CHECK (precio_historico = 150.00),
    CONSTRAINT fk_privada_participante_sesion FOREIGN KEY (id_sesion) REFERENCES clases_privadas (id_sesion) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_privada_participante_alumno FOREIGN KEY (id_alumno) REFERENCES alumnos (id_alumno) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci
SQL,
        <<<'SQL'
ALTER TABLE pagos
    ADD COLUMN IF NOT EXISTS sesion_privada_activa INT(20)
        GENERATED ALWAYS AS (IF(tipo_pago = 'clase_privada' AND estado = 'activo', id_sesion_privada, NULL)) PERSISTENT,
    ADD UNIQUE INDEX IF NOT EXISTS uq_pago_privado_activo (sesion_privada_activa),
    ADD CONSTRAINT fk_pagos_sesion_privada FOREIGN KEY (id_sesion_privada) REFERENCES clases_privadas (id_sesion) ON UPDATE CASCADE ON DELETE RESTRICT
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS notificaciones (
    id_notificacion INT(11) NOT NULL AUTO_INCREMENT,
    id_usuario_destinatario INT(20) NOT NULL,
    id_cargo INT(11) NOT NULL,
    tipo ENUM('vencimiento_3_dias','vencimiento_hoy','cargo_cubierto') NOT NULL,
    fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    leida TINYINT(1) NOT NULL DEFAULT 0,
    estado ENUM('activa','cerrada') NOT NULL DEFAULT 'activa',
    PRIMARY KEY (id_notificacion),
    UNIQUE KEY uq_notificacion_cargo_tipo (id_cargo, tipo),
    KEY idx_notificaciones_destinatario_estado (id_usuario_destinatario, estado, leida, fecha_creacion),
    CONSTRAINT fk_notificacion_usuario FOREIGN KEY (id_usuario_destinatario) REFERENCES usuarios (id_usuario) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_notificacion_cargo FOREIGN KEY (id_cargo) REFERENCES cargos_mensuales (id_cargo) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci
SQL,
    ],
];
