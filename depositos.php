<?php
// depositos.php — Admin valida depósitos con opción de ajuste
session_start();
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
  header("Location: 403.php");
  exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Helper HTML seguro (evita redeclaración si navbar.php ya lo define)
if (!function_exists('e')) {
  function e($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
  }
}

$msg = '';

/* ===========================================================
   1) Acciones: Validar (sin ajuste) y ValidarAjuste (con ajuste)
   =========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_deposito'], $_POST['accion'])) {
  $idDeposito = (int)$_POST['id_deposito'];
  $accion     = $_POST['accion'];

  // Traer datos del depósito y del corte
  $sqlBase = "
      SELECT ds.id, ds.id_corte, ds.id_sucursal, ds.monto_depositado, ds.estado,
             cc.total_efectivo, cc.id AS corte_id
      FROM depositos_sucursal ds
      INNER JOIN cortes_caja cc ON cc.id = ds.id_corte
      WHERE ds.id = ?
      LIMIT 1
    ";
  $st = $conn->prepare($sqlBase);
  $st->bind_param('i', $idDeposito);
  $st->execute();
  $dep = $st->get_result()->fetch_assoc();
  $st->close();

  if ($dep && $dep['estado'] === 'Pendiente') {
    $adminId = (int)$_SESSION['id_usuario'];

    if ($accion === 'Validar') {
      // Validación simple: monto_validado = monto_depositado
      $upd = $conn->prepare("
        UPDATE depositos_sucursal
        SET estado='Validado',
            id_admin_valida=?,
            monto_validado = ROUND(monto_depositado,2),
            ajuste = 0,
            motivo_ajuste = NULL,
            actualizado_en=NOW()
        WHERE id=? AND estado='Pendiente'
      ");
      $upd->bind_param('ii', $adminId, $idDeposito);
      $upd->execute();
      $upd->close();

    } elseif ($accion === 'ValidarAjuste') {
      // Validación con ajuste
      $ajuste = (float)($_POST['ajuste'] ?? 0);
      if ($ajuste < 0) $ajuste = 0;
      $motivo = trim($_POST['motivo_ajuste'] ?? '');

      // Calcular validado previo (excluyendo este depósito)
      $prevQ = $conn->prepare("
        SELECT IFNULL(SUM(monto_validado),0) AS suma_prev
        FROM depositos_sucursal
        WHERE id_corte=? AND estado='Validado'
      ");
      $prevQ->bind_param('i', $dep['id_corte']);
      $prevQ->execute();
      $prev = $prevQ->get_result()->fetch_assoc();
      $prevQ->close();

      $sumaPrev = (float)$prev['suma_prev'];
      $faltante = max(0.0, (float)$dep['total_efectivo'] - ($sumaPrev + (float)$dep['monto_depositado']));
      if ($ajuste > $faltante) $ajuste = $faltante;
      if ($ajuste > 0 && $motivo === '') { $motivo = 'Ajuste de caja'; }

      $upd = $conn->prepare("
        UPDATE depositos_sucursal
        SET estado='Validado',
            id_admin_valida=?,
            ajuste=ROUND(?,2),
            motivo_ajuste=?,
            monto_validado = ROUND(monto_depositado + ?,2),
            actualizado_en=NOW()
        WHERE id=? AND estado='Pendiente'
      ");
      $upd->bind_param('idssi', $adminId, $ajuste, $motivo, $ajuste, $idDeposito);
      $upd->execute();
      $upd->close();
    }

    // Recalcular suma validada del corte y cerrar si procede
    $sumQ = $conn->prepare("
      SELECT
        cc.id            AS corte_id,
        cc.total_efectivo,
        IFNULL(SUM(ds.monto_validado),0) AS suma_val
      FROM cortes_caja cc
      LEFT JOIN depositos_sucursal ds
        ON ds.id_corte = cc.id AND ds.estado='Validado'
      WHERE cc.id = ?
      GROUP BY cc.id, cc.total_efectivo
    ");
    $sumQ->bind_param('i', $dep['id_corte']);
    $sumQ->execute();
    $sum = $sumQ->get_result()->fetch_assoc();
    $sumQ->close();

    if ($sum && (float)$sum['suma_val'] >= (float)$sum['total_efectivo']) {
      // Cerrar corte usando la suma validada (incluye ajustes)
      $obsAppend = 'Cierre automático por validación de depósitos (incluye ajustes).';
      $close = $conn->prepare("
        UPDATE cortes_caja
        SET estado='Cerrado',
            depositado=1,
            monto_depositado = ROUND(?,2),
            fecha_deposito = NOW(),
            observaciones = TRIM(CONCAT(COALESCE(observaciones,''),' ',?))
        WHERE id=?
      ");
      $sumVal = (float)$sum['suma_val'];
      $close->bind_param('dsi', $sumVal, $obsAppend, $sum['corte_id']);
      $close->execute();
      $close->close();
    }

    $msg = "<div class='alert alert-success'>✅ Depósito validado correctamente.</div>";
  } else {
    $msg = "<div class='alert alert-warning'>El depósito no existe o ya fue procesado.</div>";
  }
}

/* ===========================================================
   2) Listado de depósitos PENDIENTES (con datos para modal)
   =========================================================== */
