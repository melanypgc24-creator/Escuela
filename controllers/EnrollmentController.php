<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\JsonResponse;
use App\Core\Request;
use App\Models\EnrollmentModel;
use DomainException;
use PDOException;

final class EnrollmentController
{
    public function __construct(private EnrollmentModel $enrollments)
    {
    }

    public function index(): void
    {
        $classId = isset($_GET['id_clase']) ? filter_var($_GET['id_clase'], FILTER_VALIDATE_INT) : null;
        if ($classId === false || ($classId !== null && $classId < 1)) {
            JsonResponse::send(['error' => 'Identificador de clase inválido.'], 422);
            return;
        }
        JsonResponse::send(['data' => $this->enrollments->all($classId)]);
    }

    public function assigned(): void
    {
        $professorId = $_SESSION['id_profesor'] ?? null;
        if (!is_int($professorId)) {
            JsonResponse::send(['error' => 'La cuenta de maestro no está vinculada a un profesor.'], 403);
            return;
        }
        JsonResponse::send(['data' => $this->enrollments->all(null, $professorId)]);
    }

    public function mine(): void
    {
        $studentId = $_SESSION['id_alumno'] ?? null;
        if (!is_int($studentId)) {
            JsonResponse::send(['error' => 'La cuenta no está vinculada a una ficha de alumno.'], 403);
            return;
        }
        JsonResponse::send(['data' => $this->enrollments->all(null, null, $studentId)]);
    }

    public function store(): void
    {
        $input = Request::json();
        if ($input === null) {
            JsonResponse::send(['error' => 'El cuerpo debe ser JSON válido.'], 400);
            return;
        }
        $classId = filter_var($input['id_clase'] ?? null, FILTER_VALIDATE_INT);
        $studentId = filter_var($input['id_alumno'] ?? null, FILTER_VALIDATE_INT);
        if ($classId === false || $classId < 1 || $studentId === false || $studentId < 0) {
            JsonResponse::send(['error' => 'Clase o alumno inválido.'], 422);
            return;
        }
        try {
            $id = $this->enrollments->create($classId, $studentId, (int) $_SESSION['id_usuario']);
            JsonResponse::send(['data' => ['id_inscripcion' => $id]], 201);
        } catch (DomainException $exception) {
            JsonResponse::send(['error' => $exception->getMessage()], 422);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                JsonResponse::send(['error' => 'El alumno ya está inscrito o una relación no es válida.'], 409);
                return;
            }
            throw $exception;
        }
    }

    public function destroy(array $params): void
    {
        if (!$this->enrollments->deactivate((int) $params['id'], (int) $_SESSION['id_usuario'])) {
            JsonResponse::send(['error' => 'Inscripción no encontrada.'], 404);
            return;
        }
        JsonResponse::send(['message' => 'Inscripción inactivada; se conserva su historial.']);
    }
}
