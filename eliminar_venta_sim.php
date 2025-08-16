<?php
session_start();
include 'db.php';

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

$idVenta = intval($_POST['id_venta'] ?? 0);
$idUsuario = $_SESSION['id_usuario'];
$rolUsuario = $_SESSION['rol'];

// 1️⃣ Verificar que la venta SIM exista
$sqlVenta = "SELECT id, id_usuario, fecha_venta FROM ventas_sims WHERE id=?";
$stmt = $conn->prepare($sqlVenta);
$stmt->bind_param("i", $idVenta);
$stmt->execute();
$venta = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$venta) {
    header("Location: historial_ventas_sims.php?msg=Venta+de+SIM+no+encontrada");
    exit();
}

// 2️⃣ Validar permisos del usuario (Ejecutivo, Gerente, Admin puede eliminar sus propias ventas)
$puedeEliminar = false;
if (in_array($rolUsuario, ['Ejecutivo','Gerente','Admin']) && $venta['id_usuario'] == $idUsuario) {
    $puedeEliminar = true;
}

// 3️⃣ Validar si la venta es de la semana actual (martes-lunes)
$fechaVenta = new DateTime($venta['fecha_venta']);
$diaSemana = (int)$fechaVenta->format('N'); // 1 (Lunes) - 7 (Domingo)
$offset = $diaSemana - 2;
if ($offset < 0) $offset += 7;
$inicioSemana = new DateTime($venta['fecha_venta']);
$inicioSemana->modify("-$offset days")->setTime(0, 0, 0);
$finSemana = clone $inicioSemana;
$finSemana->modify('+6 days')->setTime(23, 59, 59);

$hoy = new DateTime();
if (!($hoy >= $inicioSemana && $hoy <= $finSemana)) {
    header("Location: historial_ventas_sims.php?msg=Solo+puedes+eliminar+ventas+de+la+semana+actual");
    exit();
}

if (!$puedeEliminar) {
    header("Location: historial_ventas_sims.php?msg=No+tienes+permiso+para+eliminar+esta+venta");
    exit();
}

// 4️⃣ Obtener los SIMs de la venta
$sqlSims = "SELECT id_sim FROM detalle_venta_sims WHERE id_venta=?";
$stmt = $conn->prepare($sqlSims);
$stmt->bind_param("i", $idVenta);
$stmt->execute();
$res = $stmt->get_result();

// 5️⃣ Devolver cada SIM al inventario
while ($row = $res->fetch_assoc()) {
    $idSim = (int)$row['id_sim'];
    $conn->query("UPDATE inventario_sims SET estatus='Disponible' WHERE id=$idSim");
}
$stmt->close();

// 6️⃣ Eliminar detalle y venta
$conn->query("DELETE FROM detalle_venta_sims WHERE id_venta=$idVenta");
$conn->query("DELETE FROM ventas_sims WHERE id=$idVenta");

// 7️⃣ Redirigir
header("Location: historial_ventas_sims.php?msg=✅+Venta+de+SIM+eliminada+correctamente");
exit();
