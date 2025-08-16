<?php
// modelos.php - Catálogo de modelos (marca+modelo+color+ram+capacidad+codigo_producto)

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }
include 'db.php';
include 'navbar.php';


$ROL = $_SESSION['rol'] ?? 'Ejecutivo';
$permEscritura = in_array($ROL, ['Admin','Gerente']);

// Helpers
function texto($s,$n){ return substr(trim($s ?? ''), 0, $n); }
function esc($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

$mensaje = "";

// ===== Crear / editar =====
if ($permEscritura && $_SERVER['REQUEST_METHOD']==='POST') {
  $modo   = $_POST['modo'] ?? 'crear';
  $id     = (int)($_POST['id'] ?? 0);

  $marca  = texto($_POST['marca']  ?? '', 80);
  $modelo = texto($_POST['modelo'] ?? '', 80);
  $color  = texto($_POST['color']  ?? '', 50);
  $ram    = texto($_POST['ram']    ?? '', 50);
  $cap    = texto($_POST['capacidad'] ?? '', 50);
  $codigo = texto($_POST['codigo_producto'] ?? '', 50);

  if ($marca==='' || $modelo==='') {
    $mensaje = "<div class='alert alert-danger'>Marca y Modelo son obligatorios.</div>";
  } else {
    if ($modo==='editar' && $id>0) {
      $stmt = $conn->prepare("
        UPDATE catalogo_modelos
           SET marca=?, modelo=?, color=?, ram=?, capacidad=?, codigo_producto=?
         WHERE id=?
      ");
      if (!$stmt) {
        $mensaje = "<div class='alert alert-danger'>Error de preparación: ".$conn->error."</div>";
      } else {
        $stmt->bind_param("ssssssi", $marca,$modelo,$color,$ram,$cap,$codigo,$id);
        $ok = $stmt->execute();
        $errno = $stmt->errno; $stmt->close();

        if ($ok) {
          $mensaje = "<div class='alert alert-success'>Modelo actualizado.</div>";
        } else {
          // 1062 = Duplicado (índice único)
          if ($errno === 1062) {
            $mensaje = "<div class='alert alert-danger'>
              Ya existe un modelo con la misma combinación Marca + Modelo + Color + RAM + Capacidad
              o el Código de producto está duplicado.
            </div>";
          } else {
            $mensaje = "<div class='alert alert-danger'>Error al actualizar.</div>";
          }
        }
      }
    } else {
      $stmt = $conn->prepare("
        INSERT INTO catalogo_modelos (marca, modelo, color, ram, capacidad, codigo_producto, activo)
        VALUES (?,?,?,?,?,?,1)
      ");
      if (!$stmt) {
        $mensaje = "<div class='alert alert-danger'>Error de preparación: ".$conn->error."</div>";
      } else {
        $stmt->bind_param("ssssss", $marca,$modelo,$color,$ram,$cap,$codigo);
        $ok = $stmt->execute();
        $errno = $stmt->errno; $stmt->close();

        if ($ok) {
          $mensaje = "<div class='alert alert-success'>Modelo creado.</div>";
        } else {
          if ($errno === 1062) {
            $mensaje = "<div class='alert alert-danger'>
              Duplicado: revisa Marca + Modelo + Color + RAM + Capacidad o el Código de producto.
            </div>";
          } else {
            $mensaje = "<div class='alert alert-danger'>Error al crear.</div>";
          }
        }
      }
    }
  }
}

// ===== Activar / inactivar =====
if ($permEscritura && isset($_GET['accion'], $_GET['id']) && $_GET['accion']==='toggle') {
  $id = (int)$_GET['id'];
  if ($id > 0) {
    $conn->query("UPDATE catalogo_modelos SET activo=IF(activo=1,0,1) WHERE id=$id");
  }
  header("Location: modelos.php"); exit();
}

// ===== Cargar para edición =====
$edit = null;
if ($permEscritura && isset($_GET['editar'])) {
  $id = (int)$_GET['editar'];
  if ($id > 0) {
    $res = $conn->query("SELECT * FROM catalogo_modelos WHERE id=$id");
    $edit = $res ? $res->fetch_assoc() : null;
  }
}

// ===== Filtros =====
$estado = $_GET['estado'] ?? 'activos';
$q = texto($_GET['q'] ?? '', 80);

$w = [];
if ($estado==='activos')   $w[]="activo=1";
if ($estado==='inactivos') $w[]="activo=0";
if ($q!=='') {
  $x = $conn->real_escape_string($q);
  $w[] = "(marca LIKE '%$x%' OR modelo LIKE '%$x%' OR color LIKE '%$x%' OR ram LIKE '%$x%' OR capacidad LIKE '%$x%' OR codigo_producto LIKE '%$x%')";
}
$where = count($w) ? "WHERE ".implode(" AND ",$w) : "";

$list = $conn->query("SELECT * FROM catalogo_modelos $where ORDER BY marca, modelo, color, ram, capacidad");
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<div class="container my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3>Catálogo de Modelos</h3>
    <a href="modelos.php" class="btn btn-outline-secondary btn-sm">Nuevo</a>
  </div>

  <?= $mensaje ?>

  <div class="row g-3">
    <?php if ($permEscritura): ?>
    <div class="col-lg-5">
      <div class="card shadow-sm">
        <div class="card-header"><?= $edit ? 'Editar modelo' : 'Nuevo modelo' ?></div>
        <div class="card-body">
          <form method="POST" class="row g-2">
            <input type="hidden" name="modo" value="<?= $edit ? 'editar' : 'crear' ?>">
            <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>

            <div class="col-md-6">
              <label class="form-label">Marca *</label>
              <input class="form-control" name="marca" required value="<?= esc($edit['marca'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Modelo *</label>
              <input class="form-control" name="modelo" required value="<?= esc($edit['modelo'] ?? '') ?>">
            </div>

            <div class="col-md-4">
              <label class="form-label">Color</label>
              <input class="form-control" name="color" value="<?= esc($edit['color'] ?? '') ?>" placeholder="p. ej. Negro">
            </div>
            <div class="col-md-4">
              <label class="form-label">RAM</label>
              <input class="form-control" name="ram" value="<?= esc($edit['ram'] ?? '') ?>" placeholder="p. ej. 4GB">
            </div>
            <div class="col-md-4">
              <label class="form-label">Capacidad</label>
              <input class="form-control" name="capacidad" value="<?= esc($edit['capacidad'] ?? '') ?>" placeholder="p. ej. 128GB">
            </div>

            <div class="col-12">
              <label class="form-label">Código de producto</label>
              <input class="form-control" name="codigo_producto" value="<?= esc($edit['codigo_producto'] ?? '') ?>">
              <div class="form-text">Debe ser único si lo usas como SKU; puedes dejarlo vacío y generar después.</div>
            </div>

            <div class="col-12 text-end">
              <button class="btn btn-success"><?= $edit ? 'Actualizar' : 'Guardar' ?></button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <div class="<?= $permEscritura ? 'col-lg-7' : 'col-12' ?>">
      <div class="card shadow-sm">
        <div class="card-header">
          <form class="row g-2 align-items-center">
            <div class="col-md-4">
              <select name="estado" class="form-select" onchange="this.form.submit()">
                <option value="activos"   <?= $estado==='activos'?'selected':'' ?>>Activos</option>
                <option value="inactivos" <?= $estado==='inactivos'?'selected':'' ?>>Inactivos</option>
                <option value="todos"     <?= $estado==='todos'?'selected':'' ?>>Todos</option>
              </select>
            </div>
            <div class="col-md-6">
              <input name="q" class="form-control" placeholder="Buscar marca, modelo, color, RAM, capacidad o código"
                     value="<?= esc($q) ?>">
            </div>
            <div class="col-md-2"><button class="btn btn-primary w-100">Buscar</button></div>
          </form>
        </div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-hover align-middle">
              <thead>
                <tr>
                  <th>Marca</th>
                  <th>Modelo</th>
                  <th>Color</th>
                  <th>RAM</th>
                  <th>Capacidad</th>
                  <th>Código</th>
                  <th class="text-center">Estatus</th>
                  <th class="text-end">Acciones</th>
                </tr>
              </thead>
              <tbody>
              <?php if($list && $list->num_rows): while($r=$list->fetch_assoc()): ?>
                <tr>
                  <td><?= esc($r['marca']) ?></td>
                  <td><?= esc($r['modelo']) ?></td>
                  <td><?= esc($r['color']) ?></td>
                  <td><?= esc($r['ram']) ?></td>
                  <td><?= esc($r['capacidad']) ?></td>
                  <td><?= esc($r['codigo_producto']) ?></td>
                  <td class="text-center">
                    <?= ((int)$r['activo'] === 1)
                          ? '<span class="badge bg-success">Activo</span>'
                          : '<span class="badge bg-secondary">Inactivo</span>' ?>
                  </td>
                  <td class="text-end">
                    <div class="btn-group">
                      <a class="btn btn-sm btn-outline-primary" href="modelos.php?editar=<?= (int)$r['id'] ?>">Editar</a>
                      <?php if($permEscritura): ?>
                      <a class="btn btn-sm btn-outline-<?= ((int)$r['activo']===1)?'danger':'success' ?>"
                         href="modelos.php?accion=toggle&id=<?= (int)$r['id'] ?>"
                         onclick="return confirm('¿Seguro que deseas <?= ((int)$r['activo']===1)?'inactivar':'activar' ?> este modelo?');">
                         <?= ((int)$r['activo']===1)?'Inactivar':'Activar' ?>
                      </a>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endwhile; else: ?>
                <tr><td colspan="8" class="text-center text-muted py-4">Sin modelos</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
          <a href="compras_nueva.php" class="btn btn-outline-secondary">Ir a compras</a>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  (function () {
    try { document.title = 'Catálogo · Equipos — Central2.0'; } catch(e) {}
  })();
</script>