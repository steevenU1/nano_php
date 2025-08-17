<?php
// export_historial_ma.php
session_start();
if (!isset($_SESSION['id_usuario'])) { exit('No autorizado'); }
require 'db.php';

// ===== Helpers
function esc($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function mes_corto_es($n){ static $m=[1=>'Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic']; return $m[(int)$n]??''; }
function fecha_corta_es(DateTime $d){ return $d->format('d').' '.mes_corto_es($d->format('n')).' '.$d->format('Y'); }

// ===== Semana Mar→Lun (usa ?w=)
$w = (int)($_GET['w'] ?? 0);
$hoy = new DateTime('today');
$dif = (int)$hoy->format('N') - 2; if ($dif < 0) $dif += 7;
$inicio = (new DateTime('today'))->modify("-{$dif} days")->setTime(0,0,0);
if ($w !== 0) { $inicio->modify(($w>0?'+':'').($w*7).' days'); }
$fin = (clone $inicio)->modify('+6 days')->setTime(23,59,59);

$desde = $inicio->format('Y-m-d');
$hasta = $fin->format('Y-m-d');
$labelSemana = fecha_corta_es($inicio).' — '.fecha_corta_es($fin);

// ===== Filtros extra
$id_sucursal = (int)($_GET['sucursal'] ?? 0);
$busca_imei  = trim($_GET['imei'] ?? '');

// ===== WHERE compartido
$where  = " s.subtipo='Master Admin' AND DATE(v.fecha_venta) BETWEEN ? AND ? ";
$params = [$desde, $hasta];
$types  = "ss";
if ($id_sucursal > 0) {
  $where   .= " AND v.id_sucursal=? ";
  $params[] = $id_sucursal; $types .= "i";
}
if ($busca_imei !== '') {
  $where   .= " AND EXISTS (SELECT 1 FROM detalle_venta dv WHERE dv.id_venta=v.id AND dv.imei1 LIKE ?) ";
  $params[] = '%'.$busca_imei.'%'; $types .= "s";
}

// ===== Query
$sql = "
  SELECT v.id, v.fecha_venta, s.nombre AS sucursal, u.nombre AS vendedor,
         v.tipo_venta, v.origen_ma, v.precio_venta, v.enganche,
         v.comision_master_admin, v.comentarios,
         (SELECT GROUP_CONCAT(DISTINCT dv.imei1 ORDER BY dv.imei1 SEPARATOR ', ')
            FROM detalle_venta dv WHERE dv.id_venta = v.id) AS imeis
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

// ===== Encabezados (solo HTML)
header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=historial_ventas_master_admin.xls");
header("Pragma: no-cache"); header("Expires: 0");
echo "\xEF\xBB\xBF"; // BOM UTF-8
?>
<meta charset="UTF-8">
<table border="1" cellspacing="0" cellpadding="4">
  <tr style="background:#f2f2f2;"><th colspan="11" align="left">Semana <?= esc($labelSemana) ?> (Mar → Lun)</th></tr>
  <tr style="background:#222;color:#fff;">
    <th>ID</th>
    <th>Fecha</th>
    <th>Sucursal</th>
    <th>Capturó</th>
    <th>Tipo</th>
    <th>Origen</th>
    <th>IMEI(s)</th>
    <th>Precio</th>
    <th>Enganche</th>
    <th>Comisión MA</th>
    <th>Comentarios</th>
  </tr>
<?php while ($row = $rs->fetch_assoc()): ?>
  <?php
    $fecha = date('d/m/Y', strtotime($row['fecha_venta']));
    $origen = ($row['origen_ma']==='nano' ? 'Nano' : ($row['origen_ma']==='propio' ? 'Propio' : '—'));
    $imeis = ($row['origen_ma']==='nano') ? ($row['imeis'] ?: '') : '';
  ?>
  <tr>
    <td><?= (int)$row['id'] ?></td>
    <td><?= esc($fecha) ?></td>
    <td><?= esc($row['sucursal']) ?></td>
    <td><?= esc($row['vendedor']) ?></td>
    <td><?= esc($row['tipo_venta']) ?></td>
    <td><?= esc($origen) ?></td>
    <td><?= esc($imeis) ?></td>
    <td style="mso-number-format:'0.00';"><?= (float)$row['precio_venta'] ?></td>
    <td style="mso-number-format:'0.00';"><?= (float)$row['enganche'] ?></td>
    <td style="mso-number-format:'0.00';"><?= (float)$row['comision_master_admin'] ?></td>
    <td><?= esc(str_replace(["\t","\n","\r"], " ", $row['comentarios'])) ?></td>
  </tr>
<?php endwhile; ?>
</table>
