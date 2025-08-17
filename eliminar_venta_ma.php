<?php
// eliminar_venta_ma.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
    header("Location: 403.php"); exit();
}

include 'db.php';

/* ===== Helpers introspectivos (no truenan si faltan columnas) ===== */
function columnExists(mysqli $conn, string $table, string $column): bool {
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='$t' AND COLUMN_NAME='$c' LIMIT 1";
    $res = $conn->query($sql);
    return $res && $res->num_rows > 0;
}

$idVenta = (int)($_GET['id'] ?? 0);
if ($idVenta <= 0) { header("Location: historial_ventas_ma.php?msg=venta_invalida"); exit(); }

/* ===== Descubrir columnas opcionales ===== */
$HAS_ORIGEN_MA   = columnExists($conn, 'ventas', 'origen_ma');
$HAS_FECHA_ACT   = columnExists($conn, 'inventario', 'fecha_actualizacion');
$HAS_DV_IMEI     = columnExists($conn, 'detalle_venta', 'imei1');   // muchos esquemas lo traen
$HAS_DV_INV_ID   = columnExists($conn, 'detalle_venta', 'id_inventario'); // por si ya guardas el id de inventario

try {
    $conn->begin_transaction();

    /* ===== 1) Traer venta ===== */
    if ($HAS_ORIGEN_MA) {
        $stmtV = $conn->prepare("SELECT id, id_sucursal, comentarios, origen_ma FROM ventas WHERE id=? LIMIT 1");
    } else {
        $stmtV = $conn->prepare("SELECT id, id_sucursal, comentarios FROM ventas WHERE id=? LIMIT 1");
    }
    $stmtV->bind_param("i", $idVenta);
    $stmtV->execute();
    $venta = $stmtV->get_result()->fetch_assoc();
    $stmtV->close();

    if (!$venta) {
        $conn->rollback();
        header("Location: historial_ventas_ma.php?msg=venta_no_encontrada"); exit();
    }

    $idSucursal = (int)$venta['id_sucursal'];
    $origenEsNano = false;
    if ($HAS_ORIGEN_MA) {
        $origenEsNano = (strtolower((string)$venta['origen_ma']) === 'nano');
    } else {
        $origenEsNano = (stripos((string)$venta['comentarios'], 'Inventario Nano') !== false);
    }

    /* ===== 2) Obtener detalles ===== */
    if ($HAS_DV_IMEI && $HAS_DV_INV_ID) {
        $stmtD = $conn->prepare("SELECT id, id_producto, imei1, id_inventario FROM detalle_venta WHERE id_venta=?");
    } elseif ($HAS_DV_IMEI) {
        $stmtD = $conn->prepare("SELECT id, id_producto, imei1 FROM detalle_venta WHERE id_venta=?");
    } elseif ($HAS_DV_INV_ID) {
        $stmtD = $conn->prepare("SELECT id, id_producto, id_inventario FROM detalle_venta WHERE id_venta=?");
    } else {
        $stmtD = $conn->prepare("SELECT id, id_producto FROM detalle_venta WHERE id_venta=?");
    }
    $stmtD->bind_param("i", $idVenta);
    $stmtD->execute();
    $resD = $stmtD->get_result();
    $detalles = $resD->fetch_all(MYSQLI_ASSOC);
    $stmtD->close();

    /* ===== 3) Revertir inventario si el origen fue Nano ===== */
    if ($origenEsNano && $detalles) {
        // Statements de búsqueda
        $stmtFindByInv = $HAS_DV_INV_ID
            ? $conn->prepare("SELECT id FROM inventario WHERE id=? AND id_sucursal=? LIMIT 1")
            : null;

        $stmtFindByIMEI = $HAS_DV_IMEI
            ? $conn->prepare("
                SELECT i.id
                FROM inventario i
                INNER JOIN productos p ON p.id=i.id_producto
                WHERE (TRIM(p.imei1)=? OR TRIM(p.imei2)=?) AND i.id_sucursal=? LIMIT 1
              ")
            : null;

        $stmtFindByProd = $conn->prepare("
            SELECT id FROM inventario
            WHERE id_producto=? AND id_sucursal=? LIMIT 1
        ");

        // UPDATE según exista fecha_actualizacion
        if ($HAS_FECHA_ACT) {
            $stmtUpd = $conn->prepare("
                UPDATE inventario
                SET estatus='Disponible', fecha_actualizacion=NOW()
                WHERE id=? AND estatus IN ('Vendido','Apartado')
            ");
        } else {
            $stmtUpd = $conn->prepare("
                UPDATE inventario
                SET estatus='Disponible'
                WHERE id=? AND estatus IN ('Vendido','Apartado')
            ");
        }

        foreach ($detalles as $d) {
            $idInv = 0;

            // 3.a Preferir id_inventario directo si viene
            if ($HAS_DV_INV_ID && isset($d['id_inventario'])) {
                $idInvDet = (int)$d['id_inventario'];
                if ($idInvDet > 0 && $stmtFindByInv) {
                    $stmtFindByInv->bind_param("ii", $idInvDet, $idSucursal);
                    $stmtFindByInv->execute();
                    $row = $stmtFindByInv->get_result()->fetch_assoc();
                    if ($row) $idInv = (int)$row['id'];
                }
            }

            // 3.b Si no, probar por IMEI
            if ($idInv === 0 && $HAS_DV_IMEI && !empty($d['imei1'])) {
                $imei = preg_replace('/\D+/', '', (string)$d['imei1']);
                if ($imei !== '' && $stmtFindByIMEI) {
                    $stmtFindByIMEI->bind_param("ssi", $imei, $imei, $idSucursal);
                    $stmtFindByIMEI->execute();
                    $row = $stmtFindByIMEI->get_result()->fetch_assoc();
                    if ($row) $idInv = (int)$row['id'];
                }
            }

            // 3.c Último recurso: por id_producto en la misma sucursal
            if ($idInv === 0 && isset($d['id_producto'])) {
                $idProd = (int)$d['id_producto'];
                if ($idProd > 0) {
                    $stmtFindByProd->bind_param("ii", $idProd, $idSucursal);
                    $stmtFindByProd->execute();
                    $row = $stmtFindByProd->get_result()->fetch_assoc();
                    if ($row) $idInv = (int)$row['id'];
                }
            }

            if ($idInv > 0) {
                $stmtUpd->bind_param("i", $idInv);
                $stmtUpd->execute();
                // Si prefieres revertir sin importar estatus, quita "AND estatus IN (...)" en el UPDATE
            }
            // Si no se encuentra, lo ignoramos para no bloquear la eliminación; puedes loguearlo si quieres.
        }

        if ($stmtFindByInv)  $stmtFindByInv->close();
        if ($stmtFindByIMEI) $stmtFindByIMEI->close();
        $stmtFindByProd->close();
        $stmtUpd->close();
    }

    /* ===== 4) Borrar detalle y la venta ===== */
    $stmtDelDet = $conn->prepare("DELETE FROM detalle_venta WHERE id_venta=?");
    $stmtDelDet->bind_param("i", $idVenta);
    $stmtDelDet->execute();
    $stmtDelDet->close();

    $stmtDelV = $conn->prepare("DELETE FROM ventas WHERE id=? LIMIT 1");
    $stmtDelV->bind_param("i", $idVenta);
    $stmtDelV->execute();
    $stmtDelV->close();

    $conn->commit();
    header("Location: historial_ventas_ma.php?msg=venta_eliminada");
    exit();

} catch (Throwable $e) {
    if ($conn->errno) { $conn->rollback(); }
    // Para depurar durante desarrollo:
    // die('Error: '.$e->getMessage());
    header("Location: historial_ventas_ma.php?msg=error_tx");
    exit();
}
