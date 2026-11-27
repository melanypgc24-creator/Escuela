<?php
declare(strict_types=1);

/**
 * Caja de pagos compartida por el panel de recepción y registrar_pago.php.
 *
 * Las reglas del pago (monto, fecha de hoy, nombre del pagador, tarjeta simulada y transferencia) viven en
 * App\Models\PaymentModel::create(), el mismo camino que usa la API. Esta caja solo recoge el formulario,
 * aplica lo que es propio de la pantalla y muestra el resultado:
 *
 * - Efectivo: se captura el efectivo recibido y se calcula el cambio (esto es solo de la pantalla).
 * - Tarjeta: es una SIMULACIÓN. El modelo valida el formato (Luhn, vencimiento, CVV) y genera una
 *   autorización falsa; no se contacta a ningún procesador de pagos y nunca se guarda el número completo
 *   ni el CVV (solo los últimos 4 dígitos y el código simulado quedan en la auditoría).
 *   Tarjeta de prueba aprobada: 4242 4242 4242 4242. Termina en 0002 = rechazo simulado.
 * - Transferencia: el pago ya se hizo en el banco; se captura la clave de rastreo o referencia
 *   (obligatoria y única), el banco de origen y la fecha de la transferencia. El modelo deja esos datos en
 *   la bitácora (auditoria_eventos) sin cambiar el esquema de la base, y una misma referencia no se puede
 *   registrar dos veces.
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

    if ($studentId === false || $studentId < 0) {
        return 'Selecciona un alumno válido.';
    }
    if (!is_string($method) || !in_array($method, $allowedMethods, true)) {
        return 'Selecciona un método de pago válido.';
    }

    // Lo único propio de la pantalla es el efectivo recibido y el cambio. El resto de las reglas
    // (monto, fecha, nombre del pagador, tarjeta, transferencia) las valida PaymentModel::create().
    $audit = [];
    $change = null;
    if ($method === 'Efectivo') {
        if (!is_string($amount) || preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $amount) !== 1 || (float) $amount <= 0) {
            return 'Escribe un monto mayor que cero, con hasta 8 enteros y 2 decimales.';
        }
        $amountCents = (int) round(((float) $amount) * 100);
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
    }

    $payments = new PaymentModel($pdo);
    try {
        $paymentId = $payments->create([
            'id_alumno' => $studentId,
            'monto' => $amount,
            'metodo_pago' => strtolower($method),
            'nombre_pagador' => $_POST['nombre_pagador'] ?? '',
            'id_alumno_pagador' => null,
            'id_usuario_registra' => (int) $_SESSION['id_usuario'],
            'detalles_auditoria' => $audit,
        ] + array_intersect_key($_POST, array_flip(PaymentModel::METHOD_FIELDS)));
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

    // Nota para el mensaje de confirmación, a partir de lo que el modelo dejó en la bitácora.
    $cardNote = null;
    try {
        $details = $payments->registrationDetails($paymentId);
    } catch (\Throwable $exception) {
        error_log('No se pudieron leer los detalles del pago: ' . $exception->getMessage());
        $details = [];
    }
    if (isset($details['transferencia_referencia'])) {
        $cardNote = sprintf(
            'Transferencia %s · %s · %s.',
            (string) $details['transferencia_referencia'],
            (string) ($details['transferencia_banco'] ?? ''),
            date('d/m/Y', (int) strtotime((string) ($details['transferencia_fecha'] ?? '')))
        );
    } elseif (isset($details['tarjeta_ultimos4'])) {
        $cardNote = sprintf(
            'Tarjeta **** %s · autorización simulada %s.',
            (string) $details['tarjeta_ultimos4'],
            (string) ($details['autorizacion_simulada'] ?? '')
        );
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
    $_SESSION['flash_pago_id'] = $paymentId;
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
