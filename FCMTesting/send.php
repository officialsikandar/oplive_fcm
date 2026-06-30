<?php
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function respond($ok, $data = array()) {
    if (!$ok) {
        $err = isset($data['error']) ? trim((string) $data['error']) : '';
        $data['error'] = ($err === '') ? 'Request failed' : $err;
    }
    echo json_encode(array_merge(array('success' => (bool) $ok), $data));
    exit;
}

function hosting_blocked_msg() {
    return 'Your hosting blocks outbound HTTPS to Google (HTTP 0). '
        . 'Freehostia free plan cannot call Firebase API. '
        . 'Use config.js to point to an external backend, or upgrade hosting.';
}

function pick_error($body, $fallback) {
    if (!is_array($body)) {
        return $fallback;
    }
    $candidates = array();
    if (isset($body['error']['message'])) $candidates[] = $body['error']['message'];
    if (isset($body['error_description'])) $candidates[] = $body['error_description'];
    if (isset($body['error']) && is_string($body['error'])) $candidates[] = $body['error'];
    if (isset($body['error']['status'])) $candidates[] = $body['error']['status'];
    if (isset($body['raw'])) $candidates[] = $body['raw'];
    foreach ($candidates as $msg) {
        if (is_string($msg) && trim($msg) !== '') {
            return trim($msg);
        }
    }
    return $fallback;
}

function normalize_key($key) {
    return trim(str_replace(array('\\n', '\n'), "\n", $key));
}

function response_code_from_headers() {
    $headers = array();
    if (function_exists('http_get_last_response_headers')) {
        $headers = http_get_last_response_headers();
    }
    if (empty($headers) && isset($GLOBALS['http_response_header'])) {
        $headers = $GLOBALS['http_response_header'];
    }
    if (!empty($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $m)) {
        return (int) $m[1];
    }
    return 0;
}

function post_stream($url, $body, $headers = array()) {
    if (!ini_get('allow_url_fopen')) {
        return array('code' => 0, 'body' => array(), 'curl_error' => 'allow_url_fopen is disabled');
    }

    $headerLines = array('Content-Type: application/json');
    foreach ($headers as $h) {
        $headerLines[] = $h;
    }

    $ctx = stream_context_create(array(
        'http' => array(
            'method' => 'POST',
            'header' => implode("\r\n", $headerLines),
            'content' => json_encode($body),
            'timeout' => 30,
            'ignore_errors' => true,
        ),
    ));

    $res = @file_get_contents($url, false, $ctx);
    $code = response_code_from_headers();

    if ($res === false) {
        return array('code' => 0, 'body' => array(), 'curl_error' => 'Stream request failed');
    }

    $decoded = json_decode($res, true);
    if (!is_array($decoded)) {
        $decoded = array('raw' => $res);
    }

    return array('code' => $code, 'body' => $decoded);
}

function post($url, $body, $headers = array()) {
    if (!function_exists('curl_init')) {
        return post_stream($url, $body, $headers);
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(array('Content-Type: application/json'), $headers));
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);

    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);

    if ($res === false || $code === 0) {
        return array(
            'code' => 0,
            'body' => array(),
            'curl_error' => $curlErr !== '' ? $curlErr : hosting_blocked_msg(),
        );
    }

    $decoded = json_decode($res, true);
    if (!is_array($decoded)) {
        $decoded = array('raw' => $res);
    }

    return array('code' => $code, 'body' => $decoded);
}

if (isset($_GET['check'])) {
    $test = post('https://oauth2.googleapis.com/token', array('grant_type' => 'invalid'));
    respond(true, array(
        'php' => PHP_VERSION,
        'curl' => function_exists('curl_init'),
        'openssl' => function_exists('openssl_sign'),
        'allow_url_fopen' => (bool) ini_get('allow_url_fopen'),
        'google_reachable' => $test['code'] > 0,
        'google_http_code' => $test['code'],
        'google_error' => isset($test['curl_error']) ? $test['curl_error'] : '',
        'note' => $test['code'] === 0 ? hosting_blocked_msg() : 'Server can reach Google',
    ));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond(false, array('error' => 'POST only'));
}

