<?php
session_start();
require_once __DIR__.'/tickets_api_config.php';

$ROL = $_SESSION['rol'] ?? '';
if (!isset($_SESSION['id_usuario']) || !in_array($ROL, ['Ejecutivo','Gerente','Admin','Logistica'], true)) {
  header("Location: 403.php"); exit();
}

function back_to($id, $ok='', $err=''){
  if ($ok)  $_SESSION['flash_ok']  = $ok;
  if ($err) $_SESSION['flash_err'] = $err;
  header('Location: tickets_ver.php?id='.$id);
  exit();
}

$ticketId = (int)($_POST['ticket_id'] ?? 0);
$mensaje  = trim($_POST['mensaje'] ?? '');
if ($ticketId<=0 || $mensaje===''){ back_to($ticketId,'','Datos inválidos'); }

$payload = [
  'ticket_id' => $ticketId,
  'autor_id'  => (int)($_SESSION['id_usuario'] ?? 0),
  'mensaje'   => $mensaje,
];

$res = api_post_json('/tickets.reply.php', $payload);
if ($res['http']===200 && !empty($res['json']['ok'])) {
  back_to($ticketId, 'Respuesta enviada.');
}
$detalle = $res['json']['error'] ?? ($res['err'] ?: 'Error desconocido');
back_to($ticketId, '', "No se pudo enviar. HTTP {$res['http']} · {$detalle}");
