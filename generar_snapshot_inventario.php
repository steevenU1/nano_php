<?php
session_start();
require 'db.php';

// ===== Seguridad para Cron =====
// Usa un token secreto (ponlo también en tu cron). Cámbialo por uno fuerte.
$CRON_SECRET = 'CAMBIA-ESTE-VALOR-LARGO-Y-UNICO';

// Detectar ejecución por CLI o por HTTP con token
$isCli  = (php_sapi_name() === 'cli');
$token  = $isCli ? (getenv('CRON_TOKEN') ?: '') : ($_GET['token'] ?? '');

// Permitir si: (1) es CLI con token OK, (2) HTTP con token OK, o (3) sesión Admin
$tieneSesionAdmin = isset($_SESSION['id_usuario']) && (($_SESSION['rol'] ?? '') === 'Admin');
$tokenOk = hash_equals($CRON_SECRET, $token);

if (!($tieneSesionAdmin || $tokenOk)) {
  http_response_code(403);
  exit('No autorizado');
}

// ===== Parámetros =====
$fecha = $_GET['fecha'] ?? date('Y-m-d');
$retencionDias = (int)($_GET['retencion'] ?? 15);
if ($retencionDias < 1) { $retencionDias = 15; }
