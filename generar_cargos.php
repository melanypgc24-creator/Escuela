<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth_check.php';
require_permission('billing_config.write');
require __DIR__ . '/includes/panel_shell.php';

use App\Models\BillingConfigurationModel;
use App\Models\MonthlyChargeModel;

header('Cache-Control: no-store');

$csrfToken = csrf_token();
$actorId = (int) $_SESSION['id_usuario'];
$periodPattern = '/^[0-9]{4}-(0[1-9]|1[0-2])$/D';
$currentPeriod = date('Y-m');

$flash = $_SESSION['flash_cargos'] ?? null;
unset($_SESSION['flash_cargos']);
$error = null;

try {
    $pdo = require __DIR__ . '/config/conexion.php';
} catch (\PDOException $exception) {
    error_log('Charges page connection failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('No se pudo conectar con la base de datos.');
}
$model = new MonthlyChargeModel($pdo);

// Marca como vencidos los cargos que ya pasaron su fecha, para que los contadores sean reales.
try {
    $model->refreshOverdue();
} catch (\Throwable $exception) {
    error_log('No se pudo actualizar cargos vencidos: ' . $exception->getMessage());
}

$old = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $old = $_POST;
    $token = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';
    $periodo = $_POST['periodo'] ?? '';
    $dueDate = $_POST['fecha_vencimiento'] ?? '';

    if (!is_string($token) || !hash_equals($csrfToken, $token)) {
        http_response_code(403);
        $error = 'La sesión del formulario venció. Recarga la página e inténtalo de nuevo.';
    } elseif (!is_string($action) || !in_array($action, ['generar_lote', 'crear_individual', 'cambiar_vencimiento', 'cambiar_importe'], true)) {
        $error = 'La acción solicitada no es válida.';
    } elseif (!is_string($periodo) || preg_match($periodPattern, $periodo) !== 1) {
        $error = 'Elige un periodo válido (AAAA-MM).';
    } elseif ($action !== 'cambiar_importe' && (!is_string($dueDate) || trim($dueDate) === '')) {
        $error = 'Elige la fecha de vencimiento del cargo.';
    } else {
        $dueDate = is_string($dueDate) ? trim($dueDate) : '';
        try {
            if ($action === 'generar_lote') {
                $result = $model->generateForPeriod($periodo, $actorId, $dueDate);
                if (($_POST['guardar_dia'] ?? '') === '1') {
                    // El día elegido queda como predeterminado para los cargos automáticos (generar_cargos_cli.php).
                    (new BillingConfigurationModel($pdo))->setDueDay((int) substr($dueDate, 8, 2), $actorId);
                }
                $_SESSION['flash_cargos'] = [
                    'message' => sprintf(
                        'Periodo %s: %d cargo(s) creado(s) con vencimiento el %s, %d ya existía(n), %d omitido(s).',
                        $result['periodo'],
                        $result['creados'],
                        date('d/m/Y', (int) strtotime($dueDate)),
                        $result['ya_existian'],
                        count($result['omitidos'])
                    ),
                    'omitidos' => $result['omitidos'],
                ];
            } elseif ($action === 'crear_individual') {
                $studentId = filter_var($_POST['id_alumno'] ?? null, FILTER_VALIDATE_INT);
                if ($studentId === false || $studentId < 0) {
                    throw new \DomainException('Selecciona un alumno válido.');
                }
                $amount = is_string($_POST['importe'] ?? null) ? $_POST['importe'] : null;
                $concept = is_string($_POST['concepto'] ?? null) ? $_POST['concepto'] : 'mensualidad';
                $model->createForStudent((int) $studentId, $periodo, $dueDate, $actorId, $amount, $concept);
                $_SESSION['flash_cargos'] = [
                    'message' => sprintf(
                        'Cargo de %s creado con vencimiento el %s.',
                        $periodo,
                        date('d/m/Y', (int) strtotime($dueDate))
                    ),
                    'omitidos' => [],
                ];
            } elseif ($action === 'cambiar_importe') {
                $chargeId = filter_var($_POST['id_cargo'] ?? null, FILTER_VALIDATE_INT);
                if ($chargeId === false || $chargeId < 1) {
                    throw new \DomainException('Selecciona un cargo válido.');
                }
                $model->updateAmount((int) $chargeId, (string) ($_POST['importe'] ?? ''), $actorId);
                $_SESSION['flash_cargos'] = ['message' => 'El importe del cargo se actualizó.', 'omitidos' => []];
            } else {
                $chargeId = filter_var($_POST['id_cargo'] ?? null, FILTER_VALIDATE_INT);
                if ($chargeId === false || $chargeId < 1) {
                    throw new \DomainException('Selecciona un cargo válido.');
                }
                $model->updateDueDate((int) $chargeId, $dueDate, $actorId);
                $_SESSION['flash_cargos'] = [
                    'message' => 'La fecha de vencimiento se actualizó al ' . date('d/m/Y', (int) strtotime($dueDate)) . '.',
                    'omitidos' => [],
                ];
            }
            header('Location: generar_cargos.php?periodo=' . $periodo);
            exit;
        } catch (\DomainException $exception) {
            $error = $exception->getMessage();
        } catch (\App\Core\MigrationException $exception) {
            error_log('Charge generation lock error: ' . $exception->getMessage());
            http_response_code(503);
            $error = 'Otro proceso está generando cargos de este periodo. Inténtalo de nuevo en unos segundos.';
        } catch (\PDOException $exception) {
            error_log('Charge action "' . $action . '" failed: ' . $exception->getMessage());
            http_response_code(503);
            $error = 'No se pudo completar la operación. Verifica que las migraciones estén aplicadas.';
        } catch (\Throwable $exception) {
            error_log('Charge action "' . $action . '" failed: ' . $exception->getMessage());
            http_response_code(500);
            $error = 'Ocurrió un error al guardar los cargos. Inténtalo más tarde.';
        }
    }
}

