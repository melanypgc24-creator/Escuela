<?php
declare(strict_types=1);

/**
 * Corrige datos de ejemplo inconsistentes sin borrar historial:
 *
 *  1. La cuenta de secretaría "sevcretaria@localhost.com" (con typo) estaba como
 *     rol "alumno" y ligada a una ficha de alumno. Pasa a "recepcionista", con el
 *     correo corregido y sin ficha de alumno. La ficha "Secretaria Prueba" se
 *     conserva pero queda inactiva (estatus = 0) para que no se le generen cargos.
 *  2. El maestro (usuario con id_profesor = 0) apuntaba al profesor con ID 0, un
 *     valor frágil (en MySQL/MariaDB 0 es el valor "automático" de AUTO_INCREMENT).
 *     Se copia ese profesor a un ID nuevo y se reapuntan clases, clases privadas,
 *     inscripciones y cuentas; después se elimina el registro con ID 0.
 *
 * Cada sentencia es condicional, por lo que repetirla no cambia nada.
 */
return [
    'id' => '20261125_007_correccion_cuentas_ejemplo',
    'description' => 'Corrige la cuenta de secretaría (rol/correo/ficha de alumno) y reubica al profesor con ID 0',
    'transactional' => false,
    'retry_safe' => true,
    'statements' => [
        // --- 1. Secretaría ---------------------------------------------------
        <<<'SQL'
UPDATE usuarios
SET correo = 'secretaria@localhost.com',
    rol = 'recepcionista',
    id_alumno = NULL,
    id_profesor = NULL,
    nombre = COALESCE(nombre, 'Secretaria'),
    apellidos = COALESCE(apellidos, 'Prueba'),
    telefono = COALESCE(telefono, '8681215235')
WHERE correo = 'sevcretaria@localhost.com'
  AND NOT EXISTS (SELECT 1 FROM (SELECT id_usuario FROM usuarios WHERE correo = 'secretaria@localhost.com') AS ya_existe)
SQL,
        // Si el correo corregido ya existía, solo se arregla el rol de la cuenta con typo.
        <<<'SQL'
UPDATE usuarios
SET rol = 'recepcionista', id_alumno = NULL, id_profesor = NULL
WHERE correo = 'sevcretaria@localhost.com'
SQL,
        <<<'SQL'
UPDATE alumnos
SET estatus = 0
WHERE nombre = 'Secretaria' AND apellidos = 'Prueba' AND estatus = 1
  AND NOT EXISTS (SELECT 1 FROM (SELECT id_alumno FROM usuarios WHERE id_alumno IS NOT NULL) AS vinculados WHERE vinculados.id_alumno = alumnos.id_alumno)
SQL,

        // --- 2. Profesor con ID 0 -------------------------------------------
        <<<'SQL'
SET @prof_cero := (SELECT COUNT(*) FROM profesor WHERE id_profesor = 0)
SQL,
        <<<'SQL'
INSERT INTO profesor (nombre, apellidos, telefono)
SELECT nombre, apellidos, telefono FROM profesor WHERE id_profesor = 0
SQL,
        <<<'SQL'
SET @prof_nuevo := IF(@prof_cero > 0, (SELECT MAX(id_profesor) FROM profesor), NULL)
SQL,
        <<<'SQL'
UPDATE clases SET id_profesor = @prof_nuevo WHERE id_profesor = 0 AND @prof_nuevo IS NOT NULL
SQL,
        <<<'SQL'
UPDATE clases_privadas SET id_profesor = @prof_nuevo WHERE id_profesor = 0 AND @prof_nuevo IS NOT NULL
SQL,
        <<<'SQL'
UPDATE inscripciones_clases SET revisado_por_profesor = @prof_nuevo WHERE revisado_por_profesor = 0 AND @prof_nuevo IS NOT NULL
SQL,
        <<<'SQL'
UPDATE usuarios SET id_profesor = @prof_nuevo WHERE id_profesor = 0 AND @prof_nuevo IS NOT NULL
SQL,
        <<<'SQL'
DELETE FROM profesor WHERE id_profesor = 0 AND @prof_nuevo IS NOT NULL
SQL,
    ],
];
