<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

/* ========================
   FUNCIONES AUXILIARES
======================== */
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

/* ========================
   FILTROS
======================== */
$semanaSeleccionada = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
list($inicioSemanaObj, $finSemanaObj) = obtenerSemanaPorIndice($semanaSeleccionada);
$inicioSemana = $inicioSemanaObj->format('Y-m-d');
$finSemana    = $finSemanaObj->format('Y-m-d');

$id_sucursal = $_SESSION['id_sucursal'];
$rol         = $_SESSION['rol'];

// Obtener usuarios de la sucursal para filtro
$sqlUsuarios = "SELECT id, nombre FROM usuarios WHERE id_sucursal=?";
$stmtUsuarios = $conn->prepare($sqlUsuarios);
$stmtUsuarios->bind_param("i", $id_sucursal);
$stmtUsuarios->execute();
$usuarios = $stmtUsuarios->get_result();

// Construcción del WHERE base
$where  = " WHERE DATE(vs.fecha_venta) BETWEEN ? AND ?";
$params = [$inicioSemana, $finSemana];
$types  = "ss";

// Filtro según rol
if ($rol == 'Ejecutivo') {
    $where   .= " AND vs.id_usuario=?";
    $params[] = $_SESSION['id_usuario'];
    $types   .= "i";
} elseif ($rol == 'Gerente') {
    $where   .= " AND vs.id_sucursal=?";
    $params[] = $id_sucursal;
    $types   .= "i";
}

// Filtros GET
if (!empty($_GET['tipo_venta'])) {
    $where   .= " AND vs.tipo_venta=?";
    $params[] = $_GET['tipo_venta'];
    $types   .= "s";
}
if (!empty($_GET['usuario'])) {
    $where   .= " AND vs.id_usuario=?";
    $params[] = (int)$_GET['usuario'];
    $types   .= "i";
}

/* ========================
   CONSULTA HISTORIAL
======================== */
$sqlVentas = "
    SELECT
        vs.id,
        vs.tipo_venta,
        vs.modalidad,              -- para pospago
        vs.precio_total,
        vs.comision_ejecutivo,
        vs.comision_gerente,
        vs.fecha_venta,
        vs.comentarios,
        vs.id_usuario,
        vs.nombre_cliente,         -- cliente
        u.nombre AS usuario,
        s.nombre AS sucursal,
        i.iccid
    FROM ventas_sims vs
    INNER JOIN usuarios u            ON vs.id_usuario  = u.id
    INNER JOIN sucursales s          ON vs.id_sucursal = s.id
    INNER JOIN detalle_venta_sims d  ON vs.id          = d.id_venta
    INNER JOIN inventario_sims i     ON d.id_sim       = i.id
    $where
    ORDER BY vs.fecha_venta DESC
";
$stmt = $conn->prepare($sqlVentas);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$ventas = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Historial de Ventas SIM</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>Historial de Ventas SIM - <?= htmlspecialchars($_SESSION['nombre']) ?></h2>
    <a href="panel.php" class="btn btn-secondary mb-3">← Volver al Panel</a>

    <?php if (!empty($_GET['msg'])): ?>
        <div class="alert alert-info"><?= htmlspecialchars($_GET['msg']) ?></div>
    <?php endif; ?>

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
                    <option value="Nueva"         <?= (($_GET['tipo_venta'] ?? '')=='Nueva')?'selected':'' ?>>Nueva</option>
                    <option value="Portabilidad"  <?= (($_GET['tipo_venta'] ?? '')=='Portabilidad')?'selected':'' ?>>Portabilidad</option>
                    <option value="Regalo"        <?= (($_GET['tipo_venta'] ?? '')=='Regalo')?'selected':'' ?>>Regalo</option>
                    <option value="Pospago"       <?= (($_GET['tipo_venta'] ?? '')=='Pospago')?'selected':'' ?>>Pospago</option>
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
        </div>

        <div class="mt-3 text-end">
            <button class="btn btn-primary">Filtrar</button>
            <a href="historial_ventas_sims.php" class="btn btn-secondary">Limpiar</a>

            <!-- ✅ Exportar con los mismos filtros -->
            <button
                type="submit"
                class="btn btn-success"
                formaction="exportar_excel_sims.php"
                formmethod="GET"
                formtarget="_blank"
                title="Exporta con los filtros actuales"
            >
                ⬇️ Exportar a Excel
            </button>
        </div>
    </form>

    <!-- 🔹 Historial -->
    <table class="table table-striped table-bordered">
        <thead class="table-dark">
            <tr>
                <th>ID</th>
                <th>Fecha</th>
                <th>Sucursal</th>
                <th>Usuario</th>
                <th>Cliente</th>      <!-- NUEVO -->
                <th>ICCID</th>
                <th>Tipo Venta</th>
                <th>Modalidad</th>    <!-- NUEVO (solo para pospago) -->
                <th>Precio</th>
                <th>Com. Ejecutivo</th>
                <th>Com. Gerente</th>
                <th>Comentarios</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php while($v = $ventas->fetch_assoc()): ?>
            <tr>
                <td><?= $v['id'] ?></td>
                <td><?= $v['fecha_venta'] ?></td>
                <td><?= htmlspecialchars($v['sucursal']) ?></td>
                <td><?= htmlspecialchars($v['usuario']) ?></td>
                <td><?= htmlspecialchars($v['nombre_cliente'] ?? '') ?></td>
                <td><?= htmlspecialchars($v['iccid']) ?></td>
                <td><?= htmlspecialchars($v['tipo_venta']) ?></td>
                <td><?= ($v['tipo_venta'] === 'Pospago') ? htmlspecialchars($v['modalidad']) : '' ?></td>
                <td>$<?= number_format($v['precio_total'],2) ?></td>
                <td>$<?= number_format($v['comision_ejecutivo'],2) ?></td>
                <td>$<?= number_format($v['comision_gerente'],2) ?></td>
                <td><?= htmlspecialchars($v['comentarios']) ?></td>
                <td>
                    <?php if(
                        in_array($_SESSION['rol'], ['Ejecutivo','Gerente','Admin']) 
                        && $_SESSION['id_usuario'] == $v['id_usuario']
                    ): ?>
                        <form action="eliminar_venta_sim.php" method="POST" style="display:inline;">
                            <input type="hidden" name="id_venta" value="<?= $v['id'] ?>">
                            <button type="submit" 
                                    class="btn btn-sm btn-danger"
                                    onclick="return confirm('¿Seguro que deseas eliminar esta venta de SIM?\nEsto devolverá el SIM al inventario y quitará la comisión.')">
                                🗑 Eliminar
                            </button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

</body>
</html>
