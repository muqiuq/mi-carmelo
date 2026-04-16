<?php
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'list') {
    $stmt = $pdo->prepare("SELECT total_points, diamonds, stars FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }

    $itemsStmt = $pdo->query("SELECT id, code, name, description, currency, price, max_quantity, sold_count FROM shop_items WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
    $items = $itemsStmt->fetchAll();

    $ownedStmt = $pdo->prepare("SELECT item_code, COUNT(*) AS c FROM user_decorations WHERE user_id = ? GROUP BY item_code");
    $ownedStmt->execute([$_SESSION['user_id']]);
    $ownedByCode = [];
    foreach ($ownedStmt->fetchAll() as $row) {
        $ownedByCode[$row['item_code']] = (int)$row['c'];
    }

    foreach ($items as &$item) {
        $item['id'] = (int)$item['id'];
        $item['price'] = (int)$item['price'];
        $item['max_quantity'] = (int)$item['max_quantity'];
        $item['sold_count'] = (int)$item['sold_count'];
        $owned = $ownedByCode[$item['code']] ?? 0;
        $item['remaining'] = max(0, $item['max_quantity'] - $owned);

        $balance = 0;
        if ($item['currency'] === 'points') {
            $balance = (int)$user['total_points'];
        } elseif ($item['currency'] === 'diamonds') {
            $balance = (int)$user['diamonds'];
        } elseif ($item['currency'] === 'stars') {
            $balance = (int)$user['stars'];
        }

        $item['can_afford'] = ($balance >= $item['price']) && ($item['remaining'] > 0);
    }
    unset($item);

    echo json_encode([
        'success' => true,
        'balances' => [
            'points' => (int)$user['total_points'],
            'diamonds' => (int)$user['diamonds'],
            'stars' => (int)$user['stars']
        ],
        'items' => $items
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'buy') {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    $item_id = (int)($data['item_id'] ?? 0);

    if ($item_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid item']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $itemStmt = $pdo->prepare("SELECT id, code, name, currency, price, max_quantity, sold_count, is_active FROM shop_items WHERE id = ?");
        $itemStmt->execute([$item_id]);
        $item = $itemStmt->fetch();

        if (!$item || (int)$item['is_active'] !== 1) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Artikel nicht verfügbar']);
            exit;
        }

        $ownedCountStmt = $pdo->prepare("SELECT COUNT(*) FROM user_decorations WHERE user_id = ? AND item_code = ?");
        $ownedCountStmt->execute([$_SESSION['user_id'], $item['code']]);
        $ownedCount = (int)$ownedCountStmt->fetchColumn();

        if ($ownedCount >= (int)$item['max_quantity']) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Ausverkauft']);
            exit;
        }

        $userStmt = $pdo->prepare("SELECT total_points, diamonds, stars FROM users WHERE id = ?");
        $userStmt->execute([$_SESSION['user_id']]);
        $user = $userStmt->fetch();
        if (!$user) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'User not found']);
            exit;
        }

        $currency = $item['currency'];
        $price = (int)$item['price'];

        if ($currency === 'points') {
            if ((int)$user['total_points'] < $price) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Nicht genug Points']);
                exit;
            }
            $upd = $pdo->prepare("UPDATE users SET total_points = total_points - ? WHERE id = ?");
            $upd->execute([$price, $_SESSION['user_id']]);
        } elseif ($currency === 'diamonds') {
            if ((int)$user['diamonds'] < $price) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Nicht genug Diamonds']);
                exit;
            }
            $upd = $pdo->prepare("UPDATE users SET diamonds = diamonds - ? WHERE id = ?");
            $upd->execute([$price, $_SESSION['user_id']]);
        } elseif ($currency === 'stars') {
            if ((int)$user['stars'] < $price) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Nicht genug Stars']);
                exit;
            }
            $upd = $pdo->prepare("UPDATE users SET stars = stars - ? WHERE id = ?");
            $upd->execute([$price, $_SESSION['user_id']]);
        } else {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Währung nicht unterstützt']);
            exit;
        }

        // Store purchase in user_decorations with a per-user, per-item slot index.
        if ($item['code'] === 'flower_wall') {
            // Flowers use fixed background slots 0..9 (never overlap).
            $slotsStmt = $pdo->prepare("SELECT slot_index FROM user_decorations WHERE user_id = ? AND item_code = 'flower_wall' ORDER BY slot_index ASC");
            $slotsStmt->execute([$_SESSION['user_id']]);
            $used = [];
            foreach ($slotsStmt->fetchAll() as $r) {
                $used[(int)$r['slot_index']] = true;
            }

            $freeSlot = null;
            for ($i = 0; $i < 10; $i++) {
                if (!isset($used[$i])) {
                    $freeSlot = $i;
                    break;
                }
            }

            if ($freeSlot === null) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Keine freien Blumen-Plätze']);
                exit;
            }

            $insDecor = $pdo->prepare("INSERT INTO user_decorations (user_id, item_code, slot_index) VALUES (?, 'flower_wall', ?)");
            $insDecor->execute([$_SESSION['user_id'], $freeSlot]);
        } elseif ($item['code'] === 'small_lamp') {
            // Lamps use fixed slots: 0 = wall-left, 1 = wall-right, 2 = floor lamp.
            $slotsStmt = $pdo->prepare("SELECT slot_index FROM user_decorations WHERE user_id = ? AND item_code = 'small_lamp' ORDER BY slot_index ASC");
            $slotsStmt->execute([$_SESSION['user_id']]);
            $used = [];
            foreach ($slotsStmt->fetchAll() as $r) {
                $used[(int)$r['slot_index']] = true;
            }

            $freeSlot = null;
            for ($i = 0; $i < 3; $i++) {
                if (!isset($used[$i])) {
                    $freeSlot = $i;
                    break;
                }
            }

            if ($freeSlot === null) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Keine freien Lampen-Plätze']);
                exit;
            }

            $insDecor = $pdo->prepare("INSERT INTO user_decorations (user_id, item_code, slot_index) VALUES (?, 'small_lamp', ?)");
            $insDecor->execute([$_SESSION['user_id'], $freeSlot]);
        } else {
            $nextSlotStmt = $pdo->prepare("SELECT COALESCE(MAX(slot_index), -1) + 1 FROM user_decorations WHERE user_id = ? AND item_code = ?");
            $nextSlotStmt->execute([$_SESSION['user_id'], $item['code']]);
            $nextSlot = (int)$nextSlotStmt->fetchColumn();
            $insDecor = $pdo->prepare("INSERT INTO user_decorations (user_id, item_code, slot_index) VALUES (?, ?, ?)");
            $insDecor->execute([$_SESSION['user_id'], $item['code'], $nextSlot]);
        }

        $pdo->commit();
        echo json_encode(['success' => true]);
        exit;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'error' => 'Kauf fehlgeschlagen']);
        exit;
    }
}

echo json_encode(['success' => false, 'error' => 'Unsupported action']);
