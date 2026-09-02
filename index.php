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
        if (preg_match('/\.(sqlite|sqlite3|db|sql|env|htaccess|htpasswd|bak)$/i', $uri)
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
define('DB_FILE', getenv('TASSSKS_DB_FILE') ?: __DIR__ . '/tasssks.sqlite');
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB
define('IMAGE_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']);
define('GITHUB_RAW_URL', 'https://raw.githubusercontent.com/Abtz-Labs/tasssks/main/index.php');

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
            week_start_day INTEGER DEFAULT 1,
            cycle_reset_day INTEGER DEFAULT 1,
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
    $version = (int) $db->query('PRAGMA user_version')->fetchColumn();

    // Existing DB created before version-based migrations — stamp and skip
    if ($version === 0 && $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch()) {
        // Run any column additions that may or may not have been applied
        $cols = array_column($db->query("PRAGMA table_info(comments)")->fetchAll(), 'name');
        if (!in_array('user_id', $cols)) {
            $db->exec("ALTER TABLE comments ADD COLUMN user_id INTEGER REFERENCES users(id) ON DELETE SET NULL");
        }
        $cols = array_column($db->query("PRAGMA table_info(cards)")->fetchAll(), 'name');
        if (!in_array('author_name', $cols)) {
            $db->exec("ALTER TABLE cards ADD COLUMN author_name TEXT DEFAULT ''");
        }
        $cols = array_column($db->query("PRAGMA table_info(projects)")->fetchAll(), 'name');
        if (!in_array('guest_can_create_cards', $cols)) {
            $db->exec("ALTER TABLE projects ADD COLUMN guest_can_create_cards INTEGER DEFAULT 0");
        }
        if (!in_array('guest_can_sort_cards', $cols)) {
            $db->exec("ALTER TABLE projects ADD COLUMN guest_can_sort_cards INTEGER DEFAULT 0");
        }
        $cols = array_column($db->query("PRAGMA table_info(guests)")->fetchAll(), 'name');
        if (!in_array('email', $cols)) {
            $db->exec("ALTER TABLE guests ADD COLUMN email TEXT DEFAULT ''");
        }
        // Migrate last_seen schema if needed
        $lsCols = array_column($db->query("PRAGMA table_info(last_seen)")->fetchAll(), 'name');
        if (in_array('project_id', $lsCols)) {
            $db->exec("DROP TABLE IF EXISTS last_seen");
            $db->exec("CREATE TABLE last_seen (
                user_id INTEGER NOT NULL, card_id INTEGER NOT NULL, seen_at TEXT NOT NULL,
                PRIMARY KEY (user_id, card_id), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )");
        }
        $version = 1;
    }

    // Version 1 → 2: API tokens table
    if ($version < 2) {
        $db->exec("CREATE TABLE IF NOT EXISTS api_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            token_hash TEXT NOT NULL UNIQUE,
            last_used_at TEXT,
            created_at TEXT DEFAULT (datetime('now')),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
    }

    // Version 2 → 3: Time tracking
    if ($version < 3) {
        $db->exec("CREATE TABLE IF NOT EXISTS time_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id TEXT NOT NULL,
            card_id INTEGER,
            card_title TEXT NOT NULL DEFAULT '',
            user_id INTEGER,
            author_name TEXT NOT NULL DEFAULT '',
            minutes INTEGER NOT NULL,
            note TEXT DEFAULT '',
            worked_at TEXT NOT NULL,
            created_at TEXT DEFAULT (datetime('now')),
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE SET NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_time_entries_project ON time_entries(project_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_time_entries_card ON time_entries(card_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_time_entries_project_date ON time_entries(project_id, worked_at)");
        $cols = array_column($db->query("PRAGMA table_info(projects)")->fetchAll(), 'name');
        if (!in_array('guest_can_view_time', $cols)) {
            $db->exec("ALTER TABLE projects ADD COLUMN guest_can_view_time INTEGER DEFAULT 0");
        }
    }

    // Version 3 → 4: Recovery keys
    if ($version < 4) {
        $cols = array_column($db->query("PRAGMA table_info(users)")->fetchAll(), 'name');
        if (!in_array('recovery_key_hash', $cols)) {
            $db->exec("ALTER TABLE users ADD COLUMN recovery_key_hash TEXT");
        }
    }

    // Version 4 → 5: Time entry start/end times
    if ($version < 5) {
        $cols = array_column($db->query("PRAGMA table_info(time_entries)")->fetchAll(), 'name');
        if (!in_array('start_time', $cols)) {
            $db->exec("ALTER TABLE time_entries ADD COLUMN start_time TEXT");
        }
        if (!in_array('end_time', $cols)) {
            $db->exec("ALTER TABLE time_entries ADD COLUMN end_time TEXT");
        }
    }

    // Version 5 → 6: Project reporting settings (week_start_day, cycle_reset_day)
    if ($version < 6) {
        $cols = array_column($db->query("PRAGMA table_info(projects)")->fetchAll(), 'name');
        if (!in_array('week_start_day', $cols)) {
            $db->exec("ALTER TABLE projects ADD COLUMN week_start_day INTEGER DEFAULT 1");
        }
        if (!in_array('cycle_reset_day', $cols)) {
            $db->exec("ALTER TABLE projects ADD COLUMN cycle_reset_day INTEGER DEFAULT 1");
        }
    }

    // Version 6 → 7: webhook preset fields
    if ($version < 7) {
        $cols = $db->query("PRAGMA table_info(project_webhooks)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('bot_token', $cols)) {
            $db->exec("ALTER TABLE project_webhooks ADD COLUMN bot_token TEXT");
        }
        if (!in_array('chat_id', $cols)) {
            $db->exec("ALTER TABLE project_webhooks ADD COLUMN chat_id TEXT");
        }
        if (!in_array('message_template', $cols)) {
            $db->exec("ALTER TABLE project_webhooks ADD COLUMN message_template TEXT");
        }
    }

    $db->exec('PRAGMA user_version = 7');
}

// ============================================================================
// SECURITY
// ============================================================================

// Block direct access to sensitive files
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$requestPath = strtolower(parse_url($requestUri, PHP_URL_PATH));
if (preg_match('/\.(sqlite|sqlite3|db|sql|bak)$/i', $requestPath)
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

function setSetting(string $key, string $value): void {
    getDb()->prepare("INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = ?")
        ->execute([$key, $value, $value]);
}

function rotateRecoveryKey(int $userId): string {
    $key = bin2hex(random_bytes(16));
    getDb()->prepare("UPDATE users SET recovery_key_hash = ? WHERE id = ?")
        ->execute([hash('sha256', $key), $userId]);
    return $key;
}

function formatEventText(string $eventType, array $payload, string $projectName, ?string $actorName, bool $includeContent = false): string {
    $actor = $actorName ?: 'Someone';
    $text = match ($eventType) {
        'new_card' => "$actor created card \"{$payload['title']}\" in $projectName",
        'new_comment' => "$actor commented on \"{$payload['card_title']}\" in $projectName",
        'card_updated' => "$actor updated card \"{$payload['title']}\" in $projectName",
        'new_project' => "$actor created project \"$projectName\"",
        'password_changed' => "$actor changed their password",
        default => "$actor triggered $eventType in $projectName",
    };
    if ($includeContent && $eventType === 'new_comment' && !empty($payload['content'])) {
        $text .= ": {$payload['content']}";
    }
    return $text;
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
        $body = formatWebhookPayload($hook['type'], $eventType, $payload, $projectName, $actorName, $hook['bot_token'] ?? null, $hook['chat_id'] ?? null, $hook['message_template'] ?? null);
        $url = $hook['type'] === 'telegram' && !empty($hook['bot_token'])
            ? "https://api.telegram.org/bot{$hook['bot_token']}/sendMessage"
            : $hook['url'];
        sendWebhook($url, $body, $hook['type']);
    }
}

function formatWebhookPayload(string $hookType, string $eventType, array $payload, string $projectName, ?string $actorName, ?string $botToken = null, ?string $chatId = null, ?string $messageTemplate = null): string {
    $text = formatEventText($eventType, $payload, $projectName, $actorName, true);

    $replaceTemplate = function(?string $tpl) use ($text, $eventType, $projectName, $actorName, $payload) {
        if (!$tpl) return $text;
        $replacements = [
            'event' => $eventType,
            'project' => $projectName,
            'actor' => $actorName ?? '',
            'title' => $payload['title'] ?? '',
            'timestamp' => date('c'),
        ];
        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', function($m) use ($replacements) {
            return $replacements[$m[1]] ?? $m[0];
        }, $tpl);
    };

    return match ($hookType) {
        'slack' => json_encode(['text' => $replaceTemplate($messageTemplate)]),
        'telegram' => $chatId
            ? json_encode([
                'chat_id' => $chatId,
                'text' => $replaceTemplate($messageTemplate),
                'parse_mode' => 'HTML',
            ])
            : json_encode(['text' => $text, 'parse_mode' => 'HTML']),
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

$action = $_GET['action'] ?? '';

// Handle ping before initDatabase() so DB errors are caught
if ($action === 'ping') {
    if (file_exists(DB_FILE)) {
        jsonResponse(['status' => 'pong']);
    } elseif (is_writable(dirname(DB_FILE))) {
        jsonResponse(['status' => 'pong']);
    } else {
        jsonResponse(['error' => 'Database unavailable'], 503);
    }
    exit;
}

initDatabase();

// Bearer token authentication (API tokens)
$_tokenAuth = false;
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (str_starts_with($authHeader, 'Bearer ')) {
    $rawToken = substr($authHeader, 7);
    $hash = hash('sha256', $rawToken);
    $db = getDb();
    $stmt = $db->prepare("SELECT user_id FROM api_tokens WHERE token_hash = ?");
    $stmt->execute([$hash]);
    $tokenRow = $stmt->fetch();
    if ($tokenRow) {
        $_SESSION['user_id'] = $tokenRow['user_id'];
        $_tokenAuth = true;
        $db->prepare("UPDATE api_tokens SET last_used_at = datetime('now') WHERE token_hash = ?")->execute([$hash]);
    } else {
        jsonResponse(['error' => 'Invalid API token'], 401);
    }
}

if ($action) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_tokenAuth && !in_array($action, ['auth_login', 'auth_setup'])) {
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
        'regenerate_recovery_key' => apiRegenerateRecoveryKey(),
        'list_api_tokens' => apiListApiTokens(),
        'create_api_token' => apiCreateApiToken(),
        'revoke_api_token' => apiRevokeApiToken(),

        // Team (admin only)
        'team_list' => apiTeamList(),
        'team_add' => apiTeamAdd(),
        'team_remove' => apiTeamRemove(),
        'team_update_role' => apiTeamUpdateRole(),
        'team_reset_password' => apiTeamResetPassword(),

        // App Settings (admin only)
        'auth_set_app_name' => apiAuthSetAppName(),
        'auth_set_locale' => apiAuthSetLocale(),

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
        'update_webhook' => apiUpdateWebhook(),
        'delete_webhook' => apiDeleteWebhook(),
        'toggle_webhook' => apiToggleWebhook(),
        'test_webhook' => apiTestWebhook(),

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

        // Time Tracking
        'list_time_entries' => apiListTimeEntries(),
        'create_time_entry' => apiCreateTimeEntry(),
        'update_time_entry' => apiUpdateTimeEntry(),
        'delete_time_entry' => apiDeleteTimeEntry(),
        'time_report' => apiTimeReport(),

        // Updates
        'check_update' => apiCheckUpdate(),
        'apply_update' => apiApplyUpdate(),

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
    $localeRow = $db->query("SELECT value FROM settings WHERE key = 'locale'")->fetch();
    $user = getCurrentUser();

    // Check for updates: clear flag if local version already matches latest
    $updateAvailable = false;
    $latestVersionRow = $db->query("SELECT value FROM settings WHERE key = 'latest_version'")->fetch();
    if ($latestVersionRow && $latestVersionRow['value']) {
        if (version_compare(APP_VERSION, $latestVersionRow['value'], '>=')) {
            $db->exec("DELETE FROM settings WHERE key = 'update_available'");
        } else {
            $flag = $db->query("SELECT value FROM settings WHERE key = 'update_available'")->fetch();
            $updateAvailable = $flag && $flag['value'] === '1';
        }
    }

    jsonResponse([
        'needs_setup' => needsSetup(),
        'authenticated' => $user !== null,
        'user' => $user,
        'app_name' => ($appNameRow && $appNameRow['value']) ? $appNameRow['value'] : APP_NAME,
        'locale' => ($localeRow && $localeRow['value']) ? $localeRow['value'] : 'en',
        'csrf_token' => $_SESSION['csrf_token'] ?? '',
        'version' => APP_VERSION,
        'update_available' => $updateAvailable,
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

    $recoveryKey = rotateRecoveryKey($userId);

    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));

    jsonResponse(['ok' => true, 'csrf_token' => $_SESSION['csrf_token'], 'recovery_key' => $recoveryKey]);
}

function getClientIp(): string {
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function checkRateLimit(): void {
    $ip = getClientIp();
    $db = getDb();
    $window = 15; // minutes
    $maxAttempts = 15;

    $db->prepare("DELETE FROM login_attempts WHERE attempted_at < datetime('now', ?)")->execute(["-$window minutes"]);

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
    $recoveryKey = $input['recovery_key'] ?? '';

    if (!$email || (!$password && !$recoveryKey)) {
        jsonResponse(['error' => 'Email and password are required'], 400);
    }

    checkRateLimit();

    $db = getDb();
    $stmt = $db->prepare("SELECT id, password_hash, recovery_key_hash FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        recordFailedAttempt();
        jsonResponse(['error' => 'Invalid email or password'], 403);
    }

    $response = ['ok' => true];
    $passwordResetRequired = false;

    if ($recoveryKey) {
        $inputHash = hash('sha256', str_replace('-', '', strtolower(trim($recoveryKey))));
        if (!$user['recovery_key_hash'] || !hash_equals($user['recovery_key_hash'], $inputHash)) {
            recordFailedAttempt();
            jsonResponse(['error' => 'Invalid recovery key'], 403);
        }
        $response['recovery_key'] = rotateRecoveryKey($user['id']);
        $passwordResetRequired = true;
    } else {
        if (!password_verify($password, $user['password_hash'])) {
            $candidateHash = hash('sha256', str_replace('-', '', strtolower(trim($password))));
            if ($user['recovery_key_hash'] && hash_equals($user['recovery_key_hash'], $candidateHash)) {
                $response['recovery_key'] = rotateRecoveryKey($user['id']);
                $passwordResetRequired = true;
            } else {
                recordFailedAttempt();
                jsonResponse(['error' => 'Invalid email or password'], 403);
            }
        } elseif (!$user['recovery_key_hash']) {
            $response['recovery_key'] = rotateRecoveryKey($user['id']);
            $passwordResetRequired = true;
        }
    }

    clearAttempts();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    if ($passwordResetRequired) {
        $_SESSION['password_reset_required'] = true;
        $response['password_reset'] = true;
    }
    $response['csrf_token'] = $_SESSION['csrf_token'];
    jsonResponse($response);
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

    setSetting('app_name', $name);
    jsonResponse(['ok' => true]);
}

function apiAuthSetLocale(): void {
    requireAdmin();
    $input = getInput();
    $locale = trim($input['locale'] ?? '');
    $valid = ['en', 'pt-BR', 'de', 'fr', 'es', 'it', 'ja'];
    if (!in_array($locale, $valid)) jsonResponse(['error' => 'Invalid locale'], 400);

    setSetting('locale', $locale);
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

    $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $stmt->execute([$email, $user['id']]);
    if ($stmt->fetch()) {
        jsonResponse(['error' => 'Email already in use'], 400);
    }

    if ($password) {
        if (strlen($password) < 6) {
            jsonResponse(['error' => 'New password must be at least 6 characters'], 400);
        }
        if (!$currentPassword && !empty($_SESSION['password_reset_required'])) {
            // Recovery-key login: allow password change without current password
        } else {
            $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->execute([$user['id']]);
            $row = $stmt->fetch();
            if (!password_verify($currentPassword, $row['password_hash'])) {
                jsonResponse(['error' => 'Current password is incorrect'], 403);
            }
        }
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $db->prepare("UPDATE users SET name = ?, email = ?, password_hash = ? WHERE id = ?")->execute([$name, $email, $hash, $user['id']]);
        unset($_SESSION['password_reset_required']);
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

function apiRegenerateRecoveryKey(): void {
    requireAuth();
    $user = getCurrentUser();
    $input = getInput();
    $password = $input['password'] ?? '';

    if (!$password) {
        jsonResponse(['error' => 'Password is required'], 400);
    }

    $db = getDb();
    $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();

    if (!password_verify($password, $row['password_hash'])) {
        jsonResponse(['error' => 'Invalid password'], 403);
    }

    jsonResponse(['ok' => true, 'recovery_key' => rotateRecoveryKey($user['id'])]);
}

// ============================================================================
// API: API TOKENS
// ============================================================================

function apiListApiTokens(): void {
    requireAuth();
    $user = getCurrentUser();
    $db = getDb();
    $stmt = $db->prepare("SELECT id, name, last_used_at, created_at FROM api_tokens WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user['id']]);
    jsonResponse($stmt->fetchAll());
}

function apiCreateApiToken(): void {
    requireAuth();
    $user = getCurrentUser();
    $input = getInput();
    $name = trim($input['name'] ?? '');
    if (!$name) jsonResponse(['error' => 'Token name is required'], 400);

    $rawToken = bin2hex(random_bytes(32));
    $hash = hash('sha256', $rawToken);

    $db = getDb();
    $stmt = $db->prepare("INSERT INTO api_tokens (user_id, name, token_hash) VALUES (?, ?, ?)");
    $stmt->execute([$user['id'], $name, $hash]);

    jsonResponse(['token' => $rawToken, 'id' => $db->lastInsertId(), 'name' => $name]);
}

function apiRevokeApiToken(): void {
    requireAuth();
    $user = getCurrentUser();
    $input = getInput();
    $id = (int) ($input['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'Token ID is required'], 400);

    $db = getDb();
    $stmt = $db->prepare("DELETE FROM api_tokens WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $user['id']]);

    if ($stmt->rowCount() === 0) jsonResponse(['error' => 'Token not found'], 404);
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
    $projects = $db->query("SELECT id, name, slug, created_at, guest_can_create_cards, guest_can_sort_cards, guest_can_view_time, week_start_day, cycle_reset_day FROM projects ORDER BY created_at DESC")->fetchAll();

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
    $stmt = $db->prepare("SELECT p.id, p.name, p.slug, p.guest_can_create_cards, p.guest_can_sort_cards, p.guest_can_view_time, g.name as guest_name, g.email as guest_email FROM guests g JOIN projects p ON g.project_id = p.id WHERE g.token = ?");
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row) jsonResponse(['error' => 'Invalid token'], 404);
    $row['guest_can_create_cards'] = (int) $row['guest_can_create_cards'];
    $row['guest_can_sort_cards'] = (int) $row['guest_can_sort_cards'];
    $row['guest_can_view_time'] = (int) $row['guest_can_view_time'];
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

    $db->prepare("INSERT INTO project_owners (project_id, user_id) VALUES (?, ?)")
        ->execute([$projectId, $user['id']]);
    autoWatchProject($projectId, $user['id']);

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
    if (isset($input['guest_can_view_time'])) {
        $fields[] = 'guest_can_view_time = ?';
        $params[] = (int) $input['guest_can_view_time'];
    }
    if (isset($input['week_start_day'])) {
        $val = (int) $input['week_start_day'];
        if ($val < 0 || $val > 6) jsonResponse(['error' => 'week_start_day must be 0-6'], 400);
        $fields[] = 'week_start_day = ?';
        $params[] = $val;
    }
    if (isset($input['cycle_reset_day'])) {
        $val = (int) $input['cycle_reset_day'];
        if ($val < 1 || $val > 31) jsonResponse(['error' => 'cycle_reset_day must be 1-31'], 400);
        $fields[] = 'cycle_reset_day = ?';
        $params[] = $val;
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

        $timeStmt = $db->prepare("SELECT COALESCE(SUM(minutes), 0) as total FROM time_entries WHERE card_id = ?");
        $timeStmt->execute([$card['id']]);
        $card['total_minutes'] = (int) $timeStmt->fetch()['total'];

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

    $db = getDb();
    $card = $db->prepare("SELECT col.project_id FROM cards c JOIN columns_ col ON c.column_id = col.id WHERE c.id = ?");
    $card->execute([$cardId]);
    $row = $card->fetch();
    if (!$row) jsonResponse(['error' => 'Not found'], 404);

    if (!isAuthenticated()) {
        $projectId = $row['project_id'];
        if (!isGuest($projectId)) {
            jsonResponse(['error' => 'Unauthorized'], 401);
        }
        $project = $db->prepare("SELECT guest_can_create_cards FROM projects WHERE id = ?");
        $project->execute([$projectId]);
        if (!(int) $project->fetch()['guest_can_create_cards']) {
            jsonResponse(['error' => 'Guests cannot manage tags'], 403);
        }
    }

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
        AND (cm.user_id IS NULL OR cm.user_id != ?)
        GROUP BY c.id
    ");
    $stmt->execute([$user['id'], $projectId, $user['id']]);
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
    $stmt = $db->prepare("SELECT id, url, type, enabled, bot_token, chat_id, message_template, created_at FROM project_webhooks WHERE project_id = ? ORDER BY created_at");
    $stmt->execute([$projectId]);
    jsonResponse($stmt->fetchAll());
}

function apiCreateWebhook(): void {
    $input = getInput();
    $projectId = $input['project_id'] ?? '';
    $url = trim($input['url'] ?? '');
    $type = $input['type'] ?? 'generic';
    $botToken = trim($input['bot_token'] ?? '');
    $chatId = trim($input['chat_id'] ?? '');
    $messageTemplate = $input['message_template'] ?? null;

    if (!$projectId) jsonResponse(['error' => 'Missing fields'], 400);
    if (!in_array($type, ['generic', 'slack', 'telegram'])) jsonResponse(['error' => 'Invalid type'], 400);

    if ($type === 'telegram') {
        if (!$botToken) jsonResponse(['error' => 'Bot token is required for Telegram'], 400);
        if (!$chatId) jsonResponse(['error' => 'Chat ID is required for Telegram'], 400);
        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    } else {
        if (!$url) jsonResponse(['error' => 'URL is required'], 400);
        if (!filter_var($url, FILTER_VALIDATE_URL)) jsonResponse(['error' => 'Invalid URL'], 400);
    }

    requireOwner($projectId);

    $db = getDb();
    $stmt = $db->prepare("INSERT INTO project_webhooks (project_id, url, type, bot_token, chat_id, message_template) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$projectId, $url, $type, $botToken ?: null, $chatId ?: null, $messageTemplate]);
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

function apiUpdateWebhook(): void {
    $input = getInput();
    $id = (int) ($input['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'Missing id'], 400);

    $db = getDb();
    $hook = $db->prepare("SELECT project_id FROM project_webhooks WHERE id = ?");
    $hook->execute([$id]);
    $row = $hook->fetch();
    if (!$row) jsonResponse(['error' => 'Not found'], 404);

    requireOwner($row['project_id']);

    $fields = [];
    $params = [];
    foreach (['url', 'type', 'bot_token', 'chat_id', 'message_template'] as $f) {
        if (array_key_exists($f, $input)) {
            $fields[] = "$f = ?";
            $params[] = $input[$f] ?: null;
        }
    }
    if (!$fields) jsonResponse(['error' => 'No fields to update'], 400);

    $params[] = $id;
    $db->prepare("UPDATE project_webhooks SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
    jsonResponse(['ok' => true]);
}

function apiTestWebhook(): void {
    $input = getInput();
    $id = (int) ($input['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'Missing id'], 400);

    $db = getDb();
    $hook = $db->prepare("SELECT * FROM project_webhooks WHERE id = ?");
    $hook->execute([$id]);
    $row = $hook->fetch();
    if (!$row) jsonResponse(['error' => 'Not found'], 404);

    requireOwner($row['project_id']);

    $body = formatWebhookPayload($row['type'], 'test_event', ['title' => 'Test card'], 'Test Project', 'Test', $row['bot_token'], $row['chat_id'], $row['message_template']);
    $url = $row['type'] === 'telegram' && !empty($row['bot_token'])
        ? "https://api.telegram.org/bot{$row['bot_token']}/sendMessage"
        : $row['url'];
    sendWebhook($url, $body, $row['type']);
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
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $rows = $db->prepare("SELECT key, value FROM settings WHERE key IN ($placeholders)");
    $rows->execute($keys);
    $settings = array_fill_keys($keys, '');
    foreach ($rows->fetchAll() as $row) {
        $settings[$row['key']] = $row['value'];
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
        setSetting($k, (string) $v);
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
    setSetting('cron_token', $token);
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
    $db = getDb();
    $projStmt = $db->prepare("SELECT name FROM projects WHERE id = ?");
    $projStmt->execute([$n['project_id']]);
    $projName = $projStmt->fetch()['name'] ?? '';
    return formatEventText($n['event_type'], $p, $projName, $n['actor_name']);
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
        setSetting('last_digest_at', date('c'));
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

    setSetting('last_digest_at', date('c'));

    jsonResponse(['ok' => true, 'processed' => $processed]);
}

// ============================================================================
// API: TIME TRACKING
// ============================================================================

function calcMinutesFromTimes(string $start, string $end): int {
    [$sh, $sm] = array_map('intval', explode(':', $start));
    [$eh, $em] = array_map('intval', explode(':', $end));
    return max(0, ($eh * 60 + $em) - ($sh * 60 + $sm));
}

function apiCreateTimeEntry(): void {
    requireAuth();
    $user = getCurrentUser();
    $input = getInput();

    $cardId = (int) ($input['card_id'] ?? 0);
    $minutes = (int) ($input['minutes'] ?? 0);
    $workedAt = trim($input['worked_at'] ?? '');
    $note = trim($input['note'] ?? '');
    $startTime = trim($input['start_time'] ?? '');
    $endTime = trim($input['end_time'] ?? '');

    if (!$cardId) jsonResponse(['error' => 'card_id is required'], 400);
    if (!$workedAt) jsonResponse(['error' => 'worked_at is required'], 400);

    if ($startTime && $endTime) {
        $minutes = calcMinutesFromTimes($startTime, $endTime);
    } elseif ($startTime) {
        $minutes = 0;
    } elseif ($minutes <= 0) {
        jsonResponse(['error' => 'minutes must be greater than 0'], 400);
    }

    $db = getDb();
    $card = $db->prepare("SELECT c.title, col.project_id FROM cards c JOIN columns_ col ON c.column_id = col.id WHERE c.id = ?");
    $card->execute([$cardId]);
    $card = $card->fetch();
    if (!$card) jsonResponse(['error' => 'Card not found'], 404);

    $stmt = $db->prepare("INSERT INTO time_entries (project_id, card_id, card_title, user_id, author_name, minutes, note, worked_at, start_time, end_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$card['project_id'], $cardId, $card['title'], $user['id'], $user['name'], $minutes, $note, $workedAt, $startTime ?: null, $endTime ?: null]);

    jsonResponse(['id' => (int) $db->lastInsertId()]);
}

function apiUpdateTimeEntry(): void {
    requireAuth();
    $user = getCurrentUser();
    $input = getInput();
    $id = (int) ($input['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'id is required'], 400);

    $db = getDb();
    $entry = $db->prepare("SELECT * FROM time_entries WHERE id = ?");
    $entry->execute([$id]);
    $entry = $entry->fetch();
    if (!$entry) jsonResponse(['error' => 'Time entry not found'], 404);

    if ($entry['user_id'] !== $user['id'] && !isProjectOwner($entry['project_id'])) {
        jsonResponse(['error' => 'Forbidden'], 403);
    }

    $startTime = array_key_exists('start_time', $input) ? trim($input['start_time']) : $entry['start_time'];
    $endTime = array_key_exists('end_time', $input) ? trim($input['end_time']) : $entry['end_time'];
    $note = array_key_exists('note', $input) ? trim($input['note']) : $entry['note'];
    $minutes = (int) $entry['minutes'];

    if ($startTime && $endTime) {
        $minutes = calcMinutesFromTimes($startTime, $endTime);
    }

    $stmt = $db->prepare("UPDATE time_entries SET start_time = ?, end_time = ?, note = ?, minutes = ? WHERE id = ?");
    $stmt->execute([$startTime ?: null, $endTime ?: null, $note, $minutes, $id]);

    jsonResponse(['ok' => true]);
}

function apiListTimeEntries(): void {
    $projectId = $_GET['project_id'] ?? '';
    $cardId = (int) ($_GET['card_id'] ?? 0);

    if ($projectId) {
        requireAccess($projectId);
    } elseif ($cardId) {
        $db = getDb();
        $card = $db->prepare("SELECT col.project_id FROM cards c JOIN columns_ col ON c.column_id = col.id WHERE c.id = ?");
        $card->execute([$cardId]);
        $row = $card->fetch();
        if (!$row) jsonResponse(['error' => 'Card not found'], 404);
        $projectId = $row['project_id'];
        requireAccess($projectId);
    } else {
        jsonResponse(['error' => 'project_id or card_id is required'], 400);
    }

    $db = getDb();
    $where = [];
    $params = [];

    if ($cardId) {
        $where[] = 'te.card_id = ?';
        $params[] = $cardId;
    } else {
        $where[] = 'te.project_id = ?';
        $params[] = $projectId;
    }

    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';
    if ($from) { $where[] = 'te.worked_at >= ?'; $params[] = $from; }
    if ($to) { $where[] = 'te.worked_at <= ?'; $params[] = $to; }

    $sql = "SELECT te.id, te.card_id, te.card_title, te.user_id, te.author_name, te.minutes, te.note, te.worked_at, te.start_time, te.end_time, te.created_at FROM time_entries te WHERE " . implode(' AND ', $where) . " ORDER BY te.worked_at DESC, te.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    jsonResponse($stmt->fetchAll());
}

function apiDeleteTimeEntry(): void {
    requireAuth();
    $user = getCurrentUser();
    $input = getInput();
    $id = (int) ($input['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'id is required'], 400);

    $db = getDb();
    $entry = $db->prepare("SELECT * FROM time_entries WHERE id = ?");
    $entry->execute([$id]);
    $entry = $entry->fetch();
    if (!$entry) jsonResponse(['error' => 'Time entry not found'], 404);

    if ($entry['user_id'] !== $user['id'] && !isProjectOwner($entry['project_id'])) {
        jsonResponse(['error' => 'Forbidden'], 403);
    }

    $db->prepare("DELETE FROM time_entries WHERE id = ?")->execute([$id]);
    jsonResponse(['ok' => true]);
}

function apiTimeReport(): void {
    $projectId = $_GET['project_id'] ?? '';
    if (!$projectId) jsonResponse(['error' => 'project_id is required'], 400);

    $guestToken = $_GET['guest'] ?? $_SESSION['guest_token'] ?? null;
    if ($guestToken) {
        $db = getDb();
        $project = $db->prepare("SELECT guest_can_view_time FROM projects WHERE id = ?");
        $project->execute([$projectId]);
        $project = $project->fetch();
        if (!$project || !$project['guest_can_view_time']) {
            jsonResponse(['error' => 'Forbidden'], 403);
        }
    } else {
        requireAccess($projectId);
    }

    $db = getDb();
    $where = ['te.project_id = ?'];
    $params = [$projectId];

    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';
    if ($from) { $where[] = 'te.worked_at >= ?'; $params[] = $from; }
    if ($to) { $where[] = 'te.worked_at <= ?'; $params[] = $to; }

    $whereSql = implode(' AND ', $where);

    // CSV export
    if (($_GET['format'] ?? '') === 'csv') {
        $stmt = $db->prepare("SELECT te.card_title, te.author_name, te.minutes, te.worked_at, te.note FROM time_entries te WHERE $whereSql ORDER BY te.worked_at DESC");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="time-report.csv"');
        echo "Card,Author,Minutes,Date,Note\n";
        foreach ($rows as $r) {
            echo '"' . str_replace('"', '""', $r['card_title']) . '","' . str_replace('"', '""', $r['author_name']) . '",' . $r['minutes'] . ',' . $r['worked_at'] . ',"' . str_replace('"', '""', $r['note']) . "\"\n";
        }
        exit;
    }

    // Total
    $stmt = $db->prepare("SELECT COALESCE(SUM(te.minutes), 0) as total FROM time_entries te WHERE $whereSql");
    $stmt->execute($params);
    $totalMinutes = (int) $stmt->fetchColumn();

    // Entries with card info
    $stmt = $db->prepare("SELECT te.id, te.card_id, te.card_title, te.user_id, te.author_name, te.minutes, te.note, te.worked_at, te.start_time, te.end_time FROM time_entries te WHERE $whereSql ORDER BY te.worked_at DESC, te.created_at DESC");
    $stmt->execute($params);
    $entries = $stmt->fetchAll();

    $entryCount = count($entries);
    $days = [];
    foreach ($entries as $e) { $days[$e['worked_at']] = true; }
    $avgPerDay = count($days) > 0 ? round($totalMinutes / count($days)) : 0;

    jsonResponse([
        'total_minutes' => $totalMinutes,
        'entry_count' => $entryCount,
        'avg_per_day' => $avgPerDay,
        'entries' => $entries,
    ]);
}

// ============================================================================
// API: UPDATES
// ============================================================================

function apiCheckUpdate(): void {
    requireAdmin();
    $db = getDb();

    // Return cached result if checked within 24h
    $lastCheck = $db->query("SELECT value FROM settings WHERE key = 'last_update_check_at'")->fetch();
    if ($lastCheck && $lastCheck['value']) {
        $lastTime = strtotime($lastCheck['value']);
        if ($lastTime && (time() - $lastTime) < 86400) {
            $latest = $db->query("SELECT value FROM settings WHERE key = 'latest_version'")->fetch();
            $flag = $db->query("SELECT value FROM settings WHERE key = 'update_available'")->fetch();
            jsonResponse([
                'update_available' => $flag && $flag['value'] === '1',
                'latest_version' => $latest ? $latest['value'] : APP_VERSION,
                'current_version' => APP_VERSION,
                'last_checked' => $lastCheck['value'],
            ]);
        }
    }

    // Fetch from GitHub
    $ctx = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => "User-Agent: Tasssks/" . APP_VERSION . "\r\n",
        'timeout' => 10,
        'ignore_errors' => true,
    ]]);
    $content = @file_get_contents(GITHUB_RAW_URL, false, $ctx);
    if ($content === false) {
        jsonResponse(['error' => 'Failed to fetch update info'], 502);
    }

    // Parse version
    if (!preg_match("/define\('APP_VERSION',\s*'([^']+)'\)/", $content, $m)) {
        jsonResponse(['error' => 'Invalid remote version format'], 502);
    }
    $latestVersion = $m[1];
    $available = version_compare(APP_VERSION, $latestVersion, '<');

    $now = date('c');
    setSetting('latest_version', $latestVersion);
    setSetting('update_available', $available ? '1' : '0');
    setSetting('last_update_check_at', $now);
    setSetting('latest_content', $content);

    jsonResponse([
        'update_available' => $available,
        'latest_version' => $latestVersion,
        'current_version' => APP_VERSION,
        'last_checked' => $now,
    ]);
}

function apiApplyUpdate(): void {
    requireAdmin();
    $db = getDb();

    // Read cached content
    $contentRow = $db->query("SELECT value FROM settings WHERE key = 'latest_content'")->fetch();
    if (!$contentRow || !$contentRow['value']) {
        jsonResponse(['error' => 'No update cached. Run check_update first.'], 400);
    }
    $content = $contentRow['value'];

    // Sanity check
    if (!preg_match("/define\('APP_VERSION',\s*'([^']+)'\)/", $content, $m) || empty($m[1])) {
        jsonResponse(['error' => 'Cached content is invalid'], 400);
    }
    $newVersion = $m[1];
    if ($newVersion === APP_VERSION) {
        jsonResponse(['error' => 'Already up to date'], 400);
    }

    // Backup current file
    $bakPath = __DIR__ . '/index.php.bak';
    if (!copy(__DIR__ . '/index.php', $bakPath)) {
        jsonResponse(['error' => 'Failed to create backup'], 500);
    }

    // Write new content
    $previousVersion = APP_VERSION;
    if (file_put_contents(__DIR__ . '/index.php', $content) === false) {
        // Restore from backup
        @copy($bakPath, __DIR__ . '/index.php');
        jsonResponse(['error' => 'Failed to write update. Restored from backup.'], 500);
    }

    // Clear update state
    $db->exec("DELETE FROM settings WHERE key IN ('update_available', 'latest_content')");

    jsonResponse([
        'ok' => true,
        'previous_version' => $previousVersion,
        'new_version' => $newVersion,
    ]);
}

// ============================================================================
// SMTP SENDER
// ============================================================================

function sendSmtpEmail(array $smtp, string $to, string $subject, string $body): string|bool {
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
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='6' fill='%23334155'/><text x='16' y='22' font-family='sans-serif' font-size='14' font-weight='bold' fill='white' text-anchor='middle'>T.</text></svg>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?></title>
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/marked@12.0.0/marked.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/dompurify@3.1.6/dist/purify.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/turndown@7.2.0/dist/turndown.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/turndown-plugin-gfm@1.0.2/dist/turndown-plugin-gfm.js"></script>
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

[data-theme="dark"] {
    --bg: #0f172a;
    --surface: #1e293b;
    --surface-hover: #334155;
    --border: #334155;
    --border-strong: #475569;
    --text: #f1f5f9;
    --text-muted: #94a3b8;
    --text-light: #64748b;
    --primary: #60a5fa;
    --primary-hover: #3b82f6;
    --primary-light: #1e3a5f;
    --danger: #f87171;
    --danger-hover: #ef4444;
    --success: #34d399;
    --shadow: 0 1px 3px rgba(0,0,0,0.3), 0 1px 2px rgba(0,0,0,0.2);
    --shadow-md: 0 4px 6px rgba(0,0,0,0.3), 0 2px 4px rgba(0,0,0,0.2);
    --shadow-lg: 0 10px 15px rgba(0,0,0,0.4), 0 4px 6px rgba(0,0,0,0.3);
}

[data-theme="dark"] .ql-snow .ql-stroke { stroke: var(--text-muted); }
[data-theme="dark"] .ql-snow .ql-fill { fill: var(--text-muted); }
[data-theme="dark"] .ql-snow .ql-picker { color: var(--text-muted); }
[data-theme="dark"] .ql-snow .ql-picker-options { background: var(--surface); border-color: var(--border); }
[data-theme="dark"] .ql-editor { color: var(--text); }
[data-theme="dark"] img { opacity: 0.9; }

* { margin: 0; padding: 0; box-sizing: border-box; }

html { color-scheme: light; }
[data-theme="dark"] { color-scheme: dark; }

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: var(--bg);
    color: var(--text);
    line-height: 1.5;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
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

.breadcrumb { display: flex; align-items: center; gap: 8px; font-size: 14px; color: var(--text-muted); min-width: 0; }

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

.btn:disabled, .btn[disabled] { opacity: 0.4; cursor: not-allowed; pointer-events: none; }
.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:hover { background: var(--primary-hover); }

.btn-ghost { background: transparent; color: var(--text-muted); border-color: var(--border); }
.btn-ghost:hover { background: var(--surface-hover); color: var(--text); border-color: var(--border-strong); }
.btn-outline-primary { background: transparent; color: var(--primary); border-color: var(--primary); }
.btn-outline-primary:hover { background: var(--primary); color: #fff; }

.btn-danger { background: transparent; color: var(--danger); border-color: var(--danger); }
.btn-danger:hover { background: var(--danger); color: #fff; }

.btn-sm { height: 30px; padding: 0 10px; font-size: 12px; }
.btn-xs { height: 24px; padding: 0 6px; font-size: 11px; min-width: auto; }

/* Forms */
input[type="text"], input[type="email"], input[type="password"], input[type="date"], input[type="time"], textarea, select {
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

.quill-wrap { border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; background: var(--surface); }
.quill-wrap .ql-toolbar.ql-snow { border: none; background: transparent; padding: 4px 8px; }
.quill-wrap .ql-toolbar .ql-formats { margin-right: 8px; }
.quill-wrap .ql-toolbar button { width: 26px; height: 26px; padding: 3px; }
.quill-wrap .ql-toolbar button svg { width: 16px; height: 16px; }
.quill-wrap .ql-toolbar .ql-picker-label { padding: 2px 4px; }
.quill-wrap .ql-container.ql-snow { border: none; font-size: 14px; }
.quill-wrap .ql-editor { min-height: 120px; max-height: 300px; overflow-y: auto; padding: 10px 12px; }
.quill-wrap.compact .ql-editor { min-height: 50px; max-height: 160px; }
.quill-wrap.compact .ql-toolbar.ql-snow { padding: 2px 6px; }
.quill-wrap.compact .ql-toolbar button { width: 24px; height: 24px; }
.quill-wrap .ql-editor.ql-blank::before { color: var(--text-light); font-style: normal; left: 12px; }

label { display: block; font-size: 13px; font-weight: 500; color: var(--text-muted); margin-bottom: 4px; }
.form-group { margin-bottom: 16px; }
.form-group-hint { font-size: 12px; color: var(--text-light); margin-top: 4px; }
.form-group-hint a { color: var(--primary); }
.card-ref-dropdown { position: absolute; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-md); z-index: 1001; max-width: 250px; max-height: 150px; overflow-y: auto; display: none; }
.card-ref-dropdown .card-ref-item { padding: 6px 10px; cursor: pointer; font-size: 13px; display: flex; gap: 6px; align-items: center; }
.card-ref-dropdown .card-ref-item:hover, .card-ref-dropdown .card-ref-item.active { background: var(--primary-light); }
.card-ref-dropdown .card-ref-item .card-ref-id { color: var(--primary); font-weight: 600; min-width: 30px; }
.card-ref-dropdown .card-ref-item .card-ref-title { color: var(--text-light); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

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
    width: 100%;
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
    gap: 16px;
}

.project-card:hover { border-color: var(--primary); box-shadow: var(--shadow); }
.project-index { display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; font-size:12px; font-weight:600; color:var(--text-muted); background:var(--bg); border-radius:var(--radius-sm); flex:0 0 22px; }
.project-card-info { flex: 1; min-width: 0; }
.project-card-info h3 { font-size: 16px; font-weight: 600; margin-bottom: 2px; }
.project-card-info span { font-size: 13px; color: var(--text-muted); }

/* Board */
.board {
    display: flex;
    gap: 16px;
    padding: 24px;
    overflow-x: auto;
    min-height: calc(100vh - 110px);
    align-items: flex-start;
}

.column {
    flex: 0 0 300px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    display: flex;
    flex-direction: column;
    max-height: calc(100vh - 150px);
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
.column-empty { text-align: center; color: var(--text-light); font-size: 13px; padding: 16px 8px; margin: 0; }

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
.card.sortable-chosen { transform: rotate(-2deg) scale(1.03); box-shadow: var(--shadow-lg); z-index: 10; transition: transform 0.15s ease, box-shadow 0.15s ease; }

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
.modal-wide { max-width: 800px; }

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

.modal-body { padding: 24px; position: relative; }
.modal-footer {
    padding: 16px 24px;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    gap: 8px;
}
.modal-footer:has(:only-child) { justify-content: flex-end; }
.modal-footer:empty { border-top: none; padding: 0; }

/* Settings tabs */
.settings-tabs { display:flex; gap:0; border-bottom:1px solid var(--border); margin: -12px -24px 24px; padding: 0 24px; }
.settings-tab {
    padding:10px 16px; font-size:13px; font-weight:500; cursor:pointer;
    background:none; border:none; border-bottom:2px solid transparent;
    color:var(--text-muted); transition:all var(--transition);
}
.settings-tab:hover { color:var(--text); }
.settings-tab.active { color:var(--primary); border-bottom-color:var(--primary); }

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

kbd { display: inline-block; padding: 2px 6px; font-size: 12px; font-family: inherit; line-height: 1.4; color: var(--text); background: var(--bg); border: 1px solid var(--border-strong); border-radius: var(--radius-sm); box-shadow: 0 1px 0 var(--border-strong); min-width: 22px; text-align: center; }

.markdown-body { font-size: 14px; line-height: 1.6; }
.markdown-body p { margin-bottom: 8px; }
.markdown-body ol, .markdown-body ul { padding-left: 24px; margin-bottom: 8px; }
.markdown-body ol { list-style-type: decimal; }
.markdown-body ul { list-style-type: disc; }
.markdown-body li { margin-bottom: 4px; }
.markdown-body code { background: var(--surface-hover); padding: 2px 6px; border-radius: var(--radius-sm); font-size: 13px; }
.markdown-body pre { background: var(--surface-hover); padding: 12px; border-radius: var(--radius); overflow-x: auto; margin-bottom: 8px; }
.markdown-body pre code { background: none; padding: 0; }
.markdown-body img { max-width: 100%; border-radius: var(--radius); }

.comment { padding: 12px; border: 1px solid var(--border); border-radius: var(--radius); margin-bottom: 8px; }
.comment-header { display: flex; justify-content: space-between; margin-bottom: 4px; font-size: 12px; }
.comment-author { font-weight: 600; }
.comment-date { color: var(--text-light); }
.comment-body { font-size: 14px; margin-top: 16px; }

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

/* Utility classes */
.hidden { display: none !important; }
.w-full { width: 100%; }
.cursor-pointer { cursor: pointer; }

.flex { display: flex; }
.flex-center { display: flex; align-items: center; }
.flex-between { display: flex; align-items: center; justify-content: space-between; }
.flex-col { display: flex; flex-direction: column; }
.flex-wrap { flex-wrap: wrap; }
.flex-1 { flex: 1; }
.flex-shrink-0 { flex-shrink: 0; }

.gap-1 { gap: 4px; }
.gap-2 { gap: 8px; }
.gap-3 { gap: 12px; }
.gap-sm { gap: 4px; }
.gap-md { gap: 6px; }

.p-2 { padding: 8px; }
.p-3 { padding: 12px; }
.p-4 { padding: 16px; }
.px-0 { padding-left: 0; padding-right: 0; }

.mt-2 { margin-top: 8px; }
.mt-3 { margin-top: 12px; }
.mb-2 { margin-bottom: 8px; }
.mb-3 { margin-bottom: 12px; }
.mb-4 { margin-bottom: 16px; }
.mb-6 { margin-bottom: 24px; }
.mb-0 { margin-bottom: 0; }

.text-xs { font-size: 11px; }
.text-sm { font-size: 12px; }
.text-base { font-size: 13px; }
.text-lg { font-size: 14px; }
.text-xl { font-size: 18px; }
.text-2xl { font-size: 20px; }
.font-normal { font-weight: 400; }
.font-semibold { font-weight: 600; }

.text { color: var(--text); }
.text-muted { color: var(--text-muted); }
.text-light { color: var(--text-light); }
.text-primary { color: var(--primary); }
.text-danger { color: var(--danger); }
.text-success { color: var(--success); }
.bg-hover { background: var(--surface-hover); }
.bg-primary-light { background: var(--primary-light); }

.mono { font-family: monospace; }
.text-center { text-align: center; }
.text-uppercase { text-transform: uppercase; }
.text-right { text-align: right; }
.text-nowrap { white-space: nowrap; }
.overflow-hidden { overflow: hidden; }
.overflow-auto { overflow: auto; }
.word-break { word-break: break-all; }
.whitespace-pre { white-space: pre-wrap; }
.border-bottom { border-bottom: 1px solid var(--border); }

.items-center { align-items: center; }
.justify-center { justify-content: center; }
.justify-between { justify-content: space-between; }
.justify-end { justify-content: flex-end; }

.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }

.rounded { border-radius: var(--radius); }
.rounded-full { border-radius: 50%; }

.auth-container { max-width: 400px; margin: 60px auto; }
.auth-container + .auth-container { margin-top: 80px; }

.inline-flex { display: inline-flex; }
.inline-block { display: inline-block; }

/* Card detail utilities */
.card-detail-top { display: flex; align-items: center; justify-content: space-between; }
.card-detail-meta { font-size: 12px; color: var(--text-muted); display: grid; grid-template-columns: 1fr 1fr; gap: 4px 16px; flex: 1; }
.card-detail-tags { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.card-detail-tags .tag-add { background: var(--border); cursor: pointer; font-size: 13px; }
.card-detail-actions { display: flex; align-items: flex-start; gap: 8px; margin-bottom: 12px; }
.card-detail-actions .quill-wrap { flex: 1; min-width: 0; }
.card-detail-actions-end { display: flex; justify-content: flex-end; }
.btn-edit-inline { height: 22px; padding: 0 8px; font-size: 11px; margin-left: 8px; }
.btn-edit-title { height: 22px; padding: 0 8px; font-size: 11px; vertical-align: middle; }
.btn-watch { height: 30px; padding: 0 12px; font-size: 13px; display: inline-flex; align-items: center; gap: 4px; }
.btn-comment { height: 20px; padding: 0 6px; font-size: 11px; }
.btn-comment-danger { height: 20px; padding: 0 6px; font-size: 11px; color: var(--danger); }
.text-browse { color: var(--primary); cursor: pointer; text-decoration: underline; }
.text-no-desc { color: var(--text-light); }
.text-no-att { color: var(--text-light); }
.text-no-comments { color: var(--text-light); font-size: 13px; }

/* Attachment file link */
.attachment-file {
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--surface-hover);
    aspect-ratio: auto;
    padding: 8px;
    flex-direction: column;
    gap: 4px;
    text-decoration: none;
}
.attachment-file-icon { font-size: 20px; }
.attachment-file-name { font-size: 10px; color: var(--text-muted); word-break: break-all; text-align: center; }

/* Cover image */
.cover-image { width: 100%; height: 120px; object-fit: cover; border-radius: var(--radius-sm); margin-bottom: 8px; }

/* Tag picker */
.tag-picker { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.tag-color-dot {
    width: 22px; height: 22px; border-radius: 50%; cursor: pointer;
    border: 2px solid transparent; display: inline-block; flex-shrink: 0;
}
.tag-color-dot-lg {
    width: 28px; height: 28px; border-radius: 50%; cursor: pointer;
    border: 3px solid transparent; display: inline-block;
}
.tag-color-dot.selected, .tag-color-dot-lg.selected { border-color: var(--text); }
.tag-input { flex: 1; min-width: 120px; }
.btn-shrink-0 { flex-shrink: 0; }
.select-full { width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: var(--radius); }
.select-sm { padding: 6px 8px; border: 1px solid var(--border); border-radius: var(--radius); font-size: 13px; }

/* Code block */
.code-block {
    background: var(--surface-hover);
    padding: 12px;
    border-radius: var(--radius);
    font-size: 12px;
    overflow-x: auto;
    white-space: pre-wrap;
}

/* Help table */
.help-table { font-size: 13px; width: 100%; border-collapse: collapse; }
.help-table td { padding: 6px 10px; }
.help-table tr:nth-child(odd) { background: var(--surface-hover); }
.help-table td:first-child { width: 100px; }

/* Dynamic styles (keep inline: dynamic colors, conditional grab, display:none for tabs) */
.cursor-grab { cursor: grab; }
.text-18px { font-size: 18px; }
.min-w-sm { min-width: 200px; }
.min-h-textarea { min-height: 80px; }
.pl-5 { padding-left: 20px; }

/* Row card pattern */
.row-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    margin-bottom: 6px;
}

/* Form row (inline) */
.form-row { display: flex; gap: 8px; align-items: center; }
.form-row input { flex: 1; }

/* API token rows */
.api-token-row { display: flex; align-items: center; justify-content: space-between; padding: 10px; border: 1px solid var(--border); border-radius: var(--radius); margin-bottom: 6px; }
/* Time tracking */
.card-detail-actions-col { display: flex; flex-direction: column; gap: 4px; }
.time-section { padding: 12px 0; border-bottom: 1px solid var(--border); margin-bottom: 8px; }
.time-form { display: flex; flex-direction: column; gap: 8px; }
.time-form-row { display: flex; gap: 8px; align-items: center; }
.time-form-row .time-arrow { color: var(--text-muted); flex: none; }
.time-form-row .time-input { width: 90px; flex: none; }
.time-form-row input[type="date"] { width: 140px; flex: none; }
.time-form-row .flex-1 { flex: 1; min-width: 0; }
.time-input-group { display: flex; align-items: center; flex: none; }
.time-input-group input[type="time"] { width: 100px; border-top-right-radius: 0; border-bottom-right-radius: 0; border-right: none; }
.time-now-btn { height: var(--input-height); padding: 0 8px; font-size: 11px; font-weight: 500; text-transform: uppercase; letter-spacing: 0.02em; border: 1px solid var(--border); border-left: none; border-radius: 0 var(--radius) var(--radius) 0; }
.time-entries-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.time-entries-table th { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--border); font-weight: 500; color: var(--text-muted); }
.time-entries-table td { padding: 6px 8px; border-bottom: 1px solid var(--border); }
.time-entries-table td:last-child { width: auto; text-align: right; white-space: nowrap; }
.time-entries-table tr.in-progress td:first-child { color: var(--primary); }

/* Time report */
.report-controls { display: flex; flex-wrap: wrap; gap: 12px; justify-content: space-between; margin-bottom: 16px; }
.report-periods, .report-group { display: flex; gap: 0; align-items: center; }
.report-periods > .btn, .report-group > .btn { border-radius: 0; border-right: none; }
.report-periods > .btn:first-of-type, .report-group > .btn:first-of-type { border-top-left-radius: var(--radius); border-bottom-left-radius: var(--radius); }
.report-periods > .btn:last-child, .report-group > .btn:last-child { border-top-right-radius: var(--radius); border-bottom-right-radius: var(--radius); border-right: 1px solid var(--border); }
.report-periods > .btn.btn-primary, .report-group > .btn.btn-primary { border-right: 1px solid transparent; }
.report-group > span { margin-right: 8px; }
.report-summary { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 16px; }
.report-stat { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 16px 12px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); }
.report-stat strong { font-size: 22px; margin-bottom: 4px; }
.report-stat span { font-size: 12px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
.report-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.report-table th { text-align: left; padding: 8px; border-bottom: 2px solid var(--border); font-weight: 500; }
.report-table td { padding: 8px; border-bottom: 1px solid var(--border); }

.token-input { font-family: monospace; font-size: 12px; flex: 1; }
.btn-icon { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; padding: 0; border: 1px solid var(--border); border-radius: var(--radius); background: var(--surface); cursor: pointer; color: var(--text-muted); }
.btn-icon:hover { background: var(--surface-hover); color: var(--text); }
.btn-icon-danger { color: var(--danger); border: none; font-size: 1.25rem; }
.btn-icon-danger:hover { background: var(--danger); color: #fff; }
.recovery-key-display { display: inline-flex; align-items: center; gap: 8px; background: var(--surface-hover); border: 1px solid var(--border); border-radius: var(--radius); padding: 12px 16px; margin: 8px 0; }
.recovery-key-display code { font-family: monospace; font-size: 15px; letter-spacing: 0.08em; user-select: all; color: var(--text); }
.recovery-key-confirm { display: flex; align-items: center; gap: 6px; justify-content: center; font-size: 14px; cursor: pointer; }
.recovery-key-confirm input { cursor: pointer; }
.toast { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); padding: 10px 20px; border-radius: var(--radius); font-size: 14px; z-index: 10001; box-shadow: var(--shadow-lg); animation: toast-in 0.2s ease; }
.toast-error { background: var(--danger); color: #fff; }
.toast-success { background: var(--success, #22c55e); color: #fff; }
@keyframes toast-in { from { opacity: 0; transform: translateX(-50%) translateY(10px); } to { opacity: 1; transform: translateX(-50%) translateY(0); } }

/* Badge patterns */
.badge-admin {
    background: #dbeafe;
    color: #1e40af;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 500;
}
.badge-member {
    background: #f1f5f9;
    color: #475569;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 500;
}

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
    text-decoration: none;
}
.dropdown-item:hover { background: var(--surface-hover); }
.dropdown-item svg { width: 16px; height: 16px; stroke: currentColor; fill: none; }
.shortcut-hint { margin-left:auto; font-size:11px; color:var(--text-light); opacity:0.6; }

.dropdown-divider {
    height: 1px;
    background: var(--border);
    margin: 4px 0;
}

.dropdown-item--danger { color: var(--danger); }
.dropdown-item--danger:hover { background: #fef2f2; }

/* Footer */
.app-footer {
    margin-top: auto;
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
.search-wrapper .search-count.count-visible { display: block; }
.search-wrapper .search-count::after { content: ' cards'; }
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
.search-wrapper .search-clear.search-visible { display: block; }
.search-wrapper .search-clear:hover { color: var(--text); }

/* Utilities */
.hidden { display: none !important; }
.mt-2 { margin-top: 8px; }
.mt-3 { margin-top: 12px; }
.mt-4 { margin-top: 16px; }
.mb-2 { margin-bottom: 8px; }
.mb-3 { margin-bottom: 12px; }

@media (max-width: 768px) {
    /* Board */
    .board { padding: 12px; padding-bottom: 48px; gap: 12px; }
    .column { flex: 0 0 85vw; }
    .projects-view { margin: 24px auto; }

    /* Footer: stack vertically */
    .app-footer { flex-direction: column; gap: 4px; }

    /* Modal: fit mobile */
    .modal-overlay { padding-top: 24px; }
    .modal { margin: 0 8px; width: calc(100% - 16px); max-height: calc(100vh - 48px); }
    .modal-header { padding: 16px; }
    .modal-body { padding: 16px; }
    .modal-footer { padding: 12px 16px; }
    .settings-tabs { margin: -16px -16px 16px; padding: 0 16px; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .settings-tab { padding: 10px 12px; font-size: 12px; white-space: nowrap; }

    /* Cards */
    .card-title { font-size: 13px; }
    .card-meta { flex-wrap: wrap; gap: 4px; }

    /* Navbar: hide app name, brand fills space */
    #header-brand-name { display: none; }
    .header-brand { flex: 1; min-width: 0; }
    .header-brand svg { width: 24px; height: 24px; flex-shrink: 0; }
    .breadcrumb { min-width: 0; }
    .breadcrumb strong { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: block; }

    /* Navbar: watch/settings text hidden */
    .watch-label, .settings-label { display: none; }

    /* Navbar: avatar dropdown icon only */
    .dropdown-toggle { font-size: 0; gap: 0; padding: 0 8px; }
    .dropdown-toggle svg { font-size: initial; }
    .dropdown-toggle svg:last-child { display: none; }

    /* Navbar: search collapses to icon, expands on focus */
    .header-nav { position: relative; z-index: 1; }
    .search-wrapper { position: static; }
    .search-wrapper input { width: 34px; padding: 0 0 0 34px; border-color: transparent; background: transparent; transition: none; }
    .search-wrapper:focus-within { position: fixed; top: 0; left: 0; right: 0; z-index: 200; background: var(--surface); border-bottom: 1px solid var(--border); box-shadow: var(--shadow-md); }
    .search-wrapper:focus-within input { width: 100%; position: static; padding: 12px 52px 12px 44px; border-radius: 0; height: 48px; font-size: 16px; background: transparent; border: none; box-shadow: none; }
    .search-wrapper:focus-within .search-visible { display: block !important; position: absolute; right: 12px; top: 50%; transform: translateY(-50%); border: none; background: none; color: var(--text-light); font-size: 20px; }
    .search-wrapper:focus-within .search-count { display: flex; position: absolute; right: 44px; top: 50%; transform: translateY(-50%); background: var(--surface-hover); padding: 2px 8px; border-radius: 10px; font-size: 11px; color: var(--text-muted); }
    .search-wrapper:focus-within .search-count::after { content: none; }
}
    </style>
    <script>
    (function(){
        var t = localStorage.getItem('theme');
        if (t === 'dark' || (!t && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.setAttribute('data-theme', 'dark');
        }
    })();
    </script>
</head>
<body>

<!-- Header -->
<div class="header">
    <div class="header-brand">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" onclick="if(!App.isGuest) App.showProjects()" class="cursor-pointer">
            <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
            <rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
        </svg>
        <span id="header-brand-name" onclick="if(!App.isGuest) App.showProjects()" class="cursor-pointer"><?= APP_NAME ?></span>
        <span class="breadcrumb" id="breadcrumb"></span>
    </div>
    <div class="header-nav">
        <div id="navbar-actions" class="flex-center gap-2"></div>
    </div>
</div>

<!-- Guest banner -->
<div class="guest-banner hidden" id="guest-banner">You are viewing this board as a guest.</div>

<!-- Setup View (first-time) -->
<div id="view-setup" class="projects-view hidden">
    <div class="auth-container">
        <h1 class="mb-2">Welcome to <?= APP_NAME ?></h1>
        <p class="text-muted mb-6">Create your admin account to get started.</p>
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
            <button type="submit" class="btn btn-primary w-full">Create Account</button>
        </form>
        <p id="setup-error" class="hidden text-danger mt-3 text-base"></p>
    </div>
</div>

<!-- Login View -->
<div id="view-login" class="projects-view hidden">
    <div class="auth-container text-center">
        <h1 class="mb-6">&#128274; <span id="login-brand"><?= APP_NAME ?></span></h1>
        <form onsubmit="event.preventDefault();App.login()">
            <div class="form-group">
                <input type="text" id="login-email" placeholder="Email" autocomplete="email">
            </div>
            <div class="form-group">
                <input type="password" id="login-password" placeholder="Password or recovery key" autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn-primary w-full">Sign In</button>
        </form>
        <p id="login-error" class="hidden text-danger mt-3 text-base"></p>
    </div>
</div>

<!-- Unauthorized View -->
<div id="view-unauthorized" class="projects-view hidden">
    <div class="auth-container text-center">
        <h1 class="mb-3">Unauthorized</h1>
        <p class="text-muted">You need a valid guest link to access this board.</p>
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

// Configure marked.js: links open in new tab, force all lists to bullets
marked.use({
    renderer: {
        link({ href, title, text }) {
            const titleAttr = title ? ` title="${title}"` : '';
            return `<a href="${href}" target="_blank" rel="noopener noreferrer"${titleAttr}>${text}</a>`;
        },
        list(body) {
            return '<ul>' + body + '</ul>\n';
        }
    }
});

// Turndown service for HTML → Markdown conversion
const _turndown = new TurndownService({ headingStyle: 'atx', hr: '---', bulletListMarker: '-', codeBlockStyle: 'fenced' });
_turndown.use(turndownPluginGfm.gfm);
_turndown.addRule('forcedBullet', {
    filter: 'ol',
    replacement: function (content, node) {
        const items = [];
        node.querySelectorAll(':scope > li').forEach(li => {
            items.push('- ' + li.textContent.trim());
        });
        return '\n' + items.join('\n') + '\n';
    }
});

// Custom Quill blot for horizontal rule
const BlockEmbed = Quill.import('blots/block/embed');
class DividerBlot extends BlockEmbed {}
DividerBlot.blotName = 'divider';
DividerBlot.tagName = 'hr';
Quill.register(DividerBlot);

// Markdown shortcuts module: converts markdown syntax to rich text as you type
class MarkdownShortcuts {
    constructor(quill) {
        this.quill = quill;
        quill.on('text-change', (delta, _old, source) => {
            if (source !== 'user') return;
            const lastOp = delta.ops?.[delta.ops.length - 1];
            if (!lastOp?.insert || typeof lastOp.insert !== 'string') return;
            setTimeout(() => {
                this.coerceOlToUl();
                this.check();
            }, 0);
        });
    }

    coerceOlToUl() {
        const lines = this.quill.getLines();
        for (const line of lines) {
            if (line.formats().list === 'ordered') {
                const idx = this.quill.getIndex(line);
                this.quill.formatLine(idx, 1, 'list', 'bullet');
            }
        }
    }

    check() {
        const sel = this.quill.getSelection();
        if (!sel) return;
        const [line, offset] = this.quill.getLine(sel.index);
        if (!line) return;
        const text = line.domNode.textContent.substring(0, offset);
        const lineStart = sel.index - offset;

        // Block patterns (triggered by space after prefix)
        if (text.length >= 2 && text[text.length - 1] === ' ') {
            const prefix = text.slice(0, -1);
            if (prefix === '>') {
                this.quill.deleteText(lineStart, text.length);
                this.quill.formatLine(lineStart, 1, 'blockquote', true);
                return;
            }
            if (prefix === '-' || prefix === '*') {
                this.quill.formatLine(lineStart, 1, 'list', false);
                this.quill.deleteText(lineStart, text.length);
                this.quill.formatLine(lineStart, 1, 'list', 'bullet');
                return;
            }
        }

        // HR: --- at start of line
        if (text === '---') {
            this.quill.deleteText(lineStart, 3);
            this.quill.insertEmbed(lineStart, 'divider', true, Quill.sources.USER);
            this.quill.setSelection(lineStart + 1);
            return;
        }

        // Inline patterns
        let m;
        if ((m = text.match(/!\[([^\]]*)\]\(([^)]+)\)$/))) {
            const start = sel.index - m[0].length;
            this.quill.deleteText(start, m[0].length);
            this.quill.insertEmbed(start, 'image', m[2], Quill.sources.USER);
            this.quill.setSelection(start + 1);
        } else if ((m = text.match(/\[([^\]]+)\]\(([^)]+)\)$/))) {
            this.replace(sel.index, m[0], m[1], 'link', m[2]);
        } else if ((m = text.match(/\*\*(.+?)\*\*$/))) {
            this.replace(sel.index, m[0], m[1], 'bold', true);
        } else if ((m = text.match(/(?<!\*)\*([^*]+?)\*$/))) {
            this.replace(sel.index, m[0], m[1], 'italic', true);
        } else if ((m = text.match(/~~(.+?)~~$/))) {
            this.replace(sel.index, m[0], m[1], 'strike', true);
        }
    }

    replace(cursor, full, content, format, value) {
        const start = cursor - full.length;
        this.quill.deleteText(start, full.length);
        this.quill.insertText(start, content, format, value);
        this.quill.setSelection(start + content.length);
        this.quill.format(format, false);
    }
}
Quill.register('modules/markdownShortcuts', MarkdownShortcuts);

const QUILL_TOOLBAR = [
    ['bold', 'italic', 'underline', 'strike'],
    [{ list: 'bullet' }]
];

function _initQuill(selector, opts = {}) {
    const quill = new Quill(selector, {
        theme: 'snow',
        placeholder: opts.placeholder || '',
        modules: {
            toolbar: QUILL_TOOLBAR,
            markdownShortcuts: true
        }
    });
    const toolbar = quill.container.parentNode.querySelector('.ql-toolbar');
    if (toolbar) {
        toolbar.setAttribute('tabindex', '-1');
        toolbar.querySelectorAll('button, select').forEach(el => el.setAttribute('tabindex', '-1'));
    }
    _initQuillReplacements(quill);
    _initCardRefAutocomplete(quill);
    if (opts.html) {
        quill.clipboard.dangerouslyPasteHTML(opts.html);
        quill.setSelection(0, 0);
    }
    return quill;
}

function _quillToMarkdown(quill) {
    const html = quill.root.innerHTML;
    if (!html || html === '<p><br></p>') return '';
    return _turndown.turndown(html);
}

function linkCardRefs(html, projectId) {
    return html.replace(/#(\d+)/g, `<a href="#project/${projectId}/card/$1" onclick="event.preventDefault();App.openCard($1)">#$1</a>`);
}

const TEXT_REPLACEMENTS = {
    ':check:': '\u2705',
    ':cross:': '\u274C',
    ':x:': '\u274C',
    ':arrow-right:': '\u2192',
    ':emdash:': '\u2014',
    '->': '\u2192',
    '<-': '\u2190',
    '--': '\u2014'
};

const _replacementKeys = Object.keys(TEXT_REPLACEMENTS).sort((a, b) => b.length - a.length);

function _applyReplacements(input) {
    let v = input.value;
    let changed = false;
    for (const key of _replacementKeys) {
        if (v.endsWith(key)) {
            v = v.slice(0, -key.length) + TEXT_REPLACEMENTS[key];
            changed = true;
        }
    }
    if (changed) {
        const pos = input.selectionStart;
        input.value = v;
        input.setSelectionRange(pos, pos);
    }
}

function _initQuillReplacements(quill) {
    quill.on('text-change', () => {
        const range = quill.getSelection();
        if (!range) return;
        const fullText = quill.getText();
        for (const key of _replacementKeys) {
            if (fullText.slice(0, range.index).endsWith(key)) {
                const idx = range.index - key.length;
                quill.deleteText(idx, key.length);
                quill.insertText(idx, TEXT_REPLACEMENTS[key]);
                quill.setSelection(idx + TEXT_REPLACEMENTS[key].length);
                return;
            }
        }
    });
}

let _cardRefDropdown = null;
function _initCardRefAutocomplete(quill) {
    if (!_cardRefDropdown) {
        _cardRefDropdown = document.createElement('div');
        _cardRefDropdown.className = 'card-ref-dropdown';
        const modal = document.querySelector('.modal-body') || document.body;
        modal.appendChild(_cardRefDropdown);
    }
    let _matches = [];

    function _hideDropdown() { _cardRefDropdown.style.display = 'none'; _matches = []; }

    function _showDropdown(quill, matches) {
        const bounds = quill.getBounds(quill.getSelection().index);
        const containerOffset = quill.container.getBoundingClientRect().top - _cardRefDropdown.parentElement.getBoundingClientRect().top;
        _cardRefDropdown.style.left = (bounds.left) + 'px';
        _cardRefDropdown.style.top = (containerOffset + bounds.bottom + 4) + 'px';
        _cardRefDropdown.innerHTML = matches.map(m =>
            `<div class="card-ref-item"><span class="card-ref-id">#${m.id}</span><span class="card-ref-title">${App.esc(m.title)}</span></div>`
        ).join('');
        _cardRefDropdown.style.display = 'block';
    }

    function _linkifyCardRefs(quill) {
        const sel = quill.getSelection();
        const keep = sel ? sel.index : null;
        const text = quill.getText();
        const regex = /#(\d+)(?![0-9])/g;
        let m;
        let changed = false;
        while ((m = regex.exec(text)) !== null) {
            const idx = m.index;
            const len = m[0].length;
            const end = idx + len;
            if (keep !== null && keep === end) continue;
            quill.formatText(idx, len, 'link', `#project/${App.currentProject?.id || ''}/card/${m[1]}`, 'silent');
            changed = true;
        }
        if (changed && keep !== null) {
            quill.setSelection(keep, 0);
        }
    }

    quill.on('text-change', () => {
        const range = quill.getSelection();
        if (!range) return;
        const text = quill.getText().slice(0, range.index);
        const m = text.match(/#(\d+)$/);
        if (m) {
            const query = m[1];
            _matches = (App.cards || []).filter(c => String(c.id).startsWith(query)).slice(0, 5);
            if (_matches.length) { _showDropdown(quill, _matches); }
            else { _hideDropdown(); }
        } else {
            _hideDropdown();
        }
        setTimeout(() => _linkifyCardRefs(quill), 50);
    });

    quill.on('selection-change', (range) => {
        if (!range || range.length > 0) { _hideDropdown(); return; }
        const text = quill.getText().slice(0, range.index);
        if (!text.match(/#(\d+)$/)) _hideDropdown();
    });
}

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
    _quill: null,
    _quillComment: null,

    init() {
        this._updateThemeIcon();
        const params = new URLSearchParams(window.location.search);
        this.guestToken = params.get('guest');

        window.addEventListener('hashchange', () => {
            if (!this._navigating) this.handleRoute();
        });

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
                this.locale = status.locale || 'en';
                this.updateBrand();
                this.appVersion = status.version || '<?= APP_VERSION ?>';
                this.updateAvailable = status.update_available || false;
                if (status.needs_setup) {
                    $('#view-setup').removeClass('hidden');
                    setTimeout(() => $('#setup-name').focus(), 100);
                } else if (status.authenticated) {
                    this.user = status.user;
                    this.handleRoute();
                    // Auto-check for updates (fire-and-forget, admin only)
                    if (this.user.role === 'admin') {
                        setTimeout(() => {
                            this.api('check_update').done(res => {
                                this.updateAvailable = res.update_available;
                                this.appVersion = res.current_version;
                                this._updateSettingsBadge();
                                this._showUpdateToast();
                            });
                        }, 2000);
                    }
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

    toggleTheme() {
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        if (isDark) {
            document.documentElement.removeAttribute('data-theme');
            localStorage.setItem('theme', 'light');
        } else {
            document.documentElement.setAttribute('data-theme', 'dark');
            localStorage.setItem('theme', 'dark');
        }
        this._updateThemeIcon();
    },

    _updateThemeIcon() {
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        const sun = document.getElementById('theme-icon-sun');
        const moon = document.getElementById('theme-icon-moon');
        const label = document.getElementById('theme-label');
        if (moon) moon.style.display = isDark ? 'none' : 'inline';
        if (sun) sun.style.display = isDark ? 'inline' : 'none';
        if (label) label.textContent = isDark ? 'Light mode' : 'Dark mode';
        const gSun = document.getElementById('guest-theme-icon-sun');
        const gMoon = document.getElementById('guest-theme-icon-moon');
        if (gMoon) gMoon.style.display = isDark ? 'none' : 'inline';
        if (gSun) gSun.style.display = isDark ? 'inline' : 'none';
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
            if (res.recovery_key) {
                this._showRecoveryKeyModal(res.recovery_key);
            }
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
                this.appVersion = status.version || this.appVersion;
                this.updateAvailable = status.update_available || false;
                this.handleRoute();
                if (res.recovery_key) {
                    this._showRecoveryKeyModal(res.recovery_key, res.password_reset);
                }
            });
        }).fail((xhr) => {
            const msg = xhr.responseJSON?.error || 'Login failed';
            $('#login-error').text(msg).removeClass('hidden');
            $('#login-password').val('').focus();
        });
    },

    _formatRecoveryKey(key) {
        return key.replace(/(.{4})/g, '$1-').slice(0, -1).toUpperCase();
    },

    _showRecoveryKeyModal(key, passwordReset) {
        const formatted = this._formatRecoveryKey(key);
        const pwField = passwordReset ? `
                <div class="form-group mt-4 text-left">
                    <label><strong>Set a new password</strong></label>
                    <input type="password" id="rk-new-password" placeholder="Min. 6 characters" autocomplete="new-password">
                </div>` : '';
        const checkHandler = passwordReset
            ? `App._validateRkModal()`
            : `$('#rk-dismiss-btn').prop('disabled',!this.checked)`;
        const doneHandler = passwordReset ? `App._submitRkPassword()` : `App.closeModal()`;
        this.openModal('Recovery Key', `
            <div class="text-center">
                <p class="text-base mb-4"><strong>Save this recovery key in a secure place.</strong><br>You can use it to log in if you forget your password.</p>
                <div class="recovery-key-display">
                    <code id="recovery-key-value">${formatted}</code>
                    <button type="button" class="btn btn-sm btn-icon ml-2" onclick="App._copyRecoveryKey()" title="Copy">
                        <svg id="rk-copy-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                        <svg id="rk-check-icon" class="hidden" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--success)" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                    </button>
                </div>
                <p class="text-danger text-sm mt-4">This key will not be shown again.</p>${pwField}
                <label class="recovery-key-confirm mt-4"><input type="checkbox" id="rk-saved-check" onchange="${checkHandler}"> I have saved this key</label>
            </div>
        `, `<button class="btn btn-primary" id="rk-dismiss-btn" disabled onclick="${doneHandler}">Done</button>`);
        if (passwordReset) {
            setTimeout(() => $('#rk-new-password').on('input', () => this._validateRkModal()), 50);
        }
    },

    _validateRkModal() {
        const checked = $('#rk-saved-check').is(':checked');
        const pw = $('#rk-new-password').val();
        const valid = checked && pw && pw.length >= 6;
        $('#rk-dismiss-btn').prop('disabled', !valid);
    },

    _submitRkPassword() {
        const pw = $('#rk-new-password').val();
        if (!pw || pw.length < 6) {
            this.toast('Password must be at least 6 characters.');
            return;
        }
        this.api('account_update', { name: this.user.name, email: this.user.email, password: pw }, 'POST').done(() => {
            this.closeModal();
            this.toast('Password updated.', 'success');
        }).fail(xhr => {
            this.toast(xhr.responseJSON?.error || 'Failed');
        });
    },

    _copyRecoveryKey() {
        const text = $('#recovery-key-value').text();
        navigator.clipboard.writeText(text).then(() => {
            $('#rk-copy-icon').addClass('hidden');
            $('#rk-check-icon').removeClass('hidden');
            setTimeout(() => { $('#rk-check-icon').addClass('hidden'); $('#rk-copy-icon').removeClass('hidden'); }, 2000);
        });
    },

    regenerateRecoveryKey() {
        this.openModal('Regenerate Recovery Key', `
            <p class="text-base mb-4">Enter your current password to generate a new recovery key. The old key will stop working immediately.</p>
            <div class="form-group"><label>Password</label>
                <input type="password" id="regen-rk-password" autocomplete="current-password">
            </div>
        `, `
            <button class="btn btn-ghost" onclick="App.closeModal()">Cancel</button>
            <button class="btn btn-primary" id="regen-rk-btn" onclick="App._doRegenerateRecoveryKey()">Regenerate</button>
        `);
        setTimeout(() => $('#regen-rk-password').focus(), 50);
    },

    _doRegenerateRecoveryKey() {
        const password = $('#regen-rk-password').val();
        if (!password) { $('#regen-rk-password').focus(); return; }
        this.api('regenerate_recovery_key', { password }, 'POST').done(res => {
            this._showRecoveryKeyModal(res.recovery_key);
        }).fail(xhr => {
            this.toast(xhr.responseJSON?.error || 'Failed');
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
            list.html('<div class="text-center p-4 text-muted">No projects yet. Create one to get started.</div>');
            return;
        }
        list.html(this.projects.map((p, i) => {
            const unread = p.unread_comments || 0;
            const badgeText = unread > 9 ? '9+' : unread;
            const badgeHtml = unread ? `<span class="badge badge-muted">${badgeText}</span>` : '';
            const deleteBtn = p.is_owner ? `<button class="btn btn-ghost btn-sm" onclick="event.stopPropagation();App.confirmDeleteProject('${p.id}','${this.escAttr(p.name)}')">Delete</button>` : '';
            const watchIcon = p.is_watching ? '<svg viewBox="0 0 24 24" width="14" height="14" stroke="var(--primary)" fill="none" stroke-width="2" title="Watching"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>' : '';
            return `<div class="project-card" onclick="App.openProject('${p.id}')">
                <span class="project-index">${i + 1}</span>
                <div class="project-card-info">
                    <h3>${this.esc(p.name)} ${watchIcon}</h3>
                    <span>Created ${p.created_at}</span>
                </div>
                <div class="flex-center gap-2">
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
                        <span class="shortcut-hint">A</span>
                    </button>
                    ${isAdmin ? `<button class="dropdown-item" onclick="App.showTeam();$('.dropdown-menu').removeClass('open')">
                        <svg viewBox="0 0 24 24" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        Team
                        <span class="shortcut-hint">T</span>
                    </button>` : ''}
                    ${isAdmin ? `<button class="dropdown-item" onclick="App.showAppSettings();$('.dropdown-menu').removeClass('open')">
                        <svg viewBox="0 0 24 24" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                        Settings${this.updateAvailable ? ' <span style="display:inline-block;width:7px;height:7px;background:var(--primary);border-radius:50%;vertical-align:middle;margin-left:4px"></span>' : ''}
                        <span class="shortcut-hint">⌘,</span>
                    </button>` : ''}
                    <div class="dropdown-divider"></div>
                    <button class="dropdown-item" onclick="App.toggleTheme();$('.dropdown-menu').removeClass('open')">
                        <svg id="theme-icon-moon" viewBox="0 0 24 24" stroke-width="2" style="${document.documentElement.getAttribute('data-theme')==='dark'?'display:none':''}"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                        <svg id="theme-icon-sun" viewBox="0 0 24 24" stroke-width="2" style="${document.documentElement.getAttribute('data-theme')==='dark'?'':'display:none'}"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                        <span id="theme-label">${document.documentElement.getAttribute('data-theme')==='dark'?'Light mode':'Dark mode'}</span>
                    </button>
                    <button class="dropdown-item" onclick="App.showHelp();$('.dropdown-menu').removeClass('open')">
                        <svg viewBox="0 0 24 24" stroke-width="2"><path d="M3 11h1a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H3a1 1 0 0 1-1-1v-5a1 1 0 0 1 1-1z"/><path d="M21 11h-1a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1a1 1 0 0 0 1-1v-5a1 1 0 0 0-1-1z"/><path d="M4 11V8a8 8 0 0 1 16 0v3"/><path d="M18 18a4 4 0 0 1-4 4h-2"/></svg>
                        Help
                        <span class="shortcut-hint">?</span>
                    </button>
                    <a class="dropdown-item" href="https://github.com/Abtz-Labs/tasssks/issues" target="_blank" rel="noopener noreferrer" onclick="$('.dropdown-menu').removeClass('open')">
                        <svg viewBox="0 0 24 24" stroke-width="2"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>
                        Report a Bug
                        <svg viewBox="0 0 24 24" width="12" height="12" stroke="currentColor" fill="none" stroke-width="2" style="margin-left:auto"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                    </a>
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
            actions += `<button class="btn btn-ghost btn-sm" onclick="App.showTimeReport()"><svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> <span class="settings-label">Report</span></button>`;
            if (isOwner) {
                actions += `<button class="btn btn-ghost btn-sm" onclick="App.showSettings()"><svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg> <span class="settings-label">Project Settings</span></button>`;
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
            }
            if (this.currentProject.guest_can_view_time) {
                guestActions += `<button class="btn btn-ghost btn-sm" onclick="App.showTimeReport()"><svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> <span class="settings-label">Report</span></button>`;
            }
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            guestActions += `<button class="btn btn-ghost btn-sm" onclick="App.toggleTheme()">
                <svg id="guest-theme-icon-moon" viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2" style="${isDark?'display:none':''}"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                <svg id="guest-theme-icon-sun" viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2" style="${isDark?'':'display:none'}"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
            </button>`;
            $('#navbar-actions').html(guestActions);
            if (this.currentProject.guest_has_email) {
                this.loadGuestWatchState();
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
                        ${!this.isGuest && !isFixed ? `<button class="modal-close text-18px" onclick="App.deleteColumn(${col.id})" title="Delete column">&times;</button>` : ''}
                    </div>
                    <div class="column-cards${!this.isGuest || (isFixed && this.currentProject.guest_can_sort_cards) ? ' cards-sortable' : ''}" data-column-id="${col.id}">
                        ${colCards.length ? colCards.map(card => this.renderCard(card)).join('') : '<p class="column-empty">Empty stack</p>'}
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
        const coverHtml = coverAtt ? `<img src="?file=${encodeURIComponent(coverAtt.path)}" class="cover-image">` : '';
        const hasAtt = (card.attachments || []).length > 0;
        const commentCount = card.comment_count || 0;
        const unread = this.unreadCounts[card.id] || 0;
        const unreadBadge = unread ? `<span class="badge" title="${unread} new">${unread > 9 ? '9+' : unread}</span>` : '';
        return `
            <div class="card" data-id="${card.id}" onclick="App.openCard(${card.id})">
                ${coverHtml}
                ${tagsHtml ? `<div class="card-tags">${tagsHtml}</div>` : ''}
                <div class="card-title flex-between items-start gap-2">
                    <span>${this.esc(card.title)} ${unreadBadge}</span>
                    <span class="text-xs text-light text-nowrap ml-2 inline-flex items-center gap-1">${card.is_watching ? '<svg viewBox="0 0 24 24" width="12" height="12" stroke="var(--primary)" fill="none" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>' : ''}#${card.id}</span>
                </div>
                <div class="card-meta">
                    ${card.description ? '<span title="Has description">&#9776;</span>' : ''}
                    ${hasAtt ? '<span title="Has attachments">&#128206;</span>' : ''}
                    ${commentCount ? `<span title="${commentCount} comment${commentCount > 1 ? 's' : ''}">&#128172; ${commentCount}</span>` : ''}
                    ${card.total_minutes ? `<span title="${this.formatMinutes(card.total_minutes)} tracked">&#9201; ${this.formatMinutes(card.total_minutes)}</span>` : ''}
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
                        scroll: true, scrollSensitivity: 80, scrollSpeed: 15, bubbleScroll: true,
                        onEnd: (evt) => {
                            const cardId = parseInt(evt.item.dataset.id);
                            const newColumnId = parseInt(evt.to.dataset.columnId);
                            this.api('move_card', { id: cardId, column_id: newColumnId, position: evt.newIndex }, 'POST');
                            $(evt.to).find('.column-empty').remove();
                            if (!$(evt.from).find('.card').length) {
                                $(evt.from).append('<p class="column-empty">Empty stack</p>');
                            }
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
                delay: 500, delayOnTouchOnly: true, touchStartThreshold: 5,
                chosenClass: 'sortable-chosen',
                scroll: true, scrollSensitivity: 80, scrollSpeed: 15, bubbleScroll: true,
                onEnd: (evt) => {
                    const cardId = parseInt(evt.item.dataset.id);
                    const newColumnId = parseInt(evt.to.dataset.columnId);
                    this.api('move_card', { id: cardId, column_id: newColumnId, position: evt.newIndex }, 'POST');
                    $(evt.to).find('.column-empty').remove();
                    if (!$(evt.from).find('.card').length) {
                        $(evt.from).append('<p class="column-empty">Empty stack</p>');
                    }
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
            <div class="form-group"><label>Description</label><div class="quill-wrap"><div id="new-card-desc"></div></div></div>
        `, `<button class="btn btn-ghost" onclick="App.closeModal()">Cancel</button> <button class="btn btn-primary" onclick="App.createCard(${columnId})">Add Card</button>`);
        setTimeout(() => {
            this._quill = _initQuill('#new-card-desc', { placeholder: 'Optional description...' });
            $('#new-card-title').on('input', function() { _applyReplacements(this); }).focus();
        }, 50);
    },

    createCard(columnId) {
        const title = $('#new-card-title').val().trim();
        if (!title) { this.toast('Title is required.'); $('#new-card-title').focus(); return; }
        const description = this._quill ? _quillToMarkdown(this._quill) : '';
        this.api('create_card', { column_id: columnId, title, description }, 'POST').done(res => {
            const cardId = res.id;
            this.closeModal();
            this.refreshBoard();
            setTimeout(() => this.openCard(cardId), 100);
        });
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
        const descHtml = card.description ? linkCardRefs(DOMPurify.sanitize(marked.parse(card.description), { ADD_ATTR: ['target'] }), this.currentProject.id) : '<em class="text-no-desc">No description</em>';
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
            return `<a href="?file=${encodeURIComponent(a.path)}" download="${this.esc(a.filename)}" class="attachment-item attachment-file" onclick="event.stopPropagation()">
                <span class="attachment-file-icon">&#128196;</span>
                <span class="attachment-file-name">${this.esc(a.filename)}</span>
                ${!this.isGuest ? `<button class="attachment-delete" onclick="event.stopPropagation();event.preventDefault();App.deleteAttachment(${a.id},${cardId})">&times;</button>` : ''}
            </a>`;
        }).join('');

        const canWatch = !this.isGuest || (this.isGuest && this.currentProject.guest_has_email);
        const watchAction = this.isGuest ? `App._cardWatching ? App.guestUnwatchCard(${cardId}) : App.guestWatchCard(${cardId})` : `App._cardWatching ? App.unwatchCard(${cardId}) : App.watchCard(${cardId})`;
        const watchBtn = canWatch ? `<button class="btn btn-ghost btn-watch" id="watch-btn-${cardId}" onclick="${watchAction}">...</button>` : '';

        let body = `
            <div class="card-detail-section card-detail-top">
                <div class="card-detail-meta">
                    <span><strong>ID:</strong> #${card.id}</span>
                    ${card.author_name ? `<span><strong>Author:</strong> ${this.esc(card.author_name)}</span>` : '<span></span>'}
                    <span><strong>Created:</strong> ${card.created_at}</span>
                    ${card.updated_at !== card.created_at ? `<span><strong>Updated:</strong> ${card.updated_at}</span>` : '<span></span>'}
                </div>
                <div class="card-detail-actions-col">
                    ${watchBtn}
                    ${!this.isGuest ? `<button class="btn btn-ghost btn-watch" onclick="App.toggleTimeEntries(${cardId})">&#9201; <span id="time-total-${cardId}">${card.total_minutes ? this.formatMinutes(card.total_minutes) : '0m'}</span></button>` : ''}
                </div>
            </div>
            <div id="time-section-${cardId}" class="hidden time-section">
                <div class="time-form">
                    <div class="time-form-row">
                        <div class="time-input-group">
                            <input type="time" id="time-start" title="Start time">
                            <button type="button" class="btn btn-ghost time-now-btn" onclick="App.setTimeNow('time-start')" title="Set current time">Now</button>
                        </div>
                        <span class="time-arrow">→</span>
                        <div class="time-input-group">
                            <input type="time" id="time-end" title="End time">
                            <button type="button" class="btn btn-ghost time-now-btn" onclick="App.setTimeNow('time-end')" title="Set current time">Now</button>
                        </div>
                        <input type="date" id="time-date" value="${new Date().toISOString().slice(0,10)}">
                    </div>
                    <div class="time-form-row">
                        <input type="text" id="time-input" placeholder="0h 00m" class="time-input" title="Duration (auto-calculated or manual)">
                        <input type="text" id="time-note" placeholder="Note (optional)" class="flex-1">
                        <button type="button" class="btn btn-primary btn-sm" onclick="App.addTimeEntry(${cardId})">+</button>
                    </div>
                </div>
                <div id="time-entries-${cardId}"></div>
            </div>
            <div class="card-detail-section card-detail-tags">
                ${tagsHtml}
                ${!this.isGuest || this.currentProject.guest_can_create_cards ? `<span class="tag tag-add" onclick="App.showTagPicker(${cardId})">+</span>` : ''}
                ${!this.isGuest ? `<select id="card-column-select" class="btn btn-ghost btn-watch" onchange="App.moveCardToColumn(${cardId}, parseInt(this.value))" style="appearance:auto;padding-right:24px;width:auto;max-width:118px;margin-left:auto;height:30px">
                    ${this.columns.map(c => `<option value="${c.id}" ${c.id == card.column_id ? 'selected' : ''}>${this.esc(c.name)}</option>`).join('')}
                </select>` : ''}
            </div>
            <div class="card-detail-section">
                <h4>Description ${!this.isGuest ? `<button class="btn btn-ghost btn-sm btn-edit-inline" onclick="App.editCardDescription(${cardId})">Edit</button>` : ''}</h4>
                <div class="markdown-body">${descHtml}</div>
            </div>
            <div class="card-detail-section">
                <h4>Attachments</h4>
                <div class="attachment-grid mb-2">${attHtml || '<em class="text-no-att">None</em>'}</div>
                ${!this.isGuest ? `<div class="drop-zone mt-2" id="drop-zone" data-card-id="${cardId}">
                    <p>Drop files here or <label for="file-upload" class="text-browse">browse</label></p>
                    <input type="file" id="file-upload" multiple onchange="App.uploadFiles(${cardId}, this.files)" class="hidden">
                </div>` : ''}
            </div>
            <div class="card-detail-section">
                <h4>Comments</h4>
                <div class="card-detail-actions">
                    <div class="quill-wrap compact"><div id="new-comment"></div></div>
                    <div class="card-detail-actions-end">
                        <button class="btn btn-primary btn-sm" onclick="App.addComment(${cardId})">Comment</button>
                    </div>
                </div>
                <div id="card-comments">Loading...</div>
            </div>
        `;
        const footer = !this.isGuest ? `
            <button class="btn btn-danger" onclick="App.deleteCard(${cardId})">Delete Card</button>
        ` : '';
        const titleHtml = this.esc(card.title) + (!this.isGuest ? ` <button class="btn btn-ghost btn-sm btn-edit-title" onclick="App.editCardTitle(${cardId})">Edit</button>` : '');
        this.openModal(titleHtml, body, footer);
        setTimeout(() => {
            this._quillComment = _initQuill('#new-comment', { compact: true, placeholder: 'Write a comment...' });
            $(this._quillComment.root).on('keydown', e => {
                if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
                    e.preventDefault();
                    this.addComment(cardId);
                }
            });
        }, 50);
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
        setTimeout(() => { $('#edit-card-title').on('input', function() { _applyReplacements(this); }).focus().select(); }, 50);
    },

    saveCardTitle(cardId) {
        const title = $('#edit-card-title').val().trim();
        if (!title) { this.toast('Title is required.'); $('#edit-card-title').focus(); return; }
        this.api('update_card', { id: cardId, title }, 'POST').done(() => { this.refreshBoard(); setTimeout(() => this.openCard(cardId), 200); });
    },

    editCardDescription(cardId) {
        const card = this.cards.find(c => c.id == cardId);
        const html = card.description ? marked.parse(card.description) : '';
        this.openModal('Edit Description', `
            <div class="form-group"><div class="quill-wrap"><div id="edit-card-desc"></div></div></div>
        `, `
            <button class="btn btn-ghost" onclick="App.closeModal();setTimeout(()=>App.openCard(${cardId}),100)">Cancel</button>
            <button class="btn btn-primary" onclick="App.saveCardDescription(${cardId})">Save</button>
        `);
        setTimeout(() => {
            this._quill = _initQuill('#edit-card-desc', { html });
            const len = this._quill.getLength();
            this._quill.setSelection(len, 0);
            this._quill.focus();
        }, 50);
    },

    saveCardDescription(cardId) {
        const description = this._quill ? _quillToMarkdown(this._quill) : '';
        this.api('update_card', { id: cardId, description }, 'POST').done(() => { this.refreshBoard(); setTimeout(() => this.openCard(cardId), 200); });
    },

    moveCardToColumn(cardId, newColumnId) {
        const card = this.cards.find(c => c.id == cardId);
        if (!card || card.column_id == newColumnId) return;
        this.api('move_card', { id: cardId, column_id: newColumnId, position: 0 }, 'POST').done(() => {
            this.refreshBoard();
            setTimeout(() => this.openCard(cardId), 200);
        });
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
            ${!this.isGuest ? `<div class="card-detail-section"><h4>Create New Tag</h4>
                <div class="tag-picker">
                    <input type="text" id="new-tag-name" placeholder="Tag name" class="tag-input">
                    ${colors.map(c => `<span class="tag-color-dot" style="background:${c}" onclick="App.selectTagColor(this,'${c}')"></span>`).join('')}
                    <input type="hidden" id="new-tag-color" value="${colors[0]}">
                    <button class="btn btn-primary btn-sm btn-shrink-0" onclick="App.createTag()">Add</button>
                </div>
            </div>` : ''}
        `, '');
        this._pendingTagCardId = cardId;
    },

    showNewTag(cardId = null) {
        const colors = ['#ef4444','#f97316','#eab308','#22c55e','#3b82f6','#8b5cf6','#ec4899'];
        this._pendingTagCardId = cardId;
        this.openModal('New Tag', `
            <div class="form-group"><label>Name</label><input type="text" id="new-tag-name" placeholder="e.g. Bug"></div>
            <div class="form-group"><label>Color</label>
            <div class="tag-picker">
                ${colors.map(c => `<span style="width:28px;height:28px;border-radius:50%;background:${c};cursor:pointer;border:3px solid transparent;display:inline-block" onclick="$(this).parent().find('span').css('border-color','transparent');$(this).css('border-color','var(--text)');$('#new-tag-color').val('${c}')"></span>`).join('')}
            </div>
            <input type="hidden" id="new-tag-color" value="${colors[0]}">
            </div>
        `, `<button class="btn btn-primary" onclick="App.createTag()">Create</button>`);
        setTimeout(() => $('#new-tag-name').focus(), 100);
    },

    selectTagColor(el, color) {
        $(el).parent().find('.tag-color-dot, .tag-color-dot-lg').removeClass('selected');
        $(el).addClass('selected');
        $(el).parent().find('input[type="hidden"]').val(color);
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
            try {
                const currentAuthor = this.isGuest ? this.guestName : (this.user?.name || 'Unknown');
                const html = comments.length ? comments.map(c => {
                    const isOwn = c.author_name === currentAuthor;
                    const safeContent = c.content || '';
                    const rendered = DOMPurify.sanitize(marked.parse(safeContent), { ADD_ATTR: ['target'] });
                    return `<div class="comment">
                        <div class="comment-header">
                            <span class="comment-author">${this.esc(c.author_name)} wrote:</span>
                            <span class="flex-center gap-2">
                                ${isOwn ? `<button class="btn btn-ghost btn-sm btn-comment" onclick="App.editComment(${c.id},${cardId})">Edit</button>
                                <button class="btn btn-ghost btn-sm btn-comment-danger" onclick="App.deleteComment(${c.id},${cardId})">Delete</button>` : ''}
                                <span class="comment-date">${c.created_at}</span>
                            </span>
                        </div>
                        <div class="comment-body markdown-body" data-raw="${encodeURIComponent(safeContent)}">${rendered}</div>
                    </div>`;
                }).join('') : '<p class="text-no-comments">No comments yet.</p>';
                $('#card-comments').html(html);
            } catch (e) {
                console.error('Failed to render comments:', e);
                $('#card-comments').html('<p class="text-no-comments">Failed to load comments.</p>');
            }
        }).fail(() => {
            $('#card-comments').html('<p class="text-no-comments">Failed to load comments.</p>');
        });
    },

    addComment(cardId) {
        if (!this._quillComment) return;
        const content = _quillToMarkdown(this._quillComment);
        if (!content) return;
        this.api('create_comment', { card_id: cardId, content }, 'POST').done(() => {
            this._quillComment.setText('');
            this.loadComments(cardId);
            this.refreshBoard();
        });
    },

    editComment(commentId, cardId) {
        const commentEl = $(`#card-comments .comment`).filter(function() {
            return $(this).find('[onclick*="editComment(' + commentId + '"]').length > 0;
        });
        const raw = decodeURIComponent(commentEl.find('.comment-body').attr('data-raw') || '');
        const html = raw ? marked.parse(raw) : '';
        this.openModal('Edit Comment', `
            <div class="form-group"><div class="quill-wrap"><div id="edit-comment-editor"></div></div></div>
        `, `
            <button class="btn btn-ghost" onclick="App.closeModal();setTimeout(()=>App.openCard(${cardId}),100)">Cancel</button>
            <button class="btn btn-primary" id="save-edit-comment-btn">Save</button>
        `);
        setTimeout(() => {
            this._quill = _initQuill('#edit-comment-editor', { html });
            const len = this._quill.getLength();
            this._quill.setSelection(len, 0);
            this._quill.focus();
            $('#save-edit-comment-btn').off('click').on('click', () => {
                const content = _quillToMarkdown(this._quill);
                if (content) {
                    this.api('update_comment', { id: commentId, content }, 'POST').done(() => { this.closeModal(); setTimeout(() => this.openCard(cardId), 100); });
                }
            });
        }, 50);
    },

    deleteComment(commentId, cardId) {
        this.confirmAction('Delete this comment?', () => {
            this.api('delete_comment', { id: commentId }, 'POST').done(() => { this.refreshBoard(); setTimeout(() => this.openCard(cardId), 200); });
        });
    },

    // ATTACHMENTS
    uploadFiles(cardId, files) {
        if (!files.length) return;
        const uploads = Array.from(files).map(file => {
            const fd = new FormData(); fd.append('file', file); fd.append('card_id', cardId);
            return this.api('upload_attachment', fd, 'POST');
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
            this.api('get_notification_settings'),
            this.api('list_api_tokens')
        ).done((statusRes, notifRes, tokensRes) => {
            const status = statusRes[0] || statusRes;
            const notif = notifRes[0] || notifRes;
            const tokens = tokensRes[0] || tokensRes;
            this.user = status.user;
            const tokenRows = tokens.length ? tokens.map(t => `
                <div class="api-token-row">
                    <div>
                        <strong>${this.esc(t.name)}</strong>
                        <span class="text-muted text-sm ml-2">Created ${t.created_at}${t.last_used_at ? ' · Last used ' + t.last_used_at : ''}</span>
                    </div>
                    <button type="button" class="btn btn-sm btn-danger" onclick="App.revokeApiToken(${t.id},'${this.escAttr(t.name)}')">Revoke</button>
                </div>
            `).join('') : '<p class="text-muted">No API tokens yet.</p>';
            this.openModal('My Account', `
                <form onsubmit="event.preventDefault();App.saveAccount()">
                <div class="settings-tabs">
                    <button type="button" class="settings-tab active" onclick="App.switchAccountTab('general')">General</button>
                    <button type="button" class="settings-tab" onclick="App.switchAccountTab('notifications')">Notifications</button>
                    <button type="button" class="settings-tab" onclick="App.switchAccountTab('tokens')">API Tokens</button>
                </div>
                    <div id="atab-general" class="settings-tab-content">
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
                        <div class="card-detail-section mt-4"><h4>Recovery Key</h4>
                            <p class="text-sm text-light mb-2">Generate a new recovery key (invalidates the current one).</p>
                            <button type="button" class="btn btn-sm" onclick="App.regenerateRecoveryKey()">Regenerate Recovery Key</button>
                        </div>
                    </div>
                    <div id="atab-notifications" class="settings-tab-content hidden">
                        <div class="card-detail-section"><h4>Notification Preferences</h4>
                            <div class="form-group"><label>Notification Email</label>
                                <input type="email" id="notif-email" value="${this.esc(notif.email || '')}" placeholder="Leave blank to use account email">
                            </div>
                            <div class="form-group"><label>Delivery</label>
                                <select id="notif-delivery" class="select-full">
                                    <option value="immediate" ${notif.delivery === 'immediate' ? 'selected' : ''}>Immediate</option>
                                    <option value="daily" ${notif.delivery === 'daily' ? 'selected' : ''}>Daily summary</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div id="atab-tokens" class="settings-tab-content hidden">
                        <div class="card-detail-section"><h4>API Tokens</h4>
                            <p class="text-muted text-sm mb-3">Tokens allow external tools and agents to access the API on your behalf.</p>
                            <div id="new-token-form" class="form-group form-row">
                                <input type="text" id="new-token-name" placeholder="Token name (e.g. Claude Code)">
                                <button type="button" class="btn btn-primary btn-sm" onclick="App.createApiToken()">Generate</button>
                            </div>
                            <div id="new-token-display" class="hidden form-group form-row">
                                <input type="text" id="new-token-value" readonly class="token-input">
                                <button type="button" class="btn btn-sm btn-icon" onclick="App.copyApiToken()" title="Copy token">
                                    <svg id="copy-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                    <svg id="check-icon" class="hidden" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--success)" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                                </button>
                                <button type="button" class="btn btn-sm btn-icon" onclick="App.dismissApiToken()" title="Dismiss">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                </button>
                            </div>
                            <div id="api-tokens-list">${tokenRows}</div>
                        </div>
                    </div>
                </form>
            `, `<button class="btn btn-primary" onclick="App.saveAccount()">Save</button>`);
            setTimeout(() => $('#account-name').focus(), 50);
        });
    },

    saveAccount() {
        const name = $('#account-name').val().trim();
        const email = $('#account-email').val().trim();
        const password = $('#account-new-pw').val();
        const currentPassword = $('#account-current-pw').val();

        if (!name || !email) {
            this.toast('Name and email are required.');
            return;
        }
        if (password && !currentPassword) {
            this.toast('Current password is required to set a new one.');
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
            this.toast('Saved!', 'success');
        }).fail(xhr => {
            this.toast(xhr.responseJSON?.error || 'Failed to update');
        });
    },

    switchAccountTab(tab) {
        const tabs = ['general', 'notifications', 'tokens'];
        const idx = tabs.indexOf(tab);
        $('.settings-tab').removeClass('active').eq(idx).addClass('active');
        $('.settings-tab-content').addClass('hidden');
        $(`#atab-${tab}`).removeClass('hidden');
    },

    createApiToken() {
        const name = $('#new-token-name').val().trim();
        if (!name) { $('#new-token-name').focus(); return; }
        this.api('create_api_token', { name }, 'POST').done(res => {
            $('#new-token-name').val('');
            $('#new-token-value').val(res.token);
            $('#new-token-form').addClass('hidden');
            $('#new-token-display').removeClass('hidden');
            this._refreshTokenList();
        }).fail(xhr => {
            this.toast(xhr.responseJSON?.error || 'Failed to create token');
        });
    },

    copyApiToken() {
        const input = document.getElementById('new-token-value');
        input.select();
        navigator.clipboard.writeText(input.value).then(() => {
            $('#copy-icon').addClass('hidden');
            $('#check-icon').removeClass('hidden');
            setTimeout(() => { $('#check-icon').addClass('hidden'); $('#copy-icon').removeClass('hidden'); }, 2000);
        });
    },

    dismissApiToken() {
        $('#new-token-display').addClass('hidden');
        $('#new-token-form').removeClass('hidden');
    },

    revokeApiToken(id, name) {
        this.confirmAction(`Revoke token "${this.esc(name)}"? Any tool using it will lose access immediately.`, () => {
            this.api('revoke_api_token', { id: parseInt(id) }, 'POST').done(() => {
                this.showAccount();
                setTimeout(() => this.switchAccountTab('tokens'), 100);
            });
        });
    },

    // TIME TRACKING
    toggleTimeEntries(cardId) {
        const section = $(`#time-section-${cardId}`);
        if (section.hasClass('hidden')) {
            section.removeClass('hidden');
            $('#time-start, #time-end').off('change').on('change', () => this.calcTimeFromStartEnd());
            this.loadTimeEntries(cardId);
        } else {
            section.addClass('hidden');
        }
    },

    loadTimeEntries(cardId) {
        this.api('list_time_entries', { card_id: cardId }).done(entries => {
            const total = entries.reduce((s, e) => s + Number(e.minutes), 0);
            $(`#time-total-${cardId}`).text(this.formatMinutes(total));
            if (!entries.length) {
                $(`#time-entries-${cardId}`).html('<p class="text-muted text-sm">No time entries yet.</p>');
                return;
            }
            const rows = entries.map(e => {
                const inProgress = e.start_time && !e.end_time;
                const timeCol = inProgress
                    ? `<span class="text-muted">${e.start_time} → ?</span>`
                    : (e.start_time ? `${e.start_time}→${e.end_time} (${this.formatMinutes(e.minutes)})` : `<strong>${this.formatMinutes(e.minutes)}</strong>`);
                const actions = inProgress
                    ? `<button type="button" class="btn btn-primary btn-xs" onclick="App.endTimeEntry(${e.id},${cardId})" title="End now">End</button> `
                    : '';
                return `<tr${inProgress ? ' class="in-progress"' : ''}>
                    <td>${timeCol}</td>
                    <td>${e.worked_at}</td>
                    <td>${this.esc(e.note)}</td>
                    <td>${this.esc(e.author_name)}</td>
                    <td>${actions}<button type="button" class="btn-icon btn-sm btn-icon-danger" onclick="App.confirmDeleteTimeEntry(${e.id},${cardId})" title="Delete">&times;</button></td>
                </tr>`;
            }).join('');
            $(`#time-entries-${cardId}`).html(`
                <table class="time-entries-table">
                    <thead><tr><th>Time</th><th>Date</th><th>Note</th><th>By</th><th></th></tr></thead>
                    <tbody>${rows}</tbody>
                </table>
            `);
        });
    },

    setTimeNow(inputId) {
        const now = new Date();
        const hh = String(now.getHours()).padStart(2, '0');
        const mm = String(now.getMinutes()).padStart(2, '0');
        $(`#${inputId}`).val(`${hh}:${mm}`).trigger('change');
    },

    calcTimeFromStartEnd() {
        const start = $('#time-start').val();
        const end = $('#time-end').val();
        if (start && end) {
            const [sh, sm] = start.split(':').map(Number);
            const [eh, em] = end.split(':').map(Number);
            const mins = (eh * 60 + em) - (sh * 60 + sm);
            if (mins > 0) {
                const h = Math.floor(mins / 60);
                const m = mins % 60;
                $('#time-input').val((h ? h + 'h' : '') + (m ? m + 'm' : ''));
            } else {
                $('#time-input').val('');
            }
        }
    },

    addTimeEntry(cardId) {
        const startTime = $('#time-start').val().trim();
        const endTime = $('#time-end').val().trim();
        const raw = $('#time-input').val().trim();
        if (!startTime && !raw) { $('#time-start').focus(); return; }
        if (raw && !startTime) {
            const minutes = this.parseTime(raw);
            if (!minutes) { $('#time-input').focus(); return; }
        }
        const workedAt = $('#time-date').val();
        const note = $('#time-note').val().trim();
        const data = { card_id: cardId, worked_at: workedAt, note };
        if (startTime) data.start_time = startTime;
        if (endTime) data.end_time = endTime;
        if (!startTime || !endTime) {
            const minutes = this.parseTime(raw);
            if (minutes) data.minutes = minutes;
        }
        this.api('create_time_entry', data, 'POST').done(() => {
            $('#time-start').val('');
            $('#time-end').val('');
            $('#time-input').val('');
            $('#time-note').val('');
            $(`#time-section-${cardId}`).addClass('hidden');
            this.toast('Time entry saved.', 'success');
            this.loadTimeEntries(cardId);
            this.api('list_time_entries', { card_id: cardId }).done(entries => {
                const total = entries.reduce((s, e) => s + Number(e.minutes), 0);
                $(`#time-total-${cardId}`).text(this.formatMinutes(total));
            });
        });
    },

    endTimeEntry(id, cardId) {
        const now = new Date();
        const hh = String(now.getHours()).padStart(2, '0');
        const mm = String(now.getMinutes()).padStart(2, '0');
        this.api('update_time_entry', { id, end_time: `${hh}:${mm}` }, 'POST').done(() => {
            this.toast('Timer ended.', 'success');
            this.loadTimeEntries(cardId);
            this.api('list_time_entries', { card_id: cardId }).done(entries => {
                const total = entries.reduce((s, e) => s + Number(e.minutes), 0);
                $(`#time-total-${cardId}`).text(this.formatMinutes(total));
            });
        });
    },

    confirmDeleteTimeEntry(id, cardId) {
        this.openModal('Confirm', `<p class="text-lg">Delete this time entry?</p>`, `
            <button class="btn btn-ghost" onclick="App.openCard(${cardId})">Cancel</button>
            <button class="btn btn-danger" id="confirm-action-btn">Confirm</button>
        `);
        setTimeout(() => $('#confirm-action-btn').off('click').on('click', () => {
            this.api('delete_time_entry', { id }, 'POST').done(() => {
                this.refreshBoard();
                setTimeout(() => App.openCard(cardId), 200);
            });
        }), 50);
    },

    parseTime(str) {
        str = str.trim().toLowerCase();
        let total = 0;
        const hMatch = str.match(/(\d+(?:\.\d+)?)\s*h/);
        const mMatch = str.match(/(\d+)\s*m/);
        if (hMatch) total += Math.round(parseFloat(hMatch[1]) * 60);
        if (mMatch) total += parseInt(mMatch[1]);
        if (!hMatch && !mMatch && /^\d+$/.test(str)) total = parseInt(str);
        return total > 0 ? total : 0;
    },

    showTimeReport(period) {
        if (!this.currentProject) return;
        const pid = this.currentProject.id;
        period = period || 'month';
        const now = new Date();
        let from = '', to = this.toLocalDateStr(now);
        if (period === 'today') { from = to; }
        else if (period === 'week') {
            const weekStart = this.currentProject.week_start_day ?? 1;
            const start = new Date(now);
            const diff = (start.getDay() - weekStart + 7) % 7;
            start.setDate(start.getDate() - diff);
            const end = new Date(start);
            end.setDate(end.getDate() + 6);
            from = this.toLocalDateStr(start);
            to = this.toLocalDateStr(end);
        }
        else if (period === 'month') {
            const resetDay = this.currentProject.cycle_reset_day ?? 1;
            if (resetDay <= 1) {
                from = `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}-01`;
            } else {
                const today = now.getDate();
                let cycleStart;
                if (today >= resetDay) {
                    cycleStart = new Date(now.getFullYear(), now.getMonth(), resetDay);
                } else {
                    cycleStart = new Date(now.getFullYear(), now.getMonth() - 1, resetDay);
                }
                const nextReset = new Date(cycleStart);
                nextReset.setMonth(nextReset.getMonth() + 1);
                const cycleEnd = new Date(nextReset);
                cycleEnd.setDate(cycleEnd.getDate() - 1);
                from = this.toLocalDateStr(cycleStart);
                to = this.toLocalDateStr(cycleEnd);
            }
        }
        else if (period === 'year') { from = `${now.getFullYear()}-01-01`; }
        else if (period === 'custom') { from = $('#report-from').val(); to = $('#report-to').val(); }
        let periodLabel = '';
        if (period === 'today') {
            periodLabel = this.formatDate(to);
        } else if (period === 'week' || period === 'month') {
            periodLabel = this.formatDate(from) + ' \u2013 ' + this.formatDate(to);
        } else if (period === 'year') {
            periodLabel = now.getFullYear().toString();
        } else if (period === 'custom' && from && to) {
            periodLabel = this.formatDate(from) + ' \u2013 ' + this.formatDate(to);
        }
        const params = { project_id: pid };
        if (from) params.from = from;
        if (to) params.to = to;
        this.api('time_report', params).done(data => {
            const groupBy = this._reportGroupBy || 'card';
            let rows = data.entries || [];
            let tableHtml = '';
            if (groupBy === 'card') {
                const grouped = {};
                rows.forEach(e => { const k = e.card_title || 'Deleted card'; if (!grouped[k]) grouped[k] = { minutes: 0, entries: [] }; grouped[k].minutes += e.minutes; grouped[k].entries.push(e); });
                tableHtml = Object.entries(grouped).map(([title, g]) => `<tr><td>${this.esc(title)}</td><td>${this.formatMinutes(g.minutes)}</td><td>${g.entries.length}</td></tr>`).join('');
            } else if (groupBy === 'user') {
                const grouped = {};
                rows.forEach(e => { const k = e.author_name || 'Unknown'; if (!grouped[k]) grouped[k] = 0; grouped[k] += e.minutes; });
                tableHtml = Object.entries(grouped).map(([name, m]) => `<tr><td>${this.esc(name)}</td><td>${this.formatMinutes(m)}</td><td></td></tr>`).join('');
            } else {
                const grouped = {};
                rows.forEach(e => { if (!grouped[e.worked_at]) grouped[e.worked_at] = 0; grouped[e.worked_at] += e.minutes; });
                tableHtml = Object.entries(grouped).sort((a,b) => b[0].localeCompare(a[0])).map(([date, m]) => `<tr><td>${this.formatDate(date)}</td><td>${this.formatMinutes(m)}</td><td></td></tr>`).join('');
            }
            const content = `
                <div class="report-controls">
                    <div class="report-periods">
                        <button class="btn btn-sm ${period==='today'?'btn-primary':'btn-ghost'}" onclick="App.showTimeReport('today')">Today</button>
                        <button class="btn btn-sm ${period==='week'?'btn-primary':'btn-ghost'}" onclick="App.showTimeReport('week')">This Week</button>
                        <button class="btn btn-sm ${period==='month'?'btn-primary':'btn-ghost'}" onclick="App.showTimeReport('month')">This Month</button>
                        <button class="btn btn-sm ${period==='year'?'btn-primary':'btn-ghost'}" onclick="App.showTimeReport('year')">This Year</button>
                    </div>
                    <div class="report-group">
                        <span class="text-sm text-muted">Group by:</span>
                        <button class="btn btn-sm ${groupBy==='card'?'btn-primary':'btn-ghost'}" onclick="App._reportGroupBy='card';App.showTimeReport('${period}')">Card</button>
                        <button class="btn btn-sm ${groupBy==='user'?'btn-primary':'btn-ghost'}" onclick="App._reportGroupBy='user';App.showTimeReport('${period}')">User</button>
                        <button class="btn btn-sm ${groupBy==='date'?'btn-primary':'btn-ghost'}" onclick="App._reportGroupBy='date';App.showTimeReport('${period}')">Date</button>
                    </div>
                </div>
                ${periodLabel ? `<div class="report-period-label text-sm text-muted mb-3">${periodLabel}</div>` : ''}
                <div class="report-summary">
                    <div class="report-stat"><strong>${this.formatMinutes(data.total_minutes)}</strong><span>Total</span></div>
                    <div class="report-stat"><strong>${data.entry_count}</strong><span>Entries</span></div>
                    <div class="report-stat"><strong>${data.avg_per_day ? this.formatMinutes(data.avg_per_day) : '0m'}</strong><span>Avg/day</span></div>
                </div>
                <table class="report-table">
                    <thead><tr><th>${groupBy === 'card' ? 'Card' : groupBy === 'user' ? 'User' : 'Date'}</th><th>Hours</th><th>${groupBy === 'card' ? 'Entries' : ''}</th></tr></thead>
                    <tbody>${tableHtml || '<tr><td colspan="3" class="text-muted">No time entries for this period.</td></tr>'}</tbody>
                </table>
            `;
            const footer = `<a class="btn btn-outline-primary" href="?action=time_report&project_id=${pid}&from=${from}&to=${to}&format=csv" target="_blank">Export CSV</a>`;
            this.openModal('Time Report — ' + this.esc(this.currentProject.name), content, footer);
            $('.modal').addClass('modal-wide');
        });
    },

    _refreshTokenList() {
        this.api('list_api_tokens').done(tokens => {
            const rows = tokens.map(t => `
                <div class="api-token-row">
                    <div><strong>${this.esc(t.name)}</strong>
                        <span class="text-muted text-sm ml-2">Created ${t.created_at}${t.last_used_at ? ' · Last used ' + t.last_used_at : ''}</span>
                    </div>
                    <button type="button" class="btn btn-sm btn-danger" onclick="App.revokeApiToken(${t.id},'${this.escAttr(t.name)}')">Revoke</button>
                </div>
            `).join('');
            $('#api-tokens-list').html(rows || '<p class="text-muted">No API tokens yet.</p>');
        });
    },

    // TEAM (admin only)
    showTeam() {
        this.api('team_list').done(users => {
            const rows = users.map(u => {
                const isSelf = u.id === this.user.id;
                const roleBadge = u.role === 'admin'
                    ? '<span class="badge-admin">Admin</span>'
                    : '<span class="badge-member">Member</span>';
                const actions = isSelf ? '<span class="text-sm text-light">You</span>' : `
                    <button class="btn btn-ghost btn-sm" onclick="App.teamChangeRole(${u.id},'${u.role}')">${u.role === 'admin' ? 'Demote' : 'Promote'}</button>
                    <button class="btn btn-ghost btn-sm" onclick="App.teamResetPw(${u.id})">Reset Pwd</button>
                    <button class="btn btn-danger btn-sm" onclick="App.teamRemove(${u.id},'${this.escAttr(u.name)}')">Remove</button>
                `;
                return `<div class="row-card">
                    <div>
                        <strong class="text-base">${this.esc(u.name)}</strong> ${roleBadge}
                        <div class="text-sm text-light">${this.esc(u.email)}</div>
                    </div>
                    <div class="flex-center gap-sm">${actions}</div>
                </div>`;
            }).join('');

            this.openModal('Team', `
                <div class="settings-tabs">
                    <button class="settings-tab active" onclick="App.switchTeamTab('members')">Members</button>
                    <button class="settings-tab" onclick="App.switchTeamTab('add')">Add</button>
                </div>
                <div id="ttab-members" class="settings-tab-content">
                    <div class="card-detail-section">${rows}</div>
                </div>
                <div id="ttab-add" class="settings-tab-content hidden">
                    <div class="card-detail-section"><h4>Add Team Member</h4>
                        <div class="form-group"><label>Name</label><input type="text" id="team-add-name" placeholder="Name"></div>
                        <div class="form-group"><label>Email</label><input type="text" id="team-add-email" placeholder="email@example.com"></div>
                        <div class="form-group"><label>Password</label><input type="password" id="team-add-pw" placeholder="Initial password (min 6 chars)" autocomplete="new-password"></div>
                        <div class="form-group"><label>Role</label>
                            <select id="team-add-role"><option value="member">Member</option><option value="admin">Admin</option></select>
                        </div>
                        <button class="btn btn-primary" onclick="App.teamAdd()">Add Member</button>
                    </div>
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
            this.toast('All fields are required.');
            return;
        }
        this.api('team_add', { name, email, password, role }, 'POST').done(() => {
            this.showTeam();
            this.toast('Member added.', 'success');
        }).fail(xhr => {
            this.toast(xhr.responseJSON?.error || 'Failed to add member');
        });
    },

    switchTeamTab(tab) {
        const tabs = ['members', 'add'];
        const idx = tabs.indexOf(tab);
        $('.settings-tab').removeClass('active').eq(idx).addClass('active');
        $('.settings-tab-content').addClass('hidden');
        $(`#ttab-${tab}`).removeClass('hidden');
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
        `, `
            <button class="btn btn-ghost" onclick="App.showTeam()">Cancel</button>
            <button class="btn btn-primary" onclick="App.teamDoResetPw(${id})">Reset</button>
        `);
        setTimeout(() => $('#team-reset-pw').focus(), 100);
    },

    teamDoResetPw(id) {
        const pw = $('#team-reset-pw').val();
        if (!pw || pw.length < 6) {
            this.toast('Password must be at least 6 characters.');
            return;
        }
        this.api('team_reset_password', { id, password: pw }, 'POST').done(() => {
            this.showTeam();
            this.toast('Password reset.', 'success');
        }).fail(xhr => { this.toast(xhr.responseJSON?.error || 'Failed'); });
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
                <div class="settings-tabs">
                    <button class="settings-tab active" onclick="App.switchSettingsTab('general')">General</button>
                    <button class="settings-tab" onclick="App.switchSettingsTab('smtp')">SMTP</button>
                    <button class="settings-tab" onclick="App.switchSettingsTab('cron')">Cron</button>
                    <button class="settings-tab" onclick="App.switchSettingsTab('updates')">Updates</button>
                </div>
                <div id="tab-general" class="settings-tab-content">
                    <div class="card-detail-section"><h4>App Name</h4>
                        <div class="field-addons">
                            <input type="text" id="app-name" placeholder="App name" value="${this.esc(this.appName)}">
                            <button class="btn btn-primary" onclick="App.setAppName()">Save</button>
                        </div>
                    </div>
                    <div class="card-detail-section"><h4>Locale</h4>
                        <select id="app-locale" class="select-sm" onchange="App.setLocale(this.value)">
                            ${[['en','English'],['pt-BR','Português (BR)'],['de','Deutsch'],['fr','Français'],['es','Español'],['it','Italiano'],['ja','日本語']].map(([v,l]) => `<option value="${v}" ${this.locale===v?'selected':''}>${l}</option>`).join('')}
                        </select>
                        <span class="text-xs text-muted">Used for date formatting in reports.</span>
                    </div>
                </div>
                <div id="tab-smtp" class="settings-tab-content hidden">
                    <div class="card-detail-section"><h4>SMTP (Email Notifications)</h4>
                        <div class="grid-2">
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
                            <select id="smtp-encryption" class="select-full">
                                <option value="tls" ${smtp.smtp_encryption === 'tls' ? 'selected' : ''}>TLS (STARTTLS)</option>
                                <option value="ssl" ${smtp.smtp_encryption === 'ssl' ? 'selected' : ''}>SSL</option>
                                <option value="none" ${smtp.smtp_encryption === 'none' ? 'selected' : ''}>None</option>
                            </select>
                        </div>
                        <div class="flex-center gap-2 mt-2">
                            <button class="btn btn-primary" onclick="App.saveSmtp()">Save SMTP</button>
                            <button class="btn btn-ghost" onclick="App.testSmtp()">Send Test Email</button>
                        </div>
                    </div>
                </div>
                <div id="tab-cron" class="settings-tab-content hidden">
                    <div class="card-detail-section"><h4>Cron Token</h4>
                        <p class="text-sm text-light mb-2">Use this token to authenticate the daily digest cron job without a session.</p>
                        <div class="field-addons">
                            <input type="text" id="cron-token" value="${this.esc(smtp.cron_token || '')}" readonly class="mono text-sm">
                            <button class="btn btn-ghost" onclick="App.generateCronToken()">Generate</button>
                        </div>
                        <p class="text-xs text-light mt-2">Use with: <code>?action=send_notifications</code> and <code>?action=send_digest</code></p>
                        ${smtp.last_digest_at ? `<p class="text-xs text-light mt-2">Last digest run: <strong>${smtp.last_digest_at}</strong></p>` : '<p class="text-xs text-light mt-2">Digest has not run yet.</p>'}
                    </div>
                </div>
                <div id="tab-updates" class="settings-tab-content hidden">
                    <div class="card-detail-section"><h4>Updates</h4>
                        <p class="text-sm mb-2">Current version: <strong>#${status.version || '<?= APP_VERSION ?>'}</strong></p>
                        <div id="update-info">
                            <p class="text-sm text-light">Click "Check now" to see if a newer version is available.</p>
                        </div>
                        <div class="flex-center gap-2 mt-2">
                            <button class="btn btn-ghost" onclick="App.checkUpdate()">Check now</button>
                            <button class="btn btn-primary hidden" id="apply-update-btn" onclick="App.applyUpdate()">Apply update</button>
                        </div>
                    </div>
                </div>
            `, '');
            setTimeout(() => $('#app-name').focus(), 50);
        });
    },

    switchSettingsTab(tab) {
        const tabs = ['general', 'smtp', 'cron', 'updates'];
        const idx = tabs.indexOf(tab);
        $('.settings-tab').removeClass('active').eq(idx).addClass('active');
        $('.settings-tab-content').addClass('hidden');
        $(`#tab-${tab}`).removeClass('hidden');
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
            this.toast('SMTP settings saved.', 'success');
        }).fail(xhr => {
            this.toast(xhr.responseJSON?.error || 'Failed');
        });
    },

    testSmtp() {
        const to = prompt('Send test email to:');
        if (!to) return;
        this.api('test_smtp', { to }, 'POST').done(() => {
            this.toast('Test email sent!', 'success');
        }).fail(xhr => {
            this.toast(xhr.responseJSON?.error || 'Failed to send');
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

    setLocale(locale) {
        this.api('auth_set_locale', { locale }, 'POST').done(() => {
            this.locale = locale;
            this.toast('Locale saved.', 'success');
        }).fail(xhr => {
            this.toast(xhr.responseJSON?.error || 'Failed');
        });
    },

    formatDate(str) {
        if (!str) return '';
        try {
            const d = new Date(str + 'T00:00:00');
            return new Intl.DateTimeFormat(this.locale || 'en', { dateStyle: 'medium' }).format(d);
        } catch {
            return str;
        }
    },

    toLocalDateStr(d) {
        return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
    },

    checkUpdate() {
        const $info = $('#update-info');
        $info.html('<p class="text-sm text-light">Checking...</p>');
        $('#apply-update-btn').addClass('hidden');
        this.api('check_update').done((res) => {
            if (res.update_available) {
                $info.html(`<p class="text-sm">A new version is available: <strong>#${res.latest_version}</strong></p>
                    <p class="text-xs text-light mt-1">Last checked: ${res.last_checked}</p>`);
                $('#apply-update-btn').removeClass('hidden');
            } else {
                $info.html(`<p class="text-sm">You are up to date (<strong>#${res.current_version}</strong>)</p>
                    <p class="text-xs text-light mt-1">Last checked: ${res.last_checked}</p>`);
            }
            this.updateAvailable = res.update_available;
            this._updateSettingsBadge();
        }).fail(() => {
            $info.html('<p class="text-sm" style="color:var(--danger)">Failed to check for updates.</p>');
        });
    },

    applyUpdate() {
        if (!confirm('This will replace index.php with the latest version. A backup will be created. Continue?')) return;
        const $btn = $('#apply-update-btn');
        $btn.prop('disabled', true).text('Updating...');
        this.api('apply_update', {}, 'POST').done((res) => {
            $('#update-info').html(`<p class="text-sm" style="color:var(--success)">Updated from #${res.previous_version} to #${res.new_version}. Reload to use the new version.</p>`);
            $btn.addClass('hidden');
            this.updateAvailable = false;
            this._updateSettingsBadge();
            setTimeout(() => { if (confirm('Reload now?')) location.reload(); }, 1500);
        }).fail((xhr) => {
            const msg = xhr.responseJSON?.error || 'Update failed';
            $('#update-info').html(`<p class="text-sm" style="color:var(--danger)">${msg}</p>`);
            $btn.prop('disabled', false).text('Apply update');
        });
    },

    _updateSettingsBadge() {
        // Re-render user menu to reflect badge change
        if (!this.isGuest && this.user) {
            const $actions = $('#navbar-actions');
            if ($actions.find('.dropdown').length) {
                $actions.html(this.renderUserMenu());
            }
        }
    },

    _showUpdateToast() {
        if (!this.updateAvailable || this.isGuest || this.user?.role !== 'admin') return;
        if (sessionStorage.getItem('update_toast_dismissed')) return;
        const $toast = $(`<div id="update-toast" style="position:fixed;bottom:20px;right:20px;background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:12px 16px;box-shadow:var(--shadow-lg);z-index:10000;display:flex;align-items:center;gap:10px;max-width:360px;font-size:13px">
            <span style="flex:1">A new version of Tasssks is available (<strong>#${this.appVersion}</strong> → latest).</span>
            <button class="btn btn-ghost btn-sm" onclick="App.showAppSettings();$('#update-toast').remove()">Update</button>
            <button class="btn btn-ghost btn-sm" onclick="sessionStorage.setItem('update_toast_dismissed','1');$('#update-toast').remove()" style="font-size:16px;line-height:1;padding:0 4px">&times;</button>
        </div>`);
        $('body').append($toast);
        setTimeout(() => $toast.fadeOut(300, () => $toast.remove()), 10000);
    },

    // PROJECT SETTINGS (owner/admin only)
    showSettings(tab) {
        if (!this.currentProject) return;
        tab = tab || this._settingsTab || 'general';
        const pid = this.currentProject.id;
        $.when(
            this.api('list_guests', { project_id: pid }),
            this.api('list_webhooks', { project_id: pid })
        ).done((guestsRes, webhooksRes) => {
            const guests = guestsRes[0] || guestsRes;
            const webhooks = webhooksRes[0] || webhooksRes;
            const guestRows = guests.map(g => {
                const link = `${location.origin}${location.pathname}?guest=${g.token}`;
                return `<div class="row-card">
                    <div>
                        <strong class="text-base">${this.esc(g.name)}</strong>
                        <div class="text-xs text-light">${g.can_comment ? 'Can comment' : 'View only'}${g.email ? ' · ' + this.esc(g.email) : ' · no email'}</div>
                    </div>
                    <div class="flex-center gap-sm">
                        <button class="btn btn-ghost btn-sm" onclick="App.editGuest(${g.id},'${this.esc(g.name).replace(/'/g,"\\'")}','${this.esc(g.email || '').replace(/'/g,"\\'")}')">Edit</button>
                        <button class="btn btn-ghost btn-sm" onclick="navigator.clipboard.writeText('${link}');this.textContent='Copied!'">Copy Link</button>
                        <button class="btn btn-danger btn-sm" onclick="App.deleteGuest(${g.id})">Remove</button>
                    </div>
                </div>`;
            }).join('') || '<p class="text-no-comments">No guests yet.</p>';

            const webhookRows = webhooks.map(w => {
                const display = w.type === 'telegram' ? `Chat: ${this.esc(w.chat_id || '')}` : this.esc(w.url);
                return `<div class="row-card">
                    <div class="overflow-hidden">
                        <strong class="text-sm text-uppercase text-light">${this.esc(w.type)}</strong>
                        <div class="text-sm text word-break">${display}</div>
                        ${w.message_template ? `<div class="text-xs text-light">Template: ${this.esc(w.message_template)}</div>` : ''}
                    </div>
                    <div class="flex-center gap-sm flex-shrink-0">
                        <button class="btn btn-ghost btn-sm" onclick="App.testWebhook(${w.id}, this)">Test</button>
                        <button class="btn btn-ghost btn-sm" onclick="App.editWebhook(${w.id})">Edit</button>
                        <button class="btn btn-ghost btn-sm" onclick="App.toggleWebhook(${w.id})">${w.enabled ? 'Disable' : 'Enable'}</button>
                        <button class="btn btn-danger btn-sm" onclick="App.deleteWebhook(${w.id})">Remove</button>
                    </div>
                </div>`;
            }).join('') || '<p class="text-no-comments">No webhooks configured.</p>';

            const guestCreate = this.currentProject.guest_can_create_cards ? 'checked' : '';
            const guestSort = this.currentProject.guest_can_sort_cards ? 'checked' : '';
            const guestViewTime = this.currentProject.guest_can_view_time ? 'checked' : '';
            this.openModal('Project Settings', `
                <div class="settings-tabs">
                    <button class="settings-tab active" onclick="App.switchProjectSettingsTab('general')">General</button>
                    <button class="settings-tab" onclick="App.switchProjectSettingsTab('webhooks')">Webhooks</button>
                    <button class="settings-tab" onclick="App.switchProjectSettingsTab('guests')">Guests</button>
                </div>
                <div id="ptab-general" class="settings-tab-content">
                    <div class="card-detail-section"><h4>Project Name</h4>
                        <div class="field-addons">
                            <input type="text" id="project-name" value="${this.esc(this.currentProject.name)}">
                            <button class="btn btn-primary" onclick="App.updateProjectName()">Save</button>
                        </div>
                    </div>
                    <div class="card-detail-section"><h4>Tags</h4>
                    <div class="card-tags mb-3">${this.tags.map(t => `
                        <span class="tag" style="background:${t.color}">${this.esc(t.name)}
                            <span class="tag-delete" onclick="App.deleteTag(${t.id})">&times;</span>
                        </span>
                    `).join('') || '<em class="text-no-desc">No tags</em>'}</div>
                    <div class="tag-picker">
                        <input type="text" id="settings-tag-name" placeholder="Tag name" class="tag-input">
                        ${['#ef4444','#f97316','#eab308','#22c55e','#3b82f6','#8b5cf6','#ec4899'].map(c => `<span class="tag-color-dot" style="background:${c}" onclick="App.selectTagColor(this,'${c}')"></span>`).join('')}
                        <input type="hidden" id="settings-tag-color" value="#3b82f6">
                        <button class="btn btn-primary btn-sm btn-shrink-0" onclick="App.createTagFromSettings()">Add</button>
                    </div>
                    </div>
                    <div class="card-detail-section"><h4>Reporting</h4>
                        <div class="grid-2">
                            <div class="form-group">
                                <label class="text-sm text-light">First Day of Week</label>
                                <select id="week-start-day" class="select-sm" onchange="App.updateProjectSetting('week_start_day', parseInt(this.value))">
                                    ${['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'].map((d,i) => `<option value="${i}" ${(this.currentProject.week_start_day ?? 1) == i ? 'selected' : ''}>${d}</option>`).join('')}
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="text-sm text-light">Cycle Reset Day</label>
                                <select id="cycle-reset-day" class="select-sm" onchange="App.updateProjectSetting('cycle_reset_day', parseInt(this.value))">
                                    ${Array.from({length:31},(_,i)=>i+1).map(d => `<option value="${d}" ${(this.currentProject.cycle_reset_day ?? 1) == d ? 'selected' : ''}>${d}</option>`).join('')}
                                </select>
                            </div>
                        </div>
                        <span class="text-xs text-muted">Billing cycle resets on the selected day of each month (e.g. 25th → 24th next month).</span>
                    </div>
                </div>
                <div id="ptab-webhooks" class="settings-tab-content hidden">
                    <div class="card-detail-section"><h4>Webhooks <a href="#" onclick="event.preventDefault();App.showWebhookPayloads()" class="text-xs font-normal ml-2">View payload format</a></h4>
                        <p class="text-sm text-light mb-2">Receive notifications via Slack, Telegram, or any HTTP endpoint.</p>
                        ${webhookRows}
                        <div id="webhook-form" class="mt-2" style="padding:12px;background:var(--bg);border-radius:var(--radius)">
                            <input type="hidden" id="webhook-edit-id">
                            <div class="form-group">
                                <label class="text-sm text-light">Type</label>
                                <select id="webhook-type" class="select-sm" onchange="App.togglePresetFields()">
                                    <option value="generic">Generic JSON</option>
                                    <option value="slack">Slack</option>
                                    <option value="telegram">Telegram</option>
                                </select>
                            </div>
                            <div class="form-group" id="webhook-url-group">
                                <label class="text-sm text-light">URL</label>
                                <input type="text" id="webhook-url" placeholder="https://example.com/webhook">
                                <div class="form-group-hint" id="slack-hint" style="display:none">Create a webhook at <a href="https://api.slack.com/apps" target="_blank" rel="noopener">api.slack.com/apps</a></div>
                            </div>
                            <div id="telegram-fields" class="hidden">
                                <div class="form-group">
                                    <label class="text-sm text-light">Bot Token</label>
                                    <input type="text" id="webhook-bot-token" placeholder="123456:ABC-DEF...">
                                    <div class="form-group-hint">Get a token from <a href="https://t.me/BotFather" target="_blank" rel="noopener">t.me/BotFather</a></div>
                                </div>
                                <div class="form-group">
                                    <label class="text-sm text-light">Chat ID</label>
                                    <input type="text" id="webhook-chat-id" placeholder="-1001234567890">
                                </div>
                            </div>
                            <div id="slack-template" class="hidden">
                                <div class="form-group">
                                    <label class="text-sm text-light">Message Template <span style="opacity:0.5">(optional)</span></label>
                                    <textarea id="webhook-slack-template" rows="2" placeholder="*{{event}}*: {{project}} — {{title}}"></textarea>
                                    <div class="form-group-hint">Supports: {{event}}, {{project}}, {{actor}}, {{title}}, {{timestamp}}</div>
                                </div>
                            </div>
                            <div id="telegram-template" class="hidden">
                                <div class="form-group">
                                    <label class="text-sm text-light">Message Template <span style="opacity:0.5">(optional)</span></label>
                                    <textarea id="webhook-telegram-template" rows="2" placeholder="&lt;b&gt;{{event}}&lt;/b&gt;: {{project}} — {{title}}"></textarea>
                                    <div class="form-group-hint">Supports: {{event}}, {{project}}, {{actor}}, {{title}}, {{timestamp}}</div>
                                </div>
                            </div>
                            <div class="flex-center gap-sm">
                                <button class="btn btn-primary btn-sm" onclick="App.saveWebhook()">Save</button>
                                <button class="btn btn-ghost btn-sm" onclick="App.cancelWebhook()">Cancel</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div id="ptab-guests" class="settings-tab-content hidden">
                    <div class="card-detail-section"><h4>Guest Permissions</h4>
                        <label class="flex-center gap-2 cursor-pointer mb-2 text-lg font-normal text">
                            <input type="checkbox" id="guest-create-cards" ${guestCreate} onchange="App.updateGuestPerm('guest_can_create_cards', this.checked)">
                            Allow guests to create cards in the first column
                        </label>
                        <label class="flex-center gap-2 cursor-pointer text-lg font-normal text">
                            <input type="checkbox" id="guest-sort-cards" ${guestSort} onchange="App.updateGuestPerm('guest_can_sort_cards', this.checked)">
                            Allow guests to sort cards in the first column
                        </label>
                        <label class="flex-center gap-2 cursor-pointer text-lg font-normal text">
                            <input type="checkbox" id="guest-view-time" ${guestViewTime} onchange="App.updateGuestPerm('guest_can_view_time', this.checked)">
                            Allow guests to view the time report
                        </label>
                    </div>
                    <div class="card-detail-section"><h4>Guest Access</h4>
                        ${guestRows}
                        <div class="tag-picker mt-3">
                            <input type="text" id="new-guest-name" placeholder="Guest name" class="tag-input">
                            <input type="email" id="new-guest-email" placeholder="Email (optional)" class="flex-1 min-w-sm">
                            <button class="btn btn-primary btn-sm" onclick="App.createGuest()">Add Guest</button>
                        </div>
                    </div>
                </div>
            `, '');
            this.switchProjectSettingsTab(tab);
        });
    },

    switchProjectSettingsTab(tab) {
        this._settingsTab = tab;
        const tabs = ['general', 'webhooks', 'guests'];
        const idx = tabs.indexOf(tab);
        $('.settings-tab').removeClass('active').eq(idx).addClass('active');
        $('.settings-tab-content').addClass('hidden');
        $(`#ptab-${tab}`).removeClass('hidden');
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

    updateProjectSetting(key, value) {
        this.api('update_project', { id: this.currentProject.id, [key]: value }, 'POST').done(() => {
            this.currentProject[key] = value;
        });
    },

    createGuest() {
        const name = $('#new-guest-name').val().trim();
        const email = $('#new-guest-email').val().trim();
        if (!name) return;
        this.api('create_guest', { project_id: this.currentProject.id, name, can_comment: 1, email }, 'POST').done(() => this.showSettings('guests'));
    },

    editGuest(id, name, email) {
        this.openModal('Edit Guest', `
            <div class="form-group"><label>Name</label>
                <input type="text" id="edit-guest-name" value="${this.esc(name)}">
            </div>
            <div class="form-group"><label>Email (optional)</label>
                <input type="email" id="edit-guest-email" value="${this.esc(email)}" placeholder="guest@example.com">
            </div>
        `, `<button class="btn btn-ghost" onclick="App.showSettings('guests')">Cancel</button> <button class="btn btn-primary" onclick="App.saveGuest(${id})">Save</button>`);
    },
    saveGuest(id) {
        const name = $('#edit-guest-name').val().trim();
        const email = $('#edit-guest-email').val().trim();
        if (!name) return;
        this.api('update_guest', { id, name, email }, 'POST').done(() => this.showSettings('guests'));
    },
    deleteGuest(id) { this.api('delete_guest', { id }, 'POST').done(() => this.showSettings('guests')); },

    togglePresetFields() {
        const type = $('#webhook-type').val();
        const isTelegram = type === 'telegram';
        const isSlack = type === 'slack';
        $('#webhook-url-group').toggleClass('hidden', isTelegram);
        $('#telegram-fields').toggleClass('hidden', !isTelegram);
        $('#slack-template').toggleClass('hidden', !isSlack);
        $('#telegram-template').toggleClass('hidden', !isTelegram);
        $('#slack-hint').toggle(isSlack);
        if (isSlack) $('#webhook-url').attr('placeholder', 'https://hooks.slack.com/services/T00000000/B00000000/XXXX');
        else if (isTelegram) { /* no URL needed */ }
        else $('#webhook-url').attr('placeholder', 'https://example.com/webhook');
    },

    cancelWebhook() {
        $('#webhook-edit-id').val('');
        $('#webhook-type').val('generic');
        $('#webhook-url').val('');
        $('#webhook-bot-token').val('');
        $('#webhook-chat-id').val('');
        $('#webhook-slack-template').val('');
        $('#webhook-telegram-template').val('');
        this.togglePresetFields();
    },

    saveWebhook() {
        const editId = $('#webhook-edit-id').val();
        const type = $('#webhook-type').val();
        const data = { project_id: this.currentProject.id, type };
        if (type === 'telegram') {
            data.bot_token = $('#webhook-bot-token').val().trim();
            data.chat_id = $('#webhook-chat-id').val().trim();
            data.message_template = $('#webhook-telegram-template').val().trim() || null;
            if (!data.bot_token || !data.chat_id) return;
        } else {
            data.url = $('#webhook-url').val().trim();
            if (!data.url) return;
            if (type === 'slack') {
                data.message_template = $('#webhook-slack-template').val().trim() || null;
            }
        }
        if (editId) {
            data.id = parseInt(editId);
            this.api('update_webhook', data, 'POST').done(() => this.showSettings('webhooks'));
        } else {
            this.api('create_webhook', data, 'POST').done(() => this.showSettings('webhooks'));
        }
    },

    editWebhook(id) {
        this.api('list_webhooks', { project_id: this.currentProject.id }).done(webhooks => {
            const w = (webhooks || []).find(h => h.id === id);
            if (!w) return;
            $('#webhook-edit-id').val(w.id);
            $('#webhook-type').val(w.type);
            this.togglePresetFields();
            if (w.type === 'telegram') {
                $('#webhook-bot-token').val(w.bot_token || '');
                $('#webhook-chat-id').val(w.chat_id || '');
                $('#webhook-telegram-template').val(w.message_template || '');
            } else {
                $('#webhook-url').val(w.url || '');
                if (w.type === 'slack') {
                    $('#webhook-slack-template').val(w.message_template || '');
                }
            }
        });
    },

    testWebhook(id, btn) {
        $(btn).prop('disabled', true).text('Sending...');
        this.api('test_webhook', { id }, 'POST').done(() => {
            $(btn).text('Sent!');
            setTimeout(() => $(btn).prop('disabled', false).text('Test'), 2000);
        }).fail(() => {
            $(btn).text('Failed');
            setTimeout(() => $(btn).prop('disabled', false).text('Test'), 2000);
        });
    },

    deleteWebhook(id) { this.api('delete_webhook', { id }, 'POST').done(() => this.showSettings('webhooks')); },
    toggleWebhook(id) { this.api('toggle_webhook', { id }, 'POST').done(() => this.showSettings('webhooks')); },

    showWebhookPayloads() {
        this.openModal('Webhook Payload Format', `
            <div class="mb-3"><button class="btn btn-ghost btn-sm" onclick="App.showSettings('webhooks')">&larr; Back to Settings</button></div>
            <div class="card-detail-section">
                <h4>Generic</h4>
                <p class="text-sm text-light mb-2">POST with <code>Content-Type: application/json</code></p>
                <pre class="code-block">{
  "event": "new_card",
  "project": "My Project",
  "actor": "John Doe",
  "payload": {
    "card_id": 42,
    "title": "Fix login bug"
  },
  "timestamp": "2026-08-13T10:30:00+00:00"
}</pre>
                <p class="text-xs text-light mt-2">Events: <code>new_card</code>, <code>new_comment</code>, <code>card_updated</code>, <code>new_project</code>, <code>password_changed</code></p>
            </div>
            <div class="card-detail-section">
                <h4>Slack</h4>
                <p class="text-sm text-light mb-2">POST to your Slack Incoming Webhook URL</p>
                <pre class="code-block">{
  "text": "John Doe created card \\"Fix login bug\\" in My Project"
}</pre>
            </div>
            <div class="card-detail-section">
                <h4>Telegram</h4>
                <p class="text-sm text-light mb-2">POST to <code>https://api.telegram.org/bot&lt;TOKEN&gt;/sendMessage</code></p>
                <pre class="code-block">{
  "text": "John Doe created card \\"Fix login bug\\" in My Project",
  "parse_mode": "HTML"
}</pre>
                <p class="text-xs text-light mt-2">Set the webhook URL to include <code>chat_id</code> as a query param, e.g.:<br><code>https://api.telegram.org/bot&lt;TOKEN&gt;/sendMessage?chat_id=&lt;CHAT_ID&gt;</code></p>
            </div>
        `, '');
    },

    showHelp() {
        const baseUrl = location.origin + location.pathname;
        this.openModal('Help', `
            <div class="card-detail-section">
                <h4>Scheduled Tasks (Cron)</h4>
                <p class="text-base text mb-2">Set up two cron jobs to handle email notifications:</p>
                <pre class="code-block"># Immediate notifications (every minute)
* * * * * curl -s "${baseUrl}?action=send_notifications&cron_token=YOUR_TOKEN" > /dev/null

# Daily digest (once a day at 8 AM)
0 8 * * * curl -s "${baseUrl}?action=send_digest&cron_token=YOUR_TOKEN" > /dev/null</pre>
                <p class="text-sm text-light mt-2">Generate a cron token in <strong>App Settings &gt; Cron Token</strong>. The token authenticates the request without a browser session.</p>
                <p class="text-sm text-light mt-2"><strong>send_notifications</strong> — sends individual emails to users with "Immediate" delivery preference.<br><strong>send_digest</strong> — sends a batched summary to users with "Daily summary" preference and to guest watchers.</p>
            </div>
            <div class="card-detail-section">
                <h4>Webhooks</h4>
                <p class="text-base text mb-2">Configure webhooks in <strong>Project Settings</strong> to receive real-time notifications for:</p>
                <ul class="text-base text pl-5 mb-0">
                    <li>New cards created</li>
                    <li>Comments posted</li>
                    <li>Cards updated</li>
                    <li>New projects created</li>
                </ul>
                <p class="text-sm text-light mt-2">Supported integrations: Slack (Incoming Webhooks), Telegram (Bot API), or any HTTP endpoint that accepts JSON POST requests.</p>
            </div>
            <div class="card-detail-section">
                <h4>Watching</h4>
                <p class="text-base text">Click <strong>Watch</strong> on a card to receive notifications when someone comments or updates it. You automatically watch cards you create or comment on.</p>
            </div>
            <div class="card-detail-section">
                <h4>Notification Preferences</h4>
                <p class="text-base text">Go to <strong>Account</strong> to configure your notification email and choose between immediate delivery or a daily summary.</p>
            </div>
            <div class="card-detail-section">
                <h4>Keyboard Shortcuts</h4>
                <table class="help-table">
                    <tr><td><kbd>⌘</kbd> <kbd>K</kbd></td><td>Search cards</td></tr>
                    <tr><td><kbd>⌘</kbd> <kbd>S</kbd></td><td>Save (in any form)</td></tr>
                    <tr><td><kbd>N</kbd></td><td>Add card to first column</td></tr>
                    <tr><td><kbd>W</kbd></td><td>Watch/unwatch card or project</td></tr>
                    <tr><td><kbd>⌘</kbd> <kbd>,</kbd></td><td>Project settings</td></tr>
                    <tr><td><kbd>A</kbd></td><td>Account</td></tr>
                    <tr><td><kbd>T</kbd></td><td>Team</td></tr>
                    <tr><td><kbd>⌘</kbd> <kbd>G</kbd></td><td>App settings</td></tr>
                    <tr><td><kbd>?</kbd></td><td>Show this help</td></tr>
                    <tr><td><kbd>1</kbd> – <kbd>9</kbd></td><td>Open project by index (on project list)</td></tr>
                    <tr><td><kbd>Backspace</kbd></td><td>Back to project list (from board)</td></tr>
                    <tr><td><kbd>Esc</kbd></td><td>Cancel / close modal / clear search</td></tr>
                </table>
                <p class="text-sm text-light mt-2">On Windows/Linux, use <kbd>Ctrl</kbd> instead of <kbd>⌘</kbd>.</p>
            </div>
            <div class="card-detail-section">
                <h4>Recovery Key</h4>
                <p class="text-base text">On first login, a recovery key is generated and shown once. Save it securely — it can replace your password if you forget it. After use, the key is rotated and you receive a new one. You can also regenerate it from <strong>Account &gt; Recovery Key</strong>.</p>
            </div>
            <div class="card-detail-section">
                <h4>Version</h4>
                <p class="text-base text-light">#<?= APP_VERSION ?></p>
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
            const watching = this.user && watchers.some(w => w.user_id == this.user.id);
            const count = watchers.length;
            this._projectWatching = watching;
            $('#project-watch-btn').html(watching
                ? `<svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3" fill="var(--surface)"/></svg> <span class="watch-label">Watching (${count})</span>`
                : `<svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg> <span class="watch-label">Watch (${count})</span>`);
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
            ? `<svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3" fill="var(--surface)"/></svg> <span class="watch-label">Watching (${count})</span>`
            : `<svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg> <span class="watch-label">Watch (${count})</span>`);
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
        if ($('#rk-saved-check').length && !$('#rk-saved-check').is(':checked')) return;
        $('#modal-overlay').removeClass('active');
        $('.modal').removeClass('modal-wide');
        this._quill = null;
        this._quillComment = null;
        this._settingsTab = null;
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
        this.openModal('Confirm', `<p class="text-lg">${message}</p>`, `
            <button class="btn btn-ghost" onclick="App.closeModal()">Cancel</button>
            <button class="btn btn-danger" id="confirm-action-btn">Confirm</button>
        `);
        setTimeout(() => $('#confirm-action-btn').off('click').on('click', () => { App.closeModal(); onConfirm(); }), 50);
    },

    promptInput(title, label, currentValue, onSave) {
        this.openModal(title, `
            <div class="form-group"><label>${label}</label>
            <textarea id="prompt-input" class="min-h-textarea">${this.esc(currentValue || '')}</textarea></div>
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
    fuzzyMatch(text, query) {
        let qi = 0;
        for (let i = 0; i < text.length && qi < query.length; i++) {
            if (text[i] === query[qi]) qi++;
        }
        return qi === query.length;
    },

    searchCards(query) {
        const q = query.toLowerCase().trim();
        const $clear = $('#search-clear');
        const $count = $('#search-count');

        if (!q) {
            $('.card[data-id]').show();
            $('.column-empty-search').remove();
            $clear.removeClass('search-visible');
            $count.removeClass('count-visible');
            this.updateColumnCounts();
            return;
        }

        $clear.addClass('search-visible');
        const words = q.split(/\s+/);
        let matched = 0;
        this.cards.forEach(card => {
            const title = (card.title || '').toLowerCase();
            const desc = (card.description || '').toLowerCase();
            const hit = words.every(w => title.includes(w) || desc.includes(w) || this.fuzzyMatch(title, w));
            const $el = $(`.card[data-id="${card.id}"]`);
            if (hit) {
                $el.show();
                matched++;
            } else {
                $el.hide();
            }
        });

        $('.column-empty-search').remove();
        $('.column-cards').each(function() {
            const hasCards = $(this).find('.card[data-id]').length > 0;
            if (hasCards && $(this).find('.card[data-id]:visible').length === 0) {
                $(this).append('<p class="column-empty column-empty-search">No matches</p>');
            }
        });

        $count.attr('data-count', matched).text(matched).addClass('count-visible');
        this.updateColumnCounts();
        const $first = $('.card[data-id]:visible').first();
        if ($first.length) $first[0].scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'start' });
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

    formatMinutes(m) {
        m = Math.max(0, Math.floor(m));
        const y = Math.floor(m / 525600); m %= 525600;
        const mo = Math.floor(m / 43200); m %= 43200;
        const d = Math.floor(m / 1440); m %= 1440;
        const h = Math.floor(m / 60);
        const mins = m % 60;
        const parts = [];
        if (y) parts.push(`${y}y`);
        if (mo) parts.push(`${mo}mo`);
        if (d) parts.push(`${d}d`);
        if (h) parts.push(`${h}h`);
        if (mins || !parts.length) parts.push(`${mins}m`);
        return parts.join(' ');
    },
    toast(msg, type = 'error') {
        $('.toast').remove();
        const $t = $(`<div class="toast toast-${type}">${this.esc(msg)}</div>`);
        $('body').append($t);
        setTimeout(() => $t.fadeOut(300, () => $t.remove()), 3500);
    },
    esc(str) { if (!str) return ''; const d = document.createElement('div'); d.textContent = str; return d.innerHTML; },
    escAttr(str) { return this.esc(str).replace(/'/g, '&#39;'); }
};

$(document).on('keydown', e => {
    const mod = e.metaKey || e.ctrlKey;
    const tag = (e.target.tagName || '').toLowerCase();
    const inInput = tag === 'input' || tag === 'textarea' || e.target.isContentEditable;

    if (e.key === 'Escape') {
        if ($('#board-search').is(':focus')) { App.clearSearch(); $('#board-search').blur(); return; }
        if ($('#modal-overlay').hasClass('active')) {
            const $cancel = $('#modal-footer .btn-ghost:contains("Cancel")');
            if ($cancel.length) { $cancel.first().click(); } else { App.closeModal(); }
        }
        return;
    }

    // CMD+S → save (any modal with a save/confirm button)
    if (mod && e.key === 's') {
        e.preventDefault();
        const $save = $('#modal-footer .btn-primary, #modal-footer .btn-danger');
        if ($save.length) $save.first().click();
        return;
    }

    // CMD+P → block print
    if (mod && e.key === 'p') { e.preventDefault(); return; }

    // CMD+K → focus search (works even from inputs)
    if (mod && e.key === 'k') {
        e.preventDefault();
        const $search = $('#board-search');
        if ($search.length) $search.focus().select();
        return;
    }

    // Shortcuts below only work when not typing in an input
    if (inInput) return;
    if (!App.user && !App.isGuest) return;

    // N → add card to first column
    if (e.key === 'n' && !mod && !e.shiftKey) {
        if (App.currentProject && App.columns.length) App.showAddCard(App.columns[0].id);
        return;
    }

    // W → watch/unwatch card or project
    if (e.key === 'w' && !mod && !e.shiftKey) {
        if (App._openCardId) {
            const card = App.cards.find(c => c.id == App._openCardId);
            if (card?.is_watching) App.unwatchCard(App._openCardId);
            else App.watchCard(App._openCardId);
        } else if (App.currentProject) {
            if (App._projectWatching) App.unwatchProject();
            else App.watchProject();
        }
        return;
    }

    // , → project settings
    if (mod && e.key === ',') {
        e.preventDefault();
        if (App.currentProject && !App.isGuest) App.showSettings();
        return;
    }

    // A → account
    if (e.key === 'a' && !mod && !e.shiftKey) {
        if (!App.isGuest) App.showAccount();
        return;
    }

    // T → team
    if (e.key === 't' && !mod && !e.shiftKey) {
        if (!App.isGuest && App.user?.role === 'admin') App.showTeam();
        return;
    }

    // ⌘G → app settings
    if (mod && e.key === 'g') {
        e.preventDefault();
        if (!App.isGuest && App.user?.role === 'admin') App.showAppSettings();
        return;
    }

    // ? → help
    if (e.key === '?' && !mod) {
        App.showHelp();
        return;
    }

    // 1-9 → open project by index (on project list only)
    if (!mod && !e.shiftKey && e.key >= '1' && e.key <= '9' && !App.currentProject) {
        const idx = parseInt(e.key) - 1;
        if (App.projects[idx]) App.openProject(App.projects[idx].id);
        return;
    }

    // Backspace → back to project list (board only, no modal/card open)
    if (e.key === 'Backspace' && !mod && App.currentProject && !App.isGuest && !App._openCardId && !$('#modal-overlay').hasClass('active')) {
        e.preventDefault();
        App.showProjects();
        return;
    }
});
$(document).on('keydown', '#login-email, #login-password', e => { if (e.key === 'Enter') App.login(); });
$(document).on('keydown', '#setup-name, #setup-email, #setup-password', e => { if (e.key === 'Enter') App.setup(); });
$(App.init.bind(App));
</script>
<footer class="app-footer">
  <p>Designed and built by <a href="https://x.com/rogeriotaques" target="_blank" rel="noopener noreferrer">Rogerio Taques</a>, the guy behind <a href="https://abtz.co?ref=Tasssks&utm_source=Tasssks&utm_media=Instance" target="_blank" rel="noopener noreferrer">Abtz Labs</a>.</p>
  <p>#<?= APP_VERSION ?> &copy; Abtz Labs.</p>
</footer>
</body>
</html>
