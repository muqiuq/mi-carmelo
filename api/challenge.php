<?php
require_once 'config.php';
require_once 'questions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

/**
 * Ask OpenAI to produce one fill-in-the-gap sentence using the given vocabulary words.
 * Returns ['sentence' => '...', 'answer' => 'word'] or null on failure.
 */
function generateGapQuestion(array $words, string $lang, string $api_key, string $model = 'gpt-4.1-mini'): array {
    if (empty($words) || empty($api_key)) return ['valid' => false, 'error' => 'Missing words or API key'];

    // Use at most 5 words, prefer variety
    $pool = array_slice($words, 0, 5);
    $words_json = json_encode($pool, JSON_UNESCAPED_UNICODE);

    $system = 'You are a language learning assistant. '
        . 'Create exactly one very simple A1-level fill-in-the-gap sentence in the target language. '
        . 'The sentence must contain exactly one of the provided vocabulary words as the answer. '
        . 'Replace that word with "..." in the sentence. '
        . 'CRITICAL: The sentence must contain unambiguous context clues so the learner can deduce the exact missing word from the sentence alone — not just any word from the list. '
        . 'For example, for days of the week, mention a specific activity uniquely associated with that day: "Jeden ... haben wir Fußballtraining, danach ist Wochenende." (answer: Freitag). '
        . 'A sentence like "Heute ist ..." is NOT acceptable because it could be any day. '
        . 'If you want the user to enter a number include the number as a digit in the sentence in brackets after the dots, not as a word, to avoid ambiguity. '
        . 'The missing word does not require any change to be correct (e.g. no verb conjugation or pluralization). '
        . 'Respond ONLY with valid JSON, no markdown: {"sentence": "...", "answer": "word"}';

    $user = "Target language: $lang\nVocabulary words: $words_json";

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    $request_body = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model'                  => $model,
            'messages'               => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            'temperature'            => 0.7,
            'max_completion_tokens'  => 200,
        ])];
    curl_setopt_array($ch, $request_body);
    $response  = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (!$response || $curl_error) return ['valid' => false, 'error' => 'cURL error: ' . ($curl_error ?: 'empty response')];
    if ($http_code !== 200) {
        $api_err = json_decode($response, true);
        return ['valid' => false, 'error' => 'OpenAI API error ' . $http_code . ': ' . ($api_err['error']['message'] ?? $response)];
    }

    $result  = json_decode($response, true);
    $content = trim($result['choices'][0]['message']['content'] ?? '');

    // Strip markdown fences if present
    $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
    $content = preg_replace('/\s*```$/',           '', $content);

    $parsed = json_decode($content, true);
    if (!isset($parsed['sentence'], $parsed['answer'])) return ['valid' => false, 'error' => 'Could not parse OpenAI response as JSON with sentence/answer', 'raw' => $content];

    $sentence = trim($parsed['sentence']);
    $answer   = trim($parsed['answer']);

    if ($sentence === '' || $answer === '')  return ['valid' => false, 'error' => 'Empty sentence or answer in OpenAI response'];
    if (strlen($sentence) < 8 || strlen($sentence) > 100) return ['valid' => false, 'error' => 'Sentence length out of range: ' . strlen($sentence)];
    if (strpos($sentence, '...') === false) return ['valid' => false, 'error' => 'Sentence does not contain "..." placeholder'];
    if (str_word_count($answer) > 2)        return ['valid' => false, 'error' => 'Answer has too many words: ' . $answer];

    return [
        'id'       => 'gap_' . md5($sentence),
        'valid'    => true,
        'type'     => 'gap',
        'question' => $sentence,
        'answers'  => [$answer],
    ];
}


