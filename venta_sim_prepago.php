<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';


$idUsuario   = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal  = (int)($_SESSION['id_sucursal'] ?? 0);
$nombreUser  = trim($_SESSION['nombre'] ?? 'Usuario');
$mensaje     = '';

/* =========================
   FUNCIONES AUXILIARES
========================= */

function obtenerEsquemaVigente($conn, $fechaVenta) {
    $sql = "SELECT * FROM esquemas_comisiones
            WHERE fecha_inicio <= ?
              AND (fecha_fin IS NULL OR fecha_fin >= ?)
              AND activo = 1
            ORDER BY fecha_inicio DESC
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $fechaVenta, $fechaVenta);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function cumpleCuotaSucursal($conn, $idSucursal, $fechaVenta) {
    $sql = "SELECT cuota_monto
            FROM cuotas_sucursales
            WHERE id_sucursal=? AND fecha_inicio <= ?
            ORDER BY fecha_inicio DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $idSucursal, $fechaVenta);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $cuota = $row['cuota_monto'] ?? 0;

    // Semana martes-lunes
    $ini = new DateTime($fechaVenta);
    $dif = $ini->format('N') - 2;
    if ($dif < 0) $dif += 7;
    $ini->modify("-$dif days")->setTime(0,0,0);
    $fin = clone $ini;
    $fin->modify("+6 days")->setTime(23,59,59);

    $q = "SELECT SUM(precio_total) AS monto
          FROM ventas_sims
          WHERE id_sucursal=? AND fecha_venta BETWEEN ? AND ?";
    $stmt2 = $conn->prepare($q);
    $inicio = $ini->format('Y-m-d H:i:s');
    $final  = $fin->format('Y-m-d H:i:s');
    $stmt2->bind_param("iss", $idSucursal, $inicio, $final);
    $stmt2->execute();
    $row2 = $stmt2->get_result()->fetch_assoc();
    $monto = $row2['monto'] ?? 0;

    return $monto >= $cuota;
}

function calcularComisionesSIM($esquema, $tipoSim, $tipoVenta, $cumpleCuota) {
    $tipoSim   = strtolower($tipoSim);
    $tipoVenta = strtolower($tipoVenta);
    $col = null;

    if ($tipoSim == 'bait') {
        $col = ($tipoVenta == 'portabilidad')
            ? ($cumpleCuota ? 'comision_sim_bait_port_con' : 'comision_sim_bait_port_sin')
            : ($cumpleCuota ? 'comision_sim_bait_nueva_con' : 'comision_sim_bait_nueva_sin');
    } elseif ($tipoSim == 'att') {
        $col = ($tipoVenta == 'portabilidad')
            ? ($cumpleCuota ? 'comision_sim_att_port_con' : 'comision_sim_att_port_sin')
            : ($cumpleCuota ? 'comision_sim_att_nueva_con' : 'comision_sim_att_nueva_sin');
    }

    return (float)($esquema[$col] ?? 0);
}

