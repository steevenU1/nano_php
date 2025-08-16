<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Admin','Gerente General'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

// Procesar formulario
$mensaje = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_sucursal = $_POST['id_sucursal'] ?? '';
    $cuota = $_POST['cuota_monto'] ?? '';
    $fecha = $_POST['fecha_inicio'] ?? '';

    if ($id_sucursal && $cuota && $fecha) {
        // Validar que sea tipo Tienda
        $validacion = $conn->prepare("SELECT tipo_sucursal FROM sucursales WHERE id=? LIMIT 1");
        $validacion->bind_param("i", $id_sucursal);
        $validacion->execute();
        $tipo = $validacion->get_result()->fetch_assoc()['tipo_sucursal'] ?? '';

        if($tipo !== 'Tienda'){
            $mensaje = "❌ No se pueden asignar cuotas a Almacenes.";
        } else {
            // Obtener cuota vigente
            $sqlVigente = "
                SELECT cuota_monto 
                FROM cuotas_sucursales 
                WHERE id_sucursal=? 
                ORDER BY fecha_inicio DESC 
                LIMIT 1
            ";
            $stmtV = $conn->prepare($sqlVigente);
            $stmtV->bind_param("i", $id_sucursal);
            $stmtV->execute();
            $cuotaVigente = $stmtV->get_result()->fetch_assoc()['cuota_monto'] ?? null;

            // Evitar duplicados
            if ($cuotaVigente !== null && (float)$cuota == (float)$cuotaVigente) {
                $mensaje = "⚠️ La cuota ingresada es igual a la vigente. No es necesario registrar una nueva.";
            } else {
                // Insertar nueva cuota
                $sql = "INSERT INTO cuotas_sucursales (id_sucursal, cuota_monto, fecha_inicio) VALUES (?,?,?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ids", $id_sucursal, $cuota, $fecha);
                if ($stmt->execute()) {
                    header("Location: cuotas_sucursales.php");
                    exit();
                } else {
                    $mensaje = "❌ Error al guardar la cuota: ".$conn->error;
                }
            }
        }
    } else {
        $mensaje = "❌ Todos los campos son obligatorios.";
    }
}

// Obtener solo sucursales tipo Tienda
$resSuc = $conn->query("SELECT id, nombre FROM sucursales WHERE tipo_sucursal='Tienda' ORDER BY nombre");
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Nueva Cuota de Sucursal</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<script>
function cargarCuotaVigente() {
    const select = document.querySelector('select[name="id_sucursal"]');
    const infoDiv = document.getElementById('cuotaVigenteInfo');
    if (!select.value) {
        infoDiv.innerHTML = '';
        return;
    }
    fetch('get_cuota_vigente.php?id_sucursal=' + select.value)
        .then(res => res.json())
        .then(data => {
            if (data.cuota && data.fecha_inicio) {
                infoDiv.innerHTML = `<div class="alert alert-info mt-2">
                    Cuota vigente: <strong>$${data.cuota.toFixed(2)}</strong> 
                    desde el ${new Date(data.fecha_inicio).toLocaleDateString()}
                </div>`;
            } else {
                infoDiv.innerHTML = `<div class="alert alert-secondary mt-2">
                    Esta sucursal no tiene cuota vigente registrada.
                </div>`;
            }
        });
}
</script>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>➕ Nueva Cuota de Sucursal</h2>

    <?php if($mensaje): ?>
        <div class="alert alert-warning"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>

    <form method="POST" class="card p-4 shadow-sm bg-white">
        <div class="mb-3">
            <label class="form-label"><strong>Sucursal:</strong></label>
            <select name="id_sucursal" class="form-select" required onchange="cargarCuotaVigente()">
                <option value="">-- Selecciona --</option>
                <?php while($s=$resSuc->fetch_assoc()): ?>
                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['nombre']) ?></option>
                <?php endwhile; ?>
            </select>
            <div id="cuotaVigenteInfo"></div>
        </div>

        <div class="mb-3">
            <label class="form-label"><strong>Cuota ($):</strong></label>
            <input type="number" step="0.01" name="cuota_monto" class="form-control" required>
        </div>

        <div class="mb-3">
            <label class="form-label"><strong>Fecha de Inicio:</strong></label>
            <input type="date" name="fecha_inicio" class="form-control" required>
        </div>

        <button type="submit" class="btn btn-success">💾 Guardar Cuota</button>
        <a href="cuotas_sucursales.php" class="btn btn-secondary">⬅ Volver</a>
    </form>
</div>

</body>
</html>
