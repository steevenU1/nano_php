<?php
// compras_ingreso.php
// Ingreso de unidades a inventario por renglón (captura IMEI y PRECIO DE LISTA por modelo)
// Ajustado: RAM desde el detalle, SUBTIPO por renglón (no por IMEI) y mostrar último subtipo usado.

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

include 'db.php';

$detalleId = (int)($_GET['detalle'] ?? 0);
$compraId  = (int)($_GET['compra'] ?? 0);
if ($detalleId<=0 || $compraId<=0) die("Parámetros inválidos.");

/* ============================
   Helpers
============================ */
function esc($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

function parse_money($s) {
  $s = trim((string)$s);
  if ($s === '') return null;
  if (preg_match('/^\d{1,3}(\.\d{3})*,\d+$/', $s)) { // 1.234,56
    $s = str_replace('.', '', $s);
    $s = str_replace(',', '.', $s);
  } else { // 1,234.56
    $s = str_replace(',', '', $s);
  }
  return is_numeric($s) ? round((float)$s, 2) : null;
}

/** Sugerir precio de lista:
 *  1) último por código
 *  2) último por marca+modelo+ram+capacidad
 *  3) costo + IVA
 */
function sugerirPrecioLista(mysqli $conn, ?string $codigoProd, string $marca, string $modelo, string $ram, string $capacidad, float $costoConIva) {
  if ($codigoProd) {
    $q = $conn->prepare("SELECT precio_lista FROM productos
                         WHERE codigo_producto=? AND precio_lista IS NOT NULL AND precio_lista>0
                         ORDER BY id DESC LIMIT 1");
    $q->bind_param("s", $codigoProd);
    $q->execute(); $q->bind_result($pl);
    if ($q->fetch()) { $q->close(); return ['precio'=>(float)$pl, 'fuente'=>'último por código']; }
    $q->close();
  }
  $q2 = $conn->prepare("SELECT precio_lista FROM productos
                        WHERE marca=? AND modelo=? AND ram=? AND capacidad=? AND precio_lista IS NOT NULL AND precio_lista>0
                        ORDER BY id DESC LIMIT 1");
  $q2->bind_param("ssss", $marca, $modelo, $ram, $capacidad);
  $q2->execute(); $q2->bind_result($pl2);
  if ($q2->fetch()) { $q2->close(); return ['precio'=>(float)$pl2, 'fuente'=>'último por modelo (RAM/cap)']; }
  $q2->close();
  return ['precio'=>$costoConIva, 'fuente'=>'costo + IVA'];
}

/** Último subtipo usado:
 *  1) por código_producto
 *  2) por marca+modelo+ram+capacidad
 */
function ultimoSubtipo(mysqli $conn, ?string $codigoProd, string $marca, string $modelo, string $ram, string $capacidad) {
  if ($codigoProd) {
    $q = $conn->prepare("SELECT subtipo FROM productos
                         WHERE codigo_producto=? AND subtipo IS NOT NULL AND subtipo<>''
                         ORDER BY id DESC LIMIT 1");
    $q->bind_param("s", $codigoProd);
    $q->execute(); $q->bind_result($st);
    if ($q->fetch()) { $q->close(); return ['subtipo'=>$st, 'fuente'=>'por código']; }
    $q->close();
  }
  $q2 = $conn->prepare("SELECT subtipo FROM productos
                        WHERE marca=? AND modelo=? AND ram=? AND capacidad=? AND subtipo IS NOT NULL AND subtipo<>''
                        ORDER BY id DESC LIMIT 1");
  $q2->bind_param("ssss", $marca, $modelo, $ram, $capacidad);
  $q2->execute(); $q2->bind_result($st2);
  if ($q2->fetch()) { $q2->close(); return ['subtipo'=>$st2, 'fuente'=>'por modelo (RAM/cap)']; }
  $q2->close();
  return ['subtipo'=>null, 'fuente'=>null];
}

/* ============================
   Consultas base
============================ */
// Encabezado de compra (trae sucursal y proveedor)
$enc = $conn->query("
  SELECT c.*, s.nombre AS sucursal_nombre, p.nombre AS proveedor_nombre
  FROM compras c
  INNER JOIN sucursales s ON s.id=c.id_sucursal
  LEFT JOIN proveedores p ON p.id=c.id_proveedor
  WHERE c.id=$compraId
")->fetch_assoc();

$det = $conn->query("
  SELECT d.*
       , (SELECT COUNT(*) FROM compras_detalle_ingresos x WHERE x.id_detalle=d.id) AS ingresadas
  FROM compras_detalle d
  WHERE d.id=$detalleId AND d.id_compra=$compraId
")->fetch_assoc();

if (!$enc || !$det) die("Registro no encontrado.");

$pendientes     = max(0, (int)$det['cantidad'] - (int)$det['ingresadas']);
$requiereImei   = (int)$det['requiere_imei'] === 1;
$proveedorCompra= trim((string)($enc['proveedor_nombre'] ?? ''));
if ($proveedorCompra !== '') { $proveedorCompra = mb_substr($proveedorCompra, 0, 120, 'UTF-8'); }

/* ============================
   Precálculos por renglón
============================ */
// Traer código del catálogo (si existe)
$codigoCat = null;
if (!empty($det['id_modelo'])) {
  $stm = $conn->prepare("SELECT codigo_producto FROM catalogo_modelos WHERE id=?");
  $stm->bind_param("i", $det['id_modelo']);
  $stm->execute(); $stm->bind_result($codigoCat); $stm->fetch(); $stm->close();
}

// Costos del detalle
$costo       = (float)$det['precio_unitario'];      // sin IVA
$ivaPct      = (float)$det['iva_porcentaje'];       // %
$costoConIva = round($costo * (1 + $ivaPct/100), 2);

// Datos del detalle
$marcaDet  = (string)$det['marca'];
$modeloDet = (string)$det['modelo'];
$ramDet    = (string)($det['ram'] ?? '');         // 🆕 RAM del renglón
$capDet    = (string)$det['capacidad'];
$colorDet  = (string)$det['color'];

// Sugerencias
$sugerencia = sugerirPrecioLista($conn, $codigoCat, $marcaDet, $modeloDet, $ramDet, $capDet, $costoConIva);
$precioSugerido = $sugerencia['precio'];
$fuenteSugerido = $sugerencia['fuente'];

$ultimoST = ultimoSubtipo($conn, $codigoCat, $marcaDet, $modeloDet, $ramDet, $capDet);
$subtipoUltimo = $ultimoST['subtipo'];
$subtipoFuente = $ultimoST['fuente'];

// Datalist de subtipos existentes (globales)
$subtipos = [];
$resST = $conn->query("SELECT DISTINCT subtipo FROM productos WHERE subtipo IS NOT NULL AND subtipo<>'' ORDER BY subtipo LIMIT 50");
if ($resST) { while ($r=$resST->fetch_assoc()) { $subtipos[] = $r['subtipo']; } }

// Valores default de formulario
$errorMsg = "";
$precioListaForm = number_format($precioSugerido, 2, '.', '');
$subtipoForm = $subtipoUltimo ?? '';  // 🆕 prellenar con último usado

/* ============================
   POST: guardar ingresos
============================ */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $n = max(0, (int)($_POST['n'] ?? 0));
  if ($n <= 0) { header("Location: compras_ver.php?id=".$compraId); exit(); }
  if ($n > $pendientes) $n = $pendientes;

  // Precio de lista por renglón
  $precioListaForm = trim($_POST['precio_lista'] ?? '');
  $precioListaCapturado = parse_money($precioListaForm);
  if ($precioListaCapturado === null || $precioListaCapturado <= 0) {
    $errorMsg = "Precio de lista inválido. Usa números, ejemplo: 3999.00";
  }

  // Subtipo por renglón (opcional, pero lo normalizamos a máx 50 chars)
  $subtipoForm = mb_substr(trim((string)($_POST['subtipo'] ?? '')), 0, 50, 'UTF-8');

  if ($errorMsg === "") {
    $conn->begin_transaction();
    try {
      for ($i=0; $i<$n; $i++) {
        // --- IMEIs: limpiar y validar ---
        $imei1_raw = trim($_POST['imei1'][$i] ?? '');
        $imei2_raw = trim($_POST['imei2'][$i] ?? '');

        $imei1 = preg_replace('/\D+/', '', $imei1_raw);
        $imei2 = preg_replace('/\D+/', '', $imei2_raw);

        if ($requiereImei) {
          if ($imei1 === '' || !preg_match('/^\d{15}$/', $imei1)) {
            throw new Exception("IMEI1 inválido en la fila ".($i+1)." (deben ser 15 dígitos).");
          }
        } else {
          if ($imei1 !== '' && !preg_match('/^\d{15}$/', $imei1)) {
            throw new Exception("IMEI1 inválido en la fila ".($i+1)." (si lo capturas deben ser 15 dígitos).");
          }
          if ($imei1 === '') $imei1 = null;
        }

        if ($imei2 !== '' && !preg_match('/^\d{15}$/', $imei2)) {
          throw new Exception("IMEI2 inválido en la fila ".($i+1)." (si lo capturas deben ser 15 dígitos).");
        }
        if ($imei2 === '') $imei2 = null;

        // Duplicados: contra imei1 o imei2 existentes
        if ($imei1 !== null) {
          $st = $conn->prepare("SELECT COUNT(*) c FROM productos WHERE imei1=? OR imei2=?");
          $st->bind_param("ss", $imei1, $imei1);
          $st->execute(); $st->bind_result($cdup1); $st->fetch(); $st->close();
          if ($cdup1 > 0) throw new Exception("IMEI duplicado: $imei1");
        }
        if ($imei2 !== null) {
          $st = $conn->prepare("SELECT COUNT(*) c FROM productos WHERE imei1=? OR imei2=?");
          $st->bind_param("ss", $imei2, $imei2);
          $st->execute(); $st->bind_result($cdup2); $st->fetch(); $st->close();
          if ($cdup2 > 0) throw new Exception("IMEI duplicado: $imei2");
        }

        // Crear producto (una unidad) con RAM (del renglón) y SUBTIPO (de la línea)
        $stmtP = $conn->prepare("
          INSERT INTO productos (
            codigo_producto, marca, modelo, color, ram, capacidad,
            imei1, imei2, costo, costo_con_iva, proveedor, precio_lista, subtipo
          ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $marca = $marcaDet; $modelo = $modeloDet; $color = $colorDet; $ram = $ramDet; $cap = $capDet;
        $prov  = ($proveedorCompra !== '') ? $proveedorCompra : null;

        // tipos: 8*s + 2*d + 1*s + 1*d + 1*s  -> 'ssssssssddsds'
        $stmtP->bind_param(
          "ssssssssddsds",
          $codigoCat, $marca, $modelo, $color, $ram, $cap,
          $imei1, $imei2, $costo, $costoConIva, $prov, $precioListaCapturado, $subtipoForm
        );
        $stmtP->execute();
        $idProducto = $stmtP->insert_id;
        $stmtP->close();

        // Alta a inventario (sucursal de la compra)
        $stmtI = $conn->prepare("INSERT INTO inventario (id_producto, id_sucursal, estatus) VALUES (?, ?, 'Disponible')");
        $stmtI->bind_param("ii", $idProducto, $enc['id_sucursal']);
        $stmtI->execute(); $stmtI->close();

        // Registrar ingreso (vincular la unidad al detalle de compra)
        $stmtR = $conn->prepare("INSERT INTO compras_detalle_ingresos (id_detalle, imei1, imei2, id_producto) VALUES (?,?,?,?)");
        $stmtR->bind_param("issi", $detalleId, $imei1, $imei2, $idProducto);
        $stmtR->execute(); $stmtR->close();
      }

      $conn->commit();
      header("Location: compras_ver.php?id=".$compraId);
      exit();

    } catch (Exception $e) {
      $conn->rollback();
      $errorMsg = $e->getMessage();
    }
  }
}

// ===== A partir de aquí ya podemos imprimir HTML =====
include 'navbar.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<div class="container my-4">
  <h4>Ingreso a inventario</h4>
  <p class="text-muted">
    <strong>Factura:</strong> <?= esc($enc['num_factura']) ?> ·
    <strong>Sucursal destino:</strong> <?= esc($enc['sucursal_nombre']) ?><br>
    <strong>Modelo:</strong>
      <?= esc($marcaDet.' '.$modeloDet) ?> ·
      <?= $ramDet!=='' ? '<strong>RAM:</strong> '.esc($ramDet).' · ' : '' ?>
      <strong>Capacidad:</strong> <?= esc($capDet) ?> ·
      <strong>Color:</strong> <?= esc($colorDet) ?> ·
      <strong>Req. IMEI:</strong> <?= $requiereImei ? 'Sí' : 'No' ?><br>
    <strong>Proveedor (compra):</strong> <?= esc($proveedorCompra ?: '—') ?>
  </p>

  <?php if (!empty($errorMsg)): ?>
    <div class="alert alert-danger"><?= esc($errorMsg) ?></div>
  <?php endif; ?>

  <div class="card">
    <div class="card-body">
      <p><strong>Cantidad total:</strong> <?= (int)$det['cantidad'] ?> ·
         <strong>Ingresadas:</strong> <?= (int)$det['ingresadas'] ?> ·
         <strong>Pendientes:</strong> <?= $pendientes ?></p>

      <?php if ($pendientes <= 0): ?>
        <div class="alert alert-success">Este renglón ya está completamente ingresado.</div>
      <?php else: ?>
        <form method="POST">
          <input type="hidden" name="n" value="<?= $pendientes ?>">

          <!-- Subtipo por renglón -->
          <div class="row g-3 mb-3">
            <div class="col-md-4">
              <label class="form-label">Subtipo (por renglón)</label>
              <input
                type="text"
                name="subtipo"
                class="form-control"
                maxlength="50"
                list="dlSubtipos"
                placeholder="Ej. Telcel, Liberado, Kit, etc."
                value="<?= esc($subtipoForm) ?>"
              >
              <datalist id="dlSubtipos">
                <?php foreach ($subtipos as $st): ?>
                  <option value="<?= esc($st) ?>"></option>
                <?php endforeach; ?>
              </datalist>
              <small class="text-muted">
                <?= $subtipoUltimo ? 'Último subtipo: <strong>'.esc($subtipoUltimo).'</strong>'.($subtipoFuente?' ('.$subtipoFuente.')':'') : 'Sin historial de subtipo.' ?>
              </small>
            </div>

            <!-- Precio de lista por modelo -->
            <div class="col-md-4">
              <label class="form-label">Precio de lista (por modelo)</label>
              <input
                type="text"
                name="precio_lista"
                class="form-control"
                inputmode="decimal"
                placeholder="Ej. 3999.00"
                value="<?= esc($precioListaForm) ?>"
                required
              >
              <small class="text-muted">
                Sugerido: $<?= number_format((float)$precioSugerido, 2) ?> (<?= esc($fuenteSugerido) ?>).
                Se aplicará a todas las unidades de este renglón.
              </small>
            </div>
          </div>

          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead>
                <tr>
                  <th>#</th>
                  <th>IMEI1 <?= $requiereImei ? '*' : '' ?></th>
                  <th>IMEI2 (opcional)</th>
                </tr>
              </thead>
              <tbody>
                <?php for ($i=0;$i<$pendientes;$i++): ?>
                  <tr>
                    <td><?= $i+1 ?></td>
                    <td>
                      <input
                        class="form-control"
                        name="imei1[]"
                        <?= $requiereImei ? 'required' : '' ?>
                        inputmode="numeric"
                        minlength="15"
                        maxlength="15"
                        pattern="[0-9]{15}"
                        placeholder="15 dígitos"
                        title="Debe contener exactamente 15 dígitos"
                      >
                    </td>
                    <td>
                      <input
                        class="form-control"
                        name="imei2[]"
                        inputmode="numeric"
                        minlength="15"
                        maxlength="15"
                        pattern="[0-9]{15}"
                        placeholder="15 dígitos (opcional)"
                        title="Si lo capturas, deben ser 15 dígitos"
                      >
                    </td>
                  </tr>
                <?php endfor; ?>
              </tbody>
            </table>
          </div>

          <div class="text-end">
            <button class="btn btn-success">Ingresar a inventario</button>
            <a href="compras_ver.php?id=<?= (int)$compraId ?>" class="btn btn-outline-secondary">Cancelar</a>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
