<?php
require __DIR__ . '/_lib.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $bookings = ns_read_guarded_json('bookings.php', array());
    if (!is_array($bookings)) {
        $bookings = array();
    }

    // Public, unauthenticated lookup used by the booking flow's schedule
    // step to avoid double-booking a slot — returns only "date|time" keys,
    // never customer names/phones/etc.
    if (isset($_GET['busy'])) {
        $keys = array();
        foreach ($bookings as $b) {
            if (isset($b['date']) && isset($b['time'])) {
                $keys[] = $b['date'] . '|' . $b['time'];
            }
        }
        ns_json_response(array_values(array_unique($keys)));
    }

    // Full booking list (with customer details) is admin-only.
    ns_require_admin();
    ns_json_response($bookings);
}

if ($method === 'POST') {
    $body = ns_read_input();
    $action = isset($body['action']) ? $body['action'] : 'create';

    // Both branches below use ns_locked_update() so the read+modify+write
    // is one atomic, locked step -- otherwise two bookings (or a booking
    // and a status change) arriving close together could each read the
    // same "before" list and each write back a version that silently
    // drops the other's change.
    if ($action === 'status') {
        ns_require_admin();
        $id = isset($body['id']) ? $body['id'] : '';
        $status = isset($body['status']) ? $body['status'] : '';
        $found = false;
        $result = ns_locked_update('bookings.php', array(), function($bookings) use ($id, $status, &$found) {
            if (!is_array($bookings)) {
                $bookings = array();
            }
            foreach ($bookings as &$b) {
                if (isset($b['id']) && $b['id'] === $id) {
                    $b['status'] = $status;
                    $found = true;
                    break;
                }
            }
            unset($b);
            return $bookings;
        });
        if (!$found) {
            ns_json_response(array('error' => 'not_found'), 404);
        }
        if ($result === false) {
            ns_json_response(array('error' => 'write_failed'), 500);
        }
        ns_json_response(array('ok' => true));
    }

    if ($action === 'delete') {
        ns_require_admin();
        $id = isset($body['id']) ? $body['id'] : '';
        $found = false;
        $result = ns_locked_update('bookings.php', array(), function($bookings) use ($id, &$found) {
            if (!is_array($bookings)) {
                $bookings = array();
            }
            $keep = array();
            foreach ($bookings as $b) {
                if (isset($b['id']) && $b['id'] === $id) {
                    $found = true;
                } else {
                    $keep[] = $b;
                }
            }
            return $keep;
        });
        if (!$found) {
            ns_json_response(array('error' => 'not_found'), 404);
        }
        if ($result === false) {
            ns_json_response(array('error' => 'write_failed'), 500);
        }
        ns_json_response(array('ok' => true));
    }

    // Creating a booking is public — any visitor completing the booking
    // flow needs to be able to do this without being an admin.
    $booking = isset($body['booking']) ? $body['booking'] : null;
    if (!is_array($booking) || empty($booking['id']) || empty($booking['name']) || empty($booking['mobile'])) {
        ns_json_response(array('error' => 'invalid_booking'), 400);
    }

    $result = ns_locked_update('bookings.php', array(), function($bookings) use ($booking) {
        if (!is_array($bookings)) {
            $bookings = array();
        }
        // Guard against an ID collision (astronomically unlikely given the
        // client generates a timestamp+random ID, but cheap to check).
        foreach ($bookings as $existing) {
            if (isset($existing['id']) && $existing['id'] === $booking['id']) {
                return $bookings;
            }
        }
        array_unshift($bookings, $booking);
        // Cap stored history so the file can't grow without bound.
        if (count($bookings) > 5000) {
            $bookings = array_slice($bookings, 0, 5000);
        }
        return $bookings;
    });
    if ($result === false) {
        ns_json_response(array('error' => 'write_failed'), 500);
    }
    ns_json_response(array('ok' => true, 'id' => $booking['id']));
}

ns_json_response(array('error' => 'method_not_allowed'), 405);
