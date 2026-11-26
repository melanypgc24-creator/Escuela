<?php
declare(strict_types=1);

namespace App\Models;

use PDO;
use RuntimeException;

final class StudentModel
{
    private AuditModel $audit;

    public function __construct(private PDO $db)
    {
        $this->audit = new AuditModel($db);
    }

    public function all(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $where = [];
        $params = [];

        if (isset($filters['q']) && trim((string) $filters['q']) !== '') {
            $where[] = '(a.nombre LIKE :nombre_q OR a.apellidos LIKE :apellidos_q OR a.matricula LIKE :matricula_q OR a.correo_contacto LIKE :correo_q OR a.telefono LIKE :telefono_q OR EXISTS (SELECT 1 FROM cinturon cq WHERE cq.id_cinturon = a.id_cinturon AND cq.color LIKE :cinturon_q))';
            $term = '%' . trim((string) $filters['q']) . '%';
            $params += [
                'nombre_q' => $term,
                'apellidos_q' => $term,
                'matricula_q' => $term,
                'correo_q' => $term,
                'telefono_q' => $term,
                'cinturon_q' => $term,
            ];
        }
        if (isset($filters['estatus']) && in_array($filters['estatus'], ['activo', 'inactivo'], true)) {
            $where[] = 'a.estatus = :estatus';
            $params['estatus'] = $filters['estatus'] === 'activo' ? 1 : 0;
        }
        if (isset($filters['categoria']) && in_array($filters['categoria'], ['infantil', 'adulta'], true)) {
            $where[] = 'a.categoria = :categoria';
            $params['categoria'] = $filters['categoria'];
        }
        if (isset($filters['cinturon']) && trim((string) $filters['cinturon']) !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM cinturon cf WHERE cf.id_cinturon = a.id_cinturon AND cf.color = :color_cinturon)';
            $params['color_cinturon'] = trim((string) $filters['cinturon']);
        }
        if (isset($filters['id_clase']) && is_int($filters['id_clase']) && $filters['id_clase'] >= 0) {
            $where[] = 'EXISTS (
                SELECT 1 FROM clases_alumnos ca
                WHERE ca.id_alumno = a.id_alumno
                  AND ca.id_clase = :id_clase
                  AND ca.estado = "activa"
            )';
            $params['id_clase'] = $filters['id_clase'];
        }
        if (isset($filters['estado_pago']) && in_array($filters['estado_pago'], ['pendiente', 'pagado', 'vencido'], true)) {
            $estado = $filters['estado_pago'];
            $where[] = match ($estado) {
                'pendiente' => "EXISTS (SELECT 1 FROM cargos_mensuales cm WHERE cm.id_alumno = a.id_alumno AND cm.estado = 'pendiente')",
                'pagado' => "EXISTS (SELECT 1 FROM cargos_mensuales cm WHERE cm.id_alumno = a.id_alumno AND cm.estado = 'pagado')",
                'vencido' => "EXISTS (SELECT 1 FROM cargos_mensuales cm WHERE cm.id_alumno = a.id_alumno AND cm.fecha_vencimiento < CURRENT_DATE AND cm.estado = 'vencido')",
            };
        }

        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $count = $this->db->prepare('SELECT COUNT(*) FROM alumnos a' . $clause);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $query = $this->db->prepare(
            'SELECT a.id_alumno, a.matricula, a.nombre, a.apellidos, a.telefono,
                    a.correo_contacto, a.fecha_nacimiento, a.fecha_ingreso, a.estatus,
                    a.categoria, a.id_cinturon, c.color AS color_cinturon
             FROM alumnos a
             LEFT JOIN cinturon c ON c.id_cinturon = a.id_cinturon' . $clause . '
             ORDER BY a.apellidos, a.nombre
             LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $query->bindValue(':' . $key, $value);
        }
        $query->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $query->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $query->execute();

        return [
            'data' => $query->fetchAll(),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ];
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT a.id_alumno, a.matricula, a.nombre, a.apellidos, a.telefono,
                    a.correo_contacto, a.direccion, a.fecha_nacimiento, a.fecha_ingreso,
                    a.estatus, a.categoria, a.id_cinturon, c.color AS color_cinturon
             FROM alumnos a
             LEFT JOIN cinturon c ON c.id_cinturon = a.id_cinturon
             WHERE a.id_alumno = :id'
        );
        $statement->execute(['id' => $id]);
        $student = $statement->fetch();
        return $student ?: null;
    }

    public function allForProfessor(int $professorId): array
    {
        $statement = $this->db->prepare(
            'SELECT DISTINCT a.id_alumno, a.matricula, a.nombre, a.apellidos, a.telefono,
                    a.correo_contacto, a.fecha_nacimiento, a.fecha_ingreso, a.estatus,
                    a.categoria, a.id_cinturon, c.color AS color_cinturon
             FROM clases_alumnos ca
             JOIN clases cl ON cl.id_clase = ca.id_clase
             JOIN alumnos a ON a.id_alumno = ca.id_alumno
             LEFT JOIN cinturon c ON c.id_cinturon = a.id_cinturon
             WHERE cl.id_profesor = :id_profesor AND ca.estado = "activa"
             ORDER BY a.apellidos, a.nombre'
        );
        $statement->execute(['id_profesor' => $professorId]);
        return $statement->fetchAll();
    }

    public function create(array $data, int $actorUserId): int
    {
        $this->db->beginTransaction();
        try {
            $placeholder = 'TMP-' . bin2hex(random_bytes(8));
            $statement = $this->db->prepare(
                'INSERT INTO alumnos
                    (matricula, nombre, apellidos, telefono, correo_contacto, direccion,
                     fecha_nacimiento, fecha_ingreso, estatus, categoria, id_cinturon)
                 VALUES
                    (:matricula, :nombre, :apellidos, :telefono, :correo_contacto, :direccion,
                     :fecha_nacimiento, :fecha_ingreso, :estatus, :categoria, :id_cinturon)'
            );
            $statement->execute($data + [
                'matricula' => $placeholder,
                'fecha_ingreso' => date('Y-m-d'),
            ]);

            $id = (int) $this->db->lastInsertId();
            if ($id < 1) {
                throw new RuntimeException('La base de datos no devolvió un identificador válido para el alumno.');
            }

            $matricula = 'TC-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
            $update = $this->db->prepare('UPDATE alumnos SET matricula = :matricula WHERE id_alumno = :id');
            $update->execute(['matricula' => $matricula, 'id' => $id]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('No se pudo asignar la matrícula al alumno.');
            }
            $this->audit->record($actorUserId, 'crear', 'alumnos', $id, ['matricula' => $matricula]);
            $this->db->commit();
            // El primer cargo se genera el día de la inscripción (fuera de la transacción: no debe romperla).
            (new MonthlyChargeModel($this->db))->createOnEnrollment($id, $actorUserId);
            return $id;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function update(int $id, array $data, int $actorUserId): bool
    {
        $data['id'] = $id;
        $data['fecha_ingreso'] = $data['fecha_ingreso'] ?? null;
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                'UPDATE alumnos
                 SET nombre = :nombre, apellidos = :apellidos, telefono = :telefono,
                     correo_contacto = :correo_contacto, direccion = :direccion,
                     fecha_nacimiento = :fecha_nacimiento,
                     fecha_ingreso = COALESCE(:fecha_ingreso, fecha_ingreso),
                     estatus = :estatus,
                     categoria = :categoria, id_cinturon = :id_cinturon
                 WHERE id_alumno = :id'
            );
            $statement->execute($data);
            $exists = $statement->rowCount() > 0 || $this->find($id) !== null;
            if ($exists && $statement->rowCount() > 0) {
                $this->audit->record($actorUserId, 'actualizar', 'alumnos', $id, [
                    'campos' => array_values(array_diff(array_keys($data), ['id'])),
                ]);
            }
            $this->db->commit();
            return $exists;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function delete(int $id, int $actorUserId): bool
    {
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare('UPDATE alumnos SET estatus = 0 WHERE id_alumno = :id AND estatus <> 0');
            $statement->execute(['id' => $id]);
            $exists = $statement->rowCount() > 0 || $this->find($id) !== null;
            if ($statement->rowCount() > 0) {
                $this->audit->record($actorUserId, 'desactivar', 'alumnos', $id);
            }
            $this->db->commit();
            return $exists;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }
}
