<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth_check.php';
require_permission('classes.manage');

$pdo = require dirname(__DIR__, 2) . '/config/conexion.php';

$error = null;
$input = $_POST;
$fromPanel = ($_SESSION['rol'] ?? '') === 'director' && ($_GET['origen'] ?? '') === 'panel';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_valid_csrf();

    $nombreClase = trim((string) ($input['nombre_clase'] ?? ''));
    $categoria = (string) ($input['categoria'] ?? '');
    $rawProf     = $input['id_profesor'] ?? null;
    $idProfesor  = ($rawProf === '' || $rawProf === null)
        ? false
        : filter_var($rawProf, FILTER_VALIDATE_INT);
    $dias        = trim((string) ($input['dias'] ?? ''));
    $horaInicio  = trim((string) ($input['hora_inicio'] ?? ''));
    $horaFin     = trim((string) ($input['hora_fin'] ?? ''));

    $inicioValida = preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/D', $horaInicio, $mInicio) === 1;
    $finValida    = preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/D', $horaFin, $mFin) === 1;

    $inicioHm = $inicioValida ? ($mInicio[1] . ':' . $mInicio[2]) : '';
    $finHm    = $finValida ? ($mFin[1] . ':' . $mFin[2]) : '';

    $capacidad = filter_var($input['capacidad'] ?? null, FILTER_VALIDATE_INT);
    $costoRaw = str_replace([',', '$', ' '], '', trim((string) ($input['costo'] ?? '')));
    $costo = $costoRaw === '' ? false : filter_var($costoRaw, FILTER_VALIDATE_FLOAT);

    if (
        $nombreClase === '' || mb_strlen($nombreClase, 'UTF-8') > 100
        || !in_array($categoria, ['infantil', 'adulta'], true)
        || $idProfesor === false || $idProfesor < 0
        || $dias === '' || mb_strlen($dias, 'UTF-8') > 50
        || !$inicioValida
        || !$finValida || $finHm <= $inicioHm
        || $capacidad === false || $capacidad === null || $capacidad < 1
        || $costo === false || $costo < 0 || $costo > 99999999
    ) {
        $error = 'Revisa el nombre, categoría, profesor, días, horario, capacidad y costo de la clase.';
    } else {
        try {
            $pdo->beginTransaction();

            // Verifica que el profesor exista en la tabla profesor
            $checkProf = $pdo->prepare('SELECT 1 FROM profesor WHERE id_profesor = :id_profesor LIMIT 1');
            $checkProf->execute(['id_profesor' => $idProfesor]);
            if ($checkProf->fetchColumn() === false) {
                throw new \RuntimeException('El profesor seleccionado no existe.');
            }

            // Calcula el siguiente id_clase en caso de que la columna no tenga AUTO_INCREMENT
            $nextId = (int) $pdo->query('SELECT COALESCE(MAX(id_clase), 0) + 1 FROM clases')->fetchColumn();

            $stmt = $pdo->prepare(
                'INSERT INTO clases (id_clase, nombre_clase, categoria, id_profesor, dias, hora_inicio, hora_fin, capacidad, costo)
                 VALUES (:id_clase, :nombre_clase, :categoria, :id_profesor, :dias, :hora_inicio, :hora_fin, :capacidad, :costo)'
            );
            $stmt->execute([
                'id_clase'     => $nextId,
                'nombre_clase' => $nombreClase,
                'categoria'    => $categoria,
                'id_profesor'  => $idProfesor,
                'dias'         => $dias,
                'hora_inicio'  => $inicioHm,
                'hora_fin'     => $finHm,
                'capacidad'    => $capacidad,
                'costo'        => number_format((float) $costo, 2, '.', ''),
            ]);

            try {
                if (class_exists('\App\Models\AuditModel')) {
                    (new \App\Models\AuditModel($pdo))->record(
                        (int) $_SESSION['id_usuario'],
                        'crear',
                        'clases',
                        $nextId,
                        ['id_profesor' => $idProfesor, 'capacidad' => $capacidad]
                    );
                }
            } catch (\Throwable $auditException) {
                error_log('Auditoría omitida (tabla pendiente): ' . $auditException->getMessage());
            }

            $pdo->commit();
            header('Location: clases.php');
            exit;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Error al crear clase: ' . $exception->getMessage());
            $error = 'No se pudo guardar la clase. Verifica los datos y la relación con el profesor.';
        }
    }
}

