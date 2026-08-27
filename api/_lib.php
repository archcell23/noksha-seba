<?php
// Shared helpers for নকশা সেবা's API endpoints.
// Written for broad PHP 7.4+ compatibility (avoids 8.1-only syntax) since
// the exact PHP version on shared hosting isn't known in advance.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Deliberately OUTSIDE public_html (a sibling of it, one level up), not
// just gitignored inside it. On Hostinger (and most git-based deploy
// platforms), every deploy syncs public_html to exactly match the repo —
// including deleting untracked files. A gitignored-but-inside-public_html
// data folder would get wiped on every push regardless. Living outside
// the web root also means it's never directly web-accessible at all,
// regardless of .htaccess/guard-line correctness — stronger than the
// original design, not just a workaround.
define('NS_DATA_DIR', dirname(dirname(__DIR__)) . '/private_data');

if (!is_dir(NS_DATA_DIR)) {
    @mkdir(NS_DATA_DIR, 0770, true);
}

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

// Truncates a UTF-8 string to at most $maxBytes bytes without cutting a
// multi-byte character (e.g. Bengali) in half. Avoids depending on the
// mbstring extension, which isn't guaranteed to be enabled everywhere.
function ns_utf8_safe_truncate($s, $maxBytes) {
    if (strlen($s) <= $maxBytes) {
        return $s;
    }
    $s = substr($s, 0, $maxBytes);
    while ($s !== '' && (ord(substr($s, -1)) & 0xC0) === 0x80) {
        $s = substr($s, 0, -1);
    }
    return $s;
}

function ns_write_guarded_json($file, $data) {
    $path = NS_DATA_DIR . '/' . $file;
    $guard = "<?php http_response_code(403); exit('Forbidden'); ?>\n";
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return file_put_contents($path, $guard . $json, LOCK_EX) !== false;
}

// Reads, modifies, and writes a guard-prefixed JSON file as one atomic,
// locked operation. ns_read_guarded_json()+ns_write_guarded_json() used as
// two separate steps left a gap where two near-simultaneous requests could
// both read the same "before" state and then each write back a version
// that silently discards the other's change (a lost-update race) -- this
// is what was making gallery photos added in quick succession vanish.
// $mutator receives the current decoded data (or $default) and returns
// the new data to write; the whole read+mutate+write happens under a
// single exclusive lock that blocks (rather than fails) when contended,
// so concurrent requests queue up and apply in turn instead of racing.
function ns_locked_update($file, $default, $mutator) {
    $path = NS_DATA_DIR . '/' . $file;
    $fp = fopen($path, 'c+');
    if ($fp === false) {
        return false;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }
    $raw = stream_get_contents($fp);
    $marker = '?>';
    $pos = strpos($raw, $marker);
    $data = $default;
    if ($pos !== false) {
        $decoded = json_decode(substr($raw, $pos + strlen($marker)), true);
        if ($decoded !== null) {
            $data = $decoded;
        }
    }
    $data = $mutator($data);
    $guard = "<?php http_response_code(403); exit('Forbidden'); ?>\n";
    $out = $guard . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $out);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $data;
}
