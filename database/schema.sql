-- =====================================================================
-- Escuela de Jiu-Jitsu (Team Cats) — Esquema completo para instalación NUEVA
-- =====================================================================
--
-- Este archivo crea la base de datos completa tal como queda DESPUÉS de
-- aplicar las migraciones 20261118_001 ... 20261126_010. Úsalo solo
-- para una instalación nueva, sin datos previos.
--
-- Si ya tienes una base con alumnos, pagos, etc., NO uses este archivo.
-- Consulta las advertencias de migración del README antes de actualizar.
--
-- Requisitos: MariaDB 10.11+ (o compatible), PHP 8+, pdo_mysql.
--
-- Uso:
--   mysql -u root -p < database/schema.sql
-- o importa este archivo desde phpMyAdmin en una base vacía llamada
-- `escuela` (o ajusta USE/CREATE DATABASE con el nombre que prefieras).
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'NO_AUTO_VALUE_ON_ZERO');

CREATE DATABASE IF NOT EXISTS `escuela` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci;
USE `escuela`;

-- ---------------------------------------------------------------------
-- Catálogos base
-- ---------------------------------------------------------------------

CREATE TABLE `cinturon` (
    `id_cinturon` INT(11) NOT NULL AUTO_INCREMENT,
    `color` VARCHAR(50) NOT NULL,
    PRIMARY KEY (`id_cinturon`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE `profesor` (
    `id_profesor` INT(20) NOT NULL AUTO_INCREMENT,
    `nombre` VARCHAR(50) NOT NULL,
    `apellidos` VARCHAR(100) NOT NULL,
    `telefono` VARCHAR(20) NOT NULL,
    PRIMARY KEY (`id_profesor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- ---------------------------------------------------------------------
-- Alumnos
-- ---------------------------------------------------------------------

CREATE TABLE `alumnos` (
    `id_alumno` INT(20) NOT NULL AUTO_INCREMENT,
    `matricula` VARCHAR(20) NOT NULL,
    `nombre` VARCHAR(100) NOT NULL,
    `apellidos` VARCHAR(100) NOT NULL,
    `telefono` VARCHAR(20) NOT NULL,
    `correo_contacto` VARCHAR(150) NULL,
    `direccion` VARCHAR(255) NULL,
    `fecha_nacimiento` DATE NOT NULL,
    `categoria` ENUM('infantil','adulta') NULL,
    `contacto_emergencia_nombre` VARCHAR(150) NULL,
    `contacto_emergencia_telefono` VARCHAR(20) NULL,
    `fecha_ingreso` DATE NOT NULL,
    `estatus` TINYINT(1) NOT NULL,
    `id_cinturon` INT(11) DEFAULT NULL,
    PRIMARY KEY (`id_alumno`),
    UNIQUE KEY `uq_alumnos_matricula` (`matricula`),
    KEY `idx_alumnos_cinturon` (`id_cinturon`),
    CONSTRAINT `fk_alumnos_cinturon` FOREIGN KEY (`id_cinturon`) REFERENCES `cinturon` (`id_cinturon`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- ---------------------------------------------------------------------
-- Usuarios (login web/API)
-- ---------------------------------------------------------------------

CREATE TABLE `usuarios` (
    `id_usuario` INT(20) NOT NULL AUTO_INCREMENT,
    `correo` VARCHAR(100) NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `rol` ENUM('administrador','director','maestro','recepcionista','alumno') NOT NULL,
    `id_alumno` INT(20) DEFAULT NULL,
    `id_profesor` INT(20) DEFAULT NULL,
    `activo` TINYINT(1) NOT NULL DEFAULT 1,
    `nombre` VARCHAR(100) DEFAULT NULL,
    `apellidos` VARCHAR(100) DEFAULT NULL,
    `telefono` VARCHAR(20) DEFAULT NULL,
    `fecha_nacimiento` DATE DEFAULT NULL,
    PRIMARY KEY (`id_usuario`),
    UNIQUE KEY `uq_usuarios_correo` (`correo`),
    KEY `idx_usuarios_alumno` (`id_alumno`),
    KEY `idx_usuarios_profesor` (`id_profesor`),
    CONSTRAINT `fk_usuarios_alumno` FOREIGN KEY (`id_alumno`) REFERENCES `alumnos` (`id_alumno`) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_usuarios_profesor` FOREIGN KEY (`id_profesor`) REFERENCES `profesor` (`id_profesor`) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- ---------------------------------------------------------------------
-- Clases, inscripciones y asistencias
-- ---------------------------------------------------------------------

CREATE TABLE `clases` (
    `id_clase` INT(20) NOT NULL AUTO_INCREMENT,
    `nombre_clase` VARCHAR(100) NOT NULL,
    `categoria` ENUM('infantil','adulta') NULL,
    `id_profesor` INT(20) NOT NULL,
    `dias` VARCHAR(50) NOT NULL,
    `hora_inicio` TIME NOT NULL,
    `hora_fin` TIME NOT NULL,
    `capacidad` INT(11) NOT NULL,
    `costo` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (`id_clase`),
    KEY `idx_clases_profesor` (`id_profesor`),
    CONSTRAINT `fk_clases_profesor` FOREIGN KEY (`id_profesor`) REFERENCES `profesor` (`id_profesor`) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE `inscripciones_clases` (
    `id_inscripcion` INT(20) NOT NULL AUTO_INCREMENT,
    `id_alumno` INT(20) NOT NULL,
    `id_clase` INT(20) NOT NULL,
    `estado` ENUM('pendiente','aceptada','rechazada','cancelada') NOT NULL DEFAULT 'pendiente',
    `fecha_solicitud` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `fecha_resolucion` DATETIME DEFAULT NULL,
    `revisado_por_profesor` INT(20) DEFAULT NULL,
    PRIMARY KEY (`id_inscripcion`),
    UNIQUE KEY `uq_alumno_clase` (`id_alumno`,`id_clase`),
    KEY `idx_clase_estado` (`id_clase`,`estado`),
    KEY `fk_insc_profesor` (`revisado_por_profesor`),
    CONSTRAINT `fk_inscripcion_alumno` FOREIGN KEY (`id_alumno`) REFERENCES `alumnos` (`id_alumno`) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_inscripcion_clase` FOREIGN KEY (`id_clase`) REFERENCES `clases` (`id_clase`) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_inscripcion_profesor_revisa` FOREIGN KEY (`revisado_por_profesor`) REFERENCES `profesor` (`id_profesor`) ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE `clases_alumnos` (
    `id_inscripcion` INT(11) NOT NULL AUTO_INCREMENT,
    `id_clase` INT(20) NOT NULL,
    `id_alumno` INT(20) NOT NULL,
    `fecha_inscripcion` DATE NOT NULL,
    `fecha_baja` DATE NULL,
    `estado` ENUM('activa','inactiva') NOT NULL DEFAULT 'activa',
    `id_alumno_activo` INT(20) GENERATED ALWAYS AS (IF(`estado` = 'activa', `id_alumno`, NULL)) PERSISTENT,
    `id_usuario_registra` INT(20) NOT NULL,
    PRIMARY KEY (`id_inscripcion`),
    UNIQUE KEY `uq_clase_alumno_activo` (`id_clase`,`id_alumno_activo`),
    KEY `idx_clases_alumnos_alumno_estado` (`id_alumno`,`estado`),
    CONSTRAINT `fk_clase_alumno_clase` FOREIGN KEY (`id_clase`) REFERENCES `clases` (`id_clase`) ON UPDATE CASCADE ON DELETE RESTRICT,
    -- MariaDB rechaza (error 1901) ON UPDATE CASCADE sobre id_alumno, base de la columna generada id_alumno_activo.
    CONSTRAINT `fk_clase_alumno_alumno` FOREIGN KEY (`id_alumno`) REFERENCES `alumnos` (`id_alumno`) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_clase_alumno_usuario` FOREIGN KEY (`id_usuario_registra`) REFERENCES `usuarios` (`id_usuario`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE `asistencias` (
    `id_asistencia` INT(20) NOT NULL AUTO_INCREMENT,
    `id_alumno` INT(20) NOT NULL,
    `id_clase` INT(20) NOT NULL,
    `fecha` DATE NOT NULL,
    `hora` TIME NULL,
    `id_usuario_registra` INT(20) NULL,
    PRIMARY KEY (`id_asistencia`),
    UNIQUE KEY `uq_asistencia_alumno_clase_fecha` (`id_alumno`,`id_clase`,`fecha`),
    KEY `idx_asistencias_clase` (`id_clase`),
    KEY `idx_asistencias_usuario` (`id_usuario_registra`),
    CONSTRAINT `fk_asistencia_alumno` FOREIGN KEY (`id_alumno`) REFERENCES `alumnos` (`id_alumno`) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_asistencia_clase` FOREIGN KEY (`id_clase`) REFERENCES `clases` (`id_clase`) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_asistencia_usuario_registra` FOREIGN KEY (`id_usuario_registra`) REFERENCES `usuarios` (`id_usuario`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- ---------------------------------------------------------------------
-- Mensualidades, tarifas y pagos
-- ---------------------------------------------------------------------

CREATE TABLE `tarifas_mensuales` (
    `id_tarifa` INT(11) NOT NULL AUTO_INCREMENT,
    `categoria` ENUM('infantil','adulta') NOT NULL,
    `importe` DECIMAL(10,2) NOT NULL,
    `vigente_desde` DATE NULL,
    `vigente_hasta` DATE NULL,
    `activa` TINYINT(1) NOT NULL DEFAULT 1,
    `categoria_activa` VARCHAR(16) GENERATED ALWAYS AS (IF(`activa` = 1, `categoria`, NULL)) PERSISTENT,
    `id_usuario_crea` INT(20) NULL,
    `creada_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_tarifa`),
    UNIQUE KEY `uq_tarifa_categoria_activa` (`categoria_activa`),
    KEY `idx_tarifa_vigencia` (`categoria`,`vigente_desde`,`vigente_hasta`),
    CONSTRAINT `chk_tarifa_importe` CHECK (`importe` > 0),
    CONSTRAINT `chk_tarifa_vigencia` CHECK (`vigente_hasta` IS NULL OR `vigente_desde` IS NULL OR `vigente_hasta` >= `vigente_desde`),
    CONSTRAINT `fk_tarifa_usuario` FOREIGN KEY (`id_usuario_crea`) REFERENCES `usuarios` (`id_usuario`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE `configuracion_mensualidades` (
    `id_configuracion` INT(11) NOT NULL AUTO_INCREMENT,
    `dia_vencimiento` TINYINT UNSIGNED NULL,
    `activa` TINYINT(1) NOT NULL DEFAULT 0,
    `configurada_por` INT(20) NULL,
    `creada_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `configuracion_activa` TINYINT GENERATED ALWAYS AS (IF(`activa` = 1, 1, NULL)) PERSISTENT,
    PRIMARY KEY (`id_configuracion`),
    UNIQUE KEY `uq_configuracion_mensual_activa` (`configuracion_activa`),
    CONSTRAINT `chk_configuracion_dia_vencimiento` CHECK (`dia_vencimiento` IS NULL OR `dia_vencimiento` BETWEEN 1 AND 31),
    CONSTRAINT `fk_configuracion_mensual_usuario` FOREIGN KEY (`configurada_por`) REFERENCES `usuarios` (`id_usuario`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE `cargos_mensuales` (
    `id_cargo` INT(11) NOT NULL AUTO_INCREMENT,
    `id_alumno` INT(20) NOT NULL,
    `periodo` CHAR(7) NOT NULL,
    `concepto` ENUM('mensualidad','clase','clase_individual','otro') NOT NULL DEFAULT 'mensualidad',
    `id_tarifa` INT(11) NOT NULL,
    `categoria` ENUM('infantil','adulta') NOT NULL,
    `importe_tarifa` DECIMAL(10,2) NOT NULL,
    `porcentaje` DECIMAL(5,2) NOT NULL,
    `importe` DECIMAL(10,2) NOT NULL,
    `fecha_vencimiento` DATE NULL,
    `estado` ENUM('pendiente','pagado','vencido') NOT NULL DEFAULT 'pendiente',
    `generado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `id_usuario_generador` INT(20) NOT NULL,
    `clave_mensualidad` VARCHAR(32) GENERATED ALWAYS AS (IF(`concepto` = 'mensualidad', CONCAT(`id_alumno`, '-', RTRIM(`periodo`)), NULL)) PERSISTENT,
    PRIMARY KEY (`id_cargo`),
    UNIQUE KEY `uq_cargo_mensualidad` (`clave_mensualidad`),
    KEY `idx_cargo_alumno_periodo` (`id_alumno`,`periodo`),
    UNIQUE KEY `uq_cargo_id_alumno` (`id_cargo`,`id_alumno`),
    KEY `idx_cargos_periodo_estado` (`periodo`,`estado`),
    KEY `idx_cargos_vencimiento` (`fecha_vencimiento`,`estado`),
    CONSTRAINT `chk_cargo_periodo` CHECK (`periodo` REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$'),
    CONSTRAINT `chk_cargo_importes` CHECK (`importe_tarifa` > 0 AND `porcentaje` > 0 AND `porcentaje` <= 100 AND `importe` > 0),
    CONSTRAINT `fk_cargo_alumno` FOREIGN KEY (`id_alumno`) REFERENCES `alumnos` (`id_alumno`) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_cargo_tarifa` FOREIGN KEY (`id_tarifa`) REFERENCES `tarifas_mensuales` (`id_tarifa`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_cargo_usuario` FOREIGN KEY (`id_usuario_generador`) REFERENCES `usuarios` (`id_usuario`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE `clases_privadas` (
    `id_sesion` INT(20) NOT NULL AUTO_INCREMENT,
    `id_profesor` INT(20) NOT NULL,
    `fecha_hora` DATETIME NOT NULL,
    `precio_unitario` DECIMAL(10,2) NOT NULL,
    `cantidad_participantes` INT(11) UNSIGNED NOT NULL,
    `total_historico` DECIMAL(10,2) NOT NULL,
    `observaciones` TEXT NULL,
    `id_usuario_registra` INT(20) NOT NULL,
    PRIMARY KEY (`id_sesion`),
    KEY `idx_clases_privadas_fecha` (`fecha_hora`),
    CONSTRAINT `chk_clase_privada_precio` CHECK (`precio_unitario` = 150.00),
    CONSTRAINT `chk_clase_privada_participantes` CHECK (`cantidad_participantes` > 0),
    CONSTRAINT `chk_clase_privada_total` CHECK (`total_historico` = `precio_unitario` * `cantidad_participantes`),
    CONSTRAINT `fk_clase_privada_profesor` FOREIGN KEY (`id_profesor`) REFERENCES `profesor` (`id_profesor`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_clase_privada_usuario` FOREIGN KEY (`id_usuario_registra`) REFERENCES `usuarios` (`id_usuario`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE `clases_privadas_alumnos` (
    `id_participante` INT(11) NOT NULL AUTO_INCREMENT,
    `id_sesion` INT(20) NOT NULL,
    `id_alumno` INT(20) NULL,
    `nombre_participante` VARCHAR(200) NOT NULL,
    `precio_historico` DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (`id_participante`),
    KEY `idx_privadas_participante_sesion` (`id_sesion`),
    KEY `idx_privadas_participante_alumno` (`id_alumno`),
    CONSTRAINT `chk_privada_participante_precio` CHECK (`precio_historico` = 150.00),
    CONSTRAINT `fk_privada_participante_sesion` FOREIGN KEY (`id_sesion`) REFERENCES `clases_privadas` (`id_sesion`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_privada_participante_alumno` FOREIGN KEY (`id_alumno`) REFERENCES `alumnos` (`id_alumno`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE `pagos` (
    `id_pago` INT(50) NOT NULL AUTO_INCREMENT,
    `id_alumno` INT(20) NULL,
    `concepto` VARCHAR(100) NOT NULL,
    `monto` DECIMAL(10,2) NOT NULL,
    `fecha_pago` DATE NOT NULL,
    `metodo_pago` VARCHAR(50) NOT NULL,
    `folio` VARCHAR(32) NULL,
    `tipo_pago` ENUM('mensualidad','clase_privada','otro') NOT NULL DEFAULT 'otro',
    `estado` ENUM('activo','cancelado') NOT NULL DEFAULT 'activo',
    `nombre_pagador` VARCHAR(200) NULL,
    `id_alumno_pagador` INT(20) NULL,
    `id_usuario_registra` INT(20) NULL,
    `id_sesion_privada` INT(20) NULL,
    `sesion_privada_activa` INT(20) GENERATED ALWAYS AS (IF(`tipo_pago` = 'clase_privada' AND `estado` = 'activo', `id_sesion_privada`, NULL)) PERSISTENT,
    PRIMARY KEY (`id_pago`),
    UNIQUE KEY `uq_pagos_folio` (`folio`),
    UNIQUE KEY `uq_pagos_id_alumno` (`id_pago`,`id_alumno`),
    UNIQUE KEY `uq_pago_privado_activo` (`sesion_privada_activa`),
    KEY `idx_pagos_alumno` (`id_alumno`),
    KEY `idx_pagos_tipo_estado_fecha` (`tipo_pago`,`estado`,`fecha_pago`),
    CONSTRAINT `chk_pago_monto` CHECK (`monto` > 0),
    CONSTRAINT `fk_pagos_alumno` FOREIGN KEY (`id_alumno`) REFERENCES `alumnos` (`id_alumno`) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_pagos_alumno_pagador` FOREIGN KEY (`id_alumno_pagador`) REFERENCES `alumnos` (`id_alumno`) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT `fk_pagos_usuario_registra` FOREIGN KEY (`id_usuario_registra`) REFERENCES `usuarios` (`id_usuario`) ON UPDATE CASCADE ON DELETE RESTRICT,
    -- MariaDB rechaza (error 1901) ON UPDATE CASCADE sobre id_sesion_privada, base de sesion_privada_activa.
    CONSTRAINT `fk_pagos_sesion_privada` FOREIGN KEY (`id_sesion_privada`) REFERENCES `clases_privadas` (`id_sesion`) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE `aplicaciones_pagos` (
    `id_aplicacion` INT(11) NOT NULL AUTO_INCREMENT,
    `id_pago` INT(50) NOT NULL,
    `id_cargo` INT(11) NOT NULL,
    `id_alumno` INT(20) NOT NULL,
    `importe_aplicado` DECIMAL(10,2) NOT NULL,
    `aplicada_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_aplicacion`),
    UNIQUE KEY `uq_aplicacion_pago_cargo` (`id_pago`,`id_cargo`),
    KEY `idx_aplicaciones_cargo_alumno` (`id_cargo`,`id_alumno`),
    KEY `idx_aplicaciones_pago_alumno` (`id_pago`,`id_alumno`),
    CONSTRAINT `chk_aplicacion_importe` CHECK (`importe_aplicado` > 0),
    CONSTRAINT `fk_aplicacion_pago_alumno` FOREIGN KEY (`id_pago`,`id_alumno`) REFERENCES `pagos` (`id_pago`,`id_alumno`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_aplicacion_cargo_alumno` FOREIGN KEY (`id_cargo`,`id_alumno`) REFERENCES `cargos_mensuales` (`id_cargo`,`id_alumno`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE `historial_pagos` (
    `id_historial` INT(11) NOT NULL AUTO_INCREMENT,
    `id_pago` INT(50) NOT NULL,
    `estado_anterior` ENUM('activo','cancelado') NULL,
    `estado_nuevo` ENUM('activo','cancelado') NULL,
    `importe_anterior` DECIMAL(10,2) NULL,
    `importe_nuevo` DECIMAL(10,2) NULL,
    `id_usuario_responsable` INT(20) NOT NULL,
    `fecha_hora` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `motivo` VARCHAR(255) NOT NULL,
    PRIMARY KEY (`id_historial`),
    KEY `idx_historial_pago_fecha` (`id_pago`,`fecha_hora`),
    CONSTRAINT `chk_historial_importes` CHECK ((`importe_anterior` IS NULL OR `importe_anterior` >= 0) AND (`importe_nuevo` IS NULL OR `importe_nuevo` >= 0)),
    CONSTRAINT `fk_historial_pago` FOREIGN KEY (`id_pago`) REFERENCES `pagos` (`id_pago`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_historial_usuario` FOREIGN KEY (`id_usuario_responsable`) REFERENCES `usuarios` (`id_usuario`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- ---------------------------------------------------------------------
-- Notificaciones y auditoría
-- ---------------------------------------------------------------------

CREATE TABLE `notificaciones` (
    `id_notificacion` INT(11) NOT NULL AUTO_INCREMENT,
    `id_usuario_destinatario` INT(20) NOT NULL,
    `id_cargo` INT(11) NOT NULL,
    `tipo` ENUM('vencimiento_3_dias','vencimiento_hoy','cargo_cubierto') NOT NULL,
    `fecha_creacion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `leida` TINYINT(1) NOT NULL DEFAULT 0,
    `estado` ENUM('activa','cerrada') NOT NULL DEFAULT 'activa',
    PRIMARY KEY (`id_notificacion`),
    UNIQUE KEY `uq_notificacion_cargo_tipo` (`id_cargo`,`tipo`),
    KEY `idx_notificaciones_destinatario_estado` (`id_usuario_destinatario`,`estado`,`leida`,`fecha_creacion`),
    CONSTRAINT `fk_notificacion_usuario` FOREIGN KEY (`id_usuario_destinatario`) REFERENCES `usuarios` (`id_usuario`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_notificacion_cargo` FOREIGN KEY (`id_cargo`) REFERENCES `cargos_mensuales` (`id_cargo`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE `auditoria_eventos` (
    `id_evento` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `id_usuario` INT(20) NOT NULL,
    `accion` VARCHAR(80) NOT NULL,
    `entidad` VARCHAR(80) NOT NULL,
    `id_entidad` VARCHAR(64) NOT NULL,
    `detalles` TEXT NULL,
    `fecha_hora` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_evento`),
    KEY `idx_auditoria_entidad` (`entidad`,`id_entidad`,`fecha_hora`),
    KEY `idx_auditoria_usuario_fecha` (`id_usuario`,`fecha_hora`),
    CONSTRAINT `fk_auditoria_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- ---------------------------------------------------------------------
-- Control de migraciones (para que `php migrate.php status` reconozca
-- esta base como ya migrada y no intente volver a crear las tablas).
-- ---------------------------------------------------------------------

CREATE TABLE `migraciones` (
    `id_migracion` VARCHAR(191) NOT NULL,
    `archivo` VARCHAR(255) NOT NULL,
    `descripcion` VARCHAR(255) NOT NULL,
    `checksum` CHAR(64) NOT NULL,
    `ejecutada_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_migracion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `migraciones_estado` (
    `id_migracion` VARCHAR(191) NOT NULL,
    `checksum` CHAR(64) NOT NULL,
    `estado` VARCHAR(16) NOT NULL,
    `detalle` TEXT NULL,
    `iniciado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `actualizado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_migracion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NOTA IMPORTANTE: no se insertan filas en `migraciones` aquí porque el
-- checksum de cada migración depende del contenido exacto del archivo .php
-- en disco (ver core/MigrationRunner.php). Si insertas aquí un checksum
-- que no coincide con tus archivos reales, `migrate.php status` marcará
-- "checksum_mismatch".
--
-- ADVERTENCIA: no ejecutes up sin seleccionar una migración. La migración 002
-- intenta crear claves foráneas existentes y falla en MariaDB 10.11. Consulta
-- en el README cómo ejecutar individualmente la migración 011.

-- ---------------------------------------------------------------------
-- Datos semilla mínimos (catálogo de cinturones, tarifas vigentes,
-- configuración de mensualidades). Ajusta los importes a tu escuela.
-- ---------------------------------------------------------------------

INSERT INTO `cinturon` (`color`) VALUES
    ('Blanco'), ('Azul'), ('Morado'), ('Marrón'), ('Negro');

INSERT INTO `tarifas_mensuales` (`categoria`, `importe`, `vigente_desde`, `activa`) VALUES
    ('infantil', 900.00, NULL, 1),
    ('adulta', 1800.00, NULL, 1);

INSERT INTO `configuracion_mensualidades` (`dia_vencimiento`, `activa`) VALUES
    (NULL, 1);

-- ---------------------------------------------------------------------
-- Cuenta de administrador inicial
-- ---------------------------------------------------------------------
-- IMPORTANTE: reemplaza PEGA_AQUI_EL_HASH por el resultado de:
--   php -r "echo password_hash('TU_CONTRASENA_SEGURA', PASSWORD_DEFAULT), PHP_EOL;"
-- No dejes este INSERT con un hash de ejemplo en producción.

-- INSERT INTO `usuarios` (`correo`, `password`, `rol`, `activo`) VALUES
--     ('admin@tu-escuela.test', 'PEGA_AQUI_EL_HASH', 'administrador', 1);

SET FOREIGN_KEY_CHECKS = 1;
