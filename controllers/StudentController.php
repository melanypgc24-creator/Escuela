<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\JsonResponse;
use App\Core\Request;
use App\Models\StudentModel;
use InvalidArgumentException;
use PDOException;

final class StudentController
{
    public function __construct(private StudentModel $students)
    {
    }

    public function index(): void
    {
        try {
            [$filters, $page, $perPage] = self::searchParameters($_GET);
            JsonResponse::send($this->students->all($filters, $page, $perPage));
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(['error' => $exception->getMessage()], 422);
        }
    }

    public static function searchParameters(array $input, bool $assignedOnly = false): array
    {
        $filters = [];
        foreach (['q', 'cinturon'] as $name) {
            if (isset($input[$name]) && (!is_string($input[$name]) || mb_strlen($input[$name], 'UTF-8') > 100)) {
                throw new InvalidArgumentException('El texto de búsqueda no es válido.');
            }
            if (isset($input[$name]) && trim($input[$name]) !== '') {
                $filters[$name] = trim($input[$name]);
            }
        }
        foreach (['estatus' => ['activo', 'inactivo'], 'categoria' => ['infantil', 'adulta'],
                     'estado_pago' => ['sin_cargos', 'al_corriente', 'pagado', 'pendiente', 'parcial', 'vencido']] as $name => $values) {
            $value = $input[$name] ?? '';
            if (!is_string($value) || ($value !== '' && !in_array($value, $values, true))
                || ($assignedOnly && $name === 'estado_pago' && $value !== '')) {
                throw new InvalidArgumentException('El filtro seleccionado no es válido.');
            }
            if ($value !== '') {
                $filters[$name] = $value;
            }
        }
        foreach (['id_clase', 'id_cinturon'] as $name) {
            $value = $input[$name] ?? '';
            if ($value !== '') {
                $id = filter_var($value, FILTER_VALIDATE_INT);
                if ($id === false || $id < 1) {
                    throw new InvalidArgumentException('El identificador seleccionado no es válido.');
                }
                $filters[$name] = $id;
            }
        }
        $page = filter_var($input['pagina'] ?? 1, FILTER_VALIDATE_INT);
        $perPage = filter_var($input['por_pagina'] ?? 25, FILTER_VALIDATE_INT);
        if ($page === false || $page < 1 || $page > 1000000 || $perPage === false || $perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException('Los parámetros de paginación no son válidos.');
        }
        return [$filters, $page, $perPage];
    }

    public static function validateOwnProfile(array $input): array
    {
        $name = $input['nombre'] ?? null;
        $surname = $input['apellidos'] ?? null;
        $phone = $input['telefono'] ?? null;
        $birthDate = $input['fecha_nacimiento'] ?? null;
        $email = $input['correo_contacto'] ?? null;
        $address = $input['direccion'] ?? null;

        if (!is_string($name) || trim($name) === '' || mb_strlen(trim($name), 'UTF-8') > 100
            || !is_string($surname) || trim($surname) === '' || mb_strlen(trim($surname), 'UTF-8') > 100) {
            throw new InvalidArgumentException('Escribe nombre y apellidos de hasta 100 caracteres.');
        }
        if (!is_string($phone) || mb_strlen(trim($phone), 'UTF-8') > 20
            || preg_match('/^\+?[0-9][0-9 ().-]*$/D', trim($phone)) !== 1
            || strlen(preg_replace('/\D/', '', $phone)) < 7
            || strlen(preg_replace('/\D/', '', $phone)) > 15) {
            throw new InvalidArgumentException('Escribe un teléfono válido con entre 7 y 15 dígitos.');
        }
        if (!is_string($birthDate) || !Request::isValidBirthDate($birthDate)) {
            throw new InvalidArgumentException('Selecciona una fecha de nacimiento válida.');
        }
        if (!is_string($email) || (trim($email) !== '' && (strlen(trim($email)) > 150
            || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)))) {
            throw new InvalidArgumentException('Escribe un correo de contacto válido.');
        }
        if (!is_string($address) || mb_strlen(trim($address), 'UTF-8') > 255) {
            throw new InvalidArgumentException('La dirección debe tener hasta 255 caracteres.');
        }
        return [
            'nombre' => trim($name),
            'apellidos' => trim($surname),
            'telefono' => trim($phone),
            'fecha_nacimiento' => $birthDate,
            'correo_contacto' => trim($email) === '' ? null : trim($email),
            'direccion' => trim($address) === '' ? null : trim($address),
        ];
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
        $searchKeys = ['q', 'cinturon', 'estatus', 'categoria', 'estado_pago', 'id_clase', 'id_cinturon', 'pagina', 'por_pagina'];
        if (array_intersect($searchKeys, array_keys($_GET)) === []) {
            JsonResponse::send(['data' => $this->students->allForProfessor($professorId)]);
            return;
        }
        try {
            [$filters, $page, $perPage] = self::searchParameters($_GET, true);
            JsonResponse::send($this->students->all($filters, $page, $perPage, $professorId));
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(['error' => $exception->getMessage()], 422);
        }
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

        if (array_key_exists('id_cinturon', $input)
            && !\App\Core\Permissions::allows((string) ($_SESSION['rol'] ?? ''), 'students.belt_all')) {
            JsonResponse::send(['error' => 'No tienes permiso para modificar el cinturón.'], 403);
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
            || !is_string($fechaNacimiento) || !Request::isValidBirthDate($fechaNacimiento)
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
