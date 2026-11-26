<?php
declare(strict_types=1);

/**
 * Permite varios cargos por alumno y periodo (por ejemplo, mensualidad + clase individual).
 *
 *  - Agrega cargos_mensuales.concepto ('mensualidad', 'clase_individual', 'otro').
 *  - Reemplaza UNIQUE(id_alumno, periodo) por una unicidad solo para mensualidades:
 *    un alumno sigue sin poder tener dos mensualidades del mismo periodo, pero sí
 *    cualquier cantidad de clases individuales u otros cargos.
 *
 * Todas las sentencias son idempotentes, así que se puede repetir sin riesgo.
 */
return [
    'id' => '20261126_008_concepto_en_cargos',
    'description' => 'Agrega concepto a cargos_mensuales y permite varios cargos por alumno y periodo',
    'transactional' => false,
    'retry_safe' => true,
    'statements' => [
        "ALTER TABLE cargos_mensuales ADD COLUMN IF NOT EXISTS concepto ENUM('mensualidad','clase_individual','otro') NOT NULL DEFAULT 'mensualidad' AFTER periodo",
        "ALTER TABLE cargos_mensuales ADD COLUMN IF NOT EXISTS clave_mensualidad VARCHAR(32) GENERATED ALWAYS AS (IF(concepto = 'mensualidad', CONCAT(id_alumno, '-', periodo), NULL)) PERSISTENT",
        'ALTER TABLE cargos_mensuales ADD UNIQUE INDEX IF NOT EXISTS uq_cargo_mensualidad (clave_mensualidad)',
        // La llave foránea del alumno necesita un índice que empiece por id_alumno antes de quitar el único anterior.
        'ALTER TABLE cargos_mensuales ADD INDEX IF NOT EXISTS idx_cargo_alumno_periodo (id_alumno, periodo)',
        'ALTER TABLE cargos_mensuales DROP INDEX IF EXISTS uq_cargo_alumno_periodo',
    ],
];
