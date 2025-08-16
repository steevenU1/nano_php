<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Admin','Gerente General'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

// Obtener histórico de esquemas
$sql = "SELECT * FROM esquemas_comisiones_ejecutivos ORDER BY fecha_inicio DESC";
$res = $conn->query($sql);

// Determinar fecha vigente (último registro)
$vigente = $conn->query("SELECT fecha_inicio FROM esquemas_comisiones_ejecutivos ORDER BY fecha_inicio DESC LIMIT 1")->fetch_assoc()['fecha_inicio'] ?? null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Esquemas de Comisiones - Ejecutivos</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>📋 Esquemas de Comisiones - Ejecutivos</h2>
    <a href="nuevo_esquema_ejecutivos.php" class="btn btn-primary mb-3">➕ Nuevo Esquema</a>

    <table class="table table-bordered table-striped table-hover">
        <thead class="table-dark">
            <tr>
                <th>Fecha Inicio</th>
                <th>C sin / con</th>
                <th>B sin / con</th>
                <th>A sin / con</th>
                <th>MiFi sin / con</th>
                <th>Combo</th>
                <th>SIM Bait N/P</th>
                <th>SIM ATT N/P</th>
                <th>Pospago c/s eq</th>
                <th>Estado</th>
            </tr>
        </thead>
        <tbody>
            <?php while($row=$res->fetch_assoc()): 
                $fila = ($row['fecha_inicio']==$vigente) ? 'table-success' : '';
                $estado = ($row['fecha_inicio']==$vigente) ? 'Vigente ✅' : 'Histórico';
            ?>
            <tr class="<?= $fila ?>">
                <td><?= date("d/m/Y", strtotime($row['fecha_inicio'])) ?></td>
                <td>$<?= $row['comision_c_sin'] ?>/ $<?= $row['comision_c_con'] ?></td>
                <td>$<?= $row['comision_b_sin'] ?>/ $<?= $row['comision_b_con'] ?></td>
                <td>$<?= $row['comision_a_sin'] ?>/ $<?= $row['comision_a_con'] ?></td>
                <td>$<?= $row['comision_mifi_sin'] ?>/ $<?= $row['comision_mifi_con'] ?></td>
                <td>$<?= $row['comision_combo'] ?></td>
                <td>$<?= $row['comision_sim_bait_nueva_sin'] ?>/<?= $row['comision_sim_bait_port_sin'] ?> <br>
                    $<?= $row['comision_sim_bait_nueva_con'] ?>/<?= $row['comision_sim_bait_port_con'] ?></td>
                <td>$<?= $row['comision_sim_att_nueva_sin'] ?>/<?= $row['comision_sim_att_port_sin'] ?> <br>
                    $<?= $row['comision_sim_att_nueva_con'] ?>/<?= $row['comision_sim_att_port_con'] ?></td>
                <td>$<?= $row['comision_pos_con_equipo'] ?>/ $<?= $row['comision_pos_sin_equipo'] ?></td>
                <td><?= $estado ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

</body>
</html>
