<?php
/* NANO — cobros.php
   Ajustes:
   - Motivos: incluye Pago inicial pospago, Enganche Innovacion Movil, Pago Innovacion Movil.
   - Modal previo obligatorio (Innovación Móvil): nombre_cliente + telefono_cliente.
   - Guarda en BD: nombre_cliente, telefono_cliente, ticket_uid.
   - Ticket en modal 80mm con QR (URL verificable con HMAC si TICKET_SECRET).
*/

session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Ejecutivo','Gerente'], true)) {
  header("Location: 403.php"); exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

// Archivos disponibles en NANO (según indicaste)
$hasConfig = file_exists(__DIR__.'/config.php');
if ($hasConfig) require_once __DIR__.'/config.php';

// Candado de captura con fallback si no existe
$candado = __DIR__ . '/candado_captura.php';
if (file_exists($candado)) {
  require_once $candado;
} else {
  if (!defined('MODO_CAPTURA')) define('MODO_CAPTURA', true);
  if (!function_exists('abortar_si_captura_bloqueada')) {
    function abortar_si_captura_bloqueada(): void { /* captura habilitada */ }
  }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$id_usuario  = (int)($_SESSION['id_usuario']  ?? 0);
$id_sucursal = (int)($_SESSION['id_sucursal'] ?? 0);
$nombre_usr  = trim($_SESSION['nombre'] ?? 'Usuario');

/* ============ Helpers ============ */
if (!function_exists('str_starts_with')) {
  function str_starts_with($h, $n){ return (string)$n !== '' && strncmp($h, $n, strlen($n)) === 0; }
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function base_origin(): string {
  $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  $scheme  = $isHttps ? 'https://' : 'http://';
  $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
  return $scheme . $host;
}
function ticket_secret(): ?string {
  if (defined('TICKET_SECRET') && TICKET_SECRET) return TICKET_SECRET;
  $env = getenv('TICKET_SECRET');
  return ($env && strlen($env) >= 16) ? $env : null;
}
function ticket_logo_src(): ?string {
  $cands = [];
  if (defined('TICKET_LOGO_URL') && TICKET_LOGO_URL) $cands[] = TICKET_LOGO_URL;
  $base = (defined('BASE_URL') && BASE_URL) ? rtrim(BASE_URL, '/') : base_origin();
  $cands[] = $base . '/static/logo_ticket.png';
  $cands[] = $base . '/assets/logo_ticket.png';
  $cands[] = $base . '/logo.png';
  foreach ($cands as $u) {
    if (!$u) continue;
    $u = preg_replace('#(^https?://)|/{2,}#', '$1/', $u);
    if (strpos($u, 'data:') === 0) return $u;
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if ($isHttps && str_starts_with($u, 'http://')) $u = 'https://' . substr($u, 7);
    $u .= (strpos($u, '?') !== false ? '&' : '?') . 'v=' . date('Ymd');
    return $u;
  }
  return null;
}

/* ===== Token anti doble-submit ===== */
if (empty($_SESSION['cobro_token'])) {
  $_SESSION['cobro_token'] = bin2hex(random_bytes(16));
}

/* ===== Nombre de sucursal ===== */
$nombre_sucursal = "Sucursal #$id_sucursal";
try {
  $stmtSuc = $conn->prepare("SELECT nombre FROM sucursales WHERE id = ? LIMIT 1");
  $stmtSuc->bind_param("i", $id_sucursal);
  $stmtSuc->execute();
  $stmtSuc->bind_result($tmpNombre);
  if ($stmtSuc->fetch() && !empty($tmpNombre)) $nombre_sucursal = $tmpNombre;
  $stmtSuc->close();
} catch (Throwable $e) {}

/* ===== Estado general ===== */
$msg  = '';
$lock = (defined('MODO_CAPTURA') && MODO_CAPTURA === false);

/* ====== TICKET (estado post-insert) ====== */
$ticket_ready = false;
$ticket = [
  'id'       => null,
  'uid'      => null,
  'fecha'    => null,
  'hora'     => null,
  'motivo'   => null,
  'tipo_pago'=> null,
  'total'    => 0.00,
  'efectivo' => 0.00,
  'tarjeta'  => 0.00,
  'cliente'  => null,
  'telefono' => null,
];

/* ===== Guardar GASTO ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_gasto') {
  abortar_si_captura_bloqueada();
  $g_categoria = trim($_POST['g_categoria'] ?? '');
  $g_concepto  = trim($_POST['g_concepto']  ?? '');
  $g_monto     = (float)($_POST['g_monto']  ?? 0);
  $g_obs       = trim($_POST['g_obs']       ?? '');

  if ($g_categoria === '' || $g_concepto === '' || $g_monto <= 0) {
    $msg .= "<div class='alert alert-warning mb-3'>⚠ Completa categoría, concepto y monto &gt; 0.</div>";
  } else {
    try {
      $ins = $conn->prepare("INSERT INTO gastos_sucursal (id_sucursal,id_usuario,categoria,concepto,monto,observaciones,fecha_gasto,id_corte) VALUES (?,?,?,?,?,?,NOW(),NULL)");
      $ins->bind_param('iissds', $id_sucursal, $id_usuario, $g_categoria, $g_concepto, $g_monto, $g_obs);
      $ins->execute();
      $msg .= "<div class='alert alert-success mb-3'>✅ Gasto registrado. Se descontará del efectivo del corte.</div>";
      $ins->close();
    } catch (Throwable $e) {
      $msg .= "<div class='alert alert-danger mb-3'>❌ Error al registrar gasto.</div>";
    }
  }
}

/* ===== Eliminar GASTO ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar_gasto') {
  abortar_si_captura_bloqueada();
  $gasto_id = (int)($_POST['gasto_id'] ?? 0);
  try {
    $tzDel = new DateTimeZone('America/Mexico_City');
    $inicioDel = (new DateTime('today', $tzDel))->format('Y-m-d 00:00:00');
    $finDel    = (new DateTime('tomorrow', $tzDel))->format('Y-m-d 00:00:00');
    if ($gasto_id > 0) {
      $del = $conn->prepare("DELETE FROM gastos_sucursal WHERE id=? AND id_sucursal=? AND id_corte IS NULL AND fecha_gasto>=? AND fecha_gasto<? LIMIT 1");
      $del->bind_param('iiss', $gasto_id, $id_sucursal, $inicioDel, $finDel);
      $del->execute();
      $msg .= ($del->affected_rows > 0)
        ? "<div class='alert alert-success mb-3'>🗑️ Gasto eliminado.</div>"
        : "<div class='alert alert-warning mb-3'>⚠ No se pudo eliminar (quizá ya está en un corte o no es de hoy).</div>";
      $del->close();
    } else {
      $msg .= "<div class='alert alert-warning mb-3'>⚠ ID de gasto inválido.</div>";
    }
  } catch (Throwable $e) {
    $msg .= "<div class='alert alert-danger mb-3'>❌ Error al eliminar gasto.</div>";
  }
}

/* ===== Guardar COBRO ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['accion'] ?? '') === '' || ($_POST['accion'] ?? '') === 'guardar_cobro')) {
  $posted_token = $_POST['cobro_token'] ?? '';
  if (!hash_equals($_SESSION['cobro_token'] ?? '', $posted_token)) {
    $msg .= "<div class='alert alert-warning mb-3'>⚠ Sesión expirada o envío duplicado. Recarga la página e intenta de nuevo.</div>";
  } else {
    $motivo         = trim($_POST['motivo'] ?? '');
    $tipo_pago      = $_POST['tipo_pago'] ?? '';
    $monto_total    = round((float)($_POST['monto_total'] ?? 0), 2);
    $monto_efectivo = round((float)($_POST['monto_efectivo'] ?? 0), 2);
    $monto_tarjeta  = round((float)($_POST['monto_tarjeta'] ?? 0), 2);

    // Datos de cliente (solo Innovación Móvil; vienen del modal)
    $nombre_cliente   = trim($_POST['nombre_cliente']   ?? '');
    $telefono_cliente = trim($_POST['telefono_cliente'] ?? '');

    // Normaliza por tipo de pago
    if ($tipo_pago === 'Efectivo') {
      $monto_efectivo = $monto_total; $monto_tarjeta = 0.00;
    } elseif ($tipo_pago === 'Tarjeta') {
      $monto_tarjeta  = $monto_total; $monto_efectivo = 0.00;
    } elseif ($tipo_pago !== 'Mixto') {
      $monto_efectivo = 0.00; $monto_tarjeta = 0.00;
    }

    // Comisión especial PayJoy/Krediya (no aplica con Tarjeta)
    $esAbono = in_array($motivo, ['Abono PayJoy', 'Abono Krediya'], true);
    $comision_especial = ($esAbono && $tipo_pago !== 'Tarjeta') ? 10.00 : 0.00;

    if ($motivo === '' || $tipo_pago === '' || $monto_total <= 0) {
      $msg .= "<div class='alert alert-warning mb-3'>⚠ Debes llenar todos los campos obligatorios.</div>";
    } else {
      $valido = false;
      if ($tipo_pago === 'Efectivo' && abs($monto_efectivo - $monto_total) < 0.01) $valido = true;
      if ($tipo_pago === 'Tarjeta'  && abs($monto_tarjeta  - $monto_total) < 0.01) $valido = true;
      if ($tipo_pago === 'Mixto'    && abs(($monto_efectivo + $monto_tarjeta) - $monto_total) < 0.01) $valido = true;

      if (!$valido) {
        $msg .= "<div class='alert alert-danger mb-3'>⚠ Los montos no cuadran con el tipo de pago seleccionado.</div>";
      } else {
        $esInnovacion = in_array($motivo, ['Enganche Innovacion Movil','Pago Innovacion Movil'], true);
        if ($esInnovacion && ($nombre_cliente === '' || $telefono_cliente === '')) {
          $msg .= "<div class='alert alert-warning mb-3'>⚠ Para $motivo debes capturar nombre y teléfono del cliente.</div>";
        } else {
          try {
            $ticket_uid = bin2hex(random_bytes(16)); // 32 chars
            // Nota: asumes columnas nombre_cliente, telefono_cliente, ticket_uid existen en cobros (ya ajustaste la tabla)
            $stmt = $conn->prepare("
              INSERT INTO cobros (
                id_usuario, id_sucursal, motivo, tipo_pago,
                monto_total, monto_efectivo, monto_tarjeta, comision_especial,
                nombre_cliente, telefono_cliente, ticket_uid,
                fecha_cobro, id_corte, corte_generado
              ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NULL, 0)
            ");
            $stmt->bind_param(
              'iissddddsss',
              $id_usuario,
              $id_sucursal,
              $motivo,
              $tipo_pago,
              $monto_total,
              $monto_efectivo,
              $monto_tarjeta,
              $comision_especial,
              $nombre_cliente,
              $telefono_cliente,
              $ticket_uid
            );
            $stmt->execute();
            $insert_id = $stmt->insert_id;
            $stmt->close();

            $msg .= "<div class='alert alert-success mb-3'>✅ Cobro #$insert_id registrado correctamente.</div>";
            $_SESSION['cobro_token'] = bin2hex(random_bytes(16));
            $_POST = []; // limpiar valores

            // Ticket listo SOLO para Innovación Móvil
            if ($esInnovacion) {
              $ticket_ready = true;
              $dt = new DateTime('now', new DateTimeZone('America/Mexico_City'));
              $ticket = [
                'id'       => $insert_id,
                'uid'      => $ticket_uid,
                'fecha'    => $dt->format('d/m/Y'),
                'hora'     => $dt->format('H:i'),
                'motivo'   => $motivo,
                'tipo_pago'=> $tipo_pago,
                'total'    => $monto_total,
                'efectivo' => $monto_efectivo,
                'tarjeta'  => $monto_tarjeta,
                'cliente'  => $nombre_cliente,
                'telefono' => $telefono_cliente,
              ];
            }

          } catch (Throwable $e) {
            $msg .= "<div class='alert alert-danger mb-3'>❌ Error al registrar cobro.</div>";
          }
        }
      }
    }
  }
}

/* ===== Ventana HOY ===== */
$tz = new DateTimeZone('America/Mexico_City');
$inicio = (new DateTime('today', $tz))->format('Y-m-d 00:00:00');
$fin    = (new DateTime('tomorrow', $tz))->format('Y-m-d 00:00:00');

/* ===== Cobros de HOY ===== */
$cobros_hoy = [];
$tot_total = $tot_efectivo = $tot_tarjeta = $tot_comision = 0.0;
try {
  $sql = "
    SELECT c.id, c.fecha_cobro, c.motivo, c.tipo_pago,
           c.monto_total, c.monto_efectivo, c.monto_tarjeta, c.comision_especial,
           c.nombre_cliente, c.telefono_cliente,
           u.nombre AS usuario
    FROM cobros c
    JOIN usuarios u ON u.id = c.id_usuario
    WHERE c.id_sucursal = ?
      AND c.fecha_cobro >= ? AND c.fecha_cobro < ?
    ORDER BY c.fecha_cobro DESC
    LIMIT 100
  ";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('iss', $id_sucursal, $inicio, $fin);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $cobros_hoy[] = $row;
    $tot_total    += (float)$row['monto_total'];
    $tot_efectivo += (float)$row['monto_efectivo'];
    $tot_tarjeta  += (float)$row['monto_tarjeta'];
    $tot_comision += (float)$row['comision_especial'];
  }
  $stmt->close();
} catch (Throwable $e) {}

/* ===== Gastos de HOY ===== */
$gastos_hoy = [];
$tot_gastos_hoy = 0.0;
try {
  $stG = $conn->prepare("
    SELECT id, fecha_gasto, categoria, concepto, monto, observaciones
    FROM gastos_sucursal
    WHERE id_sucursal = ? AND fecha_gasto >= ? AND fecha_gasto < ?
    ORDER BY fecha_gasto DESC, id DESC
    LIMIT 200
  ");
  $stG->bind_param('iss', $id_sucursal, $inicio, $fin);
  $stG->execute();
  $rg = $stG->get_result();
  while ($row = $rg->fetch_assoc()) {
    $gastos_hoy[] = $row;
    $tot_gastos_hoy += (float)$row['monto'];
  }
  $stG->close();
} catch (Throwable $e) {}

$efectivo_neto_hoy = max(0.0, $tot_efectivo - $tot_gastos_hoy);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Registrar Cobro</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap 5 CSS -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    .page-hero{background:linear-gradient(135deg,#0ea5e9 0%,#22c55e 100%);color:#fff;border-radius:16px;padding:20px;box-shadow:0 8px 24px rgba(2,6,23,.15)}
    .card-soft{border:1px solid rgba(0,0,0,.06);border-radius:16px;box-shadow:0 8px 24px rgba(2,6,23,.06)}
    .label-req::after{content:" *";color:#ef4444;font-weight:700}
    .form-help{font-size:.9rem;color:#64748b}
    .summary-row{display:flex;justify-content:space-between;align-items:center;padding:.35rem 0;border-bottom:1px dashed #e2e8f0;font-size:.95rem}
    .summary-row:last-child{border-bottom:0}
    .currency-prefix{min-width:44px}
    .sticky-actions{position:sticky;bottom:0;background:#fff;padding-top:.5rem;margin-top:1rem;border-top:1px solid #e2e8f0}
    .table thead th{white-space:nowrap}
    .badge-soft{background:#eef2ff;color:#3730a3}
    /* Ticket impresión 80mm */
    @page { size: 80mm auto; margin: 0; }
    @media print {
      body * { visibility: hidden !important; }
      #ticketModal, #ticketModal * { visibility: visible !important; }
      .modal-backdrop { display: none !important; }
      .modal { position: static !important; }
      .modal-dialog { margin: 0 !important; max-width: 80mm !important; }
      .modal-content { border: 0 !important; box-shadow: none !important; }
      #ticketContent { width: 72mm !important; margin: 0 auto !important; font-size: 12px !important; }
      #ticketContent h6 { font-size: 14px !important; }
      .no-print { display: none !important; }
      body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
    #ticketContent { width:100%; }
    #ticketContent h6 { margin:0; font-size:14px; text-align:center; }
    #ticketContent .line { border-top:1px dashed #999; margin:6px 0; }
    #ticketContent table { width:100%; font-size:12px; border-collapse:collapse; }
    #ticketContent td { padding:2px 0; vertical-align:top; }
  </style>
</head>
<body class="bg-light">
  <div class="container py-4">

    <?php if ($lock): ?>
      <div class="alert alert-warning d-flex align-items-center mb-3" role="alert">
        <i class="bi bi-lock-fill me-2"></i>
        <div><strong>Captura deshabilitada temporalmente.</strong> Podrás registrar cobros cuando el admin lo habilite.</div>
      </div>
    <?php endif; ?>

    <!-- Hero -->
    <div class="page-hero mb-4">
      <div class="d-flex align-items-center">
        <div class="me-3" style="font-size:2rem"><i class="bi bi-cash-coin"></i></div>
        <div>
          <h2 class="h3 mb-0">Registrar Cobro</h2>
          <div class="opacity-75">Captura rápida y validada • <?= h($nombre_sucursal) ?></div>
        </div>
      </div>
    </div>

    <?= $msg ?>

    <div class="row g-4">
      <!-- Col izquierda: formulario -->
      <div class="col-12 col-lg-7">
        <form method="POST" class="card card-soft p-3 p-md-4" id="formCobro" novalidate>
          <input type="hidden" name="accion" value="guardar_cobro">
          <input type="hidden" name="cobro_token" value="<?= h($_SESSION['cobro_token']) ?>">

          <!-- Hidden para datos de cliente (los llena el modal) -->
          <input type="hidden" name="nombre_cliente" id="nombre_cliente_hidden">
          <input type="hidden" name="telefono_cliente" id="telefono_cliente_hidden">

          <!-- Motivo -->
          <div class="mb-3">
            <label class="form-label label-req"><i class="bi bi-clipboard2-check me-1"></i>Motivo del cobro</label>
            <select name="motivo" id="motivo" class="form-select" required>
              <option value="">-- Selecciona --</option>
              <option <?= (($_POST['motivo'] ?? '') === 'Enganche') ? 'selected' : ''; ?>>Enganche</option>
              <option <?= (($_POST['motivo'] ?? '') === 'Equipo de contado') ? 'selected' : ''; ?>>Equipo de contado</option>
              <option <?= (($_POST['motivo'] ?? '') === 'Venta SIM') ? 'selected' : ''; ?>>Venta SIM</option>
              <option <?= (($_POST['motivo'] ?? '') === 'Recarga Tiempo Aire') ? 'selected' : ''; ?>>Recarga Tiempo Aire</option>
              <option <?= (($_POST['motivo'] ?? '') === 'Abono PayJoy') ? 'selected' : ''; ?>>Abono PayJoy</option>
              <option <?= (($_POST['motivo'] ?? '') === 'Abono Krediya') ? 'selected' : ''; ?>>Abono Krediya</option>
              <option <?= (($_POST['motivo'] ?? '') === 'Pago inicial pospago') ? 'selected' : ''; ?>>Pago inicial pospago</option>
              <option <?= (($_POST['motivo'] ?? '') === 'Enganche Innovacion Movil') ? 'selected' : ''; ?>>Enganche Innovacion Movil</option>
              <option <?= (($_POST['motivo'] ?? '') === 'Pago Innovacion Movil') ? 'selected' : ''; ?>>Pago Innovacion Movil</option>
            </select>
            <div class="form-help">Para <strong>Abono PayJoy/Krediya</strong> se suma comisión especial automática <em>(no aplica si el pago es con tarjeta)</em>.</div>
          </div>

          <!-- Tipo de pago + total -->
          <div class="mb-3">
            <label class="form-label label-req"><i class="bi bi-credit-card-2-front me-1"></i>Tipo de pago</label>
            <div class="row g-2">
              <div class="col-12 col-sm-6">
                <select name="tipo_pago" id="tipo_pago" class="form-select" required>
                  <option value="">-- Selecciona --</option>
                  <option value="Efectivo" <?= (($_POST['tipo_pago'] ?? '') === 'Efectivo') ? 'selected' : ''; ?>>Efectivo</option>
                  <option value="Tarjeta"  <?= (($_POST['tipo_pago'] ?? '') === 'Tarjeta')  ? 'selected' : ''; ?>>Tarjeta</option>
                  <option value="Mixto"    <?= (($_POST['tipo_pago'] ?? '') === 'Mixto')    ? 'selected' : ''; ?>>Mixto</option>
                </select>
              </div>
              <div class="col-12 col-sm-6">
                <div class="input-group">
                  <span class="input-group-text currency-prefix">$</span>
                  <input type="number" step="0.01" min="0" name="monto_total" id="monto_total"
                         class="form-control" placeholder="0.00" required
                         value="<?= h((string)($_POST['monto_total'] ?? '')) ?>">
                </div>
                <div class="form-help">Monto total del cobro.</div>
              </div>
            </div>
          </div>

          <!-- Campos condicionales -->
          <div class="row g-3">
            <div class="col-12 col-md-6 pago-efectivo d-none">
              <label class="form-label"><i class="bi bi-cash me-1"></i>Monto en efectivo</label>
              <div class="input-group">
                <span class="input-group-text currency-prefix">$</span>
                <input type="number" step="0.01" min="0" name="monto_efectivo" id="monto_efectivo"
                       class="form-control" placeholder="0.00"
                       value="<?= h((string)($_POST['monto_efectivo'] ?? '')) ?>">
              </div>
            </div>
            <div class="col-12 col-md-6 pago-tarjeta d-none">
              <label class="form-label"><i class="bi bi-credit-card me-1"></i>Monto con tarjeta</label>
              <div class="input-group">
                <span class="input-group-text currency-prefix">$</span>
                <input type="number" step="0.01" min="0" name="monto_tarjeta" id="monto_tarjeta"
                       class="form-control" placeholder="0.00"
                       value="<?= h((string)($_POST['monto_tarjeta'] ?? '')) ?>">
              </div>
            </div>
          </div>

          <div class="mt-3 small text-muted">Los importes deben cuadrar con el tipo de pago: efectivo = total, tarjeta = total, mixto = efectivo + tarjeta = total.</div>

          <div class="sticky-actions">
            <div class="d-grid mt-3">
              <button type="submit" id="btnGuardar" class="btn btn-success btn-lg" <?= $lock ? 'disabled' : '' ?>>
                <i class="bi bi-save me-2"></i>Guardar Cobro
              </button>
            </div>
            <?php if ($lock): ?>
              <div class="text-center text-muted mt-2"><i class="bi bi-info-circle me-1"></i>El administrador habilitará la captura pronto.</div>
            <?php endif; ?>
          </div>
        </form>

        <!-- GASTOS -->
        <div class="card card-soft p-3 p-md-4 mt-4">
          <div class="d-flex align-items-center mb-2">
            <i class="bi bi-wallet2 me-2 fs-4"></i>
            <h5 class="mb-0">Gastos de la sucursal (se descuentan del EFECTIVO)</h5>
          </div>

          <form method="POST" class="row g-2">
            <input type="hidden" name="accion" value="guardar_gasto">
            <div class="col-12 col-md-4">
              <label class="form-label label-req">Categoría</label>
              <select name="g_categoria" class="form-select" required>
                <option value="">-- Selecciona --</option>
                <option>Operativo</option>
                <option>Publicidad</option>
                <option>Traslados</option>
                <option>Papelería</option>
                <option>Servicios</option>
                <option>Otros</option>
              </select>
            </div>
            <div class="col-12 col-md-5">
              <label class="form-label label-req">Concepto</label>
              <input type="text" name="g_concepto" class="form-control" maxlength="255" required placeholder="Ej. Gasolina, lonas, limpieza...">
            </div>
            <div class="col-12 col-md-3">
              <label class="form-label label-req">Monto (efectivo)</label>
              <div class="input-group">
                <span class="input-group-text">$</span>
                <input type="number" step="0.01" min="0" name="g_monto" class="form-control" required placeholder="0.00">
              </div>
            </div>
            <div class="col-12">
              <label class="form-label">Observaciones</label>
              <input type="text" name="g_obs" class="form-control" placeholder="Opcional">
            </div>
            <div class="col-12 d-grid">
              <button class="btn btn-outline-primary" <?= $lock ? 'disabled' : '' ?>><i class="bi bi-plus-circle me-1"></i>Agregar gasto</button>
            </div>
          </form>

          <hr>
          <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
            <h6 class="mb-0">Gastos de hoy</h6>
            <div class="d-flex gap-2">
              <span class="badge bg-danger">Total gastos: $<?= number_format($tot_gastos_hoy, 2) ?></span>
              <span class="badge bg-secondary">Efectivo cobrado: $<?= number_format($tot_efectivo, 2) ?></span>
              <span class="badge bg-success">Efectivo neto: $<?= number_format($efectivo_neto_hoy, 2) ?></span>
            </div>
          </div>
          <div class="table-responsive mt-2">
            <table class="table table-sm table-striped">
              <thead class="table-light">
                <tr>
                  <th>Hora</th><th>Categoría</th><th>Concepto</th><th class="text-end">Monto</th><th style="width:110px">Acciones</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($gastos_hoy)): ?>
                  <tr><td colspan="5" class="text-muted text-center">Sin gastos capturados hoy.</td></tr>
                <?php else: foreach ($gastos_hoy as $g): ?>
                  <tr>
                    <td><?= h((new DateTime($g['fecha_gasto']))->format('H:i')) ?></td>
                    <td><?= h($g['categoria']) ?></td>
                    <td>
                      <?= h($g['concepto']) ?>
                      <?php if (!empty($g['observaciones'])): ?>
                        <div class="small text-muted"><?= h($g['observaciones']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td class="text-end">$<?= number_format((float)$g['monto'], 2) ?></td>
                    <td>
                      <form method="POST" onsubmit="return confirm('¿Eliminar este gasto?');" class="d-inline">
                        <input type="hidden" name="accion" value="eliminar_gasto">
                        <input type="hidden" name="gasto_id" value="<?= (int)$g['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger" <?= $lock ? 'disabled' : '' ?>>Eliminar</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
          <div class="small text-muted"><i class="bi bi-info-circle me-1"></i>Estos gastos se descuentan del <b>efectivo</b> al generar el corte del día.</div>
        </div>
      </div>

      <!-- Col derecha: resumen -->
      <div class="col-12 col-lg-5">
        <div class="card card-soft p-3 p-md-4">
          <div class="d-flex align-items-center mb-2">
            <i class="bi bi-receipt-cutoff me-2 fs-4"></i>
            <h5 class="mb-0">Resumen del cobro</h5>
          </div>
          <div class="summary-row"><span class="text-muted">Motivo</span><strong id="r_motivo">—</strong></div>
          <div class="summary-row"><span class="text-muted">Tipo de pago</span><strong id="r_tipo">—</strong></div>
          <div class="summary-row"><span class="text-muted">Total</span><strong id="r_total">$0.00</strong></div>
          <div class="summary-row"><span class="text-muted">Efectivo</span><strong id="r_efectivo">$0.00</strong></div>
          <div class="summary-row"><span class="text-muted">Tarjeta</span><strong id="r_tarjeta">$0.00</strong></div>
          <div class="summary-row"><span class="text-muted">Comisión especial</span><strong id="r_comision">$0.00</strong></div>
          <div id="r_status" class="mt-3"></div>
          <div class="mt-3 small text-muted"><i class="bi bi-shield-check me-1"></i>Validación en tiempo real.</div>
        </div>

        <div class="card card-soft p-3 p-md-4 mt-4">
          <div class="d-flex align-items-center mb-2">
            <i class="bi bi-graph-down-arrow me-2 fs-4"></i>
            <h5 class="mb-0">Hoy en efectivo</h5>
          </div>
          <div class="summary-row"><span class="text-muted">Cobrado en efectivo</span><strong>$<?= number_format($tot_efectivo, 2) ?></strong></div>
          <div class="summary-row"><span class="text-muted">Gastos del día</span><strong class="text-danger">$<?= number_format($tot_gastos_hoy, 2) ?></strong></div>
          <div class="summary-row"><span class="text-muted">Efectivo neto (referencia)</span><strong class="text-success">$<?= number_format($efectivo_neto_hoy, 2) ?></strong></div>
          <div class="form-text mt-2">El efectivo neto se usará como base al generar el corte de caja.</div>
        </div>
      </div>
    </div>

    <!-- Cobros de hoy -->
    <div class="card card-soft p-3 p-md-4 mt-4">
      <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
        <h5 class="mb-2 mb-sm-0">Cobros de hoy — <span class="badge badge-soft"><?= h($nombre_sucursal) ?></span></h5>
        <div class="d-flex gap-2"><input type="text" id="filtroTabla" class="form-control" placeholder="Buscar en tabla (motivo, usuario, tipo)" /></div>
      </div>

      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle" id="tablaCobros">
          <thead class="table-light">
            <tr>
              <th>Hora</th><th>Usuario</th><th>Motivo</th><th>Tipo de pago</th>
              <th class="text-end">Total</th><th class="text-end">Efectivo</th>
              <th class="text-end">Tarjeta</th><th class="text-end">Comisión</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($cobros_hoy) === 0): ?>
              <tr><td colspan="8" class="text-center text-muted">Sin cobros registrados hoy en esta sucursal.</td></tr>
            <?php else: foreach ($cobros_hoy as $r): ?>
              <tr>
                <td><?= h((new DateTime($r['fecha_cobro']))->format('H:i')) ?></td>
                <td><?= h($r['usuario'] ?? '') ?></td>
                <td>
                  <?= h($r['motivo'] ?? '') ?>
                  <?php if (!empty($r['nombre_cliente'])): ?>
                    <div class="small text-muted">Cliente: <?= h($r['nombre_cliente']) ?> (<?= h($r['telefono_cliente']) ?>)</div>
                  <?php endif; ?>
                </td>
                <td><?= h($r['tipo_pago'] ?? '') ?></td>
                <td class="text-end"><?= number_format((float)$r['monto_total'], 2) ?></td>
                <td class="text-end"><?= number_format((float)$r['monto_efectivo'], 2) ?></td>
                <td class="text-end"><?= number_format((float)$r['monto_tarjeta'], 2) ?></td>
                <td class="text-end"><?= number_format((float)$r['comision_especial'], 2) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
          <?php if (count($cobros_hoy) > 0): ?>
            <tfoot>
              <tr class="fw-semibold">
                <td colspan="4" class="text-end">Totales</td>
                <td class="text-end"><?= number_format($tot_total, 2) ?></td>
                <td class="text-end"><?= number_format($tot_efectivo, 2) ?></td>
                <td class="text-end"><?= number_format($tot_tarjeta, 2) ?></td>
                <td class="text-end"><?= number_format($tot_comision, 2) ?></td>
              </tr>
            </tfoot>
          <?php endif; ?>
        </table>
      </div>
      <div class="small text-muted">Ventana: hoy <?= h((new DateTime('today', $tz))->format('d/m/Y')) ?> — registros más recientes primero (máx. 100).</div>
    </div>

  </div>

  <!-- ===== Modales ===== -->

  <!-- Modal Datos del Cliente (solo Innovación Móvil) -->
  <div class="modal fade" id="clienteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-person-vcard me-2"></i>Datos del cliente</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Nombre del cliente</label>
            <input type="text" class="form-control" id="nombre_cliente_modal" maxlength="120" placeholder="Nombre y apellidos">
          </div>
          <div class="mb-1">
            <label class="form-label">Teléfono del cliente</label>
            <input type="tel" class="form-control" id="telefono_cliente_modal" maxlength="25" placeholder="10 dígitos">
          </div>
          <div class="small text-muted">Estos datos quedarán ligados al cobro para Innovación Móvil.</div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-primary" id="btnGuardarCliente"><i class="bi bi-check2-circle me-1"></i>Continuar</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal Ticket -->
  <div class="modal fade" id="ticketModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:380px">
      <div class="modal-content">
        <div class="modal-header no-print">
          <h5 class="modal-title"><i class="bi bi-receipt me-2"></i>Ticket de cobro</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body" id="ticketContent">
          <?php $logoSrc = ticket_logo_src(); ?>
          <?php if ($logoSrc): ?>
            <div class="logo-wrap" style="text-align:center;margin-bottom:6px;">
              <img id="ticketLogo" src="<?= h($logoSrc) ?>" alt="Logo"
                   style="max-width:80px;max-height:60px;object-fit:contain;display:inline-block;"
                   crossorigin="anonymous" referrerpolicy="no-referrer"
                   onerror="this.closest('.logo-wrap')?.remove();">
            </div>
          <?php endif; ?>

          <h6><?= h(defined('EMPRESA_NOMBRE') ? EMPRESA_NOMBRE : 'NanoRed, S.A. de C.V.') ?></h6>
          <div style="text-align:center;font-size:11px">
            <?= h(defined('EMPRESA_DIR') ? EMPRESA_DIR : 'Domicilio fiscal') ?> •
            Tel: <?= h(defined('EMPRESA_TEL') ? EMPRESA_TEL : '—') ?><br>
            <?= h($nombre_sucursal) ?>
          </div>
          <div class="line"></div>
          <table>
            <tr><td>Folio:</td><td style="text-align:right">#<?= h((string)$ticket['id']) ?></td></tr>
            <tr><td>Fecha:</td><td style="text-align:right"><?= h((string)$ticket['fecha']) ?> <?= h((string)$ticket['hora']) ?></td></tr>
            <tr><td>Atendió:</td><td style="text-align:right"><?= h($nombre_usr) ?></td></tr>
            <tr><td>Motivo:</td><td style="text-align:right"><?= h((string)$ticket['motivo']) ?></td></tr>
            <tr><td>Tipo de pago:</td><td style="text-align:right"><?= h((string)$ticket['tipo_pago']) ?></td></tr>
          </table>

          <?php if (!empty($ticket['cliente'])): ?>
            <div class="line"></div>
            <div><strong>Cliente:</strong> <?= h($ticket['cliente']) ?></div>
            <div><strong>Teléfono:</strong> <?= h($ticket['telefono']) ?></div>
          <?php endif; ?>

          <div class="line"></div>
          <table>
            <tr><td>Total</td><td style="text-align:right">$<?= number_format((float)$ticket['total'], 2) ?></td></tr>
            <tr><td>Efectivo</td><td style="text-align:right">$<?= number_format((float)$ticket['efectivo'], 2) ?></td></tr>
            <tr><td>Tarjeta</td><td style="text-align:right">$<?= number_format((float)$ticket['tarjeta'], 2) ?></td></tr>
          </table>
          <div class="line"></div>
          <div id="qrcode" style="display:flex;justify-content:center;margin-top:6px;"></div>
          <div style="text-align:center;font-size:11px;margin-top:6px"><?= h($ticket['uid'] ?? '') ?></div>
          <div style="text-align:center;margin-top:8px">¡Gracias por su preferencia!</div>
        </div>
        <div class="modal-footer no-print">
          <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
          <button class="btn btn-primary" onclick="printTicketClean()"><i class="bi bi-printer me-1"></i>Imprimir / PDF (80 mm)</button>
        </div>
      </div>
    </div>
  </div>

  <!-- JS -->
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <!-- Bootstrap 5 JS (necesario para modales) -->
  <!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script> -->
  <!-- QR -->
  <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>

  <script>
    (function() {
      const $motivo = $("#motivo"),
            $tipo = $("#tipo_pago"),
            $total = $("#monto_total"),
            $efec = $("#monto_efectivo"),
            $tjt = $("#monto_tarjeta");

      function toggleCampos() {
        const t = $tipo.val();
        $(".pago-efectivo, .pago-tarjeta").addClass("d-none");
        if (t === "Efectivo") $(".pago-efectivo").removeClass("d-none");
        if (t === "Tarjeta")  $(".pago-tarjeta").removeClass("d-none");
        if (t === "Mixto")    $(".pago-efectivo, .pago-tarjeta").removeClass("d-none");

        if (t === "Efectivo") {
          $tjt.prop("disabled", true).val("");
          $efec.prop("disabled", false);
        } else if (t === "Tarjeta") {
          $efec.prop("disabled", true).val("");
          $tjt.prop("disabled", false);
        } else if (t === "Mixto") {
          $efec.prop("disabled", false);
          $tjt.prop("disabled", false);
        } else {
          $efec.prop("disabled", true).val("");
          $tjt.prop("disabled", true).val("");
        }
        validar();
      }

      function comisionEspecial(m, t) {
        return ((m === "Abono PayJoy" || m === "Abono Krediya") && t !== "Tarjeta") ? 10 : 0;
      }
      const fmt = n => "$" + (isFinite(n) ? Number(n) : 0).toFixed(2);

      function validar() {
        const m = ($motivo.val() || "").trim(),
              t = $tipo.val() || "",
              tot = parseFloat($total.val() || 0) || 0,
              ef  = parseFloat($efec.val()  || 0) || 0,
              tj  = parseFloat($tjt.val()   || 0) || 0,
              com = comisionEspecial(m, t);

        $("#r_motivo").text(m || "—");
        $("#r_tipo").text(t || "—");
        $("#r_total").text(fmt(tot));
        $("#r_efectivo").text(fmt(ef));
        $("#r_tarjeta").text(fmt(tj));
        $("#r_comision").text(fmt(com));

        let ok = false;
        if (t === "Efectivo") ok = Math.abs(ef - tot) < 0.01;
        if (t === "Tarjeta")  ok = Math.abs(tj - tot) < 0.01;
        if (t === "Mixto")    ok = Math.abs((ef + tj) - tot) < 0.01;

        const $s = $("#r_status");
        if (!t || tot <= 0) {
          $s.innerHTML = '';
          $s.html(`<div class="alert alert-secondary py-2 mb-0"><i class="bi bi-info-circle me-1"></i>Completa el tipo de pago y el total.</div>`);
          return;
        }
        $s.html(ok
          ? `<div class="alert alert-success py-2 mb-0"><i class="bi bi-check-circle me-1"></i>Montos correctos.</div>`
          : `<div class="alert alert-warning py-2 mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Los montos no cuadran.</div>`);
      }

      $("#tipo_pago").on("change", toggleCampos);
      $("#motivo, #monto_total, #monto_efectivo, #monto_tarjeta").on("input change", validar);
      $("#motivo").trigger("focus");
      toggleCampos(); validar();

      // Filtro de tabla
      $("#filtroTabla").on("input", function() {
        const q = $(this).val().toLowerCase();
        $("#tablaCobros tbody tr").each(function() {
          const t = $(this).text().toLowerCase();
          $(this).toggle(t.indexOf(q) !== -1);
        });
      });

      // ===== Modal previo (Innovación Móvil) =====
      const motivosInnovacion = new Set(['Enganche Innovacion Movil','Pago Innovacion Movil']);
      document.getElementById('btnGuardar').addEventListener('click', function(ev){
        const motivo = ($motivo.val() || '').trim();
        if (motivosInnovacion.has(motivo)) {
          const haveHidden = (
            (document.getElementById('nombre_cliente_hidden').value.trim() !== '') &&
            (document.getElementById('telefono_cliente_hidden').value.trim() !== '')
          );
          if (!haveHidden) {
            ev.preventDefault(); ev.stopPropagation();
            const modal = new bootstrap.Modal(document.getElementById('clienteModal'));
            modal.show();
          }
        }
      });
      document.getElementById('btnGuardarCliente').addEventListener('click', function(){
        const n = document.getElementById('nombre_cliente_modal').value.trim();
        const t = document.getElementById('telefono_cliente_modal').value.trim();
        if (n.length < 3) { alert('Nombre del cliente inválido.'); return; }
        if (t.length < 8) { alert('Teléfono del cliente inválido.'); return; }
        document.getElementById('nombre_cliente_hidden').value = n;
        document.getElementById('telefono_cliente_hidden').value = t;
        bootstrap.Modal.getInstance(document.getElementById('clienteModal')).hide();
        document.getElementById('formCobro').submit();
      });

      <?php if ($ticket_ready): ?>
        // Mostrar ticket y generar QR de verificación
        const tModal = new bootstrap.Modal(document.getElementById('ticketModal'));
        tModal.show();
        <?php
          $base = base_origin();
          $uid  = (string)$ticket['uid'];
          $amount = number_format((float)$ticket['total'], 2, '.', '');
          $ts   = time();
          $sec  = ticket_secret();
          if ($sec) {
            $sig = hash_hmac('sha256', $uid . '|' . $amount . '|' . $ts, $sec);
            $verifyUrl = $base . '/ticket_verificar.php?uid=' . urlencode($uid)
              . '&total=' . urlencode($amount) . '&ts=' . urlencode((string)$ts)
              . '&sig=' . urlencode($sig);
          } else {
            $verifyUrl = $base . '/ticket_verificar.php?uid=' . urlencode($uid);
          }
        ?>
        new QRCode(document.getElementById("qrcode"), {
          text: <?= json_encode($verifyUrl) ?>,
          width: 120, height: 120
        });
      <?php endif; ?>
    })();

    // Impresión limpia (popup 80mm) con QR como IMG
    function printTicketClean() {
      const ticketEl = document.getElementById('ticketContent').cloneNode(true);
      const qrCanvas = document.querySelector('#qrcode canvas');
      if (qrCanvas) {
        const dataURL = qrCanvas.toDataURL('image/png');
        const img = document.createElement('img');
        img.src = dataURL;
        img.style.display = 'block';
        img.style.margin = '6px auto 0';
        const qrHost = ticketEl.querySelector('#qrcode');
        if (qrHost) { qrHost.innerHTML = ''; qrHost.appendChild(img); }
      }
      const css = `
        <style>
          @page { size: 80mm auto; margin: 0; }
          body { margin: 0; font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; }
          #ticketContent { width: 72mm; margin: 0 auto; font-size: 12px; }
          #ticketContent h6 { margin: 0; font-size: 14px; text-align: center; }
          #ticketContent .line { border-top:1px dashed #999; margin:6px 0; }
          #ticketContent table { width:100%; font-size:12px; border-collapse:collapse; }
          #ticketContent td { padding:2px 0; vertical-align: top; }
          * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        </style>
      `;
      const w = window.open('', 'ticket', 'width=420,height=700');
      w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>Ticket</title>${css}</head><body>${ticketEl.outerHTML}</body></html>`);
      w.document.close();
      setTimeout(() => { w.focus(); w.print(); w.close(); }, 200);
    }
  </script>
</body>
</html>
