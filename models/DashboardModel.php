<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class DashboardModel
{
    public function __construct(private PDO $db)
    {
    }

    public function summary(): array
    {
        return [
            'alumnos_activos' => (int) $this->db->query('SELECT COUNT(*) FROM alumnos WHERE estatus = 1')->fetchColumn(),
            'tarifas_activas' => (int) $this->db->query('SELECT COUNT(*) FROM tarifas_mensuales WHERE activa = 1')->fetchColumn(),
            'cargos_pendientes' => (int) $this->db->query(
                'SELECT COUNT(*) FROM cargos_mensuales WHERE estado IN ("pendiente", "vencido")'
            )->fetchColumn(),
            'pagos_activos_mes' => (string) $this->db->query(
                'SELECT COALESCE(SUM(monto), 0)
                 FROM pagos
                 WHERE estado = "activo"
                   AND fecha_pago >= DATE_FORMAT(CURRENT_DATE, "%Y-%m-01")
                   AND fecha_pago < DATE_ADD(DATE_FORMAT(CURRENT_DATE, "%Y-%m-01"), INTERVAL 1 MONTH)'
            )->fetchColumn(),
            'pagos_historicos_sin_clasificar_mes' => (int) $this->db->query(
                'SELECT COUNT(*) FROM pagos
                 WHERE estado IS NULL
                   AND fecha_pago >= DATE_FORMAT(CURRENT_DATE, "%Y-%m-01")
                   AND fecha_pago < DATE_ADD(DATE_FORMAT(CURRENT_DATE, "%Y-%m-01"), INTERVAL 1 MONTH)'
            )->fetchColumn(),
        ];
    }
}
