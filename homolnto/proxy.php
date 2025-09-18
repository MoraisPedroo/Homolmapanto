<?php
// proxy.php
// GET ?ip=...           -> verifica a página web da impressora e retorna status simplificado
// POST (JSON) {ip,cmd} -> envia bytes para porta (default 9100) e retorna sucesso/erro
//
// Segurança:
// - Valida host simples
// - Não inclui raw_html/raw_response por padrão
// - debug=1 só habilita raw_* se a requisição for feita a partir de localhost

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function sanitize_host($raw) {
    $raw = trim($raw);
    if ($raw === '') return false;
    // permite host, nomes, ipv4, ipv6 brackets, porta opcional
    // exemplo permitido: 192.168.0.50, printer.local, 192.168.0.50:9100
    if (!preg_match('/^[0-9A-Za-z\.\-\:\[\]]+$/', $raw)) return false;
    // rejeita string com espaços ou caracteres suspeitos
    return $raw;
}

function normalize_status_text($raw) {
    $t = mb_strtolower(trim((string)$raw), 'UTF-8');
    if ($t === '') return 'UNKNOWN';
    if (mb_strpos($t, 'aguardo') !== false || mb_strpos($t, 'pronto') !== false
        || mb_strpos($t, 'ready') !== false || mb_strpos($t, 'ok') !== false) {
        return 'READY';
    }
    if (mb_strpos($t, 'pausa') !== false || mb_strpos($t, 'pause') !== false || mb_strpos($t, 'paused') !== false) {
        return 'PAUSE';
    }
    if (mb_strpos($t, 'erro') !== false || mb_strpos($t, 'error') !== false || mb_strpos($t, 'aberto') !== false) {
        return 'ERROR';
    }
    return 'UNKNOWN';
}

function is_local_request() {
    $addr = $_SERVER['REMOTE_ADDR'] ?? '';
    return ($addr === '127.0.0.1' || $addr === '::1');
}

// === POST: enviar comando à porta 9100 ===
if ($method === 'POST') {
    $body = file_get_contents('php://input');
    $json = @json_decode($body, true);
    if (!is_array($json)) respond(['error' => 'Payload inválido. Envie JSON com { "ip": "...", "cmd": "..." }'], 400);

    if (empty($json['ip']) || !isset($json['cmd'])) {
        respond(['error' => "Campos 'ip' e 'cmd' são obrigatórios."], 400);
    }

    $ipraw = $json['ip'];
    $hostPort = sanitize_host($ipraw);
    if ($hostPort === false) respond(['error' => 'IP/hospedeiro inválido.'], 400);

    // separa host e porta (se houver)
    $port = 9100;
    $host = $hostPort;
    if (strpos($hostPort, ':') !== false && substr_count($hostPort, ':') === 1 && strpos($hostPort, '[') === false) {
        // host:port simples (não ipv6)
        [$h, $p] = explode(':', $hostPort);
        $portCandidate = intval($p);
        if ($portCandidate > 0 && $portCandidate <= 65535) { $host = $h; $port = $portCandidate; }
    } elseif (strpos($hostPort, ']') !== false) {
        // possível IPv6 no formato [::1]:9100
        if (preg_match('/^\[(.+)\](?::(\d+))?$/', $hostPort, $m)) {
            $host = $m[1];
            if (!empty($m[2])) $port = intval($m[2]);
        }
    }

    $cmd = $json['cmd'];

    // tentativa de conexão TCP
    $timeout = 5;
    $errNo = 0; $errStr = '';
    $remoteAddr = $host . ':' . $port;
    $fp = @stream_socket_client("tcp://{$host}:{$port}", $errNo, $errStr, $timeout, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        respond(['success' => false, 'error' => "Falha ao conectar em {$remoteAddr} - ({$errNo}) {$errStr}"], 502);
    }

    stream_set_timeout($fp, $timeout);
    // envia exatamente o que recebeu (cliente deve enviar quebras de linha conforme necessário)
    $bytesSent = @fwrite($fp, $cmd);
    // tenta ler resposta curta (muitos dispositivos não respondem)
    $response = '';
    stream_set_blocking($fp, false);
    usleep(150000); // 150ms
    $start = microtime(true);
    while (!feof($fp) && (microtime(true) - $start) < 0.5) {
        $part = @fgets($fp, 2048);
        if ($part === false) break;
        $response .= $part;
        if (strlen($response) > 2048) break;
    }
    fclose($fp);

    $out = [
        'success' => true,
        'message' => "Comando enviado para {$remoteAddr}",
        'bytes_sent' => is_int($bytesSent) ? $bytesSent : 0,
    ];
    // por padrão não expor raw_response; permitir apenas para localhost (debug)
    if (isset($_GET['debug']) && $_GET['debug'] === '1' && is_local_request()) {
        $out['raw_response'] = $response;
    }
    respond($out, 200);
}

