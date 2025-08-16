<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

date_default_timezone_set('America/Mexico_City');
require 'db.php';
if (file_exists('navbar.php')) include 'navbar.php';

$hoy       = new DateTime('today');
$anioHoy   = (int)$hoy->format('Y');
$mesHoy    = (int)$hoy->format('n');
$diaHoy    = (int)$hoy->format('j');

$anio = isset($_GET['anio']) ? max(1970, (int)$_GET['anio']) : $anioHoy;
$mes  = isset($_GET['mes'])  ? min(12, max(1, (int)$_GET['mes'])) : $mesHoy;

$nombreMeses = [1=>"Enero","Febrero","Marzo","Abril","Mayo","Junio","Julio","Agosto","Septiembre","Octubre","Noviembre","Diciembre"];
$nombreMes = $nombreMeses[$mes];

function edadQueCumple(string $fnac, int $anio): int { return $anio - (int)date('Y', strtotime($fnac)); }
function aniosServicioQueCumple(string $fing, int $anio): int { return $anio - (int)date('Y', strtotime($fing)); }
function dia(string $f): int { return (int)date('j', strtotime($f)); }

function placeholder($nombre='Colaborador'){
  return 'https://ui-avatars.com/api/?name='.urlencode($nombre).'&background=E0F2FE&color=0F172A&bold=true';
}

// Cumpleaños
$cumples = [];
$sql = "SELECT u.id, u.nombre, s.nombre AS sucursal, e.fecha_nacimiento, e.foto
        FROM usuarios u
        LEFT JOIN usuarios_expediente e ON e.usuario_id = u.id
        LEFT JOIN sucursales s ON s.id = u.id_sucursal
        WHERE u.activo=1 AND (e.fecha_baja IS NULL) AND e.fecha_nacimiento IS NOT NULL
          AND MONTH(e.fecha_nacimiento) = ?
        ORDER BY DAY(e.fecha_nacimiento), u.nombre";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $mes);
$stmt->execute();
$r = $stmt->get_result();
while($row = $r->fetch_assoc()) $cumples[] = $row;
$stmt->close();

// Aniversarios
$anv = [];
$sql = "SELECT u.id, u.nombre, s.nombre AS sucursal, e.fecha_ingreso, e.foto
        FROM usuarios u
        LEFT JOIN usuarios_expediente e ON e.usuario_id = u.id
        LEFT JOIN sucursales s ON s.id = u.id_sucursal
        WHERE u.activo=1 AND (e.fecha_baja IS NULL) AND e.fecha_ingreso IS NOT NULL
          AND MONTH(e.fecha_ingreso) = ?
        ORDER BY DAY(e.fecha_ingreso), u.nombre";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $mes);
