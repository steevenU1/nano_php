<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Gerente','Admin'])) {
    header("Location: 403.php");
    exit();
}

require_once __DIR__ . '/db.php';

$id_usuario  = (int)($_SESSION['id_usuario'] ?? 0);
$id_sucursal = (int)($_SESSION['id_sucursal'] ?? 0);
$fechaHoy    = date('Y-m-d');
$msg = "";

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ===== 1) Días con cobros pendientes ===== */
$sqlDiasPendientes = "
  SELECT DATE(fecha_cobro) AS fecha, COUNT(*) AS total
  FROM cobros
  WHERE id_sucursal = ? AND id_corte IS NULL AND corte_generado = 0
  GROUP BY DATE(fecha_cobro)
  ORDER BY fecha ASC
";
$stmt = $conn->prepare($sqlDiasPendientes);
$stmt->bind_param("i", $id_sucursal);
$stmt->execute();
$diasPendientes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$pendientes = [];
foreach ($diasPendientes as $d) $pendientes[$d['fecha']] = (int)$d['total'];
$fechaDefault = !empty($pendientes) ? array_key_first($pendientes) : $fechaHoy;

/* ===== 2) Fecha seleccionada (GET) ===== */
$fechaSeleccionada = $_GET['fecha_operacion'] ?? $fechaDefault;

/* ===== 3) GENERAR CORTE (POST) — ANTES de imprimir navbar o HTML ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fecha_operacion'])) {
    $fecha_operacion = $_POST['fecha_operacion'] ?: $fechaSeleccionada;

    $sqlCobros = "
      SELECT *
      FROM cobros
      WHERE id_sucursal = ?
        AND DATE(fecha_cobro) = ?
        AND id_corte IS NULL
        AND corte_generado = 0
      FOR UPDATE
    ";

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare($sqlCobros);
        $stmt->bind_param("is", $id_sucursal, $fecha_operacion);
        $stmt->execute();
        $cobros = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (count($cobros) === 0) {
            $conn->rollback();
            $_SESSION['flash_msg'] = "<div class='alert alert-info'>
              ⚠ No hay cobros pendientes para generar corte en <b>".h($fecha_operacion)."</b>.
            </div>";
            header("Location: generar_corte.php?fecha_operacion=".urlencode($fecha_operacion));
            exit();
        }

        $total_efectivo = 0.0;
        $total_tarjeta  = 0.0;
        $total_comision_especial = 0.0;
        foreach ($cobros as $c) {
            $total_efectivo          += (float)$c['monto_efectivo'];
            $total_tarjeta           += (float)$c['monto_tarjeta'];
            $total_comision_especial += (float)$c['comision_especial'];
        }
        $total_general = $total_efectivo + $total_tarjeta;

        $stmtCorte = $conn->prepare("
          INSERT INTO cortes_caja
          (id_sucursal, id_usuario, fecha_operacion, fecha_corte, estado,
           total_efectivo, total_tarjeta, total_comision_especial, total_general,
           depositado, monto_depositado, observaciones)
          VALUES (?, ?, ?, NOW(), 'Pendiente', ?, ?, ?, ?, 0, 0, '')
        ");
        $stmtCorte->bind_param("iisdddd",
            $id_sucursal, $id_usuario, $fecha_operacion,
            $total_efectivo, $total_tarjeta, $total_comision_especial, $total_general
        );
        if (!$stmtCorte->execute()) throw new Exception("Error al insertar corte: ".$stmtCorte->error);
        $id_corte = $stmtCorte->insert_id;
        $stmtCorte->close();

        $stmtUpd = $conn->prepare("
          UPDATE cobros
          SET id_corte = ?, corte_generado = 1
          WHERE id_sucursal = ? AND DATE(fecha_cobro) = ? AND id_corte IS NULL AND corte_generado = 0
        ");
        $stmtUpd->bind_param("iis", $id_corte, $id_sucursal, $fecha_operacion);
        if (!$stmtUpd->execute()) throw new Exception("Error al actualizar cobros: ".$stmtUpd->error);
        $stmtUpd->close();

        $conn->commit();

        $_SESSION['flash_msg'] = "<div class='alert alert-success'>
          ✅ Corte generado (ID: {$id_corte}) para <b>".h($fecha_operacion)."</b>.
        </div>";
        header("Location: generar_corte.php?fecha_operacion=".urlencode($fecha_operacion));
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>❌ ".$e->getMessage()."</div>";
        header("Location: generar_corte.php?fecha_operacion=".urlencode($fecha_operacion));
        exit();
    }
}

/* ===== 4) A partir de aquí ya podemos imprimir HTML / incluir navbar ===== */
require_once __DIR__ . '/navbar.php';

/* Cobros pendientes de la fecha seleccionada */
$sqlCobrosPend = "
  SELECT c.*, u.nombre AS usuario
  FROM cobros c
  INNER JOIN usuarios u ON u.id = c.id_usuario
  WHERE c.id_sucursal = ?
    AND DATE(c.fecha_cobro) = ?
    AND c.id_corte IS NULL
    AND c.corte_generado = 0
  ORDER BY c.fecha_cobro ASC
";
$stmt = $conn->prepare($sqlCobrosPend);
$stmt->bind_param("is", $id_sucursal, $fechaSeleccionada);
$stmt->execute();
$cobrosFecha = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$hayCobros   = count($cobrosFecha) > 0;
$btnDisabled = $hayCobros ? '' : 'disabled';

$desde = $_GET['desde'] ?? date('Y-m-01');
$hasta = $_GET['hasta'] ?? date('Y-m-d');

