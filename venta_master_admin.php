<?php
// venta_master_admin.php — Captura de ventas para Master Admin / Socio (todo en uno)
// Endpoint interno AJAX (?ajax=buscar_inventario) para buscar inventario por IMEI1/IMEI2.

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
    if (isset($_GET['ajax'])) { header('Content-Type: application/json'); echo json_encode(['results'=>[]]); exit; }
    header("Location: 403.php"); exit();
}

require_once __DIR__ . '/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$id_usuario = (int)$_SESSION['id_usuario'];

/* =========================================
   1) ENDPOINT AJAX ANTES DE IMPRIMIR HTML
========================================= */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'buscar_inventario') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $q          = trim($_GET['q'] ?? '');
    $idSucursal = (int)($_GET['id_sucursal'] ?? 0);
    $origen     = trim($_GET['origen'] ?? 'nano'); // reservado para variantes futuras
    $page       = max(1, (int)($_GET['page'] ?? 1));
    $perPage    = 30;
    $offset     = ($page - 1) * $perPage;

    if ($q === '' || $idSucursal <= 0 || $origen !== 'nano') {
        echo json_encode(['results'=>[]]); exit;
    }

    // Sanitiza LIKE (escapa % y _)
    $likeRaw = str_replace(['%','_'], ['\%','\_'], $q);
    $like    = '%' . $likeRaw . '%';

    $sql = "
        SELECT 
          inv.id       AS id_inventario,
          p.id         AS id_producto,
          p.marca, p.modelo, p.color, p.capacidad,
          p.imei1, p.imei2,
          p.precio_lista
        FROM inventario inv
        JOIN productos p ON p.id = inv.id_producto
        WHERE inv.id_sucursal = ?
          AND TRIM(UPPER(inv.estatus)) IN ('DISPONIBLE','STOCK','EN STOCK')
          AND (p.imei1 LIKE ? OR p.imei2 LIKE ?)
        ORDER BY p.marca, p.modelo
        LIMIT ? OFFSET ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('issii', $idSucursal, $like, $like, $perPage, $offset);
    $stmt->execute();
    $res = $stmt->get_result();

    $results = [];
    while ($row = $res->fetch_assoc()) {
        $cap     = ($row['capacidad'] ?? '') !== '' ? (' · ' . $row['capacidad']) : '';
        $color   = ($row['color'] ?? '') !== '' ? (' ' . $row['color']) : '';
        $imei2Tx = ($row['imei2']  ?? '') !== '' ? (' / IMEI2 ' . $row['imei2']) : '';
        $precio  = isset($row['precio_lista']) ? number_format((float)$row['precio_lista'], 2) : '0.00';
        $text    = "{$row['marca']} {$row['modelo']}{$color}{$cap} • IMEI1 {$row['imei1']}{$imei2Tx} • $ {$precio}";

        $results[] = [
            'id'      => (string)$row['id_inventario'],  // ID exacto de inventario
            'text'    => $text,
            'detalle' => "ProdID {$row['id_producto']} | InvID {$row['id_inventario']}",
            'meta'    => [
                'id_producto'   => (int)$row['id_producto'],
                'id_inventario' => (int)$row['id_inventario'],
                'imei1'         => $row['imei1'],
                'imei2'         => $row['imei2'],
                'marca'         => $row['marca'],
                'modelo'        => $row['modelo'],
                'color'         => $row['color'],
                'capacidad'     => $row['capacidad'],
                'precio_lista'  => (float)$row['precio_lista'],
            ],
        ];
    }

    echo json_encode([
        'results' => $results,
        'more'    => (count($results) === $perPage)
    ]);
    exit; // 🔑 MUY IMPORTANTE: no sigas al HTML
}

/* =========================================
   2) RENDER DE PÁGINA (ya sin mezclar con AJAX)
========================================= */

// Traer sucursales Master Admin + Socio
$sql_suc = "SELECT id, nombre, subtipo 
            FROM sucursales 
            WHERE subtipo IN ('Master Admin','Socio') 
            ORDER BY nombre";
$sucursales = $conn->query($sql_suc)->fetch_all(MYSQLI_ASSOC);

// Fecha local → datetime-local
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
  <!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
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

