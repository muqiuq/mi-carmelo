<?php
// Cron endpoint: send push notifications to all hungry users
// Call via: /api/cron_hungry.php?token=YOUR_CRON_SECRET

$game_config = require __DIR__ . '/../data/game_config.php';

$token = $_GET['token'] ?? '';
if (!hash_equals($game_config['cron_secret'], $token) || $token === 'CHANGE_ME') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

require_once __DIR__ . '/webpush_lib.php';

$data_dir = __DIR__ . '/../data';
$db_file = $data_dir . '/micarmelo.sqlite';

if (!file_exists($db_file)) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database not found']);
    exit;
}

$pdo = new PDO("sqlite:$db_file");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$interval = $game_config['feeding_interval_seconds'];

$stmt = $pdo->prepare("
    SELECT u.id, u.username, ps.endpoint, ps.p256dh, ps.auth
    FROM users u
    JOIN push_subscriptions ps ON ps.user_id = u.id
    WHERE (u.last_fed IS NULL OR (strftime('%s', 'now') - strftime('%s', u.last_fed)) >= ?)
      AND u.is_dead = 0
");
$stmt->execute([$interval]);
$rows = $stmt->fetchAll();

$vapid = getVapidKeys($data_dir);
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

header('Content-Type: application/json');
echo json_encode(['success' => true, 'sent' => $sent, 'failed' => $failed]);
