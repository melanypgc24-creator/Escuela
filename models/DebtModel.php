<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class DebtModel
{
    public function __construct(private PDO $db)
    {
    }

    public function all(): array
    {
        return $this->fetchOutstanding(null);
    }

    public function allForStudent(int $studentId): array
    {
        return $this->fetchOutstanding($studentId);
    }

    private function fetchOutstanding(?int $studentId): array
    {
        $studentFilter = $studentId === null ? '' : ' AND cm.id_alumno = :id_alumno';
        $statement = $this->db->prepare(
            'SELECT cm.id_cargo, cm.id_alumno, CONCAT(a.nombre, " ", a.apellidos) AS alumno,
                    cm.periodo, cm.categoria, cm.importe, cm.fecha_vencimiento, cm.estado,
                    COALESCE(SUM(ap.importe_aplicado), 0) AS importe_aplicado,
                    cm.importe - COALESCE(SUM(ap.importe_aplicado), 0) AS saldo_pendiente
             FROM cargos_mensuales cm
             JOIN alumnos a ON a.id_alumno = cm.id_alumno
             LEFT JOIN (
                SELECT ap.id_cargo, ap.id_alumno, ap.importe_aplicado
                FROM aplicaciones_pagos ap
                JOIN pagos p ON p.id_pago = ap.id_pago AND p.estado = "activo"
             ) ap ON ap.id_cargo = cm.id_cargo AND ap.id_alumno = cm.id_alumno
             WHERE cm.estado IN ("pendiente", "vencido")
             ' . $studentFilter . '
             GROUP BY cm.id_cargo, cm.id_alumno, a.nombre, a.apellidos, cm.periodo,
                      cm.categoria, cm.importe, cm.fecha_vencimiento, cm.estado
             HAVING saldo_pendiente > 0
             ORDER BY cm.fecha_vencimiento, cm.periodo, cm.id_cargo'
        );
        if ($studentId !== null) {
            $statement->execute(['id_alumno' => $studentId]);
        } else {
            $statement->execute();
        }
        return $statement->fetchAll();
    }
}
