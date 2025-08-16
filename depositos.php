<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: 403.php");
    exit();
}

include 'db.php';
include 'navbar.php';

$msg = '';

// 1) Validar un depósito
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_deposito'], $_POST['accion'])) {
    $idDeposito = intval($_POST['id_deposito']);
    $accion = $_POST['accion'];

    if ($accion === 'Validar') {
        $stmt = $conn->prepare("
            UPDATE depositos_sucursal
            SET estado='Validado', id_admin_valida=?, actualizado_en=NOW()
            WHERE id=? AND estado='Pendiente'
        ");
        $stmt->bind_param("ii", $_SESSION['id_usuario'], $idDeposito);
        $stmt->execute();

        // Cierra corte si ya se cubrió
        $sqlCorte = "
            SELECT ds.id_corte, cc.total_efectivo,
                   IFNULL(SUM(ds2.monto_depositado),0) AS suma_depositos
            FROM depositos_sucursal ds
            INNER JOIN cortes_caja cc ON cc.id = ds.id_corte
            INNER JOIN depositos_sucursal ds2 ON ds2.id_corte = ds.id_corte AND ds2.estado='Validado'
            WHERE ds.id = ?
            GROUP BY ds.id_corte
        ";
        $stmtCorte = $conn->prepare($sqlCorte);
        $stmtCorte->bind_param("i", $idDeposito);
        $stmtCorte->execute();
        $corteData = $stmtCorte->get_result()->fetch_assoc();

        if ($corteData && $corteData['suma_depositos'] >= $corteData['total_efectivo']) {
            $stmtClose = $conn->prepare("
                UPDATE cortes_caja
                SET estado='Cerrado', depositado=1, monto_depositado=?, fecha_deposito=NOW()
                WHERE id=?
            ");
            $stmtClose->bind_param("di", $corteData['suma_depositos'], $corteData['id_corte']);
            $stmtClose->execute();
        }

        $msg = "<div class='alert alert-success'>✅ Depósito validado correctamente.</div>";
    }
}

// 2) Depósitos pendientes (agrupados por corte)
$sqlPendientes = "
    SELECT ds.id AS id_deposito,
           s.nombre AS sucursal,
           ds.id_corte,
           cc.fecha_corte,
           cc.total_efectivo,
           ds.monto_depositado,
           ds.banco,
           ds.referencia,
           ds.estado,
           ds.comprobante_archivo
    FROM depositos_sucursal ds
    INNER JOIN cortes_caja cc ON cc.id = ds.id_corte
    INNER JOIN sucursales s ON s.id = ds.id_sucursal
    WHERE ds.estado = 'Pendiente'
    ORDER BY cc.fecha_corte ASC, ds.id_corte ASC, ds.id ASC
";
$pendientes = $conn->query($sqlPendientes)->fetch_all(MYSQLI_ASSOC);

// 3) Filtros para Historial
$sucursales = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);

$sucursal_id = isset($_GET['sucursal_id']) ? (int)$_GET['sucursal_id'] : 0;
$desde       = trim($_GET['desde'] ?? '');
$hasta       = trim($_GET['hasta'] ?? '');
$semana      = trim($_GET['semana'] ?? ''); // formato YYYY-Www (input type=week)

// Si viene semana, calculamos lunes-domingo y anulamos desde/hasta
if ($semana && preg_match('/^(\d{4})-W(\d{2})$/', $semana, $m)) {
    $yr = (int)$m[1]; $wk = (int)$m[2];
    $dt = new DateTime();
    $dt->setISODate($yr, $wk); // lunes
    $desde = $dt->format('Y-m-d');
    $dt->modify('+6 days');    // domingo
    $hasta = $dt->format('Y-m-d');
}

// 3b) Construir consulta del historial con filtros
$sqlHistorial = "
    SELECT ds.id AS id_deposito,
           s.nombre AS sucursal,
           ds.id_corte,
           cc.fecha_corte,
           ds.fecha_deposito,
           ds.monto_depositado,
           ds.banco,
           ds.referencia,
           ds.estado,
           ds.comprobante_archivo
    FROM depositos_sucursal ds
    INNER JOIN cortes_caja cc ON cc.id = ds.id_corte
    INNER JOIN sucursales s ON s.id = ds.id_sucursal
    WHERE 1=1
";
$types = '';
$params = [];

if ($sucursal_id > 0) {
    $sqlHistorial .= " AND s.id = ? ";
    $types .= 'i'; $params[] = $sucursal_id;
}
if ($desde !== '') {
    $sqlHistorial .= " AND DATE(ds.fecha_deposito) >= ? ";
    $types .= 's'; $params[] = $desde;
}
if ($hasta !== '') {
    $sqlHistorial .= " AND DATE(ds.fecha_deposito) <= ? ";
    $types .= 's'; $params[] = $hasta;
}
$sqlHistorial .= " ORDER BY ds.fecha_deposito DESC, ds.id DESC";

