<?php
// traspaso_directo_ma.php
// Admin lista inventario de "Almacen Angelopolis", arma carrito y mueve directo a sucursales Master Admin (sin aceptación)

session_start();
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
    header("Location: 403.php");
    exit();
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include 'db.php';

// ==== helpers ====
function tableExists(mysqli $conn, string $table) {
    $t = $conn->real_escape_string($table);
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t' LIMIT 1";
    $res = $conn->query($sql);
    return $res && $res->num_rows > 0;
}
function columnExists(mysqli $conn, string $table, string $column) {
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t' AND COLUMN_NAME = '$c' LIMIT 1";
    $res = $conn->query($sql);
    return $res && $res->num_rows > 0;
}

$MSG = '';
$TYPE = 'info';
$resultados = [];
$exitos = 0; $fallas = 0;

// === Config/Resolución de IDs ===
$NOMBRE_FUENTE = 'Almacen Angelopolis';
$stmtSrc = $conn->prepare("SELECT id FROM sucursales WHERE nombre=? LIMIT 1");
$stmtSrc->bind_param("s", $NOMBRE_FUENTE);
$stmtSrc->execute();
$idFuente = ($stmtSrc->get_result()->fetch_assoc()['id'] ?? 0);
$stmtSrc->close();

if ($idFuente <= 0) {
    die("<div style='padding:20px;font-family:system-ui'><b>ERROR:</b> No se encontró la sucursal fuente '<i>$NOMBRE_FUENTE</i>' en la tabla <code>sucursales</code>. Ajusta el nombre o crea la sucursal.</div>");
}

// Destinos Master Admin
$masterAdmins = [];
$qMA = $conn->query("SELECT id, nombre FROM sucursales WHERE subtipo='Master Admin' ORDER BY nombre");
while ($r = $qMA->fetch_assoc()) { $masterAdmins[] = $r; }
$qMA && $qMA->close();

// Bitácora opcional
$LOG_HDR = tableExists($conn, 'traspasos_directos');
$LOG_DET = tableExists($conn, 'traspasos_directos_det');

// ¿Existe fecha_actualizacion?
$HAS_FECHA_ACT = columnExists($conn, 'inventario', 'fecha_actualizacion');

