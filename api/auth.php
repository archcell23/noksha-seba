<?php
require __DIR__ . '/_lib.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    ns_json_response(array('authed' => ns_is_admin()));
}

if ($method === 'POST') {
    $body = ns_read_input();
    $action = isset($body['action']) ? $body['action'] : 'login';

    if ($action === 'logout') {
        $_SESSION = array();
        session_destroy();
        ns_json_response(array('ok' => true));
    }

    $password = isset($body['password']) ? (string) $body['password'] : '';
    $configPath = NS_DATA_DIR . '/config.php';
    $config = file_exists($configPath) ? include $configPath : array();
    $hash = isset($config['password_hash']) ? $config['password_hash'] : '';

    if ($password !== '' && $hash !== '' && password_verify($password, $hash)) {
        session_regenerate_id(true);
        $_SESSION['ns_admin'] = true;
        ns_json_response(array('ok' => true));
    }

    ns_json_response(array('error' => 'invalid_password'), 401);
}

ns_json_response(array('error' => 'method_not_allowed'), 405);
