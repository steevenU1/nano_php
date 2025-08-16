<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: 403.php"); exit(); }
$ROL = $_SESSION['rol'] ?? '';
if (!in_array($ROL, ['Admin','Operaciones','Soporte','GerenteZona'], true)) { header("Location: 403.php"); exit(); }

require_once __DIR__.'/db.php';

$msg = '';

// ==== Actualizar estatus (sin redirect) ====
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='cambiar') {
  $id  = (int)($_POST['id'] ?? 0);
  $st  = $_POST['estatus'] ?? 'En revisión';
  if ($id>0) {
    $stmt = $conn->prepare("UPDATE mantenimiento_solicitudes SET estatus=?, fecha_actualizacion=NOW() WHERE id=?");
    $stmt->bind_param("si", $st, $id);
    $stmt->execute();
    $stmt->close();
    $msg = "Estatus actualizado (#$id → $st)";
  }
}

require_once __DIR__.'/navbar.php';

// ==== Solicitudes (máx 300) ====
$solicitudes = [];
$res = $conn->query("
 SELECT ms.*, s.nombre AS sucursal, u.nombre AS solicitante
 FROM mantenimiento_solicitudes ms
 INNER JOIN sucursales s ON s.id=ms.id_sucursal
 INNER JOIN usuarios u ON u.id=ms.id_usuario
 ORDER BY ms.fecha_solicitud DESC
 LIMIT 300
");
while ($r = $res->fetch_assoc()) { $solicitudes[] = $r; }

// ==== Adjuntos para TODAS las solicitudes en un solo query ====
$adjuntosBySolicitud = [];
if (!empty($solicitudes)) {
  $ids = array_map(fn($x)=>(int)$x['id'], $solicitudes);
  $in  = implode(',', $ids); // ints -> seguro
  $qr  = $conn->query("SELECT id_solicitud, ruta_relativa, nombre_archivo, mime_type FROM mantenimiento_adjuntos WHERE id_solicitud IN ($in) ORDER BY id");
  while ($f = $qr->fetch_assoc()) {
    $sid = (int)$f['id_solicitud'];
    if (!isset($adjuntosBySolicitud[$sid])) $adjuntosBySolicitud[$sid] = [];
    $adjuntosBySolicitud[$sid][] = $f;
  }
}

function badgeEstatus($e) {
  return match ($e) {
    'Nueva' => 'secondary',
    'En revisión' => 'info',
    'Aprobada' => 'primary',
    'En proceso' => 'warning',
    'Resuelta' => 'success',
    'Rechazada' => 'danger',
    default => 'secondary',
  };
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Mantenimiento - Administración</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <style>
    .thumb { width:50px; height:50px; object-fit:cover; border-radius:4px; border:1px solid #ddd; }
    .thumb-wrap { display:inline-block; margin-right:6px; margin-bottom:4px; }
    .file-chip { font-size:.8rem; }
  </style>
</head>
<body class="bg-light">
<div class="container mt-4">
  <h2>🧰 Administración de Solicitudes de Mantenimiento</h2>
  <?php if($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

  <div class="table-responsive card shadow">
    <table class="table table-striped table-hover mb-0 align-middle">
      <thead class="table-dark">
        <tr>
          <th>ID</th><th>Sucursal</th><th>Solicitante</th><th>Título</th>
          <th>Prioridad</th><th>Estatus</th><th>Fecha</th><th>Adjuntos</th><th>Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($solicitudes as $r): 
        $sid   = (int)$r['id'];
        $files = $adjuntosBySolicitud[$sid] ?? [];
        $count = count($files);
      ?>
        <tr>
          <td>#<?= $sid ?></td>
          <td><?= htmlspecialchars($r['sucursal']) ?></td>
          <td><?= htmlspecialchars($r['solicitante']) ?></td>
          <td><?= htmlspecialchars($r['titulo']) ?></td>
          <td>
            <span class="badge <?= $r['prioridad']=='Alta'?'bg-danger':($r['prioridad']=='Media'?'bg-warning text-dark':'bg-secondary') ?>">
              <?= htmlspecialchars($r['prioridad']) ?>
            </span>
          </td>
          <td><span class="badge bg-<?= badgeEstatus($r['estatus']) ?>"><?= htmlspecialchars($r['estatus']) ?></span></td>
          <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($r['fecha_solicitud']))) ?></td>
          <td style="min-width:160px">
            <?php if ($count===0): ?>
              <span class="text-muted">—</span>
            <?php else: 
              // Muestra hasta 3 miniaturas/chips
              $shown = 0;
              foreach ($files as $f) {
                $isImg = isset($f['mime_type']) && str_starts_with($f['mime_type'], 'image/');
                $url   = htmlspecialchars($f['ruta_relativa']);
                if ($isImg && $shown<3) {
                  echo '<a class="thumb-wrap" target="_blank" href="'.$url.'"><img class="thumb" src="'.$url.'" alt="adjunto"></a>';
                  $shown++;
                } elseif (!$isImg && $shown<3) {
                  echo '<a class="btn btn-outline-secondary btn-sm file-chip me-1" target="_blank" href="'.$url.'">PDF</a>';
                  $shown++;
                }
              }
              if ($count > $shown): ?>
                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalAdj<?= $sid ?>">
                  Ver todo (<?= $count ?>)
                </button>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td>
            <form method="POST" class="d-flex gap-2 align-items-center mb-0">
              <input type="hidden" name="action" value="cambiar">
              <input type="hidden" name="id" value="<?= $sid ?>">
              <select name="estatus" class="form-select form-select-sm" style="width:auto">
                <?php foreach (['En revisión','Aprobada','En proceso','Resuelta','Rechazada'] as $st): ?>
                  <option <?= $st===$r['estatus']?'selected':'' ?>><?= $st ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-sm btn-primary">Actualizar</button>
            </form>
          </td>
        </tr>

        <!-- Modal de evidencias -->
        <div class="modal fade" id="modalAdj<?= $sid ?>" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title">Evidencias — Solicitud #<?= $sid ?> (<?= htmlspecialchars($r['sucursal']) ?>)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body">
                <?php if ($count===0): ?>
                  <div class="text-muted">No hay evidencias.</div>
                <?php else: ?>
                  <div class="row g-3">
                    <?php foreach ($files as $f):
                      $isImg = isset($f['mime_type']) && str_starts_with($f['mime_type'], 'image/');
                      $url   = htmlspecialchars($f['ruta_relativa']);
                      $name  = htmlspecialchars($f['nombre_archivo']);
                    ?>
                      <div class="col-6 col-md-4">
                        <div class="card h-100">
                          <?php if ($isImg): ?>
                            <a href="<?= $url ?>" target="_blank">
                              <img src="<?= $url ?>" class="card-img-top" alt="evidencia">
                            </a>
                          <?php else: ?>
                            <div class="card-body d-flex flex-column justify-content-center text-center">
                              <div class="mb-2">📄 PDF</div>
                              <a class="btn btn-sm btn-outline-secondary" href="<?= $url ?>" target="_blank">Abrir</a>
                            </div>
                          <?php endif; ?>
                          <div class="card-footer small text-truncate" title="<?= $name ?>"><?= $name ?></div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
              <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script> -->
</body>
</html>
