<?php
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

$mensaje = "";

/* -----------------------------------------------------------
   Utilidades: detectar si una columna existe (para bitácoras)
----------------------------------------------------------- */
function hasColumn(mysqli $conn, string $table, string $column): bool {
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $rs = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $rs && $rs->num_rows > 0;
}

$hasDT_Resultado     = hasColumn($conn, 'detalle_traspaso', 'resultado');
$hasDT_FechaResultado= hasColumn($conn, 'detalle_traspaso', 'fecha_resultado');
$hasT_FechaRecep     = hasColumn($conn, 'traspasos', 'fecha_recepcion');
$hasT_UsuarioRecibio = hasColumn($conn, 'traspasos', 'usuario_recibio');

/* ==========================================================
   POST: Recepción parcial / total
   - Recibe lo marcado (checkbox) -> va a destino y Disponible
   - Lo no marcado se regresa al origen y Disponible
   - Estatus: Completado / Parcial / Rechazado
   - Guarda bitácora por pieza si existen columnas
========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['procesar_traspaso'])) {
    $idTraspaso = (int)($_POST['id_traspaso'] ?? 0);
    $marcados   = array_map('intval', $_POST['aceptar'] ?? []); // ids de inventario recibidos

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

        // Traer todos los equipos del traspaso
        $stmt = $conn->prepare("SELECT id_inventario FROM detalle_traspaso WHERE id_traspaso=?");
        $stmt->bind_param("i", $idTraspaso);
        $stmt->execute();
        $res = $stmt->get_result();

        $todos = [];
        while ($r = $res->fetch_assoc()) $todos[] = (int)$r['id_inventario'];
        $stmt->close();

        if (empty($todos)) {
            $mensaje = "<div class='alert alert-warning mt-3'>⚠️ El traspaso no contiene productos.</div>";
        } else {
            // Sanitizar marcados: que existan en el traspaso
            $marcados = array_values(array_intersect($marcados, $todos));
            $rechazados = [];
            foreach ($todos as $idInv) {
                if (!in_array($idInv, $marcados, true)) $rechazados[] = $idInv;
            }

            $conn->begin_transaction();
            try {
                // Aceptados -> destino + Disponible + (bitácora detalle)
                if (!empty($marcados)) {
                    $stmtI = $conn->prepare("UPDATE inventario SET id_sucursal=?, estatus='Disponible' WHERE id=?");
                    $stmtD = ($hasDT_Resultado || $hasDT_FechaResultado)
                        ? $conn->prepare("
                            UPDATE detalle_traspaso
                            SET ".($hasDT_Resultado ? "resultado='Recibido'," : "").($hasDT_FechaResultado ? " fecha_resultado=NOW()," : "")." id_traspaso=id_traspaso
                            WHERE id_traspaso=? AND id_inventario=?
                          ")
                        : null;

                    foreach ($marcados as $idInv) {
                        $stmtI->bind_param("ii", $idDestino, $idInv);
                        $stmtI->execute();

                        if ($stmtD) {
                            $stmtD->bind_param("ii", $idTraspaso, $idInv);
                            $stmtD->execute();
                        }
                    }
                    $stmtI->close();
                    if ($stmtD) $stmtD->close();
                }

                // Rechazados -> origen + Disponible + (bitácora detalle)
                if (!empty($rechazados)) {
                    $stmtI = $conn->prepare("UPDATE inventario SET id_sucursal=?, estatus='Disponible' WHERE id=?");
                    $stmtD = ($hasDT_Resultado || $hasDT_FechaResultado)
                        ? $conn->prepare("
                            UPDATE detalle_traspaso
                            SET ".($hasDT_Resultado ? "resultado='Rechazado'," : "").($hasDT_FechaResultado ? " fecha_resultado=NOW()," : "")." id_traspaso=id_traspaso
                            WHERE id_traspaso=? AND id_inventario=?
                          ")
                        : null;

                    foreach ($rechazados as $idInv) {
                        $stmtI->bind_param("ii", $idOrigen, $idInv);
                        $stmtI->execute();

                        if ($stmtD) {
                            $stmtD->bind_param("ii", $idTraspaso, $idInv);
                            $stmtD->execute();
                        }
                    }
                    $stmtI->close();
                    if ($stmtD) $stmtD->close();
                }

                // Estatus del traspaso
                $total = count($todos);
                $ok    = count($marcados);
                $rej   = count($rechazados);
                $estatus = ($ok === 0) ? 'Rechazado' : (($ok < $total) ? 'Parcial' : 'Completado');

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

                $mensaje = "<div class='alert alert-success mt-3'>
                    ✅ Traspaso #$idTraspaso procesado. Recibidos: <b>$ok</b> · Rechazados: <b>$rej</b> · Estatus: <b>$estatus</b>.
                </div>";
            } catch (Throwable $e) {
                $conn->rollback();
                $mensaje = "<div class='alert alert-danger mt-3'>❌ Error al procesar: ".htmlspecialchars($e->getMessage())."</div>";
            }
        }
    }
}

/* ==========================================================
   Traspasos pendientes de la sucursal
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
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Traspasos Pendientes</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <style>
    .chk-cell{ width:72px; text-align:center }
    .sticky-actions{ position:sticky; bottom:0; background:#fff; padding:10px; border-top:1px solid #e5e7eb }
  </style>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
  <h2>📦 Traspasos Pendientes</h2>
  <?= $mensaje ?>

  <?php if ($traspasos && $traspasos->num_rows > 0): ?>
    <?php while($traspaso = $traspasos->fetch_assoc()): ?>
      <?php
      $idTraspaso = (int)$traspaso['id'];
      $detalles = $conn->query("
          SELECT i.id, p.marca, p.modelo, p.color, p.imei1, p.imei2
          FROM detalle_traspaso dt
          INNER JOIN inventario i ON i.id = dt.id_inventario
          INNER JOIN productos p  ON p.id = i.id_producto
          WHERE dt.id_traspaso = $idTraspaso
          ORDER BY p.marca, p.modelo, i.id
      ");
      ?>
      <div class="card mb-4 shadow">
        <div class="card-header bg-dark text-white">
          <div class="d-flex flex-wrap justify-content-between align-items-center">
            <span>
              Traspaso #<?= $idTraspaso ?>
              &nbsp;|&nbsp; Origen: <b><?= htmlspecialchars($traspaso['sucursal_origen']) ?></b>
              &nbsp;|&nbsp; Fecha: <?= htmlspecialchars($traspaso['fecha_traspaso']) ?>
            </span>
            <span>Creado por: <?= htmlspecialchars($traspaso['usuario_creo']) ?></span>
          </div>
        </div>

        <form method="POST">
          <input type="hidden" name="id_traspaso" value="<?= $idTraspaso ?>">

          <div class="card-body p-0">
            <table class="table table-striped table-bordered table-sm mb-0">
              <thead class="table-dark">
                <tr>
                  <th class="chk-cell">
                    <input type="checkbox" class="form-check-input" id="chk_all_<?= $idTraspaso ?>" checked
                      onclick="toggleAll(<?= $idTraspaso ?>, this.checked)">
                  </th>
                  <th>ID Inv</th>
                  <th>Marca</th>
                  <th>Modelo</th>
                  <th>Color</th>
                  <th>IMEI1</th>
                  <th>IMEI2</th>
                  <th>Estatus Actual</th>
                </tr>
              </thead>
              <tbody>
                <?php while ($row = $detalles->fetch_assoc()): ?>
                  <tr>
                    <td class="chk-cell">
                      <input type="checkbox" class="form-check-input chk-item-<?= $idTraspaso ?>"
                             name="aceptar[]" value="<?= (int)$row['id'] ?>" checked>
                    </td>
                    <td><?= (int)$row['id'] ?></td>
                    <td><?= htmlspecialchars($row['marca']) ?></td>
                    <td><?= htmlspecialchars($row['modelo']) ?></td>
                    <td><?= htmlspecialchars($row['color']) ?></td>
                    <td><?= htmlspecialchars($row['imei1']) ?></td>
                    <td><?= $row['imei2'] ? htmlspecialchars($row['imei2']) : '-' ?></td>
                    <td>En tránsito</td>
                  </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          </div>

          <div class="sticky-actions d-flex justify-content-between align-items-center">
            <div class="text-muted">
              Marca lo que <b>SÍ recibiste</b>. Lo demás se <b>rechaza</b> y regresa a la sucursal origen.
            </div>
            <div class="d-flex gap-2">
              <button type="button" class="btn btn-outline-secondary btn-sm"
                      onclick="toggleAll(<?= $idTraspaso ?>, true)">Marcar todo</button>
              <button type="button" class="btn btn-outline-secondary btn-sm"
                      onclick="toggleAll(<?= $idTraspaso ?>, false)">Desmarcar todo</button>
              <button type="submit" name="procesar_traspaso" class="btn btn-success btn-sm">
                ✅ Procesar recepción
              </button>
            </div>
          </div>
        </form>
      </div>
    <?php endwhile; ?>
  <?php else: ?>
    <div class="alert alert-info mt-3">No hay traspasos pendientes para tu sucursal.</div>
  <?php endif; ?>
</div>

<script>
function toggleAll(idT, checked){
  document.querySelectorAll('.chk-item-' + idT).forEach(el => el.checked = checked);
  const master = document.getElementById('chk_all_' + idT);
  if (master) master.checked = checked;
}
</script>
</body>
</html>
