<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Ejecutivo','Gerente'])) {
    header("Location: 403.php");
    exit();
}

include 'db.php';
include 'navbar.php';

$id_usuario = $_SESSION['id_usuario'];
$id_sucursal = $_SESSION['id_sucursal'];

$msg = '';

// 🔹 Procesar cobro
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $motivo = $_POST['motivo'] ?? '';
    $tipo_pago = $_POST['tipo_pago'] ?? '';
    $monto_total = floatval($_POST['monto_total'] ?? 0);
    $monto_efectivo = floatval($_POST['monto_efectivo'] ?? 0);
    $monto_tarjeta = floatval($_POST['monto_tarjeta'] ?? 0);

    // 🔹 Calcular comisión especial
    $comision_especial = 0;
    if (in_array($motivo, ['Abono PayJoy','Abono Krediya'])) {
        $comision_especial = 10.00;
    }

    // 🔹 Validaciones
    if (!$motivo || !$tipo_pago || $monto_total <= 0) {
        $msg = "<div class='alert alert-warning'>⚠ Debes llenar todos los campos obligatorios.</div>";
    } else {
        $valido = false;
        if ($tipo_pago === 'Efectivo' && abs($monto_efectivo - $monto_total) < 0.01) $valido = true;
        if ($tipo_pago === 'Tarjeta' && abs($monto_tarjeta - $monto_total) < 0.01) $valido = true;
        if ($tipo_pago === 'Mixto' && abs(($monto_efectivo + $monto_tarjeta) - $monto_total) < 0.01) $valido = true;

        if (!$valido) {
            $msg = "<div class='alert alert-danger'>⚠ Los montos no cuadran con el tipo de pago seleccionado.</div>";
        } else {
            // 🔹 Insertar cobro (id_corte NULL hasta generar corte)
            $stmt = $conn->prepare("
                INSERT INTO cobros (
                    id_usuario, id_sucursal, motivo, tipo_pago,
                    monto_total, monto_efectivo, monto_tarjeta, comision_especial,
                    fecha_cobro, id_corte, corte_generado
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NULL, 0)
            ");
            $stmt->bind_param("iissdddd", 
                $id_usuario, $id_sucursal, $motivo, $tipo_pago,
                $monto_total, $monto_efectivo, $monto_tarjeta, $comision_especial
            );
            if ($stmt->execute()) {
                $msg = "<div class='alert alert-success'>✅ Cobro registrado correctamente.</div>";
            } else {
                $msg = "<div class='alert alert-danger'>❌ Error al registrar cobro.</div>";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Registrar Cobro</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body class="bg-light">

<div class="container mt-4">
    <h2>💰 Registrar Cobro</h2>
    <?= $msg ?>

    <form method="POST" class="card p-3 shadow">
        <div class="mb-3">
            <label class="form-label">Motivo del cobro</label>
            <select name="motivo" class="form-select" required>
                <option value="">-- Selecciona --</option>
                <option>Enganche</option>
                <option>Equipo de contado</option>
                <option>Venta SIM</option>
                <option>Recarga Tiempo Aire</option>
                <option>Abono PayJoy</option>
                <option>Abono Krediya</option>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Tipo de pago</label>
            <select name="tipo_pago" id="tipo_pago" class="form-select" required>
                <option value="">-- Selecciona --</option>
                <option value="Efectivo">Efectivo</option>
                <option value="Tarjeta">Tarjeta</option>
                <option value="Mixto">Mixto</option>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Monto total ($)</label>
            <input type="number" step="0.01" name="monto_total" id="monto_total" class="form-control" required>
        </div>

        <div class="mb-3 pago-efectivo d-none">
            <label class="form-label">Monto en efectivo ($)</label>
            <input type="number" step="0.01" name="monto_efectivo" id="monto_efectivo" class="form-control">
        </div>

        <div class="mb-3 pago-tarjeta d-none">
            <label class="form-label">Monto con tarjeta ($)</label>
            <input type="number" step="0.01" name="monto_tarjeta" id="monto_tarjeta" class="form-control">
        </div>

        <button type="submit" class="btn btn-success w-100">💾 Guardar Cobro</button>
    </form>
</div>

<script>
function toggleCampos() {
    let tipo = $("#tipo_pago").val();
    $(".pago-efectivo, .pago-tarjeta").addClass("d-none");

    if (tipo === "Efectivo") $(".pago-efectivo").removeClass("d-none");
    if (tipo === "Tarjeta") $(".pago-tarjeta").removeClass("d-none");
    if (tipo === "Mixto") $(".pago-efectivo, .pago-tarjeta").removeClass("d-none");
}

$("#tipo_pago").on("change", toggleCampos);
</script>

</body>
</html>
