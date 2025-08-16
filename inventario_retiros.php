<?php
// inventario_retiros.php
// Solo Admin. Opera exclusivamente sobre la sucursal "Almacen Angelopolis" y permite revertir retiros.

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'Admin') {
    header("Location: 403.php"); exit();
}

include 'db.php';
include 'navbar.php';

$idUsuario = (int)($_SESSION['id_usuario'] ?? 0);

// ===== Obtener ID de sucursal "Almacen Angelopolis" =====
$idCentral = 0;
$nombreCentral = '';

// Intento exacto
$stmt = $conn->prepare("SELECT id, nombre FROM sucursales WHERE nombre = 'Almacen Angelopolis' LIMIT 1");
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $idCentral = (int)$row['id'];
    $nombreCentral = $row['nombre'];
}
$stmt->close();

// Intento fallback por LIKE
if ($idCentral <= 0) {
    $stmt = $conn->prepare("SELECT id, nombre FROM sucursales WHERE nombre LIKE ? LIMIT 1");
    $like = '%Angelopolis%';
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $idCentral = (int)$row['id'];
        $nombreCentral = $row['nombre'];
    }
    $stmt->close();
}

if ($idCentral <= 0) {
    echo "<div class='container my-4'><div class='alert alert-danger'>No existe la sucursal 'Almacen Angelopolis'. Créala primero.</div></div>";
    exit();
}

$mensaje = $_GET['msg'] ?? '';
$alert   = '';
if ($mensaje === 'ok') {
    $alert = "<div class='alert alert-success my-3'>✅ Retiro realizado correctamente.</div>";
} elseif ($mensaje === 'revok') {
    $alert = "<div class='alert alert-success my-3'>✅ Reversión aplicada correctamente.</div>";
} elseif ($mensaje === 'err') {
    $err = htmlspecialchars($_GET['errdetail'] ?? 'Ocurrió un error.');
    $alert = "<div class='alert alert-danger my-3'>❌ $err</div>";
}

// ===== Búsqueda libre en disponibles de Central =====
$f_q = trim($_GET['q'] ?? '');

$params = [];
$sql = "
    SELECT inv.id AS id_inventario, inv.id_sucursal, inv.id_producto, inv.estatus,
           p.marca, p.modelo, p.color, p.capacidad, p.imei1, p.imei2, p.tipo_producto, p.codigo_producto
    FROM inventario inv
    INNER JOIN productos p ON p.id = inv.id_producto
    WHERE inv.estatus = 'Disponible'
      AND inv.id_sucursal = ?
";
$params[] = ['i', $idCentral];

if ($f_q !== '') {
    $sql .= " AND (p.marca LIKE ? OR p.modelo LIKE ? OR p.color LIKE ? OR p.capacidad LIKE ? OR p.imei1 LIKE ? OR p.codigo_producto LIKE ?) ";
    $like = "%$f_q%";
    $params[] = ['s', $like];
    $params[] = ['s', $like];
    $params[] = ['s', $like];
    $params[] = ['s', $like];
    $params[] = ['s', $like];
    $params[] = ['s', $like];
}
$sql .= " ORDER BY p.marca, p.modelo, p.capacidad, p.color, inv.id ASC ";

$itemsDisponibles = [];
$types = ''; $binds = [];
foreach ($params as $p) { $types .= $p[0]; $binds[] = $p[1]; }
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$binds);
$stmt->execute();
$itemsDisponibles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ===== Historial (solo retiros de Central) =====
$h_motivo = $_GET['h_motivo'] ?? '';
$h_qfolio = trim($_GET['h_folio'] ?? '');
$h_estado = $_GET['h_estado'] ?? ''; // '', 'vigente', 'revertido'

$histSql = "
    SELECT r.id, r.folio, r.fecha, r.motivo, r.destino, r.nota,
           r.id_sucursal, s.nombre AS sucursal_nombre, u.nombre AS usuario_nombre,
           r.revertido, r.fecha_reversion, r.nota_reversion,
           COUNT(d.id) AS cantidad
    FROM inventario_retiros r
    LEFT JOIN inventario_retiros_detalle d ON d.retiro_id = r.id
    LEFT JOIN sucursales s ON s.id = r.id_sucursal
    LEFT JOIN usuarios   u ON u.id = r.id_usuario
    WHERE r.id_sucursal = ?
";
$histParams = [['i', $idCentral]];

if ($h_motivo !== '') {
    $histSql .= " AND r.motivo = ? ";
    $histParams[] = ['s', $h_motivo];
}
if ($h_qfolio !== '') {
    $histSql .= " AND r.folio LIKE ? ";
    $histParams[] = ['s', "%$h_qfolio%"];
}
if ($h_estado === 'vigente') {
    $histSql .= " AND r.revertido = 0 ";
} elseif ($h_estado === 'revertido') {
    $histSql .= " AND r.revertido = 1 ";
}

$histSql .= " GROUP BY r.id
              ORDER BY r.fecha DESC
              LIMIT 200";

