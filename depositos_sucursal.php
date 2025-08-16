<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Gerente','Admin'])) {
    header("Location: 403.php"); exit();
}

include 'db.php';
include 'navbar.php';

$idUsuario  = (int)$_SESSION['id_usuario'];
$idSucursal = (int)$_SESSION['id_sucursal'];
$rolUsuario = $_SESSION['rol'];

$msg = '';
$MAX_BYTES = 10 * 1024 * 1024; // 10MB
$ALLOWED   = [
  'application/pdf' => 'pdf',
  'image/jpeg'      => 'jpg',
  'image/png'       => 'png',
];

/* ------- helper: guardar comprobante para un depósito ------- */
function guardar_comprobante(mysqli $conn, int $deposito_id, array $file, int $idUsuario, int $MAX_BYTES, array $ALLOWED, &$errMsg): bool {
  if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
    $errMsg = 'Debes adjuntar el comprobante.'; return false;
  }
  if ($file['error'] !== UPLOAD_ERR_OK) {
    $errMsg = 'Error al subir archivo (código '.$file['error'].').'; return false;
  }
  if ($file['size'] <= 0 || $file['size'] > $MAX_BYTES) {
    $errMsg = 'El archivo excede 10 MB o está vacío.'; return false;
  }

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime  = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';
  if (!isset($ALLOWED[$mime])) {
    $errMsg = 'Tipo de archivo no permitido. Solo PDF/JPG/PNG.'; return false;
  }
  $ext = $ALLOWED[$mime];

  // Carpeta destino
  $baseDir = __DIR__ . '/uploads/depositos/' . $deposito_id;
  if (!is_dir($baseDir)) {
    @mkdir($baseDir, 0775, true);
    if (!file_exists($baseDir.'/.htaccess')) {
      file_put_contents($baseDir.'/.htaccess', "Options -Indexes\n<FilesMatch \"\\.(php|phar|phtml|shtml|cgi|pl)$\">\nDeny from all\n</FilesMatch>\n");
    }
  }

  $storedName = 'comprobante.' . $ext;
  $fullPath   = $baseDir . '/' . $storedName;
  if (file_exists($fullPath)) @unlink($fullPath);

  if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
    $errMsg = 'No se pudo guardar el archivo en el servidor.'; return false;
  }

  $relPath = 'uploads/depositos/' . $deposito_id . '/' . $storedName;
  $orig    = substr(basename($file['name']), 0, 200);

  $stmt = $conn->prepare("
    UPDATE depositos_sucursal SET
      comprobante_archivo = ?, comprobante_nombre = ?, comprobante_mime = ?,
      comprobante_size = ?, comprobante_subido_en = NOW(), comprobante_subido_por = ?
    WHERE id = ?
  ");
  $size = (int)$file['size'];
  $stmt->bind_param('sssiii', $relPath, $orig, $mime, $size, $idUsuario, $deposito_id);
  $ok = $stmt->execute();
  $stmt->close();

  if (!$ok) {
    @unlink($fullPath);
    $errMsg = 'Error al actualizar el depósito con el comprobante.';
    return false;
  }
  return true;
}

