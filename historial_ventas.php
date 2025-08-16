<?php
include 'navbar.php';

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

// 🔹 Función semana martes-lunes
function obtenerSemanaPorIndice($offset = 0) {
    $hoy = new DateTime();
    $diaSemana = $hoy->format('N'); // 1=lunes ... 7=domingo
    $dif = $diaSemana - 2; // martes=2
    if ($dif < 0) $dif += 7;

    $inicio = new DateTime();
    $inicio->modify("-$dif days")->setTime(0,0,0);

    if ($offset > 0) {
        $inicio->modify("-" . (7*$offset) . " days");
    }

    $fin = clone $inicio;
    $fin->modify("+6 days")->setTime(23,59,59);

    return [$inicio, $fin];
}

// 🔹 Semana seleccionada
$semanaSeleccionada = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
list($inicioSemanaObj, $finSemanaObj) = obtenerSemanaPorIndice($semanaSeleccionada);
$inicioSemana = $inicioSemanaObj->format('Y-m-d');
$finSemana = $finSemanaObj->format('Y-m-d');

$msg = $_GET['msg'] ?? '';
$id_sucursal = $_SESSION['id_sucursal'] ?? 0;

// 🔹 Subtipo de la sucursal (para reglas de visibilidad)
$subtipoSucursal = '';
if ($id_sucursal) {
    $stmtSubtipo = $conn->prepare("SELECT subtipo FROM sucursales WHERE id = ? LIMIT 1");
    $stmtSubtipo->bind_param("i", $id_sucursal);
    $stmtSubtipo->execute();
    $rowSub = $stmtSubtipo->get_result()->fetch_assoc();
    $subtipoSucursal = $rowSub['subtipo'] ?? '';
    $stmtSubtipo->close();
}
$esSubdistribuidor = ($subtipoSucursal === 'Subdistribuidor');

// 🔹 Obtener usuarios para filtro (de la misma sucursal)
$sqlUsuarios = "SELECT id, nombre FROM usuarios WHERE id_sucursal=?";
$stmtUsuarios = $conn->prepare($sqlUsuarios);
$stmtUsuarios->bind_param("i", $id_sucursal);
$stmtUsuarios->execute();
$usuarios = $stmtUsuarios->get_result();

// 🔹 Construir WHERE base
$where = " WHERE DATE(v.fecha_venta) BETWEEN ? AND ?";
$params = [$inicioSemana, $finSemana];
$types = "ss";

// 🔹 Filtro según rol
if ($_SESSION['rol'] == 'Ejecutivo') {
    $where .= " AND v.id_usuario=?";
    $params[] = $_SESSION['id_usuario'];
    $types .= "i";
} elseif ($_SESSION['rol'] == 'Gerente') {
    $where .= " AND v.id_sucursal=?";
    $params[] = $_SESSION['id_sucursal'];
    $types .= "i";
}
// Admin ve todas

// 🔹 Filtros GET
if (!empty($_GET['tipo_venta'])) {
    $where .= " AND v.tipo_venta=?";
    $params[] = $_GET['tipo_venta'];
    $types .= "s";
}
if (!empty($_GET['usuario'])) {
    $where .= " AND v.id_usuario=?";
    $params[] = $_GET['usuario'];
    $types .= "i";
}
if (!empty($_GET['buscar'])) {
    $where .= " AND (v.nombre_cliente LIKE ? OR v.telefono_cliente LIKE ? OR v.tag LIKE ?
                     OR EXISTS(SELECT 1 FROM detalle_venta dv WHERE dv.id_venta=v.id AND dv.imei1 LIKE ?))";
    $busqueda = "%".$_GET['buscar']."%";
    array_push($params, $busqueda, $busqueda, $busqueda, $busqueda);
    $types .= "ssss";
}

// 🔹 Calcular tarjetas resumen
$sqlResumen = "
    SELECT 
        COUNT(dv.id) AS total_unidades,
        IFNULL(SUM(dv.comision_regular + dv.comision_especial),0) AS total_comisiones
    FROM detalle_venta dv
    INNER JOIN ventas v ON dv.id_venta = v.id
    $where
";
$stmtResumen = $conn->prepare($sqlResumen);
$stmtResumen->bind_param($types, ...$params);
$stmtResumen->execute();
$resumen = $stmtResumen->get_result()->fetch_assoc();
$totalUnidades = (int)$resumen['total_unidades'];
$totalComisiones = (float)$resumen['total_comisiones'];
$stmtResumen->close();

