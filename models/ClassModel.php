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
        $statement = $this->db->prepare(
            'SELECT DISTINCT a.id_alumno, a.matricula, a.nombre, a.apellidos, a.telefono,
                    a.correo_contacto, a.fecha_nacimiento, a.estatus, a.categoria,
                    a.id_cinturon, c.color AS color_cinturon
             FROM clases_alumnos ca
             JOIN clases cl ON cl.id_clase = ca.id_clase
             JOIN alumnos a ON a.id_alumno = ca.id_alumno
             LEFT JOIN cinturon c ON c.id_cinturon = a.id_cinturon
             WHERE cl.id_profesor = :id_profesor
               AND ca.estado = "activa"
             ORDER BY a.apellidos, a.nombre'
        );
        $statement->execute(['id_profesor' => $professorId]);
        return $statement->fetchAll();
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
            'SELECT cl.id_clase, cl.nombre_clase, cl.id_profesor,
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
