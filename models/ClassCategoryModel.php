<?php
declare(strict_types=1);

namespace App\Models;

use DomainException;
use PDO;
use Throwable;

final class ClassCategoryModel
{
    public function __construct(private PDO $db)
    {
    }

    public static function studentCategorySql(string $alias): string
    {
        return "CASE WHEN {$alias}.fecha_nacimiento BETWEEN '1900-01-01' AND CURRENT_DATE
                THEN CASE WHEN TIMESTAMPDIFF(YEAR, {$alias}.fecha_nacimiento, CURRENT_DATE) >= 18
                     THEN 'adulta' ELSE 'infantil' END ELSE NULL END";
    }

    public function requireCompatible(int $classId, int $studentId): void
    {
        $category = self::studentCategorySql('a');
        $statement = $this->db->prepare(
            "SELECT c.categoria, {$category} AS categoria_alumno
             FROM clases c JOIN alumnos a ON a.id_alumno = :alumno
             WHERE c.id_clase = :clase FOR UPDATE"
        );
        $statement->execute(['alumno' => $studentId, 'clase' => $classId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new DomainException('La clase o el alumno no existe.');
        }
        if ($row['categoria'] === null) {
            throw new DomainException('La clase aún no tiene categoría asignada.');
        }
        if ($row['categoria_alumno'] === null) {
            throw new DomainException('Se necesita una fecha de nacimiento válida para inscribirse.');
        }
        if ($row['categoria'] !== $row['categoria_alumno']) {
            throw new DomainException('La categoría de la clase no corresponde a la edad del alumno.');
        }
    }

    public function cancelIncompatible(int $actorUserId): void
    {
        $category = self::studentCategorySql('a');
        $this->db->beginTransaction();
        try {
            $audit = new AuditModel($this->db);
            foreach ([
                ['inscripciones_clases', 'ic', 'ic.estado IN ("pendiente", "aceptada")', 'cancelada'],
                ['clases_alumnos', 'ca', 'ca.estado = "activa"', 'inactiva'],
            ] as [$table, $alias, $active, $newState]) {
                $rows = $this->db->query(
                    "SELECT {$alias}.id_inscripcion, {$alias}.id_alumno, {$alias}.id_clase, {$alias}.estado
                     FROM {$table} {$alias}
                     JOIN alumnos a ON a.id_alumno = {$alias}.id_alumno
                     JOIN clases c ON c.id_clase = {$alias}.id_clase
                     WHERE {$active} AND c.categoria IS NOT NULL
                       AND {$category} IS NOT NULL AND c.categoria <> {$category}
                     FOR UPDATE"
                )->fetchAll();
                $dates = $table === 'clases_alumnos'
                    ? ', fecha_baja = CURRENT_DATE'
                    : ', fecha_resolucion = CURRENT_TIMESTAMP';
                $update = $this->db->prepare(
                    "UPDATE {$table} SET estado = :estado{$dates} WHERE id_inscripcion = :id"
                );
                foreach ($rows as $row) {
                    $update->execute(['estado' => $newState, 'id' => $row['id_inscripcion']]);
                    $audit->record($actorUserId, 'cancelar_categoria', $table, (int) $row['id_inscripcion'], [
                        'id_alumno' => (int) $row['id_alumno'],
                        'id_clase' => (int) $row['id_clase'],
                        'estado_anterior' => $row['estado'],
                        'motivo' => 'Cambio de categoría por edad o categoría de clase',
                    ]);
                }
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }
}
