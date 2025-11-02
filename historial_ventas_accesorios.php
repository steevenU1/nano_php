<?php
// historial_ventas_accesorios.php — Listado + Export CSV (por renglón si hay tabla de detalle)
// Detecta si existe detalle_venta_accesorios; si no, cae a export simple por venta.

session_start();
if (!isset($_SESSION['id_usuario'])) { header('Location: index.php'); exit(); }

require_once __DIR__.'/db.php';
date_default_timezone_set('America/Mexico_City');

$ROL         = $_SESSION['rol'] ?? '';
$ID_USUARIO  = (int)($_SESSION['id_usuario'] ?? 0);
$ID_SUCURSAL = (int)($_SESSION['id_sucursal'] ?? 0);

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function money($n){ return '$'.number_format((float)$n, 2, '.', ','); }
function column_exists(mysqli $conn, string $table, string $column): bool {
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
  $st = $conn->prepare($sql); if (!$st) return false;
  $st->bind_param('ss', $table, $column);
  $st->execute();
  $ok = (bool)$st->get_result()->fetch_row();
  $st->close();
  return $ok;
}
function table_exists(mysqli $conn, string $table): bool {
  $st = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? LIMIT 1");
  if (!$st) return false;
  $st->bind_param('s',$table); $st->execute();
  $ok = (bool)$st->get_result()->fetch_row();
  $st->close();
  return $ok;
}

/* Columna de fecha */
$DATE_COL = 'created_at';
foreach (['created_at','fecha_venta','fecha','fecha_registro'] as $c) {
  if (column_exists($conn, 'ventas_accesorios', $c)) { $DATE_COL = $c; break; }
}

/* Alcance por rol */
$scopeWhere=[]; $scopeParams=[]; $scopeTypes='';
switch ($ROL) {
  case 'Ejecutivo': $scopeWhere[]='v.id_usuario = ?';  $scopeParams[]=$ID_USUARIO;  $scopeTypes.='i'; break;
  case 'Gerente'  : $scopeWhere[]='v.id_sucursal = ?'; $scopeParams[]=$ID_SUCURSAL; $scopeTypes.='i'; break;
}

/* Filtros */
$hoy = date('Y-m-d'); $inicioDefault = date('Y-m-01');
$fecha_ini = $_GET['fecha_ini'] ?? $inicioDefault;
$fecha_fin = $_GET['fecha_fin'] ?? $hoy;
$q         = trim($_GET['q'] ?? '');
$forma     = $_GET['forma'] ?? '';
$orden     = $_GET['orden'] ?? 'fecha_desc';
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = min(100, max(10, (int)($_GET['per'] ?? 20)));
$export    = isset($_GET['export']) && $_GET['export']=='1';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$fecha_ini)) $fecha_ini = $inicioDefault;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$fecha_fin)) $fecha_fin = $hoy;
$fecha_fin_inclusive = $fecha_fin.' 23:59:59';

$filtrosWhere=[]; $filtrosParams=[]; $filtrosTypes='';
if ($fecha_ini) { $filtrosWhere[]="v.`$DATE_COL` >= ?"; $filtrosParams[]=$fecha_ini.' 00:00:00'; $filtrosTypes.='s'; }
if ($fecha_fin) { $filtrosWhere[]="v.`$DATE_COL` <= ?"; $filtrosParams[]=$fecha_fin_inclusive; $filtrosTypes.='s'; }
if ($forma !== '' && in_array($forma,['Efectivo','Tarjeta','Mixto'],true)) {
  $filtrosWhere[]='v.forma_pago = ?'; $filtrosParams[]=$forma; $filtrosTypes.='s';
}
if ($q !== '') {
  $filtrosWhere[]="(v.tag LIKE ? OR v.nombre_cliente LIKE ? OR v.telefono LIKE ? OR u.nombre LIKE ? OR s.nombre LIKE ?)";
  $like='%'.$q.'%'; array_push($filtrosParams,$like,$like,$like,$like,$like); $filtrosTypes.='sssss';
}

/* WHERE común */
$where = []; $params=[]; $types='';
if ($scopeWhere){ $where[]='('.implode(' AND ',$scopeWhere).')'; $params=array_merge($params,$scopeParams); $types.=$scopeTypes; }
if ($filtrosWhere){ $where[]='('.implode(' AND ',$filtrosWhere).')'; $params=array_merge($params,$filtrosParams); $types.=$filtrosTypes; }
$whereSQL = $where ? ('WHERE '.implode(' AND ',$where)) : '';

