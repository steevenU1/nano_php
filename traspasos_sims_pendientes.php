<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

$idSucursal = $_SESSION['id_sucursal'];
$idUsuario = $_SESSION['id_usuario'];

// ✅ Mensaje de confirmación
$mensaje = "";

// 🔹 Confirmar recepción de traspaso
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_traspaso'])) {
    $idTraspaso = (int)$_POST['id_traspaso'];

    // Obtener todas las SIMs de este traspaso
    $sqlSims = "
        SELECT ds.id_sim
        FROM detalle_traspaso_sims ds
        INNER JOIN traspasos_sims ts ON ts.id = ds.id_traspaso
        WHERE ds.id_traspaso = ? AND ts.id_sucursal_destino = ? AND ts.estatus = 'Pendiente'
    ";
    $stmtSims = $conn->prepare($sqlSims);
    $stmtSims->bind_param("ii", $idTraspaso, $idSucursal);
    $stmtSims->execute();
    $resultSims = $stmtSims->get_result();

    $idsSims = [];
    while ($sim = $resultSims->fetch_assoc()) {
        $idsSims[] = $sim['id_sim'];
    }
    $stmtSims->close();

    if (!empty($idsSims)) {
        // Actualizar inventario de SIMs a Disponible y asignar sucursal destino
        $idsPlaceholder = implode(',', array_fill(0, count($idsSims), '?'));
        $types = str_repeat('i', count($idsSims) + 1);

        $sqlUpdate = "UPDATE inventario_sims SET estatus='Disponible', id_sucursal=? WHERE id IN ($idsPlaceholder)";
        $stmtUpdate = $conn->prepare($sqlUpdate);

        // Generar parámetros dinámicos
        $params = array_merge([$idSucursal], $idsSims);
        $stmtUpdate->bind_param($types, ...$params);
        $stmtUpdate->execute();
        $stmtUpdate->close();

        // Marcar traspaso como Completado
        $stmtTraspaso = $conn->prepare("UPDATE traspasos_sims SET estatus='Completado' WHERE id=?");
        $stmtTraspaso->bind_param("i", $idTraspaso);
        $stmtTraspaso->execute();
        $stmtTraspaso->close();

        $mensaje = "<div class='alert alert-success'>✅ Traspaso #$idTraspaso confirmado correctamente. Las SIMs ya están disponibles en tu inventario.</div>";
    } else {
        $mensaje = "<div class='alert alert-warning'>⚠️ No se encontraron SIMs pendientes para este traspaso.</div>";
    }
}

// 🔹 Obtener traspasos pendientes para esta sucursal
$sqlTraspasos = "
    SELECT ts.id, ts.fecha_traspaso, so.nombre AS sucursal_origen, u.nombre AS usuario_creo
    FROM traspasos_sims ts
    INNER JOIN sucursales so ON so.id = ts.id_sucursal_origen
    INNER JOIN usuarios u ON u.id = ts.usuario_creo
    WHERE ts.id_sucursal_destino = ? AND ts.estatus = 'Pendiente'
    ORDER BY ts.fecha_traspaso ASC
";
$stmtTraspasos = $conn->prepare($sqlTraspasos);
$stmtTraspasos->bind_param("i", $idSucursal);
$stmtTraspasos->execute();
$traspasos = $stmtTraspasos->get_result();
$stmtTraspasos->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Traspasos de SIMs Pendientes</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>📦 Traspasos de SIMs Pendientes</h2>
    <?= $mensaje ?>

    <?php if ($traspasos->num_rows > 0): ?>
        <?php while($t = $traspasos->fetch_assoc()): ?>
            <?php
            $idTraspaso = $t['id'];

            // Obtener detalle de SIMs
            $sqlDetalle = "
                SELECT i.id, i.iccid, i.dn, i.caja_id
                FROM detalle_traspaso_sims ds
                INNER JOIN inventario_sims i ON i.id = ds.id_sim
                WHERE ds.id_traspaso = ?
                ORDER BY i.caja_id
            ";
            $stmtDet = $conn->prepare($sqlDetalle);
            $stmtDet->bind_param("i", $idTraspaso);
            $stmtDet->execute();
            $detalle = $stmtDet->get_result();
            ?>
            
            <div class="card mb-4 shadow">
                <div class="card-header bg-dark text-white">
                    <div class="d-flex justify-content-between">
                        <span>Traspaso #<?= $idTraspaso ?> | Origen: <?= $t['sucursal_origen'] ?> | Fecha: <?= $t['fecha_traspaso'] ?></span>
                        <span>Creado por: <?= $t['usuario_creo'] ?></span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <table class="table table-bordered table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>ID SIM</th>
                                <th>ICCID</th>
                                <th>DN</th>
                                <th>Caja</th>
                                <th>Estatus</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($sim = $detalle->fetch_assoc()): ?>
                                <tr>
                                    <td><?= $sim['id'] ?></td>
                                    <td><?= $sim['iccid'] ?></td>
                                    <td><?= $sim['dn'] ?: '-' ?></td>
                                    <td><?= $sim['caja_id'] ?></td>
                                    <td>En tránsito</td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer text-end">
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="id_traspaso" value="<?= $idTraspaso ?>">
                        <button type="submit" class="btn btn-success btn-sm">Confirmar Recepción</button>
                    </form>
                </div>
            </div>

            <?php $stmtDet->close(); ?>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="alert alert-info">No hay traspasos de SIMs pendientes para tu sucursal.</div>
    <?php endif; ?>
</div>

</body>
</html>
