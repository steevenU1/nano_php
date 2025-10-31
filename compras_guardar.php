<?php
// compras_guardar.php (Nano) — Guarda encabezado + renglones por MODELO + cargos + pago contado opcional
// Candado opcional (no truena si no existe)
$candado = __DIR__ . '/candado_captura.php';
if (is_file($candado)) {
  require_once $candado;
  if (function_exists('abortar_si_captura_bloqueada')) {
    abortar_si_captura_bloqueada(); // bloquea POST si corresponde
  }
}

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }
require_once __DIR__.'/db.php';

$ID_USUARIO = (int)($_SESSION['id_usuario'] ?? 0);

// ---------- Encabezado (POST) ----------
$id_proveedor      = (int)($_POST['id_proveedor'] ?? 0);
$num_factura       = trim($_POST['num_factura'] ?? '');
$id_sucursal       = (int)($_POST['id_sucursal'] ?? 0);
$fecha_factura     = $_POST['fecha_factura'] ?? date('Y-m-d');
$fecha_venc        = $_POST['fecha_vencimiento'] ?? null;
$condicion_pago    = $_POST['condicion_pago'] ?? 'Contado'; // Contado | Crédito
$dias_vencimiento  = isset($_POST['dias_vencimiento']) && $_POST['dias_vencimiento'] !== ''
                     ? (int)$_POST['dias_vencimiento'] : null;
$notas             = substr(trim($_POST['notas'] ?? ''), 0, 2000);

// Validaciones mínimas
if ($id_proveedor<=0 || $num_factura==='' || $id_sucursal<=0) {
  http_response_code(400);
  die("Parámetros inválidos.");
}

// Utilidades fechas
function is_valid_date($s){
  if (!$s) return false;
  $d = DateTime::createFromFormat('Y-m-d', $s);
  return $d && $d->format('Y-m-d') === $s;
}
function add_days($base, $days){
  $d = DateTime::createFromFormat('Y-m-d', $base);
  if (!$d) return null;
  $d->modify('+'.(int)$days.' days');
  return $d->format('Y-m-d');
}
function diff_days($from, $to){
  $df = DateTime::createFromFormat('Y-m-d', $from);
  $dt = DateTime::createFromFormat('Y-m-d', $to);
  if (!$df || !$dt) return null;
  return (int)$df->diff($dt)->format('%r%a');
}

// Lógica de vencimiento
if ($condicion_pago === 'Contado') {
  if (!is_valid_date($fecha_factura)) $fecha_factura = date('Y-m-d');
  $fecha_venc = $fecha_factura;
  $dias_vencimiento = 0;
} else {
  if (!is_valid_date($fecha_factura)) $fecha_factura = date('Y-m-d');
  if ($dias_vencimiento !== null) {
    if ($dias_vencimiento < 0) $dias_vencimiento = 0;
    $fecha_venc = add_days($fecha_factura, $dias_vencimiento);
  } elseif ($fecha_venc && is_valid_date($fecha_venc)) {
    $d = diff_days($fecha_factura, $fecha_venc);
    if ($d !== null && $d >= 0) {
      $dias_vencimiento = $d;
    } else {
      $dias_vencimiento = 0;
      $fecha_venc = $fecha_factura;
    }
  } else {
    $dias_vencimiento = 0;
    $fecha_venc = $fecha_factura;
  }
}

// ---------- Detalle ----------
$id_modelo   = $_POST['id_modelo'] ?? [];
$color       = $_POST['color'] ?? [];
$ram         = $_POST['ram'] ?? [];
$capacidad   = $_POST['capacidad'] ?? [];
$cantidad    = $_POST['cantidad'] ?? [];
$precio      = $_POST['precio_unitario'] ?? [];   // sin IVA
$iva_pct     = $_POST['iva_porcentaje'] ?? [];
$requiereMap = $_POST['requiere_imei'] ?? [];

// Descuento por renglón (opcionales)
$costo_dto     = $_POST['costo_dto']     ?? [];   // float | ''
$costo_dto_iva = $_POST['costo_dto_iva'] ?? [];   // float | ''

if (empty($id_modelo)) { http_response_code(400); die("Debes incluir al menos un renglón."); }

// ---------- Otros cargos (opcional) ----------
$extra_desc            = $_POST['extra_desc']            ?? [];
$extra_monto           = $_POST['extra_monto']           ?? [];
$extra_iva_porcentaje  = $_POST['extra_iva_porcentaje']  ?? [];

$subtotal = 0.0; $iva = 0.0; $total = 0.0;
$rows = [];