// ==== Ejecutar traspaso (carrito) ====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'mover') {
    $destino = (int)($_POST['destino'] ?? 0);
    $cartIds = array_map('intval', $_POST['cart_ids'] ?? []);

    // Validar destino está en MA
    $destinoValido = false; $nombreDestino = '';
    foreach ($masterAdmins as $s) { if ((int)$s['id'] === $destino) { $destinoValido = true; $nombreDestino = $s['nombre']; break; } }

    if (!$destinoValido) {
        $TYPE = 'danger'; $MSG = '❌ Selecciona una sucursal destino válida (Master Admin).';
    } elseif (empty($cartIds)) {
        $TYPE = 'warning'; $MSG = '⚠️ El carrito está vacío.';
    } else {
        $conn->begin_transaction();

        // Crear encabezado log si hay tabla
        $idLog = null;
        if ($LOG_HDR) {
            $stmtLog = $conn->prepare("INSERT INTO traspasos_directos (fecha, id_usuario, id_sucursal_destino, observaciones) VALUES (NOW(), ?, ?, ?)");
            $obs = 'Traspaso directo desde '.$NOMBRE_FUENTE;
            $stmtLog->bind_param("iis", $_SESSION['id_usuario'], $destino, $obs);
            if ($stmtLog->execute()) $idLog = $stmtLog->insert_id;
            $stmtLog->close();
        }

        $stmtGet = $conn->prepare("
            SELECT i.id, i.id_sucursal, i.estatus, p.imei1, p.marca, p.modelo, p.color, p.capacidad
            FROM inventario i
            INNER JOIN productos p ON p.id=i.id_producto
            WHERE i.id=? AND i.id_sucursal=? AND i.estatus='Disponible'
            LIMIT 1
        ");
        // UPDATE dinámico según exista o no la columna fecha_actualizacion
        if ($HAS_FECHA_ACT) {
            $stmtUpd = $conn->prepare("UPDATE inventario SET id_sucursal=?, fecha_actualizacion=NOW() WHERE id=? AND estatus='Disponible'");
        } else {
            $stmtUpd = $conn->prepare("UPDATE inventario SET id_sucursal=? WHERE id=? AND estatus='Disponible'");
        }

        $stmtLogDet = null;
        if ($LOG_DET && $idLog) {
            $stmtLogDet = $conn->prepare("INSERT INTO traspasos_directos_det (id_traspaso, id_inventario, id_sucursal_origen, id_sucursal_destino, imei, resultado, detalle) VALUES (?, ?, ?, ?, ?, ?, ?)");
        }

        foreach ($cartIds as $idInv) {
            $stmtGet->bind_param("ii", $idInv, $idFuente);
            $stmtGet->execute();
            $res = $stmtGet->get_result();
            $row = $res->fetch_assoc();
            $res->free_result();

            if (!$row) {
                $fallas++;
                $resultados[] = ['id'=>$idInv, 'resultado'=>'FALLÓ', 'detalle'=>'No está Disponible o no pertenece al almacén'];
                if ($stmtLogDet && $idLog) {
                    $resu='FALLÓ'; $det='No está Disponible o no pertenece al almacén';
                    $imeiTmp = '';
                    $stmtLogDet->bind_param("iiiisss", $idLog, $idInv, $idFuente, $destino, $imeiTmp, $resu, $det);
                    $stmtLogDet->execute();
                }
                continue;
            }

            $stmtUpd->bind_param("ii", $destino, $idInv);
            if ($stmtUpd->execute() && $stmtUpd->affected_rows > 0) {
                $exitos++;
                $resultados[] = ['id'=>$idInv, 'resultado'=>'OK', 'detalle'=>"Movido a $nombreDestino (IMEI: ".$row['imei1'].")"];
                if ($stmtLogDet && $idLog) {
                    $resu='OK'; $det="Movido a $nombreDestino";
                    $stmtLogDet->bind_param("iiiisss", $idLog, $idInv, $idFuente, $destino, $row['imei1'], $resu, $det);
                    $stmtLogDet->execute();
                }
            } else {
                $fallas++;
                $resultados[] = ['id'=>$idInv, 'resultado'=>'FALLÓ', 'detalle'=>'No se pudo actualizar inventario'];
                if ($stmtLogDet && $idLog) {
                    $resu='FALLÓ'; $det='No se pudo actualizar inventario';
                    $stmtLogDet->bind_param("iiiisss", $idLog, $idInv, $idFuente, $destino, $row['imei1'], $resu, $det);
                    $stmtLogDet->execute();
                }
            }
        }

        $stmtGet->close();
        $stmtUpd->close();
        if ($stmtLogDet) $stmtLogDet->close();

        $conn->commit();
        $TYPE = $fallas ? 'warning' : 'success';
        $MSG = "✅ Traspaso finalizado. Exitosos: <b>$exitos</b> · Fallidos: <b>$fallas</b>";
    }
}

// ==== Listado (con buscador/paginación) ====
$porPagina = 200;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $porPagina;

$qtxt = trim($_GET['q'] ?? '');
$params = [];
$where = "i.id_sucursal=? AND i.estatus='Disponible'";
$params[] = $idFuente;

$types = "i";
if ($qtxt !== '') {
    $where .= " AND (p.marca LIKE ? OR p.modelo LIKE ? OR p.color LIKE ? OR p.capacidad LIKE ? OR p.imei1 LIKE ? OR p.imei2 LIKE ? OR p.codigo_producto LIKE ?)";
    for ($i=0; $i<7; $i++) { $params[] = "%$qtxt%"; }
    $types .= "sssssss";
}

$sqlCount = "SELECT COUNT(*) AS t
             FROM inventario i
             INNER JOIN productos p ON p.id=i.id_producto
             WHERE $where";
$stmtCount = $conn->prepare($sqlCount);
$stmtCount->bind_param($types, ...$params);
$stmtCount->execute();
$total = (int)($stmtCount->get_result()->fetch_assoc()['t'] ?? 0);
$stmtCount->close();

$totalPages = max(1, (int)ceil($total / $porPagina));

$sqlList = "SELECT i.id AS id_inv, i.fecha_ingreso, p.codigo_producto, p.marca, p.modelo, p.color, p.capacidad, p.imei1, p.imei2
            FROM inventario i
            INNER JOIN productos p ON p.id=i.id_producto
            WHERE $where
            ORDER BY i.fecha_ingreso DESC, i.id DESC
            LIMIT ? OFFSET ?";
$stmtList = $conn->prepare($sqlList);
$bindTypes = $types . "ii";
$paramsList = $params;
$paramsList[] = $porPagina;
$paramsList[] = $offset;
$stmtList->bind_param($bindTypes, ...$paramsList);
$stmtList->execute();
$rs = $stmtList->get_result();
$rows = [];
while ($r = $rs->fetch_assoc()) { $rows[] = $r; }
$stmtList->close();

