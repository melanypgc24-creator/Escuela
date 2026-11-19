<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class MembershipModel
{
    private AuditModel $audit;

    public function __construct(private PDO $db)
    {
        $this->audit = new AuditModel($db);
    }

    public function all(): array
    {
        return $this->db->query(
            'SELECT id_tarifa, categoria, importe, vigente_desde, vigente_hasta, activa
             FROM tarifas_mensuales
             ORDER BY categoria, vigente_desde DESC, id_tarifa DESC'
        )->fetchAll();
    }

    public function current(): array
    {
        return $this->db->query(
            'SELECT id_tarifa, categoria, importe, vigente_desde, vigente_hasta, activa
             FROM tarifas_mensuales
             WHERE activa = 1
               AND vigente_desde IS NOT NULL
               AND vigente_desde <= CURRENT_DATE
               AND (vigente_hasta IS NULL OR vigente_hasta >= CURRENT_DATE)
             ORDER BY categoria'
        )->fetchAll();
    }

    public function create(array $data): int
    {
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                'INSERT INTO tarifas_mensuales
                    (categoria, importe, vigente_desde, vigente_hasta, activa, id_usuario_crea)
                 VALUES
                    (:categoria, :importe, :vigente_desde, :vigente_hasta, :activa, :id_usuario_crea)'
            );
            $statement->execute($data);
            $id = (int) $this->db->lastInsertId();
            $this->audit->record((int) $data['id_usuario_crea'], 'crear', 'tarifas_mensuales', $id, [
                'categoria' => $data['categoria'],
                'importe' => $data['importe'],
                'vigente_desde' => $data['vigente_desde'],
                'vigente_hasta' => $data['vigente_hasta'],
                'activa' => $data['activa'],
            ]);
            $this->db->commit();
            return $id;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function update(int $id, array $data, int $actorUserId): bool
    {
        $this->db->beginTransaction();
        try {
            $find = $this->db->prepare(
                'SELECT categoria, importe, vigente_desde, vigente_hasta, activa
                 FROM tarifas_mensuales WHERE id_tarifa = :id FOR UPDATE'
            );
            $find->execute(['id' => $id]);
            $old = $find->fetch();
            if ($old === false) {
                $this->db->commit();
                return false;
            }

            $update = $this->db->prepare(
                'UPDATE tarifas_mensuales
                 SET categoria = :categoria, importe = :importe, vigente_desde = :vigente_desde,
                     vigente_hasta = :vigente_hasta, activa = :activa
                 WHERE id_tarifa = :id'
            );
            $update->execute($data + ['id' => $id]);
            if ($update->rowCount() > 0) {
                $this->audit->record($actorUserId, 'actualizar', 'tarifas_mensuales', $id, [
                    'antes' => $old,
                    'despues' => [
                        'categoria' => $data['categoria'],
                        'importe' => $data['importe'],
                        'vigente_desde' => $data['vigente_desde'],
                        'vigente_hasta' => $data['vigente_hasta'],
                        'activa' => $data['activa'],
                    ],
                ]);
            }
            $this->db->commit();
            return true;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }
}
