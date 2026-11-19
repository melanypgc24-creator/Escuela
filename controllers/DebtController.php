<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\JsonResponse;
use App\Models\DebtModel;

final class DebtController
{
    public function __construct(private DebtModel $debts)
    {
    }

    public function index(): void
    {
        JsonResponse::send(['data' => $this->debts->all()]);
    }

    public function mine(): void
    {
        $studentId = $_SESSION['id_alumno'] ?? null;
        if (!is_int($studentId)) {
            JsonResponse::send(['error' => 'La cuenta no está vinculada a una ficha de alumno.'], 403);
            return;
        }
        JsonResponse::send(['data' => $this->debts->allForStudent($studentId)]);
    }
}
