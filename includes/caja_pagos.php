<?php
declare(strict_types=1);

/**
 * Caja de pagos compartida por el panel de recepción y registrar_pago.php.
 *
 * - Efectivo: se captura el efectivo recibido y se calcula el cambio.
 * - Tarjeta: es una SIMULACIÓN. Se valida el formato (Luhn, vencimiento, CVV) y se
 *   genera una autorización falsa; no se contacta a ningún procesador de pagos y
 *   nunca se guarda el número completo ni el CVV (solo los últimos 4 dígitos y el
 *   código simulado quedan en la auditoría).
 *   Tarjeta de prueba aprobada: 4242 4242 4242 4242. Termina en 0002 = rechazo simulado.
 * - Transferencia: el pago ya se hizo en el banco; aquí se captura la clave de rastreo o
 *   referencia (obligatoria y única), el banco de origen y la fecha de la transferencia.
 *   Esos datos quedan en la bitácora (auditoria_eventos) sin cambiar el esquema de la base,
 *   y una misma referencia no se puede registrar dos veces.
 */

use App\Models\DebtModel;
use App\Models\MonthlyChargeModel;
use App\Models\PaymentModel;

/** Alumnos activos con su saldo pendiente (cargos abiertos menos pagos activos aplicados). */
function caja_alumnos(PDO $pdo): array
{
    return $pdo->query(
        "SELECT a.id_alumno, a.nombre, a.apellidos,
                COALESCE(SUM(cm.importe - COALESCE(ap.aplicado, 0)), 0) AS saldo
         FROM alumnos a
         LEFT JOIN cargos_mensuales cm
                ON cm.id_alumno = a.id_alumno AND cm.estado IN ('pendiente', 'vencido')
         LEFT JOIN (
                SELECT x.id_cargo, SUM(x.importe_aplicado) AS aplicado
                FROM aplicaciones_pagos x
                JOIN pagos p ON p.id_pago = x.id_pago AND p.estado = 'activo'
                GROUP BY x.id_cargo
         ) ap ON ap.id_cargo = cm.id_cargo
         WHERE a.estatus = 1
         GROUP BY a.id_alumno, a.nombre, a.apellidos
         ORDER BY a.apellidos, a.nombre"
    )->fetchAll();
}

function caja_money(mixed $value): string
{
    return number_format((float) $value, 2, '.', ',');
}

/** Métodos de pago que puede registrar cualquier usuario de la caja (recepción y administración). */
const CAJA_METODOS = ['Efectivo', 'Transferencia', 'Tarjeta'];

/** Referencia de transferencia normalizada: solo letras y números, en mayúsculas. */
function caja_normalizar_referencia(string $reference): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $reference) ?? '');
}

/**
 * Valida los datos de una transferencia bancaria.
 *
 * @return array{error: ?string, referencia: ?string, banco: ?string, fecha: ?string}
 */