$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'generate') {
    $type = $_GET['type'] ?? 'pet'; // 'pet', 'feed', or 'revive'
    
    $limit = 1;
    if ($type === 'fiesta') {
        // Check cooldown (configurable, default 5 minutes)
        $game_config = require __DIR__ . '/../data/game_config.php';
        $fiestaCooldown = $game_config['fiesta_cooldown_seconds'] ?? 300;
        $cdStmt = $pdo->prepare("SELECT last_fiesta FROM users WHERE id = ?");
        $cdStmt->execute([$_SESSION['user_id']]);
        $lastFiesta = $cdStmt->fetchColumn();
        if ($lastFiesta) {
            $elapsed = time() - strtotime($lastFiesta . ' UTC');
            if ($elapsed < $fiestaCooldown) {
                echo json_encode(['success' => false, 'error' => 'Fiesta cooldown active', 'cooldown_remaining' => $fiestaCooldown - $elapsed]);
                exit;
            }
        }
    }
    if ($type === 'feed' || $type === 'revive') {
        // Fetch user's configured limit and question set
        $stmt = $pdo->prepare("SELECT questions_per_challenge, question_set FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user_row = $stmt->fetch();
        $limit = (int)($user_row['questions_per_challenge'] ?? 3);
        if ($limit < 3) $limit = 3;
        if ($limit > 5) $limit = 5;
        $user_question_set = $user_row['question_set'] ?? null;
    } else {
        $stmt = $pdo->prepare("SELECT question_set FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user_question_set = $stmt->fetchColumn() ?: null;
    }

    $all_questions = getQuestions($user_question_set);
    
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
    } else if ($type === 'pet') {
        // Pet: only unknown questions
        $pool = $unknown_pool;
        shuffle($pool);
        $selected_questions = array_slice($pool, 0, $limit);
    } else if ($type === 'fiesta') {
        // Fiesta: 1 MC question from all questions
        shuffle($all_questions);
        $mc_question = $all_questions[0];
        $correct_answer = $mc_question['answers'][0];
        $wrong_pool = [];
        foreach ($all_questions as $q) {
            if ($q['id'] === $mc_question['id']) continue;
            foreach ($q['answers'] as $a) {
                $a_lower = mb_strtolower(trim($a));
                if ($a_lower !== mb_strtolower(trim($correct_answer)) && !in_array($a_lower, array_map('mb_strtolower', $wrong_pool))) {
                    $wrong_pool[] = $a;
                }
            }
        }
        shuffle($wrong_pool);
        $wrong_options = array_slice($wrong_pool, 0, 3);
        if (count($wrong_options) >= 3) {
            $options = array_merge([$correct_answer], $wrong_options);
            shuffle($options);
            $mc_question['options'] = $options;
        }
        $selected_questions = [$mc_question];
    } else {
        // Just random
        $pool = $all_questions;
        shuffle($pool);
        $selected_questions = array_slice($pool, 0, $limit);
    }
    
    shuffle($selected_questions);

    // For feed/revive with more than 1 question, add a multiple-choice question at the end
    if (($type === 'feed' || $type === 'revive') && count($selected_questions) > 1) {
        // Pick one more question for MC (not already selected)
        $selected_ids = array_map(fn($q) => $q['id'], $selected_questions);
        $mc_pool = array_filter($all_questions, fn($q) => !in_array($q['id'], $selected_ids));
        if (empty($mc_pool)) {
            // Fallback: reuse any question
            $mc_pool = $all_questions;
        }
        $mc_pool = array_values($mc_pool);
        shuffle($mc_pool);
        $mc_question = $mc_pool[0];

        // Build 4 options: 1 correct + 3 wrong from other questions
        $correct_answer = $mc_question['answers'][0]; // use first answer as the displayed correct option
        $wrong_pool = [];
        foreach ($all_questions as $q) {
            if ($q['id'] === $mc_question['id']) continue;
            foreach ($q['answers'] as $a) {
                $a_lower = mb_strtolower(trim($a));
                if ($a_lower !== mb_strtolower(trim($correct_answer)) && !in_array($a_lower, array_map('mb_strtolower', $wrong_pool))) {
                    $wrong_pool[] = $a;
                }
            }
        }
        shuffle($wrong_pool);
        $wrong_options = array_slice($wrong_pool, 0, 3);

        // Only add MC if we have enough wrong options
        if (count($wrong_options) >= 3) {
            $options = array_merge([$correct_answer], $wrong_options);
            shuffle($options);
            $mc_question['options'] = $options;
            $selected_questions[] = $mc_question;
        }
    }

    // For feed only: append one AI-generated fill-in-the-gap sentence question at the end
    if ($type === 'feed' && count($selected_questions) > 0) {
        $game_config_gap = require __DIR__ . '/../data/game_config.php';
        $api_key_gap = $game_config_gap['openai_api_key'] ?? '';
        $model_gap = $game_config_gap['openai_model'] ?? 'gpt-4.1-mini';
        if (!empty($api_key_gap)) {
            // Collect the answer words from the selected vocab questions as learning context
            $learned_words = [];
            foreach ($selected_questions as $sq) {
                if (!isset($sq['type'])) { // only vocab questions, not MC
                    $learned_words[] = $sq['answers'][0];
                }
            }
            require_once 'questions.php';
            $user_qs_stmt = $pdo->prepare("SELECT question_set FROM users WHERE id = ?");
            $user_qs_stmt->execute([$_SESSION['user_id']]);
            $user_qs_row = $user_qs_stmt->fetch();
            $gap_lang = getLangFromQuestionSet($user_qs_row['question_set'] ?? null);

            if (!empty($learned_words)) {
                $gap_q = generateGapQuestion($learned_words, $gap_lang, $api_key_gap, $model_gap);
                if ($gap_q['valid'] === true) {
                    $selected_questions[] = $gap_q;
                }
            }
        }
    }

    echo json_encode([
        'success' => true,
        'questions' => $selected_questions,
        'type' => $type
    ]);
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'test_gap') {
    // Admin-only: test generateGapQuestion in isolation
    if (empty($_SESSION['isadmin'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    $game_config = require __DIR__ . '/../data/game_config.php';
    $api_key = $game_config['openai_api_key'] ?? '';
    if (empty($api_key)) {
        echo json_encode(['success' => false, 'error' => 'No OpenAI API key configured']);
        exit;
    }
    $model = $game_config['openai_model'] ?? 'gpt-4.1-mini';

    $words_raw = trim($_GET['words'] ?? '');
    $lang      = trim($_GET['lang']  ?? 'de');

    if ($words_raw === '') {
        echo json_encode(['success' => false, 'error' => 'words param required (comma-separated)']);
        exit;
    }

    $words = array_values(array_filter(array_map('trim', explode(',', $words_raw))));
    $gap_q = generateGapQuestion($words, $lang, $api_key, $model);

    if (!$gap_q['valid']) {
        echo json_encode(['success' => false, 'error' => $gap_q['error'] ?? 'generateGapQuestion failed', 'details' => $gap_q]);
        exit;
    }

    echo json_encode(['success' => true, 'question' => $gap_q]);
    exit;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'check_gap_answer') {
    // Check whether a user's gap answer is grammatically acceptable via OpenAI.
    // Open to any logged-in user (session already verified above).
    $data     = json_decode(file_get_contents('php://input'), true);
    $sentence    = trim($data['sentence']    ?? '');
    $user_answer = trim($data['user_answer'] ?? '');
    $lang        = trim($data['lang']        ?? 'de');

    if ($sentence === '' || $user_answer === '') {
        echo json_encode(['valid' => false, 'error' => 'Missing params']);
        exit;
    }

    $game_config = require __DIR__ . '/../data/game_config.php';
    $api_key = $game_config['openai_api_key'] ?? '';
    if (empty($api_key)) {
        // No API key — fall back to "not valid" so the exact-match error is shown
        echo json_encode(['valid' => false]);
        exit;
    }

    // Replace "..." in the sentence with the user's answer and ask if it is grammatically correct
    $filled = str_replace('...', $user_answer, $sentence);

    $system = 'You are a lenient but fair grammar checker for a language learning app targeting beginners (A1-A2 level). '
        . 'The user filled in a gap in a sentence. Evaluate whether the completed sentence is grammatically correct and makes sense in context. '
        . 'Be generous: accept minor capitalisation differences, common synonyms, and words that fit the sentence naturally even if different from the expected answer. '
        . 'Only reject answers that are clearly grammatically wrong or completely nonsensical in context. '
        . 'Respond ONLY with valid JSON: {"valid": true, "explanation": "one short sentence why"} '
        . 'or {"valid": false, "explanation": "one short sentence why not"}.';

    $user_msg = "Target language: $lang\nSentence to evaluate: $filled";

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model'                 => ($game_config['openai_model'] ?? 'gpt-4.1-mini'),
            'messages'              => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user_msg],
            ],
            'temperature'           => 0,
            'max_completion_tokens' => 200,
        ]),
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($http_code !== 200 || !$response) {
        echo json_encode(['valid' => false, 'http_code' => $http_code, 'error' => 'TTS API request failed']);
        exit;
    }

    $result  = json_decode($response, true);
    $content = trim($result['choices'][0]['message']['content'] ?? '');
    $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
    $content = preg_replace('/\s*```$/', '', $content);
    $parsed  = json_decode($content, true);

    echo json_encode([
        'valid'       => !empty($parsed['valid']),
        'explanation' => $parsed['explanation'] ?? null,
        'debug_prompt' => $user_msg,
    ]);
    exit;
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
        
        // Knowledge Tracking — skip for AI-generated gap questions (id starts with 'gap_')
        if (strncmp($q_hash, 'gap_', 4) === 0) continue;
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
    } elseif ($type === 'fiesta') {
        // Fiesta: award exactly 1 point, update last_fiesta
        $new_points = (int)$user['total_points'] + 1;
        $upd = $pdo->prepare("UPDATE users SET total_points = ?, last_fiesta = CURRENT_TIMESTAMP WHERE id = ?");
        $upd->execute([$new_points, $_SESSION['user_id']]);
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

    $decorStmt = $pdo->prepare("SELECT item_code, slot_index FROM user_decorations WHERE user_id = ? ORDER BY slot_index ASC");
    $decorStmt->execute([$_SESSION['user_id']]);
    $flower_slots = []; $lamp_slots = []; $frame_slots = []; $bed_owned = 0;
    foreach ($decorStmt->fetchAll() as $row) {
        if ($row['item_code'] === 'flower_wall')   $flower_slots[] = (int)$row['slot_index'];
        if ($row['item_code'] === 'small_lamp')    $lamp_slots[]   = (int)$row['slot_index'];
        if ($row['item_code'] === 'picture_frame') $frame_slots[]  = (int)$row['slot_index'];
        if ($row['item_code'] === 'chicken_house') $bed_owned = 1;
    }
    $response_stats['flower_slots'] = $flower_slots;
    $response_stats['lamp_slots']   = $lamp_slots;
    $response_stats['frame_slots']  = $frame_slots;
    $response_stats['bed_owned']    = $bed_owned;

    // Fiesta cooldown for response
    $fiestaCd = $game_config['fiesta_cooldown_seconds'] ?? 300;
    if ($type === 'fiesta') {
        $response_stats['fiesta_cooldown'] = $fiestaCd;
    } else {
        $response_stats['fiesta_cooldown'] = 0;
    }

    echo json_encode([
        'success' => true,
        'stats' => $response_stats,
        'earned_star' => $earned_star
    ]);
}