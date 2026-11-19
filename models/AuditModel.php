<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class AuditModel
{
    public function __construct(private PDO $db)
    {
    }

    public function record(int $userId, string $action, string $entity, int|string $entityId, array $details = []): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO auditoria_eventos (id_usuario, accion, entidad, id_entidad, detalles)
             VALUES (:id_usuario, :accion, :entidad, :id_entidad, :detalles)'
        );
        $statement->execute([
            'id_usuario' => $userId,
            'accion' => $action,
            'entidad' => $entity,
            'id_entidad' => (string) $entityId,
            'detalles' => $details === []
                ? null
                : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }
}
