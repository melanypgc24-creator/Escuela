<?php
declare(strict_types=1);
$e = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
$logo = base64_encode((string) file_get_contents(__DIR__ . '/../imagenes/Logo-comprobante.jpg'));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Comprobante <?= $e($receipt['folio']) ?></title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #242424; background: #f3f3f3; font-size: 13px; }
        .receipt { max-width: 720px; margin: 20px auto; padding: 28px; background: white; border: 1px solid #ddd; }
        .brand { text-align: center; border-bottom: 2px solid #a91620; padding-bottom: 12px; }
        .brand img { max-width: 110px; max-height: 100px; }
        h1 { font-size: 21px; } h2 { font-size: 16px; }
        table { width: 100%; border-collapse: collapse; } th, td { padding: 7px; text-align: left; border-bottom: 1px solid #ddd; }
        .cancelled { color: #a00000; font-weight: bold; font-size: 20px; }
        .actions { margin: 18px auto; max-width: 720px; } .actions a, .actions button { padding: 8px; }
        .actions .action-back { display: inline-block; padding: 8px 14px; border: 1px solid #aeb82a; border-radius: 6px; background: #d3dd3e; color: #252a34; font-weight: bold; text-decoration: none; }
        .actions .action-back:hover { border-color: #7d8810; background: #fff; color: #566008; }
        .actions .action-back:focus-visible { outline: 2px solid #7d8810; outline-offset: 2px; }
        @media print { body { background: white; } .actions { display: none; } .receipt { border: 0; margin: 0; } }
    </style>
</head>
<body>
<?php if (!$isPdf): ?>
    <nav class="actions">
        <a class="action-back" href="javascript:history.back()">&#8592; Volver</a>
        <button type="button" onclick="window.print()">Imprimir</button>
        <a href="comprobante.php?id=<?= (int) $id ?>&amp;formato=pdf">Descargar PDF</a>
    </nav>
<?php endif; ?>
<main class="receipt">
    <div class="brand"><img src="data:image/jpeg;base64,<?= $logo ?>" alt="Team Cats"><h1>Team Cats · Escuela de Jiu-Jitsu</h1><h2>Comprobante de pago</h2></div>
    <?php if (($receipt['estado'] ?? '') === 'cancelado'): ?>
        <p class="cancelled">CANCELADO</p>
        <?php if (!empty($receipt['motivo'])): ?><p>Motivo: <?= $e($receipt['motivo']) ?></p><?php endif; ?>
    <?php endif; ?>
    <?php if (!empty($receipt['reconstruido'])): ?><p>Comprobante histórico reconstruido con los datos disponibles actualmente.</p><?php endif; ?>
    <table>
        <tr><th>Folio</th><td><?= $e($receipt['folio']) ?></td></tr>
        <tr><th>Fecha</th><td><?= $e($receipt['fecha_pago']) ?></td></tr>
        <tr><th>Alumno</th><td><?= $e($receipt['alumno'] ?: 'No disponible') ?></td></tr>
        <tr><th>Matrícula</th><td><?= $e($receipt['matricula'] ?: 'No disponible') ?></td></tr>
        <tr><th>Pagador</th><td><?= $e($receipt['pagador'] ?: 'No disponible') ?></td></tr>
        <tr><th>Concepto</th><td><?= $e($receipt['concepto']) ?></td></tr>
        <tr><th>Importe</th><td>$<?= $e(number_format((float) $receipt['monto'], 2)) ?> MXN</td></tr>
        <tr><th>Método</th><td><?= $e($receipt['metodo']) ?></td></tr>
        <?php if (!empty($receipt['referencia'])): ?><tr><th>Referencia</th><td><?= $e($receipt['referencia']) ?></td></tr><?php endif; ?>
        <?php if (!empty($receipt['tarjeta_ultimos4'])): ?><tr><th>Tarjeta</th><td>**** <?= $e($receipt['tarjeta_ultimos4']) ?></td></tr><?php endif; ?>
        <tr><th>Registrado por</th><td><?= $e($receipt['responsable'] ?: 'No disponible') ?></td></tr>
        <tr><th>Estatus</th><td><?= $e(ucfirst((string) $receipt['estado'])) ?></td></tr>
    </table>
    <?php if ($receipt['cargos'] !== []): ?>
        <h2>Cargos cubiertos</h2>
        <table><thead><tr><th>Periodo</th><th>Concepto</th><th>Aplicado</th></tr></thead><tbody>
        <?php foreach ($receipt['cargos'] as $charge): ?><tr><td><?= $e($charge['periodo']) ?></td><td><?= $e($charge['concepto']) ?></td><td>$<?= $e(number_format((float) $charge['importe_aplicado'], 2)) ?></td></tr><?php endforeach; ?>
        </tbody></table>
    <?php endif; ?>
    <p>Comprobante interno. No es un comprobante fiscal CFDI.</p>
</main>
<?php if (!$isPdf && $autoPrint): ?><script>window.addEventListener('load', function () { window.print(); });</script><?php endif; ?>
</body>
</html>
