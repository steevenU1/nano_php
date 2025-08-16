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

// 🔹 Función para obtener inicio y fin de la semana actual (martes-lunes)
function obtenerSemanaActual() {
    $hoy = new DateTime();
    $diaSemana = $hoy->format('N'); // 1 = lunes, 2 = martes, ..., 7 = domingo
    $dif = $diaSemana - 2; // martes = 2
    if ($dif < 0) $dif += 7;

    $inicio = new DateTime();
    $inicio->modify("-$dif days")->setTime(0,0,0);

    $fin = clone $inicio;
    $fin->modify("+6 days")->setTime(23,59,59);

    return [$inicio, $fin];
}
list($inicioSemana, $finSemana) = obtenerSemanaActual();

// 🔹 Verificar que la venta exista y obtener su fecha y usuario
$sqlVenta = "SELECT id, id_usuario, fecha_venta FROM ventas WHERE id=?";
$stmt = $conn->prepare($sqlVenta);
$stmt->bind_param("i", $idVenta);
$stmt->execute();
$venta = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$venta) {
    header("Location: historial_ventas.php?msg=❌+Venta+no+encontrada");
    exit();
}

// 🔒 Validar que la venta sea de esta semana
$fechaVenta = new DateTime($venta['fecha_venta']);
if ($fechaVenta < $inicioSemana || $fechaVenta > $finSemana) {
    header("Location: historial_ventas.php?msg=❌+Solo+puedes+eliminar+ventas+de+esta+semana");
    exit();
}

// 🔒 Validar permisos: Ejecutivo, Gerente y Admin solo pueden eliminar sus propias ventas
$puedeEliminar = false;
if (in_array($rolUsuario, ['Ejecutivo', 'Gerente', 'Admin']) && $venta['id_usuario'] == $idUsuario) {
    $puedeEliminar = true;
}

if (!$puedeEliminar) {
    header("Location: historial_ventas.php?msg=❌+No+tienes+permiso+para+eliminar+esta+venta");
    exit();
}

// 🔁 Devolver productos al inventario
$sqlDetalle = "SELECT id_producto FROM detalle_venta WHERE id_venta=?";
$stmt = $conn->prepare($sqlDetalle);
$stmt->bind_param("i", $idVenta);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $idProd = $row['id_producto'];
    $conn->query("UPDATE inventario SET estatus='Disponible' WHERE id_producto=$idProd");
}
$stmt->close();

// 🗑 Eliminar detalle y venta
$conn->query("DELETE FROM detalle_venta WHERE id_venta=$idVenta");
$conn->query("DELETE FROM ventas WHERE id=$idVenta");

// ✅ Redirigir con mensaje
header("Location: historial_ventas.php?msg=✅+Venta+eliminada+correctamente");
exit();
