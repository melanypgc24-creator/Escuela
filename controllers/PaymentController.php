<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\JsonResponse;
use App\Core\Request;
use App\Models\PaymentModel;

final class PaymentController
{
    public function __construct(private PaymentModel $payments)
    {
    }

    public function index(): void
    {
        JsonResponse::send(['data' => $this->payments->all()]);
    }

    public function store(): void
    {
        $input = Request::json();
        if ($input === null) {
            JsonResponse::send(['error' => 'El cuerpo debe ser JSON válido.'], 400);
            return;
        }
        $studentId = filter_var($input['student_id'] ?? null, FILTER_VALIDATE_INT);
        $amount = $input['amount'] ?? null;
        $method = $input['method'] ?? '';
        $paidAt = $input['paid_at'] ?? date('Y-m-d H:i:s');
        $reference = $input['reference'] ?? '';
        if ($studentId === false || $studentId < 1 || !is_numeric($amount) || (float) $amount <= 0
            || !in_array($method, ['cash', 'transfer', 'card', 'other'], true)
            || !is_string($paidAt) || !Request::isValidDateTime($paidAt)
            || !is_string($reference)) {
            JsonResponse::send(['error' => 'Datos de pago inválidos.'], 422);
            return;
        }
        $id = $this->payments->create([
            'student_id' => $studentId,
            'amount' => $amount,
            'paid_at' => $paidAt,
            'method' => $method,
            'reference' => trim($reference) ?: null,
        ]);
        JsonResponse::send(['data' => ['id' => $id]], 201);
    }
}
