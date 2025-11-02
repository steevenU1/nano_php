<?php
// procesar_venta_accesorios.php — Venta de accesorios con modo REGALO (whitelist + $0) y FIFO por sucursal
// Respeta estatus: 'Disponible', 'En tránsito', 'Vendido', 'Retirado' (sin 'Agotado').

session_start();
if (!isset($_SESSION['id_usuario'])) { header('Location: index.php'); exit(); }
require_once __DIR__.'/db.php';
date_default_timezone_set('America/Mexico_City');

$ID_USUARIO  = (int)($_SESSION['id_usuario'] ?? 0);
$ID_SUCURSAL = (int)($_POST['id_sucursal'] ?? ($_SESSION['id_sucursal'] ?? 0));

/* Helpers */
function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function fail($m){ http_response_code(400); echo h($m); exit; }
function n2($v){ return number_format((float)$v, 2, '.', ''); }
function toInt($v){ return (is_numeric($v) ? (int)$v : 0); }
function toFloat($v){ return (is_numeric($v) ? (float)$v : -1); }
function table_exists(mysqli $c, string $t): bool {
  $t = $c->real_escape_string($t);
  $rs = $c->query("SHOW TABLES LIKE '{$t}'");
  return $rs && $rs->num_rows > 0;
}
function column_exists(mysqli $c, string $t, string $col): bool {
  $t = $c->real_escape_string($t);
  $col = $c->real_escape_string($col);
  $rs = $c->query("SHOW COLUMNS FROM `{$t}` LIKE '{$col}'");
  return $rs && $rs->num_rows > 0;
}

/* POST base */
$tag            = trim($_POST['tag'] ?? '');
$nombre_cliente = trim($_POST['nombre_cliente'] ?? '');
$telefono       = trim($_POST['telefono'] ?? '');
$comentarios    = trim($_POST['comentarios'] ?? '');
$forma_pago     = $_POST['forma_pago'] ?? 'Efectivo';
$efectivo       = (float)($_POST['efectivo'] ?? 0);
$tarjeta        = (float)($_POST['tarjeta'] ?? 0);
$es_regalo      = (int)($_POST['es_regalo'] ?? 0);

/* Validaciones base */
if ($tag === '') fail('TAG requerido');
if ($nombre_cliente === '') fail('Nombre del cliente requerido');
if (!preg_match('/^[0-9]{10}$/', $telefono)) fail('Teléfono inválido (10 dígitos)');
if ($ID_SUCURSAL <= 0) fail('Sucursal inválida');
if (!in_array($forma_pago, ['Efectivo','Tarjeta','Mixto'], true)) fail('Forma de pago inválida');

/* -------------------- PARSEO DE LÍNEAS -------------------- */
$norm = [];
$raw = $_POST['linea'] ?? null;

$push = function($idp,$cant,$precio) use (&$norm){
  $idp = toInt($idp); $cant = toInt($cant); $precio = toFloat($precio);
  if ($idp<=0 && $cant==0 && ($precio<0 || $precio===0.0)) { return; } // fila en blanco
  if ($idp<=0 || $cant<=0 || $precio<0) {
    $norm[] = ['_invalid'=>true, 'id_producto'=>$idp, 'cantidad'=>$cant, 'precio'=>$precio];
  } else {
    $norm[] = ['id_producto'=>$idp,'cantidad'=>$cant,'precio'=>$precio];
  }
};

if (is_array($raw) && isset($raw[0]) && is_array($raw[0])) {
  foreach ($raw as $ln) { $push($ln['id_producto'] ?? null, $ln['cantidad'] ?? null, $ln['precio'] ?? null); }
} elseif (is_array($raw) && isset($raw['id_producto']) && isset($raw['cantidad']) && isset($raw['precio'])
          && (is_array($raw['id_producto']) || is_array($raw['cantidad']) || is_array($raw['precio']))) {
  $N = max(count((array)$raw['id_producto']), count((array)$raw['cantidad']), count((array)$raw['precio']));
  for ($i=0;$i<$N;$i++){ $push($raw['id_producto'][$i] ?? null, $raw['cantidad'][$i] ?? null, $raw['precio'][$i] ?? null); }
} elseif (is_array($raw) && isset($raw['id_producto'],$raw['cantidad'],$raw['precio'])) {
  $push($raw['id_producto'], $raw['cantidad'], $raw['precio']);
} elseif (isset($_POST['linea_id_producto'],$_POST['linea_cantidad'],$_POST['linea_precio'])) {
  $ips = (array)$_POST['linea_id_producto'];
  $cns = (array)$_POST['linea_cantidad'];
  $prs = (array)$_POST['linea_precio'];
  $N = max(count($ips),count($cns),count($prs));
  for ($i=0;$i<$N;$i++){ $push($ips[$i] ?? null, $cns[$i] ?? null, $prs[$i] ?? null); }
} else {
  fail('No se recibió ninguna línea.');
}

