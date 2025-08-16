<?php
// navbar.php

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
include 'db.php';

date_default_timezone_set('America/Mexico_City');

$rolUsuario    = $_SESSION['rol'] ?? 'Ejecutivo';
$nombreUsuario = $_SESSION['nombre'] ?? 'Usuario';
$idUsuario     = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal    = (int)($_SESSION['id_sucursal'] ?? 0);

// Polyfills para PHP antiguos
if (!function_exists('str_starts_with')) {
  function str_starts_with($haystack, $needle) {
    return (string)$needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
  }
}
if (!function_exists('str_ends_with')) {
  function str_ends_with($haystack, $needle) {
    return $needle === '' || substr($haystack, -strlen($needle)) === (string)$needle;
  }
}

function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function initials($name){
  $name = trim((string)$name);
  if ($name==='') return 'U';
  $parts = preg_split('/\s+/', $name);
  $first = mb_substr($parts[0] ?? '', 0, 1, 'UTF-8');
  $last  = mb_substr($parts[count($parts)-1] ?? '', 0, 1, 'UTF-8');
  $ini = mb_strtoupper($first.$last, 'UTF-8');
  return $ini ?: 'U';
}

/** Convierte lo que haya en usuarios_expediente.foto a una URL servible */
function resolveAvatarUrl(?string $fotoBD): ?string {
  $f = trim((string)$fotoBD);
  if ($f==='') return null;

  // URL o data URI
  if (preg_match('#^(https?://|data:image/)#i', $f)) return $f;

  // Normaliza separadores
  $f = str_replace('\\','/',$f);

  $docroot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim(str_replace('\\','/', $_SERVER['DOCUMENT_ROOT']), '/') : '';
  $appDir  = rtrim(str_replace('\\','/', __DIR__), '/'); // carpeta del proyecto (donde está navbar.php)
  $baseUri = '';
  if ($docroot && str_starts_with($appDir, $docroot)) {
    $baseUri = substr($appDir, strlen($docroot)); // p.ej. "/luga_php"
  }

  // 1) Ruta absoluta de filesystem (C:/... o /var/...)
  if (preg_match('#^[A-Za-z]:/|^/#', $f)) {
    // si vive bajo DOCROOT, regreso ruta web directamente
    if ($docroot && str_starts_with($f, $docroot.'/')) {
      return substr($f, strlen($docroot)); // ya incluye el leading slash
    }
    // buscar por nombre en lugares comunes dentro del proyecto
    $base = basename($f);
    $dirs = [
      'uploads/expedientes','expedientes','uploads',
      'uploads/expedientes/fotos','expedientes/fotos',
      'uploads/usuarios','usuarios',
      'uploads/perfiles','perfiles',
    ];
    foreach ($dirs as $d) {
      $abs = $appDir.'/'.$d.'/'.$base;
      if (is_file($abs)) return $baseUri.'/'.$d.'/'.$base;
    }
    return null;
  }

  // 2) Ruta web absoluta
  if (str_starts_with($f,'/')) {
    // prueba que exista físicamente
    if ($docroot && is_file($docroot.$f)) return $f;
    // no sabemos, devolvemos igual (tal vez la sirve otro alias)
    return $f;
  }

  // 3) Ruta relativa: primero respecto al proyecto
  if (is_file($appDir.'/'.$f)) return $baseUri.'/'.ltrim($f,'/');

  // 4) Respecto a DOCROOT
  if ($docroot && is_file($docroot.'/'.$f)) return '/'.ltrim($f,'/');

  // 5) Buscar por basename en lugares comunes
  $base = basename($f);
  $dirs = [
    'uploads/expedientes','expedientes','uploads',
    'uploads/expedientes/fotos','expedientes/fotos',
    'uploads/usuarios','usuarios',
    'uploads/perfiles','perfiles',
  ];
  foreach ($dirs as $d) {
    $abs = $appDir.'/'.$d.'/'.$base;
    if (is_file($abs)) return $baseUri.'/'.$d.'/'.$base;
  }

  return null;
}

