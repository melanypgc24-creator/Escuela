<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DatabaseLock;
use DateTimeImmutable;
use DomainException;
use PDO;
use Throwable;

final class PaymentModel
{
    /**
     * Campos propios de cada método de pago (tarjeta simulada y transferencia). La API y la caja web
     * los envían tal cual a create(); las reglas viven aquí para que exista un solo camino.
     */
    public const METHOD_FIELDS = [
        'tarjeta_titular', 'tarjeta_numero', 'tarjeta_vencimiento', 'tarjeta_cvv',
        'transferencia_referencia', 'transferencia_banco', 'transferencia_fecha',
    ];

    /** Métodos aceptados, ya normalizados (ver normalizeMethod). */
    private const METHODS = ['efectivo', 'tarjeta', 'transferencia', 'other'];

    /** Longitud de pagos.nombre_pagador (VARCHAR(200)). */
    private const PAYER_NAME_MAX = 200;

    private AuditModel $audit;

    public function __construct(private PDO $db)
    {
        $this->audit = new AuditModel($db);
    }

    public function all(): array
    {
        return $this->db->query(
            'SELECT p.id_pago, p.id_alumno, p.concepto, p.monto, p.fecha_pago,
                    p.metodo_pago, p.folio, p.tipo_pago, p.estado, p.nombre_pagador,
                    CONCAT(a.nombre, " ", a.apellidos) AS alumno
             FROM pagos p
             LEFT JOIN alumnos a ON a.id_alumno = p.id_alumno
             ORDER BY p.fecha_pago DESC, p.id_pago DESC'
        )->fetchAll();
    }

    public function allForStudent(int $studentId): array
    {
        $statement = $this->db->prepare(
            'SELECT DISTINCT p.id_pago, p.id_alumno, p.concepto, p.monto, p.fecha_pago,
                    p.metodo_pago, p.folio, p.tipo_pago, p.estado
             FROM pagos p
             WHERE p.id_alumno = :student_charge
                OR p.id_alumno_pagador = :student_payer
             ORDER BY p.fecha_pago DESC, p.id_pago DESC'
        );
        $statement->execute([
            'student_charge' => $studentId,
            'student_payer' => $studentId,
        ]);
        return $statement->fetchAll();
    }

    /**
     * Unifica los valores históricos de la API (cash/card/transfer) con los que usa
     * la interfaz web, para que los reportes agrupen igual sin importar el origen.
     */
    private static function normalizeMethod(string $method): string
    {
        $method = strtolower(trim($method));
        return match ($method) {
            'cash' => 'efectivo',
            'card' => 'tarjeta',
            'transfer' => 'transferencia',
            default => $method,
        };
    }

    /**
     * Registra un pago de mensualidad. Aquí viven todas las reglas, sin importar si el pago llega por la
     * caja web o por la API:
     *  - monto mayor que cero (hasta 8 enteros y 2 decimales);
     *  - fecha_pago: solo la de hoy (si se omite, se usa hoy);
     *  - nombre_pagador obligatorio, de hasta 200 caracteres;
     *  - tarjeta: titular, número (Luhn), vencimiento y CVV; nunca se guarda el número completo ni el CVV;
     *  - transferencia: referencia (única), banco y fecha de la transferencia.
     * Cualquier incumplimiento lanza DomainException con un mensaje listo para mostrar.
     */
    public function create(array $data): int
    {
        $rules = self::validatePayment($data);
        $data['monto'] = self::fromCents($rules['cents']);
        $data['fecha_pago'] = $rules['fecha'];
        $data['metodo_pago'] = $rules['metodo'];
        $data['nombre_pagador'] = $rules['pagador'];
        $data['detalles_auditoria'] = $rules['detalles']
            + (is_array($data['detalles_auditoria'] ?? null) ? $data['detalles_auditoria'] : []);

        if ($rules['referencia'] !== null) {
            // La referencia es única entre todos los alumnos, así que también se serializa por referencia.
            return DatabaseLock::run(
                $this->db,
                'escuela:pago:ref:' . strtolower($rules['referencia']),
                fn (): int => $this->register($data)
            );
        }
        return $this->register($data);
    }

