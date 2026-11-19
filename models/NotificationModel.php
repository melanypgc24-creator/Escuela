<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class NotificationModel
{
    public function __construct(private PDO $db)
    {
    }

    public function allForUser(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT n.id_notificacion, n.id_cargo, n.tipo, n.fecha_creacion, n.leida, n.estado,
                    c.periodo, c.importe, c.fecha_vencimiento
             FROM notificaciones n
             JOIN cargos_mensuales c ON c.id_cargo = n.id_cargo
             WHERE n.id_usuario_destinatario = :id_usuario
             ORDER BY n.fecha_creacion DESC, n.id_notificacion DESC'
        );
        $statement->execute(['id_usuario' => $userId]);
        return $statement->fetchAll();
    }

    public function markRead(int $notificationId, int $userId): bool
    {
        $statement = $this->db->prepare(
            'UPDATE notificaciones SET leida = 1
             WHERE id_notificacion = :id_notificacion
               AND id_usuario_destinatario = :id_usuario'
        );
        $statement->execute([
            'id_notificacion' => $notificationId,
            'id_usuario' => $userId,
        ]);
        if ($statement->rowCount() > 0) {
            return true;
        }

        $check = $this->db->prepare(
            'SELECT 1 FROM notificaciones
             WHERE id_notificacion = :id_notificacion
               AND id_usuario_destinatario = :id_usuario'
        );
        $check->execute([
            'id_notificacion' => $notificationId,
            'id_usuario' => $userId,
        ]);
        return $check->fetchColumn() !== false;
    }
}
