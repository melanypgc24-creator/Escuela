<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

final class TableSchemaInspector
{
    public static function usesAutoIncrement(PDO $db, string $tableName, string $columnName): bool
    {
        $column = self::columnMetadata($db, $tableName, $columnName);
        return stripos($column['extra'], 'auto_increment') !== false;
    }

    /**
     * @return array{extra:string}
     */
    public static function columnMetadata(PDO $db, string $tableName, string $columnName): array
    {
        self::assertIdentifier($tableName, 'tableName');
        self::assertIdentifier($columnName, 'columnName');

        try {
            $statement = $db->prepare(
                "SELECT t.table_name AS found_table, c.column_name AS found_column, c.extra
                 FROM information_schema.tables t
                 LEFT JOIN information_schema.columns c
                   ON c.table_schema = t.table_schema
                  AND c.table_name = t.table_name
                  AND c.column_name = :column_name
                 WHERE t.table_schema = DATABASE()
                   AND t.table_name = :table_name
                 LIMIT 1"
            );
            $statement->execute([
                'table_name' => $tableName,
                'column_name' => $columnName,
            ]);
            $metadata = $statement->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            throw new MigrationException(
                sprintf('Error al consultar metadatos de %s.%s: %s', $tableName, $columnName, $exception->getMessage()),
                0,
                $exception
            );
        }

        if (!is_array($metadata)) {
            throw new MigrationException(
                sprintf('La tabla "%s" no existe en la base de datos conectada.', $tableName)
            );
        }
        if (!is_string($metadata['found_column'] ?? null)) {
            throw new MigrationException(
                sprintf('La columna "%s" no existe en la tabla "%s".', $columnName, $tableName)
            );
        }

        return ['extra' => (string) ($metadata['extra'] ?? '')];
    }

    public static function assertIdentifier(string $name, string $argumentName): void
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) !== 1) {
            throw new MigrationException(sprintf('Identificador inválido en %s: %s', $argumentName, $name));
        }
    }
}

