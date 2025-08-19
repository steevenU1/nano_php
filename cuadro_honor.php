<?php
// cuadro_honor.php — LUGA (vertical cards) — versión compatible con ONLY_FULL_GROUP_BY (sin ANY_VALUE)

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';
date_default_timezone_set('America/Mexico_City');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ========= Helpers ========= */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function iniciales($nombreCompleto){
  $p = preg_split('/\s+/', trim((string)$nombreCompleto));
  $ini = '';
  foreach ($p as $w) { if ($w !== '') { $ini .= mb_substr($w, 0, 1, 'UTF-8'); } if (mb_strlen($ini,'UTF-8')>=2) break; }
  return mb_strtoupper($ini ?: 'U', 'UTF-8');
}
function nombreMes($m){
  static $meses=[1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
  return $meses[(int)$m] ?? '';
}

/* ========= Rango de fechas ========= */
function rangoSemanaMarLun(int $offset = 0): array {
  $hoy = new DateTime('today'); $n = (int)$hoy->format('N'); $dif = $n - 2; if ($dif < 0) $dif += 7;
  $ini = (clone $hoy)->modify("-$dif days"); if ($offset !== 0) $ini->modify(($offset*7).' days');
  $fin = (clone $ini)->modify('+7 days'); $ini->setTime(0,0,0); $fin->setTime(0,0,0); return [$ini,$fin];
}
function rangoMesActual(?int $y=null, ?int $m=null): array {
  $base=new DateTime('today'); $year=$y??(int)$base->format('Y'); $mon=$m??(int)$base->format('n');
  $ini=(new DateTime())->setDate($year,$mon,1)->setTime(0,0,0); $fin=(clone $ini)->modify('+1 month'); return [$ini,$fin];
}

/* ========= Utilidades de ruta / fotos ========= */
function appBaseWebAbs(): string {
  $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443);
  $scheme = $https ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $script = $_SERVER['SCRIPT_NAME'] ?? '';
  $base   = rtrim(str_replace(basename($script), '', $script), '/'); // p.ej. /luga_php
  return $scheme.'://'.$host.$base.'/';
}
function fotoUsuarioUrl(mysqli $conn, int $idUsuario): ?string {
  $stmt = $conn->prepare("SELECT foto FROM usuarios_expediente WHERE usuario_id = ? LIMIT 1");
  $stmt->bind_param("i", $idUsuario);
  $stmt->execute();
  $stmt->bind_result($foto);
  $value = null;
  if ($stmt->fetch() && $foto) $value = trim($foto);
  $stmt->close();
  if (!$value) return null;

  if (preg_match('~^https?://~i', $value)) return $value;

  $baseWeb = appBaseWebAbs();

  if (strpos($value, '/') !== false) {
    $abs = __DIR__ . '/' . ltrim($value, '/');
    if (is_file($abs)) {
      $ts = @filemtime($abs);
      return $baseWeb . ltrim($value, '/') . ($ts ? ('?t='.$ts) : '');
    }
    return $baseWeb . ltrim($value, '/');
  }

  $candidatos = [
    'uploads/fotos_usuarios/',
    'uploads/expediente/',
    'uploads/expediente/fotos/',
    'uploads/usuarios/',
    'uploads/',
  ];
  $enc = rawurlencode($value);
  foreach ($candidatos as $rel) {
    $abs = __DIR__ . '/' . $rel . $value;
    if (is_file($abs)) {
      $ts = @filemtime($abs);
      return $baseWeb . $rel . $enc . ($ts ? ('?t='.$ts) : '');
    }
  }
  return $baseWeb . 'uploads/fotos_usuarios/' . $enc;
}

/* ========= Parámetros UI ========= */
$tab = $_GET['tab'] ?? 'semana';  // semana | mes
$w   = (int)($_GET['w'] ?? 0);
$yy  = isset($_GET['yy']) ? (int)$_GET['yy'] : null;
$mm  = isset($_GET['mm']) ? (int)$_GET['mm'] : null;

if ($tab === 'mes'){
  [$ini,$fin] = rangoMesActual($yy,$mm);
  $tituloRango = nombreMes((int)$ini->format('n')).' '.$ini->format('Y');
} else {
  [$ini,$fin] = rangoSemanaMarLun($w);
  $tituloRango = 'Del '.$ini->format('d/m/Y').' al '.(clone $fin)->modify('-1 day')->format('d/m/Y');
}
$iniStr = $ini->format('Y-m-d H:i:s');
$finStr = $fin->format('Y-m-d H:i:s');

/* ========= Filtros MiFi/Modem ========= */
$notLike1 = "%modem%";
$notLike2 = "%mifi%";

