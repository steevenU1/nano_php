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

// Inventarios seleccionados (cuando origen = nano)
$equipo1InvId      = (int)($_POST['equipo1'] ?? 0);                 // inventario.id
$equipo2InvId      = (int)($_POST['equipo2'] ?? 0);                 // inventario.id (combo opcional)

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
    if ($equipo1InvId <= 0) {
        die("Error: selecciona el equipo principal del inventario.");
    }
    if ($tipoVenta === 'Financiamiento+Combo' && $equipo2InvId <= 0) {
        die("Error: selecciona el equipo combo del inventario.");
    }
    if ($equipo1InvId > 0 && $equipo2InvId > 0 && $equipo1InvId === $equipo2InvId) {
        die("Error: equipo principal y combo no pueden ser el mismo inventario.");
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

// Sumar comisiones si vienen como arreglo productos[] (legacy)
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

// Fallback: si no vino el arreglo productos, intentar con selects equipo1/equipo2 (inventario.id)
if ($comisionSumaProductos == 0.0) {
    if ($equipo1InvId > 0) $comisionSumaProductos += obtenerComisionDesdeCualquierId($conn, $equipo1InvId);
    if ($equipo2InvId > 0) $comisionSumaProductos += obtenerComisionDesdeCualquierId($conn, $equipo2InvId);
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

$stmt->bind_param(
    "ssssddsddisiissssd",
    $tag, $nombreCliente, $telefonoCliente,
    $tipoVenta, $precioVenta, $enganche, $formaPagoEng,
    $engancheEfectivo, $engancheTarjeta, $plazoSemanas, $financiera,
    $idSucursal, $idUsuario, $origen_ma, $origen_subtipo,
    $comentarios, $fechaVenta, $comisionMA
);

$stmt->execute();
$idVenta = $stmt->insert_id;
$stmt->close();

/* =========================
   DETALLE + MOVIMIENTO INVENTARIO
   - Prioriza equipo1/equipo2 (inventario.id exacto) cuando origen = nano.
   - Si no vienen, usa el bloque legacy de productos[].
========================= */

try {
    $conn->begin_transaction();

    if ($origen_ma === 'nano' && ($equipo1InvId > 0 || $equipo2InvId > 0)) {

        // Helper para tomar un inventario específico y venderlo
        $sqlSel = "
            SELECT i.id AS id_inventario, i.id_sucursal, i.estatus, i.id_producto,
                   p.imei1, p.imei2, p.precio_lista
            FROM inventario i
            JOIN productos p ON p.id = i.id_producto
            WHERE i.id = ?
            FOR UPDATE
        ";
        $sqlUpd = "
            UPDATE inventario
               SET estatus='Vendido'
             WHERE id=? AND id_sucursal=? AND (estatus IN ('Disponible','DISPONIBLE','Stock','EN STOCK'))
             LIMIT 1
        ";
        $stmtSel = $conn->prepare($sqlSel);
        $stmtUpd = $conn->prepare($sqlUpd);
        $stmtDet = $conn->prepare("INSERT INTO detalle_venta (id_venta, id_producto, imei1, precio_unitario) VALUES (?,?,?,?)");

        $procesar = function(int $invId) use ($conn, $idSucursal, $stmtSel, $stmtUpd, $stmtDet, $idVenta) {
            if ($invId <= 0) return;

            $stmtSel->bind_param("i", $invId);
            $stmtSel->execute();
            $res = $stmtSel->get_result();
            if (!$row = $res->fetch_assoc()) {
                throw new Exception("Inventario {$invId} no encontrado.");
            }

            // Validaciones de sucursal y disponibilidad
            if ((int)$row['id_sucursal'] !== $idSucursal) {
                throw new Exception("El inventario {$invId} no pertenece a la sucursal seleccionada.");
            }
            $estatusOk = in_array(strtoupper((string)$row['estatus']), ['DISPONIBLE','STOCK','EN STOCK'], true);
            if (!$estatusOk) {
                throw new Exception("El inventario {$invId} no está disponible.");
            }

            // Marcar como vendido este ID EXACTO
            $stmtUpd->bind_param("ii", $invId, $idSucursal);
            $stmtUpd->execute();
            if ($stmtUpd->affected_rows !== 1) {
                throw new Exception("No se pudo actualizar el inventario {$invId} a 'Vendido'.");
            }

            // Snapshot al detalle (guardamos precio_lista como precio_unitario)
            $idProducto     = (int)$row['id_producto'];
            $imei1          = (string)$row['imei1'];
            $precioUnitario = (float)($row['precio_lista'] ?? 0);

            $stmtDet->bind_param("iisd", $idVenta, $idProducto, $imei1, $precioUnitario);
            $stmtDet->execute();
        };

        // equipo1 es obligatorio en nano
        $procesar($equipo1InvId);

        // equipo2 solo si aplica combo
        if ($tipoVenta === 'Financiamiento+Combo' && $equipo2InvId > 0) {
            $procesar($equipo2InvId);
        }

        $stmtSel->close();
        $stmtUpd->close();
        $stmtDet->close();

    } elseif (isset($_POST['productos']) && is_array($_POST['productos'])) {
        // ====== BLOQUE LEGACY (cuando no usamos equipo1/equipo2) ======
        foreach ($_POST['productos'] as $prod) {
            $idProducto     = (int)($prod['id_producto'] ?? 0);
            $imei1          = trim($prod['imei1'] ?? '');
            $precioUnitario = (float)($prod['precio_unitario'] ?? 0);

            if ($idProducto > 0) {
                // detalle
                $sqlDet = "INSERT INTO detalle_venta (id_venta, id_producto, imei1, precio_unitario)
                           VALUES (?,?,?,?)";
                $stmtDet = $conn->prepare($sqlDet);
                $stmtDet->bind_param("iisd", $idVenta, $idProducto, $imei1, $precioUnitario);
                $stmtDet->execute();
                $stmtDet->close();

                // ❗ OJO: esto toma "cualquier" inventario disponible de ese producto
                // Se mantiene por compatibilidad, pero no garantiza el IMEI exacto.
                $upd = $conn->prepare("UPDATE inventario
                                       SET estatus='Vendido'
                                       WHERE id_producto=? AND id_sucursal=? AND estatus='Disponible'
                                       LIMIT 1");
                $upd->bind_param("ii", $idProducto, $idSucursal);
                $upd->execute();
                $upd->close();
            }
        }
    }

    $conn->commit();

} catch (Throwable $e) {
    $conn->rollback();
    // Mensaje claro para depurar; en producción podrías redirigir con msg de error.
    die("Error al registrar detalle / inventario: " . $e->getMessage());
}

/* =========================
   FIN
========================= */
header("Location: historial_ventas_ma.php?msg=ok");
exit();
