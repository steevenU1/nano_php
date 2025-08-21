<?php
// venta_master_admin.php — Tema claro + Modal confirmación
include 'navbar.php'; // ya inicia la sesión

// Solo Admin
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
    header("Location: 403.php");
    exit();
}

require_once __DIR__ . '/db.php';

$id_usuario = (int)$_SESSION['id_usuario'];

// Traer sucursales Master Admin
$sql_suc = "SELECT id, nombre FROM sucursales WHERE subtipo='Master Admin' ORDER BY nombre";
$sucursales = $conn->query($sql_suc)->fetch_all(MYSQLI_ASSOC);

// Helper de fecha local → value para datetime-local (YYYY-MM-DDTHH:MM)
date_default_timezone_set('America/Mexico_City');
$nowLocal = date('Y-m-d\TH:i');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title>Nueva Venta (Master Admin)</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
  <!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
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
  </style>
</head>
<body>

<div class="container py-4">
  <!-- Encabezado -->
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h2 class="page-title mb-1">Registrar Nueva Venta — <span class="text-primary">Master Admin</span></h2>
      <div class="text-muted">Selecciona primero el <strong>Tipo de Venta</strong> y confirma en el modal antes de enviar.</div>
    </div>
    <a href="panel.php" class="btn btn-outline-secondary">← Volver al Panel</a>
  </div>

  <!-- Chips de sesión (opcionales) -->
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

    <!-- TIPO DE VENTA / OPERACIÓN -->
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
            <label class="form-label">Sucursal de la Venta *</label>
            <select name="id_sucursal" id="id_sucursal" class="form-select" required>
              <option value="">Seleccione...</option>
              <?php foreach ($sucursales as $s): ?>
                <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="help">Puedes registrar en otra sucursal si operaste ahí.</div>
          </div>

          <div class="col-md-4">
            <label class="form-label">Origen del equipo *</label>
            <select name="origen_ma" id="origen_ma" class="form-select" required>
              <option value="">Seleccione...</option>
              <option value="nano">Inventario Nano</option>
              <option value="propio">Inventario propio del Master Admin</option>
            </select>
          </div>
        </div>

        <div class="row g-3 mt-1">
          <div class="col-md-4">
            <label class="form-label">Fecha de la venta</label>
            <input type="datetime-local" name="fecha_venta" id="fecha_venta" class="form-control" value="<?= $nowLocal ?>">
            <div class="help">Si la dejas vacía, se usa la fecha/hora actual.</div>
          </div>
        </div>
      </div>
    </div>

    <!-- CLIENTE -->
    <div class="card card-neo mb-3">
      <div class="card-header">
        <span class="section-title">👥 Datos del Cliente</span>
        <span class="badge badge-soft ms-2">Requerido si Origen = Nano</span>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Nombre del Cliente</label>
            <input type="text" name="nombre_cliente" id="nombre_cliente" class="form-control" placeholder="Nombre y apellidos">
          </div>
          <div class="col-md-6">
            <label class="form-label">Teléfono del Cliente</label>
            <input type="text" name="telefono_cliente" id="telefono_cliente" class="form-control" placeholder="10 dígitos">
          </div>
        </div>
        <div class="row g-3 mt-1">
          <div class="col-md-6" id="tag_field">
            <label class="form-label">TAG (ID del crédito)</label>
            <input type="text" name="tag" id="tag" class="form-control">
          </div>
        </div>
      </div>
    </div>

    <!-- EQUIPOS (solo Nano) -->
    <div class="card card-neo mb-3" id="card_equipos">
      <div class="card-header">
        <span class="section-title">📱 Equipos</span>
        <span class="badge badge-soft ms-2">Desde inventario Nano</span>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Equipo Principal</label>
            <select name="equipo1" id="equipo1" class="form-select select2-equipo"></select>
            <div class="help">Si tu AJAX lo permite, agrega <code>data-comisionma</code> a las opciones.</div>
          </div>
          <div class="col-md-6" id="combo_wrap">
            <label class="form-label">Equipo Combo (opcional)</label>
            <select name="equipo2" id="equipo2" class="form-select select2-equipo"></select>
          </div>
        </div>
      </div>
    </div>

    <!-- FINANZAS -->
    <div class="card card-neo mb-4">
      <div class="card-header">
        <span class="section-title">💵 Datos Financieros</span>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Precio de Venta Total ($)</label>
            <input type="number" step="0.01" min="0.01" name="precio_venta" id="precio_venta" class="form-control" required>
          </div>
          <div class="col-md-4" id="enganche_field">
            <label class="form-label">Enganche ($)</label>
            <input type="number" step="0.01" min="0" name="enganche" id="enganche" class="form-control" value="0">
          </div>
          <div class="col-md-4">
            <label class="form-label" id="label_forma_pago">Forma de Pago</label>
            <select name="forma_pago_enganche" id="forma_pago_enganche" class="form-select" required>
              <option value="Efectivo">Efectivo</option>
              <option value="Tarjeta">Tarjeta</option>
              <option value="Mixto">Mixto</option>
            </select>
          </div>
        </div>

        <div class="row g-3 mt-1" id="mixto_detalle" style="display:none;">
          <div class="col-md-6">
            <label class="form-label">Enganche Efectivo ($)</label>
            <input type="number" step="0.01" min="0" name="enganche_efectivo" id="enganche_efectivo" class="form-control" value="0">
          </div>
          <div class="col-md-6">
            <label class="form-label">Enganche Tarjeta ($)</label>
            <input type="number" step="0.01" min="0" name="enganche_tarjeta" id="enganche_tarjeta" class="form-control" value="0">
          </div>
        </div>

        <div class="row g-3 mt-1">
          <div class="col-md-4" id="plazo_field">
            <label class="form-label">Plazo en Semanas</label>
            <input type="number" min="1" name="plazo_semanas" id="plazo_semanas" class="form-control" value="0">
          </div>
          <div class="col-md-4" id="financiera_field">
            <label class="form-label">Financiera</label>
            <select name="financiera" id="financiera" class="form-select">
              <option value="">N/A</option>
              <option value="PayJoy">PayJoy</option>
              <option value="Krediya">Krediya</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Comentarios</label>
            <input type="text" name="comentarios" class="form-control" placeholder="Notas (opcional)">
          </div>
        </div>
      </div>
    </div>

    <div class="d-grid">
      <button type="button" class="btn btn-gradient btn-lg" id="btn_preconfirm">
        Revisar y confirmar venta
      </button>
    </div>
    <button class="d-none" id="btn_submit_real">Registrar Venta</button>
  </form>
