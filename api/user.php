<?php
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if ($action === 'change_password') {
        $new_password = $data['new_password'] ?? '';
        
        if (strlen($new_password) < 4) {
             echo json_encode(['success' => false, 'error' => 'Password too short']);
             exit;
        }

        $hash = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->execute([$hash, $_SESSION['user_id']]);
        
        echo json_encode(['success' => true]);

    } elseif ($action === 'update_settings') {
        $q_per_challenge = (int)($data['questions_per_challenge'] ?? 3);
        
        if ($q_per_challenge < 3 || $q_per_challenge > 5) {
            echo json_encode(['success' => false, 'error' => 'Invalid number of questions (must be 3-5)']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE users SET questions_per_challenge = ? WHERE id = ?");
        $stmt->execute([$q_per_challenge, $_SESSION['user_id']]);
        
        echo json_encode(['success' => true]);

    } elseif ($action === 'revive') {
        // Revive the dead chicken: reset is_dead and last_fed
        $stmt = $pdo->prepare("UPDATE users SET is_dead = 0, last_fed = NULL WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        echo json_encode(['success' => true]);
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'get_settings') {
        $stmt = $pdo->prepare("SELECT username, questions_per_challenge, question_set FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        
        echo json_encode(['success' => true, 'settings' => $user]);
    } elseif ($action === 'get_stats') {
        $game_config = require __DIR__ . '/../data/game_config.php';
        $stmt = $pdo->prepare("SELECT total_points, coins, diamonds, stars, correct_streak_count, last_fed, is_dead, last_fiesta, pet_color FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $stats = $stmt->fetch();

        // Check death: if not fed within death_timeout_seconds, chicken dies
        if (!$stats['is_dead'] && !empty($stats['last_fed'])) {
            $last_fed_time = strtotime($stats['last_fed'] . " UTC");
            $now = time();
            if (($now - $last_fed_time) >= $game_config['death_timeout_seconds']) {
                $pdo->prepare("UPDATE users SET is_dead = 1 WHERE id = ?")->execute([$_SESSION['user_id']]);
                $stats['is_dead'] = 1;
            }
        }
        // Also die if never fed and we want to be lenient on first login — skip death for never-fed

        $stats['is_dead'] = (int)$stats['is_dead'];

        // Calculate hunger state based on the centralized feeding interval parameter configuration
        $is_hungry = false;
        if (!$stats['is_dead']) {
            if (empty($stats['last_fed'])) {
                $is_hungry = true;
            } else {
                $last_fed_time = strtotime($stats['last_fed'] . " UTC");
                $now = time();
                if (($now - $last_fed_time) >= $game_config['feeding_interval_seconds']) {
                    $is_hungry = true;
                }
            }
        }
        $stats['is_hungry'] = $is_hungry;

        // Provide next-feed timestamp so frontend can show countdown
        if (!$stats['is_dead'] && !$is_hungry && !empty($stats['last_fed'])) {
            $last_fed_time = strtotime($stats['last_fed'] . " UTC");
            $stats['next_feed_ts'] = $last_fed_time + $game_config['feeding_interval_seconds'];
        }

        $decorStmt = $pdo->prepare("SELECT slot_index FROM user_decorations WHERE user_id = ? AND item_code = 'flower_wall' ORDER BY slot_index ASC");
        $decorStmt->execute([$_SESSION['user_id']]);
        $flower_slots = [];
        foreach ($decorStmt->fetchAll() as $row) {
            $flower_slots[] = (int)$row['slot_index'];
        }
        $stats['flower_slots'] = $flower_slots;

        $lampStmt = $pdo->prepare("SELECT slot_index FROM user_decorations WHERE user_id = ? AND item_code = 'small_lamp' ORDER BY slot_index ASC");
        $lampStmt->execute([$_SESSION['user_id']]);
        $lamp_slots = [];
        foreach ($lampStmt->fetchAll() as $row) {
            $lamp_slots[] = (int)$row['slot_index'];
        }
        $stats['lamp_slots'] = $lamp_slots;

        $frameStmt = $pdo->prepare("SELECT slot_index FROM user_decorations WHERE user_id = ? AND item_code = 'picture_frame' ORDER BY slot_index ASC");
        $frameStmt->execute([$_SESSION['user_id']]);
        $frame_slots = [];
        foreach ($frameStmt->fetchAll() as $row) {
            $frame_slots[] = (int)$row['slot_index'];
        }
        $stats['frame_slots'] = $frame_slots;

        $bedStmt = $pdo->prepare("SELECT COUNT(*) FROM user_decorations WHERE user_id = ? AND item_code = 'chicken_house'");
        $bedStmt->execute([$_SESSION['user_id']]);
        $stats['bed_owned'] = ((int)$bedStmt->fetchColumn()) > 0;

        // Fiesta cooldown
        $fiestaCd = $game_config['fiesta_cooldown_seconds'] ?? 300;
        if (!empty($stats['last_fiesta'])) {
            $fiestaTime = strtotime($stats['last_fiesta'] . ' UTC');
            $elapsed = time() - $fiestaTime;
            $stats['fiesta_cooldown'] = max(0, $fiestaCd - $elapsed);
        } else {
            $stats['fiesta_cooldown'] = 0;
        }
        $stats['fiesta_mode'] = get_app_state($pdo, 'fiesta_mode', 'normal');
        unset($stats['last_fiesta']);

        unset($stats['last_fed']);
        
        echo json_encode(['success' => true, 'stats' => $stats]);
    }
}