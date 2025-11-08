<?php
// tickets_api_config.php — Config de NANO para hablar con el API de LUGA

// ⚠️ AJUSTA ESTO:
const API_BASE  = 'https://lugaph.site/api'; // sin slash al final
const API_TOKEN = '1Sp2gd3pa*1Fba23a326*'; // Debe coincidir con el de LUGA para NANO

// Opcional: timeout global de cURL
const API_TIMEOUT = 15;

// Helper simple para llamadas POST JSON
function api_post_json(string $path, array $payload): array {
    $url = rtrim(API_BASE, '/').$path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer '.API_TOKEN,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => API_TIMEOUT,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $json = json_decode((string)$resp, true);
    return [
        'http' => $code ?: 0,
        'json' => is_array($json) ? $json : null,
        'raw'  => $resp,
        'err'  => $err,
    ];
}
