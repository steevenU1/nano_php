<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// =====================
// Parámetros
// =====================
$tzOffset        = '-06:00'; // Hora CDMX
$diasProductivos = 5;        // 6 semanales -> 1.2 diarias

$hoyLocal  = (new DateTime('now',       new DateTimeZone('America/Mexico_City')))->format('Y-m-d');
$ayerLocal = (new DateTime('yesterday', new DateTimeZone('America/Mexico_City')))->format('Y-m-d');

$fecha = $_GET['fecha'] ?? $ayerLocal;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) $fecha = $ayerLocal;

// =====================
// TARJETAS GLOBALES  (tickets, ventas$, unidades)
// =====================
$sqlGlobal = "
  SELECT
    COUNT(DISTINCT v.id) AS tickets,

    IFNULL(SUM(
      CASE
        WHEN dv.id IS NULL THEN 0
        WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0
        ELSE dv.precio_unitario
      END
    ),0) AS ventas_validas,

    IFNULL(SUM(
      CASE
        WHEN dv.id IS NULL THEN 0
        WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0
        WHEN v.tipo_venta='Financiamiento+Combo'
             AND dv.id = (
               SELECT MIN(dv2.id) FROM detalle_venta dv2 WHERE dv2.id_venta = v.id
             ) THEN 2
        ELSE 1
      END
    ),0) AS unidades_validas

  FROM ventas v
  LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
  LEFT JOIN productos p ON p.id = dv.id_producto
  WHERE DATE(CONVERT_TZ(v.fecha_venta,'+00:00', ? )) = ?
";
$stmt = $conn->prepare($sqlGlobal);
$stmt->bind_param("ss", $tzOffset, $fecha);
$stmt->execute();
$glob = $stmt->get_result()->fetch_assoc();
$stmt->close();

$tickets         = (int)($glob['tickets'] ?? 0);
$ventasValidas   = (float)($glob['ventas_validas'] ?? 0);
$unidadesValidas = (int)($glob['unidades_validas'] ?? 0);
$ticketProm      = $tickets > 0 ? ($ventasValidas / $tickets) : 0.0;

// =====================
// Cuota diaria GLOBAL (u.) = SUM_suc (cuota_unidades * #ejecutivos_activos) / diasProductivos
// =====================
$sqlCuotaDiariaGlobalU = "
  SELECT IFNULL(SUM(cuota_calc),0) AS cuota_diaria_global_u FROM (
    SELECT 
      s.id,
      (
        IFNULL((
          SELECT css.cuota_unidades
          FROM cuotas_semanales_sucursal css
          WHERE css.id_sucursal = s.id
            AND ? BETWEEN css.semana_inicio AND css.semana_fin
          ORDER BY css.semana_inicio DESC
          LIMIT 1
        ), 0)
        *
        GREATEST((
          SELECT COUNT(*) FROM usuarios u2
          WHERE u2.id_sucursal = s.id AND u2.activo = 1 AND u2.rol = 'Ejecutivo'
        ), 0)
      ) / ? AS cuota_calc
    FROM sucursales s
    WHERE s.tipo_sucursal='Tienda'
  ) t
";
$stmt = $conn->prepare($sqlCuotaDiariaGlobalU);
$stmt->bind_param("si", $fecha, $diasProductivos);
$stmt->execute();
$cdgU = $stmt->get_result()->fetch_assoc();
$stmt->close();

$cuotaDiariaGlobalU = (float)($cdgU['cuota_diaria_global_u'] ?? 0);

// =====================
// Cuota diaria GLOBAL ($) y % de cumplimiento en monto
// =====================
$sqlCuotaDiariaGlobalM = "
  SELECT IFNULL(SUM(cuota_diaria),0) AS cuota_diaria_global_monto FROM (
    SELECT s.id,
           IFNULL((
             SELECT cs.cuota_monto
             FROM cuotas_sucursales cs
             WHERE cs.id_sucursal = s.id
               AND cs.fecha_inicio <= ?
             ORDER BY cs.fecha_inicio DESC
             LIMIT 1
           ), 0) / ? AS cuota_diaria
    FROM sucursales s
    WHERE s.tipo_sucursal='Tienda'
  ) t
";
$stmt = $conn->prepare($sqlCuotaDiariaGlobalM);
$stmt->bind_param("si", $fecha, $diasProductivos);
$stmt->execute();
$cdgM = $stmt->get_result()->fetch_assoc();
$stmt->close();

