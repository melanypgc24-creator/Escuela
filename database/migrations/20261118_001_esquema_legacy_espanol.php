<?php
declare(strict_types=1);

return [
    'id' => '20261118_001_esquema_legacy_espanol',
    'description' => 'Prepara las tablas originales en español conservando sus IDs y relaciones',
    'transactional' => false,
    'preflight' => [
        [
            'sql' => 'SELECT COUNT(*) FROM (SELECT correo FROM usuarios GROUP BY correo HAVING COUNT(*) > 1) AS duplicados',
            'expected' => 0,
            'message' => 'hay correos repetidos y no se puede crear el índice único de usuarios',
        ],
        [
            'sql' => 'SELECT COUNT(*) FROM alumnos a LEFT JOIN cinturon c ON c.id_cinturon = a.id_cinturon WHERE a.id_cinturon IS NOT NULL AND c.id_cinturon IS NULL',
            'expected' => 0,
            'message' => 'hay alumnos que hacen referencia a cinturones inexistentes',
        ],
    ],
    'statements' => [
        <<<'SQL'
SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'NO_AUTO_VALUE_ON_ZERO')
SQL,
        <<<'SQL'
ALTER TABLE alumnos MODIFY id_alumno INT(20) NOT NULL AUTO_INCREMENT
SQL,
        <<<'SQL'
ALTER TABLE asistencias MODIFY id_asistencia INT(20) NOT NULL AUTO_INCREMENT
SQL,
        <<<'SQL'
ALTER TABLE cinturon MODIFY id_cinturon INT(11) NOT NULL AUTO_INCREMENT
SQL,
        <<<'SQL'
ALTER TABLE clases MODIFY id_clase INT(20) NOT NULL AUTO_INCREMENT
SQL,
        <<<'SQL'
ALTER TABLE pagos MODIFY id_pago INT(50) NOT NULL AUTO_INCREMENT
SQL,
        <<<'SQL'
ALTER TABLE profesor MODIFY id_profesor INT(20) NOT NULL AUTO_INCREMENT
SQL,
        <<<'SQL'
ALTER TABLE usuarios MODIFY id_usuario INT(20) NOT NULL AUTO_INCREMENT
SQL,
        <<<'SQL'
ALTER TABLE usuarios MODIFY rol ENUM('administrador','director','maestro','recepcionista','alumno') NOT NULL
SQL,
        <<<'SQL'
ALTER TABLE alumnos
    ADD COLUMN IF NOT EXISTS matricula VARCHAR(20) NULL AFTER id_alumno,
    ADD COLUMN IF NOT EXISTS correo_contacto VARCHAR(150) NULL AFTER telefono,
    ADD COLUMN IF NOT EXISTS direccion VARCHAR(255) NULL AFTER correo_contacto,
    ADD COLUMN IF NOT EXISTS categoria ENUM('infantil','adulta') NULL AFTER fecha_nacimiento,
    ADD COLUMN IF NOT EXISTS contacto_emergencia_nombre VARCHAR(150) NULL AFTER categoria,
    ADD COLUMN IF NOT EXISTS contacto_emergencia_telefono VARCHAR(20) NULL AFTER contacto_emergencia_nombre
SQL,
        <<<'SQL'
UPDATE alumnos
SET matricula = CONCAT('TC-', LPAD(id_alumno, 6, '0'))
WHERE matricula IS NULL OR TRIM(matricula) = ''
SQL,
        <<<'SQL'
ALTER TABLE alumnos MODIFY matricula VARCHAR(20) NOT NULL
SQL,
        <<<'SQL'
CREATE UNIQUE INDEX IF NOT EXISTS uq_alumnos_matricula ON alumnos (matricula)
SQL,
        <<<'SQL'
CREATE UNIQUE INDEX IF NOT EXISTS uq_usuarios_correo ON usuarios (correo)
SQL,
        <<<'SQL'
SET @legacy_belt_fk_count := (
    SELECT COUNT(*)
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'cinturon'
      AND CONSTRAINT_NAME = 'cinturon_ibfk_1'
      AND REFERENCED_TABLE_NAME = 'alumnos'
)
SQL,
        <<<'SQL'
SET @legacy_belt_fk_sql := IF(
    @legacy_belt_fk_count > 0,
    'ALTER TABLE `cinturon` DROP FOREIGN KEY `cinturon_ibfk_1`',
    'SELECT 1'
)
SQL,
        <<<'SQL'
PREPARE escuela_migration_stmt FROM @legacy_belt_fk_sql
SQL,
        <<<'SQL'
EXECUTE escuela_migration_stmt
SQL,
        <<<'SQL'
DEALLOCATE PREPARE escuela_migration_stmt
SQL,
        <<<'SQL'
SET @student_belt_fk_count := (
    SELECT COUNT(*)
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'alumnos'
      AND COLUMN_NAME = 'id_cinturon'
      AND REFERENCED_TABLE_NAME = 'cinturon'
      AND REFERENCED_COLUMN_NAME = 'id_cinturon'
)
SQL,
        <<<'SQL'
SET @student_belt_fk_sql := IF(
    @student_belt_fk_count = 0,
    'ALTER TABLE `alumnos` ADD CONSTRAINT `fk_alumnos_cinturon` FOREIGN KEY (`id_cinturon`) REFERENCES `cinturon` (`id_cinturon`) ON UPDATE CASCADE ON DELETE SET NULL',
    'SELECT 1'
)
SQL,
        <<<'SQL'
PREPARE escuela_migration_stmt FROM @student_belt_fk_sql
SQL,
        <<<'SQL'
EXECUTE escuela_migration_stmt
SQL,
        <<<'SQL'
DEALLOCATE PREPARE escuela_migration_stmt
SQL,
    ],
];
