<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Ejecutivo', 'Gerente'])) {
  header("Location: 403.php");
  exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

// Candado de captura con fallback si no existe el archivo
$candado = __DIR__ . '/candado_captura.php';
if (file_exists($candado)) {
  require_once $candado;
} else {
  if (!defined('MODO_CAPTURA')) define('MODO_CAPTURA', true);
  if (!function_exists('abortar_si_captura_bloqueada')) {
    function abortar_si_captura_bloqueada(): void
    { /* captura habilitada por defecto */ }
  }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$id_usuario  = (int)($_SESSION['id_usuario']  ?? 0);
$id_sucursal = (int)($_SESSION['id_sucursal'] ?? 0);

// === Token anti doble-submit (idempotencia) ===
if (empty($_SESSION['cobro_token'])) {
  $_SESSION['cobro_token'] = bin2hex(random_bytes(16));
}

/* ===========================
   Nombre de la sucursal
   =========================== */
$nombre_sucursal = "Sucursal #$id_sucursal";
try {
  $stmtSuc = $conn->prepare("SELECT nombre FROM sucursales WHERE id = ? LIMIT 1");
  $stmtSuc->bind_param("i", $id_sucursal);
  $stmtSuc->execute();
  $stmtSuc->bind_result($tmpNombre);
  if ($stmtSuc->fetch() && !empty($tmpNombre)) {
    $nombre_sucursal = $tmpNombre;
  }
  $stmtSuc->close();
} catch (Throwable $e) {
  // Fallback ya definido
}

/* ===========================
   Estado general y mensajes
   =========================== */
$msg = '';
$lock = (defined('MODO_CAPTURA') && MODO_CAPTURA === false);

/* ===========================
   Capturar GASTO (POST)
   =========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_gasto') {
  abortar_si_captura_bloqueada(); // por si el candado aplica también a gastos
  $g_categoria = trim($_POST['g_categoria'] ?? '');
  $g_concepto  = trim($_POST['g_concepto']  ?? '');
  $g_monto     = (float)($_POST['g_monto']  ?? 0);
  $g_obs       = trim($_POST['g_obs']       ?? '');

  if ($g_categoria === '' || $g_concepto === '' || $g_monto <= 0) {
    $msg .= "<div class='alert alert-warning mb-3'>⚠ Completa categoría, concepto y monto &gt; 0.</div>";
  } else {
    try {
      $ins = $conn->prepare("
        INSERT INTO gastos_sucursal
        (id_sucursal, id_usuario, categoria, concepto, monto, observaciones, fecha_gasto, id_corte)
        VALUES (?, ?, ?, ?, ?, ?, NOW(), NULL)
      ");
      $ins->bind_param('iissds', $id_sucursal, $id_usuario, $g_categoria, $g_concepto, $g_monto, $g_obs);
      if ($ins->execute()) {
        $msg .= "<div class='alert alert-success mb-3'>✅ Gasto registrado. Se descontará del efectivo del corte.</div>";
      } else {
        $msg .= "<div class='alert alert-danger mb-3'>❌ Error al registrar gasto.</div>";
      }
      $ins->close();
    } catch (Throwable $e) {
      $msg .= "<div class='alert alert-danger mb-3'>❌ Error al registrar gasto.</div>";
    }
  }
}

/* ===========================
   Eliminar GASTO (POST)
   =========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar_gasto') {
  abortar_si_captura_bloqueada(); // respeta candado
  $gasto_id = (int)($_POST['gasto_id'] ?? 0);

  try {
    $tzDel = new DateTimeZone('America/Mexico_City');
    $inicioDel = (new DateTime('today', $tzDel))->format('Y-m-d 00:00:00');
    $finDel    = (new DateTime('tomorrow', $tzDel))->format('Y-m-d 00:00:00');

    if ($gasto_id > 0) {
      $del = $conn->prepare("
        DELETE FROM gastos_sucursal
        WHERE id = ?
          AND id_sucursal = ?
          AND id_corte IS NULL
          AND fecha_gasto >= ? AND fecha_gasto < ?
        LIMIT 1
      ");
      $del->bind_param('iiss', $gasto_id, $id_sucursal, $inicioDel, $finDel);
      if ($del->execute() && $del->affected_rows > 0) {
        $msg .= "<div class='alert alert-success mb-3'>🗑️ Gasto eliminado.</div>";
      } else {
        $msg .= "<div class='alert alert-warning mb-3'>⚠ No se pudo eliminar (quizá ya está ligado a un corte o no es de hoy).</div>";
      }
      $del->close();
    } else {
      $msg .= "<div class='alert alert-warning mb-3'>⚠ ID de gasto inválido.</div>";
    }
  } catch (Throwable $e) {
    $msg .= "<div class='alert alert-danger mb-3'>❌ Error al eliminar gasto.</div>";
  }
}

/* ===========================
   Procesar COBRO (POST)
   =========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['accion'] ?? '') === '' || ($_POST['accion'] ?? '') === 'guardar_cobro')) {
  // mantener compatibilidad: si no viene 'accion', asumimos guardar cobro

  // valida token (evita reenvíos duplicados)
  $posted_token = $_POST['cobro_token'] ?? '';
  if (!hash_equals($_SESSION['cobro_token'] ?? '', $posted_token)) {
    $msg .= "<div class='alert alert-warning mb-3'>⚠ Sesión expirada o envío duplicado. Recarga la página e intenta de nuevo.</div>";
  } else {
    $motivo         = trim($_POST['motivo'] ?? '');
    $tipo_pago      = $_POST['tipo_pago'] ?? '';
    $monto_total    = (float)($_POST['monto_total'] ?? 0);
    $monto_efectivo = (float)($_POST['monto_efectivo'] ?? 0);
    $monto_tarjeta  = (float)($_POST['monto_tarjeta'] ?? 0);

    // Redondeo seguro
    $monto_total    = round($monto_total, 2);
    $monto_efectivo = round($monto_efectivo, 2);
    $monto_tarjeta  = round($monto_tarjeta, 2);

    // Normaliza por tipo de pago (evita arrastre de valores ocultos)
    switch ($tipo_pago) {
      case 'Efectivo':
        $monto_efectivo = $monto_total;
        $monto_tarjeta  = 0.00;
        break;
      case 'Tarjeta':
        $monto_tarjeta  = $monto_total;
        $monto_efectivo = 0.00;
        break;
      case 'Mixto':
        // se conserva lo capturado (ya redondeado)
        break;
      default:
        $monto_efectivo = 0.00;
        $monto_tarjeta  = 0.00;
    }

    // Comisión especial solo si es Abono PayJoy/Krediya y NO es pago con Tarjeta
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
        try {
          $stmt = $conn->prepare("
            INSERT INTO cobros (
              id_usuario, id_sucursal, motivo, tipo_pago,
              monto_total, monto_efectivo, monto_tarjeta, comision_especial,
              fecha_cobro, id_corte, corte_generado
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NULL, 0)
          ");
          $stmt->bind_param(
            "iissdddd",
            $id_usuario,
            $id_sucursal,
            $motivo,
            $tipo_pago,
            $monto_total,
            $monto_efectivo,
            $monto_tarjeta,
            $comision_especial
          );
          if ($stmt->execute()) {
            $msg .= "<div class='alert alert-success mb-3'>✅ Cobro registrado correctamente.</div>";
            // nuevo token para impedir reenvío del mismo formulario
            $_SESSION['cobro_token'] = bin2hex(random_bytes(16));
            // limpia POST para no repoblar inputs
            $_POST = [];
          } else {
            $msg .= "<div class='alert alert-danger mb-3'>❌ Error al registrar cobro.</div>";
          }
          $stmt->close();
        } catch (Throwable $e) {
          $msg .= "<div class='alert alert-danger mb-3'>❌ Error al registrar cobro.</div>";
        }
      }
    }
  }
}

/* ===========================
   Rango HOY por sucursal
   =========================== */
$tz = new DateTimeZone('America/Mexico_City');
$inicio = (new DateTime('today', $tz))->format('Y-m-d 00:00:00');
$fin    = (new DateTime('tomorrow', $tz))->format('Y-m-d 00:00:00');

/* ===========================
   Cobros de HOY
   =========================== */
$cobros_hoy = [];
$tot_total = $tot_efectivo = $tot_tarjeta = $tot_comision = 0.0;

try {
  $sql = "
    SELECT c.id, c.fecha_cobro, c.motivo, c.tipo_pago,
           c.monto_total, c.monto_efectivo, c.monto_tarjeta, c.comision_especial,
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
} catch (Throwable $e) {
  // Si hay error, dejamos la tabla vacía silenciosamente
}

/* ===========================
   GASTOS de HOY por sucursal
   =========================== */
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
} catch (Throwable $e) {
  // silencioso
}

