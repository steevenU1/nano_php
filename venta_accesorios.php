<?php
// venta_accesorios.php — Venta de Accesorios (con modo REGALO)
// - Checkbox "Venta con regalo": fuerza totales y pagos a $0 y oculta SOLO los campos de pago.
// - El botón Guardar SIEMPRE visible.
// - Whitelist de modelos regalables desde accesorios_regalo_modelos(id_producto, activo).

session_start();
if (!isset($_SESSION['id_usuario'])) { header('Location: index.php'); exit(); }

require_once __DIR__.'/db.php';
require_once __DIR__.'/navbar.php';

date_default_timezone_set('America/Mexico_City');

$ROL         = $_SESSION['rol'] ?? '';
$ID_USUARIO  = (int)($_SESSION['id_usuario'] ?? 0);
$ID_SUCURSAL = (int)($_SESSION['id_sucursal'] ?? 0);

$ROLES_PERMITIDOS = ['Ejecutivo','Gerente','Admin','GerenteZona','Logistica'];
if (!in_array($ROL, $ROLES_PERMITIDOS, true)) { header('Location: 403.php'); exit(); }

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/* -------- Nombre real de sucursal (read-only) -------- */
$sucursalNombre = trim($_SESSION['sucursal_nombre'] ?? '');
if ($sucursalNombre === '' && $ID_SUCURSAL > 0) {
  if ($st = $conn->prepare("SELECT nombre FROM sucursales WHERE id=? LIMIT 1")) {
    $st->bind_param('i', $ID_SUCURSAL);
    $st->execute();
    if ($row = $st->get_result()->fetch_assoc()) {
      $sucursalNombre = $row['nombre'] ?: '';
      if ($sucursalNombre !== '') $_SESSION['sucursal_nombre'] = $sucursalNombre;
    }
  }
}
if ($sucursalNombre === '') $sucursalNombre = 'Sucursal #'.$ID_SUCURSAL;

/* -------- Whitelist de modelos regalables -------- */
$regaloPermitidos = [];
$tblCheck = $conn->query("SHOW TABLES LIKE 'accesorios_regalo_modelos'");
if ($tblCheck && $tblCheck->num_rows > 0) {
  $rs = $conn->query("SELECT id_producto FROM accesorios_regalo_modelos WHERE activo=1");
  if ($rs) while ($r = $rs->fetch_assoc()) $regaloPermitidos[] = (int)$r['id_producto'];
}
$regaloPermitidos = array_values(array_unique(array_filter($regaloPermitidos)));

/* -------- Catálogo de accesorios con stock por sucursal -------- */
$soloSucursal = !in_array($ROL, ['Admin','Logistica','GerenteZona'], true);

$params = [];
$sql = "
  SELECT 
    p.id AS id_producto,
    TRIM(CONCAT(p.marca,' ',p.modelo,' ',COALESCE(p.color,''))) AS nombre,
    COALESCE(p.precio_lista,0) AS precio_sugerido,
    SUM(
      CASE WHEN i.estatus IN ('Disponible','Parcial','En stock') 
           THEN COALESCE(i.cantidad,1) ELSE 0 END
    ) AS stock_disp
  FROM productos p
  JOIN inventario i ON i.id_producto = p.id
  WHERE (p.tipo_producto='Accesorio' OR (COALESCE(p.imei1,'')='' AND COALESCE(p.imei2,'')=''))
";
if ($soloSucursal) { $sql .= " AND i.id_sucursal=? "; $params[] = $ID_SUCURSAL; }
$sql .= " GROUP BY p.id, p.marca, p.modelo, p.color, p.precio_lista
          HAVING stock_disp > 0
          ORDER BY nombre ASC";