/* Orden */
$ordenMap = [
  'fecha_desc'=>"v.`$DATE_COL` DESC", 'fecha_asc'=>"v.`$DATE_COL` ASC",
  'total_desc'=>"v.total DESC", 'total_asc'=>"v.total ASC",
  'cliente_asc'=>"v.nombre_cliente ASC", 'cliente_desc'=>"v.nombre_cliente DESC"
];
$orderBy = $ordenMap[$orden] ?? $ordenMap['fecha_desc'];

/* Joins base (sin WHERE) */
$joinsBase = "
  LEFT JOIN usuarios   u ON u.id = v.id_usuario
  LEFT JOIN sucursales s ON s.id = v.id_sucursal
";

/* ¿Existe la tabla de detalle? */
$DETALLE_TAB = null;
foreach (['detalle_venta_accesorios','detalle_venta_accesorio','detalle_venta_acc','detalle_venta'] as $cand) {
  if (table_exists($conn, $cand) && column_exists($conn, $cand, 'id_venta') && column_exists($conn, $cand, 'id_producto')) {
    $DETALLE_TAB = $cand; break;
  }
}

/* ========= EXPORT CSV ========= */
if ($export) {
  while (ob_get_level()) { ob_end_clean(); }
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="ventas_accesorios'.($DETALLE_TAB?'_detalle':'').'.csv"');
  $out = fopen('php://output','w'); fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

  if ($DETALLE_TAB) {
    // Export por renglón (una fila por accesorio)
    fputcsv($out, ['Folio','TAG','Cliente','Teléfono','Usuario','Sucursal','Forma de pago',
                   'Accesorio','Cantidad','Precio unitario','Subtotal renglón','Total venta','Fecha']);
    $csvSQL = "
      SELECT 
        v.id AS folio, v.tag, v.nombre_cliente, v.telefono,
        COALESCE(u.nombre, CONCAT('Usuario #',v.id_usuario))   AS usuario,
        COALESCE(s.nombre, CONCAT('Sucursal #',v.id_sucursal)) AS sucursal,
        v.forma_pago, v.total AS total_venta, v.`$DATE_COL` AS fecha,
        d.id_producto, d.cantidad, d.precio_unitario,
        (d.cantidad * d.precio_unitario) AS subtotal_renglon,
        TRIM(CONCAT(p.marca,' ',p.modelo,' ',COALESCE(p.color,''))) AS accesorio
      FROM ventas_accesorios v
      $joinsBase
      INNER JOIN {$DETALLE_TAB} d ON d.id_venta = v.id
      LEFT JOIN productos p ON p.id = d.id_producto
      $whereSQL
      ORDER BY $orderBy, v.id ASC, d.id ASC
    ";
    $st = $conn->prepare($csvSQL);
    if ($types!=='') $st->bind_param($types, ...$params);
    $st->execute(); $rs = $st->get_result();
    while ($r = $rs->fetch_assoc()) {
      fputcsv($out, [
        $r['folio'], $r['tag'], $r['nombre_cliente'], $r['telefono'],
        $r['usuario'], $r['sucursal'], $r['forma_pago'],
        $r['accesorio'] ?: '—',
        number_format((float)$r['cantidad'],0,'.',''),
        number_format((float)$r['precio_unitario'],2,'.',''),
        number_format((float)$r['subtotal_renglon'],2,'.',''),
        number_format((float)$r['total_venta'],2,'.',''),
        $r['fecha']
      ]);
    }
    fclose($out); exit;
  } else {
    // Export simple por venta (no truena si detalle no existe)
    fputcsv($out, ['Folio','TAG','Cliente','Teléfono','Usuario','Sucursal','Forma de pago','Total venta','Fecha',
                   'Accesorio','Cantidad','Precio unitario','Subtotal renglón']);
    $csvSQL = "
      SELECT v.id AS folio, v.tag, v.nombre_cliente, v.telefono,
             COALESCE(u.nombre, CONCAT('Usuario #',v.id_usuario))   AS usuario,
             COALESCE(s.nombre, CONCAT('Sucursal #',v.id_sucursal)) AS sucursal,
             v.forma_pago, v.total AS total_venta, v.`$DATE_COL` AS fecha
      FROM ventas_accesorios v
      $joinsBase
      $whereSQL
      ORDER BY $orderBy, v.id ASC
    ";
    $st = $conn->prepare($csvSQL);
    if ($types!=='') $st->bind_param($types, ...$params);
    $st->execute(); $rs = $st->get_result();
    while ($r = $rs->fetch_assoc()) {
      fputcsv($out, [
        $r['folio'], $r['tag'], $r['nombre_cliente'], $r['telefono'],
        $r['usuario'], $r['sucursal'], $r['forma_pago'],
        number_format((float)$r['total_venta'],2,'.',''), $r['fecha'],
        '—', 0, number_format(0,2,'.',''), number_format(0,2,'.','')
      ]);
    }
    fclose($out); exit;
  }
}
/* ========= FIN EXPORT ========= */

