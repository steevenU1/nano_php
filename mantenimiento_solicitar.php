<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: 403.php"); exit(); }

$ROL = $_SESSION['rol'] ?? '';
$ALLOWED = ['Admin','GerenteZona','GerenteSucursal','Gerente']; // ajusta a tus roles
if (!in_array($ROL, $ALLOWED, true)) { header("Location: 403.php"); exit(); }

require_once __DIR__.'/db.php';

$idUsuario   = (int)$_SESSION['id_usuario'];
$idSucursal  = (int)($_SESSION['id_sucursal'] ?? 0);

// 🔹 Nombre de la sucursal (si viene en sesión; si no, lo consultamos)
$sucursalNombre = $_SESSION['sucursal_nombre'] ?? '';
if ($idSucursal > 0 && $sucursalNombre === '') {
  $stNom = $conn->prepare("SELECT nombre FROM sucursales WHERE id=? LIMIT 1");
  $stNom->bind_param("i", $idSucursal);
  $stNom->execute();
  $stNom->bind_result($sucursalNombre);
  $stNom->fetch();
  $stNom->close();
}

// --- Config de uploads (se crea si no existe)
$UPLOAD_DIR = __DIR__.'/uploads/mantenimiento';
$URL_BASE   = 'uploads/mantenimiento'; // para servir estático
if (!is_dir($UPLOAD_DIR)) { @mkdir($UPLOAD_DIR, 0775, true); }

$ALLOWED_MIME = ['image/jpeg','image/png','image/webp','application/pdf'];
$MAX_PER_FILE = 10 * 1024 * 1024;   // 10MB
$MAX_FILES    = 6;

$msgOK = '';
$msgErr = '';

