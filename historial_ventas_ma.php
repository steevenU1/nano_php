<?php
// historial_ventas_ma.php — Master Admin / Socio (una vista, separadas por tipo)
// - En vista "Socio" se oculta toda la columna/metricas de Comisión

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

// ---------- Helpers ----------
function esc($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function n2($v){ return number_format((float)$v, 2); }
function mes_corto_es($n){ static $m=[1=>'Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic']; $n=(int)$n; return $m[$n]??''; }
function fecha_corta_es(DateTime $d){ return $d->format('d').' '.mes_corto_es($d->format('n')).' '.$d->format('Y'); }

// ===============================
//  Tipo de vista (separación)
// ===============================
$tipo = strtolower(trim($_GET['tipo'] ?? 'ma'));  // 'ma' | 'socio'
if (!in_array($tipo, ['ma','socio'], true)) $tipo = 'ma';
$labelTipo = ($tipo === 'socio') ? 'Socio' : 'Master Admin';
$mostrarComision = ($tipo === 'ma'); // <- clave: solo MA muestra comisión

// ===============================
//  Semana actual (martes a lunes)
// ===============================
$w = (int)($_GET['w'] ?? 0); // desplazamiento de semanas: 0 = actual, -1 = pasada, etc.
$hoy = new DateTime('today');
$dia = (int)$hoy->format('N');     // 1 = Lunes ... 7 = Domingo
$dif = $dia - 2;                   // Martes = 2
if ($dif < 0) $dif += 7;

$inicio = (new DateTime('today'))->modify("-{$dif} days")->setTime(0, 0, 0);
if ($w !== 0) {
    $inicio->modify(($w > 0 ? '+' : '').($w * 7).' days');
}
$fin = (clone $inicio)->modify('+6 days')->setTime(23, 59, 59);

$fecha_desde = $inicio->format('Y-m-d');
$fecha_hasta = $fin->format('Y-m-d');
$labelSemana = fecha_corta_es($inicio).' — '.fecha_corta_es($fin);

// ===============================
//  Parámetros extra de filtro
// ===============================
$id_sucursal = (int)($_GET['sucursal'] ?? 0);
$busca_imei  = trim($_GET['imei'] ?? '');

// ===============================
//  WHERE + parámetros (compartido)
//  Nota: usamos snapshot v.origen_subtipo
// ===============================
$subtipoObjetivo = ($tipo === 'socio') ? 'Socio' : 'Master Admin';

$where  = " v.origen_subtipo = ? AND DATE(v.fecha_venta) BETWEEN ? AND ? ";
$params = [$subtipoObjetivo, $fecha_desde, $fecha_hasta];
$types  = "sss";

if ($id_sucursal > 0) {
    $where   .= " AND v.id_sucursal=? ";
    $params[] = $id_sucursal;
    $types   .= "i";
}
if ($busca_imei !== '') {
    // EXISTS para no duplicar filas
    $where     .= " AND EXISTS (SELECT 1 FROM detalle_venta dv WHERE dv.id_venta=v.id AND dv.imei1 LIKE ?) ";
    $params[]   = '%'.$busca_imei.'%';
    $types     .= "s";
}

// ===============================
//  EXPORTAR A EXCEL (ANTES de incluir navbar.php)
// ===============================
if (isset($_GET['export']) && $_GET['export'] === '1') {
    // Armamos SELECT base
    $selectCols = "
      v.id, v.fecha_venta, s.nombre AS sucursal, u.nombre AS vendedor,
      v.tipo_venta, v.origen_ma, v.precio_venta, v.enganche,
      v.comentarios,
      (SELECT GROUP_CONCAT(DISTINCT dv.imei1 ORDER BY dv.imei1 SEPARATOR ', ')
         FROM detalle_venta dv WHERE dv.id_venta = v.id) AS imeis
    ";
    // Solo MA exporta comisión
    if ($mostrarComision) {
        $selectCols = "
          v.id, v.fecha_venta, s.nombre AS sucursal, u.nombre AS vendedor,
          v.tipo_venta, v.origen_ma, v.precio_venta, v.enganche,
          v.comision_master_admin, v.comentarios,
          (SELECT GROUP_CONCAT(DISTINCT dv.imei1 ORDER BY dv.imei1 SEPARATOR ', ')
             FROM detalle_venta dv WHERE dv.id_venta = v.id) AS imeis
        ";
    }

    $sql = "
      SELECT $selectCols
      FROM ventas v
      INNER JOIN sucursales s ON s.id=v.id_sucursal
      INNER JOIN usuarios   u ON u.id=v.id_usuario
      WHERE $where
      ORDER BY v.fecha_venta DESC, v.id DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rs = $stmt->get_result();

    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=historial_ventas_{$tipo}.xls");
    header("Pragma: no-cache");
    header("Expires: 0");
    echo "\xEF\xBB\xBF"; // BOM

    echo "Tipo\t$labelTipo\n";
    echo "Semana\t$labelSemana (Mar → Lun)\n\n";

    if ($mostrarComision) {
        echo "ID\tFecha\tSucursal\tCapturó\tTipo\tOrigen\tIMEI(s)\tPrecio\tEnganche\tComisión\tComentarios\n";
    } else {
        echo "ID\tFecha\tSucursal\tCapturó\tTipo\tOrigen\tIMEI(s)\tPrecio\tEnganche\tComentarios\n";
    }

    while ($row = $rs->fetch_assoc()) {
        $origenTxt = ($row['origen_ma'] === 'nano' ? 'Nano' : ($row['origen_ma'] === 'propio' ? 'Propio' : '—'));
        $imeis = ($row['origen_ma'] === 'nano') ? ($row['imeis'] ?: '') : '';
        $fechaSolo = date('d/m/Y', strtotime($row['fecha_venta']));

        if ($mostrarComision) {
            echo $row['id'] . "\t" .
                 $fechaSolo . "\t" .
                 $row['sucursal'] . "\t" .
                 $row['vendedor'] . "\t" .
                 $row['tipo_venta'] . "\t" .
                 $origenTxt . "\t" .
                 $imeis . "\t" .
                 n2($row['precio_venta']) . "\t" .
                 n2($row['enganche']) . "\t" .
                 n2($row['comision_master_admin']) . "\t" .
                 str_replace(["\t","\n","\r"], " ", $row['comentarios']) . "\n";
        } else {
            echo $row['id'] . "\t" .
                 $fechaSolo . "\t" .
                 $row['sucursal'] . "\t" .
                 $row['vendedor'] . "\t" .
                 $row['tipo_venta'] . "\t" .
                 $origenTxt . "\t" .
                 $imeis . "\t" .
                 n2($row['precio_venta']) . "\t" .
                 n2($row['enganche']) . "\t" .
                 str_replace(["\t","\n","\r"], " ", $row['comentarios']) . "\n";
        }
    }
    exit();
}

// =====================================================================
//  DE AQUÍ PARA ABAJO ES LA VISTA (ya podemos incluir navbar con HTML)
// =====================================================================
include 'navbar.php';

// Cargar sucursales para el selector (según tipo)
$sucursales = [];
$qSuc = $conn->prepare("SELECT id, nombre FROM sucursales WHERE subtipo=? ORDER BY nombre");
$qSuc->bind_param("s", $subtipoObjetivo);
$qSuc->execute();
$rSuc = $qSuc->get_result();
$sucursales = $rSuc->fetch_all(MYSQLI_ASSOC);
$qSuc->close();

// Consulta principal para la tabla
$selectCols = "
  v.id, v.fecha_venta, s.nombre AS sucursal, u.nombre AS vendedor,
  v.tipo_venta, v.origen_ma, v.precio_venta, v.enganche,
  v.comentarios,
  (SELECT GROUP_CONCAT(DISTINCT dv.imei1 ORDER BY dv.imei1 SEPARATOR ', ')
     FROM detalle_venta dv WHERE dv.id_venta = v.id) AS imeis
";
if ($mostrarComision) {
    $selectCols = "
      v.id, v.fecha_venta, s.nombre AS sucursal, u.nombre AS vendedor,
      v.tipo_venta, v.origen_ma, v.precio_venta, v.enganche,
      v.comentarios, v.comision_master_admin,
      (SELECT GROUP_CONCAT(DISTINCT dv.imei1 ORDER BY dv.imei1 SEPARATOR ', ')
         FROM detalle_venta dv WHERE dv.id_venta = v.id) AS imeis
    ";
}

$sql = "
  SELECT $selectCols
  FROM ventas v
  INNER JOIN sucursales s ON s.id=v.id_sucursal
  INNER JOIN usuarios   u ON u.id=v.id_usuario
  WHERE $where
  ORDER BY v.fecha_venta DESC, v.id DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rs = $stmt->get_result();
$ventas = $rs->fetch_all(MYSQLI_ASSOC);

// Totales
$total_monto  = 0;
$unidades_semana = count($ventas);
$total_com_ma = 0; // solo se usará si $mostrarComision=true
foreach ($ventas as $v) {
    $total_monto  += (float)$v['precio_venta'];
    if ($mostrarComision) $total_com_ma += (float)($v['comision_master_admin'] ?? 0);
}

// Totales por sucursal
if ($mostrarComision) {
    $sqlTot = "
      SELECT s.nombre AS sucursal, COUNT(*) AS unidades,
             SUM(v.precio_venta) AS total_monto,
             SUM(v.comision_master_admin) AS total_com_ma
      FROM ventas v
      INNER JOIN sucursales s ON s.id=v.id_sucursal
      WHERE $where
      GROUP BY s.id, s.nombre
      ORDER BY s.nombre
    ";
} else {
    $sqlTot = "
      SELECT s.nombre AS sucursal, COUNT(*) AS unidades,
             SUM(v.precio_venta) AS total_monto
      FROM ventas v
      INNER JOIN sucursales s ON s.id=v.id_sucursal
      WHERE $where
      GROUP BY s.id, s.nombre
      ORDER BY s.nombre
    ";
}
$stmt2 = $conn->prepare($sqlTot);
$stmt2->bind_param($types, ...$params);
$stmt2->execute();
$rst = $stmt2->get_result();
$totalesSucursal = $rst->fetch_all(MYSQLI_ASSOC);
$stmt2->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Historial Ventas — <?= esc($labelTipo) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .badge-origen { font-size:.75rem; }
        .table thead th { white-space:nowrap; }
        .text-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace; }
        .nav-pills .nav-link.active{ font-weight:600; }
    </style>
