<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

/* ==============================
   Semana actual (martes-lunes)
================================= */
function obtenerSemanaPorIndice($offset = 0) {
    $hoy = new DateTime();
    $diaSemana = $hoy->format('N'); // 1=Lun..7=Dom
    $dif = $diaSemana - 2; // Martes=2
    if ($dif < 0) $dif += 7;

    $inicio = new DateTime();
    $inicio->modify("-$dif days")->setTime(0,0,0);
    if ($offset > 0) $inicio->modify('-'.(7*$offset).' days');

    $fin = clone $inicio;
    $fin->modify('+6 days')->setTime(23,59,59);

    return [$inicio, $fin];
}

/* Quita prefijo NANORED del nombre de sucursal solo para visualizar */
function limpiarNombreSucursal(string $n): string {
    $t = preg_replace('/^\s*NANORED\s*/i', '', $n);
    return trim($t);
}

$semanaSeleccionada = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
list($inicioObj, $finObj) = obtenerSemanaPorIndice($semanaSeleccionada);
$inicioSemana = $inicioObj->format('Y-m-d');
$finSemana    = $finObj->format('Y-m-d');

/* ==============================
   Ejecutivos (Propias) + SIMs
================================= */
$sqlEjecutivos = "
    SELECT 
        u.id, u.nombre, u.rol,
        s.nombre AS sucursal,
        s.subtipo AS subtipo,
        (
            SELECT ec.cuota_ejecutivo
            FROM esquemas_comisiones ec
            WHERE ec.activo=1
              AND ec.fecha_inicio <= ?
              AND (ec.fecha_fin IS NULL OR ec.fecha_fin >= ?)
            ORDER BY ec.fecha_inicio DESC
            LIMIT 1
        ) AS cuota_ejecutivo,
        IFNULL(SUM(
            CASE 
                WHEN dv.id IS NULL THEN 0
                WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0
                WHEN REPLACE(LOWER(v.tipo_venta),' ','')='financiamiento+combo' THEN
                    CASE 
                      WHEN dv.id = (SELECT MIN(dv2.id) FROM detalle_venta dv2 WHERE dv2.id_venta = v.id) THEN 2
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
        ),0) AS total_ventas,
        (SELECT COUNT(*) FROM ventas_sims vs
          WHERE vs.id_usuario=u.id
            AND DATE(CONVERT_TZ(vs.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
            AND LOWER(vs.tipo_venta) LIKE '%pospago%') AS sims_pospago,
        (SELECT COUNT(*) FROM ventas_sims vs
          WHERE vs.id_usuario=u.id
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
    WHERE s.tipo_sucursal='Tienda' AND s.subtipo='Propia' AND u.activo = 1
    GROUP BY u.id
    ORDER BY unidades DESC, total_ventas DESC
";
$stmt = $conn->prepare($sqlEjecutivos);
$stmt->bind_param("ssssssss", 
    $inicioSemana, $finSemana,     // cuota_ejecutivo
    $inicioSemana, $finSemana,     // sims_pospago
    $inicioSemana, $finSemana,     // sims_prepago
    $inicioSemana, $finSemana      // ventas cabecera
);
$stmt->execute();
$resEjecutivos = $stmt->get_result();

$rankingEjecutivos = [];
while ($row = $resEjecutivos->fetch_assoc()) {
    $row['unidades']        = (int)$row['unidades'];
    $row['total_ventas']    = (float)$row['total_ventas'];
    $row['cuota_ejecutivo'] = (int)$row['cuota_ejecutivo'];
    $row['cumplimiento']    = $row['cuota_ejecutivo']>0 ? ($row['unidades']/$row['cuota_ejecutivo']*100) : 0;
    $row['sims_prepago']    = (int)$row['sims_prepago'];
    $row['sims_pospago']    = (int)$row['sims_pospago'];
    $rankingEjecutivos[]    = $row;
}
$top3Ejecutivos = array_slice(array_column($rankingEjecutivos, 'id'), 0, 3);

/* ==============================
   Sucursales (Propias) + SIMs
================================= */
$sqlSucursalesPropias = "
    SELECT s.id AS id_sucursal,
           s.nombre AS sucursal,
           s.subtipo AS subtipo,
           (
               SELECT cs.cuota_monto
               FROM cuotas_sucursales cs
               WHERE cs.id_sucursal = s.id AND cs.fecha_inicio <= ?
               ORDER BY cs.fecha_inicio DESC LIMIT 1
           ) AS cuota_semanal,
           IFNULL(SUM(
                CASE 
                    WHEN dv.id IS NULL THEN 0
                    WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0
                    WHEN REPLACE(LOWER(v.tipo_venta),' ','')='financiamiento+combo' THEN
                        CASE 
                          WHEN dv.id = (SELECT MIN(dv2.id) FROM detalle_venta dv2 WHERE dv2.id_venta = v.id) THEN 2
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
           ),0) AS total_ventas,
           (SELECT COUNT(*) FROM ventas_sims vs
             WHERE vs.id_sucursal=s.id
               AND DATE(CONVERT_TZ(vs.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
               AND LOWER(vs.tipo_venta) LIKE '%pospago%') AS sims_pospago,
           (SELECT COUNT(*) FROM ventas_sims vs
             WHERE vs.id_sucursal=s.id
               AND DATE(CONVERT_TZ(vs.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
               AND LOWER(vs.tipo_venta) NOT LIKE '%pospago%'
               AND LOWER(vs.tipo_venta) NOT LIKE '%regalo%') AS sims_prepago
    FROM sucursales s
    LEFT JOIN (
        SELECT v.id, v.id_sucursal, v.fecha_venta, v.tipo_venta, v.precio_venta
        FROM ventas v
        WHERE DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
    ) v ON v.id_sucursal = s.id
    LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
    LEFT JOIN productos p ON p.id = dv.id_producto
    WHERE s.tipo_sucursal='Tienda' AND s.subtipo='Propia'
    GROUP BY s.id
    ORDER BY total_ventas DESC
";
$stmt2 = $conn->prepare($sqlSucursalesPropias);
$stmt2->bind_param("sssssss", 
    $inicioSemana,
    $inicioSemana, $finSemana,
    $inicioSemana, $finSemana,
    $inicioSemana, $finSemana
);
$stmt2->execute();
$resSucursalesPropias = $stmt2->get_result();

$sucursales = [];
$totalUnidadesPropias = 0;
$totalVentasPropias   = 0;
$totalCuotaPropias    = 0;
while ($row = $resSucursalesPropias->fetch_assoc()) {
    $row['sucursal']      = limpiarNombreSucursal($row['sucursal']);
    $row['unidades']      = (int)$row['unidades'];
    $row['total_ventas']  = (float)$row['total_ventas'];
    $row['cuota_semanal'] = (float)$row['cuota_semanal'];
    $row['cumplimiento']  = $row['cuota_semanal']>0 ? ($row['total_ventas']/$row['cuota_semanal']*100) : 0;
    $row['sims_prepago']  = (int)$row['sims_prepago'];
    $row['sims_pospago']  = (int)$row['sims_pospago'];

    $sucursales[]          = $row;
    $totalUnidadesPropias += $row['unidades'];
    $totalVentasPropias   += $row['total_ventas'];
    $totalCuotaPropias    += $row['cuota_semanal'];
}
$porcentajeGlobalPropias = $totalCuotaPropias>0 ? ($totalVentasPropias/$totalCuotaPropias)*100 : 0;

/* ==============================
   Master Admin + SIMs
================================= */
$sqlSucursalesMA = "
    SELECT 
        s.id AS id_sucursal,
        s.nombre AS sucursal,
        s.subtipo AS subtipo,
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
        ),0) AS total_ventas,
        (SELECT COUNT(*) FROM ventas_sims vs
          WHERE vs.id_sucursal=s.id
            AND DATE(CONVERT_TZ(vs.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
            AND LOWER(vs.tipo_venta) LIKE '%pospago%') AS sims_pospago,
        (SELECT COUNT(*) FROM ventas_sims vs
          WHERE vs.id_sucursal=s.id
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
    ORDER BY total_ventas DESC
";
$stmtMA = $conn->prepare($sqlSucursalesMA);
$stmtMA->bind_param("ssssss", 
    $inicioSemana, $finSemana,
    $inicioSemana, $finSemana,
    $inicioSemana, $finSemana
);
$stmtMA->execute();
$resSucursalesMA = $stmtMA->get_result();

$sucursalesMA = [];
$totalUnidadesMA = 0;
$totalVentasMA   = 0;
while ($row = $resSucursalesMA->fetch_assoc()) {
    $row['sucursal']      = limpiarNombreSucursal($row['sucursal']);
    $row['unidades']      = (int)$row['unidades'];
    $row['total_ventas']  = (float)$row['total_ventas'];
    $row['sims_prepago']  = (int)$row['sims_prepago'];
    $row['sims_pospago']  = (int)$row['sims_pospago'];
    $sucursalesMA[]       = $row;
    $totalUnidadesMA     += $row['unidades'];
    $totalVentasMA       += $row['total_ventas'];
}

/* ==========================================================
   GRÁFICO con switches: grupo (propias/ma) y métrica (monto/unidades)
========================================================== */
$g = $_GET['g'] ?? 'propias';
if ($g !== 'propias' && $g !== 'ma') { $g = 'propias'; }

$m = $_GET['m'] ?? 'monto'; // 'monto' | 'unidades'
if ($m !== 'monto' && $m !== 'unidades') { $m = 'monto'; }

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
    } else { // unidades
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
} else { // MA
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
    } else { // unidades (con regla especial MA)
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
$stmtC->bind_param("ss", $inicioSemana, $finSemana);
$stmtC->execute();
$resChart = $stmtC->get_result();

$rowsChart = [];
while ($r = $resChart->fetch_assoc()) {
    $r['sucursal'] = limpiarNombreSucursal($r['sucursal']);
    $r['valor']    = (float)$r['valor'];
    $rowsChart[]   = $r;
}
usort($rowsChart, fn($a,$b) => $b['valor'] <=> $a['valor']);
$top = array_slice($rowsChart, 0, 15);
$otrasVal = 0;
for ($i=15; $i<count($rowsChart); $i++) { $otrasVal += $rowsChart[$i]['valor']; }
$labelsGraf = array_map(fn($r)=>$r['sucursal'],$top);
$dataGraf   = array_map(fn($r)=>round($r['valor'],2),$top);
if ($otrasVal>0) { $labelsGraf[]='Otras'; $dataGraf[]=round($otrasVal,2); }

/* ==============================
   HTML
================================= */
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Semanal Nano</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/x-icon" href="./img/favicon.ico">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
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
      .progress{height:20px}
      .progress-bar{font-size:.75rem}
      .btn-download{
        border: 1px solid rgba(255,255,255,.6);
        background: rgba(255,255,255,.05);
        backdrop-filter: blur(4px);
      }
      .btn-download svg{ width:16px; height:16px; vertical-align: -3px;}
    </style>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-3">
    <!-- Sticky filtro semana en móvil -->
    <div class="sticky-mobile-bar rounded-3 shadow-sm d-md-none mb-3">
      <form method="GET" class="d-flex gap-2 align-items-center">
        <label class="mb-0"><strong>Semana:</strong></label>
        <select name="semana" class="form-select form-select-sm" onchange="this.form.submit()">
          <?php for ($i=0; $i<8; $i++):
              list($ini, $fin) = obtenerSemanaPorIndice($i);
              $texto = "{$ini->format('d/m')}–{$fin->format('d/m')}";
          ?>
          <option value="<?= $i ?>" <?= $i==$semanaSeleccionada?'selected':'' ?>><?= $texto ?></option>
          <?php endfor; ?>
        </select>
        <input type="hidden" name="g" value="<?= htmlspecialchars($g) ?>">
        <input type="hidden" name="m" value="<?= htmlspecialchars($m) ?>">
      </form>
    </div>

    <h2 class="d-none d-md-block">📊 Dashboard Semanal Nano</h2>

    <!-- Tarjetas -->
    <div class="row mb-3 g-3">
        <div class="col-12 col-md-4">
            <div class="card shadow text-center h-100">
                <div class="card-header bg-dark text-white">Propias</div>
                <div class="card-body">
                    <h5 class="mb-2"><?= number_format($porcentajeGlobalPropias,1) ?>% Cumplimiento</h5>
                    <div class="small text-muted mb-2">
                        Unidades: <?= $totalUnidadesPropias ?> · Ventas: $<?= number_format($totalVentasPropias,2) ?> · Cuota: $<?= number_format($totalCuotaPropias,2) ?>
                    </div>
                    <div class="progress">
                        <div class="progress-bar <?= $porcentajeGlobalPropias>=100?'bg-success':($porcentajeGlobalPropias>=60?'bg-warning':'bg-danger') ?>"
                             style="width:<?= min(100,$porcentajeGlobalPropias) ?>%">
                            <?= number_format(min(100,$porcentajeGlobalPropias),1) ?>%
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card shadow text-center h-100">
                <div class="card-header bg-secondary text-white">Master Admin</div>
                <div class="card-body">
                    <h6 class="mb-2">Sin cuota</h6>
                    <div class="small text-muted">Unidades: <?= $totalUnidadesMA ?> · Ventas: $<?= number_format($totalVentasMA,2) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card shadow text-center h-100">
                <div class="card-header bg-primary text-white">🌐 Global (Propias)</div>
                <div class="card-body">
                    <h5 class="mb-2"><?= number_format($porcentajeGlobalPropias,1) ?>% Cumplimiento</h5>
                    <div class="small text-muted">Unidades: <?= $totalUnidadesPropias ?> · Ventas: $<?= number_format($totalVentasPropias,2) ?> · Cuota: $<?= number_format($totalCuotaPropias,2) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Gráfica semanal -->
    <div class="card shadow mb-3">
        <div class="card-header bg-dark text-white position-relative d-flex align-items-center gap-2 flex-wrap">
          <span>Resumen semanal por sucursal</span>
          <div class="ms-auto d-flex align-items-center gap-2">
            <!-- Switch grupo -->
            <div class="btn-group btn-group-sm" role="group" aria-label="Grupo gráfico">
              <a class="btn <?= $g==='propias'?'btn-primary':'btn-outline-light' ?>"
                 href="?semana=<?= $semanaSeleccionada ?>&g=propias&m=<?= htmlspecialchars($m) ?>">Propias</a>
              <a class="btn <?= $g==='ma'?'btn-primary':'btn-outline-light' ?>"
                 href="?semana=<?= $semanaSeleccionada ?>&g=ma&m=<?= htmlspecialchars($m) ?>">Master Admin</a>
            </div>
            <!-- Switch métrica -->
            <div class="btn-group btn-group-sm" role="group" aria-label="Métrica">
              <a class="btn <?= $m==='monto'?'btn-warning':'btn-outline-light' ?>"
                 href="?semana=<?= $semanaSeleccionada ?>&g=<?= htmlspecialchars($g) ?>&m=monto">$</a>
              <a class="btn <?= $m==='unidades'?'btn-warning':'btn-outline-light' ?>"
                 href="?semana=<?= $semanaSeleccionada ?>&g=<?= htmlspecialchars($g) ?>&m=unidades">uds</a>
            </div>
          </div>
        </div>
        <div class="card-body">
            <div class="chart-wrap" style="position:relative; height:320px;">
                <canvas id="chartSemanal"></canvas>
            </div>
            <small class="text-muted d-block mt-2">* Top-15 por <?= $m==='monto' ? 'monto semanal' : 'unidades semanales' ?> + “Otras”.</small>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-3" id="dashboardTabs">
        <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#sucursales">Sucursales (Propias)</button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#ejecutivos">Ejecutivos</button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#masteradmin">Master Admin</button>
        </li>
    </ul>

    <div class="tab-content">
        <!-- Sucursales (Propias) -->
        <div class="tab-pane fade show active" id="sucursales">
            <div class="card mb-4 shadow">
                <div class="card-header bg-dark text-white position-relative">
                    Sucursales (Propias)
                    <button class="btn btn-sm btn-light btn-download position-absolute top-50 end-0 translate-middle-y me-2"
                            onclick="descargarTabla('wrapSucursales','sucursales_propias_semana.png')" title="Descargar imagen">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M9.4 4l1.2-2h2.8l1.2 2H19a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h4.4zM12 18a5 5 0 1 0 0-10 5 5 0 0 0 0 10zm0-2.2a2.8 2.8 0 1 1 0-5.6 2.8 2.8 0 0 1 0 5.6z"/></svg>
                    </button>
                </div>
                <div class="card-body p-0" id="wrapSucursales">
                    <div class="table-responsive">
                      <table class="table table-striped table-bordered mb-0" id="tblSucursales">
                        <thead class="table-dark">
                          <tr>
                            <th>Sucursal</th>
                            <th class="d-none d-md-table-cell">Tipo</th>

                            <!-- Desktop (orden: Uds, Ventas $, % Cumpl., Prep, Pos, Cuota $, Progreso) -->
                            <th class="d-none d-md-table-cell">Unidades</th>
                            <th class="d-none d-md-table-cell">Ventas $</th>
                            <th class="d-none d-md-table-cell">% Cumpl.</th>
                            <th class="d-none d-md-table-cell">Prep</th>
                            <th class="d-none d-md-table-cell">Pos</th>
                            <th class="d-none d-md-table-cell">Cuota $</th>
                            <th class="d-none d-md-table-cell">Progreso</th>

                            <!-- Móvil (orden: Uds, $, %, Prep, Pos) -->
                            <th class="d-table-cell d-md-none text-center">Uds</th>
                            <th class="d-table-cell d-md-none text-center">$</th>
                            <th class="d-table-cell d-md-none text-center">%</th>
                            <th class="d-table-cell d-md-none text-center">Prep</th>
                            <th class="d-table-cell d-md-none text-center">Pos</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($sucursales as $s):
                              $cumpl = round($s['cumplimiento'],1);
                              $estado = $cumpl>=100?"✅":($cumpl>=60?"⚠️":"❌");
                              $fila = $cumpl>=100?"table-success":($cumpl>=60?"table-warning":"table-danger");
                          ?>
                          <tr class="<?= $fila ?>">
                            <td><?= htmlspecialchars($s['sucursal']) ?></td>
                            <td class="d-none d-md-table-cell"><?= htmlspecialchars($s['subtipo']) ?></td>

                            <!-- Desktop -->
                            <td class="d-none d-md-table-cell"><?= (int)$s['unidades'] ?></td>
                            <td class="d-none d-md-table-cell">$<?= number_format($s['total_ventas'],2) ?></td>
                            <td class="d-none d-md-table-cell"><?= $cumpl ?>% <?= $estado ?></td>
                            <td class="d-none d-md-table-cell"><?= (int)$s['sims_prepago'] ?></td>
                            <td class="d-none d-md-table-cell"><?= (int)$s['sims_pospago'] ?></td>
                            <td class="d-none d-md-table-cell">$<?= number_format($s['cuota_semanal'],2) ?></td>
                            <td class="d-none d-md-table-cell">
                              <div class="progress">
                                <div class="progress-bar <?= $cumpl>=100?'bg-success':($cumpl>=60?'bg-warning':'bg-danger') ?>"
                                     style="width:<?= min(100,$cumpl) ?>%"><?= $cumpl ?>%</div>
                              </div>
                            </td>

                            <!-- Móvil (Uds, $, %, Prep, Pos) -->
                            <td class="d-table-cell d-md-none text-center"><?= (int)$s['unidades'] ?></td>
                            <td class="d-table-cell d-md-none text-center">$<?= number_format($s['total_ventas'],0) ?></td>
                            <td class="d-table-cell d-md-none text-center"><?= $cumpl ?>%</td>
                            <td class="d-table-cell d-md-none text-center"><?= (int)$s['sims_prepago'] ?></td>
                            <td class="d-table-cell d-md-none text-center"><?= (int)$s['sims_pospago'] ?></td>
                          </tr>
                          <?php endforeach;?>
                        </tbody>
                      </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Ejecutivos -->
        <div class="tab-pane fade" id="ejecutivos">
            <div class="card mb-4 shadow">
                <div class="card-header bg-dark text-white position-relative">
                    Ejecutivos (Propias)
                    <button class="btn btn-sm btn-light btn-download position-absolute top-50 end-0 translate-middle-y me-2"
                            onclick="descargarTabla('wrapEjecutivos','ejecutivos_propias_semana.png')" title="Descargar imagen">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M9.4 4l1.2-2h2.8l1.2 2H19a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h4.4zM12 18a5 5 0 1 0 0-10 5 5 0 0 0 0 10zm0-2.2a2.8 2.8 0 1 1 0-5.6 2.8 2.8 0 0 1 0 5.6z"/></svg>
                    </button>
                </div>
                <div class="card-body p-0" id="wrapEjecutivos">
                  <div class="table-responsive">
                    <table class="table table-striped table-bordered mb-0" id="tblEjecutivos">
                      <thead class="table-dark">
                        <tr>
                          <th>Ejecutivo</th>
                          <th class="d-none d-md-table-cell">Sucursal</th>
                          <th class="d-none d-md-table-cell">Tipo</th>

                          <!-- Desktop (Uds, Ventas $, % Cumpl., Prep, Pos, Cuota (u), Progreso) -->
                          <th class="d-none d-md-table-cell">Unidades</th>
                          <th class="d-none d-md-table-cell">Ventas $</th>
                          <th class="d-none d-md-table-cell">% Cumpl.</th>
                          <th class="d-none d-md-table-cell">Prep</th>
                          <th class="d-none d-md-table-cell">Pos</th>
                          <th class="d-none d-md-table-cell">Cuota (u)</th>
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
                        <?php foreach ($rankingEjecutivos as $r):
                            $cumpl = round($r['cumplimiento'],1);
                            $estado = $cumpl>=100?"✅":($cumpl>=60?"⚠️":"❌");
                            $fila = $cumpl>=100?"table-success":($cumpl>=60?"table-warning":"table-danger");
                            $iconTop = in_array($r['id'],$top3Ejecutivos) ? ' 🏆' : '';
                            $iconCrown = ($cumpl >= 100) ? ' 👑' : '';
                            $sucVis = limpiarNombreSucursal($r['sucursal']);
                        ?>
                        <tr class="<?= $fila ?>">
                          <td><?= htmlspecialchars($r['nombre']).$iconTop.$iconCrown ?></td>
                          <td class="d-none d-md-table-cell"><?= htmlspecialchars($sucVis) ?></td>
                          <td class="d-none d-md-table-cell"><?= htmlspecialchars($r['subtipo']) ?></td>

                          <!-- Desktop -->
                          <td class="d-none d-md-table-cell"><?= (int)$r['unidades'] ?></td>
                          <td class="d-none d-md-table-cell">$<?= number_format($r['total_ventas'],2) ?></td>
                          <td class="d-none d-md-table-cell"><?= $cumpl ?>% <?= $estado ?></td>
                          <td class="d-none d-md-table-cell"><?= (int)$r['sims_prepago'] ?></td>
                          <td class="d-none d-md-table-cell"><?= (int)$r['sims_pospago'] ?></td>
                          <td class="d-none d-md-table-cell"><?= (int)$r['cuota_ejecutivo'] ?></td>
                          <td class="d-none d-md-table-cell">
                            <div class="progress">
                              <div class="progress-bar <?= $cumpl>=100?'bg-success':($cumpl>=60?'bg-warning':'bg-danger') ?>"
                                   style="width:<?= min(100,$cumpl) ?>%"><?= $cumpl ?>%</div>
                            </div>
                          </td>

                          <!-- Móvil (Uds, $, %, Prep, Pos) -->
                          <td class="d-table-cell d-md-none text-center"><?= (int)$r['unidades'] ?></td>
                          <td class="d-table-cell d-md-none text-center">$<?= number_format($r['total_ventas'],0) ?></td>
                          <td class="d-table-cell d-md-none text-center"><?= $cumpl ?>%</td>
                          <td class="d-table-cell d-md-none text-center"><?= (int)$r['sims_prepago'] ?></td>
                          <td class="d-table-cell d-md-none text-center"><?= (int)$r['sims_pospago'] ?></td>
                        </tr>
                        <?php endforeach;?>
                      </tbody>
                    </table>
                  </div>
                </div>
            </div>
        </div>

        <!-- Master Admin -->
        <div class="tab-pane fade" id="masteradmin">
            <div class="card mb-4 shadow">
                <div class="card-header bg-dark text-white position-relative">
                    Master Admin (sin cuota)
                    <button class="btn btn-sm btn-light btn-download position-absolute top-50 end-0 translate-middle-y me-2"
                            onclick="descargarTabla('wrapMA','master_admin_semana.png')" title="Descargar imagen">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M9.4 4l1.2-2h2.8l1.2 2H19a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h4.4zM12 18a5 5 0 1 0 0-10 5 5 0 0 0 0 10zm0-2.2a2.8 2.8 0 1 1 0-5.6 2.8 2.8 0 0 1 0 5.6z"/></svg>
                    </button>
                </div>
                <div class="card-body p-0" id="wrapMA">
                  <div class="table-responsive">
                    <table class="table table-striped table-bordered mb-0" id="tblMA">
                        <thead class="table-dark">
                            <tr>
                                <th>Sucursal</th>
                                <th class="d-none d-md-table-cell">Tipo</th>

                                <!-- Desktop: Uds, Ventas $, Prep, Pos -->
                                <th class="d-none d-md-table-cell">Unidades</th>
                                <th class="d-none d-md-table-cell">Ventas $</th>
                                <th class="d-none d-md-table-cell">Prep</th>
                                <th class="d-none d-md-table-cell">Pos</th>

                                <!-- Móvil: Uds, $, Prep, Pos -->
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
                                <td class="d-none d-md-table-cell"><?= htmlspecialchars($s['subtipo']) ?></td>

                                <!-- Desktop -->
                                <td class="d-none d-md-table-cell"><?= (int)$s['unidades'] ?></td>
                                <td class="d-none d-md-table-cell">$<?= number_format($s['total_ventas'],2) ?></td>
                                <td class="d-none d-md-table-cell"><?= (int)$s['sims_prepago'] ?></td>
                                <td class="d-none d-md-table-cell"><?= (int)$s['sims_pospago'] ?></td>

                                <!-- Móvil -->
                                <td class="d-table-cell d-md-none text-center"><?= (int)$s['unidades'] ?></td>
                                <td class="d-table-cell d-md-none text-center">$<?= number_format($s['total_ventas'],0) ?></td>
                                <td class="d-table-cell d-md-none text-center"><?= (int)$s['sims_prepago'] ?></td>
                                <td class="d-table-cell d-md-none text-center"><?= (int)$s['sims_pospago'] ?></td>
                            </tr>
                            <?php endforeach;?>
                        </tbody>
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

const yTickFormatter = (v) => {
  if (metric === 'monto') {
    return '$' + Number(v).toLocaleString();
  }
  return Number(v).toLocaleString('es-MX');
}
const tooltipLabel = (ctx) => {
  const val = Number(ctx.parsed.y);
  if (metric === 'monto') {
    return ` Monto: $${val.toLocaleString()}`;
  }
  return ` Unidades: ${val.toLocaleString('es-MX')}`;
}

new Chart(document.getElementById('chartSemanal').getContext('2d'), {
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

/* ===== Descargar imagen de la tabla ===== */
function descargarTabla(wrapperId, filename){
  const node = document.getElementById(wrapperId);
  if(!node) return;
  const prevBg = node.style.backgroundColor;
  node.style.backgroundColor = '#ffffff';
  html2canvas(node, {scale: 2, useCORS: true}).then(canvas => {
    node.style.backgroundColor = prevBg || '';
    const link = document.createElement('a');
    link.download = filename || 'tabla.png';
    link.href = canvas.toDataURL('image/png');
    link.click();
  });
}
</script>
</body>
</html>