</div>

<!-- MODAL CONFIRMACIÓN -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Confirmar datos de la venta</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6">
            <div class="summary-pill mb-2"><strong>Sucursal:</strong> <span id="sum_sucursal"></span></div>
            <div class="summary-pill mb-2"><strong>Origen:</strong> <span id="sum_origen"></span></div>
            <div class="summary-pill mb-2"><strong>Tipo de venta:</strong> <span id="sum_tipo"></span></div>
            <div class="summary-pill mb-2"><strong>Fecha:</strong> <span id="sum_fecha"></span></div>
          </div>
          <div class="col-md-6">
            <div class="summary-pill mb-2"><strong>Cliente:</strong> <span id="sum_cliente"></span></div>
            <div class="summary-pill mb-2"><strong>Teléfono:</strong> <span id="sum_tel"></span></div>
            <div class="summary-pill mb-2"><strong>TAG:</strong> <span id="sum_tag"></span></div>
          </div>
        </div>

        <hr>

        <div class="row g-3">
          <div class="col-md-6">
            <div class="summary-pill mb-2"><strong>Precio venta:</strong> $<span id="sum_precio"></span></div>
            <div class="summary-pill mb-2"><strong>Forma de pago:</strong> <span id="sum_forma"></span></div>
            <div class="summary-pill mb-2"><strong>Enganche total:</strong> $<span id="sum_enganche_total"></span></div>
          </div>
          <div class="col-md-6">
            <div class="summary-pill mb-2"><strong>Plazo (semanas):</strong> <span id="sum_plazo"></span></div>
            <div class="summary-pill mb-2"><strong>Financiera:</strong> <span id="sum_financiera"></span></div>
            <div class="summary-pill mb-2">
              <strong>Comisión MA (estimada):</strong> $<span id="sum_comision_estimada"></span>
              <small class="d-block text-muted">* El cálculo final se hace al procesar.</small>
            </div>
          </div>
        </div>

        <div id="sum_equipos_wrap" class="mt-3">
          <span class="badge text-bg-light border">Equipos seleccionados</span>
          <ul class="mt-2" id="sum_equipos" style="padding-left:1.1rem;"></ul>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Volver a editar</button>
        <button class="btn btn-gradient" id="btn_confirmar_modal">Confirmar y registrar</button>
      </div>
    </div>
  </div>
