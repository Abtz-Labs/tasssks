<?php
/**
 * Tasssks — A single-file lightweight kanban board.
 * Inspired by Trello, Fizzy (37signals), and phpLiteAdmin.
 *
 * Requirements: PHP 8.1+ with SQLite3 extension.
 *
 * Dev server: php -S localhost:8080 index.php
 */

// When used as PHP built-in server router, serve allowed static files directly
if (php_sapi_name() === 'cli-server') {
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($uri !== '/' && $uri !== '' && file_exists(__DIR__ . $uri)) {
        if (preg_match('/\.(sqlite|sqlite3|db|sql|env|htaccess|htpasswd)$/i', $uri)
            || str_contains($uri, '/.git')
        ) {
            http_response_code(403);
            return true;
        }
        return false;
    }
}

// ============================================================================
// CONFIGURATION
// ============================================================================

define('APP_NAME', 'Tasssks');
define('APP_VERSION', '0.1.0');
define('DB_FILE', getenv('TASSSKS_DB_FILE') ?: __DIR__ . '/kanban.sqlite');
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB
define('IMAGE_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']);

// ============================================================================
// DATABASE SETUP
// ============================================================================

function getDb(): PDO {
    static $db = null;
    if ($db === null) {
        $db = new PDO('sqlite:' . DB_FILE, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA foreign_keys=ON');
    }
    return $db;
}

function initDatabase(): void {
    $db = getDb();
    $db->exec("
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT
        );
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'member',
            created_at TEXT DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS projects (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            created_at TEXT DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS project_owners (
            project_id TEXT NOT NULL,
            user_id INTEGER NOT NULL,
            PRIMARY KEY (project_id, user_id),
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS columns_ (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id TEXT NOT NULL,
            name TEXT NOT NULL,
            position INTEGER NOT NULL DEFAULT 0,
            color TEXT DEFAULT '#6b7280',
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS cards (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            column_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            description TEXT DEFAULT '',
            position INTEGER NOT NULL DEFAULT 0,
            created_at TEXT DEFAULT (datetime('now')),
            updated_at TEXT DEFAULT (datetime('now')),
            FOREIGN KEY (column_id) REFERENCES columns_(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id TEXT NOT NULL,
            name TEXT NOT NULL,
            color TEXT DEFAULT '#3b82f6',
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS card_tags (
            card_id INTEGER NOT NULL,
            tag_id INTEGER NOT NULL,
            PRIMARY KEY (card_id, tag_id),
            FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE,
            FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            card_id INTEGER NOT NULL,
            filename TEXT NOT NULL,
            path TEXT NOT NULL,
            created_at TEXT DEFAULT (datetime('now')),
            FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS guests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id TEXT NOT NULL,
            token TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            email TEXT DEFAULT '',
            can_comment INTEGER DEFAULT 1,
            created_at TEXT DEFAULT (datetime('now')),
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            card_id INTEGER NOT NULL,
            user_id INTEGER,
            author_name TEXT NOT NULL,
            content TEXT NOT NULL,
            created_at TEXT DEFAULT (datetime('now')),
            FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        );
        CREATE TABLE IF NOT EXISTS last_seen (
            user_id INTEGER NOT NULL,
            card_id INTEGER NOT NULL,
            seen_at TEXT NOT NULL,
            PRIMARY KEY (user_id, card_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS login_attempts (
            ip TEXT NOT NULL,
            attempted_at TEXT DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS watchers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            project_id TEXT,
            card_id INTEGER,
            created_at TEXT DEFAULT (datetime('now')),
            UNIQUE(user_id, project_id, card_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS project_webhooks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id TEXT NOT NULL,
            url TEXT NOT NULL,
            type TEXT NOT NULL DEFAULT 'generic',
            enabled INTEGER DEFAULT 1,
            created_at TEXT DEFAULT (datetime('now')),
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS user_notification_settings (
            user_id INTEGER PRIMARY KEY,
            email TEXT DEFAULT '',
            delivery TEXT NOT NULL DEFAULT 'immediate',
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS notification_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id TEXT NOT NULL,
            event_type TEXT NOT NULL,
            payload TEXT NOT NULL,
            actor_user_id INTEGER,
            actor_name TEXT,
            created_at TEXT DEFAULT (datetime('now')),
            delivered INTEGER DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS guest_watchers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            guest_id INTEGER NOT NULL,
            project_id TEXT NOT NULL,
            card_id INTEGER,
            created_at TEXT DEFAULT (datetime('now')),
            UNIQUE(guest_id, project_id, card_id),
            FOREIGN KEY (guest_id) REFERENCES guests(id) ON DELETE CASCADE,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS notification_sent (
            notification_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            sent_at TEXT DEFAULT (datetime('now')),
            PRIMARY KEY (notification_id, user_id),
            FOREIGN KEY (notification_id) REFERENCES notification_queue(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
    ");

    migrateDatabase($db);
}

function migrateDatabase(PDO $db): void {
    // Add user_id column to comments if missing (migration from old schema)
    $cols = $db->query("PRAGMA table_info(comments)")->fetchAll();
    $hasUserId = false;
    foreach ($cols as $col) {
        if ($col['name'] === 'user_id') { $hasUserId = true; break; }
    }
    if (!$hasUserId) {
        $db->exec("ALTER TABLE comments ADD COLUMN user_id INTEGER REFERENCES users(id) ON DELETE SET NULL");
    }

    // Migrate last_seen from (project_id, card_id) to (user_id, card_id) if needed
    $lsCols = $db->query("PRAGMA table_info(last_seen)")->fetchAll();
    $hasProjectId = false;
    foreach ($lsCols as $col) {
        if ($col['name'] === 'project_id') { $hasProjectId = true; break; }
    }
    if ($hasProjectId) {
        $db->exec("DROP TABLE IF EXISTS last_seen");
        $db->exec("
            CREATE TABLE IF NOT EXISTS last_seen (
                user_id INTEGER NOT NULL,
                card_id INTEGER NOT NULL,
                seen_at TEXT NOT NULL,
                PRIMARY KEY (user_id, card_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");
    }

    // Add author_name column to cards
    $cardCols = $db->query("PRAGMA table_info(cards)")->fetchAll();
    $hasAuthorName = false;
    foreach ($cardCols as $col) {
        if ($col['name'] === 'author_name') { $hasAuthorName = true; break; }
    }
    if (!$hasAuthorName) {
        $db->exec("ALTER TABLE cards ADD COLUMN author_name TEXT DEFAULT ''");
    }

    // Add guest permission columns to projects
    $projCols = $db->query("PRAGMA table_info(projects)")->fetchAll();
    $hasGuestCreate = false;
    $hasGuestSort = false;
    foreach ($projCols as $col) {
        if ($col['name'] === 'guest_can_create_cards') $hasGuestCreate = true;
        if ($col['name'] === 'guest_can_sort_cards') $hasGuestSort = true;
    }
    if (!$hasGuestCreate) {
        $db->exec("ALTER TABLE projects ADD COLUMN guest_can_create_cards INTEGER DEFAULT 0");
    }
    if (!$hasGuestSort) {
        $db->exec("ALTER TABLE projects ADD COLUMN guest_can_sort_cards INTEGER DEFAULT 0");
    }

    // Add email column to guests
    $guestCols = $db->query("PRAGMA table_info(guests)")->fetchAll();
    $hasGuestEmail = false;
    foreach ($guestCols as $col) {
        if ($col['name'] === 'email') { $hasGuestEmail = true; break; }
    }
    if (!$hasGuestEmail) {
        $db->exec("ALTER TABLE guests ADD COLUMN email TEXT DEFAULT ''");
    }
}

// ============================================================================
// SECURITY
// ============================================================================

// Block direct access to sensitive files
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$requestPath = strtolower(parse_url($requestUri, PHP_URL_PATH));
if (preg_match('/\.(sqlite|sqlite3|db|sql)$/i', $requestPath)
    || preg_match('/\.(env|git|htaccess|htpasswd)$/i', $requestPath)
    || str_contains($requestPath, '/.git/')
) {
    http_response_code(403);
    exit;
}

header_remove('X-Powered-By');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

// ============================================================================
// HELPERS
// ============================================================================

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

function verifyCsrf(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf_token'], $token)) {
        jsonResponse(['error' => 'Invalid CSRF token'], 403);
    }
}

function jsonResponse(mixed $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function getInput(): array {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        return json_decode(file_get_contents('php://input'), true) ?? [];
    }
    return $_POST;
}

function generateToken(int $length = 32): string {
    return bin2hex(random_bytes($length / 2));
}

function slugify(string $text): string {
    $text = preg_replace('/[^a-z0-9]+/i', '-', strtolower(trim($text)));
    return trim($text, '-');
}

function hasUsers(): bool {
    $db = getDb();
    $stmt = $db->query("SELECT COUNT(*) as cnt FROM users");
    return (int) $stmt->fetch()['cnt'] > 0;
}

function needsSetup(): bool {
    return !hasUsers();
}

function getCurrentUser(): ?array {
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) return null;
    $db = getDb();
    $stmt = $db->prepare("SELECT id, name, email, role FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

function isAuthenticated(): bool {
    return getCurrentUser() !== null;
}

function isAdmin(): bool {
    $user = getCurrentUser();
    return $user && $user['role'] === 'admin';
}

function isGuest(string $projectId): bool {
    $token = $_GET['guest'] ?? $_SESSION['guest_token'] ?? null;
    if (!$token) return false;
    $db = getDb();
    $stmt = $db->prepare("SELECT id FROM guests WHERE project_id = ? AND token = ?");
    $stmt->execute([$projectId, $token]);
    return (bool) $stmt->fetch();
}

function getGuestInfo(string $projectId): ?array {
    $token = $_GET['guest'] ?? $_SESSION['guest_token'] ?? null;
    if (!$token) return null;
    $db = getDb();
    $stmt = $db->prepare("SELECT * FROM guests WHERE project_id = ? AND token = ?");
    $stmt->execute([$projectId, $token]);
    return $stmt->fetch() ?: null;
}

function isProjectOwner(string $projectId): bool {
    $user = getCurrentUser();
    if (!$user) return false;
    if ($user['role'] === 'admin') return true;
    $db = getDb();
    $stmt = $db->prepare("SELECT 1 FROM project_owners WHERE project_id = ? AND user_id = ?");
    $stmt->execute([$projectId, $user['id']]);
    return (bool) $stmt->fetch();
}

function requireAccess(string $projectId): void {
    if (!isAuthenticated() && !isGuest($projectId)) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }
}

function requireOwner(string $projectId): void {
    if (!isAuthenticated()) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }
    if (!isProjectOwner($projectId)) {
        jsonResponse(['error' => 'Forbidden'], 403);
    }
}

function requireAuth(): void {
    if (!isAuthenticated()) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }
}

function requireAdmin(): void {
    if (!isAdmin()) {
        jsonResponse(['error' => 'Forbidden'], 403);
    }
}

// ============================================================================
// NOTIFICATIONS
// ============================================================================

function notifyEvent(string $projectId, string $eventType, array $payload, ?int $actorUserId = null, ?string $actorName = null): void {
    $db = getDb();
    $stmt = $db->prepare("INSERT INTO notification_queue (project_id, event_type, payload, actor_user_id, actor_name) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$projectId, $eventType, json_encode($payload), $actorUserId, $actorName]);

    dispatchWebhooks($projectId, $eventType, $payload, $actorName);
}

function dispatchWebhooks(string $projectId, string $eventType, array $payload, ?string $actorName): void {
    $db = getDb();
    $stmt = $db->prepare("SELECT * FROM project_webhooks WHERE project_id = ? AND enabled = 1");
    $stmt->execute([$projectId]);
    $hooks = $stmt->fetchAll();
    if (!$hooks) return;

    $project = $db->prepare("SELECT name FROM projects WHERE id = ?");
    $project->execute([$projectId]);
    $projectName = $project->fetch()['name'] ?? 'Unknown';

    foreach ($hooks as $hook) {
        $body = formatWebhookPayload($hook['type'], $eventType, $payload, $projectName, $actorName);
        sendWebhook($hook['url'], $body, $hook['type']);
    }
}

function formatWebhookPayload(string $hookType, string $eventType, array $payload, string $projectName, ?string $actorName): string {
    $actor = $actorName ?: 'Someone';
    $text = match ($eventType) {
        'new_card' => "$actor created card \"{$payload['title']}\" in $projectName",
        'new_comment' => "$actor commented on \"{$payload['card_title']}\" in $projectName: {$payload['content']}",
        'card_updated' => "$actor updated card \"{$payload['title']}\" in $projectName",
        'new_project' => "$actor created project \"$projectName\"",
        'password_changed' => "$actor changed their password",
        default => "$actor triggered $eventType in $projectName",
    };

    return match ($hookType) {
        'slack' => json_encode(['text' => $text]),
        'telegram' => json_encode(['text' => $text, 'parse_mode' => 'HTML']),
        default => json_encode([
            'event' => $eventType,
            'project' => $projectName,
            'actor' => $actorName,
            'payload' => $payload,
            'timestamp' => date('c'),
        ]),
    };
}

function sendWebhook(string $url, string $body, string $type): void {
    $headers = ['Content-Type: application/json'];
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => implode("\r\n", $headers),
        'content' => $body,
        'timeout' => 5,
        'ignore_errors' => true,
    ]]);
    @file_get_contents($url, false, $ctx);
}

function autoWatchCard(int $cardId, int $userId): void {
    $db = getDb();
    $col = $db->prepare("SELECT col.project_id FROM cards c JOIN columns_ col ON c.column_id = col.id WHERE c.id = ?");
    $col->execute([$cardId]);
    $row = $col->fetch();
    if (!$row) return;
    $db->prepare("INSERT OR IGNORE INTO watchers (user_id, project_id, card_id) VALUES (?, ?, ?)")
        ->execute([$userId, $row['project_id'], $cardId]);
}

function autoWatchProject(string $projectId, int $userId): void {
    $db = getDb();
    $db->prepare("INSERT OR IGNORE INTO watchers (user_id, project_id, card_id) VALUES (?, ?, NULL)")
        ->execute([$userId, $projectId]);
}

// ============================================================================
// API ROUTER
// ============================================================================

initDatabase();

$action = $_GET['action'] ?? '';

if ($action) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($action, ['auth_login', 'auth_setup'])) {
        verifyCsrf();
    }
    match ($action) {
        // Auth
        'auth_status' => apiAuthStatus(),
        'auth_setup' => apiAuthSetup(),
        'auth_login' => apiAuthLogin(),
        'auth_logout' => apiAuthLogout(),

        // Account
        'account_update' => apiAccountUpdate(),

        // Team (admin only)
        'team_list' => apiTeamList(),
        'team_add' => apiTeamAdd(),
        'team_remove' => apiTeamRemove(),
        'team_update_role' => apiTeamUpdateRole(),
        'team_reset_password' => apiTeamResetPassword(),

        // App Settings (admin only)
        'auth_set_app_name' => apiAuthSetAppName(),

        // Projects
        'list_projects' => apiListProjects(),
        'create_project' => apiCreateProject(),
        'update_project' => apiUpdateProject(),
        'delete_project' => apiDeleteProject(),
        'guest_project_info' => apiGuestProjectInfo(),

        // Columns
        'list_columns' => apiListColumns(),
        'create_column' => apiCreateColumn(),
        'update_column' => apiUpdateColumn(),
        'delete_column' => apiDeleteColumn(),
        'reorder_columns' => apiReorderColumns(),

        // Cards
        'list_cards' => apiListCards(),
        'create_card' => apiCreateCard(),
        'update_card' => apiUpdateCard(),
        'delete_card' => apiDeleteCard(),
        'move_card' => apiMoveCard(),

        // Tags
        'list_tags' => apiListTags(),
        'create_tag' => apiCreateTag(),
        'delete_tag' => apiDeleteTag(),
        'toggle_card_tag' => apiToggleCardTag(),

        // Comments
        'list_comments' => apiListComments(),
        'create_comment' => apiCreateComment(),
        'update_comment' => apiUpdateComment(),
        'delete_comment' => apiDeleteComment(),
        'mark_comments_seen' => apiMarkCommentsSeen(),
        'unread_counts' => apiUnreadCounts(),

        // Attachments
        'upload_attachment' => apiUploadAttachment(),
        'delete_attachment' => apiDeleteAttachment(),

        // Guests
        'list_guests' => apiListGuests(),
        'create_guest' => apiCreateGuest(),
        'update_guest' => apiUpdateGuest(),
        'delete_guest' => apiDeleteGuest(),
        'guest_watch' => apiGuestWatch(),
        'guest_unwatch' => apiGuestUnwatch(),
        'guest_watch_status' => apiGuestWatchStatus(),

        // Webhooks
        'list_webhooks' => apiListWebhooks(),
        'create_webhook' => apiCreateWebhook(),
        'delete_webhook' => apiDeleteWebhook(),
        'toggle_webhook' => apiToggleWebhook(),

        // Watchers
        'watch' => apiWatch(),
        'unwatch' => apiUnwatch(),
        'list_watchers' => apiListWatchers(),

        // Notification Settings
        'get_notification_settings' => apiGetNotificationSettings(),
        'update_notification_settings' => apiUpdateNotificationSettings(),

        // SMTP Settings (admin only)
        'get_smtp_settings' => apiGetSmtpSettings(),
        'update_smtp_settings' => apiUpdateSmtpSettings(),
        'test_smtp' => apiTestSmtp(),
        'generate_cron_token' => apiGenerateCronToken(),

        // Notifications (cron)
        'send_notifications' => apiSendNotifications(),
        'send_digest' => apiSendDigest(),

        default => jsonResponse(['error' => 'Unknown action'], 404),
    };
    exit;
}

// ============================================================================
// API: AUTH
// ============================================================================

function apiAuthStatus(): void {
    $db = getDb();
    $appNameRow = $db->query("SELECT value FROM settings WHERE key = 'app_name'")->fetch();
    $user = getCurrentUser();

    jsonResponse([
        'needs_setup' => needsSetup(),
        'authenticated' => $user !== null,
        'user' => $user,
        'app_name' => ($appNameRow && $appNameRow['value']) ? $appNameRow['value'] : APP_NAME,
        'csrf_token' => $_SESSION['csrf_token'] ?? '',
    ]);
}

function apiAuthSetup(): void {
    if (!needsSetup()) {
        jsonResponse(['error' => 'Setup already completed'], 400);
    }

    $input = getInput();
    $name = trim($input['name'] ?? '');
    $email = trim(strtolower($input['email'] ?? ''));
    $password = $input['password'] ?? '';

    if (!$name || !$email || !$password) {
        jsonResponse(['error' => 'All fields are required'], 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['error' => 'Invalid email'], 400);
    }
    if (strlen($password) < 6) {
        jsonResponse(['error' => 'Password must be at least 6 characters'], 400);
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $db = getDb();
    $stmt = $db->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'admin')");
    $stmt->execute([$name, $email, $hash]);
    $userId = (int) $db->lastInsertId();

    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));

    jsonResponse(['ok' => true, 'csrf_token' => $_SESSION['csrf_token']]);
}

function getClientIp(): string {
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function checkRateLimit(): void {
    $ip = getClientIp();
    $db = getDb();
    $window = 15; // minutes
    $maxAttempts = 5;

    // Clean old attempts
    $db->prepare("DELETE FROM login_attempts WHERE attempted_at < datetime('now', ?)")->execute(["-$window minutes"]);

    // Count recent attempts
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM login_attempts WHERE ip = ? AND attempted_at > datetime('now', ?)");
    $stmt->execute([$ip, "-$window minutes"]);
    $count = (int) $stmt->fetch()['cnt'];

    if ($count >= $maxAttempts) {
        jsonResponse(['error' => 'Too many attempts. Try again later.'], 429);
    }
}

function recordFailedAttempt(): void {
    $db = getDb();
    $db->prepare("INSERT INTO login_attempts (ip) VALUES (?)")->execute([getClientIp()]);
}

function clearAttempts(): void {
    $db = getDb();
    $db->prepare("DELETE FROM login_attempts WHERE ip = ?")->execute([getClientIp()]);
}

function apiAuthLogin(): void {
    if (needsSetup()) {
        jsonResponse(['error' => 'Setup required'], 400);
    }

    $input = getInput();
    $email = trim(strtolower($input['email'] ?? ''));
    $password = $input['password'] ?? '';

    if (!$email || !$password) {
        jsonResponse(['error' => 'Email and password are required'], 400);
    }

    checkRateLimit();

    $db = getDb();
    $stmt = $db->prepare("SELECT id, password_hash FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        recordFailedAttempt();
        jsonResponse(['error' => 'Invalid email or password'], 403);
    }

    clearAttempts();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    jsonResponse(['ok' => true, 'csrf_token' => $_SESSION['csrf_token']]);
}

function apiAuthLogout(): void {
    session_destroy();
    jsonResponse(['ok' => true]);
}

function apiAuthSetAppName(): void {
    requireAdmin();
    $input = getInput();
    $name = trim($input['name'] ?? '');
    if (!$name) jsonResponse(['error' => 'Name cannot be empty'], 400);

    $db = getDb();
    $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('app_name', ?)")->execute([$name]);
    jsonResponse(['ok' => true]);
}

// ============================================================================
// API: ACCOUNT
// ============================================================================

function apiAccountUpdate(): void {
    requireAuth();
    $user = getCurrentUser();
    $input = getInput();

    $name = trim($input['name'] ?? '');
    $email = trim(strtolower($input['email'] ?? ''));
    $password = $input['password'] ?? '';
    $currentPassword = $input['current_password'] ?? '';

    if (!$name) jsonResponse(['error' => 'Name is required'], 400);
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['error' => 'Valid email is required'], 400);
    }

    $db = getDb();

    // Check email uniqueness
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $stmt->execute([$email, $user['id']]);
    if ($stmt->fetch()) {
        jsonResponse(['error' => 'Email already in use'], 400);
    }

    // If changing password, verify current password
    if ($password) {
        if (strlen($password) < 6) {
            jsonResponse(['error' => 'New password must be at least 6 characters'], 400);
        }
        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch();
        if (!password_verify($currentPassword, $row['password_hash'])) {
            jsonResponse(['error' => 'Current password is incorrect'], 403);
        }
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $db->prepare("UPDATE users SET name = ?, email = ?, password_hash = ? WHERE id = ?")->execute([$name, $email, $hash, $user['id']]);
        $projects = $db->prepare("SELECT project_id FROM project_owners WHERE user_id = ?");
        $projects->execute([$user['id']]);
        foreach ($projects->fetchAll() as $p) {
            notifyEvent($p['project_id'], 'password_changed', ['user_name' => $user['name']], $user['id'], $user['name']);
        }
    } else {
        $db->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?")->execute([$name, $email, $user['id']]);
    }

    jsonResponse(['ok' => true]);
}

// ============================================================================
// API: TEAM
// ============================================================================

function apiTeamList(): void {
    requireAdmin();
    $db = getDb();
    $users = $db->query("SELECT id, name, email, role, created_at FROM users ORDER BY created_at")->fetchAll();
    jsonResponse($users);
}

function apiTeamAdd(): void {
    requireAdmin();
    $input = getInput();
    $name = trim($input['name'] ?? '');
    $email = trim(strtolower($input['email'] ?? ''));
    $password = $input['password'] ?? '';
    $role = $input['role'] ?? 'member';

    if (!$name || !$email || !$password) {
        jsonResponse(['error' => 'Name, email, and password are required'], 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['error' => 'Invalid email'], 400);
    }
    if (strlen($password) < 6) {
        jsonResponse(['error' => 'Password must be at least 6 characters'], 400);
    }
    if (!in_array($role, ['admin', 'member'])) {
        jsonResponse(['error' => 'Invalid role'], 400);
    }

    $db = getDb();
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        jsonResponse(['error' => 'A user with this email already exists'], 400);
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $db->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)");
    $stmt->execute([$name, $email, $hash, $role]);
    jsonResponse(['ok' => true, 'id' => (int) $db->lastInsertId()]);
}

function apiTeamRemove(): void {
    requireAdmin();
    $input = getInput();
    $id = (int) ($input['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'Missing id'], 400);

    $user = getCurrentUser();
    if ($id === $user['id']) {
        jsonResponse(['error' => 'You cannot remove yourself'], 400);
    }

    $db = getDb();

    // Transfer orphaned projects to current admin
    $stmt = $db->prepare("
        SELECT po.project_id FROM project_owners po
        WHERE po.user_id = ?
        AND (SELECT COUNT(*) FROM project_owners WHERE project_id = po.project_id) = 1
    ");
    $stmt->execute([$id]);
    $orphaned = $stmt->fetchAll();
    foreach ($orphaned as $row) {
        $db->prepare("INSERT OR IGNORE INTO project_owners (project_id, user_id) VALUES (?, ?)")
            ->execute([$row['project_id'], $user['id']]);
    }

    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
    jsonResponse(['ok' => true]);
}

function apiTeamUpdateRole(): void {
    requireAdmin();
    $input = getInput();
    $id = (int) ($input['id'] ?? 0);
    $role = $input['role'] ?? '';

    if (!$id || !in_array($role, ['admin', 'member'])) {
        jsonResponse(['error' => 'Invalid input'], 400);
    }

    $user = getCurrentUser();
    if ($id === $user['id']) {
        jsonResponse(['error' => 'You cannot change your own role'], 400);
    }

    $db = getDb();
    $db->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$role, $id]);
    jsonResponse(['ok' => true]);
}

function apiTeamResetPassword(): void {
    requireAdmin();
    $input = getInput();
    $id = (int) ($input['id'] ?? 0);
    $password = $input['password'] ?? '';

    if (!$id || !$password) jsonResponse(['error' => 'Missing fields'], 400);
    if (strlen($password) < 6) {
        jsonResponse(['error' => 'Password must be at least 6 characters'], 400);
    }

    $db = getDb();
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $id]);
    jsonResponse(['ok' => true]);
}

// ============================================================================
// API: PROJECTS
// ============================================================================

function apiListProjects(): void {
    requireAuth();
    $user = getCurrentUser();
    $db = getDb();
    $projects = $db->query("SELECT id, name, slug, created_at, guest_can_create_cards, guest_can_sort_cards FROM projects ORDER BY created_at DESC")->fetchAll();

    foreach ($projects as &$project) {
        $stmt = $db->prepare("
            SELECT COUNT(cm.id) as unread
            FROM comments cm
            JOIN cards c ON cm.card_id = c.id
            JOIN columns_ col ON c.column_id = col.id
            LEFT JOIN last_seen ls ON ls.user_id = ? AND ls.card_id = c.id
            WHERE col.project_id = ?
            AND (ls.seen_at IS NULL OR cm.created_at > ls.seen_at)
        ");
        $stmt->execute([$user['id'], $project['id']]);
        $project['unread_comments'] = (int) $stmt->fetch()['unread'];

        $stmt = $db->prepare("SELECT 1 FROM project_owners WHERE project_id = ? AND user_id = ?");
        $stmt->execute([$project['id'], $user['id']]);
        $project['is_owner'] = (bool) $stmt->fetch() || $user['role'] === 'admin';

        $stmt = $db->prepare("SELECT (SELECT COUNT(*) FROM watchers WHERE project_id = ? AND card_id IS NULL) + (SELECT COUNT(*) FROM guest_watchers WHERE project_id = ? AND card_id IS NULL) as cnt");
        $stmt->execute([$project['id'], $project['id']]);
        $project['watcher_count'] = (int) $stmt->fetch()['cnt'];

        $stmt = $db->prepare("SELECT 1 FROM watchers WHERE project_id = ? AND card_id IS NULL AND user_id = ?");
        $stmt->execute([$project['id'], $user['id']]);
        $project['is_watching'] = (bool) $stmt->fetch();
    }

    jsonResponse($projects);
}

function apiGuestProjectInfo(): void {
    $token = $_GET['token'] ?? '';
    if (!$token) jsonResponse(['error' => 'Missing token'], 400);

    $db = getDb();
    $stmt = $db->prepare("SELECT p.id, p.name, p.slug, p.guest_can_create_cards, p.guest_can_sort_cards, g.name as guest_name, g.email as guest_email FROM guests g JOIN projects p ON g.project_id = p.id WHERE g.token = ?");
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row) jsonResponse(['error' => 'Invalid token'], 404);
    $row['guest_can_create_cards'] = (int) $row['guest_can_create_cards'];
    $row['guest_can_sort_cards'] = (int) $row['guest_can_sort_cards'];
    $row['guest_has_email'] = !empty($row['guest_email']);
    unset($row['guest_email']);
    jsonResponse($row);
}

function apiCreateProject(): void {
    requireAuth();
    $user = getCurrentUser();
    $input = getInput();
    $name = trim($input['name'] ?? '');

    if (!$name) jsonResponse(['error' => 'Name is required'], 400);

    $slug = slugify($name);
    $db = getDb();

    $existing = $db->prepare("SELECT id FROM projects WHERE slug = ?");
    $existing->execute([$slug]);
    if ($existing->fetch()) {
        $slug .= '-' . substr(generateToken(8), 0, 4);
    }

    $projectId = substr(bin2hex(random_bytes(4)), 0, 7);
    $stmt = $db->prepare("INSERT INTO projects (id, name, slug) VALUES (?, ?, ?)");
    $stmt->execute([$projectId, $name, $slug]);

    // Assign creator as owner
    $db->prepare("INSERT INTO project_owners (project_id, user_id) VALUES (?, ?)")
        ->execute([$projectId, $user['id']]);

    // Auto-watch the project
    autoWatchProject($projectId, $user['id']);

    // Create default columns
    $defaults = ['To Do', 'In Progress', 'Done'];
    foreach ($defaults as $i => $col) {
        $stmt = $db->prepare("INSERT INTO columns_ (project_id, name, position) VALUES (?, ?, ?)");
        $stmt->execute([$projectId, $col, $i]);
    }

    notifyEvent($projectId, 'new_project', ['name' => $name], $user['id'], $user['name']);
    jsonResponse(['id' => $projectId, 'slug' => $slug]);
}

function apiUpdateProject(): void {
    $input = getInput();
    $id = $input['id'] ?? '';
    if (!$id) jsonResponse(['error' => 'Invalid project'], 400);

    requireOwner($id);

    $db = getDb();
    $fields = [];
    $params = [];

    if (isset($input['name'])) {
        $name = trim($input['name']);
        if (!$name) jsonResponse(['error' => 'Name is required'], 400);
        $fields[] = 'name = ?';
        $params[] = $name;
    }
    if (isset($input['guest_can_create_cards'])) {
        $fields[] = 'guest_can_create_cards = ?';
        $params[] = (int) $input['guest_can_create_cards'];
    }
    if (isset($input['guest_can_sort_cards'])) {
        $fields[] = 'guest_can_sort_cards = ?';
        $params[] = (int) $input['guest_can_sort_cards'];
    }

    if ($fields) {
        $params[] = $id;
        $db->prepare("UPDATE projects SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
    }
    jsonResponse(['ok' => true]);
}

function apiDeleteProject(): void {
    $input = getInput();
    $id = $input['id'] ?? '';
    if (!$id) jsonResponse(['error' => 'Invalid project'], 400);

    requireOwner($id);
    $db = getDb();

    // Remove uploads
    $uploadPath = UPLOAD_DIR . "/$id";
    if (is_dir($uploadPath)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($uploadPath);
    }

    $stmt = $db->prepare("DELETE FROM projects WHERE id = ?");
    $stmt->execute([$id]);
    jsonResponse(['ok' => true]);
}

// ============================================================================
// API: COLUMNS
// ============================================================================

function apiListColumns(): void {
    $projectId = $_GET['project_id'] ?? '';
    if (!$projectId) jsonResponse(['error' => 'Missing project_id'], 400);
    requireAccess($projectId);

    $db = getDb();
    $stmt = $db->prepare("SELECT * FROM columns_ WHERE project_id = ? ORDER BY position");
    $stmt->execute([$projectId]);
    jsonResponse($stmt->fetchAll());
}

function apiCreateColumn(): void {
    $input = getInput();
    $projectId = $input['project_id'] ?? '';
    $name = trim($input['name'] ?? '');

    if (!$projectId || !$name) jsonResponse(['error' => 'Missing fields'], 400);
    requireOwner($projectId);

    $db = getDb();
    $maxPos = $db->prepare("SELECT COALESCE(MAX(position), -1) + 1 as next_pos FROM columns_ WHERE project_id = ?");
    $maxPos->execute([$projectId]);
    $pos = $maxPos->fetch()['next_pos'];

    $stmt = $db->prepare("INSERT INTO columns_ (project_id, name, position) VALUES (?, ?, ?)");
    $stmt->execute([$projectId, $name, $pos]);
    jsonResponse(['id' => (int) $db->lastInsertId(), 'position' => $pos]);
}

function apiUpdateColumn(): void {
    $input = getInput();
    $id = (int) ($input['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'Missing id'], 400);

    $db = getDb();
    $col = $db->prepare("SELECT project_id FROM columns_ WHERE id = ?");
    $col->execute([$id]);
    $row = $col->fetch();
    if (!$row) jsonResponse(['error' => 'Not found'], 404);

    requireOwner($row['project_id']);

    $fields = [];
    $params = [];
    if (isset($input['name'])) { $fields[] = 'name = ?'; $params[] = trim($input['name']); }
    if (isset($input['color'])) { $fields[] = 'color = ?'; $params[] = $input['color']; }

    if ($fields) {
        $params[] = $id;
        $db->prepare("UPDATE columns_ SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
    }

    jsonResponse(['ok' => true]);
}

function apiDeleteColumn(): void {
    $input = getInput();
    $id = (int) ($input['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'Missing id'], 400);

    $db = getDb();
    $col = $db->prepare("SELECT project_id FROM columns_ WHERE id = ?");
    $col->execute([$id]);
    $row = $col->fetch();
    if (!$row) jsonResponse(['error' => 'Not found'], 404);

    requireOwner($row['project_id']);

    $db->prepare("DELETE FROM columns_ WHERE id = ?")->execute([$id]);
    jsonResponse(['ok' => true]);
}

function apiReorderColumns(): void {
    $input = getInput();
    $projectId = $input['project_id'] ?? '';
    $order = $input['order'] ?? [];

    if (!$projectId || !is_array($order)) jsonResponse(['error' => 'Invalid input'], 400);
    requireOwner($projectId);

    $db = getDb();
    $stmt = $db->prepare("UPDATE columns_ SET position = ? WHERE id = ? AND project_id = ?");
    foreach ($order as $pos => $id) {
        $stmt->execute([$pos, (int) $id, $projectId]);
    }
    jsonResponse(['ok' => true]);
}

// ============================================================================
// API: CARDS
// ============================================================================

function apiListCards(): void {
    $projectId = $_GET['project_id'] ?? '';
    if (!$projectId) jsonResponse(['error' => 'Missing project_id'], 400);
    requireAccess($projectId);

    $db = getDb();
    $stmt = $db->prepare("
        SELECT c.*, col.project_id
        FROM cards c
        JOIN columns_ col ON c.column_id = col.id
        WHERE col.project_id = ?
        ORDER BY c.position
    ");
    $stmt->execute([$projectId]);
    $cards = $stmt->fetchAll();

    $user = getCurrentUser();
    $userId = $user ? $user['id'] : 0;
    $guest = getGuestInfo($projectId);

    // Attach tags, attachments, and watcher info
    foreach ($cards as &$card) {
        $tagStmt = $db->prepare("SELECT t.* FROM tags t JOIN card_tags ct ON t.id = ct.tag_id WHERE ct.card_id = ?");
        $tagStmt->execute([$card['id']]);
        $card['tags'] = $tagStmt->fetchAll();

        $attStmt = $db->prepare("SELECT * FROM attachments WHERE card_id = ?");
        $attStmt->execute([$card['id']]);
        $card['attachments'] = $attStmt->fetchAll();

        $commentStmt = $db->prepare("SELECT COUNT(*) as cnt FROM comments WHERE card_id = ?");
        $commentStmt->execute([$card['id']]);
        $card['comment_count'] = (int) $commentStmt->fetch()['cnt'];

        $wcStmt = $db->prepare("SELECT (SELECT COUNT(*) FROM watchers WHERE project_id = ? AND card_id = ?) + (SELECT COUNT(*) FROM guest_watchers WHERE project_id = ? AND card_id = ?) as cnt");
        $wcStmt->execute([$projectId, $card['id'], $projectId, $card['id']]);
        $card['watcher_count'] = (int) $wcStmt->fetch()['cnt'];

        if ($guest && !empty($guest['email'])) {
            $iwStmt = $db->prepare("SELECT 1 FROM guest_watchers WHERE guest_id = ? AND project_id = ? AND card_id = ?");
            $iwStmt->execute([$guest['id'], $projectId, $card['id']]);
            $card['is_watching'] = (bool) $iwStmt->fetch();
        } elseif ($userId) {
            $iwStmt = $db->prepare("SELECT 1 FROM watchers WHERE project_id = ? AND card_id = ? AND user_id = ?");
            $iwStmt->execute([$projectId, $card['id'], $userId]);
            $card['is_watching'] = (bool) $iwStmt->fetch();
        } else {
            $card['is_watching'] = false;
        }
    }

    jsonResponse($cards);
}

function apiCreateCard(): void {
    $input = getInput();
    $columnId = (int) ($input['column_id'] ?? 0);
    $title = trim($input['title'] ?? '');

    if (!$columnId || !$title) jsonResponse(['error' => 'Missing fields'], 400);

    $db = getDb();
    $col = $db->prepare("SELECT project_id FROM columns_ WHERE id = ?");
    $col->execute([$columnId]);
    $row = $col->fetch();
    if (!$row) jsonResponse(['error' => 'Column not found'], 404);

    if (!isAuthenticated()) {
        $projectId = $row['project_id'];
        if (!isGuest($projectId)) {
            jsonResponse(['error' => 'Unauthorized'], 401);
        }
        $project = $db->prepare("SELECT guest_can_create_cards FROM projects WHERE id = ?");
        $project->execute([$projectId]);
        if (!(int) $project->fetch()['guest_can_create_cards']) {
            jsonResponse(['error' => 'Guests cannot create cards'], 403);
        }
        $firstCol = $db->prepare("SELECT id FROM columns_ WHERE project_id = ? ORDER BY position LIMIT 1");
        $firstCol->execute([$projectId]);
        if ((int) $firstCol->fetch()['id'] !== $columnId) {
            jsonResponse(['error' => 'Guests can only add cards to the first column'], 403);
        }
    }

    $maxPos = $db->prepare("SELECT COALESCE(MAX(position), -1) + 1 as next_pos FROM cards WHERE column_id = ?");
    $maxPos->execute([$columnId]);
    $pos = $maxPos->fetch()['next_pos'];

    $guest = getGuestInfo($row['project_id']);
    $authorName = $guest ? $guest['name'] : (getCurrentUser()['name'] ?? '');

    $stmt = $db->prepare("INSERT INTO cards (column_id, title, description, position, author_name) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$columnId, $title, $input['description'] ?? '', $pos, $authorName]);
    $newCardId = (int) $db->lastInsertId();

    $user = getCurrentUser();
    if ($user) {
        autoWatchCard($newCardId, $user['id']);
    }
    notifyEvent($row['project_id'], 'new_card', ['card_id' => $newCardId, 'title' => $title], $user['id'] ?? null, $authorName);

    jsonResponse(['id' => $newCardId, 'position' => $pos]);
}

function apiUpdateCard(): void {
    $input = getInput();
    $id = (int) ($input['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'Missing id'], 400);

    requireAuth();

    $db = getDb();
    $card = $db->prepare("SELECT c.id, col.project_id FROM cards c JOIN columns_ col ON c.column_id = col.id WHERE c.id = ?");
    $card->execute([$id]);
    $row = $card->fetch();
    if (!$row) jsonResponse(['error' => 'Not found'], 404);

    $fields = [];
    $params = [];
    if (isset($input['title'])) { $fields[] = 'title = ?'; $params[] = trim($input['title']); }
    if (isset($input['description'])) { $fields[] = 'description = ?'; $params[] = $input['description']; }

    if ($fields) {
        $fields[] = "updated_at = datetime('now')";
        $params[] = $id;
        $db->prepare("UPDATE cards SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);

        $user = getCurrentUser();
        $cardTitle = $input['title'] ?? '';
        if (!$cardTitle) {
            $t = $db->prepare("SELECT title FROM cards WHERE id = ?");
            $t->execute([$id]);
            $cardTitle = $t->fetch()['title'] ?? '';
        }
        notifyEvent($row['project_id'], 'card_updated', ['card_id' => $id, 'title' => $cardTitle], $user['id'], $user['name']);
    }

    jsonResponse(['ok' => true]);
}

function apiDeleteCard(): void {
    $input = getInput();
    $id = (int) ($input['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'Missing id'], 400);

    requireAuth();

    $db = getDb();
    $card = $db->prepare("SELECT c.id, col.project_id FROM cards c JOIN columns_ col ON c.column_id = col.id WHERE c.id = ?");
    $card->execute([$id]);
    $row = $card->fetch();
    if (!$row) jsonResponse(['error' => 'Not found'], 404);

    // Remove card attachments from disk
    $atts = $db->prepare("SELECT path FROM attachments WHERE card_id = ?");
    $atts->execute([$id]);
    foreach ($atts->fetchAll() as $att) {
        $fullPath = UPLOAD_DIR . '/' . $att['path'];
        if (file_exists($fullPath)) unlink($fullPath);
    }

    $db->prepare("DELETE FROM cards WHERE id = ?")->execute([$id]);
    jsonResponse(['ok' => true]);
}

function apiMoveCard(): void {
    $input = getInput();
    $id = (int) ($input['id'] ?? 0);
    $newColumnId = (int) ($input['column_id'] ?? 0);
    $newPosition = (int) ($input['position'] ?? 0);

    if (!$id || !$newColumnId) jsonResponse(['error' => 'Missing fields'], 400);

    $db = getDb();
    $card = $db->prepare("SELECT c.id, c.column_id, col.project_id FROM cards c JOIN columns_ col ON c.column_id = col.id WHERE c.id = ?");
    $card->execute([$id]);
    $row = $card->fetch();
    if (!$row) jsonResponse(['error' => 'Not found'], 404);

    if (!isAuthenticated()) {
        $projectId = $row['project_id'];
        if (!isGuest($projectId)) {
            jsonResponse(['error' => 'Unauthorized'], 401);
        }
        $project = $db->prepare("SELECT guest_can_sort_cards FROM projects WHERE id = ?");
        $project->execute([$projectId]);
        if (!(int) $project->fetch()['guest_can_sort_cards']) {
            jsonResponse(['error' => 'Guests cannot sort cards'], 403);
        }
        $firstCol = $db->prepare("SELECT id FROM columns_ WHERE project_id = ? ORDER BY position LIMIT 1");
        $firstCol->execute([$projectId]);
        $firstColId = (int) $firstCol->fetch()['id'];
        if ((int)$row['column_id'] !== $firstColId || $newColumnId !== $firstColId) {
            jsonResponse(['error' => 'Guests can only reorder cards in the first column'], 403);
        }
    }

    $stmt = $db->prepare("UPDATE cards SET column_id = ?, position = ?, updated_at = datetime('now') WHERE id = ?");
    $stmt->execute([$newColumnId, $newPosition, $id]);

    // Reorder other cards in target column
    $others = $db->prepare("SELECT id FROM cards WHERE column_id = ? AND id != ? ORDER BY position");
    $others->execute([$newColumnId, $id]);
    $pos = 0;
    foreach ($others->fetchAll() as $other) {
        if ($pos === $newPosition) $pos++;
        $db->prepare("UPDATE cards SET position = ? WHERE id = ?")->execute([$pos, $other['id']]);
        $pos++;
    }

    jsonResponse(['ok' => true]);
}

// ============================================================================
// API: TAGS
// ============================================================================

function apiListTags(): void {
    $projectId = $_GET['project_id'] ?? '';
    if (!$projectId) jsonResponse(['error' => 'Missing project_id'], 400);
    requireAccess($projectId);

    $db = getDb();
    $stmt = $db->prepare("SELECT * FROM tags WHERE project_id = ? ORDER BY name");
    $stmt->execute([$projectId]);
    jsonResponse($stmt->fetchAll());
}

function apiCreateTag(): void {
    $input = getInput();
    $projectId = $input['project_id'] ?? '';
    $name = trim($input['name'] ?? '');
    $color = $input['color'] ?? '#3b82f6';
    $cardId = (int) ($input['card_id'] ?? 0);

    if (!$projectId || !$name) jsonResponse(['error' => 'Missing fields'], 400);
    requireAuth();

    $db = getDb();
    $stmt = $db->prepare("INSERT INTO tags (project_id, name, color) VALUES (?, ?, ?)");
    $stmt->execute([$projectId, $name, $color]);
    $tagId = (int) $db->lastInsertId();

    if ($cardId) {
        $db->prepare("INSERT OR IGNORE INTO card_tags (card_id, tag_id) VALUES (?, ?)")->execute([$cardId, $tagId]);
    }

    jsonResponse(['id' => $tagId]);
}

function apiDeleteTag(): void {
    $input = getInput();
    $id = (int) ($input['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'Missing id'], 400);

    requireAuth();

    $db = getDb();
    $tag = $db->prepare("SELECT project_id FROM tags WHERE id = ?");
    $tag->execute([$id]);
    $row = $tag->fetch();
    if (!$row) jsonResponse(['error' => 'Not found'], 404);

    $db->prepare("DELETE FROM tags WHERE id = ?")->execute([$id]);
    jsonResponse(['ok' => true]);
}

function apiToggleCardTag(): void {
    $input = getInput();
    $cardId = (int) ($input['card_id'] ?? 0);
    $tagId = (int) ($input['tag_id'] ?? 0);

    if (!$cardId || !$tagId) jsonResponse(['error' => 'Missing fields'], 400);

    requireAuth();

    $db = getDb();
    $card = $db->prepare("SELECT col.project_id FROM cards c JOIN columns_ col ON c.column_id = col.id WHERE c.id = ?");
    $card->execute([$cardId]);
    $row = $card->fetch();
    if (!$row) jsonResponse(['error' => 'Not found'], 404);

    $existing = $db->prepare("SELECT 1 FROM card_tags WHERE card_id = ? AND tag_id = ?");
    $existing->execute([$cardId, $tagId]);

    if ($existing->fetch()) {
        $db->prepare("DELETE FROM card_tags WHERE card_id = ? AND tag_id = ?")->execute([$cardId, $tagId]);
        jsonResponse(['toggled' => 'off']);
    } else {
        $db->prepare("INSERT INTO card_tags (card_id, tag_id) VALUES (?, ?)")->execute([$cardId, $tagId]);
        jsonResponse(['toggled' => 'on']);
    }
}

// ============================================================================
// API: COMMENTS
// ============================================================================

function apiListComments(): void {
    $cardId = (int) ($_GET['card_id'] ?? 0);
    if (!$cardId) jsonResponse(['error' => 'Missing card_id'], 400);

    $db = getDb();
    $card = $db->prepare("SELECT col.project_id FROM cards c JOIN columns_ col ON c.column_id = col.id WHERE c.id = ?");
    $card->execute([$cardId]);
    $row = $card->fetch();
    if (!$row) jsonResponse(['error' => 'Card not found'], 404);

    requireAccess($row['project_id']);

    $stmt = $db->prepare("SELECT * FROM comments WHERE card_id = ? ORDER BY created_at DESC");
    $stmt->execute([$cardId]);
    jsonResponse($stmt->fetchAll());
}

function apiCreateComment(): void {
    $input = getInput();
    $cardId = (int) ($input['card_id'] ?? 0);
    $content = trim($input['content'] ?? '');

    if (!$cardId || !$content) jsonResponse(['error' => 'Missing fields'], 400);

    $db = getDb();
    $card = $db->prepare("SELECT col.project_id FROM cards c JOIN columns_ col ON c.column_id = col.id WHERE c.id = ?");
    $card->execute([$cardId]);
    $row = $card->fetch();
    if (!$row) jsonResponse(['error' => 'Card not found'], 404);

    $guest = getGuestInfo($row['project_id']);
    if ($guest) {
        if (!$guest['can_comment']) jsonResponse(['error' => 'No comment permission'], 403);
        $stmt = $db->prepare("INSERT INTO comments (card_id, author_name, content) VALUES (?, ?, ?)");
        $stmt->execute([$cardId, $guest['name'], $content]);
        $commentId = (int) $db->lastInsertId();
        $actorName = $guest['name'];
        $actorId = null;
    } else {
        requireAuth();
        $user = getCurrentUser();
        $stmt = $db->prepare("INSERT INTO comments (card_id, user_id, author_name, content) VALUES (?, ?, ?, ?)");
        $stmt->execute([$cardId, $user['id'], $user['name'], $content]);
        $commentId = (int) $db->lastInsertId();
        $actorName = $user['name'];
        $actorId = $user['id'];
        autoWatchCard($cardId, $user['id']);
    }

    $cardTitle = $db->prepare("SELECT title FROM cards WHERE id = ?");
    $cardTitle->execute([$cardId]);
    $cTitle = $cardTitle->fetch()['title'] ?? '';
    notifyEvent($row['project_id'], 'new_comment', ['card_id' => $cardId, 'card_title' => $cTitle, 'content' => $content], $actorId, $actorName);

    jsonResponse(['id' => $commentId]);
}

function verifyCommentOwnership(array $comment, string $projectId): void {
    $user = getCurrentUser();
    if ($user) {
        if ($user['role'] === 'admin') return;
        if ($comment['user_id'] && (int)$comment['user_id'] === $user['id']) return;
        jsonResponse(['error' => 'You can only modify your own comments'], 403);
    }
    $guest = getGuestInfo($projectId);
    if (!$guest || $comment['author_name'] !== $guest['name']) {
        jsonResponse(['error' => 'You can only modify your own comments'], 403);
    }
}

function apiUpdateComment(): void {
    $input = getInput();
    $id = (int) ($input['id'] ?? 0);
    $content = trim($input['content'] ?? '');
    if (!$id || !$content) jsonResponse(['error' => 'Missing fields'], 400);

    $db = getDb();
    $stmt = $db->prepare("SELECT * FROM comments WHERE id = ?");
    $stmt->execute([$id]);
    $comment = $stmt->fetch();
    if (!$comment) jsonResponse(['error' => 'Not found'], 404);

    $card = $db->prepare("SELECT col.project_id FROM cards c JOIN columns_ col ON c.column_id = col.id WHERE c.id = ?");
    $card->execute([$comment['card_id']]);
    $row = $card->fetch();
    if (!$row) jsonResponse(['error' => 'Card not found'], 404);

    verifyCommentOwnership($comment, $row['project_id']);

    $db->prepare("UPDATE comments SET content = ? WHERE id = ?")->execute([$content, $id]);
    jsonResponse(['ok' => true]);
}

function apiDeleteComment(): void {
    $input = getInput();
    $id = (int) ($input['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'Missing id'], 400);

    $db = getDb();
    $stmt = $db->prepare("SELECT * FROM comments WHERE id = ?");
    $stmt->execute([$id]);
    $comment = $stmt->fetch();
    if (!$comment) jsonResponse(['error' => 'Not found'], 404);

    $card = $db->prepare("SELECT col.project_id FROM cards c JOIN columns_ col ON c.column_id = col.id WHERE c.id = ?");
    $card->execute([$comment['card_id']]);
    $row = $card->fetch();
    if (!$row) jsonResponse(['error' => 'Card not found'], 404);

    verifyCommentOwnership($comment, $row['project_id']);

    $db->prepare("DELETE FROM comments WHERE id = ?")->execute([$id]);
    jsonResponse(['ok' => true]);
}

function apiMarkCommentsSeen(): void {
    requireAuth();
    $user = getCurrentUser();
    $input = getInput();
    $cardId = (int) ($input['card_id'] ?? 0);
    if (!$cardId) jsonResponse(['error' => 'Missing card_id'], 400);

    $db = getDb();
    $db->prepare("INSERT OR REPLACE INTO last_seen (user_id, card_id, seen_at) VALUES (?, ?, datetime('now'))")
        ->execute([$user['id'], $cardId]);
    jsonResponse(['ok' => true]);
}

function apiUnreadCounts(): void {
    $projectId = $_GET['project_id'] ?? '';
    if (!$projectId) jsonResponse(['error' => 'Missing project_id'], 400);
    requireAccess($projectId);

    $user = getCurrentUser();
    if (!$user) {
        jsonResponse([]);
        return;
    }

    $db = getDb();
    $stmt = $db->prepare("
        SELECT c.id as card_id, COUNT(cm.id) as unread
        FROM cards c
        JOIN columns_ col ON c.column_id = col.id
        JOIN comments cm ON cm.card_id = c.id
        LEFT JOIN last_seen ls ON ls.user_id = ? AND ls.card_id = c.id
        WHERE col.project_id = ?
        AND (ls.seen_at IS NULL OR cm.created_at > ls.seen_at)
        GROUP BY c.id
    ");
    $stmt->execute([$user['id'], $projectId]);
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $result[$row['card_id']] = (int) $row['unread'];
    }
    jsonResponse($result);
}

// ============================================================================
// API: ATTACHMENTS
// ============================================================================

function apiUploadAttachment(): void {
    $cardId = (int) ($_POST['card_id'] ?? 0);
    if (!$cardId) jsonResponse(['error' => 'Missing card_id'], 400);

    requireAuth();

    $db = getDb();
    $card = $db->prepare("SELECT col.project_id FROM cards c JOIN columns_ col ON c.column_id = col.id WHERE c.id = ?");
    $card->execute([$cardId]);
    $row = $card->fetch();
    if (!$row) jsonResponse(['error' => 'Card not found'], 404);

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(['error' => 'No file uploaded'], 400);
    }

    $file = $_FILES['file'];
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        jsonResponse(['error' => 'File too large (max 10MB)'], 400);
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $blocked = ['php', 'phtml', 'phar', 'html', 'htm', 'xhtml', 'shtml', 'js', 'sh', 'bat', 'exe'];
    if (in_array($ext, $blocked)) {
        jsonResponse(['error' => 'File type not allowed'], 400);
    }

    $projectId = $row['project_id'];
    $dir = UPLOAD_DIR . "/$projectId/$cardId";
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = time() . '_' . preg_replace('/[^a-z0-9._-]/i', '', $file['name']);
    $relativePath = "$projectId/$cardId/$filename";

    move_uploaded_file($file['tmp_name'], "$dir/$filename");

    $stmt = $db->prepare("INSERT INTO attachments (card_id, filename, path) VALUES (?, ?, ?)");
    $stmt->execute([$cardId, $file['name'], $relativePath]);

    jsonResponse(['id' => (int) $db->lastInsertId(), 'filename' => $file['name'], 'path' => $relativePath]);
}

function apiDeleteAttachment(): void {
    $input = getInput();
    $id = (int) ($input['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'Missing id'], 400);

    requireAuth();

    $db = getDb();
    $att = $db->prepare("
        SELECT a.*, col.project_id
        FROM attachments a
        JOIN cards c ON a.card_id = c.id
        JOIN columns_ col ON c.column_id = col.id
        WHERE a.id = ?
    ");
    $att->execute([$id]);
    $row = $att->fetch();
    if (!$row) jsonResponse(['error' => 'Not found'], 404);

    $fullPath = UPLOAD_DIR . '/' . $row['path'];
    if (file_exists($fullPath)) unlink($fullPath);

    $db->prepare("DELETE FROM attachments WHERE id = ?")->execute([$id]);
    jsonResponse(['ok' => true]);
}

// ============================================================================
// API: GUESTS
// ============================================================================

function apiListGuests(): void {
    $projectId = $_GET['project_id'] ?? '';
    if (!$projectId) jsonResponse(['error' => 'Missing project_id'], 400);
    requireOwner($projectId);

    $db = getDb();
    $stmt = $db->prepare("SELECT * FROM guests WHERE project_id = ? ORDER BY created_at DESC");
    $stmt->execute([$projectId]);
    jsonResponse($stmt->fetchAll());
}

function apiCreateGuest(): void {
    $input = getInput();
    $projectId = $input['project_id'] ?? '';
    $name = trim($input['name'] ?? '');
    $canComment = (int) ($input['can_comment'] ?? 1);
    $email = trim($input['email'] ?? '');

    if (!$projectId || !$name) jsonResponse(['error' => 'Missing fields'], 400);
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) jsonResponse(['error' => 'Invalid email'], 400);
    requireOwner($projectId);

    $token = generateToken();
    $db = getDb();
    $stmt = $db->prepare("INSERT INTO guests (project_id, token, name, can_comment, email) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$projectId, $token, $name, $canComment, $email]);

    jsonResponse(['id' => (int) $db->lastInsertId(), 'token' => $token]);
}

function apiUpdateGuest(): void {
    $input = getInput();
    $id = (int) ($input['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'Missing id'], 400);

    $db = getDb();
    $guest = $db->prepare("SELECT project_id FROM guests WHERE id = ?");
    $guest->execute([$id]);
    $row = $guest->fetch();
    if (!$row) jsonResponse(['error' => 'Not found'], 404);

    requireOwner($row['project_id']);

    $fields = [];
    $params = [];
    if (isset($input['name'])) {
        $name = trim($input['name']);
        if (!$name) jsonResponse(['error' => 'Name is required'], 400);
        $fields[] = 'name = ?';
        $params[] = $name;
    }
    if (isset($input['email'])) {
        $email = trim($input['email']);
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) jsonResponse(['error' => 'Invalid email'], 400);
        $fields[] = 'email = ?';
        $params[] = $email;
    }
    if (isset($input['can_comment'])) {
        $fields[] = 'can_comment = ?';
        $params[] = (int) $input['can_comment'];
    }

    if ($fields) {
        $params[] = $id;
        $db->prepare("UPDATE guests SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
    }
    jsonResponse(['ok' => true]);
}

function apiDeleteGuest(): void {
    $input = getInput();
    $id = (int) ($input['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'Missing id'], 400);

    $db = getDb();
    $guest = $db->prepare("SELECT project_id FROM guests WHERE id = ?");
    $guest->execute([$id]);
    $row = $guest->fetch();
    if (!$row) jsonResponse(['error' => 'Not found'], 404);

    requireOwner($row['project_id']);

    $db->prepare("DELETE FROM guests WHERE id = ?")->execute([$id]);
    jsonResponse(['ok' => true]);
}

function apiGuestWatch(): void {
    $input = getInput();
    $projectId = $input['project_id'] ?? '';
    $cardId = isset($input['card_id']) ? (int) $input['card_id'] : null;
    if (!$projectId) jsonResponse(['error' => 'Missing project_id'], 400);

    $guest = getGuestInfo($projectId);
    if (!$guest || empty($guest['email'])) {
        jsonResponse(['error' => 'Guests must have an email to watch'], 403);
    }

    $db = getDb();
    $db->prepare("INSERT OR IGNORE INTO guest_watchers (guest_id, project_id, card_id) VALUES (?, ?, ?)")
        ->execute([$guest['id'], $projectId, $cardId]);
    jsonResponse(['ok' => true]);
}

function apiGuestUnwatch(): void {
    $input = getInput();
    $projectId = $input['project_id'] ?? '';
    $cardId = isset($input['card_id']) ? (int) $input['card_id'] : null;
    if (!$projectId) jsonResponse(['error' => 'Missing project_id'], 400);

    $guest = getGuestInfo($projectId);
    if (!$guest || empty($guest['email'])) {
        jsonResponse(['error' => 'Guests must have an email to watch'], 403);
    }

    $db = getDb();
    if ($cardId) {
        $db->prepare("DELETE FROM guest_watchers WHERE guest_id = ? AND project_id = ? AND card_id = ?")
            ->execute([$guest['id'], $projectId, $cardId]);
    } else {
        $db->prepare("DELETE FROM guest_watchers WHERE guest_id = ? AND project_id = ? AND card_id IS NULL")
            ->execute([$guest['id'], $projectId]);
    }
    jsonResponse(['ok' => true]);
}

function apiGuestWatchStatus(): void {
    $projectId = $_GET['project_id'] ?? '';
    $cardId = isset($_GET['card_id']) ? (int) $_GET['card_id'] : null;
    if (!$projectId) jsonResponse(['error' => 'Missing project_id'], 400);

    $guest = getGuestInfo($projectId);
    if (!$guest || empty($guest['email'])) {
        jsonResponse(['watching' => false]);
    }

    $db = getDb();
    if ($cardId) {
        $stmt = $db->prepare("SELECT 1 FROM guest_watchers WHERE guest_id = ? AND project_id = ? AND card_id = ?");
        $stmt->execute([$guest['id'], $projectId, $cardId]);
    } else {
        $stmt = $db->prepare("SELECT 1 FROM guest_watchers WHERE guest_id = ? AND project_id = ? AND card_id IS NULL");
        $stmt->execute([$guest['id'], $projectId]);
    }
    jsonResponse(['watching' => (bool) $stmt->fetch()]);
}

// ============================================================================
// API: WEBHOOKS
// ============================================================================

function apiListWebhooks(): void {
    $input = $_GET;
    $projectId = $input['project_id'] ?? '';
    if (!$projectId) jsonResponse(['error' => 'Missing project_id'], 400);
    requireOwner($projectId);

    $db = getDb();
    $stmt = $db->prepare("SELECT id, url, type, enabled, created_at FROM project_webhooks WHERE project_id = ? ORDER BY created_at");
    $stmt->execute([$projectId]);
    jsonResponse($stmt->fetchAll());
}

function apiCreateWebhook(): void {
    $input = getInput();
    $projectId = $input['project_id'] ?? '';
    $url = trim($input['url'] ?? '');
    $type = $input['type'] ?? 'generic';

    if (!$projectId || !$url) jsonResponse(['error' => 'Missing fields'], 400);
    if (!in_array($type, ['generic', 'slack', 'telegram'])) jsonResponse(['error' => 'Invalid type'], 400);
    if (!filter_var($url, FILTER_VALIDATE_URL)) jsonResponse(['error' => 'Invalid URL'], 400);

    requireOwner($projectId);

    $db = getDb();
    $stmt = $db->prepare("INSERT INTO project_webhooks (project_id, url, type) VALUES (?, ?, ?)");
    $stmt->execute([$projectId, $url, $type]);
    jsonResponse(['id' => (int) $db->lastInsertId()]);
}

function apiDeleteWebhook(): void {
    $input = getInput();
    $id = (int) ($input['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'Missing id'], 400);

    $db = getDb();
    $hook = $db->prepare("SELECT project_id FROM project_webhooks WHERE id = ?");
    $hook->execute([$id]);
    $row = $hook->fetch();
    if (!$row) jsonResponse(['error' => 'Not found'], 404);

    requireOwner($row['project_id']);
    $db->prepare("DELETE FROM project_webhooks WHERE id = ?")->execute([$id]);
    jsonResponse(['ok' => true]);
}

function apiToggleWebhook(): void {
    $input = getInput();
    $id = (int) ($input['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'Missing id'], 400);

    $db = getDb();
    $hook = $db->prepare("SELECT project_id, enabled FROM project_webhooks WHERE id = ?");
    $hook->execute([$id]);
    $row = $hook->fetch();
    if (!$row) jsonResponse(['error' => 'Not found'], 404);

    requireOwner($row['project_id']);
    $db->prepare("UPDATE project_webhooks SET enabled = ? WHERE id = ?")->execute([!$row['enabled'] ? 1 : 0, $id]);
    jsonResponse(['ok' => true]);
}

// ============================================================================
// API: WATCHERS
// ============================================================================

function apiWatch(): void {
    requireAuth();
    $user = getCurrentUser();
    $input = getInput();
    $projectId = $input['project_id'] ?? '';
    $cardId = isset($input['card_id']) ? (int) $input['card_id'] : null;

    if (!$projectId) jsonResponse(['error' => 'Missing project_id'], 400);

    $db = getDb();
    $db->prepare("INSERT OR IGNORE INTO watchers (user_id, project_id, card_id) VALUES (?, ?, ?)")
        ->execute([$user['id'], $projectId, $cardId]);
    jsonResponse(['ok' => true]);
}

function apiUnwatch(): void {
    requireAuth();
    $user = getCurrentUser();
    $input = getInput();
    $projectId = $input['project_id'] ?? '';
    $cardId = isset($input['card_id']) ? (int) $input['card_id'] : null;

    if (!$projectId) jsonResponse(['error' => 'Missing project_id'], 400);

    $db = getDb();
    if ($cardId) {
        $db->prepare("DELETE FROM watchers WHERE user_id = ? AND project_id = ? AND card_id = ?")
            ->execute([$user['id'], $projectId, $cardId]);
    } else {
        $db->prepare("DELETE FROM watchers WHERE user_id = ? AND project_id = ? AND card_id IS NULL")
            ->execute([$user['id'], $projectId]);
    }
    jsonResponse(['ok' => true]);
}

function apiListWatchers(): void {
    requireAuth();
    $input = $_GET;
    $projectId = $input['project_id'] ?? '';
    $cardId = isset($input['card_id']) ? (int) $input['card_id'] : null;

    if (!$projectId) jsonResponse(['error' => 'Missing project_id'], 400);

    $db = getDb();
    if ($cardId) {
        $stmt = $db->prepare("SELECT w.user_id, u.name, 'user' as type FROM watchers w JOIN users u ON w.user_id = u.id WHERE w.project_id = ? AND (w.card_id = ? OR w.card_id IS NULL)");
        $stmt->execute([$projectId, $cardId]);
        $gStmt = $db->prepare("SELECT gw.guest_id, g.name, 'guest' as type FROM guest_watchers gw JOIN guests g ON gw.guest_id = g.id WHERE gw.project_id = ? AND (gw.card_id = ? OR gw.card_id IS NULL)");
        $gStmt->execute([$projectId, $cardId]);
    } else {
        $stmt = $db->prepare("SELECT w.user_id, u.name, 'user' as type FROM watchers w JOIN users u ON w.user_id = u.id WHERE w.project_id = ? AND w.card_id IS NULL");
        $stmt->execute([$projectId]);
        $gStmt = $db->prepare("SELECT gw.guest_id, g.name, 'guest' as type FROM guest_watchers gw JOIN guests g ON gw.guest_id = g.id WHERE gw.project_id = ? AND gw.card_id IS NULL");
        $gStmt->execute([$projectId]);
    }
    $results = array_merge($stmt->fetchAll(), $gStmt->fetchAll());
    jsonResponse($results);
}

// ============================================================================
// API: NOTIFICATION SETTINGS
// ============================================================================

function apiGetNotificationSettings(): void {
    requireAuth();
    $user = getCurrentUser();
    $db = getDb();
    $stmt = $db->prepare("SELECT email, delivery FROM user_notification_settings WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();
    jsonResponse($row ?: ['email' => '', 'delivery' => 'immediate']);
}

function apiUpdateNotificationSettings(): void {
    requireAuth();
    $user = getCurrentUser();
    $input = getInput();
    $email = trim($input['email'] ?? '');
    $delivery = $input['delivery'] ?? 'immediate';

    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['error' => 'Invalid email'], 400);
    }
    if (!in_array($delivery, ['immediate', 'daily'])) {
        jsonResponse(['error' => 'Invalid delivery mode'], 400);
    }

    $db = getDb();
    $db->prepare("INSERT INTO user_notification_settings (user_id, email, delivery) VALUES (?, ?, ?) ON CONFLICT(user_id) DO UPDATE SET email = ?, delivery = ?")
        ->execute([$user['id'], $email, $delivery, $email, $delivery]);
    jsonResponse(['ok' => true]);
}

// ============================================================================
// API: SMTP SETTINGS
// ============================================================================

function getSmtpSettings(): array {
    $db = getDb();
    $keys = ['smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from_email', 'smtp_from_name', 'smtp_encryption'];
    $settings = [];
    foreach ($keys as $k) {
        $stmt = $db->prepare("SELECT value FROM settings WHERE key = ?");
        $stmt->execute([$k]);
        $row = $stmt->fetch();
        $settings[$k] = $row ? $row['value'] : '';
    }
    $settings['smtp_port'] = (int) ($settings['smtp_port'] ?: 587);
    if (!$settings['smtp_encryption']) $settings['smtp_encryption'] = 'tls';
    return $settings;
}

function apiGetSmtpSettings(): void {
    requireAdmin();
    $settings = getSmtpSettings();
    $settings['smtp_pass_set'] = !empty($settings['smtp_pass']);
    unset($settings['smtp_pass']);

    $db = getDb();
    $cronRow = $db->prepare("SELECT value FROM settings WHERE key = 'cron_token'");
    $cronRow->execute();
    $ct = $cronRow->fetch();
    $settings['cron_token'] = $ct ? $ct['value'] : '';

    $digestRow = $db->prepare("SELECT value FROM settings WHERE key = 'last_digest_at'");
    $digestRow->execute();
    $dt = $digestRow->fetch();
    $settings['last_digest_at'] = $dt ? $dt['value'] : '';

    jsonResponse($settings);
}

function apiUpdateSmtpSettings(): void {
    requireAdmin();
    $input = getInput();

    $encryption = $input['smtp_encryption'] ?? 'tls';
    if (!in_array($encryption, ['tls', 'ssl', 'none'])) {
        jsonResponse(['error' => 'Invalid encryption. Use: tls, ssl, or none'], 400);
    }

    $db = getDb();
    $fields = [
        'smtp_host' => trim($input['smtp_host'] ?? ''),
        'smtp_port' => (int) ($input['smtp_port'] ?? 587),
        'smtp_user' => trim($input['smtp_user'] ?? ''),
        'smtp_from_email' => trim($input['smtp_from_email'] ?? ''),
        'smtp_from_name' => trim($input['smtp_from_name'] ?? ''),
        'smtp_encryption' => $encryption,
    ];

    if (isset($input['smtp_pass']) && $input['smtp_pass'] !== '') {
        $fields['smtp_pass'] = $input['smtp_pass'];
    }

    foreach ($fields as $k => $v) {
        $db->prepare("INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = ?")
            ->execute([$k, (string) $v, (string) $v]);
    }
    jsonResponse(['ok' => true]);
}

function apiTestSmtp(): void {
    requireAdmin();
    $input = getInput();
    $to = trim($input['to'] ?? '');
    if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['error' => 'Valid email address required'], 400);
    }

    $settings = getSmtpSettings();
    if (!$settings['smtp_host']) {
        jsonResponse(['error' => 'SMTP not configured'], 400);
    }

    $result = sendSmtpEmail(
        $settings,
        $to,
        'Tasssks - SMTP Test',
        'This is a test email from Tasssks. If you received this, your SMTP configuration is working correctly.'
    );

    if ($result === true) {
        jsonResponse(['ok' => true]);
    } else {
        jsonResponse(['error' => "SMTP failed: $result"], 500);
    }
}

function apiGenerateCronToken(): void {
    requireAdmin();
    $token = generateToken(32);
    $db = getDb();
    $db->prepare("INSERT INTO settings (key, value) VALUES ('cron_token', ?) ON CONFLICT(key) DO UPDATE SET value = ?")
        ->execute([$token, $token]);
    jsonResponse(['token' => $token]);
}

// ============================================================================
// API: NOTIFICATIONS (CRON)
// ============================================================================

function verifyCronAuth(): void {
    $cronToken = $_GET['cron_token'] ?? '';
    if ($cronToken) {
        $db = getDb();
        $stored = $db->prepare("SELECT value FROM settings WHERE key = 'cron_token'");
        $stored->execute();
        $row = $stored->fetch();
        if (!$row || !hash_equals($row['value'], $cronToken)) {
            jsonResponse(['error' => 'Invalid cron token'], 403);
        }
    } else {
        requireAdmin();
    }
}

function getUnsentNotifsForUsers(array $pending, string $deliveryFilter): array {
    $db = getDb();
    $userNotifs = [];
    foreach ($pending as $notif) {
        $cardId = null;
        $payload = json_decode($notif['payload'], true);
        if (isset($payload['card_id'])) $cardId = (int) $payload['card_id'];

        $watchers = $db->prepare("SELECT DISTINCT w.user_id FROM watchers w WHERE w.project_id = ? AND (w.card_id IS NULL OR w.card_id = ?)");
        $watchers->execute([$notif['project_id'], $cardId]);

        foreach ($watchers->fetchAll() as $watcher) {
            if ($notif['actor_user_id'] && (int) $watcher['user_id'] === (int) $notif['actor_user_id']) continue;

            $alreadySent = $db->prepare("SELECT 1 FROM notification_sent WHERE notification_id = ? AND user_id = ?");
            $alreadySent->execute([$notif['id'], $watcher['user_id']]);
            if ($alreadySent->fetch()) continue;

            $prefs = $db->prepare("SELECT delivery FROM user_notification_settings WHERE user_id = ?");
            $prefs->execute([$watcher['user_id']]);
            $row = $prefs->fetch();
            $delivery = $row['delivery'] ?? 'immediate';
            if ($delivery !== $deliveryFilter) continue;

            $userNotifs[$watcher['user_id']][] = $notif;
        }
    }
    return $userNotifs;
}

function formatNotifLine(array $n): string {
    $p = json_decode($n['payload'], true);
    $actor = $n['actor_name'] ?: 'Someone';
    $db = getDb();
    $projStmt = $db->prepare("SELECT name FROM projects WHERE id = ?");
    $projStmt->execute([$n['project_id']]);
    $projName = $projStmt->fetch()['name'] ?? '';
    return match ($n['event_type']) {
        'new_card' => "$actor created card \"{$p['title']}\" in $projName",
        'new_comment' => "$actor commented on \"{$p['card_title']}\" in $projName",
        'card_updated' => "$actor updated card \"{$p['title']}\" in $projName",
        'new_project' => "$actor created project \"$projName\"",
        'password_changed' => "$actor changed their password",
        default => "$actor triggered {$n['event_type']} in $projName",
    };
}

function getUserEmail(int $userId): string {
    $db = getDb();
    $prefs = $db->prepare("SELECT email FROM user_notification_settings WHERE user_id = ?");
    $prefs->execute([$userId]);
    $row = $prefs->fetch();
    $email = $row['email'] ?? '';
    if (!$email) {
        $u = $db->prepare("SELECT email FROM users WHERE id = ?");
        $u->execute([$userId]);
        $row = $u->fetch();
        $email = $row ? $row['email'] : '';
    }
    return $email;
}

function apiSendNotifications(): void {
    verifyCronAuth();
    $db = getDb();
    $pending = $db->query("SELECT * FROM notification_queue WHERE delivered = 0 ORDER BY created_at")->fetchAll();
    if (!$pending) {
        jsonResponse(['ok' => true, 'processed' => 0]);
    }

    $smtp = getSmtpSettings();
    $processed = 0;
    $userNotifs = getUnsentNotifsForUsers($pending, 'immediate');

    foreach ($userNotifs as $userId => $notifs) {
        $email = getUserEmail($userId);
        if (!$email) continue;

        foreach ($notifs as $n) {
            $text = formatNotifLine($n);
            $p = json_decode($n['payload'], true);
            $actor = $n['actor_name'] ?: 'Someone';
            $projStmt = $db->prepare("SELECT name FROM projects WHERE id = ?");
            $projStmt->execute([$n['project_id']]);
            $projName = $projStmt->fetch()['name'] ?? '';
            $subject = match ($n['event_type']) {
                'new_card' => "New card: {$p['title']}",
                'new_comment' => "New comment on: {$p['card_title']}",
                'card_updated' => "Card updated: {$p['title']}",
                'new_project' => "New project: $projName",
                'password_changed' => "Password changed: $actor",
                default => "Notification: {$n['event_type']}",
            };
            if ($smtp['smtp_host']) {
                $result = sendSmtpEmail($smtp, $email, "Tasssks - $subject", $text);
                if ($result === true) {
                    $db->prepare("INSERT OR IGNORE INTO notification_sent (notification_id, user_id) VALUES (?, ?)")
                        ->execute([$n['id'], $userId]);
                }
            }
        }
        $processed++;
    }

    // Mark processed notifications as delivered for immediate users
    if ($processed > 0) {
        $processedIds = [];
        foreach ($userNotifs as $notifs) {
            foreach ($notifs as $n) { $processedIds[] = $n['id']; }
        }
        if ($processedIds) {
            $processedIds = array_unique($processedIds);
            $ph = implode(',', array_fill(0, count($processedIds), '?'));
            $db->prepare("UPDATE notification_queue SET delivered = 1 WHERE id IN ($ph)")->execute($processedIds);
        }
    }

    jsonResponse(['ok' => true, 'processed' => $processed]);
}

function apiSendDigest(): void {
    verifyCronAuth();
    $db = getDb();
    $pending = $db->query("SELECT * FROM notification_queue WHERE delivered = 0 ORDER BY created_at")->fetchAll();
    if (!$pending) {
        $db->prepare("INSERT INTO settings (key, value) VALUES ('last_digest_at', ?) ON CONFLICT(key) DO UPDATE SET value = ?")
            ->execute([date('c'), date('c')]);
        jsonResponse(['ok' => true, 'processed' => 0]);
    }

    $smtp = getSmtpSettings();
    $processed = 0;
    $userNotifs = getUnsentNotifsForUsers($pending, 'daily');

    foreach ($userNotifs as $userId => $notifs) {
        $email = getUserEmail($userId);
        if (!$email) continue;

        $lines = [];
        foreach ($notifs as $n) {
            $lines[] = formatNotifLine($n);
        }
        if ($smtp['smtp_host'] && $lines) {
            $body = "Here's your daily summary:\n\n" . implode("\n", array_map(fn($l) => "• $l", $lines)) . "\n";
            $result = sendSmtpEmail($smtp, $email, 'Tasssks - Daily Summary', $body);
            if ($result === true) {
                foreach ($notifs as $n) {
                    $db->prepare("INSERT OR IGNORE INTO notification_sent (notification_id, user_id) VALUES (?, ?)")
                        ->execute([$n['id'], $userId]);
                }
            }
        }
        $processed++;
    }

    // Process guest watchers (digest only)
    $guestNotifs = [];
    foreach ($pending as $notif) {
        $cardId = null;
        $payload = json_decode($notif['payload'], true);
        if (isset($payload['card_id'])) $cardId = (int) $payload['card_id'];

        $gw = $db->prepare("SELECT DISTINCT gw.guest_id, g.email, g.name FROM guest_watchers gw JOIN guests g ON gw.guest_id = g.id WHERE gw.project_id = ? AND (gw.card_id IS NULL OR gw.card_id = ?) AND g.email != ''");
        $gw->execute([$notif['project_id'], $cardId]);
        foreach ($gw->fetchAll() as $gWatch) {
            if ($notif['actor_name'] === $gWatch['name']) continue;
            $guestNotifs[$gWatch['guest_id']]['email'] = $gWatch['email'];
            $guestNotifs[$gWatch['guest_id']]['notifs'][] = $notif;
        }
    }

    foreach ($guestNotifs as $guestId => $data) {
        $email = $data['email'];
        $lines = [];
        foreach ($data['notifs'] as $n) {
            $lines[] = formatNotifLine($n);
        }
        if ($smtp['smtp_host'] && $lines) {
            $body = "Here's your daily summary:\n\n" . implode("\n", array_map(fn($l) => "• $l", $lines)) . "\n";
            sendSmtpEmail($smtp, $email, 'Tasssks - Daily Summary', $body);
        }
        $processed++;
    }

    // Mark all pending as delivered after digest
    $ids = array_column($pending, 'id');
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("UPDATE notification_queue SET delivered = 1 WHERE id IN ($placeholders)")->execute($ids);
    }

    $db->prepare("INSERT INTO settings (key, value) VALUES ('last_digest_at', ?) ON CONFLICT(key) DO UPDATE SET value = ?")
        ->execute([date('c'), date('c')]);

    jsonResponse(['ok' => true, 'processed' => $processed]);
}

// ============================================================================
// SMTP SENDER
// ============================================================================

function sendSmtpEmail(array $smtp, string $to, string $subject, string $body): true|string {
    $host = $smtp['smtp_host'];
    $port = (int) $smtp['smtp_port'];
    $user = $smtp['smtp_user'];
    $pass = $smtp['smtp_pass'];
    $fromEmail = $smtp['smtp_from_email'] ?: $user;
    $fromName = $smtp['smtp_from_name'] ?: 'Tasssks';
    $encryption = $smtp['smtp_encryption'] ?? 'tls';

    if (!$host) return 'SMTP host not configured';

    $prefix = $encryption === 'ssl' ? 'ssl://' : '';
    $conn = @stream_socket_client("$prefix$host:$port", $errno, $errstr, 10);
    if (!$conn) return "Connection failed: $errstr ($errno)";

    stream_set_timeout($conn, 10);

    $greeting = fgets($conn, 512);
    if (!str_starts_with(trim($greeting), '220')) {
        fclose($conn);
        return "Unexpected greeting: $greeting";
    }

    $ehloHost = $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
    smtpCmd($conn, "EHLO $ehloHost");

    if ($encryption === 'tls') {
        smtpCmd($conn, "STARTTLS");
        if (!stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($conn);
            return 'STARTTLS failed';
        }
        smtpCmd($conn, "EHLO $ehloHost");
    }

    if ($user && $pass) {
        $r = smtpCmd($conn, "AUTH LOGIN");
        if (!str_starts_with($r, '334')) { fclose($conn); return "AUTH LOGIN rejected: $r"; }
        $r = smtpCmd($conn, base64_encode($user));
        if (!str_starts_with($r, '334')) { fclose($conn); return "AUTH user rejected: $r"; }
        $r = smtpCmd($conn, base64_encode($pass));
        if (!str_starts_with($r, '235')) { fclose($conn); return "AUTH failed: $r"; }
    }

    $fromName = str_replace(["\r", "\n"], '', $fromName);
    $fromEmail = str_replace(["\r", "\n"], '', $fromEmail);
    $to = str_replace(["\r", "\n"], '', $to);
    $subject = str_replace(["\r", "\n"], '', $subject);

    $r = smtpCmd($conn, "MAIL FROM:<$fromEmail>");
    if (!str_starts_with($r, '250')) { fclose($conn); return "MAIL FROM rejected: $r"; }
    $r = smtpCmd($conn, "RCPT TO:<$to>");
    if (!str_starts_with($r, '250') && !str_starts_with($r, '251')) { fclose($conn); return "RCPT TO rejected: $r"; }
    $r = smtpCmd($conn, "DATA");
    if (!str_starts_with($r, '354')) { fclose($conn); return "DATA rejected: $r"; }

    $headers = "From: $fromName <$fromEmail>\r\n";
    $headers .= "To: $to\r\n";
    $headers .= "Subject: $subject\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Date: " . date('r') . "\r\n";
    $headers .= "\r\n";

    $body = str_replace(["\r\n", "\r", "\n"], "\r\n", $body);
    if (str_starts_with($body, '.')) $body = '.' . $body;
    $body = str_replace("\r\n.", "\r\n..", $body);

    fwrite($conn, $headers . $body . "\r\n.\r\n");
    $dataResp = fgets($conn, 512);

    smtpCmd($conn, "QUIT");
    fclose($conn);

    if (!$dataResp || !str_starts_with(trim($dataResp), '250')) {
        return "Send failed: $dataResp";
    }
    return true;
}

function smtpCmd($conn, string $cmd): string {
    fwrite($conn, "$cmd\r\n");
    $response = '';
    $attempts = 0;
    while ($attempts++ < 50) {
        $line = fgets($conn, 512);
        if ($line === false) break;
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') break;
        if (strlen($line) < 4) break;
    }
    return trim($response);
}

// ============================================================================
// SERVE UPLOADED FILES
// ============================================================================

if (isset($_GET['file'])) {
    $filePath = str_replace('..', '', $_GET['file']);
    $projectId = explode('/', $filePath)[0] ?? '';
    if ($projectId && !isAuthenticated() && !isGuest($projectId)) {
        http_response_code(401);
        exit;
    }
    $path = UPLOAD_DIR . '/' . $filePath;
    if (file_exists($path)) {
        $mime = mime_content_type($path) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Security-Policy: sandbox');
        header('Cache-Control: public, max-age=86400');
        readfile($path);
        exit;
    }
    http_response_code(404);
    exit;
}

// ============================================================================
// FRONTEND
// ============================================================================

$isGuestRequest = isset($_GET['guest']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?></title>
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/marked@12.0.0/marked.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/dompurify@3.1.6/dist/purify.min.js"></script>
    <style>
:root {
    --bg: #f8fafc;
    --surface: #ffffff;
    --surface-hover: #f1f5f9;
    --border: #e2e8f0;
    --border-strong: #cbd5e1;
    --text: #1e293b;
    --text-muted: #64748b;
    --text-light: #94a3b8;
    --primary: #3b82f6;
    --primary-hover: #2563eb;
    --primary-light: #eff6ff;
    --danger: #ef4444;
    --danger-hover: #dc2626;
    --success: #10b981;
    --radius: 8px;
    --radius-sm: 4px;
    --shadow: 0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.04);
    --shadow-md: 0 4px 6px rgba(0,0,0,0.07), 0 2px 4px rgba(0,0,0,0.04);
    --shadow-lg: 0 10px 15px rgba(0,0,0,0.1), 0 4px 6px rgba(0,0,0,0.05);
    --transition: 150ms ease;
    --input-height: 38px;
}

* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: var(--bg);
    color: var(--text);
    line-height: 1.5;
    min-height: 100vh;
}

/* Header */
.header {
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    padding: 12px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 100;
}

.header-brand {
    font-size: 18px;
    font-weight: 700;
    color: var(--text);
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
}

.header-brand svg { width: 24px; height: 24px; }

.header-nav {
    display: flex;
    align-items: center;
    gap: 12px;
}

.breadcrumb {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    color: var(--text-muted);
}

.breadcrumb-sep { color: var(--text-light); }
.breadcrumb a { color: var(--text-muted); text-decoration: none; }
.breadcrumb a:hover { color: var(--primary); }

/* Buttons */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    height: var(--input-height);
    padding: 0 16px;
    border-radius: var(--radius);
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    border: 1px solid transparent;
    transition: all var(--transition);
    text-decoration: none;
    white-space: nowrap;
}

.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:hover { background: var(--primary-hover); }

.btn-ghost { background: transparent; color: var(--text-muted); border-color: var(--border); }
.btn-ghost:hover { background: var(--surface-hover); color: var(--text); border-color: var(--border-strong); }

.btn-danger { background: transparent; color: var(--danger); border-color: var(--danger); }
.btn-danger:hover { background: var(--danger); color: #fff; }

.btn-sm { height: 30px; padding: 0 10px; font-size: 12px; }

/* Forms */
input[type="text"], input[type="email"], input[type="password"], textarea, select {
    width: 100%;
    height: var(--input-height);
    padding: 0 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    font-size: 14px;
    font-family: inherit;
    transition: border-color var(--transition);
    background: var(--surface);
    color: var(--text);
}

input:focus, textarea:focus, select:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

textarea { resize: vertical; min-height: 100px; height: auto; padding: 10px 12px; }

label { display: block; font-size: 13px; font-weight: 500; color: var(--text-muted); margin-bottom: 4px; }
.form-group { margin-bottom: 16px; }

/* Inline field (input + button same height, no gap) */
.field-addons {
    display: flex;
    align-items: stretch;
}
.field-addons > input {
    border-top-right-radius: 0;
    border-bottom-right-radius: 0;
    flex: 1;
}
.field-addons > .btn {
    border-top-left-radius: 0;
    border-bottom-left-radius: 0;
    border-left: none;
}
.field-addons > input[type="color"] {
    width: 42px;
    padding: 4px;
    cursor: pointer;
    border-radius: 0;
    border-left: none;
}

/* Field row (label above, items side by side) */
.field-row {
    display: flex;
    gap: 8px;
    align-items: flex-end;
}
.field-row .field-grow { flex: 1; }

/* Projects list */
.projects-view {
    max-width: 800px;
    margin: 60px auto;
    padding: 0 24px;
}

.projects-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 32px;
}

.projects-header h1 { font-size: 28px; font-weight: 700; }

.project-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 20px;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    transition: all var(--transition);
}

.project-card:hover { border-color: var(--primary); box-shadow: var(--shadow); }
.project-card-info h3 { font-size: 16px; font-weight: 600; margin-bottom: 2px; }
.project-card-info span { font-size: 13px; color: var(--text-muted); }

/* Board */
.board {
    display: flex;
    gap: 16px;
    padding: 24px;
    overflow-x: auto;
    min-height: calc(100vh - 60px);
    align-items: flex-start;
}

.column {
    flex: 0 0 300px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    display: flex;
    flex-direction: column;
    max-height: calc(100vh - 100px);
}

.column-header {
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.column-header h3 {
    font-size: 14px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.column-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
.column-count { font-size: 12px; color: var(--text-light); font-weight: 400; }

.column-cards { flex: 1; overflow-y: auto; padding: 8px; min-height: 60px; }

.column-footer { padding: 8px; border-top: 1px solid var(--border); }

.add-card-btn {
    width: 100%;
    padding: 8px;
    border: 1px dashed var(--border);
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--text-muted);
    font-size: 13px;
    cursor: pointer;
    transition: all var(--transition);
}
.add-card-btn:hover { border-color: var(--primary); color: var(--primary); background: var(--primary-light); }

/* Cards */
.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 12px;
    margin-bottom: 8px;
    cursor: pointer;
    transition: all var(--transition);
}

.cards-sortable .card { cursor: grab; }

.card:hover { box-shadow: var(--shadow-md); border-color: var(--border-strong); }
.card.sortable-ghost { opacity: 0.4; background: var(--primary-light); }

.card-title { font-size: 14px; font-weight: 500; margin-bottom: 6px; }
.card-tags { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 6px; }

.tag {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 500;
    color: #fff;
}
.tag-delete {
    cursor: pointer;
    opacity: 0.8;
    font-size: 13px;
    line-height: 1;
}
.tag-delete:hover { opacity: 1; }

.card-meta { display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--text-light); }

/* Add column */
.add-column {
    flex: 0 0 300px;
    border: 2px dashed var(--border);
    border-radius: var(--radius);
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 120px;
    cursor: pointer;
    color: var(--text-muted);
    font-size: 14px;
    transition: all var(--transition);
}
.add-column:hover { border-color: var(--primary); color: var(--primary); background: var(--primary-light); }

/* Modal */
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding-top: 80px;
    opacity: 0;
    visibility: hidden;
    transition: all var(--transition);
}
.modal-overlay.active { opacity: 1; visibility: visible; }

.modal {
    background: var(--surface);
    border-radius: var(--radius);
    box-shadow: var(--shadow-lg);
    width: 100%;
    max-width: 600px;
    max-height: 80vh;
    overflow-y: auto;
    transform: translateY(-10px);
    transition: transform var(--transition);
}
.modal-overlay.active .modal { transform: translateY(0); }

.modal-header {
    padding: 20px 24px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.modal-header h2 { font-size: 18px; font-weight: 600; }

.modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: var(--text-muted);
    padding: 4px;
    line-height: 1;
}
.modal-close:hover { color: var(--text); }

.modal-body { padding: 24px; }
.modal-footer {
    padding: 16px 24px;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}

/* Card detail */
.card-detail-section { margin-bottom: 24px; }
.card-detail-section h4 {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
}

.markdown-body { font-size: 14px; line-height: 1.6; }
.markdown-body p { margin-bottom: 8px; }
.markdown-body code { background: var(--surface-hover); padding: 2px 6px; border-radius: var(--radius-sm); font-size: 13px; }
.markdown-body pre { background: var(--surface-hover); padding: 12px; border-radius: var(--radius); overflow-x: auto; margin-bottom: 8px; }
.markdown-body pre code { background: none; padding: 0; }
.markdown-body img { max-width: 100%; border-radius: var(--radius); }

.comment { padding: 12px; border: 1px solid var(--border); border-radius: var(--radius); margin-bottom: 8px; }
.comment-header { display: flex; justify-content: space-between; margin-bottom: 4px; font-size: 12px; }
.comment-author { font-weight: 600; }
.comment-date { color: var(--text-light); }
.comment-body { font-size: 14px; }

.attachment-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap: 8px; }
.attachment-item { position: relative; border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; aspect-ratio: 1; }
.attachment-item img { width: 100%; height: 100%; object-fit: cover; }
.attachment-delete {
    position: absolute; top: 4px; right: 4px;
    background: rgba(0,0,0,0.6); color: #fff; border: none; border-radius: 50%;
    width: 20px; height: 20px; font-size: 12px; cursor: pointer;
    display: none; align-items: center; justify-content: center;
}
.attachment-item:hover .attachment-delete { display: flex; }

.drop-zone {
    border: 2px dashed var(--border);
    border-radius: var(--radius);
    padding: 20px;
    text-align: center;
    color: var(--text-muted);
    font-size: 13px;
    transition: all var(--transition);
    cursor: pointer;
}
.drop-zone:hover, .drop-zone-active { border-color: var(--primary); background: var(--primary-light); color: var(--primary); }

/* Lightbox */
.lightbox {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.85);
    z-index: 2000;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    animation: fadeIn 150ms ease;
}
.lightbox img { max-width: 90vw; max-height: 90vh; border-radius: var(--radius); box-shadow: 0 0 40px rgba(0,0,0,0.5); }
.lightbox-close { position: absolute; top: 20px; right: 20px; color: #fff; font-size: 32px; cursor: pointer; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

/* Guest banner */
.guest-banner {
    background: #fef3c7;
    border-bottom: 1px solid #f59e0b;
    padding: 8px 24px;
    font-size: 13px;
    color: #92400e;
    text-align: center;
}

/* Status badge */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: var(--radius);
    font-size: 13px;
    font-weight: 500;
}
.status-badge.is-success { background: #d1fae5; color: #065f46; }

/* Badge */
.badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    border-radius: 10px;
    background: var(--danger);
    color: #fff;
    font-size: 11px;
    font-weight: 600;
}
.badge-muted { background: var(--text-light); }

/* Dropdown menu */
.dropdown {
    position: relative;
    display: inline-block;
}

.dropdown-toggle {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 34px;
    padding: 0 12px;
    border-radius: var(--radius);
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    border: 1px solid var(--border);
    background: var(--surface);
    color: var(--text);
    transition: all var(--transition);
}
.dropdown-toggle:hover { background: var(--surface-hover); border-color: var(--border-strong); }

.dropdown-menu {
    position: absolute;
    top: 100%;
    right: 0;
    margin-top: 4px;
    min-width: 180px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-lg);
    z-index: 200;
    display: none;
    padding: 4px;
}
.dropdown-menu.open { display: block; }

.dropdown-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    font-size: 13px;
    color: var(--text);
    cursor: pointer;
    border-radius: var(--radius-sm);
    transition: background var(--transition);
    border: none;
    background: none;
    width: 100%;
    text-align: left;
}
.dropdown-item:hover { background: var(--surface-hover); }
.dropdown-item svg { width: 16px; height: 16px; stroke: currentColor; fill: none; }

.dropdown-divider {
    height: 1px;
    background: var(--border);
    margin: 4px 0;
}

.dropdown-item--danger { color: var(--danger); }
.dropdown-item--danger:hover { background: #fef2f2; }

/* Footer */
.app-footer {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    text-align: center;
    padding: 8px 24px;
    font-size: 12px;
    color: var(--text-light);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.app-footer a { color: var(--text-light); }
.app-footer a:hover { color: var(--primary); }
.app-footer p+p { margin-top: 8px; }

/* Search */
.search-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}
.search-wrapper input {
    width: 260px;
    height: 34px;
    padding: 0 72px 0 32px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    font-size: 13px;
    background: var(--surface);
    color: var(--text);
    transition: border-color var(--transition), width var(--transition);
}
.search-wrapper input:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    width: 320px;
}
.search-wrapper .search-icon {
    position: absolute;
    left: 10px;
    pointer-events: none;
    color: var(--text-light);
    display: flex;
}
.search-wrapper .search-count {
    position: absolute;
    right: 28px;
    font-size: 11px;
    color: var(--text-muted);
    background: var(--surface-hover);
    padding: 2px 7px;
    border-radius: 10px;
    white-space: nowrap;
    pointer-events: none;
    display: none;
}
.search-wrapper .search-clear {
    position: absolute;
    right: 8px;
    background: none;
    border: none;
    cursor: pointer;
    color: var(--text-light);
    font-size: 16px;
    line-height: 1;
    padding: 2px;
    display: none;
}
.search-wrapper .search-clear:hover { color: var(--text); }

/* Utilities */
.hidden { display: none !important; }
.mt-2 { margin-top: 8px; }
.mt-3 { margin-top: 12px; }
.mt-4 { margin-top: 16px; }
.mb-2 { margin-bottom: 8px; }
.mb-3 { margin-bottom: 12px; }

@media (max-width: 768px) {
    .board { padding: 12px; gap: 12px; }
    .column { flex: 0 0 260px; }
    .projects-view { margin: 24px auto; }
}
    </style>
</head>
<body>

<!-- Header -->
<div class="header">
    <div class="header-brand">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" onclick="if(!App.isGuest) App.showProjects()" style="cursor:pointer">
            <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
            <rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
        </svg>
        <span id="header-brand-name" onclick="if(!App.isGuest) App.showProjects()" style="cursor:pointer"><?= APP_NAME ?></span>
        <span class="breadcrumb" id="breadcrumb"></span>
    </div>
    <div class="header-nav">
        <div id="navbar-actions" style="display:flex;gap:8px;align-items:center"></div>
    </div>
</div>

<!-- Guest banner -->
<div class="guest-banner hidden" id="guest-banner">You are viewing this board as a guest.</div>

<!-- Setup View (first-time) -->
<div id="view-setup" class="projects-view hidden">
    <div style="max-width:400px;margin:60px auto">
        <h1 style="margin-bottom:8px">Welcome to <?= APP_NAME ?></h1>
        <p style="color:var(--text-muted);margin-bottom:24px">Create your admin account to get started.</p>
        <form onsubmit="event.preventDefault();App.setup()">
            <div class="form-group">
                <label>Name</label>
                <input type="text" id="setup-name" placeholder="Your name" autocomplete="name">
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="text" id="setup-email" placeholder="you@example.com" autocomplete="email">
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" id="setup-password" placeholder="Min. 6 characters" autocomplete="new-password">
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%">Create Account</button>
        </form>
        <p id="setup-error" class="hidden" style="color:var(--danger);margin-top:12px;font-size:13px"></p>
    </div>
</div>

<!-- Login View -->
<div id="view-login" class="projects-view hidden">
    <div style="max-width:360px;margin:80px auto;text-align:center">
        <h1 style="margin-bottom:24px">&#128274; <span id="login-brand"><?= APP_NAME ?></span></h1>
        <form onsubmit="event.preventDefault();App.login()">
            <div class="form-group">
                <input type="text" id="login-email" placeholder="Email" autocomplete="email">
            </div>
            <div class="form-group">
                <input type="password" id="login-password" placeholder="Password" autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%">Sign In</button>
        </form>
        <p id="login-error" class="hidden" style="color:var(--danger);margin-top:12px;font-size:13px"></p>
    </div>
</div>

<!-- Unauthorized View -->
<div id="view-unauthorized" class="projects-view hidden">
    <div style="max-width:400px;margin:80px auto;text-align:center">
        <h1 style="margin-bottom:12px">Unauthorized</h1>
        <p style="color:var(--text-muted)">You need a valid guest link to access this board.</p>
    </div>
</div>

<!-- Projects View -->
<div id="view-projects" class="projects-view hidden">
    <div class="projects-header">
        <h1>Projects</h1>
        <button class="btn btn-primary" onclick="App.showNewProjectModal()">+ New Project</button>
    </div>
    <div id="projects-list"></div>
</div>

<!-- Board View -->
<div id="view-board" class="hidden">
    <div class="board" id="board"></div>
</div>

<!-- Modal -->
<div class="modal-overlay" id="modal-overlay" onclick="App.closeModal(event)">
    <div class="modal" onclick="event.stopPropagation()">
        <div class="modal-header">
            <h2 id="modal-title">Modal</h2>
            <button class="modal-close" onclick="App.closeModal()">&times;</button>
        </div>
        <div class="modal-body" id="modal-body"></div>
        <div class="modal-footer" id="modal-footer"></div>
    </div>
</div>

<script>
let CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';
const App = {
    currentProject: null,
    projects: [],
    columns: [],
    cards: [],
    tags: [],
    isGuest: false,
    guestToken: null,
    guestName: '',
    user: null,
    unreadCounts: {},
    appName: '<?= APP_NAME ?>',
    _pendingTagCardId: null,

    init() {
        const params = new URLSearchParams(window.location.search);
        this.guestToken = params.get('guest');

        window.addEventListener('hashchange', () => {
            if (!this._navigating) this.handleRoute();
        });

        // Close dropdown on outside click
        $(document).on('click', e => {
            if (!$(e.target).closest('.dropdown').length) {
                $('.dropdown-menu').removeClass('open');
            }
        });

        if (this.guestToken) {
            this.isGuest = true;
            this.api('guest_project_info', { token: this.guestToken }).done(project => {
                this.guestName = project.guest_name || 'Guest';
                this.currentProject = project;
                this.projects = [project];
                this.api('list_columns', { project_id: project.id }).done(columns => {
                    this.columns = columns;
                    this.loadBoard(project.id);
                });
            }).fail(() => {
                $('#view-unauthorized').removeClass('hidden');
            });
        } else {
            this.api('auth_status').done(status => {
                this.appName = status.app_name || '<?= APP_NAME ?>';
                this.updateBrand();
                if (status.needs_setup) {
                    $('#view-setup').removeClass('hidden');
                    setTimeout(() => $('#setup-name').focus(), 100);
                } else if (status.authenticated) {
                    this.user = status.user;
                    this.handleRoute();
                } else {
                    this._loginRedirect = window.location.hash || '';
                    $('#login-brand').text(this.appName);
                    $('#view-login').removeClass('hidden');
                    const savedEmail = localStorage.getItem('kanban_email') || '';
                    $('#login-email').val(savedEmail);
                    setTimeout(() => $(savedEmail ? '#login-password' : '#login-email').focus(), 100);
                }
            });
        }
    },

    handleRoute() {
        const hash = window.location.hash;
        const cardMatch = hash.match(/^#project\/([a-f0-9]+)\/card\/(\d+)$/);
        const projectMatch = hash.match(/^#project\/([a-f0-9]+)$/);
        if (cardMatch) {
            const projectId = cardMatch[1];
            const cardId = parseInt(cardMatch[2]);
            this.api('list_projects').done(projects => {
                this.projects = projects;
                const project = projects.find(p => p.id == projectId);
                if (project) {
                    this._pendingCardOpen = cardId;
                    this.openProject(projectId);
                } else {
                    this.showProjects();
                }
            });
        } else if (projectMatch) {
            const projectId = projectMatch[1];
            this.api('list_projects').done(projects => {
                this.projects = projects;
                const project = projects.find(p => p.id == projectId);
                if (project) {
                    this.openProject(projectId);
                } else {
                    this.showProjects();
                }
            });
        } else {
            this.showProjects();
        }
    },

    setup() {
        const name = $('#setup-name').val().trim();
        const email = $('#setup-email').val().trim();
        const password = $('#setup-password').val();
        if (!name || !email || !password) {
            $('#setup-error').text('All fields are required.').removeClass('hidden');
            return;
        }
        this.api('auth_setup', { name, email, password }, 'POST').done(res => {
            if (res.csrf_token) CSRF_TOKEN = res.csrf_token;
            $('#view-setup').addClass('hidden');
            this.user = { name, email, role: 'admin' };
            this.handleRoute();
        }).fail((xhr) => {
            const msg = xhr.responseJSON?.error || 'Setup failed';
            $('#setup-error').text(msg).removeClass('hidden');
        });
    },

    login() {
        const email = $('#login-email').val().trim();
        const password = $('#login-password').val();
        if (!email || !password) {
            $('#login-error').text('Enter email and password.').removeClass('hidden');
            return;
        }
        this.api('auth_login', { email, password }, 'POST').done(res => {
            if (res.csrf_token) CSRF_TOKEN = res.csrf_token;
            localStorage.setItem('kanban_email', email);
            $('#view-login').addClass('hidden');
            $('#login-error').addClass('hidden');
            if (this._loginRedirect) {
                window.location.hash = this._loginRedirect;
                this._loginRedirect = '';
            }
            this.api('auth_status').done(status => {
                this.user = status.user;
                this.handleRoute();
            });
        }).fail((xhr) => {
            const msg = xhr.responseJSON?.error || 'Login failed';
            $('#login-error').text(msg).removeClass('hidden');
            $('#login-password').val('').focus();
        });
    },

    logout() {
        this.api('auth_logout', {}, 'POST').done(() => {
            location.reload();
        });
    },

    // API
    api(action, data = null, method = 'GET') {
        let url = `?action=${action}`;
        if (this.guestToken) url += `&guest=${this.guestToken}`;
        if (method === 'GET' && data) {
            Object.keys(data).forEach(k => url += `&${k}=${encodeURIComponent(data[k])}`);
        }
        const headers = {};
        if (method === 'POST') headers['X-CSRF-Token'] = CSRF_TOKEN;
        return $.ajax({
            url,
            method,
            headers,
            data: method === 'POST' ? (data instanceof FormData ? data : JSON.stringify(data)) : undefined,
            contentType: data instanceof FormData ? false : 'application/json',
            processData: false,
            dataType: 'json'
        });
    },

    // PROJECTS
    loadProjects() {
        this.api('list_projects').done(projects => {
            this.projects = projects;
            this.renderProjects();
        });
    },

    renderProjects() {
        const list = $('#projects-list');
        if (!this.projects.length) {
            list.html('<div style="text-align:center;padding:40px;color:var(--text-muted)">No projects yet. Create one to get started.</div>');
            return;
        }
        list.html(this.projects.map(p => {
            const unread = p.unread_comments || 0;
            const badgeText = unread > 9 ? '9+' : unread;
            const badgeHtml = unread ? `<span class="badge badge-muted">${badgeText}</span>` : '';
            const deleteBtn = p.is_owner ? `<button class="btn btn-ghost btn-sm" onclick="event.stopPropagation();App.confirmDeleteProject('${p.id}','${this.escAttr(p.name)}')">Delete</button>` : '';
            const watchIcon = p.is_watching ? '<svg viewBox="0 0 24 24" width="14" height="14" stroke="var(--primary)" fill="none" stroke-width="2" title="Watching"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>' : '';
            return `<div class="project-card" onclick="App.openProject('${p.id}')">
                <div class="project-card-info">
                    <h3>${this.esc(p.name)} ${watchIcon}</h3>
                    <span>Created ${p.created_at}</span>
                </div>
                <div style="display:flex;align-items:center;gap:8px">
                    ${badgeHtml}
                    ${deleteBtn}
                </div>
            </div>`;
        }).join(''));
    },

    showNewProjectModal() {
        this.openModal('New Project', `
            <div class="form-group">
                <label>Project Name</label>
                <input type="text" id="new-project-name" placeholder="My Project">
            </div>
        `, `<button class="btn btn-primary" onclick="App.createProject()">Create</button>`);
        setTimeout(() => $('#new-project-name').focus(), 100);
    },

    createProject() {
        const name = $('#new-project-name').val().trim();
        if (!name) return;
        this.api('create_project', { name }, 'POST').done(() => { this.closeModal(); this.loadProjects(); });
    },

    openProject(id) {
        this._navigating = true;
        history.replaceState(null, '', `#project/${id}`);
        this._navigating = false;
        this.api('list_columns', { project_id: id }).done(columns => {
            const project = this.projects.find(p => p.id == id);
            if (project) {
                this.currentProject = project;
            } else {
                this.currentProject = { id, name: 'Project', is_owner: false };
            }
            this.columns = columns;
            this.loadBoard(id);
        });
    },

    confirmDeleteProject(id, name) {
        this.confirmAction(`Delete project "${this.esc(name)}" and all its data? This cannot be undone.`, () => {
            this.api('delete_project', { id }, 'POST').done(() => this.loadProjects());
        });
    },

    // BOARD
    loadBoard(projectId) {
        this.api('list_cards', { project_id: projectId }).done(cards => {
            this.cards = cards;
            $.when(
                this.api('list_tags', { project_id: projectId }),
                this.api('unread_counts', { project_id: projectId })
            ).done((tagsRes, unreadRes) => {
                this.tags = tagsRes[0] || tagsRes;
                this.unreadCounts = unreadRes[0] || unreadRes || {};
                this.renderBoard();
            });
        });
    },

    renderUserMenu() {
        if (this.isGuest || !this.user) return '';
        const isAdmin = this.user.role === 'admin';
        return `
            <div class="dropdown">
                <button class="dropdown-toggle" onclick="$(this).next().toggleClass('open')">
                    <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    ${this.esc(this.user.name)}
                    <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <div class="dropdown-menu">
                    <button class="dropdown-item" onclick="App.showAccount();$('.dropdown-menu').removeClass('open')">
                        <svg viewBox="0 0 24 24" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        Account
                    </button>
                    ${isAdmin ? `<button class="dropdown-item" onclick="App.showTeam();$('.dropdown-menu').removeClass('open')">
                        <svg viewBox="0 0 24 24" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        Team
                    </button>` : ''}
                    ${isAdmin ? `<button class="dropdown-item" onclick="App.showAppSettings();$('.dropdown-menu').removeClass('open')">
                        <svg viewBox="0 0 24 24" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                        Settings
                    </button>` : ''}
                    <button class="dropdown-item" onclick="App.showHelp();$('.dropdown-menu').removeClass('open')">
                        <svg viewBox="0 0 24 24" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        Help
                    </button>
                    <div class="dropdown-divider"></div>
                    <button class="dropdown-item dropdown-item--danger" onclick="App.logout()">
                        <svg viewBox="0 0 24 24" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                        Sign Out
                    </button>
                </div>
            </div>
        `;
    },

    renderBoard() {
        $('#view-projects').addClass('hidden');
        $('#view-board').removeClass('hidden');

        if (!this.isGuest) {
            const isOwner = this.currentProject.is_owner;
            $('#breadcrumb').html(`
                <span class="breadcrumb-sep">&gt;</span>
                <strong>${this.esc(this.currentProject.name)}</strong>
            `);
            let actions = '';
            actions += `<div class="search-wrapper">
                <span class="search-icon"><svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></span>
                <input type="text" id="board-search" placeholder="Search cards..." oninput="App.searchCards(this.value)">
                <span class="search-count" id="search-count"></span>
                <button class="search-clear" id="search-clear" onclick="App.clearSearch()">&times;</button>
            </div>`;
            actions += `<button class="btn btn-ghost btn-sm" id="project-watch-btn" onclick="App._projectWatching ? App.unwatchProject() : App.watchProject()">...</button>`;
            if (isOwner) {
                actions += `<button class="btn btn-ghost btn-sm" onclick="App.showSettings()"><svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg> Project Settings</button>`;
            }
            actions += this.renderUserMenu();
            $('#navbar-actions').html(actions);
            this.loadProjectWatchState();
        } else {
            $('#breadcrumb').html(`<span class="breadcrumb-sep">&gt;</span> <strong>${this.esc(this.currentProject.name)}</strong>`);
            let guestActions = `<div class="search-wrapper">
                <span class="search-icon"><svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></span>
                <input type="text" id="board-search" placeholder="Search cards..." oninput="App.searchCards(this.value)">
                <span class="search-count" id="search-count"></span>
                <button class="search-clear" id="search-clear" onclick="App.clearSearch()">&times;</button>
            </div>`;
            if (this.currentProject.guest_has_email) {
                guestActions += `<button class="btn btn-ghost btn-sm" id="guest-watch-btn" onclick="App._guestWatching ? App.guestUnwatchProject() : App.guestWatchProject()">...</button>`;
                $('#navbar-actions').html(guestActions);
                this.loadGuestWatchState();
            } else {
                $('#navbar-actions').html(guestActions);
            }
            $('#guest-banner').text(`Hey ${this.guestName}! You are viewing this board as a guest.`).removeClass('hidden');
        }

        const board = $('#board').empty();
        this.columns.forEach((col, idx) => {
            const isFixed = idx === 0;
            const colCards = this.cards.filter(c => c.column_id == col.id).sort((a, b) => a.position - b.position);
            board.append(`
                <div class="column${isFixed ? ' column-fixed' : ''}" data-id="${col.id}">
                    <div class="column-header"${isFixed ? '' : ' style="cursor:grab"'}>
                        <h3>
                            <span class="column-dot" style="background:${col.color}"></span>
                            <span class="column-name"${!this.isGuest && !isFixed ? ` ondblclick="App.editColumnName(${col.id}, this)"` : ''}>${this.esc(col.name)}</span>
                            <span class="column-count">${colCards.length}</span>
                        </h3>
                        ${!this.isGuest && !isFixed ? `<button class="modal-close" onclick="App.deleteColumn(${col.id})" title="Delete column" style="font-size:18px">&times;</button>` : ''}
                    </div>
                    <div class="column-cards${!this.isGuest || (isFixed && this.currentProject.guest_can_sort_cards) ? ' cards-sortable' : ''}" data-column-id="${col.id}">
                        ${colCards.map(card => this.renderCard(card)).join('')}
                    </div>
                    ${!this.isGuest || (isFixed && this.currentProject.guest_can_create_cards) ? `<div class="column-footer"><button class="add-card-btn" onclick="App.showAddCard(${col.id})">+ Add card</button></div>` : ''}
                </div>
            `);
        });

        if (!this.isGuest) {
            board.append('<div class="add-column" onclick="App.showAddColumn()">+ Add Column</div>');
        }
        this.initSortable();

        if (this._pendingCardOpen) {
            const cardId = this._pendingCardOpen;
            this._pendingCardOpen = null;
            setTimeout(() => this.openCard(cardId), 100);
        }
    },

    renderCard(card) {
        const tagsHtml = (card.tags || []).map(t =>
            `<span class="tag" style="background:${t.color}">${this.esc(t.name)}</span>`
        ).join('');
        const imageExts = ['jpg','jpeg','png','gif','webp','svg'];
        const coverAtt = (card.attachments || []).find(a => imageExts.includes(a.filename.split('.').pop().toLowerCase()));
        const coverHtml = coverAtt ? `<img src="?file=${encodeURIComponent(coverAtt.path)}" style="width:100%;height:120px;object-fit:cover;border-radius:var(--radius-sm);margin-bottom:8px">` : '';
        const hasAtt = (card.attachments || []).length > 0;
        const commentCount = card.comment_count || 0;
        const unread = this.unreadCounts[card.id] || 0;
        const unreadBadge = unread ? `<span class="badge" title="${unread} new">${unread > 9 ? '9+' : unread}</span>` : '';
        return `
            <div class="card" data-id="${card.id}" onclick="App.openCard(${card.id})">
                ${coverHtml}
                ${tagsHtml ? `<div class="card-tags">${tagsHtml}</div>` : ''}
                <div class="card-title" style="display:flex;justify-content:space-between;align-items:flex-start">
                    <span>${this.esc(card.title)} ${unreadBadge}</span>
                    <span style="font-size:11px;color:var(--text-light);white-space:nowrap;margin-left:8px;display:inline-flex;align-items:center;gap:3px">${card.is_watching ? '<svg viewBox="0 0 24 24" width="12" height="12" stroke="var(--primary)" fill="none" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>' : ''}#${card.id}</span>
                </div>
                <div class="card-meta">
                    ${card.description ? '<span title="Has description">&#9776;</span>' : ''}
                    ${hasAtt ? '<span title="Has attachments">&#128206;</span>' : ''}
                    ${commentCount ? `<span title="${commentCount} comment${commentCount > 1 ? 's' : ''}">&#128172; ${commentCount}</span>` : ''}
                </div>
            </div>
        `;
    },

    initSortable() {
        if (this.isGuest) {
            if (this.currentProject.guest_can_sort_cards) {
                const firstCol = document.querySelector('.column-fixed .column-cards');
                if (firstCol) {
                    new Sortable(firstCol, {
                        animation: 150, ghostClass: 'sortable-ghost',
                        onEnd: (evt) => {
                            const cardId = parseInt(evt.item.dataset.id);
                            const newColumnId = parseInt(evt.to.dataset.columnId);
                            this.api('move_card', { id: cardId, column_id: newColumnId, position: evt.newIndex }, 'POST');
                        }
                    });
                }
            }
            return;
        }
        const boardEl = document.getElementById('board');
        if (boardEl) {
            new Sortable(boardEl, {
                animation: 150, handle: '.column-header', draggable: '.column:not(.column-fixed)', ghostClass: 'sortable-ghost',
                onEnd: () => {
                    const order = [...boardEl.querySelectorAll('.column')].map(el => el.dataset.id);
                    this.api('reorder_columns', { project_id: this.currentProject.id, order }, 'POST');
                }
            });
        }
        document.querySelectorAll('.column-cards').forEach(el => {
            new Sortable(el, {
                group: 'cards', animation: 150, ghostClass: 'sortable-ghost',
                onEnd: (evt) => {
                    const cardId = parseInt(evt.item.dataset.id);
                    const newColumnId = parseInt(evt.to.dataset.columnId);
                    this.api('move_card', { id: cardId, column_id: newColumnId, position: evt.newIndex }, 'POST');
                }
            });
        });
    },

    // COLUMNS
    showAddColumn() {
        this.openModal('New Column', `
            <div class="form-group"><label>Column Name</label>
            <input type="text" id="new-column-name" placeholder="e.g. Review"></div>
        `, `<button class="btn btn-primary" onclick="App.createColumn()">Add</button>`);
        setTimeout(() => $('#new-column-name').focus(), 100);
    },

    createColumn() {
        const name = $('#new-column-name').val().trim();
        if (!name) return;
        this.api('create_column', { project_id: this.currentProject.id, name }, 'POST').done(() => { this.closeModal(); this.refreshBoard(); });
    },

    editColumnName(colId, el) {
        if (this.isGuest) return;
        const current = el.textContent;
        const input = document.createElement('input');
        input.type = 'text'; input.value = current;
        input.style.cssText = 'font-size:14px;font-weight:600;width:120px;padding:2px 6px;height:26px;border:1px solid var(--border);border-radius:4px;';
        el.replaceWith(input); input.focus(); input.select();
        const save = () => {
            const val = input.value.trim() || current;
            const span = document.createElement('span');
            span.className = 'column-name';
            span.textContent = val;
            span.setAttribute('ondblclick', `App.editColumnName(${colId}, this)`);
            input.replaceWith(span);
            if (val !== current) this.api('update_column', { id: colId, name: val }, 'POST');
        };
        input.addEventListener('blur', save);
        input.addEventListener('keydown', e => { if (e.key === 'Enter') input.blur(); });
    },

    deleteColumn(id) {
        this.confirmAction('Delete this column and all its cards?', () => {
            this.api('delete_column', { id }, 'POST').done(() => this.refreshBoard());
        });
    },

    // CARDS
    showAddCard(columnId) {
        this.openModal('New Card', `
            <div class="form-group"><label>Title</label><input type="text" id="new-card-title" placeholder="Card title"></div>
            <div class="form-group"><label>Description (Markdown)</label><textarea id="new-card-desc" placeholder="Optional description..."></textarea></div>
        `, `<button class="btn btn-primary" onclick="App.createCard(${columnId})">Add Card</button>`);
        setTimeout(() => $('#new-card-title').focus(), 100);
    },

    createCard(columnId) {
        const title = $('#new-card-title').val().trim();
        if (!title) return;
        this.api('create_card', { column_id: columnId, title, description: $('#new-card-desc').val() }, 'POST').done(() => { this.closeModal(); this.refreshBoard(); });
    },

    openCard(cardId) {
        const card = this.cards.find(c => c.id == cardId);
        if (!card) return;
        this._openCardId = cardId;
        if (this.currentProject) {
            this._navigating = true;
            history.replaceState(null, '', `#project/${this.currentProject.id}/card/${cardId}`);
            this._navigating = false;
        }
        const descHtml = card.description ? DOMPurify.sanitize(marked.parse(card.description)) : '<em style="color:var(--text-light)">No description</em>';
        const assignedTags = (card.tags || []);
        const tagsHtml = assignedTags.map(t =>
            `<span class="tag" style="background:${t.color};cursor:pointer" onclick="App.toggleTag(${cardId},${t.id})">${this.esc(t.name)}</span>`
        ).join('');

        const imageExts = ['jpg','jpeg','png','gif','webp','svg'];
        const attHtml = (card.attachments || []).map(a => {
            const isImage = imageExts.includes(a.filename.split('.').pop().toLowerCase());
            if (isImage) {
                return `<div class="attachment-item" onclick="event.stopPropagation();App.previewImage('?file=${encodeURIComponent(a.path)}')">
                    <img src="?file=${encodeURIComponent(a.path)}" alt="${this.esc(a.filename)}">
                    ${!this.isGuest ? `<button class="attachment-delete" onclick="event.stopPropagation();App.deleteAttachment(${a.id},${cardId})">&times;</button>` : ''}
                </div>`;
            }
            return `<a href="?file=${encodeURIComponent(a.path)}" download="${this.esc(a.filename)}" class="attachment-item" style="display:flex;align-items:center;justify-content:center;background:var(--surface-hover);aspect-ratio:auto;padding:8px;flex-direction:column;gap:4px;text-decoration:none" onclick="event.stopPropagation()">
                <span style="font-size:20px">&#128196;</span>
                <span style="font-size:10px;color:var(--text-muted);word-break:break-all;text-align:center">${this.esc(a.filename)}</span>
                ${!this.isGuest ? `<button class="attachment-delete" onclick="event.stopPropagation();event.preventDefault();App.deleteAttachment(${a.id},${cardId})">&times;</button>` : ''}
            </a>`;
        }).join('');

        const canWatch = !this.isGuest || (this.isGuest && this.currentProject.guest_has_email);
        const watchAction = this.isGuest ? `App._cardWatching ? App.guestUnwatchCard(${cardId}) : App.guestWatchCard(${cardId})` : `App._cardWatching ? App.unwatchCard(${cardId}) : App.watchCard(${cardId})`;
        const watchBtn = canWatch ? `<button class="btn btn-ghost" style="height:30px;padding:0 12px;font-size:13px;display:inline-flex;align-items:center;gap:4px" id="watch-btn-${cardId}" onclick="${watchAction}">...</button>` : '';

        let body = `
            <div class="card-detail-section" style="display:flex;align-items:center;justify-content:space-between">
                <div style="font-size:12px;color:var(--text-muted);display:grid;grid-template-columns:1fr 1fr;gap:4px 16px;flex:1">
                    <span><strong>ID:</strong> #${card.id}</span>
                    ${card.author_name ? `<span><strong>Author:</strong> ${this.esc(card.author_name)}</span>` : '<span></span>'}
                    <span><strong>Created:</strong> ${card.created_at}</span>
                    ${card.updated_at !== card.created_at ? `<span><strong>Updated:</strong> ${card.updated_at}</span>` : '<span></span>'}
                </div>
                ${watchBtn}
            </div>
            <div class="card-detail-section" style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                ${tagsHtml}
                ${!this.isGuest ? `<span class="tag" style="background:var(--border);cursor:pointer;font-size:13px" onclick="App.showTagPicker(${cardId})">+</span>` : ''}
            </div>
            <div class="card-detail-section">
                <h4>Description ${!this.isGuest ? `<button class="btn btn-ghost btn-sm" style="height:22px;padding:0 8px;font-size:11px;margin-left:8px" onclick="App.editCardDescription(${cardId})">Edit</button>` : ''}</h4>
                <div class="markdown-body">${descHtml}</div>
            </div>
            <div class="card-detail-section">
                <h4>Attachments</h4>
                <div class="attachment-grid mb-2">${attHtml || '<em style="color:var(--text-light)">None</em>'}</div>
                ${!this.isGuest ? `<div class="drop-zone mt-2" id="drop-zone" data-card-id="${cardId}">
                    <p>Drop files here or <label for="file-upload" style="color:var(--primary);cursor:pointer;text-decoration:underline">browse</label></p>
                    <input type="file" id="file-upload" multiple onchange="App.uploadFiles(${cardId}, this.files)" style="display:none">
                </div>` : ''}
            </div>
            <div class="card-detail-section">
                <h4>Comments</h4>
                <div style="display:flex;gap:8px;align-items:center;margin-bottom:12px">
                    <textarea id="new-comment" placeholder="Write a comment (Markdown)" style="min-height:38px;flex:1"></textarea>
                    <button class="btn btn-primary btn-sm" onclick="App.addComment(${cardId})">Comment</button>
                </div>
                <div id="card-comments">Loading...</div>
            </div>
        `;
        const footer = !this.isGuest ? `
            <button class="btn btn-danger" onclick="App.deleteCard(${cardId})">Delete Card</button>
        ` : '';
        const titleHtml = this.esc(card.title) + (!this.isGuest ? ` <button class="btn btn-ghost btn-sm" style="height:22px;padding:0 8px;font-size:11px;vertical-align:middle" onclick="App.editCardTitle(${cardId})">Edit</button>` : '');
        this.openModal(titleHtml, body, footer);
        this.loadComments(cardId);
        this.api('mark_comments_seen', { card_id: cardId }, 'POST').done(() => {
            delete this.unreadCounts[cardId];
            $(`.card[data-id="${cardId}"] .badge`).remove();
        });
        if (!this.isGuest) {
            this.initDropZone();
        }
        if (canWatch) {
            const watching = !!card.is_watching;
            const count = card.watcher_count || 0;
            this._cardWatching = watching;
            $(`#watch-btn-${cardId}`).html(watching
                ? `<svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3" fill="var(--surface)"/></svg> Watching (${count})`
                : `<svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg> Watch (${count})`);
        }
    },

    editCardTitle(cardId) {
        const card = this.cards.find(c => c.id == cardId);
        this.openModal('Edit Title', `
            <div class="form-group"><label>Title</label><input type="text" id="edit-card-title" value="${this.esc(card.title)}"></div>
        `, `
            <button class="btn btn-ghost" onclick="App.closeModal();setTimeout(()=>App.openCard(${cardId}),100)">Cancel</button>
            <button class="btn btn-primary" onclick="App.saveCardTitle(${cardId})">Save</button>
        `);
        setTimeout(() => $('#edit-card-title').focus().select(), 50);
    },

    saveCardTitle(cardId) {
        const title = $('#edit-card-title').val().trim();
        if (!title) return;
        this.api('update_card', { id: cardId, title }, 'POST').done(() => { this.refreshBoard(); setTimeout(() => this.openCard(cardId), 200); });
    },

    editCardDescription(cardId) {
        const card = this.cards.find(c => c.id == cardId);
        this.openModal('Edit Description', `
            <div class="form-group"><textarea id="edit-card-desc" rows="10">${this.esc(card.description || '')}</textarea></div>
        `, `
            <button class="btn btn-ghost" onclick="App.closeModal();setTimeout(()=>App.openCard(${cardId}),100)">Cancel</button>
            <button class="btn btn-primary" onclick="App.saveCardDescription(${cardId})">Save</button>
        `);
    },

    saveCardDescription(cardId) {
        this.api('update_card', { id: cardId, description: $('#edit-card-desc').val() }, 'POST').done(() => { this.refreshBoard(); setTimeout(() => this.openCard(cardId), 200); });
    },

    deleteCard(cardId) {
        this.confirmAction('Delete this card? This cannot be undone.', () => {
            this.api('delete_card', { id: cardId }, 'POST').done(() => { this.closeModal(); this.refreshBoard(); });
        });
    },

    toggleTag(cardId, tagId) {
        this.api('toggle_card_tag', { card_id: cardId, tag_id: tagId }, 'POST').done(() => {
            this.refreshBoard();
            setTimeout(() => this.openCard(cardId), 200);
        });
    },

    showTagPicker(cardId) {
        const card = this.cards.find(c => c.id == cardId);
        const assignedIds = (card.tags || []).map(t => t.id);
        const available = this.tags.filter(t => !assignedIds.includes(t.id));
        const assigned = this.tags.filter(t => assignedIds.includes(t.id));

        const assignedHtml = assigned.map(t =>
            `<span class="tag" style="background:${t.color};cursor:pointer" onclick="App.toggleTag(${cardId},${t.id})">${this.esc(t.name)} &times;</span>`
        ).join(' ');
        const availableHtml = available.map(t =>
            `<span class="tag" style="background:${t.color};cursor:pointer" onclick="App.toggleTag(${cardId},${t.id})">${this.esc(t.name)}</span>`
        ).join(' ');

        const colors = ['#ef4444','#f97316','#eab308','#22c55e','#3b82f6','#8b5cf6','#ec4899'];
        this.openModal('Tags', `
            ${assigned.length ? `<div class="card-detail-section"><h4>Assigned</h4><div class="card-tags">${assignedHtml}</div></div>` : ''}
            ${available.length ? `<div class="card-detail-section"><h4>Available</h4><div class="card-tags">${availableHtml}</div></div>` : ''}
            <div class="card-detail-section"><h4>Create New Tag</h4>
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                    <input type="text" id="new-tag-name" placeholder="Tag name" style="flex:1;min-width:120px">
                    ${colors.map(c => `<span style="width:22px;height:22px;border-radius:50%;background:${c};cursor:pointer;border:2px solid transparent;display:inline-block;flex-shrink:0" onclick="$(this).parent().find('span').css('border-color','transparent');$(this).css('border-color','var(--text)');$('#new-tag-color').val('${c}')"></span>`).join('')}
                    <input type="hidden" id="new-tag-color" value="${colors[0]}">
                    <button class="btn btn-primary btn-sm" style="flex-shrink:0" onclick="App.createTag()">Add</button>
                </div>
            </div>
        `, '');
        this._pendingTagCardId = cardId;
    },

    showNewTag(cardId = null) {
        const colors = ['#ef4444','#f97316','#eab308','#22c55e','#3b82f6','#8b5cf6','#ec4899'];
        this._pendingTagCardId = cardId;
        this.openModal('New Tag', `
            <div class="form-group"><label>Name</label><input type="text" id="new-tag-name" placeholder="e.g. Bug"></div>
            <div class="form-group"><label>Color</label>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
                ${colors.map(c => `<span style="width:28px;height:28px;border-radius:50%;background:${c};cursor:pointer;border:3px solid transparent;display:inline-block" onclick="$(this).parent().find('span').css('border-color','transparent');$(this).css('border-color','var(--text)');$('#new-tag-color').val('${c}')"></span>`).join('')}
            </div>
            <input type="hidden" id="new-tag-color" value="${colors[0]}">
            </div>
        `, `<button class="btn btn-primary" onclick="App.createTag()">Create</button>`);
        setTimeout(() => $('#new-tag-name').focus(), 100);
    },

    createTag() {
        const name = $('#new-tag-name').val().trim();
        const color = $('#new-tag-color').val();
        if (!name) return;
        const payload = { project_id: this.currentProject.id, name, color };
        if (this._pendingTagCardId) payload.card_id = this._pendingTagCardId;
        this.api('create_tag', payload, 'POST').done(() => {
            const cardId = this._pendingTagCardId;
            this._pendingTagCardId = null;
            this.closeModal(); this.refreshBoard();
            if (cardId) setTimeout(() => this.openCard(cardId), 200);
        });
    },

    // COMMENTS
    loadComments(cardId) {
        this.api('list_comments', { card_id: cardId }).done(comments => {
            const currentAuthor = this.isGuest ? this.guestName : (this.user?.name || 'Unknown');
            const html = comments.length ? comments.map(c => {
                const isOwn = c.author_name === currentAuthor;
                return `<div class="comment">
                    <div class="comment-header">
                        <span class="comment-author">${this.esc(c.author_name)}</span>
                        <span style="display:flex;align-items:center;gap:8px">
                            ${isOwn ? `<button class="btn btn-ghost btn-sm" style="height:20px;padding:0 6px;font-size:11px" onclick="App.editComment(${c.id},${cardId})">Edit</button>
                            <button class="btn btn-ghost btn-sm" style="height:20px;padding:0 6px;font-size:11px;color:var(--danger)" onclick="App.deleteComment(${c.id},${cardId})">Delete</button>` : ''}
                            <span class="comment-date">${c.created_at}</span>
                        </span>
                    </div>
                    <div class="comment-body markdown-body">${DOMPurify.sanitize(marked.parse(c.content))}</div>
                </div>`;
            }).join('') : '<p style="color:var(--text-light);font-size:13px">No comments yet.</p>';
            $('#card-comments').html(html);
        });
    },

    addComment(cardId) {
        const content = $('#new-comment').val().trim();
        if (!content) return;
        this.api('create_comment', { card_id: cardId, content }, 'POST').done(() => {
            $('#new-comment').val('');
            this.loadComments(cardId);
        });
    },

    editComment(commentId, cardId) {
        const commentEl = $(`#card-comments .comment`).filter(function() {
            return $(this).find('[onclick*="editComment(' + commentId + '"]').length > 0;
        });
        const currentContent = commentEl.find('.comment-body').text().trim();
        this.promptInput('Edit Comment', 'Comment (Markdown)', currentContent, (newContent) => {
            this.api('update_comment', { id: commentId, content: newContent }, 'POST').done(() => this.openCard(cardId));
        });
    },

    deleteComment(commentId, cardId) {
        this.confirmAction('Delete this comment?', () => {
            this.api('delete_comment', { id: commentId }, 'POST').done(() => this.openCard(cardId));
        });
    },

    // ATTACHMENTS
    uploadFiles(cardId, files) {
        if (!files.length) return;
        const uploads = Array.from(files).map(file => {
            const fd = new FormData(); fd.append('file', file); fd.append('card_id', cardId);
            return $.ajax({ url: '?action=upload_attachment', method: 'POST', data: fd, contentType: false, processData: false, dataType: 'json' });
        });
        $.when(...uploads).always(() => { this.refreshBoard(); setTimeout(() => this.openCard(cardId), 200); });
    },

    initDropZone() {
        $(document).off('dragover.dz drop.dz dragleave.dz');
        $(document).on('dragover.dz', '#drop-zone', e => { e.preventDefault(); e.stopPropagation(); $(e.currentTarget).addClass('drop-zone-active'); });
        $(document).on('dragleave.dz', '#drop-zone', e => { $(e.currentTarget).removeClass('drop-zone-active'); });
        $(document).on('drop.dz', '#drop-zone', e => {
            e.preventDefault(); e.stopPropagation(); $(e.currentTarget).removeClass('drop-zone-active');
            const cardId = parseInt(e.currentTarget.dataset.cardId);
            if (e.originalEvent.dataTransfer.files.length) this.uploadFiles(cardId, e.originalEvent.dataTransfer.files);
        });
    },

    deleteAttachment(id, cardId) {
        this.api('delete_attachment', { id }, 'POST').done(() => { this.refreshBoard(); setTimeout(() => this.openCard(cardId), 200); });
    },

    // ACCOUNT
    showAccount() {
        $.when(
            this.api('auth_status'),
            this.api('get_notification_settings')
        ).done((statusRes, notifRes) => {
            const status = statusRes[0] || statusRes;
            const notif = notifRes[0] || notifRes;
            this.user = status.user;
            this.openModal('My Account', `
                <form onsubmit="event.preventDefault();App.saveAccount()">
                    <div class="form-group"><label>Name</label>
                        <input type="text" id="account-name" value="${this.esc(this.user.name)}">
                    </div>
                    <div class="form-group"><label>Email</label>
                        <input type="text" id="account-email" value="${this.esc(this.user.email)}">
                    </div>
                    <div class="card-detail-section mt-4"><h4>Change Password</h4>
                        <div class="form-group"><label>Current Password</label>
                            <input type="password" id="account-current-pw" placeholder="Required to change password" autocomplete="current-password">
                        </div>
                        <div class="form-group"><label>New Password</label>
                            <input type="password" id="account-new-pw" placeholder="Leave blank to keep current" autocomplete="new-password">
                        </div>
                    </div>
                    <div class="card-detail-section mt-4"><h4>Notification Preferences</h4>
                        <div class="form-group"><label>Notification Email</label>
                            <input type="email" id="notif-email" value="${this.esc(notif.email || '')}" placeholder="Leave blank to use account email">
                        </div>
                        <div class="form-group"><label>Delivery</label>
                            <select id="notif-delivery" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:var(--radius)">
                                <option value="immediate" ${notif.delivery === 'immediate' ? 'selected' : ''}>Immediate</option>
                                <option value="daily" ${notif.delivery === 'daily' ? 'selected' : ''}>Daily summary</option>
                            </select>
                        </div>
                    </div>
                    <p id="account-error" class="hidden" style="color:var(--danger);font-size:13px;margin-bottom:12px"></p>
                    <p id="account-success" class="hidden" style="color:var(--success);font-size:13px;margin-bottom:12px">Saved!</p>
                </form>
            `, `<button class="btn btn-primary" onclick="App.saveAccount()">Save</button>`);
        });
    },

    saveAccount() {
        const name = $('#account-name').val().trim();
        const email = $('#account-email').val().trim();
        const password = $('#account-new-pw').val();
        const currentPassword = $('#account-current-pw').val();

        if (!name || !email) {
            $('#account-error').text('Name and email are required.').removeClass('hidden');
            return;
        }
        if (password && !currentPassword) {
            $('#account-error').text('Current password is required to set a new one.').removeClass('hidden');
            return;
        }

        const payload = { name, email };
        if (password) {
            payload.password = password;
            payload.current_password = currentPassword;
        }

        const notifEmail = $('#notif-email').val().trim();
        const notifDelivery = $('#notif-delivery').val();

        this.api('account_update', payload, 'POST').done(() => {
            this.user.name = name;
            this.user.email = email;
            this.api('update_notification_settings', { email: notifEmail, delivery: notifDelivery }, 'POST');
            $('#account-error').addClass('hidden');
            $('#account-success').removeClass('hidden');
            setTimeout(() => $('#account-success').addClass('hidden'), 2000);
        }).fail(xhr => {
            const msg = xhr.responseJSON?.error || 'Failed to update';
            $('#account-error').text(msg).removeClass('hidden');
        });
    },

    // TEAM (admin only)
    showTeam() {
        this.api('team_list').done(users => {
            const rows = users.map(u => {
                const isSelf = u.id === this.user.id;
                const roleBadge = u.role === 'admin'
                    ? '<span style="background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:500">Admin</span>'
                    : '<span style="background:#f1f5f9;color:#475569;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:500">Member</span>';
                const actions = isSelf ? '<span style="font-size:12px;color:var(--text-light)">You</span>' : `
                    <button class="btn btn-ghost btn-sm" onclick="App.teamChangeRole(${u.id},'${u.role}')">${u.role === 'admin' ? 'Demote' : 'Promote'}</button>
                    <button class="btn btn-ghost btn-sm" onclick="App.teamResetPw(${u.id})">Reset Pwd</button>
                    <button class="btn btn-danger btn-sm" onclick="App.teamRemove(${u.id},'${this.escAttr(u.name)}')">Remove</button>
                `;
                return `<div style="display:flex;align-items:center;justify-content:space-between;padding:10px;border:1px solid var(--border);border-radius:var(--radius);margin-bottom:6px">
                    <div>
                        <strong style="font-size:13px">${this.esc(u.name)}</strong> ${roleBadge}
                        <div style="font-size:12px;color:var(--text-light)">${this.esc(u.email)}</div>
                    </div>
                    <div style="display:flex;gap:4px;align-items:center">${actions}</div>
                </div>`;
            }).join('');

            this.openModal('Team', `
                <div class="card-detail-section">${rows}</div>
                <div class="card-detail-section"><h4>Add Team Member</h4>
                    <div class="form-group"><label>Name</label><input type="text" id="team-add-name" placeholder="Name"></div>
                    <div class="form-group"><label>Email</label><input type="text" id="team-add-email" placeholder="email@example.com"></div>
                    <div class="form-group"><label>Password</label><input type="password" id="team-add-pw" placeholder="Initial password (min 6 chars)" autocomplete="new-password"></div>
                    <div class="form-group"><label>Role</label>
                        <select id="team-add-role"><option value="member">Member</option><option value="admin">Admin</option></select>
                    </div>
                    <p id="team-error" class="hidden" style="color:var(--danger);font-size:13px;margin-bottom:12px"></p>
                    <button class="btn btn-primary" onclick="App.teamAdd()">Add Member</button>
                </div>
            `, '');
        });
    },

    teamAdd() {
        const name = $('#team-add-name').val().trim();
        const email = $('#team-add-email').val().trim();
        const password = $('#team-add-pw').val();
        const role = $('#team-add-role').val();
        if (!name || !email || !password) {
            $('#team-error').text('All fields are required.').removeClass('hidden');
            return;
        }
        this.api('team_add', { name, email, password, role }, 'POST').done(() => {
            this.showTeam();
        }).fail(xhr => {
            const msg = xhr.responseJSON?.error || 'Failed to add member';
            $('#team-error').text(msg).removeClass('hidden');
        });
    },

    teamRemove(id, name) {
        this.confirmAction(`Remove "${this.esc(name)}" from the team? Their projects will be transferred to you.`, () => {
            this.api('team_remove', { id }, 'POST').done(() => this.showTeam());
        });
    },

    teamChangeRole(id, currentRole) {
        const newRole = currentRole === 'admin' ? 'member' : 'admin';
        this.api('team_update_role', { id, role: newRole }, 'POST').done(() => this.showTeam());
    },

    teamResetPw(id) {
        this.openModal('Reset Password', `
            <div class="form-group"><label>New Password</label>
                <input type="password" id="team-reset-pw" placeholder="New password (min 6 chars)" autocomplete="new-password">
            </div>
            <p id="team-reset-error" class="hidden" style="color:var(--danger);font-size:13px;margin-bottom:12px"></p>
        `, `
            <button class="btn btn-ghost" onclick="App.showTeam()">Cancel</button>
            <button class="btn btn-primary" onclick="App.teamDoResetPw(${id})">Reset</button>
        `);
        setTimeout(() => $('#team-reset-pw').focus(), 100);
    },

    teamDoResetPw(id) {
        const pw = $('#team-reset-pw').val();
        if (!pw || pw.length < 6) {
            $('#team-reset-error').text('Password must be at least 6 characters.').removeClass('hidden');
            return;
        }
        this.api('team_reset_password', { id, password: pw }, 'POST').done(() => this.showTeam())
            .fail(xhr => { $('#team-reset-error').text(xhr.responseJSON?.error || 'Failed').removeClass('hidden'); });
    },

    // APP SETTINGS (admin only)
    showAppSettings() {
        $.when(
            this.api('auth_status'),
            this.api('get_smtp_settings')
        ).done((statusRes, smtpRes) => {
            const status = statusRes[0] || statusRes;
            const smtp = smtpRes[0] || smtpRes;
            this.appName = status.app_name || '<?= APP_NAME ?>';
            this.updateBrand();

            this.openModal('App Settings', `
                <div class="card-detail-section"><h4>App Name</h4>
                    <div class="field-addons">
                        <input type="text" id="app-name" placeholder="App name" value="${this.esc(this.appName)}">
                        <button class="btn btn-primary" onclick="App.setAppName()">Save</button>
                    </div>
                </div>
                <div class="card-detail-section"><h4>SMTP (Email Notifications)</h4>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                        <div class="form-group"><label>Host</label>
                            <input type="text" id="smtp-host" value="${this.esc(smtp.smtp_host || '')}" placeholder="smtp.example.com">
                        </div>
                        <div class="form-group"><label>Port</label>
                            <input type="text" id="smtp-port" value="${smtp.smtp_port || 587}" placeholder="587">
                        </div>
                        <div class="form-group"><label>Username</label>
                            <input type="text" id="smtp-user" value="${this.esc(smtp.smtp_user || '')}" placeholder="user@example.com">
                        </div>
                        <div class="form-group"><label>Password</label>
                            <input type="password" id="smtp-pass" placeholder="${smtp.smtp_pass_set ? '(unchanged)' : 'SMTP password'}">
                        </div>
                        <div class="form-group"><label>From Email</label>
                            <input type="email" id="smtp-from-email" value="${this.esc(smtp.smtp_from_email || '')}" placeholder="noreply@example.com">
                        </div>
                        <div class="form-group"><label>From Name</label>
                            <input type="text" id="smtp-from-name" value="${this.esc(smtp.smtp_from_name || '')}" placeholder="Tasssks">
                        </div>
                    </div>
                    <div class="form-group"><label>Encryption</label>
                        <select id="smtp-encryption" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:var(--radius)">
                            <option value="tls" ${smtp.smtp_encryption === 'tls' ? 'selected' : ''}>TLS (STARTTLS)</option>
                            <option value="ssl" ${smtp.smtp_encryption === 'ssl' ? 'selected' : ''}>SSL</option>
                            <option value="none" ${smtp.smtp_encryption === 'none' ? 'selected' : ''}>None</option>
                        </select>
                    </div>
                    <div style="display:flex;gap:8px;margin-top:8px">
                        <button class="btn btn-primary" onclick="App.saveSmtp()">Save SMTP</button>
                        <button class="btn btn-ghost" onclick="App.testSmtp()">Send Test Email</button>
                    </div>
                    <p id="smtp-msg" class="hidden" style="font-size:12px;margin-top:8px"></p>
                </div>
                <div class="card-detail-section"><h4>Cron Token</h4>
                    <p style="font-size:12px;color:var(--text-light);margin-bottom:8px">Use this token to authenticate the daily digest cron job without a session.</p>
                    <div class="field-addons">
                        <input type="text" id="cron-token" value="${this.esc(smtp.cron_token || '')}" readonly style="font-family:monospace;font-size:12px">
                        <button class="btn btn-ghost" onclick="App.generateCronToken()">Generate</button>
                    </div>
                    <p style="font-size:11px;color:var(--text-light);margin-top:6px">Use with: <code>?action=send_notifications</code> and <code>?action=send_digest</code></p>
                    ${smtp.last_digest_at ? `<p style="font-size:11px;color:var(--text-light);margin-top:6px">Last digest run: <strong>${smtp.last_digest_at}</strong></p>` : '<p style="font-size:11px;color:var(--text-light);margin-top:6px">Digest has not run yet.</p>'}
                </div>
            `, '');
        });
    },

    saveSmtp() {
        const payload = {
            smtp_host: $('#smtp-host').val().trim(),
            smtp_port: parseInt($('#smtp-port').val()) || 587,
            smtp_user: $('#smtp-user').val().trim(),
            smtp_from_email: $('#smtp-from-email').val().trim(),
            smtp_from_name: $('#smtp-from-name').val().trim(),
            smtp_encryption: $('#smtp-encryption').val(),
        };
        const pass = $('#smtp-pass').val();
        if (pass) payload.smtp_pass = pass;
        this.api('update_smtp_settings', payload, 'POST').done(() => {
            $('#smtp-msg').text('SMTP settings saved.').css('color', 'var(--success)').removeClass('hidden');
            setTimeout(() => $('#smtp-msg').addClass('hidden'), 3000);
        }).fail(xhr => {
            $('#smtp-msg').text(xhr.responseJSON?.error || 'Failed').css('color', 'var(--danger)').removeClass('hidden');
        });
    },

    testSmtp() {
        const to = prompt('Send test email to:');
        if (!to) return;
        this.api('test_smtp', { to }, 'POST').done(() => {
            $('#smtp-msg').text('Test email sent!').css('color', 'var(--success)').removeClass('hidden');
        }).fail(xhr => {
            $('#smtp-msg').text(xhr.responseJSON?.error || 'Failed to send').css('color', 'var(--danger)').removeClass('hidden');
        });
    },

    generateCronToken() {
        this.api('generate_cron_token', {}, 'POST').done(res => {
            $('#cron-token').val(res.token);
        });
    },

    setAppName() {
        const name = $('#app-name').val().trim();
        if (!name) return;
        this.api('auth_set_app_name', { name }, 'POST').done(() => {
            this.appName = name;
            this.updateBrand();
            this.showAppSettings();
        });
    },

    // PROJECT SETTINGS (owner/admin only)
    showSettings() {
        if (!this.currentProject) return;
        const pid = this.currentProject.id;
        $.when(
            this.api('list_guests', { project_id: pid }),
            this.api('list_webhooks', { project_id: pid })
        ).done((guestsRes, webhooksRes) => {
            const guests = guestsRes[0] || guestsRes;
            const webhooks = webhooksRes[0] || webhooksRes;
            const guestRows = guests.map(g => {
                const link = `${location.origin}${location.pathname}?guest=${g.token}`;
                return `<div style="padding:10px;border:1px solid var(--border);border-radius:var(--radius);margin-bottom:6px">
                    <div style="display:flex;align-items:center;justify-content:space-between">
                        <div>
                            <strong style="font-size:13px">${this.esc(g.name)}</strong>
                            <div style="font-size:11px;color:var(--text-light)">${g.can_comment ? 'Can comment' : 'View only'}${g.email ? ' · ' + this.esc(g.email) : ' · no email'}</div>
                        </div>
                        <div style="display:flex;gap:4px">
                            <button class="btn btn-ghost btn-sm" onclick="App.editGuest(${g.id},'${this.esc(g.name).replace(/'/g,"\\'")}','${this.esc(g.email || '').replace(/'/g,"\\'")}')">Edit</button>
                            <button class="btn btn-ghost btn-sm" onclick="navigator.clipboard.writeText('${link}');this.textContent='Copied!'">Copy Link</button>
                            <button class="btn btn-danger btn-sm" onclick="App.deleteGuest(${g.id})">Remove</button>
                        </div>
                    </div>
                </div>`;
            }).join('') || '<p style="color:var(--text-light);font-size:13px">No guests yet.</p>';

            const webhookRows = webhooks.map(w => `<div style="display:flex;align-items:center;justify-content:space-between;padding:10px;border:1px solid var(--border);border-radius:var(--radius);margin-bottom:6px">
                <div style="overflow:hidden">
                    <strong style="font-size:12px;text-transform:uppercase;color:var(--text-light)">${this.esc(w.type)}</strong>
                    <div style="font-size:12px;color:var(--text);word-break:break-all">${this.esc(w.url)}</div>
                </div>
                <div style="display:flex;gap:4px;flex-shrink:0">
                    <button class="btn btn-ghost btn-sm" onclick="App.toggleWebhook(${w.id})">${w.enabled ? 'Disable' : 'Enable'}</button>
                    <button class="btn btn-danger btn-sm" onclick="App.deleteWebhook(${w.id})">Remove</button>
                </div>
            </div>`).join('') || '<p style="color:var(--text-light);font-size:13px">No webhooks configured.</p>';

            const guestCreate = this.currentProject.guest_can_create_cards ? 'checked' : '';
            const guestSort = this.currentProject.guest_can_sort_cards ? 'checked' : '';
            this.openModal('Project Settings', `
                <div class="card-detail-section"><h4>Project Name</h4>
                    <div class="field-addons">
                        <input type="text" id="project-name" value="${this.esc(this.currentProject.name)}">
                        <button class="btn btn-primary" onclick="App.updateProjectName()">Save</button>
                    </div>
                </div>
                <div class="card-detail-section"><h4>Webhooks <a href="#" onclick="event.preventDefault();App.showWebhookPayloads()" style="font-size:11px;font-weight:400;margin-left:8px">View payload format</a></h4>
                    <p style="font-size:12px;color:var(--text-light);margin-bottom:8px">Receive notifications via Slack, Telegram, or any HTTP endpoint.</p>
                    ${webhookRows}
                    <div style="display:flex;gap:6px;align-items:center;margin-top:8px;flex-wrap:wrap">
                        <select id="webhook-type" style="padding:6px 8px;border:1px solid var(--border);border-radius:var(--radius);font-size:13px">
                            <option value="generic">Generic</option>
                            <option value="slack">Slack</option>
                            <option value="telegram">Telegram</option>
                        </select>
                        <input type="text" id="webhook-url" placeholder="https://hooks.slack.com/..." style="flex:1;min-width:200px">
                        <button class="btn btn-primary btn-sm" onclick="App.createWebhook()">Add</button>
                    </div>
                </div>
                <div class="card-detail-section"><h4>Guest Permissions</h4>
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:10px;font-size:14px;font-weight:400;color:var(--text)">
                        <input type="checkbox" id="guest-create-cards" ${guestCreate} onchange="App.updateGuestPerm('guest_can_create_cards', this.checked)">
                        Allow guests to create cards in the first column
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;font-weight:400;color:var(--text)">
                        <input type="checkbox" id="guest-sort-cards" ${guestSort} onchange="App.updateGuestPerm('guest_can_sort_cards', this.checked)">
                        Allow guests to sort cards in the first column
                    </label>
                </div>
                <div class="card-detail-section"><h4>Guest Access</h4>
                    ${guestRows}
                    <div style="display:flex;gap:6px;align-items:center;margin-top:12px;flex-wrap:wrap">
                        <input type="text" id="new-guest-name" placeholder="Guest name" style="flex:1;min-width:120px">
                        <input type="email" id="new-guest-email" placeholder="Email (optional)" style="flex:1;min-width:150px">
                        <button class="btn btn-primary btn-sm" onclick="App.createGuest()">Add Guest</button>
                    </div>
                </div>
                <div class="card-detail-section"><h4>Tags</h4>
                <div class="card-tags mb-3">${this.tags.map(t => `
                    <span class="tag" style="background:${t.color}">${this.esc(t.name)}
                        <span class="tag-delete" onclick="App.deleteTag(${t.id})">&times;</span>
                    </span>
                `).join('') || '<em style="color:var(--text-light)">No tags</em>'}</div>
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                    <input type="text" id="settings-tag-name" placeholder="Tag name" style="flex:1;min-width:120px">
                    ${['#ef4444','#f97316','#eab308','#22c55e','#3b82f6','#8b5cf6','#ec4899'].map(c => `<span style="width:22px;height:22px;border-radius:50%;background:${c};cursor:pointer;border:2px solid transparent;display:inline-block;flex-shrink:0" onclick="$(this).parent().find('span').css('border-color','transparent');$(this).css('border-color','var(--text)');$('#settings-tag-color').val('${c}')"></span>`).join('')}
                    <input type="hidden" id="settings-tag-color" value="#3b82f6">
                    <button class="btn btn-primary btn-sm" style="flex-shrink:0" onclick="App.createTagFromSettings()">Add</button>
                </div>
                </div>
            `, '');
        });
    },

    updateProjectName() {
        const name = $('#project-name').val().trim();
        if (!name) return;
        this.api('update_project', { id: this.currentProject.id, name }, 'POST').done(() => {
            this.currentProject.name = name;
            this.renderBoard();
            this.showSettings();
        });
    },

    updateGuestPerm(key, value) {
        const payload = { id: this.currentProject.id };
        payload[key] = value ? 1 : 0;
        this.api('update_project', payload, 'POST').done(() => {
            this.currentProject[key] = value ? 1 : 0;
        });
    },

    createGuest() {
        const name = $('#new-guest-name').val().trim();
        const email = $('#new-guest-email').val().trim();
        if (!name) return;
        this.api('create_guest', { project_id: this.currentProject.id, name, can_comment: 1, email }, 'POST').done(() => this.showSettings());
    },

    editGuest(id, name, email) {
        this.openModal('Edit Guest', `
            <div class="form-group"><label>Name</label>
                <input type="text" id="edit-guest-name" value="${this.esc(name)}">
            </div>
            <div class="form-group"><label>Email (optional)</label>
                <input type="email" id="edit-guest-email" value="${this.esc(email)}" placeholder="guest@example.com">
            </div>
        `, `<button class="btn btn-ghost" onclick="App.showSettings()">Cancel</button> <button class="btn btn-primary" onclick="App.saveGuest(${id})">Save</button>`);
    },
    saveGuest(id) {
        const name = $('#edit-guest-name').val().trim();
        const email = $('#edit-guest-email').val().trim();
        if (!name) return;
        this.api('update_guest', { id, name, email }, 'POST').done(() => this.showSettings());
    },
    deleteGuest(id) { this.api('delete_guest', { id }, 'POST').done(() => this.showSettings()); },

    createWebhook() {
        const url = $('#webhook-url').val().trim();
        const type = $('#webhook-type').val();
        if (!url) return;
        this.api('create_webhook', { project_id: this.currentProject.id, url, type }, 'POST').done(() => this.showSettings());
    },
    deleteWebhook(id) { this.api('delete_webhook', { id }, 'POST').done(() => this.showSettings()); },
    toggleWebhook(id) { this.api('toggle_webhook', { id }, 'POST').done(() => this.showSettings()); },

    showWebhookPayloads() {
        this.openModal('Webhook Payload Format', `
            <div style="margin-bottom:12px"><button class="btn btn-ghost btn-sm" onclick="App.showSettings()">&larr; Back to Settings</button></div>
            <div class="card-detail-section">
                <h4>Generic</h4>
                <p style="font-size:12px;color:var(--text-light);margin-bottom:6px">POST with <code>Content-Type: application/json</code></p>
                <pre style="background:var(--surface-hover);padding:12px;border-radius:var(--radius);font-size:12px;overflow-x:auto;white-space:pre-wrap">{
  "event": "new_card",
  "project": "My Project",
  "actor": "John Doe",
  "payload": {
    "card_id": 42,
    "title": "Fix login bug"
  },
  "timestamp": "2026-08-13T10:30:00+00:00"
}</pre>
                <p style="font-size:11px;color:var(--text-light);margin-top:6px">Events: <code>new_card</code>, <code>new_comment</code>, <code>card_updated</code>, <code>new_project</code>, <code>password_changed</code></p>
            </div>
            <div class="card-detail-section">
                <h4>Slack</h4>
                <p style="font-size:12px;color:var(--text-light);margin-bottom:6px">POST to your Slack Incoming Webhook URL</p>
                <pre style="background:var(--surface-hover);padding:12px;border-radius:var(--radius);font-size:12px;overflow-x:auto;white-space:pre-wrap">{
  "text": "John Doe created card \\"Fix login bug\\" in My Project"
}</pre>
            </div>
            <div class="card-detail-section">
                <h4>Telegram</h4>
                <p style="font-size:12px;color:var(--text-light);margin-bottom:6px">POST to <code>https://api.telegram.org/bot&lt;TOKEN&gt;/sendMessage</code></p>
                <pre style="background:var(--surface-hover);padding:12px;border-radius:var(--radius);font-size:12px;overflow-x:auto;white-space:pre-wrap">{
  "text": "John Doe created card \\"Fix login bug\\" in My Project",
  "parse_mode": "HTML"
}</pre>
                <p style="font-size:11px;color:var(--text-light);margin-top:6px">Set the webhook URL to include <code>chat_id</code> as a query param, e.g.:<br><code>https://api.telegram.org/bot&lt;TOKEN&gt;/sendMessage?chat_id=&lt;CHAT_ID&gt;</code></p>
            </div>
        `, '');
    },

    showHelp() {
        const baseUrl = location.origin + location.pathname;
        this.openModal('Help', `
            <div class="card-detail-section">
                <h4>Scheduled Tasks (Cron)</h4>
                <p style="font-size:13px;color:var(--text);margin-bottom:8px">Set up two cron jobs to handle email notifications:</p>
                <pre style="background:var(--surface-hover);padding:12px;border-radius:var(--radius);font-size:12px;overflow-x:auto;white-space:pre-wrap"># Immediate notifications (every minute)
* * * * * curl -s "${baseUrl}?action=send_notifications&cron_token=YOUR_TOKEN" > /dev/null

# Daily digest (once a day at 8 AM)
0 8 * * * curl -s "${baseUrl}?action=send_digest&cron_token=YOUR_TOKEN" > /dev/null</pre>
                <p style="font-size:12px;color:var(--text-light);margin-top:8px">Generate a cron token in <strong>App Settings &gt; Cron Token</strong>. The token authenticates the request without a browser session.</p>
                <p style="font-size:12px;color:var(--text-light);margin-top:8px"><strong>send_notifications</strong> — sends individual emails to users with "Immediate" delivery preference.<br><strong>send_digest</strong> — sends a batched summary to users with "Daily summary" preference and to guest watchers.</p>
            </div>
            <div class="card-detail-section">
                <h4>Webhooks</h4>
                <p style="font-size:13px;color:var(--text);margin-bottom:8px">Configure webhooks in <strong>Project Settings</strong> to receive real-time notifications for:</p>
                <ul style="font-size:13px;color:var(--text);padding-left:20px;margin:0">
                    <li>New cards created</li>
                    <li>Comments posted</li>
                    <li>Cards updated</li>
                    <li>New projects created</li>
                </ul>
                <p style="font-size:12px;color:var(--text-light);margin-top:8px">Supported integrations: Slack (Incoming Webhooks), Telegram (Bot API), or any HTTP endpoint that accepts JSON POST requests.</p>
            </div>
            <div class="card-detail-section">
                <h4>Watching</h4>
                <p style="font-size:13px;color:var(--text)">Click <strong>Watch</strong> on a card to receive notifications when someone comments or updates it. You automatically watch cards you create or comment on.</p>
            </div>
            <div class="card-detail-section">
                <h4>Notification Preferences</h4>
                <p style="font-size:13px;color:var(--text)">Go to <strong>Account</strong> to configure your notification email and choose between immediate delivery or a daily summary.</p>
            </div>
            <div class="card-detail-section">
                <h4>Version</h4>
                <p style="font-size:13px;color:var(--text-light)">#<?= APP_VERSION ?></p>
            </div>
        `, '');
    },

    watchCard(cardId) {
        this.api('watch', { project_id: this.currentProject.id, card_id: cardId }, 'POST').done(() => {
            this.refreshBoard(); setTimeout(() => this.openCard(cardId), 200);
        });
    },
    unwatchCard(cardId) {
        this.api('unwatch', { project_id: this.currentProject.id, card_id: cardId }, 'POST').done(() => {
            this.refreshBoard(); setTimeout(() => this.openCard(cardId), 200);
        });
    },
    loadProjectWatchState() {
        if (!this.currentProject) return;
        this.api('list_watchers', { project_id: this.currentProject.id }).done(watchers => {
            const watching = watchers.some(w => w.user_id == this.user.id);
            const count = watchers.length;
            this._projectWatching = watching;
            $('#project-watch-btn').html(watching
                ? `<svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3" fill="var(--surface)"/></svg> Watching (${count})`
                : `<svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg> Watch (${count})`);
        });
    },
    watchProject() {
        this.api('watch', { project_id: this.currentProject.id }, 'POST').done(() => {
            this._projectWatching = true;
            this.loadProjectWatchState();
        });
    },
    unwatchProject() {
        this.api('unwatch', { project_id: this.currentProject.id }, 'POST').done(() => {
            this._projectWatching = false;
            this.loadProjectWatchState();
        });
    },

    loadGuestWatchState() {
        if (!this.currentProject) return;
        $.when(
            this.api('guest_watch_status', { project_id: this.currentProject.id }),
            this.api('list_watchers', { project_id: this.currentProject.id })
        ).done((statusRes, watchersRes) => {
            const status = statusRes[0] || statusRes;
            const watchers = watchersRes[0] || watchersRes;
            this._guestWatching = status.watching;
            this._guestWatchCount = watchers.length;
            this.updateGuestWatchBtn();
        });
    },
    updateGuestWatchBtn() {
        const count = this._guestWatchCount || 0;
        $('#guest-watch-btn').html(this._guestWatching
            ? `<svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3" fill="var(--surface)"/></svg> Watching (${count})`
            : `<svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg> Watch (${count})`);
    },
    guestWatchProject() {
        this.api('guest_watch', { project_id: this.currentProject.id }, 'POST').done(() => {
            this._guestWatching = true;
            this.loadGuestWatchState();
        });
    },
    guestUnwatchProject() {
        this.api('guest_unwatch', { project_id: this.currentProject.id }, 'POST').done(() => {
            this._guestWatching = false;
            this.loadGuestWatchState();
        });
    },
    guestWatchCard(cardId) {
        this.api('guest_watch', { project_id: this.currentProject.id, card_id: cardId }, 'POST').done(() => {
            this.refreshBoard(); setTimeout(() => this.openCard(cardId), 200);
        });
    },
    guestUnwatchCard(cardId) {
        this.api('guest_unwatch', { project_id: this.currentProject.id, card_id: cardId }, 'POST').done(() => {
            this.refreshBoard(); setTimeout(() => this.openCard(cardId), 200);
        });
    },

    deleteTag(id) {
        this.api('delete_tag', { id }, 'POST').done(() => {
            this.api('list_tags', { project_id: this.currentProject.id }).done(tags => {
                this.tags = tags;
                this.renderBoard();
                this.showSettings();
            });
        });
    },

    createTagFromSettings() {
        const name = $('#settings-tag-name').val().trim();
        const color = $('#settings-tag-color').val();
        if (!name) return;
        this.api('create_tag', { project_id: this.currentProject.id, name, color }, 'POST').done(() => {
            this.api('list_tags', { project_id: this.currentProject.id }).done(tags => {
                this.tags = tags;
                this.renderBoard();
                this.showSettings();
            });
        });
    },

    // NAVIGATION
    showProjects() {
        if (this.isGuest) return;
        this.currentProject = null;
        history.replaceState(null, '', window.location.pathname + window.location.search);
        $('#view-login, #view-board, #view-setup').addClass('hidden');
        $('#view-projects').removeClass('hidden');
        $('#breadcrumb').empty();
        $('#navbar-actions').html(this.renderUserMenu());
        $('#guest-banner').addClass('hidden');
        this.loadProjects();
    },

    refreshBoard() {
        if (this.currentProject) {
            this.api('list_columns', { project_id: this.currentProject.id }).done(cols => { this.columns = cols; this.loadBoard(this.currentProject.id); });
        }
    },

    // MODAL
    openModal(title, body, footer) {
        $('#modal-title').html(title);
        $('#modal-body').html(body);
        $('#modal-footer').html(footer || '');
        $('#modal-overlay').addClass('active');
    },

    closeModal(event) {
        if (event && event.target !== event.currentTarget) return;
        $('#modal-overlay').removeClass('active');
        if (this._openCardId && this.currentProject) {
            this._navigating = true;
            history.replaceState(null, '', `#project/${this.currentProject.id}`);
            this._navigating = false;
        }
        this._openCardId = null;
    },

    // LIGHTBOX
    previewImage(src) {
        const overlay = document.createElement('div');
        overlay.className = 'lightbox';
        overlay.innerHTML = `<img src="${src}"><span class="lightbox-close">&times;</span>`;
        overlay.onclick = () => overlay.remove();
        document.body.appendChild(overlay);
    },

    // INLINE DIALOGS
    confirmAction(message, onConfirm) {
        this.openModal('Confirm', `<p style="font-size:14px">${message}</p>`, `
            <button class="btn btn-ghost" onclick="App.closeModal()">Cancel</button>
            <button class="btn btn-danger" id="confirm-action-btn">Confirm</button>
        `);
        setTimeout(() => $('#confirm-action-btn').off('click').on('click', () => { App.closeModal(); onConfirm(); }), 50);
    },

    promptInput(title, label, currentValue, onSave) {
        this.openModal(title, `
            <div class="form-group"><label>${label}</label>
            <textarea id="prompt-input" style="min-height:80px">${this.esc(currentValue || '')}</textarea></div>
        `, `
            <button class="btn btn-ghost" onclick="App.closeModal()">Cancel</button>
            <button class="btn btn-primary" id="prompt-save-btn">Save</button>
        `);
        setTimeout(() => {
            $('#prompt-input').focus();
            $('#prompt-save-btn').off('click').on('click', () => {
                const val = $('#prompt-input').val().trim();
                if (val) { App.closeModal(); onSave(val); }
            });
        }, 50);
    },

    // SEARCH
    searchCards(query) {
        const q = query.toLowerCase().trim();
        const $clear = $('#search-clear');
        const $count = $('#search-count');

        if (!q) {
            $('.card[data-id]').show();
            $clear.hide();
            $count.hide();
            this.updateColumnCounts();
            return;
        }

        $clear.show();
        let matched = 0;
        this.cards.forEach(card => {
            const inTitle = (card.title || '').toLowerCase().includes(q);
            const inDesc = (card.description || '').toLowerCase().includes(q);
            const $el = $(`.card[data-id="${card.id}"]`);
            if (inTitle || inDesc) {
                $el.show();
                matched++;
            } else {
                $el.hide();
            }
        });

        $count.text(matched === 1 ? '1 card' : `${matched} cards`).show();
        this.updateColumnCounts();
    },

    clearSearch() {
        $('#board-search').val('').focus();
        this.searchCards('');
    },

    updateColumnCounts() {
        $('.column').each(function() {
            const visible = $(this).find('.card[data-id]:visible').length;
            $(this).find('.column-count').text(visible);
        });
    },

    // UTILS
    updateBrand() {
        $('#header-brand-name').text(this.appName);
        document.title = this.appName;
    },

    esc(str) { if (!str) return ''; const d = document.createElement('div'); d.textContent = str; return d.innerHTML; },
    escAttr(str) { return this.esc(str).replace(/'/g, '&#39;'); }
};

$(document).on('keydown', e => { if (e.key === 'Escape') App.closeModal(); });
$(document).on('keydown', '#login-email, #login-password', e => { if (e.key === 'Enter') App.login(); });
$(document).on('keydown', '#setup-name, #setup-email, #setup-password', e => { if (e.key === 'Enter') App.setup(); });
$(App.init.bind(App));
</script>
<footer class="app-footer">
  <p>Designed, built, and backed by <a href="https://x.com/rogeriotaques" target="_blank" rel="noopener noreferrer">Rogerio Taques</a>, the guy behind <a href="https://abtz.co" target="_blank" rel="noopener noreferrer">Abtz Labs</a>.</p>
  <p>#<?= APP_VERSION ?> &copy; Abtz Labs.</p>
</footer>
</body>
</html>
