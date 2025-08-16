<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: index.php");
    exit();
}

include 'db.php';

/* ========================
   FUNCIONES AUXILIARES
======================== */
function obtenerSemanaPorIndice($offset = 0) {
    $hoy = new DateTime();
    $diaSemana = $hoy->format('N'); // 1=lunes ... 7=domingo
    $dif = $diaSemana - 2;          // martes=2
    if ($dif < 0) $dif += 7;

    $inicio = new DateTime();
    $inicio->modify("-$dif days")->setTime(0,0,0);
    if ($offset > 0) $inicio->modify("-" . (7*$offset) . " days");

    $fin = clone $inicio;
    $fin->modify("+6 days")->setTime(23,59,59);

    return [$inicio, $fin];
}

/* ========================
   SEMANA SELECCIONADA
======================== */
$semanaSeleccionada = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
list($inicioSemana, $finSemana) = obtenerSemanaPorIndice($semanaSeleccionada);
$fechaInicio = $inicioSemana->format('Y-m-d');
$fechaFin = $finSemana->format('Y-m-d');

/* ========================
   OBTENER GERENTES DE ZONA
======================== */
$sqlGerentes = "
    SELECT u.id, u.nombre, u.sueldo, s.zona
    FROM usuarios u
    INNER JOIN sucursales s ON s.id = u.id_sucursal
    WHERE u.rol='GerenteZona'
";
$gerentes = $conn->query($sqlGerentes);