function caja_validar_transferencia(PDO $pdo, array $input): array
{
    $fail = static fn (string $message): array => ['error' => $message, 'referencia' => null, 'banco' => null, 'fecha' => null];

    $reference = $input['transferencia_referencia'] ?? '';
    $bank = $input['transferencia_banco'] ?? '';
    $date = $input['transferencia_fecha'] ?? '';
    if (!is_string($reference) || !is_string($bank) || !is_string($date)) {
        return $fail('Completa los datos de la transferencia.');
    }

    $normalized = caja_normalizar_referencia($reference);
    if (strlen($normalized) < 6 || strlen($normalized) > 40) {
        return $fail('Escribe la clave de rastreo o referencia de la transferencia (de 6 a 40 letras o números).');
    }
    $bank = trim($bank);
    if ($bank === '' || mb_strlen($bank, 'UTF-8') > 80) {
        return $fail('Escribe el banco desde el que se hizo la transferencia.');
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', trim($date));
    if ($parsed === false || $parsed->format('Y-m-d') !== trim($date)) {
        return $fail('Escribe la fecha en que se hizo la transferencia.');
    }
    $today = new DateTimeImmutable('today');
    if ($parsed > $today) {
        return $fail('La fecha de la transferencia no puede ser futura.');
    }
    if ($parsed < $today->modify('-90 days')) {
        return $fail('La transferencia tiene más de 90 días; confirma la fecha con el comprobante.');
    }

    // La referencia solo contiene A-Z y 0-9, por lo que es seguro buscarla con LIKE dentro del JSON de la bitácora.
    $duplicate = $pdo->prepare(
        "SELECT COUNT(*) FROM auditoria_eventos
         WHERE entidad = 'pagos' AND accion = 'registrar'
           AND detalles LIKE :patron"
    );
    $duplicate->execute(['patron' => '%"transferencia_referencia":"' . $normalized . '"%']);
    if ((int) $duplicate->fetchColumn() > 0) {
        return $fail('Esa referencia de transferencia ya fue registrada en otro pago.');
    }

    return ['error' => null, 'referencia' => $normalized, 'banco' => $bank, 'fecha' => $parsed->format('Y-m-d')];
}

/**
 * Referencias de transferencia de varios pagos, indexadas por id_pago (para mostrarlas en tablas).
 *
 * @param list<int> $paymentIds
 * @return array<int, string>
 */
function caja_referencias_pagos(PDO $pdo, array $paymentIds): array
{
    $paymentIds = array_values(array_unique(array_map('intval', $paymentIds)));
    if ($paymentIds === []) {
        return [];
    }
    try {
        $placeholders = implode(',', array_fill(0, count($paymentIds), '?'));
        $statement = $pdo->prepare(
            "SELECT id_entidad, detalles FROM auditoria_eventos
             WHERE entidad = 'pagos' AND accion = 'registrar' AND id_entidad IN ($placeholders)"
        );
        $statement->execute(array_map('strval', $paymentIds));
        $references = [];
        foreach ($statement->fetchAll() as $row) {
            $details = json_decode((string) ($row['detalles'] ?? ''), true);
            if (is_array($details) && isset($details['transferencia_referencia'])) {
                $references[(int) $row['id_entidad']] = (string) $details['transferencia_referencia'];
            }
        }
        return $references;
    } catch (\Throwable $exception) {
        error_log('No se pudieron leer las referencias de transferencia: ' . $exception->getMessage());
        return [];
    }
}

/** Algoritmo de Luhn sobre una cadena de dígitos. */
function caja_luhn_ok(string $digits): bool
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
 * Valida y "autoriza" una tarjeta simulada.
 *
 * @return array{error: ?string, last4: ?string, auth: ?string}
 */
function caja_simular_tarjeta(array $input): array
{
    $fail = static fn (string $message): array => ['error' => $message, 'last4' => null, 'auth' => null];

    $holder = $input['tarjeta_titular'] ?? '';
    $number = $input['tarjeta_numero'] ?? '';
    $expiry = $input['tarjeta_vencimiento'] ?? '';
    $cvv = $input['tarjeta_cvv'] ?? '';
    if (!is_string($holder) || !is_string($number) || !is_string($expiry) || !is_string($cvv)) {
        return $fail('Completa los datos de la tarjeta.');
    }
    if (trim($holder) === '' || mb_strlen(trim($holder), 'UTF-8') > 80) {
        return $fail('Escribe el nombre del titular de la tarjeta.');
    }
    $digits = preg_replace('/[\s-]/', '', $number) ?? '';
    if (preg_match('/^\d{13,19}$/D', $digits) !== 1 || !caja_luhn_ok($digits)) {
        return $fail('El número de tarjeta no es válido.');
    }
    if (preg_match('/^(0[1-9]|1[0-2])\s*\/\s*(\d{2})$/D', trim($expiry), $m) !== 1) {
        return $fail('El vencimiento debe tener formato MM/AA.');
    }
    $lastDay = new DateTimeImmutable(sprintf('20%s-%s-01', $m[2], $m[1]));
    $lastDay = $lastDay->modify('last day of this month')->setTime(23, 59, 59);
    if ($lastDay < new DateTimeImmutable('now')) {
        return $fail('La tarjeta está vencida.');
    }
    if (preg_match('/^\d{3,4}$/D', trim($cvv)) !== 1) {
        return $fail('El CVV debe tener 3 o 4 dígitos.');
    }

    $last4 = substr($digits, -4);
    if ($last4 === '0002') {
        return $fail('Tarjeta rechazada por el banco (simulación). Prueba con otra tarjeta.');
    }
    return ['error' => null, 'last4' => $last4, 'auth' => 'SIM-' . strtoupper(bin2hex(random_bytes(3)))];
}

/**
 * Procesa el POST del formulario de caja. En éxito guarda un mensaje en
 * $_SESSION['flash_pago'] y redirige a $redirectTo (PRG). En error devuelve el mensaje.
 *
 * @param list<string> $allowedMethods Métodos que este usuario puede registrar (Efectivo, Tarjeta, ...)
 */
function caja_procesar_pago(PDO $pdo, array $allowedMethods, string $redirectTo): ?string
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return null;
    }

    $token = $_POST['csrf_token'] ?? '';
    $expected = csrf_token();
    if (!is_string($token) || !hash_equals($expected, $token)) {
        http_response_code(403);
        return 'La solicitud venció. Recarga la página e inténtalo de nuevo.';
    }

    $studentId = filter_var($_POST['id_alumno'] ?? null, FILTER_VALIDATE_INT);
    $amount = $_POST['monto'] ?? '';
    $method = $_POST['metodo_pago'] ?? '';
    $payerName = $_POST['nombre_pagador'] ?? '';

    if ($studentId === false || $studentId < 0) {
        return 'Selecciona un alumno válido.';
    }
    if (!is_string($amount) || preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $amount) !== 1 || (float) $amount <= 0) {
        return 'Escribe un monto mayor que cero, con hasta 8 enteros y 2 decimales.';
    }
    if (!is_string($method) || !in_array($method, $allowedMethods, true)) {
        return 'Selecciona un método de pago válido.';
    }
    if (!is_string($payerName) || trim($payerName) === '' || mb_strlen(trim($payerName), 'UTF-8') > 200) {
        return 'Escribe el nombre de quien realiza el pago.';
    }

    $audit = [];
    $change = null;
    $cardNote = null;
    $amountCents = (int) round(((float) $amount) * 100);

    if ($method === 'Efectivo') {
        $received = $_POST['efectivo_recibido'] ?? '';
        if (!is_string($received) || preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $received) !== 1) {
            return 'Escribe cuánto efectivo se recibió.';
        }
        $receivedCents = (int) round(((float) $received) * 100);
        if ($receivedCents < $amountCents) {
            return 'El efectivo recibido es menor que el monto a pagar.';
        }
        $change = $receivedCents - $amountCents;
        $audit = ['efectivo_recibido' => caja_money($receivedCents / 100), 'cambio' => caja_money($change / 100)];
    } elseif ($method === 'Transferencia') {
        $transfer = caja_validar_transferencia($pdo, $_POST);
        if ($transfer['error'] !== null) {
            return $transfer['error'];
        }
        $audit = [
            'transferencia_referencia' => $transfer['referencia'],
            'transferencia_banco' => $transfer['banco'],
            'transferencia_fecha' => $transfer['fecha'],
        ];
        $cardNote = sprintf('Transferencia %s · %s · %s.', $transfer['referencia'], $transfer['banco'], date('d/m/Y', (int) strtotime((string) $transfer['fecha'])));
    } elseif ($method === 'Tarjeta') {
        $card = caja_simular_tarjeta($_POST);
        if ($card['error'] !== null) {
            return $card['error'];
        }
        $audit = ['tarjeta_simulada' => true, 'tarjeta_ultimos4' => $card['last4'], 'autorizacion_simulada' => $card['auth']];
        $cardNote = sprintf('Tarjeta **** %s · autorización simulada %s.', $card['last4'], $card['auth']);
    }

    try {
        $paymentId = (new PaymentModel($pdo))->create([
            'id_alumno' => $studentId,
            'monto' => $amount,
            'fecha_pago' => date('Y-m-d'),
            'metodo_pago' => strtolower($method),
            'nombre_pagador' => trim($payerName),
            'id_alumno_pagador' => null,
            'id_usuario_registra' => (int) $_SESSION['id_usuario'],
            'detalles_auditoria' => $audit,
        ]);
    } catch (\DomainException $exception) {
        http_response_code(422);
        return $exception->getMessage();
    } catch (\PDOException $exception) {
        error_log('Payment registration failed: ' . $exception->getMessage());
        http_response_code(503);
        return 'No se pudo registrar el pago. Verifica que las migraciones estén aplicadas.';
    } catch (\App\Core\MigrationException $exception) {
        error_log('Payment registration lock error: ' . $exception->getMessage());
        http_response_code(503);
        return 'No se pudo registrar el pago en este momento. Inténtalo de nuevo.';
    } catch (\Throwable $exception) {
        error_log('Payment registration failed: ' . $exception->getMessage());
        http_response_code(500);
        return 'No se pudo registrar el pago.';
    }

    $folioQuery = $pdo->prepare('SELECT folio FROM pagos WHERE id_pago = :id');
    $folioQuery->execute(['id' => $paymentId]);
    $folio = (string) ($folioQuery->fetchColumn() ?: $paymentId);

    $message = sprintf('Pago registrado. Folio %s · $%s en %s.', $folio, caja_money($amount), strtolower($method));
    if ($change !== null) {
        $message .= sprintf(' Cambio a entregar: $%s.', caja_money($change / 100));
    }
    if ($cardNote !== null) {
        $message .= ' ' . $cardNote;
    }
    $_SESSION['flash_pago'] = $message;
    header('Location: ' . $redirectTo);
    exit;
}

