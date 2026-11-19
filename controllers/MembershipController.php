<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\JsonResponse;
use App\Core\Request;
use App\Models\MembershipModel;
use PDOException;

final class MembershipController
{
    public function __construct(private MembershipModel $memberships)
    {
    }

    public function index(): void
    {
        $rows = ($_SESSION['rol'] ?? null) === 'recepcionista'
            ? $this->memberships->current()
            : $this->memberships->all();
        JsonResponse::send(['data' => $rows]);
    }

    public function store(): void
    {
        $input = Request::json();
        if ($input === null) {
            JsonResponse::send(['error' => 'El cuerpo debe ser JSON válido.'], 400);
            return;
        }
        $category = $input['categoria'] ?? null;
        $amount = $input['importe'] ?? null;
        $from = $input['vigente_desde'] ?? null;
        $until = $input['vigente_hasta'] ?? null;
        $active = $input['activa'] ?? 0;
        if (!in_array($category, ['infantil', 'adulta'], true)
            || (!is_string($amount) && !is_int($amount) && !is_float($amount))
            || preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', (string) $amount) !== 1
            || (float) $amount <= 0
            || !is_string($from) || !Request::isValidDate($from)
            || ($until !== null && $until !== '' && (!is_string($until) || !Request::isValidDate($until) || $until < $from))
            || !in_array($active, [0, 1, '0', '1', false, true], true)) {
            JsonResponse::send(['error' => 'Categoría, importe, vigencia y estado de tarifa deben ser válidos.'], 422);
            return;
        }
        try {
            $id = $this->memberships->create([
                'categoria' => $category,
                'importe' => self::decimalAmount((string) $amount),
                'vigente_desde' => $from,
                'vigente_hasta' => is_string($until) && $until !== '' ? $until : null,
                'activa' => (int) $active,
                'id_usuario_crea' => (int) $_SESSION['id_usuario'],
            ]);
            JsonResponse::send(['data' => ['id_tarifa' => $id]], 201);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                JsonResponse::send(['error' => 'Solo puede existir una tarifa activa por categoría.'], 409);
                return;
            }
            throw $exception;
        }
    }

    public function update(array $params): void
    {
        $input = Request::json();
        if ($input === null) {
            JsonResponse::send(['error' => 'El cuerpo debe ser JSON válido.'], 400);
            return;
        }
        $data = $this->validatedTariffData($input);
        if ($data === null) {
            JsonResponse::send(['error' => 'Categoría, importe, vigencia y estado de tarifa deben ser válidos.'], 422);
            return;
        }
        try {
            if (!$this->memberships->update(
                (int) $params['id'],
                $data,
                (int) $_SESSION['id_usuario']
            )) {
                JsonResponse::send(['error' => 'Tarifa no encontrada.'], 404);
                return;
            }
            JsonResponse::send(['message' => 'Tarifa actualizada.']);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                JsonResponse::send(['error' => 'Solo puede existir una tarifa activa por categoría.'], 409);
                return;
            }
            throw $exception;
        }
    }

    private function validatedTariffData(array $input): ?array
    {
        $category = $input['categoria'] ?? null;
        $amount = $input['importe'] ?? null;
        $from = $input['vigente_desde'] ?? null;
        $until = $input['vigente_hasta'] ?? null;
        $active = $input['activa'] ?? 0;
        if (!in_array($category, ['infantil', 'adulta'], true)
            || (!is_string($amount) && !is_int($amount) && !is_float($amount))
            || preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', (string) $amount) !== 1
            || (float) $amount <= 0
            || !is_string($from) || !Request::isValidDate($from)
            || ($until !== null && $until !== '' && (!is_string($until) || !Request::isValidDate($until) || $until < $from))
            || !in_array($active, [0, 1, '0', '1', false, true], true)) {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', (string) $amount, 2), 2, '');
        return [
            'categoria' => $category,
            'importe' => $whole . '.' . str_pad($fraction, 2, '0'),
            'vigente_desde' => $from,
            'vigente_hasta' => is_string($until) && $until !== '' ? $until : null,
            'activa' => (int) $active,
        ];
    }

    private static function decimalAmount(string $amount): string
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        return $whole . '.' . str_pad($fraction, 2, '0');
    }
}
