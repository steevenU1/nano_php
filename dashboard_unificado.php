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
   Dashboard Ejecutivos (semanal)
================================= */
$sqlEjecutivos = "
    SELECT 
        u.id, u.nombre, u.rol, s.nombre AS sucursal,
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
                WHEN v.tipo_venta='Financiamiento+Combo' 
                     AND dv.id = (SELECT MIN(dv2.id) FROM detalle_venta dv2 WHERE dv2.id_venta = v.id)
                     THEN 2
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
    WHERE s.tipo_sucursal='Tienda' AND u.activo = 1
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
   Dashboard Sucursales (semanal)
================================= */
$sqlSucursales = "
    SELECT s.id AS id_sucursal, s.nombre AS sucursal, s.zona,
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
                    WHEN v.tipo_venta='Financiamiento+Combo' 
                         AND dv.id = (SELECT MIN(dv2.id) FROM detalle_venta dv2 WHERE dv2.id_venta = v.id)
                         THEN 2
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
    WHERE s.tipo_sucursal='Tienda'
    GROUP BY s.id
    ORDER BY total_ventas DESC
";
$stmt2 = $conn->prepare($sqlSucursales);
$stmt2->bind_param("sss", $inicioSemana, $inicioSemana, $finSemana);
$stmt2->execute();
$resSucursales = $stmt2->get_result();

$sucursales = [];
$totalUnidades = 0;
$totalVentasGlobal = 0;
$totalCuotaGlobal = 0;

while ($row = $resSucursales->fetch_assoc()) {
    $row['unidades']      = (int)$row['unidades'];
    $row['total_ventas']  = (float)$row['total_ventas'];
    $row['cuota_semanal'] = (float)$row['cuota_semanal'];
    $row['cumplimiento']  = $row['cuota_semanal']>0 ? ($row['total_ventas']/$row['cuota_semanal']*100) : 0;

    $sucursales[] = $row;
    $totalUnidades      += $row['unidades'];
    $totalVentasGlobal  += $row['total_ventas'];
    $totalCuotaGlobal   += $row['cuota_semanal'];
}
$porcentajeGlobal = $totalCuotaGlobal>0 ? ($totalVentasGlobal/$totalCuotaGlobal)*100 : 0;

/* ==============================
   Agrupación por Zonas
================================= */
$zonas = [];
foreach ($sucursales as $s) {
    $z = $s['zona'];
    if (!isset($zonas[$z])) $zonas[$z] = ['unidades'=>0,'ventas'=>0,'cuota'=>0];
    $zonas[$z]['unidades'] += $s['unidades'];
    $zonas[$z]['ventas']   += $s['total_ventas'];
    $zonas[$z]['cuota']    += $s['cuota_semanal'];
}
foreach ($zonas as $z => &$info) {
    $info['cumplimiento'] = $info['cuota']>0 ? ($info['ventas']/$info['cuota']*100) : 0;
}
unset($info);

/* ==========================================================
   📈 Serie SEMANAL (diaria mar–lun) por sucursal — TODAS
   Días en español y altura reducida en el canvas
========================================================== */
// Labels semana (mar–lun) en español
$labelsSemanaISO = [];
$labelsSemanaVis = [];
$diasES = [1=>'Lun','Mar','Mié','Jue','Vie','Sáb','Dom']; // 1=Lun ... 7=Dom
$cur = clone $inicioObj;
for ($i=0; $i<7; $i++) {
    $labelsSemanaISO[] = $cur->format('Y-m-d');
    $labelsSemanaVis[] = $diasES[(int)$cur->format('N')] . ' ' . $cur->format('d/m');
    $cur->modify('+1 day');
}

// Unidades por sucursal/día
$sqlWeek = "
SELECT s.nombre AS sucursal,
       DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) AS dia,
       SUM(CASE 
             WHEN dv.id IS NULL THEN 0
             WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0
             WHEN v.tipo_venta='Financiamiento+Combo' 
                  AND dv.id = (SELECT MIN(dv2.id) FROM detalle_venta dv2 WHERE dv2.id_venta=v.id)
                  THEN 2
             ELSE 1
           END) AS unidades
FROM sucursales s
LEFT JOIN ventas v
  ON v.id_sucursal = s.id
 AND DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
LEFT JOIN productos p ON p.id = dv.id_producto
WHERE s.tipo_sucursal='Tienda'
GROUP BY s.id, dia
";
$stmtW = $conn->prepare($sqlWeek);
$stmtW->bind_param("ss", $inicioSemana, $finSemana);
$stmtW->execute();
$resW = $stmtW->get_result();

$weekSeries = [];   // [sucursal => [dateISO=>unid]]
while ($r = $resW->fetch_assoc()) {
    $suc = $r['sucursal'];
    $dia = $r['dia'];
    $u   = (int)$r['unidades'];
    if (!isset($weekSeries[$suc])) $weekSeries[$suc] = [];
    if ($dia) $weekSeries[$suc][$dia] = $u;
}
// Garantiza todas las sucursales (cero si no vendieron)
foreach ($sucursales as $s) {
    if (!isset($weekSeries[$s['sucursal']])) $weekSeries[$s['sucursal']] = [];
}

