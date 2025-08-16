<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';

$idSucursalUsuario = (int)$_SESSION['id_sucursal'];
$rolUsuario        = $_SESSION['rol'] ?? '';
$mensaje = "";

// Mensaje de eliminación (opcional)
if (isset($_GET['msg']) && $_GET['msg'] === 'eliminado') {
    $mensaje = "<div class='alert alert-success'>✅ Traspaso eliminado correctamente.</div>";
}

// Utilidad: detectar si existe una columna (para usar bitácoras si existen)
function hasColumn(mysqli $conn, string $table, string $column): bool {
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $rs = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $rs && $rs->num_rows > 0;
}
$hasDT_Resultado      = hasColumn($conn, 'detalle_traspaso', 'resultado');
$hasDT_FechaResultado = hasColumn($conn, 'detalle_traspaso', 'fecha_resultado');
$hasT_FechaRecep      = hasColumn($conn, 'traspasos', 'fecha_recepcion');
$hasT_UsuarioRecibio  = hasColumn($conn, 'traspasos', 'usuario_recibio');

// -------------------------------
// PENDIENTES (enviados y no recibidos)
// -------------------------------
$sqlPend = "
    SELECT t.id, t.fecha_traspaso, s.nombre AS sucursal_destino, u.nombre AS usuario_creo
    FROM traspasos t
    INNER JOIN sucursales s ON s.id = t.id_sucursal_destino
    INNER JOIN usuarios  u ON u.id = t.usuario_creo
    WHERE t.id_sucursal_origen = ? AND t.estatus='Pendiente'
    ORDER BY t.fecha_traspaso ASC, t.id ASC
";
$stmtPend = $conn->prepare($sqlPend);
$stmtPend->bind_param("i", $idSucursalUsuario);
$stmtPend->execute();
$traspasosPend = $stmtPend->get_result();
$stmtPend->close();

// -------------------------------
/* HISTÓRICO: filtros */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$desde    = $_GET['desde']   ?? date('Y-m-01');
$hasta    = $_GET['hasta']   ?? date('Y-m-d');
$estatus  = $_GET['estatus'] ?? 'Todos'; // Todos / Pendiente / Parcial / Completado / Rechazado
$idDest   = (int)($_GET['destino'] ?? 0);

