<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Admin','RH'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

/* ========================
   Funciones auxiliares
======================== */
function obtenerSemanaPorIndice($offset = 0) {
    $hoy = new DateTime();
    $diaSemana = $hoy->format('N'); // 1=Lunes ... 7=Domingo
    $dif = $diaSemana - 2; // Martes=2
    if ($dif < 0) $dif += 7;

    $inicio = new DateTime();
    $inicio->modify("-$dif days")->setTime(0,0,0);
    if ($offset > 0) $inicio->modify("-" . (7*$offset) . " days");

    $fin = clone $inicio;
    $fin->modify("+6 days")->setTime(23,59,59);

    return [$inicio, $fin];
}

$semanaSeleccionada = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
list($inicioSemanaObj, $finSemanaObj) = obtenerSemanaPorIndice($semanaSeleccionada);
$inicioSemana = $inicioSemanaObj->format('Y-m-d 00:00:00');
$finSemana    = $finSemanaObj->format('Y-m-d 23:59:59');

/* ========================
   Configuración CSV
======================== */
$filename = "nomina_semana_" . $semanaSeleccionada . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
$output = fopen('php://output', 'w');

/* ========================
   ENCABEZADO RESUMEN
======================== */
fputcsv($output, ['REPORTE DE NÓMINA SEMANAL']);
fputcsv($output, ['Semana', $inicioSemanaObj->format('d/m/Y') . ' - ' . $finSemanaObj->format('d/m/Y')]);
fputcsv($output, []); // línea en blanco

// Encabezados resumen por empleado (incluye conteos)
fputcsv($output, [
    'Empleado', 'Rol', 'Sucursal',
    'Sueldo Base',
    '# Equipos', 'Com. Equipos',
    '# SIMs',   'Com. SIMs',
    '# Pospago','Com. Pospago',
    'Com. Gerente',
    'Total a Pagar'
]);

$totalGlobal = 0.0;

/* ========================
   Consulta de usuarios (excluye almacén)
======================== */
$sqlUsuarios = "
    SELECT u.id, u.nombre, u.rol, u.sueldo, s.nombre AS sucursal, u.id_sucursal
    FROM usuarios u
    INNER JOIN sucursales s ON s.id=u.id_sucursal
    WHERE s.tipo_sucursal <> 'Almacen'
    ORDER BY s.nombre, FIELD(u.rol,'Gerente','Ejecutivo'), u.nombre
";
$resUsuarios = $conn->query($sqlUsuarios);

/* Guardaremos el detalle para escribirlo al final */
$detalleFilas = []; // cada elemento es una fila detalla por venta/rubro

