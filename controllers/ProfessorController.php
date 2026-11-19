<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\JsonResponse;
use App\Models\ProfessorModel;

final class ProfessorController
{
    public function __construct(private ProfessorModel $professors)
    {
    }

    public function index(): void
    {
        JsonResponse::send(['data' => $this->professors->all()]);
    }
}
