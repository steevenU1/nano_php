<?php
// procesar_retiro.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'Admin') {
    header("Location: 403.php"); exit();
}
include 'db.php';

$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$items      = $_POST['items'] ?? [];
$idSucursal = isset($_POST['id_sucursal']) ? (int)$_POST['id_sucursal'] : 0; // Debe ser Eulalia
$motivo     = $_POST['motivo'] ?? '';
$destino    = trim($_POST['destino'] ?? '');
$nota       = trim($_POST['nota'] ?? '');

if (empty($items) || $motivo === '' || $idSucursal <= 0) {
    header("Location: inventario_retiros.php?msg=err&errdetail=" . urlencode("Datos incompletos.")); exit();
}

// Validar que idSucursal sea realmente Eulalia
$chk = $conn->prepare("SELECT id FROM sucursales WHERE id=? AND nombre='Eulalia' LIMIT 1");
$chk->bind_param("i", $idSucursal);
$chk->execute();
if ($chk->get_result()->num_rows === 0) {
    header("Location: inventario_retiros.php?msg=err&errdetail=" . urlencode("Operación restringida a Eulalia.")); exit();
}
$chk->close();

$folio = sprintf("RIT-%s-%d", date('Ymd-His'), $idUsuario);

$conn->begin_transaction();
try {
    $stmt = $conn->prepare("INSERT INTO inventario_retiros (folio, id_usuario, id_sucursal, motivo, destino, nota) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("siisss", $folio, $idUsuario, $idSucursal, $motivo, $destino, $nota);
    $stmt->execute();
    $retiroId = $stmt->insert_id;
    $stmt->close();

    $qCheck = $conn->prepare("
        SELECT inv.id AS id_inventario, inv.id_sucursal, inv.id_producto, inv.estatus, p.imei1
        FROM inventario inv
        INNER JOIN productos p ON p.id = inv.id_producto
        WHERE inv.id = ?
        LIMIT 1
    ");
    $qUpdate = $conn->prepare("UPDATE inventario SET estatus = 'Retirado' WHERE id = ?");
    $qDet    = $conn->prepare("INSERT INTO inventario_retiros_detalle (retiro_id, id_inventario, id_producto, imei1) VALUES (?, ?, ?, ?)");

    foreach ($items as $invId) {
        $invId = (int)$invId;
        $qCheck->bind_param("i", $invId);
        $qCheck->execute();
        $res = $qCheck->get_result();
        if ($res->num_rows === 0) throw new Exception("Inventario $invId no existe.");
        $row = $res->fetch_assoc();

        if ($row['estatus'] !== 'Disponible') throw new Exception("Inventario {$row['id_inventario']} no está Disponible.");
        if ((int)$row['id_sucursal'] !== $idSucursal) throw new Exception("Inventario {$row['id_inventario']} no pertenece a Eulalia.");

        $qUpdate->bind_param("i", $invId);
        $qUpdate->execute();
        if ($qUpdate->affected_rows === 0) throw new Exception("No se pudo actualizar inventario $invId.");

        $qDet->bind_param("iiis", $retiroId, $invId, $row['id_producto'], $row['imei1']);
        $qDet->execute();
    }

    $qCheck->close(); $qUpdate->close(); $qDet->close();
    $conn->commit();
    header("Location: inventario_retiros.php?msg=ok"); exit();

} catch (Exception $e) {
    $conn->rollback();
    header("Location: inventario_retiros.php?msg=err&errdetail=".urlencode($e->getMessage())); exit();
}
