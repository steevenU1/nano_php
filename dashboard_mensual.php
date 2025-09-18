<?php
session_start();
if (empty($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

/* -------------------------------
   Utilidades
---------------------------------*/
if (!function_exists('nombreMes')) {
    function nombreMes($mes) {
        $meses = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
        return $meses[$mes] ?? '';
    }
}
function badgeFila($pct) {
    if ($pct === null) return '';
    return $pct>=100 ? 'table-success' : ($pct>=60 ? 'table-warning' : 'table-danger');
}
/* Quita prefijos del nombre de sucursal solo para visualizar */
function limpiarNombreSucursal(string $n): string {
    // Elimina "NANORED" o "Bait" al inicio, con espacios y sin importar may/min
    $t = preg_replace('/^\s*(?:NANORED|Bait)\s*/i', '', $n);
    return trim($t);
}

/* -------------------------------
   Mes/Año seleccionados y rango
---------------------------------*/
$mes  = isset($_GET['mes'])  ? (int)$_GET['mes']  : (int)date('n');
$anio = isset($_GET['anio']) ? (int)$_GET['anio'] : (int)date('Y');

$inicioMes = sprintf('%04d-%02d-01', $anio, $mes);
$finMes    = date('Y-m-t', strtotime($inicioMes));

/* -------------------------------
   Toggles gráfico
   g: (propias | ma)
   m: (monto | unidades)
---------------------------------*/
$g = $_GET['g'] ?? 'propias';
if ($g!=='propias' && $g!=='ma') $g='propias';
$m = $_GET['m'] ?? 'monto';
if ($m!=='monto' && $m!=='unidades') $m='monto';

/* ======================================================
   0) Cuota mensual ejecutivos (POR EJECUTIVO)
====================================================== */
$cuotaMesU_porEj = 0.0;
$cuotaMesM_porEj = 0.0;
$qe = $conn->prepare("
    SELECT cuota_unidades, cuota_monto
    FROM cuotas_mensuales_ejecutivos
    WHERE anio=? AND mes=?
    ORDER BY id DESC LIMIT 1
");
$qe->bind_param("ii", $anio, $mes);
$qe->execute();
if ($rowQ = $qe->get_result()->fetch_assoc()) {
    $cuotaMesU_porEj = (float)$rowQ['cuota_unidades'];
    $cuotaMesM_porEj = (float)$rowQ['cuota_monto'];
}
$qe->close();

/* ======================================================
   1) Sucursales PROPIAS: ventas, unidades, cuotas + SIMs
====================================================== */
$cuotasSuc = [];
$q = $conn->prepare("SELECT id_sucursal, cuota_unidades, cuota_monto FROM cuotas_mensuales WHERE anio=? AND mes=?");
$q->bind_param("ii", $anio, $mes);
$q->execute();
$r = $q->get_result();
while ($row = $r->fetch_assoc()) {
    $cuotasSuc[(int)$row['id_sucursal']] = [
        'cuota_unidades' => (int)$row['cuota_unidades'],
        'cuota_monto'    => (float)$row['cuota_monto']
    ];
}
$q->close();

$sqlSucPropias = "
    SELECT s.id AS id_sucursal, s.nombre AS sucursal,
           s.subtipo AS tipo,
           IFNULL(SUM(
                CASE 
                    WHEN dv.id IS NULL THEN 0
                    WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0
                    WHEN REPLACE(LOWER(v.tipo_venta),' ','')='financiamiento+combo' THEN
                        CASE 
                            WHEN dv.id = (SELECT MIN(dv2.id) FROM detalle_venta dv2 WHERE dv2.id_venta=v.id) THEN 2
                            ELSE 0
                        END
                    ELSE 1
                END
           ),0) AS unidades,
           IFNULL(SUM(
                CASE
                    WHEN v.id IS NULL THEN 0
                    WHEN dv.id IS NULL THEN 0
                    WHEN dv.id = (SELECT MIN(dv3.id) FROM detalle_venta dv3 WHERE dv3.id_venta = v.id)
                        THEN IFNULL(v.precio_venta,0)
                    ELSE 0
                END
           ),0) AS ventas,
           (SELECT COUNT(*) FROM ventas_sims vs
             WHERE vs.id_sucursal = s.id
               AND DATE(CONVERT_TZ(vs.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
               AND LOWER(vs.tipo_venta) LIKE '%pospago%') AS sims_pospago,
           (SELECT COUNT(*) FROM ventas_sims vs
             WHERE vs.id_sucursal = s.id
               AND DATE(CONVERT_TZ(vs.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
               AND LOWER(vs.tipo_venta) NOT LIKE '%pospago%'
               AND LOWER(vs.tipo_venta) NOT LIKE '%regalo%') AS sims_prepago
    FROM sucursales s
    LEFT JOIN ventas v 
        ON v.id_sucursal = s.id 
       AND DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
    LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
    LEFT JOIN productos p ON p.id = dv.id_producto
    WHERE s.tipo_sucursal='Tienda' AND s.subtipo = 'Propia'
    GROUP BY s.id
    ORDER BY ventas DESC
";
$stmt = $conn->prepare($sqlSucPropias);
$stmt->bind_param("ssssss", $inicioMes, $finMes, $inicioMes, $finMes, $inicioMes, $finMes);
$stmt->execute();
$res = $stmt->get_result();

$sucursalesPropias = [];
$totalGlobalUnidades   = 0;
$totalGlobalVentas     = 0;
$totalGlobalCuota      = 0;
$totalGlobalCuotaUnid  = 0;
$totalGlobalPrepago    = 0;
$totalGlobalPospago    = 0;

while ($row = $res->fetch_assoc()) {
    $id_suc = (int)$row['id_sucursal'];
    $cuotaUnidades = $cuotasSuc[$id_suc]['cuota_unidades'] ?? 0;
    $cuotaMonto    = $cuotasSuc[$id_suc]['cuota_monto']    ?? 0;
    $cumpl = $cuotaMonto > 0 ? ((float)$row['ventas']/$cuotaMonto*100) : 0;

    $sucursalesPropias[] = [
        'id_sucursal'     => $id_suc,
        'sucursal'        => limpiarNombreSucursal($row['sucursal']),
        'tipo'            => $row['tipo'],
        'unidades'        => (int)$row['unidades'],
        'ventas'          => (float)$row['ventas'],
        'sims_prepago'    => (int)$row['sims_prepago'],
        'sims_pospago'    => (int)$row['sims_pospago'],
        'cuota_unidades'  => (int)$cuotaUnidades,
        'cuota_monto'     => (float)$cuotaMonto,
        'cumplimiento'    => $cumpl
    ];

    $totalGlobalUnidades  += (int)$row['unidades'];
    $totalGlobalVentas    += (float)$row['ventas'];
    $totalGlobalCuota     += (float)$cuotaMonto;
    $totalGlobalCuotaUnid += (int)$cuotaUnidades;
    $totalGlobalPrepago   += (int)$row['sims_prepago'];
    $totalGlobalPospago   += (int)$row['sims_pospago'];
}
$stmt->close();

/* ======================================================
   3) Ejecutivos (Propias) + SIMs
====================================================== */
$sqlEj = "
    SELECT 
        u.id, u.nombre, s.nombre AS sucursal,
        IFNULL(SUM(
            CASE 
                WHEN dv.id IS NULL THEN 0
                WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0
                WHEN REPLACE(LOWER(v.tipo_venta),' ','')='financiamiento+combo' THEN
                    CASE 
                        WHEN dv.id = (SELECT MIN(dv2.id) FROM detalle_venta dv2 WHERE dv2.id_venta=v.id) THEN 2
                        ELSE 0
                    END
                ELSE 1
            END
        ),0) AS unidades,
        IFNULL(SUM(
            CASE 
                WHEN v.id IS NULL THEN 0
                WHEN dv.id IS NULL THEN 0
                WHEN dv.id = (SELECT MIN(dv3.id) FROM detalle_venta dv3 WHERE dv3.id_venta = v.id)
                    THEN IFNULL(v.precio_venta,0)
                ELSE 0
            END
        ),0) AS ventas,
        (SELECT COUNT(*) FROM ventas_sims vs
          WHERE vs.id_usuario = u.id
            AND DATE(CONVERT_TZ(vs.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
            AND LOWER(vs.tipo_venta) LIKE '%pospago%') AS sims_pospago,
        (SELECT COUNT(*) FROM ventas_sims vs
          WHERE vs.id_usuario = u.id
            AND DATE(CONVERT_TZ(vs.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
            AND LOWER(vs.tipo_venta) NOT LIKE '%pospago%'
            AND LOWER(vs.tipo_venta) NOT LIKE '%regalo%') AS sims_prepago
    FROM usuarios u
    INNER JOIN sucursales s ON s.id = u.id_sucursal
    LEFT JOIN ventas v 
        ON v.id_usuario = u.id
       AND DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
    LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
    LEFT JOIN productos p ON p.id = dv.id_producto
    WHERE u.activo = 1 AND u.rol='Ejecutivo' AND s.subtipo='Propia'
    GROUP BY u.id
    ORDER BY unidades DESC, ventas DESC
";
$stEj = $conn->prepare($sqlEj);
$stEj->bind_param("ssssss", $inicioMes, $finMes, $inicioMes, $finMes, $inicioMes, $finMes);
$stEj->execute();
$resEj = $stEj->get_result();

$ejecutivos = [];
// Totales ejecutivos
$totalEjUnidades = 0;
$totalEjVentas   = 0.0;
$totalEjPrep     = 0;
$totalEjPos      = 0;
$totalEjCuota    = 0.0;

while ($row = $resEj->fetch_assoc()) {
    $cumpl_uni = $cuotaMesU_porEj>0 ? ((int)$row['unidades']/$cuotaMesU_porEj*100) : null;
    $ej = [
        'id'             => (int)$row['id'],
        'nombre'         => $row['nombre'],
        'sucursal'       => limpiarNombreSucursal($row['sucursal']),
        'unidades'       => (int)$row['unidades'],
        'ventas'         => (float)$row['ventas'],
        'sims_prepago'   => (int)$row['sims_prepago'],
        'sims_pospago'   => (int)$row['sims_pospago'],
        'cuota_unidades' => $cuotaMesU_porEj,
        'cumpl_uni'      => $cumpl_uni,
    ];
    $ejecutivos[]   = $ej;
    // acumular
    $totalEjUnidades += $ej['unidades'];
    $totalEjVentas   += $ej['ventas'];
    $totalEjPrep     += $ej['sims_prepago'];
    $totalEjPos      += $ej['sims_pospago'];
    $totalEjCuota    += $ej['cuota_unidades'];
}
$stEj->close();
$cumplTotEj = $totalEjCuota>0 ? ($totalEjUnidades/$totalEjCuota*100) : 0;

/* ======================================================
   5) Master Admin — SIN CUOTA + SIMs
====================================================== */
$sqlSucMA = "
    SELECT 
        s.id   AS id_sucursal, s.nombre AS sucursal, s.subtipo AS tipo,
        IFNULL(SUM(
            CASE 
                WHEN v.id IS NULL THEN 0
                WHEN v.origen_ma='nano' THEN
                    CASE 
                        WHEN dv.id IS NULL THEN 
                            CASE WHEN REPLACE(LOWER(v.tipo_venta),' ','')='financiamiento+combo' THEN 2 ELSE 1 END
                        WHEN dv.id = (SELECT MIN(dv2.id) FROM detalle_venta dv2 WHERE dv2.id_venta = v.id) THEN
                            CASE WHEN REPLACE(LOWER(v.tipo_venta),' ','')='financiamiento+combo' THEN 2 ELSE 1 END
                        ELSE 0
                    END
                ELSE
                    CASE 
                        WHEN dv.id IS NULL THEN 0
                        WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0
                        ELSE 1
                    END
            END
        ),0) AS unidades,
        IFNULL(SUM(
            CASE 
                WHEN v.id IS NULL THEN 0
                WHEN dv.id IS NULL THEN 0
                WHEN dv.id = (SELECT MIN(dv3.id) FROM detalle_venta dv3 WHERE dv3.id_venta = v.id)
                    THEN IFNULL(v.precio_venta,0)
                ELSE 0
            END
        ),0) AS ventas,
        (SELECT COUNT(*) FROM ventas_sims vs
          WHERE vs.id_sucursal = s.id
            AND DATE(CONVERT_TZ(vs.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
            AND LOWER(vs.tipo_venta) LIKE '%pospago%') AS sims_pospago,
        (SELECT COUNT(*) FROM ventas_sims vs
          WHERE vs.id_sucursal = s.id
            AND DATE(CONVERT_TZ(vs.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
            AND LOWER(vs.tipo_venta) NOT LIKE '%pospago%'
            AND LOWER(vs.tipo_venta) NOT LIKE '%regalo%') AS sims_prepago
    FROM sucursales s
    LEFT JOIN ventas v
           ON v.id_sucursal = s.id
          AND DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
    LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
    LEFT JOIN productos p      ON p.id       = dv.id_producto
    WHERE s.tipo_sucursal='Tienda' AND s.subtipo='Master Admin'
    GROUP BY s.id
    ORDER BY ventas DESC
";
$stMA = $conn->prepare($sqlSucMA);
$stMA->bind_param("ssssss", $inicioMes, $finMes, $inicioMes, $finMes, $inicioMes, $finMes);
$stMA->execute();
$resMA = $stMA->get_result();

$sucursalesMA = [];
$totalMAUnidades = 0;
$totalMAVentas   = 0.0;
$totalMAPrepago  = 0;
$totalMAPospago  = 0;

while ($row = $resMA->fetch_assoc()) {
    $sucursalesMA[] = [
        'id_sucursal' => (int)$row['id_sucursal'],
        'sucursal'    => limpiarNombreSucursal($row['sucursal']),
        'tipo'        => $row['tipo'],
        'unidades'    => (int)$row['unidades'],
        'ventas'      => (float)$row['ventas'],
        'sims_prepago'=> (int)$row['sims_prepago'],
        'sims_pospago'=> (int)$row['sims_pospago'],
    ];
    $totalMAUnidades += (int)$row['unidades'];
    $totalMAVentas   += (float)$row['ventas'];
    $totalMAPrepago  += (int)$row['sims_prepago'];
    $totalMAPospago  += (int)$row['sims_pospago'];
}
$stMA->close();

/* ======================================================
   6) Gráfico MENSUAL con switches (grupo + métrica)
====================================================== */
if ($g === 'propias') {
    if ($m === 'monto') {
        $sqlChart = "
          SELECT s.nombre AS sucursal,
                 SUM(
                   CASE
                     WHEN v.id IS NULL THEN 0
                     WHEN dv.id IS NULL THEN 0
                     WHEN dv.id = (SELECT MIN(dv2.id) FROM detalle_venta dv2 WHERE dv2.id_venta=v.id)
                          THEN IFNULL(v.precio_venta,0)
                     ELSE 0
                   END
                 ) AS valor
          FROM sucursales s
          LEFT JOIN ventas v
            ON v.id_sucursal = s.id
           AND DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
          LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
          WHERE s.tipo_sucursal='Tienda' AND s.subtipo='Propia'
          GROUP BY s.id
        ";
    } else {
        $sqlChart = "
          SELECT s.nombre AS sucursal,
                 SUM(
                   CASE 
                     WHEN dv.id IS NULL THEN 0
                     WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0
                     WHEN REPLACE(LOWER(v.tipo_venta),' ','')='financiamiento+combo' THEN
                       CASE WHEN dv.id = (SELECT MIN(dv2.id) FROM detalle_venta dv2 WHERE dv2.id_venta = v.id) THEN 2 ELSE 0 END
                     ELSE 1
                   END
                 ) AS valor
          FROM sucursales s
          LEFT JOIN ventas v
            ON v.id_sucursal = s.id
           AND DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
          LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
          LEFT JOIN productos p ON p.id = dv.id_producto
          WHERE s.tipo_sucursal='Tienda' AND s.subtipo='Propia'
          GROUP BY s.id
        ";
    }
} else {
    if ($m === 'monto') {
        $sqlChart = "
          SELECT s.nombre AS sucursal,
                 SUM(
                   CASE
                     WHEN v.id IS NULL THEN 0
                     WHEN dv.id IS NULL THEN 0
                     WHEN dv.id = (SELECT MIN(dv3.id) FROM detalle_venta dv3 WHERE dv3.id_venta=v.id)
                          THEN IFNULL(v.precio_venta,0)
                     ELSE 0
                   END
                 ) AS valor
          FROM sucursales s
          LEFT JOIN ventas v
            ON v.id_sucursal = s.id
           AND DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
          LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
          WHERE s.tipo_sucursal='Tienda' AND s.subtipo='Master Admin'
          GROUP BY s.id
        ";
    } else {
        $sqlChart = "
          SELECT s.nombre AS sucursal,
                 SUM(
                   CASE 
                     WHEN v.id IS NULL THEN 0
                     WHEN v.origen_ma='nano' THEN
                        CASE 
                           WHEN dv.id IS NULL THEN 
                               CASE WHEN REPLACE(LOWER(v.tipo_venta),' ','')='financiamiento+combo' THEN 2 ELSE 1 END
                           WHEN dv.id = (SELECT MIN(dv2.id) FROM detalle_venta dv2 WHERE dv2.id_venta = v.id) THEN
                               CASE WHEN REPLACE(LOWER(v.tipo_venta),' ','')='financiamiento+combo' THEN 2 ELSE 1 END
                           ELSE 0
                        END
                     ELSE
                        CASE 
                           WHEN dv.id IS NULL THEN 0
                           WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0
                           ELSE 1
                        END
                   END
                 ) AS valor
          FROM sucursales s
          LEFT JOIN ventas v
            ON v.id_sucursal = s.id
           AND DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
          LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
          LEFT JOIN productos p      ON p.id       = dv.id_producto
          WHERE s.tipo_sucursal='Tienda' AND s.subtipo='Master Admin'
          GROUP BY s.id
        ";
    }
}

$stmtC = $conn->prepare($sqlChart);
$stmtC->bind_param("ss", $inicioMes, $finMes);
$stmtC->execute();
$resChart = $stmtC->get_result();

$rowsChart = [];
while ($r = $resChart->fetch_assoc()) {
    $rowsChart[] = [
        'sucursal' => limpiarNombreSucursal($r['sucursal']),
        'valor'    => (float)$r['valor']
    ];
}
usort($rowsChart, fn($a,$b) => $b['valor'] <=> $a['valor']);
$top = array_slice($rowsChart, 0, 15);
$otrasVal = 0;
for ($i=15; $i<count($rowsChart); $i++) { $otrasVal += $rowsChart[$i]['valor']; }
$labelsGraf = array_map(fn($r)=>$r['sucursal'],$top);
$dataGraf   = array_map(fn($r)=>round($r['valor'],2),$top);
if ($otrasVal>0) { $labelsGraf[]='Otras'; $dataGraf[]=round($otrasVal,2); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Dashboard Mensual - Nano</title>
  <meta name="viewport" content="width=device-width, initial-scale=1"> <!-- móvil -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <style>
    .progress{height:18px}
    .progress-bar{font-size:.75rem}
    .tab-pane{padding-top:10px}
    /* Toques móviles */
    .navbar { box-shadow: 0 2px 12px rgba(0,0,0,.06); }
    @media (max-width: 576px){
      .container { padding-left: .6rem; padding-right: .6rem; }
      h2 { font-size: 1.15rem; }
      .card .card-header { padding: .5rem .75rem; font-size: .9rem; }
      .card .card-body { padding: .75rem; }
      .nav-tabs { overflow-x: auto; overflow-y: hidden; white-space: nowrap; flex-wrap: nowrap; }
      .nav-tabs .nav-link { padding: .5rem .75rem; }
      .chart-wrap { height: 240px; }
      .sticky-mobile-bar {
        position: sticky; top: 0; z-index: 1030; background: #fff; padding:.5rem .75rem;
        border-bottom: 1px solid rgba(0,0,0,.06);
      }
      .form-select, .btn { font-size: .9rem; }
      .table th, .table td { padding: .45rem .5rem; font-size: .86rem; }
    }
    .btn-download{
      border: 1px solid rgba(255,255,255,.6);
      background: rgba(255,255,255,.05);
      backdrop-filter: blur(4px);
    }
    .btn-download svg{ width:16px; height:16px; vertical-align: -3px;}
    tfoot tr { font-weight: 700; }
  </style>
</head>
<body class="bg-light">

<div class="container mt-3">

  <!-- Sticky filtros en móvil -->
  <div class="sticky-mobile-bar rounded-3 shadow-sm d-md-none mb-3">
    <form method="GET" class="row g-2 align-items-center">
      <div class="col-6">
        <select name="mes" class="form-select form-select-sm">
          <?php for ($mSel=1;$mSel<=12;$mSel++): ?>
            <option value="<?= $mSel ?>" <?= $mSel==$mes?'selected':'' ?>><?= nombreMes($mSel) ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-4">
        <select name="anio" class="form-select form-select-sm">
          <?php for ($a=date('Y')-1;$a<=date('Y')+1;$a++): ?>
            <option value="<?= $a ?>" <?= $a==$anio?'selected':'' ?>><?= $a ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-2">
        <button class="btn btn-primary btn-sm w-100">Ir</button>
      </div>
      <input type="hidden" name="g" value="<?= htmlspecialchars($g) ?>">
      <input type="hidden" name="m" value="<?= htmlspecialchars($m) ?>">
    </form>
  </div>

  <h2 class="d-none d-md-block">📊 Dashboard Mensual - Nano — <?= nombreMes($mes)." $anio" ?></h2>

  <!-- Tarjetas superiores -->
  <div class="row mb-3 g-3">
    <div class="col-12 col-md-4">
      <div class="card shadow text-center h-100">
        <div class="card-header bg-dark text-white">Propias</div>
        <div class="card-body">
          <?php $pctPropias = $totalGlobalCuota>0 ? ($totalGlobalVentas/$totalGlobalCuota*100) : 0; ?>
          <h5 class="mb-2"><?= number_format($pctPropias,1) ?>% Cumplimiento</h5>
          <div class="small text-muted">Unid: <?= (int)$totalGlobalUnidades ?> · Ventas: $<?= number_format($totalGlobalVentas,2) ?> · Cuota: $<?= number_format($totalGlobalCuota,2) ?></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-4">
      <div class="card shadow text-center h-100">
        <div class="card-header bg-secondary text-white">Master Admin</div>
        <div class="card-body">
          <h6 class="mb-2">Sin cuota</h6>
          <div class="small text-muted">Unid: <?= (int)$totalMAUnidades ?> · Ventas: $<?= number_format($totalMAVentas,2) ?></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-4">
      <div class="card shadow text-center h-100">
        <div class="card-header bg-primary text-white">🌎 Global (Propias)</div>
        <div class="card-body">
          <?php $porcentajeGlobal = $totalGlobalCuota>0 ? ($totalGlobalVentas/$totalGlobalCuota*100) : 0; ?>
          <h5 class="mb-2"><?= number_format($porcentajeGlobal,1) ?>% Cumplimiento</h5>
          <div class="small text-muted">Unid: <?= (int)$totalGlobalUnidades ?> · Ventas: $<?= number_format($totalGlobalVentas,2) ?> · Cuota: $<?= number_format($totalGlobalCuota,2) ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Gráfica mensual -->
  <div class="card shadow mb-3">
    <div class="card-header bg-dark text-white position-relative d-flex align-items-center gap-2 flex-wrap">
      <span>Acumulado mensual por sucursal</span>
      <div class="ms-auto d-flex align-items-center gap-2">
        <!-- Switch grupo -->
        <div class="btn-group btn-group-sm" role="group" aria-label="Grupo gráfico">
          <a class="btn <?= $g==='propias'?'btn-primary':'btn-outline-light' ?>" href="?mes=<?= $mes ?>&anio=<?= $anio ?>&g=propias&m=<?= htmlspecialchars($m) ?>">Propias</a>
          <a class="btn <?= $g==='ma'?'btn-primary':'btn-outline-light' ?>" href="?mes=<?= $mes ?>&anio=<?= $anio ?>&g=ma&m=<?= htmlspecialchars($m) ?>">Master Admin</a>
        </div>
        <!-- Switch métrica -->
        <div class="btn-group btn-group-sm" role="group" aria-label="Métrica">
          <a class="btn <?= $m==='monto'?'btn-warning':'btn-outline-light' ?>" href="?mes=<?= $mes ?>&anio=<?= $anio ?>&g=<?= htmlspecialchars($g) ?>&m=monto">$</a>
          <a class="btn <?= $m==='unidades'?'btn-warning':'btn-outline-light' ?>" href="?mes=<?= $mes ?>&anio=<?= $anio ?>&g=<?= htmlspecialchars($g) ?>&m=unidades">uds</a>
        </div>
      </div>
    </div>
    <div class="card-body">
      <div class="chart-wrap" style="position:relative; height:320px;">
        <canvas id="chartMensual"></canvas>
      </div>
      <small class="text-muted d-block mt-2">* Top-15 por <?= $m==='monto' ? 'monto mensual' : 'unidades mensuales' ?> + “Otras”.</small>
    </div>
  </div>

  <!-- Tabs -->
  <ul class="nav nav-tabs" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-suc">Sucursales (Propias)</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-ej">Ejecutivos</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-ma">Master Admin</button></li>
  </ul>

  <div class="tab-content">
    <!-- Sucursales PROPIAS -->
    <div class="tab-pane fade show active" id="tab-suc" role="tabpanel">
      <div class="card shadow mt-3">
        <div class="card-header bg-primary text-white position-relative">
          Sucursales (Propias)
          <button class="btn btn-sm btn-light btn-download position-absolute top-50 end-0 translate-middle-y me-2"
                  onclick="descargarTabla('wrapSucursales','mensual_sucursales_propias.png')" title="Descargar imagen">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M9.4 4l1.2-2h2.8l1.2 2H19a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h4.4zM12 18a5 5 0 1 0 0-10 5 5 0 0 0 0 10zm0-2.2a2.8 2.8 0 1 1 0-5.6 2.8 2.8 0 0 1 0 5.6z"/></svg>
          </button>
        </div>
        <div class="card-body p-0" id="wrapSucursales">
          <div class="table-responsive">
            <table class="table table-bordered table-striped table-sm mb-0">
              <thead class="table-dark">
                <tr>
                  <th>Sucursal</th>
                  <th class="d-none d-md-table-cell">Tipo</th>
                  <!-- Desktop (orden: Uds, $ Ventas, % Cumpl., Prep, Pos, Cuotas) -->
                  <th class="d-none d-md-table-cell">Unidades</th>
                  <th class="d-none d-md-table-cell">Ventas $</th>
                  <th class="d-none d-md-table-cell">% Cumplimiento</th>
                  <th class="d-none d-md-table-cell">SIM Prepago</th>
                  <th class="d-none d-md-table-cell">SIM Pospago</th>
                  <th class="d-none d-md-table-cell">Cuota Unid.</th>
                  <th class="d-none d-md-table-cell">Cuota $</th>
                  <!-- Móvil (orden: Uds, $, %, Prep, Pos) -->
                  <th class="d-table-cell d-md-none text-center">Uds</th>
                  <th class="d-table-cell d-md-none text-center">$</th>
                  <th class="d-table-cell d-md-none text-center">%</th>
                  <th class="d-table-cell d-md-none text-center">Prep</th>
                  <th class="d-table-cell d-md-none text-center">Pos</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($sucursalesPropias as $s): 
                  $fila  = badgeFila($s['cumplimiento']);
                  $estado= $s['cumplimiento']>=100?"✅":($s['cumplimiento']>=60?"⚠️":"❌");
                ?>
                <tr class="<?= $fila ?>">
                  <td><?= htmlspecialchars($s['sucursal']) ?></td>
                  <td class="d-none d-md-table-cell"><?= htmlspecialchars($s['tipo']) ?></td>
                  <!-- Desktop -->
                  <td class="d-none d-md-table-cell"><?= (int)$s['unidades'] ?></td>
                  <td class="d-none d-md-table-cell">$<?= number_format($s['ventas'],2) ?></td>
                  <td class="d-none d-md-table-cell"><?= round($s['cumplimiento'],1) ?>% <?= $estado ?></td>
                  <td class="d-none d-md-table-cell"><?= (int)$s['sims_prepago'] ?></td>
                  <td class="d-none d-md-table-cell"><?= (int)$s['sims_pospago'] ?></td>
                  <td class="d-none d-md-table-cell"><?= (int)$s['cuota_unidades'] ?></td>
                  <td class="d-none d-md-table-cell">$<?= number_format($s['cuota_monto'],2) ?></td>
                  <!-- Móvil -->
                  <td class="d-table-cell d-md-none text-center"><?= (int)$s['unidades'] ?></td>
                  <td class="d-table-cell d-md-none text-center">$<?= number_format($s['ventas'],0) ?></td>
                  <td class="d-table-cell d-md-none text-center"><?= round($s['cumplimiento'],1) ?>%</td>
                  <td class="d-table-cell d-md-none text-center"><?= (int)$s['sims_prepago'] ?></td>
                  <td class="d-table-cell d-md-none text-center"><?= (int)$s['sims_pospago'] ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>

              <!-- ====== TOTALES SUCURSALES PROPIAS ====== -->
              <?php
                $cumplTotSuc = $totalGlobalCuota>0 ? round(($totalGlobalVentas/$totalGlobalCuota)*100, 1) : 0;
                $estadoTotSuc = $cumplTotSuc>=100?"✅":($cumplTotSuc>=60?"⚠️":"❌");
              ?>
              <tfoot>
                <tr class="table-dark">
                  <td>TOTALES</td>
                  <td class="d-none d-md-table-cell">—</td>

                  <!-- Desktop -->
                  <td class="d-none d-md-table-cell"><?= (int)$totalGlobalUnidades ?></td>
                  <td class="d-none d-md-table-cell">$<?= number_format($totalGlobalVentas,2) ?></td>
                  <td class="d-none d-md-table-cell"><?= $cumplTotSuc ?>% <?= $estadoTotSuc ?></td>
                  <td class="d-none d-md-table-cell"><?= (int)$totalGlobalPrepago ?></td>
                  <td class="d-none d-md-table-cell"><?= (int)$totalGlobalPospago ?></td>
                  <td class="d-none d-md-table-cell"><?= (int)$totalGlobalCuotaUnid ?></td>
                  <td class="d-none d-md-table-cell">$<?= number_format($totalGlobalCuota,2) ?></td>

                  <!-- Móvil -->
                  <td class="d-table-cell d-md-none text-center"><?= (int)$totalGlobalUnidades ?></td>
                  <td class="d-table-cell d-md-none text-center">$<?= number_format($totalGlobalVentas,0) ?></td>
                  <td class="d-table-cell d-md-none text-center"><?= $cumplTotSuc ?>%</td>
                  <td class="d-table-cell d-md-none text-center"><?= (int)$totalGlobalPrepago ?></td>
                  <td class="d-table-cell d-md-none text-center"><?= (int)$totalGlobalPospago ?></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Ejecutivos (Propias) -->
    <div class="tab-pane fade" id="tab-ej" role="tabpanel">
      <div class="card shadow mt-3">
        <div class="card-header bg-dark text-white position-relative">
          Productividad mensual por Ejecutivo (Propias)
          <button class="btn btn-sm btn-light btn-download position-absolute top-50 end-0 translate-middle-y me-2"
                  onclick="descargarTabla('wrapEjecutivos','mensual_ejecutivos_propias.png')" title="Descargar imagen">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M9.4 4l1.2-2h2.8l1.2 2H19a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h4.4zM12 18a5 5 0 1 0 0-10 5 5 0 0 0 0 10zm0-2.2a2.8 2.8 0 1 1 0-5.6 2.8 2.8 0 0 1 0 5.6z"/></svg>
          </button>
        </div>
        <div class="card-body p-0" id="wrapEjecutivos">
          <div class="table-responsive">
            <table class="table table-striped table-bordered table-sm mb-0">
              <thead class="table-dark">
                <tr>
                  <th>Ejecutivo</th>
                  <th class="d-none d-md-table-cell">Sucursal</th>
                  <!-- Desktop (Uds, $ Ventas, % Cumpl., Prep, Pos, Cuota, Progreso) -->
                  <th class="d-none d-md-table-cell">Unidades</th>
                  <th class="d-none d-md-table-cell">Ventas $</th>
                  <th class="d-none d-md-table-cell">% Cumpl. (Unid.)</th>
                  <th class="d-none d-md-table-cell">SIM Prepago</th>
                  <th class="d-none d-md-table-cell">SIM Pospago</th>
                  <th class="d-none d-md-table-cell">Cuota Mes (u)</th>
                  <th class="d-none d-md-table-cell">Progreso</th>
                  <!-- Móvil (Uds, $, %, Prep, Pos) -->
                  <th class="d-table-cell d-md-none text-center">Uds</th>
                  <th class="d-table-cell d-md-none text-center">$</th>
                  <th class="d-table-cell d-md-none text-center">%</th>
                  <th class="d-table-cell d-md-none text-center">Prep</th>
                  <th class="d-table-cell d-md-none text-center">Pos</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($ejecutivos as $e):
                  $pct = $e['cumpl_uni'];
                  $pctRound = ($pct===null) ? null : round($pct,1);
                  $fila = badgeFila($pct);
                  $barClass = ($pct===null) ? 'bg-secondary' : ($pct>=100?'bg-success':($pct>=60?'bg-warning':'bg-danger'));
                ?>
                <tr class="<?= $fila ?>">
                  <td><?= htmlspecialchars($e['nombre']) ?></td>
                  <td class="d-none d-md-table-cell"><?= htmlspecialchars($e['sucursal']) ?></td>
                  <!-- Desktop -->
                  <td class="d-none d-md-table-cell"><?= (int)$e['unidades'] ?></td>
                  <td class="d-none d-md-table-cell">$<?= number_format($e['ventas'],2) ?></td>
                  <td class="d-none d-md-table-cell"><?= $pct===null ? '–' : ($pctRound.'%') ?></td>
                  <td class="d-none d-md-table-cell"><?= (int)$e['sims_prepago'] ?></td>
                  <td class="d-none d-md-table-cell"><?= (int)$e['sims_pospago'] ?></td>
                  <td class="d-none d-md-table-cell"><?= number_format($e['cuota_unidades'],2) ?></td>
                  <td class="d-none d-md-table-cell" style="min-width:160px">
                    <div class="progress">
                      <div class="progress-bar <?= $barClass ?>" role="progressbar"
                          style="width: <?= $pct===null? 0 : min(100,$pctRound) ?>%">
                          <?= $pct===null ? 'Sin cuota' : ($pctRound.'%') ?>
                      </div>
                    </div>
                  </td>
                  <!-- Móvil -->
                  <td class="d-table-cell d-md-none text-center"><?= (int)$e['unidades'] ?></td>
                  <td class="d-table-cell d-md-none text-center">$<?= number_format($e['ventas'],0) ?></td>
                  <td class="d-table-cell d-md-none text-center"><?= $pct===null ? '–' : ($pctRound.'%') ?></td>
                  <td class="d-table-cell d-md-none text-center"><?= (int)$e['sims_prepago'] ?></td>
                  <td class="d-table-cell d-md-none text-center"><?= (int)$e['sims_pospago'] ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>

              <!-- ====== TOTALES EJECUTIVOS ====== -->
              <?php
                $cumplTotEjRound = round($cumplTotEj,1);
                $barClassTot = $cumplTotEj>=100?'bg-success':($cumplTotEj>=60?'bg-warning':'bg-danger');
              ?>
              <tfoot>
                <tr class="table-dark">
                  <td>TOTALES</td>
                  <td class="d-none d-md-table-cell">—</td>

                  <!-- Desktop -->
                  <td class="d-none d-md-table-cell"><?= (int)$totalEjUnidades ?></td>
                  <td class="d-none d-md-table-cell">$<?= number_format($totalEjVentas,2) ?></td>
                  <td class="d-none d-md-table-cell"><?= $cumplTotEjRound ?>%</td>
                  <td class="d-none d-md-table-cell"><?= (int)$totalEjPrep ?></td>
                  <td class="d-none d-md-table-cell"><?= (int)$totalEjPos ?></td>
                  <td class="d-none d-md-table-cell"><?= number_format($totalEjCuota,2) ?></td>
                  <td class="d-none d-md-table-cell" style="min-width:160px">
                    <div class="progress">
                      <div class="progress-bar <?= $barClassTot ?>" role="progressbar"
                           style="width: <?= min(100,$cumplTotEjRound) ?>%">
                           <?= $cumplTotEjRound ?>%
                      </div>
                    </div>
                  </td>

                  <!-- Móvil -->
                  <td class="d-table-cell d-md-none text-center"><?= (int)$totalEjUnidades ?></td>
                  <td class="d-table-cell d-md-none text-center">$<?= number_format($totalEjVentas,0) ?></td>
                  <td class="d-table-cell d-md-none text-center"><?= $cumplTotEjRound ?>%</td>
                  <td class="d-table-cell d-md-none text-center"><?= (int)$totalEjPrep ?></td>
                  <td class="d-table-cell d-md-none text-center"><?= (int)$totalEjPos ?></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Master Admin -->
    <div class="tab-pane fade" id="tab-ma" role="tabpanel">
      <div class="card shadow mt-3">
        <div class="card-header bg-secondary text-white position-relative">
          Sucursales (Master Admin)
          <button class="btn btn-sm btn-light btn-download position-absolute top-50 end-0 translate-middle-y me-2"
                  onclick="descargarTabla('wrapMA','mensual_master_admin.png')" title="Descargar imagen">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M9.4 4l1.2-2h2.8l1.2 2H19a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h4.4zM12 18a5 5 0 1 0 0-10 5 5 0 0 0 0 10zm0-2.2a2.8 2.8 0 1 1 0-5.6 2.8 2.8 0 0 1 0 5.6z"/></svg>
          </button>
        </div>
        <div class="card-body p-0" id="wrapMA">
          <div class="table-responsive">
            <table class="table table-bordered table-striped table-sm mb-0">
              <thead class="table-dark">
                <tr>
                  <th>Sucursal</th>
                  <th class="d-none d-md-table-cell">Tipo</th>
                  <!-- Desktop (Uds, $ Ventas, Prep, Pos) -->
                  <th class="d-none d-md-table-cell">Unidades</th>
                  <th class="d-none d-md-table-cell">Ventas $</th>
                  <th class="d-none d-md-table-cell">SIM Prepago</th>
                  <th class="d-none d-md-table-cell">SIM Pospago</th>
                  <!-- Móvil (Uds, $, Prep, Pos) -->
                  <th class="d-table-cell d-md-none text-center">Uds</th>
                  <th class="d-table-cell d-md-none text-center">$</th>
                  <th class="d-table-cell d-md-none text-center">Prep</th>
                  <th class="d-table-cell d-md-none text-center">Pos</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($sucursalesMA as $s): ?>
                <tr>
                  <td><?= htmlspecialchars($s['sucursal']) ?></td>
                  <td class="d-none d-md-table-cell"><?= htmlspecialchars($s['tipo']) ?></td>
                  <!-- Desktop -->
                  <td class="d-none d-md-table-cell"><?= (int)$s['unidades'] ?></td>
                  <td class="d-none d-md-table-cell">$<?= number_format($s['ventas'],2) ?></td>
                  <td class="d-none d-md-table-cell"><?= (int)$s['sims_prepago'] ?></td>
                  <td class="d-none d-md-table-cell"><?= (int)$s['sims_pospago'] ?></td>
                  <!-- Móvil -->
                  <td class="d-table-cell d-md-none text-center"><?= (int)$s['unidades'] ?></td>
                  <td class="d-table-cell d-md-none text-center">$<?= number_format($s['ventas'],0) ?></td>
                  <td class="d-table-cell d-md-none text-center"><?= (int)$s['sims_prepago'] ?></td>
                  <td class="d-table-cell d-md-none text-center"><?= (int)$s['sims_pospago'] ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>

              <!-- ====== TOTALES MASTER ADMIN ====== -->
              <tfoot>
                <tr class="table-dark">
                  <td>TOTALES</td>
                  <td class="d-none d-md-table-cell">—</td>

                  <!-- Desktop -->
                  <td class="d-none d-md-table-cell"><?= (int)$totalMAUnidades ?></td>
                  <td class="d-none d-md-table-cell">$<?= number_format($totalMAVentas,2) ?></td>
                  <td class="d-none d-md-table-cell"><?= (int)$totalMAPrepago ?></td>
                  <td class="d-none d-md-table-cell"><?= (int)$totalMAPospago ?></td>

                  <!-- Móvil -->
                  <td class="d-table-cell d-md-none text-center"><?= (int)$totalMAUnidades ?></td>
                  <td class="d-table-cell d-md-none text-center">$<?= number_format($totalMAVentas,0) ?></td>
                  <td class="d-table-cell d-md-none text-center"><?= (int)$totalMAPrepago ?></td>
                  <td class="d-table-cell d-md-none text-center"><?= (int)$totalMAPospago ?></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script> -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script>
const labelsGraf = <?= json_encode($labelsGraf, JSON_UNESCAPED_UNICODE) ?>;
const dataGraf   = <?= json_encode($dataGraf, JSON_UNESCAPED_UNICODE) ?>;
const metric     = <?= json_encode($m) ?>; // 'monto' | 'unidades'

// Formateadores
const yTickFormatter = (v) => (metric === 'monto')
  ? '$' + Number(v).toLocaleString()
  : Number(v).toLocaleString('es-MX');

const tooltipLabel = (ctx) => {
  const val = Number(ctx.parsed.y);
  return (metric === 'monto')
    ? ` Monto: $${val.toLocaleString()}`
    : ` Unidades: ${val.toLocaleString('es-MX')}`;
};

new Chart(document.getElementById('chartMensual').getContext('2d'), {
  type: 'bar',
  data: { labels: labelsGraf, datasets: [{
    label: metric === 'monto' ? 'Ventas ($)' : 'Unidades',
    data: dataGraf,
    borderWidth: 0,
    maxBarThickness: 28,
    categoryPercentage: 0.8,
    barPercentage: 0.9
  }]},
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: { callbacks: { label: tooltipLabel } }
    },
    scales: {
      x: { ticks: { autoSkip: false, maxRotation: 45, minRotation: 45 } },
      y: {
        beginAtZero: true,
        ticks: { callback: yTickFormatter },
        title: { display: true, text: metric === 'monto' ? 'Ventas ($)' : 'Unidades' }
      }
    }
  }
});

// Descargar imagen de rankings (tablas)
function descargarTabla(wrapperId, filename){
  const node = document.getElementById(wrapperId);
  if(!node) return;
  const prevBg = node.style.backgroundColor;
  node.style.backgroundColor = '#ffffff'; // fondo sólido para export
  html2canvas(node, {scale: 2, useCORS: true}).then(canvas => {
    node.style.backgroundColor = prevBg || '';
    const a = document.createElement('a');
    a.download = filename || 'tabla.png';
    a.href = canvas.toDataURL('image/png');
    a.click();
  });
}
</script>
</body>
</html>
