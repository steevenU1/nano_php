<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';
include 'navbar.php';

$id_usuario = $_SESSION['id_usuario'];
$rol = $_SESSION['rol'];
$id_sucursal = $_SESSION['id_sucursal'] ?? 0;

// 🔹 Filtro por IMEI
$filtroImei = $_GET['imei'] ?? '';
$where = "WHERE i.estatus IN ('Disponible','En tránsito')";
$params = [];
$types = "";

if ($rol != 'Admin') {
    $where .= " AND i.id_sucursal = ?";
    $params[] = $id_sucursal;
    $types .= "i";
}

if (!empty($filtroImei)) {
    $where .= " AND (p.imei1 LIKE ? OR p.imei2 LIKE ?)";
    $filtroLike = "%$filtroImei%";
    $params[] = $filtroLike;
    $params[] = $filtroLike;
    $types .= "ss";
}

// 🔹 Consulta inventario
$sql = "
    SELECT i.id, p.marca, p.modelo, p.color, p.capacidad,
           p.imei1, p.imei2, i.estatus, i.fecha_ingreso
    FROM inventario i
    INNER JOIN productos p ON p.id = i.id_producto
    $where
    ORDER BY i.fecha_ingreso DESC
";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$totalEquipos = $result->num_rows;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Inventario de Sucursal</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<div class="container mt-4">
    <h2>📦 Inventario de Sucursal</h2>
    <p>Total de equipos: <b><?= $totalEquipos ?></b></p>

    <!-- 🔹 Filtros -->
    <form method="GET" class="row g-3 mb-3">
        <div class="col-md-4">
            <input type="text" name="imei" class="form-control" placeholder="Buscar por IMEI..." value="<?= htmlspecialchars($filtroImei ?? '', ENT_QUOTES) ?>">
        </div>
        <div class="col-md-4">
            <button class="btn btn-primary">🔍 Buscar</button>
            <a href="panel.php" class="btn btn-secondary">Limpiar</a>
            <a href="exportar_inventario_excel.php" class="btn btn-success">📊 Exportar a Excel</a>
        </div>
    </form>

    <!-- 🔹 Tabla inventario -->
    <table class="table table-bordered table-striped table-sm">
        <thead class="table-dark">
            <tr>
                <th>ID</th>
                <th>Marca</th>
                <th>Modelo</th>
                <th>Color</th>
                <th>Capacidad</th>
                <th>IMEI1</th>
                <th>IMEI2</th>
                <th>Estatus</th>
                <th>Fecha Ingreso</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $result->fetch_assoc()): ?>
            <tr>
                <td><?= $row['id'] ?></td>
                <td><?= htmlspecialchars($row['marca'] ?? '', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($row['modelo'] ?? '', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($row['color'] ?? '', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($row['capacidad'] ?? '-', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($row['imei1'] ?? '-', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($row['imei2'] ?? '-', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($row['estatus'] ?? '', ENT_QUOTES) ?></td>
                <td><?= $row['fecha_ingreso'] ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

</body>
</html>
