<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

// 📄 Configuración para exportar a Excel
header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=historial_ventas.xls");
header("Pragma: no-cache");
header("Expires: 0");

echo "\xEF\xBB\xBF"; // BOM para UTF-8

$rolUsuario  = $_SESSION['rol'];
$id_sucursal = $_SESSION['id_sucursal'];

// 🔹 Calcular semana martes-lunes desde parámetro GET
function obtenerSemanaPorIndice($offset = 0) {
    $hoy = new DateTime();
    $diaSemana = $hoy->format('N'); // lunes=1, domingo=7
    $dif = $diaSemana - 2; // martes=2
    if ($dif < 0) $dif += 7;

    $inicio = new DateTime();
    $inicio->modify("-$dif days")->setTime(0, 0, 0);

    if ($offset > 0) {
        $inicio->modify("-" . (7 * $offset) . " days");
    }

    $fin = clone $inicio;
    $fin->modify("+6 days")->setTime(23, 59, 59);

    return [$inicio->format('Y-m-d'), $fin->format('Y-m-d')];
}

$semanaSeleccionada = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
list($fechaInicio, $fechaFin) = obtenerSemanaPorIndice($semanaSeleccionada);

// =====================
//   Filtros base
// =====================
$where  = " WHERE DATE(v.fecha_venta) BETWEEN ? AND ? ";
$params = [$fechaInicio, $fechaFin];
$types  = "ss";

// Si NO es admin, filtramos por sucursal
if ($rolUsuario != 'Admin') {
    $where   .= " AND v.id_sucursal = ? ";
    $params[] = $id_sucursal;
    $types   .= "i";
}

// Tipo de venta
if (!empty($_GET['tipo_venta'])) {
    $where   .= " AND v.tipo_venta = ? ";
    $params[] = $_GET['tipo_enta'] ?? $_GET['tipo_venta']; // tolerante
    $types   .= "s";
}

// Usuario (solo si no es Admin)
if (!empty($_GET['usuario']) && $rolUsuario != 'Admin') {
    $where   .= " AND v.id_usuario = ? ";
    $params[] = $_GET['usuario'];
    $types   .= "i";
}

// =====================
//   Consulta ventas
// =====================
$sqlVentas = "
    SELECT v.id, v.tag, v.nombre_cliente, v.telefono_cliente, v.tipo_venta,
           v.precio_venta, v.fecha_venta, v.comision,
           u.nombre AS usuario,
           s.nombre AS sucursal
    FROM ventas v
    INNER JOIN usuarios u   ON v.id_usuario   = u.id
    INNER JOIN sucursales s ON v.id_sucursal  = s.id
    $where
    ORDER BY v.fecha_venta DESC
";
$stmt = $conn->prepare($sqlVentas);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$ventas = $stmt->get_result();

// =====================
//   Consulta detalles
// =====================
$sqlDetalle = "
    SELECT dv.id_venta, p.marca, p.modelo, p.color, dv.imei1,
           dv.comision_regular, dv.comision_especial, dv.comision
    FROM detalle_venta dv
    INNER JOIN productos p ON dv.id_producto = p.id
";
$detalleResult = $conn->query($sqlDetalle);
$detalles = [];
while ($row = $detalleResult->fetch_assoc()) {
    $detalles[$row['id_venta']][] = $row;
}

// =====================
//   Generar Excel
// =====================
echo "<table border='1'>";
echo "<thead>
        <tr style='background-color:#f2f2f2'>
            <th>ID Venta</th>
            <th>Fecha</th>
            <th>TAG</th>
            <th>Cliente</th>
            <th>Teléfono</th>
            <th>Sucursal</th>
            <th>Usuario</th>
            <th>Tipo Venta</th>
            <th>Precio Venta</th>
            <th>Comisión Total Venta</th>
            <th>Marca</th>
            <th>Modelo</th>
            <th>Color</th>
            <th>IMEI</th>
            <th>Comisión Regular</th>
            <th>Comisión Especial</th>
            <th>Total Comisión Equipo</th>
        </tr>
      </thead>
      <tbody>";

while ($venta = $ventas->fetch_assoc()) {
    if (isset($detalles[$venta['id']])) {
        foreach ($detalles[$venta['id']] as $equipo) {
            echo "<tr>
                    <td>{$venta['id']}</td>
                    <td>{$venta['fecha_venta']}</td>
                    <td>{$venta['tag']}</td>
                    <td>{$venta['nombre_cliente']}</td>
                    <td>{$venta['telefono_cliente']}</td>
                    <td>{$venta['sucursal']}</td>
                    <td>{$venta['usuario']}</td>
                    <td>{$venta['tipo_venta']}</td>
                    <td>{$venta['precio_venta']}</td>
                    <td>{$venta['comision']}</td>
                    <td>{$equipo['marca']}</td>
                    <td>{$equipo['modelo']}</td>
                    <td>{$equipo['color']}</td>
                    <td>=\"{$equipo['imei1']}\"</td>
                    <td>{$equipo['comision_regular']}</td>
                    <td>{$equipo['comision_especial']}</td>
                    <td>{$equipo['comision']}</td>
                  </tr>";
        }
    } else {
        echo "<tr>
                <td>{$venta['id']}</td>
                <td>{$venta['fecha_venta']}</td>
                <td>{$venta['tag']}</td>
                <td>{$venta['nombre_cliente']}</td>
                <td>{$venta['telefono_cliente']}</td>
                <td>{$venta['sucursal']}</td>
                <td>{$venta['usuario']}</td>
                <td>{$venta['tipo_venta']}</td>
                <td>{$venta['precio_venta']}</td>
                <td>{$venta['comision']}</td>
                <td></td><td></td><td></td><td></td><td></td><td></td><td></td>
              </tr>";
    }
}

echo "</tbody></table>";
exit;
?>
