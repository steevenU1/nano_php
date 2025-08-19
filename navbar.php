<?php
// navbar.php — versión “bonita” con activo automático, nombre corto y "Gestionar usuarios" (Gerente, Admin, GerenteZona)
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
include 'db.php';

date_default_timezone_set('America/Mexico_City');

$rolUsuario     = $_SESSION['rol'] ?? 'Ejecutivo';
$nombreUsuario  = $_SESSION['nombre'] ?? 'Usuario';
$idUsuario      = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal     = (int)($_SESSION['id_sucursal'] ?? 0);

// ===== Helpers =====
if (!function_exists('str_starts_with')) {
  function str_starts_with($haystack, $needle)
  {
    return (string)$needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
  }
}
if (!function_exists('str_ends_with')) {
  function str_ends_with($haystack, $needle)
  {
    return $needle === '' || substr($haystack, -strlen($needle)) === (string)$needle;
  }
}
function e($s)
{
  return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}
function initials($name)
{
  $name = trim((string)$name);
  if ($name === '') return 'U';
  $parts = preg_split('/\s+/', $name);
  $first = mb_substr($parts[0] ?? '', 0, 1, 'UTF-8');
  $last  = mb_substr($parts[count($parts) - 1] ?? '', 0, 1, 'UTF-8');
  $ini = mb_strtoupper($first . $last, 'UTF-8');
  return $ini ?: 'U';
}
function firstName($name)
{
  $name = trim((string)$name);
  if ($name === '') return 'Usuario';
  $parts = preg_split('/\s+/', $name);
  return $parts[0] ?? $name;
}

/** Resuelve URL visible para avatar guardado en BD */
function resolveAvatarUrl(?string $fotoBD): ?string
{
  $f = trim((string)$fotoBD);
  if ($f === '') return null;
  if (preg_match('#^(https?://|data:image/)#i', $f)) return $f;
  $f = str_replace('\\', '/', $f);

  $docroot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/') : '';
  $appDir  = rtrim(str_replace('\\', '/', __DIR__), '/');
  $baseUri = '';
  if ($docroot && str_starts_with($appDir, $docroot)) {
    $baseUri = substr($appDir, strlen($docroot));
  }

  // Ruta absoluta
  if (preg_match('#^[A-Za-z]:/|^/#', $f)) {
    if ($docroot && str_starts_with($f, $docroot . '/')) return substr($f, strlen($docroot));
    $base = basename($f);
    $dirs = ['uploads/expedientes', 'expedientes', 'uploads', 'uploads/expedientes/fotos', 'expedientes/fotos', 'uploads/usuarios', 'usuarios', 'uploads/perfiles', 'perfiles'];
    foreach ($dirs as $d) {
      $abs = $appDir . '/' . $d . '/' . $base;
      if (is_file($abs)) return $baseUri . '/' . $d . '/' . $base;
    }
    return null;
  }
  // Ruta web absoluta
  if (str_starts_with($f, '/')) {
    if ($docroot && is_file($docroot . $f)) return $f;
    return $f;
  }
  // Relativas
  if (is_file($appDir . '/' . $f)) return $baseUri . '/' . ltrim($f, '/');
  if ($docroot && is_file($docroot . '/' . $f)) return '/' . ltrim($f, '/');

  $base = basename($f);
  $dirs = ['uploads/expedientes', 'expedientes', 'uploads', 'uploads/expedientes/fotos', 'expedientes/fotos', 'uploads/usuarios', 'usuarios', 'uploads/perfiles', 'perfiles'];
  foreach ($dirs as $d) {
    $abs = $appDir . '/' . $d . '/' . $base;
    if (is_file($abs)) return $baseUri . '/' . $d . '/' . $base;
  }
  return null;
}

// Avatar (columna correcta: usuario_id)
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

// Sucursal (solo para mostrar en el desplegable)
$sucursalNombre = '';
if ($idSucursal > 0) {
  $stmt = $conn->prepare("SELECT nombre FROM sucursales WHERE id=?");
  $stmt->bind_param("i", $idSucursal);
  $stmt->execute();
  $stmt->bind_result($sucursalNombre);
  $stmt->fetch();
  $stmt->close();
}

// Badge traspasos
$badgeTraspasos = 0;
if ($idSucursal > 0) {
  $stmt = $conn->prepare("SELECT COUNT(*) FROM traspasos WHERE id_sucursal_destino=? AND estatus='Pendiente'");
  $stmt->bind_param("i", $idSucursal);
  $stmt->execute();
  $stmt->bind_result($badgeTraspasos);
  $stmt->fetch();
  $stmt->close();
}

$esAdmin = in_array($rolUsuario, ['Admin', 'Super']);

