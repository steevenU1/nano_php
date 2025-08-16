<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Gerente','Admin'])) {
    header("Location: 403.php");
    exit();
}

include 'db.php';
include 'navbar.php';

$idSucursal = $_SESSION['id_sucursal'];
$hoy = date('Y-m-d');

// 🔹 1️⃣ Consultar cortes de la sucursal
$sqlCortes = "
    SELECT cc.*, u.nombre AS usuario
    FROM cortes_caja cc
    INNER JOIN usuarios u ON u.id = cc.id_usuario
    WHERE cc.id_sucursal = ?
    ORDER BY cc.fecha_operacion DESC
";
$stmt = $conn->prepare($sqlCortes);
$stmt->bind_param("i", $idSucursal);
$stmt->execute();
$cortes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 🔹 2️⃣ Detectar si hay cortes pendientes
$hayPendiente = false;
foreach ($cortes as $c) {
    if ($c['estado'] === 'Pendiente') {
        $hayPendiente = true;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Historial de Cortes de Caja</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<div class="container mt-4">
    <h2>💰 Historial de Cortes de Caja - <?= htmlspecialchars($_SESSION['nombre']) ?></h2>

    <?php if ($hayPendiente): ?>
        <div class="alert alert-warning">
            ⚠ Existen cortes pendientes de validación o depósito.  
            <a href="generar_corte.php" class="btn btn-sm btn-warning ms-2">Generar Corte Pendiente</a>
        </div>
    <?php endif; ?>

    <?php if (empty($cortes)): ?>
        <div class="alert alert-info">Aún no hay cortes generados para esta sucursal.</div>
    <?php else: ?>
        <table class="table table-sm table-bordered table-striped mt-3">
            <thead class="table-dark">
                <tr>
                    <th>ID Corte</th>
                    <th>Fecha Operación</th>
                    <th>Fecha Generado</th>
                    <th>Usuario</th>
                    <th>Total Efectivo</th>
                    <th>Total Tarjeta</th>
                    <th>Total General</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cortes as $c): ?>
                <tr>
                    <td><?= $c['id'] ?></td>
                    <td><?= $c['fecha_operacion'] ?></td>
                    <td><?= $c['fecha_corte'] ?></td>
                    <td><?= htmlspecialchars($c['usuario']) ?></td>
                    <td>$<?= number_format($c['total_efectivo'],2) ?></td>
                    <td>$<?= number_format($c['total_tarjeta'],2) ?></td>
                    <td>$<?= number_format($c['total_general'],2) ?></td>
                    <td>
                        <?php if ($c['estado'] === 'Pendiente'): ?>
                            <span class="badge bg-warning text-dark">Pendiente</span>
                        <?php else: ?>
                            <span class="badge bg-success">Validado</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="detalle_corte.php?id_corte=<?= $c['id'] ?>" class="btn btn-sm btn-primary">
                            🔍 Ver detalle
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

</body>
</html>
