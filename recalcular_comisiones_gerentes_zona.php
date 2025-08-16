<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: index.php");
    exit();
}

include 'db.php';

$fechaInicio = $_POST['fecha_inicio'] ?? '';
$semana = (int)($_POST['semana'] ?? 0);

if (!$fechaInicio) {
    header("Location: reporte_nomina_gerentes_zona.php?semana=$semana&msg=Fecha+no+válida");
    exit();
}

// 1️⃣ Eliminar registros existentes de esa semana
$stmtDel = $conn->prepare("DELETE FROM comisiones_gerentes_zona WHERE fecha_inicio = ?");
$stmtDel->bind_param("s", $fechaInicio);
$stmtDel->execute();
$stmtDel->close();

// 2️⃣ Redirigir con mensaje de éxito
header("Location: reporte_nomina_gerentes_zona.php?semana=$semana&msg=✅ Semana recalculada correctamente");
exit();