// Construcción de renglones y cálculo de totales
foreach ($id_modelo as $idx => $idmRaw) {
  $idm = (int)$idmRaw;
  if ($idm<=0) continue;

  // Snapshot del catálogo
  $st = $conn->prepare("SELECT marca, modelo, codigo_producto FROM catalogo_modelos WHERE id=? AND activo=1");
  $st->bind_param("i", $idm);
  $st->execute();
  $st->bind_result($marca, $modelo, $codigoCat);
  $ok = $st->fetch(); $st->close();
  if (!$ok) continue;

  $col = substr(trim($color[$idx] ?? ''), 0, 40);
  $ramv= substr(trim($ram[$idx] ?? ''),    0, 50);
  $cap = substr(trim($capacidad[$idx] ?? ''), 0, 40);
  $qty = max(0, (int)($cantidad[$idx] ?? 0));
  $pu  = max(0, (float)($precio[$idx] ?? 0));     // sin IVA
  $ivp = max(0, (float)($iva_pct[$idx] ?? 0));    // %
  $req = (int)($requiereMap[$idx] ?? 1) === 1 ? 1 : 0;

  if ($marca==='' || $modelo==='' || $col==='' || $cap==='' || $qty<=0 || $pu<=0) continue;

  // Normalización de DTOs (por unidad)
  $dto    = isset($costo_dto[$idx])     && $costo_dto[$idx]     !== '' ? (float)$costo_dto[$idx]     : null;
  $dtoIva = isset($costo_dto_iva[$idx]) && $costo_dto_iva[$idx] !== '' ? (float)$costo_dto_iva[$idx] : null;

  if ($dto !== null && ($dtoIva === null || $dtoIva <= 0)) {
    $dtoIva = round($dto * (1 + ($ivp/100)), 2);
  } elseif (($dto === null || $dto <= 0) && $dtoIva !== null && $dtoIva > 0) {
    $dto = round($dtoIva / (1 + ($ivp/100)), 2);
  }

  // Totales del renglón (contables, sin aplicar DTO a estos totales)
  $rsub = $qty * $pu;
  $riva = $rsub * ($ivp/100.0);
  $rtot = $rsub + $riva;

  $subtotal += $rsub; $iva += $riva; $total += $rtot;

  $rows[] = [
    'id_modelo'       => $idm,
    'marca'           => $marca,
    'modelo'          => $modelo,
    'color'           => $col,
    'ram'             => $ramv,
    'capacidad'       => $cap,
    'cantidad'        => $qty,
    'precio_unitario' => $pu,
    'iva_porcentaje'  => $ivp,
    'subtotal'        => $rsub,
    'iva'             => $riva,
    'total'           => $rtot,
    'requiere_imei'   => $req,
    'codigo_producto' => $codigoCat,
    'costo_dto'       => $dto,       // nullable
    'costo_dto_iva'   => $dtoIva     // nullable
  ];
}

if (empty($rows)) { http_response_code(400); die("Debes incluir al menos un renglón válido."); }

// Extras
$extraSub = 0.0; $extraIVA = 0.0;
if (!empty($extra_desc) && is_array($extra_desc)) {
  foreach ($extra_desc as $i => $descRaw) {
    $desc  = trim((string)$descRaw);
    $monto = isset($extra_monto[$i]) ? (float)$extra_monto[$i] : 0.0;
    $ivaP  = isset($extra_iva_porcentaje[$i]) ? (float)$extra_iva_porcentaje[$i] : 0.0;
    if ($desc === '' || $monto <= 0) continue;
    $extraSub += $monto;
    $extraIVA += $monto * ($ivaP/100.0);
  }
}
$subtotal += $extraSub;
$iva      += $extraIVA;
$total     = $subtotal + $iva; // robusto

