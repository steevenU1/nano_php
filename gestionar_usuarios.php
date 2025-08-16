<?php
// gestionar_usuarios.php
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

include 'db.php';
include 'navbar.php';

$ROL         = $_SESSION['rol'] ?? '';
$ID_USUARIO  = intval($_SESSION['id_usuario'] ?? 0);
$ID_SUCURSAL = intval($_SESSION['id_sucursal'] ?? 0);

$permAdmin   = ($ROL === 'Admin');
$permGerente = ($ROL === 'Gerente');
if (!$permAdmin && !$permGerente) { header("Location: 403.php"); exit(); }

// Crear tabla de bitácora si no existe (con reset_password)
$conn->query("
  CREATE TABLE IF NOT EXISTS usuarios_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    actor_id INT NOT NULL,
    target_id INT NOT NULL,
    accion ENUM('baja','reactivar','cambiar_rol','reset_password') NOT NULL,
    detalles TEXT,
    ip VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (target_id),
    INDEX (actor_id),
    INDEX (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// CSRF
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf'];
$mensaje = "";

// Helpers
function puedeGerenteOperar($target, $ID_SUCURSAL) {
  return ($target['rol'] === 'Ejecutivo' && intval($target['id_sucursal']) === $ID_SUCURSAL);
}
function log_usuario($conn, $actor_id, $target_id, $accion, $detalles = '') {
  $ip = $_SERVER['REMOTE_ADDR'] ?? null;
  $stmt = $conn->prepare("INSERT INTO usuarios_log (actor_id, target_id, accion, detalles, ip) VALUES (?,?,?,?,?)");
  $stmt->bind_param("iisss", $actor_id, $target_id, $accion, $detalles, $ip);
  $stmt->execute();
  $stmt->close();
}
function generarTemporal() {
  $alf = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%^*()-_+=';
  $len = random_int(12, 16);
  $out = '';
  for ($i=0; $i<$len; $i++) $out .= $alf[random_int(0, strlen($alf)-1)];
  return $out;
}

// ====== Acciones POST ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
  if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
    $mensaje = "<div class='alert alert-danger'>❌ Token inválido. Recarga la página.</div>";
  } else {
    $accion = $_POST['accion'];
    $usuario_id = intval($_POST['usuario_id'] ?? 0);

    // Cargar usuario objetivo
    $stmt = $conn->prepare("SELECT id, nombre, rol, id_sucursal, activo FROM usuarios WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $usuario_id);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$target) {
      $mensaje = "<div class='alert alert-danger'>❌ Usuario no encontrado.</div>";
    } elseif (intval($target['id']) === $ID_USUARIO && $accion !== 'cambiar_rol') {
      $mensaje = "<div class='alert alert-warning'>⚠️ No puedes operar sobre tu propia cuenta.</div>";
    } else {
      try {
        $conn->begin_transaction();

        if ($accion === 'baja') {
          $motivo_baja = trim($_POST['motivo_baja'] ?? '');
          $fecha_baja  = $_POST['fecha_baja'] ?? date('Y-m-d');

          if (!$permAdmin && !($permGerente && puedeGerenteOperar($target, $ID_SUCURSAL))) {
            throw new Exception("Permisos insuficientes para dar de baja a este usuario.");
          }
          if ($target['rol'] === 'Admin' && !$permAdmin) {
            throw new Exception("No puedes dar de baja a Admin.");
          }

          // Desactivar
          $stmt = $conn->prepare("UPDATE usuarios SET activo=0 WHERE id=?");
          $stmt->bind_param("i", $usuario_id);
          $stmt->execute();
          $stmt->close();

          // Expediente
          $stmt = $conn->prepare("SELECT id FROM usuarios_expediente WHERE usuario_id=? LIMIT 1");
          $stmt->bind_param("i", $usuario_id);
          $stmt->execute();
          $exp = $stmt->get_result()->fetch_assoc();
          $stmt->close();

          if ($exp) {
            $stmt = $conn->prepare("UPDATE usuarios_expediente SET fecha_baja=?, motivo_baja=?, updated_at=NOW() WHERE usuario_id=?");
            $stmt->bind_param("ssi", $fecha_baja, $motivo_baja, $usuario_id);
            $stmt->execute();
            $stmt->close();
          } else {
            $stmt = $conn->prepare("INSERT INTO usuarios_expediente (usuario_id, fecha_baja, motivo_baja, created_at, updated_at) VALUES (?,?,?,NOW(),NOW())");
            $stmt->bind_param("iss", $usuario_id, $fecha_baja, $motivo_baja);
            $stmt->execute();
            $stmt->close();
          }

          log_usuario($conn, $ID_USUARIO, $usuario_id, 'baja', "Motivo: $motivo_baja | Fecha: $fecha_baja");
          $conn->commit();
          $mensaje = "<div class='alert alert-success'>✅ Usuario dado de baja correctamente.</div>";

        } elseif ($accion === 'reactivar') {
          if (!$permAdmin && !($permGerente && puedeGerenteOperar($target, $ID_SUCURSAL))) {
            throw new Exception("Permisos insuficientes para reactivar a este usuario.");
          }
          if ($target['rol'] === 'Admin' && !$permAdmin) {
            throw new Exception("No puedes reactivar Admin.");
          }

          $stmt = $conn->prepare("UPDATE usuarios SET activo=1 WHERE id=?");
          $stmt->bind_param("i", $usuario_id);
          $stmt->execute();
          $stmt->close();

          $stmt = $conn->prepare("UPDATE usuarios_expediente SET updated_at=NOW() WHERE usuario_id=?");
          $stmt->bind_param("i", $usuario_id);
          $stmt->execute();
          $stmt->close();

          log_usuario($conn, $ID_USUARIO, $usuario_id, 'reactivar', "Reactivación de cuenta");
          $conn->commit();
          $mensaje = "<div class='alert alert-success'>✅ Usuario reactivado.</div>";

        } elseif ($accion === 'cambiar_rol') {
          $nuevo_rol = $_POST['nuevo_rol'] ?? '';
          if (!$permAdmin) { throw new Exception("Solo Admin puede cambiar roles."); }
          $rolesPermitidos = ['Ejecutivo','Gerente','GerenteZona','Supervisor','Admin'];
          if (!in_array($nuevo_rol, $rolesPermitidos, true)) {
            throw new Exception("Rol no válido.");
          }

          $stmt = $conn->prepare("UPDATE usuarios SET rol=? WHERE id=?");
          $stmt->bind_param("si", $nuevo_rol, $usuario_id);
          $stmt->execute();
          $stmt->close();

          log_usuario($conn, $ID_USUARIO, $usuario_id, 'cambiar_rol', "Nuevo rol: $nuevo_rol (antes: {$target['rol']})");
          $conn->commit();
          $mensaje = "<div class='alert alert-success'>✅ Rol actualizado a <b>".htmlspecialchars($nuevo_rol)."</b>.</div>";

        } elseif ($accion === 'reset_password') {
          if (!$permAdmin && !($permGerente && $target['rol']==='Ejecutivo' && intval($target['id_sucursal'])===$ID_SUCURSAL)) {
            throw new Exception("Permisos insuficientes para resetear la contraseña.");
          }

          $temp = generarTemporal();
          $hash = password_hash($temp, PASSWORD_DEFAULT);

          $stmt = $conn->prepare("UPDATE usuarios 
              SET password=?, must_change_password=1, last_password_reset_at=NOW()
              WHERE id=?");
          $stmt->bind_param("si", $hash, $usuario_id);
          $stmt->execute();
          $stmt->close();

          log_usuario($conn, $ID_USUARIO, $usuario_id, 'reset_password', "Se generó contraseña temporal");
          $conn->commit();

          $mensaje = "<div class='alert alert-warning'>🔐 Contraseña temporal generada: 
                      <code style='user-select:all'>".htmlspecialchars($temp)."</code><br>
                      * Compártela al usuario. Se le pedirá cambiarla al iniciar sesión.
                      </div>";
        }

      } catch (Throwable $e) {
        $conn->rollback();
        $mensaje = "<div class='alert alert-danger'>❌ ".$e->getMessage()."</div>";
      }
    }
  }
}

// ====== Listados ======
$busq = trim($_GET['q'] ?? '');
$frol = $_GET['rol'] ?? '';
$fsuc = intval($_GET['sucursal'] ?? 0);

function cargarUsuarios($conn, $activo, $busq, $frol, $fsuc, $limitToSucursalId = null) {
  $sql = "SELECT u.id, u.nombre, u.usuario, u.rol, u.id_sucursal, u.activo, s.nombre AS sucursal_nombre
          FROM usuarios u
          LEFT JOIN sucursales s ON s.id = u.id_sucursal
          WHERE u.activo=?";
  $params = [$activo]; $types = "i";
  if ($busq !== '') { $sql .= " AND (u.nombre LIKE CONCAT('%',?,'%') OR u.usuario LIKE CONCAT('%',?,'%'))"; $params[]=$busq; $params[]=$busq; $types.="ss"; }
  if ($frol !== '') { $sql .= " AND u.rol=?"; $params[]=$frol; $types.="s"; }
  if ($fsuc > 0)    { $sql .= " AND u.id_sucursal=?"; $params[]=$fsuc; $types.="i"; }
  if (!is_null($limitToSucursalId)) { $sql .= " AND u.id_sucursal=?"; $params[]=$limitToSucursalId; $types.="i"; }
  $sql .= " ORDER BY s.nombre ASC, u.nombre ASC";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
  return $data;
}

$limitSucGerente = (!$permAdmin && $permGerente) ? $ID_SUCURSAL : null;
$usuariosActivos   = cargarUsuarios($conn, 1, $busq, $frol, $fsuc, $limitSucGerente);
$usuariosInactivos = cargarUsuarios($conn, 0, $busq, $frol, $fsuc, $limitSucGerente);

// Logs
$logLimit = 300;
$stmt = $conn->prepare("
  SELECT l.id, l.created_at, l.accion, l.detalles, l.ip,
         a.nombre AS actor_nombre,
         t.nombre AS target_nombre, t.usuario AS target_user
  FROM usuarios_log l
  LEFT JOIN usuarios a ON a.id = l.actor_id
  LEFT JOIN usuarios t ON t.id = l.target_id
  ORDER BY l.id DESC
  LIMIT ?
");
$stmt->bind_param("i", $logLimit);
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Sucursales/roles para filtro
$suc = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre ASC")->fetch_all(MYSQLI_ASSOC);
$roles = ['Ejecutivo','Gerente','GerenteZona','Supervisor','Admin'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Gestionar usuarios</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <link rel="icon" type="image/x-icon" href="./img/favicon.ico">
</head>
<body class="bg-light">

<div class="container mt-4">
  <h2>👥 Gestión de Usuarios</h2>
  <?= $mensaje ?>

  <!-- Filtros -->
  <form class="card p-3 mb-3 shadow-sm bg-white" method="get">
    <div class="row g-2">
      <div class="col-md-4">
        <input class="form-control" type="text" name="q" placeholder="Buscar por nombre o usuario" value="<?=htmlspecialchars($busq)?>">
      </div>
      <div class="col-md-3">
        <select name="rol" class="form-select">
          <option value="">Todos los roles</option>
          <?php foreach ($roles as $r): ?>
            <option value="<?=$r?>" <?=($frol===$r?'selected':'')?>><?=$r?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <select name="sucursal" class="form-select" <?=(!$permAdmin?'disabled':'')?>>
          <option value="0">Todas las sucursales</option>
          <?php foreach ($suc as $s): ?>
            <option value="<?=$s['id']?>" <?=($fsuc==$s['id']?'selected':'')?>>
              <?=htmlspecialchars($s['nombre'])?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if(!$permAdmin): ?><div class="form-text">Como Gerente, solo ves tu sucursal.</div><?php endif; ?>
      </div>
      <div class="col-md-2 d-grid">
        <button class="btn btn-primary" type="submit">Filtrar</button>
      </div>
    </div>
  </form>

  <!-- Tabs -->
  <ul class="nav nav-tabs" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-activos" type="button" role="tab">Activos</button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-inactivos" type="button" role="tab">Inactivos</button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-bitacora" type="button" role="tab">Bitácora</button>
    </li>
  </ul>

  <div class="tab-content card border-top-0 shadow-sm">
    <!-- Activos -->
    <div class="tab-pane fade show active" id="tab-activos" role="tabpanel">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>#</th><th>Nombre</th><th>Usuario</th><th>Rol</th><th>Sucursal</th>
              <th class="text-end">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$usuariosActivos): ?>
              <tr><td colspan="6" class="text-center py-4">Sin usuarios activos.</td></tr>
            <?php else: foreach ($usuariosActivos as $u):
              $bloqGerente = (!$permAdmin && $permGerente && !puedeGerenteOperar($u, $ID_SUCURSAL));
              $deshabBaja  = ($u['id']===$ID_USUARIO) || ($u['rol']==='Admin' && !$permAdmin) || $bloqGerente;
              $deshabRol   = !$permAdmin; // solo Admin cambia rol
              $deshabReset = (!$permAdmin && ($u['rol']!=='Ejecutivo' || intval($u['id_sucursal'])!==$ID_SUCURSAL));
            ?>
              <tr>
                <td><?=intval($u['id'])?></td>
                <td><?=htmlspecialchars($u['nombre'])?></td>
                <td><?=htmlspecialchars($u['usuario'])?></td>
                <td><?=htmlspecialchars($u['rol'])?></td>
                <td><?=htmlspecialchars($u['sucursal_nombre'] ?? '-')?></td>
                <td class="text-end">
                  <button class="btn btn-outline-danger btn-sm"
                          data-bs-toggle="modal" data-bs-target="#modalBaja"
                          data-id="<?=$u['id']?>" data-nombre="<?=htmlspecialchars($u['nombre'])?>"
                          <?= $deshabBaja ? 'disabled' : '' ?>>Dar de baja</button>
                  <button class="btn btn-outline-secondary btn-sm"
                          data-bs-toggle="modal" data-bs-target="#modalRol"
                          data-id="<?=$u['id']?>" data-nombre="<?=htmlspecialchars($u['nombre'])?>"
                          data-rol="<?=$u['rol']?>"
                          <?= $deshabRol ? 'disabled' : '' ?>>Cambiar rol</button>
                  <button class="btn btn-outline-warning btn-sm"
                          data-bs-toggle="modal" data-bs-target="#modalResetPass"
                          data-id="<?=$u['id']?>" data-nombre="<?=htmlspecialchars($u['nombre'])?>"
                          <?= $deshabReset ? 'disabled' : '' ?>>Resetear contraseña</button>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Inactivos -->
    <div class="tab-pane fade" id="tab-inactivos" role="tabpanel">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>#</th><th>Nombre</th><th>Usuario</th><th>Rol</th><th>Sucursal</th>
              <th class="text-end">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$usuariosInactivos): ?>
              <tr><td colspan="6" class="text-center py-4">Sin usuarios inactivos.</td></tr>
            <?php else: foreach ($usuariosInactivos as $u):
              $bloqGerente = (!$permAdmin && $permGerente && !puedeGerenteOperar($u, $ID_SUCURSAL));
              $deshabReac  = ($u['rol']==='Admin' && !$permAdmin) || $bloqGerente;
              $deshabResetInac = (!$permAdmin && ($u['rol']!=='Ejecutivo' || intval($u['id_sucursal'])!==$ID_SUCURSAL));
            ?>
              <tr>
                <td><?=intval($u['id'])?></td>
                <td><?=htmlspecialchars($u['nombre'])?></td>
                <td><?=htmlspecialchars($u['usuario'])?></td>
                <td><?=htmlspecialchars($u['rol'])?></td>
                <td><?=htmlspecialchars($u['sucursal_nombre'] ?? '-')?></td>
                <td class="text-end">
                  <button class="btn btn-outline-success btn-sm"
                          data-bs-toggle="modal" data-bs-target="#modalReactivar"
                          data-id="<?=$u['id']?>" data-nombre="<?=htmlspecialchars($u['nombre'])?>"
                          <?= $deshabReac ? 'disabled' : '' ?>>Reactivar</button>
                  <button class="btn btn-outline-warning btn-sm"
                          data-bs-toggle="modal" data-bs-target="#modalResetPass"
                          data-id="<?=$u['id']?>" data-nombre="<?=htmlspecialchars($u['nombre'])?>"
                          <?= $deshabResetInac ? 'disabled' : '' ?>>Resetear contraseña</button>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Bitácora -->
    <div class="tab-pane fade" id="tab-bitacora" role="tabpanel">
      <div class="p-3">
        <h5 class="mb-2">Últimos <?=$logLimit?> movimientos</h5>
        <div class="table-responsive">
          <table class="table table-sm table-striped align-middle">
            <thead class="table-light">
              <tr>
                <th>#</th>
                <th>Fecha</th>
                <th>Acción</th>
                <th>Actor</th>
                <th>Usuario afectado</th>
                <th>Detalles</th>
                <th>IP</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($logs)): ?>
                <tr><td colspan="7" class="text-center py-3">Sin registros.</td></tr>
              <?php else: foreach ($logs as $l): ?>
                <tr>
                  <td><?=intval($l['id'])?></td>
                  <td><?=htmlspecialchars($l['created_at'])?></td>
                  <td><span class="badge bg-secondary"><?=htmlspecialchars($l['accion'])?></span></td>
                  <td><?=htmlspecialchars($l['actor_nombre'] ?: '-')?></td>
                  <td><?=htmlspecialchars(($l['target_nombre'] ?: '-') . ' (' . ($l['target_user'] ?: '-') . ')')?></td>
                  <td><?=htmlspecialchars($l['detalles'] ?: '-')?></td>
                  <td><?=htmlspecialchars($l['ip'] ?: '-')?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
        <div class="text-muted small">* El historial se conserva aunque el usuario sea reactivado.</div>
      </div>
    </div>
  </div>
