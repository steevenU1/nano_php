<?php
// venta_master_admin.php — Captura de ventas para Master Admin / Socio
include 'navbar.php'; // ya inicia la sesión

// Solo Admin
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
    header("Location: 403.php");
    exit();
}

require_once __DIR__ . '/db.php';

$id_usuario = (int)$_SESSION['id_usuario'];

// Traer sucursales Master Admin + Socio
$sql_suc = "SELECT id, nombre, subtipo 
            FROM sucursales 
            WHERE subtipo IN ('Master Admin','Socio') 
            ORDER BY nombre";
$sucursales = $conn->query($sql_suc)->fetch_all(MYSQLI_ASSOC);

// Helper de fecha local → value para datetime-local
date_default_timezone_set('America/Mexico_City');
$nowLocal = date('Y-m-d\TH:i');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title>Nueva Venta (MA / Socio)</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
  <style>
    body{background:#f6f7fb;}
    .page-title{color:#1f2937}
    .card-neo{background:#ffffff;border:1px solid #e5e7eb;border-radius:16px;box-shadow:0 4px 16px rgba(17,24,39,.06)}
    .card-neo .card-header{background:#ffffff;border-bottom:1px solid #eef0f4;color:#374151}
    .help{color:#6b7280;font-size:.85rem}
    .section-title{font-weight:700;letter-spacing:.02em;color:#111827}
    .badge-soft{background:#eef2ff;color:#4f46e5;border:1px solid #e0e7ff}
    .summary-pill{background:#f9fafb;border:1px solid #e5e7eb;border-radius:999px;padding:.4rem .8rem;color:#374151}
    .btn-gradient{background:linear-gradient(135deg,#16a34a,#3b82f6);border:none}
    .btn-gradient:hover{filter:brightness(1.05)}
    .select2-container--default .select2-selection--single{height:38px;border-color:#d1d5db;border-radius:.5rem}
    .select2-container--default .select2-selection__rendered{line-height:38px;color:#111827;padding-left:.5rem}
    .select2-container--default .select2-selection__arrow{height:38px}
    .header-chip{gap:.5rem}
    .d-none-imp{display:none!important;}
  </style>
</head>
<body>

<div class="container py-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h2 class="page-title mb-1">Registrar Nueva Venta — <span class="text-primary">Master Admin / Socio</span></h2>
      <div class="text-muted">Selecciona primero el <strong>Tipo de Venta</strong> y confirma en el modal antes de enviar.</div>
    </div>
    <a href="panel.php" class="btn btn-outline-secondary">← Volver al Panel</a>
  </div>

  <div class="d-flex flex-wrap header-chip mb-3">
    <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle me-2">
      👤 Usuario ID: <?= (int)$id_usuario ?>
    </span>
    <span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle me-2">
      🏷️ Modo: Admin
    </span>
    <span class="badge rounded-pill bg-info-subtle text-info border border-info-subtle">
      🔒 Sesión activa
    </span>
  </div>

  <div id="errores" class="alert alert-danger d-none"></div>

  <form method="POST" action="procesar_venta_master_admin.php" id="form_venta" novalidate>
    <input type="hidden" name="id_usuario" value="<?= $id_usuario ?>">
    <input type="hidden" name="origen_subtipo" id="origen_subtipo" value="">

    <!-- Tipo de Venta -->
    <div class="card card-neo mb-3">
      <div class="card-header">
        <span class="section-title">🧾 Tipo de Venta</span>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Tipo de Venta *</label>
            <select name="tipo_venta" id="tipo_venta" class="form-select" required>
              <option value="">Seleccione...</option>
              <option value="Contado">Contado</option>
              <option value="Financiamiento">Financiamiento</option>
              <option value="Financiamiento+Combo">Financiamiento + Combo</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Sucursal *</label>
            <select name="id_sucursal" id="id_sucursal" class="form-select" required>
              <option value="">Seleccione...</option>
              <?php foreach ($sucursales as $s): ?>
                <option value="<?= (int)$s['id'] ?>" data-subtipo="<?= htmlspecialchars($s['subtipo']) ?>">
                  <?= htmlspecialchars($s['nombre']) ?> — <?= htmlspecialchars($s['subtipo']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Origen del equipo *</label>
            <select name="origen_ma" id="origen_ma" class="form-select" required>
              <option value="">Seleccione...</option>
              <option value="nano">Inventario Nano</option>
              <option value="propio">Inventario propio MA / Socio</option>
            </select>
          </div>
        </div>
        <div class="row g-3 mt-1">
          <div class="col-md-4">
            <label class="form-label">Fecha de la venta</label>
            <input type="datetime-local" name="fecha_venta" id="fecha_venta" class="form-control" value="<?= $nowLocal ?>">
          </div>
        </div>
      </div>
    </div>

    <!-- Cliente -->
    <div class="card card-neo mb-3">
      <div class="card-header"><span class="section-title">👥 Datos del Cliente</span></div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6"><input type="text" name="nombre_cliente" id="nombre_cliente" class="form-control" placeholder="Nombre del cliente"></div>
        </div>
        <div class="row g-3 mt-1">
          <div class="col-md-6"><input type="text" name="telefono_cliente" id="telefono_cliente" class="form-control" placeholder="Teléfono (10 dígitos)"></div>
          <div class="col-md-6" id="tag_field"><input type="text" name="tag" id="tag" class="form-control" placeholder="TAG (ID crédito)"></div>
        </div>
      </div>
    </div>

    <!-- Equipos -->
    <div class="card card-neo mb-3" id="card_equipos">
      <div class="card-header"><span class="section-title">📱 Equipos</span></div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6"><select name="equipo1" id="equipo1" class="form-select select2-equipo"></select></div>
          <div class="col-md-6" id="combo_wrap"><select name="equipo2" id="equipo2" class="form-select select2-equipo"></select></div>
        </div>
      </div>
    </div>

    <!-- Finanzas -->
    <div class="card card-neo mb-4">
      <div class="card-header"><span class="section-title">💵 Datos Financieros</span></div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-4"><input type="number" step="0.01" name="precio_venta" id="precio_venta" class="form-control" placeholder="Precio Total"></div>
          <div class="col-md-4" id="enganche_field"><input type="number" step="0.01" name="enganche" id="enganche" class="form-control" placeholder="Enganche"></div>
          <div class="col-md-4"><select name="forma_pago_enganche" id="forma_pago_enganche" class="form-select"><option>Efectivo</option><option>Tarjeta</option><option>Mixto</option></select></div>
        </div>
      </div>
    </div>

    <div class="d-grid">
      <button type="button" class="btn btn-gradient btn-lg" id="btn_preconfirm">Revisar y confirmar venta</button>
    </div>
    <button class="d-none" id="btn_submit_real">Registrar Venta</button>
  </form>
</div>

<!-- Modal Confirmación -->
<div class="modal fade" id="confirmModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Confirmar datos de la venta</h5></div>
      <div class="modal-body">
        <div><strong>Sucursal:</strong> <span id="sum_sucursal"></span></div>
        <div><strong>Subtipo:</strong> <span id="sum_subtipo"></span></div>
        <div><strong>Tipo:</strong> <span id="sum_tipo"></span></div>
        <div><strong>Precio:</strong> $<span id="sum_precio"></span></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Volver</button>
        <button class="btn btn-gradient" id="btn_confirmar_modal">Confirmar</button>
      </div>
    </div>
  </div>
</div>

<script>
$(function(){
  // Helpers
  function selectedSubtipo(){ const $o=$('#id_sucursal').find('option:selected'); return $o.data('subtipo')||''; }
  function syncSubtipoHidden(){ $('#origen_subtipo').val(selectedSubtipo()); }

  function toggleEquipos(){
    const origen = $('#origen_ma').val();          // nano | propio
    const show = (origen === 'nano');
    $('#card_equipos').toggleClass('d-none-imp', !show);
    // deshabilitar inputs al ocultar para que no se envíen
    $('#equipo1, #equipo2').prop('disabled', !show);
  }

  function toggleCombo(){
    const tipo = $('#tipo_venta').val();
    const showCombo = (tipo === 'Financiamiento+Combo');
    $('#combo_wrap').toggleClass('d-none-imp', !showCombo);
    $('#equipo2').prop('disabled', !showCombo || $('#origen_ma').val()!=='nano');
  }

  // Eventos
  $('#id_sucursal').on('change', syncSubtipoHidden);
  $('#origen_ma').on('change', function(){ toggleEquipos(); toggleCombo(); });
  $('#tipo_venta').on('change', toggleCombo);

  // Select2 (si luego llenas via AJAX)
  $('.select2-equipo').select2({placeholder:'Selecciona un equipo', width:'100%'});

  // Modal
  $('#btn_preconfirm').on('click', function(){
    syncSubtipoHidden();
    $('#sum_sucursal').text($('#id_sucursal option:selected').text());
    $('#sum_subtipo').text(selectedSubtipo());
    $('#sum_tipo').text($('#tipo_venta').val());
    $('#sum_precio').text($('#precio_venta').val());
    new bootstrap.Modal('#confirmModal').show();
  });
  $('#btn_confirmar_modal').on('click', ()=>$('#btn_submit_real').trigger('click'));

  // estado inicial
  toggleEquipos();
  toggleCombo();
});
</script>
</body>
</html>