    /**
     * Detalles guardados en la bitácora al registrar un pago (tarjeta simulada, transferencia, efectivo).
     *
     * @return array<string, mixed>
     */
    public function registrationDetails(int $paymentId): array
    {
        $statement = $this->db->prepare(
            "SELECT detalles FROM auditoria_eventos
             WHERE entidad = 'pagos' AND accion = 'registrar' AND id_entidad = :id
             ORDER BY id_evento DESC LIMIT 1"
        );
        $statement->execute(['id' => (string) $paymentId]);
        $decoded = json_decode((string) ($statement->fetchColumn() ?: ''), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function register(array $data): int
    {
        $studentId = (int) $data['id_alumno'];
        return DatabaseLock::run(
            $this->db,
            'escuela:pago:alumno:' . $studentId,
            function () use ($data, $studentId): int {
                $reference = $data['detalles_auditoria']['transferencia_referencia'] ?? null;
                if (is_string($reference)) {
                    $this->assertReferenceUnused($reference);
                }
                $this->db->beginTransaction();
                try {
                    $charges = $this->db->prepare(
                        'SELECT cm.id_cargo, cm.importe, cm.estado,
                                COALESCE((
                                    SELECT SUM(ap.importe_aplicado)
                                    FROM aplicaciones_pagos ap
                                    JOIN pagos px ON px.id_pago = ap.id_pago AND px.estado = "activo"
                                    WHERE ap.id_cargo = cm.id_cargo AND ap.id_alumno = cm.id_alumno
                                ), 0) AS importe_aplicado
                         FROM cargos_mensuales cm
                         WHERE cm.id_alumno = :id_alumno
                           AND cm.estado IN ("pendiente", "vencido")
                         ORDER BY cm.periodo, cm.id_cargo
                         FOR UPDATE'
                    );
                    $charges->execute(['id_alumno' => $studentId]);
                    $openCharges = $charges->fetchAll();
                    $outstanding = [];
                    $totalOutstanding = 0;
                    foreach ($openCharges as $charge) {
                        $remaining = self::toCents($charge['importe']) - self::toCents($charge['importe_aplicado']);
                        if ($remaining < 0) {
                            throw new DomainException('El cargo tiene aplicaciones superiores a su importe; requiere revisión manual.');
                        }
                        if ($remaining > 0) {
                            $outstanding[] = ['id_cargo' => (int) $charge['id_cargo'], 'remaining' => $remaining];
                            $totalOutstanding += $remaining;
                        }
                    }

                    $paymentCents = self::toCents($data['monto']);
                    if ($totalOutstanding === 0) {
                        throw new DomainException('No hay cargos mensuales pendientes para este alumno.');
                    }
                    if ($paymentCents > $totalOutstanding) {
                        throw new DomainException('El pago supera el saldo pendiente; los anticipos aún no tienen una regla definida.');
                    }

                    $insert = $this->db->prepare(
                        'INSERT INTO pagos
                            (id_alumno, concepto, monto, fecha_pago, metodo_pago, tipo_pago, estado,
                             nombre_pagador, id_alumno_pagador, id_usuario_registra)
                         VALUES
                            (:id_alumno, "Mensualidad", :monto, :fecha_pago, :metodo_pago, "mensualidad", "activo",
                             :nombre_pagador, :id_alumno_pagador, :id_usuario_registra)'
                    );
                    $insert->execute([
                        'id_alumno' => $studentId,
                        'monto' => self::fromCents($paymentCents),
                        'fecha_pago' => $data['fecha_pago'],
                        'metodo_pago' => $data['metodo_pago'],
                        'nombre_pagador' => $data['nombre_pagador'],
                        'id_alumno_pagador' => $data['id_alumno_pagador'],
                        'id_usuario_registra' => $data['id_usuario_registra'],
                    ]);
                    $paymentId = (int) $this->db->lastInsertId();
                    if ($paymentId < 1) {
                        throw new DomainException('La base de datos no devolvió un identificador de pago válido.');
                    }

                    // Folio legible y único derivado del ID (la columna folio ya existía pero nunca se llenaba).
                    $folio = sprintf(
                        'TC-%s-%06d',
                        date('Ymd', (int) strtotime((string) $data['fecha_pago'])),
                        $paymentId
                    );
                    $setFolio = $this->db->prepare(
                        'UPDATE pagos SET folio = :folio WHERE id_pago = :id_pago AND folio IS NULL'
                    );
                    $setFolio->execute(['folio' => $folio, 'id_pago' => $paymentId]);

                    $apply = $this->db->prepare(
                        'INSERT INTO aplicaciones_pagos
                            (id_pago, id_cargo, id_alumno, importe_aplicado)
                         VALUES (:id_pago, :id_cargo, :id_alumno, :importe_aplicado)'
                    );
                    $markPaid = $this->db->prepare(
                        'UPDATE cargos_mensuales SET estado = "pagado"
                         WHERE id_cargo = :id_cargo AND id_alumno = :id_alumno'
                    );
                    $remainingPayment = $paymentCents;
                    foreach ($outstanding as $charge) {
                        if ($remainingPayment === 0) {
                            break;
                        }
                        $applied = min($remainingPayment, $charge['remaining']);
                        $apply->execute([
                            'id_pago' => $paymentId,
                            'id_cargo' => $charge['id_cargo'],
                            'id_alumno' => $studentId,
                            'importe_aplicado' => self::fromCents($applied),
                        ]);
                        $remainingPayment -= $applied;
                        if ($applied === $charge['remaining']) {
                            $markPaid->execute(['id_cargo' => $charge['id_cargo'], 'id_alumno' => $studentId]);
                        }
                    }

                    $history = $this->db->prepare(
                        'INSERT INTO historial_pagos
                            (id_pago, estado_anterior, estado_nuevo, importe_anterior, importe_nuevo,
                             id_usuario_responsable, motivo)
                         VALUES (:id_pago, NULL, "activo", NULL, :importe, :id_usuario, "Registro de pago")'
                    );
                    $history->execute([
                        'id_pago' => $paymentId,
                        'importe' => self::fromCents($paymentCents),
                        'id_usuario' => $data['id_usuario_registra'],
                    ]);
                    $this->audit->record(
                        (int) $data['id_usuario_registra'],
                        'registrar',
                        'pagos',
                        $paymentId,
                        ['id_alumno' => $studentId, 'metodo_pago' => $data['metodo_pago'], 'folio' => $folio]
                        + (is_array($data['detalles_auditoria'] ?? null) ? $data['detalles_auditoria'] : [])
                    );

                    $this->db->commit();
                    return $paymentId;
                } catch (Throwable $exception) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    throw $exception;
                }
            }
        );
    }

    public function cancel(int $paymentId, int $actorUserId, string $reason): bool
    {
        $lookup = $this->db->prepare('SELECT id_alumno FROM pagos WHERE id_pago = :id_pago');
        $lookup->execute(['id_pago' => $paymentId]);
        $studentValue = $lookup->fetchColumn();
        if ($studentValue === false) {
            return false;
        }
        $studentId = $studentValue === null ? null : (int) $studentValue;
        $lockName = $studentId === null
            ? 'escuela:pago:registro'
            : 'escuela:pago:alumno:' . $studentId;

        return DatabaseLock::run(
            $this->db,
            $lockName,
            function () use ($paymentId, $actorUserId, $reason, $studentId): bool {
                $this->db->beginTransaction();
                try {
                    $paymentQuery = $this->db->prepare(
                        'SELECT id_pago, id_alumno, monto, estado
                         FROM pagos WHERE id_pago = :id_pago FOR UPDATE'
                    );
                    $paymentQuery->execute(['id_pago' => $paymentId]);
                    $payment = $paymentQuery->fetch();
                    if ($payment === false) {
                        $this->db->commit();
                        return false;
                    }
                    if ($payment['estado'] !== 'activo') {
                        throw new DomainException('Solo se pueden cancelar pagos activos.');
                    }

                    $update = $this->db->prepare(
                        'UPDATE pagos SET estado = "cancelado" WHERE id_pago = :id_pago AND estado = "activo"'
                    );
                    $update->execute(['id_pago' => $paymentId]);
                    if ($update->rowCount() !== 1) {
                        throw new DomainException('No se pudo cancelar el pago; vuelve a consultar su estado.');
                    }

                    if ($studentId !== null) {
                        $chargeIds = $this->db->prepare(
                            'SELECT DISTINCT id_cargo FROM aplicaciones_pagos WHERE id_pago = :id_pago'
                        );
                        $chargeIds->execute(['id_pago' => $paymentId]);
                        $chargeQuery = $this->db->prepare(
                            'SELECT cm.importe, cm.fecha_vencimiento,
                                    COALESCE((
                                        SELECT SUM(ap.importe_aplicado)
                                        FROM aplicaciones_pagos ap
                                        JOIN pagos p ON p.id_pago = ap.id_pago AND p.estado = "activo"
                                        WHERE ap.id_cargo = cm.id_cargo
                                          AND ap.id_alumno = cm.id_alumno
                                    ), 0) AS importe_aplicado
                             FROM cargos_mensuales cm
                             WHERE cm.id_cargo = :id_cargo AND cm.id_alumno = :id_alumno
                             FOR UPDATE'
                        );
                        $setStatus = $this->db->prepare(
                            'UPDATE cargos_mensuales
                             SET estado = :estado
                             WHERE id_cargo = :id_cargo AND id_alumno = :id_alumno'
                        );
                        foreach ($chargeIds->fetchAll(PDO::FETCH_COLUMN) as $chargeId) {
                            $chargeQuery->execute([
                                'id_cargo' => (int) $chargeId,
                                'id_alumno' => $studentId,
                            ]);
                            $charge = $chargeQuery->fetch();
                            if ($charge === false) {
                                throw new DomainException('No se encontró un cargo aplicado; requiere revisión manual.');
                            }
                            $remaining = self::toCents($charge['importe']) - self::toCents($charge['importe_aplicado']);
                            if ($remaining < 0) {
                                throw new DomainException('El cargo quedó sobreaplicado; requiere revisión manual.');
                            }
                            $status = $remaining === 0
                                ? 'pagado'
                                : ($charge['fecha_vencimiento'] !== null && $charge['fecha_vencimiento'] < date('Y-m-d')
                                    ? 'vencido'
                                    : 'pendiente');
                            $setStatus->execute([
                                'estado' => $status,
                                'id_cargo' => (int) $chargeId,
                                'id_alumno' => $studentId,
                            ]);
                        }
                    }

                    $history = $this->db->prepare(
                        'INSERT INTO historial_pagos
                            (id_pago, estado_anterior, estado_nuevo, importe_anterior, importe_nuevo,
                             id_usuario_responsable, motivo)
                         VALUES (:id_pago, "activo", "cancelado", :importe, :importe, :id_usuario, :motivo)'
                    );
                    $history->execute([
                        'id_pago' => $paymentId,
                        'importe' => $payment['monto'],
                        'id_usuario' => $actorUserId,
                        'motivo' => $reason,
                    ]);
                    $this->audit->record($actorUserId, 'cancelar', 'pagos', $paymentId, ['motivo' => $reason]);
                    $this->db->commit();
                    return true;
                } catch (Throwable $exception) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    throw $exception;
                }
            }
        );
    }

