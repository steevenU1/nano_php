<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'GerenteZona') {
    header("Location: 403.php");
    exit();
}

include 'db.php';
include 'navbar.php';

$idGerente = $_SESSION['id_usuario'];

// 🔹 Obtener zona del gerente
$sqlZona = "
    SELECT DISTINCT s.zona
    FROM sucursales s
    INNER JOIN usuarios u ON u.id_sucursal = s.id
    WHERE u.id = ?
";
$stmtZona = $conn->prepare($sqlZona);
$stmtZona->bind_param("i", $idGerente);
$stmtZona->execute();
$zona = $stmtZona->get_result()->fetch_assoc()['zona'] ?? '';
$stmtZona->close();

// 🔹 Función para obtener saldos por sucursal
function obtenerSaldos($conn, $zona){
    $sql = "
        SELECT 
            s.id AS id_sucursal,
            s.nombre AS sucursal,
            IFNULL(c.total_comisiones,0) AS total_comisiones,
            IFNULL(e.total_entregado,0) AS total_entregado,
            (IFNULL(c.total_comisiones,0) - IFNULL(e.total_entregado,0)) AS saldo_pendiente
        FROM sucursales s
        LEFT JOIN (
            SELECT id_sucursal, SUM(comision_especial) AS total_comisiones
            FROM cobros
            WHERE comision_especial > 0
            GROUP BY id_sucursal
        ) c ON c.id_sucursal = s.id
        LEFT JOIN (
            SELECT id_sucursal, SUM(monto_entregado) AS total_entregado
            FROM entregas_comisiones_especiales
            GROUP BY id_sucursal
        ) e ON e.id_sucursal = s.id
        WHERE s.zona = ?
        ORDER BY s.nombre
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s",$zona);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// 🔹 Función para historial de entregas
function obtenerHistorial($conn, $zona){
    $sql = "
        SELECT e.id, s.nombre AS sucursal, e.monto_entregado, e.fecha_entrega, e.observaciones
        FROM entregas_comisiones_especiales e
        INNER JOIN sucursales s ON s.id=e.id_sucursal
        WHERE s.zona = ?
        ORDER BY e.fecha_entrega DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s",$zona);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// 🔹 Procesar recolección
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idSucursal = intval($_POST['id_sucursal'] ?? 0);
    $monto = floatval($_POST['monto_entregado'] ?? 0);
    $observaciones = trim($_POST['observaciones'] ?? '');

    if ($idSucursal > 0 && $monto > 0) {
        // Verificar saldo pendiente actual
        $sqlSaldo = "
            SELECT 
                IFNULL(c.total_comisiones,0) - IFNULL(e.total_entregado,0) AS saldo_pendiente
            FROM (
                SELECT id_sucursal, SUM(comision_especial) AS total_comisiones
                FROM cobros
                WHERE comision_especial > 0
                GROUP BY id_sucursal
            ) c
            LEFT JOIN (
                SELECT id_sucursal, SUM(monto_entregado) AS total_entregado
                FROM entregas_comisiones_especiales
                GROUP BY id_sucursal
            ) e ON e.id_sucursal = c.id_sucursal
            WHERE c.id_sucursal = ?
            LIMIT 1
        ";
        $stmt = $conn->prepare($sqlSaldo);
        $stmt->bind_param("i",$idSucursal);
        $stmt->execute();
        $saldo = $stmt->get_result()->fetch_assoc()['saldo_pendiente'] ?? 0;

        if ($monto > $saldo) {
            $msg = "<div class='alert alert-danger'>❌ El monto excede el saldo pendiente: $".number_format($saldo,2)."</div>";
        } else {
            $sqlInsert = "
                INSERT INTO entregas_comisiones_especiales (id_sucursal,id_gerentezona,monto_entregado,fecha_entrega,observaciones)
                VALUES (?,?,?,NOW(),?)
            ";
            $stmtIns = $conn->prepare($sqlInsert);
            $stmtIns->bind_param("iids",$idSucursal,$idGerente,$monto,$observaciones);
            if ($stmtIns->execute()) {
                $msg = "<div class='alert alert-success'>✅ Entrega registrada correctamente.</div>";
            } else {
                $msg = "<div class='alert alert-danger'>❌ Error al registrar la entrega.</div>";
            }
        }
    } else {
        $msg = "<div class='alert alert-warning'>⚠ Debes seleccionar sucursal y monto válido.</div>";
    }

    // Refrescar datos
    $saldos = obtenerSaldos($conn,$zona);
    $historial = obtenerHistorial($conn,$zona);
} else {
    $saldos = obtenerSaldos($conn,$zona);
    $historial = obtenerHistorial($conn,$zona);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Recolección de Comisiones Abonos</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<div class="container mt-4">
    <h2>📊 Recolección de Comisiones Abonos - Zona <?= htmlspecialchars($zona) ?></h2>
    <?= $msg ?>

    <!-- Tabla de saldos -->
    <h4 class="mt-4">💰 Saldos de Comisiones por Sucursal</h4>
    <table class="table table-bordered table-sm">
        <thead class="table-dark">
            <tr>
                <th>Sucursal</th>
                <th>Total Comisiones</th>
                <th>Total Entregado</th>
                <th>Saldo Pendiente</th>
                <th>Acción</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($saldos as $s): ?>
            <tr>
                <td><?= $s['sucursal'] ?></td>
                <td>$<?= number_format($s['total_comisiones'],2) ?></td>
                <td>$<?= number_format($s['total_entregado'],2) ?></td>
                <td class="<?= $s['saldo_pendiente']>0?'text-danger fw-bold':'text-success' ?>">
                    $<?= number_format($s['saldo_pendiente'],2) ?>
                </td>
                <td>
                    <?php if ($s['saldo_pendiente']>0): ?>
                        <form method="POST" style="display:inline-block;">
                            <input type="hidden" name="id_sucursal" value="<?= $s['id_sucursal'] ?>">
                            <input type="hidden" name="monto_entregado" value="<?= $s['saldo_pendiente'] ?>">
                            <input type="hidden" name="observaciones" value="Recolección completa">
                            <button class="btn btn-primary btn-sm" onclick="return confirm('¿Recolectar todo?')">♻ Recolectar Todo</button>
                        </form>
                    <?php else: ?>
                        ✅
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Formulario -->
    <h4 class="mt-4">📥 Registrar Entrega de Comisiones</h4>
    <form method="POST" class="card p-3 shadow">
        <div class="mb-3">
            <label class="form-label">Sucursal</label>
            <select name="id_sucursal" class="form-select" required>
                <option value="">-- Selecciona Sucursal --</option>
                <?php foreach($saldos as $s): if($s['saldo_pendiente']>0): ?>
                    <option value="<?= $s['id_sucursal'] ?>">
                        <?= $s['sucursal'] ?> - Pendiente $<?= number_format($s['saldo_pendiente'],2) ?>
                    </option>
                <?php endif; endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Monto Entregado</label>
            <input type="number" step="0.01" name="monto_entregado" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Observaciones</label>
            <textarea name="observaciones" class="form-control"></textarea>
        </div>
        <button type="submit" class="btn btn-success w-100">💾 Registrar Entrega</button>
    </form>

    <!-- Historial -->
    <h4 class="mt-4">📜 Historial de Entregas</h4>
    <table class="table table-bordered table-sm">
        <thead class="table-dark">
            <tr>
                <th>ID</th>
                <th>Sucursal</th>
                <th>Monto Entregado</th>
                <th>Fecha</th>
                <th>Observaciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($historial as $h): ?>
            <tr>
                <td><?= $h['id'] ?></td>
                <td><?= $h['sucursal'] ?></td>
                <td>$<?= number_format($h['monto_entregado'],2) ?></td>
                <td><?= $h['fecha_entrega'] ?></td>
                <td><?= htmlspecialchars($h['observaciones'],ENT_QUOTES) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

</body>
</html>
