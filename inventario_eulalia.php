<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'Admin') {
    header("Location: 403.php");
    exit();
}

include 'db.php';
include 'navbar.php';

// ===============================
//   Obtener ID de Eulalia
// ===============================
$idEulalia = 0;
$res = $conn->query("SELECT id FROM sucursales WHERE nombre='Eulalia' LIMIT 1");
if ($res && $row = $res->fetch_assoc()) {
    $idEulalia = (int)$row['id'];
}
if ($idEulalia <= 0) {
    die("No se encontró la sucursal 'Eulalia'. Verifica el catálogo de sucursales.");
}

// ===============================
//   Filtros
// ===============================
$fImei        = $_GET['imei']         ?? '';
$fTipo        = $_GET['tipo_producto']?? '';
$fEstatus     = $_GET['estatus']      ?? '';
$fAntiguedad  = $_GET['antiguedad']   ?? '';
$fPrecioMin   = $_GET['precio_min']   ?? '';
$fPrecioMax   = $_GET['precio_max']   ?? '';

// Construir SQL
$sql = "
SELECT 
    i.id,
    p.marca, p.modelo, p.color, p.imei1, p.imei2,
    COALESCE(p.costo_con_iva, p.costo, 0) AS costo_mostrar,
    p.precio_lista,
    p.tipo_producto,
    i.estatus, i.fecha_ingreso,
    TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) AS antiguedad_dias
FROM inventario i
INNER JOIN productos p ON p.id = i.id_producto
WHERE i.id_sucursal = ?
";

$params = [$idEulalia];
$types  = "i";

if ($fImei !== '') {
    $sql .= " AND (p.imei1 LIKE ? OR p.imei2 LIKE ?)";
    $like = "%".$fImei."%";
    $params[] = $like; $params[] = $like;
    $types   .= "ss";
}
if ($fTipo !== '') {
    $sql .= " AND p.tipo_producto = ?";
    $params[] = $fTipo;
    $types   .= "s";
}
if ($fEstatus !== '') {
    $sql .= " AND i.estatus = ?";
    $params[] = $fEstatus;
    $types   .= "s";
}
if ($fAntiguedad === '<30') {
    $sql .= " AND TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) < 30";
} elseif ($fAntiguedad === '30-90') {
    $sql .= " AND TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) BETWEEN 30 AND 90";
} elseif ($fAntiguedad === '>90') {
    $sql .= " AND TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) > 90";
}
if ($fPrecioMin !== '') {
    $sql .= " AND p.precio_lista >= ?";
    $params[] = (float)$fPrecioMin;
    $types   .= "d";
}
if ($fPrecioMax !== '') {
    $sql .= " AND p.precio_lista <= ?";
    $params[] = (float)$fPrecioMax;
    $types   .= "d";
}

$sql .= " ORDER BY i.fecha_ingreso DESC";

$stmt = $conn->prepare($sql);
if (!$stmt) { die("Error de consulta: ".$conn->error); }

