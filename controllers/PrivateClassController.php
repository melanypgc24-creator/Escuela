<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\JsonResponse;
use App\Core\Request;
use App\Models\PrivateClassModel;
use DomainException;
use PDOException;

final class PrivateClassController
{
    public function __construct(private PrivateClassModel $classes)
    {
    }

    public function index(): void
    {
        JsonResponse::send(['data' => $this->classes->all()]);
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

    public function store(): void
    {
        $input = Request::json();
        if ($input === null) {
            JsonResponse::send(['error' => 'El cuerpo debe ser JSON válido.'], 400);
            return;
        }

        $professorId = filter_var($input['id_profesor'] ?? null, FILTER_VALIDATE_INT);
        $dateTime = $input['fecha_hora'] ?? null;
        $participants = $input['participantes'] ?? null;
        $payerName = $input['nombre_pagador'] ?? null;
        $payerId = $input['id_alumno_pagador'] ?? null;
        $payerId = $payerId === null || $payerId === '' ? null : filter_var($payerId, FILTER_VALIDATE_INT);
        $method = $input['metodo_pago'] ?? null;
        $notes = $input['observaciones'] ?? null;

        if ($professorId === false || $professorId < 0
            || !is_string($dateTime) || !Request::isValidDateTime($dateTime)
            || !is_array($participants) || $participants === []
            || !is_string($payerName) || trim($payerName) === ''
            || ($payerId !== null && ($payerId === false || $payerId < 0))
            || !in_array(
                $method,
                ($_SESSION['rol'] ?? null) === 'recepcionista'
                    ? ['Efectivo', 'Tarjeta']
                    : ['Efectivo', 'Transferencia', 'Tarjeta', 'Otro'],
                true
            )
            || ($notes !== null && !is_string($notes))) {
            JsonResponse::send(['error' => 'Datos inválidos para la sesión privada o su único pago.'], 422);
            return;
        }

        $normalizedParticipants = [];
        foreach ($participants as $participant) {
            if (!is_array($participant)) {
                JsonResponse::send(['error' => 'Cada participante debe incluir una ficha o un nombre.'], 422);
                return;
            }
            $studentId = $participant['id_alumno'] ?? null;
            $studentId = $studentId === null || $studentId === '' ? null : filter_var($studentId, FILTER_VALIDATE_INT);
            if ($studentId === null || $studentId === false || $studentId < 0) {
                JsonResponse::send(['error' => 'Cada participante debe tener una ficha de alumno hasta que se definan las reglas para participantes sin ficha.'], 422);
                return;
            }
            $normalizedParticipants[] = [
                'id_alumno' => $studentId,
                'nombre_participante' => '',
            ];
        }

        try {
            $result = $this->classes->create([
                'id_profesor' => $professorId,
                'fecha_hora' => $dateTime,
                'participantes' => $normalizedParticipants,
                'observaciones' => is_string($notes) ? trim($notes) : null,
                'nombre_pagador' => trim($payerName),
                'id_alumno_pagador' => $payerId,
                'metodo_pago' => $method,
                'id_usuario_registra' => (int) $_SESSION['id_usuario'],
            ]);
            JsonResponse::send(['data' => $result], 201);
        } catch (DomainException $exception) {
            JsonResponse::send(['error' => $exception->getMessage()], 422);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                JsonResponse::send(['error' => 'La sesión referencia un profesor, alumno, pagador o pago no válido.'], 409);
                return;
            }
            throw $exception;
        }
    }
}
