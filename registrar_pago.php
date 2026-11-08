<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth_check.php';
require_role('administrador');

header('Cache-Control: no-store');

$error = null;
$success = $_SESSION['flash_pago'] ?? null;
unset($_SESSION['flash_pago']);
$csrfToken = $_SESSION['csrf_token'] ?? '';
$paymentMethods = ['Efectivo', 'Transferencia', 'Tarjeta'];

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
    $concept = $_POST['concepto'] ?? '';
    $amount = $_POST['monto'] ?? '';
    $method = $_POST['metodo_pago'] ?? '';

    if (!is_string($providedToken) || !is_string($csrfToken) || $csrfToken === ''
        || !hash_equals($csrfToken, $providedToken)) {
        http_response_code(403);
        $error = 'La solicitud venció. Recarga la página e inténtalo de nuevo.';
    } elseif ($studentId === false || $studentId < 1) {
        $error = 'Selecciona un alumno válido.';
    } elseif (!is_string($concept) || trim($concept) === '' || mb_strlen(trim($concept), 'UTF-8') > 100) {
        $error = 'Escribe un concepto de hasta 100 caracteres.';
    } elseif (!is_string($amount) || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $amount)
        || (float) $amount <= 0) {
        $error = 'Escribe un monto mayor que cero, con hasta 8 enteros y 2 decimales.';
    } elseif (!is_string($method) || !in_array($method, $paymentMethods, true)) {
        $error = 'Selecciona un método de pago válido.';
    } else {
        try {
            $pdo->beginTransaction();
            $student = $pdo->prepare(
                'SELECT id_alumno
                 FROM escuela_alumnos
                 WHERE id_alumno = :id_alumno AND estatus = 1
                 FOR UPDATE'
            );
            $student->execute(['id_alumno' => $studentId]);

            if ($student->fetchColumn() === false) {
                $pdo->rollBack();
                $error = 'El alumno seleccionado no existe o está inactivo.';
            } else {
                $payment = $pdo->prepare(
                    'INSERT INTO escuela_pagos
                        (id_alumno, concepto, monto, fecha_pago, metodo_pago)
                     VALUES
                        (:id_alumno, :concepto, :monto, CURRENT_DATE, :metodo_pago)'
                );
                $payment->execute([
                    'id_alumno' => $studentId,
                    'concepto' => trim($concept),
                    'monto' => $amount,
                    'metodo_pago' => $method,
                ]);
                $pdo->commit();
                $_SESSION['flash_pago'] = 'El pago se registró correctamente.';
                header('Location: registrar_pago.php');
                exit;
            }
        } catch (\PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Payment registration failed: ' . $exception->getMessage());
            http_response_code(503);
            $error = 'No se pudo registrar el pago. Verifica los datos y la estructura de la tabla.';
        }
    }
}

try {
    $students = $pdo->query(
        'SELECT id_alumno, nombre, apellidos
         FROM escuela_alumnos
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
        <a class="auth-home-link" href="dashboard_admin.php">Volver al panel</a>
        <h1>Registrar pago</h1>
        <p>Registra un cobro asociado a un alumno activo.</p>

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

                <label for="concepto">Concepto</label>
                <input id="concepto" name="concepto" type="text" maxlength="100" required
                    placeholder="Mensualidad, examen de cinta..."
                    value="<?= $escape($_POST['concepto'] ?? '') ?>">

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
