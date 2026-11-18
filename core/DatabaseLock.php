<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use Throwable;

final class DatabaseLock
{
    private const MAX_LOCK_NAME_BYTES = 64;

    public static function acquire(PDO $db, string $lockName, int $timeoutSeconds = 10): void
    {
        self::validateLockName($lockName);
        if ($timeoutSeconds < 0 || $timeoutSeconds > 86400) {
            throw new MigrationException('El timeout del lock debe estar entre 0 y 86400 segundos.');
        }

        $statement = $db->prepare('SELECT GET_LOCK(:lock_name, :timeout_seconds)');
        $statement->bindValue('lock_name', $lockName, PDO::PARAM_STR);
        $statement->bindValue('timeout_seconds', $timeoutSeconds, PDO::PARAM_INT);
        $statement->execute();
        $acquired = $statement->fetchColumn();

        if ($acquired === 1 || $acquired === '1') {
            return;
        }
        if ($acquired === 0 || $acquired === '0') {
            throw new MigrationException(
                sprintf('Se agotó el timeout de %d segundos al adquirir el lock "%s".', $timeoutSeconds, $lockName)
            );
        }

        throw new MigrationException(
            sprintf('MariaDB no pudo adquirir el lock "%s" (GET_LOCK devolvió NULL o un valor inesperado).', $lockName)
        );
    }

    public static function release(PDO $db, string $lockName): void
    {
        self::validateLockName($lockName);
        $statement = $db->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $statement->execute(['lock_name' => $lockName]);
        $released = $statement->fetchColumn();

        if ($released === 1 || $released === '1') {
            return;
        }
        if ($released === 0 || $released === '0') {
            throw new MigrationException(
                sprintf('La conexión actual no es propietaria del lock "%s"; no se liberó.', $lockName)
            );
        }

        throw new MigrationException(
            sprintf('El lock "%s" no existe o MariaDB no pudo liberarlo (RELEASE_LOCK devolvió NULL o un valor inesperado).', $lockName)
        );
    }

    public static function assertHeldByCurrentConnection(PDO $db, string $lockName): void
    {
        self::validateLockName($lockName);
        $statement = $db->prepare(
            'SELECT IS_USED_LOCK(:lock_name), CONNECTION_ID()'
        );
        $statement->execute(['lock_name' => $lockName]);
        $ownership = $statement->fetch(PDO::FETCH_NUM);

        if (!is_array($ownership) || !array_key_exists(0, $ownership)
            || !array_key_exists(1, $ownership) || $ownership[0] === null
            || (string) $ownership[0] !== (string) $ownership[1]) {
            throw new MigrationException(
                sprintf('La conexión actual no posee el lock requerido "%s".', $lockName)
            );
        }
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public static function run(PDO $db, string $lockName, callable $callback, int $timeoutSeconds = 10): mixed
    {
        self::acquire($db, $lockName, $timeoutSeconds);

        $result = null;
        $operationException = null;
        try {
            $result = $callback();
        } catch (Throwable $exception) {
            $operationException = $exception;
        }

        try {
            self::release($db, $lockName);
        } catch (Throwable $releaseException) {
            if ($operationException !== null) {
                throw new MigrationException(
                    sprintf(
                        'Falló la operación protegida (%s) y también la liberación del lock "%s" (%s).',
                        $operationException->getMessage(),
                        $lockName,
                        $releaseException->getMessage()
                    ),
                    0,
                    $operationException
                );
            }
            throw new MigrationException(
                sprintf('La operación finalizó, pero no se pudo liberar el lock "%s": %s', $lockName, $releaseException->getMessage()),
                0,
                $releaseException
            );
        }

        if ($operationException !== null) {
            throw $operationException;
        }

        return $result;
    }

    private static function validateLockName(string $lockName): void
    {
        if ($lockName === '' || strlen($lockName) > self::MAX_LOCK_NAME_BYTES
            || preg_match('/^[a-z0-9:_-]+$/D', $lockName) !== 1) {
            throw new MigrationException(
                'Nombre de lock inválido: debe contener únicamente minúsculas ASCII, dígitos, dos puntos, guiones o guiones bajos y medir como máximo 64 bytes.'
            );
        }
    }
}

