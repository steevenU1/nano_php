<?php
session_start();
include 'db.php';

$mensaje = '';
if (isset($_GET['error']) && $_GET['error'] === 'baja') {
  $mensaje = "⚠️ Tu cuenta ha sido dada de baja. Contacta al administrador.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $usuario  = $_POST['usuario'] ?? '';
  $password = $_POST['password'] ?? '';

  $sql  = "SELECT id, nombre, id_sucursal, rol, password, activo, must_change_password 
           FROM usuarios 
           WHERE usuario = ? 
           LIMIT 1";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("s", $usuario);
  $stmt->execute();
  $result = $stmt->get_result();

  if ($row = $result->fetch_assoc()) {
    if ((int)$row['activo'] !== 1) {
      $mensaje = "⚠️ Tu cuenta ha sido dada de baja.";
    } else {
      $hashInfo = password_get_info($row['password']);
      $ok = false;

      if (!empty($hashInfo['algo'])) {
        $ok = password_verify($password, $row['password']);
      } else {
        $ok = hash_equals($row['password'], $password);
      }

      if ($ok) {
        session_regenerate_id(true);
        $_SESSION['id_usuario']  = (int)$row['id'];
        $_SESSION['nombre']      = $row['nombre'];
        $_SESSION['id_sucursal'] = (int)$row['id_sucursal'];
        $_SESSION['rol']         = $row['rol'];
        $_SESSION['must_change_password'] = (int)$row['must_change_password'] === 1;

        if (!empty($_SESSION['must_change_password'])) {
          header("Location: cambiar_password.php?force=1"); 
          exit();
        } else {
          header("Location: dashboard_unificado.php"); 
          exit();
        }
      } else {
        $mensaje = "❌ Contraseña incorrecta";
      }
    }
  } else {
    $mensaje = "❌ Usuario no encontrado";
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Login - Central Nano 2.0</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="icon" type="image/png" href="https://i.ibb.co/kgT18Gx8/4e68073d-5ca3-4c71-8bf5-9756511d74fb.png">
<style>
  :root{ --brand:#1e90ff; --brand-600:#1877cf; }

  html,body{height:100%}
  body{
    margin:0;
    background: linear-gradient(-45deg,#0f2027,#203a43,#2c5364,#1c2b33);
    background-size: 400% 400%;
    animation: bgshift 15s ease infinite;
    display:flex; align-items:center; justify-content:center;
  }
  @keyframes bgshift{0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}

  .login-card{
    width:min(420px,92vw);
    background:#fff; color:#333;
    border-radius:16px;
    box-shadow:0 12px 28px rgba(0,0,0,.18);
    padding:26px 22px;
    animation: enter .6s ease both;
  }
  @keyframes enter{from{opacity:0; transform:translateY(-12px)}to{opacity:1; transform:none}}

  .brand-logo{display:block; margin:0 auto 12px; width:88px; height:auto}
  .title{ text-align:center; font-weight:800; font-size:1.6rem; margin-bottom:.25rem }
  .subtitle{ text-align:center; color:#56606a; margin-bottom:1.1rem }

  .form-label{font-weight:600}
  .form-control:focus{
    border-color:var(--brand);
    box-shadow:0 0 0 .2rem rgba(30,144,255,.2);
    transition:.25s;
  }

  input[type="password"]::-ms-reveal,
  input[type="password"]::-ms-clear,
  input[type="password"]::-webkit-contacts-auto-fill-button,
  input[type="password"]::-webkit-credentials-auto-fill-button{display:none !important;width:0;height:0}

  .pwd-field{position:relative}
  .pwd-field .form-control{padding-right:2.8rem}
  .btn-eye{
    position:absolute; inset:0 .6rem 0 auto;
    display:flex; align-items:center; justify-content:center;
    width:34px;
    background:transparent; border:0; padding:0; line-height:0;
    color:#6c757d; cursor:pointer; border-radius:50%;
  }
  .btn-eye:hover{background:rgba(0,0,0,.06); color:#111}
  .btn-eye svg{width:20px; height:20px}

  .btn-brand{background:var(--brand); border:none; font-weight:700}
  .btn-brand:hover{background:var(--brand-600)}

  .page-exit{opacity:.0; transform: translateY(8px) scale(.98); transition:.35s ease}
  #overlay{
    position:fixed; inset:0; display:none;
    align-items:center; justify-content:center;
    background:rgba(0,0,0,.35); z-index:9999;
    backdrop-filter: blur(2px);
  }
</style>
</head>
<body>

<div id="overlay">
  <div class="spinner-border text-light" role="status" aria-label="Cargando"></div>
</div>

<div class="login-card" id="card">
  <img class="brand-logo" src="https://i.ibb.co/bjp3PF0j/2d7af8d4-c3df-4046-b550-fc31638d5196-1.png" alt="Logo NanoRed">
  <div class="title">Central NanoRed <span style="color:var(--brand)">2.0</span></div>
  <div class="subtitle" id="welcomeMsg">Bienvenido</div>

  <?php if ($mensaje): ?>
    <div class="alert alert-danger text-center"><?= htmlspecialchars($mensaje) ?></div>
  <?php endif; ?>

  <form id="loginForm" method="POST" novalidate>
    <div class="mb-3">
      <label class="form-label">Usuario</label>
      <input type="text" name="usuario" class="form-control" autocomplete="username" required autofocus>
    </div>

    <div class="mb-3">
      <label class="form-label">Contraseña</label>
      <div class="pwd-field">
        <input type="password" name="password" id="password" class="form-control" autocomplete="current-password" required>
        <button type="button" class="btn-eye" id="togglePwd" aria-label="Mostrar/ocultar contraseña" aria-pressed="false">
          <svg id="eyeIcon" viewBox="0 0 24 24" fill="none">
            <path d="M12 5C7 5 2.73 8.11 1 12c1.73 3.89 6 7 11 7s9.27-3.11 11-7c-1.73-3.89-6-7-11-7Z" stroke="currentColor" stroke-width="1.6"/>
            <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.6"/>
          </svg>
        </button>
      </div>
    </div>

    <button class="btn btn-brand w-100 btn-lg" id="submitBtn">Ingresar</button>
  </form>
</div>

<script>
  (function(){
    const h = new Date().getHours();
    const msg = h < 12 ? "Buenos días, ingresa tus credenciales para continuar."
              : h < 19 ? "Buenas tardes, ingresa tus credenciales para continuar."
              : "Buenas noches, ingresa tus credenciales para continuar.";
    document.getElementById('welcomeMsg').textContent = msg;
  })();

  (function(){
    const pwd = document.getElementById('password');
    const btn = document.getElementById('togglePwd');
    const icon = document.getElementById('eyeIcon');
    btn.addEventListener('click', () => {
      const showing = pwd.type === 'text';
      pwd.type = showing ? 'password' : 'text';
      btn.setAttribute('aria-pressed', (!showing).toString());
      icon.innerHTML = showing
        ? '<path d="M12 5C7 5 2.73 8.11 1 12c1.73 3.89 6 7 11 7s9.27-3.11 11-7c-1.73-3.89-6-7-11-7Z" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.6"/>'
        : '<path d="M2 2l20 20" stroke="currentColor" stroke-width="1.6"/><path d="M12 5c-5 0-9.27 3.11-11 7a13.4 13.4 0 003.34 4.23M21.66 12A13.4 13.4 0 0018.32 7.77" stroke="currentColor" stroke-width="1.6"/><path d="M15 12a3 3 0 11-6 0" stroke="currentColor" stroke-width="1.6"/>';
      pwd.focus({preventScroll:true});
    });
  })();

  (function(){
    const form = document.getElementById('loginForm');
    const overlay = document.getElementById('overlay');
    const card = document.getElementById('card');
    const submitBtn = document.getElementById('submitBtn');
    let lock = false;

    form.addEventListener('submit', function(){
      if (lock) return;
      lock = true;
      submitBtn.disabled = true;
      overlay.style.display = 'flex';
      card.classList.add('page-exit');
    });
  })();
</script>
</body>
</html>
