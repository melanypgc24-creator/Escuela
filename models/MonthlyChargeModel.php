<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DatabaseLock;
use DateTimeImmutable;
use DomainException;
use PDO;
use Throwable;

/**
 * Genera los cargos mensuales (cargos_mensuales) de los alumnos activos para
 * un periodo dado, a partir de la tarifa vigente de su categoría.
 *
 * Reglas aplicadas (documentadas porque antes de esto la generación estaba
 * deshabilitada a propósito, ver BillingConfigurationController):
 *  - Solo se cobra a alumnos con estatus = 1 (activo) y categoria definida
 *    ('infantil' o 'adulta'). Un alumno sin categoria se omite y se reporta,
 *    porque no hay forma de saber qué tarifa le corresponde.
 *  - Se usa la tarifa activa (tarifas_mensuales.activa = 1) de esa categoría.
 *    Si no existe, el alumno se omite y se reporta como error de datos.
 *  - El cargo es por el 100% de la tarifa (porcentaje = 100.00). Prorrateo
 *    por alta a mitad de mes NO está implementado: si lo necesitas, es el
 *    siguiente paso natural sobre este mismo método.
 *  - fecha_vencimiento: la elige el administrador al generar el cargo (por
 *    lote o individual). Si no se indica, se usa el día `dia_vencimiento` de
 *    configuracion_mensualidades dentro del propio periodo, recortado al último día del mes si el día
 *    configurado no existe en ese mes (p. ej. 31 de febrero). Si no hay un
 *    día configurado, el cargo queda sin fecha de vencimiento (NULL).
 *  - Si el alumno no tiene categoría capturada (los registros nuevos nacen sin
 *    ella), se deduce de su fecha de nacimiento: menor de ADULT_AGE años =
 *    infantil, en otro caso adulta. Una categoría capturada manualmente siempre
 *    tiene prioridad. Ajusta ADULT_AGE si la escuela usa otro corte.
 *  - Los cargos pendientes cuya fecha de vencimiento ya pasó se marcan como
 *    'vencido' (refreshOverdue) al generar cargos y al abrir la caja.
 *  - Nunca se duplica un cargo: cargos_mensuales tiene UNIQUE(id_alumno,
 *    periodo), así que repetir la generación del mismo periodo es seguro
 *    (los alumnos que ya tienen cargo simplemente se reportan como "ya
 *    existía", no se tocan).
 */
final class MonthlyChargeModel
{
    /** Edad a partir de la cual un alumno sin categoría capturada se cobra como adulto. */
    public const ADULT_AGE = 18;

    /** Conceptos de cargo permitidos (columna cargos_mensuales.concepto). */
    public const CONCEPTS = ['mensualidad', 'clase', 'clase_individual', 'otro'];

    private AuditModel $audit;

    public function __construct(private PDO $db)
    {
        $this->audit = new AuditModel($db);
    }

    /**
     * @return array{
     *   periodo: string,
     *   creados: int,
     *   ya_existian: int,
     *   omitidos: list<array{id_alumno:int, nombre:string, motivo:string}>
     * }
     */
    /**
     * Marca como 'vencido' los cargos pendientes cuya fecha de vencimiento ya pasó.
     * Antes nada cambiaba ese estado, así que el filtro "vencido" nunca encontraba cargos.
     * Es idempotente y no toca cargos pagados ni sin fecha de vencimiento.
     */
    public function refreshOverdue(): int
    {
        $statement = $this->db->prepare(
            "UPDATE cargos_mensuales
             SET estado = 'vencido'
             WHERE estado = 'pendiente'
               AND fecha_vencimiento IS NOT NULL
               AND fecha_vencimiento < CURRENT_DATE"
        );
        $statement->execute();
        return $statement->rowCount();
    }

