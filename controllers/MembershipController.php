<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\JsonResponse;
use App\Core\Request;
use App\Models\MembershipModel;

final class MembershipController
{
    public function __construct(private MembershipModel $memberships)
    {
    }

    public function index(): void
    {
        JsonResponse::send(['data' => $this->memberships->all()]);
    }

    public function store(): void
    {
        $input = Request::json();
        if ($input === null) {
            JsonResponse::send(['error' => 'El cuerpo debe ser JSON válido.'], 400);
            return;
        }
        $name = $input['name'] ?? null;
        $price = $input['price'] ?? null;
        $duration = filter_var($input['duration_days'] ?? null, FILTER_VALIDATE_INT);
        $description = $input['description'] ?? '';
        if (!is_string($name) || trim($name) === '' || !is_string($description)
            || !is_numeric($price) || (float) $price < 0 || $duration === false || $duration < 1) {
            JsonResponse::send(['error' => 'Nombre, precio y duración son obligatorios y deben ser válidos.'], 422);
            return;
        }
        $id = $this->memberships->create([
            'name' => trim($name),
            'description' => trim($description),
            'price' => $price,
            'duration_days' => $duration,
            'active' => 1,
        ]);
        JsonResponse::send(['data' => ['id' => $id]], 201);
    }
}
