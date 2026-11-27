<?php
declare(strict_types=1);

namespace App\Models;

use PDO;
use RuntimeException;

final class ReceiptModel
{
    public function __construct(private PDO $db)
    {
    }

    public function find(int $id, ?int $studentId = null): ?array
    {
        $sql = 'SELECT p.*, a.nombre, a.apellidos, a.matricula,
                       COALESCE(NULLIF(TRIM(CONCAT_WS(" ", u.nombre, u.apellidos)), ""), u.correo) AS responsable
                FROM pagos p LEFT JOIN alumnos a ON a.id_alumno = p.id_alumno
                LEFT JOIN usuarios u ON u.id_usuario = p.id_usuario_registra
                WHERE p.id_pago = :id';
        $params = ['id' => $id];
        if ($studentId !== null) {
            $sql .= ' AND (p.id_alumno = :beneficiario OR p.id_alumno_pagador = :pagador)';
            $params['beneficiario'] = $studentId;
            $params['pagador'] = $studentId;
        }
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        $payment = $statement->fetch();
        if (!$payment) {
            return null;
        }

        $audit = $this->db->prepare(
            "SELECT detalles FROM auditoria_eventos WHERE entidad = 'pagos'
             AND accion = 'comprobante_datos' AND id_entidad = :id ORDER BY id_evento DESC LIMIT 1"
        );
        $audit->execute(['id' => (string) $id]);
        $snapshot = $audit->fetchColumn();
        if ($snapshot !== false) {
            $data = json_decode((string) $snapshot, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new RuntimeException('Los datos históricos del comprobante no son válidos.');
            }
        } else {
            $data = $this->snapshot($payment);
            $data['reconstruido'] = true;
        }
        $data['estado'] = $payment['estado'];
        $data['motivo'] = null;
        if ($payment['estado'] === 'cancelado') {
            $history = $this->db->prepare(
                'SELECT motivo FROM historial_pagos WHERE id_pago = :id AND estado_nuevo = "cancelado"
                 ORDER BY id_historial DESC LIMIT 1'
            );
            $history->execute(['id' => $id]);
            $data['motivo'] = $history->fetchColumn() ?: null;
        }
        if ($studentId !== null && (int) ($payment['id_alumno'] ?? 0) !== $studentId) {
            $data['alumno'] = null;
            $data['matricula'] = null;
            $data['cargos'] = [];
            $data['motivo'] = null;
        }
        if ($studentId !== null && is_string($data['responsable'] ?? null) && str_contains($data['responsable'], '@')) {
            $data['responsable'] = 'Personal de la escuela';
        }
        return $data;
    }

    public function capture(int $paymentId, int $actorId): void
    {
        $payment = $this->find($paymentId);
        if ($payment === null) {
            throw new RuntimeException('No se encontró el pago para emitir su comprobante.');
        }
        unset($payment['estado'], $payment['motivo'], $payment['reconstruido']);
        (new AuditModel($this->db))->record($actorId, 'comprobante_datos', 'pagos', $paymentId, $payment);
    }

    private function snapshot(array $payment): array
    {
        $charges = $this->db->prepare(
            'SELECT cm.periodo, cm.concepto, ap.importe_aplicado
             FROM aplicaciones_pagos ap JOIN cargos_mensuales cm ON cm.id_cargo = ap.id_cargo
             WHERE ap.id_pago = :id ORDER BY cm.periodo, cm.id_cargo'
        );
        $charges->execute(['id' => (int) $payment['id_pago']]);
        $details = (new PaymentModel($this->db))->registrationDetails((int) $payment['id_pago']);
        return [
            'folio' => $payment['folio'],
            'fecha_pago' => $payment['fecha_pago'],
            'alumno' => trim(($payment['nombre'] ?? '') . ' ' . ($payment['apellidos'] ?? '')),
            'matricula' => $payment['matricula'],
            'pagador' => $payment['nombre_pagador'],
            'concepto' => $payment['concepto'],
            'monto' => $payment['monto'],
            'metodo' => $payment['metodo_pago'],
            'responsable' => $payment['responsable'],
            'cargos' => $charges->fetchAll(),
            'referencia' => $details['transferencia_referencia'] ?? null,
            'tarjeta_ultimos4' => $details['tarjeta_ultimos4'] ?? null,
        ];
    }
}
