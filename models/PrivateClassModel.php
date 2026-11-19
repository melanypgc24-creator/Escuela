<?php
declare(strict_types=1);

namespace App\Models;

use DomainException;
use PDO;
use Throwable;

final class PrivateClassModel
{
    private const PRICE_PER_PARTICIPANT_CENTS = 15000;
    private AuditModel $audit;

    public function __construct(private PDO $db)
    {
        $this->audit = new AuditModel($db);
    }

    public function all(): array
    {
        return $this->db->query(
            'SELECT s.id_sesion, s.id_profesor, s.fecha_hora, s.precio_unitario,
                    s.cantidad_participantes, s.total_historico, p.id_pago,
                    p.estado AS estado_pago
             FROM clases_privadas s
             LEFT JOIN pagos p ON p.id_sesion_privada = s.id_sesion AND p.estado = "activo"
             ORDER BY s.fecha_hora DESC, s.id_sesion DESC'
        )->fetchAll();
    }

    public function allForStudent(int $studentId): array
    {
        $statement = $this->db->prepare(
            'SELECT s.id_sesion, s.fecha_hora, cpa.nombre_participante, cpa.precio_historico
             FROM clases_privadas_alumnos cpa
             JOIN clases_privadas s ON s.id_sesion = cpa.id_sesion
             WHERE cpa.id_alumno = :id_alumno
             ORDER BY s.fecha_hora DESC, s.id_sesion DESC'
        );
        $statement->execute(['id_alumno' => $studentId]);
        return $statement->fetchAll();
    }

    public function create(array $data): array
    {
        $participants = $data['participantes'];
        if ($participants === []) {
            throw new DomainException('La sesión debe tener al menos un participante.');
        }

        $totalCents = count($participants) * self::PRICE_PER_PARTICIPANT_CENTS;
        if ($totalCents > 9999999999) {
            throw new DomainException('La cantidad de participantes excede el máximo monetario admitido.');
        }
        $this->db->beginTransaction();
        try {
            $session = $this->db->prepare(
                'INSERT INTO clases_privadas
                    (id_profesor, fecha_hora, precio_unitario, cantidad_participantes,
                     total_historico, observaciones, id_usuario_registra)
                 VALUES (:id_profesor, :fecha_hora, 150.00, :cantidad, :total, :observaciones, :id_usuario)'
            );
            $session->execute([
                'id_profesor' => $data['id_profesor'],
                'fecha_hora' => $data['fecha_hora'],
                'cantidad' => count($participants),
                'total' => self::fromCents($totalCents),
                'observaciones' => $data['observaciones'],
                'id_usuario' => $data['id_usuario_registra'],
            ]);
            $sessionId = (int) $this->db->lastInsertId();
            if ($sessionId < 1) {
                throw new DomainException('La base de datos no devolvió un identificador de sesión válido.');
            }

            $participantInsert = $this->db->prepare(
                'INSERT INTO clases_privadas_alumnos
                    (id_sesion, id_alumno, nombre_participante, precio_historico)
                 VALUES (:id_sesion, :id_alumno, :nombre, 150.00)'
            );
            $lookupStudent = $this->db->prepare(
                'SELECT nombre, apellidos FROM alumnos WHERE id_alumno = :id_alumno'
            );
            foreach ($participants as $participant) {
                $studentId = $participant['id_alumno'];
                $name = $participant['nombre_participante'];
                if (!is_int($studentId) || $studentId < 0) {
                    throw new DomainException('Cada participante debe tener una ficha de alumno hasta que se definan las reglas para participantes sin ficha.');
                }
                $lookupStudent->execute(['id_alumno' => $studentId]);
                $student = $lookupStudent->fetch();
                if ($student === false) {
                    throw new DomainException('Uno de los participantes seleccionados no existe.');
                }
                $name = trim($student['nombre'] . ' ' . $student['apellidos']);
                $participantInsert->execute([
                    'id_sesion' => $sessionId,
                    'id_alumno' => $studentId,
                    'nombre' => $name,
                ]);
            }

            $payment = $this->db->prepare(
                'INSERT INTO pagos
                    (id_alumno, concepto, monto, fecha_pago, metodo_pago, tipo_pago, estado,
                     nombre_pagador, id_alumno_pagador, id_usuario_registra, id_sesion_privada)
                 VALUES
                    (NULL, :concepto, :monto, :fecha_pago, :metodo_pago, "clase_privada", "activo",
                     :nombre_pagador, :id_alumno_pagador, :id_usuario, :id_sesion)'
            );
            $payment->execute([
                'concepto' => 'Clase privada #' . $sessionId,
                'monto' => self::fromCents($totalCents),
                'fecha_pago' => substr($data['fecha_hora'], 0, 10),
                'metodo_pago' => $data['metodo_pago'],
                'nombre_pagador' => $data['nombre_pagador'],
                'id_alumno_pagador' => $data['id_alumno_pagador'],
                'id_usuario' => $data['id_usuario_registra'],
                'id_sesion' => $sessionId,
            ]);
            $paymentId = (int) $this->db->lastInsertId();
            if ($paymentId < 1) {
                throw new DomainException('La base de datos no devolvió un identificador de pago válido.');
            }

            $history = $this->db->prepare(
                'INSERT INTO historial_pagos
                    (id_pago, estado_anterior, estado_nuevo, importe_anterior, importe_nuevo,
                     id_usuario_responsable, motivo)
                 VALUES (:id_pago, NULL, "activo", NULL, :importe, :id_usuario, "Registro de clase privada")'
            );
            $history->execute([
                'id_pago' => $paymentId,
                'importe' => self::fromCents($totalCents),
                'id_usuario' => $data['id_usuario_registra'],
            ]);
            $this->audit->record(
                (int) $data['id_usuario_registra'],
                'crear',
                'clases_privadas',
                $sessionId,
                ['participantes' => count($participants), 'id_pago' => $paymentId]
            );

            $this->db->commit();
            return ['id_sesion' => $sessionId, 'id_pago' => $paymentId];
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private static function fromCents(int $amount): string
    {
        return intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
