<?php
declare(strict_types=1);

/**
 * Costo por clase y concepto 'clase' en los cargos.
 *
 *  - clases.costo: lo que se cobra al alumno cuando el profesor acepta su solicitud
 *    (0 = se cobra la mensualidad de su categoría).
 *  - cargos_mensuales.concepto admite 'clase'. La columna generada clave_mensualidad depende de
 *    concepto, así que se suelta y se vuelve a crear en el mismo cambio.
 *
 * Todas las sentencias son idempotentes.
 */
return [
    'id' => '20261126_009_costo_de_clase',
    'description' => 'Agrega clases.costo y el concepto clase en cargos_mensuales',
    'transactional' => false,
    'retry_safe' => true,
    'statements' => [
        'ALTER TABLE clases ADD COLUMN IF NOT EXISTS costo DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER capacidad',
        "ALTER TABLE cargos_mensuales DROP INDEX IF EXISTS uq_cargo_mensualidad, DROP COLUMN IF EXISTS clave_mensualidad, MODIFY concepto ENUM('mensualidad','clase','clase_individual','otro') NOT NULL DEFAULT 'mensualidad'",
        "ALTER TABLE cargos_mensuales ADD COLUMN IF NOT EXISTS clave_mensualidad VARCHAR(32) GENERATED ALWAYS AS (IF(concepto = 'mensualidad', CONCAT(id_alumno, '-', periodo), NULL)) PERSISTENT, ADD UNIQUE INDEX IF NOT EXISTS uq_cargo_mensualidad (clave_mensualidad)",
    ],
];
