<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: 403.php");
    exit();
}

$ROL = $_SESSION['rol'] ?? '';
$ALLOWED = ['Admin','GerenteZona']; // Acceso permitido
if (!in_array($ROL, $ALLOWED, true)) {
    header("Location: 403.php");
    exit();
}

// No se puede editar precio desde esta vista
$canEditPrice = false;

include 'db.php';
include 'navbar.php';
include 'verificar_sesion.php';

// ===== Filtros =====
$filtroImei       = $_GET['imei']        ?? '';
$filtroSucursal   = $_GET['sucursal']    ?? '';
$filtroEstatus    = $_GET['estatus']     ?? '';
$filtroAntiguedad = $_GET['antiguedad']  ?? '';
$filtroPrecioMin  = $_GET['precio_min']  ?? '';
$filtroPrecioMax  = $_GET['precio_max']  ?? '';

$sql = "
    SELECT i.id AS id_inventario,
           s.nombre AS sucursal,
           p.id AS id_producto,
           p.marca, p.modelo, p.color, p.capacidad,
           p.imei1, p.imei2,
           p.costo,                    -- sin IVA (histórico)
           p.costo_con_iva,            -- ✅ nuevo campo para mostrar y calcular profit
           p.precio_lista,
           p.proveedor,
           p.codigo_producto,
           p.tipo_producto,
           -- ✅ Profit con costo_con_iva; si viene NULL, cae a costo (compatibilidad)
           (p.precio_lista - COALESCE(p.costo_con_iva, p.costo, 0)) AS profit,
           i.estatus, i.fecha_ingreso,
           TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) AS antiguedad_dias
    FROM inventario i
    INNER JOIN productos p ON p.id = i.id_producto
    INNER JOIN sucursales s ON s.id = i.id_sucursal
    WHERE i.estatus IN ('Disponible','En tránsito')
";

$params = [];
$types  = "";

if ($filtroSucursal !== '') {
    $sql .= " AND s.id = ?";
    $params[] = (int)$filtroSucursal;
    $types .= "i";
}
if ($filtroImei !== '') {
    $sql .= " AND (p.imei1 LIKE ? OR p.imei2 LIKE ?)";
    $like = "%$filtroImei%";
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}
if ($filtroEstatus !== '') {
    $sql .= " AND i.estatus = ?";
    $params[] = $filtroEstatus;
    $types .= "s";
}
if ($filtroAntiguedad == '<30') {
    $sql .= " AND TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) < 30";
} elseif ($filtroAntiguedad == '30-90') {
    $sql .= " AND TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) BETWEEN 30 AND 90";
} elseif ($filtroAntiguedad == '>90') {
    $sql .= " AND TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) > 90";
}
if ($filtroPrecioMin !== '') {
    $sql .= " AND p.precio_lista >= ?";
    $params[] = (float)$filtroPrecioMin;
    $types .= "d";
}
if ($filtroPrecioMax !== '') {
    $sql .= " AND p.precio_lista <= ?";
    $params[] = (float)$filtroPrecioMax;
    $types .= "d";
}

$sql .= " ORDER BY s.nombre, i.fecha_ingreso ASC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$sucursales = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");

// Datos para gráfica
$rangos = ['<30' => 0, '30-90' => 0, '>90' => 0];
$inventario = [];
while ($row = $result->fetch_assoc()) {
    $inventario[] = $row;
    $dias = (int)$row['antiguedad_dias'];
    if ($dias < 30) $rangos['<30']++;
    elseif ($dias <= 90) $rangos['30-90']++;
    else $rangos['>90']++;
}

