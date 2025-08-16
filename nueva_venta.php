<?php
include 'navbar.php';

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

$id_usuario = (int)$_SESSION['id_usuario'];
$id_sucursal_usuario = (int)$_SESSION['id_sucursal'];

// Traer sucursales
$sql_suc = "SELECT id, nombre FROM sucursales ORDER BY nombre";
$sucursales = $conn->query($sql_suc)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Nueva Venta</title>
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
    <h2>Registrar Nueva Venta</h2>
    <a href="panel.php" class="btn btn-secondary mb-3">← Volver al Panel</a>

    <!-- ⚠️ Advertencia -->
    <div id="alerta_sucursal" class="alert alert-warning d-none">
        ⚠️ Estás eligiendo una sucursal diferente a la tuya. La venta contará para tu usuario en otra sucursal.
    </div>

    <!-- Errores de validación -->
    <div id="errores" class="alert alert-danger d-none"></div>

    <form method="POST" action="procesar_venta.php" id="form_venta" novalidate>
        <input type="hidden" name="id_usuario" value="<?= $id_usuario ?>">

        <div class="row mb-3">
            <div class="col-md-4">
                <label class="form-label">Sucursal de la Venta</label>
                <select name="id_sucursal" id="id_sucursal" class="form-control" required>
                    <?php foreach ($sucursales as $sucursal): ?>
                        <option value="<?= (int)$sucursal['id'] ?>" <?= (int)$sucursal['id'] === $id_sucursal_usuario ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sucursal['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Datos del cliente -->
        <div class="row mb-3">
            <div class="col-md-4" id="tag_field">
                <label for="tag" class="form-label">TAG (ID del crédito)</label>
                <input type="text" name="tag" id="tag" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">Nombre del Cliente</label>
                <!-- Quitar required: solo se exige en financiamiento -->
                <input type="text" name="nombre_cliente" id="nombre_cliente" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">Teléfono del Cliente</label>
                <!-- Quitar required: solo se exige en financiamiento -->
                <input type="text" name="telefono_cliente" id="telefono_cliente" class="form-control" placeholder="10 dígitos">
            </div>
        </div>

        <!-- Tipo de venta y equipos -->
        <div class="row mb-3">
            <div class="col-md-4">
                <label class="form-label">Tipo de Venta</label>
                <select name="tipo_venta" id="tipo_venta" class="form-control" required>
                    <option value="">Seleccione...</option>
                    <option value="Contado">Contado</option>
                    <option value="Financiamiento">Financiamiento</option>
                    <option value="Financiamiento+Combo">Financiamiento + Combo</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Equipo Principal</label>
                <select name="equipo1" id="equipo1" class="form-control select2-equipo" required></select>
            </div>
            <div class="col-md-4" id="combo" style="display:none;">
                <label class="form-label">Equipo Combo</label>
                <select name="equipo2" id="equipo2" class="form-control select2-equipo"></select>
            </div>
        </div>

        <!-- Datos financieros -->
        <div class="row mb-3">
            <div class="col-md-4">
                <label class="form-label">Precio de Venta Total ($)</label>
                <!-- Siempre obligatorio -->
                <input type="number" step="0.01" min="0.01" name="precio_venta" id="precio_venta" class="form-control" required>
            </div>
            <div class="col-md-4" id="enganche_field">
                <label class="form-label">Enganche ($)</label>
                <input type="number" step="0.01" min="0" name="enganche" id="enganche" class="form-control" value="0">
            </div>
            <div class="col-md-4">
                <label for="forma_pago_enganche" id="label_forma_pago" class="form-label">Forma de Pago</label>
                <!-- Siempre obligatorio -->
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
                <input type="text" name="comentarios" class="form-control">
            </div>
        </div>

        <button class="btn btn-success w-100" id="btn_submit">Registrar Venta</button>
    </form>
</div>

<script>
$(document).ready(function() {
    const idSucursalUsuario = <?= $id_sucursal_usuario ?>;

    // Inicializa Select2
    $('.select2-equipo').select2({
        placeholder: "Buscar por modelo o IMEI",
        allowClear: true,
        width: '100%'
    });

    // Mostrar u ocultar campos según tipo de venta
    $('#tipo_venta').on('change', function() {
        $('#combo').toggle(isFinanciamientoCombo());
        toggleVenta();
    });

    $('#forma_pago_enganche').on('change', function() {
        $('#mixto_detalle').toggle($(this).val() === 'Mixto' && isFinanciamiento());
    });

    function isFinanciamiento(){
        const tipo = $('#tipo_venta').val();
        return (tipo === 'Financiamiento' || tipo === 'Financiamiento+Combo');
    }
    function isFinanciamientoCombo(){
        return $('#tipo_venta').val() === 'Financiamiento+Combo';
    }

    function toggleVenta() {
        const esFin = isFinanciamiento();

        // TAG, Enganche, Plazo, Financiera visibles solo en financiamiento
        $('#tag_field, #enganche_field, #plazo_field, #financiera_field').toggle(esFin);
        $('#mixto_detalle').toggle(esFin && $('#forma_pago_enganche').val()==='Mixto');

        // Etiqueta de forma de pago
        $('#label_forma_pago').text(esFin ? 'Forma de Pago Enganche' : 'Forma de Pago');

        // Requeridos SÓLO cuando es financiamiento (incluye combo)
        $('#tag').prop('required', esFin);
        $('#nombre_cliente').prop('required', esFin);
        $('#telefono_cliente').prop('required', esFin);
        $('#enganche').prop('required', esFin);
        $('#plazo_semanas').prop('required', esFin);
        $('#financiera').prop('required', esFin);

        // Campos siempre obligatorios: precio y forma de pago
        $('#precio_venta').prop('required', true);
        $('#forma_pago_enganche').prop('required', true);

        if (!esFin) {
            // Reset cuando es Contado
            $('#tag').val('');
            $('#enganche').val(0);
            $('#plazo_semanas').val(0);
            $('#financiera').val('');
            $('#enganche_efectivo').val(0);
            $('#enganche_tarjeta').val(0);
        }
    }

    toggleVenta(); // Al cargar

    // Cargar productos por sucursal
    function cargarEquipos(sucursalId) {
        $.ajax({
            url: 'ajax_productos_por_sucursal.php',
            method: 'POST',
            data: { id_sucursal: sucursalId },
            success: function(response) {
                $('#equipo1, #equipo2').html(response).val('').trigger('change');
            }
        });
    }

    cargarEquipos($('#id_sucursal').val());

    $('#id_sucursal').on('change', function() {
        const seleccionada = parseInt($(this).val());
        if (seleccionada !== idSucursalUsuario) {
            $('#alerta_sucursal').removeClass('d-none');
        } else {
            $('#alerta_sucursal').addClass('d-none');
        }
        cargarEquipos(seleccionada);
    });

    // ---------- Validación antes de enviar ----------
    $('#form_venta').on('submit', function(e){
        const errores = [];
        const esFin = isFinanciamiento();

        const nombre = $('#nombre_cliente').val().trim();
        const tel    = $('#telefono_cliente').val().trim();
        const tag    = $('#tag').val().trim();
        const tipo   = $('#tipo_venta').val();

        const precio = parseFloat($('#precio_venta').val());
        const eng    = parseFloat($('#enganche').val());
        const forma  = $('#forma_pago_enganche').val();
        const plazo  = parseInt($('#plazo_semanas').val(), 10);
        const finan  = $('#financiera').val();

        // Siempre
        if (!tipo) errores.push('Selecciona el tipo de venta.');
        if (!precio || precio <= 0) errores.push('El precio de venta debe ser mayor a 0.');
        if (!forma) errores.push('Selecciona la forma de pago.');

        // Solo en financiamiento / combo
        if (esFin) {
            if (!nombre) errores.push('Ingresa el nombre del cliente (Financiamiento).');
            if (!tel) errores.push('Ingresa el teléfono del cliente (Financiamiento).');
            if (tel && !/^\d{10}$/.test(tel)) errores.push('El teléfono debe tener 10 dígitos.');
            if (!tag) errores.push('El TAG (ID del crédito) es obligatorio.');
            if (isNaN(eng) || eng < 0) errores.push('El enganche es obligatorio (puede ser 0, no negativo).');
            if (!plazo || plazo <= 0) errores.push('El plazo en semanas debe ser mayor a 0.');
            if (!finan) errores.push('Selecciona una financiera (no N/A).');

            if (forma === 'Mixto') {
                const ef = parseFloat($('#enganche_efectivo').val()) || 0;
                const tj = parseFloat($('#enganche_tarjeta').val()) || 0;
                if (ef <= 0 && tj <= 0) errores.push('En Mixto, al menos uno de los montos debe ser > 0.');
                if (eng.toFixed(2) !== (ef+tj).toFixed(2)) errores.push('Efectivo + Tarjeta debe igualar al Enganche.');
            }
        } else {
            // En contado, si el usuario llenó teléfono, valida formato (opcional)
            if (tel && !/^\d{10}$/.test(tel)) errores.push('El teléfono debe tener 10 dígitos.');
        }

        if (errores.length > 0) {
            e.preventDefault();
            $('#errores').removeClass('d-none').html('<strong>Corrige lo siguiente:</strong><ul class="mb-0"><li>' + errores.join('</li><li>') + '</li></ul>');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } else {
            $('#btn_submit').prop('disabled', true).text('Enviando...');
        }
    });
});
</script>

</body>
</html>
