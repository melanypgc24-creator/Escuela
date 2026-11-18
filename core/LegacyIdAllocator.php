<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

final class LegacyIdAllocator
{
    public static function lockNameForTable(string $tableName): string
    {
        TableSchemaInspector::assertIdentifier($tableName, 'tableName');
        return 'id_alloc_' . substr(hash('sha256', strtolower($tableName)), 0, 32);
    }

    public static function nextId(PDO $db, string $tableName, string $idColumn): int
    {
        TableSchemaInspector::assertIdentifier($tableName, 'tableName');
        TableSchemaInspector::assertIdentifier($idColumn, 'idColumn');
        TableSchemaInspector::columnMetadata($db, $tableName, $idColumn);

        if (!$db->inTransaction()) {
            throw new MigrationException('La asignación manual de IDs requiere una transacción activa.');
        }

        DatabaseLock::assertHeldByCurrentConnection($db, self::lockNameForTable($tableName));

        $query = sprintf(
            'SELECT `%1$s`
             FROM `%2$s`
             ORDER BY `%1$s` DESC
             LIMIT 1',
            $idColumn,
            $tableName
        );

        $statement = $db->query($query);
        $lastId = $statement->fetchColumn();
        if ($lastId === false) {
            return 1;
        }

        $lastIdInteger = filter_var((string) $lastId, FILTER_VALIDATE_INT);
        if ($lastIdInteger === false || $lastIdInteger === PHP_INT_MAX) {
            throw new MigrationException(
                sprintf('El identificador máximo de %s.%s no se puede incrementar con seguridad.', $tableName, $idColumn)
            );
        }

        return $lastIdInteger + 1;
    }
}

