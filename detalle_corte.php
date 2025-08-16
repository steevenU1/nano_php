<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Gerente','Admin'])) {
    header("Location: 403.php");
    exit();
}

require_once __DIR__ . '/db.php';

$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);
$rol        = $_SESSION['rol'] ?? '';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$idCorte = isset($_GET['id_corte']) ? (int)$_GET['id_corte'] : 0;
if ($idCorte <= 0) {
    header("Location: cortes_caja.php");
    exit();
}

$sqlCorte = "
  SELECT cc.*, u.nombre AS usuario, s.nombre AS sucursal
  FROM cortes_caja cc
  INNER JOIN usuarios u ON u.id = cc.id_usuario
  INNER JOIN sucursales s ON s.id = cc.id_sucursal
  WHERE cc.id = ?
";
$stmt = $conn->prepare($sqlCorte);
$stmt->bind_param("i", $idCorte);
$stmt->execute();
$corte = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$corte) {
    header("Location: cortes_caja.php");
    exit();
}

if ($rol === 'Gerente' && (int)$corte['id_sucursal'] !== $idSucursal) {
    header("Location: cortes_caja.php");
    exit();
}

$sqlCobros = "
  SELECT c.*, u.nombre AS usuario
  FROM cobros c
  INNER JOIN usuarios u ON u.id = c.id_usuario
  WHERE c.id_corte = ?
  ORDER BY c.fecha_cobro ASC
";
$stmt = $conn->prepare($sqlCobros);
$stmt->bind_param("i", $idCorte);
$stmt->execute();
$cobros = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$sumEfec = 0.0; $sumTjta = 0.0; $sumComEsp = 0.0; $sumTotal = 0.0;
foreach ($cobros as $c) {
    $sumEfec  += (float)$c['monto_efectivo'];
    $sumTjta  += (float)$c['monto_tarjeta'];
    $sumComEsp+= (float)$c['comision_especial'];
    $sumTotal += (float)$c['monto_total'];
}

$difGeneral = (float)$corte['total_general'] - (float)$corte['monto_depositado'];

require_once __DIR__ . '/navbar.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Detalle del Corte #<?= (int)$idCorte ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container mt-4">
  <div class="d-flex justify-content-between align-items-center">
    <h2>🧾 Detalle del Corte #<?= (int)$idCorte ?></h2>
    <a class="btn btn-secondary btn-sm" href="cortes_caja.php">← Volver</a>
  </div>

  <!-- Resumen del corte -->
  <div class="row g-3 mt-1">
    <div class="col-md-4">
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title mb-2">Información</h5>
          <p class="mb-1"><b>Sucursal:</b> <?= h($corte['sucursal']) ?></p>
          <p class="mb-1"><b>Usuario que generó:</b> <?= h($corte['usuario']) ?></p>
          <p class="mb-1"><b>Fecha operación:</b> <?= h($corte['fecha_operacion']) ?></p>
          <p class="mb-1"><b>Fecha corte:</b> <?= h($corte['fecha_corte']) ?></p>
          <p class="mb-0">
            <b>Estado:</b>
            <?php if ($corte['estado'] === 'Pendiente'): ?>
              <span class="badge bg-warning text-dark">Pendiente</span>
            <?php else: ?>
              <span class="badge bg-success">Cerrado/Validado</span>
            <?php endif; ?>
          </p>
        </div>
      </div>
    </div>

    <div class="col-md-4">
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title mb-2">Totales del corte</h5>
          <p class="mb-1"><b>Efectivo:</b> $<?= number_format((float)$corte['total_efectivo'],2) ?></p>
          <p class="mb-1"><b>Tarjeta:</b> $<?= number_format((float)$corte['total_tarjeta'],2) ?></p>
          <p class="mb-1"><b>Comisión especial:</b> $<?= number_format((float)$corte['total_comision_especial'],2) ?></p>
          <p class="mb-0"><b>Total general:</b> $<?= number_format((float)$corte['total_general'],2) ?></p>
        </div>
      </div>
    </div>

    <div class="col-md-4">
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title mb-2">Depósito</h5>
          <p class="mb-1"><b>¿Depositado?</b> <?= $corte['depositado'] ? 'Sí' : 'No' ?></p>
          <p class="mb-1"><b>Monto depositado:</b> $<?= number_format((float)$corte['monto_depositado'],2) ?></p>
          <p class="mb-0"><b>Diferencia:</b>
            $<?= number_format($difGeneral,2) ?>
          </p>
        </div>
      </div>
    </div>
  </div>

  <!-- Observaciones -->
  <?php if (!empty($corte['observaciones'])): ?>
    <div class="card shadow-sm mt-3">
      <div class="card-body">
        <h6 class="card-title">Observaciones</h6>
        <p class="mb-0"><?= nl2br(h($corte['observaciones'])) ?></p>
      </div>
    </div>
  <?php endif; ?>

  <!-- Tabla de cobros -->
  <div class="card shadow-sm mt-4">
    <div class="card-body">
      <h5 class="card-title">Cobros incluidos en el corte</h5>
      <?php if (empty($cobros)): ?>
        <div class="alert alert-info mb-0">No hay cobros asociados a este corte.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm table-bordered align-middle">
            <thead class="table-dark">
              <tr>
                <th>Fecha</th>
                <th>Usuario</th>
                <th>Motivo</th>
                <th>Tipo Pago</th>
                <th>Total</th>
                <th>Efectivo</th>
                <th>Tarjeta</th>
                <th>Comisión Esp.</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($cobros as $p): ?>
                <tr>
                  <td><?= h($p['fecha_cobro']) ?></td>
                  <td><?= h($p['usuario']) ?></td>
                  <td><?= h($p['motivo']) ?></td>
                  <td><?= h($p['tipo_pago']) ?></td>
                  <td>$<?= number_format((float)$p['monto_total'],2) ?></td>
                  <td>$<?= number_format((float)$p['monto_efectivo'],2) ?></td>
                  <td>$<?= number_format((float)$p['monto_tarjeta'],2) ?></td>
                  <td>$<?= number_format((float)$p['comision_especial'],2) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr class="table-secondary">
                <th colspan="4" class="text-end">Totales</th>
                <th>$<?= number_format($sumTotal,2) ?></th>
                <th>$<?= number_format($sumEfec,2) ?></th>
                <th>$<?= number_format($sumTjta,2) ?></th>
                <th>$<?= number_format($sumComEsp,2) ?></th>
              </tr>
            </tfoot>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div>
</body>
</html>
