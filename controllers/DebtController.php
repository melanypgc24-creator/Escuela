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
}
