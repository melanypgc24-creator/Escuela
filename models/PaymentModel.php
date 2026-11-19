<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DatabaseLock;
use DomainException;
use PDO;
use Throwable;

final class PaymentModel
{
    private AuditModel $audit;

    public function __construct(private PDO $db)
    {
        $this->audit = new AuditModel($db);
    }

    public function all(): array
    {
        return $this->db->query(
            'SELECT p.id_pago, p.id_alumno, p.concepto, p.monto, p.fecha_pago,
                    p.metodo_pago, p.folio, p.tipo_pago, p.estado, p.nombre_pagador,
                    CONCAT(a.nombre, " ", a.apellidos) AS alumno
             FROM pagos p
             LEFT JOIN alumnos a ON a.id_alumno = p.id_alumno
             ORDER BY p.fecha_pago DESC, p.id_pago DESC'
        )->fetchAll();
    }

    public function allForStudent(int $studentId): array
    {
        $statement = $this->db->prepare(
            'SELECT DISTINCT p.id_pago, p.id_alumno, p.concepto, p.monto, p.fecha_pago,
                    p.metodo_pago, p.folio, p.tipo_pago, p.estado
             FROM pagos p
             WHERE p.id_alumno = :student_charge
                OR p.id_alumno_pagador = :student_payer
             ORDER BY p.fecha_pago DESC, p.id_pago DESC'
        );
        $statement->execute([
            'student_charge' => $studentId,
            'student_payer' => $studentId,
        ]);
        return $statement->fetchAll();
    }

