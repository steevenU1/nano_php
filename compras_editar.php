<?php
// compras_editar.php — Editor temporal para agregar renglones a una compra existente
// Permite agregar líneas adicionales sin tocar lo ya capturado ni inventario.
// Roles: Admin, Logistica, Gerente (ajustable abajo).

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

$ROL = $_SESSION['rol'] ?? 'Ejecutivo';
$PERMITIDOS = ['Admin','Logistica','Gerente'];
if (!in_array($ROL, $PERMITIDOS, true)) {
  header("Location: 403.php"); exit();
}

// CSRF
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['csrf'];

// Helpers
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function n2($v){ return number_format((float)$v, 2); }

// ---------------------
// 1) Resolver compra
// ---------------------
$id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);
if ($id <= 0) { header("Location: compras_resumen.php?msg=" . urlencode("Compra inválida.")); exit(); }

// Encabezado de la compra
$sqlCab = "SELECT c.*, p.nombre AS proveedor, s.nombre AS sucursal
           FROM compras c
           JOIN proveedores p ON p.id = c.id_proveedor
           JOIN sucursales  s ON s.id = c.id_sucursal
           WHERE c.id = ? LIMIT 1";
$st = $conn->prepare($sqlCab);
$st->bind_param("i", $id);
$st->execute();
$cab = $st->get_result()->fetch_assoc();
$st->close();

if (!$cab) { header("Location: compras_resumen.php?msg=" . urlencode("Compra no encontrada.")); exit(); }

// Renglones existentes (solo lectura)
$sqlDet = "SELECT d.*
           FROM compras_detalle d
           WHERE d.id_compra = ?
           ORDER BY d.id ASC";
