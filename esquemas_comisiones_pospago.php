<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Admin','Gerente General'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

// Consultar todas las comisiones pospago por plan
$sql = "
    SELECT ecp.*, ec.fecha_inicio AS fecha_esquema
    FROM esquemas_comisiones_pospago ecp
    LEFT JOIN esquemas_comisiones_ejecutivos ec
      ON ec.id = ecp.id_esquema
    ORDER BY ecp.tipo, ec.fecha_inicio DESC, ecp.plan_monto DESC
";
$res = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Comisiones Pospago por Plan</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>📋 Comisiones Pospago por Plan</h2>
    <a href="nuevo_pospago_plan.php" class="btn btn-primary mb-3">➕ Agregar Plan</a>

    <table class="table table-bordered table-striped">
        <thead class="table-dark">
            <tr>
                <th>Tipo</th>
                <th>Esquema Inicio</th>
                <th>Plan ($)</th>
                <th>Con Equipo ($)</th>
                <th>Sin Equipo ($)</th>
            </tr>
        </thead>
        <tbody>
            <?php while($row=$res->fetch_assoc()): ?>
            <tr>
                <td><?= $row['tipo'] ?></td>
                <td><?= date("d/m/Y", strtotime($row['fecha_esquema'])) ?></td>
                <td>$<?= number_format($row['plan_monto'],2) ?></td>
                <td>$<?= number_format($row['comision_con_equipo'],2) ?></td>
                <td>$<?= number_format($row['comision_sin_equipo'],2) ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

</body>
</html>
