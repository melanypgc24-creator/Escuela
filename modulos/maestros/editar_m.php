<?php
require_once '../../config/database.php';

if (!isset($_GET['id'])) {
    die("Error: No se proporcionó el ID del maestro.");
}
$id_profesor = $_GET['id'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nombre = $_POST['nombre'];
    $apellidos = $_POST['apellidos'];
    $telefono = $_POST['telefono'];

    $sql_update = "UPDATE profesor SET nombre = :nombre, apellidos = :apellidos, telefono = :telefono WHERE id_profesor = :id_profesor";
                   
    $stmt_update = $conexion->prepare($sql_update);
    $stmt_update->execute([
        ':nombre' => $nombre,
        ':apellidos' => $apellidos,
        ':telefono' => $telefono,
        ':id_profesor' => $id_profesor
    ]);
    
    echo "<b>¡Maestro actualizado correctamente!</b> <a href='maestros.php'>Volver a la lista</a><br><br>";
}

$sql_prof = "SELECT * FROM profesor WHERE id_profesor = :id_profesor";
$stmt_prof = $conexion->prepare($sql_prof);
$stmt_prof->execute([':id_profesor' => $id_profesor]);
$profesor = $stmt_prof->fetch(PDO::FETCH_ASSOC);

if (!$profesor) {
    die("Error: El maestro no existe.");
}

include '../../includes/header.php';
?>

<h1>Editar Maestro</h1>

<form method="POST" action="">
    <label>Nombre:</label><br>
    <input type="text" name="nombre" value="<?php echo htmlspecialchars($profesor['nombre']); ?>" required><br><br>

    <label>Apellidos:</label><br>
    <input type="text" name="apellidos" value="<?php echo htmlspecialchars($profesor['apellidos']); ?>" required><br><br>

    <label>Teléfono:</label><br>
    <input type="tel" name="telefono" value="<?php echo htmlspecialchars($profesor['telefono']); ?>"><br><br>

    <button type="submit">Actualizar Maestro</button>
</form>

<?php include '../../includes/footer.php'; ?>