<?php
require_once 'config.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'login') {
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';
        
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['isadmin'] = (int)$user['isadmin'];
            
            echo json_encode([
                'success' => true,
                'user' => [
                    'id' => (int)$user['id'],
                    'username' => $user['username'],
                    'isadmin' => (int)$user['isadmin']
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
        }
    } elseif ($action === 'logout') {
        session_destroy();
        echo json_encode(['success' => true]);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'check') {
        if (isset($_SESSION['user_id'])) {
            echo json_encode([
                'authenticated' => true,
                'user' => [
                    'id' => (int)$_SESSION['user_id'],
                    'username' => $_SESSION['username'],
                    'isadmin' => (int)$_SESSION['isadmin']
                ]
            ]);
        } else {
            echo json_encode(['authenticated' => false]);
        }
    }
}