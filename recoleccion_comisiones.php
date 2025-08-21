<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'GerenteZona') {
    header("Location: 403.php");
    exit();
}

include 'db.php';
include 'navbar.php';

$idGerente = $_SESSION['id_usuario'];

// ===== Helpers de salida =====
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function mx($n){ return '$'.number_format((float)$n, 2); }

// 🔹 Obtener zona del gerente
$sqlZona = "
    SELECT DISTINCT s.zona
    FROM sucursales s
    INNER JOIN usuarios u ON u.id_sucursal = s.id
    WHERE u.id = ?
";
$stmtZona = $conn->prepare($sqlZona);
$stmtZona->bind_param("i", $idGerente);
$stmtZona->execute();
$zona = $stmtZona->get_result()->fetch_assoc()['zona'] ?? '';
$stmtZona->close();

// 🔹 Función para obtener saldos por sucursal
function obtenerSaldos($conn, $zona){
    $sql = "
        SELECT 
            s.id AS id_sucursal,
            s.nombre AS sucursal,
            IFNULL(c.total_comisiones,0) AS total_comisiones,
            IFNULL(e.total_entregado,0) AS total_entregado,
            (IFNULL(c.total_comisiones,0) - IFNULL(e.total_entregado,0)) AS saldo_pendiente
        FROM sucursales s
        LEFT JOIN (
            SELECT id_sucursal, SUM(comision_especial) AS total_comisiones
            FROM cobros
            WHERE comision_especial > 0
            GROUP BY id_sucursal
        ) c ON c.id_sucursal = s.id
        LEFT JOIN (
            SELECT id_sucursal, SUM(monto_entregado) AS total_entregado
            FROM entregas_comisiones_especiales
            GROUP BY id_sucursal
        ) e ON e.id_sucursal = s.id
        WHERE s.zona = ?
        ORDER BY s.nombre
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s",$zona);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// 🔹 Función para historial de entregas
function obtenerHistorial($conn, $zona){
    $sql = "
        SELECT e.id, s.nombre AS sucursal, e.monto_entregado, e.fecha_entrega, e.observaciones
        FROM entregas_comisiones_especiales e
        INNER JOIN sucursales s ON s.id=e.id_sucursal
        WHERE s.zona = ?
        ORDER BY e.fecha_entrega DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s",$zona);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// 🔹 Procesar recolección (misma lógica)
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idSucursal = intval($_POST['id_sucursal'] ?? 0);
    $monto = floatval($_POST['monto_entregado'] ?? 0);
    $observaciones = trim($_POST['observaciones'] ?? '');

    if ($idSucursal > 0 && $monto > 0) {
        // Verificar saldo pendiente actual
        $sqlSaldo = "
            SELECT 
                IFNULL(c.total_comisiones,0) - IFNULL(e.total_entregado,0) AS saldo_pendiente
            FROM (
                SELECT id_sucursal, SUM(comision_especial) AS total_comisiones
                FROM cobros
                WHERE comision_especial > 0
                GROUP BY id_sucursal
            ) c
            LEFT JOIN (
                SELECT id_sucursal, SUM(monto_entregado) AS total_entregado
                FROM entregas_comisiones_especiales
                GROUP BY id_sucursal
            ) e ON e.id_sucursal = c.id_sucursal
            WHERE c.id_sucursal = ?
            LIMIT 1
        ";
        $stmt = $conn->prepare($sqlSaldo);
        $stmt->bind_param("i",$idSucursal);
        $stmt->execute();
        $saldo = $stmt->get_result()->fetch_assoc()['saldo_pendiente'] ?? 0;

        if ($monto > $saldo) {
            $msg = "<div class='alert alert-danger shadow-sm'>❌ El monto excede el saldo pendiente: ".mx($saldo)."</div>";
        } else {
            $sqlInsert = "
                INSERT INTO entregas_comisiones_especiales (id_sucursal,id_gerentezona,monto_entregado,fecha_entrega,observaciones)
                VALUES (?,?,?,NOW(),?)
            ";
            $stmtIns = $conn->prepare($sqlInsert);
            $stmtIns->bind_param("iids",$idSucursal,$idGerente,$monto,$observaciones);
            if ($stmtIns->execute()) {
                $msg = "<div class='alert alert-success shadow-sm'>✅ Entrega registrada correctamente.</div>";
            } else {
                $msg = "<div class='alert alert-danger shadow-sm'>❌ Error al registrar la entrega.</div>";
            }
        }
    } else {
        $msg = "<div class='alert alert-warning shadow-sm'>⚠ Debes seleccionar sucursal y un monto válido.</div>";
    }

    // Refrescar datos
    $saldos = obtenerSaldos($conn,$zona);
    $historial = obtenerHistorial($conn,$zona);
} else {
    $saldos = obtenerSaldos($conn,$zona);
    $historial = obtenerHistorial($conn,$zona);
}

// Totales para tarjetas
$totalComisiones = 0; $totalEntregado = 0; $totalSaldo = 0;
foreach ($saldos as $s) {
    $totalComisiones += (float)$s['total_comisiones'];
    $totalEntregado  += (float)$s['total_entregado'];
    $totalSaldo      += (float)$s['saldo_pendiente'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Recolección de Comisiones Abonos</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap / Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body { background: #f6f8fb; }
        .page-title {
            display:flex; align-items:center; gap:.6rem;
        }
        .card-kpi{
            border: 0; border-radius: 1rem;
            box-shadow: 0 8px 24px rgba(29, 78, 216, .06);
            overflow: hidden;
        }
        .card-kpi .card-body{ padding: 1rem 1.25rem; }
        .kpi-label{ font-size: .85rem; color: #64748b; }
        .kpi-value{ font-size: 1.25rem; font-weight: 800; letter-spacing:.3px; }
        .table thead th { position: sticky; top: 0; background: #0d6efd; color: #fff; z-index: 2; }
        .table-hover tbody tr:hover{ background:#f1f5ff; }
        .status-badge {
            font-weight: 700; border-radius: 999px; padding: .25rem .6rem; font-size:.8rem;
        }
        .badge-ok{ background: #e8f7ef; color:#127a41; border:1px solid #bce8cf; }
        .badge-pend{ background: #fff1f2; color:#b42318; border:1px solid #ffd7db; }
        .card-form{
            border:0; border-radius: 1rem; overflow: hidden;
            box-shadow: 0 10px 30px rgba(2, 6, 23, .06);
        }
        .form-label{ font-weight:600; color:#334155; }
        .btn-pill{ border-radius: 999px; }
        .search-wrap{ position: relative; }
        .search-wrap .bi{ position:absolute; left:.75rem; top:50%; transform:translateY(-50%); opacity:.6; }
        .search-input{ padding-left: 2.2rem; }
        .table-responsive{ border-radius: .75rem; overflow: hidden; }
        .subtitle{ color:#475569; }
        .small-muted{ font-size:.85rem; color:#6b7280; }
    </style>
</head>
<body>

<div class="container py-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
        <div>
            <h2 class="page-title mb-0">
                <i class="bi bi-cash-coin text-primary"></i>
                Recolección de Comisiones (Zona <?= h($zona) ?>)
            </h2>
            <div class="subtitle">Control y registro de entregas de comisiones especiales por sucursal.</div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="small-muted">Actualizado:</span>
            <span class="badge text-bg-light border"><i class="bi bi-clock-history"></i> <?= h(date('Y-m-d H:i')) ?></span>
        </div>
    </div>

    <?= $msg ?>

    <!-- KPIs -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-md-4">
            <div class="card card-kpi">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <span class="kpi-label"><i class="bi bi-coin"></i> Total comisiones</span>
                        <span class="badge text-bg-primary-subtle">Zona <?= h($zona) ?></span>
                    </div>
                    <div class="kpi-value mt-1"><?= mx($totalComisiones) ?></div>
                    <div class="progress mt-2" role="progressbar" aria-label="Avance entrega"
                         aria-valuenow="<?= ($totalComisiones>0? ($totalEntregado/$totalComisiones*100):0) ?>"
                         aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar" style="width: <?= ($totalComisiones>0? ($totalEntregado/$totalComisiones*100):0) ?>%"></div>
                    </div>
                    <div class="small-muted mt-1">Avance de entrega vs. total</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card card-kpi">
                <div class="card-body">
                    <div class="kpi-label"><i class="bi bi-box-arrow-up-right"></i> Total entregado</div>
                    <div class="kpi-value mt-1 text-success"><?= mx($totalEntregado) ?></div>
                    <div class="small-muted mt-2">Entregas registradas en el sistema</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card card-kpi">
                <div class="card-body">
                    <div class="kpi-label"><i class="bi bi-exclamation-diamond"></i> Saldo pendiente</div>
                    <div class="kpi-value mt-1 text-danger"><?= mx($totalSaldo) ?></div>
                    <div class="small-muted mt-2">Suma de saldos por sucursal</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Saldos por sucursal -->
    <div class="d-flex align-items-center justify-content-between mb-2">
        <h4 class="mb-0"><i class="bi bi-building-check"></i> Saldos de Comisiones por Sucursal</h4>
        <div class="search-wrap">
            <i class="bi bi-search"></i>
            <input type="text" id="filtroSaldos" class="form-control form-control-sm search-input" placeholder="Filtrar sucursales...">
        </div>
    </div>

    <div class="table-responsive mb-4">
        <table class="table table-hover align-middle mb-0" id="tablaSaldos">
            <thead>
                <tr>
                    <th style="min-width:220px;">Sucursal</th>
                    <th class="text-end">Total Comisiones</th>
                    <th class="text-end">Total Entregado</th>
                    <th class="text-end">Saldo Pendiente</th>
                    <th class="text-center" style="width:160px;">Acción</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($saldos as $s): 
                $tC = (float)$s['total_comisiones'];
                $tE = (float)$s['total_entregado'];
                $sp = (float)$s['saldo_pendiente'];
                $badge = $sp>0 ? "<span class='status-badge badge-pend'>Pendiente</span>" : "<span class='status-badge badge-ok'>Al día</span>";
            ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-shop text-primary"></i>
                            <div class="fw-semibold"><?= h($s['sucursal']) ?></div>
                            <?= $badge ?>
                        </div>
                    </td>
                    <td class="text-end"><?= mx($tC) ?></td>
                    <td class="text-end text-success"><?= mx($tE) ?></td>
                    <td class="text-end <?= $sp>0?'text-danger fw-bold':'' ?>"><?= mx($sp) ?></td>
                    <td class="text-center">
                        <?php if ($sp>0): ?>
                        <!-- Botón abre modal de confirmación “Recolectar todo” -->
                        <button 
                            class="btn btn-sm btn-primary btn-pill" 
                            data-bs-toggle="modal" 
                            data-bs-target="#modalRecolectarTodo" 
                            data-sucursal="<?= h($s['sucursal']) ?>"
                            data-id="<?= (int)$s['id_sucursal'] ?>"
                            data-monto="<?= number_format($sp,2,'.','') ?>">
                            <i class="bi bi-recycle"></i> Recolectar todo
                        </button>
                        <?php else: ?>
                            <span class="text-success"><i class="bi bi-check2-circle"></i></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="fw-bold">
                    <td class="text-end">Totales:</td>
                    <td class="text-end"><?= mx($totalComisiones) ?></td>
                    <td class="text-end"><?= mx($totalEntregado) ?></td>
                    <td class="text-end"><?= mx($totalSaldo) ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- Formulario de registro -->
    <h4 class="mb-2"><i class="bi bi-journal-plus"></i> Registrar Entrega de Comisiones</h4>
    <div class="card card-form mb-4">
        <div class="card-body">
            <form method="POST" class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label">Sucursal</label>
                    <select name="id_sucursal" id="selectSucursal" class="form-select" required>
                        <option value="">-- Selecciona Sucursal --</option>
                        <?php foreach($saldos as $s): if((float)$s['saldo_pendiente']>0): ?>
                            <option 
                                value="<?= (int)$s['id_sucursal'] ?>"
                                data-saldo="<?= number_format((float)$s['saldo_pendiente'],2,'.','') ?>">
                                <?= h($s['sucursal']) ?> — Pendiente <?= mx($s['saldo_pendiente']) ?>
                            </option>
                        <?php endif; endforeach; ?>
                    </select>
                    <div class="form-text">Solo se listan sucursales con saldo pendiente.</div>
                </div>

                <div class="col-12 col-md-3">
                    <label class="form-label">Monto Entregado</label>
                    <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="number" step="0.01" min="0" name="monto_entregado" id="inputMonto" class="form-control" required>
                    </div>
                    <div class="form-text">Puedes autollenar con el saldo pendiente.</div>
                </div>

                <div class="col-12 col-md-3 d-flex align-items-end">
                    <button type="button" class="btn btn-outline-primary w-100 btn-pill" id="btnUsarSaldo">
                        <i class="bi bi-magic"></i> Usar saldo pendiente
                    </button>
                </div>

                <div class="col-12">
                    <label class="form-label">Observaciones</label>
                    <textarea name="observaciones" class="form-control" rows="2" placeholder="Ej. Recolección parcial de efectivos, folio tal..."></textarea>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-success w-100 btn-pill">
                        <i class="bi bi-save2"></i> Guardar entrega
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Historial -->
    <div class="d-flex align-items-center justify-content-between mb-2">
        <h4 class="mb-0"><i class="bi bi-clock-history"></i> Historial de Entregas</h4>
        <div class="search-wrap">
            <i class="bi bi-search"></i>
            <input type="text" id="filtroHistorial" class="form-control form-control-sm search-input" placeholder="Filtrar historial...">
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-striped align-middle" id="tablaHistorial">
            <thead class="table-light">
                <tr>
                    <th style="width:80px;">ID</th>
                    <th>Sucursal</th>
                    <th class="text-end">Monto Entregado</th>
                    <th style="min-width:160px;">Fecha</th>
                    <th>Observaciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($historial as $h): ?>
                <tr>
                    <td>#<?= (int)$h['id'] ?></td>
                    <td><i class="bi bi-shop"></i> <?= h($h['sucursal']) ?></td>
                    <td class="text-end text-success"><?= mx($h['monto_entregado']) ?></td>
                    <td><i class="bi bi-calendar2-event"></i> <?= h(date('Y-m-d H:i', strtotime($h['fecha_entrega']))) ?></td>
                    <td><?= h($h['observaciones']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Recolectar Todo -->
<div class="modal fade" id="modalRecolectarTodo" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-recycle"></i> Confirmar recolección total</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <p class="mb-1">Estás por recolectar el saldo completo de:</p>
        <div class="fw-bold" id="modalSucursal"></div>
        <div class="mt-2">Monto: <span class="fw-bold text-danger" id="modalMonto"></span></div>
        <input type="hidden" name="id_sucursal" id="modalIdSucursal">
        <input type="hidden" name="monto_entregado" id="modalMontoInput">
        <input type="hidden" name="observaciones" value="Recolección completa">
        <div class="alert alert-warning mt-3 mb-0"><i class="bi bi-exclamation-triangle"></i> Esta acción registrará una entrega por el total del saldo pendiente.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-pill" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary btn-pill">Confirmar</button>
      </div>
    </form>
  </div>
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
// Filtro simple en tablas
function filtraTabla(inputId, tableId){
    const q = document.getElementById(inputId)?.value.toLowerCase() ?? '';
    document.querySelectorAll('#'+tableId+' tbody tr').forEach(tr=>{
        tr.style.display = tr.innerText.toLowerCase().includes(q) ? '' : 'none';
    });
}
document.getElementById('filtroSaldos')?.addEventListener('input', ()=>filtraTabla('filtroSaldos','tablaSaldos'));
document.getElementById('filtroHistorial')?.addEventListener('input', ()=>filtraTabla('filtroHistorial','tablaHistorial'));

// Autollenar monto con saldo pendiente de la sucursal seleccionada
const selectSucursal = document.getElementById('selectSucursal');
const inputMonto = document.getElementById('inputMonto');
document.getElementById('btnUsarSaldo')?.addEventListener('click', ()=>{
    if (!selectSucursal) return;
    const opt = selectSucursal.options[selectSucursal.selectedIndex];
    const saldo = opt?.getAttribute('data-saldo');
    if (saldo){
        inputMonto.value = saldo;
        inputMonto.focus();
    }
});

// Modal de “Recolectar todo”
const modal = document.getElementById('modalRecolectarTodo');
if (modal){
    modal.addEventListener('show.bs.modal', (ev)=>{
        const btn = ev.relatedTarget;
        const sucursal = btn.getAttribute('data-sucursal');
        const id       = btn.getAttribute('data-id');
        const monto    = btn.getAttribute('data-monto');
        document.getElementById('modalSucursal').textContent = sucursal;
        document.getElementById('modalMonto').textContent    = new Intl.NumberFormat('es-MX',{style:'currency',currency:'MXN'}).format(parseFloat(monto||0));
        document.getElementById('modalIdSucursal').value     = id;
        document.getElementById('modalMontoInput').value     = monto;
    });
}
</script>
</body>
</html>
