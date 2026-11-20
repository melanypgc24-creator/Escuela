<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class AttendanceModel
{
    private AuditModel $audit;

    public function __construct(private PDO $db)
    {
        $this->audit = new AuditModel($db);
    }

    public function all(): array
    {
        return $this->fetchAttendance(null, null);
    }

    public function allForProfessor(int $professorId): array
    {
        return $this->fetchAttendance($professorId, null);
    }

    public function allForStudent(int $studentId): array
    {
        return $this->fetchAttendance(null, $studentId);
    }

    private function fetchAttendance(?int $professorId, ?int $studentId): array
    {
        $conditions = [];
        $params = [];
        if ($professorId !== null) {
            $conditions[] = 'c.id_profesor = :id_profesor';
            $params['id_profesor'] = $professorId;
        }
        if ($studentId !== null) {
            $conditions[] = 'a.id_alumno = :id_alumno';
            $params['id_alumno'] = $studentId;
        }
        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $statement = $this->db->prepare(
            'SELECT a.id_asistencia, a.id_alumno, CONCAT(al.nombre, " ", al.apellidos) AS alumno,
                    a.id_clase, c.nombre_clase, a.fecha, a.hora, a.id_usuario_registra
             FROM asistencias a
             JOIN alumnos al ON al.id_alumno = a.id_alumno
             JOIN clases c ON c.id_clase = a.id_clase' . $where . '
             ORDER BY a.fecha DESC, a.hora DESC, a.id_asistencia DESC'
        );
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public function create(
        int $studentId,
        int $classId,
        string $date,
        string $time,
        int $userId,
        ?int $professorId = null
    ): int
    {
        $this->db->beginTransaction();
        try {
            $billingTablesPresent = (int) $this->db->query(
                "SELECT COUNT(*)
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name IN ('cargos_mensuales', 'aplicaciones_pagos', 'pagos')"
            )->fetchColumn() === 3;
            if ($billingTablesPresent) {
                $pendingDebtCheck = $this->db->prepare(
                    'SELECT COUNT(*)
                     FROM (
                        SELECT cm.id_cargo,
                               cm.importe - COALESCE((
                                   SELECT SUM(ap.importe_aplicado)
                                   FROM aplicaciones_pagos ap
                                   JOIN pagos p ON p.id_pago = ap.id_pago AND p.estado = "activo"
                                   WHERE ap.id_cargo = cm.id_cargo
                                     AND ap.id_alumno = cm.id_alumno
                               ), 0) AS saldo_pendiente
                        FROM cargos_mensuales cm
                        WHERE cm.id_alumno = :id_alumno
                          AND cm.estado IN ("pendiente", "vencido")
                     ) pendientes
                     WHERE pendientes.saldo_pendiente > 0'
                );
                $pendingDebtCheck->execute(['id_alumno' => $studentId]);
                if ((int) $pendingDebtCheck->fetchColumn() > 0) {
                    throw new \DomainException(
                        'No se puede registrar asistencia porque el alumno tiene adeudos pendientes o vencidos.'
                    );
                }
            }

            if ($professorId !== null) {
                $enrollment = $this->db->prepare(
                    'SELECT ca.id_inscripcion
                     FROM clases_alumnos ca
                     JOIN clases c ON c.id_clase = ca.id_clase
                     WHERE ca.id_clase = :id_clase
                       AND ca.id_alumno = :id_alumno
                       AND ca.estado = "activa"
                       AND c.id_profesor = :id_profesor
                     FOR UPDATE'
                );
                $enrollment->execute([
                    'id_clase' => $classId,
                    'id_alumno' => $studentId,
                    'id_profesor' => $professorId,
                ]);
                if ($enrollment->fetchColumn() === false) {
                    throw new \DomainException('El alumno no está inscrito en una clase asignada a este maestro.');
                }
            }

            $statement = $this->db->prepare(
                'INSERT INTO asistencias (id_alumno, id_clase, fecha, hora, id_usuario_registra)
                 VALUES (:id_alumno, :id_clase, :fecha, :hora, :id_usuario_registra)'
            );
            $statement->execute([
                'id_alumno' => $studentId,
                'id_clase' => $classId,
                'fecha' => $date,
                'hora' => $time,
                'id_usuario_registra' => $userId,
            ]);
            $id = (int) $this->db->lastInsertId();
            $this->audit->record($userId, 'registrar', 'asistencias', $id, [
                'id_alumno' => $studentId,
                'id_clase' => $classId,
                'fecha' => $date,
                'hora' => $time,
            ]);
            $this->db->commit();
            return $id;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function update(
        int $attendanceId,
        string $date,
        string $time,
        int $userId,
        ?int $professorId = null
    ): bool
    {
        $this->db->beginTransaction();
        try {
            $selectSql = 'SELECT a.id_alumno, a.id_clase, a.fecha, a.hora
                          FROM asistencias a JOIN clases c ON c.id_clase = a.id_clase
                          WHERE a.id_asistencia = :id_asistencia';
            $params = ['id_asistencia' => $attendanceId];
            if ($professorId !== null) {
                $selectSql .= ' AND c.id_profesor = :id_profesor';
                $params['id_profesor'] = $professorId;
            }
            $selectSql .= ' FOR UPDATE';
            $select = $this->db->prepare($selectSql);
            $select->execute($params);
            $old = $select->fetch();
            if ($old === false) {
                $this->db->commit();
                return false;
            }

            $update = $this->db->prepare(
                'UPDATE asistencias SET fecha = :fecha, hora = :hora WHERE id_asistencia = :id'
            );
            $update->execute(['fecha' => $date, 'hora' => $time, 'id' => $attendanceId]);
            if ($update->rowCount() > 0) {
                $this->audit->record($userId, 'corregir', 'asistencias', $attendanceId, [
                    'antes' => ['fecha' => $old['fecha'], 'hora' => $old['hora']],
                    'despues' => ['fecha' => $date, 'hora' => $time],
                ]);
            }
            $this->db->commit();
            return true;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }
}
