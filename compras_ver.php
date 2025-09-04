<?php
// compras_ver.php (versión robusta p/ nanored)
// - Maneja ausencia de tablas/columnas (compras_cargos, compras_detalle_ingresos, subtotal/iva/total, costo vs precio unitario, etc.)
// - Activa modal de Bootstrap (incluye bundle JS)

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }
include 'db.php';

// ID de compra
$id = (int)($_POST['id_compra'] ?? ($_GET['id'] ?? 0));
if ($id <= 0) die("ID inválido.");

/* ============================
   Helpers: tablas/columnas
============================ */
function table_exists(mysqli $conn, string $table): bool {
  $t = $conn->real_escape_string($table);
  $q = $conn->query("SHOW TABLES LIKE '{$t}'");
  return $q && $q->num_rows > 0;
}
function column_exists(mysqli $conn, string $table, string $col): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($col);
  $q = $conn->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
  return $q && $q->num_rows > 0;
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ============================
   Descubrimiento de esquema
============================ */
$hasTblIngresos = table_exists($conn, 'compras_detalle_ingresos');
$hasTblCargos   = table_exists($conn, 'compras_cargos');

$hasPU   = column_exists($conn, 'compras_detalle', 'precio_unitario') || column_exists($conn, 'compras_detalle', 'costo_unitario');
$unitCol = column_exists($conn, 'compras_detalle', 'precio_unitario') ? 'precio_unitario' : (column_exists($conn, 'compras_detalle', 'costo_unitario') ? 'costo_unitario' : null);
$hasIVAp = column_exists($conn, 'compras_detalle', 'iva_porcentaje');

$hasSubCol = column_exists($conn, 'compras_detalle', 'subtotal');
$hasIvaCol = column_exists($conn, 'compras_detalle', 'iva');
$hasTotCol = column_exists($conn, 'compras_detalle', 'total');

$hasCarIvaMonto = $hasTblCargos && column_exists($conn, 'compras_cargos', 'iva_monto');
$hasCarTotal    = $hasTblCargos && column_exists($conn, 'compras_cargos', 'total');
$hasCarIVAp     = $hasTblCargos && column_exists($conn, 'compras_cargos', 'iva_porcentaje');