/* ------- Registrar DEPÓSITO (AHORA con comprobante OBLIGATORIO) ------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion']==='registrar') {
  $id_corte        = (int)($_POST['id_corte'] ?? 0);
  $fecha_deposito  = $_POST['fecha_deposito'] ?? date('Y-m-d');
  $banco           = trim($_POST['banco'] ?? '');
  $monto           = (float)($_POST['monto_depositado'] ?? 0);
  $referencia      = trim($_POST['referencia'] ?? '');
  $motivo          = trim($_POST['motivo'] ?? '');

  // 1) Validar archivo obligatorio (antes de tocar BD)
  if (!isset($_FILES['comprobante']) || $_FILES['comprobante']['error'] === UPLOAD_ERR_NO_FILE) {
    $msg = "<div class='alert alert-warning'>⚠ Debes adjuntar el comprobante del depósito.</div>";
  } elseif ($_FILES['comprobante']['error'] !== UPLOAD_ERR_OK) {
    $msg = "<div class='alert alert-danger'>❌ Error al subir el archivo (código ".$_FILES['comprobante']['error'].").</div>";
  } elseif ($_FILES['comprobante']['size'] <= 0 || $_FILES['comprobante']['size'] > $MAX_BYTES) {
    $msg = "<div class='alert alert-warning'>⚠ El comprobante debe pesar hasta 10 MB.</div>";
  } else {
    // Validar MIME permitido
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($_FILES['comprobante']['tmp_name']) ?: 'application/octet-stream';
    if (!isset($ALLOWED[$mime])) {
      $msg = "<div class='alert alert-warning'>⚠ Tipo de archivo no permitido. Solo PDF/JPG/PNG.</div>";
    } else {
      // 2) Validar datos y pendiente del corte
      if ($id_corte>0 && $monto>0 && $banco!=='') {
        $sqlCheck = "SELECT cc.total_efectivo, IFNULL(SUM(ds.monto_depositado),0) AS suma_actual
                     FROM cortes_caja cc
                     LEFT JOIN depositos_sucursal ds ON ds.id_corte = cc.id
                     WHERE cc.id = ? GROUP BY cc.id";
        $stmt = $conn->prepare($sqlCheck);
        $stmt->bind_param("i", $id_corte);
        $stmt->execute();
        $corte = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($corte) {
          $pendiente = (float)$corte['total_efectivo'] - (float)$corte['suma_actual'];
          if ($monto > $pendiente + 0.0001) {
            $msg = "<div class='alert alert-danger'>❌ El depósito excede el monto pendiente del corte. Solo queda $".number_format($pendiente,2)."</div>";
          } else {
            // 3) Insertar y adjuntar (si adjuntar falla, revertimos)
            $stmtIns = $conn->prepare("
              INSERT INTO depositos_sucursal
                (id_sucursal, id_corte, fecha_deposito, monto_depositado, banco, referencia, observaciones, estado, creado_en)
              VALUES (?, ?, ?, ?, ?, ?, ?, 'Pendiente', NOW())
            ");
            $stmtIns->bind_param("iisddss", $idSucursal, $id_corte, $fecha_deposito, $monto, $banco, $referencia, $motivo);
            if ($stmtIns->execute()) {
              $deposito_id = $stmtIns->insert_id;
              $stmtIns->close();

              $errUp = '';
              if (guardar_comprobante($conn, $deposito_id, $_FILES['comprobante'], $idUsuario, $MAX_BYTES, $ALLOWED, $errUp)) {
                $msg = "<div class='alert alert-success'>✅ Depósito registrado y comprobante adjuntado.</div>";
              } else {
                // revertir
                $del = $conn->prepare("DELETE FROM depositos_sucursal WHERE id=?");
                $del->bind_param('i', $deposito_id);
                $del->execute();
                $del->close();
                $msg = "<div class='alert alert-danger'>❌ No se guardó el depósito porque falló el comprobante: ".htmlspecialchars($errUp)."</div>";
              }
            } else {
              $msg = "<div class='alert alert-danger'>❌ Error al registrar depósito.</div>";
            }
          }
        } else {
          $msg = "<div class='alert alert-danger'>❌ Corte no encontrado.</div>";
        }
      } else {
        $msg = "<div class='alert alert-warning'>⚠ Debes llenar todos los campos obligatorios.</div>";
      }
    }
  }
}

/* ------- Subir/Reemplazar comprobante DESDE historial (seguimos permitiendo) ------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion']==='subir_comprobante') {
  $deposito_id = (int)($_POST['deposito_id'] ?? 0);

  $stmt = $conn->prepare("SELECT id_sucursal, estado FROM depositos_sucursal WHERE id=?");
  $stmt->bind_param('i', $deposito_id);
  $stmt->execute();
  $dep = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$dep) {
    $msg = "<div class='alert alert-danger'>❌ Depósito no encontrado.</div>";
  } elseif ($rolUsuario!=='Admin' && (int)$dep['id_sucursal'] !== $idSucursal) {
    $msg = "<div class='alert alert-danger'>❌ No tienes permiso para adjuntar a este depósito.</div>";
  } elseif (!in_array($dep['estado'], ['Pendiente','Parcial'], true)) {
    $msg = "<div class='alert alert-warning'>⚠ No se puede modificar un depósito ya validado.</div>";
  } else {
    $errUp = '';
    if (guardar_comprobante($conn, $deposito_id, $_FILES['comprobante'] ?? [], $idUsuario, $MAX_BYTES, $ALLOWED, $errUp)) {
      $msg = "<div class='alert alert-success'>✅ Comprobante adjuntado.</div>";
    } else {
      $msg = "<div class='alert alert-danger'>❌ ".$errUp."</div>";
    }
  }
}

/* ------- Consultas para render ------- */
// Cortes pendientes
$sqlPendientes = "
  SELECT cc.id, cc.fecha_corte, cc.total_efectivo,
         IFNULL(SUM(ds.monto_depositado),0) AS total_depositado
  FROM cortes_caja cc
  LEFT JOIN depositos_sucursal ds ON ds.id_corte = cc.id
  WHERE cc.id_sucursal = ? AND cc.estado='Pendiente'
  GROUP BY cc.id
  ORDER BY cc.fecha_corte ASC";
$stmtPend = $conn->prepare($sqlPendientes);
$stmtPend->bind_param("i", $idSucursal);
$stmtPend->execute();
$cortesPendientes = $stmtPend->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtPend->close();

// Historial
$sqlHistorial = "
  SELECT ds.*, cc.fecha_corte
  FROM depositos_sucursal ds
  INNER JOIN cortes_caja cc ON cc.id = ds.id_corte
  WHERE ds.id_sucursal = ?
  ORDER BY ds.fecha_deposito DESC, ds.id DESC";