/* Listado UI */
$sqlBase = "FROM ventas_accesorios v $joinsBase $whereSQL";
$countSQL = "SELECT COUNT(*) AS n ".$sqlBase;
$st = $conn->prepare($countSQL);
if ($types!=='') $st->bind_param($types, ...$params);
$st->execute(); $totalRows = (int)($st->get_result()->fetch_assoc()['n'] ?? 0);

$offset = ($page-1)*$perPage;
$listSQL = "
  SELECT v.id, v.tag, v.nombre_cliente, v.telefono, v.forma_pago, v.total, v.`$DATE_COL` AS fecha,
         COALESCE(u.nombre, CONCAT('Usuario #',v.id_usuario))  AS usuario_nombre,
         COALESCE(s.nombre, CONCAT('Sucursal #',v.id_sucursal)) AS sucursal_nombre
  $sqlBase ORDER BY $orderBy LIMIT ?, ?
";
$st2 = $conn->prepare($listSQL);
if ($types!=='') { $types2=$types.'ii'; $bind=array_merge($params,[$offset,$perPage]); $st2->bind_param($types2, ...$bind); }
else            { $st2->bind_param('ii', $offset, $perPage); }
$st2->execute(); $rows = $st2->get_result()->fetch_all(MYSQLI_ASSOC);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
function sel($a,$b){ return $a===$b?'selected':''; }

