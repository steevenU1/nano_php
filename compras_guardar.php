<?php
// compras_guardar.php
// Guarda encabezado y renglones por MODELO del catálogo + otros cargos + pago contado opcional

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__.'/db.php';

$ID_USUARIO = (int)($_SESSION['id_usuario'] ?? 0);

// ---------- Helpers ----------
function str_lim($s, $len){ return substr(trim((string)$s), 0, $len); }
function is_valid_date($s){
  if (!$s) return false;
  $d = DateTime::createFromFormat('Y-m-d', $s);
  return $d && $d->format('Y-m-d') === $s;
}
function add_days($base, $days){
  $d = DateTime::createFromFormat('Y-m-d', $base);
  if (!$d) return null;
  $d->modify('+' . (int)$days . ' days');
  return $d->format('Y-m-d');
}
function diff_days($from, $to){
  $df = DateTime::createFromFormat('Y-m-d', $from);
  $dt = DateTime::createFromFormat('Y-m-d', $to);
  if (!$df || !$dt) return null;
  return (int)$df->diff($dt)->format('%r%a');
}

// ---------- Encabezado (POST) ----------
$id_proveedor      = (int)($_POST['id_proveedor'] ?? 0);
$num_factura       = str_lim($_POST['num_factura'] ?? '', 80);
$id_sucursal       = (int)($_POST['id_sucursal'] ?? 0);
$fecha_factura     = $_POST['fecha_factura'] ?? date('Y-m-d');
$fecha_venc        = $_POST['fecha_vencimiento'] ?? null;
$condicion_pago    = ($_POST['condicion_pago'] ?? 'Contado') === 'Crédito' ? 'Crédito' : 'Contado';
$dias_vencimiento  = isset($_POST['dias_vencimiento']) && $_POST['dias_vencimiento'] !== '' ? (int)$_POST['dias_vencimiento'] : null;
$notas             = str_lim($_POST['notas'] ?? '', 250);

// ---------- Validaciones mínimas ----------
if ($id_proveedor<=0 || $num_factura==='' || $id_sucursal<=0) {
  http_response_code(422);
  die("Parámetros inválidos.");
}
if (!is_valid_date($fecha_factura)) $fecha_factura = date('Y-m-d');

