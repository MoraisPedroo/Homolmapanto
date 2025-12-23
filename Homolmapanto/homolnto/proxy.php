<?php
// proxy.php
// GET  -> busca conteúdo remoto (use ip=... ou url=...); as_json=1 retorna body_base64 + headers
// POST -> envia comando raw (campo JSON { ip: "...", cmd: "..." }) para porta 9100 (socket TCP)
// CORS e OPTIONS tratados para facilitar testes locais

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // preflight
    http_response_code(200);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function send_json($data, $status = 200) {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if ($method === 'POST') {
    // espera JSON: { ip: "...", cmd: "..." }
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!$data || empty($data['ip']) || !isset($data['cmd'])) {
        send_json(['error' => "POST JSON inválido. Formato esperado: { ip, cmd }"], 400);
    }
    $ip = trim($data['ip']);
    $cmd = $data['cmd'];

    // porta padrão 9100 (pode incluir :porta no ip)
    $host = $ip;
    $port = 9100;
    if (strpos($ip, ':') !== false) {
        [$host, $maybePort] = explode(':', $ip, 2);
        if (is_numeric($maybePort)) $port = intval($maybePort);
    }

    $timeout = 5;
    $errno = 0; $errstr = '';
    $fp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);
    if (!$fp) {
        send_json(['error' => "Falha ao conectar {$host}:{$port} - $errstr", 'errno'=>$errno], 502);
    }

    // tenta enviar os bytes (envia exatamente como string)
    $written = @fwrite($fp, $cmd);
    // opcional: flush e espera pequeno
    @fflush($fp);
    // fecha
    @fclose($fp);

    if ($written === false || $written === 0) {
        send_json(['error' => "Falha ao enviar comando.", 'sent'=>false], 500);
    }

    send_json(['success' => true, 'sent' => true, 'bytes_written' => $written], 200);
    exit;
}

// GET path: montar $target a partir de url ou ip+path
if ($method !== 'GET') {
    send_json(['error' => 'Método não suportado. Use GET ou POST.'], 405);
}

if (isset($_GET['url']) && trim($_GET['url']) !== '') {
    $target = trim($_GET['url']);
    if (!preg_match('#^https?://#i', $target)) {
        $target = 'http://' . $target;
    }
} elseif (isset($_GET['ip']) && trim($_GET['ip']) !== '') {
    $ip = trim($_GET['ip']);
    $path = isset($_GET['path']) ? $_GET['path'] : '/';
    if ($path !== '' && $path[0] !== '/') $path = '/' . $path;
    // se ip contém :porta, mantenha
    $target = 'http://' . $ip . $path;
} else {
    send_json(['error' => "Parâmetro 'ip' ou 'url' é obrigatório."], 400);
}

$timeout = 6;
$opts = [
    "http" => [
        "method" => "GET",
        "timeout" => $timeout,
        "header" => "User-Agent: PHP-proxy/1.0\r\nAccept: */*\r\n"
    ]
];
$context = stream_context_create($opts);
$body = @file_get_contents($target, false, $context);
$respHeaders = isset($http_response_header) ? $http_response_header : [];

function find_content_type($headers) {
    foreach ($headers as $h) {
        if (stripos($h, 'Content-Type:') === 0) {
            return trim(substr($h, strlen('Content-Type:')));
        }
    }
    return null;
}

$remoteStatus = null;
foreach ($respHeaders as $h) {
    if (preg_match('#^HTTP/[\d\.]+\s+(\d{3})#i', $h, $m)) {
        $remoteStatus = intval($m[1]);
        break;
    }
}

if (isset($_GET['as_json']) && ($_GET['as_json'] === '1' || strtolower($_GET['as_json']) === 'true')) {
    $out = [
        'target' => $target,
        'http_status' => $remoteStatus,
        'headers' => $respHeaders,
        'body_base64' => $body !== false ? base64_encode($body) : null,
    ];
    if ($body === false) {
        send_json(array_merge($out, ['error' => "Falha ao buscar {$target}"]), 502);
    } else {
        send_json($out, 200);
    }
}

// modo normal: retorna corpo bruto (tenta preservar content-type)
if ($body === false) {
    send_json(['error' => "Falha ao acessar {$target}"], 502);
}

$contentType = find_content_type($respHeaders) ?: 'text/html; charset=UTF-8';
header("Content-Type: {$contentType}");
if ($remoteStatus !== null) {
    http_response_code($remoteStatus);
} else {
    http_response_code(200);
}
echo $body;
exit;