// ---------- Transacción ----------
$conn->begin_transaction();
try {
  // Encabezado
  $sqlC = "INSERT INTO compras
            (num_factura, id_proveedor, id_sucursal, fecha_factura, fecha_vencimiento,
             condicion_pago, dias_vencimiento,
             subtotal, iva, total, estatus, notas, creado_por)
           VALUES (?,?,?,?,?,?,?,?,?,?,'Pendiente',?,?)";
  $stmtC = $conn->prepare($sqlC);
  if (!$stmtC) throw new Exception("Prepare compras: ".$conn->error);

  $stmtC->bind_param(
    'siisssidddsi',
    $num_factura,
    $id_proveedor,
    $id_sucursal,
    $fecha_factura,
    $fecha_venc,
    $condicion_pago,
    $dias_vencimiento,
    $subtotal,
    $iva,
    $total,
    $notas,
    $ID_USUARIO
  );
  if (!$stmtC->execute()) throw new Exception("Insert compras: ".$stmtC->error);
  $id_compra = $stmtC->insert_id;
  $stmtC->close();

  // Detalle: permitir NULL en costo_dto y costo_dto_iva (se mandan como strings y NULLIF los convierte a NULL)
  $sqlD = "INSERT INTO compras_detalle
            (id_compra, id_modelo, marca, modelo, color, ram, capacidad, requiere_imei, descripcion,
             cantidad, precio_unitario, iva_porcentaje, subtotal, iva, total, costo_dto, costo_dto_iva)
           VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?,
             NULLIF(?, ''), NULLIF(?, ''))";
  $stmtD = $conn->prepare($sqlD);
  if (!$stmtD) throw new Exception("Prepare detalle: ".$conn->error);

  // Tipos: i i s s s s s i i d d d d d s s
  $stmtD_types = 'iisssssiidddddss';

  foreach ($rows as $r) {
    $dtoStr    = $r['costo_dto']     === null ? '' : number_format((float)$r['costo_dto'], 2, '.', '');
    $dtoIvaStr = $r['costo_dto_iva'] === null ? '' : number_format((float)$r['costo_dto_iva'], 2, '.', '');

    $stmtD->bind_param(
      $stmtD_types,
      $id_compra,
      $r['id_modelo'],
      $r['marca'],
      $r['modelo'],
      $r['color'],
      $r['ram'],
      $r['capacidad'],
      $r['requiere_imei'],
      $r['cantidad'],
      $r['precio_unitario'],
      $r['iva_porcentaje'],
      $r['subtotal'],
      $r['iva'],
      $r['total'],
      $dtoStr,
      $dtoIvaStr
    );
    if (!$stmtD->execute()) throw new Exception("Insert detalle: ".$stmtD->error);
  }
  $stmtD->close();

  // Otros cargos (si existe la tabla)
  if (!empty($extra_desc) && is_array($extra_desc) &&
      $conn->query("SHOW TABLES LIKE 'compras_cargos'")->num_rows) {
    $sqlX = "INSERT INTO compras_cargos
              (id_compra, descripcion, monto, iva_porcentaje, iva_monto, total, afecta_costo)
             VALUES (?,?,?,?,?,?,?)";
    $stmtX = $conn->prepare($sqlX);
    if (!$stmtX) throw new Exception("Prepare cargos: ".$conn->error);

    foreach ($extra_desc as $i => $descRaw) {
      $desc  = trim((string)$descRaw);
      $monto = isset($extra_monto[$i]) ? (float)$extra_monto[$i] : 0.0;
      $ivaP  = isset($extra_iva_porcentaje[$i]) ? (float)$extra_iva_porcentaje[$i] : 0.0;
      if ($desc === '' || $monto <= 0) continue;

      $ivaMonto = round($monto * ($ivaP/100.0), 2);
      $totCargo = round($monto + $ivaMonto, 2);
      $afecta   = 0;

      $stmtX->bind_param("isddddi", $id_compra, $desc, $monto, $ivaP, $ivaMonto, $totCargo, $afecta);
      if (!$stmtX->execute()) throw new Exception("Insert cargos: ".$stmtX->error);
    }
    $stmtX->close();
  }

  // Pago contado opcional
  $registrarPago = ($_POST['registrar_pago'] ?? '0') === '1';
  if ($registrarPago && $condicion_pago === 'Contado') {
    $pago_monto  = isset($_POST['pago_monto']) ? (float)$_POST['pago_monto'] : 0.0;
    $pago_metodo = substr(trim($_POST['pago_metodo'] ?? ''), 0, 40);
    $pago_ref    = substr(trim($_POST['pago_referencia'] ?? ''), 0, 120);
    $pago_fecha  = $_POST['pago_fecha'] ?? date('Y-m-d');
    $pago_notas  = substr(trim($_POST['pago_nota'] ?? ''), 0, 1000);

    $sqlP = "INSERT INTO compras_pagos
             (id_compra, fecha_pago, monto, metodo_pago, referencia, notas)
             VALUES (?,?,?,?,?,?)";
    $stP = $conn->prepare($sqlP);
    if (!$stP) throw new Exception('Prepare pago: '.$conn->error);
    $stP->bind_param("isdsss", $id_compra, $pago_fecha, $pago_monto, $pago_metodo, $pago_ref, $pago_notas);
    if (!$stP->execute()) throw new Exception('Insert pago: '.$stP->error);
    $stP->close();

    if ($pago_monto >= $total) {
      $conn->query("UPDATE compras SET estatus='Pagada' WHERE id=".$id_compra);
    }
  }

  $conn->commit();
  header("Location: compras_ver.php?id=".$id_compra);
  exit();

} catch (Exception $e) {
  $conn->rollback();
  http_response_code(500);
  echo "Error al guardar la compra: ".$e->getMessage();
}