// ---------- Lógica de vencimiento ----------
if ($condicion_pago === 'Contado') {
  $fecha_venc = $fecha_factura;
  $dias_vencimiento = 0;
} else {
  if ($dias_vencimiento !== null) {
    if ($dias_vencimiento < 0) $dias_vencimiento = 0;
    $fv = add_days($fecha_factura, $dias_vencimiento);
    $fecha_venc = $fv ?: $fecha_factura;
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

// ---------- Anti duplicados (factura por proveedor) ----------
$dupQ = $conn->prepare("SELECT id FROM compras WHERE id_proveedor=? AND num_factura=? LIMIT 1");
$dupQ->bind_param("is", $id_proveedor, $num_factura);
$dupQ->execute();
$dupQ->store_result();
if ($dupQ->num_rows > 0) {
  $dupQ->close();
  http_response_code(409);
  die("Esta factura ya existe para el proveedor seleccionado.");
}
$dupQ->close();

// ---------- Detalle (indexados por fila) ----------
$id_modelo   = $_POST['id_modelo'] ?? [];
$color       = $_POST['color'] ?? [];
$ram         = $_POST['ram'] ?? [];
$capacidad   = $_POST['capacidad'] ?? [];
$cantidad    = $_POST['cantidad'] ?? [];
$precio      = $_POST['precio_unitario'] ?? [];
$iva_pct     = $_POST['iva_porcentaje'] ?? [];
$requiereMap = $_POST['requiere_imei'] ?? [];

// Descuento por renglón
$costo_dto     = $_POST['costo_dto']     ?? [];
$costo_dto_iva = $_POST['costo_dto_iva'] ?? [];

if (empty($id_modelo) || !is_array($id_modelo)) {
  http_response_code(422);
  die("Debes incluir al menos un renglón.");
}

// ---------- Otros cargos (opcional) ----------
$extra_desc            = $_POST['extra_desc']            ?? [];
$extra_monto           = $_POST['extra_monto']           ?? [];
$extra_iva_porcentaje  = $_POST['extra_iva_porcentaje']  ?? [];

$subtotal = 0.0; $iva = 0.0; $total = 0.0;
$rows = [];

// Snapshot del catálogo
$stCat = $conn->prepare("
  SELECT marca, modelo, codigo_producto, UPPER(COALESCE(tipo_producto,'')) AS tipo_producto
  FROM catalogo_modelos
  WHERE id=? AND activo=1
");

foreach ($id_modelo as $idx => $idmRaw) {
  $idm = (int)$idmRaw;
  if ($idm<=0) continue;

  $stCat->bind_param("i", $idm);
  $stCat->execute();
  $stCat->bind_result($marca, $modelo, $codigoCat, $tipoProd);
  $ok = $stCat->fetch();
  $stCat->free_result();
  if (!$ok) continue;

  $col = str_lim($color[$idx] ?? '—', 40);
  $ramv= str_lim($ram[$idx] ?? '—', 50);
  $cap = str_lim($capacidad[$idx] ?? '—', 40);
  $qty = max(0, (int)($cantidad[$idx] ?? 0));
  $pu  = max(0, (float)($precio[$idx] ?? 0));
  $ivp = max(0, (float)($iva_pct[$idx] ?? 0));
  $req = (int)($requiereMap[$idx] ?? 1) === 1 ? 1 : 0;

  if ($tipoProd === 'ACCESORIO') $req = 0;
  if ($marca==='' || $modelo==='' || $qty<=0 || $pu<=0) continue;

  $dto    = isset($costo_dto[$idx])     && $costo_dto[$idx]     !== '' ? (float)$costo_dto[$idx]     : null;
  $dtoIva = isset($costo_dto_iva[$idx]) && $costo_dto_iva[$idx] !== '' ? (float)$costo_dto_iva[$idx] : null;
  if ($dto !== null && ($dtoIva === null || $dtoIva <= 0)) $dtoIva = round($dto * (1 + ($ivp/100)), 2);
  elseif (($dto === null || $dto <= 0) && $dtoIva !== null && $dtoIva > 0) $dto = round($dtoIva / (1 + ($ivp/100)), 2);

  $rsub = round($qty * $pu, 2);
  $riva = round($rsub * ($ivp/100.0), 2);
  $rtot = round($rsub + $riva, 2);
  $subtotal += $rsub; $iva += $riva; $total += $rtot;

  $rows[] = compact('idm','marca','modelo','col','ramv','cap','qty','pu','ivp','req','codigoCat','tipoProd','dto','dtoIva','rsub','riva','rtot');
}
$stCat->close();

if (empty($rows)) {
  http_response_code(422);
  die("Debes incluir al menos un renglón válido.");
}

// ====== Calcular extras ======
$extraSub = 0.0; $extraIVA = 0.0;
if (!empty($extra_desc) && is_array($extra_desc)) {
  foreach ($extra_desc as $i => $descRaw) {
    $desc = str_lim($descRaw, 200);
    $monto = isset($extra_monto[$i]) ? (float)$extra_monto[$i] : 0.0;
    $ivaP  = isset($extra_iva_porcentaje[$i]) ? (float)$extra_iva_porcentaje[$i] : 0.0;
    if ($desc === '' || $monto <= 0) continue;
    $extraSub += $monto;
    $extraIVA += ($monto * ($ivaP/100.0));
  }
}
$subtotal = round($subtotal + $extraSub, 2);
$iva      = round($iva + $extraIVA, 2);
$total    = round($subtotal + $iva, 2);

// ---------- Transacción ----------
$conn->begin_transaction();
try {
  $sqlC = "INSERT INTO compras
    (num_factura, id_proveedor, id_sucursal, fecha_factura, fecha_vencimiento,
     condicion_pago, dias_vencimiento, subtotal, iva, total, estatus, notas, creado_por)
     VALUES (?,?,?,?,?,?,?,?,?,?,'Pendiente',?,?)";
  $stmtC = $conn->prepare($sqlC);
  $stmtC->bind_param('siisssidddsi',
    $num_factura, $id_proveedor, $id_sucursal, $fecha_factura,
    $fecha_venc, $condicion_pago, $dias_vencimiento, $subtotal, $iva, $total, $notas, $ID_USUARIO
  );
  $stmtC->execute();
  $id_compra = $stmtC->insert_id;
  $stmtC->close();

  $sqlD = "INSERT INTO compras_detalle
    (id_compra,id_modelo,marca,modelo,color,ram,capacidad,requiere_imei,descripcion,
     cantidad,precio_unitario,iva_porcentaje,subtotal,iva,total,costo_dto,costo_dto_iva)
     VALUES (?,?,?,?,?,?,?,?,NULL,?,?,?,?,?,?,?,?)";
  $stmtD = $conn->prepare($sqlD);
  foreach ($rows as $r) {
    $stmtD->bind_param(
      'iisssssiiddddddd',
      $id_compra, $r['idm'], $r['marca'], $r['modelo'], $r['col'], $r['ramv'], $r['cap'],
      $r['req'], $r['qty'], $r['pu'], $r['ivp'], $r['rsub'], $r['riva'], $r['rtot'], $r['dto'], $r['dtoIva']
    );
    $stmtD->execute();
  }
  $stmtD->close();

  $conn->commit();
  header("Location: compras_ver.php?id=".$id_compra);
  exit();

} catch (Exception $e) {
  $conn->rollback();
  http_response_code(500);
  echo "Error al guardar la compra: ".$e->getMessage();
}
