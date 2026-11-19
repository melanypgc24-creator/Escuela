<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\JsonResponse;
use App\Core\Request;
use App\Models\PaymentModel;
use DomainException;
use PDOException;

final class PaymentController
{
    public function __construct(private PaymentModel $payments)
    {
    }

    public function index(): void
    {
        JsonResponse::send(['data' => $this->payments->all()]);
    }

    public function mine(): void
    {
        $studentId = $_SESSION['id_alumno'] ?? null;
        if (!is_int($studentId)) {
            JsonResponse::send(['error' => 'La cuenta no está vinculada a una ficha de alumno.'], 403);
            return;
        }
        JsonResponse::send(['data' => $this->payments->allForStudent($studentId)]);
    }

    public function store(): void
    {
        $input = Request::json();
        if ($input === null) {
            JsonResponse::send(['error' => 'El cuerpo debe ser JSON válido.'], 400);
            return;
        }
        $studentId = filter_var($input['id_alumno'] ?? $input['student_id'] ?? null, FILTER_VALIDATE_INT);
        $amount = $input['monto'] ?? $input['amount'] ?? null;
        $method = $input['metodo_pago'] ?? $input['method'] ?? '';
        $paidAt = $input['fecha_pago'] ?? $input['paid_at'] ?? date('Y-m-d H:i:s');
        $payerName = $input['nombre_pagador'] ?? null;
        $payerId = $input['id_alumno_pagador'] ?? null;
        $payerId = $payerId === null || $payerId === '' ? null : filter_var($payerId, FILTER_VALIDATE_INT);
        if ($studentId === false || $studentId < 0
            || (!is_string($amount) && !is_int($amount) && !is_float($amount))
            || preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', (string) $amount) !== 1 || (float) $amount <= 0
            || !in_array(
                $method,
                ($_SESSION['rol'] ?? null) === 'recepcionista'
                    ? ['cash', 'card', 'efectivo', 'tarjeta']
                    : ['cash', 'transfer', 'card', 'other', 'efectivo', 'transferencia', 'tarjeta'],
                true
            )
            || !is_string($paidAt) || !Request::isValidDateTime($paidAt)
            || !is_string($payerName) || trim($payerName) === ''
            || ($payerId !== null && $payerId === false)) {
            JsonResponse::send(['error' => 'Datos de pago inválidos.'], 422);
            return;
        }
        try {
            $id = $this->payments->create([
                'id_alumno' => $studentId,
                'monto' => (string) $amount,
                'fecha_pago' => (new \DateTimeImmutable($paidAt))->format('Y-m-d'),
                'metodo_pago' => $method,
                'nombre_pagador' => trim($payerName),
                'id_alumno_pagador' => $payerId,
                'id_usuario_registra' => (int) $_SESSION['id_usuario'],
            ]);
            JsonResponse::send(['data' => ['id_pago' => $id]], 201);
        } catch (DomainException $exception) {
            JsonResponse::send(['error' => $exception->getMessage()], 422);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                JsonResponse::send(['error' => 'El pago no pudo aplicarse por una relación o restricción duplicada.'], 409);
                return;
            }
            throw $exception;
        }
    }

    public function cancel(array $params): void
    {
        $input = Request::json();
        if ($input === null) {
            JsonResponse::send(['error' => 'El cuerpo debe ser JSON válido.'], 400);
            return;
        }
        $reason = $input['motivo'] ?? null;
        if (!is_string($reason) || trim($reason) === ''
            || mb_strlen(trim($reason), 'UTF-8') > 255) {
            JsonResponse::send(['error' => 'Indica un motivo de cancelación de hasta 255 caracteres.'], 422);
            return;
        }
        try {
            if (!$this->payments->cancel(
                (int) $params['id'],
                (int) $_SESSION['id_usuario'],
                trim($reason)
            )) {
                JsonResponse::send(['error' => 'Pago no encontrado.'], 404);
                return;
            }
            JsonResponse::send(['message' => 'Pago cancelado y conservado en el historial.']);
        } catch (DomainException $exception) {
            JsonResponse::send(['error' => $exception->getMessage()], 409);
        } catch (PDOException $exception) {
            throw $exception;
        }
    }
}
