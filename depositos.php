<?php
// depositos.php — Admin valida depósitos con opción de ajuste (usando total_efectivo YA NETO) + tabs + export gastos + export transacciones del día
session_start();
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
  header("Location: 403.php");
  exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Helper HTML seguro
if (!function_exists('e')) {
  function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$msg = '';
$hoy = date('Y-m-d'); // para prefijar el export de transacciones del día

// ---------------------------------------------
// 1) Acciones POST: Validar / ValidarAjuste
// ---------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_deposito'], $_POST['accion'])) {
  $idDeposito = (int)$_POST['id_deposito'];
  $accion     = $_POST['accion'];

  // Traer depósito + corte + gastos informativos del corte
  // OJO: cc.total_efectivo ya es EFECTIVO NETO DEL CORTE
  $sqlBase = "
    SELECT 
      ds.id, ds.id_corte, ds.id_sucursal, ds.monto_depositado, ds.estado,
      cc.total_efectivo, cc.id AS corte_id,
      COALESCE(ge.gastos_efectivo,0) AS gastos_corte
    FROM depositos_sucursal ds
    INNER JOIN cortes_caja cc ON cc.id = ds.id_corte
    LEFT JOIN (
      SELECT id_corte, SUM(monto) AS gastos_efectivo
      FROM gastos_sucursal
      GROUP BY id_corte
    ) ge ON ge.id_corte = cc.id
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

    // Ya NO restamos gastos aquí: total_efectivo YA ES NETO
    $efectivoNetoCorte = (float)$dep['total_efectivo'];
    if ($efectivoNetoCorte < 0) $efectivoNetoCorte = 0;

    if ($accion === 'Validar') {
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
      $ajuste = (float)($_POST['ajuste'] ?? 0);
      if ($ajuste < 0) $ajuste = 0;
      $motivo = trim($_POST['motivo_ajuste'] ?? '');

      // Validado previo del corte (ya validados)
      $prevQ = $conn->prepare("
        SELECT COALESCE(SUM(monto_validado),0) AS suma_prev
        FROM depositos_sucursal
        WHERE id_corte=? AND estado='Validado'
      ");
      $prevQ->bind_param('i', $dep['id_corte']);
      $prevQ->execute();
      $prev = $prevQ->get_result()->fetch_assoc();
      $prevQ->close();

      $sumaPrev = (float)$prev['suma_prev'];
      $faltante = max(0.0, $efectivoNetoCorte - ($sumaPrev + (float)$dep['monto_depositado']));
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

    // Recalcular suma validada del corte y cerrar si procede (vs total_efectivo NETO)
    $sumQ = $conn->prepare("
      SELECT
        cc.id AS corte_id,
        cc.total_efectivo,
        COALESCE(ge.gastos_efectivo,0) AS gastos_corte,
        COALESCE(SUM(ds.monto_validado),0) AS suma_val
      FROM cortes_caja cc
      LEFT JOIN depositos_sucursal ds
        ON ds.id_corte = cc.id AND ds.estado='Validado'
      LEFT JOIN (
        SELECT id_corte, SUM(monto) AS gastos_efectivo
        FROM gastos_sucursal
        GROUP BY id_corte
      ) ge ON ge.id_corte = cc.id
      WHERE cc.id = ?
      GROUP BY cc.id, cc.total_efectivo, ge.gastos_efectivo
    ");
    $sumQ->bind_param('i', $dep['id_corte']);
    $sumQ->execute();
    $sum = $sumQ->get_result()->fetch_assoc();
    $sumQ->close();

    if ($sum) {
      // El umbral ES el total_efectivo (ya neto). Gastos sólo informativos.
      $umbralCierre = max(0.0, (float)$sum['total_efectivo']);
      if ((float)$sum['suma_val'] >= $umbralCierre) {
        $obsAppend = 'Cierre automático por validación de depósitos (total_efectivo ya neto).';
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
    }

    $msg = "<div class='alert alert-success'>✅ Depósito validado correctamente.</div>";
  } else {
    $msg = "<div class='alert alert-warning'>El depósito no existe o ya fue procesado.</div>";
  }
}

// ---------------------------------------------
// 2) Datos comunes / filtros
// ---------------------------------------------
$tab = $_GET['tab'] ?? 'pendientes';
$tabsValid = ['pendientes','historial','cortes','saldos','gastos'];
if (!in_array($tab, $tabsValid, true)) $tab = 'pendientes';

$sucursales = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);

// ---------------------------------------------
// 3) Pendientes (lista por depósito)
// total_efectivo YA ES NETO; gastos_corte sólo informativo
// ---------------------------------------------
$sqlPendientes = "
  SELECT
    ds.id AS id_deposito,
    s.nombre AS sucursal,
    ds.id_corte,
    cc.fecha_corte,
    cc.total_efectivo,
    COALESCE(ge.gastos_efectivo,0) AS gastos_corte,
    cc.total_efectivo AS efectivo_neto_corte,
    ds.monto_depositado,
    ds.banco,
    ds.referencia,
    ds.estado,
    ds.comprobante_archivo,
    (SELECT COALESCE(SUM(x.monto_validado),0)
       FROM depositos_sucursal x
      WHERE x.id_corte = ds.id_corte AND x.estado='Validado') AS suma_validada_previa
  FROM depositos_sucursal ds
  INNER JOIN cortes_caja cc ON cc.id = ds.id_corte
  INNER JOIN sucursales s   ON s.id = ds.id_sucursal
  LEFT JOIN (
    SELECT id_corte, SUM(monto) AS gastos_efectivo
    FROM gastos_sucursal
    GROUP BY id_corte
  ) ge ON ge.id_corte = cc.id
  WHERE ds.estado = 'Pendiente'
  ORDER BY cc.fecha_corte ASC, ds.id_corte ASC, ds.id ASC
";
$pendientes = $conn->query($sqlPendientes)->fetch_all(MYSQLI_ASSOC);

// ---------------------------------------------
// 4) Historial (filtros propios)
// ---------------------------------------------
$sucursal_id = (int)($_GET['sucursal_id'] ?? 0);
$desde       = trim($_GET['desde'] ?? '');
$hasta       = trim($_GET['hasta'] ?? '');
$semana      = trim($_GET['semana'] ?? '');

if ($semana && preg_match('/^(\d{4})-W(\d{2})$/', $semana, $m)) {
  $yr = (int)$m[1]; $wk = (int)$m[2];
  $dt = new DateTime(); $dt->setISODate($yr, $wk);
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
$typesH = ''; $paramsH = [];
if ($sucursal_id > 0) { $sqlHistorial .= " AND s.id=? ";                 $typesH.='i'; $paramsH[]=$sucursal_id; }
if ($desde !== '')    { $sqlHistorial .= " AND DATE(ds.fecha_deposito)>=? "; $typesH.='s'; $paramsH[]=$desde; }
if ($hasta !== '')    { $sqlHistorial .= " AND DATE(ds.fecha_deposito)<=? "; $typesH.='s'; $paramsH[]=$hasta; }
$sqlHistorial .= " ORDER BY ds.fecha_deposito DESC, ds.id DESC";
$stH = $conn->prepare($sqlHistorial);
if ($typesH) { $stH->bind_param($typesH, ...$paramsH); }
$stH->execute();
$historial = $stH->get_result()->fetch_all(MYSQLI_ASSOC);
$stH->close();

// ---------------------------------------------
// 5) Saldos por sucursal (neteando gastos a nivel global)
// (Se mantiene como estaba; es reporte agregado)
// ---------------------------------------------
$sqlSaldos = "
  SELECT 
    s.id,
    s.nombre AS sucursal,
    COALESCE(SUM(c.monto_efectivo),0) AS total_efectivo,
    COALESCE((SELECT SUM(gs.monto) FROM gastos_sucursal gs WHERE gs.id_sucursal = s.id),0) AS total_gastos,
    COALESCE((SELECT SUM(d.ajuste) FROM depositos_sucursal d WHERE d.id_sucursal = s.id AND d.estado='Validado'),0) AS total_ajustado,
    COALESCE((SELECT SUM(d.monto_validado) FROM depositos_sucursal d WHERE d.id_sucursal = s.id AND d.estado='Validado'),0) AS total_depositado_validado,
    GREATEST(
      COALESCE(SUM(c.monto_efectivo),0)
      - COALESCE((SELECT SUM(gs2.monto) FROM gastos_sucursal gs2 WHERE gs2.id_sucursal = s.id),0)
      - COALESCE((SELECT SUM(d2.monto_validado) FROM depositos_sucursal d2 WHERE d2.id_sucursal = s.id AND d2.estado='Validado'),0),
      0
    ) AS saldo_pendiente
  FROM sucursales s
  LEFT JOIN cobros c 
    ON c.id_sucursal = s.id AND c.corte_generado = 1
  WHERE s.subtipo <> 'Master Admin'
  GROUP BY s.id
  ORDER BY saldo_pendiente DESC
";
$saldos = $conn->query($sqlSaldos)->fetch_all(MYSQLI_ASSOC);

// ---------------------------------------------
// 6) Cortes
// total_efectivo YA ES NETO; mostramos gastos sólo informativos
// ---------------------------------------------
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
    COALESCE(ge.gastos_efectivo,0) AS gastos_corte,
    cc.total_efectivo AS efectivo_neto,
    cc.depositado,
    cc.monto_depositado,
    (SELECT COUNT(*) FROM cobros cb WHERE cb.id_corte = cc.id) AS num_cobros,
    COALESCE((SELECT SUM(ds.ajuste) FROM depositos_sucursal ds WHERE ds.id_corte = cc.id AND ds.estado='Validado'),0) AS total_ajuste_corte,
    COALESCE((SELECT SUM(ds.monto_validado) FROM depositos_sucursal ds WHERE ds.id_corte = cc.id AND ds.estado='Validado'),0) AS total_validado_corte
  FROM cortes_caja cc
  INNER JOIN sucursales s ON s.id = cc.id_sucursal
  LEFT JOIN (
    SELECT id_corte, SUM(monto) AS gastos_efectivo
    FROM gastos_sucursal
    GROUP BY id_corte
  ) ge ON ge.id_corte = cc.id
  WHERE 1=1
";
$typesC = ''; $paramsC = [];
if ($c_sucursal_id > 0) { $sqlCortes .= " AND cc.id_sucursal=? ";   $typesC.='i'; $paramsC[]=$c_sucursal_id; }
if ($c_desde !== '')     { $sqlCortes .= " AND cc.fecha_operacion>=? "; $typesC.='s'; $paramsC[]=$c_desde; }
if ($c_hasta !== '')     { $sqlCortes .= " AND cc.fecha_operacion<=? "; $typesC.='s'; $paramsC[]=$c_hasta; }
$sqlCortes .= " ORDER BY cc.fecha_operacion DESC, cc.id DESC";
$stC = $conn->prepare($sqlCortes);
if ($typesC) { $stC->bind_param($typesC, ...$paramsC); }
$stC->execute();
$cortes = $stC->get_result()->fetch_all(MYSQLI_ASSOC);
$stC->close();

// ---------------------------------------------
// 7) Gastos (tab) + filtros y preview
// ---------------------------------------------
$g_sucursal_id = (int)($_GET['g_sucursal_id'] ?? 0);
$g_desde       = trim($_GET['g_desde'] ?? '');
$g_hasta       = trim($_GET['g_hasta'] ?? '');
$g_semana      = trim($_GET['g_semana'] ?? '');
$g_id_corte    = (int)($_GET['g_id_corte'] ?? 0);

if ($g_semana && preg_match('/^(\d{4})-W(\d{2})$/', $g_semana, $m)) {
  $yr = (int)$m[1]; $wk = (int)$m[2];
  $dt = new DateTime(); $dt->setISODate($yr, $wk);
  $g_desde = $dt->format('Y-m-d');
  $dt->modify('+6 days');
  $g_hasta = $dt->format('Y-m-d');
}

$sqlGastos = "
  SELECT 
    gs.id, gs.id_sucursal, s.nombre AS sucursal, gs.id_usuario,
    gs.fecha_gasto, gs.categoria, gs.concepto, gs.monto, gs.observaciones, gs.id_corte
  FROM gastos_sucursal gs
  LEFT JOIN sucursales s ON s.id = gs.id_sucursal
  WHERE 1=1
";
$typesG=''; $paramsG=[];
if ($g_sucursal_id > 0) { $sqlGastos .= " AND gs.id_sucursal=? ";       $typesG.='i'; $paramsG[]=$g_sucursal_id; }
if ($g_id_corte > 0)    { $sqlGastos .= " AND gs.id_corte=? ";          $typesG.='i'; $paramsG[]=$g_id_corte; }
if ($g_desde !== '')    { $sqlGastos .= " AND DATE(gs.fecha_gasto)>=? ";$typesG.='s'; $paramsG[]=$g_desde; }
if ($g_hasta !== '')    { $sqlGastos .= " AND DATE(gs.fecha_gasto)<=? ";$typesG.='s'; $paramsG[]=$g_hasta; }
$sqlGastos .= " ORDER BY gs.fecha_gasto DESC, gs.id DESC LIMIT 200";
$stG = $conn->prepare($sqlGastos);
if ($typesG) { $stG->bind_param($typesG, ...$paramsG); }
$stG->execute();
$gastos = $stG->get_result()->fetch_all(MYSQLI_ASSOC);
$stG->close();

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Validación de Depósitos</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <style>
    .nav-tabs .nav-link { font-weight:600; }
    .modal-xl { max-width: 1000px; }
    #visorFrame { width: 100%; height: 80vh; border: 0; }
    .table thead th { white-space: nowrap; }
  </style>
</head>
<body class="bg-light">

<div class="container mt-4">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h2 class="mb-0">🏦 Depósitos - Admin</h2>
    <span class="text-muted"><?= e($_SESSION['nombre'] ?? '') ?> (Admin)</span>
  </div>
  <?= $msg ?>

  <!-- Toolbar superior: Exportar transacciones del día -->
  <div class="d-flex flex-wrap justify-content-end align-items-center gap-2 mb-3">
    <form class="d-flex align-items-center gap-2" method="get" action="export_transacciones_dia.php" target="_blank">
      <label class="form-label mb-0 small">📤 Transacciones del día</label>
      <input type="date" name="dia" class="form-control form-control-sm" value="<?= e($hoy) ?>" required>
      <button class="btn btn-outline-success btn-sm">Exportar CSV</button>
    </form>
  </div>

  <!-- TABS -->
  <ul class="nav nav-tabs" id="tabsDep" role="tablist">
    <?php
      $tablbl = [
        'pendientes'=>'Pendientes',
        'historial'=>'Historial',
        'cortes'=>'Cortes',
        'saldos'=>'Saldos',
        'gastos'=>'Gastos',
      ];
      foreach ($tablbl as $k=>$lbl):
    ?>
      <li class="nav-item" role="presentation">
        <a class="nav-link <?= $tab===$k?'active':'' ?>" 
           href="?tab=<?= $k ?>" role="tab"><?= $lbl ?></a>
      </li>
    <?php endforeach; ?>
  </ul>

  <div class="tab-content pt-3">

    <!-- TAB: PENDIENTES -->
    <?php if ($tab==='pendientes'): ?>
      <div class="card shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
          <h4 class="mb-0">Depósitos pendientes de validación</h4>
          <span class="badge bg-secondary">Total: <?= count($pendientes) ?></span>
        </div>
        <div class="card-body p-0">
          <?php if (count($pendientes) === 0): ?>
            <div class="p-3"><div class="alert alert-info mb-0">No hay depósitos pendientes.</div></div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-bordered table-sm align-middle mb-0">
                <thead class="table-dark">
                  <tr>
                    <th>ID Dep.</th>
                    <th>Sucursal</th>
                    <th>ID Corte</th>
                    <th>Fecha Corte</th>
                    <th>Efec. (neto)</th>
                    <th>Gastos (info)</th>
                    <th>Neto (corte)</th>
                    <th>Depósito</th>
                    <th>Banco</th>
                    <th>Referencia</th>
                    <th>Comprobante</th>
                    <th>Acciones</th>
                  </tr>
                </thead>
                <tbody>
                  <?php $lastCorte = null;
                  foreach ($pendientes as $p):
                    $efecNeto   = max(0, (float)$p['efectivo_neto_corte']); // ya neto
                    $validPrev  = (float)$p['suma_validada_previa'];
                    $faltante   = max(0, $efecNeto - ($validPrev + (float)$p['monto_depositado']));
                    if ($lastCorte !== $p['id_corte']): ?>
                      <tr class="table-secondary">
                        <td colspan="12">
                          Corte #<?= (int)$p['id_corte'] ?> - <?= e($p['sucursal']) ?> 
                          (Fecha: <?= e($p['fecha_corte']) ?> | Efectivo neto: $<?= number_format($p['total_efectivo'],2) ?> | Gastos del día: <span class="text-danger">$<?= number_format($p['gastos_corte'],2) ?></span>)
                        </td>
                      </tr>
                    <?php endif; ?>
                    <tr>
                      <td><?= (int)$p['id_deposito'] ?></td>
                      <td><?= e($p['sucursal']) ?></td>
                      <td><?= (int)$p['id_corte'] ?></td>
                      <td><?= e($p['fecha_corte']) ?></td>
                      <td class="fw-bold">$<?= number_format($p['total_efectivo'],2) ?></td>
                      <td class="text-danger">$<?= number_format($p['gastos_corte'],2) ?></td>
                      <td class="fw-bold">$<?= number_format($efecNeto,2) ?></td>
                      <td>$<?= number_format($p['monto_depositado'],2) ?></td>
                      <td><?= e($p['banco']) ?></td>
                      <td><?= e($p['referencia']) ?></td>
                      <td>
                        <?php if (!empty($p['comprobante_archivo'])): ?>
                          <button class="btn btn-primary btn-sm js-ver"
                                  data-src="deposito_comprobante.php?id=<?= (int)$p['id_deposito'] ?>"
                                  data-bs-toggle="modal" data-bs-target="#visorModal">Ver</button>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                      </td>
                      <td class="d-flex gap-1">
                        <form method="POST" class="d-inline">
                          <input type="hidden" name="id_deposito" value="<?= (int)$p['id_deposito'] ?>">
                          <button name="accion" value="Validar" class="btn btn-success btn-sm">✅ Validar</button>
                        </form>
                        <button type="button" class="btn btn-warning btn-sm js-ajustar"
                            data-bs-toggle="modal" data-bs-target="#ajusteModal"
                            data-id="<?= (int)$p['id_deposito'] ?>"
                            data-totalnet="<?= (float)$p['efectivo_neto_corte'] ?>"
                            data-previo="<?= (float)$p['suma_validada_previa'] ?>"
                            data-depo="<?= (float)$p['monto_depositado'] ?>"
                            data-sucursal="<?= e($p['sucursal']) ?>"
                            data-corte="<?= (int)$p['id_corte'] ?>">
                          🧮 Ajustar y Validar
                        </button>
                      </td>
                    </tr>
                    <?php $lastCorte = $p['id_corte'];
                  endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- TAB: HISTORIAL -->
    <?php if ($tab==='historial'): ?>
      <div class="card shadow-sm">
        <div class="card-header bg-white">
          <h4 class="mb-0">Historial de depósitos</h4>
        </div>
        <div class="card-body">
          <form class="row g-2 mb-3" method="get">
            <input type="hidden" name="tab" value="historial">
            <div class="col-md-3">
              <label class="form-label mb-0">Sucursal</label>
              <select name="sucursal_id" class="form-select form-select-sm">
                <option value="0">Todas</option>
                <?php foreach ($sucursales as $s): ?>
                  <option value="<?= (int)$s['id'] ?>" <?= $sucursal_id===(int)$s['id']?'selected':'' ?>><?= e($s['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label mb-0">Desde</label>
              <input type="date" name="desde" class="form-control form-control-sm" value="<?= e($desde) ?>" <?= $semana ? 'disabled' : '' ?>>
            </div>
            <div class="col-md-3">
              <label class="form-label mb-0">Hasta</label>
              <input type="date" name="hasta" class="form-control form-control-sm" value="<?= e($hasta) ?>" <?= $semana ? 'disabled' : '' ?>>
            </div>
            <div class="col-md-3">
              <label class="form-label mb-0">Semana (ISO)</label>
              <input type="week" name="semana" class="form-control form-control-sm" value="<?= e($semana) ?>">
            </div>
            <div class="col-12 d-flex gap-2">
              <button class="btn btn-primary btn-sm">Aplicar filtros</button>
              <a class="btn btn-outline-secondary btn-sm" href="depositos.php?tab=historial">Limpiar</a>
            </div>
          </form>

          <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle mb-0">
              <thead class="table-dark">
                <tr>
                  <th>ID</th><th>Sucursal</th><th>ID Corte</th><th>Fecha Corte</th>
                  <th>Fecha Depósito</th><th>Monto</th><th>Ajuste</th><th>Validado</th>
                  <th>Motivo</th><th>Banco</th><th>Referencia</th><th>Comprobante</th><th>Estado</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$historial): ?>
                  <tr><td colspan="13" class="text-muted">Sin resultados con los filtros actuales.</td></tr>
                <?php endif; foreach ($historial as $h): ?>
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
                        <button class="btn btn-outline-primary btn-sm js-ver"
                                data-src="deposito_comprobante.php?id=<?= (int)$h['id_deposito'] ?>"
                                data-bs-toggle="modal" data-bs-target="#visorModal">Ver</button>
                      <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td><?= e($h['estado']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

        </div>
      </div>
    <?php endif; ?>

    <!-- TAB: CORTES -->
    <?php if ($tab==='cortes'): ?>
      <div class="card shadow-sm">
        <div class="card-header bg-white">
          <h4 class="mb-0">Cortes de caja</h4>
        </div>
        <div class="card-body">
          <form class="row g-2 mb-3" method="get">
            <input type="hidden" name="tab" value="cortes">
            <div class="col-md-3">
              <label class="form-label mb-0">Sucursal</label>
              <select name="c_sucursal_id" class="form-select form-select-sm">
                <option value="0">Todas</option>
                <?php foreach ($sucursales as $s): ?>
                  <option value="<?= (int)$s['id'] ?>" <?= $c_sucursal_id===(int)$s['id']?'selected':'' ?>><?= e($s['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label mb-0">Desde</label>
              <input type="date" name="c_desde" class="form-control form-control-sm" value="<?= e($c_desde) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label mb-0">Hasta</label>
              <input type="date" name="c_hasta" class="form-control form-control-sm" value="<?= e($c_hasta) ?>">
            </div>
            <div class="col-12 d-flex gap-2">
              <button class="btn btn-primary btn-sm">Filtrar cortes</button>
              <a class="btn btn-outline-secondary btn-sm" href="depositos.php?tab=cortes">Limpiar</a>
            </div>
          </form>

          <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle mb-0">
              <thead class="table-dark">
                <tr>
                  <th>ID Corte</th><th>Sucursal</th><th>Fecha Operación</th><th>Fecha Corte</th>
                  <th>Efec. Neto</th><th>Gastos (info)</th><th>Tarj.</th><th>Com. Esp.</th><th>Total</th>
                  <th>Ajuste Dep.</th><th>Validado (dep+ajuste)</th><th>Depositado</th><th>Estado</th><th>Detalle</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$cortes): ?>
                  <tr><td colspan="15" class="text-muted">Sin cortes con los filtros seleccionados.</td></tr>
                <?php else: foreach ($cortes as $c): ?>
                  <tr>
                    <td><?= (int)$c['id'] ?></td>
                    <td><?= e($c['sucursal']) ?></td>
                    <td><?= e($c['fecha_operacion']) ?></td>
                    <td><?= e($c['fecha_corte']) ?></td>
                    <td class="fw-bold">$<?= number_format((float)$c['total_efectivo'],2) ?></td>
                    <td class="text-danger">$<?= number_format((float)$c['gastos_corte'],2) ?></td>
                    <td>$<?= number_format((float)$c['total_tarjeta'],2) ?></td>
                    <td>$<?= number_format((float)$c['total_comision_especial'],2) ?></td>
                    <td><b>$<?= number_format((float)$c['total_general'],2) ?></b></td>
                    <td>$<?= number_format((float)$c['total_ajuste_corte'],2) ?></td>
                    <td><b>$<?= number_format((float)$c['total_validado_corte'],2) ?></b></td>
                    <td><?= $c['depositado'] ? ('$'.number_format((float)$c['monto_depositado'],2)) : '<span class="text-muted">No</span>' ?></td>
                    <td><span class="badge <?= $c['estado']==='Cerrado'?'bg-success':'bg-warning text-dark' ?>"><?= e($c['estado']) ?></span></td>
                    <td>
                      <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#det<?= (int)$c['id'] ?>">
                        Ver cobros (<?= (int)$c['num_cobros'] ?>)
                      </button>
                    </td>
                  </tr>
                  <tr class="collapse" id="det<?= (int)$c['id'] ?>">
                    <td colspan="15">
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
                              <th>ID Cobro</th><th>Fecha/Hora</th><th>Ejecutivo</th><th>Motivo</th>
                              <th>Tipo pago</th><th>Total</th><th>Efectivo</th><th>Tarjeta</th><th>Com. Especial</th>
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

        </div>
      </div>
    <?php endif; ?>

    <!-- TAB: SALDOS -->
    <?php if ($tab==='saldos'): ?>
      <div class="card shadow-sm">
        <div class="card-header bg-white">
          <h4 class="mb-0">📊 Saldos por Sucursal</h4>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle mb-0">
              <thead class="table-dark">
                <tr>
                  <th>Sucursal</th>
                  <th>Total Efectivo Cobrado</th>
                  <th>Gastos (acum.)</th>
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
                    <td class="text-danger">$<?= number_format((float)$s['total_gastos'],2) ?></td>
                    <td>$<?= number_format((float)$s['total_ajustado'],2) ?></td>
                    <td>$<?= number_format((float)$s['total_depositado_validado'],2) ?></td>
                    <td><b>$<?= number_format((float)$s['saldo_pendiente'],2) ?></b></td>
                  </tr>
                <?php endforeach; if(!$saldos): ?>
                  <tr><td colspan="6" class="text-muted">Sin datos de saldos.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- TAB: GASTOS -->
    <?php if ($tab==='gastos'): ?>
      <div class="card shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
          <h4 class="mb-0">💸 Gastos de sucursal</h4>
          <a class="btn btn-outline-success btn-sm"
             href="export_gastos.php?sucursal_id=<?= (int)$g_sucursal_id ?>&desde=<?= e($g_desde) ?>&hasta=<?= e($g_hasta) ?>&semana=<?= e($g_semana) ?>&id_corte=<?= (int)$g_id_corte ?>"
             target="_blank">📤 Exportar CSV</a>
        </div>
        <div class="card-body">
          <form class="row g-2 mb-3" method="get">
            <input type="hidden" name="tab" value="gastos">
            <div class="col-md-3">
              <label class="form-label mb-0">Sucursal</label>
              <select name="g_sucursal_id" class="form-select form-select-sm">
                <option value="0">Todas</option>
                <?php foreach ($sucursales as $s): ?>
                  <option value="<?= (int)$s['id'] ?>" <?= $g_sucursal_id===(int)$s['id']?'selected':'' ?>><?= e($s['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label mb-0">Desde</label>
              <input type="date" name="g_desde" class="form-control form-control-sm" value="<?= e($g_desde) ?>" <?= $g_semana ? 'disabled' : '' ?>>
            </div>
            <div class="col-md-3">
              <label class="form-label mb-0">Hasta</label>
              <input type="date" name="g_hasta" class="form-control form-control-sm" value="<?= e($g_hasta) ?>" <?= $g_semana ? 'disabled' : '' ?>>
            </div>
            <div class="col-md-3">
              <label class="form-label mb-0">Semana (ISO)</label>
              <input type="week" name="g_semana" class="form-control form-control-sm" value="<?= e($g_semana) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label mb-0">ID Corte (opcional)</label>
              <input type="number" name="g_id_corte" class="form-control form-control-sm" value="<?= $g_id_corte ?: '' ?>" placeholder="Ej. 123">
            </div>
            <div class="col-12 d-flex gap-2">
              <button class="btn btn-primary btn-sm">Aplicar filtros</button>
              <a class="btn btn-outline-secondary btn-sm" href="depositos.php?tab=gastos">Limpiar</a>
            </div>
          </form>

          <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle mb-0">
              <thead class="table-dark">
                <tr>
                  <th>ID</th><th>Sucursal</th><th>Fecha</th><th>Categoría</th><th>Concepto</th><th>Monto</th><th>ID Corte</th><th>Observaciones</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$gastos): ?>
                  <tr><td colspan="8" class="text-muted">No hay gastos con los filtros actuales.</td></tr>
                <?php else: foreach ($gastos as $g): ?>
                  <tr>
                    <td><?= (int)$g['id'] ?></td>
                    <td><?= e($g['sucursal'] ?? '') ?></td>
                    <td><?= e($g['fecha_gasto']) ?></td>
                    <td><?= e($g['categoria']) ?></td>
                    <td><?= e($g['concepto']) ?></td>
                    <td class="text-danger">$<?= number_format((float)$g['monto'],2) ?></td>
                    <td><?= (int)$g['id_corte'] ?></td>
                    <td><?= e($g['observaciones']) ?></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
            <div class="form-text mt-1">Se muestran hasta 200 gastos (usa el CSV para el detalle completo).</div>
          </div>

        </div>
      </div>
    <?php endif; ?>

  </div><!-- tab-content -->
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
            <label class="form-label mb-0">Efectivo neto del corte</label>
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
  // Visor modal
  const visorModal  = document.getElementById('visorModal');
  const visorFrame  = document.getElementById('visorFrame');
  const btnAbrir    = document.getElementById('btnAbrirNueva');
  document.querySelectorAll('.js-ver').forEach(btn => {
    btn.addEventListener('click', () => {
      const src = btn.getAttribute('data-src');
      visorFrame.src = src; btnAbrir.href = src;
    });
  });
  if (visorModal) visorModal.addEventListener('hidden.bs.modal', () => { visorFrame.src=''; btnAbrir.href='#'; });

  // Modal de Ajuste (efectivo neto)
  const fmt = v => Number(v).toFixed(2);
  document.querySelectorAll('.js-ajustar').forEach(btn => {
    btn.addEventListener('click', () => {
      const id      = btn.dataset.id;
      const total   = parseFloat(btn.dataset.totalnet || '0'); // EFECTIVO NETO (cc.total_efectivo)
      const previo  = parseFloat(btn.dataset.previo   || '0');
      const depo    = parseFloat(btn.dataset.depo     || '0');
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
      document.getElementById('aj_ajuste').value    = fmt(faltante);
      document.getElementById('aj_max').textContent = fmt(faltante);
    });
  });

  // Desactivar fechas cuando se usa semana en Historial y Gastos
  const toggleByWeek = (weekSel, fromSel, toSel) => {
    const w = document.querySelector(weekSel);
    const f = document.querySelector(fromSel);
    const t = document.querySelector(toSel);
    if (w && f && t) {
      const fn = () => {
        const using = (w.value || '').trim() !== '';
        f.disabled = using; t.disabled = using;
        if (using) { f.value=''; t.value=''; }
      };
      w.addEventListener('input', fn); fn();
    }
  };
  toggleByWeek('input[name="semana"]','input[name="desde"]','input[name="hasta"]');
  toggleByWeek('input[name="g_semana"]','input[name="g_desde"]','input[name="g_hasta"]');
</script>
</body>
</html>
