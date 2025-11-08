<?php
// tickets_ver.php — Detalle + conversación + responder (NANO → LUGA) con manejo de errores
session_start();

// Debug controlado (puedes apagar en producción)
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__.'/tickets_api_config.php'; // define API_BASE, API_TOKEN y api_get/api_post_json si ya lo dejaste así
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Permisos
$ROL = $_SESSION['rol'] ?? '';
if (!isset($_SESSION['id_usuario']) || !in_array($ROL, ['Ejecutivo','Gerente','Admin','Logistica'], true)) {
  header("Location: 403.php"); exit();
}

// Navbar si existe
if (file_exists(__DIR__.'/navbar.php')) require_once __DIR__.'/navbar.php';

date_default_timezone_set('America/Mexico_City');

// === Parámetro ID
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(422);
  echo '<div class="container py-4"><div class="alert alert-danger">ID inválido.</div></div>';
  exit;
}

// ========== Llamada al API ==========
$resp = api_get('/tickets.get.php', ['id'=>$id]); // debe existir en LUGA y filtrar por sistema_origen=NANO
$http = (int)($resp['http'] ?? 0);
$json = is_array($resp['json'] ?? null) ? $resp['json'] : null;

// Manejo de errores del API
$api_error_msg = '';
if ($http !== 200 || !$json) {
  // Mensaje amigable según código
  if ($http === 404) {
    $api_error_msg = 'No encontrado o sin permiso. Recuerda que NANO solo puede ver sus propios tickets.';
  } elseif ($http === 403 || $http === 401) {
    $api_error_msg = 'Acceso no autorizado. Revisa el token o permisos.';
  } elseif ($http === 0) {
    $api_error_msg = 'Sin respuesta del API. Revisa conectividad.';
  } else {
    $api_error_msg = 'Falla del API (HTTP '.$http.').';
  }
}

$ticket = $json['ticket']   ?? null;
$mens   = $json['mensajes'] ?? [];

// Flash
$flash_ok  = $_SESSION['flash_ok']  ?? '';
$flash_err = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Ticket #<?=h($id)?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <div class="d-flex align-items-center gap-2">
      <!-- Ajusta el back según la ruta que uses (lista integrada en tickets_nuevo.php) -->
      <a href="tickets_nuevo.php" class="btn btn-link p-0">← Volver</a>
      <h1 class="h5 m-0">Ticket #<?=h($id)?></h1>
    </div>
    <div>
      <?php if ($ticket): ?>
        <span class="badge bg-secondary"><?=h($ticket['estado'] ?? '')?></span>
        <span class="badge bg-info ms-1"><?=h($ticket['prioridad'] ?? '')?></span>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($flash_ok): ?><div class="alert alert-success"><?=h($flash_ok)?></div><?php endif; ?>
  <?php if ($flash_err): ?><div class="alert alert-danger"><?=h($flash_err)?></div><?php endif; ?>

  <?php if ($api_error_msg): ?>
    <div class="alert alert-danger">
      <strong>Error:</strong> <?=h($api_error_msg)?>
      <?php if (!empty($resp['json']['error'])): ?>
        <div class="small text-muted">API: <?=h($resp['json']['error'])?></div>
      <?php endif; ?>
      <div class="mt-2">
        <a class="btn btn-outline-secondary btn-sm" href="tickets_nuevo.php">Volver a la lista</a>
        <a class="btn btn-outline-primary btn-sm" href="?id=<?=h((string)$id)?>">Reintentar</a>
      </div>
    </div>
  <?php else: ?>

  <!-- Encabezado del ticket -->
  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <div class="mb-2">
        <div class="text-muted small">
          Origen: <strong><?=h($ticket['sistema_origen'] ?? '')?></strong>
          · Creado: <?=h($ticket['created_at'] ?? '')?>
          · Actualizado: <?=h($ticket['updated_at'] ?? '')?>
        </div>
      </div>
      <div class="fs-5 fw-semibold"><?=h($ticket['asunto'] ?? '')?></div>
    </div>
  </div>

  <!-- Conversación -->
  <div class="card shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold">Conversación</div>
    <div class="card-body">
      <?php if (!$mens): ?>
        <div class="text-muted">Sin mensajes aún.</div>
      <?php else: ?>
        <?php foreach ($mens as $m): ?>
          <div class="mb-3">
            <div class="small text-muted">
              <?=h($m['autor_sistema'])?> • <?=h($m['created_at'])?>
              <?php if(!empty($m['autor_id'])):?> • Usuario ID: <?=h($m['autor_id'])?><?php endif;?>
            </div>
            <div><?=nl2br(h($m['cuerpo']))?></div>
          </div>
          <hr class="my-2">
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Responder -->
  <form class="card card-body" method="post" action="tickets_responder.php">
    <input type="hidden" name="ticket_id" value="<?=h((string)$id)?>">
    <div class="mb-2">
      <label class="form-label">Tu respuesta</label>
      <textarea name="mensaje" class="form-control" rows="4" required></textarea>
      <div class="form-text">Se enviará con tu usuario <strong><?=h($_SESSION['nombre'] ?? 'Usuario')?></strong>.</div>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-primary">Enviar</button>
      <a class="btn btn-outline-secondary" href="tickets_nuevo.php">Cerrar</a>
    </div>
  </form>

  <div class="text-muted small mt-3">API: LUGA · HTTP <?=$http?></div>

  <?php endif; // fin error/no error ?>
</div>

<script>
// Auto-refresh conversación cada 60s, solo si no hubo error
<?php if (!$api_error_msg): ?>
setTimeout(()=>location.reload(), 60000);
<?php endif; ?>
</script>
</body>
</html>
