<?php
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Access token gate
$data_dir = __DIR__ . '/data';
$db_file = $data_dir . '/micarmelo.sqlite';
if (file_exists($db_file)) {
    $gate_pdo = new PDO("sqlite:$db_file");
    $gate_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $gate_pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $require_token = false;
    try {
        $row = $gate_pdo->query("SELECT value FROM app_state WHERE key = 'require_access_token'")->fetch();
        $require_token = $row && $row['value'] === '1';
    } catch (PDOException $e) { /* table may not exist yet */ }
    if ($require_token) {
        $count = (int)$gate_pdo->query("SELECT COUNT(*) FROM access_tokens WHERE expires_at > datetime('now')")->fetchColumn();
        if ($count > 0) {
            $token = $_GET['t'] ?? '';
            if ($token === '') {
                http_response_code(404);
                exit;
            }
            $stmt = $gate_pdo->prepare("SELECT id FROM access_tokens WHERE token = ? AND expires_at > datetime('now')");
            $stmt->execute([$token]);
            if (!$stmt->fetch()) {
                http_response_code(403);
                exit;
            }
        }
    }
    unset($gate_pdo);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Carmelo - Virtual Pet Chicken</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <!-- Twitter Bootstrap 5.3.8 CSS -->
    <link href="vendor/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=<?= filemtime(__DIR__ . '/css/style.css') ?>">
</head>
<body>
    <div class="container-fluid text-center mt-3" id="app">
        
        <!-- LOGIN VIEW -->
        <div id="view-login" class="view-section">
            <div class="mt-4 mb-3">
                <div id="login-chicken" class="anim-idle mx-auto">
                    <div class="chicken-body">
                        <div class="chicken-head">
                            <div class="chicken-eye"></div>
                            <div class="chicken-beak">
                                <div class="chicken-beak-top"></div>
                                <div class="chicken-beak-bottom"></div>
                            </div>
                            <div class="chicken-comb"></div>
                        </div>
                        <div class="chicken-wing"></div>
                        <div class="chicken-tail"></div>
                    </div>
                    <div class="chicken-legs">
                        <div class="chicken-leg chicken-leg-left"></div>
                        <div class="chicken-leg chicken-leg-right"></div>
                    </div>
                </div>
            </div>
            <h1 class="mb-4">Mi Carmelo</h1>
            <div class="card mx-auto" style="max-width: 400px;">
                <div class="card-body">
                    <form id="form-login">
                        <div class="mb-3">
                            <input type="text" id="login-username" class="form-control" placeholder="Username" required>
                        </div>
                        <div class="mb-3">
                            <input type="password" id="login-password" class="form-control" placeholder="Password" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Login</button>
                        <div id="login-error" class="text-danger mt-2 d-none"></div>
                    </form>
                </div>
            </div>
        </div>

        <!-- MAIN GAME VIEW -->
        <div id="view-game" class="view-section d-none position-relative" style="min-height: 100vh;">
            <header>
                <h1>Mi Carmelo <span id="user-display-name" class="fs-6 text-muted"></span></h1>
                <div id="status-bar" class="d-flex justify-content-center gap-2 px-2 py-2">
                    <div class="card text-center flex-fill" style="min-width:0">
                        <div class="card-body py-2 px-2">
                            <div class="fs-4">💎</div>
                            <div class="fw-bold" id="diamond-count">0</div>
                            <div class="text-muted" style="font-size:0.7rem">Diamonds</div>
                        </div>
                    </div>
                    <div class="card text-center flex-fill" style="min-width:0">
                        <div class="card-body py-2 px-2">
                            <div class="fs-4">⭐</div>
                            <div class="fw-bold" id="star-count">0</div>
                            <div class="text-muted" style="font-size:0.7rem">Stars</div>
                        </div>
                    </div>
                    <div class="card text-center flex-fill" style="min-width:0">
                        <div class="card-body py-2 px-2">
                            <div class="fs-4">🏆</div>
                            <div class="fw-bold" id="point-count">0</div>
                            <div class="text-muted" style="font-size:0.7rem">Points</div>
                        </div>
                    </div>
                </div>
            </header>
            
            <main class="mt-4">
                <!-- Central pet interaction area — the chicken walks within this stage -->
                <div id="pet-area">
                    <div id="pet-decor"></div>
                    <div id="speech-bubble" class="d-none">
                        Tengo hambre :-( Dame de comer
                    </div>
                    <div id="chicken-sprite" class="anim-idle">
                        <div class="chicken-body">
                            <div class="chicken-head">
                                <div class="chicken-eye"></div>
                                <div class="chicken-beak">
                                    <div class="chicken-beak-top"></div>
                                    <div class="chicken-beak-bottom"></div>
                                </div>
                                <div class="chicken-comb"></div>
                            </div>
                            <div class="chicken-wing"></div>
                            <div class="chicken-tail"></div>
                        </div>
                        <div class="chicken-legs">
                            <div class="chicken-leg chicken-leg-left"></div>
                            <div class="chicken-leg chicken-leg-right"></div>
                        </div>
                    </div>
                </div>

                <div class="mt-4" id="game-buttons">
                    <button id="btn-pet" class="btn btn-info btn-lg m-2">Acariciar</button>
                    <button id="btn-feed" class="btn btn-warning btn-lg m-2">Alimentar</button>
                    <button id="btn-clock" class="btn btn-secondary btn-lg m-2" title="Clock challenge">🕐</button>
                    <button id="btn-fiesta" class="btn btn-danger btn-lg m-2 d-none">🎉 Fiesta</button>
                    <button id="btn-shop" class="btn btn-outline-primary btn-lg m-2">Shop</button>
                    <div id="feed-countdown" class="text-muted small mt-1 d-none"></div>
                    <div id="fiesta-countdown" class="text-muted small mt-1 d-none"></div>
                </div>

                <!-- DEAD OVERLAY -->
                <div id="dead-overlay" class="d-none mt-3">
                    <div class="alert alert-danger text-center">
                        <h4>💀 Your chicken has died!</h4>
                        <p>It wasn't fed in time...</p>
                        <button id="btn-revive" class="btn btn-success btn-lg">🪺 Revive Chicken</button>
                    </div>
                </div>

                <div class="mt-5 d-flex flex-wrap justify-content-center gap-2">
                    <button id="btn-settings" class="btn btn-outline-secondary btn-sm">Settings</button>
                    <button id="btn-admin" class="btn btn-outline-danger btn-sm d-none">Admin Panel</button>
                    <button id="btn-sleep-toggle" class="btn btn-outline-info btn-sm d-none" title="Schlaf-Modus umschalten">😴 Auto</button>
                    <button id="btn-notifications" class="btn btn-outline-secondary btn-sm d-none" title="Enable/disable notifications">🔔 Notifications</button>
                    <button id="btn-logout" class="btn btn-outline-dark btn-sm">Logout</button>
                </div>
                <div class="text-center text-muted small mt-2" style="opacity:.45">v1.8</div>
            </main>

            <!-- CHALLENGE OVERLAY -->
            <div id="challenge-overlay" class="position-absolute top-0 start-0 w-100 h-100 bg-white d-flex flex-column z-3 align-items-center justify-content-center d-none" style="min-height: 100vh;">
                <div class="w-100 px-3" style="max-width: 500px;">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 id="challenge-progress">Question 1/3</h4>
                        <button id="btn-challenge-close" class="btn-close"></button>
                    </div>
                    
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <p id="challenge-question-label" class="text-center text-muted small mb-1 d-none"></p>
                            <div id="challenge-clock-face" class="text-center mb-3 d-none"></div>
                            <h3 id="challenge-question" class="mb-4 text-center">...</h3>
                            
                            <form id="form-challenge">
                                <div id="challenge-text-input" class="mb-3">
                                    <input type="text" id="challenge-answer" class="form-control form-control-lg text-center" placeholder="Your answer" autocomplete="off">
                                </div>
                                <div id="challenge-mc-options" class="d-grid gap-2 mb-3 d-none"></div>
                                <button type="submit" id="btn-challenge-submit" class="btn btn-primary btn-lg w-100">Check</button>
                            </form>

                            <div id="challenge-feedback" class="mt-4 p-3 rounded d-none text-center">
                                <h5 id="challenge-feedback-title"></h5>
                                <p id="challenge-feedback-text" class="d-none"></p>
                                <div class="d-flex gap-2 mt-2">
                                    <button type="button" id="btn-tts-play" class="btn btn-outline-secondary btn-lg flex-fill d-none">🔊 Escuchar</button>
                                    <button id="btn-challenge-next" class="btn btn-success btn-lg flex-fill d-none">▶️ Next</button>
                                </div>
                                <button id="btn-challenge-repeat" class="btn btn-warning w-100 mt-2 d-none">🔄 Repeat</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ADMIN VIEW -->
        <div id="view-admin" class="view-section d-none">
            <button class="btn btn-secondary mb-2 btn-admin-back">Back to Game</button>
            <h2>Admin Panel</h2>

            <!-- ADMIN MENU -->
            <div id="admin-menu" class="list-group mx-auto mb-3" style="max-width: 600px;">
                <a href="#" class="list-group-item list-group-item-action btn-admin-section" data-section="admin-section-users">👥 Users</a>
                <a href="#" class="list-group-item list-group-item-action btn-admin-section" data-section="admin-section-questions">📝 Questions</a>
                <a href="#" class="list-group-item list-group-item-action btn-admin-section" data-section="admin-section-push">🔔 Push Notifications</a>
                <a href="#" class="list-group-item list-group-item-action btn-admin-section" data-section="admin-section-tokens">🔑 Access Tokens</a>
                <a href="#" class="list-group-item list-group-item-action btn-admin-section" data-section="admin-section-shop">🛒 Shop</a>
                <a href="#" class="list-group-item list-group-item-action btn-admin-section" data-section="admin-section-fiesta">🎉 Fiesta</a>
                <a href="#" class="list-group-item list-group-item-action btn-admin-section" data-section="admin-section-ai">🤖 AI Generate Questions</a>
                <a href="#" class="list-group-item list-group-item-action btn-admin-section" data-section="admin-section-audio">🔊 Audio Cache</a>
            </div>

            <!-- USERS SECTION -->
            <div id="admin-section-users" class="admin-section d-none">
                <div class="card mx-auto text-start mb-4" style="max-width: 600px;">
                    <div class="card-body">
                        <h5>Users</h5>
                        <div id="admin-users-list" class="list-group mb-3"></div>
                        <hr>
                        <h6>Create User</h6>
                        <form id="form-create-user">
                            <div class="mb-2">
                                <input type="text" id="admin-new-username" class="form-control" placeholder="Username" required>
                            </div>
                            <div class="mb-2">
                                <input type="password" id="admin-new-password" class="form-control" placeholder="Password" required minlength="4">
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="admin-new-isadmin">
                                <label class="form-check-label" for="admin-new-isadmin">Admin</label>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Create User</button>
                        </form>
                        <div id="admin-user-msg" class="mt-2 small"></div>
                    </div>
                </div>
                <!-- USER DETAIL VIEW (hidden by default) -->
                <div id="admin-user-detail" class="card mx-auto text-start mb-4 d-none" style="max-width: 600px;">
                    <div class="card-body">
                        <button id="btn-user-detail-back" class="btn btn-outline-secondary btn-sm mb-3">&larr; Back to Users</button>
                        <h5 id="user-detail-title"></h5>
                        <form id="form-edit-user" class="mb-3">
                            <div class="mb-2">
                                <label class="form-label">Username</label>
                                <input type="text" id="edit-user-username" class="form-control" required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">New Password <span class="text-muted small">(leave blank to keep)</span></label>
                                <input type="password" id="edit-user-password" class="form-control" minlength="4">
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="edit-user-isadmin">
                                <label class="form-check-label" for="edit-user-isadmin">Admin</label>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Question Set</label>
                                <select id="edit-user-question-set" class="form-select">
                                    <option value="">Default (questions.yaml)</option>
                                </select>
                            </div>
                            <hr>
                            <div class="mb-2">
                                <label class="form-label">🏆 Points</label>
                                <div class="input-group">
                                    <button type="button" class="btn btn-outline-secondary btn-stat-minus" data-target="edit-user-points" data-step="25">−25</button>
                                    <input type="number" id="edit-user-points" class="form-control text-center" min="0" step="25" readonly>
                                    <button type="button" class="btn btn-outline-secondary btn-stat-plus" data-target="edit-user-points" data-step="25">+25</button>
                                </div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">💎 Diamonds</label>
                                <div class="input-group">
                                    <button type="button" class="btn btn-outline-secondary btn-stat-minus" data-target="edit-user-diamonds" data-step="1">−1</button>
                                    <input type="number" id="edit-user-diamonds" class="form-control text-center" min="0" step="1" readonly>
                                    <button type="button" class="btn btn-outline-secondary btn-stat-plus" data-target="edit-user-diamonds" data-step="1">+1</button>
                                </div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">⭐ Stars</label>
                                <div class="input-group">
                                    <button type="button" class="btn btn-outline-secondary btn-stat-minus" data-target="edit-user-stars" data-step="1">−1</button>
                                    <input type="number" id="edit-user-stars" class="form-control text-center" min="0" step="1" readonly>
                                    <button type="button" class="btn btn-outline-secondary btn-stat-plus" data-target="edit-user-stars" data-step="1">+1</button>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Save Changes</button>
                        </form>
                        <div class="d-grid gap-2 mb-3">
                            <button id="btn-detail-reset-feed" class="btn btn-warning">🍗 Reset Feed Timer</button>
                            <button id="btn-detail-reset-fiesta" class="btn btn-warning">🎉 Reset Fiesta Cooldown</button>
                            <button id="btn-detail-test-push" class="btn btn-info">🔔 Send Test Push</button>
                            <button id="btn-detail-clear-subs" class="btn btn-outline-secondary">🧹 Clear Push Subscriptions</button>
                            <button id="btn-detail-clean-stats" class="btn btn-outline-secondary">🧹 Gelöschte Fragen entfernen</button>
                            <button id="btn-detail-clear-decos" class="btn btn-outline-warning">🔄 Dekorationen zurücksetzen & erstatten</button>
                            <button id="btn-detail-kill" class="btn btn-outline-danger">💀 Kill Chicken</button>
                            <button id="btn-detail-delete" class="btn btn-danger">🗑️ Delete User</button>
                        </div>
                        <div id="user-detail-msg" class="small mb-3"></div>
                        <h6>Question Statistics</h6>
                        <div id="user-detail-stats"></div>
                    </div>
                </div>
            </div>

            <!-- QUESTIONS SECTION -->
            <div id="admin-section-questions" class="admin-section d-none">
                <div class="card mx-auto text-start mb-4" style="max-width: 600px;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="mb-0">Questions</h5>
                            <button id="btn-add-question" class="btn btn-outline-primary btn-sm">+ Add Question</button>
                        </div>
                        <div id="admin-questions-list"></div>
                        <button id="btn-save-questions" class="btn btn-success btn-sm mt-3 w-100">Save All Questions</button>
                        <div id="admin-yaml-msg" class="mt-2 small"></div>
                    </div>
                </div>
            </div>

            <!-- PUSH NOTIFICATIONS SECTION -->
            <div id="admin-section-push" class="admin-section d-none">
                <div class="card mx-auto text-start mb-4" style="max-width: 600px;">
                    <div class="card-body">
                        <h5>Push Notifications</h5>
                        <p class="small text-muted">Send a test push notification to a user, or trigger hunger alerts for all hungry chickens.</p>
                        <button id="btn-send-hungry" class="btn btn-warning btn-sm w-100 mb-2">🐔 Send Hunger Alerts</button>
                        <div id="admin-push-msg" class="mt-2 small"></div>
                        <hr>
                        <h6>Debug Info</h6>
                        <div id="admin-push-debug" class="small text-muted">Loading...</div>
                    </div>
                </div>
            </div>

            <!-- ACCESS TOKENS SECTION -->
            <div id="admin-section-tokens" class="admin-section d-none">
                <div class="card mx-auto text-start mb-4" style="max-width: 600px;">
                    <div class="card-body">
                        <h5>Access Tokens</h5>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="toggle-require-token">
                            <label class="form-check-label" for="toggle-require-token">Require access token to load page</label>
                        </div>
                        <button id="btn-generate-token" class="btn btn-primary btn-sm w-100 mb-2">🔑 Generate New Token</button>
                        <div id="admin-token-msg" class="small mb-2"></div>
                        <div id="admin-tokens-list"></div>
                    </div>
                </div>
            </div>

            <!-- SHOP ADMIN SECTION -->
            <div id="admin-section-shop" class="admin-section d-none">
                <div class="card mx-auto text-start mb-4" style="max-width: 700px;">
                    <div class="card-body">
                        <h5>Shop Overview</h5>
                        <div id="admin-shop-content">Loading...</div>
                    </div>
                </div>
            </div>

            <!-- FIESTA SECTION -->
            <div id="admin-section-fiesta" class="admin-section d-none">
                <div class="card mx-auto text-start mb-4" style="max-width: 600px;">
                    <div class="card-body">
                        <h5>🎉 Fiesta Settings</h5>
                        <div class="d-flex align-items-center justify-content-between">
                            <span>Party Button</span>
                            <button id="btn-toggle-fiesta" class="btn btn-sm"></button>
                        </div>
                        <div id="fiesta-toggle-msg" class="small mt-1"></div>
                    </div>
                </div>
            </div>

            <!-- AI QUESTIONS SECTION -->
            <div id="admin-section-ai" class="admin-section d-none">
                <div class="card mx-auto text-start mb-4" style="max-width: 600px;">
                    <div class="card-body">
                        <h5>🤖 AI Generate Questions</h5>
                        <div id="ai-no-key" class="alert alert-warning d-none">No OpenAI API key configured. Please set <code>openai_api_key</code> in <code>data/game_config.php</code>.</div>
                        <div id="ai-form-area">
                            <div class="mb-3">
                                <label class="form-label">Topic (e.g. "Food", "Family", "Colors")</label>
                                <input type="text" id="ai-topic" class="form-control" placeholder="Enter topic..." maxlength="200">
                            </div>
                            <button id="btn-ai-generate" class="btn btn-primary w-100 mb-2">🎲 Generate Questions</button>
                            <div id="ai-spinner" class="text-center d-none"><div class="spinner-border text-primary" role="status"></div><div class="small text-muted mt-1">Generating questions...</div></div>
                            <div id="ai-error" class="alert alert-danger d-none mt-2"></div>
                            <div id="ai-results" class="d-none mt-3">
                                <h6>Generated Questions</h6>
                                <div id="ai-questions-list"></div>
                                <div class="d-flex gap-2 mt-3">
                                    <button id="btn-ai-add" class="btn btn-success flex-fill">✅ Add Selected</button>
                                    <button id="btn-ai-retry" class="btn btn-outline-secondary">🔄 Retry</button>
                                </div>
                                <div id="ai-add-msg" class="small mt-2"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="admin-section-audio" class="admin-section d-none">
                <div class="card mx-auto text-start mb-4" style="max-width: 600px;">
                    <div class="card-body">
                        <h5>🔊 Audio Cache</h5>
                        <div id="audio-list" class="list-group"></div>
                        <p id="audio-empty" class="text-muted small mt-2 d-none">No cached audio files.</p>
                    </div>
                </div>
            </div>

            <button class="btn btn-secondary mt-2 mb-3 btn-admin-back">Back to Game</button>
        </div>

        <!-- SETTINGS VIEW -->
        <div id="view-settings" class="view-section d-none">
            <h2>User Settings</h2>
            <div class="card mx-auto text-start" style="max-width: 400px;">
                <div class="card-body">
                    <form id="form-settings">
                        <div class="mb-3">
                            <label>Questions per challenge (3-5)</label>
                            <input type="number" id="setting-questions" class="form-control" min="3" max="5" value="3">
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Save Settings</button>
                    </form>
                    <hr>
                    <form id="form-password">
                        <div class="mb-3">
                            <label>New Password</label>
                            <input type="password" id="setting-password" class="form-control" required minlength="4">
                        </div>
                        <button type="submit" class="btn btn-warning w-100">Change Password</button>
                        <div id="settings-msg" class="mt-2 text-success"></div>
                    </form>
                    <button id="btn-settings-back" class="btn btn-secondary w-100 mt-3">Back to Game</button>
                </div>
            </div>
        </div>

        <!-- SHOP VIEW -->
        <div id="view-shop" class="view-section d-none">
            <h2>Shop</h2>
            <p class="text-muted small">Kaufe Dekorationen für Carmelo ❤️</p>
            <div id="shop-balance" class="small mb-3"></div>
            <div id="shop-list" class="row g-3 justify-content-center"></div>
            <button id="btn-shop-back" class="btn btn-secondary mt-3">Zurück zum Spiel</button>
        </div>

    </div>

    <!-- Twitter Bootstrap 5.3.8 JS -->
    <script src="vendor/bootstrap.bundle.min.js"></script>
    <script src="js/app.js?v=<?= filemtime(__DIR__ . '/js/app.js') ?>"></script>
</body>
</html>