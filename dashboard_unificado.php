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
   FIX: combo -> 2 solo en MIN(dv.id); demás dv=0
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
        IFNULL(SUM(dv.precio_unitario),0) AS total_ventas
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
$stmt->bind_param("ssss", $inicioSemana, $finSemana, $inicioSemana, $finSemana);
$stmt->execute();
$resEjecutivos = $stmt->get_result();

$rankingEjecutivos = [];
while ($row = $resEjecutivos->fetch_assoc()) {
    $row['unidades']        = (int)$row['unidades'];
    $row['total_ventas']    = (float)$row['total_ventas'];
    $row['cuota_ejecutivo'] = (int)$row['cuota_ejecutivo'];
    $row['cumplimiento']    = $row['cuota_ejecutivo']>0 ? ($row['unidades']/$row['cuota_ejecutivo']*100) : 0;
    $rankingEjecutivos[]    = $row;
}
$top3Ejecutivos = array_slice(array_column($rankingEjecutivos, 'id'), 0, 3);

/* ==============================
   Sucursales (Propias)
   FIX: combo -> 2 solo en MIN(dv.id); demás dv=0
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
           IFNULL(SUM(CASE WHEN dv.id IS NULL OR LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0 ELSE dv.precio_unitario END),0) AS total_ventas
    FROM sucursales s
    LEFT JOIN (
        SELECT v.id, v.id_sucursal, v.fecha_venta, v.tipo_venta
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
$stmt2->bind_param("sss", $inicioSemana, $inicioSemana, $finSemana);
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

    $sucursales[]          = $row;
    $totalUnidadesPropias += $row['unidades'];
    $totalVentasPropias   += $row['total_ventas'];
    $totalCuotaPropias    += $row['cuota_semanal'];
}
$porcentajeGlobalPropias = $totalCuotaPropias>0 ? ($totalVentasPropias/$totalCuotaPropias)*100 : 0;

/* ==============================
   Sucursales (Master Admin) — SIN cuota
   (esta ya tenía el patrón anti-duplicado para 'nano' en monto; lo replicamos en unidades)
================================= */
$sqlSucursalesMA = "
    SELECT 
        s.id AS id_sucursal,
        s.nombre AS sucursal,
        s.subtipo AS subtipo,

        -- Unidades (FIX nano: una sola vez por venta)
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

        -- Monto (ya con anti-duplicado nano)
        IFNULL(SUM(
            CASE 
                WHEN v.id IS NULL THEN 0
                WHEN v.origen_ma='nano' THEN
                    CASE 
                        WHEN dv.id IS NULL THEN IFNULL(v.precio_venta,0)
                        WHEN dv.id = (SELECT MIN(dv3.id) FROM detalle_venta dv3 WHERE dv3.id_venta = v.id) THEN IFNULL(v.precio_venta,0)
                        ELSE 0
                    END
                ELSE
                    CASE 
                        WHEN dv.id IS NULL OR LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0
                        ELSE IFNULL(dv.precio_unitario,0)
                    END
            END
        ),0) AS total_ventas

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
$stmtMA->bind_param("ss", $inicioSemana, $finSemana);
$stmtMA->execute();
$resSucursalesMA = $stmtMA->get_result();

$sucursalesMA = [];
$totalUnidadesMA = 0;
$totalVentasMA   = 0;
while ($row = $resSucursalesMA->fetch_assoc()) {
    $row['unidades']      = (int)$row['unidades'];
    $row['total_ventas']  = (float)$row['total_ventas'];
    $sucursalesMA[]       = $row;
    $totalUnidadesMA     += $row['unidades'];
    $totalVentasMA       += $row['total_ventas'];
}

/* ==========================================================
   Serie semanal (mar–lun) por sucursal — SOLO FILTRO DEL GRÁFICO
   g=propias | ma | todas
   FIX: mismo criterio anti-duplicado para combos y nano
========================================================== */
$g = $_GET['g'] ?? 'todas';

// labels de la semana
$labelsSemanaISO = [];
$labelsSemanaVis = [];
$diasES = [1=>'Lun','Mar','Mié','Jue','Vie','Sáb','Dom']; // 1=Lun ... 7=Dom
$cur = clone $inicioObj;
for ($i=0; $i<7; $i++) {
    $labelsSemanaISO[] = $cur->format('Y-m-d');
    $labelsSemanaVis[] = $diasES[(int)$cur->format('N')] . ' ' . $cur->format('d/m');
    $cur->modify('+1 day');
}

$whereBase = "s.tipo_sucursal='Tienda'";
if ($g === 'propias') {
    $whereBase .= " AND s.subtipo='Propia'";
} elseif ($g === 'ma') {
    $whereBase .= " AND s.subtipo='Master Admin'";
}

