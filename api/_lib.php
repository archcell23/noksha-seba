<?php
// Shared helpers for নকশা সেবা's API endpoints.
// Written for broad PHP 7.4+ compatibility (avoids 8.1-only syntax) since
// the exact PHP version on shared hosting isn't known in advance.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('NS_DATA_DIR', __DIR__ . '/../data');

header('X-Content-Type-Options: nosniff');

function ns_json_response($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ns_read_input() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : array();
}

function ns_is_admin() {
    return !empty($_SESSION['ns_admin']);
}

function ns_require_admin() {
    if (!ns_is_admin()) {
        ns_json_response(array('error' => 'unauthorized'), 401);
    }
}

// Reads a guard-prefixed JSON data file: the file starts with a line of
// PHP that makes direct HTTP requests to it exit immediately, followed by
// the actual JSON payload. We strip that guard line and parse the rest.
function ns_read_guarded_json($file, $default) {
    $path = NS_DATA_DIR . '/' . $file;
    if (!file_exists($path)) {
        return $default;
    }
    $raw = file_get_contents($path);
    $marker = '?>';
    $pos = strpos($raw, $marker);
    if ($pos === false) {
        return $default;
    }
    $json = substr($raw, $pos + strlen($marker));
    $data = json_decode($json, true);
    return $data === null ? $default : $data;
}

function ns_write_guarded_json($file, $data) {
    $path = NS_DATA_DIR . '/' . $file;
    $guard = "<?php http_response_code(403); exit('Forbidden'); ?>\n";
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return file_put_contents($path, $guard . $json, LOCK_EX) !== false;
}
