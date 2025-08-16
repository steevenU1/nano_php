<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php"); exit();
}
include 'db.php';

// ===== Parámetros =====
$fFecha       = $_GET['fecha']        ?? date('Y-m-d', strtotime('-1 day'));
$fSucursal    = $_GET['sucursal']     ?? '';
$fImei        = $_GET['imei']         ?? '';
$fTipo        = $_GET['tipo_producto']?? '';
$fEstatus     = $_GET['estatus']      ?? '';
$fAntiguedad  = $_GET['antiguedad']   ?? '';
$fPrecioMin   = $_GET['precio_min']   ?? '';
$fPrecioMax   = $_GET['precio_max']   ?? '';

/* ===== Consulta ===== */
$sql = "
SELECT
  id_inventario, id_sucursal, sucursal_nombre, codigo_producto,
  marca, modelo, color, capacidad, imei1, imei2,
  tipo_producto, proveedor,
  costo_con_iva, precio_lista, profit,
  estatus, fecha_ingreso, antiguedad_dias
FROM inventario_snapshot
WHERE snapshot_date = ?
";
$params = [$fFecha];
$types  = "s";

if ($fSucursal !== '') { $sql .= " AND id_sucursal = ?"; $params[] = (int)$fSucursal; $types .= "i"; }
if ($fImei !== '')     { $sql .= " AND (imei1 LIKE ? OR imei2 LIKE ?)"; $like = "%$fImei%"; $params[]=$like; $params[]=$like; $types.="ss"; }
if ($fTipo !== '')     { $sql .= " AND tipo_producto = ?"; $params[] = $fTipo; $types .= "s"; }
if ($fEstatus !== '')  { $sql .= " AND estatus = ?"; $params[] = $fEstatus; $types .= "s"; }
if ($fAntiguedad === '<30') { $sql .= " AND antiguedad_dias < 30"; }
elseif ($fAntiguedad === '30-90') { $sql .= " AND antiguedad_dias BETWEEN 30 AND 90"; }
elseif ($fAntiguedad === '>90') { $sql .= " AND antiguedad_dias > 90"; }
if ($fPrecioMin !== '') { $sql .= " AND precio_lista >= ?"; $params[] = (float)$fPrecioMin; $types .= "d"; }
if ($fPrecioMax !== '') { $sql .= " AND precio_lista <= ?"; $params[] = (float)$fPrecioMax; $types .= "d"; }

$sql .= " ORDER BY sucursal_nombre, fecha_ingreso DESC";

$stmt = $conn->prepare($sql);
if (!$stmt) die("Error: ".$conn->error);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

/* ===== Cabeceras de Excel ===== */
header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=inventario_historico_{$fFecha}.xls");
header("Pragma: no-cache");
header("Expires: 0");
echo "\xEF\xBB\xBF"; // BOM UTF-8

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function nf($n){ return number_format((float)$n, 2, '.', ''); }

/* ===== Render ===== */
echo "<html><head><meta charset='UTF-8'></head><body>";
echo "<table border='1' cellspacing='0' cellpadding='4'>";
echo "<tr style='background:#222;color:#fff;font-weight:bold'>"
    ."<td>ID Inv</td>"
    ."<td>ID Sucursal</td>"
    ."<td>Sucursal</td>"
    ."<td>Código</td>"
    ."<td>Marca</td>"
    ."<td>Modelo</td>"
    ."<td>Color</td>"
    ."<td>Capacidad</td>"
    ."<td>IMEI1</td>"
    ."<td>IMEI2</td>"
    ."<td>Tipo</td>"
    ."<td>Proveedor</td>"
    ."<td>Costo c/IVA</td>"
    ."<td>Precio Lista</td>"
    ."<td>Profit</td>"
    ."<td>Estatus</td>"
    ."<td>Fecha Ingreso</td>"
    ."<td>Antigüedad (días)</td>"
    ."</tr>";

while ($r = $res->fetch_assoc()) {
  echo "<tr>"
     ."<td>".h($r['id_inventario'])."</td>"
     ."<td>".h($r['id_sucursal'])."</td>"
     ."<td>".h($r['sucursal_nombre'])."</td>"
     ."<td>".h($r['codigo_producto'] ?? '-')."</td>"
     ."<td>".h($r['marca'])."</td>"
     ."<td>".h($r['modelo'])."</td>"
     ."<td>".h($r['color'])."</td>"
     ."<td>".h($r['capacidad'] ?? '-')."</td>"
     ."<td>'".h($r['imei1'] ?? '-')."</td>"  /* forzar texto en Excel */
     ."<td>'".h($r['imei2'] ?? '-')."</td>"
     ."<td>".h($r['tipo_producto'])."</td>"
     ."<td>".h($r['proveedor'] ?? '-')."</td>"
     ."<td>".nf($r['costo_con_iva'])."</td>"
     ."<td>".nf($r['precio_lista'])."</td>"
     ."<td>".nf($r['profit'])."</td>"
     ."<td>".h($r['estatus'])."</td>"
     ."<td>".h($r['fecha_ingreso'])."</td>"
     ."<td>".h($r['antiguedad_dias'])."</td>"
     ."</tr>";
}
echo "</table></body></html>";

$stmt->close();
$conn->close();
