-- ETAPA 1: migración incremental (solo entorno stage1)
-- Base objetivo: escuela_stage1_20261117_233336
-- No ejecutar sobre la base activa `escuela`

USE `escuela_stage1_20261117_233336`;

SET @OLD_SQL_MODE := @@SESSION.sql_mode;
SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'NO_AUTO_VALUE_ON_ZERO');

-- 1) Corregir relación de cinturones (FK invertida en esquema legado)
SET @fk_cinturon_invertida := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'cinturon'
      AND CONSTRAINT_NAME = 'cinturon_ibfk_1'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(@fk_cinturon_invertida > 0, 'ALTER TABLE cinturon DROP FOREIGN KEY cinturon_ibfk_1', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_alumnos_cinturon := (
    SELECT COUNT(*)
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'alumnos'
      AND COLUMN_NAME = 'id_cinturon'
      AND REFERENCED_TABLE_NAME = 'cinturon'
      AND REFERENCED_COLUMN_NAME = 'id_cinturon'
);
SET @sql := IF(
    @fk_alumnos_cinturon = 0,
    'ALTER TABLE alumnos ADD CONSTRAINT fk_alumnos_cinturon FOREIGN KEY (id_cinturon) REFERENCES cinturon(id_cinturon) ON UPDATE CASCADE ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2) Ajustar PK/AUTO_INCREMENT conservando IDs existentes
ALTER TABLE alumnos MODIFY id_alumno INT(20) NOT NULL AUTO_INCREMENT;
ALTER TABLE asistencias MODIFY id_asistencia INT(20) NOT NULL AUTO_INCREMENT;
ALTER TABLE cinturon MODIFY id_cinturon INT(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE clases MODIFY id_clase INT(20) NOT NULL AUTO_INCREMENT;
ALTER TABLE pagos MODIFY id_pago INT(50) NOT NULL AUTO_INCREMENT;
ALTER TABLE profesor MODIFY id_profesor INT(20) NOT NULL AUTO_INCREMENT;
ALTER TABLE usuarios MODIFY id_usuario INT(20) NOT NULL AUTO_INCREMENT;

SET @next_alumno := (SELECT GREATEST(COALESCE(MAX(id_alumno), 0) + 1, 1) FROM alumnos);
SET @next_asistencia := (SELECT GREATEST(COALESCE(MAX(id_asistencia), 0) + 1, 1) FROM asistencias);
SET @next_cinturon := (SELECT GREATEST(COALESCE(MAX(id_cinturon), 0) + 1, 1) FROM cinturon);
SET @next_clase := (SELECT GREATEST(COALESCE(MAX(id_clase), 0) + 1, 1) FROM clases);
SET @next_pago := (SELECT GREATEST(COALESCE(MAX(id_pago), 0) + 1, 1) FROM pagos);
SET @next_profesor := (SELECT GREATEST(COALESCE(MAX(id_profesor), 0) + 1, 1) FROM profesor);
SET @next_usuario := (SELECT GREATEST(COALESCE(MAX(id_usuario), 0) + 1, 1) FROM usuarios);

SET @sql := CONCAT('ALTER TABLE alumnos AUTO_INCREMENT = ', @next_alumno);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := CONCAT('ALTER TABLE asistencias AUTO_INCREMENT = ', @next_asistencia);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := CONCAT('ALTER TABLE cinturon AUTO_INCREMENT = ', @next_cinturon);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := CONCAT('ALTER TABLE clases AUTO_INCREMENT = ', @next_clase);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := CONCAT('ALTER TABLE pagos AUTO_INCREMENT = ', @next_pago);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := CONCAT('ALTER TABLE profesor AUTO_INCREMENT = ', @next_profesor);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := CONCAT('ALTER TABLE usuarios AUTO_INCREMENT = ', @next_usuario);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) Columnas incrementales en esquema legado
ALTER TABLE alumnos
    ADD COLUMN IF NOT EXISTS matricula VARCHAR(20) NULL AFTER id_alumno;

UPDATE alumnos
SET matricula = CONCAT('TC-', LPAD(id_alumno, 6, '0'))
WHERE matricula IS NULL OR TRIM(matricula) = '';

ALTER TABLE alumnos
    MODIFY matricula VARCHAR(20) NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_alumnos_matricula ON alumnos (matricula);
CREATE UNIQUE INDEX IF NOT EXISTS uq_usuarios_correo ON usuarios (correo);

-- 4) Tablas nuevas del dominio API
CREATE TABLE IF NOT EXISTS students (
    id INT(20) NOT NULL AUTO_INCREMENT,
    legacy_alumno_id INT(20) DEFAULT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    birth_date DATE DEFAULT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_students_email (email),
    UNIQUE KEY uq_students_legacy_alumno_id (legacy_alumno_id),
    CONSTRAINT fk_students_legacy_alumno
        FOREIGN KEY (legacy_alumno_id) REFERENCES alumnos(id_alumno)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE IF NOT EXISTS memberships (
    id INT(20) NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    price DECIMAL(10,2) NOT NULL,
    duration_days INT(11) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_memberships_name (name),
    CONSTRAINT chk_memberships_price CHECK (price >= 0),
    CONSTRAINT chk_memberships_duration CHECK (duration_days > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE IF NOT EXISTS payments (
    id INT(20) NOT NULL AUTO_INCREMENT,
    student_id INT(20) NOT NULL,
    legacy_pago_id INT(50) DEFAULT NULL,
    amount DECIMAL(10,2) NOT NULL,
    paid_at DATETIME NOT NULL,
    method ENUM('cash','transfer','card','other') NOT NULL,
    reference VARCHAR(100) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payments_legacy_pago_id (legacy_pago_id),
    KEY idx_payments_student_paid_at (student_id, paid_at),
    CONSTRAINT fk_payments_student
        FOREIGN KEY (student_id) REFERENCES students(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_payments_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE IF NOT EXISTS attendance (
    id INT(20) NOT NULL AUTO_INCREMENT,
    student_id INT(20) NOT NULL,
    legacy_asistencia_id INT(20) DEFAULT NULL,
    attended_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_legacy_asistencia_id (legacy_asistencia_id),
    KEY idx_attendance_student_attended_at (student_id, attended_at),
    CONSTRAINT fk_attendance_student
        FOREIGN KEY (student_id) REFERENCES students(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE IF NOT EXISTS debts (
    id INT(20) NOT NULL AUTO_INCREMENT,
    student_id INT(20) NOT NULL,
    description VARCHAR(255) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    due_date DATE NOT NULL,
    status ENUM('pending','paid','cancelled') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_debts_student_due_date (student_id, due_date),
    KEY idx_debts_status (status),
    CONSTRAINT fk_debts_student
        FOREIGN KEY (student_id) REFERENCES students(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_debts_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- 5) Migración de datos (sin perder IDs/registros existentes)
INSERT INTO students (
    id,
    legacy_alumno_id,
    first_name,
    last_name,
    email,
    phone,
    birth_date,
    status,
    created_at
)
SELECT
    a.id_alumno,
    a.id_alumno,
    a.nombre,
    a.apellidos,
    CONCAT('legacy+', LPAD(a.id_alumno, 6, '0'), '@escuela.local'),
    NULLIF(TRIM(a.telefono), ''),
    a.fecha_nacimiento,
    CASE WHEN a.estatus = 1 THEN 'active' ELSE 'inactive' END,
    CONCAT(a.fecha_ingreso, ' 00:00:00')
FROM alumnos a
WHERE NOT EXISTS (
    SELECT 1 FROM students s WHERE s.legacy_alumno_id = a.id_alumno
);

SET @next_student := (SELECT GREATEST(COALESCE(MAX(id), 0) + 1, 1) FROM students);
SET @sql := CONCAT('ALTER TABLE students AUTO_INCREMENT = ', @next_student);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO payments (
    id,
    student_id,
    legacy_pago_id,
    amount,
    paid_at,
    method,
    reference
)
SELECT
    p.id_pago,
    s.id,
    p.id_pago,
    p.monto,
    CONCAT(p.fecha_pago, ' 00:00:00'),
    CASE
        WHEN LOWER(TRIM(p.metodo_pago)) IN ('efectivo', 'cash') THEN 'cash'
        WHEN LOWER(TRIM(p.metodo_pago)) IN ('transferencia', 'transfer') THEN 'transfer'
        WHEN LOWER(TRIM(p.metodo_pago)) IN ('tarjeta', 'card') THEN 'card'
        ELSE 'other'
    END,
    NULLIF(TRIM(p.concepto), '')
FROM pagos p
JOIN students s ON s.legacy_alumno_id = p.id_alumno
WHERE NOT EXISTS (
    SELECT 1 FROM payments np WHERE np.legacy_pago_id = p.id_pago
);

SET @next_payment := (SELECT GREATEST(COALESCE(MAX(id), 0) + 1, 1) FROM payments);
SET @sql := CONCAT('ALTER TABLE payments AUTO_INCREMENT = ', @next_payment);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO attendance (
    id,
    student_id,
    legacy_asistencia_id,
    attended_at
)
SELECT
    a.id_asistencia,
    s.id,
    a.id_asistencia,
    CONCAT(a.fecha, ' 00:00:00')
FROM asistencias a
JOIN students s ON s.legacy_alumno_id = a.id_alumno
WHERE NOT EXISTS (
    SELECT 1 FROM attendance na WHERE na.legacy_asistencia_id = a.id_asistencia
);

SET @next_attendance := (SELECT GREATEST(COALESCE(MAX(id), 0) + 1, 1) FROM attendance);
SET @sql := CONCAT('ALTER TABLE attendance AUTO_INCREMENT = ', @next_attendance);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Membresía base para validar flujo API sin afectar datos legado
INSERT INTO memberships (name, description, price, duration_days, active)
SELECT 'General', 'Membresía general mensual', 0.00, 30, 1
WHERE NOT EXISTS (SELECT 1 FROM memberships WHERE name = 'General');

SET SESSION sql_mode = @OLD_SQL_MODE;
