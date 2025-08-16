<?php
// compras_resumen.php
// Resumen de facturas de compra: filtros + KPIs + aging + alertas + acciones

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

include 'db.php';

include 'navbar.php';

$ROL = $_SESSION['rol'] ?? 'Ejecutivo';
$permEscritura = in_array($ROL, ['Admin','Gerente']);

// ====== Helpers ======
function esc($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function cap($s,$n){ return substr(trim($s ?? ''),0,$n); }
function n2($v){ return number_format((float)$v, 2); }

$hoy = date('Y-m-d');

// ====== Filtros ======
$estado   = cap($_GET['estado'] ?? 'todos', 20);           // todos|Pendiente|Parcial|Pagada|Cancelada
$prov_id  = (int)($_GET['proveedor'] ?? 0);
$suc_id   = (int)($_GET['sucursal'] ?? 0);
$desde    = cap($_GET['desde'] ?? '', 10);                 // YYYY-MM-DD
$hasta    = cap($_GET['hasta'] ?? '', 10);                 // YYYY-MM-DD
$q        = cap($_GET['q'] ?? '', 60);                     // búsqueda por # factura
$pxdias   = (int)($_GET['px'] ?? 7);                       // Próximos X días (default 7)
if ($pxdias < 0) $pxdias = 0;

$where = [];
$params = [];
$types = '';

if ($estado !== 'todos') { $where[] = "c.estatus = ?"; $params[] = $estado; $types.='s'; }
if ($prov_id > 0)        { $where[] = "c.id_proveedor = ?"; $params[] = $prov_id; $types.='i'; }
if ($suc_id > 0)         { $where[] = "c.id_sucursal = ?";  $params[] = $suc_id;  $types.='i'; }
if ($desde !== '')       { $where[] = "c.fecha_factura >= ?"; $params[] = $desde; $types.='s'; }
if ($hasta !== '')       { $where[] = "c.fecha_factura <= ?"; $params[] = $hasta; $types.='s'; }
if ($q !== '')           { $where[] = "c.num_factura LIKE ?"; $params[] = "%$q%"; $types.='s'; }

$sqlWhere = count($where) ? ('WHERE '.implode(' AND ', $where)) : '';

// Catálogos para filtros
$proveedores = $conn->query("SELECT id, nombre FROM proveedores WHERE activo=1 ORDER BY nombre");
$sucursales  = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");

// ====== Consulta principal ======
$sql = "
  SELECT
    c.id,
    c.num_factura,
    c.fecha_factura,
    c.fecha_vencimiento,
    c.subtotal,
    c.iva,
    c.total,
    c.estatus,
    c.id_proveedor,
    p.nombre AS proveedor,
    s.nombre AS sucursal,
    IFNULL(pg.pagado, 0) AS pagado,
    (c.total - IFNULL(pg.pagado, 0)) AS saldo,
    IFNULL(ing.pendientes_ingreso, 0) AS pendientes_ingreso,
    ing.primer_detalle_pendiente
  FROM compras c
  INNER JOIN proveedores p ON p.id = c.id_proveedor
  INNER JOIN sucursales  s ON s.id = c.id_sucursal
  LEFT JOIN (
    SELECT id_compra, SUM(monto) AS pagado
    FROM compras_pagos
    GROUP BY id_compra
  ) pg ON pg.id_compra = c.id
  LEFT JOIN (
    SELECT
      d.id_compra,
      SUM( GREATEST(d.cantidad - IFNULL(x.ing,0), 0) ) AS pendientes_ingreso,
      MIN( CASE WHEN GREATEST(d.cantidad - IFNULL(x.ing,0), 0) > 0 THEN d.id END ) AS primer_detalle_pendiente
    FROM compras_detalle d
    LEFT JOIN (
      SELECT id_detalle, COUNT(*) AS ing
      FROM compras_detalle_ingresos
      GROUP BY id_detalle
    ) x ON x.id_detalle = d.id
    GROUP BY d.id_compra
  ) ing ON ing.id_compra = c.id
  $sqlWhere
  ORDER BY c.fecha_factura DESC, c.id DESC
";

$stmt = $conn->prepare($sql);
if ($stmt === false) { die("Error en prepare: ".$conn->error); }
if (strlen($types) > 0) {
  $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();

// Cargar filas en memoria
$rows = [];
while($row = $res->fetch_assoc()){
  $row['total']  = (float)$row['total'];
  $row['pagado'] = (float)$row['pagado'];
  $row['saldo']  = (float)$row['saldo'];
  $rows[] = $row;
}

// ====== KPIs y métricas ======
$totalCompras = 0.0;
$totalPagado  = 0.0;
$totalSaldo   = 0.0;
$saldoVencido = 0.0;
$saldoPorVencer = 0.0;

// Aging buckets
$aging = [
  'current' => 0.0,
  'd1_30'   => 0.0,
  'd31_60'  => 0.0,
  'd61_90'  => 0.0,
  'd90p'    => 0.0,
];

$vencidas = [];
$porVencer = [];

foreach ($rows as $r) {
  $totalCompras += $r['total'];
  $totalPagado  += $r['pagado'];
  $totalSaldo   += max(0, $r['saldo']);

  $vence = $r['fecha_vencimiento'] ?: null;
  $saldo = max(0, $r['saldo']);
  $pagada = ($r['estatus'] === 'Pagada');

  if ($saldo <= 0 || $pagada) continue;

  if ($vence) {
    $diffDays = (int)floor((strtotime($vence) - strtotime($hoy)) / 86400);
    if ($diffDays < 0) {
      $saldoVencido += $saldo;
      $daysOver = abs($diffDays);
      if     ($daysOver <= 30) $aging['d1_30']  += $saldo;
      elseif ($daysOver <= 60) $aging['d31_60'] += $saldo;
      elseif ($daysOver <= 90) $aging['d61_90'] += $saldo;
      else                     $aging['d90p']   += $saldo;

      $vencidas[] = $r + ['dias' => -$diffDays];
    } else {
      if ($diffDays <= $pxdias) {
        $saldoPorVencer += $saldo;
        $porVencer[] = $r + ['dias' => $diffDays];
      }
      $aging['current'] += $saldo;
    }
  } else {
    $aging['current'] += $saldo;
  }
}

usort($vencidas, fn($a,$b)=> $b['dias'] <=> $a['dias']);
usort($porVencer, fn($a,$b)=> $a['dias'] <=> $b['dias']);

$saldoPorProveedor = [];
foreach ($rows as $r) {
  $saldo = max(0, (float)$r['saldo']);
  if ($r['estatus'] === 'Pagada' || $saldo <= 0) continue;
  $pid = (int)$r['id_proveedor'];
  $saldoPorProveedor[$pid]['proveedor'] = $r['proveedor'];
  $saldoPorProveedor[$pid]['saldo'] = ($saldoPorProveedor[$pid]['saldo'] ?? 0) + $saldo;
}
usort($saldoPorProveedor, function($a,$b){ return ($b['saldo'] ?? 0) <=> ($a['saldo'] ?? 0); });
$topProv = array_slice($saldoPorProveedor, 0, 5, true);
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<div class="container my-4">
  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
    <h3 class="mb-2">Resumen de compras</h3>
    <div>
      <a href="compras_nueva.php" class="btn btn-sm btn-primary">+ Nueva compra</a>
    </div>
  </div>

  <!-- Filtros -->
  <div class="card shadow-sm mb-3">
    <div class="card-header">Filtros</div>
    <div class="card-body">
      <form class="row g-2">
        <div class="col-md-2">
          <label class="form-label">Estatus</label>
          <select name="estado" class="form-select" onchange="this.form.submit()">
            <?php
              $estados = ['todos'=>'Todos','Pendiente'=>'Pendiente','Parcial'=>'Parcial','Pagada'=>'Pagada','Cancelada'=>'Cancelada'];
              foreach ($estados as $val=>$txt): ?>
              <option value="<?= $val ?>" <?= $estado===$val?'selected':'' ?>><?= $txt ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Proveedor</label>
          <select name="proveedor" class="form-select">
            <option value="0">Todos</option>
            <?php if($proveedores) while($p=$proveedores->fetch_assoc()): ?>
              <option value="<?= (int)$p['id'] ?>" <?= $prov_id===(int)$p['id']?'selected':'' ?>>
                <?= esc($p['nombre']) ?>
              </option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Sucursal</label>
          <select name="sucursal" class="form-select">
            <option value="0">Todas</option>
            <?php if($sucursales) while($s=$sucursales->fetch_assoc()): ?>
              <option value="<?= (int)$s['id'] ?>" <?= $suc_id===(int)$s['id']?'selected':'' ?>>
                <?= esc($s['nombre']) ?>
              </option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Desde</label>
          <input type="date" name="desde" value="<?= esc($desde) ?>" class="form-control">
        </div>
        <div class="col-md-2">
          <label class="form-label">Hasta</label>
          <input type="date" name="hasta" value="<?= esc($hasta) ?>" class="form-control">
        </div>
        <div class="col-md-3">
          <label class="form-label"># Factura</label>
          <input type="text" name="q" value="<?= esc($q) ?>" class="form-control" placeholder="Buscar por número">
        </div>
        <div class="col-md-2">
          <label class="form-label">Próximos (días)</label>
          <input type="number" name="px" min="0" step="1" value="<?= (int)$pxdias ?>" class="form-control">
        </div>
        <div class="col-md-2 d-flex align-items-end">
          <button class="btn btn-outline-primary w-100">Aplicar</button>
        </div>
      </form>
    </div>
  </div>

  <!-- KPIs -->
  <div class="row g-3 mb-3">
    <div class="col-md-3">
      <div class="card shadow-sm border-0">
        <div class="card-body">
          <div class="text-muted">Total compras</div>
          <div class="fs-4 fw-bold">$<?= n2($totalCompras) ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card shadow-sm border-0">
        <div class="card-body">
          <div class="text-muted">Pagado</div>
          <div class="fs-4 fw-bold text-success">$<?= n2($totalPagado) ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card shadow-sm border-0">
        <div class="card-body">
          <div class="text-muted">Saldo</div>
          <div class="fs-4 fw-bold text-primary">$<?= n2($totalSaldo) ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card shadow-sm border-0">
        <div class="card-body">
          <div class="text-muted">Vencido</div>
          <div class="fs-4 fw-bold text-danger">$<?= n2($saldoVencido) ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Aging + Por vencer + Top proveedores -->
  <div class="row g-3 mb-3">
    <div class="col-lg-4">
      <div class="card shadow-sm h-100">
        <div class="card-header">Aging de saldos</div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead>
                <tr><th>Rango</th><th class="text-end">Saldo</th></tr>
              </thead>
              <tbody>
                <tr><td>Current (no vencido)</td><td class="text-end">$<?= n2($aging['current']) ?></td></tr>
                <tr><td>1–30 días vencido</td><td class="text-end">$<?= n2($aging['d1_30']) ?></td></tr>
                <tr><td>31–60 días vencido</td><td class="text-end">$<?= n2($aging['d31_60']) ?></td></tr>
                <tr><td>61–90 días vencido</td><td class="text-end">$<?= n2($aging['d61_90']) ?></td></tr>
                <tr class="table-danger"><td>&gt; 90 días vencido</td><td class="text-end">$<?= n2($aging['d90p']) ?></td></tr>
              </tbody>
              <tfoot>
                <tr class="table-light"><th>Total</th><th class="text-end">$<?= n2(array_sum($aging)) ?></th></tr>
              </tfoot>
            </table>
          </div>
          <div class="small text-muted">Solo considera facturas con saldo &gt; 0 y estatus distinto a “Pagada”.</div>
        </div>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="card shadow-sm h-100">
        <div class="card-header d-flex justify-content-between">
          <span>Próximas a vencer (≤ <?= (int)$pxdias ?> días)</span>
          <span class="text-primary fw-semibold">$<?= n2($saldoPorVencer) ?></span>
        </div>
        <div class="card-body">
          <?php if (count($porVencer)): ?>
            <div class="table-responsive">
              <table class="table table-sm table-hover align-middle">
                <thead>
                  <tr><th>Proveedor</th><th>Factura</th><th>Vence</th><th class="text-end">Saldo</th><th class="text-center">Días</th><th></th></tr>
                </thead>
                <tbody>
                  <?php foreach (array_slice($porVencer, 0, 8) as $r): ?>
                    <tr>
                      <td><?= esc($r['proveedor']) ?></td>
                      <td><?= esc($r['num_factura']) ?></td>
                      <td><?= esc($r['fecha_vencimiento']) ?></td>
                      <td class="text-end">$<?= n2($r['saldo']) ?></td>
                      <td class="text-center"><?= (int)$r['dias'] ?></td>
                      <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="compras_ver.php?id=<?= (int)$r['id'] ?>">Ver</a></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="text-muted">No hay facturas por vencer en este rango.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-3">
      <div class="card shadow-sm h-100">
        <div class="card-header">Top proveedores por saldo</div>
        <div class="card-body">
          <?php if (count($topProv)): ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($topProv as $tp): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                  <span><?= esc($tp['proveedor']) ?></span>
                  <span class="fw-semibold">$<?= n2($tp['saldo']) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div class="text-muted">Sin saldos pendientes.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Tabla principal -->
  <div class="card shadow-sm">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead>
            <tr>
              <th>#</th>
              <th>Proveedor</th>
              <th>Factura</th>
              <th>Sucursal</th>
              <th>Fecha</th>
              <th>Vence</th>
              <th class="text-end">Total</th>
              <th class="text-end">Pagado</th>
              <th class="text-end">Saldo</th>
              <th class="text-center">Pend. ingreso</th>
              <th class="text-center">Estatus</th>
              <th class="text-end">Acciones</th>
            </tr>
          </thead>
          <tbody>
          <?php
          $i=1;
          foreach ($rows as $r):
            $saldo = (float)$r['saldo'];
            $vence = $r['fecha_vencimiento'];
            $rowClass = '';
            if ($r['estatus'] !== 'Pagada' && $saldo > 0 && $vence) {
              if ($vence < $hoy) $rowClass = 'table-danger';
              else {
                if ($vence <= date('Y-m-d', strtotime("+$pxdias days"))) $rowClass = 'table-warning';
              }
            }
          ?>
            <tr class="<?= $rowClass ?>">
              <td><?= $i++ ?></td>
              <td><?= esc($r['proveedor']) ?></td>
              <td><?= esc($r['num_factura']) ?></td>
              <td><?= esc($r['sucursal']) ?></td>
              <td><?= esc($r['fecha_factura']) ?></td>
              <td><?= esc($vence ?: '-') ?></td>
              <td class="text-end">$<?= n2($r['total']) ?></td>
              <td class="text-end">$<?= n2($r['pagado']) ?></td>
              <td class="text-end fw-semibold">$<?= n2($saldo) ?></td>
              <td class="text-center">
                <?php if ((int)$r['pendientes_ingreso'] > 0): ?>
                  <span class="badge bg-warning text-dark"><?= (int)$r['pendientes_ingreso'] ?></span>
                <?php else: ?>
                  <span class="badge bg-success">0</span>
                <?php endif; ?>
              </td>
              <td class="text-center">
                <?php
                  $badge = 'secondary';
                  if ($r['estatus']==='Pagada') $badge='success';
                  elseif ($r['estatus']==='Parcial') $badge='warning text-dark';
                  elseif ($r['estatus']==='Pendiente') $badge='danger';
                ?>
                <span class="badge bg-<?= $badge ?>"><?= esc($r['estatus']) ?></span>
              </td>
              <td class="text-end">
                <div class="btn-group">
                  <a class="btn btn-sm btn-outline-secondary" href="compras_ver.php?id=<?= (int)$r['id'] ?>">Ver</a>
                  <a class="btn btn-sm btn-success" href="compras_pagos.php?id=<?= (int)$r['id'] ?>">Abonar</a>
                  <?php if ((int)$r['pendientes_ingreso'] > 0 && (int)$r['primer_detalle_pendiente'] > 0): ?>
                    <a class="btn btn-sm btn-primary"
                       href="compras_ingreso.php?detalle=<?= (int)$r['primer_detalle_pendiente'] ?>&compra=<?= (int)$r['id'] ?>">
                       Ingresar
                    </a>
                  <?php else: ?>
                    <button class="btn btn-sm btn-outline-secondary" disabled>Ingresar</button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<!-- 🔹 Forzar título de la pestaña al final para que prevalezca -->
<script>
  (function () {
    try { document.title = 'Resumen · Compras — Central2.0'; } catch(e) {}
  })();
</script>