$cuotaDiariaGlobalM = (float)($cdgM['cuota_diaria_global_monto'] ?? 0);
$cumplGlobalM = $cuotaDiariaGlobalM > 0 ? ($ventasValidas / $cuotaDiariaGlobalM) * 100 : 0;

// =====================
// RANKING EJECUTIVOS
// Cuota diaria por EJECUTIVO (u.) = cuota_unidades_sucursal / diasProductivos
// =====================
$sqlEjecutivos = "
  SELECT
    u.id,
    u.nombre,
    s.nombre AS sucursal,

    IFNULL((
      SELECT css.cuota_unidades
      FROM cuotas_semanales_sucursal css
      WHERE css.id_sucursal = s.id
        AND ? BETWEEN css.semana_inicio AND css.semana_fin
      ORDER BY css.semana_inicio DESC
      LIMIT 1
    ) / ?, 0) AS cuota_diaria_ejecutivo,

    (
      SELECT COUNT(DISTINCT v2.id)
      FROM ventas v2
      WHERE v2.id_usuario = u.id
        AND DATE(CONVERT_TZ(v2.fecha_venta,'+00:00', ? )) = ?
    ) AS tickets,

    IFNULL(SUM(
      CASE
        WHEN dv.id IS NULL THEN 0
        WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0
        ELSE dv.precio_unitario
      END
    ),0) AS ventas_validas,

    IFNULL(SUM(
      CASE
        WHEN dv.id IS NULL THEN 0
        WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0
        WHEN v.tipo_venta='Financiamiento+Combo'
             AND dv.id = (
               SELECT MIN(dv2.id) FROM detalle_venta dv2 WHERE dv2.id_venta = v.id
             ) THEN 2
        ELSE 1
      END
    ),0) AS unidades_validas

  FROM usuarios u
  INNER JOIN sucursales s ON s.id = u.id_sucursal
  LEFT JOIN ventas v 
    ON v.id_usuario = u.id 
    AND DATE(CONVERT_TZ(v.fecha_venta,'+00:00', ? )) = ?
  LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
  LEFT JOIN productos p ON p.id = dv.id_producto
  WHERE s.tipo_sucursal='Tienda' AND u.activo=1 AND u.rol='Ejecutivo'
  GROUP BY u.id
  ORDER BY unidades_validas DESC, ventas_validas DESC
";
$stmt = $conn->prepare($sqlEjecutivos);
$stmt->bind_param("sissss", $fecha, $diasProductivos, $tzOffset, $fecha, $tzOffset, $fecha);
$stmt->execute();
$resEj = $stmt->get_result();

$ejecutivos = [];
while ($r = $resEj->fetch_assoc()) {
    $r['cuota_diaria_ejecutivo'] = (float)$r['cuota_diaria_ejecutivo'];
    $r['unidades_validas'] = (int)$r['unidades_validas'];
    $r['ventas_validas'] = (float)$r['ventas_validas'];
    $r['tickets'] = (int)$r['tickets'];
    $r['cumplimiento'] = $r['cuota_diaria_ejecutivo']>0 ? ($r['unidades_validas'] / $r['cuota_diaria_ejecutivo'] * 100) : 0;
    $ejecutivos[] = $r;
}
$stmt->close();

// =====================
// RANKING SUCURSALES  (cumplimiento en MONTO)
// - Cuota diaria ($) = cuota_monto vigente / diasProductivos
// - % Cumplimiento = ventas_validas ($) / cuota_diaria_monto * 100
// =====================
$sqlSucursales = "
  SELECT
    s.id AS id_sucursal,
    s.nombre AS sucursal,
    s.zona,

    IFNULL((
      SELECT cs.cuota_monto
      FROM cuotas_sucursales cs
      WHERE cs.id_sucursal = s.id
        AND cs.fecha_inicio <= ?
      ORDER BY cs.fecha_inicio DESC
      LIMIT 1
    ) / ?, 0) AS cuota_diaria_monto,

    IFNULL(SUM(
      CASE
        WHEN dv.id IS NULL THEN 0
        WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0
        ELSE dv.precio_unitario
      END
    ),0) AS ventas_validas,

    IFNULL(SUM(
      CASE
        WHEN dv.id IS NULL THEN 0
        WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0
        WHEN v.tipo_venta='Financiamiento+Combo'
             AND dv.id = (
               SELECT MIN(dv2.id) FROM detalle_venta dv2 WHERE dv2.id_venta = v.id
             ) THEN 2
        ELSE 1
      END
    ),0) AS unidades_validas

  FROM sucursales s
  LEFT JOIN (
    SELECT v1.*
    FROM ventas v1
    WHERE DATE(CONVERT_TZ(v1.fecha_venta,'+00:00', ? )) = ?
  ) v ON v.id_sucursal = s.id
  LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
  LEFT JOIN productos p ON p.id = dv.id_producto
  WHERE s.tipo_sucursal='Tienda'
  GROUP BY s.id
  ORDER BY ventas_validas DESC