$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Totales para la vista
$inventario = [];
$rangos = ['<30'=>0,'30-90'=>0,'>90'=>0];
while ($r = $result->fetch_assoc()) {
    $inventario[] = $r;
    $d = (int)$r['antiguedad_dias'];
    if ($d < 30) $rangos['<30']++;
    elseif ($d <= 90) $rangos['30-90']++;
    else $rangos['>90']++;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Inventario – Almacén Eulalia</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
</head>
<body class="bg-light">
<div class="container mt-4">
    <h2>📦 Inventario — Almacén Eulalia</h2>
    <p class="text-muted mb-3">Total de equipos: <b><?= count($inventario) ?></b></p>

    <!-- Filtros -->
    <form method="GET" class="card p-3 mb-4 shadow-sm bg-white">
        <div class="row g-3">
            <div class="col-md-2">
                <input type="text" name="imei" class="form-control" placeholder="Buscar IMEI..." value="<?= htmlspecialchars($fImei, ENT_QUOTES) ?>">
            </div>
            <div class="col-md-2">
                <select name="tipo_producto" class="form-select">
                    <option value="">Todos los tipos</option>
                    <option value="Equipo"  <?= $fTipo==='Equipo'?'selected':'' ?>>Equipo</option>
                    <option value="Modem"   <?= $fTipo==='Modem'?'selected':'' ?>>Módem</option>
                    <option value="Accesorio" <?= $fTipo==='Accesorio'?'selected':'' ?>>Accesorio</option>
                </select>
            </div>
            <div class="col-md-2">
                <select name="estatus" class="form-select">
                    <option value="">Todos Estatus</option>
                    <option value="Disponible" <?= $fEstatus==='Disponible'?'selected':'' ?>>Disponible</option>
                    <option value="En tránsito" <?= $fEstatus==='En tránsito'?'selected':'' ?>>En tránsito</option>
                    <option value="Vendido" <?= $fEstatus==='Vendido'?'selected':'' ?>>Vendido</option>
                </select>
            </div>
            <div class="col-md-2">
                <select name="antiguedad" class="form-select">
                    <option value="">Antigüedad</option>
                    <option value="<30"   <?= $fAntiguedad === '<30' ? 'selected' : '' ?>>< 30 días</option>
                    <option value="30-90" <?= $fAntiguedad === '30-90' ? 'selected' : '' ?>>30–90 días</option>
                    <option value=">90"   <?= $fAntiguedad === '>90' ? 'selected' : '' ?>>> 90 días</option>
                </select>
            </div>
            <div class="col-md-2">
                <input type="number" name="precio_min" step="0.01" class="form-control" placeholder="Precio min" value="<?= htmlspecialchars($fPrecioMin, ENT_QUOTES) ?>">
            </div>
            <div class="col-md-2">
                <input type="number" name="precio_max" step="0.01" class="form-control" placeholder="Precio max" value="<?= htmlspecialchars($fPrecioMax, ENT_QUOTES) ?>">
            </div>
            <div class="col-12 text-end">
                <button class="btn btn-primary">Filtrar</button>
                <a href="almacen_eulalia.php" class="btn btn-secondary">Limpiar</a>
            </div>
        </div>
    </form>

    <!-- Resumen antigüedad -->
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card border-success">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <span><b>< 30 días</b></span>
                        <span><?= (int)$rangos['<30'] ?></span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-warning">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <span><b>30–90 días</b></span>
                        <span><?= (int)$rangos['30-90'] ?></span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-danger">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <span><b>> 90 días</b></span>
                        <span><?= (int)$rangos['>90'] ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabla -->
    <div class="card shadow">
        <div class="card-header bg-primary text-white">Inventario actual en Eulalia</div>
        <div class="card-body">
            <table id="tablaEulalia" class="table table-striped table-bordered table-sm">
                <thead class="table-dark">
                    <tr>
                        <th>ID Inv</th>
                        <th>Marca</th>
                        <th>Modelo</th>
                        <th>Color</th>
                        <th>IMEI1</th>
                        <th>IMEI2</th>
                        <th>Tipo</th>
                        <th>Costo c/IVA</th>
                        <th>Precio Lista</th>
                        <th>Estatus</th>
                        <th>Fecha Ingreso</th>
                        <th>Antigüedad (días)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inventario as $row): 
                        $dias  = (int)$row['antiguedad_dias'];
                        $clase = $dias < 30 ? 'table-success' : ($dias <= 90 ? 'table-warning' : 'table-danger');
                    ?>
                        <tr class="<?= $clase ?>">
                            <td><?= (int)$row['id'] ?></td>
                            <td><?= htmlspecialchars($row['marca'], ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars($row['modelo'], ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars($row['color'], ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars($row['imei1'] ?? '-', ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars($row['imei2'] ?? '-', ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars($row['tipo_producto'], ENT_QUOTES) ?></td>
                            <td class="text-end">$<?= number_format((float)$row['costo_mostrar'],2) ?></td>
                            <td class="text-end">$<?= number_format((float)$row['precio_lista'],2) ?></td>
                            <td><?= htmlspecialchars($row['estatus'], ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars($row['fecha_ingreso'], ENT_QUOTES) ?></td>
                            <td><b><?= $dias ?></b></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- JS -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script>
$(function(){
  $('#tablaEulalia').DataTable({
    pageLength: 25,
    order: [[ 0, 'desc' ]],
    language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json' }
  });
});
</script>
</body>
</html>
