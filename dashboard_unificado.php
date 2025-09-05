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

$semanaSeleccionada = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
list($inicioObj, $finObj) = obtenerSemanaPorIndice($semanaSeleccionada);
$inicioSemana = $inicioObj->format('Y-m-d');
$finSemana    = $finObj->format('Y-m-d');

/* ==============================
   Ejecutivos (solo Propias)
   FIX: combo -> 2 solo en MIN(dv.id); MiFi/Modem fuera
   FIX: ventas $ desde cabecera v.precio_venta (una vez por venta en MIN dv)
   + SIM Prepago / SIM Pospago desde ventas_sims
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

        /* ===== SIMS: por usuario en semana ===== */
        (
          SELECT COUNT(*)
          FROM ventas_sims vs
          WHERE vs.id_usuario = u.id
            AND DATE(CONVERT_TZ(vs.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
            AND LOWER(vs.tipo_venta) LIKE '%pospago%'
        ) AS sims_pospago,
        (
          SELECT COUNT(*)
          FROM ventas_sims vs
          WHERE vs.id_usuario = u.id
            AND DATE(CONVERT_TZ(vs.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
            AND LOWER(vs.tipo_venta) NOT LIKE '%pospago%'
            AND LOWER(vs.tipo_venta) NOT LIKE '%regalo%'
        ) AS sims_prepago

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
/* Bind:
   1-2  cuota_ejecutivo ventana
   3-4  sims_pospago
   5-6  sims_prepago
   7-8  ventas (cabecera)
*/
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
   Sucursales (Propias)
   FIX: combo -> 2 solo en MIN(dv.id); MiFi/Modem fuera
   FIX: ventas $ desde cabecera v.precio_venta (una vez por venta en MIN dv)
   Orden: monto DESC
   + SIM Prepago / SIM Pospago desde ventas_sims
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

           /* ===== SIMS: por sucursal en semana ===== */
           (
             SELECT COUNT(*)
             FROM ventas_sims vs
             WHERE vs.id_sucursal = s.id
               AND DATE(CONVERT_TZ(vs.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
               AND LOWER(vs.tipo_venta) LIKE '%pospago%'
           ) AS sims_pospago,
           (
             SELECT COUNT(*)
             FROM ventas_sims vs
             WHERE vs.id_sucursal = s.id
               AND DATE(CONVERT_TZ(vs.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
               AND LOWER(vs.tipo_venta) NOT LIKE '%pospago%'
               AND LOWER(vs.tipo_venta) NOT LIKE '%regalo%'
           ) AS sims_prepago

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
/* Bind:
   1    cuota_semanal lookup (usa solo inicio)
   2-3  sims_pospago
   4-5  sims_prepago
   6-7  ventas cabecera ventana
*/
$stmt2->bind_param("sssssss", 
    $inicioSemana,             // cuota_semanal
    $inicioSemana, $finSemana, // sims_pospago
    $inicioSemana, $finSemana, // sims_prepago
    $inicioSemana, $finSemana  // ventas cabecera
);
$stmt2->execute();
$resSucursalesPropias = $stmt2->get_result();

$sucursales = [];
$totalUnidadesPropias = 0;
$totalVentasPropias   = 0;
$totalCuotaPropias    = 0;
while ($row = $resSucursalesPropias->fetch_assoc()) {
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
   Sucursales (Master Admin) — SIN cuota
   (incluye patrón anti-duplicado nano en monto y unidades)
   Orden: monto DESC
   + SIM Prepago / SIM Pospago desde ventas_sims
================================= */
$sqlSucursalesMA = "
    SELECT 
        s.id AS id_sucursal,
        s.nombre AS sucursal,
        s.subtipo AS subtipo,

        -- Unidades (nano por venta; combo=2 una sola vez)
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

        -- Monto (cabecera v.precio_venta una vez en MIN dv)
        IFNULL(SUM(
            CASE 
                WHEN v.id IS NULL THEN 0
                WHEN dv.id IS NULL THEN 0
                WHEN dv.id = (SELECT MIN(dv3.id) FROM detalle_venta dv3 WHERE dv3.id_venta = v.id)
                    THEN IFNULL(v.precio_venta,0)
                ELSE 0
            END
        ),0) AS total_ventas,

        /* ===== SIMS: por sucursal MA en semana ===== */
        (
          SELECT COUNT(*)
          FROM ventas_sims vs
          WHERE vs.id_sucursal = s.id
            AND DATE(CONVERT_TZ(vs.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
            AND LOWER(vs.tipo_venta) LIKE '%pospago%'
        ) AS sims_pospago,
        (
          SELECT COUNT(*)
          FROM ventas_sims vs
          WHERE vs.id_sucursal = s.id
            AND DATE(CONVERT_TZ(vs.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
            AND LOWER(vs.tipo_venta) NOT LIKE '%pospago%'
            AND LOWER(vs.tipo_venta) NOT LIKE '%regalo%'
        ) AS sims_prepago

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
/* Bind:
   1-2 sims_pospago
   3-4 sims_prepago
   5-6 ventas cabecera
*/
$stmtMA->bind_param("ssssss", 
    $inicioSemana, $finSemana,  // sims_pospago
    $inicioSemana, $finSemana,  // sims_prepago
    $inicioSemana, $finSemana   // ventas cabecera
);
$stmtMA->execute();
$resSucursalesMA = $stmtMA->get_result();

$sucursalesMA = [];
$totalUnidadesMA = 0;
$totalVentasMA   = 0;
while ($row = $resSucursalesMA->fetch_assoc()) {
    $row['unidades']      = (int)$row['unidades'];
    $row['total_ventas']  = (float)$row['total_ventas'];
    $row['sims_prepago']  = (int)$row['sims_prepago'];
    $row['sims_pospago']  = (int)$row['sims_pospago'];
    $sucursalesMA[]       = $row;
    $totalUnidadesMA     += $row['unidades'];
    $totalVentasMA       += $row['total_ventas'];
}

/* ==========================================================
   GRÁFICO: BARRAS (ACUMULADO SEMANAL POR MONTO)
   Toggle g=propias | ma en la esquina del card
========================================================== */
$g = $_GET['g'] ?? 'propias';
if ($g !== 'propias' && $g !== 'ma') { $g = 'propias'; }

if ($g === 'propias') {
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
             ) AS total_ventas
      FROM sucursales s
      LEFT JOIN ventas v
        ON v.id_sucursal = s.id
       AND DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
      LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
      WHERE s.tipo_sucursal='Tienda' AND s.subtipo='Propia'
      GROUP BY s.id
    ";
} else { // ma
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
             ) AS total_ventas
      FROM sucursales s
      LEFT JOIN ventas v
        ON v.id_sucursal = s.id
       AND DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
      LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
      WHERE s.tipo_sucursal='Tienda' AND s.subtipo='Master Admin'
      GROUP BY s.id
    ";
}
$stmtC = $conn->prepare($sqlChart);
$stmtC->bind_param("ss", $inicioSemana, $finSemana);
$stmtC->execute();
$resChart = $stmtC->get_result();

$rowsChart = [];
while ($r = $resChart->fetch_assoc()) {
    $r['total_ventas'] = (float)$r['total_ventas'];
    $rowsChart[] = $r;
}
usort($rowsChart, fn($a,$b) => $b['total_ventas'] <=> $a['total_ventas']);
$top = array_slice($rowsChart, 0, 15);
$otrasVal = 0;
for ($i=15; $i<count($rowsChart); $i++) { $otrasVal += $rowsChart[$i]['total_ventas']; }

$labelsGraf = array_map(fn($r)=>$r['sucursal'],$top);
$dataGraf   = array_map(fn($r)=>round($r['total_ventas'],2),$top);
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
    <link rel="icon" type="image/x-icon" href="./img/favicon.ico">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>📊 Dashboard Semanal Nano</h2>

    <!-- Selector de semana (global, no toca tablas) -->
    <form method="GET" class="mb-3">
        <label class="me-2"><strong>Semana:</strong></label>
        <select name="semana" class="form-select w-auto d-inline-block me-3" onchange="this.form.submit()">
            <?php for ($i=0; $i<8; $i++):
                list($ini, $fin) = obtenerSemanaPorIndice($i);
                $texto = "Del {$ini->format('d/m/Y')} al {$fin->format('d/m/Y')}";
            ?>
            <option value="<?= $i ?>" <?= $i==$semanaSeleccionada?'selected':'' ?>><?= $texto ?></option>
            <?php endfor; ?>
        </select>
        <!-- preservar el grupo al cambiar semana -->
        <input type="hidden" name="g" value="<?= htmlspecialchars($g) ?>">
    </form>

    <!-- Tarjetas: Propias | Master Admin | Global (Propias) -->
    <div class="row mb-4">
        <!-- Propias -->
        <div class="col-md-4 mb-3">
            <div class="card shadow text-center">
                <div class="card-header bg-dark text-white">Propias</div>
                <div class="card-body">
                    <h5><?= number_format($porcentajeGlobalPropias,1) ?>% Cumplimiento</h5>
                    <p class="mb-2">
                        Unidades: <?= $totalUnidadesPropias ?><br>
                        Ventas: $<?= number_format($totalVentasPropias,2) ?><br>
                        Cuota: $<?= number_format($totalCuotaPropias,2) ?>
                    </p>
                    <div class="progress" style="height:20px">
                        <div class="progress-bar <?= $porcentajeGlobalPropias>=100?'bg-success':($porcentajeGlobalPropias>=60?'bg-warning':'bg-danger') ?>"
                             style="width:<?= min(100,$porcentajeGlobalPropias) ?>%">
                            <?= number_format(min(100,$porcentajeGlobalPropias),1) ?>%
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Master Admin -->
        <div class="col-md-4 mb-3">
            <div class="card shadow text-center">
                <div class="card-header bg-secondary text-white">Master Admin</div>
                <div class="card-body">
                    <h5>Sin cuota</h5>
                    <p class="mb-0">
                        Unidades: <?= $totalUnidadesMA ?><br>
                        Ventas: $<?= number_format($totalVentasMA,2) ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Global (Propias) -->
        <div class="col-md-4 mb-3">
            <div class="card shadow text-center">
                <div class="card-header bg-primary text-white">🌐 Global (Propias)</div>
                <div class="card-body">
                    <h5><?= number_format($porcentajeGlobalPropias,1) ?>% Cumplimiento</h5>
                    <p class="mb-2">
                        Unidades: <?= $totalUnidadesPropias ?><br>
                        Ventas: $<?= number_format($totalVentasPropias,2) ?><br>
                        Cuota: $<?= number_format($totalCuotaPropias,2) ?>
                    </p>
                    <div class="progress" style="height:20px">
                        <div class="progress-bar <?= $porcentajeGlobalPropias>=100?'bg-success':($porcentajeGlobalPropias>=60?'bg-warning':'bg-danger') ?>"
                             style="width:<?= min(100,$porcentajeGlobalPropias) ?>%">
                            <?= number_format(min(100,$porcentajeGlobalPropias),1) ?>%
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Gráfica semanal (barras, monto) -->
    <div class="card shadow mb-4">
        <div class="card-header bg-dark text-white position-relative d-flex align-items-center">
          <span>Resumen semanal por sucursal (monto)</span>
          <!-- Toggle en esquina -->
          <div class="position-absolute top-50 end-0 translate-middle-y pe-2">
            <div class="btn-group btn-group-sm" role="group" aria-label="Grupo gráfico">
              <a class="btn <?= $g==='propias'?'btn-primary':'btn-outline-light' ?>"
                 href="?semana=<?= $semanaSeleccionada ?>&g=propias">Propias</a>
              <a class="btn <?= $g==='ma'?'btn-primary':'btn-outline-light' ?>"
                 href="?semana=<?= $semanaSeleccionada ?>&g=ma">Master Admin</a>
            </div>
          </div>
        </div>
        <div class="card-body">
            <div style="position:relative; height:320px;">
                <canvas id="chartSemanal"></canvas>
            </div>
            <small class="text-muted d-block mt-2">* Se muestran Top-15 sucursales por monto semanal + “Otras”.</small>
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
                <div class="card-header bg-dark text-white">Sucursales (Propias)</div>
                <div class="card-body">
                    <table class="table table-striped table-bordered">
                        <thead class="table-dark">
                            <tr>
                                <th>Sucursal</th>
                                <th>Tipo</th>
                                <th>Unidades</th>
                                <th>SIM Prepago</th>
                                <th>SIM Pospago</th>
                                <th>Cuota $</th>
                                <th>Ventas $</th>
                                <th>% Cumplimiento</th>
                                <th>Progreso</th>
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
                                <td><?= htmlspecialchars($s['subtipo']) ?></td>
                                <td><?= (int)$s['unidades'] ?></td>
                                <td><?= (int)$s['sims_prepago'] ?></td>
                                <td><?= (int)$s['sims_pospago'] ?></td>
                                <td>$<?= number_format($s['cuota_semanal'],2) ?></td>
                                <td>$<?= number_format($s['total_ventas'],2) ?></td>
                                <td><?= $cumpl ?>% <?= $estado ?></td>
                                <td>
                                    <div class="progress" style="height:20px">
                                        <div class="progress-bar <?= $cumpl>=100?'bg-success':($cumpl>=60?'bg-warning':'bg-danger') ?>"
                                            style="width:<?= min(100,$cumpl) ?>%"><?= $cumpl ?>%</div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach;?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Ejecutivos -->
        <div class="tab-pane fade" id="ejecutivos">
            <div class="card mb-4 shadow">
                <div class="card-header bg-dark text-white">Ejecutivos (Propias)</div>
                <div class="card-body">
                    <table class="table table-striped table-bordered">
                        <thead class="table-dark">
                            <tr>
                                <th>Ejecutivo</th>
                                <th>Sucursal</th>
                                <th>Tipo</th>
                                <th>Unidades</th>
                                <th>SIM Prepago</th>
                                <th>SIM Pospago</th>
                                <th>Ventas $</th>
                                <th>% Cumplimiento</th>
                                <th>Progreso</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rankingEjecutivos as $r):
                                $cumpl = round($r['cumplimiento'],1);
                                $estado = $cumpl>=100?"✅":($cumpl>=60?"⚠️":"❌");
                                $fila = $cumpl>=100?"table-success":($cumpl>=60?"table-warning":"table-danger");
                                $iconTop = in_array($r['id'],$top3Ejecutivos) ? ' 🏆' : '';
                                $iconCrown = ($cumpl >= 100) ? ' 👑' : '';
                            ?>
                            <tr class="<?= $fila ?>">
                                <td><?= htmlspecialchars($r['nombre']).$iconTop.$iconCrown ?></td>
                                <td><?= htmlspecialchars($r['sucursal']) ?></td>
                                <td><?= htmlspecialchars($r['subtipo']) ?></td>
                                <td><?= (int)$r['unidades'] ?></td>
                                <td><?= (int)$r['sims_prepago'] ?></td>
                                <td><?= (int)$r['sims_pospago'] ?></td>
                                <td>$<?= number_format($r['total_ventas'],2) ?></td>
                                <td><?= $cumpl ?>% <?= $estado ?></td>
                                <td>
                                    <div class="progress" style="height:20px">
                                        <div class="progress-bar <?= $cumpl>=100?'bg-success':($cumpl>=60?'bg-warning':'bg-danger') ?>"
                                            style="width:<?= min(100,$cumpl) ?>%"><?= $cumpl ?>%</div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach;?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Master Admin -->
        <div class="tab-pane fade" id="masteradmin">
            <div class="card mb-4 shadow">
                <div class="card-header bg-dark text-white">Master Admin (sin cuota)</div>
                <div class="card-body">
                    <table class="table table-striped table-bordered">
                        <thead class="table-dark">
                            <tr>
                                <th>Sucursal</th>
                                <th>Tipo</th>
                                <th>Unidades</th>
                                <th>SIM Prepago</th>
                                <th>SIM Pospago</th>
                                <th>Ventas $</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sucursalesMA as $s): ?>
                            <tr>
                                <td><?= htmlspecialchars($s['sucursal']) ?></td>
                                <td><?= htmlspecialchars($s['subtipo']) ?></td>
                                <td><?= (int)$s['unidades'] ?></td>
                                <td><?= (int)$s['sims_prepago'] ?></td>
                                <td><?= (int)$s['sims_pospago'] ?></td>
                                <td>$<?= number_format($s['total_ventas'],2) ?></td>
                            </tr>
                            <?php endforeach;?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const labelsGraf = <?= json_encode($labelsGraf, JSON_UNESCAPED_UNICODE) ?>;
const dataGraf   = <?= json_encode($dataGraf, JSON_UNESCAPED_UNICODE) ?>;

new Chart(document.getElementById('chartSemanal').getContext('2d'), {
  type: 'bar',
  data: { labels: labelsGraf, datasets: [{
    label: 'Ventas ($)',
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
      tooltip: { callbacks: { label: (ctx) => ` Ventas: $${Number(ctx.parsed.y).toLocaleString()}` } }
    },
    scales: {
      x: { ticks: { autoSkip: false, maxRotation: 45, minRotation: 45 } },
      y: {
        beginAtZero: true,
        ticks: { callback: (v) => '$' + Number(v).toLocaleString() },
        title: { display: true, text: 'Ventas ($)' }
      }
    }
  }
});
</script>
</body>
</html>