if (empty($norm)) fail('No hay líneas válidas.');
$idx = 1;
foreach ($norm as $ln){
  if (!empty($ln['_invalid'])) fail('Línea inválida en la fila '.$idx.'. Selecciona accesorio, cantidad y precio.');
  $idx++;
}

/* =========================
   MODO REGALO (servidor)
   ========================= */
$regaloMode = ($es_regalo === 1);
$permitidos = [];

if ($regaloMode) {
  if (!table_exists($conn, 'accesorios_regalo_modelos')) {
    fail('No existe configuración de modelos para regalo. Contacta a Logística.');
  }
  $wh = $conn->query("SELECT id_producto FROM accesorios_regalo_modelos WHERE activo=1");
  if ($wh) while ($r = $wh->fetch_assoc()) $permitidos[(int)$r['id_producto']] = true;
  if (empty($permitidos)) fail('No hay modelos elegibles para regalo configurados.');

  foreach ($norm as &$ln) {
    $pid = (int)$ln['id_producto'];
    if (!isset($permitidos[$pid])) fail('El producto '.$pid.' no está autorizado como regalo.');
    $ln['precio'] = 0.00; // precio $0 en regalo
  }
  unset($ln);
  $forma_pago = 'Efectivo'; // backend fuerza efectivo $0
  $efectivo   = 0.00;
  $tarjeta    = 0.00;
}

/* Total y pagos */
$total = 0.0;
foreach ($norm as $ln) $total += $ln['cantidad'] * $ln['precio'];
$total = (float)n2($total);

if (!$regaloMode) {
  if ($forma_pago==='Efectivo' && (float)n2($efectivo) !== $total) fail('Efectivo debe igualar el total.');
  if ($forma_pago==='Tarjeta'  && (float)n2($tarjeta)  !== $total) fail('Tarjeta debe igualar el total.');
  if ($forma_pago==='Mixto'    && (float)n2($efectivo+$tarjeta) !== $total) fail('En pago Mixto, Efectivo + Tarjeta debe igualar el total.');
} else {
  if ((float)$total !== 0.0) fail('En venta con regalo el total debe ser $0.');
}

/* ====== FIFO (respetando estatus existentes) ======
   - Solo consume filas con estatus = 'Disponible'
   - Si se consume por completo: estatus = 'Vendido'
   - Si se consume parcial: permanece 'Disponible'
==================================================== */
$EST_DISPONIBLE = 'Disponible';
$EST_VENDIDO    = 'Vendido';