    public function create(array $data): int
    {
        $studentId = (int) $data['id_alumno'];
        return DatabaseLock::run(
            $this->db,
            'escuela:pago:alumno:' . $studentId,
            function () use ($data, $studentId): int {
                $this->db->beginTransaction();
                try {
                    $charges = $this->db->prepare(
                        'SELECT cm.id_cargo, cm.importe, cm.estado,
                                COALESCE((
                                    SELECT SUM(ap.importe_aplicado)
                                    FROM aplicaciones_pagos ap
                                    JOIN pagos px ON px.id_pago = ap.id_pago AND px.estado = "activo"
                                    WHERE ap.id_cargo = cm.id_cargo AND ap.id_alumno = cm.id_alumno
                                ), 0) AS importe_aplicado
                         FROM cargos_mensuales cm
                         WHERE cm.id_alumno = :id_alumno
                           AND cm.estado IN ("pendiente", "vencido")
                         ORDER BY cm.periodo, cm.id_cargo
                         FOR UPDATE'
                    );
                    $charges->execute(['id_alumno' => $studentId]);
                    $openCharges = $charges->fetchAll();
                    $outstanding = [];
                    $totalOutstanding = 0;
                    foreach ($openCharges as $charge) {
                        $remaining = self::toCents($charge['importe']) - self::toCents($charge['importe_aplicado']);
                        if ($remaining < 0) {
                            throw new DomainException('El cargo tiene aplicaciones superiores a su importe; requiere revisión manual.');
                        }
                        if ($remaining > 0) {
                            $outstanding[] = ['id_cargo' => (int) $charge['id_cargo'], 'remaining' => $remaining];
                            $totalOutstanding += $remaining;
                        }
                    }

                    $paymentCents = self::toCents($data['monto']);
                    if ($totalOutstanding === 0) {
                        throw new DomainException('No hay cargos mensuales pendientes para este alumno.');
                    }
                    if ($paymentCents > $totalOutstanding) {
                        throw new DomainException('El pago supera el saldo pendiente; los anticipos aún no tienen una regla definida.');
                    }

                    $insert = $this->db->prepare(
                        'INSERT INTO pagos
                            (id_alumno, concepto, monto, fecha_pago, metodo_pago, tipo_pago, estado,
                             nombre_pagador, id_alumno_pagador, id_usuario_registra)
                         VALUES
                            (:id_alumno, "Mensualidad", :monto, :fecha_pago, :metodo_pago, "mensualidad", "activo",
                             :nombre_pagador, :id_alumno_pagador, :id_usuario_registra)'
                    );
                    $insert->execute([
                        'id_alumno' => $studentId,
                        'monto' => self::fromCents($paymentCents),
                        'fecha_pago' => $data['fecha_pago'],
                        'metodo_pago' => $data['metodo_pago'],
                        'nombre_pagador' => $data['nombre_pagador'],
                        'id_alumno_pagador' => $data['id_alumno_pagador'],
                        'id_usuario_registra' => $data['id_usuario_registra'],
                    ]);
                    $paymentId = (int) $this->db->lastInsertId();
                    if ($paymentId < 1) {
                        throw new DomainException('La base de datos no devolvió un identificador de pago válido.');
                    }

                    $apply = $this->db->prepare(
                        'INSERT INTO aplicaciones_pagos
                            (id_pago, id_cargo, id_alumno, importe_aplicado)
                         VALUES (:id_pago, :id_cargo, :id_alumno, :importe_aplicado)'
                    );
                    $markPaid = $this->db->prepare(
                        'UPDATE cargos_mensuales SET estado = "pagado"
                         WHERE id_cargo = :id_cargo AND id_alumno = :id_alumno'
                    );
                    $remainingPayment = $paymentCents;
                    foreach ($outstanding as $charge) {
                        if ($remainingPayment === 0) {
                            break;
                        }
                        $applied = min($remainingPayment, $charge['remaining']);
                        $apply->execute([
                            'id_pago' => $paymentId,
                            'id_cargo' => $charge['id_cargo'],
                            'id_alumno' => $studentId,
                            'importe_aplicado' => self::fromCents($applied),
                        ]);
                        $remainingPayment -= $applied;
                        if ($applied === $charge['remaining']) {
                            $markPaid->execute(['id_cargo' => $charge['id_cargo'], 'id_alumno' => $studentId]);
                        }
                    }

                    $history = $this->db->prepare(
                        'INSERT INTO historial_pagos
                            (id_pago, estado_anterior, estado_nuevo, importe_anterior, importe_nuevo,
                             id_usuario_responsable, motivo)
                         VALUES (:id_pago, NULL, "activo", NULL, :importe, :id_usuario, "Registro de pago")'
                    );
                    $history->execute([
                        'id_pago' => $paymentId,
                        'importe' => self::fromCents($paymentCents),
                        'id_usuario' => $data['id_usuario_registra'],
                    ]);
                    $this->audit->record(
                        (int) $data['id_usuario_registra'],
                        'registrar',
                        'pagos',
                        $paymentId,
                        ['id_alumno' => $studentId]
                    );

                    $this->db->commit();
                    return $paymentId;
                } catch (Throwable $exception) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    throw $exception;
                }
            }
        );
    }

    public function cancel(int $paymentId, int $actorUserId, string $reason): bool
    {
        $lookup = $this->db->prepare('SELECT id_alumno FROM pagos WHERE id_pago = :id_pago');
        $lookup->execute(['id_pago' => $paymentId]);
        $studentValue = $lookup->fetchColumn();
        if ($studentValue === false) {
            return false;
        }
        $studentId = $studentValue === null ? null : (int) $studentValue;
        $lockName = $studentId === null
            ? 'escuela:pago:registro'
            : 'escuela:pago:alumno:' . $studentId;

        return DatabaseLock::run(
            $this->db,
            $lockName,
            function () use ($paymentId, $actorUserId, $reason, $studentId): bool {
                $this->db->beginTransaction();
                try {
                    $paymentQuery = $this->db->prepare(
                        'SELECT id_pago, id_alumno, monto, estado
                         FROM pagos WHERE id_pago = :id_pago FOR UPDATE'
                    );
                    $paymentQuery->execute(['id_pago' => $paymentId]);
                    $payment = $paymentQuery->fetch();
                    if ($payment === false) {
                        $this->db->commit();
                        return false;
                    }
                    if ($payment['estado'] !== 'activo') {
                        throw new DomainException('Solo se pueden cancelar pagos activos.');
                    }

                    $update = $this->db->prepare(
                        'UPDATE pagos SET estado = "cancelado" WHERE id_pago = :id_pago AND estado = "activo"'
                    );
                    $update->execute(['id_pago' => $paymentId]);
                    if ($update->rowCount() !== 1) {
                        throw new DomainException('No se pudo cancelar el pago; vuelve a consultar su estado.');
                    }

                    if ($studentId !== null) {
                        $chargeIds = $this->db->prepare(
                            'SELECT DISTINCT id_cargo FROM aplicaciones_pagos WHERE id_pago = :id_pago'
                        );
                        $chargeIds->execute(['id_pago' => $paymentId]);
                        $chargeQuery = $this->db->prepare(
                            'SELECT cm.importe, cm.fecha_vencimiento,
                                    COALESCE((
                                        SELECT SUM(ap.importe_aplicado)
                                        FROM aplicaciones_pagos ap
                                        JOIN pagos p ON p.id_pago = ap.id_pago AND p.estado = "activo"
                                        WHERE ap.id_cargo = cm.id_cargo
                                          AND ap.id_alumno = cm.id_alumno
                                    ), 0) AS importe_aplicado
                             FROM cargos_mensuales cm
                             WHERE cm.id_cargo = :id_cargo AND cm.id_alumno = :id_alumno
                             FOR UPDATE'
                        );
                        $setStatus = $this->db->prepare(
                            'UPDATE cargos_mensuales
                             SET estado = :estado
                             WHERE id_cargo = :id_cargo AND id_alumno = :id_alumno'
                        );
                        foreach ($chargeIds->fetchAll(PDO::FETCH_COLUMN) as $chargeId) {
                            $chargeQuery->execute([
                                'id_cargo' => (int) $chargeId,
                                'id_alumno' => $studentId,
                            ]);
                            $charge = $chargeQuery->fetch();
                            if ($charge === false) {
                                throw new DomainException('No se encontró un cargo aplicado; requiere revisión manual.');
                            }
                            $remaining = self::toCents($charge['importe']) - self::toCents($charge['importe_aplicado']);
                            if ($remaining < 0) {
                                throw new DomainException('El cargo quedó sobreaplicado; requiere revisión manual.');
                            }
                            $status = $remaining === 0
                                ? 'pagado'
                                : ($charge['fecha_vencimiento'] !== null && $charge['fecha_vencimiento'] < date('Y-m-d')
                                    ? 'vencido'
                                    : 'pendiente');
                            $setStatus->execute([
                                'estado' => $status,
                                'id_cargo' => (int) $chargeId,
                                'id_alumno' => $studentId,
                            ]);
                        }
                    }

                    $history = $this->db->prepare(
                        'INSERT INTO historial_pagos
                            (id_pago, estado_anterior, estado_nuevo, importe_anterior, importe_nuevo,
                             id_usuario_responsable, motivo)
                         VALUES (:id_pago, "activo", "cancelado", :importe, :importe, :id_usuario, :motivo)'
                    );
                    $history->execute([
                        'id_pago' => $paymentId,
                        'importe' => $payment['monto'],
                        'id_usuario' => $actorUserId,
                        'motivo' => $reason,
                    ]);
                    $this->audit->record($actorUserId, 'cancelar', 'pagos', $paymentId, ['motivo' => $reason]);
                    $this->db->commit();
                    return true;
                } catch (Throwable $exception) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    throw $exception;
                }
            }
        );
    }

    private static function toCents(string|int|float $amount): int
    {
        $normalized = trim((string) $amount);
        if (preg_match('/^\d+(?:\.\d{1,2})?$/D', $normalized) !== 1) {
            throw new DomainException('El importe debe ser positivo y tener como máximo dos decimales.');
        }
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private static function fromCents(int $amount): string
    {
        return intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
