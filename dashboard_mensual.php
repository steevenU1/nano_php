<?php
session_start();
if (empty($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

// 🔹 Nombre de mes en español
function nombreMes($mes) {
    $meses = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
    return $meses[$mes] ?? '';
}

// 🔹 Mes/Año seleccionados
$mes  = isset($_GET['mes'])  ? (int)$_GET['mes']  : (int)date('n');
$anio = isset($_GET['anio']) ? (int)$_GET['anio'] : (int)date('Y');

// 🔹 Rango del mes
$inicioMes = sprintf('%04d-%02d-01', $anio, $mes);
$finMes    = date('Y-m-t', strtotime($inicioMes));
$diasMes   = (int)date('t', strtotime($inicioMes));
$factorSem = 7 / max(1,$diasMes); // semanas “efectivas” del mes

/* ======================================================
   0) Cuota mensual ejecutivos (POR EJECUTIVO)
====================================================== */
$cuotaMesU_porEj = 0.0;  // unidades / ejecutivo / mes
$cuotaMesM_porEj = 0.0;  // monto $ / ejecutivo / mes
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

$cuotaSemU_porEj = $cuotaMesU_porEj * $factorSem; // (informativo)

/* ======================================================
   1) Sucursales: ventas, unidades, cuotas mensuales
====================================================== */
$sqlSuc = "
    SELECT s.id AS id_sucursal, s.nombre AS sucursal, s.zona,
           IFNULL(SUM(
                CASE 
                    WHEN dv.id IS NULL THEN 0
                    WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0
                    WHEN v.tipo_venta='Financiamiento+Combo' 
                         AND dv.id = (
                             SELECT MIN(dv2.id) FROM detalle_venta dv2 WHERE dv2.id_venta=v.id
                         )
                         THEN 2
                    ELSE 1
                END
           ),0) AS unidades,
           IFNULL(SUM(
                CASE 
                    WHEN dv.id IS NULL THEN 0
                    WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0
                    ELSE dv.precio_unitario
                END
           ),0) AS ventas
    FROM sucursales s
    LEFT JOIN ventas v 
        ON v.id_sucursal = s.id 
        AND DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
    LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
    LEFT JOIN productos p ON p.id = dv.id_producto
    WHERE s.tipo_sucursal='Tienda'
    GROUP BY s.id
    ORDER BY s.zona, s.nombre
";
$stmt = $conn->prepare($sqlSuc);
$stmt->bind_param("ss", $inicioMes, $finMes);
$stmt->execute();
$res = $stmt->get_result();

$sucursales = [];
$totalGlobalUnidades = 0;
$totalGlobalVentas   = 0;
$totalGlobalCuota    = 0;

// Cuotas mensuales por sucursal
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

while ($row = $res->fetch_assoc()) {
    $id_suc = (int)$row['id_sucursal'];
    $cuotaUnidades = $cuotasSuc[$id_suc]['cuota_unidades'] ?? 0;
    $cuotaMonto    = $cuotasSuc[$id_suc]['cuota_monto']    ?? 0;

    $cumpl = $cuotaMonto > 0 ? ($row['ventas']/$cuotaMonto*100) : 0;

    $sucursales[] = [
        'id_sucursal'     => $id_suc,
        'sucursal'        => $row['sucursal'],
        'zona'            => $row['zona'],
        'unidades'        => (int)$row['unidades'],
        'ventas'          => (float)$row['ventas'],
        'cuota_unidades'  => (int)$cuotaUnidades,
        'cuota_monto'     => (float)$cuotaMonto,
        'cumplimiento'    => $cumpl
    ];

    $totalGlobalUnidades += (int)$row['unidades'];
    $totalGlobalVentas   += (float)$row['ventas'];
    $totalGlobalCuota    += (float)$cuotaMonto;
}
$stmt->close();

/* ======================================================
   2) Zonas (agregados)
====================================================== */
$zonas = [];
foreach ($sucursales as $s) {
    $z = $s['zona'];
    if (!isset($zonas[$z])) $zonas[$z] = ['unidades'=>0,'ventas'=>0,'cuota'=>0];
    $zonas[$z]['unidades'] += $s['unidades'];
    $zonas[$z]['ventas']   += $s['ventas'];
    $zonas[$z]['cuota']    += $s['cuota_monto'];
}
$porcentajeGlobal = $totalGlobalCuota > 0 ? ($totalGlobalVentas/$totalGlobalCuota*100) : 0;

/* ======================================================
   3) Ejecutivos: ventas mensuales + % cumplimiento por UNIDADES
====================================================== */
$sqlEj = "
    SELECT 
        u.id,
        u.nombre,
        s.nombre AS sucursal,
        IFNULL(SUM(
            CASE 
                WHEN dv.id IS NULL THEN 0
                WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0
                WHEN v.tipo_venta='Financiamiento+Combo' 
                     AND dv.id = (
                         SELECT MIN(dv2.id) FROM detalle_venta dv2 WHERE dv2.id_venta=v.id
                     )
                     THEN 2
                ELSE 1
            END
        ),0) AS unidades,
        IFNULL(SUM(
            CASE 
                WHEN dv.id IS NULL THEN 0
                WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0
                ELSE dv.precio_unitario
            END
        ),0) AS ventas
    FROM usuarios u
    INNER JOIN sucursales s ON s.id = u.id_sucursal
    LEFT JOIN ventas v 
        ON v.id_usuario = u.id
        AND DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
    LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
    LEFT JOIN productos p ON p.id = dv.id_producto
    WHERE u.activo = 1 AND u.rol='Ejecutivo'
    GROUP BY u.id
    ORDER BY unidades DESC, ventas DESC
";
$stEj = $conn->prepare($sqlEj);
$stEj->bind_param("ss", $inicioMes, $finMes);
$stEj->execute();
$resEj = $stEj->get_result();

$ejecutivos = [];
while ($row = $resEj->fetch_assoc()) {
    $cumpl_uni = $cuotaMesU_porEj>0 ? ($row['unidades']/$cuotaMesU_porEj*100) : null;

    $ejecutivos[] = [
        'id'             => (int)$row['id'],
        'nombre'         => $row['nombre'],
        'sucursal'       => $row['sucursal'],
        'unidades'       => (int)$row['unidades'],
        'ventas'         => (float)$row['ventas'],
        'cuota_unidades' => $cuotaMesU_porEj,
        'cumpl_uni'      => $cumpl_uni,
    ];
}
$stEj->close();

function badgeFila($pct) {
    if ($pct === null) return '';
    return $pct>=100 ? 'table-success' : ($pct>=60 ? 'table-warning' : 'table-danger');
}

/* ======================================================
   4) 📈 Serie MENSUAL por SEMANAS (mar–lun) — TODAS
====================================================== */

// Helper: inicio de semana (martes) para una fecha
function inicioSemanaMartes(DateTime $dt): DateTime {
    $dow = (int)$dt->format('N'); // 1=Lun..7=Dom
    $diff = $dow - 2;            // Martes=2
    if ($diff < 0) $diff += 7;
    $start = clone $dt;
    $start->modify("-{$diff} days")->setTime(0,0,0);
    return $start;
}

// Construir tramos semanales del mes (mar–lun)
$inicioMesDT = new DateTime($inicioMes.' 00:00:00');
$finMesDT    = new DateTime($finMes.' 23:59:59');

$wkStart = inicioSemanaMartes(clone $inicioMesDT); // martes anterior o mismo día
$semanas = []; // ['ini'=>'Y-m-d','fin'=>'Y-m-d','label'=>'Sem N (dd/mm–dd/mm)']
$idx = 1;
while ($wkStart <= $finMesDT) {
    $wkFin = (clone $wkStart)->modify('+6 days')->setTime(23,59,59);
    // recorte al mes para mostrarse en la etiqueta
    $visIni = ($wkStart < $inicioMesDT) ? $inicioMesDT : $wkStart;
    $visFin = ($wkFin   > $finMesDT)    ? $finMesDT    : $wkFin;
    $semanas[] = [
        'ini'   => $wkStart->format('Y-m-d'),
        'fin'   => $wkFin->format('Y-m-d'),
        'label' => sprintf('Sem %d (%s–%s)', $idx, $visIni->format('d/m'), $visFin->format('d/m'))
    ];
    $idx++;
    $wkStart->modify('+7 days')->setTime(0,0,0);
}

// Mapa rápido: día → índice semana
function findWeekIndex(string $dia, array $semanas): ?int {
    foreach ($semanas as $i => $sem) {
        if ($dia >= $sem['ini'] && $dia <= $sem['fin']) return $i;
    }
    return null;
}

// Traer unidades por sucursal y DÍA en el mes, luego agregamos por semana
$sqlMonthDaily = "
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
  AND v.id IS NOT NULL          -- ✅ evita filas con fecha NULL
GROUP BY s.id, dia
";
$stMd = $conn->prepare($sqlMonthDaily);
$stMd->bind_param("ss", $inicioMes, $finMes);
$stMd->execute();
$resMd = $stMd->get_result();

$weeklySeries = []; // [sucursal => [indexSemana => unidades]]
while ($r = $resMd->fetch_assoc()) {
    $suc = $r['sucursal'];
    $dia = $r['dia'];
    if (empty($dia)) {           // ✅ guard clause extra de seguridad
        continue;
    }
    $u   = (int)$r['unidades'];
    $i   = findWeekIndex($dia, $semanas);
    if ($i === null) continue;
    if (!isset($weeklySeries[$suc])) $weeklySeries[$suc] = [];
    if (!isset($weeklySeries[$suc][$i])) $weeklySeries[$suc][$i] = 0;
    $weeklySeries[$suc][$i] += $u;
}
$stMd->close();

// Garantiza que todas las sucursales existan y rellena ceros
$labelsSemanas = array_column($semanas, 'label');
$k = count($labelsSemanas);
foreach ($sucursales as $s) {
    $name = $s['sucursal'];
    if (!isset($weeklySeries[$name])) $weeklySeries[$name] = [];
    for ($i=0; $i<$k; $i++) {
        if (!isset($weeklySeries[$name][$i])) $weeklySeries[$name][$i] = 0;
    }
    ksort($weeklySeries[$name]);
}

// Construye datasets para Chart.js (todas las sucursales)
$datasetsMonth = [];
foreach ($weeklySeries as $sucursalNombre => $serie) {
    $row = [];
    for ($i=0; $i<$k; $i++) $row[] = (int)$serie[$i];
    $datasetsMonth[] = [
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
  <title>Dashboard Mensual</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <style>
    .progress{height:18px}
    .progress-bar{font-size:.75rem}
    .tab-pane{padding-top:10px}
  </style>
</head>
<body class="bg-light">

<div class="container mt-4">
  <h2>📊 Dashboard Mensual - <?= nombreMes($mes)." $anio" ?></h2>

  <!-- Filtros -->
  <form method="GET" class="row g-2 mb-4">
    <div class="col-md-2">
      <select name="mes" class="form-select">
        <?php for ($m=1;$m<=12;$m++): ?>
          <option value="<?= $m ?>" <?= $m==$mes?'selected':'' ?>><?= nombreMes($m) ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div class="col-md-2">
      <select name="anio" class="form-select">
        <?php for ($a=date('Y')-1;$a<=date('Y')+1;$a++): ?>
          <option value="<?= $a ?>" <?= $a==$anio?'selected':'' ?>><?= $a ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div class="col-md-2">
      <button class="btn btn-primary">Filtrar</button>
    </div>
  </form>

  <!-- Tarjetas Zonas + Global -->
  <div class="row mb-4">
    <?php foreach ($zonas as $zona => $info): 
      $cumpl = $info['cuota']>0 ? ($info['ventas']/$info['cuota']*100) : 0;
    ?>
      <div class="col-md-4 mb-3">
        <div class="card shadow text-center">
          <div class="card-header bg-dark text-white">Zona <?= htmlspecialchars($zona) ?></div>
          <div class="card-body">
            <h5><?= number_format($cumpl,1) ?>% Cumplimiento</h5>
            <p class="mb-0">
              Unidades: <?= (int)$info['unidades'] ?><br>
              Ventas: $<?= number_format($info['ventas'],2) ?><br>
              Cuota: $<?= number_format($info['cuota'],2) ?>
            </p>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    <div class="col-md-4 mb-3">
      <div class="card shadow text-center">
        <div class="card-header bg-primary text-white">🌎 Global Compañía</div>
        <div class="card-body">
          <h5><?= number_format($porcentajeGlobal,1) ?>% Cumplimiento</h5>
          <p class="mb-0">
            Unidades: <?= (int)$totalGlobalUnidades ?><br>
            Ventas: $<?= number_format($totalGlobalVentas,2) ?><br>
            Cuota: $<?= number_format($totalGlobalCuota,2) ?>
          </p>
        </div>
      </div>
    </div>
  </div>

  <!-- 👇 Gráfica mensual por SEMANAS (mar–lun) — TODAS las sucursales -->
  <div class="card shadow mb-4">
    <div class="card-header bg-dark text-white">Comportamiento por Semanas del Mes (mar–lun) — Sucursales</div>
    <div class="card-body">
      <div style="position:relative; height:220px;">
        <canvas id="chartMensualSemanas"></canvas>
      </div>
      <small class="text-muted d-block mt-2">
        * Toca los nombres en la leyenda para ocultar/mostrar sucursales.
      </small>
    </div>
  </div>

  <!-- Tabs -->
  <ul class="nav nav-tabs" role="tablist">
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-suc">Sucursales</button></li>
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-ej">Ejecutivos</button></li>
  </ul>

  <div class="tab-content">
    <!-- Sucursales -->
    <div class="tab-pane fade" id="tab-suc" role="tabpanel">
      <div class="card shadow mt-3">
        <div class="card-header bg-primary text-white">Sucursales</div>
        <div class="card-body p-0">
          <table class="table table-bordered table-striped table-sm mb-0">
            <thead class="table-dark">
              <tr>
                <th>Sucursal</th><th>Zona</th><th>Unidades</th><th>Cuota Unid.</th>
                <th>Ventas $</th><th>Cuota $</th><th>% Cumplimiento</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($sucursales as $s): 
                $fila  = badgeFila($s['cumplimiento']);
                $estado= $s['cumplimiento']>=100?"✅":($s['cumplimiento']>=60?"⚠️":"❌");
              ?>
              <tr class="<?= $fila ?>">
                <td><?= htmlspecialchars($s['sucursal']) ?></td>
                <td><?= htmlspecialchars($s['zona']) ?></td>
                <td><?= (int)$s['unidades'] ?></td>
                <td><?= (int)$s['cuota_unidades'] ?></td>
                <td>$<?= number_format($s['ventas'],2) ?></td>
                <td>$<?= number_format($s['cuota_monto'],2) ?></td>
                <td><?= round($s['cumplimiento'],1) ?>% <?= $estado ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Ejecutivos -->
    <div class="tab-pane fade show active" id="tab-ej" role="tabpanel">
      <div class="card shadow mt-3">
        <div class="card-header bg-dark text-white">Productividad mensual por Ejecutivo</div>
        <div class="card-body p-0">
          <table class="table table-striped table-bordered table-sm mb-0">
            <thead class="table-dark">
              <tr>
                <th>Ejecutivo</th>
                <th>Sucursal</th>
                <th>Unidades</th>
                <th>Ventas $</th>
                <th>Cuota Mes (u)</th>
                <th>% Cumpl. (Unid.)</th>
                <th>Progreso</th>
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
                <td><?= htmlspecialchars($e['sucursal']) ?></td>
                <td><?= (int)$e['unidades'] ?></td>
                <td>$<?= number_format($e['ventas'],2) ?></td>
                <td><?= number_format($e['cuota_unidades'],2) ?></td>
                <td><?= $pct===null ? '–' : ($pctRound.'%') ?></td>
                <td style="min-width:160px">
                  <div class="progress">
                    <div class="progress-bar <?= $barClass ?>" role="progressbar"
                         style="width: <?= $pct===null? 0 : min(100,$pctRound) ?>%">
                         <?= $pct===null ? 'Sin cuota' : ($pctRound.'%') ?>
                    </div>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
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
// Datos para la gráfica mensual por semanas
const labelsSemanas = <?= json_encode($labelsSemanas, JSON_UNESCAPED_UNICODE) ?>;
const datasetsMonth = <?= json_encode($datasetsMonth, JSON_UNESCAPED_UNICODE) ?>;

new Chart(document.getElementById('chartMensualSemanas').getContext('2d'), {
  type: 'line',
  data: { labels: labelsSemanas, datasets: datasetsMonth },
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
