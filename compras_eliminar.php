<?php
// compras_eliminar.php — Eliminación segura de facturas de compra (temporal)
// Reglas: Solo Admin. Solo si NO tiene pagos y NO tiene ingresos registrados.

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

$ROL = $_SESSION['rol'] ?? 'Ejecutivo';
if ($ROL !== 'Admin') {
  header("Location: compras_resumen.php?msg=" . urlencode('Solo Admin puede eliminar facturas.')); exit();
}

require_once __DIR__ . '/db.php';

$id   = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$csrf = $_POST['csrf'] ?? '';

if (!$id || !hash_equals($_SESSION['csrf'] ?? '', $csrf)) {
  header("Location: compras_resumen.php?msg=" . urlencode('Solicitud inválida.')); exit();
}

// 1) Validar existencias, pagos e ingresos
$stmt = $conn->prepare("
  SELECT
    c.id,
    c.num_factura,
    IFNULL(pg.pagados, 0)   AS pagos,
    IFNULL(ing.ingresos, 0) AS ingresos
  FROM compras c
  LEFT JOIN (
    SELECT id_compra, COUNT(*) AS pagados
    FROM compras_pagos
    WHERE id_compra = ?
  ) pg ON pg.id_compra = c.id
  LEFT JOIN (
    SELECT d.id_compra, COUNT(i.id) AS ingresos
    FROM compras_detalle d
    LEFT JOIN compras_detalle_ingresos i ON i.id_detalle = d.id
    WHERE d.id_compra = ?
  ) ing ON ing.id_compra = c.id
  WHERE c.id = ?
  LIMIT 1
");
$stmt->bind_param("iii", $id, $id, $id);
$stmt->execute();
$info = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$info) {
  header("Location: compras_resumen.php?msg=" . urlencode('Factura no encontrada.')); exit();
}
if ((int)$info['pagos'] > 0) {
  header("Location: compras_resumen.php?msg=" . urlencode('No se puede eliminar: la factura tiene pagos.')); exit();
}
if ((int)$info['ingresos'] > 0) {
  header("Location: compras_resumen.php?msg=" . urlencode('No se puede eliminar: la factura tiene ingresos en inventario.')); exit();
}

// 2) Eliminar en transacción (orden cuidadoso por FKs)
$conn->begin_transaction();
try {
  // Borrar ingresos ligados (defensivo; deberían ser 0 por la validación)
  $sql = "DELETE i FROM compras_detalle_ingresos i
          INNER JOIN compras_detalle d ON d.id = i.id_detalle
          WHERE d.id_compra = ?";
  $st = $conn->prepare($sql);
  $st->bind_param("i", $id);
  $st->execute();
  $st->close();

  // Borrar detalle
  $st = $conn->prepare("DELETE FROM compras_detalle WHERE id_compra = ?");
  $st->bind_param("i", $id);
  $st->execute();
  $st->close();

  // Borrar pagos (defensivo; deberían ser 0)
  $st = $conn->prepare("DELETE FROM compras_pagos WHERE id_compra = ?");
  $st->bind_param("i", $id);
  $st->execute();
  $st->close();

  // Borrar cabecera
  $st = $conn->prepare("DELETE FROM compras WHERE id = ?");
  $st->bind_param("i", $id);
  $st->execute();
  $st->close();

  $conn->commit();
  $msg = 'Factura eliminada correctamente.';
} catch (Throwable $e) {
  $conn->rollback();
  $msg = 'Error al eliminar: ' . $e->getMessage();
}

header("Location: compras_resumen.php?msg=" . urlencode($msg));
exit();