if (!function_exists('openssl_sign')) {
    respond(false, array('error' => 'PHP OpenSSL is disabled on this server'));
}

function b64url($s) {
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

try {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        respond(false, array('error' => 'Empty request body'));
    }

    $in = json_decode($raw, true);
    if (!is_array($in)) {
        respond(false, array('error' => 'Invalid request JSON'));
    }

    $sa = json_decode(trim($in['serviceAccountJson']), true);
    $token = preg_replace('/\s+/', '', trim($in['token']));
    $title = trim($in['title']);

    if (!is_array($sa) || empty($sa['private_key']) || empty($sa['client_email']) || empty($sa['project_id'])) {
        respond(false, array('error' => 'Invalid Firebase service account JSON'));
    }
    if ($token === '') respond(false, array('error' => 'FCM token required'));
    if ($title === '') respond(false, array('error' => 'Title required'));

    $key = openssl_pkey_get_private(normalize_key($sa['private_key']));
    if ($key === false) {
        respond(false, array('step' => 'private_key', 'error' => 'Invalid private key. Upload original JSON from Firebase.'));
    }

    $msg = array(
        'token' => $token,
        'notification' => array(
            'title' => $title,
            'body' => trim(isset($in['body']) ? $in['body'] : ''),
        ),
    );

    if (!empty($in['image'])) {
        $msg['notification']['image'] = trim($in['image']);
    }

    if (!empty($in['data']) && is_array($in['data'])) {
        $data = array();
        foreach ($in['data'] as $k => $v) {
            if ($k !== '' && $v !== '') $data[(string) $k] = (string) $v;
        }
        if (!empty($data)) $msg['data'] = $data;
    }

    $now = time();
    $jwt = b64url(json_encode(array('alg' => 'RS256', 'typ' => 'JWT'))) . '.'
         . b64url(json_encode(array(
             'iss' => $sa['client_email'],
             'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
             'aud' => 'https://oauth2.googleapis.com/token',
             'iat' => $now,
             'exp' => $now + 3600,
         )));

    if (!openssl_sign($jwt, $sig, $key, OPENSSL_ALGO_SHA256)) {
        respond(false, array('step' => 'sign_jwt', 'error' => 'Failed to sign auth token'));
    }

    $jwt .= '.' . b64url($sig);

    $auth = post('https://oauth2.googleapis.com/token', array(
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt,
    ));

    if (!empty($auth['curl_error'])) {
        respond(false, array('step' => 'google_auth', 'error' => $auth['curl_error'], 'http_code' => $auth['code']));
    }

    $accessToken = isset($auth['body']['access_token']) ? trim($auth['body']['access_token']) : '';
    if ($accessToken === '') {
        $msg = ($auth['code'] === 0) ? hosting_blocked_msg() : pick_error($auth['body'], 'Google auth failed (HTTP ' . $auth['code'] . ')');
        respond(false, array('step' => 'google_auth', 'error' => $msg, 'http_code' => $auth['code'], 'details' => $auth['body']));
    }

    $fcm = post(
        'https://fcm.googleapis.com/v1/projects/' . $sa['project_id'] . '/messages:send',
        array('message' => $msg),
        array('Authorization: Bearer ' . $accessToken)
    );

    if (!empty($fcm['curl_error'])) {
        respond(false, array('step' => 'fcm_send', 'error' => $fcm['curl_error'], 'http_code' => $fcm['code']));
    }

    if ($fcm['code'] >= 200 && $fcm['code'] < 300) {
        respond(true, array('messageId' => isset($fcm['body']['name']) ? $fcm['body']['name'] : ''));
    }

    respond(false, array(
        'step' => 'fcm_send',
        'error' => pick_error($fcm['body'], 'FCM send failed (HTTP ' . $fcm['code'] . ')'),
        'http_code' => $fcm['code'],
        'details' => $fcm['body'],
    ));
} catch (Exception $e) {
    respond(false, array('step' => 'exception', 'error' => $e->getMessage()));
}