    /**
     * @param string|null $dueDate Fecha de vencimiento (AAAA-MM-DD) elegida por el administrador;
     *                             null usa el día configurado en configuracion_mensualidades.
     */
    public function generateForPeriod(string $periodo, int $actorUserId, ?string $dueDate = null): array
    {
        $this->assertPeriod($periodo);
        $dueDate = $this->normalizeDueDate($periodo, $dueDate);

        $lockName = 'cargos_' . substr(hash('sha256', $periodo), 0, 24);
        $this->refreshOverdue();

        return DatabaseLock::run($this->db, $lockName, function () use ($periodo, $actorUserId, $dueDate): array {
            $this->db->beginTransaction();
            try {
                $result = $this->generateWithinTransaction($periodo, $actorUserId, $dueDate);
                $this->db->commit();
                return $result;
            } catch (Throwable $exception) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $exception;
            }
        });
    }

    /**
     * @return array{periodo: string, creados: int, ya_existian: int, omitidos: list<array{id_alumno:int, nombre:string, motivo:string}>}
     */
    private function generateWithinTransaction(string $periodo, int $actorUserId, ?string $explicitDueDate): array
    {
        $tariffs = $this->activeTariffsByCategory();
        if ($explicitDueDate !== null) {
            $dueDate = $explicitDueDate;
        } else {
            $dueDay = $this->currentDueDay();
            $dueDate = $dueDay !== null ? $this->dueDateForPeriod($periodo, $dueDay) : null;
        }

        $students = $this->db->prepare(
            'SELECT id_alumno, nombre, apellidos, categoria, fecha_nacimiento
             FROM alumnos
             WHERE estatus = 1
             FOR UPDATE'
        );
        $students->execute();

        $created = 0;
        $alreadyExisted = 0;
        $skipped = [];

        $existsStatement = $this->db->prepare(
            'SELECT 1 FROM cargos_mensuales WHERE id_alumno = :id_alumno AND periodo = :periodo AND concepto = \'mensualidad\' LIMIT 1'
        );
        $insertStatement = $this->db->prepare(
            'INSERT INTO cargos_mensuales
                (id_alumno, periodo, id_tarifa, categoria, importe_tarifa, porcentaje, importe,
                 fecha_vencimiento, estado, id_usuario_generador)
             VALUES
                (:id_alumno, :periodo, :id_tarifa, :categoria, :importe_tarifa, 100.00, :importe,
                 :fecha_vencimiento, \'pendiente\', :id_usuario_generador)'
        );

        foreach ($students->fetchAll() as $student) {
            $studentId = (int) $student['id_alumno'];
            $fullName = trim($student['nombre'] . ' ' . $student['apellidos']);
            $category = $student['categoria'] ?? null;
            if ($category !== 'infantil' && $category !== 'adulta') {
                $category = $this->categoryFromBirthDate($student['fecha_nacimiento'] ?? null, $periodo);
            }

            if ($category !== 'infantil' && $category !== 'adulta') {
                $skipped[] = [
                    'id_alumno' => $studentId,
                    'nombre' => $fullName,
                    'motivo' => 'Sin categoría (infantil/adulta) definida; no se puede elegir tarifa.',
                ];
                continue;
            }

            if (!isset($tariffs[$category])) {
                $skipped[] = [
                    'id_alumno' => $studentId,
                    'nombre' => $fullName,
                    'motivo' => sprintf('No hay una tarifa activa para la categoría "%s".', $category),
                ];
                continue;
            }

            $existsStatement->execute(['id_alumno' => $studentId, 'periodo' => $periodo]);
            if ($existsStatement->fetchColumn() !== false) {
                $alreadyExisted++;
                continue;
            }

            $tariff = $tariffs[$category];
            $insertStatement->execute([
                'id_alumno' => $studentId,
                'periodo' => $periodo,
                'id_tarifa' => $tariff['id_tarifa'],
                'categoria' => $category,
                'importe_tarifa' => $tariff['importe'],
                'importe' => $tariff['importe'],
                'fecha_vencimiento' => $dueDate,
                'id_usuario_generador' => $actorUserId,
            ]);
            $created++;
        }

        if ($created > 0) {
            $this->audit->record($actorUserId, 'generar', 'cargos_mensuales', $periodo, [
                'periodo' => $periodo,
                'creados' => $created,
                'ya_existian' => $alreadyExisted,
                'omitidos' => count($skipped),
                'fecha_vencimiento' => $dueDate,
            ]);
        }

        return [
            'periodo' => $periodo,
            'creados' => $created,
            'ya_existian' => $alreadyExisted,
            'omitidos' => $skipped,
        ];
    }

    /**
     * Crea el cargo de UN alumno para un periodo, con la fecha de vencimiento elegida.
     * Lanza DomainException con un mensaje legible si no se puede crear.
     */
    public function createForStudent(int $studentId, string $periodo, ?string $dueDate, int $actorUserId, ?string $customAmount = null, string $concept = 'mensualidad'): int
    {
        $this->assertPeriod($periodo);
        if (!in_array($concept, self::CONCEPTS, true)) {
            throw new DomainException('El concepto del cargo no es válido.');
        }
        $customAmount = $this->normalizeAmount($customAmount);
        if ($concept !== 'mensualidad' && $customAmount === null) {
            throw new DomainException('Escribe el importe de la clase individual u otro concepto.');
        }
        $dueDate = $this->normalizeDueDate($periodo, $dueDate);
        if ($dueDate === null) {
            $dueDay = $this->currentDueDay();
            $dueDate = $dueDay !== null ? $this->dueDateForPeriod($periodo, $dueDay) : null;
        }

        $lockName = 'cargos_' . substr(hash('sha256', $periodo), 0, 24);

        return DatabaseLock::run($this->db, $lockName, function () use ($studentId, $periodo, $dueDate, $actorUserId, $customAmount, $concept): int {
            $this->db->beginTransaction();
            try {
                $statement = $this->db->prepare(
                    'SELECT id_alumno, nombre, apellidos, categoria, fecha_nacimiento, estatus
                     FROM alumnos WHERE id_alumno = :id FOR UPDATE'
                );
                $statement->execute(['id' => $studentId]);
                $student = $statement->fetch();
                if ($student === false) {
                    throw new DomainException('El alumno seleccionado no existe.');
                }
                if ((int) $student['estatus'] !== 1) {
                    throw new DomainException('El alumno está inactivo; no se le pueden generar cargos.');
                }

                $category = $student['categoria'] ?? null;
                if ($category !== 'infantil' && $category !== 'adulta') {
                    $category = $this->categoryFromBirthDate($student['fecha_nacimiento'] ?? null, $periodo);
                }
                if ($category !== 'infantil' && $category !== 'adulta') {
                    throw new DomainException('El alumno no tiene categoría ni fecha de nacimiento; no se puede elegir tarifa.');
                }
                $tariffs = $this->activeTariffsByCategory();
                if (!isset($tariffs[$category])) {
                    throw new DomainException(sprintf('No hay una tarifa activa para la categoría "%s".', $category));
                }

                $exists = $this->db->prepare(
                    'SELECT 1 FROM cargos_mensuales WHERE id_alumno = :id_alumno AND periodo = :periodo AND concepto = \'mensualidad\' LIMIT 1'
                );
                $exists->execute(['id_alumno' => $studentId, 'periodo' => $periodo]);
                if ($concept === 'mensualidad' && $exists->fetchColumn() !== false) {
                    throw new DomainException('Ese alumno ya tiene un cargo para el periodo ' . $periodo . '.');
                }

                $tariff = $tariffs[$category];
                // Si el admin escribió un importe (clase individual, descuento, etc.) se usa ese;
                // si no, se usa la tarifa de la categoría.
                $amount = $customAmount ?? $tariff['importe'];
                $insert = $this->db->prepare(
                    'INSERT INTO cargos_mensuales
                        (id_alumno, periodo, concepto, id_tarifa, categoria, importe_tarifa, porcentaje, importe,
                         fecha_vencimiento, estado, id_usuario_generador)
                     VALUES
                        (:id_alumno, :periodo, :concepto, :id_tarifa, :categoria, :importe_tarifa, 100.00, :importe,
                         :fecha_vencimiento, \'pendiente\', :id_usuario_generador)'
                );
                $insert->execute([
                    'concepto' => $concept,
                    'id_alumno' => $studentId,
                    'periodo' => $periodo,
                    'id_tarifa' => $tariff['id_tarifa'],
                    'categoria' => $category,
                    'importe_tarifa' => $amount,
                    'importe' => $amount,
                    'fecha_vencimiento' => $dueDate,
                    'id_usuario_generador' => $actorUserId,
                ]);
                $chargeId = (int) $this->db->lastInsertId();

                $this->audit->record($actorUserId, 'generar', 'cargos_mensuales', $chargeId, [
                    'periodo' => $periodo,
                    'id_alumno' => $studentId,
                    'importe' => $amount,
                    'concepto' => $concept,
                    'fecha_vencimiento' => $dueDate,
                    'individual' => true,
                ]);

                $this->db->commit();
                return $chargeId;
            } catch (Throwable $exception) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $exception;
            }
        });
    }

    /**
     * Cambia la fecha de vencimiento de un cargo que aún se debe. Si la nueva fecha ya pasó
     * el cargo queda 'vencido'; si es futura, vuelve a 'pendiente'.
     */
    public function updateDueDate(int $chargeId, string $dueDate, int $actorUserId): void
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate);
        if ($date === false || $date->format('Y-m-d') !== $dueDate) {
            throw new DomainException('La fecha de vencimiento no es válida.');
        }

        $this->db->beginTransaction();
        try {
            $current = $this->db->prepare(
                'SELECT periodo, fecha_vencimiento, estado FROM cargos_mensuales WHERE id_cargo = :id FOR UPDATE'
            );
            $current->execute(['id' => $chargeId]);
            $charge = $current->fetch();
            if ($charge === false) {
                throw new DomainException('El cargo no existe.');
            }
            if ($charge['estado'] === 'pagado') {
                throw new DomainException('Un cargo pagado no puede cambiar de fecha de vencimiento.');
            }
            if ($dueDate < $charge['periodo'] . '-01') {
                throw new DomainException('El vencimiento no puede ser anterior al inicio del periodo del cargo.');
            }

            $update = $this->db->prepare(
                'UPDATE cargos_mensuales
                 SET fecha_vencimiento = :fecha,
                     estado = IF(:fecha_estado < CURRENT_DATE, \'vencido\', \'pendiente\')
                 WHERE id_cargo = :id'
            );
            $update->execute(['fecha' => $dueDate, 'fecha_estado' => $dueDate, 'id' => $chargeId]);

            $this->audit->record($actorUserId, 'modificar', 'cargos_mensuales', $chargeId, [
                'vencimiento_anterior' => $charge['fecha_vencimiento'],
                'vencimiento_nuevo' => $dueDate,
            ]);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Cambia el importe de un cargo que aún se debe (por ejemplo, de mensualidad a clase individual).
     * Un cargo pagado no se toca.
     */
    public function updateAmount(int $chargeId, string $amount, int $actorUserId): void
    {
        $normalized = $this->normalizeAmount($amount);
        if ($normalized === null) {
            throw new DomainException('Escribe el importe del cargo.');
        }

        $this->db->beginTransaction();
        try {
            $current = $this->db->prepare(
                'SELECT importe, estado FROM cargos_mensuales WHERE id_cargo = :id FOR UPDATE'
            );
            $current->execute(['id' => $chargeId]);
            $charge = $current->fetch();
            if ($charge === false) {
                throw new DomainException('El cargo no existe.');
            }
            if ($charge['estado'] === 'pagado') {
                throw new DomainException('Un cargo pagado no puede cambiar de importe.');
            }

            $update = $this->db->prepare(
                'UPDATE cargos_mensuales SET importe = :importe, importe_tarifa = :importe_tarifa, porcentaje = 100.00
                 WHERE id_cargo = :id'
            );
            $update->execute(['importe' => $normalized, 'importe_tarifa' => $normalized, 'id' => $chargeId]);

            $this->audit->record($actorUserId, 'modificar', 'cargos_mensuales', $chargeId, [
                'importe_anterior' => $charge['importe'],
                'importe_nuevo' => $normalized,
            ]);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Cargo automático del primer mes: se crea el día de la inscripción y vence ese mismo día.
     * Nunca debe romper la inscripción: si algo falla (sin tarifa, ya existe), simplemente no se crea.
     */
    public function createOnEnrollment(int $studentId, int $actorUserId): ?int
    {
        try {
            return $this->createForStudent($studentId, date('Y-m'), date('Y-m-d'), $actorUserId);
        } catch (Throwable $exception) {
            error_log('Cargo de inscripción no creado: ' . $exception->getMessage());
            return null;
        }
    }

    /**
     * Cargo que se genera cuando el profesor acepta al alumno en una clase.
     * Si la clase tiene costo, se cobra ese costo (concepto 'clase'); si el costo es 0,
     * se cobra la mensualidad de su categoría (y si ya la tiene este mes, no se duplica).
     * Nunca debe romper la aceptación: ante cualquier problema solo se registra en el log.
     */
    public function createOnClassAcceptance(int $studentId, int $classId, int $actorUserId): ?int
    {
        try {
            $statement = $this->db->prepare('SELECT costo FROM clases WHERE id_clase = :id');
            $statement->execute(['id' => $classId]);
            $cost = (float) ($statement->fetchColumn() ?: 0);
            $periodo = date('Y-m');
            $today = date('Y-m-d');
            if ($cost > 0) {
                return $this->createForStudent($studentId, $periodo, $today, $actorUserId, number_format($cost, 2, '.', ''), 'clase');
            }
            return $this->createForStudent($studentId, $periodo, $today, $actorUserId);
        } catch (Throwable $exception) {
            error_log('Cargo por aceptación de clase no creado: ' . $exception->getMessage());
            return null;
        }
    }

    /** Valida un importe escrito a mano. Devuelve null si viene vacío. */
    private function normalizeAmount(?string $amount): ?string
    {
        if ($amount === null || trim($amount) === '') {
            return null;
        }
        $amount = str_replace([',', '$', ' '], '', $amount);
        if (!is_numeric($amount) || (float) $amount <= 0 || (float) $amount > 99999999) {
            throw new DomainException('El importe debe ser un número mayor a 0.');
        }
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * Cargos de un periodo con el alumno, para que el administrador revise lo generado.
     *
     * @return list<array<string,mixed>>
     */
    public function listForPeriod(string $periodo): array
    {
        $this->assertPeriod($periodo);
        $statement = $this->db->prepare(
            'SELECT cm.id_cargo, cm.id_alumno, CONCAT(a.nombre, \' \', a.apellidos) AS alumno,
                    cm.categoria, cm.concepto, cm.importe, cm.fecha_vencimiento, cm.estado
             FROM cargos_mensuales cm
             JOIN alumnos a ON a.id_alumno = cm.id_alumno
             WHERE cm.periodo = :periodo
             ORDER BY a.apellidos, a.nombre'
        );
        $statement->execute(['periodo' => $periodo]);
        return $statement->fetchAll();
    }

    /** Periodos que ya tienen cargos, del más reciente al más antiguo. @return list<string> */
    public function periodsWithCharges(int $limit = 24): array
    {
        $statement = $this->db->prepare(
            'SELECT DISTINCT periodo FROM cargos_mensuales ORDER BY periodo DESC LIMIT ' . max(1, min($limit, 120))
        );
        $statement->execute();
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Cuántos alumnos activos aún no tienen cargo en el periodo (para avisar al administrador). */
    public function studentsWithoutCharge(string $periodo): int
    {
        $this->assertPeriod($periodo);
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM alumnos a
             WHERE a.estatus = 1
               AND NOT EXISTS (SELECT 1 FROM cargos_mensuales cm WHERE cm.id_alumno = a.id_alumno AND cm.periodo = :periodo)'
        );
        $statement->execute(['periodo' => $periodo]);
        return (int) $statement->fetchColumn();
    }

    /** Día de vencimiento predeterminado (1-31) o null si no está configurado. */
    public function defaultDueDay(): ?int
    {
        return $this->currentDueDay();
    }

    /** Fecha de vencimiento que se sugiere para un periodo (la del día predeterminado, o el día 10). */
    public function suggestedDueDate(string $periodo): string
    {
        $this->assertPeriod($periodo);
        return $this->dueDateForPeriod($periodo, $this->currentDueDay() ?? 10);
    }

    private function assertPeriod(string $periodo): void
    {
        if (preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $periodo) !== 1) {
            throw new DomainException('El periodo debe tener formato AAAA-MM, por ejemplo 2026-11.');
        }
    }

    /**
     * Valida la fecha elegida por el administrador. Devuelve null si viene vacía.
     * No puede ser anterior al primer día del periodo que se cobra.
     */
    private function normalizeDueDate(string $periodo, ?string $dueDate): ?string
    {
        if ($dueDate === null || trim($dueDate) === '') {
            return null;
        }
        $dueDate = trim($dueDate);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate);
        if ($date === false || $date->format('Y-m-d') !== $dueDate) {
            throw new DomainException('La fecha de vencimiento no es válida.');
        }
        if ($dueDate < $periodo . '-01') {
            throw new DomainException('El vencimiento no puede ser anterior al inicio del periodo que se cobra.');
        }
        return $dueDate;
    }

    /** Deduce la categoría por edad al inicio del periodo; null si no hay fecha de nacimiento válida. */
    private function categoryFromBirthDate(?string $birthDate, string $periodo): ?string
    {
        if ($birthDate === null || $birthDate === '' || $birthDate === '0000-00-00') {
            return null;
        }
        try {
            $birth = new DateTimeImmutable($birthDate);
            $periodStart = new DateTimeImmutable($periodo . '-01');
        } catch (Throwable) {
            return null;
        }
        return $birth->diff($periodStart)->y < self::ADULT_AGE ? 'infantil' : 'adulta';
    }

    /**
     * @return array<string, array{id_tarifa:int, importe:string}>
     */
    private function activeTariffsByCategory(): array
    {
        $rows = $this->db->query(
            'SELECT id_tarifa, categoria, importe FROM tarifas_mensuales WHERE activa = 1'
        )->fetchAll();

        $byCategory = [];
        foreach ($rows as $row) {
            $byCategory[$row['categoria']] = [
                'id_tarifa' => (int) $row['id_tarifa'],
                'importe' => $row['importe'],
            ];
        }
        return $byCategory;
    }

    private function currentDueDay(): ?int
    {
        $statement = $this->db->query(
            'SELECT dia_vencimiento FROM configuracion_mensualidades WHERE activa = 1 ORDER BY id_configuracion DESC LIMIT 1'
        );
        $row = $statement->fetch();
        if ($row === false || $row['dia_vencimiento'] === null) {
            return null;
        }
        return (int) $row['dia_vencimiento'];
    }

    private function dueDateForPeriod(string $periodo, int $dueDay): string
    {
        [$year, $month] = array_map('intval', explode('-', $periodo));
        $firstOfMonth = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $lastDay = (int) $firstOfMonth->format('t');
        $day = min($dueDay, $lastDay);
        return $firstOfMonth->modify(sprintf('+%d days', $day - 1))->format('Y-m-d');
    }
}