// -------- Avatar (foto o iniciales) --------
$avatarUrl = null;
if ($idUsuario > 0) {
  $st = $conn->prepare("SELECT foto FROM usuarios_expediente WHERE usuario_id=? ORDER BY id DESC LIMIT 1");
  $st->bind_param("i", $idUsuario);
  $st->execute();
  $st->bind_result($fotoBD);
  if ($st->fetch()) {
    $avatarUrl = resolveAvatarUrl($fotoBD);
  }
  $st->close();
}

// -------- Sucursal para mostrar --------
$sucursalNombre = '';
if ($idSucursal > 0) {
  $stmt = $conn->prepare("SELECT nombre FROM sucursales WHERE id=?");
  $stmt->bind_param("i", $idSucursal);
  $stmt->execute();
  $stmt->bind_result($sucursalNombre);
  $stmt->fetch();
  $stmt->close();
}

// -------- Badge traspasos --------
$badgeTraspasos = 0;
if ($idSucursal > 0) {
  $stmt = $conn->prepare("SELECT COUNT(*) FROM traspasos WHERE id_sucursal_destino=? AND estatus='Pendiente'");
  $stmt->bind_param("i", $idSucursal);
  $stmt->execute();
  $stmt->bind_result($badgeTraspasos);
  $stmt->fetch();
  $stmt->close();
}

