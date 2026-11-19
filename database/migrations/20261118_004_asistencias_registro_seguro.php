<?php
declare(strict_types=1);

return [
    'id' => '20261118_004_asistencias_registro_seguro',
    'description' => 'Agrega hora y responsable de registro, y evita asistencias duplicadas',
    'transactional' => false,
    'preflight' => [
        [
            'sql' => 'SELECT COUNT(*) FROM (SELECT id_alumno, id_clase, fecha FROM asistencias GROUP BY id_alumno, id_clase, fecha HAVING COUNT(*) > 1) AS duplicados',
            'expected' => 0,
            'message' => 'hay asistencias duplicadas que impiden crear la restricción única',
        ],
    ],
    'statements' => [
        <<<'SQL'
ALTER TABLE asistencias
    ADD COLUMN IF NOT EXISTS hora TIME NULL AFTER fecha,
    ADD COLUMN IF NOT EXISTS id_usuario_registra INT(20) NULL AFTER hora,
    ADD INDEX IF NOT EXISTS idx_asistencias_usuario (id_usuario_registra)
SQL,
        <<<'SQL'
ALTER TABLE asistencias
    ADD CONSTRAINT fk_asistencia_usuario_registra FOREIGN KEY (id_usuario_registra) REFERENCES usuarios (id_usuario) ON UPDATE CASCADE ON DELETE SET NULL
SQL,
        <<<'SQL'
CREATE UNIQUE INDEX IF NOT EXISTS uq_asistencia_alumno_clase_fecha ON asistencias (id_alumno, id_clase, fecha)
SQL,
    ],
];