/* ============================
   POST: Agregar pago (modal)
============================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'agregar_pago') {
  $fecha_pago  = $_POST['fecha_pago'] ?: date('Y-m-d');
  $monto       = (float)($_POST['monto'] ?? 0);
  $metodo      = substr(trim($_POST['metodo_pago'] ?? ''), 0, 40);
  $referencia  = substr(trim($_POST['referencia'] ?? ''), 0, 120);
  $notas       = substr(trim($_POST['notas'] ?? ''), 0, 1000);

  if ($monto > 0) {
    $stmt = $conn->prepare("INSERT INTO compras_pagos (id_compra, fecha_pago, monto, metodo_pago, referencia, notas) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param("isdsss", $id, $fecha_pago, $monto, $metodo, $referencia, $notas);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
      $resTot = $conn->query("SELECT total FROM compras WHERE id=".$id);
      $rowTot = $resTot ? $resTot->fetch_assoc() : null;
      $totalCompra = $rowTot ? (float)$rowTot['total'] : 0;

      $resPag = $conn->query("SELECT COALESCE(SUM(monto),0) AS pagado FROM compras_pagos WHERE id_compra=".$id);
      $rowPag = $resPag ? $resPag->fetch_assoc() : null;
      $pagado = $rowPag ? (float)$rowPag['pagado'] : 0;

      if ($pagado >= $totalCompra && $totalCompra > 0) {
        $conn->query("UPDATE compras SET estatus='Pagada' WHERE id=".$id);
      }
    }
  }

  header("Location: compras_ver.php?id=".$id);
  exit();
}

/* ============================
   GET: Export Excel (xls)
   compras_ver.php?id=123&excel=1
============================ */
if (isset($_GET['excel'])) {
  // Encabezado
  $enc = $conn->query("
    SELECT c.*, p.nombre AS proveedor, s.nombre AS sucursal
    FROM compras c
    INNER JOIN proveedores p ON p.id=c.id_proveedor
    INNER JOIN sucursales s ON s.id=c.id_sucursal
    WHERE c.id={$id}
  ")->fetch_assoc();
  if (!$enc) die("Compra no encontrada.");

  // Detalle de modelos (ingresadas opcional)
  $ingresadasExpr = $hasTblIngresos ? "(SELECT COUNT(*) FROM compras_detalle_ingresos x WHERE x.id_detalle=d.id)" : "0";
  $detRows = [];
  $detQ = $conn->query("
    SELECT d.*, {$ingresadasExpr} AS ingresadas
    FROM compras_detalle d
    WHERE d.id_compra={$id}
    ORDER BY id ASC
  ");
  while($r=$detQ->fetch_assoc()) $detRows[]=$r;

  // Sumas detalle (fallback por columnas)
  if ($hasSubCol && $hasIvaCol && $hasTotCol) {
    $sumDet = $conn->query("
      SELECT COALESCE(SUM(subtotal),0) AS sub, COALESCE(SUM(iva),0) AS iva, COALESCE(SUM(total),0) AS tot
      FROM compras_detalle WHERE id_compra={$id}
    ")->fetch_assoc();
  } else {
    // calcular en SQL con unitario/iva%
    $exprSub = $unitCol ? "COALESCE(SUM(cantidad * {$unitCol}),0)" : "0";
    $exprIva = ($unitCol && $hasIVAp) ? "COALESCE(SUM(cantidad * {$unitCol} * (IFNULL(iva_porcentaje,0)/100)),0)" : "0";
    $sumDet = $conn->query("
      SELECT {$exprSub} AS sub, {$exprIva} AS iva
      FROM compras_detalle WHERE id_compra={$id}
    ")->fetch_assoc();
    $sumDet['tot'] = (float)$sumDet['sub'] + (float)$sumDet['iva'];
  }

  // Sumas cargos (si existe tabla)
  if ($hasTblCargos) {
    if ($hasCarIvaMonto && $hasCarTotal) {
      $sumCar = $conn->query("
        SELECT COALESCE(SUM(monto),0) AS sub, COALESCE(SUM(iva_monto),0) AS iva, COALESCE(SUM(total),0) AS tot
        FROM compras_cargos WHERE id_compra={$id}
      ")->fetch_assoc();
    } else {
      $exprSub = "COALESCE(SUM(monto),0)";
      $exprIva = $hasCarIVAp ? "COALESCE(SUM(monto * (IFNULL(iva_porcentaje,0)/100)),0)" : "0";
      $sumCar = $conn->query("SELECT {$exprSub} AS sub, {$exprIva} AS iva FROM compras_cargos WHERE id_compra={$id}")->fetch_assoc();
      $sumCar['tot'] = (float)$sumCar['sub'] + (float)$sumCar['iva'];
    }
  } else {
    $sumCar = ['sub'=>0,'iva'=>0,'tot'=>0];
  }

  // Ingresos con IMEI/series (si existe la tabla)
  $imeiRows = [];
  $candDynamic = ['imei1','imei','imei2','serial','n_serie','lote','id_producto','creado_en'];
  if ($hasTblIngresos) {
    $present = [];
    foreach ($candDynamic as $c) if (column_exists($conn,'compras_detalle_ingresos',$c)) $present[]=$c;
    $selectImeis = "";
    foreach ($present as $c) $selectImeis .= ", i.`{$c}` AS `{$c}`";
    $sqlI = "
      SELECT i.id, i.id_detalle {$selectImeis},
             d.marca, d.modelo, d.color, d.ram, d.capacidad
      FROM compras_detalle_ingresos i
      JOIN compras_detalle d ON d.id=i.id_detalle
      WHERE d.id_compra={$id}
      ORDER BY i.id ASC
    ";
    if ($present) {
      $resI = $conn->query($sqlI);
      if ($resI) while($x=$resI->fetch_assoc()) $imeiRows[]=$x;
    }
  }

  // Nombre de archivo
  $num = preg_replace('/[^A-Za-z0-9_\-]/','_', (string)($enc['num_factura'] ?? "compra_{$id}"));
  $fname = "factura_{$num}.xls";

  // Headers Excel
  header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
  header("Content-Disposition: attachment; filename=\"{$fname}\"");
  header("Cache-Control: max-age=0, no-cache, no-store, must-revalidate");

  echo "<html><head><meta charset='UTF-8'><title>".h($fname)."</title>"
      ."<style>.text{mso-number-format:'\\@';}</style></head><body>";

  // === Resumen
  echo "<h3>Factura #".h($enc['num_factura'])."</h3>";
  echo "<table border='1' cellspacing='0' cellpadding='4'>";
  echo "<tr><th align='left'>Proveedor</th><td>".h($enc['proveedor'])."</td></tr>";
  echo "<tr><th align='left'>Sucursal destino</th><td>".h($enc['sucursal'])."</td></tr>";
  echo "<tr><th align='left'>Fecha factura</th><td>".h($enc['fecha_factura'])."</td></tr>";
  echo "<tr><th align='left'>Vencimiento</th><td>".h($enc['fecha_vencimiento'] ?? '')."</td></tr>";
  echo "<tr><th align='left'>Condición</th><td>".h($enc['condicion_pago'] ?? '')."</td></tr>";
  echo "<tr><th align='left'>Días vencimiento</th><td>".(int)($enc['dias_vencimiento'] ?? 0)."</td></tr>";
  echo "<tr><th align='left'>Estatus</th><td>".h($enc['estatus'] ?? '')."</td></tr>";
  echo "<tr><th align='left'>Total factura</th><td>".number_format((float)$enc['total'],2,'.','')."</td></tr>";
  echo "</table><br>";

  // === Detalle de modelos
  $unitKey = $unitCol ?: ''; // para mostrar precio unitario
  echo "<h3>Detalle de modelos</h3>";
  echo "<table border='1' cellspacing='0' cellpadding='4'>";
  echo "<tr>
          <th>Marca</th><th>Modelo</th><th>Color</th><th>RAM</th><th>Capacidad</th>
          <th>Req. IMEI</th><th>Cantidad</th><th>Ingresadas</th>
          <th>PrecioUnit</th><th>IVA%</th><th>Subtotal</th><th>IVA</th><th>Total</th>
        </tr>";
  foreach ($detRows as $r) {
    $qty  = (int)($r['cantidad'] ?? 0);
    $pu   = $unitKey ? (float)($r[$unitKey] ?? 0) : 0;
    $ivap = $hasIVAp ? (float)($r['iva_porcentaje'] ?? 0) : (float)($r['iva_porcentaje'] ?? 0);
    $sub  = $hasSubCol ? (float)($r['subtotal'] ?? 0) : ($qty * $pu);
    $iva  = $hasIvaCol ? (float)($r['iva'] ?? 0) : ($sub * ($ivap/100));
    $tot  = $hasTotCol ? (float)($r['total'] ?? 0) : ($sub + $iva);

    echo "<tr>";
    echo "<td>".h($r['marca'] ?? '')."</td>";
    echo "<td>".h($r['modelo'] ?? '')."</td>";
    echo "<td>".h($r['color'] ?? '')."</td>";
    echo "<td>".h($r['ram'] ?? '')."</td>";
    echo "<td>".h($r['capacidad'] ?? '')."</td>";
    echo "<td>".((int)($r['requiere_imei'] ?? 0) ? 'Sí':'No')."</td>";
    echo "<td>".$qty."</td>";
    echo "<td>".(int)($r['ingresadas'] ?? 0)."</td>";
    echo "<td>".number_format($pu,2,'.','')."</td>";
    echo "<td>".number_format($ivap,2,'.','')."</td>";
    echo "<td>".number_format($sub,2,'.','')."</td>";
    echo "<td>".number_format($iva,2,'.','')."</td>";
    echo "<td>".number_format($tot,2,'.','')."</td>";
    echo "</tr>";
  }
  echo "<tr><th colspan='10' align='right'>Subtotal (modelos)</th><th>".number_format((float)$sumDet['sub'],2,'.','')."</th><th colspan='2'></th></tr>";
  echo "<tr><th colspan='11' align='right'>IVA (modelos)</th><th>".number_format((float)$sumDet['iva'],2,'.','')."</th><th></th></tr>";
  echo "<tr><th colspan='12' align='right'>Total (modelos)</th><th>".number_format((float)$sumDet['tot'],2,'.','')."</th></tr>";
  echo "</table>";

  // === Otros cargos (totales)
  if ($hasTblCargos && ((float)$sumCar['tot'])>0) {
    echo "<br><h3>Otros cargos</h3>";
    echo "<table border='1' cellspacing='0' cellpadding='4'>";
    echo "<tr><th>Subtotal (cargos)</th><td>".number_format((float)$sumCar['sub'],2,'.','')."</td></tr>";
    echo "<tr><th>IVA (cargos)</th><td>".number_format((float)$sumCar['iva'],2,'.','')."</td></tr>";
    echo "<tr><th>Total (cargos)</th><td>".number_format((float)$sumCar['tot'],2,'.','')."</td></tr>";
    echo "</table>";
  }

  // === Ingresos con IMEIs/series
  if (!empty($imeiRows)) {
    echo "<br><h3>Ingresos (IMEI / series) de esta factura</h3>";
    echo "<table border='1' cellspacing='0' cellpadding='4'><tr>";
    echo "<th>#</th><th>Marca</th><th>Modelo</th><th>Color</th><th>RAM</th><th>Capacidad</th>";
    foreach ($candDynamic as $c) if (array_key_exists($c, $imeiRows[0])) echo "<th>".strtoupper($c)."</th>";
    echo "</tr>";
    $imeisComoTexto = ['imei1','imei','imei2','serial','n_serie','lote'];
    $n=1;
    foreach ($imeiRows as $x) {
      echo "<tr>";
      echo "<td>".$n++."</td>";
      echo "<td>".h($x['marca'])."</td>";
      echo "<td>".h($x['modelo'])."</td>";
      echo "<td>".h($x['color'])."</td>";
      echo "<td>".h($x['ram'])."</td>";
      echo "<td>".h($x['capacidad'])."</td>";
      foreach ($candDynamic as $c) {
        if (array_key_exists($c, $x)) {
          $val = h((string)$x[$c]);
          $isText = in_array($c, $imeisComoTexto, true);
          echo $isText ? "<td class='text'>{$val}</td>" : "<td>{$val}</td>";
        }
      }
      echo "</tr>";
    }
    echo "</table>";
  }

  echo "</body></html>";
  exit;
}

// (Navbar se incluye después del handler de Excel)
include 'navbar.php';

/* ============================
   Consultas base (vista)
============================ */
$enc = $conn->query("
  SELECT c.*, p.nombre AS proveedor, s.nombre AS sucursal
  FROM compras c
  INNER JOIN proveedores p ON p.id=c.id_proveedor
  INNER JOIN sucursales s ON s.id=c.id_sucursal
  WHERE c.id=$id
")->fetch_assoc();
if (!$enc) die("Compra no encontrada.");

// detalle con ingresadas opcional
$ingresadasExpr = $hasTblIngresos ? "(SELECT COUNT(*) FROM compras_detalle_ingresos x WHERE x.id_detalle=d.id)" : "0";
$det = $conn->query("
  SELECT d.*, {$ingresadasExpr} AS ingresadas
  FROM compras_detalle d
  WHERE d.id_compra=$id
  ORDER BY id ASC
");

// pagos
$pagos = $conn->query("
  SELECT id, fecha_pago, monto, metodo_pago, referencia, notas, creado_en
  FROM compras_pagos
  WHERE id_compra=$id
  ORDER BY fecha_pago DESC, id DESC
");

$rowSum = $conn->query("SELECT COALESCE(SUM(monto),0) AS pagado FROM compras_pagos WHERE id_compra=$id")->fetch_assoc();
$totalPagado = (float)$rowSum['pagado'];
$saldo = max(0, (float)$enc['total'] - $totalPagado);

// cargos (si existe tabla)
if ($hasTblCargos) {
  $cargos = $conn->query("
    SELECT id, descripcion, monto, ".($hasCarIVAp?'iva_porcentaje':'0')." AS iva_porcentaje,
           ".($hasCarIvaMonto?'iva_monto':'(monto * (IFNULL('.($hasCarIVAp?'iva_porcentaje':'0').",0)/100))")." AS iva_monto,
           ".($hasCarTotal?'total':'(monto + (monto * (IFNULL('.($hasCarIVAp?'iva_porcentaje':'0').",0)/100)))")." AS total,
           ".(column_exists($conn,'compras_cargos','afecta_costo')?'afecta_costo':'0')." AS afecta_costo,
           ".(column_exists($conn,'compras_cargos','creado_en')?'creado_en':"NULL")." AS creado_en
    FROM compras_cargos
    WHERE id_compra=$id
    ORDER BY id ASC
  ");
  // sumas cargos
  if ($hasCarIvaMonto && $hasCarTotal) {
    $sumCar = $conn->query("
      SELECT COALESCE(SUM(monto),0) AS sub, COALESCE(SUM(iva_monto),0) AS iva, COALESCE(SUM(total),0) AS tot
      FROM compras_cargos WHERE id_compra=$id
    ")->fetch_assoc();
  } else {
    $sumCar = $conn->query("
      SELECT COALESCE(SUM(monto),0) AS sub,
             ".($hasCarIVAp?"COALESCE(SUM(monto*(IFNULL(iva_porcentaje,0)/100)),0)":"0")." AS iva
      FROM compras_cargos WHERE id_compra=$id
    ")->fetch_assoc();
    $sumCar['tot'] = (float)$sumCar['sub'] + (float)$sumCar['iva'];
  }
} else {
  $cargos = false;
  $sumCar = ['sub'=>0,'iva'=>0,'tot'=>0];
}

// sumas detalle
if ($hasSubCol && $hasIvaCol && $hasTotCol) {
  $sumDet = $conn->query("
    SELECT COALESCE(SUM(subtotal),0) AS sub, COALESCE(SUM(iva),0) AS iva, COALESCE(SUM(total),0) AS tot
    FROM compras_detalle WHERE id_compra=$id
  ")->fetch_assoc();
} else {
  $exprSub = $unitCol ? "COALESCE(SUM(cantidad * {$unitCol}),0)" : "0";
  $exprIva = ($unitCol && $hasIVAp) ? "COALESCE(SUM(cantidad * {$unitCol} * (IFNULL(iva_porcentaje,0)/100)),0)" : "0";
  $sumDet = $conn->query("SELECT {$exprSub} AS sub, {$exprIva} AS iva FROM compras_detalle WHERE id_compra=$id")->fetch_assoc();
  $sumDet['tot'] = (float)$sumDet['sub'] + (float)$sumDet['iva'];
}
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<div class="container my-4">

  <div class="d-flex justify-content-between align-items-center mb-2">
    <h4 class="mb-0">
      Factura #<?= h($enc['num_factura']) ?>
      <?php if (!empty($enc['estatus'])): ?>
        <span class="badge <?= $enc['estatus']==='Pagada' ? 'bg-success' : 'bg-secondary' ?> ms-2">
          <?= h($enc['estatus']) ?>
        </span>
      <?php endif; ?>
    </h4>
    <div class="btn-group">
      <a href="compras_resumen.php" class="btn btn-outline-secondary">↩︎ Volver a resumen</a>
      <a href="compras_nueva.php" class="btn btn-primary">Nueva compra</a>
      <a href="compras_ver.php?id=<?= $id ?>&excel=1" class="btn btn-success">Descargar Excel</a>
    </div>
  </div>

  <p class="text-muted mb-1"><strong>Proveedor:</strong> <?= h($enc['proveedor']) ?></p>
  <p class="text-muted mb-1"><strong>Sucursal destino:</strong> <?= h($enc['sucursal']) ?></p>
  <p class="text-muted mb-3">
    <strong>Fechas:</strong> Factura <?= h($enc['fecha_factura']) ?> · Vence <?= h($enc['fecha_vencimiento'] ?? '-') ?>
    <?php if (!empty($enc['condicion_pago'])): ?>
      · <strong>Condición:</strong> <?= h($enc['condicion_pago']) ?>
      <?php if ($enc['condicion_pago']==='Crédito' && $enc['dias_vencimiento']!==''): ?>
        (<?= (int)$enc['dias_vencimiento'] ?> días)
      <?php endif; ?>
    <?php endif; ?>
  </p>

  <!-- Detalle de modelos -->
  <div class="table-responsive mb-4">
    <table class="table table-bordered align-middle">
      <thead>
        <tr>
          <th>Marca</th>
          <th>Modelo</th>
          <th>Color</th>
          <th>RAM</th>
          <th>Capacidad</th>
          <th class="text-center">Req. IMEI</th>
          <th class="text-end">Cant.</th>
          <th class="text-end">Ingresadas</th>
          <th class="text-end"><?= $unitCol ? ($unitCol==='costo_unitario'?'C.Unit':'P.Unit') : 'Unitario' ?></th>
          <th class="text-end">IVA%</th>
          <th class="text-end">Subtotal</th>
          <th class="text-end">IVA</th>
          <th class="text-end">Total</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php while($r=$det->fetch_assoc()):
        $qty  = (int)($r['cantidad'] ?? 0);
        $pu   = $unitCol ? (float)($r[$unitCol] ?? 0) : 0;
        $ivap = $hasIVAp ? (float)($r['iva_porcentaje'] ?? 0) : (float)($r['iva_porcentaje'] ?? 0);
        $sub  = $hasSubCol ? (float)($r['subtotal'] ?? 0) : ($qty * $pu);
        $iva  = $hasIvaCol ? (float)($r['iva'] ?? 0) : ($sub * ($ivap/100));
        $tot  = $hasTotCol ? (float)($r['total'] ?? 0) : ($sub + $iva);
        $ing  = (int)($r['ingresadas'] ?? 0);
        $pend = max(0, $qty - $ing); ?>
        <tr class="<?= $pend>0 ? 'table-warning' : 'table-success' ?>">
          <td><?= h($r['marca'] ?? '') ?></td>
          <td><?= h($r['modelo'] ?? '') ?></td>
          <td><?= h($r['color'] ?? '') ?></td>
          <td><?= h($r['ram'] ?? '') ?></td>
          <td><?= h($r['capacidad'] ?? '') ?></td>
          <td class="text-center"><?= (int)($r['requiere_imei'] ?? 0) ? 'Sí' : 'No' ?></td>
          <td class="text-end"><?= $qty ?></td>
          <td class="text-end"><?= $ing ?></td>
          <td class="text-end">$<?= number_format($pu,2) ?></td>
          <td class="text-end"><?= number_format($ivap,2) ?></td>
          <td class="text-end">$<?= number_format($sub,2) ?></td>
          <td class="text-end">$<?= number_format($iva,2) ?></td>
          <td class="text-end">$<?= number_format($tot,2) ?></td>
          <td class="text-end">
            <?php if ($pend>0): ?>
              <a class="btn btn-sm btn-primary" href="compras_ingreso.php?detalle=<?= (int)$r['id'] ?>&compra=<?= $id ?>">Ingresar</a>
            <?php else: ?>
              <span class="badge bg-success">Completado</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endwhile; ?>
      </tbody>
      <tfoot>
        <tr>
          <th colspan="10" class="text-end">Subtotal (modelos)</th>
          <th class="text-end">$<?= number_format((float)$sumDet['sub'],2) ?></th>
          <th colspan="3"></th>
        </tr>
        <tr>
          <th colspan="11" class="text-end">IVA (modelos)</th>
          <th class="text-end">$<?= number_format((float)$sumDet['iva'],2) ?></th>
          <th colspan="2"></th>
        </tr>
        <tr class="table-light">
          <th colspan="12" class="text-end fs-6">Total (modelos)</th>
          <th class="text-end fs-6">$<?= number_format((float)$sumDet['tot'],2) ?></th>
          <th></th>
        </tr>
      </tfoot>
    </table>
  </div>

  <!-- Otros cargos -->
  <div class="card shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Otros cargos</strong>
      <?php if ($hasTblCargos && $cargos && $cargos->num_rows > 0): ?>
        <span class="text-muted small">
          Subtotal: $<?= number_format((float)$sumCar['sub'],2) ?> ·
          IVA: $<?= number_format((float)$sumCar['iva'],2) ?> ·
          Total: $<?= number_format((float)$sumCar['tot'],2) ?>
        </span>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <?php if ($hasTblCargos && $cargos && $cargos->num_rows > 0): ?>
        <div class="table-responsive">
          <table class="table table-sm table-bordered align-middle">
            <thead class="table-light">
              <tr>
                <th>Descripción</th>
                <th class="text-end">Importe</th>
                <th class="text-end">IVA %</th>
                <th class="text-end">IVA</th>
                <th class="text-end">Total</th>
                <th class="text-muted">Capturado</th>
              </tr>
            </thead>
            <tbody>
              <?php while($x = $cargos->fetch_assoc()): ?>
                <tr>
                  <td><?= h($x['descripcion']) ?></td>
                  <td class="text-end">$<?= number_format((float)$x['monto'],2) ?></td>
                  <td class="text-end"><?= number_format((float)$x['iva_porcentaje'],2) ?></td>
                  <td class="text-end">$<?= number_format((float)$x['iva_monto'],2) ?></td>
                  <td class="text-end">$<?= number_format((float)$x['total'],2) ?></td>
                  <td class="text-muted small"><?= h($x['creado_en']) ?></td>
                </tr>
              <?php endwhile; ?>
            </tbody>
            <tfoot>
              <tr>
                <th class="text-end">Subtotal (cargos)</th>
                <th class="text-end">$<?= number_format((float)$sumCar['sub'],2) ?></th>
                <th></th>
                <th class="text-end">$<?= number_format((float)$sumCar['iva'],2) ?></th>
                <th class="text-end">$<?= number_format((float)$sumCar['tot'],2) ?></th>
                <th></th>
              </tr>
            </tfoot>
          </table>
        </div>
      <?php else: ?>
        <div class="text-muted">No hay otros cargos registrados para esta compra.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Resumen de factura (modelos + cargos = total) -->
  <div class="row g-3 mb-4">
    <div class="col-md-6">
      <div class="card border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex justify-content-between">
            <span>Subtotal modelos</span>
            <strong>$<?= number_format((float)$sumDet['sub'],2) ?></strong>
          </div>
          <div class="d-flex justify-content-between">
            <span>IVA modelos</span>
            <strong>$<?= number_format((float)$sumDet['iva'],2) ?></strong>
          </div>
          <div class="d-flex justify-content-between mb-2">
            <span>Total modelos</span>
            <strong>$<?= number_format((float)$sumDet['tot'],2) ?></strong>
          </div>
          <hr>
          <div class="d-flex justify-content-between">
            <span>Subtotal otros cargos</span>
            <strong>$<?= number_format((float)$sumCar['sub'],2) ?></strong>
          </div>
          <div class="d-flex justify-content-between">
            <span>IVA otros cargos</span>
            <strong>$<?= number_format((float)$sumCar['iva'],2) ?></strong>
          </div>
          <div class="d-flex justify-content-between mb-2">
            <span>Total otros cargos</span>
            <strong>$<?= number_format((float)$sumCar['tot'],2) ?></strong>
          </div>
          <hr>
          <div class="d-flex justify-content-between fs-5">
            <span>Total factura</span>
            <span><strong>$<?= number_format((float)$enc['total'],2) ?></strong></span>
          </div>
          <div class="text-muted small mt-1">
            (El total de la factura ya incluye los cargos.)
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Panel de pagos -->
  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div>
        <strong>Pagos</strong>
        <span class="ms-2">Total pagado: <strong>$<?= number_format($totalPagado,2) ?></strong></span>
        <span class="ms-3">Saldo:
          <strong class="<?= $saldo<=0 ? 'text-success' : 'text-danger' ?>">
            $<?= number_format($saldo,2) ?>
          </strong>
        </span>
      </div>
      <?php $puedeAgregarPago = $saldo > 0; ?>
      <button
        class="btn btn-sm btn-outline-primary <?= $puedeAgregarPago ? '' : 'disabled' ?>"
        data-bs-toggle="modal"
        data-bs-target="#modalPago"
        <?= $puedeAgregarPago ? '' : 'disabled title="Saldo cubierto: no es posible agregar más pagos"' ?>
      >+ Agregar pago</button>
    </div>
    <div class="card-body">
      <?php
      $hayPagos = ($pagos && $pagos->num_rows > 0);
      if ($hayPagos): ?>
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Método</th>
                <th>Referencia</th>
                <th class="text-end">Monto</th>
                <th>Notas</th>
                <th class="text-muted">Capturado</th>
              </tr>
            </thead>
            <tbody>
              <?php while($p = $pagos->fetch_assoc()): ?>
                <tr>
                  <td><?= h($p['fecha_pago']) ?></td>
                  <td><?= h($p['metodo_pago'] ?? '') ?></td>
                  <td><?= h($p['referencia'] ?? '') ?></td>
                  <td class="text-end">$<?= number_format((float)$p['monto'],2) ?></td>
                  <td><?= nl2br(h($p['notas'] ?? '')) ?></td>
                  <td class="text-muted small"><?= h($p['creado_en']) ?></td>
                </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="text-muted">No hay pagos registrados.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Modal: agregar pago -->
  <div class="modal fade" id="modalPago" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <form method="post" class="modal-content">
        <input type="hidden" name="accion" value="agregar_pago">
        <input type="hidden" name="id_compra" value="<?= $id ?>">
        <div class="modal-header">
          <h5 class="modal-title">Agregar pago</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Fecha</label>
              <input type="date" name="fecha_pago" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Método</label>
              <select name="metodo_pago" class="form-select">
                <option value="Efectivo">Efectivo</option>
                <option value="Transferencia">Transferencia</option>
                <option value="Tarjeta">Tarjeta</option>
                <option value="Depósito">Depósito</option>
                <option value="Otro">Otro</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Monto</label>
              <input type="number" name="monto" class="form-control" step="0.01" min="0.01" required>
            </div>
            <div class="col-12">
              <label class="form-label">Referencia</label>
              <input type="text" name="referencia" class="form-control" maxlength="120" placeholder="Folio, banco, etc. (opcional)">
            </div>
            <div class="col-12">
              <label class="form-label">Notas</label>
              <textarea name="notas" class="form-control" rows="2" maxlength="1000" placeholder="Opcional"></textarea>
            </div>
          </div>
          <small class="text-muted d-block mt-2">Se registrará en <strong>compras_pagos</strong>.</small>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-primary">Guardar pago</button>
        </div>
      </form>
    </div>
  </div>

</div>

<!-- Bundle JS de Bootstrap para que funcione el modal -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
