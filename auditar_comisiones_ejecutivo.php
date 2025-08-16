<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Admin','RH'])) {
    header("Location: index.php"); exit();
}

include 'db.php';
include 'navbar.php';

/* ========================
   Helpers
======================== */
function obtenerSemanaPorIndice($offset = 0) {
    $hoy = new DateTime();
    $dia = $hoy->format('N'); // 1=Lun ... 7=Dom
    $dif = $dia - 2; if ($dif < 0) $dif += 7; // base martes
    $ini = new DateTime(); $ini->modify("-$dif days")->setTime(0,0,0);
    if ($offset > 0) $ini->modify("-".(7*$offset)." days");
    $fin = clone $ini; $fin->modify("+6 days")->setTime(23,59,59);
    return [$ini,$fin];
}
function rowf($res){ return $res ? $res->fetch_assoc() : null; }

/* ========================
   Parámetros
======================== */
$semana = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
$id_usuario = isset($_GET['id_usuario']) ? (int)$_GET['id_usuario'] : 0;

list($iniObj,$finObj) = obtenerSemanaPorIndice($semana);
$ini = $iniObj->format('Y-m-d 00:00:00');
$fin = $finObj->format('Y-m-d 23:59:59');

/* ========================
   Datos del ejecutivo
======================== */
$usr = rowf($conn->query("SELECT u.nombre, s.nombre AS sucursal
                          FROM usuarios u
                          LEFT JOIN sucursales s ON s.id = u.id_sucursal
                          WHERE u.id={$id_usuario} LIMIT 1"));
$nombreEjecutivo = $usr['nombre'] ?? 'Ejecutivo';
$nombreSucursal  = $usr['sucursal'] ?? '(sin sucursal)';

/* ========================
   Heurístico robusto: PARECE MODEM
======================== */
$condPareceModem = "("
  ." p.tipo_producto = 'Modem'"
  ." OR UPPER(TRIM(p.marca))  LIKE 'HBB%'"
  ." OR UPPER(TRIM(p.modelo)) LIKE '%MIFI%'"
  ." OR UPPER(TRIM(p.modelo)) LIKE '%MODEM%'"
.")";

$caseTipoDetectado = "
  CASE 
    WHEN {$condPareceModem} THEN 'Modem'
    ELSE p.tipo_producto
  END
";

/* ========================
   1) EQUIPOS (excluye “parece modem”) — lee comisiones guardadas
   -> ANY_VALUE() para cumplir ONLY_FULL_GROUP_BY
======================== */
$equipos = [];
$res = $conn->query("
    SELECT v.id AS venta_id,
           v.fecha_venta,
           ANY_VALUE({$caseTipoDetectado}) AS tipo_detectado,
           SUM(dv.comision) AS comision_venta
    FROM detalle_venta dv
    INNER JOIN ventas v  ON v.id = dv.id_venta
    INNER JOIN productos p ON p.id = dv.id_producto
    WHERE v.id_usuario={$id_usuario}
      AND v.fecha_venta BETWEEN '{$ini}' AND '{$fin}'
      AND LOWER(p.tipo_producto) <> 'accesorio'
      AND NOT {$condPareceModem}
    GROUP BY v.id, v.fecha_venta
    ORDER BY v.fecha_venta, v.id
");
$totalEquipos = 0.0;
while ($r = $res->fetch_assoc()) {
    $equipos[] = $r;
    $totalEquipos += (float)$r['comision_venta'];
}
/* unidades de equipo (informativo) */
$unidRow = rowf($conn->query("
    SELECT COUNT(*) AS unidades
    FROM detalle_venta dv
    INNER JOIN ventas v  ON v.id = dv.id_venta
    INNER JOIN productos p ON p.id = dv.id_producto
    WHERE v.id_usuario={$id_usuario}
      AND v.fecha_venta BETWEEN '{$ini}' AND '{$fin}'
      AND LOWER(p.tipo_producto) <> 'accesorio'
      AND NOT {$condPareceModem}
"));
$unidadesEquipos = (int)($unidRow['unidades'] ?? 0);

/* ========================
   2) MODEM (incluye “parece modem”) — lee comisiones guardadas
======================== */
$modems = [];
$res = $conn->query("
    SELECT v.id AS venta_id,
           v.fecha_venta,
           SUM(dv.comision) AS comision_venta
    FROM detalle_venta dv
    INNER JOIN ventas v  ON v.id = dv.id_venta
    INNER JOIN productos p ON p.id = dv.id_producto
    WHERE v.id_usuario={$id_usuario}
      AND v.fecha_venta BETWEEN '{$ini}' AND '{$fin}'
      AND {$condPareceModem}
    GROUP BY v.id, v.fecha_venta
    ORDER BY v.fecha_venta, v.id
");
$totalModem = 0.0;
while ($r = $res->fetch_assoc()) {
    $modems[] = $r;
    $totalModem += (float)$r['comision_venta'];
}

/* ========================
   3) SIMs PREPAGO — ventas_sims.comision_ejecutivo
======================== */
$sims = [];
$res = $conn->query("
    SELECT vs.id AS venta_id, vs.fecha_venta, vs.tipo_venta, vs.comision_ejecutivo
    FROM ventas_sims vs
    WHERE vs.id_usuario={$id_usuario}
      AND vs.fecha_venta BETWEEN '{$ini}' AND '{$fin}'
      AND vs.tipo_venta IN ('Nueva','Portabilidad','Regalo')
    ORDER BY vs.fecha_venta, vs.id
");
$totalSims = 0.0;
while ($r = $res->fetch_assoc()) {
    $sims[] = $r;
    $totalSims += (float)$r['comision_ejecutivo'];
}

/* ========================
   4) POSPAGO — ventas_sims.comision_ejecutivo
======================== */
$pospago = [];
$res = $conn->query("
    SELECT vs.id AS venta_id, vs.fecha_venta, vs.modalidad, vs.precio_total, vs.comision_ejecutivo
    FROM ventas_sims vs
    WHERE vs.id_usuario={$id_usuario}
      AND vs.fecha_venta BETWEEN '{$ini}' AND '{$fin}'
      AND vs.tipo_venta='Pospago'
    ORDER BY vs.fecha_venta, vs.id
");
$totalPospago = 0.0;
while ($r = $res->fetch_assoc()) {
    $pospago[] = $r;
    $totalPospago += (float)$r['comision_ejecutivo'];
}

/* ========================
   TOTAL
======================== */
$total = $totalEquipos + $totalModem + $totalSims + $totalPospago;
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Auditoría comisiones — Ejecutivo</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="icon" type="image/x-icon" href="./img/favicon.ico">
</head>
<body class="bg-light">
<div class="container my-4">
  <h3>🔍 Auditoría de comisiones — <?= htmlspecialchars($nombreEjecutivo) ?></h3>
  <p>
    <strong>Sucursal:</strong> <?= htmlspecialchars($nombreSucursal) ?> |
    <strong>Semana:</strong> <?= $iniObj->format('d/m/Y') ?> - <?= $finObj->format('d/m/Y') ?> |
    <strong>Unidades equipo (sin modem):</strong> <?= $unidadesEquipos ?>
  </p>

  <!-- Equipos -->
  <div class="card mb-3">
    <div class="card-header bg-primary text-white">Equipos</div>
    <div class="card-body">
      <?php if (empty($equipos)): ?>
        <div class="text-muted">Sin ventas de equipos.</div>
      <?php else: ?>
        <ul class="mb-2">
          <?php foreach($equipos as $v): ?>
            <li>
              Venta #<?= (int)$v['venta_id'] ?> —
              <?= htmlspecialchars($v['tipo_detectado']) ?> →
              $<?= number_format($v['comision_venta'],2) ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <strong>Total: $<?= number_format($totalEquipos,2) ?></strong>
    </div>
  </div>

  <!-- Modem -->
  <div class="card mb-3">
    <div class="card-header bg-info text-white">MiFi / Modem</div>
    <div class="card-body">
      <?php if (empty($modems)): ?>
        <div class="text-muted">Sin ventas de modem.</div>
      <?php else: ?>
        <ul class="mb-2">
          <?php foreach($modems as $v): ?>
            <li>Venta #<?= (int)$v['venta_id'] ?> → $<?= number_format($v['comision_venta'],2) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <strong>Total: $<?= number_format($totalModem,2) ?></strong>
    </div>
  </div>

  <!-- SIMs -->
  <div class="card mb-3">
    <div class="card-header bg-success text-white">SIMs (prepago)</div>
    <div class="card-body">
      <?php if (empty($sims)): ?>
        <div class="text-muted">Sin ventas de SIMs (prepago).</div>
      <?php else: ?>
        <ul class="mb-2">
          <?php foreach($sims as $v): ?>
            <li>Venta #<?= (int)$v['venta_id'] ?> — <?= htmlspecialchars($v['tipo_venta']) ?> → $<?= number_format($v['comision_ejecutivo'],2) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <strong>Total: $<?= number_format($totalSims,2) ?></strong>
    </div>
  </div>

  <!-- Pospago -->
  <div class="card mb-3">
    <div class="card-header bg-dark text-white">Pospago (planes)</div>
    <div class="card-body">
      <?php if (empty($pospago)): ?>
        <div class="text-muted">Sin ventas de pospago.</div>
      <?php else: ?>
        <ul class="mb-2">
          <?php foreach($pospago as $v): ?>
            <li>Venta #<?= (int)$v['venta_id'] ?> — <?= htmlspecialchars($v['modalidad']) ?>, plan $<?= number_format($v['precio_total'],2) ?> → $<?= number_format($v['comision_ejecutivo'],2) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <strong>Total: $<?= number_format($totalPospago,2) ?></strong>
    </div>
  </div>

  <!-- Total -->
  <div class="alert alert-warning">
    <strong>Total comisiones capturadas:</strong> $<?= number_format($total,2) ?>
  </div>

  <a href="reporte_nomina.php?semana=<?= $semana ?>" class="btn btn-outline-secondary">← Volver al Reporte</a>
</div>
</body>
</html>
