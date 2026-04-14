<?php
require_once 'config.php';

error_reporting(0);
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !$_SESSION['isadmin']) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$game_config = require __DIR__ . '/../data/game_config.php';
$action = $_GET['action'] ?? '';

if ($action === 'check_key') {
    echo json_encode(['success' => true, 'has_key' => !empty($game_config['openai_api_key'])]);
    exit;
}

if ($action === 'generate') {
    $api_key = $game_config['openai_api_key'] ?? '';
    if (empty($api_key)) {
        echo json_encode(['success' => false, 'error' => 'No OpenAI API key configured. Please set openai_api_key in data/game_config.php.']);
        exit;
    }

    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    $topic = trim($data['topic'] ?? '');

    if (empty($topic)) {
        echo json_encode(['success' => false, 'error' => 'Please enter a topic.']);
        exit;
    }

    if (mb_strlen($topic) > 200) {
        echo json_encode(['success' => false, 'error' => 'Topic too long (max 200 characters).']);
        exit;
    }

    $system_prompt = "You are a language learning assistant for a Spanish-to-German vocabulary app. "
        . "The learner is at A1 beginner level. "
        . "Generate exactly 5 vocabulary question-answer pairs on the given topic. "
        . "Each question is a Spanish word or short phrase (max 30 characters). "
        . "Each question should have 1-3 German answer alternatives (synonyms or with/without article). "
        . "Each answer must be max 3 words and max 30 characters. "
        . "Respond ONLY with valid JSON, no markdown, no explanation. "
        . "Format: [{\"question\": \"Spanish phrase\", \"answers\": [\"German answer 1\", \"German answer 2\"]}]";

    $user_prompt = "Topic: " . $topic;

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'gpt-5.4-nano',
            'messages' => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user', 'content' => $user_prompt],
            ],
            'temperature' => 0.7,
            'max_completion_tokens' => 1000,
        ]),
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    // curl_close is a no-op since PHP 8.0, skip to avoid deprecation warning in 8.5+

    if ($curl_error) {
        echo json_encode(['success' => false, 'error' => 'Connection error: ' . $curl_error]);
        exit;
    }

    if ($http_code !== 200) {
        $err_data = json_decode($response, true);
        $err_msg = $err_data['error']['message'] ?? "HTTP $http_code";
        $err_type = $err_data['error']['type'] ?? '';
        $detail = $err_type ? "[$err_type] $err_msg" : $err_msg;
        echo json_encode(['success' => false, 'error' => "OpenAI error (HTTP $http_code): $detail"]);
        exit;
    }

    $result = json_decode($response, true);
    $content = $result['choices'][0]['message']['content'] ?? '';

    // Strip markdown code fences if present
    $content = preg_replace('/^```(?:json)?\s*/i', '', trim($content));
    $content = preg_replace('/\s*```$/', '', $content);

    $questions = json_decode($content, true);

    if (!is_array($questions) || empty($questions)) {
        echo json_encode(['success' => false, 'error' => 'Could not parse AI response. Raw content: ' . mb_substr($content, 0, 200)]);
        exit;
    }

    // Validate and sanitize
    $clean = [];
    foreach ($questions as $q) {
        if (empty($q['question']) || empty($q['answers']) || !is_array($q['answers'])) continue;
        $question = mb_substr(trim($q['question']), 0, 60);
        $answers = [];
        foreach ($q['answers'] as $a) {
            $a = mb_substr(trim($a), 0, 30);
            if (!empty($a)) $answers[] = $a;
        }
        if (!empty($question) && !empty($answers)) {
            $clean[] = ['question' => $question, 'answers' => $answers];
        }
    }

    if (empty($clean)) {
        echo json_encode(['success' => false, 'error' => 'No valid questions generated. Please try again with a different topic.']);
        exit;
    }

    echo json_encode(['success' => true, 'questions' => $clean]);
    exit;
}

if ($action === 'add') {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    $questions = $data['questions'] ?? [];

    if (empty($questions) || !is_array($questions)) {
        echo json_encode(['success' => false, 'error' => 'No questions selected.']);
        exit;
    }

    $file = __DIR__ . '/../data/questions.yaml';
    $existing = file_exists($file) ? file_get_contents($file) : '';

    // Ensure file ends with newline
    if (!empty($existing) && substr($existing, -1) !== "\n") {
        $existing .= "\n";
    }

    $yaml = '';
    foreach ($questions as $q) {
        if (empty($q['question']) || empty($q['answers']) || !is_array($q['answers'])) continue;
        $question = str_replace('"', '\\"', trim($q['question']));
        $yaml .= "\n- question: \"$question\"\n  answers:\n";
        foreach ($q['answers'] as $a) {
            $a = str_replace('"', '\\"', trim($a));
            $yaml .= "    - \"$a\"\n";
        }
    }

    if (empty($yaml)) {
        echo json_encode(['success' => false, 'error' => 'Keine gültigen Fragen.']);
        exit;
    }

    $result = file_put_contents($file, $existing . $yaml);
    if ($result === false) {
        echo json_encode(['success' => false, 'error' => 'Datei konnte nicht geschrieben werden.']);
        exit;
    }

    echo json_encode(['success' => true, 'added' => count($questions)]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
