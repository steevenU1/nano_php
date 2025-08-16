<?php
session_start();
include 'db.php';

// Asegura que haya sesión activa
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

$idSucursal = $_SESSION['id_sucursal'];
$rol = $_SESSION['rol'] ?? '';

// Consulta de precios por modelo+capacidad incluyendo la promoción
$sql = "
    SELECT 
        p.marca,
        p.modelo,
        p.capacidad,
        COUNT(*) AS disponibles_global,
        SUM(CASE WHEN i.id_sucursal = $idSucursal THEN 1 ELSE 0 END) AS disponibles_sucursal,
        MAX(p.precio_lista) AS precio_lista,
        MAX(pc.precio_combo) AS precio_combo,
        MAX(pc.promocion) AS promocion
    FROM inventario i
    INNER JOIN productos p ON p.id = i.id_producto
    LEFT JOIN precios_combo pc 
        ON pc.marca = p.marca AND pc.modelo = p.modelo AND pc.capacidad = p.capacidad
    WHERE i.estatus = 'Disponible'
    GROUP BY p.marca, p.modelo, p.capacidad
    ORDER BY p.marca, p.modelo;
";

$result = $conn->query($sql);
$datos = $result->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Lista de Precios - Luga</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h3>📋 Lista de Precios por Modelo</h3>
    <p class="text-muted">Mostrando solo equipos <b>Disponibles</b>. Precios agrupados por marca, modelo y capacidad.</p>

    <div class="table-responsive mt-4">
        <table class="table table-bordered table-striped table-hover">
            <thead class="table-dark">
                <tr>
                    <th>Marca</th>
                    <th>Modelo</th>
                    <th>Capacidad</th>
                    <th>Precio Lista ($)</th>
                    <th>Precio Combo ($)</th>
                    <th>Promoción</th>
                    <th>Disponibles (Global)</th>
                    <th>Disponibles en Sucursal</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($datos as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['marca']) ?></td>
                        <td><?= htmlspecialchars($row['modelo']) ?></td>
                        <td><?= htmlspecialchars($row['capacidad']) ?></td>
                        <td>$<?= number_format($row['precio_lista'], 2) ?></td>
                        <td>
                            <?= is_null($row['precio_combo']) 
                                ? '<span class="text-muted">No definido</span>' 
                                : '$' . number_format($row['precio_combo'], 2) ?>
                        </td>
                        <td>
                            <?= empty($row['promocion']) 
                                ? '<span class="text-muted">Sin promoción</span>' 
                                : htmlspecialchars($row['promocion']) ?>
                        </td>
                        <td><b><?= $row['disponibles_global'] ?></b></td>
                        <td><b><?= $row['disponibles_sucursal'] ?></b></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