$esAdmin = in_array($rolUsuario, ['Admin','Super']);
?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<style>
  .nav-avatar, .nav-initials {
    width: 32px; height: 32px; border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 0.95rem;
  }
  .nav-avatar { object-fit: cover; }
  .nav-initials { background: #6c757d; color: #fff; }
  .dropdown-avatar { width: 56px; height: 56px; border-radius: 50%; object-fit: cover; }
  .dropdown-initials {
    width: 56px; height: 56px; border-radius: 50%;
    background: #6c757d; color: #fff; font-weight: 800; font-size: 1.1rem;
    display: inline-flex; align-items: center; justify-content: center;
  }
</style>

<nav class="navbar navbar-expand-lg navbar-dark bg-black sticky-top shadow">
  <div class="container-fluid">

    <!-- LOGO -->
    <a class="navbar-brand d-flex align-items-center" href="dashboard_unificado.php">
      <img src="https://i.ibb.co/kgT18Gx8/4e68073d-5ca3-4c71-8bf5-9756511d74fb.png" alt="Logo" width="35" height="35" class="me-2" style="object-fit: contain;">
      <span>Central2.0</span>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navbarMain">
      <!-- IZQUIERDA -->
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">

        <!-- DASHBOARD -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Dashboard</a>
          <ul class="dropdown-menu dropdown-menu-dark">
            <li><a class="dropdown-item" href="productividad_dia.php">Dashboard Diario</a></li>
            <li><a class="dropdown-item" href="dashboard_unificado.php">Dashboard semanal</a></li>
            <li><a class="dropdown-item" href="dashboard_mensual.php">Dashboard mensual</a></li>
          </ul>
        </li>

        <!-- VENTAS -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Ventas</a>
          <ul class="dropdown-menu dropdown-menu-dark">
            <?php if ($rolUsuario === 'Logistica'): ?>
              <li><a class="dropdown-item" href="historial_ventas.php">Historial de ventas</a></li>
              <li><a class="dropdown-item" href="historial_ventas_sims.php">Historial ventas SIM</a></li>
            <?php else: ?>
              <li><a class="dropdown-item" href="nueva_venta.php">Venta equipos</a></li>
              <li><a class="dropdown-item" href="venta_sim_prepago.php">Venta SIM prepago</a></li>
              <li><a class="dropdown-item" href="venta_sim_pospago.php">Venta SIM pospago</a></li>
              <li><a class="dropdown-item" href="historial_ventas.php">Historial de ventas</a></li>
              <li><a class="dropdown-item" href="historial_ventas_sims.php">Historial ventas SIM</a></li>
            <?php endif; ?>
          </ul>
        </li>

        <!-- INVENTARIO -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Inventario</a>
          <ul class="dropdown-menu dropdown-menu-dark">
            <?php if ($rolUsuario === 'Logistica'): ?>
              <li><a class="dropdown-item" href="inventario_global.php">Inventario global</a></li>
              <li><a class="dropdown-item" href="inventario_historico.php">Inventario histórico</a></li> <!-- ✅ NUEVO solo Logística -->
            <?php else: ?>
              <?php if (in_array($rolUsuario, ['Ejecutivo','Gerente'])): ?>
                <li><a class="dropdown-item" href="panel.php">Inventario sucursal</a></li>
              <?php endif; ?>
              <?php if (in_array($rolUsuario, ['Admin','Subdistribuidor','Super'])): ?>
                <li><a class="dropdown-item" href="inventario_subdistribuidor.php">Inventario subdistribuidor</a></li>
              <?php endif; ?>
              <?php if (in_array($rolUsuario, ['Admin','GerenteZona','Super'])): ?>
                <li><a class="dropdown-item" href="inventario_global.php">Inventario global</a></li>
              <?php endif; ?>
              <?php if (in_array($rolUsuario, ['Admin','Super'])): ?>
                <li><hr class="dropdown-divider"></li>
                <li class="dropdown-header">Administrador</li>
                <li><a class="dropdown-item" href="inventario_resumen.php">Resumen Global</a></li>
                <li><a class="dropdown-item" href="inventario_central.php">Inventario Angelopolis</a></li>
                <li><a class="dropdown-item" href="inventario_retiros.php">🛑 Retiros de Inventario</a></li>
              <?php endif; ?>
            <?php endif; ?>
          </ul>
        </li>

        <!-- COMPRAS (Admin o Logística) -->
        <?php if (in_array($rolUsuario, ['Admin','Super','Logistica'])): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Compras</a>
          <ul class="dropdown-menu dropdown-menu-dark">
            <li><a class="dropdown-item" href="compras_nueva.php">Nueva factura</a></li>
            <li><a class="dropdown-item" href="compras_resumen.php">Resumen de compras</a></li>
            <li><a class="dropdown-item" href="modelos.php">Catálogo de modelos</a></li>
            <li><a class="dropdown-item" href="proveedores.php">Proveedores</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="compras_resumen.php?estado=Pendiente">Ingreso a almacén (pendientes)</a></li>
            <li><a class="dropdown-item disabled" href="#" tabindex="-1" aria-disabled="true" title="Se accede desde el Resumen">compras_ingreso.php (directo)</a></li>
          </ul>
        </li>
        <?php endif; ?>

        <!-- TRASPASOS (no Logística) -->
        <?php if (in_array($rolUsuario, ['Gerente','Admin','Super'])): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
            Traspasos
            <?php if ((int)$badgeTraspasos > 0): ?>
              <span class="badge rounded-pill text-bg-danger ms-1"><?= (int)$badgeTraspasos ?></span>
            <?php endif; ?>
          </a>
          <ul class="dropdown-menu dropdown-menu-dark">
            <?php if (in_array($rolUsuario, ['Admin','Super'])): ?>
              <li><a class="dropdown-item" href="generar_traspaso.php">Generar traspaso desde Central</a></li>
            <?php endif; ?>
            <li><a class="dropdown-item" href="generar_traspaso_sims.php">Generar traspaso SIMs</a></li>
            <li><hr class="dropdown-divider"></li>
            <li class="dropdown-header">SIMs</li>
            <li><a class="dropdown-item" href="traspasos_sims_pendientes.php">SIMs pendientes</a></li>
            <li><a class="dropdown-item" href="traspasos_sims_salientes.php">SIMs salientes</a></li>
            <?php if ($rolUsuario === 'Gerente'): ?>
              <li><hr class="dropdown-divider"></li>
              <li class="dropdown-header">Equipos</li>
              <li><a class="dropdown-item" href="traspaso_nuevo.php">Generar traspaso entre sucursales</a></li>
            <?php endif; ?>
            <li><hr class="dropdown-divider"></li>
            <li class="dropdown-header">Historial de equipos</li>
            <li><a class="dropdown-item" href="traspasos_pendientes.php">Historial traspasos entrantes</a></li>
            <li><a class="dropdown-item" href="traspasos_salientes.php">Historial traspasos salientes</a></li>
          </ul>
        </li>
        <?php endif; ?>

        <!-- EFECTIVO (oculto para Logística) -->
        <?php if ($rolUsuario !== 'Logistica'): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Efectivo</a>
          <ul class="dropdown-menu dropdown-menu-dark">
            <li><a class="dropdown-item" href="cobros.php">Generar cobro</a></li>
            <li><a class="dropdown-item" href="cortes_caja.php">Corte de caja</a></li>
            <li><a class="dropdown-item" href="generar_corte.php">Generar corte sucursal</a></li>
            <li><a class="dropdown-item" href="depositos_sucursal.php">Depósitos sucursal</a></li>
            <?php if (in_array($rolUsuario, ['Admin','Super'])): ?>
              <li><a class="dropdown-item" href="depositos.php">Validar depósitos</a></li>
            <?php endif; ?>
            <?php if ($rolUsuario === 'GerenteZona'): ?>
              <li><hr class="dropdown-divider"></li>
              <li class="dropdown-header">Comisiones</li>
              <li><a class="dropdown-item" href="recoleccion_comisiones.php">Recolección comisiones</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>

        <!-- OPERACIÓN -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Operación</a>
          <ul class="dropdown-menu dropdown-menu-dark">
            <?php if ($rolUsuario === 'Logistica'): ?>
              <li><a class="dropdown-item" href="lista_precios.php">Lista de precios</a></li>
            <?php else: ?>
              <li><a class="dropdown-item" href="lista_precios.php">Lista de precios</a></li>
              <?php if (in_array($rolUsuario, ['Ejecutivo','Gerente'])): ?>
                <li><a class="dropdown-item" href="prospectos.php">Prospectos</a></li>
              <?php endif; ?>
              <?php if ($rolUsuario === 'Gerente'): ?>
                <li><a class="dropdown-item" href="insumos_pedido.php">Pedido de insumos</a></li>
              <?php endif; ?>
              <?php if (in_array($rolUsuario, ['Admin','Super'])): ?>
                <li><a class="dropdown-item" href="insumos_admin.php">Administrar insumos</a></li>
              <?php endif; ?>
              <?php if (in_array($rolUsuario, ['Gerente','Admin','Super'])): ?>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="gestionar_usuarios.php">Gestionar usuarios</a></li>
              <?php endif; ?>
              <?php if (in_array($rolUsuario, ['Gerente','GerenteZona','GerenteSucursal','Admin','Super'])): ?>
                <li><hr class="dropdown-divider"></li>
                <li class="dropdown-header">Mantenimiento</li>
                <?php if (in_array($rolUsuario, ['Gerente','GerenteZona','GerenteSucursal'])): ?>
                  <li><a class="dropdown-item" href="mantenimiento_solicitar.php">Solicitar mantenimiento</a></li>
                <?php endif; ?>
                <?php if (in_array($rolUsuario, ['Admin','Super'])): ?>
                  <li><a class="dropdown-item" href="mantenimiento_admin.php">Administrar solicitudes</a></li>
                <?php endif; ?>
              <?php endif; ?>
            <?php endif; ?>
          </ul>
        </li>

        <!-- RH & OPERATIVOS solo Admin/Super -->
        <?php if (in_array($rolUsuario, ['Admin','Super'])): ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">RH</a>
            <ul class="dropdown-menu dropdown-menu-dark">
              <li><a class="dropdown-item" href="reporte_nomina.php">Reporte semanal</a></li>
              <li><a class="dropdown-item" href="reporte_nomina_gerentes_zona.php">Gerentes zona</a></li>
              <li><hr class="dropdown-divider"></li>
              <li class="dropdown-header">Expedientes</li>
              <li><a class="dropdown-item" href="admin_expedientes.php">Panel de expedientes</a></li>
            </ul>
          </li>

          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Operativos</a>
            <ul class="dropdown-menu dropdown-menu-dark">
              <li><a class="dropdown-item" href="insumos_catalogo.php">Catálogo de insumos</a></li>
              <li><a class="dropdown-item" href="actualizar_precios_modelo.php">Actualizar precios</a></li>
              <li><a class="dropdown-item" href="cuotas_mensuales.php">Cuotas mensuales</a></li>
              <li><a class="dropdown-item" href="cuotas_mensuales_ejecutivos.php">Cuotas ejecutivos</a></li>
              <li><a class="dropdown-item" href="cuotas_sucursales.php">Cuotas sucursales</a></li>
              <li><a class="dropdown-item" href="cargar_cuotas_semanales.php">Cuotas semanales</a></li>
              <li><a class="dropdown-item" href="esquemas_comisiones_ejecutivos.php">Esquema ejecutivos</a></li>
              <li><a class="dropdown-item" href="esquemas_comisiones_gerentes.php">Esquema gerentes</a></li>
              <li><a class="dropdown-item" href="esquemas_comisiones_pospago.php">Esquema pospago</a></li>
              <li><a class="dropdown-item" href="comisiones_especiales_equipos.php">Comisiones escalables</a></li>
              <li><a class="dropdown-item" href="carga_masiva_productos.php">Carga masiva equipos</a></li>
              <li><a class="dropdown-item" href="carga_masiva_sims.php">Carga masiva SIMs</a></li>
              <li><a class="dropdown-item" href="alta_usuario.php">Alta usuario</a></li>
              <li><a class="dropdown-item" href="alta_sucursal.php">Alta sucursal</a></li>
            </ul>
          </li>
        <?php endif; ?>

        <!-- CELEBRACIONES (oculto para Logística) -->
        <?php if ($rolUsuario !== 'Logistica'): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Celebraciones</a>
          <ul class="dropdown-menu dropdown-menu-dark">
            <li><a class="dropdown-item" href="cumples_aniversarios.php">🎉 Cumpleaños & Aniversarios</a></li>
          </ul>
        </li>
        <?php endif; ?>

      </ul>

      <!-- DERECHA: Perfil (avatar / iniciales) -->
      <ul class="navbar-nav ms-auto">
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown">
            <span class="me-2 position-relative">
              <?php if ($avatarUrl): ?>
                <img
                  src="<?= e($avatarUrl) ?>"
                  alt="avatar"
                  class="nav-avatar"
                  onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-flex';"
                >
                <span class="nav-initials" style="display:none;"><?= e(initials($nombreUsuario)) ?></span>
              <?php else: ?>
                <span class="nav-initials"><?= e(initials($nombreUsuario)) ?></span>
              <?php endif; ?>
            </span>
            <span class="me-1"><?= e($nombreUsuario) ?></span>
            <?php if ($sucursalNombre): ?>
              <small class="text-secondary d-none d-lg-inline">| <?= e($sucursalNombre) ?></small>
            <?php endif; ?>
          </a>
          <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark">
            <li class="px-3 py-2">
              <?php if ($avatarUrl): ?>
                <img
                  src="<?= e($avatarUrl) ?>"
                  class="dropdown-avatar me-2"
                  alt="avatar"
                  onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-flex';"
                >
                <span class="dropdown-initials me-2" style="display:none;"><?= e(initials($nombreUsuario)) ?></span>
              <?php else: ?>
                <span class="dropdown-initials me-2"><?= e(initials($nombreUsuario)) ?></span>
              <?php endif; ?>
              <div class="d-inline-block align-middle">
                <div class="fw-semibold"><?= e($nombreUsuario) ?></div>
                <?php if ($sucursalNombre): ?>
                  <div class="text-secondary small"><?= e($sucursalNombre) ?></div>
                <?php endif; ?>
                <!-- ✅ Mostrar rol del usuario en el desplegable -->
                <div class="text-secondary small">Rol: <?= e($rolUsuario) ?></div>
              </div>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="expediente_usuario.php">Mi expediente</a></li>
            <li><a class="dropdown-item" href="documentos_historial.php">Mis documentos</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="logout.php">Salir</a></li>
          </ul>
        </li>
      </ul>
    </div>
  </div>
</nav>
