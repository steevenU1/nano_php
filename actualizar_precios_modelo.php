<?php
session_start();
if(!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin'){
    header("Location: 403.php");
    exit();
}

include 'db.php';
include 'navbar.php';

$mensaje = "";

/* -------------------------------------------------
   Helper: asegurar que precios_combo tenga comision_ma
-------------------------------------------------- */
function ensureComisionMA(mysqli $conn){
    $has = false;
    if ($res = $conn->query("SHOW COLUMNS FROM precios_combo LIKE 'comision_ma'")) {
        $has = ($res->num_rows > 0);
        $res->close();
    }
    if (!$has) {
        // Intenta crear la columna de forma tolerante
        try {
            $conn->query("ALTER TABLE precios_combo ADD COLUMN comision_ma DECIMAL(10,2) NULL DEFAULT NULL");
        } catch (Throwable $e) {
            // Si falla por cualquier razón, seguimos sin romper la página
        }
    }
}
ensureComisionMA($conn);

// 🔹 Procesar formulario de actualización
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $modeloCapacidad  = $_POST['modelo'] ?? '';
    $nuevoPrecioLista = isset($_POST['precio_lista']) && $_POST['precio_lista'] !== '' ? floatval($_POST['precio_lista']) : null;
    $nuevoPrecioCombo = isset($_POST['precio_combo']) && $_POST['precio_combo'] !== '' ? floatval($_POST['precio_combo']) : null;

    $promocionTexto   = trim($_POST['promocion'] ?? '');
    $quitarPromo      = isset($_POST['limpiar_promocion']); // si viene marcado, borraremos la promo (NULL)

    // NUEVO: Comisión para Master Admin
    $nuevaComisionMA  = isset($_POST['comision_ma']) && $_POST['comision_ma'] !== '' ? floatval($_POST['comision_ma']) : null;

    if($modeloCapacidad){
        list($marca, $modelo, $capacidad) = explode('|', $modeloCapacidad);

        // 1) Actualizar precio de lista en productos (para items Disponibles / En tránsito)
        if ($nuevoPrecioLista !== null && $nuevoPrecioLista > 0){
            $sql = "
                UPDATE productos p
                INNER JOIN inventario i ON i.id_producto = p.id
                SET p.precio_lista = ?
                WHERE p.marca = ? AND p.modelo = ? AND (p.capacidad = ? OR IFNULL(p.capacidad,'') = ?)
                  AND TRIM(i.estatus) IN ('Disponible','En tránsito')
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("dssss", $nuevoPrecioLista, $marca, $modelo, $capacidad, $capacidad);
            $stmt->execute();
            $afectados = $stmt->affected_rows;
            $stmt->close();
            $mensaje .= "✅ Precio de lista actualizado a $" . number_format($nuevoPrecioLista,2) . " ({$afectados} registros).<br>";
        }

        /* 2) Upsert en precios_combo (precio combo, promoción y comisión MA)
              Ejecutar si:
              - viene un precio_combo válido (>0)  ó
              - viene un texto de promoción           ó
              - se pide limpiar la promoción          ó
              - viene una comisión MA (>= 0)
        */
        $disparaUpsert =
            ($nuevoPrecioCombo !== null && $nuevoPrecioCombo > 0) ||
            ($promocionTexto !== '') ||
            $quitarPromo ||
            ($nuevaComisionMA !== null && $nuevaComisionMA >= 0);

        if ($disparaUpsert){
            // Parametrizar valores (NULL conserva el existente en ON DUPLICATE con COALESCE)
            $precioComboParam = ($nuevoPrecioCombo !== null && $nuevoPrecioCombo > 0) ? $nuevoPrecioCombo : null;
            $promocionParam   = $quitarPromo ? null : ($promocionTexto !== '' ? $promocionTexto : null);
            $comisionMAParam  = ($nuevaComisionMA !== null && $nuevaComisionMA >= 0) ? $nuevaComisionMA : null;

            // Intentar incluir comision_ma en el upsert. Si no existe, ensureComisionMA ya la intentó crear.
            $tieneColumna = false;
            if ($res = $conn->query("SHOW COLUMNS FROM precios_combo LIKE 'comision_ma'")) {
                $tieneColumna = ($res->num_rows > 0);
                $res->close();
            }

            if ($tieneColumna) {
                $sql = "
                    INSERT INTO precios_combo (marca, modelo, capacidad, precio_combo, promocion, comision_ma)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        precio_combo = COALESCE(VALUES(precio_combo), precio_combo),
                        promocion    = VALUES(promocion),
                        comision_ma  = COALESCE(VALUES(comision_ma), comision_ma)
                ";
                $stmt = $conn->prepare($sql);
                // types: s s s d s d
                $stmt->bind_param("sssdsd", $marca, $modelo, $capacidad, $precioComboParam, $promocionParam, $comisionMAParam);
            } else {
                // Respaldo (por si la columna no quedó creada): guarda sin comision_ma
                $sql = "
                    INSERT INTO precios_combo (marca, modelo, capacidad, precio_combo, promocion)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        precio_combo = COALESCE(VALUES(precio_combo), precio_combo),
                        promocion    = VALUES(promocion)
                ";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssds", $marca, $modelo, $capacidad, $precioComboParam, $promocionParam);
            }

            $stmt->execute();
            $stmt->close();

            if ($nuevoPrecioCombo !== null && $nuevoPrecioCombo > 0) {
                $mensaje .= "✅ Precio combo actualizado a $" . number_format($nuevoPrecioCombo,2) . ".<br>";
            }
            if ($quitarPromo) {
                $mensaje .= "🧹 Promoción eliminada.<br>";
            } elseif ($promocionTexto !== '') {
                $mensaje .= "✅ Promoción guardada: <i>".htmlspecialchars($promocionTexto)."</i>.<br>";
            }
            if ($nuevaComisionMA !== null && $nuevaComisionMA >= 0) {
                $mensaje .= "✅ Comisión MA guardada: $" . number_format($nuevaComisionMA,2) . ".<br>";
            }
        }

        if ($mensaje === "") {
            $mensaje = "⚠️ No enviaste cambios: captura un precio, promoción o comisión MA.";
        }

    } else {
        $mensaje = "⚠️ Selecciona un modelo válido.";
    }
}