// 🔹 Monto total vendido
$sqlMonto = "
    SELECT IFNULL(SUM(v.precio_venta),0) AS total_monto
    FROM ventas v
    $where
";
$stmtMonto = $conn->prepare($sqlMonto);
$stmtMonto->bind_param($types, ...$params);
$stmtMonto->execute();
$totalMonto = (float)$stmtMonto->get_result()->fetch_assoc()['total_monto'];
$stmtMonto->close();

// 🔹 Consulta ventas
$sqlVentas = "
    SELECT v.id, v.tag, v.nombre_cliente, v.telefono_cliente, v.tipo_venta,
           v.precio_venta, v.fecha_venta,
           u.id AS id_usuario, u.nombre AS usuario
    FROM ventas v
    INNER JOIN usuarios u ON v.id_usuario = u.id
    $where
    ORDER BY v.fecha_venta DESC
";
$stmt = $conn->prepare($sqlVentas);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$ventas = $stmt->get_result();

// 🔹 Consultar detalles con precio_lista
$sqlDetalle = "
    SELECT dv.id_venta, p.marca, p.modelo, p.color, dv.imei1,
           dv.comision_regular, dv.comision_especial, dv.comision,
           p.precio_lista
    FROM detalle_venta dv
    INNER JOIN productos p ON dv.id_producto = p.id
    ORDER BY dv.id_venta, dv.id ASC
