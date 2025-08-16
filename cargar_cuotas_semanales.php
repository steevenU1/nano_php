<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: 403.php");
    exit();
}

include 'db.php';
include 'navbar.php';

// Manejo del formulario
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $semanaInicio = $_POST['semana_inicio'] ?? '';
    $semanaFin = $_POST['semana_fin'] ?? '';
    $cuotaUnidades = (int)($_POST['cuota_unidades'] ?? 0);

    if ($semanaInicio && $semanaFin && $cuotaUnidades > 0) {
        // Consultar todas las sucursales
        $sqlSuc = "SELECT id, nombre FROM sucursales";
        $resSuc = $conn->query($sqlSuc);

        $insertadas = 0;
        while ($suc = $resSuc->fetch_assoc()) {
            $idSucursal = $suc['id'];

            // Verificar si ya existe cuota para esa semana y sucursal
            $stmtCheck = $conn->prepare("
                SELECT COUNT(*) AS total 
                FROM cuotas_semanales_sucursal 
                WHERE id_sucursal=? 
                  AND semana_inicio=? 
                  AND semana_fin=? 
            ");
            $stmtCheck->bind_param("iss", $idSucursal, $semanaInicio, $semanaFin);
            $stmtCheck->execute();
            $existe = $stmtCheck->get_result()->fetch_assoc()['total'] ?? 0;

            if ($existe == 0) {
                $stmtIns = $conn->prepare("
                    INSERT INTO cuotas_semanales_sucursal 
                        (id_sucursal, semana_inicio, semana_fin, cuota_unidades, creado_en) 
                    VALUES (?,?,?,?,NOW())
                ");
                $stmtIns->bind_param("issi", $idSucursal, $semanaInicio, $semanaFin, $cuotaUnidades);
                $stmtIns->execute();
                $insertadas++;
            }
        }

        $msg = "✅ Se generaron $insertadas cuotas semanales para la semana seleccionada.";
    } else {
        $msg = "⚠ Por favor completa todos los campos correctamente.";
    }
}

// Consultar últimas cuotas generadas
$sqlUltimas = "
    SELECT c.*, s.nombre AS sucursal 
    FROM cuotas_semanales_sucursal c
    INNER JOIN sucursales s ON c.id_sucursal = s.id
    ORDER BY c.id DESC
    LIMIT 30
";
$resUltimas = $conn->query($sqlUltimas);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cargar Cuotas Semanales</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<div class="container mt-4">
    <h2>📅 Cargar Cuotas Semanales por Sucursal (Solo Admin)</h2>
    <a href="panel.php" class="btn btn-secondary mb-3">← Volver al Panel</a>

    <?php if ($msg): ?>
        <div class="alert alert-info"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <!-- Formulario -->
    <form method="POST" class="card p-3 shadow-sm mb-4">
        <div class="row g-3">
            <div class="col-md-3">
                <label for="semana_inicio" class="form-label">Semana Inicio (martes)</label>
                <input type="date" name="semana_inicio" id="semana_inicio" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label for="semana_fin" class="form-label">Semana Fin (lunes)</label>
                <input type="date" name="semana_fin" id="semana_fin" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label for="cuota_unidades" class="form-label">Cuota de Unidades</label>
                <input type="number" name="cuota_unidades" id="cuota_unidades" class="form-control" required>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button class="btn btn-primary w-100">Generar Cuotas</button>
            </div>
        </div>
    </form>

    <!-- Últimas cuotas generadas -->
    <h4>📋 Últimas Cuotas Generadas</h4>
    <table class="table table-striped table-bordered shadow-sm mt-3 bg-white">
        <thead class="table-dark">
            <tr>
                <th>ID</th>
                <th>Sucursal</th>
                <th>Semana Inicio</th>
                <th>Semana Fin</th>
                <th>Cuota Unidades</th>
                <th>Creado en</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $resUltimas->fetch_assoc()): ?>
                <tr>
                    <td><?= $row['id'] ?></td>
                    <td><?= htmlspecialchars($row['sucursal']) ?></td>
                    <td><?= $row['semana_inicio'] ?></td>
                    <td><?= $row['semana_fin'] ?></td>
                    <td><?= $row['cuota_unidades'] ?></td>
                    <td><?= $row['creado_en'] ?></td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

</body>
</html>
