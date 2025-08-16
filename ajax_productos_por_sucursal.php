<?php
include 'db.php';

$id_sucursal = intval($_POST['id_sucursal']);

$sql = "SELECT i.id AS id_inventario, p.marca, p.modelo, p.color, p.imei1, p.precio_lista, LOWER(p.tipo_producto) AS tipo
        FROM inventario i
        INNER JOIN productos p ON i.id_producto = p.id
        WHERE i.id_sucursal = ? AND i.estatus = 'Disponible'
        ORDER BY p.marca, p.modelo";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id_sucursal);
$stmt->execute();
$productos = $stmt->get_result();

$options = '<option value="">Seleccione un equipo...</option>';
while ($row = $productos->fetch_assoc()) {
    $texto = strtoupper($row['tipo']) . " | " . $row['marca'] . " " . $row['modelo'] . " (" . $row['imei1'] . ") - $" . number_format($row['precio_lista'], 2);
    $options .= "<option value='{$row['id_inventario']}'>{$texto}</option>";
}

echo $options;
?>