while ($u = $resUsuarios->fetch_assoc()) {
    $id_usuario   = (int)$u['id'];
    $id_sucursal  = (int)$u['id_sucursal'];
    $rol          = $u['rol'];

    /* ========================
       1) EQUIPOS (ejecutivo)
       Conteo por líneas y suma de comisiones de detalle_venta
======================== */
    $sqlEquiposTot = "
        SELECT 
            COUNT(dv.id) AS cnt,
            SUM(dv.comision_regular + dv.comision_especial) AS com_tot
        FROM detalle_venta dv
        INNER JOIN ventas v  ON dv.id_venta=v.id
        INNER JOIN productos p ON dv.id_producto=p.id
        WHERE v.id_usuario=?
          AND v.fecha_venta BETWEEN ? AND ?
          AND LOWER(p.tipo_producto) NOT IN ('sim','chip','pospago')
    ";
    $stmtE1 = $conn->prepare($sqlEquiposTot);
    $stmtE1->bind_param("iss", $id_usuario, $inicioSemana, $finSemana);
    $stmtE1->execute();
    $rowE1 = $stmtE1->get_result()->fetch_assoc() ?: [];
    $equipos_cnt = (int)($rowE1['cnt'] ?? 0);
    $equipos_com = (float)($rowE1['com_tot'] ?? 0);

    // Detalle por línea de equipo
    $sqlEquiposDet = "
        SELECT 
            v.id AS venta_id,
            v.fecha_venta,
            p.marca, p.modelo, p.color, p.imei1, p.tipo_producto,
            dv.precio_unitario,
            dv.comision_regular,
            dv.comision_especial,
            (dv.comision_regular + dv.comision_especial) AS com_total_linea
        FROM detalle_venta dv
        INNER JOIN ventas v     ON dv.id_venta=v.id
        INNER JOIN productos p  ON dv.id_producto=p.id
        WHERE v.id_usuario=?
          AND v.fecha_venta BETWEEN ? AND ?
          AND LOWER(p.tipo_producto) NOT IN ('sim','chip','pospago')
        ORDER BY v.fecha_venta, v.id, dv.id
    ";
    $stmtE2 = $conn->prepare($sqlEquiposDet);
    $stmtE2->bind_param("iss", $id_usuario, $inicioSemana, $finSemana);
    $stmtE2->execute();
    $resE2 = $stmtE2->get_result();
    while ($d = $resE2->fetch_assoc()) {
        $detalleFilas[] = [
            $u['nombre'],
            $rol,
            $u['sucursal'],
            'Equipo',
            $d['venta_id'],
            (new DateTime($d['fecha_venta']))->format('Y-m-d H:i:s'),
            trim(($d['marca'] ?? '') . ' ' . ($d['modelo'] ?? '') . ' ' . ($d['color'] ?? '')),
            $d['imei1'],
            number_format((float)$d['precio_unitario'], 2, '.', ''),
            number_format((float)$d['comision_regular'], 2, '.', ''),
            number_format((float)$d['comision_especial'], 2, '.', ''),
            number_format((float)$d['com_total_linea'], 2, '.', ''),
            '' // Comisión Gerente (venta) — esta columna se usa sólo en filas de gerente
        ];
    }

    /* ========================
       2) SIMs PREPAGO (ejecutivo) — usa ventas_sims.comision_ejecutivo
======================== */
    $sims_cnt = 0; $sims_com = 0.0;

    if ($rol != 'Gerente') {
        $sqlSimsTot = "
            SELECT 
                COUNT(vs.id) AS cnt,
                SUM(vs.comision_ejecutivo) AS com_sims
            FROM ventas_sims vs
            WHERE vs.id_usuario=?
              AND vs.fecha_venta BETWEEN ? AND ?
              AND vs.tipo_venta IN ('Nueva','Portabilidad')
        ";
        $stmtS1 = $conn->prepare($sqlSimsTot);
        $stmtS1->bind_param("iss", $id_usuario, $inicioSemana, $finSemana);
        $stmtS1->execute();
        $rowS1 = $stmtS1->get_result()->fetch_assoc() ?: [];
        $sims_cnt = (int)($rowS1['cnt'] ?? 0);
        $sims_com = (float)($rowS1['com_sims'] ?? 0);

        // Detalle SIMs prepago (una fila por venta_sims)
        $sqlSimsDet = "
            SELECT 
                vs.id AS venta_id,
                vs.fecha_venta,
                vs.tipo_sim,
                vs.tipo_venta,
                vs.precio_total,
                vs.comision_ejecutivo
            FROM ventas_sims vs
            WHERE vs.id_usuario=?
              AND vs.fecha_venta BETWEEN ? AND ?
              AND vs.tipo_venta IN ('Nueva','Portabilidad')
            ORDER BY vs.fecha_venta, vs.id
        ";
        $stmtS2 = $conn->prepare($sqlSimsDet);
        $stmtS2->bind_param("iss", $id_usuario, $inicioSemana, $finSemana);
        $stmtS2->execute();
        $resS2 = $stmtS2->get_result();
        while ($d = $resS2->fetch_assoc()) {
            $detalleFilas[] = [
                $u['nombre'],
                $rol,
                $u['sucursal'],
                'SIM (' . $d['tipo_venta'] . ')',
                $d['venta_id'],
                (new DateTime($d['fecha_venta']))->format('Y-m-d H:i:s'),
                'SIM ' . $d['tipo_sim'],
                '', // IMEI no aplica
                number_format((float)$d['precio_total'], 2, '.', ''),
                number_format(0, 2, '.', ''), // com_regular no aplica
                number_format(0, 2, '.', ''), // com_especial no aplica
                number_format((float)$d['comision_ejecutivo'], 2, '.', ''), // comisión total rubro
                '' // com gerente (venta) no aplica
            ];
        }
    }

    /* ========================
       3) POSPAGO (ejecutivo) — usa ventas_sims.comision_ejecutivo
======================== */
    $pos_cnt = 0; $pos_com = 0.0;

    if ($rol != 'Gerente') {
        $sqlPosTot = "
            SELECT 
                COUNT(vs.id) AS cnt,
                SUM(vs.comision_ejecutivo) AS com_pos
            FROM ventas_sims vs
            WHERE vs.id_usuario=?
              AND vs.fecha_venta BETWEEN ? AND ?
              AND vs.tipo_venta='Pospago'
        ";
        $stmtP1 = $conn->prepare($sqlPosTot);
        $stmtP1->bind_param("iss", $id_usuario, $inicioSemana, $finSemana);
        $stmtP1->execute();
        $rowP1 = $stmtP1->get_result()->fetch_assoc() ?: [];
        $pos_cnt = (int)($rowP1['cnt'] ?? 0);
        $pos_com = (float)($rowP1['com_pos'] ?? 0);

        // Detalle pospago (una fila por venta_sims)
        $sqlPosDet = "
            SELECT 
                vs.id AS venta_id,
                vs.fecha_venta,
                vs.modalidad,
                vs.precio_total,
                vs.comision_ejecutivo
            FROM ventas_sims vs
            WHERE vs.id_usuario=?
              AND vs.fecha_venta BETWEEN ? AND ?
              AND vs.tipo_venta='Pospago'
            ORDER BY vs.fecha_venta, vs.id
        ";
        $stmtP2 = $conn->prepare($sqlPosDet);
        $stmtP2->bind_param("iss", $id_usuario, $inicioSemana, $finSemana);
        $stmtP2->execute();
        $resP2 = $stmtP2->get_result();
        while ($d = $resP2->fetch_assoc()) {
            $detalleFilas[] = [
                $u['nombre'],
                $rol,
                $u['sucursal'],
                'Pospago',
                $d['venta_id'],
                (new DateTime($d['fecha_venta']))->format('Y-m-d H:i:s'),
                'Pospago ' . $d['modalidad'],
                '', // IMEI no aplica
                number_format((float)$d['precio_total'], 2, '.', ''),
                number_format(0, 2, '.', ''),
                number_format(0, 2, '.', ''),
                number_format((float)$d['comision_ejecutivo'], 2, '.', ''),
                ''
            ];
        }
    }

    /* ========================
       4) GERENTE (por sucursal): ventas + ventas_sims
======================== */
    $com_ger = 0.0;
    if ($rol == 'Gerente') {
        // Ventas con comision_gerente
        $sqlGerTotV = "
            SELECT IFNULL(SUM(v.comision_gerente),0) AS com_ger_vtas
            FROM ventas v
            WHERE v.id_sucursal=? 
              AND v.fecha_venta BETWEEN ? AND ?
        ";
        $stmtG1 = $conn->prepare($sqlGerTotV);
        $stmtG1->bind_param("iss", $id_sucursal, $inicioSemana, $finSemana);
        $stmtG1->execute();
        $com_ger_vtas = (float)($stmtG1->get_result()->fetch_assoc()['com_ger_vtas'] ?? 0);

        // Detalle por venta (ventas)
        $sqlGerDetV = "
            SELECT v.id AS venta_id, v.fecha_venta, v.comision_gerente
            FROM ventas v
            WHERE v.id_sucursal=?
              AND v.fecha_venta BETWEEN ? AND ?
              AND v.comision_gerente > 0
            ORDER BY v.fecha_venta, v.id
        ";
        $stmtG2 = $conn->prepare($sqlGerDetV);
        $stmtG2->bind_param("iss", $id_sucursal, $inicioSemana, $finSemana);
        $stmtG2->execute();
        $resG2 = $stmtG2->get_result();
        while ($d = $resG2->fetch_assoc()) {
            $detalleFilas[] = [
                $u['nombre'],
                $rol,
                $u['sucursal'],
                'Gerente (venta sucursal - Equipos/MiFi/Modem)',
                $d['venta_id'],
                (new DateTime($d['fecha_venta']))->format('Y-m-d H:i:s'),
                'Comisión gerente por venta (ventas)',
                '',
                number_format(0, 2, '.', ''), // no aplica
                number_format(0, 2, '.', ''), // no aplica
                number_format(0, 2, '.', ''), // no aplica
                number_format(0, 2, '.', ''), // total rubro no aplica
                number_format((float)$d['comision_gerente'], 2, '.', '')
            ];
        }

        // SIMs (prepago y pospago) con comision_gerente
        $sqlGerTotS = "
            SELECT IFNULL(SUM(vs.comision_gerente),0) AS com_ger_sims
            FROM ventas_sims vs
            WHERE vs.id_sucursal=? 
              AND vs.fecha_venta BETWEEN ? AND ?
        ";
        $stmtG3 = $conn->prepare($sqlGerTotS);
        $stmtG3->bind_param("iss", $id_sucursal, $inicioSemana, $finSemana);
        $stmtG3->execute();
        $com_ger_sims = (float)($stmtG3->get_result()->fetch_assoc()['com_ger_sims'] ?? 0);

        // Detalle por venta_sims
        $sqlGerDetS = "
            SELECT vs.id AS venta_id, vs.fecha_venta, vs.tipo_venta, vs.comision_gerente
            FROM ventas_sims vs
            WHERE vs.id_sucursal=?
              AND vs.fecha_venta BETWEEN ? AND ?
              AND vs.comision_gerente > 0
            ORDER BY vs.fecha_venta, vs.id
        ";
        $stmtG4 = $conn->prepare($sqlGerDetS);
        $stmtG4->bind_param("iss", $id_sucursal, $inicioSemana, $finSemana);
        $stmtG4->execute();
        $resG4 = $stmtG4->get_result();
        while ($d = $resG4->fetch_assoc()) {
            $detalleFilas[] = [
                $u['nombre'],
                $rol,
                $u['sucursal'],
                'Gerente (venta sucursal - ' . $d['tipo_venta'] . ')',
                $d['venta_id'],
                (new DateTime($d['fecha_venta']))->format('Y-m-d H:i:s'),
                'Comisión gerente por venta (ventas_sims)',
                '',
                number_format(0, 2, '.', ''), // no aplica
                number_format(0, 2, '.', ''), // no aplica
                number_format(0, 2, '.', ''), // no aplica
                number_format(0, 2, '.', ''), // total rubro no aplica
                number_format((float)$d['comision_gerente'], 2, '.', '')
            ];
        }

        $com_ger = $com_ger_vtas + $com_ger_sims;
    }

    /* ========================
       5) TOTAL empleado y fila RESUMEN
======================== */
    $total = (float)$u['sueldo'] + $equipos_com + $sims_com + $pos_com + $com_ger;
    $totalGlobal += $total;

    fputcsv($output, [
        $u['nombre'],
        $rol,
        $u['sucursal'],
        number_format((float)$u['sueldo'], 2, '.', ''),
        $equipos_cnt,
        number_format($equipos_com, 2, '.', ''),
        $sims_cnt,
        number_format($sims_com, 2, '.', ''),
        $pos_cnt,
        number_format($pos_com, 2, '.', ''),
        number_format($com_ger, 2, '.', ''),
        number_format($total, 2, '.', '')
    ]);
}

/* ========================
   Fila de total global
======================== */
fputcsv($output, []);
fputcsv($output, ['TOTAL GLOBAL', '', '', '', '', '', '', '', '', '', '', number_format($totalGlobal,2,'.','')]);

/* ========================
   Sección DETALLE POR VENTA
======================== */
fputcsv($output, []); // línea en blanco
fputcsv($output, ['DETALLE POR VENTA (Comisiones pagadas en la semana)']);
fputcsv($output, [
    'Empleado', 'Rol', 'Sucursal',
    'Tipo', 'ID Venta', 'Fecha/Hora',
    'Producto/Concepto', 'IMEI',
    'Precio Unitario',
    'Com. Regular', 'Com. Especial',
    'Comisión Total (rubro)',     // Equipos / SIM / Pospago
    'Comisión Gerente (venta)'    // cuando aplica
]);

foreach ($detalleFilas as $fila) {
    fputcsv($output, $fila);
}

fclose($output);
exit();
