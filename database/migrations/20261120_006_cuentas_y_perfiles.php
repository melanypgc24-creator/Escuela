<?php
declare(strict_types=1);

return [
    'id' => '20261120_006_cuentas_y_perfiles',
    'description' => 'Agrega estado activo y datos personales opcionales a usuarios para administrar cuentas del personal',
    'transactional' => false,
    // Un solo ALTER con IF NOT EXISTS: repetirlo tras una interrupción no cambia nada.
    'retry_safe' => true,
    'statements' => [
        <<<'SQL'
ALTER TABLE usuarios
    ADD COLUMN IF NOT EXISTS activo TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS nombre VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS apellidos VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS telefono VARCHAR(20) NULL,
    ADD COLUMN IF NOT EXISTS fecha_nacimiento DATE NULL
SQL,
    ],
];