/* ========================
   CONSULTA TABLA EXISTENTE
======================== */
$stmtHist = $conn->prepare("
    SELECT cgz.*, u.nombre AS gerente, u.sueldo
    FROM comisiones_gerentes_zona cgz
    INNER JOIN usuarios u ON cgz.id_gerente = u.id
    WHERE cgz.fecha_inicio = ?
    ORDER BY cgz.zona
");
$stmtHist->bind_param("s", $fechaInicio);
$stmtHist->execute();
$resultHist = $stmtHist->get_result();

$datos = [];

if ($resultHist->num_rows > 0) {
    while ($row = $resultHist->fetch_assoc()) {
        $totalPago = $row['sueldo'] + $row['comision_total'];
        $datos[] = [
            'gerente' => $row['gerente'],
            'zona' => $row['zona'],
            'sueldo' => $row['sueldo'],
            'com_equipos' => $row['comision_equipos'],
            'com_sims' => $row['comision_sims'],
            'com_pospago' => $row['comision_pospago'],
            'com_total' => $row['comision_total'],
            'total_pago' => $totalPago
        ];
    }
}

/* ========================
   SI NO EXISTE REGISTRO → CALCULAR AUTOMÁTICO
======================== */
if ($resultHist->num_rows == 0) {
    while ($g = $gerentes->fetch_assoc()) {
        $idGerente = $g['id'];
        $zona = $g['zona'];
        $sueldo = (float)$g['sueldo'];
        $nombreGerente = $g['nombre'];

        // 1️⃣ Calcular ventas de equipos
        $stmtEq = $conn->prepare("
            SELECT COUNT(dv.id) AS total_equipos, IFNULL(SUM(v.precio_venta),0) AS monto
            FROM detalle_venta dv
            INNER JOIN ventas v ON dv.id_venta = v.id
            INNER JOIN sucursales s ON s.id = v.id_sucursal
            WHERE s.zona = ? AND DATE(v.fecha_venta) BETWEEN ? AND ?
        ");
        $stmtEq->bind_param("sss", $zona, $fechaInicio, $fechaFin);
        $stmtEq->execute();
        $rowEq = $stmtEq->get_result()->fetch_assoc();
        $stmtEq->close();

        $totalEquipos = (int)$rowEq['total_equipos'];
        $montoEquipos = (float)$rowEq['monto'];

        // 2️⃣ Calcular ventas SIMs
        $stmtSims = $conn->prepare("
            SELECT COUNT(dvs.id) AS total_sims, IFNULL(SUM(vs.precio_total),0) AS monto
            FROM detalle_venta_sims dvs
            INNER JOIN ventas_sims vs ON dvs.id_venta = vs.id
            INNER JOIN sucursales s ON s.id = vs.id_sucursal
            WHERE s.zona = ? AND DATE(vs.fecha_venta) BETWEEN ? AND ?
        ");
        $stmtSims->bind_param("sss", $zona, $fechaInicio, $fechaFin);
        $stmtSims->execute();
        $rowSims = $stmtSims->get_result()->fetch_assoc();
        $stmtSims->close();

        $totalSims = (int)$rowSims['total_sims'];
        $montoSims = (float)$rowSims['monto'];

        // 3️⃣ Cuota total de la zona
        $stmtSucursales = $conn->prepare("SELECT id FROM sucursales WHERE zona = ?");
        $stmtSucursales->bind_param("s", $zona);
        $stmtSucursales->execute();
        $resSuc = $stmtSucursales->get_result();

        $cuotaZona = 0;
        while ($suc = $resSuc->fetch_assoc()) {
            $idSucursal = $suc['id'];
            $stmtCuota = $conn->prepare("
                SELECT cuota_monto
                FROM cuotas_sucursales
                WHERE id_sucursal=? AND fecha_inicio <= ?
                ORDER BY fecha_inicio DESC
                LIMIT 1
            ");
            $stmtCuota->bind_param("is", $idSucursal, $fechaInicio);
            $stmtCuota->execute();
            $rowC = $stmtCuota->get_result()->fetch_assoc();
            if ($rowC) $cuotaZona += (float)$rowC['cuota_monto'];
            $stmtCuota->close();
        }
        $stmtSucursales->close();

        // 4️⃣ % Cumplimiento
        $ventasZona = $montoEquipos + $montoSims;
        $cumplimiento = $cuotaZona > 0 ? ($ventasZona / $cuotaZona) * 100 : 0;

        // 5️⃣ Comisión según esquema
        if ($cumplimiento < 80) {
            $comEquipos = $totalEquipos * 10;
            $comSims = 0;
        } elseif ($cumplimiento < 100) {
            $comEquipos = $totalEquipos * 10;
            $comSims = $totalSims * 5;
        } else {
            $comEquipos = $totalEquipos * 20;
            $comSims = $totalSims * 10;
        }

        // 6️⃣ Comisión pospago
        $comPospago = 0;
        $stmtPos = $conn->prepare("
            SELECT vs.precio_total, vs.modalidad
            FROM ventas_sims vs
            INNER JOIN sucursales s ON s.id = vs.id_sucursal
            WHERE s.zona = ? 
              AND DATE(vs.fecha_venta) BETWEEN ? AND ? 
              AND vs.tipo_venta = 'Pospago'
        ");
        $stmtPos->bind_param("sss", $zona, $fechaInicio, $fechaFin);
        $stmtPos->execute();
        $resPos = $stmtPos->get_result();

        while ($row = $resPos->fetch_assoc()) {
            $plan = (int)$row['precio_total'];
            $conEquipo = ($row['modalidad'] === 'Con equipo');

            if ($plan >= 339) $comPospago += $conEquipo ? 30 : 25;
            elseif ($plan >= 289) $comPospago += $conEquipo ? 25 : 20;
            elseif ($plan >= 249) $comPospago += $conEquipo ? 20 : 15;
            elseif ($plan >= 199) $comPospago += $conEquipo ? 15 : 10;
        }
        $stmtPos->close();

        $comTotal = $comEquipos + $comSims + $comPospago;
        $totalPago = $sueldo + $comTotal;

        // 🔹 Variable para modems (por ahora 0)
        $comModems = 0.0;

        // 🔹 Guardar en tabla histórica
        $stmtInsert = $conn->prepare("
            INSERT INTO comisiones_gerentes_zona
            (id_gerente, fecha_inicio, zona, cuota_zona, ventas_zona, porcentaje_cumplimiento,
             comision_equipos, comision_modems, comision_sims, comision_pospago, comision_total)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmtInsert->bind_param(
            "issdddddddd",
            $idGerente, $fechaInicio, $zona, $cuotaZona, $ventasZona, $cumplimiento,
            $comEquipos, $comModems, $comSims, $comPospago, $comTotal
        );
        $stmtInsert->execute();
        $stmtInsert->close();

        $datos[] = [
            'gerente' => $nombreGerente,
            'zona' => $zona,
            'sueldo' => $sueldo,
            'com_equipos' => $comEquipos,
            'com_sims' => $comSims,
            'com_pospago' => $comPospago,
            'com_total' => $comTotal,
            'total_pago' => $totalPago
        ];
    }
}

$total_sueldos = array_sum(array_column($datos, 'sueldo'));
$total_com_equipos = array_sum(array_column($datos, 'com_equipos'));
$total_com_sims = array_sum(array_column($datos, 'com_sims'));
$total_com_pospago = array_sum(array_column($datos, 'com_pospago'));
$total_comisiones = array_sum(array_column($datos, 'com_total'));
$total_global = array_sum(array_column($datos, 'total_pago'));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Nómina Gerentes de Zona</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>Reporte Nómina - Gerentes de Zona</h2>
    <p class="text-muted">Semana: <?= $fechaInicio ?> al <?= $fechaFin ?></p>

    <?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($_GET['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

    <!-- Selector de semana -->
    <form method="GET" class="mb-4 card card-body shadow-sm">
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="semana">Seleccionar Semana</label>
                <select name="semana" id="semana" class="form-select" onchange="this.form.submit()">
                    <?php for($i=0;$i<8;$i++):
                        list($ini,$fin) = obtenerSemanaPorIndice($i);
                        $txt = "Del {$ini->format('d/m/Y')} al {$fin->format('d/m/Y')}";
                    ?>
                    <option value="<?= $i ?>" <?= $i==$semanaSeleccionada?'selected':'' ?>>
                        <?= $txt ?>
                    </option>
                    <?php endfor; ?>
                </select>
            </div>
        </div>
    </form>

    <table class="table table-striped table-bordered text-center align-middle">
        <thead class="table-dark">
            <tr>
                <th>Gerente</th>
                <th>Zona</th>
                <th>Sueldo Base</th>
                <th>Com. Equipos</th>
                <th>Com. SIMs</th>
                <th>Com. Pospago</th>
                <th>Total Comisión</th>
                <th>Total a Pagar</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($datos as $d): ?>
            <tr>
                <td><?= $d['gerente'] ?></td>
                <td><?= $d['zona'] ?></td>
                <td>$<?= number_format($d['sueldo'],2) ?></td>
                <td>$<?= number_format($d['com_equipos'],2) ?></td>
                <td>$<?= number_format($d['com_sims'],2) ?></td>
                <td>$<?= number_format($d['com_pospago'],2) ?></td>
                <td>$<?= number_format($d['com_total'],2) ?></td>
                <td><strong>$<?= number_format($d['total_pago'],2) ?></strong></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot class="table-secondary">
            <tr>
                <th colspan="2">Totales</th>
                <th>$<?= number_format($total_sueldos,2) ?></th>
                <th>$<?= number_format($total_com_equipos,2) ?></th>
                <th>$<?= number_format($total_com_sims,2) ?></th>
                <th>$<?= number_format($total_com_pospago,2) ?></th>
                <th>$<?= number_format($total_comisiones,2) ?></th>
                <th>$<?= number_format($total_global,2) ?></th>
            </tr>
        </tfoot>
    </table>

    <div class="text-end mb-3">
    <form action="recalcular_comisiones_gerentes_zona.php" method="POST" class="d-inline">
        <input type="hidden" name="fecha_inicio" value="<?= $fechaInicio ?>">
        <input type="hidden" name="semana" value="<?= $semanaSeleccionada ?>">
        <button type="submit" class="btn btn-warning"
            onclick="return confirm('¿Seguro que deseas recalcular las comisiones de esta semana?\nEsto reemplazará los valores actuales con los más recientes.');">
            🔄 Recalcular Semana
        </button>
    </form>

    <a href="exportar_nomina_gerentes_excel.php?semana=<?= $fechaInicio ?>" class="btn btn-success ms-2">
        📄 Exportar a Excel
    </a>
</div>
</div>

</body>
</html>
