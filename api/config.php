<?php
session_start();

// Base configuration and SQLite initialization
$data_dir = __DIR__ . '/../data';
$db_file = $data_dir . '/micarmelo.sqlite';

// Ensure data directory exists
if (!is_dir($data_dir)) {
    mkdir($data_dir, 0777, true);
}

// Check if first run
$is_first_run = !file_exists($db_file);

try {
    $pdo = new PDO("sqlite:$db_file");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    if ($is_first_run) {
        // Initialize Schema
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                isadmin INTEGER DEFAULT 0,
                last_fed DATETIME,
                is_dead INTEGER DEFAULT 0,
                diamonds INTEGER DEFAULT 0,
                stars INTEGER DEFAULT 0,
                correct_streak_count INTEGER DEFAULT 0,
                total_points INTEGER DEFAULT 0,
                questions_per_challenge INTEGER DEFAULT 3
            );
            
            CREATE TABLE IF NOT EXISTS user_knowledge (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                question_hash TEXT NOT NULL,
                correct_attempts INTEGER DEFAULT 0,
                incorrect_attempts INTEGER DEFAULT 0,
                knows_well INTEGER DEFAULT 0,
                FOREIGN KEY (user_id) REFERENCES users(id)
            );

            CREATE TABLE IF NOT EXISTS push_subscriptions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                endpoint TEXT NOT NULL UNIQUE,
                p256dh TEXT NOT NULL,
                auth TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );
        ");

        // Create default users
        $userPass = password_hash('carmelo', PASSWORD_DEFAULT);
        $adminPass = password_hash('queen', PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, isadmin) VALUES (?, ?, ?)");
        $stmt->execute(['carmelo', $userPass, 0]);
        $stmt->execute(['queen', $adminPass, 1]);
    }

} catch (PDOException $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Ensure push_subscriptions table exists (migration for existing DBs)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS push_subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            endpoint TEXT NOT NULL UNIQUE,
            p256dh TEXT NOT NULL,
            auth TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
} catch (PDOException $e) {
    // Table may already exist, that's fine
}

// Migration: add is_dead column if missing
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN is_dead INTEGER DEFAULT 0");
} catch (PDOException $e) {
    // Column already exists
}

// Migration: access_tokens table
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS access_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token TEXT UNIQUE NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL
        )
    ");
} catch (PDOException $e) {
    // Table may already exist
}