</div>

<!-- Modales -->
<div class="modal fade" id="modalBaja" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Dar de baja usuario</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="accion" value="baja">
        <input type="hidden" name="csrf" value="<?=$csrf?>">
        <input type="hidden" name="usuario_id" id="baja_usuario_id">

        <div class="mb-2">
          <label class="form-label">Usuario</label>
          <input type="text" id="baja_usuario_nombre" class="form-control" readonly>
        </div>
        <div class="mb-2">
          <label class="form-label">Motivo de baja</label>
          <textarea name="motivo_baja" class="form-control" rows="2" required></textarea>
        </div>
        <div class="mb-2">
          <label class="form-label">Fecha de baja</label>
          <input type="date" name="fecha_baja" class="form-control" value="<?=date('Y-m-d')?>">
        </div>

        <!-- ✅ Checklist informativo, no se guarda (habilita el botón al marcar los tres) -->
        <div class="mb-3">
          <label class="form-label">Checklist de bajas/seguridad</label>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="chkPayjoy">
            <label class="form-check-label" for="chkPayjoy">Ya generé baja de usuario PayJoy</label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="chkKrediya">
            <label class="form-check-label" for="chkKrediya">Ya generé baja de usuario Krediya</label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="chkTiempoAire">
            <label class="form-check-label" for="chkTiempoAire">Cambio de contraseña para Tiempo aire</label>
          </div>
          <!-- <div class="form-text">Estos checks no se guardan; para confirmar debes marcar los 3.</div> -->
        </div>

        <div class="alert alert-warning mb-0">Esta acción desactiva el acceso inmediatamente.</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-danger" id="btnConfirmarBaja" type="submit" disabled>Confirmar baja</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="modalReactivar" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Reactivar usuario</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="accion" value="reactivar">
        <input type="hidden" name="csrf" value="<?=$csrf?>">
        <input type="hidden" name="usuario_id" id="react_usuario_id">
        <div class="mb-2">
          <label class="form-label">Usuario</label>
          <input type="text" id="react_usuario_nombre" class="form-control" readonly>
        </div>
        <div class="alert alert-info mb-0">Se reactivará la cuenta y podrá iniciar sesión nuevamente.</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-success" type="submit">Reactivar</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="modalRol" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Cambiar rol</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="accion" value="cambiar_rol">
        <input type="hidden" name="csrf" value="<?=$csrf?>">
        <input type="hidden" name="usuario_id" id="rol_usuario_id">
        <div class="mb-2">
          <label class="form-label">Usuario</label>
          <input type="text" id="rol_usuario_nombre" class="form-control" readonly>
        </div>
        <div class="mb-2">
          <label class="form-label">Rol actual</label>
          <input type="text" id="rol_actual" class="form-control" readonly>
        </div>
        <div class="mb-2">
          <label class="form-label">Nuevo rol</label>
          <select name="nuevo_rol" class="form-select" required>
            <?php foreach ($roles as $r): ?>
              <option value="<?=$r?>"><?=$r?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="alert alert-warning mb-0">Solo Administradores pueden cambiar roles.</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" type="submit" <?=(!$permAdmin?'disabled':'')?>>Guardar cambio</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Resetear Password -->