// 🔹 Obtener modelos únicos de productos con inventario disponible o en tránsito
$modelos = $conn->query("
    SELECT 
        p.marca, 
        p.modelo, 
        IFNULL(p.capacidad,'') AS capacidad
    FROM productos p
    WHERE p.tipo_producto = 'Equipo'
      AND p.id IN (
            SELECT DISTINCT i.id_producto
            FROM inventario i
            WHERE TRIM(i.estatus) IN ('Disponible','En tránsito')
      )
    GROUP BY p.marca, p.modelo, p.capacidad
    ORDER BY LOWER(p.marca), LOWER(p.modelo), LOWER(p.capacidad)
");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Actualizar Precios por Modelo</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="icon" type="image/x-icon" href="./img/favicon.ico">
</head>
<body class="bg-light">

<div class="container mt-4">
    <h2>💰 Actualizar Precios por Modelo</h2>
    <p>Selecciona un modelo y asigna nuevos precios. Afecta equipos <b>Disponibles</b> o <b>En tránsito</b>.</p>

    <?php if($mensaje): ?>
        <div class="alert alert-info"><?= $mensaje ?></div>
    <?php endif; ?>

    <form method="POST" class="card p-3 shadow-sm bg-white" style="max-width:720px;">
        <div class="mb-3">
            <label class="form-label">Modelo y Capacidad</label>
            <select name="modelo" class="form-select" required>
                <option value="">Seleccione un modelo...</option>
                <?php while($m = $modelos->fetch_assoc()): 
                    $valor = $m['marca'].'|'.$m['modelo'].'|'.$m['capacidad'];
                    $texto = trim($m['marca'].' '.$m['modelo'].' '.$m['capacidad']);
                ?>
                <option value="<?= htmlspecialchars($valor) ?>"><?= htmlspecialchars($texto) ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="row">
          <div class="col-md-4 mb-3">
              <label class="form-label">Nuevo Precio de Lista ($)</label>
              <input type="number" step="0.01" name="precio_lista" class="form-control" placeholder="Ej. 2500.00">
              <div class="form-text">Déjalo en blanco si no deseas cambiarlo.</div>
          </div>

          <div class="col-md-4 mb-3">
              <label class="form-label">Nuevo Precio Combo ($)</label>
              <input type="number" step="0.01" name="precio_combo" class="form-control" placeholder="Ej. 2199.00">
              <div class="form-text">Déjalo en blanco para conservar el combo actual.</div>
          </div>

          <div class="col-md-4 mb-3">
              <label class="form-label">Comisión MA ($)</label>
              <input type="number" step="0.01" name="comision_ma" class="form-control" placeholder="Ej. 150.00">
              <div class="form-text">Comisión para Master Admin. Déjalo en blanco para no cambiarla.</div>
          </div>
        </div>

        <div class="mb-3">
            <label class="form-label">Promoción (texto informativo)</label>
            <input type="text" name="promocion" class="form-control" placeholder="Ej. Descuento $500 en enganche / Incentivo portabilidad">
            <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" name="limpiar_promocion" id="limpiar_promocion">
                <label class="form-check-label" for="limpiar_promocion">Quitar promoción (dejar en blanco/NULL)</label>
            </div>
            <div class="form-text">Puedes guardar promoción sin cambiar el precio combo. Marca “Quitar promoción” para borrar el texto.</div>
        </div>

        <div class="d-flex gap-2">
            <button class="btn btn-primary">Actualizar</button>
            <a href="lista_precios.php" class="btn btn-secondary">Ver Lista</a>
        </div>
    </form>
</div>

</body>
</html>