$stmtH = $conn->prepare($sqlHistorial);
if ($types) { $stmtH->bind_param($types, ...$params); }
$stmtH->execute();
$historial = $stmtH->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtH->close();

// 4) Saldos por sucursal
$sqlSaldos = "
    SELECT 
        s.id,
        s.nombre AS sucursal,
        IFNULL(SUM(c.monto_efectivo),0) AS total_efectivo,
        IFNULL((SELECT SUM(d.monto_depositado) FROM depositos_sucursal d WHERE d.id_sucursal = s.id AND d.estado='Validado'),0) AS total_depositado,
        GREATEST(
            IFNULL(SUM(c.monto_efectivo),0) - IFNULL((SELECT SUM(d.monto_depositado) FROM depositos_sucursal d WHERE d.id_sucursal = s.id AND d.estado='Validado'),0),
        0) AS saldo_pendiente
    FROM sucursales s
    LEFT JOIN cobros c 
        ON c.id_sucursal = s.id 
       AND c.corte_generado = 1
    GROUP BY s.id
    ORDER BY saldo_pendiente DESC
";
$saldos = $conn->query($sqlSaldos)->fetch_all(MYSQLI_ASSOC);

/* ==========================
   5) CORTES DE CAJA (con filtros por sucursal y fecha)
   ========================== */
$c_sucursal_id = isset($_GET['c_sucursal_id']) ? (int)$_GET['c_sucursal_id'] : 0;
$c_desde       = trim($_GET['c_desde'] ?? '');
$c_hasta       = trim($_GET['c_hasta'] ?? '');

$sqlCortes = "
  SELECT cc.id,
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
         (SELECT COUNT(*) FROM cobros cb WHERE cb.id_corte = cc.id) AS num_cobros
  FROM cortes_caja cc
  INNER JOIN sucursales s ON s.id = cc.id_sucursal
  WHERE 1=1
";
$typesC=''; $paramsC=[];
if ($c_sucursal_id > 0) { $sqlCortes .= " AND cc.id_sucursal = ? "; $typesC.='i'; $paramsC[]=$c_sucursal_id; }
if ($c_desde !== '')     { $sqlCortes .= " AND cc.fecha_operacion >= ? "; $typesC.='s'; $paramsC[]=$c_desde; }
if ($c_hasta !== '')     { $sqlCortes .= " AND cc.fecha_operacion <= ? "; $typesC.='s'; $paramsC[]=$c_hasta; }
$sqlCortes .= " ORDER BY cc.fecha_operacion DESC, cc.id DESC";

