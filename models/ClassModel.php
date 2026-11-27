<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class ClassModel
{
    public function __construct(private PDO $db)
    {
    }

    public function all(): array
    {
        return $this->fetchClasses(null, null);
    }

    public function allForProfessor(int $professorId): array
    {
        return $this->fetchClasses($professorId, null);
    }

    public function allForStudent(int $studentId): array
    {
        return $this->fetchClasses(null, $studentId);
    }

    public function studentsForProfessor(int $professorId): array
    {
        $students = (new StudentModel($this->db))->allForProfessor($professorId);
        foreach ($students as &$student) {
            unset($student['fecha_ingreso']);
        }
        unset($student);
        return $students;
    }

    private function fetchClasses(?int $professorId, ?int $studentId): array
    {
        $conditions = [];
        $params = [];
        if ($professorId !== null) {
            $conditions[] = 'cl.id_profesor = :id_profesor';
            $params['id_profesor'] = $professorId;
        }
        if ($studentId !== null) {
            $conditions[] = 'EXISTS (
                SELECT 1 FROM clases_alumnos ca
                WHERE ca.id_clase = cl.id_clase
                  AND ca.id_alumno = :id_alumno
                  AND ca.estado = "activa"
            )';
            $params['id_alumno'] = $studentId;
        }
        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $statement = $this->db->prepare(
            'SELECT cl.id_clase, cl.nombre_clase, cl.categoria, cl.id_profesor,
                    CONCAT(p.nombre, " ", p.apellidos) AS profesor,
                    cl.dias, cl.hora_inicio, cl.hora_fin, cl.capacidad
             FROM clases cl
             JOIN profesor p ON p.id_profesor = cl.id_profesor' . $where . '
             ORDER BY cl.nombre_clase, cl.hora_inicio'
        );
        $statement->execute($params);
        return $statement->fetchAll();
    }
}