// Fallback para código (por si hay registros viejos sin codigo_producto)
function buildCodigoFallback($tipo, $marca, $modelo, $color, $cap) {
    $partes = array_filter([$tipo, $marca, $modelo, $color, $cap], fn($v) => $v !== null && $v !== '');
    if (!$partes) return '-';
    $codigo = strtoupper(implode('-', $partes));
    $codigo = preg_replace('/\s+/', '', $codigo);
    return $codigo;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Inventario Global</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/img/favicon.ico?v=7" sizes="any">

    <!-- CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
</head>
<body class="bg-light">
<div class="container mt-4">
    <h2>🌎 Inventario Global — <?= ($ROL==='Admin'?'Admin':'Gerente Zona') ?></h2>
    <p>Total de equipos visibles: <b><?= count($inventario) ?></b></p>

    <!-- Filtros -->
    <form method="GET" class="card p-3 mb-4 shadow-sm bg-white">
        <div class="row g-3">
            <div class="col-md-2">
                <select name="sucursal" class="form-select">
                    <option value="">Todas las Sucursales</option>
                    <?php while ($s = $sucursales->fetch_assoc()): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= $filtroSucursal==$s['id']?'selected':'' ?>>
                            <?= htmlspecialchars($s['nombre'], ENT_QUOTES) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-md-2">
                <input type="text" name="imei" class="form-control" placeholder="Buscar IMEI..." value="<?= htmlspecialchars($filtroImei, ENT_QUOTES) ?>">
            </div>
            <div class="col-md-2">
                <select name="estatus" class="form-select">
                    <option value="">Todos Estatus</option>
                    <option value="Disponible" <?= $filtroEstatus=='Disponible'?'selected':'' ?>>Disponible</option>
                    <option value="En tránsito" <?= $filtroEstatus=='En tránsito'?'selected':'' ?>>En tránsito</option>
                </select>
            </div>
            <div class="col-md-2">
                <select name="antiguedad" class="form-select">
                    <option value="">Todas las Antigüedades</option>
                    <option value="<30"   <?= $filtroAntiguedad=='<30'?'selected':'' ?>>< 30 días</option>
                    <option value="30-90" <?= $filtroAntiguedad=='30-90'?'selected':'' ?>>30-90 días</option>
                    <option value=">90"   <?= $filtroAntiguedad=='>90'?'selected':'' ?>>> 90 días</option>
                </select>
            </div>
            <div class="col-md-2">
                <input type="number" step="0.01" name="precio_min" class="form-control" placeholder="Precio min" value="<?= htmlspecialchars($filtroPrecioMin, ENT_QUOTES) ?>">
            </div>
            <div class="col-md-2">
                <input type="number" step="0.01" name="precio_max" class="form-control" placeholder="Precio max" value="<?= htmlspecialchars($filtroPrecioMax, ENT_QUOTES) ?>">
            </div>
            <div class="col-md-12 text-end">
                <button class="btn btn-primary">Filtrar</button>
                <a href="inventario_global.php" class="btn btn-secondary">Limpiar</a>
                <a href="exportar_inventario_global.php?<?= http_build_query($_GET) ?>" class="btn btn-success">📊 Exportar Excel</a>
            </div>
        </div>
    </form>

    <!-- Gráfica + Top 5 -->
    <div class="d-flex justify-content-between mb-4 flex-wrap gap-4">
        <div style="max-width:400px; width:100%;">
            <canvas id="graficaAntiguedad"></canvas>
        </div>
        <div class="card shadow-sm p-3 bg-white" style="min-width:300px;">
            <h5 class="mb-3">🔥 Top 5 Equipos Vendidos</h5>
            <select id="filtro-top" class="form-select mb-2">
                <option value="semana">Esta Semana</option>
                <option value="mes">Este Mes</option>
                <option value="historico" selected>Histórico</option>
            </select>
            <div id="tabla-top">
                <div class="text-muted">Cargando...</div>
            </div>
        </div>
    </div>

    <!-- Tabla Inventario -->
    <table id="tablaInventario" class="table table-bordered table-striped table-sm">
        <thead class="table-dark">
            <tr>
                <th>ID</th>
                <th>Sucursal</th>
                <th>Marca</th>
                <th>Modelo</th>
                <th>Código</th>
                <th>Color</th>
                <th>Capacidad</th>
                <th>IMEI1</th>
                <th>IMEI2</th>
                <th>Proveedor</th>
                <th>Costo c/IVA ($)</th> <!-- ✅ renombrado -->
                <th>Precio Lista ($)</th>
                <th>Profit ($)</th>      <!-- ✅ calculado con costo_con_iva -->
                <th>Estatus</th>
                <th>Fecha Ingreso</th>
                <th>Antigüedad (días)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($inventario as $row):
                $dias  = (int)$row['antiguedad_dias'];
                $clase = $dias < 30 ? 'table-success' : ($dias <= 90 ? 'table-warning' : 'table-danger');
                // usa codigo_producto; si viene vacío, genera fallback temporal
                $codigo = $row['codigo_producto'] ?? '';
                if ($codigo === '' || $codigo === null) {
                    $codigo = buildCodigoFallback($row['tipo_producto'] ?? '', $row['marca'] ?? '', $row['modelo'] ?? '', $row['color'] ?? '', $row['capacidad'] ?? '');
                }
                $costoConIva = $row['costo_con_iva'];
                if ($costoConIva === null || $costoConIva === '') {
                    // compatibilidad con registros viejos: muestra costo sin IVA si no existe con IVA
                    $costoConIva = $row['costo'];
                }
            ?>
            <tr class="<?= $clase ?>">
                <td><?= (int)$row['id_inventario'] ?></td>
                <td><?= htmlspecialchars($row['sucursal'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($row['marca'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($row['modelo'], ENT_QUOTES) ?></td>
                <td><code><?= htmlspecialchars($codigo, ENT_QUOTES) ?></code></td>
                <td><?= htmlspecialchars($row['color'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($row['capacidad'] ?? '-', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($row['imei1'] ?? '-', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($row['imei2'] ?? '-', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($row['proveedor'] ?? '-', ENT_QUOTES) ?></td>
                <td class="text-end">$<?= number_format((float)$costoConIva,2) ?></td>
                <td class="text-end">$<?= number_format((float)$row['precio_lista'],2) ?></td>
                <td class="text-end"><b>$<?= number_format((float)$row['profit'],2) ?></b></td>
                <td><?= htmlspecialchars($row['estatus'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($row['fecha_ingreso'], ENT_QUOTES) ?></td>
                <td><b><?= $dias ?> días</b></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- JS -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
$(function() {
  $('#tablaInventario').DataTable({
    pageLength: 25,
    order: [[ 0, "desc" ]],
    language: { url: "//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json" }
  });
});

// Gráfica
const ctx = document.getElementById('graficaAntiguedad').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: ['<30 días', '30-90 días', '>90 días'],
        datasets: [{
            label: 'Cantidad de equipos',
            data: [<?= (int)$rangos['<30'] ?>, <?= (int)$rangos['30-90'] ?>, <?= (int)$rangos['>90'] ?>]
        }]
    },
    options: { responsive: true, plugins: { legend: { display: false } } }
});

// Top vendidos
function cargarTopVendidos(rango = 'historico') {
    fetch('top_productos.php?rango=' + rango)
        .then(res => res.text())
        .then(html => {
            document.getElementById('tabla-top').innerHTML = html;
        });
}
document.getElementById('filtro-top').addEventListener('change', function() {
    cargarTopVendidos(this.value);
});
cargarTopVendidos();
</script>
</body>
</html>
