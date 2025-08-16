<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Admin','Gerente General'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

// Obtenemos las cuotas históricas de sucursales tipo Tienda
$sql = "
    SELECT cs.id, 
           s.nombre AS sucursal, 
           cs.cuota_monto, 
           cs.fecha_inicio,
           cs.fecha_inicio = (
                SELECT MAX(cs2.fecha_inicio)
                FROM cuotas_sucursales cs2
                WHERE cs2.id_sucursal = cs.id_sucursal
           ) AS es_vigente
    FROM cuotas_sucursales cs
    INNER JOIN sucursales s ON s.id = cs.id_sucursal
    WHERE s.tipo_sucursal='Tienda'
    ORDER BY s.nombre, cs.fecha_inicio DESC
";
$res = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Gestión de Cuotas de Sucursales</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>📋 Gestión de Cuotas de Sucursales (solo Tiendas)</h2>
    <a href="nueva_cuota_sucursal.php" class="btn btn-primary mb-3">➕ Nueva Cuota</a>

    <table class="table table-bordered table-striped table-hover">
        <thead class="table-dark">
            <tr>
                <th>Sucursal</th><th>Cuota ($)</th><th>Fecha Inicio</th><th>Estado</th>
            </tr>
        </thead>
        <tbody>
            <?php while($row=$res->fetch_assoc()): 
                $vigente = (int)$row['es_vigente'] === 1;
                $fila = $vigente ? 'table-success' : '';
                $estado = $vigente ? 'Vigente ✅' : 'Histórica';
            ?>
            <tr class="<?= $fila ?>">
                <td><?= htmlspecialchars($row['sucursal']) ?></td>
                <td>$<?= number_format($row['cuota_monto'],2) ?></td>
                <td><?= date("d/m/Y", strtotime($row['fecha_inicio'])) ?></td>
                <td><?= $estado ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

</body>
</html>
