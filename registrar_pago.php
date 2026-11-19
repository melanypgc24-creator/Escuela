<?php
declare(strict_types=1);

use App\Models\PaymentModel;

require __DIR__ . '/includes/auth_check.php';
require_permission('payments.create');

header('Cache-Control: no-store');

$error = null;
$success = $_SESSION['flash_pago'] ?? null;
unset($_SESSION['flash_pago']);
$csrfToken = $_SESSION['csrf_token'] ?? '';
$paymentMethods = ($_SESSION['rol'] ?? null) === 'recepcionista'
    ? ['Efectivo', 'Tarjeta']
    : ['Efectivo', 'Transferencia', 'Tarjeta'];

try {
    $pdo = require __DIR__ . '/config/conexion.php';
} catch (\PDOException $exception) {
    error_log('Payment connection failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('No se pudo conectar con la base de datos.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $providedToken = $_POST['csrf_token'] ?? '';
    $studentId = filter_var($_POST['id_alumno'] ?? null, FILTER_VALIDATE_INT);
    $amount = $_POST['monto'] ?? '';
    $method = $_POST['metodo_pago'] ?? '';
    $payerName = $_POST['nombre_pagador'] ?? '';

    if (!is_string($providedToken) || !is_string($csrfToken) || $csrfToken === ''
        || !hash_equals($csrfToken, $providedToken)) {
        http_response_code(403);
        $error = 'La solicitud venció. Recarga la página e inténtalo de nuevo.';
    } elseif ($studentId === false || $studentId < 0) {
        $error = 'Selecciona un alumno válido.';
    } elseif (!is_string($amount) || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $amount)
        || (float) $amount <= 0) {
        $error = 'Escribe un monto mayor que cero, con hasta 8 enteros y 2 decimales.';
    } elseif (!is_string($method) || !in_array($method, $paymentMethods, true)) {
        $error = 'Selecciona un método de pago válido.';
    } elseif (!is_string($payerName) || trim($payerName) === '' || mb_strlen(trim($payerName), 'UTF-8') > 200) {
        $error = 'Escribe el nombre de quien realiza el pago.';
    } else {
        try {
            $paymentId = (new PaymentModel($pdo))->create([
                'id_alumno' => $studentId,
                'monto' => $amount,
                'fecha_pago' => date('Y-m-d'),
                'metodo_pago' => strtolower($method),
                'nombre_pagador' => trim($payerName),
                'id_alumno_pagador' => null,
                'id_usuario_registra' => (int) $_SESSION['id_usuario'],
            ]);
            $_SESSION['flash_pago'] = 'El pago se registró correctamente. Referencia: ' . $paymentId . '.';
            header('Location: registrar_pago.php');
            exit;
        } catch (\DomainException $exception) {
            http_response_code(422);
            $error = $exception->getMessage();
        } catch (\PDOException $exception) {
            error_log('Payment registration failed: ' . $exception->getMessage());
            http_response_code(503);
            $error = 'No se pudo registrar el pago. Verifica que las migraciones del esquema español estén aplicadas.';
        } catch (\App\Core\MigrationException $exception) {
            error_log('Payment registration lock error: ' . $exception->getMessage());
            http_response_code(503);
            $error = 'No se pudo registrar el pago en este momento. Inténtalo de nuevo.';
        } catch (\Throwable $exception) {
            error_log('Payment registration failed: ' . $exception->getMessage());
            http_response_code(500);
            $error = 'No se pudo registrar el pago.';
        }
    }
}

try {
    $students = $pdo->query(
        'SELECT id_alumno, nombre, apellidos
         FROM alumnos
         WHERE estatus = 1
         ORDER BY apellidos, nombre'
    )->fetchAll();
} catch (\PDOException $exception) {
    error_log('Active students query failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('No se pudo cargar la lista de alumnos.');
}

$escape = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Registrar pago | Team Cats</title>
    <link rel="stylesheet" href="css/estilos.css">
</head>
<body class="auth-page">
    <main class="auth-card payment-card">
        <a class="auth-home-link" href="<?= ($_SESSION['rol'] ?? '') === 'recepcionista' ? 'panel_recepcionista.php' : 'dashboard_admin.php' ?>">Volver al panel</a>
        <h1>Registrar pago</h1>
        <p>Registra un pago y aplícalo a los cargos pendientes más antiguos del alumno.</p>

        <?php if ($error !== null): ?>
            <p class="auth-error" role="alert"><?= $escape($error) ?></p>
        <?php elseif (is_string($success)): ?>
            <p class="auth-success" role="status"><?= $escape($success) ?></p>
        <?php endif; ?>

        <?php if ($students === []): ?>
            <p class="auth-error" role="status">No hay alumnos activos disponibles para registrar pagos.</p>
        <?php else: ?>
            <form method="post" action="registrar_pago.php">
                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">

                <label for="id_alumno">Alumno</label>
                <select id="id_alumno" name="id_alumno" required>
                    <option value="">Selecciona un alumno</option>
                    <?php foreach ($students as $student): ?>
                        <option value="<?= $escape($student['id_alumno']) ?>"
                            <?= (string) ($_POST['id_alumno'] ?? '') === (string) $student['id_alumno'] ? 'selected' : '' ?>>
                            <?= $escape($student['nombre'] . ' ' . $student['apellidos']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="nombre_pagador">Nombre de quien realiza el pago</label>
                <input id="nombre_pagador" name="nombre_pagador" type="text" maxlength="200" required
                    value="<?= $escape($_POST['nombre_pagador'] ?? '') ?>">

                <label for="monto">Monto</label>
                <input id="monto" name="monto" type="number" min="0.01" max="99999999.99" step="0.01" required
                    value="<?= $escape($_POST['monto'] ?? '') ?>">

                <label for="metodo_pago">Método de pago</label>
                <select id="metodo_pago" name="metodo_pago" required>
                    <option value="">Selecciona un método</option>
                    <?php foreach ($paymentMethods as $paymentMethod): ?>
                        <option value="<?= $escape($paymentMethod) ?>"
                            <?= ($_POST['metodo_pago'] ?? '') === $paymentMethod ? 'selected' : '' ?>>
                            <?= $escape($paymentMethod) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit">Guardar pago</button>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>
