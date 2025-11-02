<?php
// traspasos_pendientes.php — Recepción de traspasos (Gerente/Destino)
// - Aceptación/Rechazo parcial de Equipos (IMEI) y Accesorios (por cantidad)
// - Blindaje: si al rechazar un accesorio no existe ya la fila original de inventario, hace UPERT en origen por (id_producto)
// - Abre acuse (iframe) para equipos recibidos

session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';

$idSucursalUsuario = (int)($_SESSION['id_sucursal'] ?? 0);
$idUsuario         = (int)($_SESSION['id_usuario'] ?? 0);
$rolUsuario        = $_SESSION['rol'] ?? '';
$whereSucursal     = "id_sucursal_destino = $idSucursalUsuario";

$mensaje    = "";
$acuseUrl   = "";
$acuseReady = false;

/* -----------------------------------------------------------
   Utilidades
----------------------------------------------------------- */
function hasColumn(mysqli $conn, string $table, string $column): bool {
    $table  = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $rs = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $rs && $rs->num_rows > 0;
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$hasDT_Resultado         = hasColumn($conn, 'detalle_traspaso', 'resultado');
$hasDT_FechaResultado    = hasColumn($conn, 'detalle_traspaso', 'fecha_resultado');
$hasACC_Resultado        = hasColumn($conn, 'detalle_traspaso_acc', 'resultado');
$hasACC_FechaResultado   = hasColumn($conn, 'detalle_traspaso_acc', 'fecha_resultado');
$hasT_FechaRecep         = hasColumn($conn, 'traspasos', 'fecha_recepcion');
$hasT_UsuarioRecibio     = hasColumn($conn, 'traspasos', 'usuario_recibio');
$inventarioTieneCantidad = hasColumn($conn, 'inventario', 'cantidad'); // accesorios

/* ==========================================================
   POST: Recepción parcial / total (equipos + accesorios)
========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['procesar_traspaso'])) {
    $idTraspaso = (int)($_POST['id_traspaso'] ?? 0);

    // Checkboxes: equipos (id_inventario) y accesorios (id_detalle_acc)
    $recibirEq  = array_map('intval', $_POST['aceptar_eq']  ?? []);
    $recibirAcc = array_map('intval', $_POST['aceptar_acc'] ?? []);

    // Validar traspaso pendiente de mi sucursal
    $stmt = $conn->prepare("
        SELECT id_sucursal_origen, id_sucursal_destino
        FROM traspasos
        WHERE id=? AND $whereSucursal AND estatus='Pendiente'
        LIMIT 1
    ");
    $stmt->bind_param("i", $idTraspaso);
    $stmt->execute();
    $tinfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$tinfo) {
        $mensaje = "<div class='alert alert-danger mt-3'>❌ Traspaso inválido o ya procesado.</div>";
    } else {
        $idOrigen  = (int)$tinfo['id_sucursal_origen'];
        $idDestino = (int)$tinfo['id_sucursal_destino'];

        // --- Universo de EQUIPOS del traspaso (por id_inventario) ---
        $stmt = $conn->prepare("SELECT id_inventario FROM detalle_traspaso WHERE id_traspaso=?");
        $stmt->bind_param("i", $idTraspaso);
        $stmt->execute();
        $res = $stmt->get_result();
        $todosEq = [];
        while ($r = $res->fetch_assoc()) $todosEq[] = (int)$r['id_inventario'];
        $stmt->close();

        // --- Universo de ACCESORIOS del traspaso (por id detalle_acc) ---
        $stmt = $conn->prepare("SELECT id FROM detalle_traspaso_acc WHERE id_traspaso=?");
        $stmt->bind_param("i", $idTraspaso);
        $stmt->execute();
        $res = $stmt->get_result();
        $todosAcc = [];
        while ($r = $res->fetch_assoc()) $todosAcc[] = (int)$r['id'];
        $stmt->close();

        if (empty($todosEq) && empty($todosAcc)) {
            $mensaje = "<div class='alert alert-warning mt-3'>⚠️ El traspaso no contiene productos.</div>";
        } else {
            // Sanitizar marcados contra el universo
            $recibirEq  = array_values(array_intersect($recibirEq, $todosEq));
            $recibirAcc = array_values(array_intersect($recibirAcc, $todosAcc));

            // Rechazados = universo - aceptados (por tipo)
            $rechazarEq  = array_values(array_diff($todosEq, $recibirEq));
            $rechazarAcc = array_values(array_diff($todosAcc, $recibirAcc));

            $conn->begin_transaction();
            try {
                /* =======================
                   EQUIPOS (detalle_traspaso)
                   ======================= */
                // Aceptados: mover a destino y poner Disponible + marcar resultado
                if (!empty($recibirEq)) {
                    $stUpdInv = $conn->prepare("UPDATE inventario SET id_sucursal=?, estatus='Disponible' WHERE id=?");
                    $stUpdDet = ($hasDT_Resultado || $hasDT_FechaResultado)
                        ? $conn->prepare("
                            UPDATE detalle_traspaso
                            SET ".($hasDT_Resultado ? "resultado='Recibido'," : "").($hasDT_FechaResultado ? " fecha_resultado=NOW()," : "")." id_traspaso=id_traspaso
                            WHERE id_traspaso=? AND id_inventario=?
                          ") : null;

                    foreach ($recibirEq as $idInv) {
                        $stUpdInv->bind_param("ii", $idDestino, $idInv);
                        $stUpdInv->execute();

                        if ($stUpdDet) {
                            $stUpdDet->bind_param("ii", $idTraspaso, $idInv);
                            $stUpdDet->execute();
                        }
                    }
                    $stUpdInv->close();
                    if ($stUpdDet) $stUpdDet->close();
                }

                // Rechazados: regresar a origen + Disponible + marcar resultado
                if (!empty($rechazarEq)) {
                    $stUpdInv = $conn->prepare("UPDATE inventario SET id_sucursal=?, estatus='Disponible' WHERE id=?");
                    $stUpdDet = ($hasDT_Resultado || $hasDT_FechaResultado)
                        ? $conn->prepare("
                            UPDATE detalle_traspaso
                            SET ".($hasDT_Resultado ? "resultado='Rechazado'," : "").($hasDT_FechaResultado ? " fecha_resultado=NOW()," : "")." id_traspaso=id_traspaso
                            WHERE id_traspaso=? AND id_inventario=?
                          ") : null;

                    foreach ($rechazarEq as $idInv) {
                        $stUpdInv->bind_param("ii", $idOrigen, $idInv);
                        $stUpdInv->execute();

                        if ($stUpdDet) {
                            $stUpdDet->bind_param("ii", $idTraspaso, $idInv);
                            $stUpdDet->execute();
                        }
                    }
                    $stUpdInv->close();
                    if ($stUpdDet) $stUpdDet->close();
                }

                /* =======================
                   ACCESORIOS (detalle_traspaso_acc)
                   ======================= */
                if (!$inventarioTieneCantidad) {
                    throw new Exception("La tabla inventario no tiene columna 'cantidad' para accesorios.");
                }

                // Helpers accesorios
                $stSelAccDet = $conn->prepare("
                    SELECT id, id_inventario_origen, id_producto, cantidad
                    FROM detalle_traspaso_acc
                    WHERE id=?
                ");

                // Aceptados → sumar al destino (upsert) y marcar resultado
                $stFindDest = $conn->prepare("SELECT id FROM inventario WHERE id_sucursal=? AND id_producto=? AND estatus='Disponible' LIMIT 1");
                $stInsDest  = $conn->prepare("INSERT INTO inventario (id_producto, id_sucursal, cantidad, estatus, fecha_ingreso) VALUES (?,?,?,?, NOW())");
                $stUpdDest  = $conn->prepare("UPDATE inventario SET cantidad = cantidad + ? WHERE id=?");

                foreach ($recibirAcc as $idDetAcc) {
                    $stSelAccDet->bind_param("i", $idDetAcc);
                    $stSelAccDet->execute();
                    $a = $stSelAccDet->get_result()->fetch_assoc();
                    if (!$a) continue;

                    $idProd = (int)$a['id_producto'];
                    $qty    = (int)$a['cantidad'];

                    // upsert en inventario destino
                    $stFindDest->bind_param("ii", $idDestino, $idProd);
                    $stFindDest->execute();
                    $row = $stFindDest->get_result()->fetch_assoc();
                    if ($row) {
                        $idInvDest = (int)$row['id'];
                        $stUpdDest->bind_param("ii", $qty, $idInvDest);
                        $stUpdDest->execute();
                    } else {
                        $estatus = 'Disponible';
                        $stInsDest->bind_param("iiis", $idProd, $idDestino, $qty, $estatus);
                        $stInsDest->execute();
                    }

                    // marcar resultado en detalle_traspaso_acc
                    if ($hasACC_Resultado || $hasACC_FechaResultado) {
                        $conn->query("
                            UPDATE detalle_traspaso_acc
                            SET ".($hasACC_Resultado ? "resultado='Recibido'," : "").($hasACC_FechaResultado ? " fecha_resultado=NOW()," : "")." id_traspaso=id_traspaso
                            WHERE id={$idDetAcc} AND id_traspaso={$idTraspaso}
                        ");
                    }
                }

                // Rechazados → reponer al inventario origen (con fallback si la fila ya no existe)
                $stUpdBack    = $conn->prepare("UPDATE inventario SET cantidad = cantidad + ? WHERE id=? AND id_sucursal=?");
                $stFindOrigUP = $conn->prepare("SELECT id FROM inventario WHERE id_sucursal=? AND id_producto=? AND estatus='Disponible' LIMIT 1");
                $stInsOrigUP  = $conn->prepare("INSERT INTO inventario (id_producto, id_sucursal, cantidad, estatus, fecha_ingreso) VALUES (?,?,?,?, NOW())");
                $stUpdOrigUP  = $conn->prepare("UPDATE inventario SET cantidad = cantidad + ? WHERE id=?");

                foreach ($rechazarAcc as $idDetAcc) {
                    $stSelAccDet->bind_param("i", $idDetAcc);
                    $stSelAccDet->execute();
                    $a = $stSelAccDet->get_result()->fetch_assoc();
                    if (!$a) continue;

                    $qty        = (int)$a['cantidad'];
                    $idInvOrig  = (int)$a['id_inventario_origen'];
                    $idProd     = (int)$a['id_producto'];

                    // Intento 1: reponer exactamente la fila original
                    $stUpdBack->bind_param("iii", $qty, $idInvOrig, $idOrigen);
                    $stUpdBack->execute();

                    if ($stUpdBack->affected_rows === 0) {
                        // Fallback “upsert” por producto en la sucursal de origen
                        $stFindOrigUP->bind_param("ii", $idOrigen, $idProd);
                        $stFindOrigUP->execute();
                        $rowO = $stFindOrigUP->get_result()->fetch_assoc();

                        if ($rowO) {
                            $idInvO = (int)$rowO['id'];
                            $stUpdOrigUP->bind_param("ii", $qty, $idInvO);
                            $stUpdOrigUP->execute();
                        } else {
                            $estatus = 'Disponible';
                            $stInsOrigUP->bind_param("iiis", $idProd, $idOrigen, $qty, $estatus);
                            $stInsOrigUP->execute();
                        }
                    }

                    if ($hasACC_Resultado || $hasACC_FechaResultado) {
                        $conn->query("
                            UPDATE detalle_traspaso_acc
                            SET ".($hasACC_Resultado ? "resultado='Rechazado'," : "").($hasACC_FechaResultado ? " fecha_resultado=NOW()," : "")." id_traspaso=id_traspaso
                            WHERE id={$idDetAcc} AND id_traspaso={$idTraspaso}
                        ");
                    }
                }
                $stSelAccDet->close();
                $stFindDest->close(); $stInsDest->close(); $stUpdDest->close();
                $stUpdBack->close();  $stFindOrigUP->close(); $stInsOrigUP->close(); $stUpdOrigUP->close();

                /* =======================
                   Estatus del traspaso
                   ======================= */
                // Métricas para estatus:
                // - equipos: items = número de filas
                // - accesorios: items = número de filas (no sumamos cantidad para el estatus del movimiento)
                $totEq   = count($todosEq);
                $totAcc  = count($todosAcc);
                $okEq    = count($recibirEq);
                $okAcc   = count($recibirAcc);
                $rechEq  = count($rechazarEq);
                $rechAcc = count($rechazarAcc);

                $totalFilas = $totEq + $totAcc;
                $okFilas    = $okEq  + $okAcc;

                $estatus = ($okFilas === 0) ? 'Rechazado' : (($okFilas < $totalFilas) ? 'Parcial' : 'Completado');

                // Actualizar traspaso (fecha/usuario si existen)
                if ($hasT_FechaRecep && $hasT_UsuarioRecibio) {
                    $stmt = $conn->prepare("UPDATE traspasos SET estatus=?, fecha_recepcion=NOW(), usuario_recibio=? WHERE id=?");
                    $stmt->bind_param("sii", $estatus, $idUsuario, $idTraspaso);
                } else {
                    $stmt = $conn->prepare("UPDATE traspasos SET estatus=? WHERE id=?");
                    $stmt->bind_param("si", $estatus, $idTraspaso);
                }
                $stmt->execute();
                $stmt->close();

                $conn->commit();

                // ===== Preparar ACUSE (solo equipos recibidos; accesorios no llevan IMEI) =====
                if ($okEq > 0) {
                    $idsCsv    = implode(',', array_map('intval', $recibirEq));
                    $acuseUrl  = "acuse_traspaso.php?id={$idTraspaso}&scope=recibidos&ids=" . urlencode($idsCsv) . "&print=1";
                    $acuseReady= true;
                }

                // Conteos para mensaje (sumar PZ para accesorios):
                $pzAccOk = 0;
                if (!empty($recibirAcc)) {
                    $in = implode(',', array_map('intval',$recibirAcc));
                    $q  = $conn->query("SELECT IFNULL(SUM(cantidad),0) AS pz FROM detalle_traspaso_acc WHERE id IN ($in)");
                    $pzAccOk = (int)($q->fetch_assoc()['pz'] ?? 0);
                }
                $pzAccRej = 0;
                if (!empty($rechazarAcc)) {
                    $in = implode(',', array_map('intval',$rechazarAcc));
                    $q  = $conn->query("SELECT IFNULL(SUM(cantidad),0) AS pz FROM detalle_traspaso_acc WHERE id IN ($in)");
                    $pzAccRej = (int)($q->fetch_assoc()['pz'] ?? 0);
                }

                $mensaje = "<div class='alert alert-success mt-3'>
                    ✅ Traspaso #".h($idTraspaso)." procesado.<br>
                    <div class='mt-1'>
                      <span class='badge bg-primary me-1'>Equipos recibidos: <b>".h($okEq)."</b></span>
                      <span class='badge bg-info text-dark me-1'>Accesorios recibidos (pzs): <b>".h($pzAccOk)."</b></span>
                      <span class='badge bg-secondary'>Estatus: <b>".h($estatus)."</b></span>
                    </div>
                    ".($okEq>0 ? "<div class='small text-muted mt-1'>Se abrirá un acuse con los <b>equipos</b> recibidos.</div>" : "")."
                </div>";
            } catch (Throwable $e) {
                $conn->rollback();
                $mensaje = "<div class='alert alert-danger mt-3'>❌ Error al procesar: ".h($e->getMessage())."</div>";
            }
        }
    }
}

/* ==========================================================
   Traspasos pendientes de la sucursal + RESUMEN
========================================================== */
$sql = "
    SELECT t.id, t.fecha_traspaso, s.nombre AS sucursal_origen, u.nombre AS usuario_creo
    FROM traspasos t
    INNER JOIN sucursales s ON s.id = t.id_sucursal_origen
    INNER JOIN usuarios  u ON u.id = t.usuario_creo
    WHERE t.$whereSucursal AND t.estatus='Pendiente'
    ORDER BY t.fecha_traspaso ASC, t.id ASC
";
$traspasos = $conn->query($sql);
$cntTraspasos = $traspasos ? $traspasos->num_rows : 0;

// Nombre sucursal
$nomSucursal = '—';
$stS = $conn->prepare("SELECT nombre FROM sucursales WHERE id=?");
$stS->bind_param("i",$idSucursalUsuario);
$stS->execute();
if ($rowS = $stS->get_result()->fetch_assoc()) $nomSucursal = $rowS['nombre'];
$stS->close();

// Totales de piezas por recibir y últimas fechas (equipos + SUM accesorios)
$totItems = 0; $minFecha = null; $maxFecha = null;
$stRes = $conn->prepare("
    SELECT 
      IFNULL(SUM(eq.cnt),0) AS eq_items,
      IFNULL(SUM(acc.pzs),0) AS acc_pzs,
      MIN(t.fecha_traspaso) AS primero,
      MAX(t.fecha_traspaso) AS ultimo
    FROM traspasos t
    LEFT JOIN (
      SELECT dt.id_traspaso, COUNT(*) AS cnt
      FROM detalle_traspaso dt
      GROUP BY dt.id_traspaso
    ) eq ON eq.id_traspaso = t.id
    LEFT JOIN (
      SELECT dta.id_traspaso, IFNULL(SUM(dta.cantidad),0) AS pzs
      FROM detalle_traspaso_acc dta
      GROUP BY dta.id_traspaso
    ) acc ON acc.id_traspaso = t.id
    WHERE t.$whereSucursal AND t.estatus='Pendiente'
");
$stRes->execute();
$rRes = $stRes->get_result()->fetch_assoc();
if ($rRes){
  $totItems = (int)($rRes['eq_items'] ?? 0) + (int)($rRes['acc_pzs'] ?? 0);
  $minFecha = $rRes['primero'];
  $maxFecha = $rRes['ultimo'];
}
$stRes->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Traspasos Pendientes</title>
  <link rel="icon" type="image/x-icon" href="./img/favicon.ico">

  <!-- Bootstrap & Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

  <style>
    :root{
      --brand:#0d6efd;
      --brand-100:rgba(13,110,253,.08);
    }
    body.bg-light{
      background:
        radial-gradient(1200px 420px at 110% -80%, var(--brand-100), transparent),
        radial-gradient(1200px 420px at -10% 120%, rgba(25,135,84,.06), transparent),
        #f8fafc;
    }
    .page-head{
      border:0; border-radius:1rem;
      background: linear-gradient(135deg, #22c55e 0%, #0ea5e9 55%, #6366f1 100%);
      color:#fff;
      box-shadow: 0 20px 45px rgba(2,8,20,.12), 0 3px 10px rgba(2,8,20,.06);
    }
    .page-head .icon{
      width:48px;height:48px; display:grid;place-items:center;
      background:rgba(255,255,255,.15); border-radius:14px;
    }
    .chip{
      background:rgba(255,255,255,.16);
      border:1px solid rgba(255,255,255,.25);
      color:#fff; padding:.35rem .6rem; border-radius:999px; font-weight:600;
    }
    .card-elev{
      border:0; border-radius:1rem;
      box-shadow:0 10px 28px rgba(2,8,20,.06), 0 2px 8px rgba(2,8,20,.05);
    }
    .table thead th{ letter-spacing:.4px; text-transform:uppercase; font-size:.78rem; }
    .sticky-actions{
      position:sticky; bottom:0; background:#fff; padding:12px; 
      border-top:1px solid #e5e7eb; border-bottom-left-radius:1rem; border-bottom-right-radius:1rem;
    }
    .card-header-gradient{
      background: linear-gradient(135deg,#1f2937 0%,#0f172a 100%);
      color:#fff !important;
      border-top-left-radius:1rem; border-top-right-radius:1rem;
      text-shadow: 0 1px 0 rgba(0,0,0,.35);
    }
    .card-header-gradient *{ color:#fff !important; }
    .chk-cell{ width:72px; text-align:center }
    .badge-type{ font-weight:600; }
  </style>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container my-4">

  <!-- Encabezado -->
  <div class="page-head p-4 p-md-5 mb-4">
    <div class="d-flex flex-wrap align-items-center gap-3">
      <div class="icon"><i class="bi bi-boxes fs-4"></i></div>
      <div class="flex-grow-1">
        <h2 class="mb-1 fw-bold">Traspasos Pendientes</h2>
        <div class="">Sucursal: <strong><?= h($nomSucursal) ?></strong></div>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <span class="chip"><i class="bi bi-clock-history me-1"></i> <?= date('d/m/Y H:i') ?></span>
      </div>
    </div>
  </div>

  <?= $mensaje ?>

  <?php if ($traspasos && $traspasos->num_rows > 0): ?>
    <?php
      // Totales informativos (piezas)
      $totItems = 0; $minFecha = null; $maxFecha = null;
      // ya calculados arriba, pero mantenemos cards simples
    ?>
    <?php while($traspaso = $traspasos->fetch_assoc()): ?>
      <?php
      $idTraspaso = (int)$traspaso['id'];

      // DETALLE UNIFICADO (equipos + accesorios)
      $sqlDetalle = "
        -- EQUIPOS
        SELECT 
          dt.id                AS det_id,
          'equipo'             AS tipo,
          i.id                 AS id_inv,
          p.marca, p.modelo, p.color,
          p.imei1, p.imei2,
          1                    AS cantidad
        FROM detalle_traspaso dt
        JOIN inventario  i ON i.id = dt.id_inventario
        JOIN productos   p ON p.id = i.id_producto
        WHERE dt.id_traspaso = {$idTraspaso}

        UNION ALL

        -- ACCESORIOS
        SELECT
          dta.id               AS det_id,
          'accesorio'          AS tipo,
          i.id                 AS id_inv,      -- inventario origen
          p.marca, p.modelo, p.color,
          NULL AS imei1, NULL AS imei2,
          dta.cantidad         AS cantidad
        FROM detalle_traspaso_acc dta
        JOIN inventario  i ON i.id = dta.id_inventario_origen
        JOIN productos   p ON p.id = i.id_producto
        WHERE dta.id_traspaso = {$idTraspaso}
        ORDER BY tipo DESC, modelo, color, det_id
      ";
      $detalles = $conn->query($sqlDetalle);
      ?>
      <div class="card card-elev mb-4">
        <div class="card-header card-header-gradient">
          <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
            <div class="fw-semibold">
              <i class="bi bi-hash"></i> Traspaso <strong>#<?= $idTraspaso ?></strong>
              <span class="ms-2">• Origen: <strong><?= h($traspaso['sucursal_origen']) ?></strong></span>
              <span class="ms-2">• Fecha: <strong><?= h($traspaso['fecha_traspaso']) ?></strong></span>
            </div>
            <div class="">
              Creado por: <strong><?= h($traspaso['usuario_creo']) ?></strong>
            </div>
          </div>
        </div>

        <form method="POST">
          <input type="hidden" name="id_traspaso" value="<?= $idTraspaso ?>">

          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-striped table-hover table-sm mb-0">
                <thead class="table-dark">
                  <tr>
                    <th class="chk-cell">
                      <input type="checkbox" class="form-check-input" id="chk_all_<?= $idTraspaso ?>" checked
                        onclick="toggleAll(<?= $idTraspaso ?>, this.checked)">
                    </th>
                    <th>Tipo</th>
                    <th>ID Inv</th>
                    <th>Marca</th>
                    <th>Modelo</th>
                    <th>Color</th>
                    <th>IMEI1</th>
                    <th>IMEI2</th>
                    <th class="text-end">Cantidad</th>
                    <th>Estatus</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($detalles && $detalles->num_rows): ?>
                    <?php while ($row = $detalles->fetch_assoc()): ?>
                      <?php
                        $isEquipo = ($row['tipo']==='equipo');
                        $checkName = $isEquipo ? 'aceptar_eq[]' : 'aceptar_acc[]';
                        $checkVal  = $isEquipo ? (int)$row['id_inv'] : (int)$row['det_id'];
                      ?>
                      <tr>
                        <td class="chk-cell">
                          <input type="checkbox" class="form-check-input chk-item-<?= $idTraspaso ?>"
                                 name="<?= $checkName ?>" value="<?= $checkVal ?>" checked>
                        </td>
                        <td>
                          <span class="badge badge-type <?= $isEquipo?'text-bg-primary':'text-bg-info text-dark' ?>">
                            <?= $isEquipo ? 'Equipo' : 'Accesorio' ?>
                          </span>
                        </td>
                        <td><?= (int)$row['id_inv'] ?></td>
                        <td><?= h($row['marca']) ?></td>
                        <td><?= h($row['modelo']) ?></td>
                        <td><?= h($row['color']) ?></td>
                        <td><?= $row['imei1'] ? h($row['imei1']) : '—' ?></td>
                        <td><?= $row['imei2'] ? h($row['imei2']) : '—' ?></td>
                        <td class="text-end"><?= (int)$row['cantidad'] ?></td>
                        <td><span class="badge text-bg-warning">En tránsito</span></td>
                      </tr>
                    <?php endwhile; ?>
                  <?php else: ?>
                    <tr><td colspan="10" class="text-center text-muted py-3">Sin detalle</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="sticky-actions d-flex flex-wrap gap-2 justify-content-between align-items-center">
            <div class="text-muted">
              Marca lo que <b>SÍ recibiste</b>. Lo demás se <b>rechaza</b> y regresa a la sucursal origen.
            </div>
            <div class="d-flex gap-2">
              <button type="button" class="btn btn-outline-secondary btn-sm"
                      onclick="toggleAll(<?= $idTraspaso ?>, true)"><i class="bi bi-check2-all me-1"></i> Marcar todo</button>
              <button type="button" class="btn btn-outline-secondary btn-sm"
                      onclick="toggleAll(<?= $idTraspaso ?>, false)"><i class="bi bi-x-circle me-1"></i> Desmarcar todo</button>
              <button type="submit" name="procesar_traspaso" class="btn btn-success text-white btn-sm">
                <i class="bi bi-send-check me-1"></i> Procesar recepción
              </button>
            </div>
          </div>
        </form>
      </div>
    <?php endwhile; ?>
  <?php else: ?>
    <div class="card card-elev">
      <div class="card-body text-center py-5">
        <div class="display-6 mb-2">😌</div>
        <h5 class="mb-1">No hay traspasos pendientes para tu sucursal</h5>
        <div class="text-muted">Cuando recibas traspasos, aparecerán aquí para su confirmación.</div>
      </div>
    </div>
  <?php endif; ?>
</div>

<!-- Modal ACUSE (iframe) -->
<div class="modal fade" id="modalAcuse" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-xxl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i>Acuse de recepción</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body p-0">
        <iframe id="frameAcuse" src="about:blank" style="width:100%;min-height:72vh;border:0;background:#fff"></iframe>
      </div>
      <div class="modal-footer">
        <button type="button" id="btnOpenAcuse" class="btn btn-outline-secondary">
          <i class="bi bi-box-arrow-up-right me-1"></i> Abrir en pestaña
        </button>
        <button type="button" id="btnPrintAcuse" class="btn btn-primary">
          <i class="bi bi-printer me-1"></i> Reimprimir
        </button>
        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<script>
function toggleAll(idT, checked){
  document.querySelectorAll('.chk-item-' + idT).forEach(el => el.checked = checked);
  const master = document.getElementById('chk_all_' + idT);
  if (master) master.checked = checked;
}

// ===== Modal ACUSE: auto-apertura posterior al procesamiento =====
const ACUSE_URL   = <?= json_encode($acuseUrl, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
const ACUSE_READY = <?= $acuseReady ? 'true' : 'false' ?>;

if (ACUSE_READY && ACUSE_URL) {
  const modalAcuse = new bootstrap.Modal(document.getElementById('modalAcuse'));
  const frame = document.getElementById('frameAcuse');
  frame.src = ACUSE_URL;
  frame.addEventListener('load', () => { try { frame.contentWindow.focus(); } catch(e){} });
  modalAcuse.show();

  document.getElementById('btnOpenAcuse').onclick  = () => window.open(ACUSE_URL, '_blank', 'noopener');
  document.getElementById('btnPrintAcuse').onclick = () => {
    try { frame.contentWindow.focus(); frame.contentWindow.print(); }
    catch(e){ window.open(ACUSE_URL, '_blank', 'noopener'); }
  };
}
</script>
</body>
</html>