</div>

<script>
$(function(){
  $('.select2-equipo').select2({ placeholder:"Buscar por modelo o IMEI", allowClear:true, width:'100%' });

  function isFin(){ const t=$('#tipo_venta').val(); return t==='Financiamiento' || t==='Financiamiento+Combo'; }
  function isCombo(){ return $('#tipo_venta').val()==='Financiamiento+Combo'; }

  function toggleCamposFin() {
    const fin = isFin();
    $('#tag_field, #enganche_field, #plazo_field, #financiera_field').toggle(fin);
    $('#mixto_detalle').toggle(fin && $('#forma_pago_enganche').val()==='Mixto');
    $('#label_forma_pago').text(fin ? 'Forma de Pago Enganche' : 'Forma de Pago');
    $('#tag,#enganche,#plazo_semanas,#financiera').prop('required', fin);

    if (!fin) {
      $('#tag').val(''); $('#enganche').val(0); $('#plazo_semanas').val(0); $('#financiera').val('');
      $('#enganche_efectivo').val(0); $('#enganche_tarjeta').val(0);
    }
  }

  function toggleEquiposYCliente() {
    const origen = $('#origen_ma').val(); // nano | propio
    const showEquipos = (origen === 'nano');

    $('#card_equipos').toggle(showEquipos);
    $('#equipo1').prop('required', showEquipos);
    $('#equipo2').prop('required', showEquipos && isCombo());

    const reqCliente = (origen === 'nano');
    $('#nombre_cliente, #telefono_cliente').prop('required', reqCliente);
  }

  $('#tipo_venta').on('change', function(){
    $('#combo_wrap').toggle(isCombo());
    toggleCamposFin();
    toggleEquiposYCliente();
  });

  $('#forma_pago_enganche').on('change', function(){
    $('#mixto_detalle').toggle($(this).val() === 'Mixto' && isFin());
  });

  $('#origen_ma').on('change', function(){ toggleEquiposYCliente(); });

  // Cargar equipos por sucursal (tu endpoint debe devolver <option> … data-comisionma="123.45")
  function cargarEquipos(idSucursal){
    if (!idSucursal) { $('#equipo1,#equipo2').html(''); return; }
    $.post('ajax_productos_por_sucursal.php', { id_sucursal: idSucursal }, function(html){
      $('#equipo1,#equipo2').html(html).val('').trigger('change');
    });
  }
  $('#id_sucursal').on('change', function(){ cargarEquipos($(this).val()); });

  // ====== Modal de confirmación ======
  const confirmModal = new bootstrap.Modal('#confirmModal');

  function money(v){ v=parseFloat(v||0); return v.toLocaleString('es-MX',{minimumFractionDigits:2,maximumFractionDigits:2}); }

  function engancheTotal(){
    const f = $('#forma_pago_enganche').val();
    if (f === 'Mixto') return parseFloat($('#enganche_efectivo').val()||0)+parseFloat($('#enganche_tarjeta').val()||0);
    return parseFloat($('#enganche').val()||0);
  }

  function getOptionText(sel){ const $o=$(sel).find('option:selected'); return $o.length?$o.text().trim():''; }
  function getOptionCM(sel){ const $o=$(sel).find('option:selected'); const raw=$o.attr('data-comisionma'); return raw?parseFloat(raw):0; }

  function estimarComision(){
    const origen = $('#origen_ma').val();
    const precio = parseFloat($('#precio_venta').val()||0);
    const engTot = engancheTotal();

    if (origen === 'nano') {
      const c1 = getOptionCM('#equipo1');
      const c2 = isCombo()? getOptionCM('#equipo2') : 0;
      const suma = c1 + c2;
      if (suma === 0) return null; // sin data-comisionma en opciones
      return (suma - engTot);
    } else if (origen === 'propio') {
      return (precio - engTot - (precio * 0.10));
    }
    return null;
  }

  function llenarResumen(){
    $('#sum_sucursal').text(getOptionText('#id_sucursal'));
    $('#sum_origen').text($('#origen_ma').val()==='nano'?'Inventario Nano':'Inventario propio MA');
    $('#sum_tipo').text($('#tipo_venta').val()||'');
    $('#sum_fecha').text(($('#fecha_venta').val()||'').replace('T',' '));

    $('#sum_cliente').text($('#nombre_cliente').val()||'—');
    $('#sum_tel').text($('#telefono_cliente').val()||'—');
    $('#sum_tag').text($('#tag').val()||'—');

    $('#sum_precio').text(money($('#precio_venta').val()));
    $('#sum_forma').text($('#forma_pago_enganche').val());
    $('#sum_enganche_total').text(money(engancheTotal()));
    $('#sum_plazo').text($('#plazo_semanas').val()||'—');
    $('#sum_financiera').text($('#financiera').val()||'N/A');

    const equipos = [];
    const t1 = getOptionText('#equipo1'); if (t1) equipos.push(t1);
    const t2 = getOptionText('#equipo2'); if (t2) equipos.push(t2);
    const $ul = $('#sum_equipos').empty();
    if (equipos.length){ equipos.forEach(t => $ul.append('<li>'+t+'</li>')); $('#sum_equipos_wrap').show(); }
    else { $('#sum_equipos_wrap').hide(); }

    const est = estimarComision();
    $('#sum_comision_estimada').text(est===null ? 'No disponible' : money(est));
  }

  // Validación + abrir modal
  $('#btn_preconfirm').on('click', function(){
    const errores = [];
    const origen = $('#origen_ma').val();
    const nombre = $('#nombre_cliente').val().trim();
    const tel    = $('#telefono_cliente').val().trim();
    const precio = parseFloat($('#precio_venta').val()||0);

    if (!$('#tipo_venta').val()) errores.push('Selecciona el tipo de venta.');
    if (!$('#id_sucursal').val()) errores.push('Selecciona la sucursal Master Admin.');
    if (!origen) errores.push('Selecciona el origen del equipo.');
    if (!precio || precio <= 0) errores.push('Ingresa el precio de venta.');

    if (origen === 'nano') {
      if (!$('#equipo1').val()) errores.push('Selecciona el equipo principal (origen Nano).');
      if (!nombre) errores.push('Ingresa el nombre del cliente (origen Nano).');
      if (!tel) errores.push('Ingresa el teléfono del cliente (origen Nano).');
      if (tel && !/^\d{10}$/.test(tel)) errores.push('El teléfono debe tener 10 dígitos.');
    } else if (origen === 'propio') {
      if (tel && !/^\d{10}$/.test(tel)) errores.push('El teléfono debe tener 10 dígitos.');
    }

    if (isFin() && !$('#tag').val()) errores.push('TAG es requerido en financiamiento.');

    if (errores.length){
      $('#errores').removeClass('d-none').html('<strong>Corrige lo siguiente:</strong><ul class="mb-0"><li>'+errores.join('</li><li>')+'</li></ul>');
      window.scrollTo({ top: 0, behavior: 'smooth' });
      return;
    }

    $('#errores').addClass('d-none').empty();
    llenarResumen();
    new bootstrap.Modal('#confirmModal').show();
  });

  // Confirmar -> enviar
  $('#btn_confirmar_modal').on('click', function(){ $('#btn_submit_real').trigger('click'); });

  // Inicial
  $('#combo_wrap').hide();
  $('#card_equipos').hide();
  $('.select2-equipo').select2({ width:'100%' });
  function init(){ 
    const fin = isFin(); 
    $('#tag_field, #enganche_field, #plazo_field, #financiera_field').toggle(fin);
  }
  init(); toggleEquiposYCliente();
});
</script>
</body>
</html>
