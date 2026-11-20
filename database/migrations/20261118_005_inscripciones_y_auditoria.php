<?php
declare(strict_types=1);

return [
    'id' => '20261118_005_inscripciones_y_auditoria',
    'description' => 'Agrega inscripciones explícitas a clases y bitácora de acciones sensibles',
    'transactional' => false,
    // Todas las sentencias son idempotentes: si la migración se interrumpe, "recover" permite repetirla desde el inicio.
    'retry_safe' => true,
    // Checksums (LF y CRLF) de la versión publicada anteriormente, que usaba ON UPDATE CASCADE. Quien ya la ejecutó
    // con éxito en otro motor la conserva como registrada; no se vuelve a ejecutar ni se considera modificada.
    'supersedes_checksums' => [
        '0c47f1d7cff826da31f1db056bf12700b5e4fc13efaf38cf7c7e23b3da1d20f7',
        '4de53cbd1002ca46a037f895cd2d8d9cccd2d88eb1a71b90e476964e1c8d09f7',
    ],
    'statements' => [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS clases_alumnos (
    id_inscripcion INT(11) NOT NULL AUTO_INCREMENT,
    id_clase INT(20) NOT NULL,
    id_alumno INT(20) NOT NULL,
    fecha_inscripcion DATE NOT NULL,
    fecha_baja DATE NULL,
    estado ENUM('activa','inactiva') NOT NULL DEFAULT 'activa',
    id_alumno_activo INT(20) GENERATED ALWAYS AS (IF(estado = 'activa', id_alumno, NULL)) PERSISTENT,
    id_usuario_registra INT(20) NOT NULL,
    PRIMARY KEY (id_inscripcion),
    UNIQUE KEY uq_clase_alumno_activo (id_clase, id_alumno_activo),
    KEY idx_clases_alumnos_alumno_estado (id_alumno, estado),
    CONSTRAINT fk_clase_alumno_clase FOREIGN KEY (id_clase) REFERENCES clases (id_clase) ON UPDATE CASCADE ON DELETE RESTRICT,
    -- MariaDB rechaza (error 1901) ON UPDATE CASCADE sobre id_alumno, base de la columna generada id_alumno_activo.
    CONSTRAINT fk_clase_alumno_alumno FOREIGN KEY (id_alumno) REFERENCES alumnos (id_alumno) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_clase_alumno_usuario FOREIGN KEY (id_usuario_registra) REFERENCES usuarios (id_usuario) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS auditoria_eventos (
    id_evento BIGINT(20) NOT NULL AUTO_INCREMENT,
    id_usuario INT(20) NOT NULL,
    accion VARCHAR(80) NOT NULL,
    entidad VARCHAR(80) NOT NULL,
    id_entidad VARCHAR(64) NOT NULL,
    detalles TEXT NULL,
    fecha_hora DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_evento),
    KEY idx_auditoria_entidad (entidad, id_entidad, fecha_hora),
    KEY idx_auditoria_usuario_fecha (id_usuario, fecha_hora),
    CONSTRAINT fk_auditoria_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios (id_usuario) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci
SQL,
    ],
];
