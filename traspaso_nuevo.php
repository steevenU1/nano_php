<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

$idUsuario = $_SESSION['id_usuario'];
$idSucursal = $_SESSION['id_sucursal'];
$mensaje = "";

// 🔹 Procesar traspaso
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['equipos']) && isset($_POST['sucursal_destino'])) {
    $sucursalDestino = (int)$_POST['sucursal_destino'];
    $equiposSeleccionados = $_POST['equipos'];

    if ($sucursalDestino <= 0) {
        $mensaje = "<div class='alert alert-warning'>Selecciona una sucursal destino.</div>";
    } elseif (!empty($equiposSeleccionados)) {
        // 1️⃣ Insertar traspaso
        $stmt = $conn->prepare("INSERT INTO traspasos (id_sucursal_origen, id_sucursal_destino, fecha_traspaso, estatus, usuario_creo)
                                VALUES (?, ?, NOW(), 'Pendiente', ?)");
        $stmt->bind_param("iii", $idSucursal, $sucursalDestino, $idUsuario);
        $stmt->execute();
        $idTraspaso = $stmt->insert_id;
        $stmt->close();

        // 2️⃣ Insertar detalle y actualizar inventario
        $stmtDetalle = $conn->prepare("INSERT INTO detalle_traspaso (id_traspaso, id_inventario) VALUES (?, ?)");
        $stmtUpdateInv = $conn->prepare("UPDATE inventario SET estatus='En tránsito' WHERE id=?");

        foreach ($equiposSeleccionados as $idInventario) {
            $idInventario = (int)$idInventario;
            $stmtDetalle->bind_param("ii", $idTraspaso, $idInventario);
            $stmtDetalle->execute();
            $stmtUpdateInv->bind_param("i", $idInventario);
            $stmtUpdateInv->execute();
        }

        $stmtDetalle->close();
        $stmtUpdateInv->close();

        $mensaje = "<div class='alert alert-success'>✅ Traspaso #$idTraspaso generado correctamente. 
                    Los equipos seleccionados ahora están en tránsito.</div>";
    } else {
        $mensaje = "<div class='alert alert-warning'>⚠️ Debes seleccionar al menos un equipo.</div>";
    }
}

// 🔹 Sucursales destino (todas menos la actual)
$sqlSucursales = "SELECT id, nombre FROM sucursales WHERE id != ? ORDER BY nombre";
$stmtSuc = $conn->prepare($sqlSucursales);
$stmtSuc->bind_param("i", $idSucursal);
$stmtSuc->execute();
$sucursales = $stmtSuc->get_result();

// 🔹 Inventario disponible
$sqlInventario = "
    SELECT i.id, p.marca, p.modelo, p.color, p.capacidad, p.imei1, p.imei2
    FROM inventario i
    INNER JOIN productos p ON p.id = i.id_producto
    WHERE i.id_sucursal = ? AND i.estatus = 'Disponible'
    ORDER BY p.marca, p.modelo
";
$stmtInv = $conn->prepare($sqlInventario);
$stmtInv->bind_param("i", $idSucursal);
$stmtInv->execute();
$inventario = $stmtInv->get_result();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Nuevo Traspaso</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>🚚 Generar Traspaso de Equipos</h2>
    <?= $mensaje ?>

    <div class="row">
      <div class="col-lg-8">
        <form id="formTraspaso" method="POST" class="card p-3 mb-4 shadow-sm bg-white">
            <div class="mb-3">
                <label><strong>Sucursal destino:</strong></label>
                <select name="sucursal_destino" id="sucursal_destino" class="form-select w-auto" required>
                    <option value="">-- Selecciona --</option>
                    <?php while ($s = $sucursales->fetch_assoc()): ?>
                        <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['nombre']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>

            <!-- 🔹 Buscador -->
            <div class="mb-3">
                <input type="text" id="buscarIMEI" class="form-control" placeholder="Buscar por IMEI, Marca o Modelo...">
            </div>

            <div class="d-flex align-items-center justify-content-between mb-2">
              <h5 class="mb-0">Selecciona equipos a traspasar:</h5>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="checkAll">
                <label class="form-check-label" for="checkAll">Seleccionar todos</label>
              </div>
            </div>

            <div class="table-responsive">
              <table class="table table-bordered table-striped table-hover align-middle" id="tablaInventario">
                  <thead class="table-dark">
                      <tr>
                          <th></th>
                          <th>ID Inv</th>
                          <th>Marca</th>
                          <th>Modelo</th>
                          <th>Color</th>
                          <th>Capacidad</th>
                          <th>IMEI1</th>
                          <th>IMEI2</th>
                      </tr>
                  </thead>
                  <tbody>
                      <?php if ($inventario->num_rows > 0): ?>
                          <?php while ($row = $inventario->fetch_assoc()): ?>
                              <tr data-id="<?= $row['id'] ?>">
                                  <td><input type="checkbox" name="equipos[]" value="<?= $row['id'] ?>" class="chk-equipo"></td>
                                  <td class="td-id"><?= $row['id'] ?></td>
                                  <td class="td-marca"><?= htmlspecialchars($row['marca']) ?></td>
                                  <td class="td-modelo"><?= htmlspecialchars($row['modelo']) ?></td>
                                  <td><?= htmlspecialchars($row['color']) ?></td>
                                  <td><?= htmlspecialchars($row['capacidad'] ?: '-') ?></td>
                                  <td class="td-imei1"><?= htmlspecialchars($row['imei1']) ?></td>
                                  <td class="td-imei2"><?= htmlspecialchars($row['imei2'] ?: '-') ?></td>
                              </tr>
                          <?php endwhile; ?>
                      <?php else: ?>
                          <tr><td colspan="8" class="text-center">No hay equipos disponibles en esta sucursal</td></tr>
                      <?php endif; ?>
                  </tbody>
              </table>
            </div>

            <div class="text-end mt-3">
                <button type="button" id="btnConfirmar" class="btn btn-success">Confirmar traspaso</button>
            </div>

            <!-- Modal de confirmación -->
            <div class="modal fade" id="modalResumen" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog modal-lg">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title">Confirmar traspaso</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                  </div>
                  <div class="modal-body">
                    <p><b>Destino:</b> <span id="resSucursal"></span></p>
                    <p><b>Cantidad:</b> <span id="resCantidad">0</span></p>
                    <div class="table-responsive">
                      <table class="table table-sm table-striped align-middle mb-0">
                        <thead>
                          <tr>
                            <th>ID</th><th>Marca</th><th>Modelo</th><th>IMEI1</th><th>IMEI2</th>
                          </tr>
                        </thead>
                        <tbody id="resTbody"></tbody>
                      </table>
                    </div>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">Generar traspaso</button>
                  </div>
                </div>
              </div>
            </div>

        </form>
      </div>

      <!-- Panel lateral de seleccionados -->
      <div class="col-lg-4">
        <div class="card sticky-top shadow" style="top: 90px;">
          <div class="card-header bg-info text-white">
            Selección actual <span class="badge bg-dark ms-2" id="badgeCount">0</span>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive" style="max-height: 360px; overflow:auto;">
              <table class="table table-sm mb-0" id="tablaSeleccion">
                <thead class="table-light">
                  <tr><th>ID</th><th>Modelo</th><th>IMEI</th><th></th></tr>
                </thead>
                <tbody></tbody>
              </table>
            </div>
          </div>
          <div class="card-footer d-flex justify-content-between">
            <small class="text-muted" id="miniDestino">Destino: —</small>
            <button class="btn btn-success btn-sm" id="btnAbrirModal">Confirmar (0)</button>
          </div>
        </div>
      </div>
    </div>
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
// Búsqueda en tiempo real
document.getElementById('buscarIMEI').addEventListener('keyup', function(){
  const filtro = this.value.toLowerCase();
  document.querySelectorAll('#tablaInventario tbody tr').forEach(tr => {
    tr.style.display = tr.innerText.toLowerCase().includes(filtro) ? '' : 'none';
  });
});

// Seleccionar todos
document.getElementById('checkAll').addEventListener('change', function(){
  const checked = this.checked;
  document.querySelectorAll('.chk-equipo').forEach(chk => { chk.checked = checked; });
  rebuildSelection();
});

// Construir lista lateral
function rebuildSelection(){
  const tbody = document.querySelector('#tablaSeleccion tbody');
  tbody.innerHTML = '';
  let count = 0;
  document.querySelectorAll('.chk-equipo:checked').forEach(chk => {
    const tr = chk.closest('tr');
    const id = tr.querySelector('.td-id').textContent.trim();
    const modelo = tr.querySelector('.td-modelo').textContent.trim();
    const marca = tr.querySelector('.td-marca').textContent.trim();
    const imei = tr.querySelector('.td-imei1').textContent.trim();
    const row = document.createElement('tr');
    row.innerHTML = `<td>${id}</td><td>${marca} ${modelo}</td><td>${imei}</td>
                     <td><button type="button" class="btn btn-sm btn-outline-danger" data-id="${id}">X</button></td>`;
    tbody.appendChild(row);
    count++;
  });
  document.getElementById('badgeCount').textContent = count;
  document.getElementById('btnAbrirModal').textContent = `Confirmar (${count})`;
}

// Quitar item desde la lista lateral
document.querySelector('#tablaSeleccion tbody').addEventListener('click', function(e){
  if (e.target.matches('button[data-id]')) {
    const id = e.target.getAttribute('data-id');
    const chk = document.querySelector(`.chk-equipo[value="${id}"]`);
    if (chk) chk.checked = false;
    rebuildSelection();
  }
});

// Actualizar mini-destino
document.getElementById('sucursal_destino').addEventListener('change', function(){
  const txt = this.options[this.selectedIndex]?.text || '—';
  document.getElementById('miniDestino').textContent = `Destino: ${txt}`;
});

// Escuchar checks individuales
document.querySelectorAll('.chk-equipo').forEach(chk => {
  chk.addEventListener('change', rebuildSelection);
});

// Abrir modal de resumen
const modalResumen = new bootstrap.Modal(document.getElementById('modalResumen'));
function openResumen() {
  const sel = document.getElementById('sucursal_destino');
  const sucTxt = sel.value ? sel.options[sel.selectedIndex].text : '';
  const seleccionados = document.querySelectorAll('.chk-equipo:checked');

  if (!sel.value) { alert('Selecciona una sucursal destino.'); sel.focus(); return; }
  if (seleccionados.length === 0) { alert('Selecciona al menos un equipo.'); return; }

  document.getElementById('resSucursal').textContent = sucTxt;
  document.getElementById('resCantidad').textContent = seleccionados.length;

  const tbody = document.getElementById('resTbody');
  tbody.innerHTML = '';
  seleccionados.forEach(chk => {
    const tr = chk.closest('tr');
    const id = tr.querySelector('.td-id').textContent.trim();
    const marca = tr.querySelector('.td-marca').textContent.trim();
    const modelo = tr.querySelector('.td-modelo').textContent.trim();
    const imei1 = tr.querySelector('.td-imei1').textContent.trim();
    const imei2 = tr.querySelector('.td-imei2').textContent.trim();
    const row = document.createElement('tr');
    row.innerHTML = `<td>${id}</td><td>${marca}</td><td>${modelo}</td><td>${imei1}</td><td>${imei2}</td>`;
    tbody.appendChild(row);
  });

  modalResumen.show();
}

document.getElementById('btnAbrirModal').addEventListener('click', openResumen);
document.getElementById('btnConfirmar').addEventListener('click', openResumen);
</script>

</body>
</html>
