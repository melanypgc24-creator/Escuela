<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\JsonResponse;
use App\Core\Request;
use App\Models\StudentModel;
use PDOException;

final class StudentController
{
    public function __construct(private StudentModel $students)
    {
    }

    public function index(): void
    {
        JsonResponse::send(['data' => $this->students->all()]);
    }

    public function show(array $params): void
    {
        $student = $this->students->find((int) $params['id']);
        if ($student === null) {
            JsonResponse::send(['error' => 'Alumno no encontrado.'], 404);
            return;
        }
        JsonResponse::send(['data' => $student]);
    }

    public function store(): void
    {
        $data = $this->validatedInput();
        if ($data === null) {
            return;
        }
        try {
            $id = $this->students->create($data);
            JsonResponse::send(['data' => $this->students->find($id)], 201);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                JsonResponse::send(['error' => 'El correo ya está registrado.'], 409);
                return;
            }
            throw $exception;
        }
    }

    public function update(array $params): void
    {
        $data = $this->validatedInput();
        if ($data === null) {
            return;
        }
        $id = (int) $params['id'];
        if ($this->students->find($id) === null) {
            JsonResponse::send(['error' => 'Alumno no encontrado.'], 404);
            return;
        }
        try {
            $this->students->update($id, $data);
            JsonResponse::send(['data' => $this->students->find($id)]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                JsonResponse::send(['error' => 'El correo ya está registrado.'], 409);
                return;
            }
            throw $exception;
        }
    }

    public function destroy(array $params): void
    {
        if (!$this->students->delete((int) $params['id'])) {
            JsonResponse::send(['error' => 'Alumno no encontrado.'], 404);
            return;
        }
        JsonResponse::send(['message' => 'Alumno eliminado.']);
    }

    private function validatedInput(): ?array
    {
        $input = Request::json();
        if ($input === null) {
            JsonResponse::send(['error' => 'El cuerpo debe ser JSON válido.'], 400);
            return null;
        }

        $firstName = $input['first_name'] ?? null;
        $lastName = $input['last_name'] ?? null;
        $email = $input['email'] ?? null;
        $phone = $input['phone'] ?? null;
        $birthDate = $input['birth_date'] ?? null;
        $status = $input['status'] ?? 'active';
        if (!is_string($firstName) || trim($firstName) === ''
            || !is_string($lastName) || trim($lastName) === ''
            || !is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || ($phone !== null && !is_string($phone))
            || !in_array($status, ['active', 'inactive'], true)
            || ($birthDate !== null && (!is_string($birthDate) || !\App\Core\Request::isValidDate($birthDate)))) {
            JsonResponse::send(['error' => 'Datos inválidos: nombre, apellido, correo, fecha y estado deben ser válidos.'], 422);
            return null;
        }

        return [
            'first_name' => trim($firstName),
            'last_name' => trim($lastName),
            'email' => trim($email),
            'phone' => is_string($phone) && trim($phone) !== '' ? trim($phone) : null,
            'birth_date' => $birthDate,
            'status' => $status,
        ];
    }
}
