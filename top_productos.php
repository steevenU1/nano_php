<?php
include 'db.php';

$rango = $_GET['rango'] ?? 'historico';

$where = "";
if ($rango == 'semana') {
    $inicio = date('Y-m-d', strtotime('last tuesday'));
    $fin = date('Y-m-d', strtotime('next monday'));
    $where = "WHERE v.fecha_venta BETWEEN '$inicio' AND '$fin'";
} elseif ($rango == 'mes') {
    $inicio = date('Y-m-01');
    $fin = date('Y-m-t');
    $where = "WHERE v.fecha_venta BETWEEN '$inicio' AND '$fin'";
}

// Consulta de top vendidos
$sql = "
    SELECT p.marca, p.modelo, p.capacidad, COUNT(*) AS vendidos
    FROM detalle_venta dv
    INNER JOIN productos p ON p.id = dv.id_producto
    INNER JOIN ventas v ON v.id = dv.id_venta
    $where
    GROUP BY p.marca, p.modelo, p.capacidad
    ORDER BY vendidos DESC
    LIMIT 5
";

$result = $conn->query($sql);

// Mostrar tabla
echo '<table class="table table-sm table-bordered mb-0">';
echo '<thead class="table-light"><tr><th>#</th><th>Equipo</th><th>Capacidad</th><th>Vendidos</th></tr></thead><tbody>';

$i = 1;
while ($row = $result->fetch_assoc()) {
    $equipo = $row['marca'] . ' ' . $row['modelo'];
    echo "<tr>
        <td>$i</td>
        <td>$equipo</td>
        <td>{$row['capacidad']}</td>
        <td><b>{$row['vendidos']}</b></td>
    </tr>";
    $i++;
}

if ($i == 1) {
    echo "<tr><td colspan='4' class='text-center text-muted'>No hay datos</td></tr>";
}
echo '</tbody></table>';
