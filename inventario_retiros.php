<?php
// inventario_retiros.php
// Solo Admin. Opera exclusivamente sobre la sucursal "Eulalia" y permite revertir retiros,
// ahora con modal de resumen antes de confirmar.

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'Admin') {
    header("Location: 403.php"); exit();
}

include 'db.php';
include 'navbar.php';

$idUsuario = (int)($_SESSION['id_usuario'] ?? 0);

// ===== Obtener ID de sucursal "Eulalia" =====
$stmt = $conn->prepare("SELECT id, nombre FROM sucursales WHERE nombre = 'Eulalia' LIMIT 1");
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) {
    echo "<div class='container my-4'><div class='alert alert-danger'>No existe la sucursal 'Eulalia'. Créala primero.</div></div>";
    exit();
}
$rowE = $res->fetch_assoc();
$idEulalia = (int)$rowE['id'];
$nombreEulalia = $rowE['nombre'];
$stmt->close();

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

// ===== Búsqueda libre en disponibles de Eulalia =====
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
$params[] = ['i', $idEulalia];

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

// ===== Historial (solo retiros de Eulalia) =====
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
$histParams = [['i', $idEulalia]];

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
  <h3 class="mb-2">Retiros de Inventario (Eulalia)</h3>
  <p class="text-muted">Operación restringida a la sucursal <strong><?= htmlspecialchars($nombreEulalia) ?></strong>. Solo Admin.</p>

  <?= $alert ?>

  <!-- === Form de BÚSQUEDA separado para evitar que dispare el submit del retiro === -->
  <form id="searchForm" method="GET"></form>

  <!-- ===== Nuevo retiro (Eulalia fija) ===== -->
  <div class="card mb-4">
    <div class="card-header">Nuevo retiro</div>
    <div class="card-body">
      <form id="formRetiro" action="procesar_retiro.php" method="POST">
        <input type="hidden" name="id_sucursal" value="<?= $idEulalia ?>">
        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label">Sucursal</label>
            <input type="text" class="form-control" value="<?= htmlspecialchars($nombreEulalia) ?>" readonly>
          </div>
          <div class="col-md-3">
            <label class="form-label">Motivo</label>
            <select id="motivo" name="motivo" class="form-select" required>
              <option value="">— Selecciona —</option>
              <option>Venta a distribuidor</option>
              <option>Garantía</option>
              <option>Merma</option>
              <option>Utilitario</option>
              <option>Otro</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Destino (opcional)</label>
            <input id="destino" type="text" name="destino" class="form-control" placeholder="Ej. Dist. López / Taller">
          </div>
          <div class="col-md-3">
            <label class="form-label">Nota (opcional)</label>
            <input id="nota" type="text" name="nota" class="form-control" maxlength="200">
          </div>
        </div>

        <hr>

        <!-- Búsqueda (asociada al form GET separado) -->
        <div class="row g-2 align-items-end">
          <div class="col-md-6">
            <label class="form-label">Búsqueda</label>
            <div class="d-flex gap-2">
              <input id="qsearch" type="text" name="q" form="searchForm"
                     class="form-control"
                     value="<?= htmlspecialchars($f_q) ?>"
                     placeholder="Marca, modelo, color, IMEI, código…">
              <button type="submit" class="btn btn-outline-secondary" form="searchForm">Buscar</button>
            </div>
          </div>
          <div class="col-md-6 text-end">
            <!-- Este botón ya NO envía directo; ahora abre el modal de resumen -->
            <button type="button" id="btnResumen" class="btn btn-danger">Retirar seleccionados</button>
          </div>
        </div>

        <div class="table-responsive mt-3" style="max-height: 55vh; overflow:auto;">
          <table class="table table-sm table-hover align-middle" id="tablaInventario">
            <thead class="table-light">
              <tr>
                <th><input type="checkbox" id="chkAll"></th>
                <th>Marca</th>
                <th>Modelo</th>
                <th>Cap.</th>
                <th>Color</th>
                <th>IMEI</th>
                <th>Código</th>
                <th>Tipo</th>
              </tr>
            </thead>
            <tbody>
            <?php if (empty($itemsDisponibles)): ?>
              <tr><td colspan="8" class="text-center text-muted py-4">Sin resultados</td></tr>
            <?php else: foreach ($itemsDisponibles as $it): ?>
              <tr>
                <td><input type="checkbox" name="items[]" value="<?= $it['id_inventario'] ?>"></td>
                <td><?= htmlspecialchars($it['marca']) ?></td>
                <td><?= htmlspecialchars($it['modelo']) ?></td>
                <td><?= htmlspecialchars($it['capacidad']) ?></td>
                <td><?= htmlspecialchars($it['color']) ?></td>
                <td><?= htmlspecialchars($it['imei1']) ?></td>
                <td><?= htmlspecialchars($it['codigo_producto']) ?></td>
                <td><?= htmlspecialchars($it['tipo_producto']) ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

      </form>
    </div>
  </div>

  <!-- ===== Historial (Eulalia) con opción de revertir ===== -->
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span>Historial de retiros (últimos 200) — Eulalia</span>
    </div>
    <div class="card-body">
      <form method="GET" class="row g-2 mb-3">
        <div class="col-md-3">
          <label class="form-label">Motivo</label>
          <select name="h_motivo" class="form-select" onchange="this.form.submit()">
            <option value="">— Todos —</option>
            <?php
              $motivos = ['Venta a distribuidor','Garantía','Merma','Utilitario','Otro'];
              foreach ($motivos as $m) {
                $sel = ($h_motivo === $m) ? 'selected' : '';
                echo "<option $sel>".htmlspecialchars($m)."</option>";
              }
            ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Estado</label>
          <select name="h_estado" class="form-select" onchange="this.form.submit()">
            <option value="" <?= $h_estado===''?'selected':'' ?>>— Todos —</option>
            <option value="vigente" <?= $h_estado==='vigente'?'selected':'' ?>>Vigente</option>
            <option value="revertido" <?= $h_estado==='revertido'?'selected':'' ?>>Revertido</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Folio</label>
          <input type="text" name="h_folio" class="form-control" value="<?= htmlspecialchars($h_qfolio) ?>" placeholder="Buscar folio…">
        </div>
        <div class="col-md-3 d-flex align-items-end">
          <button class="btn btn-outline-secondary w-100">Filtrar</button>
        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead class="table-light">
            <tr>
              <th>Folio</th>
              <th>Fecha</th>
              <th>Usuario</th>
              <th>Motivo</th>
              <th>Destino</th>
              <th>Cantidad</th>
              <th>Estado</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($historial)): ?>
              <tr><td colspan="8" class="text-center text-muted py-4">Sin retiros registrados</td></tr>
            <?php else: foreach ($historial as $h): ?>
              <tr>
                <td><span class="badge bg-dark"><?= htmlspecialchars($h['folio']) ?></span></td>
                <td><?= htmlspecialchars($h['fecha']) ?></td>
                <td><?= htmlspecialchars($h['usuario_nombre'] ?? 'N/D') ?></td>
                <td><?= htmlspecialchars($h['motivo']) ?></td>
                <td><?= htmlspecialchars($h['destino'] ?? '') ?></td>
                <td><strong><?= (int)$h['cantidad'] ?></strong></td>
                <td>
                  <?php if ((int)$h['revertido'] === 1): ?>
                    <span class="badge bg-secondary">Revertido</span><br>
                    <small class="text-muted"><?= htmlspecialchars($h['fecha_reversion']) ?></small>
                  <?php else: ?>
                    <span class="badge bg-success">Vigente</span>
                  <?php endif; ?>
                </td>
                <td class="d-flex gap-2">
                  <!-- Ver detalle -->
                  <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#det<?= $h['id'] ?>">Detalle</button>

                  <!-- Revertir -->
                  <?php if ((int)$h['revertido'] === 0): ?>
                    <form action="revertir_retiro.php" method="POST" onsubmit="return confirmarReversion();">
                      <input type="hidden" name="id_retiro" value="<?= (int)$h['id'] ?>">
                      <input type="hidden" name="id_sucursal" value="<?= $idEulalia ?>">
                      <input type="text" name="nota_reversion" class="form-control form-control-sm d-inline-block" style="width: 180px;" placeholder="Nota de reversión (opcional)">
                      <button class="btn btn-warning btn-sm ms-1">Revertir</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
              <tr class="collapse" id="det<?= $h['id'] ?>">
                <td colspan="8">
                  <?php
                    $did = (int)$h['id'];
                    $qdet = $conn->prepare("
                      SELECT d.id_inventario, d.id_producto, d.imei1, p.marca, p.modelo, p.capacidad, p.color, p.codigo_producto
                      FROM inventario_retiros_detalle d
                      LEFT JOIN productos p ON p.id = d.id_producto
                      WHERE d.retiro_id = ?
                      ORDER BY d.id ASC
                    ");
                    $qdet->bind_param("i", $did);
                    $qdet->execute();
                    $detallito = $qdet->get_result()->fetch_all(MYSQLI_ASSOC);
                    $qdet->close();
                  ?>
                  <div class="table-responsive">
                    <table class="table table-sm mb-0">
                      <thead>
                        <tr>
                          <th>ID Inv.</th>
                          <th>Marca</th>
                          <th>Modelo</th>
                          <th>Cap.</th>
                          <th>Color</th>
                          <th>IMEI</th>
                          <th>Código</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($detallito as $d): ?>
                          <tr>
                            <td><?= (int)$d['id_inventario'] ?></td>
                            <td><?= htmlspecialchars($d['marca']) ?></td>
                            <td><?= htmlspecialchars($d['modelo']) ?></td>
                            <td><?= htmlspecialchars($d['capacidad']) ?></td>
                            <td><?= htmlspecialchars($d['color']) ?></td>
                            <td><?= htmlspecialchars($d['imei1']) ?></td>
                            <td><?= htmlspecialchars($d['codigo_producto']) ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ===== Modal de resumen ===== -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Confirmar retiro de equipos</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <strong>Sucursal:</strong> <?= htmlspecialchars($nombreEulalia) ?><br>
          <strong>Motivo:</strong> <span id="resMotivo"></span><br>
          <strong>Destino:</strong> <span id="resDestino"></span><br>
          <strong>Nota:</strong> <span id="resNota"></span>
        </div>
        <div class="alert alert-warning py-2">
          Se retirarán <strong id="resCantidad">0</strong> equipos. Revisa la lista:
        </div>
        <div class="table-responsive" style="max-height: 50vh; overflow:auto;">
          <table class="table table-sm table-striped align-middle">
            <thead>
              <tr>
                <th>#</th>
                <th>Marca</th>
                <th>Modelo</th>
                <th>Cap.</th>
                <th>Color</th>
                <th>IMEI</th>
                <th>Código</th>
              </tr>
            </thead>
            <tbody id="resumenBody">
              <!-- Se llena por JS -->
            </tbody>
          </table>
        </div>
        <small class="text-muted">Si necesitas quitar alguno, cierra este diálogo y desmarca el equipo en la tabla.</small>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" id="btnConfirmarEnviar" class="btn btn-danger">Confirmar y retirar</button>
      </div>
    </div>
  </div>
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
// Toggle "seleccionar todo"
document.getElementById('chkAll')?.addEventListener('change', e => {
  document.querySelectorAll("input[name='items[]']").forEach(c => c.checked = e.target.checked);
});

// Búsqueda con Enter sin disparar el form de retiro
document.getElementById('qsearch')?.addEventListener('keydown', e => {
  if (e.key === 'Enter') { e.preventDefault(); document.getElementById('searchForm').submit(); }
});

// Mostrar modal de resumen
const btnResumen = document.getElementById('btnResumen');
const formRetiro = document.getElementById('formRetiro');
const confirmModalEl = document.getElementById('confirmModal');
const confirmModal = new bootstrap.Modal(confirmModalEl);

btnResumen?.addEventListener('click', () => {
  const seleccionados = Array.from(document.querySelectorAll("input[name='items[]']:checked"));
  if (seleccionados.length === 0) { alert("Selecciona al menos un equipo para retirar."); return; }

  // Llenar encabezado de resumen (motivo/destino/nota)
  document.getElementById('resMotivo').textContent  = document.getElementById('motivo').value || '—';
  document.getElementById('resDestino').textContent = document.getElementById('destino').value || '—';
  document.getElementById('resNota').textContent    = document.getElementById('nota').value || '—';
  document.getElementById('resCantidad').textContent = seleccionados.length;

  // Construir cuerpo de tabla
  const tbody = document.getElementById('resumenBody');
  tbody.innerHTML = '';
  seleccionados.forEach((chk, idx) => {
    const tr = chk.closest('tr');
    const celdas = tr.querySelectorAll('td');
    // celdas: [0]=chk, [1]=marca, [2]=modelo, [3]=cap, [4]=color, [5]=imei, [6]=codigo
    const row = document.createElement('tr');
    row.innerHTML = `
      <td>${idx+1}</td>
      <td>${celdas[1]?.textContent ?? ''}</td>
      <td>${celdas[2]?.textContent ?? ''}</td>
      <td>${celdas[3]?.textContent ?? ''}</td>
      <td>${celdas[4]?.textContent ?? ''}</td>
      <td>${celdas[5]?.textContent ?? ''}</td>
      <td>${celdas[6]?.textContent ?? ''}</td>
    `;
    tbody.appendChild(row);
  });

  // Mostrar modal
  confirmModal.show();
});

// Confirmar y enviar
document.getElementById('btnConfirmarEnviar')?.addEventListener('click', () => {
  // Verificación final por seguridad
  const any = document.querySelector("input[name='items[]']:checked");
  if (!any) { alert("No hay equipos seleccionados."); return; }
  formRetiro.submit();
});

// Confirmación para revertir
function confirmarReversion(){
  return confirm("¿Revertir el retiro? Se restaurará el estatus a 'Disponible' para todos los equipos del folio.");
}
</script>