$sqlPendientes = "
  SELECT
    ds.id AS id_deposito,
    s.nombre AS sucursal,
    ds.id_corte,
    cc.fecha_corte,
    cc.total_efectivo,
    ds.monto_depositado,
    ds.banco,
    ds.referencia,
    ds.estado,
    ds.comprobante_archivo,
    -- Suma validada previa (sin contar los pendientes)
    (
      SELECT IFNULL(SUM(x.monto_validado),0)
      FROM depositos_sucursal x
      WHERE x.id_corte = ds.id_corte AND x.estado='Validado'
    ) AS suma_validada_previa
  FROM depositos_sucursal ds
  INNER JOIN cortes_caja cc ON cc.id = ds.id_corte
  INNER JOIN sucursales s   ON s.id = ds.id_sucursal
  WHERE ds.estado = 'Pendiente'
  ORDER BY cc.fecha_corte ASC, ds.id_corte ASC, ds.id ASC
";
$pendientes = $conn->query($sqlPendientes)->fetch_all(MYSQLI_ASSOC);

/* ===========================================================
   3) Historial con filtros
   =========================================================== */
$sucursales = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);

$sucursal_id = (int)($_GET['sucursal_id'] ?? 0);
$desde       = trim($_GET['desde'] ?? '');
$hasta       = trim($_GET['hasta'] ?? '');
$semana      = trim($_GET['semana'] ?? '');

if ($semana && preg_match('/^(\d{4})-W(\d{2})$/', $semana, $m)) {
  $yr = (int)$m[1];
  $wk = (int)$m[2];
  $dt = new DateTime();
  $dt->setISODate($yr, $wk);
  $desde = $dt->format('Y-m-d');
  $dt->modify('+6 days');
  $hasta = $dt->format('Y-m-d');
}

$sqlHistorial = "
  SELECT ds.id AS id_deposito,
         s.nombre AS sucursal,
         ds.id_corte,
         cc.fecha_corte,
         ds.fecha_deposito,
         ds.monto_depositado,
         ds.monto_validado,
         ds.ajuste,
         ds.motivo_ajuste,
         ds.banco,
         ds.referencia,
         ds.estado,
         ds.comprobante_archivo
  FROM depositos_sucursal ds
  INNER JOIN cortes_caja cc ON cc.id = ds.id_corte
  INNER JOIN sucursales s   ON s.id = ds.id_sucursal
  WHERE 1=1