";
$detalleResult = $conn->query($sqlDetalle);
$detalles = [];
while ($row = $detalleResult->fetch_assoc()) {
    $detalles[$row['id_venta']][] = $row;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Historial de Ventas</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        .venta-card { margin-bottom: 1rem; border: 1px solid #dee2e6; border-radius: 8px; background: #fff; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .venta-header { background: #0d6efd; color: white; padding: 0.5rem 1rem; border-radius: 8px 8px 0 0; }
        .card-body { display: flex; flex-direction: column; justify-content: center; }
    </style>
</head>
<body class="bg-light">

<div class="container mt-4">
    <h2>Historial de Ventas - <?= htmlspecialchars($_SESSION['nombre']) ?></h2>
    <a href="panel.php" class="btn btn-secondary mb-3">← Volver al Panel</a>

    <?php if ($msg): ?>
        <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <!-- 🔹 Tarjetas resumen -->
    <div class="row mb-4">
        <div class="col-md-4 d-flex">
            <div class="card text-center shadow-sm h-100 w-100">
                <div class="card-body">
                    <h5 class="card-title">Unidades Vendidas</h5>
                    <p class="display-6"><?= $totalUnidades ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-4 d-flex">
            <div class="card text-center shadow-sm h-100 w-100">
                <div class="card-body">
                    <h5 class="card-title">Monto Vendido</h5>
                    <p class="display-6">$<?= number_format($totalMonto, 2) ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-4 d-flex">
            <div class="card text-center shadow-sm h-100 w-100">
                <div class="card-body">
                    <h5 class="card-title">Total Comisiones</h5>
                    <?php if ($esSubdistribuidor): ?>
                        <p class="display-6">No disponible</p>
                    <?php else: ?>
                        <p class="display-6">$<?= number_format($totalComisiones, 2) ?></p>
                        <small class="text-muted">* Aproximado, sujeto a recálculo semanal</small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 🔹 Filtros -->
    <form method="GET" class="card p-3 mb-4 shadow-sm bg-white">
        <div class="row g-3">
            <div class="col-md-3">
                <label>Semana</label>
                <select name="semana" class="form-select" onchange="this.form.submit()">
                    <?php for ($i=0; $i<8; $i++): 
                        list($ini, $fin) = obtenerSemanaPorIndice($i);
                        $texto = "Del {$ini->format('d/m/Y')} al {$fin->format('d/m/Y')}";
                    ?>
                        <option value="<?= $i ?>" <?= $i==$semanaSeleccionada?'selected':'' ?>><?= $texto ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label>Tipo de Venta</label>
                <select name="tipo_venta" class="form-control">
                    <option value="">Todas</option>
                    <option value="Contado" <?= (($_GET['tipo_venta'] ?? '')=='Contado')?'selected':'' ?>>Contado</option>
                    <option value="Financiamiento" <?= (($_GET['tipo_venta'] ?? '')=='Financiamiento')?'selected':'' ?>>Financiamiento</option>
                    <option value="Financiamiento+Combo" <?= (($_GET['tipo_venta'] ?? '')=='Financiamiento+Combo')?'selected':'' ?>>Financiamiento + Combo</option>
                </select>
            </div>
            <div class="col-md-3">
                <label>Usuario</label>
                <select name="usuario" class="form-control">
                    <option value="">Todos</option>
                    <?php while($u = $usuarios->fetch_assoc()): ?>
                        <option value="<?= $u['id'] ?>" <?= (($_GET['usuario'] ?? '')==$u['id'])?'selected':'' ?>><?= $u['nombre'] ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label>Buscar (Cliente, Tel, IMEI, TAG)</label>
                <input type="text" name="buscar" class="form-control" value="<?= htmlspecialchars($_GET['buscar'] ?? '') ?>">
            </div>
        </div>

        <div class="mt-3 text-end">
            <button class="btn btn-primary">Filtrar</button>
            <a href="historial_ventas.php" class="btn btn-secondary">Limpiar</a>
            <a href="exportar_excel.php?<?= http_build_query($_GET) ?>" class="btn btn-success ms-2">
                📥 Exportar a Excel
            </a>
        </div>
    </form>

    <!-- 🔹 Historial -->
    <?php while ($venta = $ventas->fetch_assoc()): ?>
        <div class="venta-card">
            <div class="venta-header d-flex justify-content-between align-items-center">
                <div>
                    <strong>ID Venta: <?= $venta['id'] ?></strong> - <?= $venta['tipo_venta'] ?>
                </div>
                <div class="d-flex align-items-center">
                    Fecha: <?= $venta['fecha_venta'] ?>
                    
                    <!-- 🔹 Botón eliminar con lógica de permisos -->
                    <?php 
                        $puedeEliminar = false;
                        if ($_SESSION['rol'] == 'Admin') {
                            $puedeEliminar = true;
                        } elseif (in_array($_SESSION['rol'], ['Ejecutivo', 'Gerente']) 
                                  && $_SESSION['id_usuario'] == $venta['id_usuario']) {
                            $puedeEliminar = true;
                        }
                        if ($puedeEliminar):
                    ?>
                        <form action="eliminar_venta.php" method="POST" style="display:inline;">
                            <input type="hidden" name="id_venta" value="<?= $venta['id'] ?>">
                            <button type="submit" 
                                    class="btn btn-sm btn-danger ms-2"
                                    onclick="return confirm('¿Seguro que deseas eliminar esta venta?\nEsto devolverá los equipos al inventario y quitará la comisión.')">
                                🗑 Eliminar
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <div class="p-3">
                <p>
                    <strong>TAG:</strong> <?= $venta['tag'] ?> |
                    <strong>Cliente:</strong> <?= $venta['nombre_cliente'] ?> (<?= $venta['telefono_cliente'] ?>) |
                    <strong>Precio:</strong> $<?= number_format($venta['precio_venta'], 2) ?> |
                    <strong>Vendedor:</strong> <?= $venta['usuario'] ?>
                </p>

                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Marca</th>
                            <th>Modelo</th>
                            <th>Color</th>
                            <th>IMEI</th>
                            <th>Precio Lista</th>
                            <?php if (!$esSubdistribuidor): ?>
                                <th>Comisión Regular</th>
                                <th>Comisión Especial</th>
                                <th>Total Comisión</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (isset($detalles[$venta['id']])): ?>
                            <?php 
                                $esPrincipal = true;
                                foreach ($detalles[$venta['id']] as $equipo): 
                            ?>
                                <tr>
                                    <td><?= $equipo['marca'] ?></td>
                                    <td><?= $equipo['modelo'] ?></td>
                                    <td><?= $equipo['color'] ?></td>
                                    <td><?= $equipo['imei1'] ?></td>
                                    <td>
                                        <?php if ($esPrincipal): ?>
                                            $<?= number_format($equipo['precio_lista'], 2) ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <?php if (!$esSubdistribuidor): ?>
                                        <td>$<?= number_format($equipo['comision_regular'], 2) ?></td>
                                        <td>$<?= number_format($equipo['comision_especial'], 2) ?></td>
                                        <td>$<?= number_format($equipo['comision'], 2) ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php 
                                $esPrincipal = false;
                                endforeach; 
                            ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="<?= $esSubdistribuidor ? 5 : 8; ?>" class="text-center">Sin equipos registrados</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endwhile; ?>
</div>

</body>
</html>
