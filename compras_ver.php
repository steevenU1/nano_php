<?php
// compras_ver.php
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }
include 'db.php';
include 'navbar.php';

// ID de compra (desde GET o desde POST al agregar pago)
$id = (int)($_POST['id_compra'] ?? ($_GET['id'] ?? 0));
if ($id <= 0) die("ID inválido.");

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
   Consultas base
============================ */
$enc = $conn->query("
  SELECT c.*, p.nombre AS proveedor, s.nombre AS sucursal
  FROM compras c
  INNER JOIN proveedores p ON p.id=c.id_proveedor
  INNER JOIN sucursales s ON s.id=c.id_sucursal
  WHERE c.id=$id
")->fetch_assoc();
if (!$enc) die("Compra no encontrada.");

$det = $conn->query("
  SELECT d.*
       , (SELECT COUNT(*) FROM compras_detalle_ingresos x WHERE x.id_detalle=d.id) AS ingresadas
  FROM compras_detalle d
  WHERE d.id_compra=$id
");

$pagos = $conn->query("
  SELECT id, fecha_pago, monto, metodo_pago, referencia, notas, creado_en
  FROM compras_pagos
  WHERE id_compra=$id
  ORDER BY fecha_pago DESC, id DESC
");

$rowSum = $conn->query("SELECT COALESCE(SUM(monto),0) AS pagado FROM compras_pagos WHERE id_compra=$id")->fetch_assoc();
$totalPagado = (float)$rowSum['pagado'];
$saldo = max(0, (float)$enc['total'] - $totalPagado);

// 🆕 flag para habilitar/deshabilitar el botón de pago
$puedeAgregarPago = $saldo > 0;
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<div class="container my-4">

  <div class="d-flex justify-content-between align-items-center mb-2">
    <h4 class="mb-0">
      Factura #<?= htmlspecialchars($enc['num_factura']) ?>
      <?php if (!empty($enc['estatus'])): ?>
        <span class="badge <?= $enc['estatus']==='Pagada' ? 'bg-success' : 'bg-secondary' ?> ms-2">
          <?= htmlspecialchars($enc['estatus']) ?>
        </span>
      <?php endif; ?>
    </h4>
    <div class="btn-group">
      <a href="compras_resumen.php" class="btn btn-outline-secondary">↩︎ Volver a resumen</a>
      <a href="compras_nueva.php" class="btn btn-primary">Nueva compra</a>
    </div>
  </div>

  <p class="text-muted mb-1"><strong>Proveedor:</strong> <?= htmlspecialchars($enc['proveedor']) ?></p>
  <p class="text-muted mb-1"><strong>Sucursal destino:</strong> <?= htmlspecialchars($enc['sucursal']) ?></p>
  <p class="text-muted mb-3">
    <strong>Fechas:</strong> Factura <?= $enc['fecha_factura'] ?> · Vence <?= $enc['fecha_vencimiento'] ?: '-' ?>
    <?php if (!empty($enc['condicion_pago'])): ?>
      · <strong>Condición:</strong> <?= htmlspecialchars($enc['condicion_pago']) ?>
      <?php if ($enc['condicion_pago']==='Crédito' && $enc['dias_vencimiento']!==''): ?>
        (<?= (int)$enc['dias_vencimiento'] ?> días)
      <?php endif; ?>
    <?php endif; ?>
  </p>

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
          <th class="text-end">P.Unit</th>
          <th class="text-end">IVA%</th>
          <th class="text-end">Subtotal</th>
          <th class="text-end">IVA</th>
          <th class="text-end">Total</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php while($r=$det->fetch_assoc()):
        $pend = max(0, (int)$r['cantidad'] - (int)$r['ingresadas']); ?>
        <tr class="<?= $pend>0 ? 'table-warning' : 'table-success' ?>">
          <td><?= htmlspecialchars($r['marca']) ?></td>
          <td><?= htmlspecialchars($r['modelo']) ?></td>
          <td><?= htmlspecialchars($r['color']) ?></td>
          <td><?= htmlspecialchars($r['ram'] ?? '') ?></td>
          <td><?= htmlspecialchars($r['capacidad']) ?></td>
          <td class="text-center"><?= $r['requiere_imei'] ? 'Sí' : 'No' ?></td>
          <td class="text-end"><?= (int)$r['cantidad'] ?></td>
          <td class="text-end"><?= (int)$r['ingresadas'] ?></td>
          <td class="text-end">$<?= number_format((float)$r['precio_unitario'],2) ?></td>
          <td class="text-end"><?= number_format((float)$r['iva_porcentaje'],2) ?></td>
          <td class="text-end">$<?= number_format((float)$r['subtotal'],2) ?></td>
          <td class="text-end">$<?= number_format((float)$r['iva'],2) ?></td>
          <td class="text-end">$<?= number_format((float)$r['total'],2) ?></td>
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
          <th colspan="10" class="text-end">Subtotal</th>
          <th class="text-end">$<?= number_format((float)$enc['subtotal'],2) ?></th>
          <th colspan="3"></th>
        </tr>
        <tr>
          <th colspan="11" class="text-end">IVA</th>
          <th class="text-end">$<?= number_format((float)$enc['iva'],2) ?></th>
          <th colspan="2"></th>
        </tr>
        <tr class="table-light">
          <th colspan="12" class="text-end fs-5">Total</th>
          <th class="text-end fs-5">$<?= number_format((float)$enc['total'],2) ?></th>
          <th></th>
        </tr>
      </tfoot>
    </table>
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
      <button
        class="btn btn-sm btn-outline-primary <?= $puedeAgregarPago ? '' : 'disabled' ?>"
        data-bs-toggle="modal"
        data-bs-target="#modalPago"
        <?= $puedeAgregarPago ? '' : 'disabled title="Saldo cubierto: no es posible agregar más pagos"' ?>
      >+ Agregar pago</button>
    </div>
    <div class="card-body">
      <?php if ($pagos && $pagos->num_rows > 0): ?>
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
                  <td><?= htmlspecialchars($p['fecha_pago']) ?></td>
                  <td><?= htmlspecialchars($p['metodo_pago'] ?? '') ?></td>
                  <td><?= htmlspecialchars($p['referencia'] ?? '') ?></td>
                  <td class="text-end">$<?= number_format((float)$p['monto'],2) ?></td>
                  <td><?= nl2br(htmlspecialchars($p['notas'] ?? '')) ?></td>
                  <td class="text-muted small"><?= htmlspecialchars($p['creado_en']) ?></td>
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
