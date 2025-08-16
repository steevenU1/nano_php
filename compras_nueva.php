<?php
// compras_nueva.php
// Captura de factura de compra por renglones de MODELO (catálogo formal)

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

include 'db.php';

$ROL         = $_SESSION['rol'] ?? 'Ejecutivo';
$ID_USUARIO  = (int)($_SESSION['id_usuario'] ?? 0);
$ID_SUCURSAL = (int)($_SESSION['id_sucursal'] ?? 0);

/* =========================
   Permisos (ANTES de imprimir HTML)
========================= */
// Permitidos: Admin y Logistica
if (!in_array($ROL, ['Admin','Logistica'])) {
  header("Location: 403.php");
  exit();
}

/* =========================
   Datos para el formulario
========================= */

// Proveedores
$proveedores = [];
$res = $conn->query("SELECT id, nombre FROM proveedores WHERE activo=1 ORDER BY nombre");
while ($row = $res->fetch_assoc()) { $proveedores[] = $row; }

// Sucursales
$sucursales = [];
$res2 = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");
while ($row = $res2->fetch_assoc()) { $sucursales[] = $row; }

// Catálogo de modelos (activos) con atributos
$modelos = [];
$res3 = $conn->query("
  SELECT id, marca, modelo, codigo_producto, color, ram, capacidad
  FROM catalogo_modelos
  WHERE activo=1
  ORDER BY marca, modelo, color, ram, capacidad
");
while ($row = $res3->fetch_assoc()) { $modelos[] = $row; }

// Navbar (ya podemos imprimir HTML)
include 'navbar.php';
?>
<!-- Forzamos el título de la pestaña desde la vista -->
<script>
  (function () {
    var t = 'Compras · Nueva factura — Central2.0';
    try { document.title = t; } catch (e) {}
  })();
</script>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script> -->

<style>
  #tablaDetalle th, #tablaDetalle td { white-space: nowrap; }
  #tablaDetalle .col-codigo { min-width: 320px; }
  #tablaDetalle .col-color  { width: 120px; }
  #tablaDetalle .col-ram    { width: 110px; }
  #tablaDetalle .col-cap    { width: 130px; }
  #tablaDetalle .col-qty    { width: 90px; }
  #tablaDetalle .col-pu     { width: 120px; }
  #tablaDetalle .col-ivp    { width: 90px; }
  #tablaDetalle .col-sub    { width: 120px; }
  #tablaDetalle .col-iva    { width: 110px; }
  #tablaDetalle .col-tot    { width: 130px; }
  #tablaDetalle .col-req    { width: 120px; }
  #tablaDetalle .col-acc    { width: 60px; }

  #tablaDetalle .form-control { padding: .3rem .5rem; }
  #tablaDetalle input.num { text-align: right; }

  #tablaDetalle input[readonly] {
    background-color: #f8f9fa;
    cursor: not-allowed;
  }
</style>

