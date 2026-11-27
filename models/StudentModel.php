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

    public function all(array $filters = [], int $page = 1, int $perPage = 25, ?int $professorId = null): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $where = [];
        $params = [];

        if (($filters['q'] ?? '') !== '') {
            $term = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($filters['q'])) . '%';
            $expressions = ['a.nombre', 'a.apellidos', "CONCAT(a.nombre, ' ', a.apellidos)", 'a.matricula',
                'a.telefono', 'c.color'];
            if ($professorId === null) {
                $expressions[] = 'a.correo_contacto';
            }
            $matches = [];
            foreach ($expressions as $index => $expression) {
                $matches[] = "$expression LIKE :q$index ESCAPE '!'";
                $params["q$index"] = $term;
            }
            if ($professorId === null) {
                $matches[] = "EXISTS (SELECT 1 FROM usuarios u WHERE u.id_alumno = a.id_alumno AND u.correo LIKE :q_usuario ESCAPE '!')";
                $params['q_usuario'] = $term;
            }
            $where[] = '(' . implode(' OR ', $matches) . ')';
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
        if (isset($filters['id_cinturon'])) {
            $where[] = 'a.id_cinturon = :id_cinturon';
            $params['id_cinturon'] = $filters['id_cinturon'];
        }
        if ($professorId !== null || isset($filters['id_clase'])) {
            $conditions = [];
            if ($professorId !== null) {
                $conditions[] = 'cl.id_profesor = :profesor';
                $params['profesor'] = $professorId;
            }
            if (isset($filters['id_clase'])) {
                $conditions[] = 'cl.id_clase = :id_clase';
                $params['id_clase'] = $filters['id_clase'];
            }
            $where[] = self::assignmentCondition(implode(' AND ', $conditions));
        }
        if (isset($filters['estado_pago'])) {
            $where[] = 'COALESCE(s.estado_pago, "sin_cargos") = :estado_pago';
            $params['estado_pago'] = $filters['estado_pago'] === 'pagado' ? 'al_corriente' : $filters['estado_pago'];
        }

        $summary = ' LEFT JOIN (
            SELECT d.id_alumno, SUM(GREATEST(d.saldo, 0)) AS saldo_pendiente,
                CASE WHEN MAX(d.saldo > 0 AND d.fecha_vencimiento < CURRENT_DATE) = 1 THEN "vencido"
                     WHEN MAX(d.saldo > 0 AND d.aplicado > 0) = 1 THEN "parcial"
                     WHEN MAX(d.saldo > 0) = 1 THEN "pendiente"
                     ELSE "al_corriente" END AS estado_pago
            FROM (
                SELECT cm.id_alumno, cm.fecha_vencimiento, cm.importe,
                       COALESCE(ap.aplicado, 0) AS aplicado,
                       cm.importe - COALESCE(ap.aplicado, 0) AS saldo
                FROM cargos_mensuales cm
                LEFT JOIN (
                    SELECT x.id_cargo, x.id_alumno, SUM(x.importe_aplicado) AS aplicado
                    FROM aplicaciones_pagos x JOIN pagos p ON p.id_pago = x.id_pago AND p.estado = "activo"
                    GROUP BY x.id_cargo, x.id_alumno
                ) ap ON ap.id_cargo = cm.id_cargo AND ap.id_alumno = cm.id_alumno
            ) d GROUP BY d.id_alumno
        ) s ON s.id_alumno = a.id_alumno';
        $from = ' FROM alumnos a LEFT JOIN cinturon c ON c.id_cinturon = a.id_cinturon' . $summary;
        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $count = $this->db->prepare('SELECT COUNT(*)' . $from . $clause);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $financial = $professorId === null
            ? ', (SELECT GROUP_CONCAT(u.correo ORDER BY u.correo SEPARATOR ", ") FROM usuarios u WHERE u.id_alumno = a.id_alumno) AS correo_cuenta,
                 COALESCE(s.estado_pago, "sin_cargos") AS estado_pago, COALESCE(s.saldo_pendiente, 0) AS saldo_pendiente'
            : ', CASE WHEN a.estatus <> 1 THEN 0 WHEN s.id_alumno IS NULL THEN NULL
                       WHEN s.saldo_pendiente > 0 THEN 0 ELSE 1 END AS habilitado';
        $query = $this->db->prepare(
            'SELECT a.id_alumno, a.matricula, a.nombre, a.apellidos, a.telefono,
                    a.correo_contacto, a.fecha_nacimiento, a.fecha_ingreso, a.estatus,
                    a.categoria, a.id_cinturon, c.color AS color_cinturon' . $financial . $from . $clause . '
             ORDER BY a.apellidos, a.nombre, a.id_alumno
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

    private static function assignmentCondition(string $classCondition): string
    {
        return 'EXISTS (SELECT 1 FROM clases cl WHERE ' . $classCondition . '
            AND (EXISTS (SELECT 1 FROM clases_alumnos ca WHERE ca.id_clase = cl.id_clase
                         AND ca.id_alumno = a.id_alumno AND ca.estado = "activa")
                 OR EXISTS (SELECT 1 FROM inscripciones_clases ic WHERE ic.id_clase = cl.id_clase
                            AND ic.id_alumno = a.id_alumno AND ic.estado = "aceptada")))';
    }

    public function findForBelt(int $id, ?int $professorId): ?array
    {
        $where = $professorId === null ? '' : ' AND ' . self::assignmentCondition('cl.id_profesor = :profesor');
        $statement = $this->db->prepare(
            'SELECT a.id_alumno, a.matricula, a.nombre, a.apellidos, a.id_cinturon, c.color AS color_cinturon
             FROM alumnos a LEFT JOIN cinturon c ON c.id_cinturon = a.id_cinturon
             WHERE a.id_alumno = :id' . $where
        );
        $params = ['id' => $id];
        if ($professorId !== null) {
            $params['profesor'] = $professorId;
        }
        $statement->execute($params);
        $student = $statement->fetch();
        return $student ?: null;
    }

    public function updateBelt(int $id, ?int $beltId, int $actorUserId, ?int $professorId): bool
    {
        $where = $professorId === null ? '' : ' AND ' . self::assignmentCondition('cl.id_profesor = :profesor');
        $params = ['id' => $id, 'cinturon' => $beltId];
        if ($professorId !== null) {
            $params['profesor'] = $professorId;
        }
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                'UPDATE alumnos AS a SET id_cinturon = :cinturon WHERE a.id_alumno = :id' . $where
            );
            $statement->execute($params);
            $changed = $statement->rowCount() > 0;
            $exists = $changed || $this->findForBelt($id, $professorId) !== null;
            if ($changed) {
                $this->audit->record($actorUserId, 'actualizar', 'alumnos', $id, [
                    'campos' => ['id_cinturon'],
                    'id_cinturon' => $beltId,
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

    public function allForProfessor(int $professorId): array
    {
        $statement = $this->db->prepare(
            'SELECT DISTINCT a.id_alumno, a.matricula, a.nombre, a.apellidos, a.telefono,
                    a.correo_contacto, a.fecha_nacimiento, a.fecha_ingreso, a.estatus,
                    a.categoria, a.id_cinturon, c.color AS color_cinturon
             FROM alumnos a
             LEFT JOIN cinturon c ON c.id_cinturon = a.id_cinturon
             WHERE ' . self::assignmentCondition('cl.id_profesor = :id_profesor') . '
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
        $beltUpdate = array_key_exists('id_cinturon', $data) ? ', id_cinturon = :id_cinturon' : '';
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                'UPDATE alumnos
                 SET nombre = :nombre, apellidos = :apellidos, telefono = :telefono,
                     correo_contacto = :correo_contacto, direccion = :direccion,
                     fecha_nacimiento = :fecha_nacimiento,
                     fecha_ingreso = COALESCE(:fecha_ingreso, fecha_ingreso),
                     estatus = :estatus,
                     categoria = :categoria' . $beltUpdate . '
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

    public function updateOwnProfile(int $id, int $actorUserId, array $data): bool
    {
        $params = [
            'nombre' => $data['nombre'],
            'apellidos' => $data['apellidos'],
            'telefono' => $data['telefono'],
            'fecha_nacimiento' => $data['fecha_nacimiento'],
            'correo_contacto' => $data['correo_contacto'],
            'direccion' => $data['direccion'],
            'id' => $id,
            'usuario' => $actorUserId,
        ];
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                'UPDATE alumnos AS a
                 SET nombre = :nombre, apellidos = :apellidos, telefono = :telefono,
                     fecha_nacimiento = :fecha_nacimiento, correo_contacto = :correo_contacto, direccion = :direccion
                 WHERE a.id_alumno = :id AND EXISTS (
                     SELECT 1 FROM usuarios u WHERE u.id_usuario = :usuario
                     AND u.id_alumno = a.id_alumno AND u.rol = "alumno"
                 )'
            );
            $statement->execute($params);
            $changed = $statement->rowCount() > 0;
            if (!$changed) {
                $owner = $this->db->prepare(
                    'SELECT 1 FROM alumnos a JOIN usuarios u ON u.id_alumno = a.id_alumno
                     WHERE a.id_alumno = :id AND u.id_usuario = :usuario AND u.rol = "alumno"'
                );
                $owner->execute(['id' => $id, 'usuario' => $actorUserId]);
                $exists = $owner->fetchColumn() !== false;
            } else {
                $exists = true;
                $this->audit->record($actorUserId, 'actualizar', 'alumnos', $id, [
                    'campos' => ['nombre', 'apellidos', 'telefono', 'fecha_nacimiento', 'correo_contacto', 'direccion'],
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
