<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}
require_once __DIR__ . '/db.php';

/* ========================
   Helpers
======================== */
// Inicio de semana (martes-lunes)
function obtenerInicioSemana() {
  $hoy = new DateTime();
  $diaSemana = (int)$hoy->format('N'); // 1=lun ... 7=dom
  $offset = $diaSemana - 2;            // martes = 2
  if ($offset < 0) $offset += 7;
  $inicio = new DateTime();
  $inicio->modify("-$offset days")->setTime(0,0,0);
  return $inicio;
}

// Comisión regular equipos
function calcularComisionEquipo($precio, $esCombo, $cubreCuota, $esMiFi = false) {
  if ($esCombo) return 75;
  if ($esMiFi) return $cubreCuota ? 100 : 75;

  if ($precio >= 1 && $precio <= 3500)  return $cubreCuota ? 100 : 75;
  if ($precio >= 3501 && $precio <= 5500) return $cubreCuota ? 200 : 100;
  if ($precio >= 5501)                   return $cubreCuota ? 250 : 150;
  return 0;
}

// Comisión especial
function obtenerComisionEspecial($id_producto, mysqli $conn) {
  $hoy = date('Y-m-d');

  $stmt = $conn->prepare("SELECT marca, modelo, capacidad FROM productos WHERE id=?");
  $stmt->bind_param("i", $id_producto);
  $stmt->execute();
  $prod = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$prod) return 0.0;

  $stmt2 = $conn->prepare("
    SELECT monto
    FROM comisiones_especiales
    WHERE marca=? AND modelo=? AND (capacidad=? OR capacidad='' OR capacidad IS NULL)
      AND fecha_inicio <= ? AND (fecha_fin IS NULL OR fecha_fin >= ?)
      AND activo=1
    ORDER BY fecha_inicio DESC
    LIMIT 1
  ");
  $stmt2->bind_param("sssss", $prod['marca'], $prod['modelo'], $prod['capacidad'], $hoy, $hoy);
  $stmt2->execute();
  $res = $stmt2->get_result()->fetch_assoc();
  $stmt2->close();

  return (float)($res['monto'] ?? 0);
}

// Verifica inventario disponible y (opcional) en la sucursal
function validarInventario(mysqli $conn, int $id_inv, int $id_sucursal): bool {
  $stmt = $conn->prepare("SELECT COUNT(*) FROM inventario WHERE id=? AND estatus='Disponible' AND id_sucursal=?");
  $stmt->bind_param("ii", $id_inv, $id_sucursal);
  $stmt->execute();
  $stmt->bind_result($ok);
  $stmt->fetch();
  $stmt->close();
  return (int)$ok > 0;
}

