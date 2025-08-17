<?php
// procesar_venta_master_admin.php (Nano) – con comision_master_admin

session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$fechaVenta = date('Y-m-d H:i:s');

/* =========================
   ENTRADAS DEL FORMULARIO
========================= */
$idSucursal        = (int)($_POST['id_sucursal'] ?? 0);
$tag               = trim($_POST['tag'] ?? '');
$nombreCliente     = trim($_POST['nombre_cliente'] ?? '');
$telefonoCliente   = trim($_POST['telefono_cliente'] ?? '');

$tipoVenta         = trim($_POST['tipo_venta'] ?? 'Contado');       // ENUM
$precioVenta       = (float)($_POST['precio_venta'] ?? 0);

$enganche          = (float)($_POST['enganche'] ?? 0);
$formaPagoEng      = trim($_POST['forma_pago_enganche'] ?? 'Efectivo'); // ENUM
$engancheEfectivo  = (float)($_POST['enganche_efectivo'] ?? 0);
$engancheTarjeta   = (float)($_POST['enganche_tarjeta'] ?? 0);

$plazoSemanas      = (int)($_POST['plazo_semanas'] ?? 0);
$financiera        = trim($_POST['financiera'] ?? 'N/A');           // ENUM

$comentarios       = trim($_POST['comentarios'] ?? '');
$origen_ma         = ($_POST['origen_ma'] ?? 'nano');               // 'nano' | 'propio'

/* =========================
   NORMALIZACIONES/VALIDACIONES BÁSICAS
========================= */
$tipoVentaAllow = ['Contado','Financiamiento','Financiamiento+Combo'];
if (!in_array($tipoVenta, $tipoVentaAllow, true)) $tipoVenta = 'Contado';

$formaPagoAllow = ['Efectivo','Tarjeta','Mixto'];
if (!in_array($formaPagoEng, $formaPagoAllow, true)) $formaPagoEng = 'Efectivo';

$financieraAllow = ['N/A','PayJoy','Krediya'];
if (!in_array($financiera, $financieraAllow, true)) $financiera = 'N/A';

$origen_ma = ($origen_ma === 'propio') ? 'propio' : 'nano';

if ($tag === '' || $precioVenta <= 0 || $idSucursal <= 0) {
    die("Error: faltan datos obligatorios (TAG, precio o sucursal).");
}
if ($origen_ma === 'nano') {
    if ($nombreCliente === '' || $telefonoCliente === '') {
        die("Error: para origen Nano, nombre y teléfono del cliente son obligatorios.");
    }
}

/* =========================
   ENGANCHE TOTAL & COMISIÓN MA
========================= */
// Enganche total (si es mixto, suma; en otro caso usa 'enganche')
$engancheTotal = ($formaPagoEng === 'Mixto')
    ? ($engancheEfectivo + $engancheTarjeta)
    : $enganche;

// No permitir enganche mayor al precio de venta (sanidad)
if ($engancheTotal < 0) $engancheTotal = 0;
if ($engancheTotal > $precioVenta) $engancheTotal = $precioVenta;

// Cálculo de comisión de Master Admin
if ($origen_ma === 'nano') {
    // precio - enganche - 10% de precio
    $comisionMA = ($precioVenta * 0.90) - $engancheTotal;
} else {
    // comisión negativa del 10% del monto
    $comisionMA = -($precioVenta * 0.10);
}
$comisionMA = round($comisionMA, 2);

/* =========================
   INSERT A VENTAS (incluye comision_master_admin)
========================= */
$sql = "INSERT INTO ventas (
    tag, nombre_cliente, telefono_cliente,
    tipo_venta, precio_venta, enganche, forma_pago_enganche,
    enganche_efectivo, enganche_tarjeta, plazo_semanas, financiera,
    id_sucursal, id_usuario, origen_ma, comentarios, fecha_venta,
    comision_master_admin
) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Error en prepare(): " . $conn->error);
}

/* Tipos de bind_param por orden:
   s s s s d d s d d i s i i s s s d
*/
$stmt->bind_param(
    "ssssddsddisiisssd",
    $tag, $nombreCliente, $telefonoCliente,
    $tipoVenta, $precioVenta, $enganche, $formaPagoEng,
    $engancheEfectivo, $engancheTarjeta, $plazoSemanas, $financiera,
    $idSucursal, $idUsuario, $origen_ma, $comentarios, $fechaVenta,
    $comisionMA
);

if (!$stmt->execute()) {
    die("Error al guardar la venta: " . $stmt->error);
}

$idVenta = $stmt->insert_id;
$stmt->close();

/* =========================
   DETALLE DE PRODUCTOS (opcional)
========================= */
if (isset($_POST['productos']) && is_array($_POST['productos'])) {
    foreach ($_POST['productos'] as $prod) {
        $idProducto     = (int)($prod['id_producto'] ?? 0);
        $imei1          = trim($prod['imei1'] ?? '');
        $precioUnitario = (float)($prod['precio_unitario'] ?? 0);

        if ($idProducto > 0) {
            $sqlDet = "INSERT INTO detalle_venta (id_venta, id_producto, imei1, precio_unitario)
                       VALUES (?,?,?,?)";
            $stmtDet = $conn->prepare($sqlDet);
            if ($stmtDet) {
                $stmtDet->bind_param("iisd", $idVenta, $idProducto, $imei1, $precioUnitario);
                $stmtDet->execute();
                $stmtDet->close();
            }

            // Marcar como vendido en inventario (solo si está disponible)
            $upd = $conn->prepare("UPDATE inventario
                                   SET estatus='Vendido'
                                   WHERE id_producto=? AND estatus='Disponible'
                                   LIMIT 1");
            if ($upd) {
                $upd->bind_param("i", $idProducto);
                $upd->execute();
                $upd->close();
            }
        }
    }
}

/* =========================
   FIN
========================= */
header("Location: historial_ventas_ma.php?msg=ok");
exit();
