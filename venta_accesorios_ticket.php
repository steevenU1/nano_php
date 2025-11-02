<?php
// venta_accesorios_ticket.php — Ticket/recibo de venta de accesorios
// Uso: venta_accesorios_ticket.php?id=123

session_start();
if (!isset($_SESSION['id_usuario'])) { header('Location: index.php'); exit(); }

require_once __DIR__.'/db.php';
date_default_timezone_set('America/Mexico_City');

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function money($n){ return '$'.number_format((float)$n, 2, '.', ','); }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo "ID inválido."; exit; }

// ---- Venta (encabezado) ----
$sqlVenta = "
  SELECT 
    v.*,
    u.nombre   AS usuario_nombre,
    s.nombre   AS sucursal_nombre
  FROM ventas_accesorios v
  LEFT JOIN usuarios   u ON u.id = v.id_usuario
  LEFT JOIN sucursales s ON s.id = v.id_sucursal
  WHERE v.id = ?
  LIMIT 1
";
$st = $conn->prepare($sqlVenta);
if (!$st) { echo "Error preparando consulta."; exit; }
$st->bind_param('i', $id);
$st->execute();
$venta = $st->get_result()->fetch_assoc();
if (!$venta) { echo "Venta no encontrada."; exit; }

// ---- Detalle ----
$sqlDet = "
  SELECT d.*, 
         COALESCE(d.descripcion_snapshot,
                  TRIM(CONCAT(p.marca,' ',p.modelo,' ',COALESCE(p.color,'')))
         ) AS descripcion
  FROM detalle_venta_accesorio d
  LEFT JOIN productos p ON p.id = d.id_producto
  WHERE d.id_venta = ?
  ORDER BY d.id ASC
";
$sd = $conn->prepare($sqlDet);
$sd->bind_param('i', $id);
$sd->execute();
$detalles = $sd->get_result()->fetch_all(MYSQLI_ASSOC);

// Datos “bonitos”
$folio       = $venta['id'];
$tag         = $venta['tag'] ?? '';
$cliente     = $venta['nombre_cliente'] ?? '';
$telefono    = $venta['telefono'] ?? '';
$forma_pago  = $venta['forma_pago'] ?? '';
$efectivo    = (float)($venta['efectivo'] ?? 0);
$tarjeta     = (float)($venta['tarjeta'] ?? 0);
$total       = (float)($venta['total'] ?? 0);
$comentarios = $venta['comentarios'] ?? '';
$fecha       = $venta['created_at'] ?? $venta['fecha'] ?? date('Y-m-d H:i:s'); // por si no tienes created_at
$usuarioNom  = $venta['usuario_nombre'] ?? ('Usuario #'.(int)($venta['id_usuario'] ?? 0));
$sucursalNom = $venta['sucursal_nombre'] ?? ('Sucursal #'.(int)($venta['id_sucursal'] ?? 0));
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Ticket venta accesorios #<?= (int)$folio ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    @media print {
      .no-print { display: none !important; }
      body { background: #fff; }
      .card { box-shadow: none !important; border: 0 !important; }
    }
    body { background: #f5f6f8; }
    .table-sm td, .table-sm th { padding-top: .35rem; padding-bottom: .35rem; }
    .lh-tight { line-height: 1.1; }
  </style>
</head>
<body>
<div class="container my-4">
  <div class="card shadow-sm">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <h4 class="mb-0">Ticket de Venta — Accesorios</h4>
          <small class="text-muted">Folio #<?= (int)$folio ?> · TAG: <?= h($tag) ?></small>
        </div>
        <div class="text-end">
          <button class="btn btn-outline-secondary btn-sm no-print" onclick="window.print()">Imprimir</button>
          <a href="venta_accesorios.php" class="btn btn-success btn-sm no-print">Nueva venta</a>
        </div>
      </div>

      <hr>

      <div class="row g-3 mb-2">
        <div class="col-md-4">
          <div class="small text-muted">Cliente</div>
          <div class="fw-semibold"><?= h($cliente) ?></div>
          <div class="text-muted"><?= h($telefono) ?></div>
        </div>
        <div class="col-md-4">
          <div class="small text-muted">Sucursal</div>
          <div class="fw-semibold"><?= h($sucursalNom) ?></div>
          <div class="text-muted">Atendido por: <?= h($usuarioNom) ?></div>
        </div>
        <div class="col-md-4">
          <div class="small text-muted">Fecha</div>
          <div class="fw-semibold"><?= h(date('d/m/Y H:i', strtotime($fecha))) ?></div>
          <div class="text-muted">Forma de pago: <?= h($forma_pago) ?></div>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-sm">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>Descripción</th>
              <th class="text-center">Cant.</th>
              <th class="text-end">Precio</th>
              <th class="text-end">Subtotal</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $i = 1;
            foreach ($detalles as $d):
              $desc = $d['descripcion'] ?? ('Producto #'.(int)$d['id_producto']);
              $cant = (int)$d['cantidad'];
              $precio = (float)$d['precio_unitario'];
              $sub = (float)$d['subtotal'];
            ?>
            <tr>
              <td><?= $i++ ?></td>
              <td><?= h($desc) ?></td>
              <td class="text-center"><?= $cant ?></td>
              <td class="text-end"><?= money($precio) ?></td>
              <td class="text-end"><?= money($sub) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <th colspan="4" class="text-end">Total</th>
              <th class="text-end"><?= money($total) ?></th>
            </tr>
          </tfoot>
        </table>
      </div>

      <div class="row g-3">
        <div class="col-md-6">
          <div class="small text-muted mb-1">Comentarios</div>
          <div class="border rounded p-2" style="min-height:48px"><?= nl2br(h($comentarios)) ?></div>
        </div>
        <div class="col-md-6">
          <div class="small text-muted mb-1">Pago</div>
          <table class="table table-sm mb-0">
            <tr><td>Efectivo</td><td class="text-end"><?= money($efectivo) ?></td></tr>
            <tr><td>Tarjeta</td><td class="text-end"><?= money($tarjeta) ?></td></tr>
            <tr class="table-light"><th>Total</th><th class="text-end"><?= money($total) ?></th></tr>
          </table>
        </div>
      </div>

      <hr>
      <div class="text-center">
        <small class="text-muted lh-tight d-block">
          Gracias por su compra. Conserve este comprobante.
        </small>
      </div>
    </div>
  </div>

  <div class="text-center mt-3 no-print">
    <a href="venta_accesorios.php" class="btn btn-outline-primary btn-sm">Registrar otra venta</a>
    <a href="dashboard_unificado.php" class="btn btn-outline-secondary btn-sm">Ir al dashboard</a>
  </div>
</div>
</body>
</html>