<?php include 'navbar.php'; ?>

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
      <div class="card-header d-flex align-items-center justify-content-between">
        <span class="section-title">📱 Equipos</span>
        <small class="help">Busca por IMEI1 o IMEI2 del inventario de la sucursal seleccionada</small>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Equipo principal (IMEI)*</label>
            <select name="equipo1" id="equipo1" class="form-select select2-equipo" required></select>
          </div>
          <div class="col-md-6" id="combo_wrap">
            <label class="form-label">Equipo combo (IMEI)</label>
            <select name="equipo2" id="equipo2" class="form-select select2-equipo"></select>
          </div>
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
          <div class="col-md-4">
            <select name="forma_pago_enganche" id="forma_pago_enganche" class="form-select">
              <option>Efectivo</option><option>Tarjeta</option><option>Mixto</option>
            </select>
          </div>
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
  // ===== Helpers =====
  function selectedSubtipo(){ const $o=$('#id_sucursal').find('option:selected'); return $o.data('subtipo')||''; }
  function syncSubtipoHidden(){ $('#origen_subtipo').val(selectedSubtipo()); }
  function sucursalSeleccionada(){ const idSuc = $('#id_sucursal').val(); return idSuc && idSuc !== ''; }

  function toggleEquipos(){
    const origen = $('#origen_ma').val(); // nano | propio
    const show = (origen === 'nano');
    $('#card_equipos').toggleClass('d-none-imp', !show);
    $('#equipo1, #equipo2').prop('disabled', !show);
    $('#equipo1').prop('required', show);
    if (!show) { $('#equipo1, #equipo2').val(null).trigger('change'); }
  }

  function toggleCombo(){
    const tipo = $('#tipo_venta').val();
    const showCombo = (tipo === 'Financiamiento+Combo');
    $('#combo_wrap').toggleClass('d-none-imp', !showCombo);
    $('#equipo2').prop('disabled', !showCombo || $('#origen_ma').val()!=='nano');
    if (!showCombo) $('#equipo2').val(null).trigger('change');
  }

  // ===== Select2 con AJAX (misma URL con ?ajax=buscar_inventario) =====
  function buildSelect2($el){
    $el.select2({
      placeholder:'Escribe IMEI1 o IMEI2…',
      width:'100%',
      allowClear:true,
      minimumInputLength: 4,
      ajax:{
        url: window.location.pathname + '?ajax=buscar_inventario',
        dataType: 'json',
        delay: 250,
        data: function(params){
          return {
            q: params.term || '',
            id_sucursal: $('#id_sucursal').val() || '',
            origen: $('#origen_ma').val() || 'nano',
            page: params.page || 1
          };
        },
        processResults: function(data, params){
          params.page = params.page || 1;
          return {
            results: data.results || [],
            pagination: { more: data.more === true }
          };
        },
        transport: function (params, success, failure) {
          if (!sucursalSeleccionada() || $('#origen_ma').val() !== 'nano') {
            success({results: []}); return;
          }
          const req = $.ajax(params);
          req.then(success);
          req.fail(function(xhr){
            console.error('AJAX inventario error:', xhr.status, xhr.responseText);
            failure(xhr);
          });
          return req;
        }
      },
      templateResult: function (item) {
        if (item.loading) return 'Buscando…';
        return $(`
          <div>
            <div>${item.text||''}</div>
            ${item.detalle ? `<div class="help">${item.detalle}</div>`:''}
          </div>
        `);
      },
      templateSelection: function (item) { return item.text || item.id || ''; }
    });
  }

  buildSelect2($('#equipo1'));
  buildSelect2($('#equipo2'));

  // ===== Eventos =====
  $('#id_sucursal').on('change', function(){
    syncSubtipoHidden();
    $('#equipo1, #equipo2').val(null).trigger('change');
  });

  $('#origen_ma').on('change', function(){
    toggleEquipos(); toggleCombo();
    $('#equipo1, #equipo2').val(null).trigger('change');
  });

  $('#tipo_venta').on('change', toggleCombo);

  // ===== Modal de confirmación =====
  $('#btn_preconfirm').on('click', function(){
    syncSubtipoHidden();

    const errores = [];
    if (!$('#tipo_venta').val()) errores.push('Selecciona el Tipo de Venta.');
    if (!$('#id_sucursal').val()) errores.push('Selecciona la Sucursal.');
    if (!$('#origen_ma').val()) errores.push('Selecciona el Origen del equipo.');

    if ($('#origen_ma').val()==='nano') {
      if (!$('#equipo1').val()) errores.push('Selecciona el IMEI del equipo principal.');
      if ($('#tipo_venta').val()==='Financiamiento+Combo' && !$('#equipo2').val()) errores.push('Selecciona el IMEI del equipo combo.');
    }

    if (errores.length){
      $('#errores').removeClass('d-none').html(errores.map(e=>`• ${e}`).join('<br>'));
      window.scrollTo({top:0, behavior:'smooth'}); return;
    } else { $('#errores').addClass('d-none').empty(); }

    $('#sum_sucursal').text($('#id_sucursal option:selected').text());
    $('#sum_subtipo').text(selectedSubtipo());
    $('#sum_tipo').text($('#tipo_venta').val());
    $('#sum_precio').text($('#precio_venta').val());
    new bootstrap.Modal('#confirmModal').show();
  });

  $('#btn_confirmar_modal').on('click', ()=>$('#btn_submit_real').trigger('click'));

  // Estado inicial
  (function init(){
    $('#equipo1, #equipo2').prop('disabled', true);
    toggleEquipos(); toggleCombo();
  })();
});
</script>
</body>
</html>