// ==== Historial (si hay tablas) ====
$histEnabled = $LOG_HDR && $LOG_DET;

$h_from = $_GET['from'] ?? date('Y-m-01');
$h_to   = $_GET['to']   ?? date('Y-m-d');
$h_dest = (int)($_GET['dest'] ?? 0);

$hist = [];
if ($histEnabled) {
    $whereH = "h.id = d.id_traspaso AND DATE(h.fecha) BETWEEN ? AND ?";
    $tH = "ss";
    $pH = [$h_from, $h_to];

    if ($h_dest > 0) { $whereH .= " AND h.id_sucursal_destino=?"; $tH .= "i"; $pH[] = $h_dest; }

    $sqlHist = "
        SELECT h.id, h.fecha, h.id_sucursal_destino, s.nombre AS destino, COUNT(d.id) AS piezas,
               SUM(d.resultado='OK') AS ok_cnt, SUM(d.resultado='FALLÓ') AS fail_cnt
        FROM traspasos_directos h
        JOIN traspasos_directos_det d ON d.id_traspaso=h.id
        LEFT JOIN sucursales s ON s.id=h.id_sucursal_destino
        WHERE $whereH
        GROUP BY h.id, h.fecha, h.id_sucursal_destino, s.nombre
        ORDER BY h.fecha DESC, h.id DESC
        LIMIT 300
    ";
    $stmtH = $conn->prepare($sqlHist);
    $stmtH->bind_param($tH, ...$pH);
    $stmtH->execute();
    $resH = $stmtH->get_result();
    while ($r = $resH->fetch_assoc()) { $hist[] = $r; }
    $stmtH->close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Traspaso directo desde <?= htmlspecialchars($NOMBRE_FUENTE) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body{background:#f8fafc}
  .card{border-radius:16px}
  .card-header{font-weight:600}
  table td, table th{vertical-align:middle}
  .mono{font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;}
  .cart-sticky{position:sticky; top:0; z-index:10}
</style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container my-4">
  <h2 class="fw-bold">🚚 Traspaso directo desde <span class="text-primary"><?= htmlspecialchars($NOMBRE_FUENTE) ?></span></h2>
  <p class="text-muted mb-3">Arma el <b>carrito</b> con equipos <b>Disponibles</b> y muévelos a una sucursal <em>Master Admin</em> (sin aceptación).</p>

  <?php if ($MSG): ?>
    <div class="alert alert-<?= htmlspecialchars($TYPE) ?> shadow-sm"><?= $MSG ?></div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-lg-8">
      <div class="card shadow-sm mb-3">
        <div class="card-header bg-dark text-white">Inventario en <?= htmlspecialchars($NOMBRE_FUENTE) ?></div>
        <div class="card-body">
          <form class="row g-2" method="GET" action="">
            <div class="col-md-6">
              <input type="text" name="q" value="<?= htmlspecialchars($qtxt) ?>" class="form-control" placeholder="Buscar marca, modelo, color, capacidad, IMEI o código...">
            </div>
            <div class="col-md-2">
              <button class="btn btn-outline-primary w-100" type="submit">🔎 Buscar</button>
            </div>
            <div class="col-md-2">
              <a class="btn btn-outline-secondary w-100" href="traspaso_directo_ma.php">Limpiar</a>
            </div>
            <div class="col-md-2 text-end">
              <span class="badge bg-secondary">Total: <?= number_format($total) ?></span>
            </div>
          </form>

          <div class="table-responsive mt-3">
            <table class="table table-hover table-bordered align-middle bg-white" id="tabla-inv">
              <thead class="table-light">
                <tr>
                  <th style="width:42px" class="text-center"><input type="checkbox" id="checkAll"></th>
                  <th>Código</th>
                  <th>Marca</th>
                  <th>Modelo</th>
                  <th>Color</th>
                  <th>Capacidad</th>
                  <th>IMEI1</th>
                  <th>IMEI2</th>
                  <th class="text-nowrap">Fecha ingreso</th>
                  <th style="width:70px"></th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($rows)): ?>
                <tr><td colspan="10" class="text-center text-muted">No hay equipos disponibles que coincidan con el filtro.</td></tr>
                <?php else: foreach ($rows as $r): ?>
                <tr data-id="<?= (int)$r['id_inv'] ?>"
                    data-codigo="<?= htmlspecialchars($r['codigo_producto']) ?>"
                    data-marca="<?= htmlspecialchars($r['marca']) ?>"
                    data-modelo="<?= htmlspecialchars($r['modelo']) ?>"
                    data-color="<?= htmlspecialchars($r['color']) ?>"
                    data-capacidad="<?= htmlspecialchars($r['capacidad']) ?>"
                    data-imei1="<?= htmlspecialchars($r['imei1']) ?>"
                    data-imei2="<?= htmlspecialchars($r['imei2']) ?>"
                    data-fecha="<?= htmlspecialchars($r['fecha_ingreso']) ?>">
                  <td class="text-center"><input type="checkbox" class="row-check"></td>
                  <td class="mono"><?= htmlspecialchars($r['codigo_producto']) ?></td>
                  <td><?= htmlspecialchars($r['marca']) ?></td>
                  <td><?= htmlspecialchars($r['modelo']) ?></td>
                  <td><?= htmlspecialchars($r['color']) ?></td>
                  <td><?= htmlspecialchars($r['capacidad']) ?></td>
                  <td class="mono"><?= htmlspecialchars($r['imei1']) ?></td>
                  <td class="mono"><?= htmlspecialchars($r['imei2']) ?></td>
                  <td><?= htmlspecialchars($r['fecha_ingreso']) ?></td>
                  <td><button type="button" class="btn btn-sm btn-outline-primary add-one">➕</button></td>
                </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>

          <div class="d-flex justify-content-between align-items-center mt-2">
            <div class="text-muted small">Mostrando <?= count($rows) ?> de <?= number_format($total) ?></div>
            <?php if ($totalPages > 1):
              $baseUrl = 'traspaso_directo_ma.php?q='.urlencode($qtxt).'&page=';
              $prev = max(1, $page-1); $next = min($totalPages, $page+1);
            ?>
            <nav>
              <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="<?= $baseUrl.$prev ?>">«</a></li>
                <?php $start=max(1,$page-2); $end=min($totalPages,$page+2); for($i=$start;$i<=$end;$i++): ?>
                  <li class="page-item <?= $i==$page?'active':'' ?>"><a class="page-link" href="<?= $baseUrl.$i ?>"><?= $i ?></a></li>
                <?php endfor; ?>
                <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>"><a class="page-link" href="<?= $baseUrl.$next ?>">»</a></li>
              </ul>
            </nav>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card shadow-sm cart-sticky">
        <div class="card-header bg-primary text-white">🛒 Carrito de traspaso <span class="badge bg-light text-dark ms-2" id="cart-count">0</span></div>
        <div class="card-body">
          <div class="mb-2 d-flex gap-2">
            <button class="btn btn-outline-secondary btn-sm" id="add-selected">Agregar seleccionados</button>
            <button class="btn btn-outline-danger btn-sm" id="clear-cart">Vaciar</button>
          </div>
          <div class="table-responsive" style="max-height:320px; overflow:auto">
            <table class="table table-sm table-bordered" id="cart-table">
              <thead class="table-light">
                <tr>
                  <th>Modelo</th>
                  <th>IMEI</th>
                  <th style="width:42px"></th>
                </tr>
              </thead>
              <tbody></tbody>
            </table>
          </div>
          <hr>
          <form method="POST" id="form-mover">
            <input type="hidden" name="accion" value="mover">
            <div id="cart-hidden"></div>
            <div class="mb-3">
              <label class="form-label">Destino (Master Admin) <span class="text-danger">*</span></label>
              <select name="destino" class="form-select" required>
                <option value="">— Selecciona destino —</option>
                <?php foreach ($masterAdmins as $s): ?>
                  <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="submit" class="btn btn-success w-100">🚀 Traspasar <span id="cart-count-submit">0</span> equipos</button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- Historial -->
  <div class="card shadow-sm mt-4">
    <div class="card-header">🕑 Historial de traspasos directos</div>
    <div class="card-body">
      <?php if (!$histEnabled): ?>
        <div class="alert alert-info">ℹ️ El historial requiere las tablas <code>traspasos_directos</code> y <code>traspasos_directos_det</code>. Si aún no existen, la operación de traspaso funciona sin bitácora.</div>
      <?php else: ?>
        <form class="row g-2 mb-3" method="GET">
          <div class="col-md-3">
            <label class="form-label">Desde</label>
            <input type="date" class="form-control" name="from" value="<?= htmlspecialchars($h_from) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Hasta</label>
            <input type="date" class="form-control" name="to" value="<?= htmlspecialchars($h_to) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Destino</label>
            <select class="form-select" name="dest">
              <option value="0">Todos</option>
              <?php foreach ($masterAdmins as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= $h_dest===(int)$s['id']?'selected':'' ?>><?= htmlspecialchars($s['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2 d-flex align-items-end">
            <button class="btn btn-outline-primary w-100" type="submit">Filtrar</button>
          </div>
        </form>

        <div class="table-responsive">
          <table class="table table-sm table-bordered align-middle">
            <thead class="table-light">
              <tr>
                <th>ID</th>
                <th>Fecha</th>
                <th>Destino</th>
                <th>Piezas</th>
                <th>OK</th>
                <th>Falló</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($hist)): ?>
                <tr><td colspan="6" class="text-center text-muted">Sin registros para el filtro.</td></tr>
              <?php else: foreach ($hist as $h): ?>
                <tr>
                  <td class="mono"><?= (int)$h['id'] ?></td>
                  <td><?= htmlspecialchars($h['fecha']) ?></td>
                  <td><?= htmlspecialchars($h['destino'] ?? ('#'.$h['id_sucursal_destino'])) ?></td>
                  <td><?= (int)$h['piezas'] ?></td>
                  <td class="text-success"><?= (int)$h['ok_cnt'] ?></td>
                  <td class="text-danger"><?= (int)$h['fail_cnt'] ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($resultados)): ?>
    <div class="card shadow-sm mt-3">
      <div class="card-header">Resultado del último traspaso</div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-sm table-bordered">
            <thead class="table-light"><tr><th>ID Inventario</th><th>Resultado</th><th>Detalle</th></tr></thead>
            <tbody>
              <?php foreach ($resultados as $r): ?>
              <tr class="<?= $r['resultado']==='OK' ? 'table-success' : 'table-danger' ?>">
                <td class="mono"><?= htmlspecialchars($r['id']) ?></td>
                <td><?= htmlspecialchars($r['resultado']) ?></td>
                <td><?= htmlspecialchars($r['detalle']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endif; ?>

</div>

<script>
// ===== Carrito simple (en memoria) =====
const cart = new Map(); // key: id_inv -> item {id, modelo, imei}
const cartTableBody = document.querySelector('#cart-table tbody');
const cartHidden = document.getElementById('cart-hidden');
const cartCount = document.getElementById('cart-count');
const cartCountSubmit = document.getElementById('cart-count-submit');

function updateCartUI() {
  cartTableBody.innerHTML = '';
  cartHidden.innerHTML = '';
  for (const [id, it] of cart.entries()) {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${escapeHtml(it.marca)} ${escapeHtml(it.modelo)} ${escapeHtml(it.capacidad||'')}</td>
      <td class="mono">${escapeHtml(it.imei1||'')}</td>
      <td><button type="button" class="btn btn-sm btn-outline-danger rm" data-id="${id}">✖</button></td>
    `;
    cartTableBody.appendChild(tr);

    const inp = document.createElement('input');
    inp.type = 'hidden'; inp.name = 'cart_ids[]'; inp.value = id;
    cartHidden.appendChild(inp);
  }
  const n = cart.size;
  cartCount.textContent = n;
  cartCountSubmit.textContent = n;
}

function escapeHtml(s){ return (s??'').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m])); }

function rowToItem(tr) {
  return {
    id: parseInt(tr.dataset.id),
    marca: tr.dataset.marca,
    modelo: tr.dataset.modelo,
    capacidad: tr.dataset.capacidad,
    imei1: tr.dataset.imei1
  };
}

document.querySelectorAll('.add-one').forEach(btn=>{
  btn.addEventListener('click', e=>{
    const tr = e.target.closest('tr');
    const it = rowToItem(tr);
    cart.set(it.id, it);
    updateCartUI();
  });
});

document.getElementById('add-selected').addEventListener('click', ()=>{
  document.querySelectorAll('.row-check:checked').forEach(chk=>{
    const tr = chk.closest('tr');
    const it = rowToItem(tr);
    cart.set(it.id, it);
  });
  updateCartUI();
});

document.getElementById('clear-cart').addEventListener('click', ()=>{
  cart.clear(); updateCartUI();
});

cartTableBody.addEventListener('click', e=>{
  if (e.target.classList.contains('rm')) {
    const id = parseInt(e.target.dataset.id);
    cart.delete(id); updateCartUI();
  }
});

const checkAll = document.getElementById('checkAll');
if (checkAll) {
  checkAll.addEventListener('change', () => {
    document.querySelectorAll('.row-check').forEach(chk => chk.checked = checkAll.checked);
  });
}
</script>
</body>
</html>