$stmtC = $conn->prepare($sqlCortes);
if ($typesC) { $stmtC->bind_param($typesC, ...$paramsC); }
$stmtC->execute();
$cortes = $stmtC->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtC->close();
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
              Corte #<?= (int)$p['id_corte'] ?> - <?= htmlspecialchars($p['sucursal']) ?>
              (Fecha: <?= htmlspecialchars($p['fecha_corte']) ?> | Total Efectivo: $<?= number_format($p['total_efectivo'],2) ?>)
            </td>
          </tr>
        <?php endif; ?>
        <tr>
          <td><?= (int)$p['id_deposito'] ?></td>
          <td><?= htmlspecialchars($p['sucursal']) ?></td>
          <td><?= (int)$p['id_corte'] ?></td>
          <td><?= htmlspecialchars($p['fecha_corte']) ?></td>
          <td>$<?= number_format($p['monto_depositado'],2) ?></td>
          <td><?= htmlspecialchars($p['banco']) ?></td>
          <td><?= htmlspecialchars($p['referencia']) ?></td>
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
          <td>
            <form method="POST" class="d-inline">
              <input type="hidden" name="id_deposito" value="<?= (int)$p['id_deposito'] ?>">
              <button name="accion" value="Validar" class="btn btn-success btn-sm">✅ Validar</button>
            </form>
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
            <?= htmlspecialchars($s['nombre']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4 col-lg-3">
      <label class="form-label mb-0">Desde</label>
      <input type="date" name="desde" class="form-control form-control-sm" value="<?= htmlspecialchars($desde) ?>" <?= $semana ? 'disabled' : '' ?>>
    </div>
    <div class="col-md-4 col-lg-3">
      <label class="form-label mb-0">Hasta</label>
      <input type="date" name="hasta" class="form-control form-control-sm" value="<?= htmlspecialchars($hasta) ?>" <?= $semana ? 'disabled' : '' ?>>
    </div>
    <div class="col-md-4 col-lg-3">
      <label class="form-label mb-0">Semana (ISO)</label>
      <input type="week" name="semana" class="form-control form-control-sm" value="<?= htmlspecialchars($semana) ?>">
      <small class="text-muted">Si eliges semana, ignora las fechas.</small>
    </div>
    <div class="col-12 d-flex gap-2">
      <button class="btn btn-primary btn-sm">Aplicar filtros</button>
      <a class="btn btn-outline-secondary btn-sm" href="depositos.php">Limpiar</a>
    </div>
  </form>

  <table class="table table-bordered table-sm align-middle">
    <thead class="table-dark">
      <tr>
        <th>ID</th>
        <th>Sucursal</th>
        <th>ID Corte</th>
        <th>Fecha Corte</th>
        <th>Fecha Depósito</th>
        <th>Monto</th>
        <th>Banco</th>
        <th>Referencia</th>
        <th>Comprobante</th>
        <th>Estado</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$historial): ?>
        <tr><td colspan="10" class="text-muted">Sin resultados con los filtros actuales.</td></tr>
      <?php endif; ?>
      <?php foreach ($historial as $h): ?>
        <tr class="<?= $h['estado']=='Validado'?'table-success':'table-warning' ?>">
          <td><?= (int)$h['id_deposito'] ?></td>
          <td><?= htmlspecialchars($h['sucursal']) ?></td>
          <td><?= (int)$h['id_corte'] ?></td>
          <td><?= htmlspecialchars($h['fecha_corte']) ?></td>
          <td><?= htmlspecialchars($h['fecha_deposito']) ?></td>
          <td>$<?= number_format($h['monto_depositado'],2) ?></td>
          <td><?= htmlspecialchars($h['banco']) ?></td>
          <td><?= htmlspecialchars($h['referencia']) ?></td>
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
          <td><?= htmlspecialchars($h['estado']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

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
            <?= htmlspecialchars($s['nombre']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4 col-lg-3">
      <label class="form-label mb-0">Desde</label>
      <input type="date" name="c_desde" class="form-control form-control-sm" value="<?= htmlspecialchars($c_desde) ?>">
    </div>
    <div class="col-md-4 col-lg-3">
      <label class="form-label mb-0">Hasta</label>
      <input type="date" name="c_hasta" class="form-control form-control-sm" value="<?= htmlspecialchars($c_hasta) ?>">
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
          <th>Depositado</th>
          <th>Estado</th>
          <th>Detalle</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$cortes): ?>
          <tr><td colspan="11" class="text-muted">Sin cortes con los filtros seleccionados.</td></tr>
        <?php else: foreach ($cortes as $c): ?>
          <tr>
            <td><?= (int)$c['id'] ?></td>
            <td><?= htmlspecialchars($c['sucursal']) ?></td>
            <td><?= htmlspecialchars($c['fecha_operacion']) ?></td>
            <td><?= htmlspecialchars($c['fecha_corte']) ?></td>
            <td>$<?= number_format($c['total_efectivo'],2) ?></td>
            <td>$<?= number_format($c['total_tarjeta'],2) ?></td>
            <td>$<?= number_format($c['total_comision_especial'],2) ?></td>
            <td><b>$<?= number_format($c['total_general'],2) ?></b></td>
            <td>
              <?= $c['depositado'] ? 
                    ('$'.number_format($c['monto_depositado'],2)) : 
                    '<span class="text-muted">No</span>' ?>
            </td>
            <td>
              <span class="badge <?= $c['estado']==='Cerrado'?'bg-success':'bg-warning text-dark' ?>">
                <?= htmlspecialchars($c['estado']) ?>
              </span>
            </td>
            <td>
              <button class="btn btn-sm btn-outline-primary" type="button"
                      data-bs-toggle="collapse" data-bs-target="#det<?= $c['id'] ?>">
                Ver cobros (<?= (int)$c['num_cobros'] ?>)
              </button>
            </td>
          </tr>
          <tr class="collapse" id="det<?= $c['id'] ?>">
            <td colspan="11">
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
                      <td><?= htmlspecialchars($r['fecha_cobro']) ?></td>
                      <td><?= htmlspecialchars($r['ejecutivo'] ?? 'N/D') ?></td>
                      <td><?= htmlspecialchars($r['motivo']) ?></td>
                      <td><?= htmlspecialchars($r['tipo_pago']) ?></td>
                      <td>$<?= number_format($r['monto_total'],2) ?></td>
                      <td>$<?= number_format($r['monto_efectivo'],2) ?></td>
                      <td>$<?= number_format($r['monto_tarjeta'],2) ?></td>
                      <td>$<?= number_format($r['comision_especial'],2) ?></td>
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
        <th>Total Depositado</th>
        <th>Saldo Pendiente</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach($saldos as $s): ?>
      <tr class="<?= $s['saldo_pendiente']>0?'table-warning':'table-success' ?>">
        <td><?= htmlspecialchars($s['sucursal']) ?></td>
        <td>$<?= number_format($s['total_efectivo'],2) ?></td>
        <td>$<?= number_format($s['total_depositado'],2) ?></td>
        <td>$<?= number_format($s['saldo_pendiente'],2) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Modal visor -->
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
</script>
</body>
</html>
