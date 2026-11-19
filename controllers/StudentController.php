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
        $page = filter_var($_GET['pagina'] ?? 1, FILTER_VALIDATE_INT);
        $perPage = filter_var($_GET['por_pagina'] ?? 25, FILTER_VALIDATE_INT);
        if ($page === false || $perPage === false) {
            JsonResponse::send(['error' => 'Los parámetros de paginación deben ser enteros.'], 422);
            return;
        }
        $classId = isset($_GET['id_clase']) ? filter_var($_GET['id_clase'], FILTER_VALIDATE_INT) : null;
        if ($classId === false || ($classId !== null && $classId < 0)) {
            JsonResponse::send(['error' => 'El identificador de clase debe ser un entero no negativo.'], 422);
            return;
        }
        JsonResponse::send($this->students->all([
            'q' => is_string($_GET['q'] ?? null) ? $_GET['q'] : '',
            'estatus' => is_string($_GET['estatus'] ?? null) ? $_GET['estatus'] : null,
            'categoria' => is_string($_GET['categoria'] ?? null) ? $_GET['categoria'] : null,
            'cinturon' => is_string($_GET['cinturon'] ?? null) ? $_GET['cinturon'] : null,
            'id_clase' => $classId,
            'estado_pago' => is_string($_GET['estado_pago'] ?? null) ? $_GET['estado_pago'] : null,
        ], $page, $perPage));
    }

    public function mine(): void
    {
        $studentId = $_SESSION['id_alumno'] ?? null;
        if (!is_int($studentId)) {
            JsonResponse::send(['error' => 'La cuenta no está vinculada a una ficha de alumno.'], 403);
            return;
        }
        $student = $this->students->find($studentId);
        if ($student === null) {
            JsonResponse::send(['error' => 'Alumno no encontrado.'], 404);
            return;
        }
        JsonResponse::send(['data' => $student]);
    }

    public function assigned(): void
    {
        $professorId = $_SESSION['id_profesor'] ?? null;
        if (!is_int($professorId)) {
            JsonResponse::send(['error' => 'La cuenta de maestro no está vinculada a un profesor.'], 403);
            return;
        }
        JsonResponse::send(['data' => $this->students->allForProfessor($professorId)]);
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
            if (($_SESSION['rol'] ?? null) === 'recepcionista') {
                $data['estatus'] = 1;
                $data['categoria'] = null;
            }
            $id = $this->students->create($data, (int) $_SESSION['id_usuario']);
            JsonResponse::send(['data' => $this->students->find($id)], 201);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                JsonResponse::send(['error' => 'La matrícula o una relación del alumno ya existe.'], 409);
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
            if (($_SESSION['rol'] ?? null) === 'recepcionista') {
                $current = $this->students->find($id);
                $data['estatus'] = (int) $current['estatus'];
                $data['categoria'] = $current['categoria'];
            }
            $this->students->update($id, $data, (int) $_SESSION['id_usuario']);
            JsonResponse::send(['data' => $this->students->find($id)]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                JsonResponse::send(['error' => 'La matrícula o una relación del alumno ya existe.'], 409);
                return;
            }
            throw $exception;
        }
    }

    public function destroy(array $params): void
    {
        if (!$this->students->delete((int) $params['id'], (int) $_SESSION['id_usuario'])) {
            JsonResponse::send(['error' => 'Alumno no encontrado.'], 404);
            return;
        }
        JsonResponse::send(['message' => 'Alumno desactivado; se conserva su historial.']);
    }

    private function validatedInput(): ?array
    {
        $input = Request::json();
        if ($input === null) {
            JsonResponse::send(['error' => 'El cuerpo debe ser JSON válido.'], 400);
            return null;
        }

        $nombre = $input['nombre'] ?? $input['first_name'] ?? null;
        $apellidos = $input['apellidos'] ?? $input['last_name'] ?? null;
        $telefono = $input['telefono'] ?? $input['phone'] ?? null;
        $correo = $input['correo_contacto'] ?? $input['email'] ?? null;
        $direccion = $input['direccion'] ?? null;
        $fechaNacimiento = $input['fecha_nacimiento'] ?? $input['birth_date'] ?? null;
        $estatus = $input['estatus'] ?? $input['status'] ?? 1;
        $categoria = $input['categoria'] ?? null;
        $categoria = $categoria === '' ? null : $categoria;
        $idCinturon = $input['id_cinturon'] ?? null;
        $idCinturon = $idCinturon === null || $idCinturon === '' ? null : filter_var($idCinturon, FILTER_VALIDATE_INT);
        $estatus = match ($estatus) {
            'activo', 'active', 1, '1', true => 1,
            'inactivo', 'inactive', 0, '0', false => 0,
            default => null,
        };
        if (!is_string($nombre) || trim($nombre) === ''
            || mb_strlen(trim($nombre), 'UTF-8') > 100
            || !is_string($apellidos) || trim($apellidos) === ''
            || mb_strlen(trim($apellidos), 'UTF-8') > 100
            || !is_string($telefono) || trim($telefono) === ''
            || mb_strlen(trim($telefono), 'UTF-8') > 20
            || ($correo !== null && $correo !== '' && (!is_string($correo) || !filter_var($correo, FILTER_VALIDATE_EMAIL)))
            || (is_string($correo) && mb_strlen(trim($correo), 'UTF-8') > 150)
            || ($direccion !== null && !is_string($direccion))
            || (is_string($direccion) && mb_strlen(trim($direccion), 'UTF-8') > 255)
            || !is_string($fechaNacimiento) || !Request::isValidDate($fechaNacimiento)
            || $estatus === null
            || ($categoria !== null && !in_array($categoria, ['infantil', 'adulta'], true))
            || ($idCinturon !== null && ($idCinturon === false || $idCinturon < 0))) {
            JsonResponse::send(['error' => 'Datos inválidos: nombre, apellidos, teléfono, fecha de nacimiento, estado, categoría o cinturón.'], 422);
            return null;
        }

        return [
            'nombre' => trim($nombre),
            'apellidos' => trim($apellidos),
            'telefono' => trim($telefono),
            'correo_contacto' => is_string($correo) && trim($correo) !== '' ? trim($correo) : null,
            'direccion' => is_string($direccion) && trim($direccion) !== '' ? trim($direccion) : null,
            'fecha_nacimiento' => $fechaNacimiento,
            'estatus' => $estatus,
            'categoria' => $categoria,
            'id_cinturon' => $idCinturon,
        ];
    }
}
