<?php
// traspaso_directo_ma.php (multi-origen → cualquier destino, sin aceptación)
// Admin puede tomar productos del inventario de cualquier sucursal y moverlos directo a otra sucursal.

session_start();
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
    header("Location: 403.php");
    exit();
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

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
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ==== Catálogos de sucursales ====
$sucursales = [];
$qSuc = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");
while ($r = $qSuc->fetch_assoc()) { $sucursales[] = $r; }
$qSuc && $qSuc->close();

// Map id->nombre
$mapSuc = [];
foreach ($sucursales as $s) { $mapSuc[(int)$s['id']] = $s['nombre']; }

// Bitácora opcional
$LOG_HDR = tableExists($conn, 'traspasos_directos');
$LOG_DET = tableExists($conn, 'traspasos_directos_det');

// ¿Existe fecha_actualizacion?
$HAS_FECHA_ACT = columnExists($conn, 'inventario', 'fecha_actualizacion');

$MSG = '';
$TYPE = 'info';
$resultados = [];
$exitos = 0; $fallas = 0;

// ==== Ejecutar traspaso (carrito) ====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'mover') {
    $destino = (int)($_POST['destino'] ?? 0);
    $cartIds = array_map('intval', $_POST['cart_ids'] ?? []);

    $destinoValido = $destino > 0 && isset($mapSuc[$destino]);
    $nombreDestino = $destinoValido ? ($mapSuc[$destino] ?? ('#'.$destino)) : '';

    if (!$destinoValido) {
        $TYPE = 'danger'; $MSG = '❌ Selecciona una sucursal destino válida.';
    } elseif (empty($cartIds)) {
        $TYPE = 'warning'; $MSG = '⚠️ El carrito está vacío.';
    } else {
        $conn->begin_transaction();

        // encabezado log
        $idLog = null;
        if ($LOG_HDR) {
            $stmtLog = $conn->prepare("INSERT INTO traspasos_directos (fecha, id_usuario, id_sucursal_destino, observaciones) VALUES (NOW(), ?, ?, ?)");
            $obs = 'Traspaso directo multi-origen';
            $stmtLog->bind_param("iis", $_SESSION['id_usuario'], $destino, $obs);
            if ($stmtLog->execute()) $idLog = $stmtLog->insert_id;
            $stmtLog->close();
        }

        // pieza por ID (sin origen fijo)
        $stmtGet = $conn->prepare("
            SELECT i.id, i.id_sucursal AS id_origen, i.estatus,
                   p.imei1, p.marca, p.modelo, p.color, p.capacidad
            FROM inventario i
            INNER JOIN productos p ON p.id=i.id_producto
            WHERE i.id=? AND i.estatus='Disponible'
            LIMIT 1
        ");
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
            $stmtGet->bind_param("i", $idInv);
            $stmtGet->execute();
            $res = $stmtGet->get_result();
            $row = $res->fetch_assoc();
            $res->free_result();

            if (!$row) {
                $fallas++;
                $resultados[] = ['id'=>$idInv, 'resultado'=>'FALLÓ', 'detalle'=>'No está Disponible o no existe'];
                if ($stmtLogDet && $idLog) {
                    $resu='FALLÓ'; $det='No está Disponible o no existe'; $imeiTmp=''; $origenTmp = null;
                    $stmtLogDet->bind_param("iiiisss", $idLog, $idInv, $origenTmp, $destino, $imeiTmp, $resu, $det);
                    $stmtLogDet->execute();
                }
                continue;
            }

            $idOrigen = (int)$row['id_origen'];
            $nombreOrigen = $mapSuc[$idOrigen] ?? ('#'.$idOrigen);

            if ($idOrigen === $destino) {
                $fallas++;
                $resultados[] = ['id'=>$idInv, 'resultado'=>'FALLÓ', 'detalle'=>'Origen = destino'];
                if ($stmtLogDet && $idLog) {
                    $resu='FALLÓ'; $det='Origen = destino';
                    $stmtLogDet->bind_param("iiiisss", $idLog, $idInv, $idOrigen, $destino, $row['imei1'], $resu, $det);
                    $stmtLogDet->execute();
                }
                continue;
            }

            $stmtUpd->bind_param("ii", $destino, $idInv);
            if ($stmtUpd->execute() && $stmtUpd->affected_rows > 0) {
                $exitos++;
                $resultados[] = ['id'=>$idInv, 'resultado'=>'OK', 'detalle'=>"Movido de {$nombreOrigen} → {$nombreDestino} (IMEI: ".$row['imei1'].")"];
                if ($stmtLogDet && $idLog) {
                    $resu='OK'; $det="Movido de {$nombreOrigen} → {$nombreDestino}";
                    $stmtLogDet->bind_param("iiiisss", $idLog, $idInv, $idOrigen, $destino, $row['imei1'], $resu, $det);
                    $stmtLogDet->execute();
                }
            } else {
                $fallas++;
                $resultados[] = ['id'=>$idInv, 'resultado'=>'FALLÓ', 'detalle'=>'No se pudo actualizar inventario'];
                if ($stmtLogDet && $idLog) {
                    $resu='FALLÓ'; $det='No se pudo actualizar inventario';
                    $stmtLogDet->bind_param("iiiisss", $idLog, $idInv, $idOrigen, $destino, $row['imei1'], $resu, $det);
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
// Filtro de origen (0 = todas)
$origenSel = (int)($_GET['origen'] ?? 0);

$porPagina = 200;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $porPagina;

$qtxt = trim($_GET['q'] ?? '');
$params = [];
$where = "i.estatus='Disponible'";
$types = "";

if ($origenSel > 0) {
    $where .= " AND i.id_sucursal=?";
    $params[] = $origenSel;
    $types .= "i";
}

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
if ($types !== "") $stmtCount->bind_param($types, ...$params);
$stmtCount->execute();
$total = (int)($stmtCount->get_result()->fetch_assoc()['t'] ?? 0);
$stmtCount->close();

$totalPages = max(1, (int)ceil($total / $porPagina));

$sqlList = "SELECT i.id AS id_inv, i.fecha_ingreso, i.id_sucursal,
                   p.codigo_producto, p.marca, p.modelo, p.color, p.capacidad, p.imei1, p.imei2,
                   s.nombre AS sucursal_origen
            FROM inventario i
            INNER JOIN productos p ON p.id=i.id_producto
            LEFT JOIN sucursales s ON s.id=i.id_sucursal
            WHERE $where
            ORDER BY i.fecha_ingreso DESC, i.id DESC
            LIMIT ? OFFSET ?";
$stmtList = $conn->prepare($sqlList);
$bindTypes = $types . "ii";
$paramsList = $params;
$paramsList[] = $porPagina;
$paramsList[] = $offset;
if ($types !== "") {
    $stmtList->bind_param($bindTypes, ...$paramsList);
} else {
    $stmtList->bind_param("ii", $porPagina, $offset);
}
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
<title>Traspaso directo (multi-origen → cualquier destino)</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body{background:#f8fafc}
  .card{border-radius:16px}
  .card-header{font-weight:600}
  table td, table th{vertical-align:middle}
  .mono{font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;}
  .cart-sticky{position:sticky; top:0; z-index:10}

  /* —— Responsividad sin cambiar estructura —— */

  /* Sombra de “hay más” en zonas con scroll horizontal */
  .table-responsive{ position:relative; }
  .table-responsive::after{
    content:''; position:absolute; top:0; right:0; width:16px; height:100%;
    pointer-events:none; background:linear-gradient(to left, rgba(0,0,0,.06), rgba(0,0,0,0));
    border-radius:0 .5rem .5rem 0;
  }

  /* Evitar desbordes de IMEIs/códigos y permitir cortes limpios en móvil */
  @media (max-width:576px){
    .table{ font-size:.95rem; }
    .table > :not(caption) > * > * { padding:.55rem .6rem; }
    .table th, .table td{ white-space:normal !important; word-break:break-word; overflow-wrap:anywhere; }
    .mono{ word-break:break-all; overflow-wrap:anywhere; }

    /* Encabezados fijos dentro del contenedor con scroll */
    .table thead th{
      position:sticky; top:0; z-index:2;
      background:var(--bs-table-bg, #fff);
      box-shadow: inset 0 -1px 0 rgba(0,0,0,.075);
    }

    /* Mejor tacto en controles */
    .btn, .form-control, .form-select{ min-height:40px; }
    .btn-group{ flex-wrap:wrap; }
    .btn-group .btn{ margin-bottom:6px; }

    /* El carrito deja de ser sticky en pantallas chicas para no tapar contenido */
    .cart-sticky{ position:static; top:auto; }
  }
</style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container my-4">
  <h2 class="fw-bold">🚚 Traspaso directo <small class="text-muted">multi-origen → cualquier destino</small></h2>
  <p class="text-muted mb-3">Arma el <b>carrito</b> con equipos <b>Disponibles</b> (de una o varias sucursales) y muévelos <b>directo</b> a la sucursal destino (sin aceptación).</p>

  <?php if ($MSG): ?>
    <div class="alert alert-<?= h($TYPE) ?> shadow-sm"><?= $MSG ?></div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-lg-8">
      <div class="card shadow-sm mb-3">
        <div class="card-header bg-dark text-white">Inventario disponible</div>
        <div class="card-body">
          <form class="row g-2" method="GET" action="" id="filtroForm">
            <div class="col-md-4">
              <label class="form-label mb-1">Origen</label>
              <select name="origen" class="form-select" id="origenSelect">
                <option value="0">— Todas las sucursales —</option>
                <?php foreach ($sucursales as $s): ?>
                  <option value="<?= (int)$s['id'] ?>" <?= $origenSel===(int)$s['id']?'selected':'' ?>><?= h($s['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label mb-1">Buscar</label>
              <input type="text" name="q" value="<?= h($qtxt) ?>" class="form-control" placeholder="Marca, modelo, color, capacidad, IMEI o código">
            </div>
            <div class="col-md-2 d-flex align-items-end">
              <button class="btn btn-outline-primary w-100" type="submit">🔎 Buscar</button>
            </div>
            <div class="col-md-2 d-flex align-items-end">
              <a class="btn btn-outline-secondary w-100" href="traspaso_directo_ma.php">Limpiar</a>
            </div>
            <div class="col-12 text-end">
              <span class="badge bg-secondary">Total: <?= number_format($total) ?></span>
            </div>
          </form>

          <div class="table-responsive mt-3">
            <table class="table table-hover table-bordered align-middle bg-white" id="tabla-inv">
              <thead class="table-light">
                <tr>
                  <th style="width:42px" class="text-center"><input type="checkbox" id="checkAll"></th>
                  <th>Sucursal</th>
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
                <tr><td colspan="11" class="text-center text-muted">No hay equipos disponibles que coincidan con el filtro.</td></tr>
                <?php else: foreach ($rows as $r): ?>
                <tr data-id="<?= (int)$r['id_inv'] ?>"
                    data-origen-id="<?= (int)$r['id_sucursal'] ?>"
                    data-origen-nom="<?= h($r['sucursal_origen'] ?? '') ?>"
                    data-codigo="<?= h($r['codigo_producto']) ?>"
                    data-marca="<?= h($r['marca']) ?>"
                    data-modelo="<?= h($r['modelo']) ?>"
                    data-color="<?= h($r['color']) ?>"
                    data-capacidad="<?= h($r['capacidad']) ?>"
                    data-imei1="<?= h($r['imei1']) ?>"
                    data-imei2="<?= h($r['imei2']) ?>"
                    data-fecha="<?= h($r['fecha_ingreso']) ?>">
                  <td class="text-center"><input type="checkbox" class="row-check"></td>
                  <td class="mono"><?= h($r['sucursal_origen'] ?? '') ?></td>
                  <td class="mono"><?= h($r['codigo_producto']) ?></td>
                  <td><?= h($r['marca']) ?></td>
                  <td><?= h($r['modelo']) ?></td>
                  <td><?= h($r['color']) ?></td>
                  <td><?= h($r['capacidad']) ?></td>
                  <td class="mono"><?= h($r['imei1']) ?></td>
                  <td class="mono"><?= h($r['imei2']) ?></td>
                  <td><?= h($r['fecha_ingreso']) ?></td>
                  <td><button type="button" class="btn btn-sm btn-outline-primary add-one">➕</button></td>
                </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>

          <div class="d-flex justify-content-between align-items-center mt-2">
            <div class="text-muted small">Mostrando <?= count($rows) ?> de <?= number_format($total) ?></div>
            <?php if ($totalPages > 1):
              $baseUrl = 'traspaso_directo_ma.php?origen='.urlencode($origenSel).'&q='.urlencode($qtxt).'&page=';
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
            <button type="button" class="btn btn-outline-secondary btn-sm" id="add-selected">Agregar seleccionados</button>
            <button type="button" class="btn btn-outline-danger btn-sm" id="clear-cart">Vaciar</button>
          </div>
          <div class="table-responsive" style="max-height:320px; overflow:auto">
            <table class="table table-sm table-bordered" id="cart-table">
              <thead class="table-light">
                <tr>
                  <th>Origen</th>
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
              <label class="form-label">Destino <span class="text-danger">*</span></label>
              <select name="destino" class="form-select" required>
                <option value="">— Selecciona destino —</option>
                <?php foreach ($sucursales as $s): ?>
                  <option value="<?= (int)$s['id'] ?>"><?= h($s['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="submit" class="btn btn-success w-100">🚀 Traspasar <span id="cart-count-submit">0</span> equipos</button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <?php if ($histEnabled): ?>
  <div class="card shadow-sm mt-4">
    <div class="card-header">🕑 Historial de traspasos directos</div>
    <div class="card-body">
      <form class="row g-2 mb-3" method="GET">
        <div class="col-md-3">
          <label class="form-label">Desde</label>
          <input type="date" class="form-control" name="from" value="<?= h($h_from) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Hasta</label>
          <input type="date" class="form-control" name="to" value="<?= h($h_to) ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Destino</label>
          <select class="form-select" name="dest">
            <option value="0">Todos</option>
            <?php foreach ($sucursales as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= $h_dest===(int)$s['id']?'selected':'' ?>><?= h($s['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2 d-flex align-items-end">
          <button class="btn btn-outline-primary w-100" type="submit">Filtrar</button>
        </div>
      </form>

      <?php
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
      ?>
      <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle">
          <thead class="table-light">
            <tr><th>ID</th><th>Fecha</th><th>Destino</th><th>Piezas</th><th>OK</th><th>Falló</th></tr>
          </thead>
          <tbody>
          <?php if ($resH->num_rows === 0): ?>
            <tr><td colspan="6" class="text-center text-muted">Sin registros para el filtro.</td></tr>
          <?php else: while ($hrow = $resH->fetch_assoc()): ?>
            <tr>
              <td class="mono"><?= (int)$hrow['id'] ?></td>
              <td><?= h($hrow['fecha']) ?></td>
              <td><?= h($hrow['destino'] ?? ('#'.$hrow['id_sucursal_destino'])) ?></td>
              <td><?= (int)$hrow['piezas'] ?></td>
              <td class="text-success"><?= (int)$hrow['ok_cnt'] ?></td>
              <td class="text-danger"><?= (int)$hrow['fail_cnt'] ?></td>
            </tr>
          <?php endwhile; endif; $stmtH->close(); ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

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
                <td class="mono"><?= h($r['id']) ?></td>
                <td><?= h($r['resultado']) ?></td>
                <td><?= h($r['detalle']) ?></td>
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
// ===== UX: auto-filtrar por Origen al cambiar =====
const origenSelect = document.getElementById('origenSelect');
if (origenSelect) origenSelect.addEventListener('change', () => {
  document.getElementById('filtroForm').submit();
});

// ===== Carrito simple (en memoria) =====
const cart = new Map(); // key: id_inv -> item {id, origenId, origenNom, marca, modelo, capacidad, imei1}
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
      <td class="mono">${escapeHtml(it.origenNom||'')}</td>
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
    origenId: parseInt(tr.dataset.origenId),
    origenNom: tr.dataset.origenNom || '',
    marca: tr.dataset.marca,
    modelo: tr.dataset.modelo,
    capacidad: tr.dataset.capacidad,
    imei1: tr.dataset.imei1
  };
}

// Delegación: botón ➕ dentro de la tabla
const tablaInv = document.getElementById('tabla-inv');
if (tablaInv) {
  tablaInv.addEventListener('click', (e) => {
    const btn = e.target.closest('.add-one');
    if (!btn) return;
    e.preventDefault();
    const tr = btn.closest('tr');
    if (!tr) return;
    const it = rowToItem(tr);
    cart.set(it.id, it);
    updateCartUI();
  });
}

// Agregar seleccionados
const addSelectedBtn = document.getElementById('add-selected');
if (addSelectedBtn) addSelectedBtn.addEventListener('click', (e)=>{
  e.preventDefault();
  document.querySelectorAll('input.row-check:checked').forEach(chk=>{
    const tr = chk.closest('tr');
    if (!tr) return;
    const it = rowToItem(tr);
    cart.set(it.id, it);
  });
  updateCartUI();
});

// Vaciar
const clearBtn = document.getElementById('clear-cart');
if (clearBtn) clearBtn.addEventListener('click', (e)=>{
  e.preventDefault();
  cart.clear();
  updateCartUI();
});

// Quitar una línea del carrito (delegación)
cartTableBody.addEventListener('click', e=>{
  const rm = e.target.closest('.rm');
  if (!rm) return;
  e.preventDefault();
  const id = parseInt(rm.dataset.id);
  cart.delete(id);
  updateCartUI();
});

// Check all
const checkAll = document.getElementById('checkAll');
if (checkAll) {
  checkAll.addEventListener('change', () => {
    document.querySelectorAll('input.row-check').forEach(chk => chk.checked = checkAll.checked);
  });
}

// Conteo inicial
updateCartUI();

// Inyectar meta viewport si no existe (para móvil)
(function () {
  try {
    if (!document.querySelector('meta[name="viewport"]')) {
      var m = document.createElement('meta');
      m.name = 'viewport';
      m.content = 'width=device-width, initial-scale=1';
      document.head.appendChild(m);
    }
  } catch(e) {}
})();
</script>
</body>
</html>
