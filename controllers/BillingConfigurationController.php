<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\JsonResponse;
use App\Core\Request;
use App\Models\BillingConfigurationModel;

final class BillingConfigurationController
{
    public function __construct(private BillingConfigurationModel $configuration)
    {
    }

    public function show(): void
    {
        JsonResponse::send(['data' => $this->configuration->current()]);
    }

    public function update(): void
    {
        $input = Request::json();
        if ($input === null) {
            JsonResponse::send(['error' => 'El cuerpo debe ser JSON válido.'], 400);
            return;
        }
        $day = $input['dia_vencimiento'] ?? null;
        if ($day !== null) {
            $day = filter_var($day, FILTER_VALIDATE_INT);
            if ($day === false || $day < 1 || $day > 31) {
                JsonResponse::send(['error' => 'El día de vencimiento debe ser nulo o un entero entre 1 y 31.'], 422);
                return;
            }
        }
        $this->configuration->setDueDay($day, (int) $_SESSION['id_usuario']);
        JsonResponse::send(['message' => 'Configuración guardada. La generación de cargos permanece deshabilitada hasta definir las reglas de vencimiento y prorrateo.']);
    }
}
