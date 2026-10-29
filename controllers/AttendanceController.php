<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\JsonResponse;
use App\Core\Request;
use App\Models\AttendanceModel;

final class AttendanceController
{
    public function __construct(private AttendanceModel $attendance)
    {
    }

    public function index(): void
    {
        JsonResponse::send(['data' => $this->attendance->all()]);
    }

    public function store(): void
    {
        $input = Request::json();
        if ($input === null) {
            JsonResponse::send(['error' => 'El cuerpo debe ser JSON válido.'], 400);
            return;
        }
        $studentId = filter_var($input['student_id'] ?? null, FILTER_VALIDATE_INT);
        $attendedAt = $input['attended_at'] ?? date('Y-m-d H:i:s');
        if ($studentId === false || $studentId < 1 || !is_string($attendedAt) || !Request::isValidDateTime($attendedAt)) {
            JsonResponse::send(['error' => 'Datos de asistencia inválidos.'], 422);
            return;
        }
        $id = $this->attendance->create($studentId, $attendedAt);
        JsonResponse::send(['data' => ['id' => $id]], 201);
    }
}
