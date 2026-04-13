<?php
require_once 'config.php';
require_once 'questions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'generate') {
    $type = $_GET['type'] ?? 'pet'; // 'pet', 'feed', or 'revive'
    
    $limit = 1;
    if ($type === 'feed' || $type === 'revive') {
        // Fetch user's configured limit
        $stmt = $pdo->prepare("SELECT questions_per_challenge FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $limit = (int)$stmt->fetchColumn();
        if ($limit < 3) $limit = 3;
        if ($limit > 5) $limit = 5;
    }

    $all_questions = getQuestions();
    
    if (empty($all_questions)) {
        echo json_encode(['success' => false, 'error' => 'No questions available']);
        exit;
    }

    // Get user's known questions
    $stmt = $pdo->prepare("SELECT question_hash FROM user_knowledge WHERE user_id = ? AND knows_well = 1");
    $stmt->execute([$_SESSION['user_id']]);
    $known_hashes = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $known_pool = [];
    $unknown_pool = [];

    foreach ($all_questions as $q) {
        if (in_array($q['id'], $known_hashes)) {
            $known_pool[] = $q;
        } else {
            $unknown_pool[] = $q;
        }
    }

    $selected_questions = [];

    if (($type === 'feed' || $type === 'revive') && count($known_pool) > 0 && $limit > 1) {
        // 1 known word required
        shuffle($known_pool);
        $selected_questions[] = array_pop($known_pool);
        
        // The rest from everything else
        $remaining_pool = array_merge($known_pool, $unknown_pool);
        shuffle($remaining_pool);
        
        while (count($selected_questions) < $limit && !empty($remaining_pool)) {
            $selected_questions[] = array_pop($remaining_pool);
        }
    } else {
        // Just random
        $pool = $all_questions;
        shuffle($pool);
        $selected_questions = array_slice($pool, 0, $limit);
    }
    
    shuffle($selected_questions);

    echo json_encode([
        'success' => true,
        'questions' => $selected_questions,
        'type' => $type
    ]);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'submit') {
    $game_config = require __DIR__ . '/../data/game_config.php';
    
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    $results = $data['results'] ?? [];
    $type = $data['type'] ?? 'pet';
    
    // Get current user state
    $stmt = $pdo->prepare("SELECT total_points, diamonds, stars, correct_streak_count FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    $points_gained = 0;
    $streak = (int)$user['correct_streak_count'];
    $diamonds = (int)$user['diamonds'];
    $stars = (int)$user['stars'];
    
    $initial_stars = $stars;
    
    foreach ($results as $res) {
        $q_hash = $res['id'];
        $attempts = (int)$res['attempts'];
        
        // Points calculation via config
        if ($attempts === 1) {
            $points_gained += $game_config['points_first_try'];
            $streak++;
        } elseif ($attempts === 2) {
            $points_gained += $game_config['points_second_try'];
        } else {
            $points_gained += $game_config['points_third_plus_try'];
        }

        // Diamond / Star calculation via config
        if ($streak >= $game_config['correct_words_for_diamond']) {
            $diamonds++;
            $streak = 0; // reset counter
            
            if ($diamonds > 0 && $diamonds % $game_config['diamonds_for_star'] === 0) {
                $stars++;
            }
        }
        
        // Knowledge Tracking
        $k_stmt = $pdo->prepare("SELECT * FROM user_knowledge WHERE user_id = ? AND question_hash = ?");
        $k_stmt->execute([$_SESSION['user_id'], $q_hash]);
        $knowledge = $k_stmt->fetch();
        
        if ($knowledge) {
            $c_att = (int)$knowledge['correct_attempts'] + ($attempts === 1 ? 1 : 0);
            $i_att = (int)$knowledge['incorrect_attempts'] + ($attempts > 1 ? 1 : 0);
            
            // threshold based on config
            $knows_well = ($c_att - $i_att) >= $game_config['knows_well_threshold'] ? 1 : 0;
            
            $u_stmt = $pdo->prepare("UPDATE user_knowledge SET correct_attempts = ?, incorrect_attempts = ?, knows_well = ? WHERE id = ?");
            $u_stmt->execute([$c_att, $i_att, $knows_well, $knowledge['id']]);
        } else {
            $c_att = ($attempts === 1 ? 1 : 0);
            $i_att = ($attempts > 1 ? 1 : 0);
            $knows_well = 0;
            
            $i_stmt = $pdo->prepare("INSERT INTO user_knowledge (user_id, question_hash, correct_attempts, incorrect_attempts, knows_well) VALUES (?, ?, ?, ?, ?)");
            $i_stmt->execute([$_SESSION['user_id'], $q_hash, $c_att, $i_att, $knows_well]);
        }
    }
    
    // Update User
    $new_points = $user['total_points'] + $points_gained;
    
    if ($type === 'feed') {
        $upd = $pdo->prepare("UPDATE users SET total_points = ?, diamonds = ?, stars = ?, correct_streak_count = ?, last_fed = CURRENT_TIMESTAMP WHERE id = ?");
        $upd->execute([$new_points, $diamonds, $stars, $streak, $_SESSION['user_id']]);
    } elseif ($type === 'revive') {
        $upd = $pdo->prepare("UPDATE users SET total_points = ?, diamonds = ?, stars = ?, correct_streak_count = ?, is_dead = 0, last_fed = NULL WHERE id = ?");
        $upd->execute([$new_points, $diamonds, $stars, $streak, $_SESSION['user_id']]);
    } else {
        $upd = $pdo->prepare("UPDATE users SET total_points = ?, diamonds = ?, stars = ?, correct_streak_count = ? WHERE id = ?");
        $upd->execute([$new_points, $diamonds, $stars, $streak, $_SESSION['user_id']]);
    }
    
    $earned_star = ($stars > $initial_stars);

    // Build full stats including hunger/death state for the frontend
    $game_config = require __DIR__ . '/../data/game_config.php';
    $stmt2 = $pdo->prepare("SELECT last_fed, is_dead FROM users WHERE id = ?");
    $stmt2->execute([$_SESSION['user_id']]);
    $fresh = $stmt2->fetch();

    $response_stats = [
        'total_points' => $new_points,
        'diamonds' => $diamonds,
        'stars' => $stars,
        'correct_streak_count' => $streak,
        'is_dead' => (int)$fresh['is_dead'],
        'is_hungry' => false
    ];

    if (!$fresh['is_dead'] && !empty($fresh['last_fed'])) {
        $last_fed_time = strtotime($fresh['last_fed'] . " UTC");
        $now = time();
        if (($now - $last_fed_time) >= $game_config['feeding_interval_seconds']) {
            $response_stats['is_hungry'] = true;
        } else {
            $response_stats['next_feed_ts'] = $last_fed_time + $game_config['feeding_interval_seconds'];
        }
    } elseif (!$fresh['is_dead']) {
        $response_stats['is_hungry'] = true;
    }

    echo json_encode([
        'success' => true,
        'stats' => $response_stats,
        'earned_star' => $earned_star
    ]);
}