/* =========================
   PROCESAR VENTA SIM
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idSim       = (int)$_POST['id_sim'];
    $tipoVenta   = $_POST['tipo_venta'];
    $precio      = (float)$_POST['precio'];
    $comentarios = trim($_POST['comentarios']);
    $fechaVenta  = date('Y-m-d');

    // 1) Verificar SIM y OBTENER operador DESDE INVENTARIO (ignorar POST)
    $sql = "SELECT id, iccid, operador
            FROM inventario_sims
            WHERE id=? AND estatus='Disponible' AND id_sucursal=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $idSim, $idSucursal);
    $stmt->execute();
    $sim = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$sim) {
        $mensaje = '<div class="alert alert-danger">La SIM seleccionada no está disponible.</div>';
    } else {
        // Normalizar operador -> tipoSim que usa el esquema
        $tipoSim = (strtoupper($sim['operador']) === 'ATT') ? 'ATT' : 'Bait';

        // 2) Comisiones
        $esquema     = obtenerEsquemaVigente($conn, $fechaVenta);
        $cumpleCuota = cumpleCuotaSucursal($conn, $idSucursal, $fechaVenta);

        $comisionEjecutivo = calcularComisionesSIM($esquema, $tipoSim, $tipoVenta, $cumpleCuota);
        $comisionGerente   = $comisionEjecutivo > 0
            ? ($cumpleCuota ? $esquema['comision_gerente_sim_con'] : $esquema['comision_gerente_sim_sin'])
            : 0;

        // 3) Insertar venta
        $sqlVenta = "INSERT INTO ventas_sims
            (tipo_venta, tipo_sim, comentarios, precio_total, comision_ejecutivo, comision_gerente, id_usuario, id_sucursal, fecha_venta)
            VALUES (?,?,?,?,?,?,?,?,NOW())";
        $stmt = $conn->prepare($sqlVenta);
        $stmt->bind_param("sssddiii", $tipoVenta, $tipoSim, $comentarios, $precio, $comisionEjecutivo, $comisionGerente, $idUsuario, $idSucursal);
        $stmt->execute();
        $idVenta = $stmt->insert_id;
        $stmt->close();

        // 4) Detalle
        $sqlDetalle = "INSERT INTO detalle_venta_sims (id_venta, id_sim, precio_unitario) VALUES (?,?,?)";
        $stmt = $conn->prepare($sqlDetalle);
        $stmt->bind_param("iid", $idVenta, $idSim, $precio);
        $stmt->execute();
        $stmt->close();

        // 5) Actualizar inventario
        $sqlUpdate = "UPDATE inventario_sims
                      SET estatus='Vendida', id_usuario_venta=?, fecha_venta=NOW()
                      WHERE id=?";
        $stmt = $conn->prepare($sqlUpdate);
        $stmt->bind_param("ii", $idUsuario, $idSim);
        $stmt->execute();
        $stmt->close();

        $mensaje = '<div class="alert alert-success">✅ Venta de SIM registrada correctamente.</div>';
    }
}

// Nombre de sucursal del usuario
$nomSucursal = '—';
$stmtNS = $conn->prepare("SELECT nombre FROM sucursales WHERE id=? LIMIT 1");
$stmtNS->bind_param("i", $idSucursal);
$stmtNS->execute();
$rowNS = $stmtNS->get_result()->fetch_assoc();
if ($rowNS) { $nomSucursal = $rowNS['nombre']; }
$stmtNS->close();

// Listar SIMs disponibles (incluye operador)
$sql = "SELECT id, iccid, caja_id, fecha_ingreso, operador
        FROM inventario_sims
        WHERE estatus='Disponible' AND id_sucursal=?
        ORDER BY fecha_ingreso ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $idSucursal);
$stmt->execute();
$disponibles = $stmt->get_result();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Venta SIM Prepago</title>

  <!-- Bootstrap 5 -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

  <!-- Select2 -->
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

  <style>
    :root{
      --brand: #0d6efd;
      --brand-100: rgba(13,110,253,.08);
    }
    body.bg-light{
      background:
        radial-gradient(1200px 400px at 100% -50%, var(--brand-100), transparent),
        radial-gradient(1200px 400px at -10% 120%, rgba(25,135,84,.06), transparent),
        #f8fafc;
    }
    .page-title{font-weight:700; letter-spacing:.3px;}
    .card-elev{border:0; box-shadow:0 10px 24px rgba(2,8,20,0.06), 0 2px 6px rgba(2,8,20,0.05); border-radius:1rem;}
    .section-title{
      font-size:.95rem; font-weight:700; color:#334155; text-transform:uppercase;
      letter-spacing:.8px; margin-bottom:.75rem; display:flex; align-items:center; gap:.5rem;
    }
    .section-title .bi{opacity:.85;}
    .help-text{font-size:.85rem; color:#64748b;}
    .select2-container .select2-selection--single { height: 38px; border-radius:.5rem; }
    .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 36px; }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 36px; }
    .btn-gradient{background:linear-gradient(90deg,#16a34a,#22c55e); border:0;}
    .btn-gradient:disabled{opacity:.7;}
    .badge-soft{background:#eef2ff; color:#1e40af; border:1px solid #dbeafe;}
    .list-compact{margin:0; padding-left:1rem;}
    .list-compact li{margin-bottom:.25rem;}
    .readonly-hint{background:#f1f5f9;}
  </style>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container my-4">

  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h2 class="page-title mb-1"><i class="bi bi-sim me-2"></i>Venta de SIM Prepago</h2>
      <div class="help-text">Selecciona la SIM y confirma los datos en el modal antes de enviar.</div>
    </div>
  </div>

  <!-- Contexto de sesión -->
  <div class="mb-3">
    <div class="card border-0 shadow-sm">
      <div class="card-body d-flex flex-wrap align-items-center gap-2">
        <span class="badge rounded-pill text-bg-primary"><i class="bi bi-person-badge me-1"></i> Usuario: <?= htmlspecialchars($nombreUser) ?></span>
        <span class="badge rounded-pill text-bg-info"><i class="bi bi-shop me-1"></i> Tu sucursal: <?= htmlspecialchars($nomSucursal) ?></span>
        <span class="badge rounded-pill badge-soft"><i class="bi bi-shield-check me-1"></i> Sesión activa</span>
      </div>
    </div>
  </div>

  <?= $mensaje ?>

  <form method="POST" class="card card-elev p-3 mb-4" id="formVentaSim" novalidate>
    <div class="card-body">

      <div class="section-title"><i class="bi bi-collection"></i> Selección de SIM</div>
      <div class="row g-3 mb-3">
        <!-- SIM con buscador -->
        <div class="col-md-6">
          <label class="form-label">SIM disponible</label>
          <select name="id_sim" id="selectSim" class="form-select select2-sims" required>
            <option value="">-- Selecciona SIM --</option>
            <?php while($row = $disponibles->fetch_assoc()): ?>
              <option
                value="<?= (int)$row['id'] ?>"
                data-operador="<?= htmlspecialchars($row['operador']) ?>"
                data-iccid="<?= htmlspecialchars($row['iccid']) ?>"
              >
                <?= htmlspecialchars($row['iccid']) ?> | <?= htmlspecialchars($row['operador']) ?> | Caja: <?= htmlspecialchars($row['caja_id']) ?> | Ingreso: <?= htmlspecialchars($row['fecha_ingreso']) ?>
              </option>
            <?php endwhile; ?>
          </select>
          <div class="form-text">Escribe ICCID, operador o caja para filtrar.</div>
        </div>

        <!-- Tipo de SIM: SOLO LECTURA -->
        <div class="col-md-3">
          <label class="form-label">Tipo de SIM</label>
          <input type="text" id="tipoSimView" class="form-control" value="" readonly>
        </div>
      </div>

      <hr class="my-4">

      <div class="section-title"><i class="bi bi-receipt"></i> Datos de la venta</div>
      <div class="row g-3 mb-3">
        <!-- Tipo de venta -->
        <div class="col-md-3">
          <label class="form-label">Tipo de venta</label>
          <select name="tipo_venta" id="tipo_venta" class="form-select" required>
            <option value="Nueva">Nueva</option>
            <option value="Portabilidad">Portabilidad</option>
            <option value="Regalo">Regalo (costo 0)</option>
          </select>
        </div>

        <!-- Precio -->
        <div class="col-md-3">
          <label class="form-label">Precio</label>
          <input type="number" step="0.01" name="precio" id="precio" class="form-control" value="0" required>
          <div class="form-text" id="precio_help">Para “Regalo”, el precio debe ser 0.</div>
        </div>

        <div class="col-md-6">
          <label class="form-label">Comentarios</label>
          <input type="text" name="comentarios" id="comentarios" class="form-control" placeholder="Notas (opcional)">
        </div>
      </div>

    </div>
    <div class="card-footer bg-white border-0 p-3">
      <button type="submit" class="btn btn-gradient text-white w-100 py-2" id="btn_submit">
        <i class="bi bi-check2-circle me-2"></i> Registrar Venta
      </button>
    </div>
  </form>
</div>

<!-- Modal de Confirmación -->
<div class="modal fade" id="modalConfirmacion" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title"><i class="bi bi-patch-question me-2 text-primary"></i>Confirma la venta de SIM</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info mb-3">
          <i class="bi bi-info-circle me-1"></i>
          <strong>Validación de identidad:</strong> verifica que se registrará con el <u>usuario correcto</u> y en la <u>sucursal correcta</u>.
        </div>

        <div class="row g-3">
          <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-body">
                <div class="section-title"><i class="bi bi-person-check"></i> Usuario y sucursal</div>
                <ul class="list-compact">
                  <li><strong>Usuario:</strong> <span id="conf_usuario"><?= htmlspecialchars($nombreUser) ?></span></li>
                  <li><strong>Sucursal:</strong> <span id="conf_sucursal"><?= htmlspecialchars($nomSucursal) ?></span></li>
                </ul>
              </div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-body">
                <div class="section-title"><i class="bi bi-sim"></i> Detalle de venta</div>
                <ul class="list-compact">
                  <li><strong>ICCID:</strong> <span id="conf_iccid">—</span></li>
                  <li><strong>Operador:</strong> <span id="conf_operador">—</span></li>
                  <li><strong>Tipo de venta:</strong> <span id="conf_tipo">—</span></li>
                  <li><strong>Precio:</strong> $<span id="conf_precio">0.00</span></li>
                  <li class="text-muted"><em>Comentarios:</em> <span id="conf_comentarios">—</span></li>
                </ul>
              </div>
            </div>
          </div>
        </div>

        <hr>
        <div class="help-text">
          Si detectas un error, cierra este modal y corrige los datos. Si todo es correcto, confirma para enviar.
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">
          <i class="bi bi-pencil-square me-1"></i> Corregir
        </button>
        <button class="btn btn-primary" id="btn_confirmar_envio">
          <i class="bi bi-send-check me-1"></i> Confirmar y enviar
        </button>
      </div>
    </div>
  </div>
</div>

<!-- JS: Bootstrap bundle + jQuery + Select2 -->
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(function(){
  const modalConfirm = new bootstrap.Modal(document.getElementById('modalConfirmacion'));
  const $form   = $('#formVentaSim');
  const $simSel = $('#selectSim');
  const $precio = $('#precio');
  const $tipo   = $('#tipo_venta');
  const $coment = $('#comentarios');
  const $tipoSimView = $('#tipoSimView');

  // Select2
  $simSel.select2({
    placeholder: '-- Selecciona SIM --',
    width: '100%',
    language: {
      noResults: () => 'Sin resultados',
      searching: () => 'Buscando…'
    }
  });

  function actualizarTipo() {
    const $opt = $simSel.find(':selected');
    const operador = ($opt.data('operador') || '').toString().trim();
    $tipoSimView.val(operador || '');
  }
  actualizarTipo();
  $simSel.on('change', actualizarTipo);

  // Reglas de precio según tipo + auto 0 y readonly para Regalo
  function ajustarAyudaPrecio(){
    if ($tipo.val() === 'Regalo') {
      // Fijar precio en 0 y solo lectura
      $precio.val('0.00').prop('readonly', true).addClass('readonly-hint');
      $('#precio_help').text('Para “Regalo”, el precio es 0 y no se puede editar.');
    } else {
      // Habilitar edición
      $precio.prop('readonly', false).removeClass('readonly-hint');
      if ($tipo.val() === 'Nueva' || $tipo.val() === 'Portabilidad') {
        $('#precio_help').text('Para “Nueva” o “Portabilidad”, el precio debe ser mayor a 0.');
      } else {
        $('#precio_help').text('Define el precio de la SIM.');
      }
      // Si veníamos de Regalo y quedó 0, no forzamos valor, el usuario puede editar
    }
  }
  ajustarAyudaPrecio();
  $tipo.on('change', ajustarAyudaPrecio);

  // Validación + Modal
  let allowSubmit = false;

  function validar() {
    const errores = [];

    const idSim = $simSel.val();
    const tipo  = $tipo.val();
    const precio = parseFloat($precio.val());

    if (!idSim) errores.push('Selecciona una SIM disponible.');
    if (!tipo) errores.push('Selecciona el tipo de venta.');

    if (tipo === 'Regalo') {
      if (isNaN(precio) || Number(precio.toFixed(2)) !== 0) errores.push('En “Regalo”, el precio debe ser exactamente 0.');
    } else {
      if (isNaN(precio) || precio <= 0) errores.push('El precio debe ser mayor a 0 para Nueva/Portabilidad.');
    }

    return errores;
  }

  function poblarModal(){
    const $opt = $simSel.find(':selected');
    const iccid = ($opt.data('iccid') || '').toString();
    const operador = ($opt.data('operador') || '').toString();
    const tipo = $tipo.val() || '—';
    const precio = parseFloat($precio.val()) || 0;
    const comentarios = ($coment.val() || '').trim();

    $('#conf_iccid').text(iccid || '—');
    $('#conf_operador').text(operador || '—');
    $('#conf_tipo').text(tipo);
    $('#conf_precio').text(precio.toFixed(2));
    $('#conf_comentarios').text(comentarios || '—');
  }

  $form.on('submit', function(e){
    if (allowSubmit) return; // ya confirmado

    e.preventDefault();
    const errs = validar();
    if (errs.length) {
      alert('Corrige lo siguiente:\n• ' + errs.join('\n• '));
      return;
    }

    poblarModal();
    modalConfirm.show();
  });

  $('#btn_confirmar_envio').on('click', function(){
    $('#btn_submit').prop('disabled', true).text('Enviando...');
    allowSubmit = true;
    modalConfirm.hide();
    $form[0].submit();
  });
});
</script>

</body>
</html>