require_once __DIR__.'/navbar.php';
?>
<!doctype html>
<html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Historial · Ventas de Accesorios</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{background:#f5f7fb}.card-ghost{backdrop-filter:saturate(140%) blur(6px);border:1px solid #0001;box-shadow:0 10px 25px #0001}
.table thead th{position:sticky;top:0;background:#fff;z-index:1}.money{text-align:right}.kpi{font-size:.95rem}
.badge-soft{background:#6c757d14;border:1px solid #6c757d2e}.modal-xxl{--bs-modal-width:min(1100px,96vw)}
.ticket-frame{width:100%;height:80vh;border:0;border-radius:.75rem;background:#fff}
</style>
</head>
<body>
<div class="container py-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h3 class="mb-0">Historial · Ventas de Accesorios</h3>
      <?php $scopeText='Toda la compañía'; if($ROL==='Gerente') $scopeText='Sucursal'; if($ROL==='Ejecutivo') $scopeText='Mis ventas'; ?>
      <span class="badge rounded-pill text-secondary badge-soft"><?= h($scopeText) ?></span>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="venta_accesorios.php">Nueva venta</a>
      <a class="btn btn-outline-primary btn-sm" id="btnExport">Exportar CSV</a>
    </div>
  </div>

  <?php $frm_fecha_ini=$fecha_ini; $frm_fecha_fin=$fecha_fin; $frm_q=$q; $frm_forma=$forma; $frm_orden=$orden; $frm_per=$perPage; ?>

  <form class="card card-ghost p-3 mb-3" method="get" id="frmFiltros">
    <div class="row g-2 align-items-end">
      <div class="col-lg-2">
        <label class="form-label">Fecha inicial</label>
        <input type="date" name="fecha_ini" value="<?= h($frm_fecha_ini) ?>" class="form-control">
      </div>
      <div class="col-lg-2">
        <label class="form-label">Fecha final</label>
        <input type="date" name="fecha_fin" value="<?= h($frm_fecha_fin) ?>" class="form-control">
      </div>
      <div class="col-lg-3">
        <label class="form-label">Buscar</label>
        <input type="text" name="q" value="<?= h($frm_q) ?>" class="form-control" placeholder="TAG, cliente, teléfono, usuario, sucursal…">
      </div>
      <div class="col-lg-2">
        <label class="form-label">Forma de pago</label>
        <select class="form-select" name="forma">
          <option value="">Todas</option>
          <option value="Efectivo" <?= sel($frm_forma,'Efectivo')?> >Efectivo</option>
          <option value="Tarjeta"  <?= sel($frm_forma,'Tarjeta')?> >Tarjeta</option>
          <option value="Mixto"    <?= sel($frm_forma,'Mixto')?> >Mixto</option>
        </select>
      </div>
      <div class="col-lg-2">
        <label class="form-label">Orden</label>
        <select class="form-select" name="orden">
          <option value="fecha_desc"  <?= sel($frm_orden,'fecha_desc')?>>Fecha ↓</option>
          <option value="fecha_asc"   <?= sel($frm_orden,'fecha_asc')?>>Fecha ↑</option>
          <option value="total_desc"  <?= sel($frm_orden,'total_desc')?>>Total ↓</option>
          <option value="total_asc"   <?= sel($frm_orden,'total_asc')?>>Total ↑</option>
          <option value="cliente_asc" <?= sel($frm_orden,'cliente_asc')?>>Cliente A-Z</option>
          <option value="cliente_desc"<?= sel($frm_orden,'cliente_desc')?>>Cliente Z-A</option>
        </select>
      </div>
      <div class="col-lg-1">
        <label class="form-label">Por pág.</label>
        <input type="number" name="per" value="<?= (int)$frm_per ?>" min="10" max="100" class="form-control">
      </div>
      <div class="col-lg-12 mt-2">
        <button class="btn btn-primary">Aplicar</button>
        <a class="btn btn-outline-secondary" href="historial_ventas_accesorios.php">Limpiar</a>
      </div>
    </div>
  </form>

  <div class="card card-ghost">
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Folio</th><th>TAG</th><th>Cliente</th><th>Teléfono</th>
            <th>Usuario</th><th>Sucursal</th><th>Forma</th>
            <th class="text-end">Total</th><th>Fecha</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="10" class="text-center text-muted py-4">Sin resultados con los filtros actuales.</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= h($r['tag']) ?></td>
              <td><?= h($r['nombre_cliente']) ?></td>
              <td><?= h($r['telefono']) ?></td>
              <td><?= h($r['usuario_nombre']) ?></td>
              <td><?= h($r['sucursal_nombre']) ?></td>
              <td><span class="badge text-bg-light"><?= h($r['forma_pago']) ?></span></td>
              <td class="text-end"><?= money($r['total']) ?></td>
              <td><?= h(date('d/m/Y H:i', strtotime($r['fecha']))) ?></td>
              <td class="text-end"><a class="btn btn-outline-primary btn-sm btnTicket" data-id="<?= (int)$r['id'] ?>">Ver ticket</a></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="d-flex justify-content-between align-items-center p-3">
      <div class="kpi text-muted">Mostrando <?= (int)min($totalRows, $offset + count($rows)) ?> de <?= (int)$totalRows ?> ventas</div>
      <nav><ul class="pagination pagination-sm mb-0">
        <?php $qs=$_GET; unset($qs['page'],$qs['export']); $baseQS=http_build_query($qs); $mk=fn($p)=>'?'.$baseQS.'&page='.$p;
              $start=max(1,$page-2); $end=min($totalPages,$page+2); ?>
        <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="<?= h($mk(max(1,$page-1))) ?>">«</a></li>
        <?php for($i=$start;$i<=$end;$i++): ?>
          <li class="page-item <?= $i===$page?'active':'' ?>"><a class="page-link" href="<?= h($mk($i)) ?>"><?= $i ?></a></li>
        <?php endfor; ?>
        <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>"><a class="page-link" href="<?= h($mk(min($totalPages,$page+1))) ?>">»</a></li>
      </ul></nav>
    </div>
    <?php else: ?><div class="p-3 text-muted kpi">Total: <?= (int)$totalRows ?> ventas</div><?php endif; ?>
  </div>
</div>

<div class="modal fade" id="ticketModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xxl">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Ticket de venta</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body"><iframe id="ticketFrame" class="ticket-frame" src="about:blank"></iframe></div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
        <button class="btn btn-primary" id="btnPrintTicket">Imprimir</button>
      </div>
    </div>
  </div>
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
document.querySelectorAll('.btnTicket').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const id = btn.dataset.id;
    const frame = document.getElementById('ticketFrame');
    frame.src = 'venta_accesorios_ticket.php?id=' + encodeURIComponent(id);
    new bootstrap.Modal(document.getElementById('ticketModal')).show();
  });
});
document.getElementById('btnPrintTicket').addEventListener('click', ()=>{
  const f = document.getElementById('ticketFrame'); try{f.contentWindow.focus(); f.contentWindow.print();}catch(e){}
});
document.getElementById('btnExport').addEventListener('click', ()=>{
  const frm = document.getElementById('frmFiltros');
  const params = new URLSearchParams(new FormData(frm)); params.set('export','1');
  window.location.href = window.location.pathname + '?' + params.toString();
});
</script>
</body></html>
