<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

$idUsuario  = $_SESSION['id_usuario'];
$idSucursal = $_SESSION['id_sucursal'];
$mensaje    = '';

// 🔹 Planes pospago visibles en el selector
$planesPospago = [
    "Plan Bait 199" => 199,
    "Plan Bait 249" => 249,
    "Plan Bait 289" => 289,
    "Plan Bait 339" => 339
];

/* ===============================
   FUNCIONES AUXILIARES
================================ */

// Tipado dinámico para bind_param (evita desajustes)
function tipos_mysqli(array $vals): string {
    $t = '';
    foreach ($vals as $v) {
        if (is_int($v)) { $t .= 'i'; continue; }
        if (is_float($v)) { $t .= 'd'; continue; }
        // Permitimos null como string (MySQLi lo maneja como NULL con s)
        $t .= 's';
    }
    return $t;
}

// 1) Traer fila vigente de comisiones de POSPAGO por plan (tipo=Ejecutivo)
function obtenerFilaPospagoVigente(mysqli $conn, float $planMonto): ?array {
    $sql = "SELECT comision_con_equipo, comision_sin_equipo
            FROM esquemas_comisiones_pospago
            WHERE tipo='Ejecutivo' AND plan_monto=?
            ORDER BY fecha_inicio DESC
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("d", $planMonto);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// 2) Calcular comisión de POSPAGO (no depende de cuota)
function calcularComisionPospago(mysqli $conn, float $planMonto, string $modalidad): float {
    $fila = obtenerFilaPospagoVigente($conn, $planMonto);
    if (!$fila) return 0.0;
    $conEquipo = (stripos($modalidad, 'con') !== false);
    return (float)($conEquipo ? $fila['comision_con_equipo'] : $fila['comision_sin_equipo']);
}

/* ===============================
   PROCESAR VENTA
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $esEsim         = isset($_POST['es_esim']) ? 1 : 0;
    $idSim          = $_POST['id_sim'] ?? null;               // solo si NO es eSIM
    $plan           = $_POST['plan'] ?? '';
    $precioPlan     = $planesPospago[$plan] ?? 0;             // 199|249|289|339
    $modalidad      = $_POST['modalidad'] ?? 'Sin equipo';    // 'Con equipo' | 'Sin equipo'
    $idVentaEquipo  = ($_POST['id_venta_equipo'] ?? '') !== '' ? (int)$_POST['id_venta_equipo'] : null;
    $nombreCliente  = trim($_POST['nombre_cliente'] ?? '');
    $numeroCliente  = trim($_POST['numero_cliente'] ?? '');
    $comentarios    = trim($_POST['comentarios'] ?? '');      // siempre definido

    // Validaciones mínimas
    if (!$plan || $precioPlan <= 0) {
        $mensaje = '<div class="alert alert-danger">Selecciona un plan válido.</div>';
    }

    // Validar SIM física si corresponde
    if ($mensaje === '' && !$esEsim && $idSim) {
        $sql = "SELECT id, iccid FROM inventario_sims
                WHERE id=? AND estatus='Disponible' AND id_sucursal=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $idSim, $idSucursal);
        $stmt->execute();
        $sim = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$sim) {
            $mensaje = '<div class="alert alert-danger">La SIM seleccionada no está disponible en esta sucursal.</div>';
        }
    }

    if ($mensaje === '') {
        // Calcular comisiones (ejecutivo). Gerente en 0 aquí (se puede recalcular después).
        $comisionEjecutivo = calcularComisionPospago($conn, (float)$precioPlan, $modalidad);
        $comisionGerente   = 0.0;

        // ===========================
        // INSERT en ventas_sims
        // ===========================
        $sqlVenta = "INSERT INTO ventas_sims
            (tipo_venta, comentarios, precio_total, comision_ejecutivo, comision_gerente,
             id_usuario, id_sucursal, fecha_venta, es_esim, modalidad, id_venta_equipo, numero_cliente, nombre_cliente)
            VALUES ('Pospago', ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sqlVenta);

        // valores en el orden EXACTO de los ?
        $vals = [
            $comentarios,        // s
            $precioPlan,         // d
            $comisionEjecutivo,  // d
            $comisionGerente,    // d
            $idUsuario,          // i
            $idSucursal,         // i
            $esEsim,             // i
            $modalidad,          // s
            $idVentaEquipo,      // i (NULL permitido)
            $numeroCliente,      // s
            $nombreCliente       // s
        ];
        $types = tipos_mysqli($vals);

        $stmt->bind_param($types, ...$vals);
        $stmt->execute();
        $idVenta = $stmt->insert_id;
        $stmt->close();

        // Si es SIM física, guardar detalle y mover inventario
        if (!$esEsim && $idSim) {
            // Detalle
            $sqlDetalle = "INSERT INTO detalle_venta_sims (id_venta, id_sim, precio_unitario) VALUES (?,?,?)";
            $stmt = $conn->prepare($sqlDetalle);
            $stmt->bind_param("iid", $idVenta, $idSim, $precioPlan);
            $stmt->execute();
            $stmt->close();

            // Inventario
            $sqlUpdate = "UPDATE inventario_sims
                          SET estatus='Vendida', id_usuario_venta=?, fecha_venta=NOW()
                          WHERE id=?";
            $stmt = $conn->prepare($sqlUpdate);
            $stmt->bind_param("ii", $idUsuario, $idSim);
            $stmt->execute();
            $stmt->close();
        }

        $mensaje = '<div class="alert alert-success">✅ Venta pospago registrada correctamente. Comisión: $'.number_format($comisionEjecutivo,2).'</div>';
    }
}

/* ===============================
   LISTAR SIMs DISPONIBLES
================================ */
$sql = "SELECT id, iccid, caja_id, fecha_ingreso
        FROM inventario_sims
        WHERE estatus='Disponible' AND id_sucursal=?
        ORDER BY fecha_ingreso ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $idSucursal);
