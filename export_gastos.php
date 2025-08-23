<?php
// export_gastos.php — CSV de gastos_sucursal
session_start();
if (!isset($_SESSION['id_usuario'])) { http_response_code(403); exit('No autorizado'); }

require_once __DIR__ . '/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

header('Content-Type: text/csv; charset=UTF-8');
$fname = 'gastos_' . date('Ymd_His') . '.csv';
header("Content-Disposition: attachment; filename=\"$fname\"");
echo "\xEF\xBB\xBF"; // BOM UTF-8

// Filtros
$sucursal_id = (int)($_GET['sucursal_id'] ?? 0);
$desde       = trim($_GET['desde'] ?? '');
$hasta       = trim($_GET['hasta'] ?? '');
$semana      = trim($_GET['semana'] ?? '');
$id_corte    = (int)($_GET['id_corte'] ?? 0);

// Semana ISO → desde/hasta (si viene, sobreescribe)
if ($semana && preg_match('/^(\d{4})-W(\d{2})$/', $semana, $m)) {
  $yr = (int)$m[1]; $wk = (int)$m[2];
  $dt = new DateTime(); $dt->setISODate($yr, $wk); $desde = $dt->format('Y-m-d');
  $dt->modify('+6 days'); $hasta = $dt->format('Y-m-d');
}

// Query
$sql = "
  SELECT 
    gs.id,
    s.nombre AS sucursal,
    gs.id_sucursal,
    gs.id_usuario,
    gs.fecha_gasto,
    gs.categoria,
    gs.concepto,
    gs.monto,
    gs.observaciones,
    gs.id_corte
  FROM gastos_sucursal gs
  LEFT JOIN sucursales s ON s.id = gs.id_sucursal
  WHERE 1=1
";
$types=''; $params=[];
if ($sucursal_id > 0) { $sql .= " AND gs.id_sucursal=? ";       $types.='i'; $params[]=$sucursal_id; }
if ($id_corte > 0)    { $sql .= " AND gs.id_corte=? ";          $types.='i'; $params[]=$id_corte; }
if ($desde !== '')    { $sql .= " AND DATE(gs.fecha_gasto)>=? ";$types.='s'; $params[]=$desde; }
if ($hasta !== '')    { $sql .= " AND DATE(gs.fecha_gasto)<=? ";$types.='s'; $params[]=$hasta; }
$sql .= " ORDER BY gs.fecha_gasto ASC, gs.id ASC";

$st = $conn->prepare($sql);
if ($types) { $st->bind_param($types, ...$params); }
$st->execute();
$res = $st->get_result();

// CSV header
$cols = ['ID','Sucursal','ID Sucursal','ID Usuario','Fecha Gasto','Categoría','Concepto','Monto','Observaciones','ID Corte'];
$out = fopen('php://output', 'w');
fputcsv($out, $cols);

while ($r = $res->fetch_assoc()) {
  fputcsv($out, [
    (int)$r['id'],
    $r['sucursal'] ?? '',
    (int)$r['id_sucursal'],
    (int)$r['id_usuario'],
    $r['fecha_gasto'],
    $r['categoria'],
    $r['concepto'],
    number_format((float)$r['monto'], 2, '.', ''),
    $r['observaciones'],
    (int)$r['id_corte'],
  ]);
}
fclose($out);
exit;