<div class="modal fade" id="modalResetPass" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Resetear contraseña</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="accion" value="reset_password">
        <input type="hidden" name="csrf" value="<?=$csrf?>">
        <input type="hidden" name="usuario_id" id="reset_usuario_id">
        <div class="mb-2">
          <label class="form-label">Usuario</label>
          <input type="text" id="reset_usuario_nombre" class="form-control" readonly>
        </div>
        <div class="alert alert-info">
          Se generará una contraseña temporal. Al iniciar sesión, el usuario deberá cambiarla obligatoriamente.
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-warning" type="submit">Generar temporal</button>
      </div>
    </form>
  </div>
</div>

<script>
// Modal Baja: setea datos y controla el checklist
const modalBaja = document.getElementById('modalBaja');
modalBaja.addEventListener('show.bs.modal', e => {
  const b = e.relatedTarget;
  document.getElementById('baja_usuario_id').value = b.getAttribute('data-id');
  document.getElementById('baja_usuario_nombre').value = b.getAttribute('data-nombre');

  const chkPay = modalBaja.querySelector('#chkPayjoy');
  const chkKre = modalBaja.querySelector('#chkKrediya');
  const chkTA  = modalBaja.querySelector('#chkTiempoAire');
  const btn    = modalBaja.querySelector('#btnConfirmarBaja');

  function toggleBtn(){ btn.disabled = !(chkPay.checked && chkKre.checked && chkTA.checked); }

  // Reiniciar estado y asignar listeners (sin duplicar)
  chkPay.checked = false;
  chkKre.checked = false;
  chkTA.checked  = false;

  chkPay.onchange = toggleBtn;
  chkKre.onchange = toggleBtn;
  chkTA.onchange  = toggleBtn;

  chkPay.oninput = toggleBtn;
  chkKre.oninput = toggleBtn;
  chkTA.oninput  = toggleBtn;

  toggleBtn();
});

const modalReactivar = document.getElementById('modalReactivar');
modalReactivar.addEventListener('show.bs.modal', e => {
  const b = e.relatedTarget;
  document.getElementById('react_usuario_id').value = b.getAttribute('data-id');
  document.getElementById('react_usuario_nombre').value = b.getAttribute('data-nombre');
});

const modalRol = document.getElementById('modalRol');
modalRol.addEventListener('show.bs.modal', e => {
  const b = e.relatedTarget;
  document.getElementById('rol_usuario_id').value = b.getAttribute('data-id');
  document.getElementById('rol_usuario_nombre').value = b.getAttribute('data-nombre');
  document.getElementById('rol_actual').value = b.getAttribute('data-rol');
});

const modalResetPass = document.getElementById('modalResetPass');
modalResetPass.addEventListener('show.bs.modal', e => {
  const b = e.relatedTarget;
  document.getElementById('reset_usuario_id').value = b.getAttribute('data-id');
  document.getElementById('reset_usuario_nombre').value = b.getAttribute('data-nombre');
});
</script>
</body>
</html>