// Periodo que se está revisando en la tabla (GET) y datos de apoyo de la pantalla.
$viewPeriod = $_GET['periodo'] ?? $currentPeriod;
if (!is_string($viewPeriod) || preg_match($periodPattern, $viewPeriod) !== 1) {
    $viewPeriod = $currentPeriod;
}

$charges = [];
$periods = [];
$students = [];
$missing = 0;
$suggestedDue = date('Y-m-10');
$defaultDay = null;
$loadError = null;
try {
    $charges = $model->listForPeriod($viewPeriod);
    $periods = $model->periodsWithCharges();
    $missing = $model->studentsWithoutCharge($currentPeriod);
    $suggestedDue = $model->suggestedDueDate($currentPeriod);
    $defaultDay = $model->defaultDueDay();
    $students = $pdo->query(
        'SELECT id_alumno, nombre, apellidos FROM alumnos WHERE estatus = 1 ORDER BY apellidos, nombre'
    )->fetchAll();
} catch (\Throwable $exception) {
    error_log('Charges page data failed: ' . $exception->getMessage());
    $loadError = 'No se pudieron cargar todos los datos de cargos. Verifica que las migraciones estén aplicadas.';
}
foreach ([$currentPeriod, $viewPeriod] as $needed) {
    if (!in_array($needed, $periods, true)) {
        $periods[] = $needed;
    }
}
rsort($periods);

$counters = ['pendiente' => 0, 'vencido' => 0, 'pagado' => 0];
$total = 0.0;
foreach ($charges as $charge) {
    $counters[$charge['estado']] = ($counters[$charge['estado']] ?? 0) + 1;
    $total += (float) $charge['importe'];
}

