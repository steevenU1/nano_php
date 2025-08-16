<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Admin'])) {
  header("Location: index.php"); exit();
}

include 'db.php';

$anio = isset($_GET['anio']) ? (int)$_GET['anio'] : (int)date('Y');
$mes  = isset($_GET['mes'])  ? (int)$_GET['mes']  : (int)date('n');

$whereSuc = "
  LOWER(s.tipo_sucursal) <> 'almacen'
  AND LOWER(COALESCE(s.subtipo,'')) NOT IN ('subdistribuidor','master admin')
";

/* ---------- util: headers excel con BOM ---------- */
function xls_headers($filename){
  while (ob_get_level()) { ob_end_clean(); }
  header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
  header("Content-Disposition: attachment; filename=\"$filename\"");
  header("Pragma: no-cache");
  header("Expires: 0");
  echo "\xEF\xBB\xBF"; // BOM UTF-8
}

/* =========================================================
   EXPORTS (antes de cualquier HTML)
========================================================= */
if (isset($_GET['export'])) {

  if ($_GET['export'] === 'xls_general') {
    $q = $conn->query("
      SELECT 
        COALESCE(cat.nombre,'Sin categoría') AS categoria,
        c.nombre, c.unidad,
        SUM(d.cantidad) AS total_cant
      FROM insumos_pedidos p
      INNER JOIN insumos_pedidos_detalle d ON d.id_pedido = p.id
      INNER JOIN insumos_catalogo c ON c.id = d.id_insumo
      LEFT JOIN insumos_categorias cat ON cat.id = c.id_categoria
      INNER JOIN sucursales s ON s.id = p.id_sucursal
      WHERE p.anio = $anio AND p.mes = $mes
        AND p.estatus IN ('Enviado','Aprobado')
        AND $whereSuc
      GROUP BY categoria, c.nombre, c.unidad
      ORDER BY categoria, c.nombre
    ");

    xls_headers("insumos_concentrado_general_{$anio}_{$mes}.xls");
    echo "<table border='1'>";
    echo "<tr><th colspan='4'>Concentrado general — ".sprintf('%02d',$mes)."/$anio</th></tr>";
    echo "<tr><th>Categoría</th><th>Insumo</th><th>Unidad</th><th>Cantidad Total</th></tr>";
    while ($r = $q->fetch_assoc()) {
      echo "<tr>";
      echo "<td>".htmlspecialchars($r['categoria'])."</td>";
      echo "<td>".htmlspecialchars($r['nombre'])."</td>";
      echo "<td>".htmlspecialchars($r['unidad'])."</td>";
      echo "<td>".number_format((float)$r['total_cant'],2,'.','')."</td>";
      echo "</tr>";
    }
    echo "</table>";
    exit;
  }

  if ($_GET['export'] === 'xls_sucursales') {
    $q = $conn->query("
      SELECT 
        s.nombre AS sucursal,
        COALESCE(cat.nombre,'Sin categoría') AS categoria,
        c.nombre AS insumo, c.unidad,
        SUM(d.cantidad) AS total_cant
      FROM insumos_pedidos p
      INNER JOIN insumos_pedidos_detalle d ON d.id_pedido = p.id
      INNER JOIN insumos_catalogo c ON c.id = d.id_insumo
      LEFT JOIN insumos_categorias cat ON cat.id = c.id_categoria
      INNER JOIN sucursales s ON s.id = p.id_sucursal
      WHERE p.anio = $anio AND p.mes = $mes
        AND p.estatus IN ('Enviado','Aprobado')
        AND $whereSuc
      GROUP BY s.nombre, categoria, insumo, c.unidad
      ORDER BY s.nombre, categoria, insumo
    ");

    xls_headers("insumos_concentrado_sucursales_{$anio}_{$mes}.xls");
    echo "<table border='1'>";
    echo "<tr><th colspan='5'>Concentrado por sucursal — ".sprintf('%02d',$mes)."/$anio</th></tr>";
    echo "<tr><th>Sucursal</th><th>Categoría</th><th>Insumo</th><th>Unidad</th><th>Cantidad Total</th></tr>";
    while ($r = $q->fetch_assoc()) {
      echo "<tr>";
      echo "<td>".htmlspecialchars($r['sucursal'])."</td>";
      echo "<td>".htmlspecialchars($r['categoria'])."</td>";
      echo "<td>".htmlspecialchars($r['insumo'])."</td>";
      echo "<td>".htmlspecialchars($r['unidad'])."</td>";
      echo "<td>".number_format((float)$r['total_cant'],2,'.','')."</td>";
      echo "</tr>";
    }
    echo "</table>";
    exit;
  }
}

/* =================== VISTA NORMAL =================== */
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Pedidos de Insumos — Admin</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
  <h3>📦 Pedidos de Insumos — Admin</h3>

  <?php
  $msg = '';
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'], $_POST['id_pedido'])) {
    $idp = (int)$_POST['id_pedido'];
    $map = ['aprobar'=>'Aprobado','rechazar'=>'Rechazado','surtido'=>'Surtido'];
    if (isset($map[$_POST['accion']])) {
      $nuevo = $map[$_POST['accion']];
      $st = $conn->prepare("UPDATE insumos_pedidos SET estatus=? WHERE id=?");
      $st->bind_param("si", $nuevo, $idp);
      $st->execute();
      $msg = "Pedido #$idp → $nuevo";
    }
  }
  if ($msg): ?>
    <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

  <?php
  /* Pedidos por sucursal */
  $ped = $conn->query("
    SELECT p.*, s.nombre AS sucursal, s.tipo_sucursal, s.subtipo
    FROM insumos_pedidos p
    INNER JOIN sucursales s ON s.id = p.id_sucursal
    WHERE p.anio = $anio AND p.mes = $mes
      AND $whereSuc
    ORDER BY s.nombre
  ");

  /* Concentrado para pestaña */
  $conGeneral = $conn->query("
    SELECT 
      COALESCE(cat.nombre,'Sin categoría') AS categoria,
      c.nombre, c.unidad,
      SUM(d.cantidad) AS total_cant
    FROM insumos_pedidos p
    INNER JOIN insumos_pedidos_detalle d ON d.id_pedido = p.id
    INNER JOIN insumos_catalogo c ON c.id = d.id_insumo
    LEFT JOIN insumos_categorias cat ON cat.id = c.id_categoria
    INNER JOIN sucursales s ON s.id = p.id_sucursal
    WHERE p.anio = $anio AND p.mes = $mes
      AND p.estatus IN ('Enviado','Aprobado')
      AND $whereSuc
    GROUP BY categoria, c.nombre, c.unidad
    ORDER BY categoria, c.nombre
  ");
  $rowsGeneral = [];
  while ($r=$conGeneral->fetch_assoc()) $rowsGeneral[]=$r;
  ?>

  <form class="row g-2 mb-3" method="get">
    <div class="col-auto">
      <select name="mes" class="form-select">
        <?php for ($m=1;$m<=12;$m++): ?>
          <option value="<?= $m ?>" <?= $m==$mes?'selected':'' ?>><?= $m ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div class="col-auto">
      <select name="anio" class="form-select">
        <?php for ($a=date('Y')-1;$a<=date('Y')+1;$a++): ?>
          <option value="<?= $a ?>" <?= $a==$anio?'selected':'' ?>><?= $a ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div class="col-auto d-flex gap-2">
      <button class="btn btn-outline-primary">Filtrar</button>
      <a class="btn btn-success"   href="?mes=<?= $mes ?>&anio=<?= $anio ?>&export=xls_general">Excel — Concentrado general</a>
      <a class="btn btn-secondary" href="?mes=<?= $mes ?>&anio=<?= $anio ?>&export=xls_sucursales">Excel — Concentrado por sucursal</a>
    </div>
  </form>

  <ul class="nav nav-tabs mb-3">
    <li class="nav-item">
      <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#t1" type="button">Pedidos por sucursal</button>
    </li>
    <li class="nav-item">
      <button class="nav-link" data-bs-toggle="tab" data-bs-target="#t2" type="button">Concentrado general</button>
    </li>
  </ul>

  <div class="tab-content">
    <div class="tab-pane fade show active" id="t1">
      <?php if ($ped->num_rows==0): ?>
        <div class="text-muted">No hay pedidos en este periodo.</div>
      <?php else: while($p=$ped->fetch_assoc()):
        $det = $conn->query("
          SELECT COALESCE(cat.nombre,'Sin categoría') AS categoria,
                 c.nombre, c.unidad, d.cantidad, d.comentario
          FROM insumos_pedidos_detalle d
          INNER JOIN insumos_catalogo c ON c.id=d.id_insumo
          LEFT JOIN insumos_categorias cat ON cat.id=c.id_categoria
          WHERE d.id_pedido={$p['id']}
          ORDER BY categoria, c.nombre
        "); ?>
        <div class="card mb-3">
          <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div><strong><?= htmlspecialchars($p['sucursal']) ?></strong> — Pedido #<?= (int)$p['id'] ?> (<?= htmlspecialchars($p['estatus']) ?>)</div>
            <div class="d-flex gap-2">
              <?php if ($p['estatus']==='Enviado'): ?>
                <form method="post" class="d-inline">
                  <input type="hidden" name="id_pedido" value="<?= (int)$p['id'] ?>">
                  <button class="btn btn-sm btn-outline-primary" name="accion" value="aprobar">Aprobar</button>
                  <button class="btn btn-sm btn-outline-danger"  name="accion" value="rechazar">Rechazar</button>
                </form>
              <?php endif; ?>
              <?php if ($p['estatus']==='Aprobado'): ?>
                <form method="post" class="d-inline">
                  <input type="hidden" name="id_pedido" value="<?= (int)$p['id'] ?>">
                  <button class="btn btn-sm btn-outline-success" name="accion" value="surtido" onclick="return confirm('¿Marcar como surtido?')">Marcar Surtido</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
          <div class="card-body">
            <?php if ($det->num_rows==0): ?>
              <div class="text-muted">Sin líneas.</div>
            <?php else: ?>
              <table class="table table-sm">
                <thead>
                  <tr>
                    <th>Categoría</th><th>Insumo</th>
                    <th class="text-end">Cantidad</th><th>Unidad</th><th>Comentario</th>
                  </tr>
                </thead>
                <tbody>
                  <?php while($r=$det->fetch_assoc()): ?>
                    <tr>
                      <td><?= htmlspecialchars($r['categoria']) ?></td>
                      <td><?= htmlspecialchars($r['nombre']) ?></td>
                      <td class="text-end"><?= number_format((float)$r['cantidad'],2) ?></td>
                      <td><?= htmlspecialchars($r['unidad']) ?></td>
                      <td><?= htmlspecialchars($r['comentario']) ?></td>
                    </tr>
                  <?php endwhile; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </div>
      <?php endwhile; endif; ?>
    </div>

    <div class="tab-pane fade" id="t2">
      <?php if (empty($rowsGeneral)): ?>
        <div class="text-muted">No hay concentrado (nada Enviado/Aprobado).</div>
      <?php else: ?>
        <table class="table table-sm">
          <thead>
            <tr><th>Categoría</th><th>Insumo</th><th>Unidad</th><th class="text-end">Cantidad total</th></tr>
          </thead>
          <tbody>
            <?php foreach($rowsGeneral as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['categoria']) ?></td>
                <td><?= htmlspecialchars($r['nombre']) ?></td>
                <td><?= htmlspecialchars($r['unidad']) ?></td>
                <td class="text-end"><?= number_format((float)$r['total_cant'],2) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>


</body>
</html>
