<?php
declare(strict_types=1);

namespace App\Models;

use PDO;
use Throwable;

final class BillingConfigurationModel
{
    private AuditModel $audit;

    public function __construct(private PDO $db)
    {
        $this->audit = new AuditModel($db);
    }

    public function current(): ?array
    {
        $statement = $this->db->query(
            'SELECT id_configuracion, dia_vencimiento, activa, configurada_por, creada_en
             FROM configuracion_mensualidades
             WHERE activa = 1
             ORDER BY id_configuracion DESC
             LIMIT 1'
        );
        $configuration = $statement->fetch();
        return $configuration ?: null;
    }

    public function setDueDay(?int $dueDay, int $actorUserId): void
    {
        $this->db->beginTransaction();
        try {
            $find = $this->db->query(
                'SELECT id_configuracion, dia_vencimiento
                 FROM configuracion_mensualidades
                 WHERE activa = 1
                 ORDER BY id_configuracion DESC
                 LIMIT 1 FOR UPDATE'
            );
            $current = $find->fetch();
            if ($current === false) {
                $insert = $this->db->prepare(
                    'INSERT INTO configuracion_mensualidades (dia_vencimiento, activa, configurada_por)
                     VALUES (:dia, 1, :usuario)'
                );
                $insert->execute(['dia' => $dueDay, 'usuario' => $actorUserId]);
                $id = (int) $this->db->lastInsertId();
                $this->audit->record($actorUserId, 'configurar', 'configuracion_mensualidades', $id, [
                    'dia_vencimiento' => $dueDay,
                ]);
            } else {
                $id = (int) $current['id_configuracion'];
                $update = $this->db->prepare(
                    'UPDATE configuracion_mensualidades
                     SET dia_vencimiento = :dia, configurada_por = :usuario
                     WHERE id_configuracion = :id'
                );
                $update->execute([
                    'dia' => $dueDay,
                    'usuario' => $actorUserId,
                    'id' => $id,
                ]);
                if ($update->rowCount() > 0) {
                    $this->audit->record($actorUserId, 'configurar', 'configuracion_mensualidades', $id, [
                        'dia_anterior' => $current['dia_vencimiento'],
                        'dia_nuevo' => $dueDay,
                    ]);
                }
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }
}