$conn->begin_transaction();
try {
  // Validar TAG único
  $chk = $conn->prepare("SELECT id FROM ventas_accesorios WHERE tag=? LIMIT 1");
  if (!$chk) throw new Exception('Error al preparar verificación de TAG.');
  $chk->bind_param('s', $tag);
  $chk->execute();
  $r = $chk->get_result();
  if ($r && $r->num_rows > 0) throw new Exception('El TAG ya existe.');

  // ¿ventas_accesorios.es_regalo existe?
  $tiene_es_regalo = column_exists($conn, 'ventas_accesorios', 'es_regalo');

  // Encabezado
  if ($tiene_es_regalo) {
    $stmt = $conn->prepare("INSERT INTO ventas_accesorios
      (tag,nombre_cliente,telefono,id_sucursal,id_usuario,forma_pago,efectivo,tarjeta,total,comentarios,es_regalo)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    if (!$stmt) throw new Exception('Error al preparar encabezado.');
    $stmt->bind_param('sssiisdddsi', $tag,$nombre_cliente,$telefono,$ID_SUCURSAL,$ID_USUARIO,$forma_pago,$efectivo,$tarjeta,$total,$comentarios,$es_regalo);
  } else {
    if ($regaloMode) $comentarios = trim(($comentarios!==''?$comentarios.' ':'').'(REGALO)');
    $stmt = $conn->prepare("INSERT INTO ventas_accesorios
      (tag,nombre_cliente,telefono,id_sucursal,id_usuario,forma_pago,efectivo,tarjeta,total,comentarios)
      VALUES (?,?,?,?,?,?,?,?,?,?)");
    if (!$stmt) throw new Exception('Error al preparar encabezado.');
    $stmt->bind_param('sssiisddds', $tag,$nombre_cliente,$telefono,$ID_SUCURSAL,$ID_USUARIO,$forma_pago,$efectivo,$tarjeta,$total,$comentarios);
  }
  $stmt->execute();
  $idVenta = (int)$conn->insert_id;

  // Helpers
  $getP = $conn->prepare("SELECT TRIM(CONCAT(marca,' ',modelo,' ',COALESCE(color,''))) AS nombre FROM productos WHERE id=? LIMIT 1");
  if (!$getP) throw new Exception('Error al preparar snapshot de producto.');
  $insD = $conn->prepare("INSERT INTO detalle_venta_accesorio (id_venta,id_producto,descripcion_snapshot,cantidad,precio_unitario,subtotal)
                          VALUES (?,?,?,?,?,?)");
  if (!$insD) throw new Exception('Error al preparar detalle.');

  // Solo cuenta y consume inventario con estatus 'Disponible'
  $qDisp = $conn->prepare("SELECT SUM(COALESCE(cantidad,1)) AS disp
                           FROM inventario
                           WHERE id_producto=? AND id_sucursal=? AND estatus=?");
  if (!$qDisp) throw new Exception('Error al preparar consulta de stock.');

  $qFIFO = $conn->prepare("SELECT id, COALESCE(cantidad,1) AS qty
                           FROM inventario
                           WHERE id_producto=? AND id_sucursal=? AND estatus=?
                           ORDER BY fecha_ingreso ASC, id ASC");
  if (!$qFIFO) throw new Exception('Error al preparar consulta FIFO.');

  $updRestar = $conn->prepare("UPDATE inventario SET cantidad = cantidad - ? WHERE id=?");
  // Cuando se agota, se marca como Vendido (no usamos 'Agotado')
  $updAgotar = $conn->prepare("UPDATE inventario SET cantidad = 0, estatus = ? WHERE id=?");

  foreach ($norm as $ln){
    $pid    = (int)$ln['id_producto'];
    $cant   = (int)$ln['cantidad'];
    $precio = (float)$ln['precio']; // 0 en modo regalo

    // Stock disponible en sucursal
    $qDisp->bind_param('iis', $pid, $ID_SUCURSAL, $EST_DISPONIBLE);
    $qDisp->execute();
    $disp = (int)($qDisp->get_result()->fetch_assoc()['disp'] ?? 0);
    if ($disp < $cant) throw new Exception('Stock insuficiente para producto '.$pid.' (disp: '.$disp.', req: '.$cant.').');

    // Snapshot de producto
    $getP->bind_param('i', $pid);
    $getP->execute();
    $rP = $getP->get_result();
    $nombre = ($rP && $rP->num_rows) ? ($rP->fetch_assoc()['nombre'] ?? ('Prod #'.$pid)) : ('Prod #'.$pid);

    // Detalle
    $sub = (float)n2($cant * $precio);
    $insD->bind_param('iisidd', $idVenta, $pid, $nombre, $cant, $precio, $sub);
    $insD->execute();

    // FIFO
    $qFIFO->bind_param('iis', $pid, $ID_SUCURSAL, $EST_DISPONIBLE);
    $qFIFO->execute();
    $rows = $qFIFO->get_result()->fetch_all(MYSQLI_ASSOC);

    $porConsumir = $cant;
    foreach ($rows as $rw){
      if ($porConsumir <= 0) break;
      $filaId = (int)$rw['id'];
      $tiene  = (int)$rw['qty'];
      if ($tiene <= 0) continue;

      if ($tiene > $porConsumir){
        // consumo parcial -> sigue 'Disponible'
        $updRestar->bind_param('ii', $porConsumir, $filaId);
        $updRestar->execute();
        $porConsumir = 0;
      } else {
        // consumo total -> marcar 'Vendido'
        $nuevoEstatus = $EST_VENDIDO;
        $updAgotar->bind_param('si', $nuevoEstatus, $filaId);
        $updAgotar->execute();
        $porConsumir -= $tiene;
      }
    }
    if ($porConsumir > 0) throw new Exception('No se pudo completar la salida de inventario (FIFO) para producto '.$pid);
  }

  $conn->commit();
  header('Location: venta_accesorios_ticket.php?id='.$idVenta);
  exit;

} catch (Throwable $e){
  $conn->rollback();
  http_response_code(500);
  echo 'Error al guardar la venta: '.h($e->getMessage());
}