if ($g === 'ma') {
    // MA: nano cuenta por venta (MIN dv) y combo=2 una sola vez
    $sqlWeek = "
    SELECT s.id AS id_sucursal, s.nombre AS sucursal,
           DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) AS dia,
           SUM(
             CASE
               WHEN v.id IS NULL THEN 0
               WHEN COALESCE(v.origen_ma,'propio')='nano' THEN
                    CASE
                      WHEN dv.id IS NULL THEN
                        CASE WHEN REPLACE(LOWER(v.tipo_venta),' ','')='financiamiento+combo' THEN 2 ELSE 1 END
                      WHEN dv.id = (SELECT MIN(dv2.id) FROM detalle_venta dv2 WHERE dv2.id_venta=v.id) THEN
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
           ) AS unidades
    FROM sucursales s
    LEFT JOIN ventas v
      ON v.id_sucursal = s.id
     AND DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
    LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
    LEFT JOIN productos p      ON p.id       = dv.id_producto
    WHERE $whereBase
    GROUP BY s.id, dia
    ";
} elseif ($g === 'propias') {
    // Propias: combo=2 solo en MIN(dv), resto 0
    $sqlWeek = "
    SELECT s.id AS id_sucursal, s.nombre AS sucursal,
           DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) AS dia,
           SUM(
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
           ) AS unidades
    FROM sucursales s
    LEFT JOIN ventas v
      ON v.id_sucursal = s.id
     AND DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
    LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
    LEFT JOIN productos p ON p.id = dv.id_producto
    WHERE $whereBase
    GROUP BY s.id, dia
    ";
} else { // todas
    // Mezcla: MA nano por venta; resto como Propias (combo=2 solo en MIN)
    $sqlWeek = "
    SELECT s.id AS id_sucursal, s.nombre AS sucursal,
           DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) AS dia,
           SUM(
             CASE
               WHEN v.id IS NULL THEN 0
               WHEN s.subtipo='Master Admin' AND COALESCE(v.origen_ma,'propio')='nano' THEN
                    CASE
                      WHEN dv.id IS NULL THEN
                        CASE WHEN REPLACE(LOWER(v.tipo_venta),' ','')='financiamiento+combo' THEN 2 ELSE 1 END
                      WHEN dv.id = (SELECT MIN(dv2.id) FROM detalle_venta dv2 WHERE dv2.id_venta=v.id) THEN
                        CASE WHEN REPLACE(LOWER(v.tipo_venta),' ','')='financiamiento+combo' THEN 2 ELSE 1 END
                      ELSE 0
                    END
               ELSE
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
             END
           ) AS unidades
    FROM sucursales s
    LEFT JOIN ventas v
      ON v.id_sucursal = s.id
     AND DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
    LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
    LEFT JOIN productos p ON p.id = dv.id_producto
    WHERE s.tipo_sucursal='Tienda'
    GROUP BY s.id, dia
    ";
}

$stmtW = $conn->prepare($sqlWeek);
$stmtW->bind_param("ss", $inicioSemana, $finSemana);
$stmtW->execute();
$resW = $stmtW->get_result();

// Construir datasets del gráfico
$weekSeries = [];   // [sucursal => [dateISO=>unid]]
while ($r = $resW->fetch_assoc()) {
    $suc = $r['sucursal'];
    $dia = $r['dia'];
    $u   = (int)$r['unidades'];
    if ($dia) {
        if (!isset($weekSeries[$suc])) $weekSeries[$suc] = [];
        $weekSeries[$suc][$dia] = $u;
    }
}
$datasetsWeek = [];
// labels de la semana
$labelsSemanaISO = [];
$labelsSemanaVis = [];
$diasES = [1=>'Lun','Mar','Mié','Jue','Vie','Sáb','Dom']; // 1=Lun ... 7=Dom
$cur = clone $inicioObj;
for ($i=0; $i<7; $i++) {
    $labelsSemanaISO[] = $cur->format('Y-m-d');
    $labelsSemanaVis[] = $diasES[(int)$cur->format('N')] . ' ' . $cur->format('d/m');
    $cur->modify('+1 day');
}
foreach ($weekSeries as $sucursalNombre => $serie) {
    $row = [];
    foreach ($labelsSemanaISO as $d) {
        $row[] = isset($serie[$d]) ? (int)$serie[$d] : 0;
    }
    $datasetsWeek[] = [
        'label'        => $sucursalNombre,
        'data'         => $row,
        'tension'      => 0.3,
        'borderWidth'  => 2,
        'pointRadius'  => 2
    ];
}
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

        <!-- Filtro SOLO para el gráfico -->
        <?php $g = $_GET['g'] ?? 'todas'; ?>
        <label class="me-2"><strong>Gráfico:</strong></label>
        <select name="g" class="form-select w-auto d-inline-block me-2" onchange="this.form.submit()">
            <option value="todas"   <?= $g==='todas'?'selected':'' ?>>Todas</option>
            <option value="propias" <?= $g==='propias'?'selected':'' ?>>Propias</option>
            <option value="ma"      <?= $g==='ma'?'selected':'' ?>>Master Admin</option>
        </select>
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

    <!-- Gráfica semanal -->
    <div class="card shadow mb-4">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
          <span>Comportamiento Semanal por Sucursal (mar–lun)</span>
          <small class="text-white-50">Grupo gráfico: <strong><?= strtoupper(htmlspecialchars($g)) ?></strong></small>
        </div>
        <div class="card-body">
            <div style="position:relative; height:260px;">
                <canvas id="chartSemanal"></canvas>
            </div>
            <small class="text-muted d-block mt-2">* Toca los nombres en la leyenda para ocultar/mostrar sucursales.</small>
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
                                <th>Ventas $</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sucursalesMA as $s): ?>
                            <tr>
                                <td><?= htmlspecialchars($s['sucursal']) ?></td>
                                <td><?= htmlspecialchars($s['subtipo']) ?></td>
                                <td><?= (int)$s['unidades'] ?></td>
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

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script> -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const labelsSemana = <?= json_encode($labelsSemanaVis, JSON_UNESCAPED_UNICODE) ?>;
const datasetsWeek = <?= json_encode($datasetsWeek, JSON_UNESCAPED_UNICODE) ?>;

new Chart(document.getElementById('chartSemanal').getContext('2d'), {
  type: 'line',
  data: { labels: labelsSemana, datasets: datasetsWeek },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: { title: { display: false }, legend: { position: 'bottom' } },
    scales: { y: { beginAtZero: true, title: { display: true, text: 'Unidades' } } }
  }
});
</script>
</body>
</html>