// Registra equipo vendido
function venderEquipo(mysqli $conn, int $id_venta, int $id_inventario, bool $esCombo, bool $cubreCuota): float {
  $stmtProd = $conn->prepare("
    SELECT i.id_producto, p.imei1, p.precio_lista, LOWER(p.tipo_producto) AS tipo
    FROM inventario i
    INNER JOIN productos p ON i.id_producto = p.id
    WHERE i.id=? AND i.estatus='Disponible'
    LIMIT 1
  ");
  $stmtProd->bind_param("i", $id_inventario);
  $stmtProd->execute();
  $row = $stmtProd->get_result()->fetch_assoc();
  $stmtProd->close();
  if (!$row) { throw new RuntimeException("Equipo $id_inventario no disponible."); }

  $esMiFi = ($row['tipo'] === 'modem' || $row['tipo'] === 'mifi');
  $comReg = calcularComisionEquipo((float)$row['precio_lista'], $esCombo, $cubreCuota, $esMiFi);
  $comEsp = obtenerComisionEspecial((int)$row['id_producto'], $conn);
  $comFin = (float)$comReg + (float)$comEsp;

  // detalle_venta
  $stmtD = $conn->prepare("
    INSERT INTO detalle_venta (id_venta, id_producto, imei1, precio_unitario, comision, comision_regular, comision_especial)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  ");
  $stmtD->bind_param(
    "iisdddd",
    $id_venta,
    $row['id_producto'],
    $row['imei1'],
    $row['precio_lista'],
    $comFin,
    $comReg,
    $comEsp
  );
  $stmtD->execute();
  $stmtD->close();

  // inventario -> Vendido
  $stmtU = $conn->prepare("UPDATE inventario SET estatus='Vendido' WHERE id=?");
  $stmtU->bind_param("i", $id_inventario);
  $stmtU->execute();
  $stmtU->close();

  return $comFin;
}

/* ========================
   1) Recibir + Validar
======================== */
$id_usuario   = (int)($_SESSION['id_usuario']);
$id_sucursal  = isset($_POST['id_sucursal']) ? (int)$_POST['id_sucursal'] : (int)$_SESSION['id_sucursal'];

$tag                 = trim($_POST['tag'] ?? '');
$nombre_cliente      = trim($_POST['nombre_cliente'] ?? '');
$telefono_cliente    = trim($_POST['telefono_cliente'] ?? '');
$tipo_venta          = $_POST['tipo_venta'] ?? '';
$equipo1             = (int)($_POST['equipo1'] ?? 0);
$equipo2             = isset($_POST['equipo2']) ? (int)$_POST['equipo2'] : 0;
$precio_venta        = (float)($_POST['precio_venta'] ?? 0);
$enganche            = (float)($_POST['enganche'] ?? 0);
$forma_pago_enganche = $_POST['forma_pago_enganche'] ?? '';
$enganche_efectivo   = (float)($_POST['enganche_efectivo'] ?? 0);
$enganche_tarjeta    = (float)($_POST['enganche_tarjeta'] ?? 0);
$plazo_semanas       = (int)($_POST['plazo_semanas'] ?? 0);
$financiera          = $_POST['financiera'] ?? '';
$comentarios         = trim($_POST['comentarios'] ?? '');

$esFin = in_array($tipo_venta, ['Financiamiento','Financiamiento+Combo'], true);
$errores = [];

// Reglas siempre
if (!$tipo_venta)                                 $errores[] = "Selecciona el tipo de venta.";
if ($precio_venta <= 0)                           $errores[] = "El precio de venta debe ser mayor a 0.";
if (!$forma_pago_enganche)                        $errores[] = "Selecciona la forma de pago.";
if ($equipo1 <= 0)                                $errores[] = "Selecciona el equipo principal.";

// Reglas para Financiamiento / Combo
if ($esFin) {
  if ($nombre_cliente === '')                     $errores[] = "Nombre del cliente es obligatorio.";
  if ($telefono_cliente === '' || !preg_match('/^\d{10}$/', $telefono_cliente)) $errores[] = "Teléfono del cliente debe tener 10 dígitos.";
  if ($tag === '')                                $errores[] = "TAG (ID del crédito) es obligatorio.";
  if ($enganche < 0)                              $errores[] = "El enganche no puede ser negativo (puede ser 0).";
  if ($plazo_semanas <= 0)                        $errores[] = "El plazo en semanas debe ser mayor a 0.";
  if ($financiera === '')                         $errores[] = "Selecciona una financiera (no puede ser N/A).";

  if ($forma_pago_enganche === 'Mixto') {
    if ($enganche_efectivo <= 0 && $enganche_tarjeta <= 0) $errores[] = "En pago Mixto, al menos uno de los montos debe ser > 0.";
    if (round($enganche_efectivo + $enganche_tarjeta, 2) !== round($enganche, 2)) $errores[] = "Efectivo + Tarjeta debe ser igual al Enganche.";
  }
} else {
  // Contado: normaliza campos
  $tag = '';
  $plazo_semanas = 0;
  $financiera = 'N/A';
  $enganche_efectivo = 0;
  $enganche_tarjeta  = 0;
}

// Validar inventarios disponibles en la sucursal seleccionada
if ($equipo1 && !validarInventario($conn, $equipo1, $id_sucursal)) {
  $errores[] = "El equipo principal no está disponible en la sucursal seleccionada.";
}
if ($tipo_venta === 'Financiamiento+Combo' && $equipo2) {
  if (!validarInventario($conn, $equipo2, $id_sucursal)) {
    $errores[] = "El equipo combo no está disponible en la sucursal seleccionada.";
  }
}

if ($errores) {
  header("Location: nueva_venta.php?err=" . urlencode(implode(' ', $errores)));
  exit();
}

/* ========================
   2) Insertar Venta (TX)
======================== */
try {
  $conn->begin_transaction();

  $sqlVenta = "INSERT INTO ventas
    (tag, nombre_cliente, telefono_cliente, tipo_venta, precio_venta, id_usuario, id_sucursal, comision,
     enganche, forma_pago_enganche, enganche_efectivo, enganche_tarjeta, plazo_semanas, financiera, comentarios)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

  $stmtVenta = $conn->prepare($sqlVenta);
  $comisionInicial = 0.0;

  // tipos: s s s s d i i d d s d d i s s
  $stmtVenta->bind_param(
    "ssssdiiddsddiss",
    $tag,
    $nombre_cliente,
    $telefono_cliente,
    $tipo_venta,
    $precio_venta,
    $id_usuario,
    $id_sucursal,
    $comisionInicial,
    $enganche,
    $forma_pago_enganche,
    $enganche_efectivo,
    $enganche_tarjeta,
    $plazo_semanas,
    $financiera,
    $comentarios
  );
  $stmtVenta->execute();
  $id_venta = (int)$stmtVenta->insert_id;
  $stmtVenta->close();

  /* ========================
     3) Cubre cuota (semana)
  ======================= */
  $inicioSemana = obtenerInicioSemana()->format('Y-m-d H:i:s');
  $finSemana    = date('Y-m-d 23:59:59');

  $stmtUni = $conn->prepare("
    SELECT COUNT(*) AS unidades
    FROM detalle_venta dv
    INNER JOIN ventas v ON dv.id_venta = v.id
    INNER JOIN productos p ON dv.id_producto = p.id
    WHERE v.id_usuario=? AND v.fecha_venta BETWEEN ? AND ?
      AND LOWER(p.tipo_producto) NOT IN ('mifi','modem')
  ");
  $stmtUni->bind_param("iss", $id_usuario, $inicioSemana, $finSemana);
  $stmtUni->execute();
  $resUni = $stmtUni->get_result()->fetch_assoc();
  $stmtUni->close();
  $unidadesSemana = (int)($resUni['unidades'] ?? 0);
  $cubreCuota = ($unidadesSemana >= 6);

  /* ========================
     4) Registrar equipos
  ======================= */
  $totalComision = 0.0;
  $totalComision += venderEquipo($conn, $id_venta, $equipo1, false, $cubreCuota);

  if ($tipo_venta === "Financiamiento+Combo" && $equipo2) {
    $totalComision += venderEquipo($conn, $id_venta, $equipo2, true, $cubreCuota);
  }

  /* ========================
     5) Actualizar venta
  ======================= */
  $stmtUpd = $conn->prepare("UPDATE ventas SET comision=? WHERE id=?");
  $stmtUpd->bind_param("di", $totalComision, $id_venta);
  $stmtUpd->execute();
  $stmtUpd->close();

  $conn->commit();

  header("Location: historial_ventas.php?msg=" . urlencode("Venta #$id_venta registrada. Comisión $" . number_format($totalComision,2)));
  exit();
} catch (Throwable $e) {
  $conn->rollback();
  header("Location: nueva_venta.php?err=" . urlencode("Error al registrar la venta: " . $e->getMessage()));
  exit();
}
