<?php
declare(strict_types=1);

return [
    'id' => '20261126_012_categorias_clases',
    'description' => 'Categorías de clases y estado de cancelación automática de solicitudes',
    'transactional' => false,
    'retry_safe' => true,
    'standalone' => true,
    'preflight' => [
        [
            'sql' => "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'clases' AND column_name = 'categoria' AND column_type <> \"enum('infantil','adulta')\"",
            'expected' => 0,
            'message' => 'la columna clases.categoria existente tiene un tipo incompatible',
        ],
        [
            'sql' => "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'inscripciones_clases' AND column_name = 'estado' AND column_type IN (\"enum('pendiente','aceptada','rechazada')\", \"enum('pendiente','aceptada','rechazada','cancelada')\") AND is_nullable = 'NO' AND column_default IN ('pendiente', \"'pendiente'\")",
            'expected' => 1,
            'message' => 'el estado de las solicitudes tiene una definición inesperada y requiere revisión',
        ],
    ],
    'statements' => [
        "ALTER TABLE clases ADD COLUMN IF NOT EXISTS categoria ENUM('infantil','adulta') NULL",
        "ALTER TABLE inscripciones_clases MODIFY COLUMN estado ENUM('pendiente','aceptada','rechazada','cancelada') NOT NULL DEFAULT 'pendiente'",
        <<<'SQL'
UPDATE clases
SET categoria = CASE
    WHEN LOWER(nombre_clase) REGEXP '(^|[^[:alpha:]])(infantil|niños|niñas|niño|niña)([^[:alpha:]]|$)'
        THEN 'infantil'
    WHEN LOWER(nombre_clase) REGEXP '(^|[^[:alpha:]])(adulto|adultos|adulta|adultas)([^[:alpha:]]|$)'
        THEN 'adulta'
    ELSE NULL
END
WHERE categoria IS NULL
  AND (LOWER(nombre_clase) REGEXP '(^|[^[:alpha:]])(infantil|niños|niñas|niño|niña)([^[:alpha:]]|$)')
      <> (LOWER(nombre_clase) REGEXP '(^|[^[:alpha:]])(adulto|adultos|adulta|adultas)([^[:alpha:]]|$)')
SQL,
    ],
];