$stmt->execute();
$r = $stmt->get_result();
while($row = $r->fetch_assoc()) $anv[] = $row;
$stmt->close();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Celebraciones · <?= htmlspecialchars($nombreMes)." ".$anio ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  :root{
    --bg:#f8fafc; --ink:#0f172a; --muted:#64748b; --line:#e5e7eb; --pri:#2563eb;
    --today:#e0f2fe; /* azul clarito */
    --badge:#dbeafe;
  }
  body{background:var(--bg); color:var(--ink)}
  .container{max-width:1100px}
  .hero{display:flex;gap:12px;align-items:end;flex-wrap:wrap;margin:20px 0 14px}
  .hero h1{font-weight:800;margin:0}
  .hero .sub{color:var(--muted)}

  .filters{display:flex;gap:8px;flex-wrap:wrap; align-items:center}
  .btn-ghost{background:#fff;border:1px solid var(--line);border-radius:10px;padding:8px 12px}
  .btn-ghost:hover{background:#f1f5f9}

  .section-title{margin:12px 0 8px;font-weight:800}

  .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px}
  .card-p{background:#fff;border:1px solid var(--line);border-radius:16px;padding:12px}
  .card-p.today{background:var(--today);border-color:#bfdbfe}
  .top{display:flex;gap:12px;align-items:center}
  .avatar{width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid #fff;box-shadow:0 1px 2px rgba(0,0,0,.06)}
  .name{font-weight:700}
  .sub{color:var(--muted);font-size:13px}
  .badge-day{margin-left:auto;background:var(--badge);border:1px solid #bfdbfe;border-radius:999px;padding:4px 8px;font-weight:700;font-size:12px}
  .chip-hoy{background:#fef3c7;border:1px solid #fde68a;border-radius:999px;padding:2px 8px;font-size:12px;font-weight:700;margin-left:6px}
</style>
</head>
<body>
<div class="container py-3">

  <div class="hero">
    <div>
      <h1>Cumpleaños & Aniversarios</h1>
      <div class="sub">Mes: <strong><?= htmlspecialchars($nombreMes) ?></strong> · Año: <strong><?= (int)$anio ?></strong></div>
    </div>
    <form class="ms-auto filters" method="get" action="">
      <select class="form-select" name="mes">
        <?php for ($m=1;$m<=12;$m++): ?>
          <option value="<?= $m ?>" <?= $m===$mes?'selected':'' ?>><?= $nombreMeses[$m] ?></option>
        <?php endfor; ?>
      </select>
      <input type="number" class="form-control" name="anio" value="<?= (int)$anio ?>" min="1970" max="<?= (int)date('Y')+1 ?>">
      <button class="btn btn-ghost">Ver</button>
    </form>
  </div>

  <!-- Cumpleaños -->
  <h5 class="section-title">🎂 Cumpleaños de <?= htmlspecialchars($nombreMes) ?></h5>
  <?php if (empty($cumples)): ?>
    <div class="alert alert-light border">No hay cumpleaños este mes.</div>
  <?php else: ?>
  <div class="grid">
    <?php foreach ($cumples as $p):
      $day  = dia($p['fecha_nacimiento']);
      $edad = edadQueCumple($p['fecha_nacimiento'], $anio);
      $foto = $p['foto'] ?: placeholder($p['nombre']);
      $esHoy = ($mes==$mesHoy && $day==$diaHoy);
    ?>
      <div class="card-p <?= $esHoy?'today':'' ?>">
        <div class="top">
          <img src="<?= htmlspecialchars($foto) ?>" class="avatar" alt="foto">
          <div>
            <div class="name">
              <?= htmlspecialchars($p['nombre']) ?>
              <?php if ($esHoy): ?><span class="chip-hoy">🎉 Hoy</span><?php endif; ?>
            </div>
            <div class="sub"><?= str_pad((string)$day,2,'0',STR_PAD_LEFT) ?> <?= htmlspecialchars($nombreMes) ?> · Cumple <?= (int)$edad ?> años</div>
            <?php if(!empty($p['sucursal'])): ?><div class="sub">🏬 <?= htmlspecialchars($p['sucursal']) ?></div><?php endif; ?>
          </div>
          <div class="badge-day"><?= str_pad((string)$day,2,'0',STR_PAD_LEFT) ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Aniversarios -->
  <h5 class="section-title mt-3">🏅 Aniversarios de <?= htmlspecialchars($nombreMes) ?></h5>
  <?php if (empty($anv)): ?>
    <div class="alert alert-light border">No hay aniversarios este mes.</div>
  <?php else: ?>
  <div class="grid">
    <?php foreach ($anv as $p):
      $day  = dia($p['fecha_ingreso']);
      $ann  = aniosServicioQueCumple($p['fecha_ingreso'], $anio);
      $foto = $p['foto'] ?: placeholder($p['nombre']);
      $esHoy = ($mes==$mesHoy && $day==$diaHoy);
    ?>
      <div class="card-p <?= $esHoy?'today':'' ?>">
        <div class="top">
          <img src="<?= htmlspecialchars($foto) ?>" class="avatar" alt="foto">
          <div>
            <div class="name">
              <?= htmlspecialchars($p['nombre']) ?>
              <?php if ($esHoy): ?><span class="chip-hoy">🎉 Hoy</span><?php endif; ?>
            </div>
            <div class="sub"><?= str_pad((string)$day,2,'0',STR_PAD_LEFT) ?> <?= htmlspecialchars($nombreMes) ?> · <?= (int)$ann ?> año(s) en la empresa</div>
            <?php if(!empty($p['sucursal'])): ?><div class="sub">🏬 <?= htmlspecialchars($p['sucursal']) ?></div><?php endif; ?>
          </div>
          <div class="badge-day"><?= str_pad((string)$day,2,'0',STR_PAD_LEFT) ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>
</body>
</html>
