<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

// 📄 Encabezados para exportar a Excel
header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=historial_ventas_sims.xls");
header("Pragma: no-cache");
header("Expires: 0");

echo "\xEF\xBB\xBF"; // BOM UTF-8

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
   FILTROS (mismos que la vista)
======================== */
$semanaSeleccionada = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
list($inicioSemanaObj, $finSemanaObj) = obtenerSemanaPorIndice($semanaSeleccionada);
$inicioSemana = $inicioSemanaObj->format('Y-m-d');
$finSemana    = $finSemanaObj->format('Y-m-d');

$id_sucursal = (int)($_SESSION['id_sucursal'] ?? 0);
$rol         = $_SESSION['rol'] ?? '';
$idUsuario   = (int)($_SESSION['id_usuario'] ?? 0);

$where  = " WHERE DATE(vs.fecha_venta) BETWEEN ? AND ?";
$params = [$inicioSemana, $finSemana];
$types  = "ss";

// Filtro por rol
if ($rol === 'Ejecutivo') {
    $where   .= " AND vs.id_usuario=? ";
    $params[] = $idUsuario;
    $types   .= "i";
} elseif ($rol === 'Gerente') {
    $where   .= " AND vs.id_sucursal=? ";
    $params[] = $id_sucursal;
    $types   .= "i";
}

// Filtros GET
$tipoVentaGet = $_GET['tipo_venta'] ?? '';
$usuarioGet   = $_GET['usuario'] ?? '';
if (!empty($tipoVentaGet)) {
    $where   .= " AND vs.tipo_venta=? ";
    $params[] = $tipoVentaGet;
    $types   .= "s";
}
if (!empty($usuarioGet)) {
    $where   .= " AND vs.id_usuario=? ";
    $params[] = (int)$usuarioGet;
    $types   .= "i";
}

/* ========================
   CONSULTA (por SIM)
======================== */
$sql = "
    SELECT 
        vs.id AS id_venta,
        vs.fecha_venta,
        s.nombre AS sucursal,
        u.nombre AS usuario,
        i.iccid,
        vs.tipo_venta,
        vs.modalidad,            -- 👈 para pospago
        vs.nombre_cliente,       -- 👈 cliente
        vs.precio_total,
        vs.comision_ejecutivo,
        vs.comision_gerente,
        vs.comentarios
    FROM ventas_sims vs
    INNER JOIN usuarios u         ON vs.id_usuario   = u.id
    INNER JOIN sucursales s       ON vs.id_sucursal  = s.id
    INNER JOIN detalle_venta_sims d ON vs.id         = d.id_venta
    INNER JOIN inventario_sims i  ON d.id_sim        = i.id
    $where
    ORDER BY vs.fecha_venta DESC, vs.id DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

/* ========================
   GENERAR TABLA XLS
======================== */
echo "<table border='1'>";
echo "<thead>
        <tr style='background-color:#f2f2f2'>
            <th>ID Venta</th>
            <th>Fecha</th>
            <th>Sucursal</th>
            <th>Usuario</th>
            <th>Cliente</th>          <!-- NUEVO -->
            <th>ICCID</th>
            <th>Tipo Venta</th>
            <th>Modalidad</th>        <!-- NUEVO (solo para pospago) -->
            <th>Precio Total Venta</th>
            <th>Comisión Ejecutivo</th>
            <th>Comisión Gerente</th>
            <th>Comentarios</th>
        </tr>
      </thead>
      <tbody>";

while ($row = $res->fetch_assoc()) {
    $iccid      = htmlspecialchars($row['iccid']);
    $tipoVenta  = (string)$row['tipo_venta'];
    $modalidad  = ($tipoVenta === 'Pospago') ? ($row['modalidad'] ?? '') : ''; // solo pospago

    echo "<tr>
            <td>{$row['id_venta']}</td>
            <td>{$row['fecha_venta']}</td>
            <td>".htmlspecialchars($row['sucursal'])."</td>
            <td>".htmlspecialchars($row['usuario'])."</td>
            <td>".htmlspecialchars($row['nombre_cliente'] ?? '')."</td>
            <td>=\"{$iccid}\"</td>
            <td>".htmlspecialchars($tipoVenta)."</td>
            <td>".htmlspecialchars($modalidad)."</td>
            <td>{$row['precio_total']}</td>
            <td>{$row['comision_ejecutivo']}</td>
            <td>{$row['comision_gerente']}</td>
            <td>".htmlspecialchars($row['comentarios'])."</td>
          </tr>";
}

echo "</tbody></table>";
exit;
