<?php
require_once 'config.php';

/**
 * Basic YAML parser tailored for our questions.yaml format to minimize external dependencies.
 */
function getQuestions() {
    $file = __DIR__ . '/../data/questions.yaml';
    if (!file_exists($file)) return [];
    
    $content = file_get_contents($file);
    $lines = explode("\n", $content);
    $questions = [];
    $currentQuestion = null;
    
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') continue;
        
        // Match "- question: 'Some question'" or "- question: "Some question""
        if (preg_match('/^- question:\s*["\']?(.*?)["\']?$/', $line, $matches)) {
            if ($currentQuestion) {
                $questions[] = $currentQuestion;
            }
            $questionText = trim($matches[1]);
            $currentQuestion = [
                'id' => md5($questionText), // Creating an ID from the question text itself
                'question' => $questionText,
                'answers' => []
            ];
        } 
        // Match "- 'answer'" or "- "answer"" or "- answer" under answers list
        elseif ($currentQuestion && preg_match('/^\s+-\s*["\']?(.*?)["\']?$/', $line, $matches)) {
            $answerStr = trim($matches[1]);
            if ($answerStr !== '') {
                $currentQuestion['answers'][] = $answerStr;
            }
        }
    }
    
    if ($currentQuestion) {
        $questions[] = $currentQuestion;
    }
    
    return $questions;
}

// Just an endpoint for debugging to see if parser works during development
// E.g. http://localhost:8080/api/questions.php
if ($_SERVER['REQUEST_METHOD'] === 'GET' && basename($_SERVER['SCRIPT_FILENAME']) === 'questions.php') {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success', 
        'count' => count(getQuestions()),
        'data' => getQuestions()
    ]);
}