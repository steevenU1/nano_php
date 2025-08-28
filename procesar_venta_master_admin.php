<?php
// procesar_venta_master_admin.php (Nano) — MA/Socio con snapshot de origen_subtipo
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$idUsuario = (int)($_SESSION['id_usuario'] ?? 0);

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

// Snapshot del subtipo de la sucursal (viene desde el form)
$origen_subtipo    = trim($_POST['origen_subtipo'] ?? '');          // 'Master Admin' | 'Socio' (u otros)

/* =========================
   FECHA DE VENTA
========================= */
$fechaVentaForm    = trim($_POST['fecha_venta'] ?? '');
if ($fechaVentaForm !== '') {
    // llega como YYYY-MM-DDTHH:MM
    $fechaVentaForm = str_replace('T', ' ', $fechaVentaForm);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $fechaVentaForm)) {
        $fechaVentaForm .= ':00';
    }
    $fechaVenta = $fechaVentaForm;
} else {
    $fechaVenta = date('Y-m-d H:i:s');
}

/* =========================
   NORMALIZACIONES/VALIDACIONES
========================= */
$tipoVentaAllow = ['Contado','Financiamiento','Financiamiento+Combo'];
if (!in_array($tipoVenta, $tipoVentaAllow, true)) $tipoVenta = 'Contado';

$formaPagoAllow = ['Efectivo','Tarjeta','Mixto'];
if (!in_array($formaPagoEng, $formaPagoAllow, true)) $formaPagoEng = 'Efectivo';

$financieraAllow = ['N/A','PayJoy','Krediya'];
if (!in_array($financiera, $financieraAllow, true)) $financiera = 'N/A';

$origen_ma = ($origen_ma === 'propio') ? 'propio' : 'nano';

// TAG solo obligatorio en financiamiento
$requiereTAG = ($tipoVenta !== 'Contado');

if ($precioVenta <= 0 || $idSucursal <= 0) {
    die("Error: faltan datos obligatorios (precio o sucursal).");
}
if ($requiereTAG && $tag === '') {
    die("Error: TAG es obligatorio en ventas de financiamiento.");
}
if ($origen_ma === 'nano') {
    if ($nombreCliente === '' || $telefonoCliente === '') {
        die("Error: para origen Nano, nombre y teléfono del cliente son obligatorios.");
    }
}

/* =========================
   ENGANCHE TOTAL
========================= */
$engancheTotal = ($formaPagoEng === 'Mixto')
    ? ($engancheEfectivo + $engancheTarjeta)
    : $enganche;

if ($engancheTotal < 0) $engancheTotal = 0;
if ($engancheTotal > $precioVenta) $engancheTotal = $precioVenta;

/* =========================
   CÁLCULO COMISIÓN MASTER ADMIN
========================= */

// Helper: obtener comision_ma a partir de un ID que puede ser de productos.id o inventario.id
function obtenerComisionDesdeCualquierId(mysqli $conn, int $id): float {
    if ($id <= 0) return 0.0;

    // 1) intentar como productos.id
    $stmt = $conn->prepare("SELECT comision_ma FROM productos WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->bind_result($cm);
    if ($stmt->fetch()) {
        $stmt->close();
        return (float)$cm;
    }
    $stmt->close();

    // 2) intentar como inventario.id -> productos
    $stmt = $conn->prepare("
        SELECT p.comision_ma
        FROM inventario i
        INNER JOIN productos p ON p.id = i.id_producto
        WHERE i.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->bind_result($cm2);
    $val = 0.0;
    if ($stmt->fetch()) $val = (float)$cm2;
    $stmt->close();

    return $val;
}

// Sumar comisiones si vienen como arreglo productos[]
$comisionSumaProductos = 0.0;
if (isset($_POST['productos']) && is_array($_POST['productos'])) {
    foreach ($_POST['productos'] as $prod) {
        $idProd = (int)($prod['id_producto'] ?? 0);
        if ($idProd > 0) {
            $stmt = $conn->prepare("SELECT comision_ma FROM productos WHERE id=? LIMIT 1");
            $stmt->bind_param("i", $idProd);
            $stmt->execute();
            $stmt->bind_result($cm);
            if ($stmt->fetch()) $comisionSumaProductos += (float)$cm;
            $stmt->close();
        }
    }
}

// Fallback: si no vino el arreglo productos, intentar con selects equipo1/equipo2
if ($comisionSumaProductos == 0.0) {
    $equipo1 = (int)($_POST['equipo1'] ?? 0);
    $equipo2 = (int)($_POST['equipo2'] ?? 0);
    if ($equipo1 > 0) $comisionSumaProductos += obtenerComisionDesdeCualquierId($conn, $equipo1);
    if ($equipo2 > 0) $comisionSumaProductos += obtenerComisionDesdeCualquierId($conn, $equipo2);
}

// Cálculo por defecto (MA):
if ($origen_ma === 'nano') {
    // Regla: suma comision_ma de equipos - engancheTotal
    $comisionMA = $comisionSumaProductos - $engancheTotal;
} else {
    // Origen propio: precio - enganche - 10%
    $comisionMA = $precioVenta - $engancheTotal - ($precioVenta * 0.10);
}

// Si es SOCIO -> comisión en 0 SIEMPRE
if (strcasecmp($origen_subtipo, 'Socio') === 0) {
    $comisionMA = 0.0;
}

$comisionMA = round((float)$comisionMA, 2);

/* =========================
   INSERT A VENTAS
   (incluye origen_ma, origen_subtipo y comision_master_admin)
========================= */
$sql = "INSERT INTO ventas (
    tag, nombre_cliente, telefono_cliente,
    tipo_venta, precio_venta, enganche, forma_pago_enganche,
    enganche_efectivo, enganche_tarjeta, plazo_semanas, financiera,
    id_sucursal, id_usuario, origen_ma, origen_subtipo,
    comentarios, fecha_venta, comision_master_admin
) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Error en prepare(): " . $conn->error);
}

/* Tipos por orden (18):
   1 s  tag
   2 s  nombre_cliente
   3 s  telefono_cliente
   4 s  tipo_venta
   5 d  precio_venta
   6 d  enganche
   7 s  forma_pago_enganche
   8 d  enganche_efectivo
   9 d  enganche_tarjeta
   10 i plazo_semanas
   11 s financiera
   12 i id_sucursal
   13 i id_usuario
   14 s origen_ma
   15 s origen_subtipo
   16 s comentarios
   17 s fecha_venta
   18 d comision_master_admin
*/
$stmt->bind_param(
    "ssssddsddis iissss d", // ← visual
    $tag, $nombreCliente, $telefonoCliente,
    $tipoVenta, $precioVenta, $enganche, $formaPagoEng,
    $engancheEfectivo, $engancheTarjeta, $plazoSemanas, $financiera,
    $idSucursal, $idUsuario, $origen_ma, $origen_subtipo,
    $comentarios, $fechaVenta, $comisionMA
);
// La cadena de tipos sin espacios:
$stmt->bind_param(
    "ssssddsddisiisssssd",
    $tag, $nombreCliente, $telefonoCliente,
    $tipoVenta, $precioVenta, $enganche, $formaPagoEng,
    $engancheEfectivo, $engancheTarjeta, $plazoSemanas, $financiera,
    $idSucursal, $idUsuario, $origen_ma, $origen_subtipo,
    $comentarios, $fechaVenta, $comisionMA
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

            // Marcar como vendido en inventario (si está disponible)
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
