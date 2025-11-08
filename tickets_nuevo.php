<?php
// tickets_nuevo.php — Formulario para crear ticket en LUGA desde NANO
session_start();
require_once __DIR__.'/tickets_api_config.php';

// Permisos (ajusta a tu gusto)
$ROL = $_SESSION['rol'] ?? '';
if (!isset($_SESSION['id_usuario']) || !in_array($ROL, ['Ejecutivo','Gerente','Admin','Logistica'], true)) {
    header("Location: 403.php");
    exit();
}

// Navbar si lo tienes
if (file_exists(__DIR__.'/navbar.php')) require_once __DIR__.'/navbar.php';

// Generar token anti doble-submit (CSRF simple)
if (empty($_SESSION['ticket_csrf'])) {
    $_SESSION['ticket_csrf'] = bin2hex(random_bytes(16));
}

// Flash messages
$flash_ok  = $_SESSION['flash_ok']  ?? '';
$flash_err = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

date_default_timezone_set('America/Mexico_City');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Nuevo ticket</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"> -->
</head>
<body class="bg-light">
<div class="container py-4">
  <h1 class="h4 mb-3">Crear ticket (NANO → LUGA)</h1>

  <?php if ($flash_ok): ?>
    <div class="alert alert-success"><?=h($flash_ok)?></div>
  <?php endif; ?>
  <?php if ($flash_err): ?>
    <div class="alert alert-danger"><?=h($flash_err)?></div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-body">
      <form method="post" action="tickets_enviar.php" id="formTicket" novalidate>
        <input type="hidden" name="csrf" value="<?=h($_SESSION['ticket_csrf'])?>">
        <div class="mb-3">
          <label class="form-label">Asunto <span class="text-danger">*</span></label>
          <input name="asunto" class="form-control" maxlength="255" required placeholder="Ej. Fallo en impresora de mostrador">
          <div class="invalid-feedback">Escribe el asunto.</div>
        </div>

        <div class="mb-3">
          <label class="form-label">Mensaje <span class="text-danger">*</span></label>
          <textarea name="mensaje" class="form-control" rows="6" required placeholder="Describe el problema, cuándo ocurre, capturas, etc."></textarea>
          <div class="form-text"><span id="chars">0</span> caracteres</div>
          <div class="invalid-feedback">Escribe el detalle del ticket.</div>
        </div>

        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Prioridad</label>
            <select name="prioridad" class="form-select">
              <option value="media" selected>Media</option>
              <option value="baja">Baja</option>
              <option value="alta">Alta</option>
              <option value="critica">Crítica</option>
            </select>
          </div>
          <div class="col-md-8">
            <div class="form-text">
              Se enviará con tu usuario <strong><?=h($_SESSION['nombre'] ?? 'Usuario')?></strong>
              y la sucursal <strong><?=h($_SESSION['nombre_sucursal'] ?? ($_SESSION['id_sucursal'] ?? ''))?></strong>.
            </div>
          </div>
        </div>

        <div class="d-flex gap-2 mt-4">
          <button class="btn btn-primary" id="btnEnviar" type="submit">Crear ticket</button>
          <a class="btn btn-outline-secondary" href="./">Cancelar</a>
        </div>
      </form>
    </div>
  </div>

  <div class="small text-muted mt-3">
    API: <?=h(API_BASE)?> &nbsp;•&nbsp; Timeout: <?=h((string)API_TIMEOUT)?>s
  </div>
</div>

<script>
(function(){
  const form = document.getElementById('formTicket');
  const btn  = document.getElementById('btnEnviar');
  const txt  = form.querySelector('textarea[name="mensaje"]');
  const chars= document.getElementById('chars');

  if (txt && chars) {
    const upd = () => chars.textContent = (txt.value||'').length;
    txt.addEventListener('input', upd); upd();
  }

  form.addEventListener('submit', function(e){
    // Front validation + anti doble submit
    if (!form.checkValidity()) {
      e.preventDefault(); e.stopPropagation();
      form.classList.add('was-validated');
      return;
    }
    btn.disabled = true;
    btn.textContent = 'Enviando...';
  });
})();
</script>
</body>
</html>
