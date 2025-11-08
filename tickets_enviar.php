<?php
// tickets_enviar.php — Envía la creación al API de LUGA y vuelve con flash
session_start();
require_once __DIR__.'/tickets_api_config.php';

$ROL = $_SESSION['rol'] ?? '';
if (!isset($_SESSION['id_usuario']) || !in_array($ROL, ['Ejecutivo','Gerente','Admin','Logistica'], true)) {
    header("Location: 403.php");
    exit();
}

function back_with($ok='', $err=''){
    if ($ok)  $_SESSION['flash_ok']  = $ok;
    if ($err) $_SESSION['flash_err'] = $err;
    header('Location: tickets_nuevo.php');
    exit();
}

// CSRF simple
if (!hash_equals($_SESSION['ticket_csrf'] ?? '', $_POST['csrf'] ?? '')) {
    back_with('', 'Token inválido o formulario duplicado. Refresca la página.');
}
// Consumimos el token para evitar doble submit
unset($_SESSION['ticket_csrf']);

$asunto   = trim($_POST['asunto']  ?? '');
$mensaje  = trim($_POST['mensaje'] ?? '');
$prioridad= $_POST['prioridad']    ?? 'media';

if ($asunto === '' || $mensaje === '') {
    back_with('', 'Llena asunto y mensaje.');
}

// Datos de sesión actuales
$idUsuario   = (int)($_SESSION['id_usuario']  ?? 0);
$idSucursal  = (int)($_SESSION['id_sucursal'] ?? 0);

// Payload para LUGA
$payload = [
    'sucursal_origen_id' => $idSucursal,
    'creado_por_id'      => $idUsuario,
    'asunto'             => $asunto,
    'mensaje'            => $mensaje,
    'prioridad'          => $prioridad,
];

// Llamar API LUGA
$res = api_post_json('/tickets.create.php', $payload);

if ($res['http'] === 200 && is_array($res['json']) && !empty($res['json']['ticket_id'])) {
    $tid = (int)$res['json']['ticket_id'];
    // Regenerar CSRF para siguiente ticket
    $_SESSION['ticket_csrf'] = bin2hex(random_bytes(16));
    back_with("✅ Ticket creado (#{$tid}).");
}

// Error legible
$detalle = $res['json']['error'] ?? ($res['err'] ?: 'Error desconocido');
back_with('', "No se pudo crear el ticket. HTTP {$res['http']}. Detalle: {$detalle}");
