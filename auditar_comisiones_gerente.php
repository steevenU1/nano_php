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
   Params
======================== */
$semana = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
$id_sucursal = isset($_GET['id_sucursal']) ? (int)$_GET['id_sucursal'] : 0;

list($iniObj,$finObj) = obtenerSemanaPorIndice($semana);
$ini = $iniObj->format('Y-m-d 00:00:00');
$fin = $finObj->format('Y-m-d 23:59:59');

/* ========================
   Datos sucursal + gerente
======================== */
$suc = rowf($conn->query("SELECT nombre FROM sucursales WHERE id={$id_sucursal} LIMIT 1"));
$sucursalNombre = $suc['nombre'] ?? 'Sucursal';

$ger = rowf($conn->query("
  SELECT id, nombre FROM usuarios 
  WHERE rol='Gerente' AND id_sucursal={$id_sucursal} LIMIT 1
"));
$idGerente = (int)($ger['id'] ?? 0);
$nombreGerente = $ger['nombre'] ?? '(sin gerente)';

/* ========================
   Esquema gerente (vigente)
======================== */
$esqGer = rowf($conn->query("
  SELECT * FROM esquemas_comisiones_gerentes
  ORDER BY fecha_inicio DESC LIMIT 1
"));

/* ========================
   Cuota tienda y monto semana
======================== */
$cuotaMonto = (float)($conn->query("
  SELECT cuota_monto 
  FROM cuotas_sucursales 
  WHERE id_sucursal={$id_sucursal} AND fecha_inicio <= '{$ini}'
  ORDER BY fecha_inicio DESC LIMIT 1
")->fetch_assoc()['cuota_monto'] ?? 0);

$montoSemana = (float)($conn->query("
  SELECT IFNULL(SUM(dv.precio_unitario),0) AS m
  FROM detalle_venta dv
  INNER JOIN ventas v ON v.id = dv.id_venta
  INNER JOIN productos p ON p.id = dv.id_producto
  WHERE v.id_sucursal={$id_sucursal}
    AND v.fecha_venta BETWEEN '{$ini}' AND '{$fin}'
    AND LOWER(p.tipo_producto) IN ('equipo','modem')
")->fetch_assoc()['m'] ?? 0);

$cumpleTienda = $montoSemana >= $cuotaMonto;

/* ========================
   Heurístico “parece modem”
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
   1) Venta directa (equipos del gerente) — solo equipos
======================== */
$ventaDirecta = [];
if ($idGerente) {
  $res = $conn->query("
    SELECT v.id AS venta_id, v.fecha_venta
    FROM detalle_venta dv
    INNER JOIN ventas v ON v.id = dv.id_venta
    INNER JOIN productos p ON p.id = dv.id_producto
    WHERE v.id_usuario={$idGerente}
      AND v.fecha_venta BETWEEN '{$ini}' AND '{$fin}'
      AND LOWER(p.tipo_producto) <> 'accesorio'
      AND NOT {$condPareceModem}   -- excluir modem detectado
  ");
  while ($r = $res->fetch_assoc()) $ventaDirecta[] = $r;
}
$comVentaDirectaUnit = $cumpleTienda ? (float)($esqGer['venta_directa_con'] ?? 0)
                                     : (float)($esqGer['venta_directa_sin'] ?? 0);
$totalVentaDirecta = count($ventaDirecta) * $comVentaDirectaUnit;

/* ========================
   2) Escalón sucursal (EQUIPOS sin modem)
======================== */
$eq = $conn->query("
  SELECT 
    v.id AS venta_id, 
    v.fecha_venta,
    {$caseTipoDetectado} AS tipo_detectado
  FROM detalle_venta dv
  INNER JOIN ventas v ON v.id = dv.id_venta
  INNER JOIN productos p ON p.id = dv.id_producto
  WHERE v.id_sucursal={$id_sucursal}
    AND v.fecha_venta BETWEEN '{$ini}' AND '{$fin}'
    AND LOWER(p.tipo_producto) <> 'accesorio'
    AND NOT {$condPareceModem}     -- EXCLUYE lo que parece modem
  ORDER BY v.fecha_venta, v.id
");
$escalonFilas = [];
while ($r = $eq->fetch_assoc()) $escalonFilas[] = $r;

// tarifa por unidad según tramo y si cumple tienda
function tarifaGerenteTramo($n, $cumple, $esq) {
  if ($n <= 10)  return $cumple ? (float)$esq['sucursal_1_10_con']   : (float)$esq['sucursal_1_10_sin'];
  if ($n <= 20)  return $cumple ? (float)$esq['sucursal_11_20_con']  : (float)$esq['sucursal_11_20_sin'];
  return             $cumple ? (float)$esq['sucursal_21_mas_con'] : (float)$esq['sucursal_21_mas_sin'];
}
$escalonDetalle = []; $totalEscalon = 0.0;
$i=0;
foreach ($escalonFilas as $row) {
  $i++;
  $t = tarifaGerenteTramo($i, $cumpleTienda, $esqGer);
  $totalEscalon += $t;
  if ($i <= 10) { // mostramos primeras 10
    $escalonDetalle[] = [
      'venta_id' => $row['venta_id'],
      'tipo'     => $row['tipo_detectado'],
      'tarifa'   => $t
    ];
  }
}

/* ========================
   3) MiFi / Modem (INCLUYE lo que “parece modem”)
======================== */
$modemRows = $conn->query("
  SELECT v.id AS venta_id, v.fecha_venta
  FROM detalle_venta dv
  INNER JOIN ventas v ON v.id = dv.id_venta
  INNER JOIN productos p ON p.id = dv.id_producto
  WHERE v.id_sucursal={$id_sucursal}
    AND v.fecha_venta BETWEEN '{$ini}' AND '{$fin}'
    AND {$condPareceModem}
  ORDER BY v.fecha_venta, v.id
");
$modems = []; while ($r = $modemRows->fetch_assoc()) $modems[] = $r;

$comModemUnit = $cumpleTienda ? (float)($esqGer['comision_modem_con'] ?? 0)
                              : (float)($esqGer['comision_modem_sin'] ?? 0);
$totalModems = count($modems) * $comModemUnit;

/* ========================
   4) SIMs (prepago) — ya grabadas
======================== */
$sims = $conn->query("
  SELECT vs.id, vs.comision_gerente
  FROM ventas_sims vs
  WHERE vs.id_sucursal={$id_sucursal}
    AND vs.fecha_venta BETWEEN '{$ini}' AND '{$fin}'
    AND vs.tipo_venta IN ('Nueva','Portabilidad')
");
$simCount = 0; $simTotal = 0.0;
while ($r = $sims->fetch_assoc()) { $simCount++; $simTotal += (float)$r['comision_gerente']; }

/* ========================
   5) Pospago — ya grabadas
======================== */
$pos = $conn->query("
  SELECT vs.id, vs.comision_gerente, vs.precio_total, vs.modalidad
  FROM ventas_sims vs
  WHERE vs.id_sucursal={$id_sucursal}
    AND vs.fecha_venta BETWEEN '{$ini}' AND '{$fin}'
    AND vs.tipo_venta='Pospago'
");
$posTotal = 0.0; $posRows = [];
while ($r = $pos->fetch_assoc()) { $posTotal += (float)$r['comision_gerente']; $posRows[] = $r; }

/* ========================
   Comparativo (DB)
======================== */
$calculado = $totalEscalon + $totalModems + $simTotal + $posTotal;

$dbVentas = (float)($conn->query("
  SELECT IFNULL(SUM(comision_gerente),0) AS t
  FROM ventas 
  WHERE id_sucursal={$id_sucursal}
    AND fecha_venta BETWEEN '{$ini}' AND '{$fin}'
")->fetch_assoc()['t'] ?? 0);

$dbSims = (float)($conn->query("
  SELECT IFNULL(SUM(comision_gerente),0) AS t
  FROM ventas_sims 
  WHERE id_sucursal={$id_sucursal}
    AND fecha_venta BETWEEN '{$ini}' AND '{$fin}'
")->fetch_assoc()['t'] ?? 0);

$dbTotal = $dbVentas + $dbSims;
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Auditoría comisión Gerente</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="icon" type="image/x-icon" href="./img/favicon.ico">
</head>
<body class="bg-light">
<div class="container my-4">
  <h3>🔍 Auditoría comisión Gerente — <?= htmlspecialchars($sucursalNombre) ?></h3>
  <p>
    <strong>Semana:</strong> <?= $iniObj->format('d/m/Y') ?> - <?= $finObj->format('d/m/Y') ?> |
    <strong>Gerente:</strong> <?= htmlspecialchars($nombreGerente) ?> |
    <strong>Cuota tienda:</strong> $<?= number_format($cuotaMonto,2) ?> |
    <strong>Monto semana:</strong> $<?= number_format($montoSemana,2) ?> |
    <strong>Cumple tienda:</strong> <?= $cumpleTienda ? '✅' : '❌' ?>
  </p>

  <!-- Venta directa -->
  <div class="card mb-3">
    <div class="card-header bg-primary text-white">Venta directa (equipos del gerente)</div>
    <div class="card-body">
      <?php if (count($ventaDirecta) === 0): ?>
        <div class="text-muted">No hay ventas directas del gerente esta semana.</div>
      <?php else: ?>
        <ul class="mb-2">
          <?php foreach($ventaDirecta as $v): ?>
            <li>Venta #<?= (int)$v['venta_id'] ?> → $<?= number_format($comVentaDirectaUnit,2) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <strong>Total: $<?= number_format($totalVentaDirecta,2) ?></strong>
      <div class="text-muted small">* Informativo. No se suma al comparativo de comisiones de gerente.</div>
    </div>
  </div>

  <!-- Escalón sucursal -->
  <div class="card mb-3">
    <div class="card-header bg-secondary text-white">Escalón sucursal (equipos incl. gerente)</div>
    <div class="card-body">
      <?php if (count($escalonDetalle) === 0): ?>
        <div class="text-muted">No hay unidades de equipo esta semana.</div>
      <?php else: ?>
        <ul class="mb-2">
          <?php foreach($escalonDetalle as $e): ?>
            <li>
              Venta #<?= (int)$e['venta_id'] ?> — 
              <?= htmlspecialchars($e['tipo']) ?> 
              → $<?= number_format($e['tarifa'],2) ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if (count($escalonFilas) > 10): ?>
          <div class="text-muted small">… y <?= count($escalonFilas)-10 ?> unidades más.</div>
        <?php endif; ?>
      <?php endif; ?>
      <strong>Total: $<?= number_format($totalEscalon,2) ?></strong>
    </div>
  </div>

  <!-- MiFi / Modem -->
  <div class="card mb-3">
    <div class="card-header bg-info text-white">MiFi / Modem</div>
    <div class="card-body">
      <?php if (count($modems) === 0): ?>
        <div class="text-muted">No hay ventas de Modem esta semana.</div>
      <?php else: ?>
        <ul class="mb-2">
          <?php foreach($modems as $m): ?>
            <li>Venta #<?= (int)$m['venta_id'] ?> → $<?= number_format($comModemUnit,2) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <strong>Total: $<?= number_format($totalModems,2) ?></strong>
    </div>
  </div>

  <!-- SIMs -->
  <div class="card mb-3">
    <div class="card-header bg-success text-white">SIMs (prepago)</div>
    <div class="card-body">
      Ventas: <?= $simCount ?> 
      <br>
      <strong>Total: $<?= number_format($simTotal,2) ?></strong>
    </div>
  </div>

  <!-- Pospago -->
  <div class="card mb-3">
    <div class="card-header bg-dark text-white">Pospago (planes)</div>
    <div class="card-body">
      <?php if (count($posRows) === 0): ?>
        <div class="text-muted">Sin ventas de pospago esta semana.</div>
      <?php else: ?>
        <ul class="mb-2">
          <?php foreach($posRows as $p): ?>
            <li>Venta #<?= (int)$p['id'] ?> — Plan $<?= number_format($p['precio_total'],2) ?> (<?= htmlspecialchars($p['modalidad']) ?>) → $<?= number_format($p['comision_gerente'],2) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <strong>Total: $<?= number_format($posTotal,2) ?></strong>
    </div>
  </div>

  <!-- Comparativo -->
  <div class="alert alert-warning">
    <strong>Comparativo:</strong> Calculado = $<?= number_format($calculado,2) ?> | Grabado en DB = $<?= number_format($dbTotal,2) ?><br>
    DB = SUM(ventas.comision_gerente) + SUM(ventas_sims.comision_gerente) de la semana.
  </div>

  <a href="reporte_nomina.php?semana=<?= $semana ?>" class="btn btn-outline-secondary">← Volver al Reporte</a>
</div>
</body>
</html>
