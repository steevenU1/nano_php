<?php
session_start();
require_once __DIR__.'/tickets_api_config.php';

// Permisos básicos
$ROL = $_SESSION['rol'] ?? '';
if (!isset($_SESSION['id_usuario']) || !in_array($ROL, ['Ejecutivo','Gerente','Admin','Logistica'], true)) {
  header("Location: 403.php"); exit();
}

// Navbar si existe
if (file_exists(__DIR__.'/navbar.php')) require_once __DIR__.'/navbar.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
date_default_timezone_set('America/Mexico_City');

// Filtros
$estado    = $_GET['estado']    ?? '';
$prioridad = $_GET['prioridad'] ?? '';
$q         = trim($_GET['q']    ?? '');
$since     = $_GET['since']     ?? '1970-01-01T00:00:00';

// Pedimos todos los cambios desde $since (límite 2000 del API)
$resp = api_get('/tickets.since.php', ['since'=>$since]);
$tickets = is_array($resp['json']['tickets'] ?? null) ? $resp['json']['tickets'] : [];

// Filtrado en servidor
$filtered = array_values(array_filter($tickets, function($t) use($estado,$prioridad,$q){
  if ($estado !== '' && ($t['estado'] ?? '') !== $estado) return false;
  if ($prioridad !== '' && ($t['prioridad'] ?? '') !== $prioridad) return false;
  if ($q !== '') {
    $hay = ($t['asunto'] ?? '').' '.($t['sistema_origen'] ?? '').' #'.($t['id'] ?? '');
    if (mb_stripos($hay, $q) === false) return false;
  }
  return true;
}));

// Orden: updated_at DESC
usort($filtered, fn($a,$b)=>strcmp($b['updated_at'],$a['updated_at']));
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Tickets (NANO → LUGA)</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 m-0">Tickets</h1>
    <div class="d-flex gap-2">
      <a href="tickets_nuevo.php" class="btn btn-primary">➕ Nuevo</a>
      <a href="tickets_lista.php?since=<?=urlencode(date('Y-m-d\T00:00:00'))?>" class="btn btn-outline-secondary">Hoy</a>
      <a href="tickets_lista.php" class="btn btn-outline-secondary">Todos</a>
    </div>
  </div>

  <form class="row g-2 mb-3">
    <div class="col-md-2">
      <select name="estado" class="form-select">
        <option value="">Estado (todos)</option>
        <?php foreach (['abierto','en_progreso','resuelto','cerrado'] as $e): ?>
          <option value="<?=$e?>" <?=$estado===$e?'selected':''?>><?=$e?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <select name="prioridad" class="form-select">
        <option value="">Prioridad (todas)</option>
        <?php foreach (['baja','media','alta','critica'] as $p): ?>
          <option value="<?=$p?>" <?=$prioridad===$p?'selected':''?>><?=$p?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-6">
      <input name="q" class="form-control" placeholder="Buscar por asunto / #ID / origen" value="<?=h($q)?>">
    </div>
    <div class="col-md-2 d-grid">
      <button class="btn btn-secondary">Filtrar</button>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th><th>Asunto</th><th>Estado</th><th>Prioridad</th><th>Origen</th><th>Actualizado</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($filtered as $t): ?>
        <tr>
          <td><?=h($t['id'])?></td>
          <td><?=h($t['asunto'])?></td>
          <td><span class="badge bg-secondary"><?=h($t['estado'])?></span></td>
          <td><?=h($t['prioridad'])?></td>
          <td><?=h($t['sistema_origen'])?></td>
          <td><?=h($t['updated_at'])?></td>
          <td><a class="btn btn-sm btn-outline-primary" href="tickets_ver.php?id=<?=h($t['id'])?>">Abrir</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="text-muted small mt-2">Fuente: LUGA API · since: <?=h($since)?> · HTTP <?=$resp['http']?></div>
</div>

<script>
// Auto-refresh cada 90s (puedes subir/bajar)
setTimeout(()=>location.reload(), 90000);
</script>
</body>
</html>