$sqlHistCortes = "
  SELECT cc.*, u.nombre AS usuario
  FROM cortes_caja cc
  INNER JOIN usuarios u ON u.id = cc.id_usuario
  WHERE cc.id_sucursal = ?
    AND DATE(cc.fecha_corte) BETWEEN ? AND ?
  ORDER BY cc.fecha_corte DESC
";
$stmtHistCortes = $conn->prepare($sqlHistCortes);
$stmtHistCortes->bind_param("iss", $id_sucursal, $desde, $hasta);
$stmtHistCortes->execute();
$histCortes = $stmtHistCortes->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtHistCortes->close();

/* Mensaje flash */
if (!empty($_SESSION['flash_msg'])) {
    $msg = $_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Generar Corte de Caja</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container mt-4">

  <h2>🧾 Generar Corte de Caja</h2>
  <?= $msg ?>

  <div class="mb-3">
    <h5>Días pendientes de corte:</h5>
    <?php if (empty($pendientes)): ?>
      <div class="alert alert-info">No hay días pendientes.</div>
    <?php else: ?>
      <ul>
        <?php foreach ($pendientes as $f => $total): ?>
          <li><?= h($f) ?> → <?= (int)$total ?> cobros</li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <form class="card p-3 shadow mb-4" method="POST" action="generar_corte.php">
    <label class="form-label">Fecha de operación</label>
    <input type="date" name="fecha_operacion" class="form-control"
           value="<?= h($fechaSeleccionada) ?>" max="<?= h($fechaHoy) ?>" required>
    <div class="d-flex gap-2 mt-3">
      <button type="submit" class="btn btn-outline-secondary w-50" formmethod="get">
        Ver cobros
      </button>
      <button type="submit" class="btn btn-primary w-50"
              <?= $btnDisabled ?>
              title="<?= $hayCobros ? 'Generar corte' : 'No hay cobros en esta fecha' ?>"
              onclick="return confirm('¿Confirmas generar el corte de caja para la fecha seleccionada?');">
        📤 Generar Corte
      </button>
    </div>
    <?php if (!$hayCobros): ?>
      <p class="text-muted mt-2 mb-0">No hay cobros pendientes para esta fecha.</p>
    <?php endif; ?>
  </form>

  <h4>Cobros pendientes para la fecha seleccionada</h4>
  <?php if (!$hayCobros): ?>
    <div class="alert alert-info">No hay cobros pendientes para la fecha <?= h($fechaSeleccionada) ?>.</div>
  <?php else: ?>
    <table class="table table-sm table-bordered">
      <thead class="table-dark">
        <tr>
          <th>Fecha</th><th>Usuario</th><th>Motivo</th><th>Tipo Pago</th>
          <th>Total</th><th>Efectivo</th><th>Tarjeta</th><th>Comisión Esp.</th><th>Eliminar</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($cobrosFecha as $p): ?>
          <tr>
            <td><?= h($p['fecha_cobro']) ?></td>
            <td><?= h($p['usuario']) ?></td>
            <td><?= h($p['motivo']) ?></td>
            <td><?= h($p['tipo_pago']) ?></td>
            <td>$<?= number_format((float)$p['monto_total'], 2) ?></td>
            <td>$<?= number_format((float)$p['monto_efectivo'], 2) ?></td>
            <td>$<?= number_format((float)$p['monto_tarjeta'], 2) ?></td>
            <td>$<?= number_format((float)$p['comision_especial'], 2) ?></td>
            <td>
              <a href="eliminar_cobro.php?id=<?= (int)$p['id'] ?>"
                 class="btn btn-danger btn-sm"
                 onclick="return confirm('¿Seguro de eliminar este cobro?');">🗑</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <h3 class="mt-5">📜 Historial de Cortes</h3>
  <form method="GET" class="row g-2 mb-3">
    <div class="col-md-4">
      <label class="form-label">Desde</label>
      <input type="date" name="desde" class="form-control" value="<?= h($desde) ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">Hasta</label>
      <input type="date" name="hasta" class="form-control" value="<?= h($hasta) ?>">
    </div>
    <div class="col-md-4 d-flex align-items-end">
      <input type="hidden" name="fecha_operacion" value="<?= h($fechaSeleccionada) ?>">
      <button class="btn btn-primary w-100">Filtrar</button>
    </div>
  </form>

  <?php if (empty($histCortes)): ?>
    <div class="alert alert-info">No hay cortes en el rango seleccionado.</div>
  <?php else: ?>
    <table class="table table-bordered table-sm">
      <thead class="table-dark">
        <tr>
          <th>ID Corte</th><th>Fecha Corte</th><th>Usuario</th>
          <th>Efectivo</th><th>Tarjeta</th><th>Total</th><th>Estado</th><th>Monto Depositado</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($histCortes as $c): ?>
          <tr class="<?= $c['estado']==='Cerrado' ? 'table-success' : 'table-warning' ?>">
            <td><?= (int)$c['id'] ?></td>
            <td><?= h($c['fecha_corte']) ?></td>
            <td><?= h($c['usuario']) ?></td>
            <td>$<?= number_format((float)$c['total_efectivo'], 2) ?></td>
            <td>$<?= number_format((float)$c['total_tarjeta'], 2) ?></td>
            <td>$<?= number_format((float)$c['total_general'], 2) ?></td>
            <td><?= h($c['estado']) ?></td>
            <td>$<?= number_format((float)$c['monto_depositado'], 2) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

</div>
</body>
</html>
