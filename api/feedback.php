<?php
require __DIR__ . '/_lib.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // The pending queue (unpublished submissions) is admin-only -- it may
    // contain a visitor's real name before it's been reviewed.
    ns_require_admin();
    $pending = ns_read_guarded_json('feedback_pending.php', array());
    if (!is_array($pending)) {
        $pending = array();
    }
    ns_json_response($pending);
}

if ($method === 'POST') {
    $body = ns_read_input();
    $action = isset($body['action']) ? $body['action'] : 'submit';

    if ($action === 'submit') {
        // Public: any visitor can leave feedback. It's stored in a
        // pending queue, not published -- an admin has to review and
        // explicitly publish it before it ever appears on the site, so
        // spam or bad-faith submissions can't show up automatically.
        $name = isset($body['name']) ? trim((string) $body['name']) : '';
        $quote = isset($body['quote']) ? trim((string) $body['quote']) : '';
        $title = isset($body['title']) ? trim((string) $body['title']) : '';
        $stars = isset($body['stars']) ? (int) $body['stars'] : 5;
        if ($stars < 1) {
            $stars = 1;
        }
        if ($stars > 5) {
            $stars = 5;
        }
        if ($name === '' || $quote === '') {
            ns_json_response(array('error' => 'invalid_feedback'), 400);
        }
        // Basic length caps so one submission can't bloat the store.
        // mbstring isn't guaranteed available on shared hosting, and a
        // plain byte-based substr() would corrupt multi-byte Bengali
        // text if it cuts a character in half, so truncate by bytes and
        // then back off to a clean UTF-8 boundary.
        $name = ns_utf8_safe_truncate($name, 240);
        $title = ns_utf8_safe_truncate($title, 240);
        $quote = ns_utf8_safe_truncate($quote, 3000);

        $item = array(
            'id' => uniqid('fb_', true),
            'created' => time(),
            'stars' => $stars,
            'name' => $name,
            'title' => $title,
            'quote' => $quote,
        );
        $result = ns_locked_update('feedback_pending.php', array(), function ($pending) use ($item) {
            if (!is_array($pending)) {
                $pending = array();
            }
            array_unshift($pending, $item);
            // Cap stored history so the file can't grow without bound.
            if (count($pending) > 500) {
                $pending = array_slice($pending, 0, 500);
            }
            return $pending;
        });
        if ($result === false) {
            ns_json_response(array('error' => 'write_failed'), 500);
        }
        ns_json_response(array('ok' => true));
    }

    if ($action === 'approve') {
        ns_require_admin();
        $id = isset($body['id']) ? $body['id'] : '';
        $found = null;
        ns_locked_update('feedback_pending.php', array(), function ($pending) use ($id, &$found) {
            if (!is_array($pending)) {
                $pending = array();
            }
            $keep = array();
            foreach ($pending as $it) {
                if ($found === null && isset($it['id']) && $it['id'] === $id) {
                    $found = $it;
                } else {
                    $keep[] = $it;
                }
            }
            return $keep;
        });
        if ($found === null) {
            ns_json_response(array('error' => 'not_found'), 404);
        }
        // Publish into content.php's "reviews" key, in the shape the
        // public site's review renderer expects (bilingual field
        // objects) -- only the bn side is filled since the visitor typed
        // in one language; bi()'s fallback chain handles display in
        // either site language.
        $review = array(
            'stars' => isset($found['stars']) ? $found['stars'] : 5,
            'photo' => '',
            'quote' => array('bn' => $found['quote'], 'en' => ''),
            'name' => array('bn' => $found['name'], 'en' => ''),
            'title' => array('bn' => isset($found['title']) ? $found['title'] : '', 'en' => ''),
            'avatar' => array('bn' => '??', 'en' => '??'),
        );
        $result = ns_locked_update('content.php', array(), function ($content) use ($review) {
            if (!is_array($content)) {
                $content = array();
            }
            if (!isset($content['reviews']) || !is_array($content['reviews'])) {
                $content['reviews'] = array();
            }
            array_unshift($content['reviews'], $review);
            return $content;
        });
        if ($result === false) {
            ns_json_response(array('error' => 'write_failed'), 500);
        }
        ns_json_response(array('ok' => true));
    }

    if ($action === 'reject') {
        ns_require_admin();
        $id = isset($body['id']) ? $body['id'] : '';
        $found = false;
        ns_locked_update('feedback_pending.php', array(), function ($pending) use ($id, &$found) {
            if (!is_array($pending)) {
                $pending = array();
            }
            $keep = array();
            foreach ($pending as $it) {
                if (isset($it['id']) && $it['id'] === $id) {
                    $found = true;
                } else {
                    $keep[] = $it;
                }
            }
            return $keep;
        });
        if (!$found) {
            ns_json_response(array('error' => 'not_found'), 404);
        }
        ns_json_response(array('ok' => true));
    }

    ns_json_response(array('error' => 'invalid_action'), 400);
}

ns_json_response(array('error' => 'method_not_allowed'), 405);
