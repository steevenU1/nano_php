<?php
// generar_traspaso.php
// Solo Admin. Genera traspasos DESDE "Almacen Angelopolis" hacia cualquier sucursal tipo Tienda.

session_start();
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
    header("Location: 403.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

$mensaje = '';
$idUsuario = (int)($_SESSION['id_usuario'] ?? 0);

// ===================================================
// 1) Obtener ID de "Almacen Angelopolis" (exacto + fallback LIKE)
// ===================================================
$idCentral = 0;
$nombreCentralUI = 'Almacén Angelopolis'; // con acento solo para UI

// Intento exacto
if ($stmt = $conn->prepare("SELECT id FROM sucursales WHERE nombre='Almacen Angelopolis' LIMIT 1")) {
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $idCentral = (int)$row['id'];
    }
    $stmt->close();
}
if ($idCentral <= 0) {
    // Fallback por LIKE
    if ($stmt = $conn->prepare("SELECT id FROM sucursales WHERE nombre LIKE ? LIMIT 1")) {
        $like = '%Angelopolis%';
        $stmt->bind_param("s", $like);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $idCentral = (int)$row['id'];
        }
        $stmt->close();
    }
}
if ($idCentral <= 0) {
    echo "<div class='container my-4'><div class='alert alert-danger'>No se encontró la sucursal de inventario central 'Almacen Angelopolis'. Verifica el catálogo de sucursales.</div></div>";
    exit();
}

// ===================================================
// 2) Procesar TRASPASO (POST)
// ===================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['equipos']) && is_array($_POST['equipos'])) {
    $equiposSeleccionados = array_map('intval', $_POST['equipos']);
    $idSucursalDestino = (int)($_POST['sucursal_destino'] ?? 0);

    if ($idSucursalDestino <= 0) {
        $mensaje = "<div class='alert alert-warning'>Selecciona una sucursal destino.</div>";
    } elseif ($idSucursalDestino === $idCentral) {
        $mensaje = "<div class='alert alert-warning'>La sucursal destino no puede ser el mismo Almacén Central.</div>";
    } elseif (count($equiposSeleccionados) === 0) {
        $mensaje = "<div class='alert alert-warning'>No seleccionaste ningún equipo para traspasar.</div>";
    } else {
        // Crear CABECERA del traspaso (estatus Pendiente)
        $stmt = $conn->prepare("
            INSERT INTO traspasos (id_sucursal_origen, id_sucursal_destino, usuario_creo, estatus)
            VALUES (?,?,?, 'Pendiente')
        ");
        $stmt->bind_param("iii", $idCentral, $idSucursalDestino, $idUsuario);
        $stmt->execute();
        $idTraspaso = $stmt->insert_id;
        $stmt->close();

        // Insertar DETALLE y poner inventario en tránsito
        $stmtDetalle = $conn->prepare("INSERT INTO detalle_traspaso (id_traspaso, id_inventario) VALUES (?, ?)");
        $stmtUpdate  = $conn->prepare("UPDATE inventario SET estatus='En tránsito' WHERE id=? AND estatus='Disponible'");

        $afectados = 0;
        foreach ($equiposSeleccionados as $idInventario) {
            $stmtDetalle->bind_param("ii", $idTraspaso, $idInventario);
            $stmtDetalle->execute();

            $stmtUpdate->bind_param("i", $idInventario);
            $stmtUpdate->execute();
            $afectados += $stmtUpdate->affected_rows > 0 ? 1 : 0;
        }
        $stmtDetalle->close();
        $stmtUpdate->close();

        $mensaje = "<div class='alert alert-success'>✅ Traspaso #{$idTraspaso} generado con éxito. Equipos marcados en tránsito: <b>{$afectados}</b>.</div>";
    }
}