// ========= Estado Activo por URL =========
$current = strtolower(basename(parse_url($_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF'], PHP_URL_PATH)));
function isActive(array $files)
{
  global $current;
  return in_array($current, array_map('strtolower', $files), true) ? ' active' : '';
}
function parentActive(array $children)
{
  return isActive($children) ? ' active' : '';
}

// Grupos por sección (para resaltar el dropdown padre)
$grpDashboard  = ['productividad_dia.php', 'dashboard_unificado.php', 'dashboard_mensual.php'];
$grpVentasLog  = ['historial_ventas.php', 'historial_ventas_sims.php', 'historial_ventas_ma.php'];
$grpVentas     = array_merge($grpVentasLog, ['nueva_venta.php', 'venta_sim_prepago.php', 'venta_sim_pospago.php', 'venta_master_admin.php']);
$grpInvLog     = ['inventario_global.php', 'inventario_historico.php'];
$grpInv        = array_merge($grpInvLog, ['panel.php', 'inventario_subdistribuidor.php', 'inventario_resumen.php', 'inventario_central.php', 'inventario_retiros.php']);
$grpCompras    = ['compras_nueva.php', 'compras_resumen.php', 'modelos.php', 'proveedores.php'];
$grpTraspasos  = ['generar_traspaso.php', 'generar_traspaso_sims.php', 'traspasos_sims_pendientes.php', 'traspasos_sims_salientes.php', 'traspaso_nuevo.php', 'traspasos_pendientes.php', 'traspasos_salientes.php'];
$grpEfectivo   = ['cobros.php', 'cortes_caja.php', 'generar_corte.php', 'depositos_sucursal.php', 'depositos.php', 'recoleccion_comisiones.php'];
$grpOperacion  = ['lista_precios.php', 'prospectos.php', 'insumos_pedido.php', 'insumos_admin.php', 'gestionar_usuarios.php', 'mantenimiento_solicitar.php', 'mantenimiento_admin.php'];
$grpRH         = ['reporte_nomina.php', 'reporte_nomina_gerentes_zona.php', 'admin_expedientes.php'];
$grpOperativos = ['insumos_catalogo.php', 'actualizar_precios_modelo.php', 'cuotas_mensuales.php', 'cuotas_mensuales_ejecutivos.php', 'cuotas_sucursales.php', 'cargar_cuotas_semanales.php', 'esquemas_comisiones_ejecutivos.php', 'esquemas_comisiones_gerentes.php', 'esquemas_comisiones_pospago.php', 'comisiones_especiales_equipos.php', 'carga_masiva_productos.php', 'carga_masiva_sims.php', 'alta_usuario.php', 'alta_sucursal.php'];
$grpCeleb      = ['cumples_aniversarios.php','cuadro_honor.php'];

// Nombre corto para topbar
$nombreCorto = firstName($nombreUsuario);
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

<style>
  :root {
    --nb-bg: rgba(16, 18, 27, 0.78);
    --nb-grad-1: rgba(0, 153, 255, 0.10);
    --nb-grad-2: rgba(0, 255, 204, 0.08);
    --nb-border: rgba(255, 255, 255, .08);
    --nb-text: #f8f9fa;
    --nb-muted: #adb5bd;
    --nb-accent: #0d6efd;
    --nb-hover: rgba(255, 255, 255, .06);
    --nb-underline: #66d9ff;
    --nb-ring: #22d3ee;

    /*  Ajustes de tamaño para ganar espacio */
    --nb-font-brand: .98rem;     /* Central2.0 */
    --nb-font-link:  .90rem;     /* Links del navbar */
    --nb-font-drop:  .88rem;     /* Items de dropdown */
    --nb-font-head:  .72rem;     /* Encabezados del dropdown */
    --nb-link-xpad:  .55rem;     /* Padding horizontal de cada link */
  }

  .navbar.pretty {
    background: linear-gradient(90deg, var(--nb-grad-1), var(--nb-grad-2)), var(--nb-bg);
    backdrop-filter: saturate(140%) blur(9px);
    -webkit-backdrop-filter: saturate(140%) blur(9px);
    border-bottom: 1px solid var(--nb-border);
  }

  .navbar.pretty .navbar-brand {
    font-weight: 700;
    letter-spacing: .2px;
    color: var(--nb-text);
    font-size: var(--nb-font-brand);
    line-height: 1.1;
  }

  .navbar.pretty .navbar-brand .brand-title {
    background: linear-gradient(90deg, #a8e6ff, #7cf6d7, #a8e6ff);
    background-size: 200% 100%;
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
    animation: brandGradient 8s linear infinite;
    font-size: inherit; /* usa --nb-font-brand */
  }

  @keyframes brandGradient {
    0% { background-position: 0% 50%; }
    100% { background-position: 200% 50%; }
  }
  @media (prefers-reduced-motion: reduce) {
    .brand-title { animation: none !important; }
  }

  /* Links más compactos */
  .navbar.pretty .navbar-nav .nav-link {
    color: var(--nb-text);
    opacity: .92;
    position: relative;
    transition: opacity .2s ease, transform .2s ease;
    font-size: var(--nb-font-link);
    line-height: 1.15;
    padding-left: var(--nb-link-xpad);
    padding-right: var(--nb-link-xpad);
  }
  .navbar.pretty .navbar-nav .nav-link:hover {
    opacity: 1;
    transform: translateY(-1px);
  }
  .navbar.pretty .nav-link::after {
    content: "";
    position: absolute;
    left: 10px;
    right: 10px;
    bottom: 6px;
    height: 2px;
    background: linear-gradient(90deg, transparent, var(--nb-underline), transparent);
    transform: scaleX(0);
    transform-origin: center;
    transition: transform .22s ease-out;
  }
  .navbar.pretty .nav-link:hover::after,
  .navbar.pretty .nav-link.active::after {
    transform: scaleX(1);
  }
  .navbar.pretty .nav-link.active,
  .navbar.pretty .dropdown-item.active {
    color: #fff !important;
    font-weight: 600;
  }

  /*  Dropdown más compacto */
  .dropdown-menu.dropdown-menu-dark {
    background: rgba(18, 20, 26, .98);
    border: 1px solid var(--nb-border);
    box-shadow: 0 12px 30px rgba(0, 0, 0, .35);
    border-radius: 14px;
    padding: .35rem;
  }
  .dropdown-menu-dark .dropdown-item {
    border-radius: 10px;
    color: var(--nb-text);
    font-size: var(--nb-font-drop);
    line-height: 1.15;
    padding: .35rem .65rem; /* menos padding horizontal */
  }
  .dropdown-menu-dark .dropdown-item:hover {
    background: var(--nb-hover);
    color: #fff;
  }
  .dropdown-menu-dark .dropdown-header {
    color: var(--nb-muted);
    font-size: var(--nb-font-head);
    letter-spacing: .4px;
    text-transform: uppercase;
    padding: .25rem .65rem;
  }
  .dropdown-divider { border-top-color: var(--nb-border) !important; }

  /* Avatar / iniciales (igual) */
  .nav-avatar,
  .nav-initials {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: .9rem;
    color: #fff;
    position: relative;
    box-shadow: 0 0 0 2px rgba(255, 255, 255, .15);
  }
  .nav-avatar { object-fit: cover; }
  .nav-avatar-ring,
  .nav-initials::before {
    content: "";
    position: absolute; inset: -3px;
    border-radius: 50%;
    background: conic-gradient(from 0deg, var(--nb-ring), transparent 60%, var(--nb-ring));
    filter: blur(.6px); opacity: .55; z-index: -1;
  }
  .nav-initials { background: #64748b; }
  .dropdown-avatar,
  .dropdown-initials {
    width: 58px; height: 58px; border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    background: #64748b; color: #fff; font-weight: 800; font-size: 1.05rem;
    box-shadow: 0 0 0 3px rgba(255, 255, 255, .12);
  }
  .dropdown-avatar { object-fit: cover; background: #222; }

  .badge.rounded-pill { font-weight: 600; }

  .navbar-toggler { border-color: var(--nb-border) !important; }
  .navbar-toggler:focus { box-shadow: 0 0 0 .2rem rgba(13, 110, 253, .35); }

  .text-secondary { color: var(--nb-muted) !important; }

  /* 🔹 Ajustes extra en desktop: aún más compacto para pantallas grandes con muchos menús */
  @media (min-width: 992px) {
    .navbar.pretty .navbar-nav .nav-link {
      font-size: .88rem;
      padding-left: .5rem;
      padding-right: .5rem;
    }
    .navbar.pretty .navbar-brand { font-size: .95rem; }
    .dropdown-menu-dark .dropdown-item { font-size: .86rem; }
    .dropdown-menu-dark .dropdown-header { font-size: .70rem; }
  }
</style>

<nav class="navbar navbar-expand-lg navbar-dark sticky-top shadow pretty">
  <div class="container-fluid">

    <!-- LOGO -->
    <a class="navbar-brand d-flex align-items-center" href="dashboard_unificado.php">
      <img src="https://i.ibb.co/kgT18Gx8/4e68073d-5ca3-4c71-8bf5-9756511d74fb.png"
        alt="Logo"
        width="36"
        height="36"
        class="me-2 rounded-circle"
        style="object-fit:cover;">
      <span class="brand-title">Central2.0</span>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navbarMain">
      <!-- IZQUIERDA -->
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">

        <!-- DASHBOARD -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle<?= parentActive($grpDashboard) ?>" href="#" role="button" data-bs-toggle="dropdown"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
          <ul class="dropdown-menu dropdown-menu-dark">
            <li><a class="dropdown-item<?= isActive(['productividad_dia.php']) ?>" href="productividad_dia.php"><i class="bi bi-bar-chart-line me-2"></i>Dashboard Diario</a></li>
            <li><a class="dropdown-item<?= isActive(['dashboard_unificado.php']) ?>" href="dashboard_unificado.php"><i class="bi bi-calendar-week me-2"></i>Dashboard semanal</a></li>
            <li><a class="dropdown-item<?= isActive(['dashboard_mensual.php']) ?>" href="dashboard_mensual.php"><i class="bi bi-calendar3 me-2"></i>Dashboard mensual</a></li>
          </ul>
        </li>

        <!-- VENTAS -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle<?= parentActive($grpVentas) ?>" href="#" role="button" data-bs-toggle="dropdown"><i class="bi bi-bag-check me-1"></i>Ventas</a>
          <ul class="dropdown-menu dropdown-menu-dark">
            <?php if ($rolUsuario === 'Logistica'): ?>
              <!-- Solo historiales para Logística -->
              <li class="dropdown-header">Historiales</li>
              <li><a class="dropdown-item<?= isActive(['historial_ventas.php']) ?>" href="historial_ventas.php"><i class="bi bi-clock-history me-2"></i>Historial de ventas</a></li>
              <li><a class="dropdown-item<?= isActive(['historial_ventas_sims.php']) ?>" href="historial_ventas_sims.php"><i class="bi bi-sim me-2"></i>Historial ventas SIM</a></li>
            <?php else: ?>
              <!-- Sección: Ventas -->
              <li class="dropdown-header">Ventas</li>
              <li><a class="dropdown-item<?= isActive(['nueva_venta.php']) ?>" href="nueva_venta.php"><i class="bi bi-phone me-2"></i>Venta equipos</a></li>
              <li><a class="dropdown-item<?= isActive(['venta_sim_prepago.php']) ?>" href="venta_sim_prepago.php"><i class="bi bi-sim me-2"></i>Venta SIM prepago</a></li>
              <li><a class="dropdown-item<?= isActive(['venta_sim_pospago.php']) ?>" href="venta_sim_pospago.php"><i class="bi bi-sim-fill me-2"></i>Venta SIM pospago</a></li>
              <?php if ($rolUsuario === 'Admin'): ?>
                <li><a class="dropdown-item<?= isActive(['venta_master_admin.php']) ?>" href="venta_master_admin.php"><i class="bi bi-shield-lock me-2"></i>Venta Master Admin</a></li>
              <?php endif; ?>

              <li><hr class="dropdown-divider"></li>

              <!-- Sección: Historiales -->
              <li class="dropdown-header">Historiales</li>
              <li><a class="dropdown-item<?= isActive(['historial_ventas.php']) ?>" href="historial_ventas.php"><i class="bi bi-clock-history me-2"></i>Historial de ventas</a></li>
              <li><a class="dropdown-item<?= isActive(['historial_ventas_sims.php']) ?>" href="historial_ventas_sims.php"><i class="bi bi-list-check me-2"></i>Historial ventas SIM</a></li>
              <?php if ($rolUsuario === 'Admin'): ?>
                <li><a class="dropdown-item<?= isActive(['historial_ventas_ma.php']) ?>" href="historial_ventas_ma.php"><i class="bi bi-clock-history me-2"></i>Historial ventas MA</a></li>
              <?php endif; ?>
            <?php endif; ?>
          </ul>
        </li>

        <!-- INVENTARIO -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle<?= parentActive($grpInv) ?>" href="#" role="button" data-bs-toggle="dropdown"><i class="bi bi-box-seam me-1"></i>Inventario</a>
          <ul class="dropdown-menu dropdown-menu-dark">
            <?php if ($rolUsuario === 'Logistica'): ?>
              <li><a class="dropdown-item<?= isActive(['inventario_global.php']) ?>" href="inventario_global.php"><i class="bi bi-globe2 me-2"></i>Inventario global</a></li>
              <li><a class="dropdown-item<?= isActive(['inventario_historico.php']) ?>" href="inventario_historico.php"><i class="bi bi-clock me-2"></i>Inventario histórico</a></li>
            <?php else: ?>
              <?php if (in_array($rolUsuario, ['Ejecutivo', 'Gerente'])): ?>
                <li><a class="dropdown-item<?= isActive(['panel.php']) ?>" href="panel.php"><i class="bi bi-shop-window me-2"></i>Inventario sucursal</a></li>
              <?php endif; ?>
              <?php if (in_array($rolUsuario, ['Admin', 'Subdistribuidor', 'Super'])): ?>
                <li><a class="dropdown-item<?= isActive(['inventario_subdistribuidor.php']) ?>" href="inventario_subdistribuidor.php"><i class="bi bi-people me-2"></i>Inventario subdistribuidor</a></li>
              <?php endif; ?>
              <?php if (in_array($rolUsuario, ['Admin', 'GerenteZona', 'Super'])): ?>
                <li><a class="dropdown-item<?= isActive(['inventario_global.php']) ?>" href="inventario_global.php"><i class="bi bi-globe2 me-2"></i>Inventario global</a></li>
              <?php endif; ?>
              <?php if (in_array($rolUsuario, ['Admin', 'Super'])): ?>
                <li><hr class="dropdown-divider"></li>
                <li class="dropdown-header">Administrador</li>
                <li><a class="dropdown-item<?= isActive(['inventario_resumen.php']) ?>" href="inventario_resumen.php"><i class="bi bi-layout-text-window-reverse me-2"></i>Resumen Global</a></li>
                <li><a class="dropdown-item<?= isActive(['inventario_central.php']) ?>" href="inventario_central.php"><i class="bi bi-buildings me-2"></i>Inventario Angelopolis</a></li>
                <li><a class="dropdown-item<?= isActive(['inventario_retiros.php']) ?>" href="inventario_retiros.php"><i class="bi bi-exclamation-octagon me-2"></i>Retiros de Inventario</a></li>
              <?php endif; ?>
            <?php endif; ?>
          </ul>
        </li>

        <!-- COMPRAS (Admin o Logística) -->
        <?php if (in_array($rolUsuario, ['Admin', 'Super', 'Logistica'])): ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle<?= parentActive($grpCompras) ?>" href="#" role="button" data-bs-toggle="dropdown"><i class="bi bi-receipt me-1"></i>Compras</a>
            <ul class="dropdown-menu dropdown-menu-dark">
              <li><a class="dropdown-item<?= isActive(['compras_nueva.php']) ?>" href="compras_nueva.php"><i class="bi bi-file-earmark-plus me-2"></i>Nueva factura</a></li>
              <li><a class="dropdown-item<?= isActive(['compras_resumen.php']) ?>" href="compras_resumen.php"><i class="bi bi-journal-text me-2"></i>Resumen de compras</a></li>
              <li><a class="dropdown-item<?= isActive(['modelos.php']) ?>" href="modelos.php"><i class="bi bi-collection me-2"></i>Catálogo de modelos</a></li>
              <li><a class="dropdown-item<?= isActive(['proveedores.php']) ?>" href="proveedores.php"><i class="bi bi-truck me-2"></i>Proveedores</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item<?= isActive(['compras_resumen.php']) ?>" href="compras_resumen.php?estado=Pendiente"><i class="bi bi-box-arrow-in-down me-2"></i>Ingreso a almacén (pendientes)</a></li>
              <li><a class="dropdown-item disabled" href="#" tabindex="-1" aria-disabled="true" title="Se accede desde el Resumen"><i class="bi bi-diagram-3 me-2"></i>compras_ingreso.php (directo)</a></li>
            </ul>
          </li>
        <?php endif; ?>

        <!-- TRASPASOS (no Logística) -->
        <?php if (in_array($rolUsuario, ['Gerente', 'Admin', 'Super'])): ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle<?= parentActive($grpTraspasos) ?>" href="#" role="button" data-bs-toggle="dropdown">
              <i class="bi bi-arrow-left-right me-1"></i>Traspasos
              <?php if ((int)$badgeTraspasos > 0): ?>
                <span class="badge rounded-pill text-bg-danger ms-1"><?= (int)$badgeTraspasos ?></span>
              <?php endif; ?>
            </a>
            <ul class="dropdown-menu dropdown-menu-dark">
              <?php if (in_array($rolUsuario, ['Admin', 'Super'])): ?>
                <li><a class="dropdown-item<?= isActive(['generar_traspaso.php']) ?>" href="generar_traspaso.php"><i class="bi bi-building-gear me-2"></i>Generar traspaso desde Central</a></li>
              <?php endif; ?>
              <li><a class="dropdown-item<?= isActive(['generar_traspaso_sims.php']) ?>" href="generar_traspaso_sims.php"><i class="bi bi-sim me-2"></i>Generar traspaso SIMs</a></li>
              <li><hr class="dropdown-divider"></li>
              <li class="dropdown-header">SIMs</li>
              <li><a class="dropdown-item<?= isActive(['traspasos_sims_pendientes.php']) ?>" href="traspasos_sims_pendientes.php"><i class="bi bi-hourglass-split me-2"></i>SIMs pendientes</a></li>
              <li><a class="dropdown-item<?= isActive(['traspasos_sims_salientes.php']) ?>" href="traspasos_sims_salientes.php"><i class="bi bi-send me-2"></i>SIMs salientes</a></li>
              <?php if ($rolUsuario === 'Gerente'): ?>
                <li><hr class="dropdown-divider"></li>
                <li class="dropdown-header">Equipos</li>
                <li><a class="dropdown-item<?= isActive(['traspaso_nuevo.php']) ?>" href="traspaso_nuevo.php"><i class="bi bi-shuffle me-2"></i>Generar traspaso entre sucursales</a></li>
              <?php endif; ?>
              <li><hr class="dropdown-divider"></li>
              <li class="dropdown-header">Historial de equipos</li>
              <li><a class="dropdown-item<?= isActive(['traspasos_pendientes.php']) ?>" href="traspasos_pendientes.php"><i class="bi bi-inboxes me-2"></i>Historial traspasos entrantes</a></li>
              <li><a class="dropdown-item<?= isActive(['traspasos_salientes.php']) ?>" href="traspasos_salientes.php"><i class="bi bi-box-arrow-up-right me-2"></i>Historial traspasos salientes</a></li>
            </ul>
          </li>
        <?php endif; ?>

        <!-- EFECTIVO (oculto para Logística) -->
        <?php if ($rolUsuario !== 'Logistica'): ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle<?= parentActive($grpEfectivo) ?>" href="#" role="button" data-bs-toggle="dropdown"><i class="bi bi-cash-coin me-1"></i>Efectivo</a>
            <ul class="dropdown-menu dropdown-menu-dark">
              <li><a class="dropdown-item<?= isActive(['cobros.php']) ?>" href="cobros.php"><i class="bi bi-qr-code-scan me-2"></i>Generar cobro</a></li>
              <li><a class="dropdown-item<?= isActive(['cortes_caja.php']) ?>" href="cortes_caja.php"><i class="bi bi-scissors me-2"></i>Historial Cortes</a></li>
              <li><a class="dropdown-item<?= isActive(['generar_corte.php']) ?>" href="generar_corte.php"><i class="bi bi-calendar2-week me-2"></i>Generar corte sucursal</a></li>
              <li><a class="dropdown-item<?= isActive(['depositos_sucursal.php']) ?>" href="depositos_sucursal.php"><i class="bi bi-bank me-2"></i>Depósitos sucursal</a></li>
              <?php if (in_array($rolUsuario, ['Admin', 'Super'])): ?>
                <li><a class="dropdown-item<?= isActive(['depositos.php']) ?>" href="depositos.php"><i class="bi bi-clipboard-check me-2"></i>Validar depósitos</a></li>
              <?php endif; ?>
              <?php if ($rolUsuario === 'GerenteZona'): ?>
                <li><hr class="dropdown-divider"></li>
                <li class="dropdown-header">Comisiones</li>
                <li><a class="dropdown-item<?= isActive(['recoleccion_comisiones.php']) ?>" href="recoleccion_comisiones.php"><i class="bi bi-currency-dollar me-2"></i>Recolección comisiones</a></li>
              <?php endif; ?>
            </ul>
          </li>
        <?php endif; ?>

        <!-- OPERACIÓN -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle<?= parentActive($grpOperacion) ?>" href="#" role="button" data-bs-toggle="dropdown"><i class="bi bi-gear-wide-connected me-1"></i>Operación</a>
          <ul class="dropdown-menu dropdown-menu-dark">
            <?php if ($rolUsuario === 'Logistica'): ?>
              <li><a class="dropdown-item<?= isActive(['lista_precios.php']) ?>" href="lista_precios.php"><i class="bi bi-tag me-2"></i>Lista de precios</a></li>
            <?php else: ?>
              <li><a class="dropdown-item<?= isActive(['lista_precios.php']) ?>" href="lista_precios.php"><i class="bi bi-tag me-2"></i>Lista de precios</a></li>

              <?php if (in_array($rolUsuario, ['Ejecutivo', 'Gerente'])): ?>
                <li><a class="dropdown-item<?= isActive(['prospectos.php']) ?>" href="prospectos.php"><i class="bi bi-person-lines-fill me-2"></i>Prospectos</a></li>
              <?php endif; ?>

              <?php if ($rolUsuario === 'Gerente'): ?>
                <li><a class="dropdown-item<?= isActive(['insumos_pedido.php']) ?>" href="insumos_pedido.php"><i class="bi bi-box2-heart me-2"></i>Pedido de insumos</a></li>
              <?php endif; ?>

              <?php if (in_array($rolUsuario, ['Admin', 'Super'])): ?>
                <li><a class="dropdown-item<?= isActive(['insumos_admin.php']) ?>" href="insumos_admin.php"><i class="bi bi-boxes me-2"></i>Administrar insumos</a></li>
              <?php endif; ?>

              <!-- Gestionar usuarios (Gerente, Admin, GerenteZona) -->
              <?php if (in_array($rolUsuario, ['Gerente', 'Admin', 'GerenteZona'])): ?>
                <li>
                  <a class="dropdown-item<?= isActive(['gestionar_usuarios.php']) ?>" href="gestionar_usuarios.php">
                    <i class="bi bi-people me-2"></i>Gestionar usuarios
                  </a>
                </li>
              <?php endif; ?>

              <?php if (in_array($rolUsuario, ['Gerente', 'GerenteZona', 'GerenteSucursal', 'Admin', 'Super'])): ?>
                <li><hr class="dropdown-divider"></li>
                <li class="dropdown-header">Mantenimiento</li>
                <?php if (in_array($rolUsuario, ['Gerente', 'GerenteZona', 'GerenteSucursal'])): ?>
                  <li><a class="dropdown-item<?= isActive(['mantenimiento_solicitar.php']) ?>" href="mantenimiento_solicitar.php"><i class="bi bi-tools me-2"></i>Solicitar mantenimiento</a></li>
                <?php endif; ?>
                <?php if (in_array($rolUsuario, ['Admin', 'Super'])): ?>
                  <li><a class="dropdown-item<?= isActive(['mantenimiento_admin.php']) ?>" href="mantenimiento_admin.php"><i class="bi bi-clipboard-pulse me-2"></i>Administrar solicitudes</a></li>
                <?php endif; ?>
              <?php endif; ?>
            <?php endif; ?>
          </ul>
        </li>

        <!-- RH & OPERATIVOS solo Admin/Super -->
        <?php if (in_array($rolUsuario, ['Admin', 'Super'])): ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle<?= parentActive($grpRH) ?>" href="#" role="button" data-bs-toggle="dropdown"><i class="bi bi-people me-1"></i>RH</a>
            <ul class="dropdown-menu dropdown-menu-dark">
              <li><a class="dropdown-item<?= isActive(['reporte_nomina.php']) ?>" href="reporte_nomina.php"><i class="bi bi-journal-check me-2"></i>Reporte semanal</a></li>
              <li><a class="dropdown-item<?= isActive(['reporte_nomina_gerentes_zona.php']) ?>" href="reporte_nomina_gerentes_zona.php"><i class="bi bi-diagram-2 me-2"></i>Gerentes zona</a></li>
              <li><hr class="dropdown-divider"></li>
              <li class="dropdown-header">Expedientes</li>
              <li><a class="dropdown-item<?= isActive(['admin_expedientes.php']) ?>" href="admin_expedientes.php"><i class="bi bi-folder-symlink me-2"></i>Panel de expedientes</a></li>
            </ul>
          </li>

          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle<?= parentActive($grpOperativos) ?>" href="#" role="button" data-bs-toggle="dropdown"><i class="bi bi-hdd-network me-1"></i>Operativos</a>
            <ul class="dropdown-menu dropdown-menu-dark">
              <li><a class="dropdown-item<?= isActive(['insumos_catalogo.php']) ?>" href="insumos_catalogo.php"><i class="bi bi-box2 me-2"></i>Catálogo de insumos</a></li>
              <li><a class="dropdown-item<?= isActive(['actualizar_precios_modelo.php']) ?>" href="actualizar_precios_modelo.php"><i class="bi bi-coin me-2"></i>Actualizar precios</a></li>
              <li><a class="dropdown-item<?= isActive(['cuotas_mensuales.php']) ?>" href="cuotas_mensuales.php"><i class="bi bi-graph-up-arrow me-2"></i>Cuotas mensuales</a></li>
              <li><a class="dropdown-item<?= isActive(['cuotas_mensuales_ejecutivos.php']) ?>" href="cuotas_mensuales_ejecutivos.php"><i class="bi bi-clipboard-data me-2"></i>Cuotas ejecutivos</a></li>
              <li><a class="dropdown-item<?= isActive(['cuotas_sucursales.php']) ?>" href="cuotas_sucursales.php"><i class="bi bi-building me-2"></i>Cuotas sucursales</a></li>
              <li><a class="dropdown-item<?= isActive(['cargar_cuotas_semanales.php']) ?>" href="cargar_cuotas_semanales.php"><i class="bi bi-calendar2-range me-2"></i>Cuotas semanales</a></li>
              <li><a class="dropdown-item<?= isActive(['esquemas_comisiones_ejecutivos.php']) ?>" href="esquemas_comisiones_ejecutivos.php"><i class="bi bi-cash me-2"></i>Esquema ejecutivos</a></li>
              <li><a class="dropdown-item<?= isActive(['esquemas_comisiones_gerentes.php']) ?>" href="esquemas_comisiones_gerentes.php"><i class="bi bi-cash-stack me-2"></i>Esquema gerentes</a></li>
              <li><a class="dropdown-item<?= isActive(['esquemas_comisiones_pospago.php']) ?>" href="esquemas_comisiones_pospago.php"><i class="bi bi-sim me-2"></i>Esquema pospago</a></li>
              <li><a class="dropdown-item<?= isActive(['comisiones_especiales_equipos.php']) ?>" href="comisiones_especiales_equipos.php"><i class="bi bi-stars me-2"></i>Comisiones escalables</a></li>
              <li><a class="dropdown-item<?= isActive(['carga_masiva_productos.php']) ?>" href="carga_masiva_productos.php"><i class="bi bi-upload me-2"></i>Carga masiva equipos</a></li>
              <li><a class="dropdown-item<?= isActive(['carga_masiva_sims.php']) ?>" href="carga_masiva_sims.php"><i class="bi bi-upload me-2"></i>Carga masiva SIMs</a></li>
              <li><a class="dropdown-item<?= isActive(['alta_usuario.php']) ?>" href="alta_usuario.php"><i class="bi bi-person-plus me-2"></i>Alta usuario</a></li>
              <li><a class="dropdown-item<?= isActive(['alta_sucursal.php']) ?>" href="alta_sucursal.php"><i class="bi bi-building-add me-2"></i>Alta sucursal</a></li>
            </ul>
          </li>
        <?php endif; ?>

        <!-- CELEBRACIONES -->
        <?php if ($rolUsuario !== 'Logistica'): ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle<?= parentActive($grpCeleb) ?>" href="#" role="button" data-bs-toggle="dropdown"><i class="bi bi-balloon-heart me-1"></i>Celebraciones</a>
            <ul class="dropdown-menu dropdown-menu-dark">
              <li><a class="dropdown-item<?= isActive(['cumples_aniversarios.php']) ?>" href="cumples_aniversarios.php">🎉 Cumpleaños & Aniversarios</a></li>
              <li><a class="dropdown-item<?= isActive(['cuadro_honor.php']) ?>" href="cuadro_honor.php">🏆 Cuadro de Honor</a></li>
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
                <span class="nav-avatar-ring"></span>
                <img
                  src="<?= e($avatarUrl) ?>"
                  alt="avatar"
                  class="nav-avatar"
                  onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-flex';">
                <span class="nav-initials" style="display:none;"><?= e(initials($nombreUsuario)) ?></span>
              <?php else: ?>
                <span class="nav-initials"><?= e(initials($nombreUsuario)) ?></span>
              <?php endif; ?>
            </span>
            <!-- Solo primer nombre en la barra superior -->
            <span class="me-1"><?= e($nombreCorto) ?></span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark">
            <li class="px-3 py-2">
              <?php if ($avatarUrl): ?>
                <img
                  src="<?= e($avatarUrl) ?>"
                  class="dropdown-avatar me-2"
                  alt="avatar"
                  onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-flex';">
                <span class="dropdown-initials me-2" style="display:none;"><?= e(initials($nombreUsuario)) ?></span>
              <?php else: ?>
                <span class="dropdown-initials me-2"><?= e(initials($nombreUsuario)) ?></span>
              <?php endif; ?>
              <div class="d-inline-block align-middle">
                <div class="fw-semibold"><?= e($nombreUsuario) ?></div>
                <?php if ($sucursalNombre): ?>
                  <div class="text-secondary small"><?= e($sucursalNombre) ?></div>
                <?php endif; ?>
                <div class="text-secondary small">Rol: <?= e($rolUsuario) ?></div>
              </div>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item<?= isActive(['expediente_usuario.php']) ?>" href="expediente_usuario.php"><i class="bi bi-person-badge me-2"></i>Mi expediente</a></li>
            <li><a class="dropdown-item<?= isActive(['documentos_historial.php']) ?>" href="documentos_historial.php"><i class="bi bi-folder2-open me-2"></i>Mis documentos</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Salir</a></li>
          </ul>
        </li>
      </ul>
    </div>
  </div>
</nav>
