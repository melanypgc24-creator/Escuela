<?php
require_once '../../config/database.php';

// Procesar el formulario al enviar
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nombre = $_POST['nombre'];
    $apellidos = $_POST['apellidos'];
    $telefono = $_POST['telefono'];
    $fecha_nacimiento = $_POST['fecha_nacimiento'];
    $fecha_ingreso = $_POST['fecha_ingreso'];
    
    // Si no se selecciona cinturón, lo mandamos como NULL
    $id_cinturon = !empty($_POST['id_cinturon']) ? $_POST['id_cinturon'] : null;
    $estatus = $_POST['estatus'];

    $sql = "INSERT INTO alumnos (nombre, apellidos, telefono, fecha_nacimiento, fecha_ingreso, id_cinturon, estatus) 
            VALUES (:nombre, :apellidos, :telefono, :fecha_nacimiento, :fecha_ingreso, :id_cinturon, :estatus)";
    $stmt = $conexion->prepare($sql);
    
    $stmt->execute([
        ':nombre' => $nombre,
        ':apellidos' => $apellidos,
        ':telefono' => $telefono,
        ':fecha_nacimiento' => $fecha_nacimiento,
        ':fecha_ingreso' => $fecha_ingreso,
        ':id_cinturon' => $id_cinturon,
        ':estatus' => $estatus
    ]);
    
    echo "<b>¡Alumno guardado correctamente!</b> <a href='lista.php'>Volver a la lista</a><br><br>";
}

// Consultar los cinturones para llenar la lista desplegable
$sql_cinturones = "SELECT id_cinturon, color FROM cinturon";
$stmt_c = $conexion->prepare($sql_cinturones);
$stmt_c->execute();
$cinturones = $stmt_c->fetchAll(PDO::FETCH_ASSOC);
?>

<h1>Registrar Nuevo Alumno</h1>

<form method="POST" action="">
    <label>Nombre:</label><br>
    <input type="text" name="nombre" required><br><br>

    <label>Apellidos:</label><br>
    <input type="text" name="apellidos" required><br><br>

    <label>Teléfono:</label><br>
    <input type="tel" name="telefono"><br><br>

    <label>Fecha de Nacimiento:</label><br>
    <input type="date" name="fecha_nacimiento" required><br><br>

    <label>Fecha de Ingreso:</label><br>
    <!-- Se pone la fecha de hoy por defecto -->
    <input type="date" name="fecha_ingreso" value="<?php echo date('Y-m-d'); ?>" required><br><br>

    <label>Cinturón:</label><br>
    <select name="id_cinturon">
        <option value="">-- Seleccione un cinturón --</option>
        <?php foreach ($cinturones as $cinturon): ?>
            <option value="<?php echo $cinturon['id_cinturon']; ?>">
                <?php echo $cinturon['color']; ?>
            </option>
        <?php endforeach; ?>
    </select><br><br>

    <label>Estatus:</label><br>
    <select name="estatus">
        <option value="1" selected>Activo</option>
        <option value="0">Inactivo</option>
    </select><br><br>

    <button type="submit">Guardar Alumno</button>
</form>