";
$stmt = $conn->prepare($sqlSucursales);
$stmt->bind_param("siss", $fecha, $diasProductivos, $tzOffset, $fecha);
$stmt->execute();
$resSuc = $stmt->get_result();

$sucursales = [];
while ($s = $resSuc->fetch_assoc()) {
    $s['cuota_diaria_monto'] = (float)$s['cuota_diaria_monto'];
    $s['ventas_validas']     = (float)$s['ventas_validas'];
    $s['unidades_validas']   = (int)$s['unidades_validas'];
    $s['cumplimiento_monto'] = $s['cuota_diaria_monto']>0 ? ($s['ventas_validas'] / $s['cuota_diaria_monto'] * 100) : 0;
    $sucursales[] = $s;
}
$stmt->close();

require_once __DIR__ . '/navbar.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Productividad del Día (<?= h($fecha) ?>)</title>
  <link rel="icon" type="image/x-icon" href="./img/favicon.ico">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container mt-4">
  <div class="d-flex justify-content-between align-items-center">
    <h2>📅 Productividad del Día — <?= date('d/m/Y', strtotime($fecha)) ?></h2>
    <form method="GET" class="d-flex gap-2">
      <input type="date" name="fecha" class="form-control" value="<?= h($fecha) ?>" max="<?= h($hoyLocal) ?>">
      <button class="btn btn-primary">Ver</button>
      <a class="btn btn-outline-secondary" href="productividad_dia.php?fecha=<?= h($ayerLocal) ?>">Ayer</a>
    </form>
  </div>

  <!-- Tarjetas globales -->
  <div class="row mt-3 g-3">
    <div class="col-md-3">
      <div class="card shadow text-center">
        <div class="card-header bg-dark text-white">Unidades</div>
        <div class="card-body"><h3><?= (int)$unidadesValidas ?></h3></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card shadow text-center">
        <div class="card-header bg-dark text-white">Ventas $</div>
        <div class="card-body"><h3>$<?= number_format($ventasValidas,2) ?></h3></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card shadow text-center">
        <div class="card-header bg-dark text-white">Tickets</div>
        <div class="card-body"><h3><?= (int)$tickets ?></h3></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card shadow text-center">
        <div class="card-header bg-dark text-white">Ticket Prom.</div>
        <div class="card-body"><h3>$<?= number_format($ticketProm,2) ?></h3></div>
      </div>
    </div>
  </div>

  <!-- Global en UNIDADES -->
  <div class="row mt-3 g-3">
    <div class="col-md-4">
      <div class="card shadow text-center">
        <div class="card-header bg-primary text-white">Cuota diaria global (u.)</div>
        <div class="card-body"><h4><?= number_format($cuotaDiariaGlobalU,2) ?></h4></div>
      </div>
    </div>
    <div class="col-md-8">
      <div class="card shadow">
        <div class="card-body">
          <?php
            $cumplGlobalU = $cuotaDiariaGlobalU > 0 ? ($unidadesValidas / $cuotaDiariaGlobalU) * 100 : 0;
            $clsU = ($cumplGlobalU>=100?'bg-success':($cumplGlobalU>=60?'bg-warning':'bg-danger'));
          ?>
          <div class="d-flex justify-content-between">
            <div><strong>Cumplimiento global del día (u.)</strong></div>
            <div><strong><?= number_format(min(100,$cumplGlobalU),1) ?>%</strong></div>
          </div>
          <div class="progress" style="height:22px">
            <div class="progress-bar <?= $clsU ?>" style="width:<?= min(100,$cumplGlobalU) ?>%"></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Global en MONTO -->
  <div class="row mt-3 g-3">
    <div class="col-md-4">
      <div class="card shadow text-center">
        <div class="card-header bg-primary text-white">Cuota diaria global ($)</div>
        <div class="card-body"><h4>$<?= number_format($cuotaDiariaGlobalM,2) ?></h4></div>
      </div>
    </div>
    <div class="col-md-8">
      <div class="card shadow">
        <div class="card-body">
          <?php $clsM = ($cumplGlobalM>=100?'bg-success':($cumplGlobalM>=60?'bg-warning':'bg-danger')); ?>
          <div class="d-flex justify-content-between">
            <div><strong>Cumplimiento global del día ($)</strong></div>
            <div><strong><?= number_format(min(100,$cumplGlobalM),1) ?>%</strong></div>
          </div>
          <div class="progress" style="height:22px">
            <div class="progress-bar <?= $clsM ?>" style="width:<?= min(100,$cumplGlobalM) ?>%"></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Tabs -->
  <ul class="nav nav-tabs mt-4" id="tabsDia">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabEjecutivos">Ejecutivos 👔</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabSucursales">Sucursales 🏢</button></li>
  </ul>

  <div class="tab-content">
    <!-- Ejecutivos -->
    <div class="tab-pane fade show active" id="tabEjecutivos">
      <div class="card shadow mt-3">
        <div class="card-header bg-dark text-white">Ranking de Ejecutivos (<?= date('d/m/Y', strtotime($fecha)) ?>)</div>
        <div class="card-body table-responsive">
          <table class="table table-striped table-bordered align-middle">
            <thead class="table-dark">
              <tr>
                <th>Ejecutivo</th>
                <th>Sucursal</th>
                <th>Unidades</th>
                <th>Ventas $</th>
                <th>Tickets</th>
                <th>Cuota diaria (u.)</th>
                <th>% Cumplimiento</th>
                <th>Progreso</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($ejecutivos as $e):
                $cuotaDiaU = $e['cuota_diaria_ejecutivo'];
                $cumpl = $e['cumplimiento'];
                $fila = $cumpl>=100?'table-success':($cumpl>=60?'table-warning':'table-danger');
                $cls  = $cumpl>=100?'bg-success':($cumpl>=60?'bg-warning':'bg-danger');
              ?>
              <tr class="<?= $fila ?>">
                <td><?= h($e['nombre']) ?></td>
                <td><?= h($e['sucursal']) ?></td>
                <td><?= (int)$e['unidades_validas'] ?></td>
                <td>$<?= number_format($e['ventas_validas'],2) ?></td>
                <td><?= (int)$e['tickets'] ?></td>
                <td><?= number_format($cuotaDiaU,2) ?></td>
                <td><?= number_format($cumpl,1) ?>%</td>
                <td>
                  <div class="progress" style="height:20px">
                    <div class="progress-bar <?= $cls ?>" style="width:<?= min(100,$cumpl) ?>%"></div>
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
    <div class="tab-pane fade" id="tabSucursales">
      <div class="card shadow mt-3">
        <div class="card-header bg-dark text-white">Ranking de Sucursales (<?= date('d/m/Y', strtotime($fecha)) ?>)</div>
        <div class="card-body table-responsive">
          <table class="table table-striped table-bordered align-middle">
            <thead class="table-dark">
              <tr>
                <th>Sucursal</th>
                <th>Zona</th>
                <th>Unidades</th>
                <th>Ventas $</th>
                <th>Cuota diaria ($)</th>
                <th>% Cumplimiento (monto)</th>
                <th>Progreso</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($sucursales as $s):
                $cumpl = $s['cumplimiento_monto'];
                $fila = $cumpl>=100?'table-success':($cumpl>=60?'table-warning':'table-danger');
                $cls  = $cumpl>=100?'bg-success':($cumpl>=60?'bg-warning':'bg-danger');
              ?>
              <tr class="<?= $fila ?>">
                <td><?= h($s['sucursal']) ?></td>
                <td>Zona <?= h($s['zona']) ?></td>
                <td><?= (int)$s['unidades_validas'] ?></td>
                <td>$<?= number_format($s['ventas_validas'],2) ?></td>
                <td>$<?= number_format($s['cuota_diaria_monto'],2) ?></td>
                <td><?= number_format($cumpl,1) ?>%</td>
                <td>
                  <div class="progress" style="height:20px">
                    <div class="progress-bar <?= $cls ?>" style="width:<?= min(100,$cumpl) ?>%"></div>
                  </div>
                </td>
              </tr>
              <?php endforeach;?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div><!-- /tab-content -->
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script> -->
</body>
</html>