$monthNames = [1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
    7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'];
$periodLabel = static function (string $periodo) use ($monthNames): string {
    [$year, $month] = explode('-', $periodo);
    return ucfirst($monthNames[(int) $month]) . ' ' . $year;
};
$dateLabel = static fn (mixed $date): string => is_string($date) && $date !== '' && strtotime($date) !== false
    ? date('d/m/Y', strtotime($date)) : 'Sin fecha';
$money = static fn (mixed $value): string => number_format((float) $value, 2, '.', ',');

$formPeriod = is_string($old['periodo'] ?? null) && preg_match($periodPattern, $old['periodo']) === 1 ? $old['periodo'] : $currentPeriod;
$formDue = is_string($old['fecha_vencimiento'] ?? null) && $old['fecha_vencimiento'] !== '' ? $old['fecha_vencimiento'] : $suggestedDue;

tc_shell_open([
    'title' => 'Cargos mensuales',
    'role' => 'administrador',
    'active' => 'cargos',
    'csrf' => $csrfToken,
    'breadcrumb' => [['Cargos mensuales', null]],
]);
?>
        <section class="tc-page-head">
            <div>
                <span class="tc-eyebrow">ADMINISTRACIÓN</span>
                <h1>Cargos mensuales</h1>
                <p>Cada mes se cobra la mensualidad de los alumnos activos. Tú eliges el periodo y la fecha de vencimiento al crear el cargo.</p>
            </div>
        </section>

        <?php if ($error !== null): ?>
            <p class="auth-error admin-message" role="alert"><?= tc_e($error) ?></p>
        <?php endif; ?>
        <?php if ($loadError !== null): ?>
            <p class="auth-error admin-message" role="alert"><?= tc_e($loadError) ?></p>
        <?php endif; ?>
        <?php if (is_array($flash) && isset($flash['message'])): ?>
            <p class="auth-success admin-message" role="status"><?= tc_e($flash['message']) ?></p>
            <?php if (!empty($flash['omitidos'])): ?>
                <section class="tc-panel">
                    <div class="tc-panel-head">
                        <div>
                            <span class="tc-eyebrow">REVISAR</span>
                            <h2>Alumnos omitidos</h2>
                        </div>
                    </div>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead><tr><th>Alumno</th><th>Motivo</th></tr></thead>
                            <tbody>
                                <?php foreach ($flash['omitidos'] as $skipped): ?>
                                    <tr>
                                        <td data-label="Alumno"><?= tc_e($skipped['nombre']) ?> (#<?= (int) $skipped['id_alumno'] ?>)</td>
                                        <td data-label="Motivo"><?= tc_e($skipped['motivo']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($missing > 0): ?>
            <div class="tc-banner" role="status">
                <div>
                    <strong><?= number_format($missing) ?> alumno(s) activo(s) todavía no tienen cargo de <?= tc_e($periodLabel($currentPeriod)) ?></strong>
                    <small>Genera los cargos del mes para que aparezcan en la caja de secretaría.</small>
                </div>
                <a class="tc-btn tc-btn-small" href="#lote"><i class="fa-solid fa-calendar-plus" aria-hidden="true"></i> Generar ahora</a>
            </div>
        <?php endif; ?>

        <section class="tc-grid">
            <div class="tc-panel" id="lote">
                <div class="tc-panel-head">
                    <div>
                        <span class="tc-eyebrow">TODOS LOS ALUMNOS ACTIVOS</span>
                        <h2>Generar cargos del mes</h2>
                    </div>
                </div>
                <form method="post" action="generar_cargos.php" class="tc-form-grid" data-due-sync data-default-day="<?= tc_e($defaultDay ?? 10) ?>">
                    <input type="hidden" name="csrf_token" value="<?= tc_e($csrfToken) ?>">
                    <input type="hidden" name="action" value="generar_lote">
                    <div class="tc-field">
                        <label for="lote_periodo">Periodo a cobrar</label>
                        <input type="month" id="lote_periodo" name="periodo" required pattern="[0-9]{4}-[0-9]{2}" placeholder="AAAA-MM"
                               value="<?= tc_e($formPeriod) ?>">
                    </div>
                    <div class="tc-field">
                        <label for="lote_vencimiento">Fecha de vencimiento</label>
                        <input type="date" id="lote_vencimiento" name="fecha_vencimiento" required
                               min="<?= tc_e($formPeriod) ?>-01" value="<?= tc_e($formDue) ?>">
                    </div>
                    <p class="tc-field-hint tc-wide">
                        Se crea una mensualidad por alumno activo según la tarifa de su categoría. Si un alumno ya tiene cargo de ese periodo, se omite (nunca se duplica).
                    </p>
                    <label class="tc-check tc-wide">
                        <input type="checkbox" name="guardar_dia" value="1">
                        Usar este día del mes como predeterminado<?= $defaultDay !== null ? ' (ahora: día ' . (int) $defaultDay . ')' : '' ?>
                    </label>
                    <div class="tc-wide">
                        <button type="submit" class="tc-btn"><i class="fa-solid fa-calendar-plus" aria-hidden="true"></i> Generar cargos</button>
                    </div>
                </form>
            </div>

            <div class="tc-panel" id="individual">
                <div class="tc-panel-head">
                    <div>
                        <span class="tc-eyebrow">UN SOLO ALUMNO</span>
                        <h2>Crear cargo individual</h2>
                    </div>
                </div>
                <form method="post" action="generar_cargos.php" class="tc-form-grid" data-due-sync data-default-day="<?= tc_e($defaultDay ?? 10) ?>">
                    <input type="hidden" name="csrf_token" value="<?= tc_e($csrfToken) ?>">
                    <input type="hidden" name="action" value="crear_individual">
                    <div class="tc-field tc-wide">
                        <label for="ind_alumno">Alumno</label>
                        <select id="ind_alumno" name="id_alumno" required>
                            <option value="">Selecciona un alumno</option>
                            <?php foreach ($students as $student): ?>
                                <option value="<?= (int) $student['id_alumno'] ?>"
                                    <?= (string) ($old['id_alumno'] ?? '') === (string) $student['id_alumno'] ? 'selected' : '' ?>>
                                    <?= tc_e($student['apellidos'] . ', ' . $student['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="tc-field">
                        <label for="ind_periodo">Periodo</label>
                        <input type="month" id="ind_periodo" name="periodo" required pattern="[0-9]{4}-[0-9]{2}" placeholder="AAAA-MM"
                               value="<?= tc_e($formPeriod) ?>">
                    </div>
                    <div class="tc-field">
                        <label for="ind_vencimiento">Fecha de vencimiento</label>
                        <input type="date" id="ind_vencimiento" name="fecha_vencimiento" required
                               min="<?= tc_e($formPeriod) ?>-01" value="<?= tc_e($formDue) ?>">
                    </div>
                    <div class="tc-field tc-wide">
                        <label for="ind_concepto">Concepto</label>
                        <select id="ind_concepto" name="concepto">
                            <?php foreach (['mensualidad' => 'Mensualidad', 'clase' => 'Clase', 'clase_individual' => 'Clase individual', 'otro' => 'Otro'] as $key => $label): ?>
                                <option value="<?= $key ?>" <?= (string) ($old['concepto'] ?? 'mensualidad') === $key ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="tc-field tc-wide">
                        <label for="ind_importe">Importe (obligatorio si no es mensualidad)</label>
                        <input type="number" id="ind_importe" name="importe" min="1" step="0.01" inputmode="decimal"
                               placeholder="Vacío = tarifa de su categoría"
                               value="<?= tc_e($old['importe'] ?? '') ?>">
                        <p class="tc-field-hint">Escríbelo para clases individuales, descuentos o cualquier monto distinto a la mensualidad.</p>
                    </div>
                    <div class="tc-wide">
                        <button type="submit" class="tc-btn"><i class="fa-solid fa-plus" aria-hidden="true"></i> Crear cargo</button>
                    </div>
                </form>
            </div>
        </section>

        <section class="tc-panel" id="cargos">
            <div class="tc-panel-head">
                <div>
                    <span class="tc-eyebrow">SEGUIMIENTO</span>
                    <h2>Cargos de <?= tc_e($periodLabel($viewPeriod)) ?></h2>
                </div>
                <form method="get" action="generar_cargos.php" class="tc-toolbar">
                    <div class="tc-field">
                        <label for="ver_periodo">Ver periodo</label>
                        <select id="ver_periodo" name="periodo">
                            <?php foreach ($periods as $period): ?>
                                <option value="<?= tc_e($period) ?>" <?= $period === $viewPeriod ? 'selected' : '' ?>><?= tc_e($periodLabel($period)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="tc-btn tc-btn-ghost tc-btn-small">Ver</button>
                </form>
            </div>

            <div class="tc-counters">
                <div class="tc-counter"><span class="tc-dot tc-dot-all"></span><strong><?= number_format($counters['pendiente']) ?></strong><span>Pendientes</span></div>
                <div class="tc-counter"><span class="tc-dot tc-dot-off"></span><strong><?= number_format($counters['vencido']) ?></strong><span>Vencidos</span></div>
                <div class="tc-counter"><span class="tc-dot tc-dot-ok"></span><strong><?= number_format($counters['pagado']) ?></strong><span>Pagados</span></div>
            </div>

            <?php if ($charges === []): ?>
                <div class="tc-empty" style="margin-top:16px">
                    <i class="fa-regular fa-circle-check" aria-hidden="true"></i>
                    <span>Todavía no hay cargos para este periodo.</span>
                </div>
            <?php else: ?>
                <div class="admin-table-wrap" style="margin-top:16px">
                    <table class="admin-table">
                        <thead>
                            <tr><th>Alumno</th><th>Concepto</th><th>Importe</th><th>Vence</th><th>Estado</th><th>Cambiar importe</th><th>Cambiar vencimiento</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($charges as $charge): ?>
                                <tr>
                                    <td data-label="Alumno"><?= tc_e($charge['alumno']) ?></td>
                                    <td data-label="Concepto"><?= tc_e(ucfirst(str_replace('_', ' ', (string) ($charge['concepto'] ?? 'mensualidad')))) ?> <small>(<?= tc_e((string) $charge['categoria']) ?>)</small></td>
                                    <td data-label="Importe">$<?= tc_e($money($charge['importe'])) ?></td>
                                    <td data-label="Vence"><?= tc_e($dateLabel($charge['fecha_vencimiento'])) ?></td>
                                    <td data-label="Estado"><span class="tc-badge tc-badge-<?= tc_e($charge['estado']) ?>"><?= tc_e(ucfirst((string) $charge['estado'])) ?></span></td>
                                    <td data-label="Cambiar importe">
                                        <?php if ($charge['estado'] === 'pagado'): ?>
                                            —
                                        <?php else: ?>
                                            <form method="post" action="generar_cargos.php" class="tc-inline-form">
                                                <input type="hidden" name="csrf_token" value="<?= tc_e($csrfToken) ?>">
                                                <input type="hidden" name="action" value="cambiar_importe">
                                                <input type="hidden" name="periodo" value="<?= tc_e($viewPeriod) ?>">
                                                <input type="hidden" name="id_cargo" value="<?= (int) $charge['id_cargo'] ?>">
                                                <input type="number" name="importe" required min="1" step="0.01" inputmode="decimal"
                                                       value="<?= tc_e(number_format((float) $charge['importe'], 2, '.', '')) ?>"
                                                       aria-label="Nuevo importe para <?= tc_e($charge['alumno']) ?>" style="max-width:110px">
                                                <button type="submit" class="tc-btn tc-btn-ghost tc-btn-small">Guardar</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Cambiar vencimiento">
                                        <?php if ($charge['estado'] === 'pagado'): ?>
                                            —
                                        <?php else: ?>
                                            <form method="post" action="generar_cargos.php" class="tc-inline-form">
                                                <input type="hidden" name="csrf_token" value="<?= tc_e($csrfToken) ?>">
                                                <input type="hidden" name="action" value="cambiar_vencimiento">
                                                <input type="hidden" name="periodo" value="<?= tc_e($viewPeriod) ?>">
                                                <input type="hidden" name="id_cargo" value="<?= (int) $charge['id_cargo'] ?>">
                                                <input type="date" name="fecha_vencimiento" required min="<?= tc_e($viewPeriod) ?>-01"
                                                       value="<?= tc_e((string) ($charge['fecha_vencimiento'] ?? '')) ?>"
                                                       aria-label="Nueva fecha de vencimiento para <?= tc_e($charge['alumno']) ?>">
                                                <button type="submit" class="tc-btn tc-btn-ghost tc-btn-small">Guardar</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="tc-table-note"><?= number_format(count($charges)) ?> cargo(s) · total $<?= tc_e($money($total)) ?></p>
            <?php endif; ?>
        </section>
<?php
tc_shell_close(['js/cargos.js']);