$stmtHist = $conn->prepare($sqlHistorial);
$stmtHist->bind_param("i", $idSucursal);
$stmtHist->execute();
$historial = $stmtHist->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtHist->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Depósitos Sucursal</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container mt-4">
  <h2>🏦 Depósitos Bancarios - <?= htmlspecialchars($_SESSION['nombre']) ?> (<?= htmlspecialchars($rolUsuario) ?>)</h2>
  <?= $msg ?>

  <h4 class="mt-4">Cortes pendientes de depósito</h4>
  <?php if (count($cortesPendientes) == 0): ?>
    <div class="alert alert-info">No hay cortes pendientes de depósito.</div>
  <?php else: ?>
    <table class="table table-bordered table-sm align-middle">
      <thead class="table-dark">
        <tr>
          <th>ID Corte</th>
          <th>Fecha Corte</th>
          <th>Efectivo a Depositar</th>
          <th>Total Depositado</th>
          <th>Pendiente</th>
          <th>Registrar Depósito</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($cortesPendientes as $c):
          $pendiente = $c['total_efectivo'] - $c['total_depositado']; ?>
          <tr>
            <td><?= (int)$c['id'] ?></td>
            <td><?= htmlspecialchars($c['fecha_corte']) ?></td>
            <td>$<?= number_format($c['total_efectivo'],2) ?></td>
            <td>$<?= number_format($c['total_depositado'],2) ?></td>
            <td class="fw-bold text-danger">$<?= number_format($pendiente,2) ?></td>
            <td>
              <form method="POST" class="row g-2" enctype="multipart/form-data">
                <input type="hidden" name="accion" value="registrar">
                <input type="hidden" name="id_corte" value="<?= (int)$c['id'] ?>">
                <div class="col-md-3">
                  <input type="date" name="fecha_deposito" class="form-control" required>
                </div>
                <div class="col-md-2">
                  <input type="number" step="0.01" name="monto_depositado" class="form-control" placeholder="Monto" required>
                </div>
                <div class="col-md-2">
                  <input type="text" name="banco" class="form-control" placeholder="Banco" required>
                </div>
                <div class="col-md-2">
                  <input type="text" name="referencia" class="form-control" placeholder="Referencia">
                </div>
                <div class="col-md-3">
                  <input type="text" name="motivo" class="form-control" placeholder="Motivo (opcional)">
                </div>
                <div class="col-md-12">
                  <!-- OBLIGATORIO -->
                  <input type="file" name="comprobante" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png" required>
                  <small class="text-muted">Adjunta comprobante (PDF/JPG/PNG, máx 10MB)</small>
                </div>
                <div class="col-md-12">
                  <button class="btn btn-success btn-sm w-100">💾 Guardar</button>
                </div>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <h4 class="mt-4">Historial de Depósitos</h4>
  <table class="table table-bordered table-sm align-middle">
    <thead class="table-dark">
      <tr>
        <th>ID Depósito</th>
        <th>ID Corte</th>
        <th>Fecha Corte</th>
        <th>Fecha Depósito</th>
        <th>Monto</th>
        <th>Banco</th>
        <th>Referencia</th>
        <th>Comprobante</th>
        <th>Estado</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($historial as $h): ?>
        <tr class="<?= $h['estado']=='Validado'?'table-success':'table-warning' ?>">
          <td><?= (int)$h['id'] ?></td>
          <td><?= (int)$h['id_corte'] ?></td>
          <td><?= htmlspecialchars($h['fecha_corte']) ?></td>
          <td><?= htmlspecialchars($h['fecha_deposito']) ?></td>
          <td>$<?= number_format($h['monto_depositado'],2) ?></td>
          <td><?= htmlspecialchars($h['banco']) ?></td>
          <td><?= htmlspecialchars($h['referencia']) ?></td>
          <td>
            <?php if (!empty($h['comprobante_archivo'])): ?>
              <a class="btn btn-primary btn-sm" target="_blank" href="deposito_comprobante.php?id=<?= (int)$h['id'] ?>">Ver</a>
              <?php if (in_array($h['estado'], ['Pendiente','Parcial'], true)): ?>
                <small class="text-muted d-block">Puedes reemplazarlo abajo.</small>
              <?php endif; ?>
            <?php endif; ?>

            <?php if (in_array($h['estado'], ['Pendiente','Parcial'], true)): ?>
              <form class="mt-1" method="post" enctype="multipart/form-data">
                <input type="hidden" name="accion" value="subir_comprobante">
                <input type="hidden" name="deposito_id" value="<?= (int)$h['id'] ?>">
                <div class="input-group input-group-sm">
                  <input type="file" name="comprobante" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
                  <button class="btn btn-outline-<?= empty($h['comprobante_archivo']) ? 'success' : 'warning' ?>">
                    <?= empty($h['comprobante_archivo']) ? 'Subir' : 'Reemplazar' ?>
                  </button>
                </div>
              </form>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars($h['estado']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
</body>
</html>
