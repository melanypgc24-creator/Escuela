<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class ProfessorModel
{
    public function __construct(private PDO $db)
    {
    }

    public function all(): array
    {
        return $this->db->query(
            'SELECT id_profesor, nombre, apellidos, telefono
             FROM profesor
             ORDER BY apellidos, nombre'
        )->fetchAll();
    }
}
