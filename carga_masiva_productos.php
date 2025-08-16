<?php
// carga_masiva_productos.php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: 403.php");
    exit();
}

// Mostrar errores de PHP/Mysqli (útil en desarrollo)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include 'db.php';

$msg = '';
$alertType = 'info';
$previewData = [];
$reportLink = '';
$insertadas = 0;
$ignoradas  = 0;

/*
CSV ESPERADO (13 columnas):
Codigo_Producto, Marca, Modelo, Color, Capacidad, IMEI1, IMEI2, Costo, Precio_Lista,
Tipo_Producto, Fecha_Ingreso(YYYY-MM-DD|DD/MM/YYYY), Sucursal, Proveedor(opcional)
*/

// ---------- Utilidades ----------
function normalizaNumero($v) {
    $v = trim((string)$v);
    if ($v === '') return null;
    // 1.234,56 -> 1234.56
    if (preg_match('/^\d{1,3}(\.\d{3})*,\d+$/', $v)) {
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
    } else {
        // 1,234.56 -> 1234.56
        $v = str_replace(',', '', $v);
    }
    return is_numeric($v) ? (float)$v : null;
}

/**
 * Convierte varias entradas a 'YYYY-MM-DD'.
 * Acepta: DD/MM/YYYY, DD-MM-YYYY, YYYY-MM-DD, YYYY/MM/DD.
 * Devuelve string YYYY-MM-DD o null si es inválida.
 */
function normalizaFechaISO($s) {
    $s = trim((string)$s);
    if ($s === '') return null;

    // Reemplazos para unificar separadores
    $s = str_replace('.', '/', $s);
    $s = str_replace('-', '/', $s);

    // DD/MM/YYYY (o D/M/YY)
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/', $s, $m)) {
        $d = (int)$m[1];
        $mth = (int)$m[2];
        $y = (int)$m[3];
        if ($y < 100) $y += 2000; // 25 -> 2025
        if (checkdate($mth, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mth, $d);
        }
        return null;
    }

    // YYYY/MM/DD
    if (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $s, $m)) {
        $y = (int)$m[1];
        $mth = (int)$m[2];
        $d = (int)$m[3];
        if (checkdate($mth, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mth, $d);
        }
        return null;
    }

    // Fallback: strtotime
    $ts = strtotime($s);
    if ($ts !== false) return date('Y-m-d', $ts);

    return null;
}