$st = $conn->prepare($sqlDet);
$st->bind_param("i", $id);
$st->execute();
$detalles = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// ---------------------
// 2) Columnas disponibles en compras_detalle
// ---------------------
$cols = [];
$resCols = $conn->query("
  SELECT COLUMN_NAME
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'compras_detalle'
");
while($c = $resCols->fetch_assoc()){ $cols[$c['COLUMN_NAME']] = true; }

$hasIdModelo      = isset($cols['id_modelo']);
$hasColor         = isset($cols['color']);
$hasCapacidad     = isset($cols['capacidad']) || isset($cols['almacenamiento']); // tolerante
$capFieldName     = isset($cols['capacidad']) ? 'capacidad' : (isset($cols['almacenamiento']) ? 'almacenamiento' : null);
$hasCostoUnitario = isset($cols['costo_unitario']) ? 'costo_unitario' : (isset($cols['precio_unitario']) ? 'precio_unitario' : null);
$hasIvaPorc       = isset($cols['iva_porcentaje']) ? 'iva_porcentaje' : null;
$hasDescripcion   = isset($cols['descripcion']);

// ---------------------
// 3) Catálogo de modelos (para agregar líneas)
// ---------------------
$modelos = [];
if ($hasIdModelo) {
  // Catálogo formal (ajusta nombres de columnas según tu tabla)
  // Usamos "catalogo_modelos" con columnas comunes: id, marca, modelo, codigo_producto
  $qMod = $conn->query("SELECT id, marca, modelo, COALESCE(codigo_producto,'') AS codigo_producto
                        FROM catalogo_modelos ORDER BY marca, modelo");
  while($m = $qMod->fetch_assoc()){
    $etq = trim($m['marca'].' '.$m['modelo']);
    if ($m['codigo_producto'] !== '') { $etq .= ' · '.$m['codigo_producto']; }
    $modelos[] = ['id'=>(int)$m['id'], 'etq'=>$etq];
  }
}

// ---------------------
// 4) Guardar líneas nuevas (POST)
// ---------------------
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf']) && hash_equals($CSRF, $_POST['csrf'] ?? '')) {
  $nuevas = $_POST['new'] ?? []; // arreglo de renglones nuevos

  // Normalizar entradas (filtrar vacíos)
  $toInsert = [];
  foreach ($nuevas as $row) {
    $id_modelo = isset($row['id_modelo']) ? (int)$row['id_modelo'] : 0;
    $cant      = isset($row['cantidad']) ? (int)$row['cantidad'] : 0;

    // costo o precio unitario
    $cu_raw    = trim((string)($row['costo_unitario'] ?? $row['precio_unitario'] ?? ''));
    $cu        = $cu_raw === '' ? null : (float)$cu_raw;
    $iva_p     = isset($row['iva_porcentaje']) && $row['iva_porcentaje'] !== '' ? (float)$row['iva_porcentaje'] : null;

    $color     = trim((string)($row['color'] ?? ''));
    $cap       = trim((string)($row['capacidad'] ?? $row['almacenamiento'] ?? ''));
    $desc      = trim((string)($row['descripcion'] ?? ''));

    // Regla mínima: debe tener cantidad >=1 y o bien id_modelo>0 (si existe) o una descripción (si no existe id_modelo)
    if ($cant <= 0) continue;
    if ($hasIdModelo && $id_modelo <= 0) continue;
    if (!$hasIdModelo && $hasDescripcion && $desc === '') continue;

    $toInsert[] = [
      'id_modelo' => $id_modelo,
      'cantidad'  => $cant,
      'cu'        => $cu,
      'iva_p'     => $iva_p,
      'color'     => $color,
      'cap'       => $cap,
      'desc'      => $desc,
    ];
  }

  if (count($toInsert) === 0) {
    $msg = 'No hay renglones válidos para agregar.';
  } else {
    $conn->begin_transaction();
    try {
      // Inserta cada renglón con columnas disponibles
      foreach ($toInsert as $r) {
        $colsIns = ['id_compra'];
        $valsIns = ['?'];
        $types   = 'i';
        $binds   = [$id];

        if ($hasIdModelo) { $colsIns[] = 'id_modelo'; $valsIns[]='?'; $types.='i'; $binds[]=$r['id_modelo']; }
        if ($hasDescripcion && $r['desc']!==''){ $colsIns[] = 'descripcion'; $valsIns[]='?'; $types.='s'; $binds[]=$r['desc']; }
        if ($hasColor) { $colsIns[]='color'; $valsIns[]='?'; $types.='s'; $binds[]=$r['color']; }
        if ($capFieldName){ $colsIns[]=$capFieldName; $valsIns[]='?'; $types.='s'; $binds[]=$r['cap']; }
        $colsIns[]='cantidad'; $valsIns[]='?'; $types.='i'; $binds[]=$r['cantidad'];
        if ($hasCostoUnitario){ $colsIns[]=$hasCostoUnitario; $valsIns[]='?'; $types.='d'; $binds[] = $r['cu'] ?? 0.0; }
        if ($hasIvaPorc){ $colsIns[]='iva_porcentaje'; $valsIns[]='?'; $types.='d'; $binds[] = $r['iva_p'] ?? 0.0; }

        $sqlIns = "INSERT INTO compras_detalle (".implode(',', $colsIns).") VALUES (".implode(',', $valsIns).")";
        $stI = $conn->prepare($sqlIns);
        $stI->bind_param($types, ...$binds);
        $stI->execute();
        $stI->close();
      }

      // Intento de recalcular totales si la estructura lo permite
      $recalcOK = false;

      // ¿Podemos sumar: cantidad * (costo_unitario|precio_unitario)?
      if ($hasCostoUnitario) {
        // IVA
        if ($hasIvaPorc) {
          // subtotal = SUM(cantidad * cu)
          // iva      = SUM(cantidad * cu * iva_porcentaje/100)
          $sqlSum = "
            SELECT
              SUM(d.cantidad * d.$hasCostoUnitario) AS sub,
              SUM(d.cantidad * d.$hasCostoUnitario * (IFNULL(d.iva_porcentaje,0)/100)) AS iva
            FROM compras_detalle d
            WHERE d.id_compra = ?
          ";
          $stS = $conn->prepare($sqlSum);
          $stS->bind_param("i", $id);
          $stS->execute();
          $sum = $stS->get_result()->fetch_assoc() ?: ['sub'=>0,'iva'=>0];
          $stS->close();

          $subtotal = (float)($sum['sub'] ?? 0);
          $iva      = (float)($sum['iva'] ?? 0);
          $total    = $subtotal + $iva;

          $stU = $conn->prepare("UPDATE compras SET subtotal=?, iva=?, total=? WHERE id=?");
          $stU->bind_param("dddi", $subtotal, $iva, $total, $id);
          $stU->execute();
          $stU->close();
          $recalcOK = true;
        } else {
          // Si no hay iva_porcentaje por renglón, asumimos que header. Recalculamos solo subtotal y total=subtotal+iva(header prev.)
          $stS = $conn->prepare("SELECT SUM(d.cantidad * d.$hasCostoUnitario) AS sub FROM compras_detalle d WHERE d.id_compra = ?");
          $stS->bind_param("i", $id);
          $stS->execute();
          $sum = $stS->get_result()->fetch_assoc();
          $stS->close();
          $subtotal = (float)($sum['sub'] ?? 0);
          $iva_prev = (float)($cab['iva'] ?? 0);
          $total    = $subtotal + $iva_prev;

          $stU = $conn->prepare("UPDATE compras SET subtotal=?, total=? WHERE id=?");
          $stU->bind_param("ddi", $subtotal, $total, $id);
          $stU->execute();
          $stU->close();
          $recalcOK = true; // parcial
        }
      }

      $conn->commit();
      $msg = $recalcOK
            ? 'Renglones agregados y totales recalculados.'
            : 'Renglones agregados. No se pudo recalcular totales automáticamente (estructura no compatible).';
      header("Location: compras_editar.php?id=".$id."&msg=".urlencode($msg));
      exit();
    } catch (Throwable $e) {
      $conn->rollback();
      $msg = 'Error al guardar: '.$e->getMessage();
    }
  }
}

// ---------------------
// 5) UI
// ---------------------
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<div class="container my-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="mb-0">Editar compra (temporal)</h3>
    <a href="compras_resumen.php" class="btn btn-sm btn-outline-secondary">← Regresar</a>
  </div>

  <?php if(isset($_GET['msg'])): ?>
    <div class="alert alert-info alert-dismissible fade show" role="alert">
      <?= h($_GET['msg']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endif; ?>
  <?php if($msg): ?>
    <div class="alert alert-warning"><?= h($msg) ?></div>
  <?php endif; ?>

  <div class="card shadow-sm mb-4">
    <div class="card-header">Encabezado</div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label"># Factura</label>
          <input type="text" class="form-control" value="<?= h($cab['num_factura'] ?? '') ?>" disabled>
        </div>
        <div class="col-md-3">
          <label class="form-label">Proveedor</label>
          <input type="text" class="form-control" value="<?= h($cab['proveedor'] ?? '') ?>" disabled>
        </div>
        <div class="col-md-3">
          <label class="form-label">Sucursal</label>
          <input type="text" class="form-control" value="<?= h($cab['sucursal'] ?? '') ?>" disabled>
        </div>
        <div class="col-md-3">
          <label class="form-label">Fecha factura</label>
          <input type="text" class="form-control" value="<?= h($cab['fecha_factura'] ?? '') ?>" disabled>
        </div>
        <div class="col-md-3">
          <label class="form-label">Subtotal</label>
          <input type="text" class="form-control" value="$<?= n2($cab['subtotal'] ?? 0) ?>" disabled>
        </div>
        <div class="col-md-3">
          <label class="form-label">IVA</label>
          <input type="text" class="form-control" value="$<?= n2($cab['iva'] ?? 0) ?>" disabled>
        </div>
        <div class="col-md-3">
          <label class="form-label">Total</label>
          <input type="text" class="form-control" value="$<?= n2($cab['total'] ?? 0) ?>" disabled>
        </div>
        <div class="col-md-3">
          <label class="form-label">Estatus</label>
          <input type="text" class="form-control" value="<?= h($cab['estatus'] ?? '') ?>" disabled>
        </div>
      </div>
      <div class="form-text mt-2">Este editor es temporal: solo agrega renglones. Lo ya capturado no se modifica.</div>
    </div>
  </div>

  <div class="card shadow-sm mb-4">
    <div class="card-header">Renglones existentes</div>
    <div class="card-body">
      <?php if(count($detalles)): ?>
        <div class="table-responsive">
          <table class="table table-sm table-striped align-middle">
            <thead>
              <tr>
                <th>ID</th>
                <?php if($hasIdModelo): ?><th>id_modelo</th><?php endif; ?>
                <?php if($hasDescripcion): ?><th>Descripción</th><?php endif; ?>
                <?php if($hasColor): ?><th>Color</th><?php endif; ?>
                <?php if($capFieldName): ?><th>Almacenamiento</th><?php endif; ?>
                <th class="text-end">Cantidad</th>
                <?php if($hasCostoUnitario): ?><th class="text-end"><?= h($hasCostoUnitario) ?></th><?php endif; ?>
                <?php if($hasIvaPorc): ?><th class="text-end">IVA %</th><?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach($detalles as $d): ?>
                <tr>
                  <td><?= (int)$d['id'] ?></td>
                  <?php if($hasIdModelo): ?><td><?= (int)($d['id_modelo'] ?? 0) ?></td><?php endif; ?>
                  <?php if($hasDescripcion): ?><td><?= h($d['descripcion'] ?? '') ?></td><?php endif; ?>
                  <?php if($hasColor): ?><td><?= h($d['color'] ?? '') ?></td><?php endif; ?>
                  <?php if($capFieldName): ?><td><?= h($d[$capFieldName] ?? '') ?></td><?php endif; ?>
                  <td class="text-end"><?= (int)($d['cantidad'] ?? 0) ?></td>
                  <?php if($hasCostoUnitario): ?><td class="text-end"><?= n2($d[$hasCostoUnitario] ?? 0) ?></td><?php endif; ?>
                  <?php if($hasIvaPorc): ?><td class="text-end"><?= n2($d['iva_porcentaje'] ?? 0) ?></td><?php endif; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="text-muted">Sin renglones capturados aún.</div>
      <?php endif; ?>
    </div>
  </div>

  <form method="POST" class="card shadow-sm">
    <div class="card-header d-flex justify-content-between">
      <span>Agregar renglones</span>
      <button type="button" class="btn btn-sm btn-outline-primary" id="btn-add-row">+ Agregar fila</button>
    </div>
    <div class="card-body">
      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
      <input type="hidden" name="id" value="<?= (int)$id ?>">

      <div class="table-responsive">
        <table class="table align-middle" id="tbl-new">
          <thead>
            <tr>
              <?php if($hasIdModelo): ?>
                <th style="min-width: 240px;">Modelo</th>
              <?php elseif($hasDescripcion): ?>
                <th style="min-width: 240px;">Descripción</th>
              <?php endif; ?>
              <?php if($hasColor): ?><th>Color</th><?php endif; ?>
              <?php if($capFieldName): ?><th>Almacenamiento</th><?php endif; ?>
              <th style="width:120px;" class="text-end">Cantidad</th>
              <?php if($hasCostoUnitario): ?><th style="width:160px;" class="text-end"><?= h($hasCostoUnitario) ?></th><?php endif; ?>
              <?php if($hasIvaPorc): ?><th style="width:120px;" class="text-end">IVA %</th><?php endif; ?>
              <th style="width:60px;"></th>
            </tr>
          </thead>
          <tbody id="new-body">
            <!-- filas dinámicas -->
          </tbody>
        </table>
      </div>

      <div class="d-flex justify-content-end mt-3">
        <button type="submit" class="btn btn-success">Guardar renglones</button>
      </div>
      <div class="form-text mt-2">
        Tras guardar, si tu esquema lo permite, se recalculan subtotal/IVA/total en la cabecera.
      </div>
    </div>
  </form>
</div>

<script>
(function(){
  const hasIdModelo   = <?= $hasIdModelo ? 'true':'false' ?>;
  const hasDesc       = <?= $hasDescripcion ? 'true':'false' ?>;
  const hasColor      = <?= $hasColor ? 'true':'false' ?>;
  const hasCap        = <?= $capFieldName ? 'true':'false' ?>;
  const hasCU         = <?= $hasCostoUnitario ? 'true':'false' ?>;
  const hasIVA        = <?= $hasIvaPorc ? 'true':'false' ?>;

  const modelos = <?php echo json_encode($modelos, JSON_UNESCAPED_UNICODE); ?>;

  const body = document.getElementById('new-body');
  const btnAdd = document.getElementById('btn-add-row');

  function makeRow(){
    const tr = document.createElement('tr');

    if (hasIdModelo) {
      const td = document.createElement('td');
      const sel = document.createElement('select');
      sel.className = 'form-select';
      sel.name = 'new[][id_modelo]';
      const opt0 = document.createElement('option');
      opt0.value = ''; opt0.textContent = '— seleccionar —';
      sel.appendChild(opt0);
      modelos.forEach(m => {
        const o = document.createElement('option');
        o.value = m.id; o.textContent = m.etq;
        sel.appendChild(o);
      });
      td.appendChild(sel);
      tr.appendChild(td);
    } else if (hasDesc) {
      const td = document.createElement('td');
      const inp = document.createElement('input');
      inp.type = 'text'; inp.name = 'new[][descripcion]'; inp.className = 'form-control'; inp.placeholder = 'Descripción';
      td.appendChild(inp);
      tr.appendChild(td);
    }

    if (hasColor) {
      const td = document.createElement('td');
      const inp = document.createElement('input');
      inp.type = 'text'; inp.name = 'new[][color]'; inp.className = 'form-control'; inp.placeholder = 'Color';
      td.appendChild(inp);
      tr.appendChild(td);
    }

    if (hasCap) {
      const td = document.createElement('td');
      const inp = document.createElement('input');
      inp.type = 'text'; inp.name = 'new[][capacidad]'; inp.className = 'form-control'; inp.placeholder = 'Almacenamiento';
      td.appendChild(inp);
      tr.appendChild(td);
    }

    {
      const td = document.createElement('td');
      const inp = document.createElement('input');
      inp.type = 'number'; inp.step = '1'; inp.min = '1'; inp.name = 'new[][cantidad]'; inp.className = 'form-control text-end';
      td.appendChild(inp);
      tr.appendChild(td);
    }

    if (hasCU) {
      const td = document.createElement('td');
      const inp = document.createElement('input');
      inp.type = 'number'; inp.step = '0.01'; inp.min = '0'; inp.name = 'new[][costo_unitario]'; inp.className = 'form-control text-end';
      td.appendChild(inp);
      tr.appendChild(td);
    }

    if (hasIVA) {
      const td = document.createElement('td');
      const inp = document.createElement('input');
      inp.type = 'number'; inp.step = '0.01'; inp.min = '0'; inp.max = '100'; inp.name = 'new[][iva_porcentaje]'; inp.className = 'form-control text-end';
      td.appendChild(inp);
      tr.appendChild(td);
    }

    const tdDel = document.createElement('td');
    const btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'btn btn-sm btn-outline-danger';
    btn.textContent = 'Quitar';
    btn.addEventListener('click', () => tr.remove());
    tdDel.appendChild(btn);
    tr.appendChild(tdDel);

    return tr;
  }

  btnAdd.addEventListener('click', () => {
    body.appendChild(makeRow());
  });

  // arranca con una fila
  body.appendChild(makeRow());
})();
</script>