// ===================================================
// 3) Consultar INVENTARIO DISPONIBLE EN CENTRAL
// ===================================================
$sqlInv = "
SELECT i.id, p.marca, p.modelo, p.color, p.imei1, p.imei2
FROM inventario i
INNER JOIN productos p ON p.id = i.id_producto
WHERE i.id_sucursal=? AND i.estatus='Disponible'
ORDER BY i.fecha_ingreso ASC
";
$stmt = $conn->prepare($sqlInv);
$stmt->bind_param("i", $idCentral);
$stmt->execute();
$invResult = $stmt->get_result();
$inventario = $invResult->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ===================================================
// 4) Sucursales DESTINO (todas las Tienda, excluyendo Central)
// ===================================================
$sucursales = [];
$resSuc = $conn->query("SELECT id, nombre FROM sucursales WHERE tipo_sucursal='Tienda' ORDER BY nombre ASC");
while ($row = $resSuc->fetch_assoc()) {
    if ((int)$row['id'] !== $idCentral) $sucursales[] = $row;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Generar Traspaso (<?= htmlspecialchars($nombreCentralUI) ?>)</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<div class="container mt-4">
    <h2>🚚 Generar Traspaso desde — <?= htmlspecialchars($nombreCentralUI) ?></h2>
    <?= $mensaje ?>

    <div class="row">
      <div class="col-lg-8">
        <div class="card mb-4 shadow">
            <div class="card-header bg-dark text-white">Seleccionar sucursal destino</div>
            <div class="card-body">
                <form id="formTraspaso" method="POST">
                    <div class="row mb-3">
                        <div class="col-md-8">
                            <select name="sucursal_destino" id="sucursal_destino" class="form-select" required>
                                <option value="">-- Selecciona Sucursal --</option>
                                <?php foreach ($sucursales as $s): ?>
                                    <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- 🔎 Buscador de IMEI/marca/modelo -->
                    <div class="mb-2">
                        <input type="text" id="buscadorIMEI" class="form-control" placeholder="Buscar por IMEI, marca o modelo...">
                    </div>

                    <!-- 🧾 Tabla de inventario con filtro -->
                    <div class="card shadow">
                        <div class="card-header bg-primary text-white d-flex align-items-center justify-content-between">
                            <span>Inventario disponible en <?= htmlspecialchars($nombreCentralUI) ?></span>
                            <div class="form-check">
                              <input class="form-check-input" type="checkbox" id="checkAll">
                              <label class="form-check-label" for="checkAll">Seleccionar todos</label>
                            </div>
                        </div>
        <?php // cargamos la tabla con los equipos disponibles ?>
                        <div class="card-body p-0">
                            <table class="table table-striped table-bordered table-sm mb-0" id="tablaInventario">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Sel</th>
                                        <th>ID Inv</th>
                                        <th>Marca</th>
                                        <th>Modelo</th>
                                        <th>Color</th>
                                        <th>IMEI1</th>
                                        <th>IMEI2</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($inventario)): ?>
                                        <tr><td colspan="7" class="text-center text-muted py-4">Sin equipos disponibles en <?= htmlspecialchars($nombreCentralUI) ?></td></tr>
                                    <?php else: foreach ($inventario as $row): ?>
                                        <tr data-id="<?= (int)$row['id'] ?>">
                                            <td><input type="checkbox" name="equipos[]" value="<?= (int)$row['id'] ?>" class="chk-equipo"></td>
                                            <td class="td-id"><?= (int)$row['id'] ?></td>
                                            <td class="td-marca"><?= htmlspecialchars($row['marca']) ?></td>
                                            <td class="td-modelo"><?= htmlspecialchars($row['modelo']) ?></td>
                                            <td class="td-color"><?= htmlspecialchars($row['color']) ?></td>
                                            <td class="td-imei1"><?= htmlspecialchars($row['imei1']) ?></td>
                                            <td class="td-imei2"><?= htmlspecialchars($row['imei2'] ?: '-') ?></td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="mt-3 d-flex gap-2">
                        <button type="button" id="btnConfirmar" class="btn btn-success">Confirmar traspaso</button>
                        <button type="reset" class="btn btn-outline-secondary">Limpiar</button>
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
                            <p><b>Origen:</b> <?= htmlspecialchars($nombreCentralUI) ?></p>
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
        </div>
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
// Filtro IMEI/marca/modelo
document.getElementById('buscadorIMEI').addEventListener('keyup', function() {
  const filtro = this.value.toLowerCase();
  document.querySelectorAll('#tablaInventario tbody tr').forEach(function(row) {
    const txt = row.innerText.toLowerCase();
    row.style.display = txt.includes(filtro) ? '' : 'none';
  });
});

// Select all
document.getElementById('checkAll').addEventListener('change', function(){
  const checked = this.checked;
  document.querySelectorAll('.chk-equipo').forEach(chk => { 
    chk.checked = checked; 
  });
  rebuildSelection();
});

// Rebuild selection list
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

// click en tabla seleccion para quitar item
document.querySelector('#tablaSeleccion tbody').addEventListener('click', function(e){
  if (e.target.matches('button[data-id]')) {
    const id = e.target.getAttribute('data-id');
    const chk = document.querySelector(`.chk-equipo[value="${id}"]`);
    if (chk) chk.checked = false;
    rebuildSelection();
  }
});

// cambiar destino pequeño
document.getElementById('sucursal_destino').addEventListener('change', function(){
  const txt = this.options[this.selectedIndex]?.text || '—';
  document.getElementById('miniDestino').textContent = `Destino: ${txt}`;
});

// Escuchar checks individuales
document.querySelectorAll('.chk-equipo').forEach(chk => {
  chk.addEventListener('change', rebuildSelection);
});

// Modal de confirmación
const modalResumen = new bootstrap.Modal(document.getElementById('modalResumen'));
function openResumen() {
  const sel = document.getElementById('sucursal_destino');
  const sucTxt = sel.value ? sel.options[sel.selectedIndex].text : '';
  const seleccionados = document.querySelectorAll('.chk-equipo:checked');

  if (!sel.value) {
    alert('Selecciona una sucursal destino.');
    sel.focus();
    return;
  }
  if (parseInt(sel.value, 10) === <?= $idCentral ?>) {
    alert('La sucursal destino no puede ser el mismo Almacén Central.');
    return;
  }
  if (seleccionados.length === 0) {
    alert('Selecciona al menos un equipo.');
    return;
  }

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
