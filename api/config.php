<?php
$sevenDays = 7 * 24 * 60 * 60;
ini_set('session.gc_maxlifetime', $sevenDays);
session_set_cookie_params($sevenDays);
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

            CREATE TABLE IF NOT EXISTS shop_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code TEXT UNIQUE NOT NULL,
                name TEXT NOT NULL,
                description TEXT NOT NULL,
                currency TEXT NOT NULL,
                price INTEGER NOT NULL,
                max_quantity INTEGER NOT NULL,
                sold_count INTEGER NOT NULL DEFAULT 0,
                sort_order INTEGER NOT NULL DEFAULT 0,
                is_active INTEGER NOT NULL DEFAULT 1
            );

            CREATE TABLE IF NOT EXISTS user_decorations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                item_code TEXT NOT NULL,
                slot_index INTEGER NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(user_id, item_code, slot_index),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );
        ");

        // Create default users
        $userPass = password_hash('carmelo', PASSWORD_DEFAULT);
        $adminPass = password_hash('queen', PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, isadmin) VALUES (?, ?, ?)");
        $stmt->execute(['carmelo', $userPass, 0]);
        $stmt->execute(['queen', $adminPass, 1]);

        // Seed default shop items
        $shopSeed = $pdo->prepare("INSERT OR IGNORE INTO shop_items (code, name, description, currency, price, max_quantity, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $shopSeed->execute(['flower_wall', 'Blume', 'Eine einzelne Blüte für die Wand.', 'points', 250, 10, 10]);
        $shopSeed->execute(['small_lamp', 'Kleine Lampe', 'Eine kleine gemütliche Lampe für das Zimmer.', 'diamonds', 5, 3, 20]);
        $shopSeed->execute(['picture_frame', 'Bilderrahmen', 'Ein süßer Bilderrahmen für die Wand.', 'diamonds', 7, 2, 30]);
        $shopSeed->execute(['chicken_house', 'Bett', 'Ein kuscheliges Premium-Bett für dein Huhn.', 'stars', 10, 1, 40]);
        $shopSeed->execute(['diamond_buy', 'Diamant kaufen', '1 Diamant für 500 Punkte.', 'points', 500, 9999, 5]);
        $shopSeed->execute(['diamond_buy_3', '3 Diamanten kaufen', '3 Diamanten für 1000 Punkte.', 'points', 1000, 9999, 5]);
        $shopSeed->execute(['color_pink',   'Farbe: Rosa',  'Färbe Carmelo rosa.',   'points', 50, 9999, 6]);
        $shopSeed->execute(['color_blue',   'Farbe: Blau',  'Färbe Carmelo blau.',   'points', 50, 9999, 7]);
        $shopSeed->execute(['color_green',  'Farbe: Grün', 'Färbe Carmelo grün.',  'points', 50, 9999, 8]);
        $shopSeed->execute(['color_purple', 'Farbe: Lila',  'Färbe Carmelo lila.',   'points', 50, 9999, 9]);
        $shopSeed->execute(['color_white',  'Farbe: Hellgrau',  'Färbe Carmelo hellgrau.',  'points', 50, 9999, 10]);
        $shopSeed->execute(['color_default', 'Originalfarbe', 'Carmelo wieder in Goldgelb.', 'points', 10, 9999, 11]);
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

// Migration: add last_fiesta column
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN last_fiesta DATETIME DEFAULT NULL");
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

// Migration: shop_items table and seed data
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS shop_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT UNIQUE NOT NULL,
            name TEXT NOT NULL,
            description TEXT NOT NULL,
            currency TEXT NOT NULL,
            price INTEGER NOT NULL,
            max_quantity INTEGER NOT NULL,
            sold_count INTEGER NOT NULL DEFAULT 0,
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_decorations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            item_code TEXT NOT NULL,
            slot_index INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id, item_code, slot_index),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");

    $shopSeed = $pdo->prepare("INSERT OR IGNORE INTO shop_items (code, name, description, currency, price, max_quantity, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $shopSeed->execute(['flower_wall', 'Blume', 'Eine einzelne Blüte für die Wand.', 'points', 250, 10, 10]);
    $shopSeed->execute(['small_lamp', 'Kleine Lampe', 'Eine kleine gemütliche Lampe für das Zimmer.', 'diamonds', 5, 3, 20]);
    $shopSeed->execute(['picture_frame', 'Bilderrahmen', 'Ein süßer Bilderrahmen für die Wand.', 'diamonds', 7, 2, 30]);
    $shopSeed->execute(['chicken_house', 'Bett', 'Ein kuscheliges Premium-Bett für dein Huhn.', 'stars', 10, 1, 40]);
    $shopSeed->execute(['diamond_buy', 'Diamant kaufen', '1 Diamant für 500 Punkte.', 'points', 500, 9999, 5]);
    $shopSeed->execute(['diamond_buy_3', '3 Diamanten kaufen', '3 Diamanten für 1000 Punkte.', 'points', 1000, 9999, 5]);
    $shopSeed->execute(['color_pink',   'Farbe: Rosa',  'Färbe Carmelo rosa.',   'points', 50, 9999, 6]);
    $shopSeed->execute(['color_blue',   'Farbe: Blau',  'Färbe Carmelo blau.',   'points', 50, 9999, 7]);
    $shopSeed->execute(['color_green',  'Farbe: Grün', 'Färbe Carmelo grün.',  'points', 50, 9999, 8]);
    $shopSeed->execute(['color_purple', 'Farbe: Lila',  'Färbe Carmelo lila.',   'points', 50, 9999, 9]);
    $shopSeed->execute(['color_white',  'Farbe: Hellgrau',  'Färbe Carmelo hellgrau.',  'points', 50, 9999, 10]);
    $shopSeed->execute(['color_default', 'Originalfarbe', 'Carmelo wieder in Goldgelb.', 'points', 10, 9999, 11]);

    // Enforce latest pricing/currency/names for existing items
    $shopUpdate = $pdo->prepare("UPDATE shop_items SET name = ?, description = ?, currency = ?, price = ? WHERE code = ?");;
    $shopUpdate->execute(['Blume', 'Eine einzelne Blüte für die Wand.', 'points', 250, 'flower_wall']);
    $shopUpdate->execute(['Kleine Lampe', 'Eine kleine gemütliche Lampe für das Zimmer.', 'diamonds', 5, 'small_lamp']);

    // Enforce latest max_quantity values
    $mqUpdate = $pdo->prepare("UPDATE shop_items SET max_quantity = ? WHERE code = ?");
    $mqUpdate->execute([3, 'small_lamp']);
    $mqUpdate->execute([2, 'picture_frame']);
    $shopUpdate->execute(['Bilderrahmen', 'Ein süßer Bilderrahmen für die Wand.', 'diamonds', 7, 'picture_frame']);
    $shopUpdate->execute(['Bett', 'Ein kuscheliges Premium-Bett für dein Huhn.', 'stars', 10, 'chicken_house']);
    $shopUpdate->execute(['Diamant kaufen', '1 Diamant für 500 Punkte.', 'points', 500, 'diamond_buy']);
    $shopUpdate->execute(['3 Diamanten kaufen', '3 Diamanten für 1000 Punkte.', 'points', 1000, 'diamond_buy_3']);
    $shopUpdate->execute(['Farbe: Rosa',  'Färbe Carmelo rosa.',  'points', 50, 'color_pink']);
    $shopUpdate->execute(['Farbe: Blau',  'Färbe Carmelo blau.',  'points', 50, 'color_blue']);
    $shopUpdate->execute(['Farbe: Grün', 'Färbe Carmelo grün.', 'points', 50, 'color_green']);
    $shopUpdate->execute(['Farbe: Lila',  'Färbe Carmelo lila.',  'points', 50, 'color_purple']);
    $shopUpdate->execute(['Farbe: Hellgrau',  'Färbe Carmelo hellgrau.',  'points', 50, 'color_white']);
    $shopUpdate->execute(['Originalfarbe', 'Carmelo wieder in Goldgelb.', 'points', 10, 'color_default']);
} catch (PDOException $e) {
    // Ignore migration errors to keep startup resilient
}

