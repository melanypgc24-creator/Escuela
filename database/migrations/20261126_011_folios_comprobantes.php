<?php
declare(strict_types=1);

return [
    'id' => '20261126_011_folios_comprobantes',
    'description' => 'Asigna folios históricos e impide su modificación o reutilización',
    'transactional' => false,
    'retry_safe' => true,
    'standalone' => true,
    'preflight' => [
        [
            'sql' => "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'pagos' AND index_name = 'uq_pagos_folio' AND column_name = 'folio' AND non_unique = 0",
            'expected' => 1,
            'message' => 'falta el índice único uq_pagos_folio',
        ],
        [
            'sql' => "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'pagos' AND index_name = 'uq_pagos_folio'",
            'expected' => 1,
            'message' => 'uq_pagos_folio no es un índice único exclusivo de folio',
        ],
        [
            'sql' => "SELECT COUNT(*) FROM pagos p WHERE (p.folio IS NULL OR TRIM(p.folio) = '') AND (p.fecha_pago IS NULL OR DATE_FORMAT(p.fecha_pago, '%Y%m%d') IS NULL OR EXISTS (SELECT 1 FROM pagos existente WHERE existente.folio = CONCAT('TC-', DATE_FORMAT(p.fecha_pago, '%Y%m%d'), '-', LPAD(p.id_pago, GREATEST(6, CHAR_LENGTH(p.id_pago)), '0'))))",
            'expected' => 0,
            'message' => 'hay fechas inválidas o folios históricos que colisionarían; requiere revisión manual',
        ],
    ],
    'statements' => [
        "UPDATE pagos SET folio = CONCAT('TC-', DATE_FORMAT(fecha_pago, '%Y%m%d'), '-', LPAD(id_pago, GREATEST(6, CHAR_LENGTH(id_pago)), '0')) WHERE folio IS NULL OR TRIM(folio) = ''",
        <<<'SQL'
CREATE TRIGGER IF NOT EXISTS escuela_pago_folio_inmutable BEFORE UPDATE ON pagos FOR EACH ROW
BEGIN
    IF OLD.folio IS NOT NULL AND NOT (BINARY NEW.folio <=> BINARY OLD.folio) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El folio de un pago no puede modificarse';
    END IF;
END
SQL,
        <<<'SQL'
CREATE TRIGGER IF NOT EXISTS escuela_pago_folio_no_borrar BEFORE DELETE ON pagos FOR EACH ROW
BEGIN
    IF OLD.folio IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Los pagos con folio deben conservarse';
    END IF;
END
SQL,
    ],
];
