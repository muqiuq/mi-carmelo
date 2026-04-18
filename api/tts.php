<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$text = trim($_GET['text'] ?? '');
if ($text === '' || mb_strlen($text) > 500) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid text']);
    exit;
}

$text_hash = hash('sha256', mb_strtolower($text));

// Check DB cache
$stmt = $pdo->prepare("SELECT filename FROM audio_cache WHERE text_hash = ?");
$stmt->execute([$text_hash]);
$cached = $stmt->fetch();

$audio_dir = __DIR__ . '/../audio';

if ($cached && file_exists($audio_dir . '/' . $cached['filename'])) {
    // Serve cached file
    header('Content-Type: audio/mpeg');
    header('Content-Length: ' . filesize($audio_dir . '/' . $cached['filename']));
    readfile($audio_dir . '/' . $cached['filename']);
    exit;
}

// Generate via OpenAI TTS
$game_config = require __DIR__ . '/../data/game_config.php';
$api_key = $game_config['openai_api_key'] ?? '';
if ($api_key === '') {
    http_response_code(500);
    echo json_encode(['error' => 'No OpenAI API key configured']);
    exit;
}

$ch = curl_init('https://api.openai.com/v1/audio/speech');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $api_key,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'model' => 'tts-1',
        'input' => '[lang:de] ' . $text,
        'voice' => 'alloy',
        'language' => 'de',
        'response_format' => 'mp3',
    ]),
]);
$audio = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($httpCode !== 200 || !$audio) {
    http_response_code(502);
    $detail = 'TTS API failed';
    $decoded = json_decode($audio, true);
    if (isset($decoded['error']['message'])) {
        $detail = $decoded['error']['message'];
    }
    echo json_encode(['error' => $detail]);
    exit;
}

// Save to file
$filename = $text_hash . '.mp3';
file_put_contents($audio_dir . '/' . $filename, $audio);

// Save to DB
$stmt = $pdo->prepare("INSERT OR IGNORE INTO audio_cache (text_hash, text, filename) VALUES (?, ?, ?)");
$stmt->execute([$text_hash, $text, $filename]);

// Serve
header('Content-Type: audio/mpeg');
header('Content-Length: ' . strlen($audio));
echo $audio;
