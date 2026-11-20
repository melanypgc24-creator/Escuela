<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use Throwable;

/**
 * Estado activo/inactivo de las cuentas. Mientras la migración 006 no se aplique
 * (columna usuarios.activo ausente) todas las cuentas se consideran activas.
 */
final class AccountStatus
{
    private static array $columnCache = [];

    public static function hasActiveColumn(PDO $db): bool
    {
        $key = spl_object_id($db);
        if (!isset(self::$columnCache[$key])) {
            try {
                $statement = $db->query(
                    "SELECT 1 FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = 'usuarios' AND column_name = 'activo' LIMIT 1"
                );
                self::$columnCache[$key] = $statement !== false && $statement->fetchColumn() !== false;
            } catch (Throwable $exception) {
                error_log('No se pudo inspeccionar usuarios.activo: ' . $exception->getMessage());
                self::$columnCache[$key] = false;
            }
        }
        return self::$columnCache[$key];
    }

    public static function isActive(PDO $db, int $userId): bool
    {
        if (!self::hasActiveColumn($db)) {
            return true;
        }
        $statement = $db->prepare('SELECT activo FROM usuarios WHERE id_usuario = :id LIMIT 1');
        $statement->execute(['id' => $userId]);
        $value = $statement->fetchColumn();
        return $value !== false && (int) $value === 1;
    }
}
