<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

$idUsuario = $_SESSION['id_usuario'];
$idSucursalOrigen = $_SESSION['id_sucursal'];

// Obtener sucursales destino (todas menos la propia)
$sqlSucursales = "SELECT id, nombre FROM sucursales WHERE id != ?";
$stmt = $conn->prepare($sqlSucursales);
$stmt->bind_param("i", $idSucursalOrigen);
$stmt->execute();
$sucursales = $stmt->get_result();

// Obtener cajas disponibles en la sucursal origen (solo SIMs disponibles)
$sqlCajas = "
    SELECT caja_id, COUNT(*) as total_sims
    FROM inventario_sims
    WHERE id_sucursal = ? AND estatus = 'Disponible'
    GROUP BY caja_id
    ORDER BY caja_id
";
$stmtCajas = $conn->prepare($sqlCajas);
$stmtCajas->bind_param("i", $idSucursalOrigen);
$stmtCajas->execute();
$cajas = $stmtCajas->get_result();

$mensaje = '';

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['caja_id'], $_POST['id_sucursal_destino'])) {
    $cajaId = trim($_POST['caja_id']);
    $idSucursalDestino = (int)$_POST['id_sucursal_destino'];

    // Validar que la caja exista y tenga SIMs disponibles
    $stmtValidar = $conn->prepare("
        SELECT id FROM inventario_sims
        WHERE id_sucursal = ? AND estatus = 'Disponible' AND caja_id = ?
    ");
    $stmtValidar->bind_param("is", $idSucursalOrigen, $cajaId);
    $stmtValidar->execute();
    $resultSims = $stmtValidar->get_result();

    if ($resultSims->num_rows == 0) {
        $mensaje = "<div class='alert alert-danger'>❌ No hay SIMs disponibles en la caja <b>$cajaId</b>.</div>";
    } else {
        // 1️⃣ Crear traspaso
        $stmtTraspaso = $conn->prepare("
            INSERT INTO traspasos_sims (id_sucursal_origen, id_sucursal_destino, usuario_creo, estatus, fecha_traspaso)
            VALUES (?, ?, ?, 'Pendiente', NOW())
        ");
        $stmtTraspaso->bind_param("iii", $idSucursalOrigen, $idSucursalDestino, $idUsuario);
        $stmtTraspaso->execute();
        $idTraspaso = $stmtTraspaso->insert_id;
        $stmtTraspaso->close();

        // 2️⃣ Procesar todas las SIMs de la caja
        while ($sim = $resultSims->fetch_assoc()) {
            $idSim = $sim['id'];

            // Insertar en detalle_traspaso_sims
            $stmtDetalle = $conn->prepare("INSERT INTO detalle_traspaso_sims (id_traspaso, id_sim) VALUES (?, ?)");
            $stmtDetalle->bind_param("ii", $idTraspaso, $idSim);
            $stmtDetalle->execute();
            $stmtDetalle->close();

            // Cambiar estatus a 'En tránsito'
            $stmtUpdate = $conn->prepare("UPDATE inventario_sims SET estatus='En tránsito' WHERE id=?");
            $stmtUpdate->bind_param("i", $idSim);
            $stmtUpdate->execute();
            $stmtUpdate->close();
        }

        $mensaje = "<div class='alert alert-success'>✅ Traspaso generado con éxito. Caja <b>$cajaId</b> enviada a la sucursal destino.</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Generar Traspaso de SIMs</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>📦 Generar Traspaso de SIMs (por Caja)</h2>
    <?= $mensaje ?>

    <form method="POST" class="card p-3 shadow mb-4">
        <div class="mb-3">
            <label class="form-label"><strong>Selecciona Caja</strong></label>
            <select name="caja_id" class="form-select" required>
                <option value="">-- Selecciona una caja --</option>
                <?php while($c = $cajas->fetch_assoc()): ?>
                    <option value="<?= $c['caja_id'] ?>">
                        <?= $c['caja_id'] ?> (<?= $c['total_sims'] ?> SIMs)
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label"><strong>Selecciona Sucursal Destino</strong></label>
            <select name="id_sucursal_destino" class="form-select" required>
                <option value="">-- Selecciona sucursal --</option>
                <?php while($s = $sucursales->fetch_assoc()): ?>
                    <option value="<?= $s['id'] ?>"><?= $s['nombre'] ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <button type="submit" class="btn btn-primary">Generar Traspaso</button>
    </form>
</div>

</body>
</html>
