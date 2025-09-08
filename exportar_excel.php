<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

// 📄 Encabezados para exportar a Excel (HTML table)
header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=historial_ventas.xls");
header("Pragma: no-cache");
header("Expires: 0");

// BOM UTF-8
echo "\xEF\xBB\xBF";

$rolUsuario  = $_SESSION['rol'];
$id_sucursal = (int)($_SESSION['id_sucursal'] ?? 0);

// =====================
//   Semana martes-lunes
// =====================
function obtenerSemanaPorIndice($offset = 0) {
    $hoy = new DateTime();
    $diaSemana = $hoy->format('N'); // 1=lun..7=dom
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
//   (siempre solo Propias)
// =====================
$joinSuc = " INNER JOIN sucursales s ON s.id = v.id_sucursal ";
$where   = " WHERE DATE(v.fecha_venta) BETWEEN ? AND ? AND s.subtipo = 'Propia' ";
$params  = [$fechaInicio, $fechaFin];
$types   = "ss";

// Si NO es admin, restringe a su sucursal
if ($rolUsuario !== 'Admin') {
    $where   .= " AND v.id_sucursal = ? ";
    $params[] = $id_sucursal;
    $types   .= "i";
}

// Tipo de venta
if (!empty($_GET['tipo_venta'])) {
    $where   .= " AND v.tipo_venta = ? ";
    // tolerante a 'tipo_enta' por si llega mal el parámetro
    $params[] = $_GET['tipo_enta'] ?? $_GET['tipo_venta'];
    $types   .= "s";
}

// Usuario (aplicable también para Admin para que coincida con la vista)
if (!empty($_GET['usuario'])) {
    $where   .= " AND v.id_usuario = ? ";
    $params[] = (int)$_GET['usuario'];
    $types   .= "i";
}

// Buscar (cliente, teléfono, TAG, IMEI1 o IMEI2)
if (!empty($_GET['buscar'])) {
    $where   .= " AND (
                     v.nombre_cliente LIKE ?
                     OR v.telefono_cliente LIKE ?
                     OR v.tag LIKE ?
                     OR EXISTS (
                          SELECT 1
                          FROM detalle_venta dv
                          INNER JOIN productos p2 ON p2.id = dv.id_producto
                          WHERE dv.id_venta = v.id
                            AND (dv.imei1 LIKE ? OR p2.imei2 LIKE ?)
                     )
                 ) ";
    $busqueda = "%".trim($_GET['buscar'])."%";
    array_push($params, $busqueda, $busqueda, $busqueda, $busqueda, $busqueda);
    $types   .= "sssss";
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
    $joinSuc
    INNER JOIN usuarios u   ON v.id_usuario  = u.id
    $where
    ORDER BY v.fecha_venta DESC
";
$stmt = $conn->prepare($sqlVentas);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$ventas = $stmt->get_result();
$stmt->close();

// =====================
//   Consulta detalles (ahora trae IMEI2)
// =====================
$sqlDetalle = "
    SELECT dv.id_venta,
           p.marca, p.modelo, p.color,
           dv.imei1,
           p.imei2,
           dv.comision_regular, dv.comision_especial, dv.comision
    FROM detalle_venta dv
    INNER JOIN productos p ON dv.id_producto = p.id
";
$detalleResult = $conn->query($sqlDetalle);
$detalles = [];
while ($row = $detalleResult->fetch_assoc()) {
    $detalles[$row['id_venta']][] = $row;
}
$detalleResult->close();

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
            <th>IMEI1</th>
            <th>IMEI2</th>
            <th>Comisión Regular</th>
            <th>Comisión Especial</th>
            <th>Total Comisión Equipo</th>
        </tr>
      </thead>
      <tbody>";

while ($venta = $ventas->fetch_assoc()) {
    $idVenta    = (int)$venta['id'];
    $fechaVenta = htmlspecialchars($venta['fecha_venta'] ?? '', ENT_QUOTES, 'UTF-8');
    $tag        = htmlspecialchars($venta['tag'] ?? '', ENT_QUOTES, 'UTF-8');
    $cliente    = htmlspecialchars($venta['nombre_cliente'] ?? '', ENT_QUOTES, 'UTF-8');
    $tel        = htmlspecialchars($venta['telefono_cliente'] ?? '', ENT_QUOTES, 'UTF-8');
    $sucursal   = htmlspecialchars($venta['sucursal'] ?? '', ENT_QUOTES, 'UTF-8');
    $usuario    = htmlspecialchars($venta['usuario'] ?? '', ENT_QUOTES, 'UTF-8');
    $tipoVenta  = htmlspecialchars($venta['tipo_venta'] ?? '', ENT_QUOTES, 'UTF-8');
    $precio     = (float)($venta['precio_venta'] ?? 0);
    $comisionV  = (float)($venta['comision'] ?? 0);

    if (isset($detalles[$idVenta])) {
        foreach ($detalles[$idVenta] as $equipo) {
            $marca   = htmlspecialchars($equipo['marca'] ?? '', ENT_QUOTES, 'UTF-8');
            $modelo  = htmlspecialchars($equipo['modelo'] ?? '', ENT_QUOTES, 'UTF-8');
            $color   = htmlspecialchars($equipo['color'] ?? '', ENT_QUOTES, 'UTF-8');
            $imei1   = $equipo['imei1'] ?? '';
            $imei2   = $equipo['imei2'] ?? '';
            $creg    = (float)($equipo['comision_regular'] ?? 0);
            $cesp    = (float)($equipo['comision_especial'] ?? 0);
            $ctot    = (float)($equipo['comision'] ?? 0);

            echo "<tr>
                    <td>{$idVenta}</td>
                    <td>{$fechaVenta}</td>
                    <td>{$tag}</td>
                    <td>{$cliente}</td>
                    <td>{$tel}</td>
                    <td>{$sucursal}</td>
                    <td>{$usuario}</td>
                    <td>{$tipoVenta}</td>
                    <td>{$precio}</td>
                    <td>{$comisionV}</td>
                    <td>{$marca}</td>
                    <td>{$modelo}</td>
                    <td>{$color}</td>
                    <td>=\"".htmlspecialchars($imei1, ENT_QUOTES, 'UTF-8')."\"</td>
                    <td>=\"".htmlspecialchars($imei2, ENT_QUOTES, 'UTF-8')."\"</td>
                    <td>{$creg}</td>
                    <td>{$cesp}</td>
                    <td>{$ctot}</td>
                  </tr>";
        }
    } else {
        echo "<tr>
                <td>{$idVenta}</td>
                <td>{$fechaVenta}</td>
                <td>{$tag}</td>
                <td>{$cliente}</td>
                <td>{$tel}</td>
                <td>{$sucursal}</td>
                <td>{$usuario}</td>
                <td>{$tipoVenta}</td>
                <td>{$precio}</td>
                <td>{$comisionV}</td>
                <td></td><td></td><td></td>
                <td></td><td></td>
                <td></td><td></td><td></td>
              </tr>";
    }
}

echo "</tbody></table>";
exit;
