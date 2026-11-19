<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DatabaseLock;
use DomainException;
use PDO;
use Throwable;

final class EnrollmentModel
{
    private AuditModel $audit;

    public function __construct(private PDO $db)
    {
        $this->audit = new AuditModel($db);
    }

    public function all(?int $classId = null, ?int $professorId = null, ?int $studentId = null): array
    {
        $conditions = [];
        $params = [];
        if ($classId !== null) {
            $conditions[] = 'ca.id_clase = :id_clase';
            $params['id_clase'] = $classId;
        }
        if ($professorId !== null) {
            $conditions[] = 'cl.id_profesor = :id_profesor';
            $params['id_profesor'] = $professorId;
        }
        if ($studentId !== null) {
            $conditions[] = 'ca.id_alumno = :id_alumno';
            $params['id_alumno'] = $studentId;
        }
        if ($professorId !== null) {
            $conditions[] = 'ca.estado = "activa"';
        }
        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $statement = $this->db->prepare(
            'SELECT ca.id_inscripcion, ca.id_clase, cl.nombre_clase, cl.id_profesor,
                    ca.id_alumno, a.matricula, a.nombre, a.apellidos,
                    ca.fecha_inscripcion, ca.fecha_baja, ca.estado
             FROM clases_alumnos ca
             JOIN clases cl ON cl.id_clase = ca.id_clase
             JOIN alumnos a ON a.id_alumno = ca.id_alumno' . $where . '
             ORDER BY cl.nombre_clase, a.apellidos, a.nombre, ca.id_inscripcion'
        );
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public function create(int $classId, int $studentId, int $actorUserId): int
    {
        return DatabaseLock::run(
            $this->db,
            'escuela:clase:' . $classId,
            function () use ($classId, $studentId, $actorUserId): int {
                $this->db->beginTransaction();
                try {
                    $class = $this->db->prepare(
                        'SELECT capacidad FROM clases WHERE id_clase = :id_clase FOR UPDATE'
                    );
                    $class->execute(['id_clase' => $classId]);
                    $capacity = $class->fetchColumn();
                    if ($capacity === false) {
                        throw new DomainException('La clase seleccionada no existe.');
                    }

                    $count = $this->db->prepare(
                        'SELECT COUNT(*) FROM clases_alumnos
                         WHERE id_clase = :id_clase AND estado = "activa"'
                    );
                    $count->execute(['id_clase' => $classId]);
                    if ((int) $count->fetchColumn() >= (int) $capacity) {
                        throw new DomainException('La clase alcanzó su capacidad registrada.');
                    }

                    $insert = $this->db->prepare(
                        'INSERT INTO clases_alumnos
                            (id_clase, id_alumno, fecha_inscripcion, estado, id_usuario_registra)
                         VALUES (:id_clase, :id_alumno, CURRENT_DATE, "activa", :id_usuario)'
                    );
                    $insert->execute([
                        'id_clase' => $classId,
                        'id_alumno' => $studentId,
                        'id_usuario' => $actorUserId,
                    ]);
                    $enrollmentId = (int) $this->db->lastInsertId();
                    $this->audit->record($actorUserId, 'inscribir', 'clases_alumnos', $enrollmentId, [
                        'id_clase' => $classId,
                        'id_alumno' => $studentId,
                    ]);
                    $this->db->commit();
                    return $enrollmentId;
                } catch (Throwable $exception) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    throw $exception;
                }
            }
        );
    }

    public function deactivate(int $enrollmentId, int $actorUserId): bool
    {
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                'UPDATE clases_alumnos SET estado = "inactiva", fecha_baja = CURRENT_DATE
                 WHERE id_inscripcion = :id_inscripcion AND estado = "activa"'
            );
            $statement->execute(['id_inscripcion' => $enrollmentId]);
            if ($statement->rowCount() > 0) {
                $this->audit->record($actorUserId, 'desinscribir', 'clases_alumnos', $enrollmentId);
                $this->db->commit();
                return true;
            }

            $check = $this->db->prepare('SELECT 1 FROM clases_alumnos WHERE id_inscripcion = :id');
            $check->execute(['id' => $enrollmentId]);
            $exists = $check->fetchColumn() !== false;
            $this->db->commit();
            return $exists;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }
}