/* ===========================
   Cálculo de "efectivo neto del día"
   =========================== */
$efectivo_neto_hoy = max(0.0, $tot_efectivo - $tot_gastos_hoy);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Registrar Cobro</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap 5 -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    .page-hero {
      background: linear-gradient(135deg, #0ea5e9 0%, #22c55e 100%);
      color: #fff;
      border-radius: 16px;
      padding: 20px;
      box-shadow: 0 8px 24px rgba(2, 6, 23, .15)
    }
    .card-soft {
      border: 1px solid rgba(0, 0, 0, .06);
      border-radius: 16px;
      box-shadow: 0 8px 24px rgba(2, 6, 23, .06)
    }
    .label-req::after { content: " *"; color: #ef4444; font-weight: 700 }
    .form-help { font-size: .9rem; color: #64748b }
    .summary-row {
      display: flex; justify-content: space-between; align-items: center;
      padding: .35rem 0; border-bottom: 1px dashed #e2e8f0; font-size: .95rem
    }
    .summary-row:last-child { border-bottom: 0 }
    .currency-prefix { min-width: 44px }
    .sticky-actions {
      position: sticky; bottom: 0; background: #fff; padding-top: .5rem;
      margin-top: 1rem; border-top: 1px solid #e2e8f0
    }
    .table thead th { white-space: nowrap }
    .badge-soft { background: #eef2ff; color: #3730a3 }
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
          <div class="opacity-75">Captura rápida y validada • <?= htmlspecialchars($nombre_sucursal, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
      </div>
    </div>

    <?= $msg ?>

    <div class="row g-4">
      <!-- Columna izquierda: formulario -->
      <div class="col-12 col-lg-7">
        <form method="POST" class="card card-soft p-3 p-md-4" id="formCobro" novalidate>
          <input type="hidden" name="accion" value="guardar_cobro">
          <input type="hidden" name="cobro_token" value="<?= htmlspecialchars($_SESSION['cobro_token'], ENT_QUOTES, 'UTF-8') ?>">
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
                  <option value="Tarjeta" <?= (($_POST['tipo_pago'] ?? '') === 'Tarjeta') ? 'selected' : '';  ?>>Tarjeta</option>
                  <option value="Mixto" <?= (($_POST['tipo_pago'] ?? '') === 'Mixto') ? 'selected' : '';    ?>>Mixto</option>
                </select>
              </div>
              <div class="col-12 col-sm-6">
                <div class="input-group">
                  <span class="input-group-text currency-prefix">$</span>
                  <input type="number" step="0.01" min="0" name="monto_total" id="monto_total"
                    class="form-control" placeholder="0.00" required
                    value="<?= htmlspecialchars((string)($_POST['monto_total'] ?? '')) ?>">
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
                  value="<?= htmlspecialchars((string)($_POST['monto_efectivo'] ?? '')) ?>">
              </div>
            </div>

            <div class="col-12 col-md-6 pago-tarjeta d-none">
              <label class="form-label"><i class="bi bi-credit-card me-1"></i>Monto con tarjeta</label>
              <div class="input-group">
                <span class="input-group-text currency-prefix">$</span>
                <input type="number" step="0.01" min="0" name="monto_tarjeta" id="monto_tarjeta"
                  class="form-control" placeholder="0.00"
                  value="<?= htmlspecialchars((string)($_POST['monto_tarjeta'] ?? '')) ?>">
              </div>
            </div>
          </div>

          <div class="mt-3 small text-muted">
            Los importes deben cuadrar con el tipo de pago: efectivo = total, tarjeta = total, mixto = efectivo + tarjeta = total.
          </div>

          <div class="sticky-actions">
            <div class="d-grid mt-3">
              <button type="submit" class="btn btn-success btn-lg"
                <?= $lock ? 'disabled' : '' ?>>
                <i class="bi bi-save me-2"></i>Guardar Cobro
              </button>
            </div>
            <?php if ($lock): ?>
              <div class="text-center text-muted mt-2">
                <i class="bi bi-info-circle me-1"></i>El administrador habilitará la captura pronto.
              </div>
            <?php endif; ?>
          </div>
        </form>

        <!-- === GASTOS DE LA SUCURSAL (captura + listado del día) === -->
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
                  <th>Hora</th>
                  <th>Categoría</th>
                  <th>Concepto</th>
                  <th class="text-end">Monto</th>
                  <th style="width:110px">Acciones</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($gastos_hoy)): ?>
                  <tr><td colspan="5" class="text-muted text-center">Sin gastos capturados hoy.</td></tr>
                <?php else: foreach ($gastos_hoy as $g): ?>
                  <tr>
                    <td><?= htmlspecialchars((new DateTime($g['fecha_gasto']))->format('H:i')) ?></td>
                    <td><?= htmlspecialchars($g['categoria']) ?></td>
                    <td>
                      <?= htmlspecialchars($g['concepto']) ?>
                      <?php if (!empty($g['observaciones'])): ?>
                        <div class="small text-muted"><?= htmlspecialchars($g['observaciones']) ?></div>
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

      <!-- Columna derecha: resumen dinámico -->
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

        <!-- Resumen del día (efectivo vs gastos) -->
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

    <!-- ===================== -->
    <!-- Cobros de hoy (tabla) -->
    <!-- ===================== -->
    <div class="card card-soft p-3 p-md-4 mt-4">
      <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
        <h5 class="mb-2 mb-sm-0">
          Cobros de hoy — <span class="badge badge-soft"><?= htmlspecialchars($nombre_sucursal, ENT_QUOTES, 'UTF-8') ?></span>
        </h5>
        <div class="d-flex gap-2">
          <input type="text" id="filtroTabla" class="form-control" placeholder="Buscar en tabla (motivo, usuario, tipo)" />
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle" id="tablaCobros">
          <thead class="table-light">
            <tr>
              <th>Hora</th>
              <th>Usuario</th>
              <th>Motivo</th>
              <th>Tipo de pago</th>
              <th class="text-end">Total</th>
              <th class="text-end">Efectivo</th>
              <th class="text-end">Tarjeta</th>
              <th class="text-end">Comisión</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($cobros_hoy) === 0): ?>
              <tr>
                <td colspan="8" class="text-center text-muted">Sin cobros registrados hoy en esta sucursal.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($cobros_hoy as $r): ?>
                <tr>
                  <td><?= htmlspecialchars((new DateTime($r['fecha_cobro']))->format('H:i')) ?></td>
                  <td><?= htmlspecialchars($r['usuario'] ?? '') ?></td>
                  <td><?= htmlspecialchars($r['motivo'] ?? '') ?></td>
                  <td><?= htmlspecialchars($r['tipo_pago'] ?? '') ?></td>
                  <td class="text-end"><?= number_format((float)$r['monto_total'], 2) ?></td>
                  <td class="text-end"><?= number_format((float)$r['monto_efectivo'], 2) ?></td>
                  <td class="text-end"><?= number_format((float)$r['monto_tarjeta'], 2) ?></td>
                  <td class="text-end"><?= number_format((float)$r['comision_especial'], 2) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
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
      <div class="small text-muted">Ventana: hoy <?= htmlspecialchars((new DateTime('today', $tz))->format('d/m/Y')) ?> — registros más recientes primero (máx. 100).</div>
    </div>

  </div>

  <!-- JS -->
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script>
    (function() {
      // === Lógica de formulario (resumen + validación) ===
      const $motivo = $("#motivo"),
            $tipo = $("#tipo_pago"),
            $total = $("#monto_total"),
            $efectivo = $("#monto_efectivo"),
            $tarjeta = $("#monto_tarjeta");
      const fmt = n => "$" + (isFinite(n) ? Number(n) : 0).toFixed(2);

      function toggleCampos() {
        const t = $tipo.val();

        // Mostrar/ocultar secciones
        $(".pago-efectivo, .pago-tarjeta").addClass("d-none");
        if (t === "Efectivo") $(".pago-efectivo").removeClass("d-none");
        if (t === "Tarjeta")  $(".pago-tarjeta").removeClass("d-none");
        if (t === "Mixto")    $(".pago-efectivo, .pago-tarjeta").removeClass("d-none");

        // Deshabilita y limpia inputs que NO aplican (si están disabled NO se envían)
        if (t === "Efectivo") {
          $tarjeta.prop("disabled", true).val("");
          $efectivo.prop("disabled", false);
        } else if (t === "Tarjeta") {
          $efectivo.prop("disabled", true).val("");
          $tarjeta.prop("disabled", false);
        } else if (t === "Mixto") {
          $efectivo.prop("disabled", false);
          $tarjeta.prop("disabled", false);
        } else {
          $efectivo.prop("disabled", true).val("");
          $tarjeta.prop("disabled", true).val("");
        }

        validar();
      }

      // Comisión especial: solo si es Abono PayJoy/Krediya y NO es tarjeta
      function comisionEspecial(m, t) {
        return ((m === "Abono PayJoy" || m === "Abono Krediya") && t !== "Tarjeta") ? 10 : 0;
      }

      function validar() {
        const m = ($motivo.val() || "").trim(),
              t = $tipo.val() || "",
              tot = parseFloat($total.val() || 0) || 0,
              ef  = parseFloat($efectivo.val() || 0) || 0,
              tj  = parseFloat($tarjeta.val() || 0) || 0,
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
      toggleCampos();
      validar();

      // === Filtro rápido en tabla de cobros de hoy ===
      $("#filtroTabla").on("input", function() {
        const q = $(this).val().toLowerCase();
        $("#tablaCobros tbody tr").each(function() {
          const t = $(this).text().toLowerCase();
          $(this).toggle(t.indexOf(q) !== -1);
        });
      });
    })();
  </script>
</body>
</html>