// Para combo de destinos (solo los que han recibido algo de mi suc)
$destinos = [];
$qDest = $conn->prepare("
    SELECT DISTINCT s.id, s.nombre
    FROM traspasos t
    INNER JOIN sucursales s ON s.id = t.id_sucursal_destino
    WHERE t.id_sucursal_origen=?
    ORDER BY s.nombre
");
$qDest->bind_param("i", $idSucursalUsuario);
$qDest->execute();
$rDest = $qDest->get_result();
while ($row = $rDest->fetch_assoc()) {
    $destinos[(int)$row['id']] = $row['nombre'];
}
$qDest->close();

// WHERE dinámico para histórico
$whereH = "t.id_sucursal_origen = ? AND DATE(t.fecha_traspaso) BETWEEN ? AND ?";
$params = [$idSucursalUsuario, $desde, $hasta];
$types  = "iss";

if ($estatus !== 'Todos') {
    $whereH .= " AND t.estatus = ?";
    $params[] = $estatus;
    $types   .= "s";
} else {
    // Histórico normalmente excluye Pendiente, pero si quieres verlo también, deja así:
    // $whereH .= " AND t.estatus <> 'Pendiente'";
}

if ($idDest > 0) {
    $whereH .= " AND t.id_sucursal_destino = ?";
    $params[] = $idDest;
    $types   .= "i";
}

$sqlHist = "
    SELECT 
      t.id, t.fecha_traspaso, t.estatus,
      s.nombre  AS sucursal_destino,
      u.nombre  AS usuario_creo".
      ($hasT_FechaRecep  ? ", t.fecha_recepcion" : "").
      ($hasT_UsuarioRecibio ? ", u2.nombre AS usuario_recibio" : "").
    "
    FROM traspasos t
    INNER JOIN sucursales s ON s.id = t.id_sucursal_destino
    INNER JOIN usuarios  u ON u.id = t.usuario_creo ".
    ($hasT_UsuarioRecibio ? " LEFT JOIN usuarios u2 ON u2.id = t.usuario_recibio " : "").
    "WHERE $whereH
    ORDER BY t.fecha_traspaso DESC, t.id DESC
";

$stmtHist = $conn->prepare($sqlHist);
$stmtHist->bind_param($types, ...$params);
$stmtHist->execute();
$historial = $stmtHist->get_result();
$stmtHist->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Traspasos Salientes Pendientes</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <style>
    .badge-status{font-size:.85rem}
    .table-sm td, .table-sm th{vertical-align: middle;}
    .btn-link{padding:0}
  </style>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
  <h2>📦 Traspasos Salientes Pendientes</h2>
  <p class="text-muted">Traspasos enviados por tu sucursal que aún no han sido confirmados por el destino.</p>
  <?= $mensaje ?>

  <?php if ($traspasosPend->num_rows > 0): ?>
    <?php while($traspaso = $traspasosPend->fetch_assoc()): ?>
      <?php
      $idTraspaso = (int)$traspaso['id'];
      $detalles = $conn->query("
          SELECT i.id, p.marca, p.modelo, p.color, p.capacidad, p.imei1, p.imei2
          FROM detalle_traspaso dt
          INNER JOIN inventario i ON i.id = dt.id_inventario
          INNER JOIN productos  p ON p.id = i.id_producto
          WHERE dt.id_traspaso = $idTraspaso
          ORDER BY p.marca, p.modelo, i.id
      ");
      ?>
      <div class="card mb-4 shadow">
        <div class="card-header bg-secondary text-white">
          <div class="d-flex justify-content-between align-items-center">
            <span>
              Traspaso #<?= $idTraspaso ?> |
              Destino: <b><?= h($traspaso['sucursal_destino']) ?></b> |
              Fecha: <?= h($traspaso['fecha_traspaso']) ?>
            </span>
            <span>Creado por: <?= h($traspaso['usuario_creo']) ?></span>
          </div>
        </div>
        <div class="card-body p-0">
          <table class="table table-striped table-bordered table-sm mb-0">
            <thead class="table-dark">
              <tr>
                <th>ID Inv</th><th>Marca</th><th>Modelo</th><th>Color</th><th>Capacidad</th>
                <th>IMEI1</th><th>IMEI2</th><th>Estatus</th>
              </tr>
            </thead>
            <tbody>
              <?php while ($row = $detalles->fetch_assoc()): ?>
                <tr>
                  <td><?= (int)$row['id'] ?></td>
                  <td><?= h($row['marca']) ?></td>
                  <td><?= h($row['modelo']) ?></td>
                  <td><?= h($row['color']) ?></td>
                  <td><?= $row['capacidad'] ?: '-' ?></td>
                  <td><?= h($row['imei1']) ?></td>
                  <td><?= $row['imei2'] ? h($row['imei2']) : '-' ?></td>
                  <td>En tránsito</td>
                </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
        <div class="card-footer text-muted d-flex justify-content-between align-items-center">
          <span>Esperando confirmación de <b><?= h($traspaso['sucursal_destino']) ?></b>...</span>
          <form method="POST" action="eliminar_traspaso.php"
                onsubmit="return confirm('¿Eliminar este traspaso? Esta acción no se puede deshacer.')">
            <input type="hidden" name="id_traspaso" value="<?= $idTraspaso ?>">
            <button type="submit" class="btn btn-sm btn-danger">🗑️ Eliminar Traspaso</button>
          </form>
        </div>
      </div>
    <?php endwhile; ?>
  <?php else: ?>
    <div class="alert alert-info">No hay traspasos salientes pendientes para tu sucursal.</div>
  <?php endif; ?>

  <!-- ===========================================================
       HISTÓRICO
  ============================================================ -->
  <hr class="my-4">
  <h3>📜 Histórico de traspasos salientes</h3>
  <form method="GET" class="row g-2 mb-3">
    <input type="hidden" name="x" value="1"><!-- evita reenvío POST -->
    <div class="col-md-3">
      <label class="form-label">Desde</label>
      <input type="date" name="desde" class="form-control" value="<?= h($desde) ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">Hasta</label>
      <input type="date" name="hasta" class="form-control" value="<?= h($hasta) ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">Estatus</label>
      <select name="estatus" class="form-select">
        <?php
          $opts = ['Todos','Pendiente','Parcial','Completado','Rechazado'];
          foreach ($opts as $op):
        ?>
          <option value="<?= $op ?>" <?= $op===$estatus?'selected':'' ?>><?= $op ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Destino</label>
      <select name="destino" class="form-select">
        <option value="0">Todos</option>
        <?php foreach ($destinos as $id=>$nom): ?>
          <option value="<?= $id ?>" <?= $id===$idDest?'selected':'' ?>><?= h($nom) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-12 d-flex gap-2 mt-2">
      <button class="btn btn-primary">Filtrar</button>
      <a class="btn btn-outline-secondary" href="traspasos_salientes.php">Limpiar</a>
    </div>
  </form>

  <?php if ($historial && $historial->num_rows > 0): ?>
    <?php while($h = $historial->fetch_assoc()): ?>
      <?php
      $idT = (int)$h['id'];

      // Conteos del detalle (si hay columnas de resultado)
      $total = $rec = $rej = null;
      if ($hasDT_Resultado) {
        $q = $conn->prepare("
          SELECT 
            COUNT(*) AS total,
            SUM(resultado='Recibido')   AS recibidos,
            SUM(resultado='Rechazado')  AS rechazados
          FROM detalle_traspaso
          WHERE id_traspaso=?
        ");
        $q->bind_param("i", $idT);
        $q->execute();
        $cnt = $q->get_result()->fetch_assoc();
        $q->close();
        $total = (int)($cnt['total'] ?? 0);
        $rec   = (int)($cnt['recibidos'] ?? 0);
        $rej   = (int)($cnt['rechazados'] ?? 0);
      } else {
        // Si no hay columna resultado, al menos contamos piezas
        $q = $conn->prepare("SELECT COUNT(*) AS total FROM detalle_traspaso WHERE id_traspaso=?");
        $q->bind_param("i", $idT);
        $q->execute();
        $total = (int)($q->get_result()->fetch_assoc()['total'] ?? 0);
        $q->close();
      }

      // Color de estatus
      $badge = 'bg-secondary';
      if ($h['estatus']==='Completado') $badge='bg-success';
      elseif ($h['estatus']==='Parcial') $badge='bg-warning text-dark';
      elseif ($h['estatus']==='Rechazado') $badge='bg-danger';
      elseif ($h['estatus']==='Pendiente') $badge='bg-info text-dark';
      ?>
      <div class="card mb-3 shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
          <div>
            <span class="badge badge-status <?= $badge ?>"><?= h($h['estatus']) ?></span>
            &nbsp; Traspaso #<?= $idT ?> · Destino: <b><?= h($h['sucursal_destino']) ?></b>
          </div>
          <div class="text-muted">
            Enviado: <?= h($h['fecha_traspaso']) ?>
            <?php if ($hasT_FechaRecep && $h['estatus']!=='Pendiente' && !empty($h['fecha_recepcion'])): ?>
              &nbsp;·&nbsp; Recibido: <?= h($h['fecha_recepcion']) ?>
            <?php endif; ?>
          </div>
        </div>
        <div class="card-body">
          <div class="d-flex flex-wrap justify-content-between">
            <div>
              <div>Creado por: <b><?= h($h['usuario_creo']) ?></b></div>
              <?php if ($hasT_UsuarioRecibio && $h['estatus']!=='Pendiente' && !empty($h['usuario_recibio'])): ?>
                <div>Recibido por: <b><?= h($h['usuario_recibio']) ?></b></div>
              <?php endif; ?>
            </div>
            <div class="text-end">
              <div>Total piezas: <b><?= ($total ?? '-') ?></b></div>
              <?php if ($hasDT_Resultado): ?>
                <div>Recibidas: <b><?= $rec ?></b> · Rechazadas: <b><?= $rej ?></b></div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Detalle colapsable -->
          <a class="btn btn-link mt-2" data-bs-toggle="collapse" href="#det_<?= $idT ?>">🔍 Ver detalle</a>
          <div id="det_<?= $idT ?>" class="collapse mt-2">
          <?php
            $det = $conn->query("
              SELECT i.id, p.marca, p.modelo, p.color, p.capacidad, p.imei1, p.imei2 ".
              ($hasDT_Resultado ? ", dt.resultado" : "").
              ($hasDT_FechaResultado ? ", dt.fecha_resultado" : "").
              " FROM detalle_traspaso dt
                INNER JOIN inventario i ON i.id = dt.id_inventario
                INNER JOIN productos  p ON p.id = i.id_producto
              WHERE dt.id_traspaso = $idT
              ORDER BY p.marca, p.modelo, i.id
            ");
          ?>
            <div class="table-responsive">
              <table class="table table-sm table-bordered mb-0">
                <thead class="table-light">
                  <tr>
                    <th>ID Inv</th><th>Marca</th><th>Modelo</th><th>Color</th><th>Capacidad</th>
                    <th>IMEI1</th><th>IMEI2</th>
                    <?php if ($hasDT_Resultado): ?><th>Resultado</th><?php endif; ?>
                    <?php if ($hasDT_FechaResultado): ?><th>Fecha resultado</th><?php endif; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php while($r = $det->fetch_assoc()): ?>
                    <tr>
                      <td><?= (int)$r['id'] ?></td>
                      <td><?= h($r['marca']) ?></td>
                      <td><?= h($r['modelo']) ?></td>
                      <td><?= h($r['color']) ?></td>
                      <td><?= $r['capacidad'] ?: '-' ?></td>
                      <td><?= h($r['imei1']) ?></td>
                      <td><?= $r['imei2'] ? h($r['imei2']) : '-' ?></td>
                      <?php if ($hasDT_Resultado): ?>
                        <td><?= h($r['resultado'] ?? 'Pendiente') ?></td>
                      <?php endif; ?>
                      <?php if ($hasDT_FechaResultado): ?>
                        <td><?= h($r['fecha_resultado'] ?? '') ?></td>
                      <?php endif; ?>
                    </tr>
                  <?php endwhile; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    <?php endwhile; ?>
  <?php else: ?>
    <div class="alert alert-warning">No hay resultados con los filtros aplicados.</div>
  <?php endif; ?>
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script> -->
</body>
</html>