</head>
<body class="bg-light">
<div class="container-fluid px-3 px-md-4 my-4">

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <h3 class="m-0">Historial de Ventas — <span class="text-primary"><?= esc($labelTipo) ?></span></h3>
        <div class="d-flex align-items-center gap-2">
            <a class="btn btn-outline-secondary" href="panel.php">← Volver</a>
        </div>
    </div>

    <!-- Switch tipo (MA / Socio) -->
    <ul class="nav nav-pills mb-3">
        <?php
          $qs = function($t) use ($w,$id_sucursal,$busca_imei){
            return '?'.http_build_query(['tipo'=>$t,'w'=>$w,'sucursal'=>$id_sucursal,'imei'=>$busca_imei]);
          };
        ?>
        <li class="nav-item">
            <a class="nav-link <?= $tipo==='ma'?'active':'' ?>" href="<?= $qs('ma') ?>">Master Admin</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tipo==='socio'?'active':'' ?>" href="<?= $qs('socio') ?>">Socio</a>
        </li>
    </ul>

    <!-- Barra de semana -->
    <div class="card p-3 mb-3">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div class="d-flex align-items-center gap-2">
                <?php
                $qsBase = function ($nw) use ($id_sucursal, $busca_imei, $tipo) {
                    $q = http_build_query(['tipo'=>$tipo,'w'=>$nw,'sucursal'=>$id_sucursal,'imei'=>$busca_imei]);
                    return '?'.$q;
                };
                ?>
                <a class="btn btn-outline-primary btn-sm" href="<?= $qsBase($w - 1) ?>">⟵ Semana anterior</a>
                <a class="btn btn-outline-primary btn-sm" href="<?= $qsBase($w + 1) ?>">Semana siguiente ⟶</a>
            </div>
            <div><strong>Semana:</strong> <?= esc($labelSemana) ?> (Mar → Lun)</div>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-success btn-sm"
                   href="?<?= http_build_query(['tipo'=>$tipo,'w'=>$w,'sucursal'=>$id_sucursal,'imei'=>$busca_imei,'export'=>1]) ?>">
                    Exportar Excel (<?= esc($labelTipo) ?>)
                </a>
            </div>
        </div>
    </div>

    <!-- KPIs -->
    <div class="row g-3 mb-3">
        <div class="col-md-6 <?= $mostrarComision ? 'col-lg-4' : 'col-lg-6' ?>">
            <div class="card p-3">
                <div class="text-muted">Unidades (semana)</div>
                <div class="fs-3"><?= (int)$unidades_semana ?></div>
                <small class="text-muted"><?= esc($labelTipo) ?></small>
            </div>
        </div>
        <div class="col-md-6 <?= $mostrarComision ? 'col-lg-4' : 'col-lg-6' ?>">
            <div class="card p-3">
                <div class="text-muted">Monto vendido ($)</div>
                <div class="fs-3"><?= n2($total_monto) ?></div>
                <small class="text-muted"><?= esc($labelTipo) ?></small>
            </div>
        </div>
        <?php if ($mostrarComision): ?>
        <div class="col-md-12 col-lg-4">
            <div class="card p-3">
                <div class="text-muted">Comisión (MA)</div>
                <div class="fs-3"><?= n2($total_com_ma) ?></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Filtros -->
    <form class="card p-3 mb-3" method="GET">
        <input type="hidden" name="tipo" value="<?= esc($tipo) ?>">
        <input type="hidden" name="w" value="<?= (int)$w ?>">
        <div class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Sucursal (<?= esc($labelTipo) ?>)</label>
                <select name="sucursal" class="form-select">
                    <option value="0">Todas</option>
                    <?php foreach ($sucursales as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= $id_sucursal === (int)$s['id'] ? 'selected' : '' ?>>
                            <?= esc($s['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Buscar por IMEI</label>
                <input type="text" name="imei" value="<?= esc($busca_imei) ?>" class="form-control" placeholder="IMEI contiene...">
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-primary">Aplicar</button>
            </div>
            <div class="col-md-2 d-grid">
                <a class="btn btn-outline-secondary" href="?<?= http_build_query(['tipo'=>$tipo,'w'=>0]) ?>">Limpiar</a>
            </div>
        </div>
    </form>

    <!-- Totales por sucursal -->
    <div class="card p-3 mb-3">
        <h6 class="text-muted mb-2">Totales por tienda <?= esc($labelTipo) ?> (Semana <?= esc($labelSemana) ?>)</h6>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Sucursal</th>
                        <th class="text-end">Unidades</th>
                        <th class="text-end">Monto ($)</th>
                        <?php if ($mostrarComision): ?>
                        <th class="text-end">Comisión ($)</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$totalesSucursal): ?>
                        <tr><td colspan="<?= $mostrarComision?4:3 ?>" class="text-center text-muted">Sin registros</td></tr>
                    <?php else: foreach ($totalesSucursal as $t): ?>
                        <tr>
                            <td><?= esc($t['sucursal']) ?></td>
                            <td class="text-end"><?= (int)$t['unidades'] ?></td>
                            <td class="text-end"><?= n2($t['total_monto']) ?></td>
                            <?php if ($mostrarComision): ?>
                            <td class="text-end"><strong><?= n2($t['total_com_ma']) ?></strong></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <th>Total general</th>
                        <th class="text-end"><?= (int)$unidades_semana ?></th>
                        <th class="text-end"><?= n2($total_monto) ?></th>
                        <?php if ($mostrarComision): ?>
                        <th class="text-end"><strong><?= n2($total_com_ma) ?></strong></th>
                        <?php endif; ?>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- Tabla de ventas -->
    <div class="card p-3">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Fecha</th>
                        <th>Sucursal</th>
                        <th>Capturó</th>
                        <th>Tipo</th>
                        <th>Origen</th>
                        <th>IMEI(s)</th>
                        <th class="text-end">Precio ($)</th>
                        <th class="text-end">Enganche ($)</th>
                        <?php if ($mostrarComision): ?>
                        <th class="text-end">Comisión ($)</th>
                        <?php endif; ?>
                        <th>Comentarios</th>
                        <?php if (($_SESSION['rol'] ?? '') === 'Admin'): ?><th>Acciones</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$ventas): ?>
                        <tr><td colspan="<?= $mostrarComision?12:11 ?>" class="text-center text-muted">Sin ventas en la semana</td></tr>
                    <?php else: foreach ($ventas as $v):
                        $origenBadge = $v['origen_ma'] === 'nano'
                          ? '<span class="badge bg-primary badge-origen">Nano</span>'
                          : ($v['origen_ma'] === 'propio'
                              ? '<span class="badge bg-secondary badge-origen">Propio</span>'
                              : '<span class="badge bg-light text-dark badge-origen">—</span>');
                        $imeis = ($v['origen_ma'] === 'nano') ? ($v['imeis'] ?: '') : '';
                        $fechaSolo = date('d/m/Y', strtotime($v['fecha_venta']));
                    ?>
                        <tr>
                            <td><?= (int)$v['id'] ?></td>
                            <td><?= esc($fechaSolo) ?></td>
                            <td><?= esc($v['sucursal']) ?></td>
                            <td><?= esc($v['vendedor']) ?></td>
                            <td><?= esc($v['tipo_venta']) ?></td>
                            <td><?= $origenBadge ?></td>
                            <td class="text-mono"><?= esc($imeis) ?></td>
                            <td class="text-end"><?= n2($v['precio_venta']) ?></td>
                            <td class="text-end"><?= n2($v['enganche']) ?></td>
                            <?php if ($mostrarComision): ?>
                            <td class="text-end"><strong><?= n2($v['comision_master_admin']) ?></strong></td>
                            <?php endif; ?>
                            <td><?= esc($v['comentarios']) ?></td>
                            <?php if (($_SESSION['rol'] ?? '') === 'Admin'): ?>
                            <td>
                                <a href="eliminar_venta_ma.php?id=<?= (int)$v['id'] ?>"
                                   class="btn btn-sm btn-danger"
                                   onclick="return confirm('¿Seguro que deseas eliminar esta venta?')">
                                   🗑 Eliminar
                                </a>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <th colspan="7" class="text-end">Totales (Semana):</th>
                        <th class="text-end"><?= n2($total_monto) ?></th>
                        <th></th>
                        <?php if ($mostrarComision): ?>
                        <th class="text-end"><strong><?= n2($total_com_ma) ?></strong></th>
                        <th></th>
                        <?php endif; ?>
                        <?php if (! $mostrarComision): ?>
                        <th></th>
                        <?php endif; ?>
                        <?php if (($_SESSION['rol'] ?? '') === 'Admin'): ?><th></th><?php endif; ?>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

</div>
</body>
</html>
