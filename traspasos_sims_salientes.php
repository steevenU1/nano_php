<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

$idSucursal = $_SESSION['id_sucursal'];
$idUsuario = $_SESSION['id_usuario'];

$mensaje = "";

// 🔹 Obtener traspasos salientes pendientes de esta sucursal
$sqlTraspasos = "
    SELECT ts.id, ts.fecha_traspaso, sd.nombre AS sucursal_destino, u.nombre AS usuario_creo
    FROM traspasos_sims ts
    INNER JOIN sucursales sd ON sd.id = ts.id_sucursal_destino
    INNER JOIN usuarios u ON u.id = ts.usuario_creo
    WHERE ts.id_sucursal_origen = ? AND ts.estatus = 'Pendiente'
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
    <title>Traspasos de SIMs Salientes</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>📤 Traspasos de SIMs Salientes</h2>
    <p class="text-muted">Estos son los traspasos enviados a otras sucursales que aún no han sido confirmados.</p>

    <?php if ($traspasos->num_rows > 0): ?>
        <?php while($t = $traspasos->fetch_assoc()): ?>
            <?php
            $idTraspaso = $t['id'];

            // Obtener detalle de SIMs enviadas
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
                <div class="card-header bg-primary text-white">
                    <div class="d-flex justify-content-between">
                        <span>Traspaso #<?= $idTraspaso ?> | Destino: <?= $t['sucursal_destino'] ?> | Fecha: <?= $t['fecha_traspaso'] ?></span>
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
            </div>

            <?php $stmtDet->close(); ?>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="alert alert-info">No tienes traspasos salientes pendientes.</div>
    <?php endif; ?>
</div>

</body>
</html>