$stmt->execute();
$disponibles = $stmt->get_result();
$stmt->close();

/* ===============================
   LISTAR VENTAS DE EQUIPO (MISMO DÍA) CON CLIENTE + MODELO + IMEI
================================ */
$sqlEquipos = "
    SELECT v.id,
           v.fecha_venta,
           v.nombre_cliente,
           p.marca,
           p.modelo,
           p.color,
           dv.imei1
    FROM ventas v
    INNER JOIN (
        SELECT id_venta, MIN(id) AS min_detalle_id
        FROM detalle_venta
        GROUP BY id_venta
    ) dmin ON dmin.id_venta = v.id
    INNER JOIN detalle_venta dv ON dv.id = dmin.min_detalle_id
    INNER JOIN productos p ON p.id = dv.id_producto
    WHERE v.id_sucursal = ?
      AND v.id_usuario  = ?
      AND DATE(v.fecha_venta) = CURDATE()
    ORDER BY v.fecha_venta DESC
";
$stmt = $conn->prepare($sqlEquipos);
$stmt->bind_param("ii", $idSucursal, $idUsuario);
$stmt->execute();
$ventasEquipos = $stmt->get_result();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Venta SIM Pospago</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">

    <!-- 🔎 Select2 (buscador en <select>) -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
      /* Integración visual con Bootstrap */
      .select2-container .select2-selection--single { height: 38px; }
      .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 36px; }
      .select2-container--default .select2-selection--single .select2-selection__arrow { height: 36px; }
    </style>

    <script>
        function toggleSimSelect() {
            const isEsim = document.getElementById('es_esim').checked;
            document.getElementById('sim_fisica').style.display = isEsim ? 'none' : 'block';
        }
        function toggleEquipo() {
            const modalidad = document.getElementById('modalidad').value;
            document.getElementById('venta_equipo').style.display = (modalidad === 'Con equipo') ? 'block' : 'none';
        }
        function setPrecio() {
            const plan = document.getElementById('plan').value;
            const precios = {
                "Plan Bait 199":199,
                "Plan Bait 249":249,
                "Plan Bait 289":289,
                "Plan Bait 339":339
            };
            document.getElementById('precio').value = precios[plan] || 0;
        }
    </script>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>📱 Venta de SIM Pospago</h2>
    <?= $mensaje ?>

    <form method="POST" class="card shadow p-3 mb-4">
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="es_esim" name="es_esim" onchange="toggleSimSelect()">
            <label class="form-check-label">Es eSIM (no afecta inventario)</label>
        </div>

        <!-- SIM Física con buscador -->
        <div class="row mb-3" id="sim_fisica">
            <div class="col-md-6">
                <label class="form-label">SIM física disponible</label>
                <select name="id_sim" class="form-select select2-sims">
                    <option value="">-- Selecciona SIM --</option>
                    <?php while($row = $disponibles->fetch_assoc()): ?>
                        <option value="<?= $row['id'] ?>">
                            <?= htmlspecialchars($row['iccid']) ?> | Caja: <?= htmlspecialchars($row['caja_id']) ?> | Ingreso: <?= htmlspecialchars($row['fecha_ingreso']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
                <div class="form-text">Escribe ICCID, caja o fecha para filtrar.</div>
            </div>
        </div>

        <!-- Datos de la venta -->
        <div class="row mb-3">
            <div class="col-md-3">
                <label class="form-label">Plan pospago</label>
                <select name="plan" id="plan" class="form-select" onchange="setPrecio()" required>
                    <option value="">-- Selecciona plan --</option>
                    <?php foreach($planesPospago as $planNombre => $precioP): ?>
                        <option value="<?= $planNombre ?>"><?= $planNombre ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Precio/Plan</label>
                <input type="number" step="0.01" id="precio" name="precio" class="form-control" readonly>
            </div>
            <div class="col-md-3">
                <label class="form-label">Modalidad</label>
                <select name="modalidad" id="modalidad" class="form-select" onchange="toggleEquipo()" required>
                    <option value="Sin equipo">Sin equipo</option>
                    <option value="Con equipo">Con equipo</option>
                </select>
            </div>

            <!-- Relacionar venta de equipo (solo del mismo día) -->
            <div class="col-md-4" id="venta_equipo" style="display:none;">
                <label class="form-label">Relacionar venta de equipo (hoy)</label>
                <select name="id_venta_equipo" class="form-select select2-ventas">
                    <option value="">-- Selecciona venta --</option>
                    <?php while($ve = $ventasEquipos->fetch_assoc()): ?>
                        <option value="<?= $ve['id'] ?>">
                            #<?= $ve['id'] ?> | Cliente: <?= htmlspecialchars($ve['nombre_cliente'] ?? 'N/D') ?>
                            | Equipo: <?= htmlspecialchars($ve['marca'].' '.$ve['modelo'].' '.$ve['color']) ?>
                            | IMEI: <?= htmlspecialchars($ve['imei1']) ?>
                            | <?= date('H:i', strtotime($ve['fecha_venta'])) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
                <div class="form-text">
                    Se muestran únicamente ventas de <b>hoy</b> en tu sucursal. Busca por ID, cliente, modelo o IMEI.
                </div>
            </div>
        </div>

        <!-- Datos del cliente -->
        <div class="row mb-3">
            <div class="col-md-4">
                <label class="form-label">Nombre del cliente</label>
                <input type="text" name="nombre_cliente" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Número telefónico</label>
                <input type="text" name="numero_cliente" class="form-control" required>
            </div>
            <div class="col-md-5">
                <label class="form-label">Comentarios</label>
                <input type="text" name="comentarios" class="form-control">
            </div>
        </div>

        <button type="submit" class="btn btn-success">Registrar Venta Pospago</button>
    </form>
</div>

<!-- jQuery + Select2 -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
// Inicialización de Select2 y UI
(function(){
    function initSelects(){
        $('.select2-sims').select2({
            placeholder: '-- Selecciona SIM --',
            width: '100%',
            language: {
                noResults: function() { return 'Sin resultados'; },
                searching: function() { return 'Buscando…'; }
            }
        });
        $('.select2-ventas').select2({
            placeholder: '-- Selecciona venta --',
            width: '100%',
            language: {
                noResults: function() { return 'Sin resultados'; },
                searching: function() { return 'Buscando…'; }
            }
        });
    }

    function initToggles(){
        toggleSimSelect();
        toggleEquipo();
        setPrecio();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function(){
            initSelects(); initToggles();
        });
    } else {
        initSelects(); initToggles();
    }
})();
</script>

</body>
</html>
