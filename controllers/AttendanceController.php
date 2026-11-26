<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\JsonResponse;
use App\Core\Request;
use App\Models\AttendanceModel;
use DomainException;
use PDOException;

final class AttendanceController
{
    public function __construct(private AttendanceModel $attendance)
    {
    }

    public function index(): void
    {
        JsonResponse::send(['data' => $this->attendance->all()]);
    }

    public function assigned(): void
    {
        $professorId = $_SESSION['id_profesor'] ?? null;
        if (!is_int($professorId)) {
            JsonResponse::send(['error' => 'La cuenta de maestro no está vinculada a un profesor.'], 403);
            return;
        }
        JsonResponse::send(['data' => $this->attendance->allForProfessor($professorId)]);
    }

    public function mine(): void
    {
        $studentId = $_SESSION['id_alumno'] ?? null;
        if (!is_int($studentId)) {
            JsonResponse::send(['error' => 'La cuenta no está vinculada a una ficha de alumno.'], 403);
            return;
        }
        JsonResponse::send(['data' => $this->attendance->allForStudent($studentId)]);
    }

    public function store(): void
    {
        $input = Request::json();
        if ($input === null) {
            JsonResponse::send(['error' => 'El cuerpo debe ser JSON válido.'], 400);
            return;
        }
        $studentId = filter_var($input['id_alumno'] ?? $input['student_id'] ?? null, FILTER_VALIDATE_INT);
        $classId = filter_var($input['id_clase'] ?? null, FILTER_VALIDATE_INT);
        $attendedAt = $input['fecha_hora'] ?? $input['attended_at'] ?? date('Y-m-d H:i:s');
        if ($studentId === false || $studentId < 0 || $classId === false || $classId < 1
            || !is_string($attendedAt) || !Request::isValidDateTime($attendedAt)) {
            JsonResponse::send(['error' => 'Datos de asistencia inválidos.'], 422);
            return;
        }
        $dateTime = new \DateTimeImmutable($attendedAt);
        $professorId = ($_SESSION['rol'] ?? null) === 'maestro'
            ? ($_SESSION['id_profesor'] ?? null)
            : null;
        if (($_SESSION['rol'] ?? null) === 'maestro' && !is_int($professorId)) {
            JsonResponse::send(['error' => 'La cuenta de maestro no está vinculada a un profesor.'], 403);
            return;
        }
        try {
            $id = $this->attendance->create(
                $studentId,
                $classId,
                $dateTime->format('Y-m-d'),
                $dateTime->format('H:i:s'),
                (int) $_SESSION['id_usuario'],
                $professorId
            );
            JsonResponse::send(['data' => ['id_asistencia' => $id]], 201);
        } catch (DomainException $exception) {
            JsonResponse::send(['error' => $exception->getMessage()], 403);
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1452) {
                JsonResponse::send(['error' => 'El alumno o la clase indicados no existen.'], 422);
                return;
            }
            if ($exception->getCode() === '23000') {
                JsonResponse::send(['error' => 'La asistencia ya existe o referencia una relación no válida.'], 409);
                return;
            }
            throw $exception;
        }
    }

    public function update(array $params): void
    {
        $input = Request::json();
        if ($input === null) {
            JsonResponse::send(['error' => 'El cuerpo debe ser JSON válido.'], 400);
            return;
        }
        $dateTime = $input['fecha_hora'] ?? null;
        if (!is_string($dateTime) || !Request::isValidDateTime($dateTime)) {
            JsonResponse::send(['error' => 'Fecha y hora de asistencia inválidas.'], 422);
            return;
        }
        $professorId = ($_SESSION['rol'] ?? null) === 'maestro'
            ? ($_SESSION['id_profesor'] ?? null)
            : null;
        if (($_SESSION['rol'] ?? null) === 'maestro' && !is_int($professorId)) {
            JsonResponse::send(['error' => 'La cuenta de maestro no está vinculada a un profesor.'], 403);
            return;
        }
        $date = new \DateTimeImmutable($dateTime);
        try {
            if (!$this->attendance->update(
                (int) $params['id'],
                $date->format('Y-m-d'),
                $date->format('H:i:s'),
                (int) $_SESSION['id_usuario'],
                $professorId
            )) {
                JsonResponse::send(['error' => 'Asistencia no encontrada o fuera de las clases asignadas.'], 404);
                return;
            }
            JsonResponse::send(['message' => 'Asistencia actualizada.']);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                JsonResponse::send(['error' => 'La corrección duplicaría una asistencia del mismo alumno, clase y fecha.'], 409);
                return;
            }
            throw $exception;
        }
    }
}
