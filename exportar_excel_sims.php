<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';

/* =========================================
   Encabezados para exportación a Excel (XLS)
========================================= */
header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=historial_ventas_sims.xls");
header("Pragma: no-cache");
header("Expires: 0");

// Forzar UTF-8
echo "\xEF\xBB\xBF";

/* ========================
   FUNCIONES AUXILIARES
======================== */
if (!function_exists('h')) {
    function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
}

function obtenerSemanaPorIndice($offset = 0) {
    $hoy = new DateTime();
    $diaSemana = (int)$hoy->format('N'); // 1=lunes ... 7=domingo
    $dif = $diaSemana - 2;               // martes=2
    if ($dif < 0) $dif += 7;

    $inicio = new DateTime();
    $inicio->modify("-{$dif} days")->setTime(0,0,0);

    if ($offset > 0) {
        $inicio->modify("-" . (7 * $offset) . " days");
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

$where  = " WHERE DATE(vs.fecha_venta) BETWEEN ? AND ? ";
$params = [$inicioSemana, $finSemana];
$types  = "ss";

/* ---- Filtro por rol ---- */
if ($rol === 'Ejecutivo') {
    $where   .= " AND vs.id_usuario = ? ";
    $params[] = $idUsuario;
    $types   .= "i";
} elseif ($rol === 'Gerente') {
    $where   .= " AND vs.id_sucursal = ? ";
    $params[] = $id_sucursal;
    $types   .= "i";
}

/* ---- Filtros GET ---- */
$tipoVentaGet = $_GET['tipo_venta'] ?? '';
$usuarioGet   = $_GET['usuario']     ?? '';

if ($tipoVentaGet !== '') {
    $where   .= " AND vs.tipo_venta = ? ";
    $params[] = $tipoVentaGet;
    $types   .= "s";
}
if ($usuarioGet !== '') {
    $where   .= " AND vs.id_usuario = ? ";
    $params[] = (int)$usuarioGet;
    $types   .= "i";
}

/* ========================
   CONSULTA (por SIM)
   - Traemos operador SIEMPRE desde inventario_sims.operador
======================== */
$sql = "
    SELECT 
        vs.id                AS id_venta,
        vs.fecha_venta,
        s.nombre             AS sucursal,
        u.nombre             AS usuario,
        vs.nombre_cliente,
        vs.tipo_venta,
        vs.modalidad,                    -- (para Pospago)
        vs.precio_total,
        vs.comision_ejecutivo,
        vs.comision_gerente,
        vs.comentarios,

        -- Detalle por SIM
        i.iccid,
        i.operador           AS operador_sim,
        i.dn                 AS dn_sim      -- opcional, por si quieres verlo
    FROM ventas_sims vs
    INNER JOIN usuarios            u  ON vs.id_usuario  = u.id
    INNER JOIN sucursales          s  ON vs.id_sucursal = s.id
    INNER JOIN detalle_venta_sims  d  ON vs.id          = d.id_venta
    INNER JOIN inventario_sims     i  ON d.id_sim       = i.id
    $where
    ORDER BY vs.fecha_venta DESC, vs.id DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

/* ========================
   GENERAR TABLA XLS
   - Protegemos ICCID y DN para que Excel no los formatee raro
======================== */
echo "<table border='1'>";
echo "<thead>
        <tr style='background-color:#f2f2f2'>
            <th>ID Venta</th>
            <th>Fecha</th>
            <th>Sucursal</th>
            <th>Usuario</th>
            <th>Cliente</th>
            <th>ICCID</th>
            <th>Operador</th>          <!-- 👈 VIENE DE inventario_sims.operador -->
            <th>DN</th>                <!-- opcional, se puede quitar si no lo quieres -->
            <th>Tipo Venta</th>
            <th>Modalidad</th>
            <th>Precio Total Venta</th>
            <th>Comisión Ejecutivo</th>
            <th>Comisión Gerente</th>
            <th>Comentarios</th>
        </tr>
      </thead>
      <tbody>";

while ($row = $res->fetch_assoc()) {
    $tipoVenta = (string)($row['tipo_venta'] ?? '');
    $modalidad = ($tipoVenta === 'Pospago') ? (string)($row['modalidad'] ?? '') : '';

    // Forzar Excel a tratar ICCID/DN como texto (evita notación científica y recortes)
    $iccidExcel = '="' . str_replace('"', '""', (string)($row['iccid'] ?? '')) . '"';
    $dnExcel    = '="' . str_replace('"', '""', (string)($row['dn_sim'] ?? '')) . '"';

    echo "<tr>
            <td>".(int)$row['id_venta']."</td>
            <td>".h($row['fecha_venta'])."</td>
            <td>".h($row['sucursal'])."</td>
            <td>".h($row['usuario'])."</td>
            <td>".h($row['nombre_cliente'] ?? '')."</td>
            <td>{$iccidExcel}</td>
            <td>".h($row['operador_sim'] ?? '')."</td>
            <td>{$dnExcel}</td>
            <td>".h($tipoVenta)."</td>
            <td>".h($modalidad)."</td>
            <td>".($row['precio_total'] ?? 0)."</td>
            <td>".($row['comision_ejecutivo'] ?? 0)."</td>
            <td>".($row['comision_gerente'] ?? 0)."</td>
            <td>".h($row['comentarios'] ?? '')."</td>
          </tr>";
}

echo "</tbody></table>";
exit;