$profesores = [];
try {
    $profesores = $pdo
        ->query('SELECT id_profesor, nombre, apellidos FROM profesor ORDER BY apellidos, nombre')
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $exception) {
    error_log('Error al cargar profesores: ' . $exception->getMessage());
    $error = $error ?? 'No se pudieron cargar los profesores.';
}

$escape = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
$cssFile = dirname(__DIR__, 2) . '/css/estilos.css';
$cssVer  = is_file($cssFile) ? (int) filemtime($cssFile) : 1;
$csrfToken = $escape(csrf_token());
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Crear clase | Team Cats</title>
    <link rel="stylesheet" href="/Escuela/css/estilos.css?v=<?= $cssVer ?>">
</head>
<body class="role-dashboard-page">
    <header class="role-dashboard-header">
        <div>
            <span class="admin-eyebrow">TEAM CATS · CLASES</span>
            <h1>Crear clase</h1>
            <p>Registra una nueva clase, asigna instructor, horario, capacidad y costo.</p>
        </div>
        <div class="admin-account">
            <span><?= $escape($_SESSION['correo'] ?? '') ?></span>
            <form method="post" action="/Escuela/logout.php">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <button type="submit" class="admin-logout">Cerrar sesión</button>
            </form>
        </div>
    </header>

    <main class="role-dashboard-content">
        <section class="role-dashboard-section">
            <div class="role-dashboard-section-heading">
                <h2>Datos de la clase</h2>
                <a class="admin-cancel" href="<?= $fromPanel ? '../../dashboard_director.php' : 'clases.php' ?>"><?= $fromPanel ? 'Volver al panel' : 'Volver a clases' ?></a>
            </div>
            <div class="role-dashboard-section-body">
                <?php if ($error !== null): ?>
                    <p class="auth-error admin-message" role="alert"><?= $escape($error) ?></p>
                <?php endif; ?>

                <form method="post" action="" class="class-create-form">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                    <label for="nombre_clase">Nombre de la clase</label>
                    <input id="nombre_clase" name="nombre_clase" type="text" maxlength="100" required value="<?= $escape($input['nombre_clase'] ?? '') ?>">

                    <label for="categoria">Categoría</label>
                    <select id="categoria" name="categoria" required>
                        <option value="">Selecciona una categoría</option>
                        <option value="infantil" <?= ($input['categoria'] ?? '') === 'infantil' ? 'selected' : '' ?>>Infantil (menores de 18 años)</option>
                        <option value="adulta" <?= ($input['categoria'] ?? '') === 'adulta' ? 'selected' : '' ?>>Adulta (18 años o más)</option>
                    </select>

                    <label for="id_profesor">Profesor</label>
                    <select id="id_profesor" name="id_profesor" required>
                        <option value="">Selecciona un profesor</option>
                        <?php foreach ($profesores as $profesor): ?>
                            <option value="<?= $escape($profesor['id_profesor']) ?>" <?= (isset($input['id_profesor']) && $input['id_profesor'] !== '' && (string) $input['id_profesor'] === (string) $profesor['id_profesor']) ? 'selected' : '' ?>>
                                <?= $escape($profesor['nombre'] . ' ' . $profesor['apellidos']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="dias">Días</label>
                    <input id="dias" name="dias" type="text" maxlength="50" required placeholder="Ej. Lunes y Miércoles" value="<?= $escape($input['dias'] ?? '') ?>">

                    <label for="hora_inicio">Hora de inicio</label>
                    <input id="hora_inicio" name="hora_inicio" type="time" required value="<?= $escape($input['hora_inicio'] ?? '') ?>">

                    <label for="hora_fin">Hora de fin</label>
                    <input id="hora_fin" name="hora_fin" type="time" required value="<?= $escape($input['hora_fin'] ?? '') ?>">

                    <label for="capacidad">Capacidad</label>
                    <input id="capacidad" name="capacidad" type="number" min="1" required value="<?= $escape($input['capacidad'] ?? 20) ?>">

                    <label for="costo">Costo de la clase</label>
                    <input id="costo" name="costo" type="number" min="0" step="0.01" inputmode="decimal" required placeholder="Ej. 250 (0 = se cobra la mensualidad)" value="<?= $escape($input['costo'] ?? '') ?>">

                    <div class="role-dashboard-actions">
                        <button type="submit" class="admin-save">Guardar clase</button>
                    </div>
                </form>
            </div>
        </section>
    </main>
</body>
</html>