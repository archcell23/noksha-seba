<?php
require __DIR__ . '/_lib.php';

$method = $_SERVER['REQUEST_METHOD'];

// Anything in here is public content shown to every visitor, so GET needs
// no auth. Only writing (POST) requires an admin session.
if ($method === 'GET') {
    $data = ns_read_guarded_json('content.php', new stdClass());
    ns_json_response($data);
}

if ($method === 'POST') {
    ns_require_admin();
    $body = ns_read_input();

    if (!isset($body['key'])) {
        ns_json_response(array('error' => 'missing_key'), 400);
    }
    $key = $body['key'];
    $value = isset($body['value']) ? $body['value'] : null;

    // Allowlist keeps this endpoint from being used to write arbitrary
    // fields into the content store.
    $allowed = array(
        'hero', 'services', 'trust', 'team', 'faq', 'reviews', 'gallery',
        'prices', 'slots', 'pkg_dur', 'pkg_feat', 'nav_order', 'nav_mobile', 'site', 'mobile_vis', 'contact', 'bot_nav', 'assure',
    );
    if (!in_array($key, $allowed, true)) {
        ns_json_response(array('error' => 'invalid_key'), 400);
    }

    // Merge into the existing stored object one key at a time, so two
    // admins (or two tabs) saving different sections at the same time
    // can't clobber each other's unrelated changes. The whole read+merge
    // +write happens under one lock (see ns_locked_update) so two
    // near-simultaneous saves to the SAME key can't race each other and
    // silently drop one of them either.
    $result = ns_locked_update('content.php', array(), function($content) use ($key, $value) {
        if (!is_array($content)) {
            $content = array();
        }
        // Storing a literal null would make every future reader of this
        // key get null instead of its normal default -- treat "save null"
        // as "clear this key" instead, so it's as if nothing was ever
        // saved here.
        if ($value === null) {
            unset($content[$key]);
        } else {
            $content[$key] = $value;
        }
        return $content;
    });

    if ($result === false) {
        ns_json_response(array('error' => 'write_failed'), 500);
    }
    ns_json_response(array('ok' => true));
}

ns_json_response(array('error' => 'method_not_allowed'), 405);