";
$types = '';
$params = [];
if ($sucursal_id > 0) { $sqlHistorial .= " AND s.id=? "; $types .= 'i'; $params[] = $sucursal_id; }
if ($desde !== '')    { $sqlHistorial .= " AND DATE(ds.fecha_deposito)>=? "; $types .= 's'; $params[] = $desde; }
if ($hasta !== '')    { $sqlHistorial .= " AND DATE(ds.fecha_deposito)<=? "; $types .= 's'; $params[] = $hasta; }
$sqlHistorial .= " ORDER BY ds.fecha_deposito DESC, ds.id DESC";
$stH = $conn->prepare($sqlHistorial);
if ($types) { $stH->bind_param($types, ...$params); }
$stH->execute();
$historial = $stH->get_result()->fetch_all(MYSQLI_ASSOC);
$stH->close();

/* ===========================================================
   4) Saldos por sucursal (usando monto_validado + ajuste)
   =========================================================== */
$sqlSaldos = "
  SELECT 
    s.id,
    s.nombre AS sucursal,

    -- efectivo cobrado (de cobros ya cortados)
    IFNULL(SUM(c.monto_efectivo),0) AS total_efectivo,

    -- suma de AJUSTES de depósitos validados de la sucursal
    IFNULL((
      SELECT SUM(d.ajuste)
      FROM depositos_sucursal d
      WHERE d.id_sucursal = s.id AND d.estado='Validado'
    ),0) AS total_ajustado,

    -- suma VALIDADA (depositado + ajuste)
    IFNULL((
      SELECT SUM(d.monto_validado)
      FROM depositos_sucursal d
      WHERE d.id_sucursal = s.id AND d.estado='Validado'
    ),0) AS total_depositado_validado,

    -- saldo pendiente = efectivo - validado (dep+ajuste)
    GREATEST(
      IFNULL(SUM(c.monto_efectivo),0) - IFNULL((
        SELECT SUM(d2.monto_validado)
        FROM depositos_sucursal d2
        WHERE d2.id_sucursal = s.id AND d2.estado='Validado'
      ),0),
      0
    ) AS saldo_pendiente
  FROM sucursales s
  LEFT JOIN cobros c 
    ON c.id_sucursal = s.id 
   AND c.corte_generado = 1
  WHERE s.subtipo <> 'Master Admin'
  GROUP BY s.id
  ORDER BY saldo_pendiente DESC
";
$saldos = $conn->query($sqlSaldos)->fetch_all(MYSQLI_ASSOC);

/* ===========================================================
   5) Cortes de caja (con totales de ajuste y validado del corte)
   =========================================================== */
$c_sucursal_id = (int)($_GET['c_sucursal_id'] ?? 0);
$c_desde       = trim($_GET['c_desde'] ?? '');
$c_hasta       = trim($_GET['c_hasta'] ?? '');

$sqlCortes = "
  SELECT 
    cc.id,
    s.nombre AS sucursal,
    cc.fecha_operacion,
    cc.fecha_corte,
    cc.estado,
    cc.total_efectivo,
    cc.total_tarjeta,
    cc.total_comision_especial,
    cc.total_general,
    cc.depositado,
    cc.monto_depositado,

    (SELECT COUNT(*) FROM cobros cb WHERE cb.id_corte = cc.id) AS num_cobros,

    -- total de AJUSTE aplicado a los depósitos validados del corte
    IFNULL((
      SELECT SUM(ds.ajuste)
      FROM depositos_sucursal ds
      WHERE ds.id_corte = cc.id AND ds.estado = 'Validado'
    ), 0) AS total_ajuste_corte,

    -- total VALIDADO (depósito + ajuste) del corte
    IFNULL((
      SELECT SUM(ds.monto_validado)
      FROM depositos_sucursal ds
      WHERE ds.id_corte = cc.id AND ds.estado = 'Validado'
    ), 0) AS total_validado_corte
  FROM cortes_caja cc
  INNER JOIN sucursales s ON s.id = cc.id_sucursal
  WHERE 1=1