    /**
     * Valida y normaliza los datos de un pago.
     *
     * @return array{cents: int, fecha: string, metodo: string, pagador: string, detalles: array<string, mixed>, referencia: ?string}
     */
    private static function validatePayment(array $data): array
    {
        $cents = self::parseAmount($data['monto'] ?? null);
        $fecha = self::normalizePaymentDate($data['fecha_pago'] ?? null);

        $payer = $data['nombre_pagador'] ?? null;
        if (!is_string($payer) || trim($payer) === '' || mb_strlen(trim($payer), 'UTF-8') > self::PAYER_NAME_MAX) {
            throw new DomainException(sprintf(
                'Escribe el nombre de quien realiza el pago (máximo %d caracteres).',
                self::PAYER_NAME_MAX
            ));
        }

        $rawMethod = $data['metodo_pago'] ?? null;
        $method = is_string($rawMethod) ? self::normalizeMethod($rawMethod) : '';
        if (!in_array($method, self::METHODS, true)) {
            throw new DomainException('Selecciona un método de pago válido.');
        }

        $details = [];
        $reference = null;
        if ($method === 'tarjeta') {
            $details = self::simulateCard($data);
        } elseif ($method === 'transferencia') {
            $transfer = self::validateTransfer($data);
            $details = $transfer['detalles'];
            $reference = $transfer['referencia'];
        }

        return [
            'cents' => $cents,
            'fecha' => $fecha,
            'metodo' => $method,
            'pagador' => trim($payer),
            'detalles' => $details,
            'referencia' => $reference,
        ];
    }

