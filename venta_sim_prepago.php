<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

$idUsuario  = $_SESSION['id_usuario'];
$idSucursal = $_SESSION['id_sucursal'];
$mensaje = '';

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
    $idSim      = (int)$_POST['id_sim'];
    $tipoVenta  = $_POST['tipo_venta'];
    $precio     = (float)$_POST['precio'];
    $comentarios= trim($_POST['comentarios']);
    $fechaVenta = date('Y-m-d');

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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">

    <!-- 🔎 Select2 (buscador en <select>) -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
      /* Para que Select2 combine bien con Bootstrap */
      .select2-container .select2-selection--single { height: 38px; }
      .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 36px; }
      .select2-container--default .select2-selection--single .select2-selection__arrow { height: 36px; }
    </style>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>📱 Venta de SIM Prepago</h2>
    <?= $mensaje ?>

    <form method="POST" class="card shadow p-3 mb-4" id="formVentaSim">
        <div class="row mb-3">
            <!-- SIM con buscador -->
            <div class="col-md-6">
                <label class="form-label">SIM disponible</label>
                <select name="id_sim" id="selectSim" class="form-select select2-sims" required>
                    <option value="">-- Selecciona SIM --</option>
                    <?php while($row = $disponibles->fetch_assoc()): ?>
                        <option
                            value="<?= $row['id'] ?>"
                            data-operador="<?= htmlspecialchars($row['operador']) ?>"
                        >
                            <?= $row['iccid'] ?> | <?= $row['operador'] ?> | Caja: <?= $row['caja_id'] ?> | Ingreso: <?= $row['fecha_ingreso'] ?>
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

            <!-- Tipo de venta -->
            <div class="col-md-2">
                <label class="form-label">Tipo de venta</label>
                <select name="tipo_venta" class="form-select" required>
                    <option value="Nueva">Nueva</option>
                    <option value="Portabilidad">Portabilidad</option>
                    <option value="Regalo">Regalo (costo 0)</option>
                </select>
            </div>

            <!-- Precio -->
            <div class="col-md-1">
                <label class="form-label">Precio</label>
                <input type="number" step="0.01" name="precio" class="form-control" value="0" required>
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-12">
                <label class="form-label">Comentarios</label>
                <input type="text" name="comentarios" class="form-control">
            </div>
        </div>

        <button type="submit" class="btn btn-success">Registrar Venta</button>
    </form>
</div>

<!-- jQuery + Select2 -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
// Inicializar Select2 y sincronizar el campo Tipo de SIM (solo lectura)
(function(){
    const $selectSim   = $('.select2-sims');
    const tipoSimView  = document.getElementById('tipoSimView');

    $selectSim.select2({
        placeholder: '-- Selecciona SIM --',
        width: '100%',
        language: {
          noResults: function() { return 'Sin resultados'; },
          searching: function() { return 'Buscando…'; }
        }
    });

    function actualizarTipo() {
        const el = $selectSim.find(':selected');
        const operador = (el && el.data('operador')) ? String(el.data('operador')).trim() : '';
        tipoSimView.value = operador || '';
    }

    actualizarTipo();
    $selectSim.on('change', actualizarTipo);
})();
</script>

</body>
</html>
