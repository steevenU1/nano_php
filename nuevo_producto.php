<?php
session_start();
if(!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin'){
    header("Location: 403.php");
    exit();
}

include 'db.php';
include 'navbar.php';

$mensaje = "";

// 🔹 Obtener sucursales
$sucursales = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $marca = trim($_POST['marca'] ?? '');
    $modelo = trim($_POST['modelo'] ?? '');
    $color = trim($_POST['color'] ?? '');
    $capacidad = trim($_POST['capacidad'] ?? '');
    $imei1 = trim($_POST['imei1'] ?? '');
    $imei2 = trim($_POST['imei2'] ?? '');
    $costo = floatval($_POST['costo'] ?? 0);
    $precio_lista = floatval($_POST['precio_lista'] ?? 0);
    $tipo_producto = $_POST['tipo_producto'] ?? 'Equipo';
    $id_sucursal = intval($_POST['id_sucursal'] ?? 0);

    if($marca && $modelo && $imei1 && $costo > 0 && $precio_lista > 0 && $id_sucursal > 0){
        // 1️⃣ Insertar producto
        $stmt = $conn->prepare("
            INSERT INTO productos(marca, modelo, color, capacidad, imei1, imei2, costo, precio_lista, tipo_producto)
            VALUES (?,?,?,?,?,?,?,?,?)
        ");
        $stmt->bind_param("ssssssdds", $marca, $modelo, $color, $capacidad, $imei1, $imei2, $costo, $precio_lista, $tipo_producto);
        if($stmt->execute()){
            $id_producto = $stmt->insert_id;

            // 2️⃣ Insertar inventario
            $stmt2 = $conn->prepare("
                INSERT INTO inventario(id_producto, id_sucursal, estatus)
                VALUES (?, ?, 'Disponible')
            ");
            $stmt2->bind_param("ii", $id_producto, $id_sucursal);
            $stmt2->execute();

            $mensaje = "✅ Producto registrado correctamente en la sucursal seleccionada.";
        } else {
            $mensaje = "❌ Error al registrar el producto.";
        }
    } else {
        $mensaje = "⚠️ Completa todos los campos obligatorios.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Nuevo Producto</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<div class="container mt-4" style="max-width:700px;">
    <h2>📦 Registrar Nuevo Producto Individual</h2>
    <p>Este módulo permite cargar un equipo a una sucursal específica.</p>

    <?php if($mensaje): ?>
        <div class="alert alert-info"><?= $mensaje ?></div>
    <?php endif; ?>

    <form method="POST" class="card p-4 shadow-sm bg-white">
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">Marca *</label>
                <input type="text" name="marca" class="form-control" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Modelo *</label>
                <input type="text" name="modelo" class="form-control" required>
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-4">
                <label class="form-label">Color</label>
                <input type="text" name="color" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">Capacidad</label>
                <input type="text" name="capacidad" class="form-control" placeholder="Ej. 1 + 128GB">
            </div>
            <div class="col-md-4">
                <label class="form-label">Tipo de Producto *</label>
                <select name="tipo_producto" class="form-select" required>
                    <option value="Equipo">Equipo</option>
                    <option value="Modem">Modem</option>
                    <option value="Accesorio">Accesorio</option>
                </select>
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">IMEI 1 *</label>
                <input type="text" name="imei1" class="form-control" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">IMEI 2</label>
                <input type="text" name="imei2" class="form-control">
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">Costo ($) *</label>
                <input type="number" step="0.01" name="costo" class="form-control" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Precio Lista ($) *</label>
                <input type="number" step="0.01" name="precio_lista" class="form-control" required>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">Sucursal *</label>
            <select name="id_sucursal" class="form-select" required>
                <option value="">Seleccione sucursal...</option>
                <?php while($s = $sucursales->fetch_assoc()): ?>
                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['nombre'], ENT_QUOTES) ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="text-end">
            <button class="btn btn-primary">Registrar Producto</button>
            <a href="inventario_global.php" class="btn btn-secondary">Volver</a>
        </div>
    </form>
</div>

</body>
</html>