/* ============================
   PROCESAR POST ANTES DE IMPRIMIR HTML (PRG)
   ============================ */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='crear') {
  $categoria   = trim($_POST['categoria'] ?? 'Otro');
  $titulo      = trim($_POST['titulo'] ?? '');
  $descripcion = trim($_POST['descripcion'] ?? '');
  $prioridad   = $_POST['prioridad'] ?? 'Media';
  $contacto    = trim($_POST['contacto'] ?? '');

  // si no tienes id_sucursal en sesión, viene por POST
  $idSucursalForm = (int)($_POST['id_sucursal'] ?? $idSucursal);
  if ($idSucursalForm > 0) { $idSucursal = $idSucursalForm; }

  if (!$titulo || !$descripcion || !$idSucursal) {
    $_SESSION['flash_err'] = "Faltan datos obligatorios (Sucursal, Título, Descripción).";
    header("Location: mantenimiento_solicitar.php");
    exit;
  }

  // Insertar solicitud
  $stmt = $conn->prepare("INSERT INTO mantenimiento_solicitudes
    (id_sucursal,id_usuario,categoria,titulo,descripcion,prioridad,contacto)
    VALUES (?,?,?,?,?,?,?)");
  $stmt->bind_param("iisssss", $idSucursal, $idUsuario, $categoria, $titulo, $descripcion, $prioridad, $contacto);

  if ($stmt->execute()) {
    $idSol = $stmt->insert_id;
    $stmt->close();

    // Adjuntos
    $filesGuardados = 0;
    if (!empty($_FILES['adjuntos']['name'][0])) {
      $c = min(count($_FILES['adjuntos']['name']), $MAX_FILES);
      for ($i=0; $i<$c; $i++) {
        $name = $_FILES['adjuntos']['name'][$i];
        $tmp  = $_FILES['adjuntos']['tmp_name'][$i];
        $size = (int)$_FILES['adjuntos']['size'][$i];

        if (!$tmp || !is_uploaded_file($tmp)) continue;
        $type = function_exists('mime_content_type') ? (mime_content_type($tmp) ?: ($_FILES['adjuntos']['type'][$i] ?? '')) : ($_FILES['adjuntos']['type'][$i] ?? '');
        if ($size <= 0 || $size > $MAX_PER_FILE) continue;
        if (!in_array($type, $ALLOWED_MIME, true)) continue;

        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $safeName = preg_replace('/[^a-zA-Z0-9_\.-]/','_', pathinfo($name, PATHINFO_FILENAME));
        $destFile = $safeName.'__'.date('Ymd_His').'__'.bin2hex(random_bytes(3)).'.'.$ext;

        $dirSol = $UPLOAD_DIR.'/sol_'.$idSol;
        if (!is_dir($dirSol)) { @mkdir($dirSol, 0775, true); }

        $destPath = $dirSol.'/'.$destFile;
        if (move_uploaded_file($tmp, $destPath)) {
          $rutaRel = $URL_BASE.'/sol_'.$idSol.'/'.$destFile;

          $stA = $conn->prepare("INSERT INTO mantenimiento_adjuntos
            (id_solicitud,nombre_archivo,ruta_relativa,mime_type,tam_bytes,subido_por)
            VALUES (?,?,?,?,?,?)");
          $stA->bind_param("isssii", $idSol, $name, $rutaRel, $type, $size, $idUsuario);
          $stA->execute();
          $stA->close();

          $filesGuardados++;
        }
      }
    }

    $_SESSION['flash_ok'] = "Solicitud #$idSol creada correctamente".($filesGuardados? " ({$filesGuardados} adjunto(s))." : ".");
    // PRG
    header("Location: mantenimiento_solicitar.php");
    exit;

  } else {
    $stmt->close();
    $_SESSION['flash_err'] = "Error al guardar la solicitud.";
    header("Location: mantenimiento_solicitar.php");
    exit;
  }
}

// Trae mensajes flash (si los hay)
if (isset($_SESSION['flash_ok']))  { $msgOK  = $_SESSION['flash_ok'];  unset($_SESSION['flash_ok']); }
if (isset($_SESSION['flash_err'])) { $msgErr = $_SESSION['flash_err']; unset($_SESSION['flash_err']); }

/* ============================
   A PARTIR DE AQUÍ YA PODEMOS IMPRIMIR HTML
   ============================ */
require_once __DIR__.'/navbar.php';

// Catálogo de sucursales (si quieres permitir elegir cuando NO hay id en sesión)
$catSuc = $conn->query("SELECT id, nombre FROM sucursales WHERE tipo_sucursal='Tienda' ORDER BY nombre");

// Mis solicitudes
$stList = $conn->prepare("
  SELECT ms.*, s.nombre AS sucursal
  FROM mantenimiento_solicitudes ms
  INNER JOIN sucursales s ON s.id = ms.id_sucursal
  WHERE ms.id_usuario = ?
  ORDER BY ms.fecha_solicitud DESC
  LIMIT 200
");
$stList->bind_param("i", $idUsuario);
$stList->execute();
$resList = $stList->get_result();

function badgeEstatus($e) {
  switch ($e) {
    case 'Nueva': return 'secondary';
    case 'En revisión': return 'info';
    case 'Aprobada': return 'primary';
    case 'En proceso': return 'warning';
    case 'Resuelta': return 'success';
    case 'Rechazada': return 'danger';
    default: return 'secondary';
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Mantenimiento - Solicitudes</title>
  <!-- <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"> -->
</head>
<body class="bg-light">

<div class="container mt-4">
  <h2>🛠️ Solicitudes de Mantenimiento</h2>
  <p class="text-muted">Levanta una solicitud y adjunta evidencias (fotos/PDF). Máx. <?= $MAX_FILES ?> archivos, 10MB c/u.</p>

  <?php if ($msgOK): ?>
    <div class="alert alert-success"><?= htmlspecialchars($msgOK) ?></div>
  <?php endif; ?>
  <?php if ($msgErr): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($msgErr) ?></div>
  <?php endif; ?>

  <div class="card shadow mb-4">
    <div class="card-header bg-dark text-white">Nueva solicitud</div>
    <div class="card-body">
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="crear">
        <div class="row g-3">
          <?php if (!$idSucursal): ?>
          <!-- Cuando NO hay sucursal en sesión, que elija -->
          <div class="col-md-4">
            <label class="form-label">Sucursal</label>
            <select name="id_sucursal" class="form-select" required>
              <option value="">Selecciona...</option>
              <?php while($s=$catSuc->fetch_assoc()): ?>
                <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['nombre']) ?></option>
              <?php endwhile; ?>
            </select>
          </div>
          <?php else: ?>
          <!-- Cuando sí hay sucursal en sesión, solo mostramos el nombre -->
          <div class="col-md-4">
            <label class="form-label">Sucursal</label>
            <input class="form-control" value="<?= htmlspecialchars($sucursalNombre) ?>" readonly>
          </div>
          <?php endif; ?>

          <div class="col-md-4">
            <label class="form-label">Categoría</label>
            <select name="categoria" class="form-select" required>
              <option>Fachada</option>
              <option>Electricidad</option>
              <option>Plomería</option>
              <option>Limpieza</option>
              <option>Seguridad</option>
              <option>Infraestructura</option>
              <option>Otro</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Prioridad</label>
            <select name="prioridad" class="form-select" required>
              <option>Media</option>
              <option>Baja</option>
              <option>Alta</option>
            </select>
          </div>

          <div class="col-md-8">
            <label class="form-label">Título</label>
            <input type="text" name="titulo" class="form-control" maxlength="150" required placeholder="Ej. Pintura de fachada dañada">
          </div>
          <div class="col-md-4">
            <label class="form-label">Contacto en sucursal (opcional)</label>
            <input type="text" name="contacto" class="form-control" maxlength="120" placeholder="Nombre y/o teléfono">
          </div>

          <div class="col-12">
            <label class="form-label">Descripción</label>
            <textarea name="descripcion" class="form-control" rows="4" required
              placeholder="Describe el problema, ubicación dentro de la sucursal, horarios, etc."></textarea>
          </div>

          <div class="col-12">
            <label class="form-label">Adjuntos (imágenes o PDF)</label>
            <input type="file" name="adjuntos[]" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf" multiple>
            <div class="form-text">Máximo <?= $MAX_FILES ?> archivos, cada uno hasta <?= (int)($MAX_PER_FILE/1024/1024) ?>MB.</div>
          </div>

          <div class="col-12 text-end">
            <button class="btn btn-primary">Enviar solicitud</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- Mis solicitudes -->
  <div class="card shadow">
    <div class="card-header bg-primary text-white">Mis últimas solicitudes</div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-striped table-hover table-sm mb-0">
          <thead class="table-dark">
            <tr>
              <th>ID</th><th>Sucursal</th><th>Categoría</th><th>Título</th>
              <th>Prioridad</th><th>Estatus</th><th>Fecha</th><th>Adjuntos</th>
            </tr>
          </thead>
          <tbody>
          <?php while($row = $resList->fetch_assoc()): ?>
            <tr>
              <td>#<?= (int)$row['id'] ?></td>
              <td><?= htmlspecialchars($row['sucursal']) ?></td>
              <td><?= htmlspecialchars($row['categoria']) ?></td>
              <td><?= htmlspecialchars($row['titulo']) ?></td>
              <td><span class="badge bg-<?= $row['prioridad']=='Alta'?'danger':($row['prioridad']=='Media'?'warning text-dark':'secondary') ?>">
                <?= $row['prioridad'] ?></span></td>
              <td><span class="badge bg-<?= badgeEstatus($row['estatus']) ?>"><?= $row['estatus'] ?></span></td>
              <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($row['fecha_solicitud']))) ?></td>
              <td>
                <?php
                  $files = $conn->query("SELECT ruta_relativa, nombre_archivo, mime_type FROM mantenimiento_adjuntos WHERE id_solicitud=".(int)$row['id']." ORDER BY id");
                  if ($files && $files->num_rows>0) {
                    while($f=$files->fetch_assoc()){
                      $isImg = isset($f['mime_type']) && strpos($f['mime_type'], 'image/')===0;
                      $txt = $isImg ? 'Ver' : 'Descargar';
                      echo '<a class="btn btn-outline-secondary btn-sm me-1" target="_blank" href="'.htmlspecialchars($f['ruta_relativa']).'">'.$txt.'</a>';
                    }
                  } else {
                    echo '<span class="text-muted">—</span>';
                  }
                ?>
              </td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>
</body>
</html>
