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
    echo "<div class='container my-4'><div class='alert alert-danger shadow-sm'>No se encontró la sucursal de inventario central 'Almacen Angelopolis'. Verifica el catálogo de sucursales.</div></div>";
    exit();
}

// ===================================================
// 2) Procesar TRASPASO (POST)
// ===================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['equipos']) && is_array($_POST['equipos'])) {
    $equiposSeleccionados = array_map('intval', $_POST['equipos']);
    $idSucursalDestino = (int)($_POST['sucursal_destino'] ?? 0);

    if ($idSucursalDestino <= 0) {
        $mensaje = "<div class='alert alert-warning alert-dismissible fade show shadow-sm' role='alert'>
                      Selecciona una sucursal destino.
                      <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Cerrar'></button>
                    </div>";
    } elseif ($idSucursalDestino === $idCentral) {
        $mensaje = "<div class='alert alert-warning alert-dismissible fade show shadow-sm' role='alert'>
                      La sucursal destino no puede ser el mismo Almacén Central.
                      <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Cerrar'></button>
                    </div>";
    } elseif (count($equiposSeleccionados) === 0) {
        $mensaje = "<div class='alert alert-warning alert-dismissible fade show shadow-sm' role='alert'>
                      No seleccionaste ningún equipo para traspasar.
                      <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Cerrar'></button>
                    </div>";
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

        $mensaje = "<div class='alert alert-success alert-dismissible fade show shadow-sm' role='alert'>
                      <i class='bi bi-check-circle me-1'></i>
                      <strong>Traspaso #{$idTraspaso}</strong> generado con éxito. Equipos marcados en tránsito: <b>{$afectados}</b>.
                      <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Cerrar'></button>
                    </div>";
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
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap / Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

  <style>
    /* ===== Paleta clara + detalles suaves ===== */
    body { background: #f7f8fb; }
    .page-header {
      background: linear-gradient(180deg, #ffffff, #f4f6fb);
      border: 1px solid #eef0f6;
      border-radius: 18px;
      padding: 18px 20px;
      box-shadow: 0 6px 20px rgba(18, 38, 63, 0.06);
    }
    .card {
      border: 1px solid #eef0f6;
      border-radius: 16px;
      overflow: hidden;
    }
    .card-header {
      background: #ffffff;
      border-bottom: 1px solid #eef0f6;
    }
    .toolbar {
      display: flex; gap: .5rem; align-items: center; flex-wrap: wrap;
    }
    .form-select, .form-control {
      border-radius: 12px;
      border-color: #e6e9f2;
    }
    .form-control:focus, .form-select:focus {
      box-shadow: 0 0 0 .25rem rgba(13,110,253,.08);
      border-color: #c6d4ff;
    }
    /* Tabla */
    table thead th {
      font-weight: 600;
      white-space: nowrap;
    }
    #tablaInventario tbody tr:hover {
      background: #f1f5ff !important;
    }
    .table thead.sticky {
      position: sticky;
      top: 0;
      z-index: 5;
      background: #fff;
    }
    .subtle-badge {
      background: #eef4ff; color: #2c5bff; border-radius: 999px; padding: .35rem .6rem; font-size: .75rem; font-weight: 600;
    }
    .sticky-aside {
      position: sticky; top: 92px;
    }
    .btn-soft {
      background: #eef4ff; color: #2c5bff; border: 1px solid #dfe8ff;
    }
    .btn-soft:hover { background: #e5eeff; }
    .muted {
      color: #6c757d;
    }
    .search-wrap {
      position: sticky; top: 82px; z-index: 7;
      background: linear-gradient(180deg, #ffffff 40%, rgba(255,255,255,0.7));
      padding: 10px; border-bottom: 1px solid #eef0f6;
    }
    /* Chips pequeñas para filtros extras (si se agregan) */
    .chip {
      border: 1px solid #e6e9f2; border-radius: 999px; padding: .25rem .6rem; background: #fff; font-size: .8rem;
    }
  </style>
</head>
<body>

<div class="container my-4">

  <!-- Header -->
  <div class="page-header d-flex align-items-center justify-content-between mb-3">
    <div>
      <h1 class="h4 mb-1">
        <i class="bi bi-arrow-left-right me-2"></i>Generar traspaso
      </h1>
      <div class="muted">
        <span class="subtle-badge"><i class="bi bi-house-gear me-1"></i>Origen: <?= htmlspecialchars($nombreCentralUI) ?></span>
      </div>
    </div>
    <div class="toolbar">
      <a class="btn btn-outline-secondary btn-sm" href="traspasos_salientes.php">
        <i class="bi bi-clock-history me-1"></i>Histórico
      </a>
    </div>
  </div>

  <?= $mensaje ?>

  <div class="row g-4">
    <!-- Col izquierda -->
    <div class="col-lg-8">
      <div class="card shadow-sm mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-geo-alt text-primary"></i>
            <strong>Seleccionar sucursal destino</strong>
          </div>
          <span class="muted small">Requerido</span>
        </div>
        <div class="card-body">
          <form id="formTraspaso" method="POST">
            <div class="row g-2 mb-2">
              <div class="col-md-8">
                <select name="sucursal_destino" id="sucursal_destino" class="form-select" required>
                  <option value="">— Selecciona sucursal —</option>
                  <?php foreach ($sucursales as $s): ?>
                    <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['nombre']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4 d-flex align-items-center">
                <div class="muted small" id="miniDestino">Destino: —</div>
              </div>
            </div>

            <!-- Buscador sticky -->
            <div class="search-wrap rounded-3 mb-2">
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" id="buscadorIMEI" class="form-control" placeholder="Buscar por IMEI, marca o modelo...">
                <button class="btn btn-outline-secondary" type="button" id="btnLimpiarBusqueda"><i class="bi bi-x-circle"></i></button>
              </div>
            </div>

            <!-- Inventario -->
            <div class="card shadow-sm">
              <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                  <i class="bi bi-box-seam text-primary"></i>
                  <span><strong>Inventario disponible</strong> en <?= htmlspecialchars($nombreCentralUI) ?></span>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="checkAll">
                  <label class="form-check-label" for="checkAll">Seleccionar todos</label>
                </div>
              </div>

              <div class="table-responsive" style="max-height: 520px; overflow:auto;">
                <table class="table table-hover align-middle mb-0" id="tablaInventario">
                  <thead class="sticky">
                    <tr>
                      <th class="text-center">Sel</th>
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
                      <tr>
                        <td colspan="7" class="text-center text-muted py-4">
                          <i class="bi bi-inboxes me-1"></i>Sin equipos disponibles en <?= htmlspecialchars($nombreCentralUI) ?>
                        </td>
                      </tr>
                    <?php else: foreach ($inventario as $row): ?>
                      <tr data-id="<?= (int)$row['id'] ?>">
                        <td class="text-center">
                          <input type="checkbox" name="equipos[]" value="<?= (int)$row['id'] ?>" class="chk-equipo form-check-input">
                        </td>
                        <td class="td-id fw-semibold"><?= (int)$row['id'] ?></td>
                        <td class="td-marca"><?= htmlspecialchars($row['marca']) ?></td>
                        <td class="td-modelo"><?= htmlspecialchars($row['modelo']) ?></td>
                        <td class="td-color"><span class="chip"><?= htmlspecialchars($row['color']) ?></span></td>
                        <td class="td-imei1"><code><?= htmlspecialchars($row['imei1']) ?></code></td>
                        <td class="td-imei2"><?= htmlspecialchars($row['imei2'] ?: '-') ?></td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>

            <div class="d-flex flex-wrap gap-2 mt-3">
              <button type="button" id="btnConfirmar" class="btn btn-primary">
                <i class="bi bi-shuffle me-1"></i>Confirmar traspaso
              </button>
              <button type="reset" class="btn btn-outline-secondary">
                <i class="bi bi-eraser me-1"></i>Limpiar
              </button>
            </div>

            <!-- Modal de confirmación -->
            <div class="modal fade" id="modalResumen" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-check2-square me-1"></i>Confirmar traspaso</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                  </div>
                  <div class="modal-body">
                    <div class="row g-3 mb-2">
                      <div class="col-md-4">
                        <div class="small text-uppercase text-muted">Origen</div>
                        <div class="fw-semibold"><?= htmlspecialchars($nombreCentralUI) ?></div>
                      </div>
                      <div class="col-md-4">
                        <div class="small text-uppercase text-muted">Destino</div>
                        <div class="fw-semibold" id="resSucursal">—</div>
                      </div>
                      <div class="col-md-4">
                        <div class="small text-uppercase text-muted">Cantidad</div>
                        <div class="fw-semibold"><span id="resCantidad">0</span> equipos</div>
                      </div>
                    </div>

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
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send-check me-1"></i>Generar traspaso</button>
                  </div>
                </div>
              </div>
            </div>

          </form>
        </div>
      </div>
    </div>

    <!-- Col derecha (seleccionados) -->
    <div class="col-lg-4">
      <div class="card shadow-sm sticky-aside">
        <div class="card-header d-flex align-items-center justify-content-between">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-check2-square text-info"></i>
            <strong>Selección actual</strong>
          </div>
          <span class="badge bg-dark" id="badgeCount">0</span>
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
        <div class="card-footer d-flex justify-content-between align-items-center">
          <small class="text-muted" id="miniDestinoFooter">Revisa la selección antes de confirmar</small>
          <button class="btn btn-primary btn-sm" id="btnAbrirModal">
            <i class="bi bi-clipboard-check me-1"></i>Confirmar (<span id="btnCount">0</span>)
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- JS Bootstrap -->
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->

<script>
// ------- Filtro IMEI/marca/modelo -------
const buscador = document.getElementById('buscadorIMEI');
buscador.addEventListener('keyup', function() {
  const filtro = this.value.toLowerCase();
  document.querySelectorAll('#tablaInventario tbody tr').forEach(function(row) {
    const txt = row.innerText.toLowerCase();
    row.style.display = txt.includes(filtro) ? '' : 'none';
  });
});
document.getElementById('btnLimpiarBusqueda').addEventListener('click', () => {
  buscador.value = '';
  buscador.dispatchEvent(new Event('keyup'));
  buscador.focus();
});

// ------- Select all -------
const checkAll = document.getElementById('checkAll');
checkAll.addEventListener('change', function(){
  const checked = this.checked;
  document.querySelectorAll('.chk-equipo').forEach(chk => {
    if (chk.closest('tr').style.display !== 'none') { // solo visibles
      chk.checked = checked;
    }
  });
  rebuildSelection();
});

// ------- Rebuild selection list -------
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
    row.innerHTML = `
      <td class="fw-semibold">${id}</td>
      <td>${marca} ${modelo}</td>
      <td><code>${imei}</code></td>
      <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-id="${id}"><i class="bi bi-x-lg"></i></button></td>`;
    tbody.appendChild(row);
    count++;
  });
  document.getElementById('badgeCount').textContent = count;
  document.getElementById('btnCount').textContent = count;
  document.getElementById('btnAbrirModal').disabled = (count === 0);
}

// Quitar item desde la selección
document.querySelector('#tablaSeleccion tbody').addEventListener('click', function(e){
  if (e.target.closest('button[data-id]')) {
    const btn = e.target.closest('button[data-id]');
    const id = btn.getAttribute('data-id');
    const chk = document.querySelector(`.chk-equipo[value="${id}"]`);
    if (chk) chk.checked = false;
    rebuildSelection();
  }
});

// Cambiar destino (textos auxiliares)
document.getElementById('sucursal_destino').addEventListener('change', function(){
  const txt = this.options[this.selectedIndex]?.text || '—';
  document.getElementById('miniDestino').textContent = `Destino: ${txt}`;
  document.getElementById('miniDestinoFooter').textContent = `Destino: ${txt}`;
});

// Escuchar checks individuales
document.querySelectorAll('.chk-equipo').forEach(chk => {
  chk.addEventListener('change', rebuildSelection);
});

// ------- Modal de confirmación -------
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