// ============================================
// 🔹 Paso 1: Vista previa del CSV
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && (($_POST['action'] ?? '') === 'preview') && isset($_FILES['archivo'])) {
    $archivoTmp = $_FILES['archivo']['tmp_name'];
    if (($handle = fopen($archivoTmp, 'r')) !== FALSE) {
        $fila = 0;
        while (($data = fgetcsv($handle, 0, ",")) !== FALSE) {
            $fila++;
            if ($fila == 1) continue; // Saltar encabezado

            // Esperamos 13 columnas (la última es opcional)
            list($codigo_producto, $marca, $modelo, $color, $capacidad, $imei1, $imei2, $costo, $precio_lista, $tipo_producto, $fecha_ingreso, $sucursal_nombre, $proveedor)
                = array_pad($data, 13, '');

            $codigo_producto = trim($codigo_producto);
            $marca           = trim($marca);
            $modelo          = trim($modelo);
            $color           = trim($color);
            $capacidad       = trim($capacidad);

            // IMEIs solo dígitos
            $imei1 = preg_replace('/\D+/', '', (string)$imei1);
            $imei2 = preg_replace('/\D+/', '', (string)$imei2);

            $costo        = normalizaNumero($costo);
            $precio_lista = normalizaNumero($precio_lista);

            $tipo_producto   = ucfirst(strtolower(trim($tipo_producto))) ?: 'Equipo';
            if ($tipo_producto === 'Módem') $tipo_producto = 'Modem';
            $sucursal_nombre = trim($sucursal_nombre);
            $proveedor       = trim($proveedor);
            if ($proveedor !== '') $proveedor = mb_substr($proveedor, 0, 120, 'UTF-8');

            // Fecha → ISO
            $fechaISO = normalizaFechaISO($fecha_ingreso);
            if ($fechaISO === null) {
                // Si viene vacía, usa hoy; si viene mal formada, la marcamos inválida
                $fechaISO = ($fecha_ingreso === '') ? date('Y-m-d') : null;
            }

            // Tipos permitidos
            $tipos_validos = ['Equipo','Modem','Accesorio'];
            if (!in_array($tipo_producto, $tipos_validos)) {
                $tipo_producto = 'Equipo';
            }

            // Buscar ID de sucursal
            $idSucursal = null;
            if ($sucursal_nombre) {
                $stmtSuc = $conn->prepare("SELECT id FROM sucursales WHERE nombre=? LIMIT 1");
                $stmtSuc->bind_param("s", $sucursal_nombre);
                $stmtSuc->execute();
                $resSuc = $stmtSuc->get_result()->fetch_assoc();
                $idSucursal = $resSuc['id'] ?? null;
                $stmtSuc->close();
            }

            $estatus = 'OK';
            $motivo  = 'Listo para insertar';

            if ($codigo_producto === '') {
                $estatus = 'Ignorada';
                $motivo  = 'codigo_producto vacío';
            }
            if (!$idSucursal) {
                $estatus = 'Ignorada';
                $motivo  = 'Sucursal no encontrada';
            }
            if ($fechaISO === null) {
                $estatus = 'Ignorada';
                $motivo  = 'Fecha_Ingreso inválida';
            }
            if (!$imei1) {
                $estatus = 'Ignorada';
                $motivo  = 'IMEI1 vacío';
            } else {
                // Duplicado por IMEI
                $sqlDup = "SELECT id FROM productos WHERE TRIM(imei1)=? OR TRIM(imei2)=? LIMIT 1";
                $stmt = $conn->prepare($sqlDup);
                $imei2Check = ($imei2 !== '') ? $imei2 : $imei1; // evita null
                $stmt->bind_param("ss", $imei1, $imei2Check);
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows > 0) {
                    $estatus = 'Ignorada';
                    $motivo  = 'Duplicado en base (IMEI)';
                }
                $stmt->close();
            }

            if ($estatus === 'OK' && $costo === null)        { $estatus='Ignorada'; $motivo='Costo inválido'; }
            if ($estatus === 'OK' && $precio_lista === null) { $estatus='Ignorada'; $motivo='Precio_Lista inválido'; }

            $previewData[] = [
                'codigo_producto'=> $codigo_producto,
                'marca'         => $marca,
                'modelo'        => $modelo,
                'color'         => $color,
                'capacidad'     => $capacidad,
                'imei1'         => $imei1,
                'imei2'         => $imei2,
                'costo'         => $costo,
                'precio_lista'  => $precio_lista,
                'tipo_producto' => $tipo_producto,
                'fecha_ingreso' => $fechaISO ?: '',  // guardamos normalizada
                'sucursal'      => $sucursal_nombre,
                'id_sucursal'   => $idSucursal,
                'proveedor'     => $proveedor,
                'estatus'       => $estatus,
                'motivo'        => $motivo
            ];
        }
        fclose($handle);
    } else {
        $msg = "❌ Error al abrir el archivo CSV.";
    }
}

