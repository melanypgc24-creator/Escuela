<?php
declare(strict_types=1);

namespace App\Models;

use PDO;
use Throwable;

/**
 * Consultas del panel del alumno. Detecta qué tablas y columnas existen para que
 * una instalación que aún no aplicó todas las migraciones muestre secciones vacías
 * en lugar de errores. Los fallos reales se registran con detalle técnico y el
 * alumno solo recibe un aviso genérico.
 */
final class StudentPortalModel
{
    private array $tables = [];
    private array $columns = [];
    private array $failedSections = [];

    public function __construct(private PDO $db)
    {
    }

    public function failedSections(): array
    {
        return array_keys($this->failedSections);
    }

    public function profile(int $studentId): ?array
    {
        return $this->guard('perfil', null, function () use ($studentId): ?array {
            $select = ['a.id_alumno', 'a.nombre', 'a.apellidos', 'a.telefono', 'a.fecha_nacimiento', 'a.estatus', 'a.id_cinturon'];
            foreach (['matricula', 'correo_contacto', 'categoria', 'fecha_ingreso'] as $optional) {
                if ($this->hasColumn('alumnos', $optional)) {
                    $select[] = 'a.' . $optional;
                }
            }
            $beltJoin = '';
            if ($this->hasTable('cinturon') && $this->hasColumn('cinturon', 'color')) {
                $select[] = 'c.color AS color_cinturon';
                $beltJoin = ' LEFT JOIN cinturon c ON c.id_cinturon = a.id_cinturon';
            }
            $statement = $this->db->prepare(
                'SELECT ' . implode(', ', $select) . ' FROM alumnos a' . $beltJoin . ' WHERE a.id_alumno = :id LIMIT 1'
            );
            $statement->execute(['id' => $studentId]);
            $row = $statement->fetch();
            return $row ?: null;
        });
    }

    public function classes(int $studentId): array
    {
        if (!$this->hasTable('clases_alumnos') || !$this->hasTable('clases') || !$this->hasTable('profesor')) {
            return [];
        }
        return $this->guard('clases', [], fn (): array => (new ClassModel($this->db))->allForStudent($studentId));
    }

    public function attendance(int $studentId, ?int $limit = null): array
    {
        if (!$this->hasTable('asistencias') || !$this->hasTable('clases')) {
            return [];
        }
        return $this->guard('asistencias', [], function () use ($studentId, $limit): array {
            $hasTime = $this->hasColumn('asistencias', 'hora');
            $statement = $this->db->prepare(
                'SELECT a.id_asistencia, a.fecha, ' . ($hasTime ? 'a.hora' : 'NULL AS hora') . ', c.nombre_clase
                 FROM asistencias a
                 JOIN clases c ON c.id_clase = a.id_clase
                 WHERE a.id_alumno = :id_alumno
                 ORDER BY a.fecha DESC' . ($hasTime ? ', a.hora DESC' : '') . ', a.id_asistencia DESC'
                . ($limit !== null ? ' LIMIT ' . max(1, $limit) : '')
            );
            $statement->execute(['id_alumno' => $studentId]);
            return $statement->fetchAll();
        });
    }

    public function payments(int $studentId, ?int $limit = null): array
    {
        if (!$this->hasTable('pagos')) {
            return [];
        }
        return $this->guard('pagos', [], function () use ($studentId, $limit): array {
            $select = ['p.id_pago', 'p.id_alumno', 'p.concepto', 'p.monto', 'p.fecha_pago', 'p.metodo_pago'];
            foreach (['folio', 'tipo_pago', 'estado'] as $optional) {
                if ($this->hasColumn('pagos', $optional)) {
                    $select[] = 'p.' . $optional;
                }
            }
            $where = 'p.id_alumno = :student_charge';
            $params = ['student_charge' => $studentId];
            if ($this->hasColumn('pagos', 'id_alumno_pagador')) {
                $where .= ' OR p.id_alumno_pagador = :student_payer';
                $params['student_payer'] = $studentId;
            }
            $statement = $this->db->prepare(
                'SELECT DISTINCT ' . implode(', ', $select) . ' FROM pagos p WHERE ' . $where
                . ' ORDER BY p.fecha_pago DESC, p.id_pago DESC'
                . ($limit !== null ? ' LIMIT ' . max(1, $limit) : '')
            );
            $statement->execute($params);
            return $statement->fetchAll();
        });
    }

    public function debts(int $studentId): array
    {
        if (!$this->hasTable('cargos_mensuales') || !$this->hasTable('aplicaciones_pagos')
            || !$this->hasColumn('pagos', 'estado')) {
            return [];
        }
        return $this->guard('adeudos', [], fn (): array => (new DebtModel($this->db))->allForStudent($studentId));
    }

    public function notifications(int $userId): array
    {
        if (!$this->hasTable('notificaciones') || !$this->hasTable('cargos_mensuales')) {
            return [];
        }
        return $this->guard('notificaciones', [], fn (): array => (new NotificationModel($this->db))->allForUser($userId));
    }

    private function guard(string $section, mixed $default, callable $query): mixed
    {
        try {
            return $query();
        } catch (Throwable $exception) {
            $this->failedSections[$section] = true;
            error_log(sprintf(
                'Panel de alumno: falló la sección "%s": %s in %s:%d',
                $section,
                $exception->getMessage(),
                basename($exception->getFile()),
                $exception->getLine()
            ));
            return $default;
        }
    }

    private function hasTable(string $table): bool
    {
        if (!array_key_exists($table, $this->tables)) {
            try {
                $statement = $this->db->prepare(
                    'SELECT 1 FROM information_schema.tables
                     WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1'
                );
                $statement->execute(['table' => $table]);
                $this->tables[$table] = $statement->fetchColumn() !== false;
            } catch (Throwable $exception) {
                error_log('Panel de alumno: no se pudo inspeccionar la tabla ' . $table . ': ' . $exception->getMessage());
                $this->tables[$table] = false;
            }
        }
        return $this->tables[$table];
    }

    private function hasColumn(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (!array_key_exists($key, $this->columns)) {
            try {
                $statement = $this->db->prepare(
                    'SELECT 1 FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column LIMIT 1'
                );
                $statement->execute(['table' => $table, 'column' => $column]);
                $this->columns[$key] = $statement->fetchColumn() !== false;
            } catch (Throwable $exception) {
                error_log('Panel de alumno: no se pudo inspeccionar la columna ' . $key . ': ' . $exception->getMessage());
                $this->columns[$key] = false;
            }
        }
        return $this->columns[$key];
    }
}
