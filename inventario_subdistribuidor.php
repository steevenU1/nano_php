<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Admin','Subdistribuidor'])) {
    header("Location: 403.php");
    exit();
}

include 'db.php';
include 'navbar.php';

// 🔹 ID de la sucursal de Eulalia (almacén)
$idSucursalAlmacen = 40; // Ajusta si el ID real es diferente

// 🔹 Consulta de inventario
$sql = "
    SELECT 
        i.id AS id_inventario,
        p.marca,
        p.modelo,
        p.color,
        p.capacidad,
        p.imei1,
        p.imei2,
        p.costo,
        (p.costo * 1.05) AS costo_con_extra,
        p.precio_lista,
        i.estatus,
        i.fecha_ingreso
    FROM inventario i
    INNER JOIN productos p ON p.id = i.id_producto
    WHERE i.id_sucursal = ? 
      AND i.estatus = 'Disponible'
    ORDER BY p.marca, p.modelo
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $idSucursalAlmacen);
$stmt->execute();
$result = $stmt->get_result();

$inventario = $result->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Inventario Subdistribuidor</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
</head>
<body class="bg-light">

<div class="container mt-4">
    <h2>📦 Inventario - Subdistribuidor / Admin</h2>
    <p>Mostrando inventario de la sucursal <b>Eulalia (Almacén)</b></p>
    <p>Total de equipos visibles: <b><?= count($inventario) ?></b></p>

    <!-- 🔹 Barra de filtros -->
    <div class="card p-3 mb-3 shadow-sm">
        <div class="row g-3">
            <div class="col-md-3">
                <input type="text" id="filtroMarca" class="form-control" placeholder="Filtrar por Marca">
            </div>
            <div class="col-md-3">
                <input type="text" id="filtroModelo" class="form-control" placeholder="Filtrar por Modelo">
            </div>
            <div class="col-md-3">
                <input type="text" id="filtroIMEI" class="form-control" placeholder="Filtrar por IMEI">
            </div>
            <div class="col-md-3">
                <select id="filtroEstatus" class="form-select">
                    <option value="">Todos los Estatus</option>
                    <option value="Disponible">Disponible</option>
                    <option value="En tránsito">En tránsito</option>
                </select>
            </div>
        </div>
    </div>

    <!-- 🔹 Tabla Inventario -->
    <table id="tablaInventario" class="table table-bordered table-striped table-sm">
        <thead class="table-dark">
            <tr>
                <th>ID</th>
                <th>Marca</th>
                <th>Modelo</th>
                <th>Color</th>
                <th>Capacidad</th>
                <th>IMEI1</th>
                <th>IMEI2</th>
                <th>Costo($)</th>
                <th>Precio Lista ($)</th>
                <th>Estatus</th>
                <th>Fecha Ingreso</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($inventario as $row): ?>
            <tr>
                <td><?= $row['id_inventario'] ?></td>
                <td><?= htmlspecialchars($row['marca'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($row['modelo'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($row['color'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($row['capacidad'] ?? '-', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($row['imei1'] ?? '-', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($row['imei2'] ?? '-', ENT_QUOTES) ?></td>
                <td>$<?= number_format($row['costo_con_extra'],2) ?></td>
                <td>$<?= number_format($row['precio_lista'],2) ?></td>
                <td><?= $row['estatus'] ?></td>
                <td><?= $row['fecha_ingreso'] ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
$(document).ready(function() {
    let tabla = $('#tablaInventario').DataTable({
        "pageLength": 25,
        "order": [[ 0, "asc" ]],
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json"
        }
    });

    // 🔹 Filtros personalizados
    $('#filtroMarca').on('keyup', function() {
        tabla.column(1).search(this.value).draw();
    });
    $('#filtroModelo').on('keyup', function() {
        tabla.column(2).search(this.value).draw();
    });
    $('#filtroIMEI').on('keyup', function() {
        // 🔹 Buscar en ambas columnas de IMEI
        tabla.columns([5,6]).search(this.value).draw();
    });
    $('#filtroEstatus').on('change', function() {
        tabla.column(9).search(this.value).draw();
    });
});
</script>

</body>
</html>
