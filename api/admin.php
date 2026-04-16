<?php
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !$_SESSION['isadmin']) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'list_users') {
        $stmt = $pdo->query("SELECT id, username, isadmin, total_points, diamonds, stars, last_fed FROM users");
        $users = $stmt->fetchAll();
        foreach ($users as &$u) {
            $u['id'] = (int)$u['id'];
            $u['isadmin'] = (int)$u['isadmin'];
            $u['total_points'] = (int)$u['total_points'];
            $u['diamonds'] = (int)$u['diamonds'];
            $u['stars'] = (int)$u['stars'];
        }
        unset($u);
        echo json_encode(['success' => true, 'users' => $users]);
    } elseif ($action === 'user_stats') {
        $user_id = (int)($_GET['user_id'] ?? 0);
        if ($user_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
            exit;
        }
        require_once 'questions.php';
        $all_questions = getQuestions();
        $q_map = [];
        foreach ($all_questions as $q) {
            $q_map[$q['id']] = $q['question'];
        }

        $stmt = $pdo->prepare("SELECT question_hash, correct_attempts, incorrect_attempts, knows_well FROM user_knowledge WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $rows = $stmt->fetchAll();

        $knowledge = [];
        foreach ($rows as $row) {
            $knowledge[] = [
                'question' => $q_map[$row['question_hash']] ?? '(deleted question)',
                'correct' => (int)$row['correct_attempts'],
                'incorrect' => (int)$row['incorrect_attempts'],
                'knows_well' => (int)$row['knows_well']
            ];
        }

        echo json_encode(['success' => true, 'knowledge' => $knowledge]);
    } elseif ($action === 'get_yaml') {
        $file = __DIR__ . '/../data/questions.yaml';
        $content = file_exists($file) ? file_get_contents($file) : '';
        echo json_encode(['success' => true, 'content' => $content]);
    } elseif ($action === 'shop_stats') {
        $items = $pdo->query("SELECT id, code, name, currency, price, max_quantity, sort_order FROM shop_items WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();
        $users = $pdo->query("SELECT id, username FROM users ORDER BY username ASC")->fetchAll();
        $decoStmt = $pdo->query("SELECT user_id, item_code, COUNT(*) AS owned FROM user_decorations GROUP BY user_id, item_code");
        $owned = [];
        foreach ($decoStmt as $row) {
            $owned[(int)$row['user_id']][$row['item_code']] = (int)$row['owned'];
        }
        echo json_encode(['success' => true, 'items' => $items, 'users' => $users, 'owned' => $owned]);
    } elseif ($action === 'list_tokens') {
        $game_config = require __DIR__ . '/../data/game_config.php';
        $tokens = $pdo->query("SELECT id, token, created_at, expires_at FROM access_tokens ORDER BY created_at DESC")->fetchAll();
        echo json_encode([
            'success' => true,
            'tokens' => $tokens,
            'require_access_token' => get_app_state($pdo, 'require_access_token', '0') === '1',
            'base_url' => rtrim($game_config['base_url'] ?? 'http://localhost:8080', '/')
        ]);
    } elseif ($action === 'get_fiesta_settings') {
        echo json_encode([
            'success' => true,
            'fiesta_mode' => get_app_state($pdo, 'fiesta_mode', 'normal')
        ]);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if ($action === 'save_yaml') {
        $content = $data['content'] ?? '';
        $file = __DIR__ . '/../data/questions.yaml';
        $result = file_put_contents($file, $content);
        if ($result === false) {
            echo json_encode(['success' => false, 'error' => 'Failed to write questions file']);
        } else {
            echo json_encode(['success' => true]);
        }
    } elseif ($action === 'reset_feed') {
        $user_id = (int)($data['user_id'] ?? 0);
        if ($user_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
            exit;
        }
        $stmt = $pdo->prepare("UPDATE users SET last_fed = NULL WHERE id = ?");
        $stmt->execute([$user_id]);
        echo json_encode(['success' => true]);
    } elseif ($action === 'create_user') {
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';
        $isadmin = isset($data['isadmin']) ? (int)$data['isadmin'] : 0;
        
        try {
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, isadmin) VALUES (?, ?, ?)");
            $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $isadmin]);
            echo json_encode(['success' => true, 'user_id' => $pdo->lastInsertId()]);
        } catch(PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'User might already exist.']);
        }
    } elseif ($action === 'delete_user') {
        $user_id = (int)($data['user_id'] ?? 0);
        if ($user_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
            exit;
        }
        if ($user_id === (int)$_SESSION['user_id']) {
            echo json_encode(['success' => false, 'error' => 'Cannot delete yourself']);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        echo json_encode(['success' => true]);
    } elseif ($action === 'edit_user') {
        $user_id = (int)($data['user_id'] ?? 0);
        if ($user_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
            exit;
        }
        $username = trim($data['username'] ?? '');
        $isadmin = isset($data['isadmin']) ? (int)$data['isadmin'] : 0;
        $password = $data['password'] ?? '';

        if ($username === '') {
            echo json_encode(['success' => false, 'error' => 'Username cannot be empty']);
            exit;
        }

        try {
            $total_points = isset($data['total_points']) ? max(0, (int)$data['total_points']) : null;
            $diamonds = isset($data['diamonds']) ? max(0, (int)$data['diamonds']) : null;
            $stars = isset($data['stars']) ? max(0, (int)$data['stars']) : null;

            if ($password !== '') {
                $stmt = $pdo->prepare("UPDATE users SET username = ?, password_hash = ?, isadmin = ?, total_points = COALESCE(?, total_points), diamonds = COALESCE(?, diamonds), stars = COALESCE(?, stars) WHERE id = ?");
                $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $isadmin, $total_points, $diamonds, $stars, $user_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET username = ?, isadmin = ?, total_points = COALESCE(?, total_points), diamonds = COALESCE(?, diamonds), stars = COALESCE(?, stars) WHERE id = ?");
                $stmt->execute([$username, $isadmin, $total_points, $diamonds, $stars, $user_id]);
            }
            echo json_encode(['success' => true]);
        } catch(PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Username might already exist.']);
        }
    } elseif ($action === 'clean_deleted_stats') {
        $user_id = (int)($data['user_id'] ?? 0);
        if ($user_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
            exit;
        }
        require_once 'questions.php';
        $all_questions = getQuestions();
        $valid_hashes = [];
        foreach ($all_questions as $q) {
            $valid_hashes[] = $q['id'];
        }
        if (empty($valid_hashes)) {
            $stmt = $pdo->prepare("DELETE FROM user_knowledge WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $deleted = $stmt->rowCount();
        } else {
            $placeholders = implode(',', array_fill(0, count($valid_hashes), '?'));
            $params = array_merge([$user_id], $valid_hashes);
            $stmt = $pdo->prepare("DELETE FROM user_knowledge WHERE user_id = ? AND question_hash NOT IN ($placeholders)");
            $stmt->execute($params);
            $deleted = $stmt->rowCount();
        }
        echo json_encode(['success' => true, 'deleted' => $deleted]);
    } elseif ($action === 'clear_decorations') {
        $user_id = (int)($data['user_id'] ?? 0);
        if ($user_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
            exit;
        }
        try {
            $pdo->beginTransaction();
            $decoStmt = $pdo->prepare("SELECT item_code, COUNT(*) AS cnt FROM user_decorations WHERE user_id = ? GROUP BY item_code");
            $decoStmt->execute([$user_id]);
            $groups = $decoStmt->fetchAll();
            $refund = ['points' => 0, 'diamonds' => 0, 'stars' => 0];
            foreach ($groups as $g) {
                $shopStmt = $pdo->prepare("SELECT currency, price FROM shop_items WHERE code = ?");
                $shopStmt->execute([$g['item_code']]);
                $shopItem = $shopStmt->fetch();
                if ($shopItem) {
                    $key = $shopItem['currency'] === 'points' ? 'points' : $shopItem['currency'];
                    $refund[$key] += (int)$shopItem['price'] * (int)$g['cnt'];
                }
            }
            $delStmt = $pdo->prepare("DELETE FROM user_decorations WHERE user_id = ?");
            $delStmt->execute([$user_id]);
            $totalDeleted = $delStmt->rowCount();
            if ($refund['points'] > 0) {
                $pdo->prepare("UPDATE users SET total_points = total_points + ? WHERE id = ?")->execute([$refund['points'], $user_id]);
            }
            if ($refund['diamonds'] > 0) {
                $pdo->prepare("UPDATE users SET diamonds = diamonds + ? WHERE id = ?")->execute([$refund['diamonds'], $user_id]);
            }
            if ($refund['stars'] > 0) {
                $pdo->prepare("UPDATE users SET stars = stars + ? WHERE id = ?")->execute([$refund['stars'], $user_id]);
            }
            $pdo->commit();
            echo json_encode(['success' => true, 'deleted' => $totalDeleted, 'refund' => $refund]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Refund failed']);
        }
    } elseif ($action === 'kill_chicken') {
        $user_id = (int)($data['user_id'] ?? 0);
        if ($user_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
            exit;
        }
        $stmt = $pdo->prepare("UPDATE users SET is_dead = 1 WHERE id = ?");
        $stmt->execute([$user_id]);
        echo json_encode(['success' => true]);
    } elseif ($action === 'revive_chicken') {
        $user_id = (int)($data['user_id'] ?? 0);
        if ($user_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
            exit;
        }
        $stmt = $pdo->prepare("UPDATE users SET is_dead = 0, last_fed = NULL WHERE id = ?");
        $stmt->execute([$user_id]);
        echo json_encode(['success' => true]);
    } elseif ($action === 'generate_token') {
        $game_config = require __DIR__ . '/../data/game_config.php';
        $token = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare("INSERT INTO access_tokens (token, expires_at) VALUES (?, datetime('now', '+1 year'))");
        $stmt->execute([$token]);
        $base = rtrim($game_config['base_url'] ?? 'http://localhost:8080', '/');
        $url = $base . '/?t=' . urlencode($token);
        echo json_encode(['success' => true, 'token' => $token, 'url' => $url]);
    } elseif ($action === 'delete_token') {
        $token_id = (int)($data['token_id'] ?? 0);
        if ($token_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid token ID']);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM access_tokens WHERE id = ?");
        $stmt->execute([$token_id]);
        echo json_encode(['success' => true]);
    } elseif ($action === 'toggle_require_token') {
        $current = get_app_state($pdo, 'require_access_token', '0') === '1';
        $new_val = !$current;
        set_app_state($pdo, 'require_access_token', $new_val ? '1' : '0');
        echo json_encode(['success' => true, 'require_access_token' => $new_val]);

    } elseif ($action === 'reset_fiesta') {
        $user_id = (int)($data['user_id'] ?? 0);
        if ($user_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
            exit;
        }
        $stmt = $pdo->prepare("UPDATE users SET last_fiesta = NULL WHERE id = ?");
        $stmt->execute([$user_id]);
        echo json_encode(['success' => true]);

    } elseif ($action === 'toggle_fiesta') {
        $current = get_app_state($pdo, 'fiesta_mode', 'normal');
        $cycle = ['normal' => 'always', 'always' => 'disabled', 'disabled' => 'normal'];
        $new_val = $cycle[$current] ?? 'normal';
        set_app_state($pdo, 'fiesta_mode', $new_val);
        echo json_encode(['success' => true, 'fiesta_mode' => $new_val]);
    }
}