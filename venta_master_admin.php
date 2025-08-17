<?php
// venta_master_admin.php
include 'navbar.php'; // ya inicia la sesión

// Solo Admin
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
    header("Location: 403.php");
    exit();
}

include 'db.php';

$id_usuario = (int)$_SESSION['id_usuario'];

// Traer sucursales Master Admin
$sql_suc = "SELECT id, nombre FROM sucursales WHERE subtipo='Master Admin' ORDER BY nombre";
$sucursales = $conn->query($sql_suc)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Nueva Venta (Master Admin)</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style>
      .select2-container--default .select2-selection--single{height:38px}
      .select2-container--default .select2-selection__rendered{line-height:38px}
      .select2-container--default .select2-selection__arrow{height:38px}
    </style>
</head>
<body class="bg-light">

<div class="container mt-4">
    <h2>Registrar Nueva Venta — <span class="text-primary">Master Admin</span></h2>
    <a href="panel.php" class="btn btn-secondary mb-3">← Volver al Panel</a>

    <div id="errores" class="alert alert-danger d-none"></div>

    <form method="POST" action="procesar_venta_master_admin.php" id="form_venta" novalidate>
        <input type="hidden" name="id_usuario" value="<?= $id_usuario ?>">

        <div class="row mb-3">
            <div class="col-md-4">
                <label class="form-label">Sucursal (Master Admin)</label>
                <select name="id_sucursal" id="id_sucursal" class="form-control" required>
                    <option value="">Seleccione...</option>
                    <?php foreach ($sucursales as $sucursal): ?>
                        <option value="<?= (int)$sucursal['id'] ?>"><?= htmlspecialchars($sucursal['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label">Origen del equipo</label>
                <!-- name correcto para el backend -->
                <select name="origen_ma" id="origen_ma" class="form-control" required>
                    <option value="">Seleccione...</option>
                    <option value="nano">Inventario Nano</option>
                    <option value="propio">Inventario propio del Master Admin</option>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label">Tipo de Venta</label>
                <select name="tipo_venta" id="tipo_venta" class="form-control" required>
                    <option value="">Seleccione...</option>
                    <option value="Contado">Contado</option>
                    <option value="Financiamiento">Financiamiento</option>
                    <option value="Financiamiento+Combo">Financiamiento + Combo</option>
                </select>
            </div>
        </div>

        <!-- Datos del cliente -->
        <div class="row mb-3">
            <div class="col-md-4">
                <label class="form-label">Nombre del Cliente</label>
                <input type="text" name="nombre_cliente" id="nombre_cliente" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">Teléfono del Cliente</label>
                <input type="text" name="telefono_cliente" id="telefono_cliente" class="form-control" placeholder="10 dígitos">
            </div>
            <div class="col-md-4" id="tag_field">
                <label for="tag" class="form-label">TAG (ID del crédito)</label>
                <input type="text" name="tag" id="tag" class="form-control">
            </div>
        </div>

        <!-- Equipos (solo si origen = nano) -->
        <div id="bloque_equipos">
          <div class="row mb-3">
              <div class="col-md-6">
                  <label class="form-label">Equipo Principal</label>
                  <select name="equipo1" id="equipo1" class="form-control select2-equipo"></select>
              </div>
              <div class="col-md-6" id="combo">
                  <label class="form-label">Equipo Combo (opcional)</label>
                  <select name="equipo2" id="equipo2" class="form-control select2-equipo"></select>
              </div>
          </div>
        </div>

        <!-- Datos financieros -->
        <div class="row mb-3">
            <div class="col-md-4">
                <label class="form-label">Precio de Venta Total ($)</label>
                <input type="number" step="0.01" min="0.01" name="precio_venta" id="precio_venta" class="form-control" required>
            </div>
            <div class="col-md-4" id="enganche_field">
                <label class="form-label">Enganche ($)</label>
                <input type="number" step="0.01" min="0" name="enganche" id="enganche" class="form-control" value="0">
            </div>
            <div class="col-md-4">
                <label for="forma_pago_enganche" id="label_forma_pago" class="form-label">Forma de Pago</label>
                <select name="forma_pago_enganche" id="forma_pago_enganche" class="form-control" required>
                    <option value="Efectivo">Efectivo</option>
                    <option value="Tarjeta">Tarjeta</option>
                    <option value="Mixto">Mixto</option>
                </select>
            </div>
        </div>

        <div class="row mb-3" id="mixto_detalle" style="display:none;">
            <div class="col-md-6">
                <label class="form-label">Enganche Efectivo ($)</label>
                <input type="number" step="0.01" min="0" name="enganche_efectivo" id="enganche_efectivo" class="form-control" value="0">
            </div>
            <div class="col-md-6">
                <label class="form-label">Enganche Tarjeta ($)</label>
                <input type="number" step="0.01" min="0" name="enganche_tarjeta" id="enganche_tarjeta" class="form-control" value="0">
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-4" id="plazo_field">
                <label class="form-label">Plazo en Semanas</label>
                <input type="number" min="1" name="plazo_semanas" id="plazo_semanas" class="form-control" value="0">
            </div>
            <div class="col-md-4" id="financiera_field">
                <label class="form-label">Financiera</label>
                <select name="financiera" id="financiera" class="form-control">
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

        <button class="btn btn-success w-100" id="btn_submit">Registrar Venta</button>
    </form>
</div>

<script>
$(function() {
    $('.select2-equipo').select2({ placeholder: "Buscar por modelo o IMEI", allowClear: true, width: '100%' });

    function isFin(){ const t=$('#tipo_venta').val(); return t==='Financiamiento' || t==='Financiamiento+Combo'; }
    function isCombo(){ return $('#tipo_venta').val()==='Financiamiento+Combo'; }

    function toggleCamposFin() {
        const fin = isFin();
        // TAG, Enganche, Plazo, Financiera dependen del tipo de venta
        $('#tag_field, #enganche_field, #plazo_field, #financiera_field').toggle(fin);
        $('#mixto_detalle').toggle(fin && $('#forma_pago_enganche').val()==='Mixto');
        $('#label_forma_pago').text(fin ? 'Forma de Pago Enganche' : 'Forma de Pago');

        // Requeridos solo para financiamiento (NO nombre/teléfono aquí)
        $('#tag,#enganche,#plazo_semanas,#financiera').prop('required', fin);

        if (!fin) {
            $('#tag').val(''); $('#enganche').val(0); $('#plazo_semanas').val(0); $('#financiera').val('');
            $('#enganche_efectivo').val(0); $('#enganche_tarjeta').val(0);
        }
    }

    function toggleEquiposYCliente() {
        const origen = $('#origen_ma').val(); // nano | propio
        const showEquipos = (origen === 'nano');

        // Mostrar selects de inventario solo si origen Nano
        $('#bloque_equipos').toggle(showEquipos);
        $('#equipo1').prop('required', showEquipos);
        $('#equipo2').prop('required', showEquipos && isCombo());

        // Reglas de cliente:
        // - Origen Nano: nombre y teléfono OBLIGATORIOS
        // - Origen Propio: nombre y teléfono OPCIONALES (si capturan teléfono, validar formato)
        const reqCliente = (origen === 'nano');
        $('#nombre_cliente, #telefono_cliente').prop('required', reqCliente);
    }

    $('#tipo_venta').on('change', function(){
        $('#combo').toggle(isCombo());
        toggleCamposFin();
        toggleEquiposYCliente();
    });

    $('#forma_pago_enganche').on('change', function(){
        $('#mixto_detalle').toggle($(this).val() === 'Mixto' && isFin());
    });

    $('#origen_ma').on('change', function(){
        toggleEquiposYCliente();
    });

    // Cargar inventario según sucursal
    function cargarEquipos(idSucursal) {
        if (!idSucursal) { $('#equipo1,#equipo2').html(''); return; }
        $.post('ajax_productos_por_sucursal.php', { id_sucursal: idSucursal }, function(html){
            $('#equipo1,#equipo2').html(html).val('').trigger('change');
        });
    }
    $('#id_sucursal').on('change', function(){ cargarEquipos($(this).val()); });

    // Validación extra en submit (tel 10 dígitos cuando origen Nano o si lo escriben)
    $('#form_venta').on('submit', function(e){
        const errores = [];
        const origen = $('#origen_ma').val();
        const nombre = $('#nombre_cliente').val().trim();
        const tel    = $('#telefono_cliente').val().trim();

        if (origen === 'nano') {
            if (!nombre) errores.push('Ingresa el nombre del cliente (origen Nano).');
            if (!tel) errores.push('Ingresa el teléfono del cliente (origen Nano).');
            if (tel && !/^\d{10}$/.test(tel)) errores.push('El teléfono debe tener 10 dígitos.');
        } else if (origen === 'propio') {
            if (tel && !/^\d{10}$/.test(tel)) errores.push('El teléfono debe tener 10 dígitos.');
        }

        if (errores.length) {
            e.preventDefault();
            $('#errores').removeClass('d-none')
                .html('<strong>Corrige lo siguiente:</strong><ul class="mb-0"><li>'+ errores.join('</li><li>') +'</li></ul>');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    });

    // Inicial
    toggleCamposFin();
    toggleEquiposYCliente();
});
</script>
</body>
</html>