";
$typesC = ''; $paramsC = [];
if ($c_sucursal_id > 0) { $sqlCortes .= " AND cc.id_sucursal=? "; $typesC .= 'i'; $paramsC[] = $c_sucursal_id; }
if ($c_desde !== '')     { $sqlCortes .= " AND cc.fecha_operacion>=? "; $typesC .= 's'; $paramsC[] = $c_desde; }
if ($c_hasta !== '')     { $sqlCortes .= " AND cc.fecha_operacion<=? "; $typesC .= 's'; $paramsC[] = $c_hasta; }
$sqlCortes .= " ORDER BY cc.fecha_operacion DESC, cc.id DESC";
$stC = $conn->prepare($sqlCortes);
if ($typesC) { $stC->bind_param($typesC, ...$paramsC); }
$stC->execute();
$cortes = $stC->get_result()->fetch_all(MYSQLI_ASSOC);
$stC->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Validación de Depósitos</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <style>
    .modal-xl { max-width: 1000px; }
    #visorFrame { width: 100%; height: 80vh; border: 0; }
  </style>
</head>
<body class="bg-light">

<div class="container mt-4">
  <h2>🏦 Validación de Depósitos - Admin</h2>
  <?= $msg ?>

  <!-- ============ PENDIENTES ============ -->
  <h4 class="mt-4">Depósitos Pendientes de Validación</h4>
  <?php if (count($pendientes) === 0): ?>
    <div class="alert alert-info">No hay depósitos pendientes.</div>
  <?php else: ?>
    <table class="table table-bordered table-sm align-middle">
      <thead class="table-dark">
        <tr>
          <th>ID Depósito</th>
          <th>Sucursal</th>
          <th>ID Corte</th>
          <th>Fecha Corte</th>
          <th>Monto</th>
          <th>Banco</th>
          <th>Referencia</th>
          <th>Comprobante</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php 
      $lastCorte = null;
      foreach ($pendientes as $p): 
        if ($lastCorte !== $p['id_corte']): ?>
          <tr class="table-secondary">
            <td colspan="9">
              Corte #<?= (int)$p['id_corte'] ?> - <?= e($p['sucursal']) ?>
              (Fecha: <?= e($p['fecha_corte']) ?> | Total Efectivo: $<?= number_format($p['total_efectivo'],2) ?>)
            </td>
          </tr>
        <?php endif; ?>
        <tr>
          <td><?= (int)$p['id_deposito'] ?></td>
          <td><?= e($p['sucursal']) ?></td>
          <td><?= (int)$p['id_corte'] ?></td>
          <td><?= e($p['fecha_corte']) ?></td>
          <td>$<?= number_format($p['monto_depositado'],2) ?></td>
          <td><?= e($p['banco']) ?></td>
          <td><?= e($p['referencia']) ?></td>
          <td>
            <?php if (!empty($p['comprobante_archivo'])): ?>
              <button 
                class="btn btn-primary btn-sm js-ver" 
                data-src="deposito_comprobante.php?id=<?= (int)$p['id_deposito'] ?>" 
                data-bs-toggle="modal" data-bs-target="#visorModal">
                Ver
              </button>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="d-flex gap-1">
            <!-- Validación directa -->
            <form method="POST" class="d-inline">
              <input type="hidden" name="id_deposito" value="<?= (int)$p['id_deposito'] ?>">
              <button name="accion" value="Validar" class="btn btn-success btn-sm">✅ Validar</button>
            </form>

            <!-- Ajustar y validar (abre modal) -->
            <button
              type="button"
              class="btn btn-warning btn-sm js-ajustar"
              data-bs-toggle="modal" data-bs-target="#ajusteModal"
              data-id="<?= (int)$p['id_deposito'] ?>"
              data-total="<?= (float)$p['total_efectivo'] ?>"
              data-previo="<?= (float)$p['suma_validada_previa'] ?>"
              data-depo="<?= (float)$p['monto_depositado'] ?>"
              data-sucursal="<?= e($p['sucursal']) ?>"
              data-corte="<?= (int)$p['id_corte'] ?>"
            >🧮 Ajustar y Validar</button>
          </td>
        </tr>
      <?php 
        $lastCorte = $p['id_corte'];
      endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <!-- ============ HISTORIAL ============ -->
  <div class="d-flex align-items-center mt-4">
    <h4 class="mb-0">Historial de Depósitos</h4>
    <small class="text-muted ms-2">(usa los filtros para acotar resultados)</small>
  </div>

  <form class="row g-2 mt-2 mb-3" method="get">
    <div class="col-md-4 col-lg-3">
      <label class="form-label mb-0">Sucursal</label>
      <select name="sucursal_id" class="form-select form-select-sm">
        <option value="0">Todas</option>
        <?php foreach ($sucursales as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= $sucursal_id===(int)$s['id']?'selected':'' ?>>
            <?= e($s['nombre']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4 col-lg-3">
      <label class="form-label mb-0">Desde</label>
      <input type="date" name="desde" class="form-control form-control-sm" value="<?= e($desde) ?>" <?= $semana ? 'disabled' : '' ?>>
    </div>
    <div class="col-md-4 col-lg-3">
      <label class="form-label mb-0">Hasta</label>
      <input type="date" name="hasta" class="form-control form-control-sm" value="<?= e($hasta) ?>" <?= $semana ? 'disabled' : '' ?>>
    </div>
    <div class="col-md-4 col-lg-3">
      <label class="form-label mb-0">Semana (ISO)</label>
      <input type="week" name="semana" class="form-control form-control-sm" value="<?= e($semana) ?>">
      <small class="text-muted">Si eliges semana, ignora las fechas.</small>
    </div>
    <div class="col-12 d-flex gap-2">
      <button class="btn btn-primary btn-sm">Aplicar filtros</button>
      <a class="btn btn-outline-secondary btn-sm" href="depositos.php">Limpiar</a>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-bordered table-sm align-middle">
      <thead class="table-dark">
        <tr>
          <th>ID</th>
          <th>Sucursal</th>
          <th>ID Corte</th>
          <th>Fecha Corte</th>
          <th>Fecha Depósito</th>
          <th>Monto</th>
          <th>Ajuste</th>
          <th>Validado</th>
          <th>Motivo</th>
          <th>Banco</th>
          <th>Referencia</th>
          <th>Comprobante</th>
          <th>Estado</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$historial): ?>
          <tr><td colspan="13" class="text-muted">Sin resultados con los filtros actuales.</td></tr>
        <?php endif; ?>
        <?php foreach ($historial as $h): ?>
          <tr class="<?= $h['estado']=='Validado'?'table-success':'table-warning' ?>">
            <td><?= (int)$h['id_deposito'] ?></td>
            <td><?= e($h['sucursal']) ?></td>
            <td><?= (int)$h['id_corte'] ?></td>
            <td><?= e($h['fecha_corte']) ?></td>
            <td><?= e($h['fecha_deposito']) ?></td>
            <td>$<?= number_format((float)$h['monto_depositado'],2) ?></td>
            <td>$<?= number_format((float)$h['ajuste'],2) ?></td>
            <td><b>$<?= number_format((float)$h['monto_validado'],2) ?></b></td>
            <td><?= e($h['motivo_ajuste']) ?></td>
            <td><?= e($h['banco']) ?></td>
            <td><?= e($h['referencia']) ?></td>
            <td>
              <?php if (!empty($h['comprobante_archivo'])): ?>
                <button 
                  class="btn btn-outline-primary btn-sm js-ver" 
                  data-src="deposito_comprobante.php?id=<?= (int)$h['id_deposito'] ?>" 
                  data-bs-toggle="modal" data-bs-target="#visorModal">
                  Ver
                </button>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td><?= e($h['estado']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- ============ CORTES DE CAJA ============ -->
  <div class="d-flex align-items-center mt-5">
    <h4 class="mb-0">🧾 Cortes de caja</h4>
    <small class="text-muted ms-2">(filtra por sucursal y rango de fechas)</small>
  </div>

  <form class="row g-2 mt-2 mb-3" method="get">
    <div class="col-md-4 col-lg-3">
      <label class="form-label mb-0">Sucursal</label>
      <select name="c_sucursal_id" class="form-select form-select-sm">
        <option value="0">Todas</option>
        <?php foreach ($sucursales as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= $c_sucursal_id===(int)$s['id']?'selected':'' ?>>
            <?= e($s['nombre']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4 col-lg-3">
      <label class="form-label mb-0">Desde</label>
      <input type="date" name="c_desde" class="form-control form-control-sm" value="<?= e($c_desde) ?>">
    </div>
    <div class="col-md-4 col-lg-3">
      <label class="form-label mb-0">Hasta</label>
      <input type="date" name="c_hasta" class="form-control form-control-sm" value="<?= e($c_hasta) ?>">
    </div>
    <div class="col-12 d-flex gap-2">
      <button class="btn btn-primary btn-sm">Filtrar cortes</button>
      <a class="btn btn-outline-secondary btn-sm" href="depositos.php">Limpiar</a>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-bordered table-sm align-middle">
      <thead class="table-dark">
        <tr>
          <th>ID Corte</th>
          <th>Sucursal</th>
          <th>Fecha Operación</th>
          <th>Fecha Corte</th>
          <th>Efec.</th>
          <th>Tarj.</th>
          <th>Com. Esp.</th>
          <th>Total</th>
          <th>Ajuste Dep.</th>           <!-- NUEVA -->
          <th>Validado (dep+ajuste)</th> <!-- NUEVA -->
          <th>Depositado</th>
          <th>Estado</th>
          <th>Detalle</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$cortes): ?>
          <tr><td colspan="13" class="text-muted">Sin cortes con los filtros seleccionados.</td></tr>
        <?php else: foreach ($cortes as $c): ?>
          <tr>
            <td><?= (int)$c['id'] ?></td>
            <td><?= e($c['sucursal']) ?></td>
            <td><?= e($c['fecha_operacion']) ?></td>
            <td><?= e($c['fecha_corte']) ?></td>
            <td>$<?= number_format((float)$c['total_efectivo'],2) ?></td>
            <td>$<?= number_format((float)$c['total_tarjeta'],2) ?></td>
            <td>$<?= number_format((float)$c['total_comision_especial'],2) ?></td>
            <td><b>$<?= number_format((float)$c['total_general'],2) ?></b></td>
            <td>$<?= number_format((float)$c['total_ajuste_corte'],2) ?></td>
            <td><b>$<?= number_format((float)$c['total_validado_corte'],2) ?></b></td>
            <td>
              <?= $c['depositado'] ? ('$'.number_format((float)$c['monto_depositado'],2)) : '<span class="text-muted">No</span>' ?>
            </td>
            <td>
              <span class="badge <?= $c['estado']==='Cerrado'?'bg-success':'bg-warning text-dark' ?>">
                <?= e($c['estado']) ?>
              </span>
            </td>
            <td>
              <button class="btn btn-sm btn-outline-primary" type="button"
                      data-bs-toggle="collapse" data-bs-target="#det<?= (int)$c['id'] ?>">
                Ver cobros (<?= (int)$c['num_cobros'] ?>)
              </button>
            </td>
          </tr>
          <tr class="collapse" id="det<?= (int)$c['id'] ?>">
            <td colspan="13">
              <?php
                $qc = $conn->prepare("
                  SELECT cb.id, cb.motivo, cb.tipo_pago, cb.monto_total, cb.monto_efectivo, cb.monto_tarjeta,
                         cb.comision_especial, cb.fecha_cobro, u.nombre AS ejecutivo
                  FROM cobros cb
                  LEFT JOIN usuarios u ON u.id = cb.id_usuario
                  WHERE cb.id_corte = ?
                  ORDER BY cb.fecha_cobro ASC, cb.id ASC
                ");
                $qc->bind_param('i', $c['id']);
                $qc->execute();
                $rows = $qc->get_result()->fetch_all(MYSQLI_ASSOC);
                $qc->close();
              ?>
              <div class="table-responsive">
                <table class="table table-sm mb-0">
                  <thead>
                    <tr>
                      <th>ID Cobro</th>
                      <th>Fecha/Hora</th>
                      <th>Ejecutivo</th>
                      <th>Motivo</th>
                      <th>Tipo pago</th>
                      <th>Total</th>
                      <th>Efectivo</th>
                      <th>Tarjeta</th>
                      <th>Com. Especial</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php foreach ($rows as $r): ?>
                    <tr>
                      <td><?= (int)$r['id'] ?></td>
                      <td><?= e($r['fecha_cobro']) ?></td>
                      <td><?= e($r['ejecutivo'] ?? 'N/D') ?></td>
                      <td><?= e($r['motivo']) ?></td>
                      <td><?= e($r['tipo_pago']) ?></td>
                      <td>$<?= number_format((float)$r['monto_total'],2) ?></td>
                      <td>$<?= number_format((float)$r['monto_efectivo'],2) ?></td>
                      <td>$<?= number_format((float)$r['monto_tarjeta'],2) ?></td>
                      <td>$<?= number_format((float)$r['comision_especial'],2) ?></td>
                    </tr>
                  <?php endforeach; if(!$rows): ?>
                    <tr><td colspan="9" class="text-muted">Sin cobros ligados a este corte.</td></tr>
                  <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- ============ EXPORTAR TRANSACCIONES POR DÍA ============ -->
  <div class="card mt-5">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span>📤 Exportar transacciones completas por día (todas las sucursales)</span>
    </div>
    <div class="card-body">
      <form class="row g-2 align-items-end" method="get" action="export_transacciones_dia.php" target="_blank">
        <div class="col-sm-4 col-md-3">
          <label class="form-label mb-0">Día</label>
          <input type="date" name="dia" class="form-control" required>
        </div>
        <div class="col-sm-8 col-md-5">
          <div class="form-text">
            Exporta los registros de <b>cobros</b> (ventas/cobros) de ese día en CSV, con sucursal y ejecutivo.
          </div>
        </div>
        <div class="col-md-4 text-end">
          <button class="btn btn-outline-success">Descargar CSV</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ============ SALDOS ============ -->
  <h4 class="mt-5">📊 Saldos por Sucursal</h4>
  <table class="table table-bordered table-sm mt-3">
    <thead class="table-dark">
      <tr>
        <th>Sucursal</th>
        <th>Total Efectivo Cobrado</th>
        <th>Ajustes aplicados</th>
        <th>Total Validado (dep+ajuste)</th>
        <th>Saldo Pendiente</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach($saldos as $s): ?>
      <tr class="<?= $s['saldo_pendiente']>0?'table-warning':'table-success' ?>">
        <td><?= e($s['sucursal']) ?></td>
        <td>$<?= number_format((float)$s['total_efectivo'],2) ?></td>
        <td>$<?= number_format((float)$s['total_ajustado'],2) ?></td>
        <td>$<?= number_format((float)$s['total_depositado_validado'],2) ?></td>
        <td>$<?= number_format((float)$s['saldo_pendiente'],2) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Modal visor de comprobante -->
<div class="modal fade" id="visorModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Comprobante de depósito</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body p-0">
        <iframe id="visorFrame" src=""></iframe>
      </div>
      <div class="modal-footer">
        <a id="btnAbrirNueva" href="#" target="_blank" class="btn btn-outline-secondary">Abrir en nueva pestaña</a>
        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Listo</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal de Ajuste -->
<div class="modal fade" id="ajusteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="POST">
      <div class="modal-header">
        <h5 class="modal-title">Ajustar y validar depósito</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id_deposito" id="aj_id_deposito" value="">
        <input type="hidden" name="accion" value="ValidarAjuste">

        <div class="mb-2">
          <div><b>Sucursal:</b> <span id="aj_sucursal">—</span></div>
          <div><b>ID Corte:</b> <span id="aj_corte">—</span></div>
        </div>

        <div class="row g-2">
          <div class="col-6">
            <label class="form-label mb-0">Total corte</label>
            <input type="text" id="aj_total" class="form-control form-control-sm" readonly>
          </div>
          <div class="col-6">
            <label class="form-label mb-0">Validado previo</label>
            <input type="text" id="aj_prev" class="form-control form-control-sm" readonly>
          </div>
          <div class="col-6">
            <label class="form-label mb-0">Este depósito</label>
            <input type="text" id="aj_depo" class="form-control form-control-sm" readonly>
          </div>
          <div class="col-6">
            <label class="form-label mb-0">Faltante sugerido</label>
            <input type="text" id="aj_faltante" class="form-control form-control-sm" readonly>
          </div>
        </div>

        <hr class="my-2">

        <div class="row g-2">
          <div class="col-6">
            <label class="form-label">Ajuste a aplicar</label>
            <input type="number" step="0.01" min="0" name="ajuste" id="aj_ajuste" class="form-control form-control-sm" value="0">
            <div class="form-text">Máximo: <span id="aj_max">0.00</span></div>
          </div>
          <div class="col-12">
            <label class="form-label">Motivo del ajuste</label>
            <textarea name="motivo_ajuste" id="aj_motivo" rows="2" class="form-control form-control-sm" placeholder="Ej. combustible, insumos, caja chica"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-warning">Aplicar ajuste y validar</button>
      </div>
    </form>
  </div>
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
  // Toggle inputs cuando seleccionas semana (historial)
  const semanaInput = document.querySelector('input[name="semana"]');
  const desdeInput  = document.querySelector('input[name="desde"]');
  const hastaInput  = document.querySelector('input[name="hasta"]');
  if (semanaInput) {
    semanaInput.addEventListener('input', () => {
      const usingWeek = semanaInput.value.trim() !== '';
      desdeInput.disabled = usingWeek;
      hastaInput.disabled = usingWeek;
      if (usingWeek) { desdeInput.value=''; hastaInput.value=''; }
    });
  }

  // Visor modal
  const visorModal  = document.getElementById('visorModal');
  const visorFrame  = document.getElementById('visorFrame');
  const btnAbrir    = document.getElementById('btnAbrirNueva');
  document.querySelectorAll('.js-ver').forEach(btn => {
    btn.addEventListener('click', () => {
      const src = btn.getAttribute('data-src');
      visorFrame.src = src;
      btnAbrir.href  = src;
    });
  });
  visorModal.addEventListener('hidden.bs.modal', () => {
    visorFrame.src = '';
    btnAbrir.href  = '#';
  });

  // Modal de Ajuste
  const fmt = v => Number(v).toFixed(2);
  document.querySelectorAll('.js-ajustar').forEach(btn => {
    btn.addEventListener('click', () => {
      const id      = btn.dataset.id;
      const total   = parseFloat(btn.dataset.total || '0');
      const previo  = parseFloat(btn.dataset.previo || '0');
      const depo    = parseFloat(btn.dataset.depo   || '0');
      const suc     = btn.dataset.sucursal || '';
      const corte   = btn.dataset.corte    || '';

      const faltante = Math.max(0, total - (previo + depo));
      document.getElementById('aj_id_deposito').value = id;
      document.getElementById('aj_sucursal').textContent = suc;
      document.getElementById('aj_corte').textContent    = corte;
      document.getElementById('aj_total').value     = fmt(total);
      document.getElementById('aj_prev').value      = fmt(previo);
      document.getElementById('aj_depo').value      = fmt(depo);
      document.getElementById('aj_faltante').value  = fmt(faltante);
      document.getElementById('aj_ajuste').value    = fmt(faltante); // sugerir faltante
      document.getElementById('aj_max').textContent = fmt(faltante);
    });
  });
</script>
</body>
</html>
