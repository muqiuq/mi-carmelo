<?php
require_once 'config.php';
require_once 'webpush_lib.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$dbDir = __DIR__ . '/../data';

// Public endpoint: get VAPID public key (no auth required)
if ($action === 'vapid_public_key') {
    $vapid = getVapidKeys($dbDir);
    echo json_encode(['success' => true, 'publicKey' => $vapid['publicKey']]);
    exit;
}

// All other actions require authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Debug info for admin panel (GET)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'push_debug') {
    if (!$_SESSION['isadmin']) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    $vapid = getVapidKeys($dbDir);

    // Total subscriptions
    $total = (int)$pdo->query("SELECT COUNT(*) FROM push_subscriptions")->fetchColumn();

    // Per-user subscription counts
    $stmt = $pdo->query("
        SELECT u.id, u.username, COUNT(ps.id) as sub_count
        FROM users u
        LEFT JOIN push_subscriptions ps ON ps.user_id = u.id
        GROUP BY u.id
        ORDER BY u.id
    ");
    $users = $stmt->fetchAll();

    // Subscription details
    $subs = $pdo->query("
        SELECT ps.user_id, u.username, ps.endpoint, ps.created_at
        FROM push_subscriptions ps
        JOIN users u ON u.id = ps.user_id
        ORDER BY ps.created_at DESC
    ")->fetchAll();

    echo json_encode([
        'success' => true,
        'vapid_public_key' => $vapid['publicKey'],
        'total_subscriptions' => $total,
        'users' => $users,
        'subscriptions' => $subs
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if ($action === 'subscribe') {
        $endpoint = $data['endpoint'] ?? '';
        $p256dh = $data['keys']['p256dh'] ?? '';
        $auth = $data['keys']['auth'] ?? '';

        if (!$endpoint || !$p256dh || !$auth) {
            echo json_encode(['success' => false, 'error' => 'Missing subscription data']);
            exit;
        }

        // Upsert: replace if endpoint already exists
        $stmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?");
        $stmt->execute([$endpoint]);

        $stmt = $pdo->prepare(
            "INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$_SESSION['user_id'], $endpoint, $p256dh, $auth]);

        echo json_encode(['success' => true]);

    } elseif ($action === 'unsubscribe') {
        $endpoint = $data['endpoint'] ?? '';
        if ($endpoint) {
            $stmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint = ? AND user_id = ?");
            $stmt->execute([$endpoint, $_SESSION['user_id']]);
        }
        echo json_encode(['success' => true]);

    } elseif ($action === 'test_notify') {
        // Admin only
        if (!$_SESSION['isadmin']) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }

        $targetUserId = (int)($data['user_id'] ?? 0);
        if ($targetUserId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
            exit;
        }

        $vapid = getVapidKeys($dbDir);
        $stmt = $pdo->prepare("SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?");
        $stmt->execute([$targetUserId]);
        $subs = $stmt->fetchAll();

        if (empty($subs)) {
            echo json_encode(['success' => false, 'error' => 'No push subscription for this user']);
            exit;
        }

        $payload = json_encode([
            'title' => 'Mi Carmelo',
            'body' => '🐔 This is a test notification!',
        ]);

        $sent = 0;
        $failed = 0;
        $details = [];
        foreach ($subs as $sub) {
            $shortEndpoint = substr($sub['endpoint'], 0, 60) . (strlen($sub['endpoint']) > 60 ? '...' : '');
            try {
                $result = sendWebPush($sub['endpoint'], $sub['p256dh'], $sub['auth'], $payload, $vapid);
                if ($result['success']) {
                    $sent++;
                    $details[] = ['endpoint' => $shortEndpoint, 'status' => 'ok', 'httpCode' => $result['httpCode']];
                } else {
                    $failed++;
                    $details[] = ['endpoint' => $shortEndpoint, 'status' => 'failed', 'httpCode' => $result['httpCode'], 'body' => substr($result['body'] ?? '', 0, 200)];
                    // Remove expired/invalid subscriptions (410 Gone or 404)
                    if ($result['httpCode'] === 410 || $result['httpCode'] === 404) {
                        $del = $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?");
                        $del->execute([$sub['endpoint']]);
                        $details[count($details) - 1]['removed'] = true;
                    }
                }
            } catch (Exception $e) {
                $failed++;
                $details[] = ['endpoint' => $shortEndpoint, 'status' => 'error', 'message' => $e->getMessage()];
            }
        }

        echo json_encode(['success' => true, 'sent' => $sent, 'failed' => $failed, 'details' => $details]);

    } elseif ($action === 'clear_subscriptions') {
        // Admin only: delete all push subscriptions for a user
        if (!$_SESSION['isadmin']) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }

        $targetUserId = (int)($data['user_id'] ?? 0);
        if ($targetUserId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE user_id = ?");
        $stmt->execute([$targetUserId]);
        $deleted = $stmt->rowCount();

        echo json_encode(['success' => true, 'deleted' => $deleted]);

    } elseif ($action === 'send_hungry') {
        // Admin only: send hunger notifications to all hungry users with push subscriptions
        if (!$_SESSION['isadmin']) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }

        $game_config = require __DIR__ . '/../data/game_config.php';
        $interval = $game_config['feeding_interval_seconds'];

        $stmt = $pdo->prepare("
            SELECT u.id, u.username, ps.endpoint, ps.p256dh, ps.auth
            FROM users u
            JOIN push_subscriptions ps ON ps.user_id = u.id
            WHERE u.last_fed IS NULL
               OR (strftime('%s', 'now') - strftime('%s', u.last_fed)) >= ?
        ");
        $stmt->execute([$interval]);
        $rows = $stmt->fetchAll();

        $vapid = getVapidKeys($dbDir);
        $sent = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $payload = json_encode([
                'title' => 'Mi Carmelo',
                'body' => "🐔 {$row['username']}'s chicken is hungry! Time to feed!",
            ]);
            try {
                $result = sendWebPush($row['endpoint'], $row['p256dh'], $row['auth'], $payload, $vapid);
                if ($result['success']) {
                    $sent++;
                } else {
                    $failed++;
                    if ($result['httpCode'] === 410 || $result['httpCode'] === 404) {
                        $del = $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?");
                        $del->execute([$row['endpoint']]);
                    }
                }
            } catch (Exception $e) {
                $failed++;
            }
        }

        echo json_encode(['success' => true, 'sent' => $sent, 'failed' => $failed]);
    }
}
