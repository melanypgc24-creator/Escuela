<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\JsonResponse;
use App\Models\ClassModel;

final class ClassController
{
    public function __construct(private ClassModel $classes)
    {
    }

    public function index(): void
    {
        JsonResponse::send(['data' => $this->classes->all()]);
    }

    public function assigned(): void
    {
        $professorId = $_SESSION['id_profesor'] ?? null;
        if (!is_int($professorId)) {
            JsonResponse::send(['error' => 'La cuenta de maestro no está vinculada a un profesor.'], 403);
            return;
        }
        JsonResponse::send(['data' => $this->classes->allForProfessor($professorId)]);
    }

    public function mine(): void
    {
        $studentId = $_SESSION['id_alumno'] ?? null;
        if (!is_int($studentId)) {
            JsonResponse::send(['error' => 'La cuenta no está vinculada a una ficha de alumno.'], 403);
            return;
        }
        JsonResponse::send(['data' => $this->classes->allForStudent($studentId)]);
    }

    public function assignedStudents(): void
    {
        $professorId = $_SESSION['id_profesor'] ?? null;
        if (!is_int($professorId)) {
            JsonResponse::send(['error' => 'La cuenta de maestro no está vinculada a un profesor.'], 403);
            return;
        }
        JsonResponse::send(['data' => $this->classes->studentsForProfessor($professorId)]);
    }
}