<div class="container my-4">
  <h3 class="mb-3">Nueva factura de compra</h3>

  <form action="compras_guardar.php" method="POST" id="formCompra">
    <div class="card shadow-sm mb-3">
      <div class="card-body row g-3">
        <div class="col-md-4">
          <label class="form-label">Proveedor *</label>
          <select name="id_proveedor" class="form-select" required>
            <option value="">-- Selecciona --</option>
            <?php foreach ($proveedores as $p): ?>
              <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-3">
          <label class="form-label"># Factura *</label>
          <input type="text" name="num_factura" class="form-control" required>
        </div>

        <div class="col-md-3">
          <label class="form-label">Sucursal destino *</label>
          <select name="id_sucursal" class="form-select" required>
            <?php foreach ($sucursales as $s): ?>
              <option value="<?= $s['id'] ?>" <?= $s['id']==$ID_SUCURSAL ? 'selected' : '' ?>>
                <?= htmlspecialchars($s['nombre']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-2">
          <label class="form-label">IVA % (default)</label>
          <input type="number" step="0.01" value="16" id="ivaDefault" class="form-control">
        </div>

        <div class="col-md-3">
          <label class="form-label">Fecha factura *</label>
          <input type="date" name="fecha_factura" class="form-control" required value="<?= date('Y-m-d') ?>">
        </div>

        <div class="col-md-3">
          <label class="form-label">Fecha vencimiento</label>
          <input type="date" name="fecha_vencimiento" class="form-control">
        </div>

        <div class="col-md-2">
          <label class="form-label">Condición de pago *</label>
          <select name="condicion_pago" id="condicionPago" class="form-select" required>
            <option value="Contado">Contado</option>
            <option value="Crédito">Crédito</option>
          </select>
        </div>

        <div class="col-md-2">
          <label class="form-label">Días de vencimiento</label>
          <input type="number" min="0" step="1" id="diasVenc" name="dias_vencimiento" class="form-control" placeholder="ej. 30" list="plazosSugeridos">
          <datalist id="plazosSugeridos">
            <option value="7"></option><option value="14"></option><option value="15"></option><option value="21"></option>
            <option value="30"></option><option value="45"></option><option value="60"></option><option value="90"></option>
          </datalist>
          <div class="form-text">Crédito: escribe días y calculo la fecha.</div>
        </div>

        <div class="col-md-4">
          <label class="form-label">Notas</label>
          <input type="text" name="notas" class="form-control" maxlength="250" placeholder="Opcional">
        </div>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="mb-0">Detalle por modelo</h5>
          <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="modelos.php" target="_blank">➕ Nuevo modelo</a>
            <button type="button" class="btn btn-sm btn-primary" id="btnAgregar">+ Agregar renglón</button>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-striped align-middle" id="tablaDetalle">
            <thead>
              <tr>
                <th class="col-codigo">Código · Marca/Modelo (buscador)</th>
                <th class="col-color">Color</th>
                <th class="col-ram">RAM</th>
                <th class="col-cap">Capacidad</th>
                <th class="col-qty">Cantidad</th>
                <th class="col-pu">P. Unitario</th>
                <th class="col-ivp">IVA %</th>
                <th class="col-sub">Subtotal</th>
                <th class="col-iva">IVA</th>
                <th class="col-tot">Total</th>
                <th class="col-req">Requiere IMEI</th>
                <th class="col-acc"></th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>

        <div class="row justify-content-end mt-3">
          <div class="col-md-4">
            <div class="card border-0 bg-light">
              <div class="card-body">
                <div class="d-flex justify-content-between mb-1">
                  <strong>Subtotal</strong><strong id="lblSubtotal">$0.00</strong>
                </div>
                <div class="d-flex justify-content-between mb-1">
                  <strong>IVA</strong><strong id="lblIVA">$0.00</strong>
                </div>
                <hr>
                <div class="d-flex justify-content-between fs-5">
                  <strong>Total</strong><strong id="lblTotal">$0.00</strong>
                </div>
              </div>
            </div>
          </div>
        </div>

        <input type="hidden" name="subtotal" id="inpSubtotal">
        <input type="hidden" name="iva" id="inpIVA">
        <input type="hidden" name="total" id="inpTotal">

        <!-- 🆕 Hidden para pago contado -->
        <input type="hidden" name="registrar_pago" id="registrarPago" value="0">
        <input type="hidden" name="pago_monto" id="pagoMonto">
        <input type="hidden" name="pago_metodo" id="pagoMetodo">
        <input type="hidden" name="pago_referencia" id="pagoReferencia">
        <input type="hidden" name="pago_fecha" id="pagoFecha">
        <input type="hidden" name="pago_nota" id="pagoNota">
      </div>
    </div>

    <div class="text-end mt-3">
      <button type="submit" class="btn btn-success">Guardar factura</button>
    </div>
  </form>
</div>

<!-- 🔎 Datalist global: value=código, label=descripción -->
<datalist id="dlModelos">
  <?php foreach ($modelos as $m):
    $desc = trim($m['marca'].' '.$m['modelo'].' · '.($m['color']??'').' · '.($m['ram']??'').' · '.($m['capacidad']??'')); 
    $val  = $m['codigo_producto'] ?: ($m['marca'].' '.$m['modelo']);
  ?>
    <option value="<?= htmlspecialchars($val) ?>" label="<?= htmlspecialchars($desc) ?>"></option>
  <?php endforeach; ?>
</datalist>

<!-- 🆕 Modal de pago para contado -->
<div class="modal fade" id="modalPago" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Registrar pago (Contado)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info py-2">
          Importe de la factura: <strong id="mpTotal">$0.00</strong>
        </div>
        <div class="row g-3">
          <div class="col-6">
            <label class="form-label">Fecha de pago</label>
            <input type="date" class="form-control" id="mpFecha" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-6">
            <label class="form-label">Método</label>
            <select id="mpMetodo" class="form-select">
              <option value="Efectivo">Efectivo</option>
              <option value="Transferencia">Transferencia</option>
              <option value="Tarjeta">Tarjeta</option>
              <option value="Depósito">Depósito</option>
              <option value="Otro">Otro</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">Referencia</label>
            <input type="text" id="mpRef" class="form-control" maxlength="80" placeholder="Folio, banco, últimos 4, etc. (opcional)">
          </div>
          <div class="col-6">
            <label class="form-label">Importe pagado</label>
            <input type="number" id="mpMonto" class="form-control" step="0.01" min="0">
          </div>
          <div class="col-6">
            <label class="form-label">Notas</label>
            <input type="text" id="mpNota" class="form-control" maxlength="120" placeholder="Opcional">
          </div>
        </div>
        <small class="text-muted d-block mt-2">El pago se guardará en <strong>compras_pagos</strong> junto con la factura.</small>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-primary" id="btnConfirmarPago">Guardar factura + pago</button>
      </div>
    </div>
  </div>
</div>

<script>
const modelos = <?= json_encode($modelos, JSON_UNESCAPED_UNICODE) ?>;
const tbody = document.querySelector('#tablaDetalle tbody');
const ivaDefault = document.getElementById('ivaDefault');

// vencimiento / condición
const fechaFacturaEl = document.querySelector('input[name="fecha_factura"]');
const fechaVencEl    = document.querySelector('input[name="fecha_vencimiento"]');
const diasVencEl     = document.getElementById('diasVenc');
const condicionPagoEl= document.getElementById('condicionPago');

// pago contado (modal)
const modalPago   = new bootstrap.Modal(document.getElementById('modalPago'));
const mpTotalEl   = document.getElementById('mpTotal');
const mpFechaEl   = document.getElementById('mpFecha');
const mpMetodoEl  = document.getElementById('mpMetodo');
const mpRefEl     = document.getElementById('mpRef');
const mpMontoEl   = document.getElementById('mpMonto');
const mpNotaEl    = document.getElementById('mpNota');

const regPagoEl   = document.getElementById('registrarPago');
const pagoMontoEl = document.getElementById('pagoMonto');
const pagoMetodoEl= document.getElementById('pagoMetodo');
const pagoRefEl   = document.getElementById('pagoReferencia');
const pagoFechaEl = document.getElementById('pagoFecha');
const pagoNotaEl  = document.getElementById('pagoNota');

let rowIdx = 0;
let forceSubmit = false;

function formato(n){ return new Intl.NumberFormat('es-MX',{minimumFractionDigits:2, maximumFractionDigits:2}).format(n||0); }

function calcTotales(){
  let sub = 0, iva = 0, tot = 0;
  document.querySelectorAll('tr.renglon').forEach(tr => {
    const qty = parseFloat(tr.querySelector('.qty').value) || 0;
    const pu  = parseFloat(tr.querySelector('.pu').value)  || 0;
    const ivp = parseFloat(tr.querySelector('.ivp').value) || 0;
    const rsub = qty * pu;
    const riva = rsub * (ivp/100.0);
    const rtot = rsub + riva;
    tr.querySelector('.rsub').textContent = '$'+formato(rsub);
    tr.querySelector('.riva').textContent = '$'+formato(riva);
    tr.querySelector('.rtot').textContent = '$'+formato(rtot);
    sub += rsub; iva += riva; tot += rtot;
  });
  document.getElementById('lblSubtotal').textContent = '$'+formato(sub);
  document.getElementById('lblIVA').textContent      = '$'+formato(iva);
  document.getElementById('lblTotal').textContent    = '$'+formato(tot);
  document.getElementById('inpSubtotal').value = sub.toFixed(2);
  document.getElementById('inpIVA').value      = iva.toFixed(2);
  document.getElementById('inpTotal').value    = tot.toFixed(2);
}

// Mapas para localizar por código o por etiqueta
function etiqueta(m){
  return (m.marca + ' ' + m.modelo + (m.codigo_producto ? (' · ' + m.codigo_producto) : '')).trim();
}
const byCodigo = {};
const byEtiqueta = {};
modelos.forEach(m => {
  if (m.codigo_producto) byCodigo[m.codigo_producto] = m;
  byEtiqueta[etiqueta(m).toLowerCase()] = m;
});

function aplicarModeloEnRenglon(m, tr){
  tr.querySelector('.mm-id').value     = m.id;
  tr.querySelector('.color').value     = m.color || '';
  tr.querySelector('.ram').value       = m.ram || '';
  tr.querySelector('.capacidad').value = m.capacidad || '';
  const mm = tr.querySelector('.mm-buscar');
  mm.classList.remove('is-invalid');
  mm.setCustomValidity('');
}

function agregarRenglon(){
  const idx = rowIdx++;
  const tr = document.createElement('tr');
  tr.className = 'renglon';
  tr.innerHTML = `
    <td class="col-codigo">
      <div class="position-relative">
        <input type="text" class="form-control mm-buscar" list="dlModelos"
               placeholder="Escribe código o marca/modelo" autocomplete="off" required>
        <input type="hidden" name="id_modelo[${idx}]" class="mm-id">
        <div class="invalid-feedback">Elige un código válido del catálogo.</div>
      </div>
    </td>
    <td class="col-color"><input type="text" class="form-control color" name="color[${idx}]" readonly required></td>
    <td class="col-ram"><input type="text" class="form-control ram" name="ram[${idx}]" readonly></td>
    <td class="col-cap"><input type="text" class="form-control capacidad" name="capacidad[${idx}]" readonly required></td>
    <td class="col-qty"><input type="number" min="1" value="1" class="form-control num qty" name="cantidad[${idx}]" required></td>
    <td class="col-pu"><input type="number" step="0.01" min="0" value="0" class="form-control num pu" name="precio_unitario[${idx}]" required></td>
    <td class="col-ivp"><input type="number" step="0.01" min="0" class="form-control num ivp" name="iva_porcentaje[${idx}]" value="${ivaDefault.value || 16}"></td>
    <td class="col-sub rsub">$0.00</td>
    <td class="col-iva riva">$0.00</td>
    <td class="col-tot rtot">$0.00</td>
    <td class="col-req text-center">
      <input type="hidden" name="requiere_imei[${idx}]" value="0">
      <input type="checkbox" class="form-check-input reqi" name="requiere_imei[${idx}]" value="1" checked>
    </td>
    <td class="col-acc"><button type="button" class="btn btn-sm btn-outline-danger btnQuitar">&times;</button></td>
  `;
  tbody.appendChild(tr);

  tr.querySelectorAll('input,select').forEach(el => el.addEventListener('input', calcTotales));
  tr.querySelector('.btnQuitar').addEventListener('click', () => { tr.remove(); calcTotales(); });

  const input  = tr.querySelector('.mm-buscar');
  const hidden = tr.querySelector('.mm-id');

  function intentarAplicar(){
    const raw = (input.value || '').trim();
    let m = byCodigo[raw];
    if (!m) m = byEtiqueta[raw.toLowerCase()];
    if (m) {
      aplicarModeloEnRenglon(m, tr);
    } else {
      hidden.value = '';
      input.classList.add('is-invalid');
      input.setCustomValidity('Selecciona un código válido.');
    }
  }

  input.addEventListener('change', intentarAplicar);
  input.addEventListener('input', () => {
    input.classList.remove('is-invalid');
    input.setCustomValidity('');
    hidden.value = '';
  });

  calcTotales();
}

document.getElementById('btnAgregar').addEventListener('click', agregarRenglon);
ivaDefault.addEventListener('input', () => {
  document.querySelectorAll('.ivp').forEach(i => i.value = ivaDefault.value || 16);
  calcTotales();
});

// arranca con 1 renglón
agregarRenglon();

// Validación antes de enviar + modal contado
document.getElementById('formCompra').addEventListener('submit', function(e){
  if (forceSubmit) return; // ya validado y con modal confirmado

  if (!tbody.querySelector('tr')) {
    e.preventDefault();
    alert('Agrega al menos un renglón');
    return;
  }
  let ok = true;
  document.querySelectorAll('#tablaDetalle tbody tr').forEach(tr => {
    const hidden = tr.querySelector('.mm-id');
    if (!hidden.value) ok = false;
  });
  if (!ok) {
    e.preventDefault();
    alert('Verifica que todos los renglones tengan un código válido seleccionado.');
    return;
  }

  // Si es contado, abre modal y no envía todavía
  if (condicionPagoEl.value === 'Contado') {
    e.preventDefault();
    calcTotales(); // asegurar totales actualizados
    const total = parseFloat(document.getElementById('inpTotal').value || '0') || 0;
    mpTotalEl.textContent = '$' + formato(total);
    mpMontoEl.value = total.toFixed(2);
    mpFechaEl.value = fechaFacturaEl.value || new Date().toISOString().slice(0,10);
    modalPago.show();
  }
});

// Confirmar en modal -> llenar hiddens y enviar
document.getElementById('btnConfirmarPago').addEventListener('click', () => {
  const monto = parseFloat(mpMontoEl.value || '0');
  if (isNaN(monto) || monto < 0) {
    alert('Importe de pago inválido'); return;
  }
  regPagoEl.value    = '1';
  pagoMontoEl.value  = monto.toFixed(2);
  pagoMetodoEl.value = mpMetodoEl.value || 'Efectivo';
  pagoRefEl.value    = mpRefEl.value.trim();
  pagoFechaEl.value  = mpFechaEl.value || new Date().toISOString().slice(0,10);
  pagoNotaEl.value   = mpNotaEl.value.trim();

  forceSubmit = true;
  modalPago.hide();
  document.getElementById('formCompra').submit();
});

/* =========================
   🧮 Lógica de vencimiento
========================= */
function ymd(dateObj){
  const tzOffset = dateObj.getTimezoneOffset();
  const local = new Date(dateObj.getTime() - tzOffset*60000);
  return local.toISOString().slice(0,10);
}
function sumarDias(baseStr, dias){
  const d = new Date(baseStr + 'T00:00:00');
  if (isNaN(d.getTime())) return '';
  const n = parseInt(dias, 10);
  if (isNaN(n)) return '';
  d.setDate(d.getDate() + n);
  return ymd(d);
}
function setContadoUI(){
  if (fechaFacturaEl.value) fechaVencEl.value = fechaFacturaEl.value;
  diasVencEl.value = 0;
  diasVencEl.readOnly = true; fechaVencEl.readOnly = true;
  diasVencEl.classList.add('bg-light'); fechaVencEl.classList.add('bg-light');
}
function setCreditoUI(){
  diasVencEl.readOnly = false; fechaVencEl.readOnly = false;
  diasVencEl.classList.remove('bg-light'); fechaVencEl.classList.remove('bg-light');
  recalcularFechaVenc();
}
function recalcularFechaVenc(){
  if (condicionPagoEl.value !== 'Crédito') return;
  const f = fechaFacturaEl.value; const dias = diasVencEl.value;
  if (f && dias !== '') {
    const fv = sumarDias(f, dias);
    if (fv) fechaVencEl.value = fv;
  }
}
function recalcularDias(){
  if (condicionPagoEl.value !== 'Crédito') return;
  const f = fechaFacturaEl.value; const fv = fechaVencEl.value;
  if (f && fv) {
    const df = new Date(f + 'T00:00:00');
    const dv = new Date(fv + 'T00:00:00');
    if (!isNaN(df.getTime()) && !isNaN(dv.getTime())) {
      const diffMs = dv - df;
      const diffDays = Math.round(diffMs / (1000*60*60*24));
      if (diffDays >= 0) diasVencEl.value = diffDays;
    }
  }
}
fechaFacturaEl.addEventListener('change', () => {
  if (condicionPagoEl.value === 'Contado') setContadoUI();
  else recalcularFechaVenc();
});
diasVencEl.addEventListener('input', recalcularFechaVenc);
fechaVencEl.addEventListener('change', recalcularDias);
condicionPagoEl.addEventListener('change', () => {
  if (condicionPagoEl.value === 'Contado') setContadoUI();
  else setCreditoUI();
});
setContadoUI();
</script>
