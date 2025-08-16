<?php
// revertir_retiro.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'Admin') {
    header("Location: 403.php"); exit();
}
include 'db.php';

$idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
$idRetiro  = (int)($_POST['id_retiro'] ?? 0);
$idSucursal= (int)($_POST['id_sucursal'] ?? 0); // Debe ser Eulalia
$notaRev   = trim($_POST['nota_reversion'] ?? '');

if ($idRetiro <= 0 || $idSucursal <= 0) {
    header("Location: inventario_retiros.php?msg=err&errdetail=".urlencode("Datos incompletos.")); exit();
}

// Validar sucursal Eulalia
$chkE = $conn->prepare("SELECT id FROM sucursales WHERE id=? AND nombre='Eulalia' LIMIT 1");
$chkE->bind_param("i", $idSucursal);
$chkE->execute();
if ($chkE->get_result()->num_rows === 0) {
    header("Location: inventario_retiros.php?msg=err&errdetail=".urlencode("Operación restringida a Eulalia.")); exit();
}
$chkE->close();

// Validar retiro
$stmt = $conn->prepare("SELECT id, id_sucursal, revertido FROM inventario_retiros WHERE id=? LIMIT 1");
$stmt->bind_param("i", $idRetiro);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) {
    header("Location: inventario_retiros.php?msg=err&errdetail=".urlencode("Retiro no encontrado.")); exit();
}
$info = $res->fetch_assoc();
$stmt->close();

if ((int)$info['id_sucursal'] !== $idSucursal) {
    header("Location: inventario_retiros.php?msg=err&errdetail=".urlencode("El retiro no pertenece a Eulalia.")); exit();
}
if ((int)$info['revertido'] === 1) {
    header("Location: inventario_retiros.php?msg=err&errdetail=".urlencode("El retiro ya fue revertido.")); exit();
}

$conn->begin_transaction();
try {
    // Restaurar a Disponible los que sigan en Retirado
    $qIds = $conn->prepare("SELECT id_inventario FROM inventario_retiros_detalle WHERE retiro_id = ?");
    $qIds->bind_param("i", $idRetiro);
    $qIds->execute();
    $ids = $qIds->get_result()->fetch_all(MYSQLI_ASSOC);
    $qIds->close();

    $qCheck = $conn->prepare("SELECT estatus FROM inventario WHERE id = ? AND id_sucursal = ? LIMIT 1");
    $qUpd   = $conn->prepare("UPDATE inventario SET estatus='Disponible' WHERE id=? AND estatus='Retirado'");

    foreach ($ids as $r) {
        $invId = (int)$r['id_inventario'];
        $qCheck->bind_param("ii", $invId, $idSucursal);
        $qCheck->execute();
        $rs = $qCheck->get_result();
        if ($rs->num_rows === 0) continue; // ignorar inconsistencias
        $estado = $rs->fetch_assoc()['estatus'];
        if ($estado === 'Retirado') {
            $qUpd->bind_param("i", $invId);
            $qUpd->execute();
        }
    }
    $qCheck->close(); $qUpd->close();

    // Marcar cabecera como revertida
    $u = $conn->prepare("UPDATE inventario_retiros 
                         SET revertido=1, fecha_reversion=NOW(), usuario_revirtio=?, nota_reversion=? 
                         WHERE id=?");
    $u->bind_param("isi", $idUsuario, $notaRev, $idRetiro);
    $u->execute();
    $u->close();

    $conn->commit();
    header("Location: inventario_retiros.php?msg=revok"); exit();

} catch (Exception $e) {
    $conn->rollback();
    header("Location: inventario_retiros.php?msg=err&errdetail=".urlencode($e->getMessage())); exit();
}