$types = ''; $binds = [];
foreach ($histParams as $p) { $types .= $p[0]; $binds[] = $p[1]; }
$stmt = $conn->prepare($histSql);
$stmt->bind_param($types, ...$binds);
$stmt->execute();
$historial = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<div class="container-fluid my-3">
  <h3 class="mb-2">Retiros de Inventario — Central (Almacén Angelopolis)</h3>
  <p class="text-muted">Operación restringida a la sucursal <strong><?= htmlspecialchars($nombreCentral) ?></strong>. Solo Admin.</p>

  <?= $alert ?>

  <!-- === Filtros de búsqueda === -->
  <form class="row g-2 mb-3" method="get">
    <div class="col-md-4">
      <input type="text" class="form-control" name="q" placeholder="Buscar por marca, modelo, color, IMEI o código" value="<?= htmlspecialchars($f_q) ?>">
    </div>
    <div class="col-md-2">
      <button type="submit" class="btn btn-primary w-100">Buscar</button>
    </div>
  </form>

  <!-- === Tabla disponibles === -->
  <form method="post" action="inventario_retiros_guardar.php">
    <input type="hidden" name="id_sucursal" value="<?= $idCentral ?>">
    <table class="table table-bordered table-sm align-middle">
      <thead class="table-light">
        <tr>
          <th><input type="checkbox" id="chkAll"></th>
          <th>Marca</th>
          <th>Modelo</th>
          <th>Capacidad</th>
          <th>Color</th>
          <th>IMEI</th>
          <th>Código</th>
        </tr>
      </thead>
      <tbody>
        <?php if (count($itemsDisponibles) === 0): ?>
          <tr><td colspan="7" class="text-center">Sin disponibles en <?= htmlspecialchars($nombreCentral) ?></td></tr>
        <?php else: foreach ($itemsDisponibles as $it): ?>
          <tr>
            <td><input type="checkbox" name="ids[]" value="<?= $it['id_inventario'] ?>"></td>
            <td><?= htmlspecialchars($it['marca']) ?></td>
            <td><?= htmlspecialchars($it['modelo']) ?></td>
            <td><?= htmlspecialchars($it['capacidad']) ?></td>
            <td><?= htmlspecialchars($it['color']) ?></td>
            <td><?= htmlspecialchars($it['imei1']) ?></td>
            <td><?= htmlspecialchars($it['codigo_producto']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
    <div class="row g-2">
      <div class="col-md-3">
        <select name="motivo" class="form-select" required>
          <option value="">-- Motivo --</option>
          <option value="Baja">Baja</option>
          <option value="Merma">Merma</option>
          <option value="Otro">Otro</option>
        </select>
      </div>
      <div class="col-md-3">
        <input type="text" name="destino" class="form-control" placeholder="Destino / Referencia">
      </div>
      <div class="col-md-4">
        <input type="text" name="nota" class="form-control" placeholder="Nota">
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-danger w-100">Retirar seleccionados</button>
      </div>
    </div>
  </form>

  <hr>

  <!-- === Historial === -->
  <h5>Historial de retiros</h5>
  <form class="row g-2 mb-3">
    <input type="hidden" name="view" value="historial">
    <div class="col-md-3">
      <select name="h_motivo" class="form-select">
        <option value="">-- Motivo --</option>
        <option value="Baja" <?= $h_motivo==='Baja'?'selected':'' ?>>Baja</option>
        <option value="Merma" <?= $h_motivo==='Merma'?'selected':'' ?>>Merma</option>
        <option value="Otro" <?= $h_motivo==='Otro'?'selected':'' ?>>Otro</option>
      </select>
    </div>
    <div class="col-md-3">
      <input type="text" name="h_folio" class="form-control" placeholder="Folio" value="<?= htmlspecialchars($h_qfolio) ?>">
    </div>
    <div class="col-md-3">
      <select name="h_estado" class="form-select">
        <option value="">-- Estado --</option>
        <option value="vigente" <?= $h_estado==='vigente'?'selected':'' ?>>Vigente</option>
        <option value="revertido" <?= $h_estado==='revertido'?'selected':'' ?>>Revertido</option>
      </select>
    </div>
    <div class="col-md-3">
      <button type="submit" class="btn btn-primary w-100">Filtrar</button>
    </div>
  </form>

  <table class="table table-bordered table-sm align-middle">
    <thead class="table-light">
      <tr>
        <th>Folio</th>
        <th>Fecha</th>
        <th>Motivo</th>
        <th>Destino</th>
        <th>Cantidad</th>
        <th>Usuario</th>
        <th>Estado</th>
        <th>Acciones</th>
      </tr>
    </thead>
    <tbody>
      <?php if (count($historial)===0): ?>
        <tr><td colspan="8" class="text-center">Sin retiros</td></tr>
      <?php else: foreach ($historial as $h): ?>
        <tr>
          <td><?= htmlspecialchars($h['folio']) ?></td>
          <td><?= htmlspecialchars($h['fecha']) ?></td>
          <td><?= htmlspecialchars($h['motivo']) ?></td>
          <td><?= htmlspecialchars($h['destino']) ?></td>
          <td><?= (int)$h['cantidad'] ?></td>
          <td><?= htmlspecialchars($h['usuario_nombre']) ?></td>
          <td>
            <?php if ($h['revertido']): ?>
              <span class="badge bg-secondary">Revertido</span><br>
              <small><?= htmlspecialchars($h['fecha_reversion']) ?></small>
            <?php else: ?>
              <span class="badge bg-success">Vigente</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!$h['revertido']): ?>
              <form method="post" action="inventario_retiros_revertir.php" onsubmit="return confirm('¿Revertir este retiro?')">
                <input type="hidden" name="id" value="<?= $h['id'] ?>">
                <button class="btn btn-sm btn-warning">Revertir</button>
              </form>
            <?php else: ?>
              <em>-</em>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<script>
document.getElementById('chkAll')?.addEventListener('change',function(){
  document.querySelectorAll('input[name="ids[]"]').forEach(ch=>ch.checked=this.checked);
});
</script>