// Datasets Chart.js (todas las sucursales)
$datasetsWeek = [];
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
    <title>Dashboard Semanal Luga</title>
    <link rel="icon" type="image/x-icon" href="./img/favicon.ico">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>📊 Dashboard Semanal Luga</h2>

    <!-- Selector de semana -->
    <form method="GET" class="mb-3">
        <label><strong>Selecciona semana:</strong></label>
        <select name="semana" class="form-select w-auto d-inline-block" onchange="this.form.submit()">
            <?php for ($i=0; $i<8; $i++):
                list($ini, $fin) = obtenerSemanaPorIndice($i);
                $texto = "Semana del {$ini->format('d/m/Y')} al {$fin->format('d/m/Y')}";
            ?>
            <option value="<?= $i ?>" <?= $i==$semanaSeleccionada?'selected':'' ?>><?= $texto ?></option>
            <?php endfor; ?>
        </select>
    </form>

    <!-- Tarjetas de Zonas y Global -->
    <div class="row mb-4">
        <?php foreach ($zonas as $zona => $info): ?>
            <div class="col-md-4 mb-3">
                <div class="card shadow text-center">
                    <div class="card-header bg-dark text-white">Zona <?= $zona ?></div>
                    <div class="card-body">
                        <h5><?= number_format($info['cumplimiento'],1) ?>% Cumplimiento</h5>
                        <p>
                            Unidades: <?= $info['unidades'] ?><br>
                            Ventas: $<?= number_format($info['ventas'],2) ?><br>
                            Cuota: $<?= number_format($info['cuota'],2) ?>
                        </p>
                        <div class="progress" style="height:20px">
                            <div class="progress-bar <?= $info['cumplimiento']>=100?'bg-success':($info['cumplimiento']>=60?'bg-warning':'bg-danger') ?>"
                                 style="width:<?= min(100,$info['cumplimiento']) ?>%">
                                <?= number_format(min(100,$info['cumplimiento']),1) ?>%
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        <div class="col-md-4 mb-3">
            <div class="card shadow text-center">
                <div class="card-header bg-primary text-white">Global Compañía</div>
                <div class="card-body">
                    <h5><?= number_format($porcentajeGlobal,1) ?>% Cumplimiento</h5>
                    <p>
                        Unidades: <?= $totalUnidades ?><br>
                        Ventas: $<?= number_format($totalVentasGlobal,2) ?><br>
                        Cuota: $<?= number_format($totalCuotaGlobal,2) ?>
                    </p>
                    <div class="progress" style="height:20px">
                        <div class="progress-bar <?= $porcentajeGlobal>=100?'bg-success':($porcentajeGlobal>=60?'bg-warning':'bg-danger') ?>"
                             style="width:<?= min(100,$porcentajeGlobal) ?>%">
                            <?= number_format(min(100,$porcentajeGlobal),1) ?>%
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 👇 Gráfica semanal (todas las sucursales) con altura reducida -->
    <div class="card shadow mb-4">
        <div class="card-header bg-dark text-white">Comportamiento Semanal por Sucursal (mar–lun)</div>
        <div class="card-body">
            <div style="position:relative; height:220px;">
                <canvas id="chartSemanal"></canvas>
            </div>
            <small class="text-muted d-block mt-2">* Toca los nombres en la leyenda para ocultar/mostrar sucursales.</small>
        </div>
    </div>

    <!-- Tablas existentes -->
    <ul class="nav nav-tabs mb-3" id="dashboardTabs">
        <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#ejecutivos">Ejecutivos 👔</button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#sucursales">Sucursales 🏢</button>
        </li>
    </ul>

    <div class="tab-content">
        <!-- Ejecutivos -->
        <div class="tab-pane fade show active" id="ejecutivos">
            <div class="card mb-4 shadow">
                <div class="card-header bg-dark text-white">Ranking de Ejecutivos</div>
                <div class="card-body">
                    <table class="table table-striped table-bordered">
                        <thead class="table-dark">
                            <tr>
                                <th>Ejecutivo</th><th>Sucursal</th><th>Unidades</th>
                                <th>Total Ventas ($)</th><th>% Cumplimiento</th><th>Progreso</th>
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
                                <td><?= $r['nombre'].$iconTop.$iconCrown ?></td>
                                <td><?= $r['sucursal'] ?></td>
                                <td><?= $r['unidades'] ?></td>
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

        <!-- Sucursales -->
        <div class="tab-pane fade" id="sucursales">
            <div class="card mb-4 shadow">
                <div class="card-header bg-dark text-white">Ranking de Sucursales</div>
                <div class="card-body">
                    <table class="table table-striped table-bordered">
                        <thead class="table-dark">
                            <tr>
                                <th>Sucursal</th><th>Zona</th><th>Unidades</th>
                                <th>Cuota ($)</th><th>Total Ventas ($)</th><th>% Cumplimiento</th><th>Progreso</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sucursales as $s):
                                $cumpl = round($s['cumplimiento'],1);
                                $estado = $cumpl>=100?"✅":($cumpl>=60?"⚠️":"❌");
                                $fila = $cumpl>=100?"table-success":($cumpl>=60?"table-warning":"table-danger");
                            ?>
                            <tr class="<?= $fila ?>">
                                <td><?= $s['sucursal'] ?></td>
                                <td>Zona <?= $s['zona'] ?></td>
                                <td><?= $s['unidades'] ?></td>
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
    </div>
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script> -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Datos para la gráfica semanal (todas las sucursales)
const labelsSemana = <?= json_encode($labelsSemanaVis, JSON_UNESCAPED_UNICODE) ?>;
const datasetsWeek = <?= json_encode($datasetsWeek, JSON_UNESCAPED_UNICODE) ?>;

new Chart(document.getElementById('chartSemanal').getContext('2d'), {
  type: 'line',
  data: { labels: labelsSemana, datasets: datasetsWeek },
  options: {
    responsive: true,
    maintainAspectRatio: false, // usa la altura del contenedor (220px)
    interaction: { mode: 'index', intersect: false },
    plugins: { title: { display: false }, legend: { position: 'bottom' } },
    scales: { y: { beginAtZero: true, title: { display: true, text: 'Unidades' } } }
  }
});
</script>
</body>
</html>