// === GET: verificar status simplificado ===
if (!isset($_GET['ip'])) {
    respond(['error' => "Parâmetro 'ip' é obrigatório para GET"], 400);
}

$ipraw = $_GET['ip'];
$hostPort = sanitize_host($ipraw);
if ($hostPort === false) respond(['error' => 'IP/hospedeiro inválido.'], 400);

// forma URL com http://host[:port] (se o usuário passou porta, usa ela)
$urlHost = $hostPort;
$url = "http://{$urlHost}/";

$opts = [
    "http" => [
        "method" => "GET",
        "timeout" => 4,
        "header" => "User-Agent: PHP-proxy/1.0\r\n"
    ]
];
$context = stream_context_create($opts);
$html = @file_get_contents($url, false, $context);

if ($html === false) {
    // resposta padronizada de erro
    respond([
        'status' => 'ERROR',
        'message' => "Falha ao acessar {$url}",
    ], 502);
}

// parse com DOMDocument para tentar extrair um texto de status curto
libxml_use_internal_errors(true);
$doc = new DOMDocument();
@$doc->loadHTML($html);
libxml_clear_errors();

$statusText = '';
$extraMessage = '';

// tenta procurar elementos comuns onde impressoras mostram status (ex: <h3>Status: ...</h3> ou <font color=...>)
$h3s = $doc->getElementsByTagName('h3');
foreach ($h3s as $h3) {
    $txt = trim($h3->textContent);
    if (stripos($txt, 'Status:') !== false) {
        $parts = preg_split('/Status:/i', $txt);
        if (isset($parts[1])) { $statusText = trim($parts[1]); break; }
    }
}

// varre fontes por texto relevante
if ($statusText === '') {
    $fonts = $doc->getElementsByTagName('font');
    foreach ($fonts as $f) {
        $t = trim($f->textContent);
        if ($t !== '') {
            // heurística: se o texto contém 'AGUARDO' 'PRONTO' 'PAUSA' etc
            if (preg_match('/aguardo|pronto|ready|pausa|pause|erro|error/i', $t)) {
                $statusText = $t;
                break;
            }
        }
    }
}

// fallback: pesquisa no HTML bruto termos conhecidos
if ($statusText === '') {
    if (stripos($html, 'EM AGUARDO') !== false || stripos($html, 'PRONTO') !== false || stripos($html, 'READY') !== false) {
        $statusText = 'PRONTO';
    } elseif (stripos($html, 'EM PAUSA') !== false || stripos($html, 'PAUSA') !== false || stripos($html, 'PAUSED') !== false) {
        $statusText = 'EM PAUSA';
    }
}

// se ainda vazio, pega alguma linha curta útil (first text node)
if ($statusText === '') {
    $bodyText = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
    if (strlen($bodyText) > 0) {
        $statusText = mb_substr($bodyText, 0, 160, 'UTF-8');
    }
}

// normaliza para READY/PAUSE/ERROR/UNKNOWN
$normalized = normalize_status_text($statusText);

// prepara resposta — sem raw_html por padrão
$response = [
    'status' => $normalized,
    'message' => $statusText ?: '',
];

// opcional: retornar cor se for detectável via <font color="..."> (não expõe HTML)
$color = null;
$fontsAll = $doc->getElementsByTagName('font');
foreach ($fontsAll as $f) {
    $c = trim($f->getAttribute('color'));
    $txt = trim($f->textContent);
    if ($c !== '' && $txt !== '') {
        // se coincidir com statusText, expõe cor
        if ($statusText !== '' && mb_stripos($statusText, $txt) !== false) {
            $color = $c;
            break;
        }
    }
}
if ($color) $response['color'] = $color;

// permitir debug (raw_html) somente se debug=1 e requisição local
if (isset($_GET['debug']) && $_GET['debug'] === '1' && is_local_request()) {
    $response['raw_html'] = mb_substr($html, 0, 20000, 'UTF-8');
}

respond($response, 200);
