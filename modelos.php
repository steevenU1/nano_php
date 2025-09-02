<?php
// modelos.php - Catálogo de modelos (fuente para Compras → Productos)
// Ahora con formulario en modal para no estorbar la tabla principal.

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }
include 'db.php';
include 'navbar.php';

$ROL = $_SESSION['rol'] ?? 'Ejecutivo';
$permEscritura = in_array($ROL, ['Admin','Gerente']);

// ============ Helpers ============
function texto($s,$n){ return substr(trim($s ?? ''), 0, $n); }
function esc($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function toNull($s){ $s=trim((string)$s); return $s===''? null : $s; }
function toDec($s){ $x=trim((string)$s); return $x===''? null : number_format((float)$x, 2, '.', ''); }

$mensaje = "";

// ============ Crear / editar ============
if ($permEscritura && $_SERVER['REQUEST_METHOD']==='POST') {
  $modo   = $_POST['modo'] ?? 'crear';
  $id     = (int)($_POST['id'] ?? 0);

  // Campos base
  $marca    = texto($_POST['marca']    ?? '', 80);
  $modelo   = texto($_POST['modelo']   ?? '', 80);
  $color    = texto($_POST['color']    ?? '', 50);
  $ram      = texto($_POST['ram']      ?? '', 50);
  $cap      = texto($_POST['capacidad']?? '', 50);
  $codigo   = texto($_POST['codigo_producto'] ?? '', 50);

  // Alineados a productos
  $descripcion       = toNull($_POST['descripcion'] ?? '');
  $nombre_comercial  = texto($_POST['nombre_comercial'] ?? '', 255);
  $compania          = texto($_POST['compania'] ?? '', 100);
  $financiera        = texto($_POST['financiera'] ?? '', 100);
  $fecha_lanzamiento = toNull($_POST['fecha_lanzamiento'] ?? '');
  $precio_lista      = toDec($_POST['precio_lista'] ?? '');
  $tipo_producto     = toNull($_POST['tipo_producto'] ?? '');
  $subtipo           = texto($_POST['subtipo'] ?? '', 50);
  $gama              = toNull($_POST['gama'] ?? '');
  $ciclo_vida        = toNull($_POST['ciclo_vida'] ?? '');
  $abc               = toNull($_POST['abc'] ?? '');
  $operador          = texto($_POST['operador'] ?? '', 50);
  $resurtible        = toNull($_POST['resurtible'] ?? '');

  if ($marca==='' || $modelo==='') {
    $mensaje = "<div class='alert alert-danger'>Marca y Modelo son obligatorios.</div>";
  } else {
    if ($modo==='editar' && $id>0) {
      $stmt = $conn->prepare("
        UPDATE catalogo_modelos
           SET marca=?, modelo=?, color=?, ram=?, capacidad=?, codigo_producto=?,
               descripcion=?, nombre_comercial=?, compania=?, financiera=?, fecha_lanzamiento=?,
               precio_lista=?, tipo_producto=?, subtipo=?, gama=?, ciclo_vida=?, abc=?, operador=?, resurtible=?
         WHERE id=?
      ");
      if (!$stmt) {
        $mensaje = "<div class='alert alert-danger'>Error de preparación: ".$conn->error."</div>";
      } else {
        $stmt->bind_param(
          "sssssssssssssssssssi",
          $marca,$modelo,$color,$ram,$cap,$codigo,
          $descripcion,$nombre_comercial,$compania,$financiera,$fecha_lanzamiento,
          $precio_lista,$tipo_producto,$subtipo,$gama,$ciclo_vida,$abc,$operador,$resurtible,
          $id
        );
        $ok = $stmt->execute();
        $errno = $stmt->errno; $stmt->close();

        if ($ok) {
          $mensaje = "<div class='alert alert-success'>Modelo actualizado.</div>";
        } else {
          if ($errno === 1062) {
            $mensaje = "<div class='alert alert-danger'>
              Duplicado: combinación Marca+Modelo+Color+RAM+Capacidad o Código de producto ya existe.
            </div>";
          } else {
            $mensaje = "<div class='alert alert-danger'>Error al actualizar.</div>";
          }
        }
      }
    } else {
      $stmt = $conn->prepare("
        INSERT INTO catalogo_modelos
          (marca, modelo, color, ram, capacidad, codigo_producto,
           descripcion, nombre_comercial, compania, financiera, fecha_lanzamiento,
           precio_lista, tipo_producto, subtipo, gama, ciclo_vida, abc, operador, resurtible, activo)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)
      ");
      if (!$stmt) {
        $mensaje = "<div class='alert alert-danger'>Error de preparación: ".$conn->error."</div>";
      } else {
        $stmt->bind_param(
          "sssssssssssssssssss",
          $marca,$modelo,$color,$ram,$cap,$codigo,
          $descripcion,$nombre_comercial,$compania,$financiera,$fecha_lanzamiento,
          $precio_lista,$tipo_producto,$subtipo,$gama,$ciclo_vida,$abc,$operador,$resurtible
        );
        $ok = $stmt->execute();
        $errno = $stmt->errno; $stmt->close();

        if ($ok) {
          $mensaje = "<div class='alert alert-success'>Modelo creado.</div>";
        } else {
          if ($errno === 1062) {
            $mensaje = "<div class='alert alert-danger'>
              Duplicado: revisa Marca+Modelo+Color+RAM+Capacidad o el Código de producto.
            </div>";
          } else {
            $mensaje = "<div class='alert alert-danger'>Error al crear.</div>";
          }
        }
      }
    }
  }
}

// ============ Activar / inactivar ============
if ($permEscritura && isset($_GET['accion'], $_GET['id']) && $_GET['accion']==='toggle') {
  $id = (int)$_GET['id'];
  if ($id > 0) { $conn->query("UPDATE catalogo_modelos SET activo=IF(activo=1,0,1) WHERE id=$id"); }
  header("Location: modelos.php"); exit();
}

// ============ Cargar para edición ============
$edit = null;
if ($permEscritura && isset($_GET['editar'])) {
  $id = (int)$_GET['editar'];
  if ($id > 0) {
    $res = $conn->query("SELECT * FROM catalogo_modelos WHERE id=$id");
    $edit = $res ? $res->fetch_assoc() : null;
  }
}

// ============ Filtros ============
$estado = $_GET['estado'] ?? 'activos';
$q = texto($_GET['q'] ?? '', 120);

$w = [];
if ($estado==='activos')   $w[]="activo=1";
if ($estado==='inactivos') $w[]="activo=0";
if ($q!=='') {
  $x = $conn->real_escape_string($q);
  $w[] = "(
      marca LIKE '%$x%' OR modelo LIKE '%$x%' OR color LIKE '%$x%'
   OR ram LIKE '%$x%' OR capacidad LIKE '%$x%' OR codigo_producto LIKE '%$x%'
   OR nombre_comercial LIKE '%$x%' OR compania LIKE '%$x%' OR financiera LIKE '%$x%'
   OR operador LIKE '%$x%'
  )";
}
$where = count($w) ? "WHERE ".implode(" AND ",$w) : "";

$list = $conn->query("SELECT * FROM catalogo_modelos $where ORDER BY marca, modelo, color, ram, capacidad");
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  /* tabla protagonista */
  .tableFixHead { max-height: 70vh; overflow:auto; }
  .tableFixHead thead th { position: sticky; top: 0; z-index: 2; background: #fff; }
  .table-hover tbody tr:hover { background: #f8fafc; }
  .cell-tight { white-space: nowrap; }
</style>

<div class="container my-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h3 class="m-0">Catálogo de Modelos</h3>
    <div class="btn-group">
      <?php if ($permEscritura): ?>
        <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#mdlModelo" id="btnNuevo">
          ➕ Nuevo
        </button>
        <a href="modelos_carga.php" class="btn btn-outline-primary btn-sm">Carga masiva CSV</a>
      <?php endif; ?>
      <a href="compras_nueva.php" class="btn btn-outline-secondary btn-sm">Ir a compras</a>
    </div>
  </div>

  <?= $mensaje ?>

  <div class="card shadow-sm">
    <div class="card-header">
      <form class="row g-2 align-items-center">
        <div class="col-md-3">
          <select name="estado" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="activos"   <?= $estado==='activos'?'selected':'' ?>>Activos</option>
            <option value="inactivos" <?= $estado==='inactivos'?'selected':'' ?>>Inactivos</option>
            <option value="todos"     <?= $estado==='todos'?'selected':'' ?>>Todos</option>
          </select>
        </div>
        <div class="col-md-7">
          <input name="q" class="form-control form-control-sm" placeholder="Buscar marca, modelo, código, compañía, financiera u operador"
                 value="<?= esc($q) ?>">
        </div>
        <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Buscar</button></div>
      </form>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive tableFixHead">
        <table class="table table-sm table-hover align-middle m-0">
          <thead class="table-light">
            <tr>
              <th class="cell-tight">Marca</th>
              <th>Modelo</th>
              <th class="cell-tight">Color</th>
              <th class="cell-tight">RAM</th>
              <th class="cell-tight">Cap.</th>
              <th class="cell-tight">Código</th>
              <th class="cell-tight">Tipo</th>
              <th class="text-end cell-tight">$ Lista</th>
              <th class="text-center cell-tight">Estatus</th>
              <th class="text-end cell-tight">Acciones</th>
            </tr>
          </thead>
          <tbody>
          <?php if($list && $list->num_rows): while($r=$list->fetch_assoc()): ?>
            <tr>
              <td class="cell-tight"><?= esc($r['marca']) ?></td>
              <td style="min-width:220px">
                <div><?= esc($r['modelo']) ?></div>
                <?php if(!empty($r['nombre_comercial'])): ?>
                  <div class="small text-muted"><?= esc($r['nombre_comercial']) ?></div>
                <?php endif; ?>
              </td>
              <td class="cell-tight"><?= esc($r['color']) ?></td>
              <td class="cell-tight"><?= esc($r['ram']) ?></td>
              <td class="cell-tight"><?= esc($r['capacidad']) ?></td>
              <td class="cell-tight"><?= esc($r['codigo_producto']) ?></td>
              <td class="cell-tight"><?= esc($r['tipo_producto'] ?? '') ?></td>
              <td class="text-end cell-tight"><?= $r['precio_lista']!==null ? number_format((float)$r['precio_lista'],2) : '' ?></td>
              <td class="text-center cell-tight">
                <?= ((int)$r['activo'] === 1)
                      ? '<span class="badge bg-success">Activo</span>'
                      : '<span class="badge bg-secondary">Inactivo</span>' ?>
              </td>
              <td class="text-end cell-tight">
                <div class="btn-group">
                  <a class="btn btn-outline-primary btn-sm"
                     href="modelos.php?editar=<?= (int)$r['id'] ?>">Editar</a>
                  <?php if($permEscritura): ?>
                  <a class="btn btn-outline-<?= ((int)$r['activo']===1)?'danger':'success' ?> btn-sm"
                     href="modelos.php?accion=toggle&id=<?= (int)$r['id'] ?>"
                     onclick="return confirm('¿Seguro que deseas <?= ((int)$r['activo']===1)?'inactivar':'activar' ?> este modelo?');">
                     <?= ((int)$r['activo']===1)?'Inactivar':'Activar' ?>
                  </a>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endwhile; else: ?>
            <tr><td colspan="10" class="text-center text-muted py-4">Sin modelos</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ================= Modal Crear/Editar ================= -->
<div class="modal fade" id="mdlModelo" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><?= $edit ? 'Editar modelo' : 'Nuevo modelo' ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <form method="POST" id="frmModelo" class="row g-2">
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
            <input class="form-control" name="color" value="<?= esc($edit['color'] ?? '') ?>" placeholder="Negro">
          </div>
          <div class="col-md-4">
            <label class="form-label">RAM</label>
            <input class="form-control" name="ram" value="<?= esc($edit['ram'] ?? '') ?>" placeholder="4GB">
          </div>
          <div class="col-md-4">
            <label class="form-label">Capacidad</label>
            <input class="form-control" name="capacidad" value="<?= esc($edit['capacidad'] ?? '') ?>" placeholder="128GB">
          </div>

          <div class="col-12">
            <label class="form-label">Código de producto</label>
            <input class="form-control" name="codigo_producto" value="<?= esc($edit['codigo_producto'] ?? '') ?>">
            <div class="form-text">Debe ser único si lo usas como SKU.</div>
          </div>

          <div class="col-12">
            <label class="form-label">Nombre comercial</label>
            <input class="form-control" name="nombre_comercial" value="<?= esc($edit['nombre_comercial'] ?? '') ?>" placeholder="Galaxy S24 Ultra">
          </div>

          <div class="col-md-6">
            <label class="form-label">Compañía</label>
            <input class="form-control" name="compania" value="<?= esc($edit['compania'] ?? '') ?>" placeholder="AT&T, Telcel...">
          </div>
          <div class="col-md-6">
            <label class="form-label">Financiera</label>
            <input class="form-control" name="financiera" value="<?= esc($edit['financiera'] ?? '') ?>" placeholder="PayJoy, Krediya...">
          </div>

          <div class="col-md-6">
            <label class="form-label">Fecha de lanzamiento</label>
            <input type="date" class="form-control" name="fecha_lanzamiento" value="<?= esc($edit['fecha_lanzamiento'] ?? '') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Precio lista (sugerido)</label>
            <input class="form-control" name="precio_lista" value="<?= esc($edit['precio_lista'] ?? '') ?>" placeholder="0.00">
          </div>

          <div class="col-md-6">
            <label class="form-label">Tipo de producto</label>
            <select class="form-select" name="tipo_producto">
              <?php $tp = $edit['tipo_producto'] ?? 'Equipo'; ?>
              <option value="">(sin definir)</option>
              <option value="Equipo"    <?= $tp==='Equipo'?'selected':'' ?>>Equipo</option>
              <option value="Modem"     <?= $tp==='Modem'?'selected':'' ?>>Modem</option>
              <option value="Accesorio" <?= $tp==='Accesorio'?'selected':'' ?>>Accesorio</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Subtipo</label>
            <input class="form-control" name="subtipo" value="<?= esc($edit['subtipo'] ?? '') ?>" placeholder="p. ej. Smartphone">
          </div>

          <div class="col-md-6">
            <label class="form-label">Gama</label>
            <?php $gm = $edit['gama'] ?? ''; ?>
            <select class="form-select" name="gama">
              <option value="">(sin definir)</option>
              <?php
                $gamas = ['Ultra baja','Baja','Media baja','Media','Media alta','Alta','Premium'];
                foreach ($gamas as $g) {
                  $sel = ($gm===$g)?'selected':''; echo "<option value=\"".esc($g)."\" $sel>".esc($g)."</option>";
                }
              ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Ciclo de vida</label>
            <?php $cv = $edit['ciclo_vida'] ?? ''; ?>
            <select class="form-select" name="ciclo_vida">
              <option value="">(sin definir)</option>
              <option value="Nuevo"        <?= $cv==='Nuevo'?'selected':'' ?>>Nuevo</option>
              <option value="Linea"        <?= $cv==='Linea'?'selected':'' ?>>Línea</option>
              <option value="Fin de vida"  <?= $cv==='Fin de vida'?'selected':'' ?>>Fin de vida</option>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label">ABC</label>
            <?php $abcv = $edit['abc'] ?? ''; ?>
            <select class="form-select" name="abc">
              <option value="">(sin definir)</option>
              <option value="A" <?= $abcv==='A'?'selected':'' ?>>A</option>
              <option value="B" <?= $abcv==='B'?'selected':'' ?>>B</option>
              <option value="C" <?= $abcv==='C'?'selected':'' ?>>C</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Operador</label>
            <input class="form-control" name="operador" value="<?= esc($edit['operador'] ?? '') ?>" placeholder="AT&T, Telcel...">
          </div>
          <div class="col-md-4">
            <label class="form-label">Resurtible</label>
            <?php $rs = $edit['resurtible'] ?? 'Sí'; ?>
            <select class="form-select" name="resurtible">
              <option value="Sí" <?= $rs==='Sí'?'selected':'' ?>>Sí</option>
              <option value="No" <?= $rs==='No'?'selected':'' ?>>No</option>
            </select>
          </div>

          <div class="col-12">
            <label class="form-label">Descripción</label>
            <textarea class="form-control" name="descripcion" rows="3" placeholder="Notas o especificaciones del modelo..."><?= esc($edit['descripcion'] ?? '') ?></textarea>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
        <?php if ($permEscritura): ?>
          <button form="frmModelo" class="btn btn-success"><?= $edit ? 'Actualizar' : 'Guardar' ?></button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
  (function () {
    try { document.title = 'Catálogo · Equipos — Central2.0'; } catch(e) {}

    // Si venimos con ?editar=ID o hubo errores/éxitos, abre el modal automáticamente
    <?php if ($permEscritura && ($edit || ($_SERVER['REQUEST_METHOD']==='POST'))): ?>
      const mdl = new bootstrap.Modal(document.getElementById('mdlModelo'));
      mdl.show();
    <?php endif; ?>
  })();
</script>