/* ========= Consultas (compatibles con ONLY_FULL_GROUP_BY, sin ANY_VALUE) ========= */

$ejecutivos = [];
$sucursales = [];

try {
  // Top 3 Ejecutivos (unidades)
  $sqlTopEjecutivos = "
    SELECT
      u.id              AS id_usuario,
      u.nombre          AS nombre_usuario,
      s.nombre          AS sucursal,
      SUM(
        CASE
          WHEN v.tipo_venta='Financiamiento+Combo'
          THEN GREATEST(2, COALESCE(eq.cnt,0))
          ELSE COALESCE(eq.cnt,0)
        END
      ) AS unidades
    FROM ventas v
    JOIN usuarios u        ON u.id = v.id_usuario
    LEFT JOIN sucursales s ON s.id = u.id_sucursal
    LEFT JOIN (
      SELECT dv.id_venta, COUNT(*) AS cnt
      FROM detalle_venta dv
      JOIN productos p ON p.id = dv.id_producto
      WHERE LOWER(COALESCE(p.modelo,'')) NOT LIKE ? AND LOWER(COALESCE(p.modelo,'')) NOT LIKE ?
      GROUP BY dv.id_venta
    ) eq ON eq.id_venta = v.id
    WHERE v.fecha_venta >= ? AND v.fecha_venta < ?
    GROUP BY u.id, u.nombre, s.nombre
    HAVING unidades > 0
    ORDER BY unidades DESC, nombre_usuario ASC
    LIMIT 3
  ";
  $stmt = $conn->prepare($sqlTopEjecutivos);
  $stmt->bind_param("ssss",$notLike1,$notLike2,$iniStr,$finStr);
  $stmt->execute();
  $res = $stmt->get_result();
  while($row = $res->fetch_assoc()){
    $row['foto_url'] = fotoUsuarioUrl($conn,(int)$row['id_usuario']);
    $ejecutivos[] = $row;
  }
  $stmt->close();

  // Top 3 Sucursales (monto)
  $sqlTopSucursales = "
    SELECT
      s.id                 AS id_sucursal,
      s.nombre             AS sucursal,
      ger.id               AS id_gerente,
      ger.nombre           AS gerente,
      SUM(dv.precio_unitario) AS monto
    FROM ventas v
    JOIN detalle_venta dv ON dv.id_venta = v.id
    JOIN productos p      ON p.id       = dv.id_producto
    JOIN usuarios u       ON u.id       = v.id_usuario
    JOIN sucursales s     ON s.id       = u.id_sucursal
    LEFT JOIN (
      SELECT id_sucursal, MIN(id) AS id_gerente
      FROM usuarios
      WHERE rol = 'Gerente'
      GROUP BY id_sucursal
    ) pick ON pick.id_sucursal = s.id
    LEFT JOIN usuarios ger ON ger.id = pick.id_gerente
    WHERE v.fecha_venta >= ? AND v.fecha_venta < ?
      AND LOWER(COALESCE(p.modelo,'')) NOT LIKE ? AND LOWER(COALESCE(p.modelo,'')) NOT LIKE ?
    GROUP BY s.id, s.nombre, ger.id, ger.nombre
    HAVING monto > 0
    ORDER BY monto DESC, sucursal ASC
    LIMIT 3
  ";
  $stmt = $conn->prepare($sqlTopSucursales);
  $stmt->bind_param("ssss",$iniStr,$finStr,$notLike1,$notLike2);
  $stmt->execute();
  $res = $stmt->get_result();
  while($row = $res->fetch_assoc()){
    $row['foto_url'] = !empty($row['id_gerente']) ? fotoUsuarioUrl($conn,(int)$row['id_gerente']) : null;
    $sucursales[] = $row;
  }
  $stmt->close();

} catch (Throwable $e) {
  // Mensaje visible y compacto (útil para prod si hay modo estricto)
  echo '<div style="max-width:900px;margin:20px auto" class="alert alert-danger"><b>Error al generar el Cuadro de Honor:</b><br>'.h($e->getMessage()).'</div>';
}

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Cuadro de Honor | NanoRed</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    /* ===== Estilos verticales ===== */
    .card-portrait{
      border-radius: 1rem;
      box-shadow: 0 8px 28px rgba(0,0,0,.08);
      padding: 18px 16px;
      height: 100%;
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      gap: 12px;
    }
    .avatar-xl{
      width: 120px; height: 120px;
      border-radius: 50%;
      object-fit: cover;
      background: #f1f5f9;
      border: 3px solid #e5e7eb;
      display: inline-flex; align-items: center; justify-content: center;
      font-weight: 800; font-size: 34px; color: #475569;
    }
    .rank-badge{
      display: inline-block;
      font-weight: 800;
      font-size: .85rem;
      padding: 4px 10px;
      border-radius: 999px;
      background: #eef2ff;
      color: #4338ca;
    }
    .name-big{ font-size: 1.15rem; font-weight: 800; line-height: 1.2; }
    .branch{ color: #64748b; font-size: .95rem; }
    .metric-wrap{
      display: flex; flex-direction: column; align-items: center; gap: 2px; margin-top: 2px;
    }
    .metric{
      font-size: 1.8rem; font-weight: 900;
      letter-spacing: .3px;
    }
    .metric-label{ color:#64748b; font-size:.85rem; }
    .divider{
      width: 100%; height: 1px; background: #f1f5f9; margin: 6px 0 2px 0;
    }
  </style>
</head>
<body class="bg-light">
<div class="container py-4">

  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h5 mb-0">🏅 Cuadro de Honor</h1>
    <div class="d-none d-md-block fw-semibold">Periodo: <?=h($tituloRango)?></div>
  </div>

  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?=($tab==='semana'?'active':'')?>" href="?tab=semana">Semanal</a></li>
    <li class="nav-item"><a class="nav-link <?=($tab==='mes'?'active':'')?>" href="?tab=mes">Mensual</a></li>
  </ul>

  <div class="mb-3 d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="?tab=semana&w=<?=($w-1)?>">⟵ Semana</a>
    <a class="btn btn-outline-secondary btn-sm" href="?tab=semana&w=<?=($w+1)?>">Semana ⟶</a>
    <?php
      $base=new DateTime('today');
      $mAct = (int)($mm ?? $base->format('n'));
      $yAct = (int)($yy ?? $base->format('Y'));
      $prev=(clone (new DateTime()))->setDate($yAct,$mAct,1)->modify('-1 month');
      $next=(clone (new DateTime()))->setDate($yAct,$mAct,1)->modify('+1 month');
    ?>
    <a class="btn btn-outline-primary btn-sm ms-auto" href="?tab=mes&yy=<?=$prev->format('Y')?>&mm=<?=$prev->format('n')?>">⟵ Mes</a>
    <a class="btn btn-outline-primary btn-sm" href="?tab=mes&yy=<?=$next->format('Y')?>&mm=<?=$next->format('n')?>">Mes ⟶</a>
  </div>

  <!-- Top 3 Ejecutivos (vertical) -->
  <h2 class="h6 mt-2">Top 3 Ejecutivos</h2>
  <div class="row g-3">
    <?php if(empty($ejecutivos)): ?>
      <div class="col-12"><div class="alert alert-light border">Sin ventas en este periodo.</div></div>
    <?php else: foreach($ejecutivos as $i=>$e): ?>
      <div class="col-12 col-md-4">
        <div class="card-portrait bg-white">
          <div class="rank-badge">#<?=($i+1)?> Ejecutivo</div>

          <?php if(!empty($e['foto_url'])): ?>
            <img src="<?=h($e['foto_url'])?>" class="avatar-xl" alt="Foto" loading="lazy">
          <?php else: ?>
            <div class="avatar-xl"><?=h(iniciales($e['nombre_usuario']))?></div>
          <?php endif; ?>

          <div class="name-big"><?=h($e['nombre_usuario'])?></div>
          <div class="branch"><?=h($e['sucursal'] ?? '—')?></div>

          <div class="divider"></div>
          <div class="metric-wrap">
            <div class="metric"><?= (int)$e['unidades'] ?></div>
            <div class="metric-label">unidades</div>
          </div>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- Top 3 Sucursales (vertical) -->
  <h2 class="h6 mt-4">Top 3 Sucursales</h2>
  <div class="row g-3">
    <?php if(empty($sucursales)): ?>
      <div class="col-12"><div class="alert alert-light border">Sin ventas en este periodo.</div></div>
    <?php else: foreach($sucursales as $i=>$s): ?>
      <div class="col-12 col-md-4">
        <div class="card-portrait bg-white">
          <div class="rank-badge">#<?=($i+1)?> Sucursal</div>

          <?php if(!empty($s['foto_url'])): ?>
            <img src="<?=h($s['foto_url'])?>" class="avatar-xl" alt="Foto" loading="lazy">
          <?php else: ?>
            <div class="avatar-xl"><?=h(iniciales($s['gerente'] ?? ''))?></div>
          <?php endif; ?>

          <div class="name-big"><?=h($s['sucursal'])?></div>
          <div class="branch">Gerente: <?=h($s['gerente'] ?? '—')?></div>

          <div class="divider"></div>
          <div class="metric-wrap">
            <div class="metric">$<?=number_format((float)$s['monto'],2)?></div>
            <div class="metric-label">monto</div>
          </div>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>
</body>
</html>
