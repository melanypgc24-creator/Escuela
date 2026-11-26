<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth_check.php';
require_permission('classes.manage');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: clases.php');
    exit;
}

require_valid_csrf();

$pdo = require dirname(__DIR__, 2) . '/config/conexion.php';
$conexion = $pdo;

$rawId = $_POST['id_clase'] ?? null;
$idClase = ($rawId === '' || $rawId === null)
    ? false
    : filter_var($rawId, FILTER_VALIDATE_INT);

if ($idClase === false || $idClase < 0) {
    header('Location: clases.php?error=id_invalido');
    exit;
}

try {
    $conexion->beginTransaction();

    $checkStmt = $conexion->prepare('SELECT id_clase FROM clases WHERE id_clase = :id_clase LIMIT 1');
    $checkStmt->execute(['id_clase' => $idClase]);
    if ($checkStmt->fetchColumn() === false) {
        $conexion->rollBack();
        header('Location: clases.php?error=no_encontrada');
        exit;
    }

    $attStmt = $conexion->prepare('SELECT COUNT(*) FROM asistencias WHERE id_clase = :id_clase');
    $attStmt->execute(['id_clase' => $idClase]);
    if ((int) $attStmt->fetchColumn() > 0) {
        $conexion->rollBack();
        header('Location: clases.php?error=tiene_asistencias');
        exit;
    }

    $insStmt = $conexion->prepare(
        'SELECT (SELECT COUNT(*) FROM inscripciones_clases WHERE id_clase = :a) + (SELECT COUNT(*) FROM clases_alumnos WHERE id_clase = :b)'
    );
    $insStmt->execute(['a' => $idClase, 'b' => $idClase]);
    if ((int) $insStmt->fetchColumn() > 0) {
        $conexion->rollBack();
        header('Location: clases.php?error=tiene_inscripciones');
        exit;
    }

    $deleteStmt = $conexion->prepare('DELETE FROM clases WHERE id_clase = :id_clase');
    $deleteStmt->execute(['id_clase' => $idClase]);

    try {
        if (class_exists('\App\Models\AuditModel')) {
            (new \App\Models\AuditModel($conexion))->record(
                (int) $_SESSION['id_usuario'],
                'eliminar',
                'clases',
                $idClase,
                []
            );
        }
    } catch (\Throwable $auditException) {
        error_log('Auditoría omitida en eliminar clase: ' . $auditException->getMessage());
    }

    $conexion->commit();
    header('Location: clases.php?eliminada=1');
    exit;
} catch (\PDOException $exception) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }
    error_log('Error SQL al eliminar clase: ' . $exception->getMessage());
    if ((string) ($exception->errorInfo[1] ?? '') === '1451') {
        header('Location: clases.php?error=tiene_relaciones');
        exit;
    }
    header('Location: clases.php?error=eliminar');
    exit;
} catch (\Throwable $exception) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }
    error_log('Error al eliminar clase: ' . $exception->getMessage());
    header('Location: clases.php?error=eliminar');
    exit;
}