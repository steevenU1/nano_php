<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit; }

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/navbar.php')) require_once __DIR__ . '/navbar.php';

/* =======================
   Filtros (GET)
======================= */
$fSucursal  = isset($_GET['sucursal'])  ? (int)$_GET['sucursal'] : 0;   // 0 = Todas
$fMarca     = isset($_GET['marca'])     ? trim($_GET['marca'])    : '';
$fModelo    = isset($_GET['modelo'])    ? trim($_GET['modelo'])   : '';
$fCapacidad = isset($_GET['capacidad']) ? trim($_GET['capacidad']): '';
$verSuc     = isset($_GET['ver_suc'])   ? (int)$_GET['ver_suc']   : 0;  // 0 = compacta
$isMA       = isset($_GET['ma'])        ? (int)$_GET['ma'] === 1 : false;

/* En modo MA ignoramos selección de sucursal (el select solo lista propias) */
if ($isMA) { $fSucursal = 0; }

/* =======================
   Catálogo sucursales (solo PROPIAS para el select)
======================= */
$catSuc = [];
$rs = $conn->query("
  SELECT id, nombre
  FROM sucursales
  WHERE COALESCE(subtipo,'') NOT IN ('Subdistribuidor','Master Admin')
  ORDER BY nombre
");
while($r = $rs->fetch_assoc()){
  $catSuc[(int)$r['id']] = $r['nombre'];
}

/* =======================
   Catálogos dinámicos por scope
======================= */
$whereCat   = [];
$whereCat[] = "i.estatus IN ('Disponible','En tránsito')";
$whereCat[] = "p.tipo_producto = 'Equipo'";
$whereCat[] = $isMA
  ? "COALESCE(s.subtipo,'') = 'Master Admin'"
  : "COALESCE(s.subtipo,'') NOT IN ('Subdistribuidor','Master Admin')";

if ($fSucursal > 0)     $whereCat[] = "i.id_sucursal = " . (int)$fSucursal;
if ($fMarca !== '')     $whereCat[] = "p.marca = '" . $conn->real_escape_string($fMarca) . "'";
if ($fCapacidad !== '') $whereCat[] = "p.capacidad = '" . $conn->real_escape_string($fCapacidad) . "'";
$whereCatSql = $whereCat ? "WHERE " . implode(" AND ", $whereCat) : '';

$catMarcas = $catModelos = $catCaps = [];
$sqlCat = "
  SELECT DISTINCT p.marca, p.modelo, p.capacidad
  FROM inventario i
  INNER JOIN productos  p ON p.id = i.id_producto
  INNER JOIN sucursales s ON s.id = i.id_sucursal
  $whereCatSql
";
$rs = $conn->query($sqlCat);
while($r=$rs->fetch_assoc()){
  if ($r['marca']     !== null && $r['marca']     !== '') $catMarcas[$r['marca']] = true;
  if ($r['modelo']    !== null && $r['modelo']    !== '') $catModelos[$r['modelo']] = true;
  if ($r['capacidad'] !== null && $r['capacidad'] !== '') $catCaps[$r['capacidad']] = true;
}
$catMarcas  = array_keys($catMarcas);
$catModelos = array_keys($catModelos);
$catCaps    = array_keys($catCaps);

/* =======================
   Query agregado (pivot)
======================= */
$where   = [];
$where[] = "i.estatus IN ('Disponible','En tránsito')";
$where[] = "p.tipo_producto = 'Equipo'";
$where[] = $isMA
  ? "COALESCE(s.subtipo,'') = 'Master Admin'"
  : "COALESCE(s.subtipo,'') NOT IN ('Subdistribuidor','Master Admin')";

if ($fSucursal > 0)     $where[] = "i.id_sucursal = " . (int)$fSucursal;
if ($fMarca !== '')     $where[] = "p.marca = '" . $conn->real_escape_string($fMarca) . "'";
if ($fModelo !== '')    $where[] = "p.modelo = '" . $conn->real_escape_string($fModelo) . "'";
if ($fCapacidad !== '') $where[] = "p.capacidad = '" . $conn->real_escape_string($fCapacidad) . "'";
$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : '';

$sql = "
  SELECT 
    p.marca, p.modelo, p.capacidad,
    s.id   AS suc_id, 
    s.nombre AS sucursal,
    COUNT(*) AS qty
  FROM inventario i
  INNER JOIN productos  p ON p.id = i.id_producto
  INNER JOIN sucursales s ON s.id = i.id_sucursal
  $whereSql
  GROUP BY p.marca, p.modelo, p.capacidad, s.id, s.nombre
  ORDER BY p.marca, p.modelo, p.capacidad, s.nombre
";
$res = $conn->query($sql);

/* Pivot en PHP */
$rows   = [];   // cada valor: ['marca','modelo','capacidad','sucs'=>[sid=>qty],'total']
$sucSet = [];   // sucursales presentes en el resultado

while($r = $res->fetch_assoc()){
  $key = $r['marca'].'|'.$r['modelo'].'|'.$r['capacidad'];
  if (!isset($rows[$key])) {
    $rows[$key] = [
      'marca'     => $r['marca'],
      'modelo'    => $r['modelo'],
      'capacidad' => $r['capacidad'],
      'sucs'      => [],
      'total'     => 0
    ];
  }
  $sid = (int)$r['suc_id'];
  $qty = (int)$r['qty'];
  $rows[$key]['sucs'][$sid] = ($rows[$key]['sucs'][$sid] ?? 0) + $qty;
  $rows[$key]['total']     += $qty;

  $sucSet[$sid] = $r['sucursal'];
}

/* Sucursales para columnas (solo si verSuc=1) */
$useSuc = [];
if ($verSuc) {
  if ($fSucursal > 0) {
    if (isset($catSuc[$fSucursal]) || isset($sucSet[$fSucursal])) {
      $useSuc[$fSucursal] = $sucSet[$fSucursal] ?? $catSuc[$fSucursal];
    }
  } else {
    $useSuc = $sucSet; // ya vienen filtradas por scope en SQL
  }
  asort($useSuc, SORT_NATURAL | SORT_FLAG_CASE);
}

/* ===== Orden filas: por Total DESC, luego marca→modelo→capacidad ===== */
uasort($rows, function($a, $b){
  $ta = (int)$a['total'];
  $tb = (int)$b['total'];
  if ($ta !== $tb) return $tb <=> $ta; // DESC
  return strnatcasecmp($a['marca'], $b['marca'])
      ?: strnatcasecmp($a['modelo'], $b['modelo'])
      ?: strnatcasecmp($a['capacidad'], $b['capacidad']);
});

/* Totales por columna y gran total */
$colTotals  = [];
$grandTotal = 0;
if ($verSuc) { foreach ($useSuc as $sid => $name) $colTotals[$sid] = 0; }

foreach ($rows as $row) {
  if ($verSuc) {
    foreach ($useSuc as $sid => $name) {
      $colTotals[$sid] += ($row['sucs'][$sid] ?? 0);
    }
  }
  $grandTotal += (int)$row['total'];
}

$colspan = 3 + ($verSuc ? max(1, count($useSuc)) : 0) + 1;

/* Helpers */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
/* Quita prefijos 'MA NANORED' y 'NANORED' del encabezado de sucursal */
function shortSuc($name){
  $n = trim((string)$name);
  $n = preg_replace('/^\s*(MA\s+NANORED|NANORED)\b[:\-\s]*/i', '', $n);
  $n = preg_replace('/\s{2,}/', ' ', $n);
  return ($n !== '') ? $n : $name;
}
/* Helpers para URLs de botones */
function qs($merge){
  $base = $_GET;
  foreach($merge as $k=>$v){ if ($v===null){ unset($base[$k]); } else { $base[$k]=$v; } }
  return http_build_query($base);
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Resumen de inventario</title>
<link rel="icon" href="/img/favicon.ico?v=5" sizes="any">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{ --header-bg:#fff; --z-body-fixed:6; --z-head:8; --z-head-fixed-1:14; --z-head-fixed-2:13; --z-head-fixed-3:12; }
  html, body { height: 100%; }
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#f8fafc}
  .container{max-width:1300px;margin:14px auto;padding:0 12px}
  h2{margin:0 0 10px 0}

  .filters{display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin:8px 0 12px}
  .filters .group{display:flex;flex-direction:column;gap:4px}
  .filters select{padding:6px 8px;border:1px solid #cbd5e1;border-radius:8px;background:#fff}
  .btn{padding:8px 12px;border:1px solid #2563eb;background:#2563eb;color:#fff;border-radius:8px;cursor:pointer;text-decoration:none;display:inline-block}
  .btn.secondary{background:#fff;color:#111;border-color:#cbd5e1}
  .btn.ghost{background:#eef2ff;color:#0b5ed7;border-color:#dbeafe}

  /* Contenedor tabla: fallback; JS ajusta a viewport */
  .table-scroll{
    position:relative;
    height:65vh;        /* fallback si no corre JS */
    min-height:320px;
    overflow:auto;
    border:1px solid #e5e7eb;
    background:#fff;
    border-radius:8px;
  }

  table{border-collapse:separate;border-spacing:0;width:100%;white-space:nowrap}
  th,td{border-bottom:1px solid #edf2f7;padding:8px 10px;text-align:right}
  thead th{background:var(--header-bg);font-weight:600;border-bottom:2px solid #e5e7eb;position:sticky;top:0;z-index:var(--z-head)}
  tbody td:first-child, tbody td:nth-child(2), tbody td:nth-child(3){text-align:left}
  .num{font-variant-numeric:tabular-nums}

  .fixed{position:sticky;background:#fff}
  .fixed-1{left:0}
  .fixed-2{left:var(--left-col-2,120px)}
  .fixed-3{left:var(--left-col-3,300px)}
  .fixed-1, .fixed-2, .fixed-3{box-shadow:1px 0 0 0 #e5e7eb inset}

  thead th.fixed-1{z-index:var(--z-head-fixed-1)}
  thead th.fixed-2{z-index:var(--z-head-fixed-2)}
  thead th.fixed-3{z-index:var(--z-head-fixed-3)}
  tbody td.fixed{z-index:var(--z-body-fixed)}
  tfoot th.fixed{z-index:var(--z-body-fixed)}

  .suc-th{ writing-mode:vertical-rl; transform: rotate(180deg); text-align:left; vertical-align:bottom; min-width:28px; padding:6px 4px; }
  tbody tr:hover td, tbody tr:hover .fixed{background:#eef4ff !important}

  .total-th, .total-td{background:#fafafa;font-weight:600}
  tfoot th, tfoot td{background:#f6f7fb;font-weight:700;border-top:2px solid #e5e7eb}
  .muted{color:#64748b;font-size:12px}

  .cell-modelo{
    display:inline-flex; align-items:center; gap:8px;
    padding:4px 10px; border-radius:999px;
    background:#eef2ff; color:#0b5ed7;
    border:1px solid #dbeafe; cursor:pointer;
    transition:background .15s, border-color .15s, box-shadow .15s;
    font-weight:600;
  }
  .cell-modelo:hover{ background:#e0e7ff; border-color:#c7d2fe; box-shadow:0 0 0 2px #e0e7ff; }
  .item-row.open .cell-modelo{ background:#dbeafe; border-color:#93c5fd; }

  .cell-modelo .chev{
    width:0; height:0;
    border-top:5px solid transparent;
    border-bottom:5px solid transparent;
    border-left:6px solid currentColor;
    transition:transform .2s ease;
  }
  .item-row.open .cell-modelo .chev{ transform:rotate(90deg); }

  .detail-row td{ background:#fbfbff; border-bottom:1px solid #e5e7eb; }
  .detail-row .detail-box{ padding:10px 12px; animation:fadeIn .15s ease-in; }
  @keyframes fadeIn{ from{opacity:.4} to{opacity:1} }

  .spinner{display:inline-block; width:18px; height:18px; border:2px solid #cbd5e1; border-top-color:#2563eb; border-radius:50%; animation:spin 0.8s linear infinite; vertical-align:middle}
  .mini{font-size:13px}
</style>

</head>
<body>
<div class="container">
  <h2>Resumen de inventario</h2>

  <form class="filters" method="get">
    <!-- preserva estado de vista al filtrar -->
    <input type="hidden" name="ver_suc" value="<?= (int)$verSuc ?>">
    <input type="hidden" name="ma" value="<?= $isMA ? 1 : 0 ?>">

    <div class="group">
      <label for="sucursal">Sucursal</label>
      <select name="sucursal" id="sucursal" <?= $isMA ? 'disabled title="No aplica en Vista MA"' : '' ?>>
        <option value="0">Todas</option>
        <?php foreach($catSuc as $id=>$nom): ?>
          <option value="<?=$id?>" <?=$id===$fSucursal?'selected':''?>><?=h($nom)?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($isMA): ?><small class="muted">Modo MA activo</small><?php endif; ?>
    </div>

    <div class="group">
      <label for="marca">Marca</label>
      <select name="marca" id="marca">
        <option value="">Todas</option>
        <?php foreach($catMarcas as $m): ?>
          <option value="<?=h($m)?>" <?=($m===$fMarca)?'selected':''?>><?=h($m)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="group">
      <label for="modelo">Modelo</label>
      <select name="modelo" id="modelo">
        <option value="">Todos</option>
        <?php foreach($catModelos as $m): ?>  <!-- FIX: 'as', no 'como' -->
          <option value="<?=h($m)?>" <?=($m===$fModelo)?'selected':''?>><?=h($m)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="group">
      <label for="capacidad">Capacidad</label>
      <select name="capacidad" id="capacidad">
        <option value="">Todas</option>
        <?php foreach($catCaps as $c): ?>
          <option value="<?=h($c)?>" <?=($c===$fCapacidad)?'selected':''?>><?=h($c)?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="group">
      <label>&nbsp;</label>
      <button class="btn" type="submit">Filtrar</button>
    </div>

    <?php if ($fSucursal||$fMarca||$fModelo||$fCapacidad||$verSuc||$isMA): ?>
    <div class="group">
      <label>&nbsp;</label>
      <a class="btn secondary" href="inventario_resumen.php">Limpiar</a>
    </div>
    <?php endif; ?>

    <!-- Botones de vista (derecha) -->
    <div class="group" style="margin-left:auto">
      <label>&nbsp;</label>
      <?php if ($verSuc): ?>
        <a class="btn ghost" href="?<?=qs(['ver_suc'=>0])?>">Vista compacta</a>
      <?php else: ?>
        <a class="btn ghost" href="?<?=qs(['ver_suc'=>1])?>">Vista por sucursal</a>
      <?php endif; ?>
      <?php if ($isMA): ?>
        <a class="btn ghost" href="?<?=qs(['ma'=>0, 'sucursal'=>0])?>">Vista Propias</a>
      <?php else: ?>
        <a class="btn ghost" href="?<?=qs(['ma'=>1, 'sucursal'=>0])?>">Vista Master Admin</a>
      <?php endif; ?>
    </div>
  </form>

  <div class="table-scroll" id="tblWrap">
    <table id="resumenTbl">
      <thead>
        <tr>
          <th class="fixed fixed-1" style="text-align:left">Marca</th>
          <th class="fixed fixed-2" style="text-align:left">Modelo</th>
          <th class="fixed fixed-3" style="text-align:left">Capacidad</th>

          <?php if ($verSuc): ?>
            <?php if (empty($useSuc)): ?>
              <th class="suc-th">—</th>
            <?php else: foreach($useSuc as $sid=>$sNom): ?>
              <th class="suc-th" title="<?=h($sNom)?>"><?=h(shortSuc($sNom))?></th>
            <?php endforeach; endif; ?>
          <?php endif; ?>

          <th class="total-th">Total</th>
        </tr>
      </thead>

      <tbody>
        <?php if (empty($rows)): ?>
          <tr>
            <td class="fixed fixed-1" colspan="<?=$colspan?>" style="text-align:center;color:#64748b">
              Sin resultados con los filtros seleccionados.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach($rows as $row): ?>
            <tr class="item-row"
                data-marca="<?=h($row['marca'])?>"
                data-modelo="<?=h($row['modelo'])?>"
                data-capacidad="<?=h($row['capacidad'])?>"
                data-sucursal="<?= (int)$fSucursal ?>"
                data-ma="<?= $isMA ? 1 : 0 ?>">
              <td class="fixed fixed-1"><?=h($row['marca'])?></td>
              <td class="fixed fixed-2" title="Ver detalle por sucursal y color">
                <button type="button" class="cell-modelo" aria-expanded="false">
                  <span class="chev" aria-hidden="true"></span>
                  <span class="label"><?=h($row['modelo'])?></span>
                </button>
              </td>
              <td class="fixed fixed-3"><?=h($row['capacidad'])?></td>

              <?php if ($verSuc): ?>
                <?php if (empty($useSuc)): ?>
                  <td class="num">0</td>
                <?php else: foreach($useSuc as $sid=>$sNom):
                        $q = $row['sucs'][$sid] ?? 0; ?>
                  <td class="num"><?= $q ?: '0' ?></td>
                <?php endforeach; endif; ?>
              <?php endif; ?>

              <td class="total-td num"><?= (int)$row['total'] ?></td>
            </tr>
            <!-- Fila detalle -->
            <tr class="detail-row" style="display:none">
              <td colspan="<?=$colspan?>" class="mini">
                <div class="detail-box">
                  <span class="spinner"></span> Cargando detalle...
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>

      <tfoot>
        <tr>
          <th class="fixed fixed-1" style="text-align:left">Totales</th>
          <th class="fixed fixed-2"></th>
          <th class="fixed fixed-3"></th>

          <?php if ($verSuc): ?>
            <?php if (empty($useSuc)): ?>
              <th class="num">0</th>
            <?php else: foreach($useSuc as $sid=>$sNom): ?>
              <th class="num"><?= (int)$colTotals[$sid] ?></th>
            <?php endforeach; endif; ?>
          <?php endif; ?>

          <th class="num"><?= (int)$grandTotal ?></th>
        </tr>
      </tfoot>
    </table>
  </div>

  <div class="muted" style="margin-top:8px">
    * Vista actual: <b><?= $isMA ? 'Master Admin' : 'Tiendas propias' ?></b> · 
      <b><?= $verSuc ? 'Por sucursal' : 'Compacta' ?></b> ·
      <b>Orden: Total ↓</b>.
  </div>
</div>

<script>
/* Offsets para columnas fijas */
function setStickyOffsets(){
  const tbl = document.getElementById('resumenTbl');
  if (!tbl) return;
  const c1 = tbl.querySelector('thead .fixed-1');
  const c2 = tbl.querySelector('thead .fixed-2');
  if (!c1 || !c2) return;
  const w1 = c1.getBoundingClientRect().width;
  const w2 = c2.getBoundingClientRect().width;
  tbl.style.setProperty('--left-col-2', Math.round(w1) + 'px');
  tbl.style.setProperty('--left-col-3', Math.round(w1 + w2) + 'px');
}

/* Altura dinámica: ocupa todo el alto disponible de la ventana */
function setTableViewportHeight(){
  const wrap = document.getElementById('tblWrap');
  if (!wrap) return;

  const rectTop = wrap.getBoundingClientRect().top;
  const vh = window.innerHeight || document.documentElement.clientHeight;
  const bottomPadding = 12;
  const h = Math.max(320, Math.floor(vh - rectTop - bottomPadding));

  wrap.style.height = h + 'px';
  wrap.style.maxHeight = h + 'px';
}

/* Bind de eventos */
function reflow(){
  setTableViewportHeight();
  setStickyOffsets();
}
window.addEventListener('load', reflow);
window.addEventListener('resize', reflow);
window.addEventListener('scroll', reflow, { passive: true });

const wrap = document.getElementById('tblWrap');
if (wrap) {
  new ResizeObserver(reflow).observe(wrap);
}

/* Toggle detalle por modelo (carga AJAX y cachea en la fila) */
document.querySelectorAll('#resumenTbl tbody tr.item-row').forEach(function(row){
  const btn = row.querySelector('.cell-modelo');
  const detailRow = row.nextElementSibling;

  btn.addEventListener('click', async function(e){
    e.preventDefault();

    document.querySelectorAll('#resumenTbl tbody tr.detail-row').forEach(dr=>{
      if (dr !== detailRow) dr.style.display = 'none';
    });
    document.querySelectorAll('#resumenTbl tbody tr.item-row.open').forEach(r=>{
      if (r !== row) { r.classList.remove('open'); const b=r.querySelector('.cell-modelo'); if(b) b.setAttribute('aria-expanded','false'); }
    });

    const isOpen = detailRow.style.display === 'table-row';
    if (isOpen) {
      detailRow.style.display = 'none';
      row.classList.remove('open');
      btn.setAttribute('aria-expanded','false');
      return;
    }

    detailRow.style.display = 'table-row';
    row.classList.add('open');
    btn.setAttribute('aria-expanded','true');

    const box = detailRow.querySelector('.detail-box');
    box.innerHTML = '<span class="spinner"></span> Cargando detalle...';

    const marca = encodeURIComponent(row.dataset.marca || '');
    const modelo = encodeURIComponent(row.dataset.modelo || '');
    const capacidad = encodeURIComponent(row.dataset.capacidad || '');
    const sucursal = encodeURIComponent(row.dataset.sucursal || '0');
    const ma = encodeURIComponent(row.dataset.ma || '0');

    try{
      const resp = await fetch(`inventario_resumen_detalle.php?marca=${marca}&modelo=${modelo}&capacidad=${capacidad}&sucursal=${sucursal}&ma=${ma}`);
      const html = await resp.text();
      box.innerHTML = html;
    }catch(err){
      box.innerHTML = '<span class="badge" style="background:#fee2e2;color:#991b1b">Error al cargar detalle</span>';
    }
  });

  btn.addEventListener('keydown', (e)=>{
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); btn.click(); }
  });
});
</script>
</body>
</html>
