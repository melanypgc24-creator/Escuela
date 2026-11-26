<?php
declare(strict_types=1);

/**
 * Integridad de las tablas heredadas: claves foráneas y restricciones que faltaban.
 *
 *  1. Claves foráneas (antes cualquier ID inexistente se guardaba sin error):
 *       asistencias.id_alumno / id_clase, inscripciones_clases.id_alumno / id_clase / revisado_por_profesor,
 *       clases.id_profesor, usuarios.id_alumno / id_profesor y pagos.id_alumno.
 *     Alumnos, clases y profesores se dan de baja (estatus = 0) o se conservan; nunca se borran ni se
 *     renumeran, por eso las referencias usan ON UPDATE RESTRICT / ON DELETE RESTRICT (igual que clases_alumnos).
 *     Solo inscripciones_clases.revisado_por_profesor usa ON DELETE SET NULL: es el dato de "quién revisó".
 *  2. pagos.estado y pagos.tipo_pago dejan de aceptar NULL. Los pagos antiguos con NULL desaparecían de las
 *     consultas con estado = 'activo'. Se rellenan así:
 *       estado    NULL -> 'activo'
 *       tipo_pago NULL -> 'clase_privada' si tiene id_sesion_privada; 'mensualidad' si el concepto es
 *                         exactamente "Mensualidad"; 'otro' en cualquier otro caso.
 *  3. pagos.monto debe ser mayor que cero (CHECK).
 *
 * NO se toca el CHECK (precio_unitario = 150.00) de clases_privadas: la clase privada tiene un precio fijo
 * que se paga por esa clase, no es un precio histórico por mes.
 *
 * Antes de ejecutar nada, el preflight comprueba que no existan registros huérfanos, montos <= 0 ni dos pagos
 * activos para la misma clase privada; si hay alguno, la migración se detiene sin cambiar la base (corrige o
 * elimina esos registros y vuelve a ejecutar `up`). Todas las sentencias son idempotentes.
 */

// [tabla, columna, tabla referenciada, columna referenciada, nombre de la FK, ON UPDATE, ON DELETE]
$claves = [
    ['asistencias', 'id_alumno', 'alumnos', 'id_alumno', 'fk_asistencia_alumno', 'RESTRICT', 'RESTRICT'],
    ['asistencias', 'id_clase', 'clases', 'id_clase', 'fk_asistencia_clase', 'RESTRICT', 'RESTRICT'],
    ['inscripciones_clases', 'id_alumno', 'alumnos', 'id_alumno', 'fk_inscripcion_alumno', 'RESTRICT', 'RESTRICT'],
    ['inscripciones_clases', 'id_clase', 'clases', 'id_clase', 'fk_inscripcion_clase', 'RESTRICT', 'RESTRICT'],
    ['inscripciones_clases', 'revisado_por_profesor', 'profesor', 'id_profesor', 'fk_inscripcion_profesor_revisa', 'RESTRICT', 'SET NULL'],
    ['clases', 'id_profesor', 'profesor', 'id_profesor', 'fk_clases_profesor', 'RESTRICT', 'RESTRICT'],
    ['usuarios', 'id_alumno', 'alumnos', 'id_alumno', 'fk_usuarios_alumno', 'RESTRICT', 'RESTRICT'],
    ['usuarios', 'id_profesor', 'profesor', 'id_profesor', 'fk_usuarios_profesor', 'RESTRICT', 'RESTRICT'],
    ['pagos', 'id_alumno', 'alumnos', 'id_alumno', 'fk_pagos_alumno', 'RESTRICT', 'RESTRICT'],
];

$etiquetas = [
    'asistencias' => 'asistencias',
    'inscripciones_clases' => 'inscripciones',
    'clases' => 'clases',
    'usuarios' => 'cuentas de usuario',
    'pagos' => 'pagos',
];

$preflight = [];
$statements = [
    "UPDATE pagos SET estado = 'activo' WHERE estado IS NULL",
    "UPDATE pagos SET tipo_pago = IF(id_sesion_privada IS NOT NULL, 'clase_privada', IF(LOWER(TRIM(concepto)) = 'mensualidad', 'mensualidad', 'otro')) WHERE tipo_pago IS NULL",
    // La columna generada sesion_privada_activa depende de estas dos; MariaDB la recalcula sin problema.
    "ALTER TABLE pagos MODIFY tipo_pago ENUM('mensualidad','clase_privada','otro') NOT NULL DEFAULT 'otro', MODIFY estado ENUM('activo','cancelado') NOT NULL DEFAULT 'activo'",
    'ALTER TABLE pagos ADD CONSTRAINT IF NOT EXISTS chk_pago_monto CHECK (monto > 0)',
];

foreach ($claves as [$tabla, $columna, $padre, $columnaPadre, $nombre, $alActualizar, $alBorrar]) {
    $preflight[] = [
        'sql' => "SELECT COUNT(*) FROM `{$tabla}` hijo LEFT JOIN `{$padre}` padre ON padre.`{$columnaPadre}` = hijo.`{$columna}` "
            . "WHERE hijo.`{$columna}` IS NOT NULL AND padre.`{$columnaPadre}` IS NULL",
        'expected' => 0,
        'message' => sprintf(
            'hay %s cuyo %s no existe en %s; corrígelos o elimínalos antes de crear la clave foránea %s',
            $etiquetas[$tabla],
            $columna,
            $padre,
            $nombre
        ),
    ];

    // Solo se crea si todavía no hay una clave foránea sobre esa columna (así se puede repetir sin error).
    $yaExiste = "(SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() "
        . "AND TABLE_NAME = '{$tabla}' AND COLUMN_NAME = '{$columna}' AND REFERENCED_TABLE_NAME = '{$padre}')";
    $alter = "ALTER TABLE `{$tabla}` ADD CONSTRAINT `{$nombre}` FOREIGN KEY (`{$columna}`) "
        . "REFERENCES `{$padre}` (`{$columnaPadre}`) ON UPDATE {$alActualizar} ON DELETE {$alBorrar}";
    $statements[] = "SET @fk_sql := IF({$yaExiste} > 0, 'DO 0', '{$alter}')";
    $statements[] = 'PREPARE escuela_migration_stmt FROM @fk_sql';
    $statements[] = 'EXECUTE escuela_migration_stmt';
    $statements[] = 'DEALLOCATE PREPARE escuela_migration_stmt';
}

$preflight[] = [
    'sql' => 'SELECT COUNT(*) FROM pagos WHERE monto <= 0',
    'expected' => 0,
    'message' => 'hay pagos con monto igual o menor que cero y no se puede crear la restricción chk_pago_monto',
];
$preflight[] = [
    'sql' => "SELECT COUNT(*) FROM (SELECT id_sesion_privada FROM pagos WHERE id_sesion_privada IS NOT NULL AND (estado IS NULL OR estado = 'activo') GROUP BY id_sesion_privada HAVING COUNT(*) > 1) AS duplicados",
    'expected' => 0,
    'message' => 'hay clases privadas con más de un pago activo (o sin estado); cancela los repetidos antes de normalizar pagos.estado',
];

return [
    'id' => '20261126_010_claves_foraneas_y_restricciones',
    'description' => 'Agrega claves foráneas a las tablas heredadas, vuelve obligatorios pagos.estado y tipo_pago y exige monto > 0',
    'transactional' => false,
    'retry_safe' => true,
    'preflight' => $preflight,
    'statements' => $statements,
];