// Migration: app_state key-value table for runtime state
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_verb_stats (
            user_id INTEGER NOT NULL,
            tense TEXT NOT NULL,
            total INTEGER NOT NULL DEFAULT 0,
            correct_first_try INTEGER NOT NULL DEFAULT 0,
            incorrect INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (user_id, tense),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
} catch (PDOException $e) {
    // Table may already exist
}

// Migration: app_state key-value table for runtime state
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_state (
            key TEXT PRIMARY KEY NOT NULL,
            value TEXT NOT NULL
        )
    ");
    // Migrate fiesta_mode from game_config.php to DB if not yet present
    $exists = $pdo->query("SELECT COUNT(*) FROM app_state WHERE key = 'fiesta_mode'")->fetchColumn();
    if (!$exists) {
        $gc = require __DIR__ . '/../data/game_config.php';
        $fm = $gc['fiesta_mode'] ?? 'normal';
        $pdo->prepare("INSERT OR IGNORE INTO app_state (key, value) VALUES (?, ?)")->execute(['fiesta_mode', $fm]);
    }
    $exists = $pdo->query("SELECT COUNT(*) FROM app_state WHERE key = 'require_access_token'")->fetchColumn();
    if (!$exists) {
        $gc = $gc ?? require __DIR__ . '/../data/game_config.php';
        $rat = !empty($gc['require_access_token']) ? '1' : '0';
        $pdo->prepare("INSERT OR IGNORE INTO app_state (key, value) VALUES (?, ?)")->execute(['require_access_token', $rat]);
    }
} catch (PDOException $e) {
    // Ignore
}

/**
 * Get a value from the app_state table.
 */
function get_app_state(PDO $pdo, string $key, string $default = ''): string {
    $stmt = $pdo->prepare("SELECT value FROM app_state WHERE key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['value'] : $default;
}

/**
 * Set a value in the app_state table.
 */
function set_app_state(PDO $pdo, string $key, string $value): void {
    $stmt = $pdo->prepare("INSERT INTO app_state (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value");
    $stmt->execute([$key, $value]);
}

// Migration: audio_cache table
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS audio_cache (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            text_hash TEXT UNIQUE NOT NULL,
            text TEXT NOT NULL,
            filename TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
} catch (PDOException $e) {
    // Ignore
}

// Migration: add question_set column to users
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN question_set TEXT DEFAULT NULL");
} catch (PDOException $e) {
    // Column already exists
}

// Migration: add pet_color column to users
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN pet_color TEXT DEFAULT NULL");
} catch (PDOException $e) {
    // Column already exists
}

// Ensure audio directory exists
$audio_dir = __DIR__ . '/../audio';
if (!is_dir($audio_dir)) {
    mkdir($audio_dir, 0777, true);
}