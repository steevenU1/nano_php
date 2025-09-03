<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Ejecutivo','Admin'])) {
    header("Location: 403.php");
    exit();
}

require_once __DIR__ . '/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$id_usuario  = (int)($_SESSION['id_usuario'] ?? 0);
$id_sucursal = (int)($_SESSION['id_sucursal'] ?? 0);
$fechaHoy    = date('Y-m-d');
$msg = "";

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ===== (opcional) AUTO-CREATE: gastos_sucursal ===== */
try {
  $conn->query("
    CREATE TABLE IF NOT EXISTS gastos_sucursal (
      id INT AUTO_INCREMENT PRIMARY KEY,
      id_sucursal INT NOT NULL,
      id_usuario  INT NOT NULL,
      fecha_gasto DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      categoria   VARCHAR(64) NOT NULL,
      concepto    VARCHAR(255) NOT NULL,
      monto       DECIMAL(12,2) NOT NULL DEFAULT 0,
      observaciones TEXT NULL,
      id_corte    INT NULL,
      creado_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_gs_suc(fecha_gasto, id_sucursal),
      INDEX idx_gs_corte(id_corte)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
} catch (Throwable $e) { /* noop */ }

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
        /* Cobros pendientes del día */
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

        /* Totales de cobros
           IMPORTANTE: la comisión especial (10) cuenta como EFECTIVO */
        $total_efectivo_bruto   = 0.0; // efectivo + comisiones especiales
        $total_efectivo_solo    = 0.0; // solo efectivo (referencia)
        $total_tarjeta          = 0.0;
        $total_comision_especial= 0.0;

        foreach ($cobros as $c) {
            $ef   = (float)$c['monto_efectivo'];
            $tj   = (float)$c['monto_tarjeta'];
            $com  = (float)$c['comision_especial'];

            $total_efectivo_solo    += $ef;
            $total_tarjeta          += $tj;
            $total_comision_especial+= $com;

            // Aquí está el truco: la comisión entra al EFECTIVO del corte
            $total_efectivo_bruto   += ($ef + $com);
        }

        /* Gastos del día (no ligados) — descuentan EFECTIVO */
        $stG = $conn->prepare("
          SELECT IFNULL(SUM(monto),0) AS total_gastos
          FROM gastos_sucursal
          WHERE id_sucursal=? AND DATE(fecha_gasto)=? AND id_corte IS NULL
          FOR UPDATE
        ");
        $stG->bind_param("is", $id_sucursal, $fecha_operacion);
        $stG->execute();
        $gRow = $stG->get_result()->fetch_assoc();
        $stG->close();
        $total_gastos_efectivo = (float)($gRow['total_gastos'] ?? 0.0);

        /* Efectivo neto y total general
           efectivo_neto = (efectivo + comisiones) - gastos */
        $efectivo_neto = max(0.0, $total_efectivo_bruto - $total_gastos_efectivo);
        $total_general = $efectivo_neto + $total_tarjeta;

        /* Observación informativa */
        $obs = "Gastos efectivo considerados en corte: $".number_format($total_gastos_efectivo,2).
               " | Comisión especial incluida en EFECTIVO: $".number_format($total_comision_especial,2);

        /* Insertar CORTE */
        $stmtCorte = $conn->prepare("
          INSERT INTO cortes_caja
          (id_sucursal, id_usuario, fecha_operacion, fecha_corte, estado,
           total_efectivo, total_tarjeta, total_comision_especial, total_general,
           depositado, monto_depositado, observaciones)
          VALUES (?, ?, ?, NOW(), 'Pendiente', ?, ?, ?, ?, 0, 0, ?)
        ");
        $stmtCorte->bind_param(
            "iisdddds",
            $id_sucursal, $id_usuario, $fecha_operacion,
            $efectivo_neto, $total_tarjeta, $total_comision_especial, $total_general,
            $obs
        );
        if (!$stmtCorte->execute()) throw new Exception("Error al insertar corte: ".$stmtCorte->error);
        $id_corte = $stmtCorte->insert_id;
        $stmtCorte->close();

        /* Ligar COBROS al corte */
        $stmtUpd = $conn->prepare("
          UPDATE cobros
          SET id_corte = ?, corte_generado = 1
          WHERE id_sucursal = ? AND DATE(fecha_cobro) = ? AND id_corte IS NULL AND corte_generado = 0
        ");
        $stmtUpd->bind_param("iis", $id_corte, $id_sucursal, $fecha_operacion);
        if (!$stmtUpd->execute()) throw new Exception("Error al actualizar cobros: ".$stmtUpd->error);
        $stmtUpd->close();

        /* Ligar GASTOS al corte */
        $upG = $conn->prepare("
          UPDATE gastos_sucursal
          SET id_corte = ?
          WHERE id_sucursal=? AND DATE(fecha_gasto)=? AND id_corte IS NULL
        ");
        $upG->bind_param("iis", $id_corte, $id_sucursal, $fecha_operacion);
        $upG->execute();
        $upG->close();

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

/* ===== 4) HTML / datos para la vista ===== */
require_once __DIR__ . '/navbar.php';

/* Cobros pendientes de la fecha seleccionada (para lista) */
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

/* === Totales para “tarjetita” (fecha seleccionada)
       EFECTIVO mostrado = efectivo + comisión especial  */
$tot_efectivo_solo = 0.0;
$tot_efectivo      = 0.0; // efectivo + comisión
$tot_tarjeta       = 0.0;
$tot_comision      = 0.0;

foreach ($cobrosFecha as $c) {
  $ef = (float)$c['monto_efectivo'];
  $tj = (float)$c['monto_tarjeta'];
  $co = (float)$c['comision_especial'];

  $tot_efectivo_solo += $ef;
  $tot_tarjeta       += $tj;
  $tot_comision      += $co;
  $tot_efectivo      += ($ef + $co);
}

/* Gastos del día (no ligados aún) */
$stG2 = $conn->prepare("
  SELECT IFNULL(SUM(monto),0) AS total_gastos
  FROM gastos_sucursal
  WHERE id_sucursal=? AND DATE(fecha_gasto)=? AND id_corte IS NULL
");
$stG2->bind_param("is", $id_sucursal, $fechaSeleccionada);
$stG2->execute();
$g2 = $stG2->get_result()->fetch_assoc();
$stG2->close();
$tot_gastos = (float)($g2['total_gastos'] ?? 0.0);

/* Efectivo neto de la tarjeta previa (para informar antes de generar corte) */
$efectivo_neto = max(0.0, $tot_efectivo - $tot_gastos);

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
  <style>
    .card-soft{border:1px solid rgba(0,0,0,.06);border-radius:16px;box-shadow:0 8px 24px rgba(2,6,23,.06)}
    .kpi{display:flex;flex-direction:column;gap:.25rem;padding:12px 14px;border-radius:12px;border:1px dashed #e5e7eb;background:#fafafa}
    .kpi .lbl{font-size:.9rem;color:#64748b}
    .kpi .val{font-weight:700}
    .hint{font-size:.85rem;color:#64748b}
  </style>
</head>
<body class="bg-light">
<div class="container mt-4">

  <h2>🧾 Generar Corte de Caja</h2>
  <?= $msg ?>

  <!-- Tarjetita de validación previa -->
  <div class="card card-soft p-3 mb-4">
    <h5 class="mb-3">Resumen del día — <?= h($fechaSeleccionada) ?></h5>
    <div class="row g-2">
      <div class="col-12 col-md-3">
        <div class="kpi">
          <span class="lbl">Cobrado en efectivo <span class="hint">(incluye comisión especial)</span></span>
          <span class="val text-success">$<?= number_format($tot_efectivo,2) ?></span>
        </div>
      </div>
      <div class="col-12 col-md-3">
        <div class="kpi">
          <span class="lbl">Cobrado con tarjeta</span>
          <span class="val">$<?= number_format($tot_tarjeta,2) ?></span>
        </div>
      </div>
      <div class="col-12 col-md-3">
        <div class="kpi">
          <span class="lbl">Gastos (efectivo) no ligados</span>
          <span class="val text-danger">$<?= number_format($tot_gastos,2) ?></span>
        </div>
      </div>
      <div class="col-12 col-md-3">
        <div class="kpi">
          <span class="lbl">Efectivo neto (efectivo − gastos)</span>
          <span class="val text-primary">$<?= number_format($efectivo_neto,2) ?></span>
        </div>
      </div>
    </div>
    <div class="form-text mt-2">
      <b>Nota:</b> La <u>comisión especial</u> de PayJoy/Krediya se incluye dentro del efectivo del corte.
    </div>
  </div>

  <div class="mb-3">
    <h5>Días pendientes de corte:</h5>
    <?php if (empty($pendientes)): ?>
      <div class="alert alert-info">No hay días pendientes.</div>
    <?php else: ?>
      <ul class="mb-0">
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
              onclick="return confirm('Se generará el corte con EFECTIVO = (efectivo + comisión especial) y se restarán los gastos del día. ¿Confirmas?');">
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
            <td>$<?= number_format(((float)$p['monto_efectivo'] + (float)$p['comision_especial']), 2) ?></td>
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