    private static function parseAmount(mixed $amount): int
    {
        $message = 'Escribe un monto mayor que cero, con hasta 8 enteros y 2 decimales.';
        if (!is_string($amount) && !is_int($amount) && !is_float($amount)) {
            throw new DomainException($message);
        }
        $normalized = trim((string) $amount);
        if (preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $normalized) !== 1) {
            throw new DomainException($message);
        }
        $cents = self::toCents($normalized);
        if ($cents <= 0) {
            throw new DomainException($message);
        }
        return $cents;
    }

    /** Los pagos solo se registran con la fecha de hoy (la caja web siempre lo hizo así). */
    private static function normalizePaymentDate(mixed $value): string
    {
        $today = date('Y-m-d');
        if ($value === null || $value === '') {
            return $today;
        }
        if (!is_string($value)) {
            throw new DomainException('La fecha de pago no es válida.');
        }
        $value = trim($value);
        $date = null;
        foreach (['Y-m-d', 'Y-m-d H:i:s'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat('!' . $format, $value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($parsed !== false
                && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && $parsed->format($format) === $value) {
                $date = $parsed->format('Y-m-d');
                break;
            }
        }
        if ($date === null) {
            throw new DomainException('La fecha de pago no es válida.');
        }
        if ($date !== $today) {
            throw new DomainException(sprintf(
                'La fecha de pago debe ser la de hoy (%s); no se aceptan pagos con fecha pasada ni futura.',
                $today
            ));
        }
        return $date;
    }

    /** Referencia de transferencia normalizada: solo letras y números, en mayúsculas. */
    private static function normalizeReference(string $reference): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $reference) ?? '');
    }

    /**
     * Valida los datos de una transferencia bancaria (referencia, banco y fecha de la transferencia).
     *
     * @return array{referencia: string, detalles: array<string, string>}
     */
    private static function validateTransfer(array $input): array
    {
        $reference = $input['transferencia_referencia'] ?? '';
        $bank = $input['transferencia_banco'] ?? '';
        $date = $input['transferencia_fecha'] ?? '';
        if (!is_string($reference) || !is_string($bank) || !is_string($date)) {
            throw new DomainException('Completa los datos de la transferencia.');
        }

        $normalized = self::normalizeReference($reference);
        if (strlen($normalized) < 6 || strlen($normalized) > 40) {
            throw new DomainException('Escribe la clave de rastreo o referencia de la transferencia (de 6 a 40 letras o números).');
        }
        $bank = trim($bank);
        if ($bank === '' || mb_strlen($bank, 'UTF-8') > 80) {
            throw new DomainException('Escribe el banco desde el que se hizo la transferencia.');
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', trim($date));
        if ($parsed === false || $parsed->format('Y-m-d') !== trim($date)) {
            throw new DomainException('Escribe la fecha en que se hizo la transferencia.');
        }
        $today = new DateTimeImmutable('today');
        if ($parsed > $today) {
            throw new DomainException('La fecha de la transferencia no puede ser futura.');
        }
        if ($parsed < $today->modify('-90 days')) {
            throw new DomainException('La transferencia tiene más de 90 días; confirma la fecha con el comprobante.');
        }

        return [
            'referencia' => $normalized,
            'detalles' => [
                'transferencia_referencia' => $normalized,
                'transferencia_banco' => $bank,
                'transferencia_fecha' => $parsed->format('Y-m-d'),
            ],
        ];
    }

    /** Una misma referencia de transferencia no puede registrarse dos veces. */
    private function assertReferenceUnused(string $reference): void
    {
        // La referencia solo contiene A-Z y 0-9, por lo que es seguro buscarla con LIKE dentro del JSON de la bitácora.
        $duplicate = $this->db->prepare(
            "SELECT COUNT(*) FROM auditoria_eventos
             WHERE entidad = 'pagos' AND accion = 'registrar'
               AND detalles LIKE :patron"
        );
        $duplicate->execute(['patron' => '%"transferencia_referencia":"' . $reference . '"%']);
        if ((int) $duplicate->fetchColumn() > 0) {
            throw new DomainException('Esa referencia de transferencia ya fue registrada en otro pago.');
        }
    }

    /** Algoritmo de Luhn sobre una cadena de dígitos. */
    private static function luhnOk(string $digits): bool
    {
        if ($digits === '' || !ctype_digit($digits)) {
            return false;
        }
        $sum = 0;
        $double = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($double) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $double = !$double;
        }
        return $sum % 10 === 0;
    }

    /**
     * Valida y "autoriza" una tarjeta simulada: no se contacta a ningún procesador de pagos y nunca se
     * guarda el número completo ni el CVV (solo los últimos 4 dígitos y el código simulado).
     * Tarjeta de prueba aprobada: 4242 4242 4242 4242. Termina en 0002 = rechazo simulado.
     *
     * @return array{tarjeta_simulada: bool, tarjeta_ultimos4: string, autorizacion_simulada: string}
     */
    private static function simulateCard(array $input): array
    {
        $holder = $input['tarjeta_titular'] ?? '';
        $number = $input['tarjeta_numero'] ?? '';
        $expiry = $input['tarjeta_vencimiento'] ?? '';
        $cvv = $input['tarjeta_cvv'] ?? '';
        if (!is_string($holder) || !is_string($number) || !is_string($expiry) || !is_string($cvv)) {
            throw new DomainException('Completa los datos de la tarjeta.');
        }
        if (trim($holder) === '' || mb_strlen(trim($holder), 'UTF-8') > 80) {
            throw new DomainException('Escribe el nombre del titular de la tarjeta.');
        }
        $digits = preg_replace('/[\s-]/', '', $number) ?? '';
        if (preg_match('/^\d{13,19}$/D', $digits) !== 1 || !self::luhnOk($digits)) {
            throw new DomainException('El número de tarjeta no es válido.');
        }
        if (preg_match('/^(0[1-9]|1[0-2])\s*\/\s*(\d{2})$/D', trim($expiry), $m) !== 1) {
            throw new DomainException('El vencimiento debe tener formato MM/AA.');
        }
        $lastDay = new DateTimeImmutable(sprintf('20%s-%s-01', $m[2], $m[1]));
        $lastDay = $lastDay->modify('last day of this month')->setTime(23, 59, 59);
        if ($lastDay < new DateTimeImmutable('now')) {
            throw new DomainException('La tarjeta está vencida.');
        }
        if (preg_match('/^\d{3,4}$/D', trim($cvv)) !== 1) {
            throw new DomainException('El CVV debe tener 3 o 4 dígitos.');
        }

        $last4 = substr($digits, -4);
        if ($last4 === '0002') {
            throw new DomainException('Tarjeta rechazada por el banco (simulación). Prueba con otra tarjeta.');
        }
        return [
            'tarjeta_simulada' => true,
            'tarjeta_ultimos4' => $last4,
            'autorizacion_simulada' => 'SIM-' . strtoupper(bin2hex(random_bytes(3))),
        ];
    }

    private static function toCents(string|int|float $amount): int
    {
        $normalized = trim((string) $amount);
        if (preg_match('/^\d+(?:\.\d{1,2})?$/D', $normalized) !== 1) {
            throw new DomainException('El importe debe ser positivo y tener como máximo dos decimales.');
        }
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private static function fromCents(int $amount): string
    {
        return intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