/** Marca como vencidos los cargos que ya pasaron su fecha; nunca debe romper la pantalla. */
function caja_actualizar_vencidos(PDO $pdo): void
{
    try {
        (new MonthlyChargeModel($pdo))->refreshOverdue();
    } catch (\Throwable $exception) {
        error_log('No se pudo actualizar cargos vencidos: ' . $exception->getMessage());
    }
}

/** Cargos abiertos de un alumno, para mostrar en pantalla qué se está pagando. */
function caja_cargos_alumno(PDO $pdo, int $studentId): array
{
    try {
        return (new DebtModel($pdo))->allForStudent($studentId);
    } catch (\Throwable $exception) {
        error_log('No se pudieron cargar los cargos del alumno: ' . $exception->getMessage());
        return [];
    }
}

/**
 * Imprime el formulario de caja (todo escapado).
 *
 * @param list<array<string,mixed>> $students  resultado de caja_alumnos()
 * @param list<string>              $methods   métodos permitidos
 * @param array<string,mixed>       $old       valores a repoblar (nunca número de tarjeta ni CVV)
 * @param list<array<string,mixed>> $charges   cargos abiertos del alumno seleccionado
 */
function caja_render_formulario(array $students, array $methods, array $old, string $csrfToken, string $action, ?int $selected, array $charges): void
{
    $e = static fn (mixed $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
    $currentMethod = (string) ($old['metodo_pago'] ?? '');
    if ($currentMethod === '' && isset($_GET['metodo']) && is_string($_GET['metodo']) && in_array($_GET['metodo'], $methods, true)) {
        $currentMethod = $_GET['metodo']; // enlace "Registrar transferencia" del menú
    }
    ?>
    <form method="post" action="<?= $e($action) ?>" class="caja-form" id="caja-form" autocomplete="off" novalidate>
        <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">

        <div class="caja-grid">
            <div class="caja-field caja-wide">
                <label for="id_alumno">Alumno</label>
                <select id="id_alumno" name="id_alumno" required>
                    <option value="">Selecciona un alumno</option>
                    <?php foreach ($students as $student):
                        $saldo = caja_money($student['saldo']);
                        $selectedId = $old['id_alumno'] ?? ($selected !== null ? (string) $selected : '');
                        ?>
                        <option value="<?= $e($student['id_alumno']) ?>"
                                data-saldo="<?= $e(number_format((float) $student['saldo'], 2, '.', '')) ?>"
                                <?= (string) $selectedId === (string) $student['id_alumno'] ? 'selected' : '' ?>>
                            <?= $e($student['apellidos'] . ', ' . $student['nombre']) ?>
                            <?= (float) $student['saldo'] > 0 ? ' — adeuda $' . $e($saldo) : ' — sin adeudo' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="caja-hint" id="caja-saldo" aria-live="polite"></p>
            </div>

            <?php if ($charges !== []): ?>
                <div class="caja-wide caja-cargos">
                    <strong>Cargos pendientes del alumno</strong>
                    <ul>
                        <?php foreach ($charges as $charge): ?>
                            <li>
                                <?= $e($charge['periodo']) ?> · <?= $e(ucfirst(str_replace('_', ' ', (string) ($charge['concepto'] ?? 'mensualidad')))) ?> · saldo $<?= $e(caja_money($charge['saldo_pendiente'])) ?>
                                <?php if (!empty($charge['fecha_vencimiento'])): ?>
                                    · vence <?= $e($charge['fecha_vencimiento']) ?>
                                <?php endif; ?>
                                <?php if (($charge['estado'] ?? '') === 'vencido'): ?>
                                    <span class="caja-badge caja-badge-warn">vencido</span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="caja-hint">El pago se aplica primero al cargo más antiguo.</p>
                </div>
            <?php endif; ?>

            <div class="caja-field">
                <label for="nombre_pagador">Nombre de quien paga</label>
                <input id="nombre_pagador" name="nombre_pagador" type="text" maxlength="200" required
                       value="<?= $e($old['nombre_pagador'] ?? '') ?>">
            </div>

            <div class="caja-field">
                <label for="monto">Monto a pagar (MXN)</label>
                <input id="monto" name="monto" type="number" min="0.01" max="99999999.99" step="0.01" inputmode="decimal" required
                       value="<?= $e($old['monto'] ?? '') ?>">
            </div>

            <fieldset class="caja-wide caja-metodos">
                <legend>Método de pago</legend>
                <?php foreach ($methods as $method): ?>
                    <label class="caja-radio">
                        <input type="radio" name="metodo_pago" value="<?= $e($method) ?>" required
                               <?= $currentMethod === $method ? 'checked' : '' ?>>
                        <span><?= $e($method) ?></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <div class="caja-wide caja-panel" data-metodo="Efectivo" <?= $currentMethod === 'Efectivo' ? '' : 'hidden' ?>>
                <div class="caja-grid">
                    <div class="caja-field">
                        <label for="efectivo_recibido">Efectivo recibido</label>
                        <input id="efectivo_recibido" name="efectivo_recibido" type="number" min="0.01" step="0.01" inputmode="decimal"
                               value="<?= $e($old['efectivo_recibido'] ?? '') ?>">
                    </div>
                    <div class="caja-field">
                        <label>Cambio</label>
                        <output id="caja-cambio" class="caja-cambio" for="monto efectivo_recibido">$0.00</output>
                    </div>
                </div>
            </div>

            <div class="caja-wide caja-panel" data-metodo="Transferencia" <?= $currentMethod === 'Transferencia' ? '' : 'hidden' ?>>
                <p class="caja-hint caja-nota">Registra una transferencia que ya llegó a la cuenta de la escuela. La referencia no puede repetirse.</p>
                <div class="caja-grid">
                    <div class="caja-field caja-wide">
                        <label for="transferencia_referencia">Clave de rastreo / referencia</label>
                        <input id="transferencia_referencia" name="transferencia_referencia" type="text" maxlength="40"
                               autocomplete="off" placeholder="Ej. MBAN01002611250012345"
                               value="<?= $e($old['transferencia_referencia'] ?? '') ?>">
                    </div>
                    <div class="caja-field">
                        <label for="transferencia_banco">Banco de origen</label>
                        <input id="transferencia_banco" name="transferencia_banco" type="text" maxlength="80"
                               autocomplete="off" placeholder="Ej. BBVA, Banorte, Santander"
                               value="<?= $e($old['transferencia_banco'] ?? '') ?>">
                    </div>
                    <div class="caja-field">
                        <label for="transferencia_fecha">Fecha de la transferencia</label>
                        <input id="transferencia_fecha" name="transferencia_fecha" type="date"
                               max="<?= $e(date('Y-m-d')) ?>"
                               value="<?= $e($old['transferencia_fecha'] ?? date('Y-m-d')) ?>">
                    </div>
                </div>
            </div>

            <div class="caja-wide caja-panel caja-tarjeta" data-metodo="Tarjeta" <?= $currentMethod === 'Tarjeta' ? '' : 'hidden' ?>>
                <p class="caja-sim" role="note">
                    <strong>Simulación.</strong> No se realiza ningún cobro real ni se guarda el número de tarjeta.
                    Para probar usa 4242 4242 4242 4242 (aprobada) o una que termine en 0002 (rechazada).
                </p>
                <div class="caja-grid">
                    <div class="caja-field caja-wide">
                        <label for="tarjeta_titular">Titular</label>
                        <input id="tarjeta_titular" name="tarjeta_titular" type="text" maxlength="80" autocomplete="off"
                               placeholder="Como aparece en la tarjeta">
                    </div>
                    <div class="caja-field caja-wide">
                        <label for="tarjeta_numero">Número de tarjeta</label>
                        <input id="tarjeta_numero" name="tarjeta_numero" type="text" inputmode="numeric" maxlength="23"
                               autocomplete="off" placeholder="0000 0000 0000 0000">
                    </div>
                    <div class="caja-field">
                        <label for="tarjeta_vencimiento">Vencimiento</label>
                        <input id="tarjeta_vencimiento" name="tarjeta_vencimiento" type="text" inputmode="numeric" maxlength="5"
                               autocomplete="off" placeholder="MM/AA">
                    </div>
                    <div class="caja-field">
                        <label for="tarjeta_cvv">CVV</label>
                        <input id="tarjeta_cvv" name="tarjeta_cvv" type="password" inputmode="numeric" maxlength="4"
                               autocomplete="off" placeholder="•••">
                    </div>
                </div>
            </div>
        </div>

        <button type="submit" class="caja-submit">Registrar pago</button>
    </form>
    <script src="js/caja-pagos.js?v=1" defer></script>
    <?php
}
