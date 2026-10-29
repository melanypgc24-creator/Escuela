<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\JsonResponse;
use App\Models\DashboardModel;

final class DashboardController
{
    public function __construct(private DashboardModel $dashboard)
    {
    }

    public function summary(): void
    {
        JsonResponse::send(['data' => $this->dashboard->summary()]);
    }
}
