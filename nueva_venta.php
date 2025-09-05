<?php
include 'navbar.php';

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

$id_usuario           = (int)($_SESSION['id_usuario'] ?? 0);
$id_sucursal_usuario  = (int)($_SESSION['id_sucursal'] ?? 0);
$nombre_usuario       = trim($_SESSION['nombre'] ?? 'Usuario');

// Traer sucursales
$sql_suc = "SELECT id, nombre FROM sucursales ORDER BY nombre";
$sucursales = $conn->query($sql_suc)->fetch_all(MYSQLI_ASSOC);

// Mapa id=>nombre para uso en JS
$mapSuc = [];
foreach ($sucursales as $s) { $mapSuc[(int)$s['id']] = $s['nombre']; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Nueva Venta</title>
  <meta name="viewport" content="width=device-width, initial-scale=1"> <!-- móvil -->
  <link rel="icon" type="image/x-icon" href="./img/favicon.ico?v=2">

  <!-- Bootstrap 5 -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <!-- Select2 -->
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

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
    .req::after{content:" *"; color:#dc3545; font-weight:600;}
    .help-text{font-size:.85rem; color:#64748b;}
    .select2-container--default .select2-selection--single{height:38px; border-radius:.5rem;}
    .select2-container--default .select2-selection__rendered{line-height:38px;}
    .select2-container--default .select2-selection__arrow{height:38px}
    .alert-sucursal{border-left:4px solid #f59e0b;}
    .btn-gradient{background:linear-gradient(90deg,#16a34a,#22c55e); border:0;}
    .btn-gradient:disabled{opacity:.7;}
    .badge-soft{background:#eef2ff; color:#1e40af; border:1px solid #dbeafe;}
    .list-compact{margin:0; padding-left:1rem;}
    .list-compact li{margin-bottom:.25rem;}

    /* Barra de acción fija en móvil */
    .mobile-action{
      position: fixed; left:0; right:0; bottom:0; z-index:1040;
      background: rgba(255,255,255,.96);
      backdrop-filter: saturate(140%) blur(6px);
      border-top: 1px solid rgba(0,0,0,.06);
      padding: .75rem .9rem;
      box-shadow: 0 -8px 24px rgba(2,8,20,.06);
    }

    @media (max-width: 576px){
      .container { padding-left: .8rem; padding-right: .8rem; }
      .page-title { font-size:1.15rem; }
      .card .card-header { padding: .55rem .8rem; font-size: .95rem; }
      .card .card-body { padding: .9rem; }
      .card-elev .card-body { padding: 1rem; }
      .card .card-footer { padding: .8rem; }
      label.form-label{ font-size: .9rem; }
      .form-control, .form-select{ font-size:.95rem; padding:.55rem .7rem; border-radius:.6rem; }
      .select2-container { width: 100% !important; }
      .help-text{ font-size:.82rem; }
      .btn { border-radius:.7rem; }
      .btn-lg { padding:.8rem 1rem; font-size:1rem; }
      .alert { font-size:.95rem; }
    }
  </style>
</head>
<body class="bg-light">

<div class="container my-4 pb-5"><!-- pb extra para no tapar el botón fijo -->
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div class="me-2">
      <h2 class="page-title mb-1"><i class="bi bi-bag-plus me-2"></i>Registrar Nueva Venta</h2>
      <div class="help-text">Selecciona primero el <strong>Tipo de Venta</strong> y confirma en el modal antes de enviar.</div>
    </div>
    <a href="panel.php" class="btn btn-outline-secondary d-none d-sm-inline-flex"><i class="bi bi-arrow-left me-1"></i> Volver</a>
    <a href="panel.php" class="btn btn-outline-secondary d-inline-flex d-sm-none"><i class="bi bi-arrow-left"></i></a>
  </div>

  <!-- Contexto de sesión -->
  <div class="mb-3">
    <div class="card border-0 shadow-sm">
      <div class="card-body d-flex flex-wrap align-items-center gap-2">
        <span class="badge rounded-pill text-bg-primary"><i class="bi bi-person-badge me-1"></i> Usuario: <?= htmlspecialchars($nombre_usuario) ?></span>
        <span class="badge rounded-pill text-bg-info"><i class="bi bi-shop me-1"></i> Tu sucursal: <?= htmlspecialchars($mapSuc[$id_sucursal_usuario] ?? '—') ?></span>
        <span class="badge rounded-pill badge-soft"><i class="bi bi-shield-check me-1"></i> Sesión activa</span>
      </div>
    </div>
  </div>

  <!-- Advertencia -->
  <div id="alerta_sucursal" class="alert alert-warning alert-sucursal d-none" role="alert" aria-live="polite">
    <i class="bi bi-exclamation-triangle me-1"></i><strong>Atención:</strong> Estás eligiendo una sucursal diferente a la tuya. La venta contará para tu usuario en esa sucursal.
  </div>

  <!-- Errores -->
  <div id="errores" class="alert alert-danger d-none" role="alert"></div>

  <form method="POST" action="procesar_venta.php" id="form_venta" novalidate>
    <input type="hidden" name="id_usuario" value="<?= $id_usuario ?>">

    <div class="card card-elev mb-4">
      <div class="card-body">

        <!-- Tipo de venta primero -->
        <div class="section-title"><i class="bi bi-phone"></i> Tipo de venta</div>
        <div class="row g-3 mb-3">
          <div class="col-md-4 col-12">
            <label class="form-label req" for="tipo_venta">Tipo de Venta</label>
            <select name="tipo_venta" id="tipo_venta" class="form-select" required aria-required="true">
              <option value="">Seleccione...</option>
              <option value="Contado">Contado</option>
              <option value="Financiamiento">Financiamiento</option>
              <option value="Financiamiento+Combo">Financiamiento + Combo</option>
            </select>
          </div>
        </div>

        <hr class="my-4">

        <div class="section-title"><i class="bi bi-geo-alt"></i> Datos de operación</div>
        <div class="row g-3 mb-3">
          <div class="col-md-4 col-12">
            <label class="form-label req" for="id_sucursal">Sucursal de la Venta</label>
            <select name="id_sucursal" id="id_sucursal" class="form-select" required aria-required="true">
              <?php foreach ($sucursales as $sucursal): ?>
                <option value="<?= (int)$sucursal['id'] ?>" <?= (int)$sucursal['id'] === $id_sucursal_usuario ? 'selected' : '' ?>>
                  <?= htmlspecialchars($sucursal['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Puedes registrar en otra sucursal si operaste ahí.</div>
          </div>
        </div>

        <hr class="my-4">

        <div class="section-title"><i class="bi bi-people"></i> Datos del cliente</div>
        <div class="row g-3 mb-2">
          <div class="col-md-4 col-12" id="tag_field">
            <label for="tag" class="form-label">TAG (ID del crédito)</label>
            <input type="text" name="tag" id="tag" class="form-control" placeholder="Ej. PJ-123ABC" inputmode="latin" autocomplete="off">
          </div>
          <div class="col-md-4 col-12">
            <label class="form-label" for="nombre_cliente">Nombre del Cliente</label>
            <input type="text" name="nombre_cliente" id="nombre_cliente" class="form-control" placeholder="Nombre y apellidos" autocomplete="name">
          </div>
          <div class="col-md-4 col-12">
            <label class="form-label" for="telefono_cliente">Teléfono del Cliente</label>
            <input type="tel" name="telefono_cliente" id="telefono_cliente" class="form-control" placeholder="10 dígitos" inputmode="numeric" autocomplete="tel">
          </div>
        </div>

        <hr class="my-4">

        <div class="section-title"><i class="bi bi-device-ssd"></i> Equipos</div>
        <div class="row g-3 mb-2">
          <div class="col-md-4 col-12">
            <label class="form-label req" for="equipo1">Equipo Principal</label>
            <select name="equipo1" id="equipo1" class="form-control select2-equipo" required aria-required="true"></select>
            <div class="form-text">Busca por modelo o IMEI.</div>
          </div>
          <div class="col-md-4 col-12" id="combo" style="display:none;">
            <label class="form-label" for="equipo2">Equipo Combo</label>
            <select name="equipo2" id="equipo2" class="form-control select2-equipo"></select>
          </div>
        </div>

        <hr class="my-4">

        <div class="section-title"><i class="bi bi-cash-coin"></i> Datos financieros</div>
        <div class="row g-3 mb-2">
          <div class="col-md-4 col-12">
            <label class="form-label req" for="precio_venta">Precio de Venta Total ($)</label>
            <input type="number" step="0.01" min="0.01" name="precio_venta" id="precio_venta" class="form-control" placeholder="0.00" required inputmode="decimal">
          </div>
          <div class="col-md-4 col-12" id="enganche_field">
            <label class="form-label" for="enganche">Enganche ($)</label>
            <input type="number" step="0.01" min="0" name="enganche" id="enganche" class="form-control" value="0" placeholder="0.00" inputmode="decimal">
          </div>
          <div class="col-md-4 col-12">
            <label id="label_forma_pago" class="form-label req" for="forma_pago_enganche">Forma de Pago</label>
            <select name="forma_pago_enganche" id="forma_pago_enganche" class="form-select" required>
              <option value="Efectivo">Efectivo</option>
              <option value="Tarjeta">Tarjeta</option>
              <option value="Mixto">Mixto</option>
            </select>
          </div>
        </div>

        <div class="row g-3 mb-2" id="mixto_detalle" style="display:none;">
          <div class="col-md-6 col-12">
            <label class="form-label" for="enganche_efectivo">Enganche Efectivo ($)</label>
            <input type="number" step="0.01" min="0" name="enganche_efectivo" id="enganche_efectivo" class="form-control" value="0" placeholder="0.00" inputmode="decimal">
          </div>
          <div class="col-md-6 col-12">
            <label class="form-label" for="enganche_tarjeta">Enganche Tarjeta ($)</label>
            <input type="number" step="0.01" min="0" name="enganche_tarjeta" id="enganche_tarjeta" class="form-control" value="0" placeholder="0.00" inputmode="decimal">
          </div>
        </div>

        <div class="row g-3">
          <div class="col-md-4 col-12" id="plazo_field">
            <label class="form-label" for="plazo_semanas">Plazo en Semanas</label>
            <input type="number" min="1" name="plazo_semanas" id="plazo_semanas" class="form-control" value="0" placeholder="Ej. 52" inputmode="numeric">
          </div>
          <div class="col-md-4 col-12" id="financiera_field">
            <label class="form-label" for="financiera">Financiera</label>
            <select name="financiera" id="financiera" class="form-select">
              <option value="">N/A</option>
              <option value="PayJoy">PayJoy</option>
              <option value="Krediya">Krediya</option>
            </select>
          </div>
          <div class="col-md-4 col-12">
            <label class="form-label" for="comentarios">Comentarios</label>
            <input type="text" name="comentarios" id="comentarios" class="form-control" placeholder="Notas adicionales (opcional)" autocomplete="off">
          </div>
        </div>
      </div>
      <div class="card-footer bg-white border-0 p-3 d-none d-sm-block">
        <button type="button" class="btn btn-gradient text-white w-100 py-2" id="btn_submit">
          <i class="bi bi-check2-circle me-2"></i> Registrar Venta
        </button>
      </div>
    </div>
  </form>
</div>

<!-- Botón fijo móvil -->
<div class="mobile-action d-sm-none">
  <button type="button" class="btn btn-gradient text-white w-100 btn-lg" id="btn_submit_mobile">
    <i class="bi bi-check2-circle me-2"></i> Registrar Venta
  </button>
</div>

<!-- Modal de Confirmación -->
<div class="modal fade" id="modalConfirmacion" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title"><i class="bi bi-patch-question me-2 text-primary"></i>Confirma los datos antes de enviar</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info mb-3">
          <i class="bi bi-info-circle me-1"></i>
          <strong>Validación de identidad:</strong> verifica que la venta se registrará con el <u>usuario correcto</u> y en la <u>sucursal correcta</u>.
        </div>

        <div class="row g-3">
          <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-body">
                <div class="section-title"><i class="bi bi-person-check"></i> Usuario y sucursal</div>
                <ul class="list-compact">
                  <li><strong>Usuario:</strong> <span id="conf_usuario"><?= htmlspecialchars($nombre_usuario) ?></span></li>
                  <li><strong>Sucursal:</strong> <span id="conf_sucursal">—</span></li>
                </ul>
              </div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-body">
                <div class="section-title"><i class="bi bi-receipt"></i> Venta</div>
                <ul class="list-compact">
                  <li><strong>Tipo:</strong> <span id="conf_tipo">—</span></li>
                  <li><strong>Equipo principal:</strong> <span id="conf_equipo1">—</span></li>
                  <li class="d-none" id="li_equipo2"><strong>Equipo combo:</strong> <span id="conf_equipo2">—</span></li>
                  <li><strong>Precio total:</strong> $<span id="conf_precio">0.00</span></li>
                  <li class="d-none" id="li_enganche"><strong>Enganche:</strong> $<span id="conf_enganche">0.00</span></li>
                  <li class="d-none" id="li_financiera"><strong>Financiera:</strong> <span id="conf_financiera">—</span></li>
                  <li class="d-none" id="li_tag"><strong>TAG:</strong> <span id="conf_tag">—</span></li>
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

<!-- Bootstrap JS (bundle) -->
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->

<script>
$(function() {
  const idSucursalUsuario = <?= $id_sucursal_usuario ?>;
  const mapaSucursales = <?= json_encode($mapSuc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const modalConfirm = new bootstrap.Modal(document.getElementById('modalConfirmacion'));

  // === Select2 equipos (UI móvil full width) ===
  $('.select2-equipo').select2({
    width: '100%',
    placeholder: 'Buscar modelo o IMEI',
    allowClear: true,
    dropdownAutoWidth: true,
    selectionCssClass: 'form-select',
    dropdownCssClass: 'shadow'
  }).on('select2:select', function(){ $(this).blur(); }); // cierra teclado móvil

  // === Mostrar/ocultar campos según tipo/forma ===
  function toggleByTipo(){
    const tipo = $('#tipo_venta').val();
    // Combo visible solo si Financiamiento+Combo
    if (tipo === 'Financiamiento+Combo') { $('#combo').slideDown(120); } else { $('#combo').slideUp(120); }

    // Campos de financiamiento visibles si no es Contado
    const esFin = (tipo === 'Financiamiento' || tipo === 'Financiamiento+Combo');
    $('#plazo_field').toggle(esFin);
    $('#financiera_field').toggle(esFin);
    $('#tag_field').toggle(esFin);
    // Enganche visible si hay financiamiento
    $('#enganche_field').toggle(esFin);
  }
  function toggleMixto(){
    const forma = $('#forma_pago_enganche').val();
    $('#mixto_detalle').toggle(forma === 'Mixto');
  }
  $('#tipo_venta').on('change', toggleByTipo);
  $('#forma_pago_enganche').on('change', toggleMixto);
  toggleByTipo(); toggleMixto(); // estado inicial

  // === Alerta si eligen sucursal distinta ===
  $('#id_sucursal').on('change', function(){
    const sel = parseInt($(this).val() || '0', 10);
    $('#alerta_sucursal').toggleClass('d-none', sel === idSucursalUsuario);
  }).trigger('change');

  // === Botones que abren el modal (desktop y móvil) ===
  $('#btn_submit, #btn_submit_mobile').on('click', function(e){
    e.preventDefault();

    // Limpia errores UI
    $('#errores').addClass('d-none').empty();

    // Rellena confirmación
    const tipo = $('#tipo_venta').val() || '—';
    const suc  = $('#id_sucursal').val() ? (mapaSucursales[$('#id_sucursal').val()] || '—') : '—';
    const eq1  = $('#equipo1').find('option:selected').text() || $('#equipo1').val() || '—';
    const eq2  = $('#equipo2').find('option:selected').text() || $('#equipo2').val() || '';

    const precio = parseFloat($('#precio_venta').val() || 0).toFixed(2);
    const eng   = parseFloat($('#enganche').val() || 0).toFixed(2);
    const fin   = $('#financiera').val() || '';
    const tag   = $('#tag').val() || '';

    $('#conf_sucursal').text(suc);
    $('#conf_tipo').text(tipo);
    $('#conf_equipo1').text(eq1);
    $('#conf_precio').text(precio);

    // Opcionales
    $('#li_equipo2').toggleClass('d-none', !(tipo === 'Financiamiento+Combo' && eq2));
    $('#conf_equipo2').text(eq2 || '—');

    const esFin = (tipo === 'Financiamiento' || tipo === 'Financiamiento+Combo');
    $('#li_enganche').toggleClass('d-none', !esFin || (parseFloat(eng) <= 0));
    $('#conf_enganche').text(eng);

    $('#li_financiera').toggleClass('d-none', !esFin || !fin);
    $('#conf_financiera').text(fin || '—');

    $('#li_tag').toggleClass('d-none', !esFin || !tag);
    $('#conf_tag').text(tag || '—');

    modalConfirm.show();
  });

  // === Confirmar y enviar ===
  $('#btn_confirmar_envio').on('click', function(){
    // Aquí no tocamos lógica; sólo mandamos el form
    $('#form_venta')[0].submit();
  });

  // === Mejora UX: enter en inputs numéricos no envía accidentalmente (abre modal) ===
  $('#form_venta').on('keypress', 'input', function(e){
    if (e.which === 13) {
      e.preventDefault();
      $('#btn_submit_mobile').trigger('click');
    }
  });

  // === Accesibilidad básica ===
  $('#tipo_venta, #id_sucursal, #equipo1, #precio_venta').attr('aria-invalid','false');
});
</script>
</body>
</html>
