<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth_check.php';
$role = current_authenticated_role();
$canReadAll = \App\Core\Permissions::allows($role, 'payments.read');
$canReadOwn = \App\Core\Permissions::allows($role, 'payments.read_own');
if (!$canReadAll && !$canReadOwn) {
    http_response_code(403);
    exit('No tienes permiso para consultar comprobantes.');
}
$studentId = $canReadAll ? null : ($_SESSION['id_alumno'] ?? null);
if (!$canReadAll && !is_int($studentId)) {
    http_response_code(403);
    exit('La cuenta no está vinculada a un alumno.');
}
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$format = $_GET['formato'] ?? 'html';
if ($id === false || $id === null || $id < 1 || !in_array($format, ['html', 'pdf'], true)) {
    http_response_code(400);
    exit('Solicitud de comprobante inválida.');
}

header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
try {
    $pdo = require __DIR__ . '/config/conexion.php';
    $receipt = (new \App\Models\ReceiptModel($pdo))->find($id, $studentId);
    if ($receipt === null) {
        http_response_code(404);
        exit('Comprobante no encontrado.');
    }
    if (!is_string($receipt['folio'] ?? null) || $receipt['folio'] === '') {
        http_response_code(409);
        exit('Este pago histórico aún no tiene folio. Solicita su regularización.');
    }
    $isPdf = $format === 'pdf';
    $autoPrint = !$isPdf && ($_GET['imprimir'] ?? '') === '1';
    ob_start();
    require __DIR__ . '/includes/comprobante_html.php';
    $html = ob_get_clean();
    if (!$isPdf) {
        header('Content-Type: text/html; charset=UTF-8');
        echo $html;
        exit;
    }
    require __DIR__ . '/vendor/autoload.php';
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isPhpEnabled', false);
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('letter');
    $dompdf->render();
    $filename = 'Comprobante_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $receipt['folio']) . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $dompdf->output();
} catch (\Throwable $exception) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    error_log('Receipt failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('No se pudo generar el comprobante.');
}