// ============================================
// 🔹 Paso 2: Confirmar e insertar (con notificación + link)
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && (($_POST['action'] ?? '') === 'insertar') && isset($_POST['data'])) {
    $data = json_decode(base64_decode($_POST['data']), true);

    // CSV de resultados
    $csvDir = __DIR__ . '/tmp';
    if (!is_dir($csvDir)) { @mkdir($csvDir, 0775, true); }
    $reportFile = 'reporte_carga_productos_' . date('Ymd_His') . '.csv';
    $reportPath = $csvDir . '/' . $reportFile;

    $output = fopen($reportPath, 'w');
    fputcsv($output, ['codigo_producto','marca','modelo','color','capacidad','imei1','imei2','sucursal','proveedor','estatus_final','motivo']);

    foreach ($data as $prod) {
        $estatusFinal = $prod['estatus'];
        $motivo = $prod['motivo'];

        if ($prod['estatus'] === 'OK') {
            $proveedor = isset($prod['proveedor']) && $prod['proveedor'] !== '' ? $prod['proveedor'] : null;

            // Inserta en productos (incluye codigo_producto)
            $sqlInsert = "INSERT INTO productos
                (codigo_producto, marca, modelo, color, capacidad, imei1, imei2, costo, proveedor, precio_lista, tipo_producto)
                VALUES (?,?,?,?,?,?,?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sqlInsert);
            $stmt->bind_param(
                "sssssssdsds",
                $prod['codigo_producto'], $prod['marca'], $prod['modelo'], $prod['color'], $prod['capacidad'],
                $prod['imei1'], $prod['imei2'], $prod['costo'],
                $proveedor,
                $prod['precio_lista'], $prod['tipo_producto']
            );

            try {
                if ($stmt->execute()) {
                    $idProducto = $stmt->insert_id;

                    // Nota: fecha_ingreso ya viene normalizada como YYYY-MM-DD
                    $stmtInv = $conn->prepare("INSERT INTO inventario (id_producto, id_sucursal, estatus, fecha_ingreso) VALUES (?, ?, 'Disponible', ?)");
                    $stmtInv->bind_param("iis", $idProducto, $prod['id_sucursal'], $prod['fecha_ingreso']);
                    $stmtInv->execute();
                    $stmtInv->close();

                    $estatusFinal = 'Insertada';
                    $motivo = 'OK';
                    $insertadas++;
                } else {
                    $estatusFinal = 'Ignorada';
                    $motivo = 'Error en inserción';
                    $ignoradas++;
                }
            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() == 1062) {
                    $estatusFinal = 'Ignorada';
                    $motivo = 'Duplicado (índice único BD)';
                } else {
                    $estatusFinal = 'Ignorada';
                    $motivo = 'Error BD: ' . $e->getMessage();
                }
                $ignoradas++;
            }
            $stmt->close();
        } else {
            $ignoradas++;
        }

        fputcsv($output, [
            $prod['codigo_producto'],
            $prod['marca'],
            $prod['modelo'],
            $prod['color'],
            $prod['capacidad'],
            $prod['imei1'],
            $prod['imei2'],
            $prod['sucursal'],
            $prod['proveedor'] ?? '',
            $estatusFinal,
            $motivo
        ]);
    }

    fclose($output);

    $alertType = 'success';
    $msg = "✅ Carga completada. <b>$insertadas</b> insertadas, <b>$ignoradas</b> ignoradas. "
         . "Descarga el reporte para detalles.";
    $reportLink = 'tmp/' . $reportFile;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Carga Masiva de Productos</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
      body{background:#f8fafc}
      h2{font-weight:700}
      .card-header{font-weight:600}
      .code-badge{font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;}
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>📥 Carga Masiva de Productos al Inventario</h2>
    <p>Sube un archivo <strong>CSV</strong> con 13 columnas (la última es opcional). La fecha puede venir como <code>YYYY-MM-DD</code> o <code>DD/MM/YYYY</code>.</p>
    <pre class="code-badge">Codigo_Producto, Marca, Modelo, Color, Capacidad, IMEI1, IMEI2, Costo, Precio_Lista, Tipo_Producto, Fecha_Ingreso, Sucursal, Proveedor</pre>

    <?php if($msg): ?>
      <div class="alert alert-<?= htmlspecialchars($alertType) ?> shadow-sm" role="alert">
        <?= $msg ?>
        <?php if($reportLink): ?>
          <div class="mt-2">
            <a class="btn btn-success btn-sm" href="<?= htmlspecialchars($reportLink) ?>" download>⬇️ Descargar CSV de resultados</a>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if(empty($previewData) && (($reportLink==='') && (($_POST['action'] ?? '') !== 'insertar'))): ?>
        <div class="card shadow mb-4">
            <div class="card-header bg-dark text-white">Subir archivo CSV</div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="preview">
                    <input type="file" name="archivo" accept=".csv" class="form-control mb-3" required>
                    <button type="submit" class="btn btn-primary">👀 Vista Previa</button>
                </form>
            </div>
        </div>
    <?php elseif(!empty($previewData) && (($_POST['action'] ?? '') === 'preview')): ?>
        <div class="card shadow p-3 mb-4 bg-white">
            <h5>Vista Previa</h5>
            <form method="POST">
                <input type="hidden" name="action" value="insertar">
                <input type="hidden" name="data" value='<?= base64_encode(json_encode($previewData)) ?>'>
                <div class="table-responsive">
                <table class="table table-bordered table-sm mt-3">
                    <thead class="table-light">
                        <tr>
                            <th>Código</th>
                            <th>Marca</th>
                            <th>Modelo</th>
                            <th>Color</th>
                            <th>Capacidad</th>
                            <th>IMEI1</th>
                            <th>IMEI2</th>
                            <th>Fecha Ingreso</th>
                            <th>Sucursal</th>
                            <th>Proveedor</th>
                            <th>Estatus</th>
                            <th>Motivo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($previewData as $p): ?>
                        <tr class="<?= $p['estatus']=='OK'?'':'table-warning' ?>">
                            <td><?= htmlspecialchars($p['codigo_producto']) ?></td>
                            <td><?= htmlspecialchars($p['marca']) ?></td>
                            <td><?= htmlspecialchars($p['modelo']) ?></td>
                            <td><?= htmlspecialchars($p['color']) ?></td>
                            <td><?= htmlspecialchars($p['capacidad']) ?></td>
                            <td><?= htmlspecialchars($p['imei1']) ?></td>
                            <td><?= htmlspecialchars($p['imei2']) ?></td>
                            <td><?= htmlspecialchars($p['fecha_ingreso']) ?></td>
                            <td><?= htmlspecialchars($p['sucursal']) ?></td>
                            <td><?= htmlspecialchars($p['proveedor'] ?? '') ?></td>
                            <td><?= htmlspecialchars($p['estatus']) ?></td>
                            <td><?= htmlspecialchars($p['motivo']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <button type="submit" class="btn btn-success mt-3">✅ Confirmar (guardará y mostrará notificación)</button>
            </form>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
