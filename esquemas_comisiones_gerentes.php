<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Admin','Gerente General'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

// Obtener histórico
$sql = "SELECT * FROM esquemas_comisiones_gerentes ORDER BY fecha_inicio DESC";
$res = $conn->query($sql);

// Esquema vigente = el más reciente
$vigente = $conn->query("SELECT fecha_inicio FROM esquemas_comisiones_gerentes ORDER BY fecha_inicio DESC LIMIT 1")->fetch_assoc()['fecha_inicio'] ?? null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Esquemas de Comisiones - Gerentes</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>📋 Esquemas de Comisiones - Gerentes</h2>
    <a href="nuevo_esquema_gerentes.php" class="btn btn-primary mb-3">➕ Nuevo Esquema</a>

    <table class="table table-bordered table-striped table-hover">
        <thead class="table-dark">
            <tr>
                <th>Fecha Inicio</th>
                <th>Venta Directa sin/con</th>
                <th>Suc 1-10 sin/con</th>
                <th>Suc 11-20 sin/con</th>
                <th>Suc 21+ sin/con</th>
                <th>Modem sin/con</th>
                <th>SIM sin/con</th>
                <th>Pospago c/s eq</th>
                <th>Estado</th>
            </tr>
        </thead>
        <tbody>
            <?php while($row=$res->fetch_assoc()): 
                $fila = ($row['fecha_inicio']==$vigente) ? 'table-success' : '';
                $estado = ($row['fecha_inicio']==$vigente) ? 'Vigente ✅' : 'Histórico';
            ?>
            <tr class="<?= $fila ?>">
                <td><?= date("d/m/Y", strtotime($row['fecha_inicio'])) ?></td>
                <td>$<?= $row['venta_directa_sin'] ?>/ $<?= $row['venta_directa_con'] ?></td>
                <td>$<?= $row['sucursal_1_10_sin'] ?>/ $<?= $row['sucursal_1_10_con'] ?></td>
                <td>$<?= $row['sucursal_11_20_sin'] ?>/ $<?= $row['sucursal_11_20_con'] ?></td>
                <td>$<?= $row['sucursal_21_mas_sin'] ?>/ $<?= $row['sucursal_21_mas_con'] ?></td>
                <td>$<?= $row['comision_modem_sin'] ?>/ $<?= $row['comision_modem_con'] ?></td>
                <td>$<?= $row['comision_sim_sin'] ?>/ $<?= $row['comision_sim_con'] ?></td>
                <td>$<?= $row['comision_pos_con_equipo'] ?>/ $<?= $row['comision_pos_sin_equipo'] ?></td>
                <td><?= $estado ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

</body>
</html>