$stmt = $conn->prepare($sql);
if ($stmt === false) { die('Error preparando SQL de accesorios.'); }
if ($params) { $types = str_repeat('i', count($params)); $stmt->bind_param($types, ...$params); }
$stmt->execute();
$res = $stmt->get_result();
$accesorios = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Venta de Accesorios</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:linear-gradient(135deg,#f6f9fc 0%,#edf2f7 100%)}
    .card-ghost{backdrop-filter:saturate(140%) blur(6px); border:1px solid rgba(0,0,0,.06); box-shadow:0 10px 25px rgba(0,0,0,.06)}
    .table thead th{position:sticky;top:0;background:#fff;z-index:1}
    .money{text-align:right}
    .total-chip{font-size:1.1rem;padding:.35rem .7rem;border-radius:.8rem;background:#0d6efd1a;border:1px solid #0d6efd33}
    .badge-soft{background:#6c757d14;border:1px solid #6c757d2e}
    .section-title{font-weight:700; letter-spacing:.2px}
    .modal-xxl{--bs-modal-width:min(1100px,96vw)}
    .ticket-frame{width:100%;height:80vh;border:0;border-radius:.75rem;background:#fff}

    /* Selector rápido (portal) */
    .fast-wrap{position:relative}
    .fast-input{padding-right:34px}
    .fast-kbd{position:absolute; right:8px; top:8px; font-size:.75rem; color:#6c757d}
    .fast-portal {
      position: fixed; z-index: 5000; background:#fff;
      border:1px solid #dee2e6; border-radius:0 0 .5rem .5rem;
      box-shadow:0 12px 24px rgba(0,0,0,.12);
      max-height: 260px; overflow:auto; display:none;
    }
    .fast-item{padding:.45rem .6rem; cursor:pointer}
    .fast-item:hover,.fast-item.active{background:#0d6efd10}
    .fast-item.muted{color:#6c757d}
    .fast-item .tag{font-size:.75rem; padding:.1rem .3rem; border:1px solid #dee2e6; border-radius:.35rem; margin-left:.4rem}
    .fast-item.blocked{opacity:.5; cursor:not-allowed}
    .fast-item.blocked:hover{background:transparent}

    .hidden-fields{display:none !important} /* solo para ocultar campos de pago */
  </style>
</head>
<body>
<div class="container py-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h3 class="mb-0">Venta de Accesorios</h3>
      <span class="badge rounded-pill text-secondary badge-soft">Sucursal: <?= h($sucursalNombre) ?></span>
    </div>
    <div><a href="dashboard_luga.php" class="btn btn-outline-secondary btn-sm">Volver</a></div>
  </div>

  <form id="frmVenta" action="procesar_venta_accesorios.php" method="post" class="card card-ghost p-3">
    <input type="hidden" name="id_sucursal" value="<?= (int)$ID_SUCURSAL ?>">
    <input type="hidden" name="es_regalo" id="es_regalo" value="0"><!-- backend guard -->

    <!-- Encabezado -->
    <div class="row g-3 align-items-end">
      <div class="col-md-3">
        <label class="form-label section-title">TAG</label>
        <input type="text" name="tag" class="form-control" maxlength="50" required>
      </div>
      <div class="col-md-4">
        <label class="form-label section-title">Nombre del cliente</label>
        <input type="text" name="nombre_cliente" class="form-control" required>
      </div>
      <div class="col-md-3">
        <label class="form-label section-title">Teléfono</label>
        <input type="text" name="telefono" pattern="^[0-9]{10}$" title="10 dígitos" class="form-control" required>
      </div>
      <div class="col-md-2">
        <label class="form-label section-title">Sucursal</label>
        <input type="text" class="form-control" value="<?=h($sucursalNombre)?>" readonly>
      </div>
      <div class="col-12">
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" id="chkRegalo">
          <label class="form-check-label" for="chkRegalo">
            Venta con <strong>regalo</strong> (solo modelos elegibles; total y pagos en $0)
          </label>
        </div>
        <div id="ayudaRegalo" class="form-text">
          Modelos elegibles configurados por Admin/Logística.
        </div>
      </div>
    </div>

    <hr class="my-3">

    <!-- Líneas -->
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h5 class="mb-0 section-title">Líneas de accesorios</h5>
      <button type="button" class="btn btn-primary btn-sm" id="btnAdd">Agregar línea</button>
    </div>

    <div class="table-responsive">
      <table class="table table-sm align-middle" id="tblLineas">
        <thead class="table-light">
          <tr>
            <th style="width:42%">Accesorio</th>
            <th style="width:10%" class="text-center">Stock</th>
            <th style="width:12%">Cantidad</th>
            <th style="width:16%">Precio</th>
            <th style="width:16%" class="text-end">Subtotal</th>
            <th style="width:8%"></th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>

    <div class="row g-3 mt-2">
      <div class="col-md-7">
        <label class="form-label">Comentarios</label>
        <input type="text" name="comentarios" class="form-control" maxlength="255" placeholder="Opcional…">
      </div>
      <div class="col-md-5 d-flex align-items-end justify-content-end">
        <div class="total-chip"><strong>Total: <span id="lblTotal">$0.00</span></strong></div>
      </div>
    </div>

    <hr class="my-3">

    <!-- Campos de pago (se ocultan en REGALO) -->
    <div id="pagosCampos" class="row g-3">
      <div class="col-md-3">
        <label class="form-label section-title">Forma de pago</label>
        <select class="form-select" name="forma_pago" id="formaPago" required>
          <option value="Efectivo">Efectivo</option>
          <option value="Tarjeta">Tarjeta</option>
          <option value="Mixto">Mixto</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label section-title">Efectivo</label>
        <input type="number" step="0.01" min="0" name="efectivo" id="inpEfectivo" class="form-control" value="0">
      </div>
      <div class="col-md-3">
        <label class="form-label section-title">Tarjeta</label>
        <input type="number" step="0.01" min="0" name="tarjeta" id="inpTarjeta" class="form-control" value="0">
      </div>
    </div>

    <!-- Acciones (botón SIEMPRE visible) -->
    <div id="accionesVenta" class="row g-3 mt-1">
      <div class="col-md-3 ms-auto d-flex align-items-end">
        <button class="btn btn-success w-100" type="submit">Guardar venta</button>
      </div>
    </div>
  </form>
</div>

<!-- Modal Ticket -->
<div class="modal fade" id="ticketModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xxl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Ticket de venta</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <iframe id="ticketFrame" class="ticket-frame" src="about:blank"></iframe>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
        <button class="btn btn-primary" id="btnPrintTicket">Imprimir</button>
      </div>
    </div>
  </div>
</div>

<script>
const accesorios = <?php echo json_encode($accesorios, JSON_UNESCAPED_UNICODE); ?>;
const REGALO_PERMITIDOS = new Set(<?php echo json_encode($regaloPermitidos, JSON_UNESCAPED_UNICODE); ?>);

const tbody        = document.querySelector('#tblLineas tbody');
const lblTotal     = document.getElementById('lblTotal');
const formaPago    = document.getElementById('formaPago');
const inpEf        = document.getElementById('inpEfectivo');
const inpTa        = document.getElementById('inpTarjeta');
const pagosCampos  = document.getElementById('pagosCampos'); // ← solo esto se oculta
const chkRegalo    = document.getElementById('chkRegalo');
const esRegaloInp  = document.getElementById('es_regalo');

function money(n){ return new Intl.NumberFormat('es-MX',{style:'currency',currency:'MXN'}).format(Number(n||0)); }
function isRegalo(){ return chkRegalo.checked === true; }

/* ---------- Selector rápido con PORTAL al <body> ---------- */
let FAST_PORTAL = null;
let FAST_OWNER  = null;
let FAST_SELECT = null;

function ensurePortal(){
  if (!FAST_PORTAL){
    FAST_PORTAL = document.createElement('div');
    FAST_PORTAL.className = 'fast-portal';
    FAST_PORTAL.style.display = 'none';
    document.body.appendChild(FAST_PORTAL);
    document.addEventListener('click', (e)=>{
      if (FAST_PORTAL.style.display==='none') return;
      if (FAST_OWNER && (e.target===FAST_OWNER || FAST_PORTAL.contains(e.target))) return;
      closePortal();
    }, true);
    window.addEventListener('scroll', ()=>repositionPortal(), true);
    window.addEventListener('resize',  ()=>repositionPortal());
  }
}
function openPortal(forInput, selectEl){
  ensurePortal();
  FAST_OWNER  = forInput;
  FAST_SELECT = selectEl;
  renderPortal(forInput.value);
  repositionPortal();
  FAST_PORTAL.style.display = 'block';
}
function closePortal(){ if (FAST_PORTAL) FAST_PORTAL.style.display='none'; FAST_OWNER = FAST_SELECT = null; }
function repositionPortal(){
  if (!FAST_PORTAL || !FAST_OWNER) return;
  const r = FAST_OWNER.getBoundingClientRect();
  FAST_PORTAL.style.left   = `${r.left}px`;
  FAST_PORTAL.style.top    = `${r.bottom}px`;
  FAST_PORTAL.style.width  = `${r.width}px`;
}
function renderPortal(q){
  if (!FAST_PORTAL) return;
  const term = (q||'').trim().toLowerCase();
  const rows = accesorios.filter(a => term==='' || a.nombre.toLowerCase().includes(term)).slice(0,150);
  FAST_PORTAL.innerHTML = rows.length
    ? rows.map(a => {
        const elig = REGALO_PERMITIDOS.has(Number(a.id_producto));
        const bloqueado = isRegalo() && !elig;
        const tag = isRegalo() ? (elig ? '<span class="tag">Elegible</span>' : '<span class="tag">No elegible (regalo)</span>') : '';
        return `
          <div class="fast-item ${bloqueado?'blocked':''}" data-id="${a.id_producto}" data-precio="${a.precio_sugerido}" data-stock="${a.stock_disp}" data-elig="${elig?1:0}">
            ${a.nombre} ${tag}
          </div>`;
      }).join('')
    : `<div class="fast-item text-muted">Sin coincidencias</div>`;
}
function buildFastSelector(td, selectEl){
  td.classList.add('fast-wrap');
  const fast = document.createElement('input');
  fast.type = 'text';
  fast.className = 'form-control fast-input';
  fast.placeholder = 'Buscar accesorio…';
  fast.autocomplete = 'off';
  const kbd = document.createElement('span');
  kbd.className = 'fast-kbd';
  kbd.textContent = '⌄';
  td.prepend(fast);
  td.appendChild(kbd);

  fast.addEventListener('focus', ()=>openPortal(fast, selectEl));
  fast.addEventListener('input', ()=>renderPortal(fast.value));
  fast.addEventListener('keydown', (e)=>{
    if (FAST_PORTAL?.style.display!=='block') return;
    const items = Array.from(FAST_PORTAL.querySelectorAll('.fast-item[data-id]'));
    const cur   = FAST_PORTAL.querySelector('.fast-item.active');
    let idx     = items.indexOf(cur);
    if (e.key==='ArrowDown'){ e.preventDefault(); idx = Math.min(idx+1, items.length-1); }
    if (e.key==='ArrowUp'){   e.preventDefault(); idx = Math.max(idx-1, 0); }
    if (e.key==='Enter' && cur){ e.preventDefault(); cur.click(); return; }
    if (e.key==='Escape'){ closePortal(); return; }
    if (idx>=0 && items[idx]){ items.forEach(x=>x.classList.remove('active')); items[idx].classList.add('active'); items[idx].scrollIntoView({block:'nearest'}); }
  });

  ensurePortal();
  FAST_PORTAL.addEventListener('click', (e)=>{
    if (FAST_PORTAL.style.display!=='block') return;
    const it = e.target.closest('.fast-item[data-id]');
    if (!it) return;
    if (isRegalo() && it.classList.contains('blocked')) return;

    const id     = it.dataset.id;
    const precio = Number(it.dataset.precio||0);
    const stock  = Number(it.dataset.stock||0);
    const eleg   = Number(it.dataset.elig||0) === 1;

    selectEl.value = id;
    const tr = selectEl.closest('tr');
    tr.querySelector('.stock').textContent = stock;

    const priceInput = tr.querySelector('.money');
    if (!isRegalo() && precio>0) priceInput.value = precio;

    fast.value = it.textContent.replace(/Elegible|No elegible \(regalo\)/g,'').trim();
    tr.dataset.elegible = eleg ? '1' : '0';

    closePortal(); recalc();
  });
}

/* ---------- Construcción de fila ---------- */
function addRow(){
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td>
      <select name="linea_id_producto[]" class="form-select selProducto d-none" tabindex="-1" aria-hidden="true">
        <option value="">—</option>
        ${accesorios.map(a=>`<option value="${a.id_producto}" data-precio="${a.precio_sugerido}" data-stock="${a.stock_disp}">${a.nombre}</option>`).join('')}
      </select>
    </td>
    <td class="text-center stock">0</td>
    <td><input type="number" name="linea_cantidad[]" class="form-control cant" min="1" value="1" required></td>
    <td><input type="number" name="linea_precio[]" class="form-control money" step="0.01" min="0" value="0" required></td>
    <td class="money subtotal text-end">$0.00</td>
    <td class="text-end"><button type="button" class="btn btn-outline-danger btn-sm btnDel">Quitar</button></td>
  `;
  tbody.appendChild(tr);

  const tdAcc = tr.children[0];
  const sel = tdAcc.querySelector('select.selProducto');
  buildFastSelector(tdAcc, sel);
  updateRowForRegalo(tr);
}
document.getElementById('btnAdd').addEventListener('click', addRow);

/* ---------- Recalcular totales ---------- */
function recalc(){
  let total = 0;
  tbody.querySelectorAll('tr').forEach(tr=>{
    const cant  = Number(tr.querySelector('.cant').value||0);
    let priceEl = tr.querySelector('.money');
    let price   = Number(priceEl.value||0);

    if (isRegalo()){
      price = 0;
      priceEl.value = '0.00';
    }

    const sub = cant * price;
    total += sub;
    tr.querySelector('.subtotal').textContent = money(sub);
  });

  if (isRegalo()) total = 0;
  lblTotal.textContent = money(total);
  syncPagos();
}
tbody.addEventListener('input', e=>{
  if (e.target.classList.contains('cant') || e.target.classList.contains('money')) recalc();
});
tbody.addEventListener('click', e=>{
  if (e.target.classList.contains('btnDel')){
    e.target.closest('tr').remove();
    recalc();
  }
});

/* ---------- Pagos ---------- */
function syncPagos(){
  const total = Number(lblTotal.textContent.replace(/[^0-9.]/g,'')) || 0;

  if (isRegalo()){
    // Ocultar SOLO los campos de pago
    pagosCampos.classList.add('hidden-fields');
    formaPago.value = 'Efectivo';
    inpEf.value = '0.00';
    inpTa.value = '0.00';
    inpEf.readOnly = true;
    inpTa.readOnly = true;
    return;
  }

  pagosCampos.classList.remove('hidden-fields');

  switch(formaPago.value){
    case 'Efectivo':
      inpEf.value = total.toFixed(2); inpTa.value = '0.00';
      inpEf.readOnly = false; inpTa.readOnly = true; break;
    case 'Tarjeta':
      inpEf.value = '0.00'; inpTa.value = total.toFixed(2);
      inpEf.readOnly = true; inpTa.readOnly = false; break;
    case 'Mixto':
      inpEf.readOnly = false; inpTa.readOnly = false; break;
  }
}
formaPago.addEventListener('change', syncPagos);

/* ---------- Modo REGALO ---------- */
function updateRowForRegalo(tr){
  const priceInput = tr.querySelector('.money');
  if (isRegalo()){
    priceInput.value = '0.00';
    priceInput.readOnly = true;
  } else {
    priceInput.readOnly = false;
  }
}
function applyRegaloModeToAllRows(){
  tbody.querySelectorAll('tr').forEach(tr=>updateRowForRegalo(tr));
}
chkRegalo.addEventListener('change', ()=>{
  esRegaloInp.value = isRegalo() ? '1' : '0';
  applyRegaloModeToAllRows();
  recalc();
});

/* ---------- Validaciones ---------- */
function validarLineas(){
  if (tbody.children.length === 0){ alert('Agrega al menos una línea.'); return false; }

  let ok = true;
  let msg = '';

  tbody.querySelectorAll('tr').forEach((tr)=>{
    const sel   = tr.querySelector('.selProducto');
    const stock = Number(tr.querySelector('.stock').textContent||0);
    const cant  = Number(tr.querySelector('.cant').value||0);
    const price = Number(tr.querySelector('.money').value||-1);
    const eleg  = tr.dataset.elegible === '1';

    if (!sel.value){ ok=false; msg='Selecciona el accesorio en todas las filas.'; return; }
    if (cant < 1 || cant > stock){ ok=false; msg='Cantidad inválida o mayor al stock.'; return; }

    if (isRegalo()){
      if (!eleg){ ok=false; msg='Incluiste un modelo no elegible para regalo.'; return; }
      if (price !== 0){ ok=false; msg='En regalo, todos los precios deben ser $0.'; return; }
    } else {
      if (price < 0){ ok=false; msg='Precio inválido.'; return; }
    }
  });

  if (!ok){ alert(msg || 'Revisa las líneas.'); return false; }

  if (!isRegalo()){
    const total = Number(lblTotal.textContent.replace(/[^0-9.]/g,'')) || 0;
    const ef = Number(inpEf.value||0), ta = Number(inpTa.value||0);
    if (formaPago.value === 'Mixto'){
      if ((ef+ta).toFixed(2) !== total.toFixed(2)){ alert('En pago Mixto, Efectivo + Tarjeta debe igualar el Total.'); return false; }
    } else if (formaPago.value === 'Efectivo' && ef.toFixed(2) !== total.toFixed(2)){
      alert('Efectivo debe igualar el Total.'); return false;
    } else if (formaPago.value === 'Tarjeta' && ta.toFixed(2) !== total.toFixed(2)){
      alert('Tarjeta debe igualar el Total.'); return false;
    }
  }
  return true;
}

/* ---------- Envío por fetch → ticket en modal ---------- */
const frm = document.getElementById('frmVenta');
frm.addEventListener('submit', async (ev)=>{
  ev.preventDefault();
  if (!validarLineas()) return;

  const btn = frm.querySelector('button[type="submit"]');
  btn.disabled = true; btn.innerText = 'Guardando…';

  try{
    const fd = new FormData(frm);
    const resp = await fetch(frm.action, { method:'POST', body: fd, redirect: 'follow' });
    const finalURL = resp.url || '';

    if (resp.ok && finalURL.includes('venta_accesorios_ticket.php')){
      const frame = document.getElementById('ticketFrame');
      frame.src = finalURL;
      const modal = new bootstrap.Modal(document.getElementById('ticketModal'));
      modal.show();
      frm.reset(); tbody.innerHTML = ''; addRow(); recalc();
      esRegaloInp.value = '0';
    } else {
      const txt = await resp.text();
      alert('No se pudo completar la venta:\n' + txt);
    }
  } catch(e){
    alert('Error de red: ' + (e?.message || e));
  } finally {
    btn.disabled = false; btn.innerText = 'Guardar venta';
  }
});

document.getElementById('btnPrintTicket').addEventListener('click', ()=>{
  const f = document.getElementById('ticketFrame');
  try { f.contentWindow.focus(); f.contentWindow.print(); } catch(e){}
});

/* Estado inicial */
addRow(); recalc();
</script>
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
</body>
</html>
