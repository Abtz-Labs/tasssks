<?php
/**
 * Tasssks API Test Suite
 * Run: php test.php
 * Spins up its own test server using test.sqlite (never touches kanban.sqlite).
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$TEST_PORT = 8089;
$TEST_DB = __DIR__ . '/test.sqlite';
$BASE = "http://localhost:$TEST_PORT";
$passed = 0;
$failed = 0;
$cookieFile = tempnam(sys_get_temp_dir(), 'kanban_test_');

// Clean previous test DB
@unlink($TEST_DB);

// Start a dedicated test server
$serverCmd = sprintf(
    'TASSSKS_DB_FILE=%s php -S localhost:%d -t %s %s/index.php',
    escapeshellarg($TEST_DB),
    $TEST_PORT,
    escapeshellarg(__DIR__),
    escapeshellarg(__DIR__)
);
$serverProc = proc_open($serverCmd, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
if (!$serverProc) {
    echo "Failed to start test server.\n";
    exit(1);
}
// Wait for server to be ready
$ready = false;
for ($i = 0; $i < 50; $i++) {
    $sock = @fsockopen('localhost', $TEST_PORT, $errno, $errstr, 0.1);
    if ($sock) { fclose($sock); $ready = true; break; }
    usleep(100_000);
}
if (!$ready) {
    echo "Test server failed to start on port $TEST_PORT.\n";
    proc_terminate($serverProc);
    exit(1);
}

function req(string $action, array $data = [], string $method = 'GET', string $csrf = '', string $cookie = ''): array {
    global $BASE, $cookieFile;
    $url = "$BASE/?action=$action";
    $ch = curl_init();

    if ($method === 'GET' && $data) {
        $url .= '&' . http_build_query($data);
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEFILE => $cookie ?: $cookieFile,
        CURLOPT_COOKIEJAR => $cookie ?: $cookieFile,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $headers = ['Content-Type: application/json'];
        if ($csrf) $headers[] = "X-CSRF-Token: $csrf";
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = substr($response, $headerSize);
    curl_close($ch);

    return ['status' => $httpCode, 'body' => json_decode($body, true) ?? [], 'raw' => $body];
}

function assert_eq($expected, $actual, string $msg): void {
    global $passed, $failed;
    if ($expected === $actual) {
        $passed++;
        echo "  ✓ $msg\n";
    } else {
        $failed++;
        echo "  ✗ $msg\n    Expected: " . json_encode($expected) . "\n    Got:      " . json_encode($actual) . "\n";
    }
}

function assert_true($val, string $msg): void {
    assert_eq(true, (bool)$val, $msg);
}

function section(string $name): void {
    echo "\n\033[1m[$name]\033[0m\n";
}

echo "Tasssks Test Suite\n";
echo str_repeat('=', 40) . "\n";

// ─── SETUP ───────────────────────────────────────────────
section('Setup / First User');

$r = req('auth_status');
assert_eq(200, $r['status'], 'auth_status returns 200');
assert_true($r['body']['needs_setup'], 'needs_setup is true with no users');
assert_eq(false, $r['body']['authenticated'], 'not authenticated initially');

$r = req('auth_setup', ['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'admin123'], 'POST');
assert_eq(200, $r['status'], 'setup succeeds');
assert_true(!empty($r['body']['csrf_token']), 'returns CSRF token');
$adminCsrf = $r['body']['csrf_token'];

$r = req('auth_status');
assert_eq(true, $r['body']['authenticated'], 'authenticated after setup');
assert_eq('admin', $r['body']['user']['role'], 'first user is admin');
assert_eq(false, $r['body']['needs_setup'], 'needs_setup is false after setup');

$r = req('auth_setup', ['name' => 'Dup', 'email' => 'dup@test.com', 'password' => '123456'], 'POST');
assert_eq(400, $r['status'], 'cannot run setup again');

// ─── LOGIN ───────────────────────────────────────────────
section('Login');

$r = req('auth_logout', [], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'logout succeeds');

// New session after logout
@unlink($cookieFile);
$cookieFile = tempnam(sys_get_temp_dir(), 'kanban_test_');

$r = req('auth_status');
assert_eq(false, $r['body']['authenticated'], 'not authenticated after logout');

$r = req('auth_login', ['email' => 'admin@test.com', 'password' => 'wrong'], 'POST');
assert_eq(403, $r['status'], 'wrong password returns 403');

$r = req('auth_login', ['email' => 'admin@test.com', 'password' => 'admin123'], 'POST');
assert_eq(200, $r['status'], 'correct login succeeds');
$adminCsrf = $r['body']['csrf_token'];

// ─── TEAM ────────────────────────────────────────────────
section('Team Management');

$r = req('team_list');
assert_eq(200, $r['status'], 'team_list returns 200');
assert_eq(1, count($r['body']), 'one user exists');

$r = req('team_add', ['name' => 'Member', 'email' => 'member@test.com', 'password' => 'mem123', 'role' => 'member'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'add member succeeds');
$memberId = $r['body']['id'];

$r = req('team_add', ['name' => 'Dup', 'email' => 'member@test.com', 'password' => 'dup123', 'role' => 'member'], 'POST', $adminCsrf);
assert_eq(400, $r['status'], 'duplicate email rejected');

$r = req('team_list');
assert_eq(2, count($r['body']), 'two users after add');

$r = req('team_update_role', ['id' => $memberId, 'role' => 'admin'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'promote to admin');

$r = req('team_update_role', ['id' => $memberId, 'role' => 'member'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'demote back to member');

$r = req('team_reset_password', ['id' => $memberId, 'password' => 'newpass1'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'reset password succeeds');

// ─── MEMBER PERMISSIONS ──────────────────────────────────
section('Member Permissions');

$memberCookie = tempnam(sys_get_temp_dir(), 'kanban_member_');
$r = req('auth_login', ['email' => 'member@test.com', 'password' => 'newpass1'], 'POST', '', $memberCookie);
assert_eq(200, $r['status'], 'member login with reset password');
$memberCsrf = $r['body']['csrf_token'];

$r = req('team_list', [], 'GET', '', $memberCookie);
assert_eq(403, $r['status'], 'member cannot list team');

$r = req('auth_set_app_name', ['name' => 'Hacked'], 'POST', $memberCsrf, $memberCookie);
assert_eq(403, $r['status'], 'member cannot change app name');

// ─── PROJECTS ────────────────────────────────────────────
section('Projects');

$r = req('create_project', ['name' => 'Admin Project'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'admin creates project');
$adminProjectId = $r['body']['id'];

$r = req('create_project', ['name' => 'Member Project'], 'POST', $memberCsrf, $memberCookie);
assert_eq(200, $r['status'], 'member creates project');
$memberProjectId = $r['body']['id'];

$r = req('list_projects', [], 'GET', '', $memberCookie);
assert_eq(200, $r['status'], 'member can list all projects');
assert_eq(2, count($r['body']), 'sees both projects');

// Find ownership flags
$adminP = array_values(array_filter($r['body'], fn($p) => $p['id'] === $adminProjectId))[0] ?? null;
$memberP = array_values(array_filter($r['body'], fn($p) => $p['id'] === $memberProjectId))[0] ?? null;
assert_eq(false, $adminP['is_owner'] ?? true, 'member is NOT owner of admin project');
assert_eq(true, $memberP['is_owner'] ?? false, 'member IS owner of their project');

// Member cannot edit admin's project settings
$r = req('update_project', ['id' => $adminProjectId, 'name' => 'Hacked'], 'POST', $memberCsrf, $memberCookie);
assert_eq(403, $r['status'], 'member cannot rename admin project');

// Member CAN edit their own project
$r = req('update_project', ['id' => $memberProjectId, 'name' => 'Renamed'], 'POST', $memberCsrf, $memberCookie);
assert_eq(200, $r['status'], 'member can rename own project');

// Admin CAN edit member's project
$r = req('update_project', ['id' => $memberProjectId, 'name' => 'Admin Override'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'admin can rename any project');

// ─── COLUMNS (owner-only) ────────────────────────────────
section('Columns');

$r = req('list_columns', ['project_id' => $adminProjectId]);
assert_eq(200, $r['status'], 'list columns succeeds');
assert_eq(3, count($r['body']), 'default 3 columns');

$r = req('create_column', ['project_id' => $adminProjectId, 'name' => 'Review'], 'POST', $memberCsrf, $memberCookie);
assert_eq(403, $r['status'], 'member cannot add column to admin project');

$r = req('create_column', ['project_id' => $adminProjectId, 'name' => 'Review'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'admin can add column');

// ─── CARDS (any auth user) ───────────────────────────────
section('Cards');

$r = req('list_columns', ['project_id' => $adminProjectId]);
$colId = $r['body'][0]['id'];

$r = req('create_card', ['column_id' => $colId, 'title' => 'Card by member'], 'POST', $memberCsrf, $memberCookie);
assert_eq(200, $r['status'], 'member can create card in any project');
$cardId = $r['body']['id'];

$r = req('update_card', ['id' => $cardId, 'title' => 'Updated'], 'POST', $memberCsrf, $memberCookie);
assert_eq(200, $r['status'], 'member can update card');

$r = req('delete_card', ['id' => $cardId], 'POST', $memberCsrf, $memberCookie);
assert_eq(200, $r['status'], 'member can delete card');

// ─── COMMENTS ────────────────────────────────────────────
section('Comments');

$r = req('create_card', ['column_id' => $colId, 'title' => 'Comment test'], 'POST', $adminCsrf);
$commentCardId = $r['body']['id'];

$r = req('create_comment', ['card_id' => $commentCardId, 'content' => 'Admin comment'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'admin can comment');
$adminCommentId = $r['body']['id'];

$r = req('create_comment', ['card_id' => $commentCardId, 'content' => 'Member comment'], 'POST', $memberCsrf, $memberCookie);
assert_eq(200, $r['status'], 'member can comment');
$memberCommentId = $r['body']['id'];

// Member cannot edit admin's comment
$r = req('update_comment', ['id' => $adminCommentId, 'content' => 'Hacked'], 'POST', $memberCsrf, $memberCookie);
assert_eq(403, $r['status'], 'member cannot edit admin comment');

// Member can edit own comment
$r = req('update_comment', ['id' => $memberCommentId, 'content' => 'Edited'], 'POST', $memberCsrf, $memberCookie);
assert_eq(200, $r['status'], 'member can edit own comment');

// Admin can edit any comment
$r = req('update_comment', ['id' => $memberCommentId, 'content' => 'Admin edit'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'admin can edit any comment');

// ─── ACCOUNT ─────────────────────────────────────────────
section('Account');

$r = req('account_update', ['name' => 'New Name', 'email' => 'member@test.com'], 'POST', $memberCsrf, $memberCookie);
assert_eq(200, $r['status'], 'member can update name/email');

$r = req('account_update', ['name' => 'X', 'email' => 'admin@test.com'], 'POST', $memberCsrf, $memberCookie);
assert_eq(400, $r['status'], 'cannot use taken email');

$r = req('account_update', ['name' => 'X', 'email' => 'member@test.com', 'password' => 'newpw22', 'current_password' => 'wrong'], 'POST', $memberCsrf, $memberCookie);
assert_eq(403, $r['status'], 'wrong current password rejected');

$r = req('account_update', ['name' => 'X', 'email' => 'member@test.com', 'password' => 'newpw22', 'current_password' => 'newpass1'], 'POST', $memberCsrf, $memberCookie);
assert_eq(200, $r['status'], 'password change with correct current password');

// ─── GUEST ACCESS ────────────────────────────────────────
section('Guest Access');

$r = req('create_guest', ['project_id' => $adminProjectId, 'name' => 'Guest User', 'can_comment' => 1], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'create guest');
$guestToken = $r['body']['token'];
$guestId = $r['body']['id'];

$r = req('guest_project_info', ['token' => $guestToken]);
assert_eq(200, $r['status'], 'guest can get project info');
assert_eq('Admin Project', $r['body']['name'], 'returns project name');

// Edit guest (add email to existing guest)
$r = req('update_guest', ['id' => $guestId, 'email' => 'updated@test.com'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'update guest email');

$r = req('list_guests', ['project_id' => $adminProjectId]);
$updatedGuest = array_values(array_filter($r['body'], fn($g) => $g['name'] === 'Guest User'))[0] ?? null;
assert_eq('updated@test.com', $updatedGuest['email'] ?? '', 'guest email updated');

// Edit guest name
$r = req('update_guest', ['id' => (int)$updatedGuest['id'], 'name' => 'Renamed Guest'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'update guest name');

$r = req('list_guests', ['project_id' => $adminProjectId]);
$renamedGuest = array_values(array_filter($r['body'], fn($g) => $g['email'] === 'updated@test.com'))[0] ?? null;
assert_eq('Renamed Guest', $renamedGuest['name'] ?? '', 'guest name updated');

// Invalid email rejected
$r = req('update_guest', ['id' => (int)$renamedGuest['id'], 'email' => 'not-an-email'], 'POST', $adminCsrf);
assert_eq(400, $r['status'], 'invalid guest email rejected');

// Member cannot edit guest on admin project (uses member cookie from earlier login)
$r = req('update_guest', ['id' => (int)$renamedGuest['id'], 'name' => 'Hacked'], 'POST', $memberCsrf, $memberCookie);
assert_eq(403, $r['status'], 'member cannot edit guest on admin project');

// Guest with email
$r = req('create_guest', ['project_id' => $adminProjectId, 'name' => 'Email Guest', 'can_comment' => 1, 'email' => 'guest@test.com'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'create guest with email');
$emailGuestToken = $r['body']['token'];

$r = req('list_guests', ['project_id' => $adminProjectId]);
$emailGuest = array_values(array_filter($r['body'], fn($g) => $g['name'] === 'Email Guest'))[0] ?? null;
assert_true(!empty($emailGuest), 'email guest exists in list');
assert_eq('guest@test.com', $emailGuest['email'] ?? '', 'guest email is stored');

// Guest with email can watch project
$guestWatchCookie = tempnam(sys_get_temp_dir(), 'kanban_gw_');
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "http://localhost:$TEST_PORT/?action=auth_status&guest=$emailGuestToken",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_COOKIEFILE => $guestWatchCookie,
    CURLOPT_COOKIEJAR => $guestWatchCookie,
]);
$response = curl_exec($ch);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$gwAuth = json_decode(substr($response, $headerSize), true);
curl_close($ch);
$guestWatchCsrf = $gwAuth['csrf_token'] ?? '';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "http://localhost:$TEST_PORT/?action=guest_watch&guest=$emailGuestToken",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['project_id' => $adminProjectId]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-CSRF-Token: $guestWatchCsrf"],
    CURLOPT_COOKIEFILE => $guestWatchCookie,
    CURLOPT_COOKIEJAR => $guestWatchCookie,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
assert_eq(200, $httpCode, 'guest with email can watch project');

// Guest without email cannot watch (create a fresh no-email guest)
$r = req('create_guest', ['project_id' => $adminProjectId, 'name' => 'No Email Guest', 'can_comment' => 1], 'POST', $adminCsrf);
$noEmailGuestToken = $r['body']['token'];

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "http://localhost:$TEST_PORT/?action=guest_watch&guest=$noEmailGuestToken",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['project_id' => $adminProjectId]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-CSRF-Token: $guestWatchCsrf"],
    CURLOPT_COOKIEFILE => $guestWatchCookie,
    CURLOPT_COOKIEJAR => $guestWatchCookie,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
assert_eq(403, $httpCode, 'guest without email cannot watch');

// Guest can unwatch
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "http://localhost:$TEST_PORT/?action=guest_unwatch&guest=$emailGuestToken",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['project_id' => $adminProjectId]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-CSRF-Token: $guestWatchCsrf"],
    CURLOPT_COOKIEFILE => $guestWatchCookie,
    CURLOPT_COOKIEJAR => $guestWatchCookie,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
assert_eq(200, $httpCode, 'guest with email can unwatch');

@unlink($guestWatchCookie);

// ─── WEBHOOKS ───────────────────────────────────────────
section('Webhooks');

$r = req('list_webhooks', ['project_id' => $adminProjectId]);
assert_eq(200, $r['status'], 'list webhooks returns 200');
assert_eq(0, count($r['body']), 'no webhooks initially');

$r = req('create_webhook', ['project_id' => $adminProjectId, 'url' => 'https://hooks.slack.com/test', 'type' => 'slack'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'create webhook succeeds');
$webhookId = $r['body']['id'];

$r = req('create_webhook', ['project_id' => $adminProjectId, 'url' => 'not-a-url', 'type' => 'generic'], 'POST', $adminCsrf);
assert_eq(400, $r['status'], 'invalid URL rejected');

$r = req('create_webhook', ['project_id' => $adminProjectId, 'url' => 'https://example.com', 'type' => 'invalid'], 'POST', $adminCsrf);
assert_eq(400, $r['status'], 'invalid type rejected');

$r = req('list_webhooks', ['project_id' => $adminProjectId]);
assert_eq(1, count($r['body']), 'one webhook after create');
assert_eq('slack', $r['body'][0]['type'], 'webhook type is slack');
assert_eq(1, (int)$r['body'][0]['enabled'], 'webhook enabled by default');

$r = req('toggle_webhook', ['id' => $webhookId], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'toggle webhook succeeds');

$r = req('list_webhooks', ['project_id' => $adminProjectId]);
assert_eq(0, (int)$r['body'][0]['enabled'], 'webhook disabled after toggle');

$r = req('toggle_webhook', ['id' => $webhookId], 'POST', $adminCsrf);
$r = req('list_webhooks', ['project_id' => $adminProjectId]);
assert_eq(1, (int)$r['body'][0]['enabled'], 'webhook re-enabled after second toggle');

$r = req('delete_webhook', ['id' => $webhookId], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'delete webhook succeeds');

$r = req('list_webhooks', ['project_id' => $adminProjectId]);
assert_eq(0, count($r['body']), 'no webhooks after delete');

// Member cannot manage webhooks on admin project
$memberCookie2 = tempnam(sys_get_temp_dir(), 'kanban_member2_');
$r = req('auth_login', ['email' => 'member@test.com', 'password' => 'newpw22'], 'POST', '', $memberCookie2);
$memberCsrf2 = $r['body']['csrf_token'];

$r = req('create_webhook', ['project_id' => $adminProjectId, 'url' => 'https://evil.com/hook', 'type' => 'generic'], 'POST', $memberCsrf2, $memberCookie2);
assert_eq(403, $r['status'], 'member cannot add webhook to admin project');

// ─── WATCHERS ───────────────────────────────────────────
section('Watchers');

$r = req('watch', ['project_id' => $adminProjectId], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'watch project succeeds');

$r = req('list_watchers', ['project_id' => $adminProjectId]);
assert_eq(200, $r['status'], 'list project watchers');
assert_true(count($r['body']) >= 1, 'at least one watcher on project');

$r = req('watch', ['project_id' => $adminProjectId, 'card_id' => $commentCardId], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'watch card succeeds');

$r = req('list_watchers', ['project_id' => $adminProjectId, 'card_id' => $commentCardId]);
assert_true(count($r['body']) >= 1, 'watcher visible on card');

$r = req('unwatch', ['project_id' => $adminProjectId, 'card_id' => $commentCardId], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'unwatch card succeeds');

$r = req('unwatch', ['project_id' => $adminProjectId], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'unwatch project succeeds');

// Unauthenticated cannot watch
$guestCookie = tempnam(sys_get_temp_dir(), 'kanban_guest_');
$r = req('watch', ['project_id' => $adminProjectId], 'POST', '', $guestCookie);
assert_eq(403, $r['status'], 'unauthenticated cannot watch');
@unlink($guestCookie);

// ─── NOTIFICATION SETTINGS ──────────────────────────────
section('Notification Settings');

$r = req('get_notification_settings');
assert_eq(200, $r['status'], 'get notification settings');
assert_eq('immediate', $r['body']['delivery'], 'default delivery is immediate');

$r = req('update_notification_settings', ['email' => 'notify@test.com', 'delivery' => 'daily'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'update notification settings');

$r = req('get_notification_settings');
assert_eq('notify@test.com', $r['body']['email'], 'email saved');
assert_eq('daily', $r['body']['delivery'], 'delivery mode saved');

$r = req('update_notification_settings', ['email' => 'bad-email', 'delivery' => 'immediate'], 'POST', $adminCsrf);
assert_eq(400, $r['status'], 'invalid email rejected');

$r = req('update_notification_settings', ['email' => '', 'delivery' => 'invalid'], 'POST', $adminCsrf);
assert_eq(400, $r['status'], 'invalid delivery mode rejected');

$r = req('update_notification_settings', ['email' => '', 'delivery' => 'immediate'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'can clear notification email');

// ─── GUEST CARD CREATION & SORTING ──────────────────────
section('Guest Card Creation & Sorting');

$r = req('update_project', ['id' => $adminProjectId, 'guest_can_create_cards' => 1, 'guest_can_sort_cards' => 1], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'enable guest permissions');

$guestCookie2 = tempnam(sys_get_temp_dir(), 'kanban_g2_');

// Get guest CSRF token by hitting auth_status to establish session
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "http://localhost:$TEST_PORT/?action=auth_status&guest=$guestToken",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_COOKIEFILE => $guestCookie2,
    CURLOPT_COOKIEJAR => $guestCookie2,
]);
$response = curl_exec($ch);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$guestAuthBody = json_decode(substr($response, $headerSize), true);
curl_close($ch);
$guestCsrf = $guestAuthBody['csrf_token'] ?? '';

$guestUrl = "http://localhost:$TEST_PORT/?action=create_card&guest=$guestToken";
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $guestUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['column_id' => $colId, 'title' => 'Guest card']),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-CSRF-Token: $guestCsrf"],
    CURLOPT_COOKIEFILE => $guestCookie2,
    CURLOPT_COOKIEJAR => $guestCookie2,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$body = json_decode(substr($response, $headerSize), true);
curl_close($ch);
assert_eq(200, $httpCode, 'guest can create card in first column');
$guestCardId = $body['id'] ?? 0;
assert_true($guestCardId > 0, 'guest card has an ID');

// Guest cannot create in second column
$r = req('list_columns', ['project_id' => $adminProjectId]);
$secondColId = $r['body'][1]['id'] ?? 0;
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "http://localhost:$TEST_PORT/?action=create_card&guest=$guestToken",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['column_id' => $secondColId, 'title' => 'Bad card']),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-CSRF-Token: $guestCsrf"],
    CURLOPT_COOKIEFILE => $guestCookie2,
    CURLOPT_COOKIEJAR => $guestCookie2,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
assert_eq(403, $httpCode, 'guest cannot create card in second column');

@unlink($guestCookie2);

// ─── CARD METADATA ──────────────────────────────────────
section('Card Metadata');

$r = req('list_cards', ['project_id' => $adminProjectId]);
assert_eq(200, $r['status'], 'list cards');
$testCard = array_values(array_filter($r['body'], fn($c) => $c['id'] == $commentCardId))[0] ?? null;
assert_true(!empty($testCard['created_at']), 'card has created_at');
assert_true(!empty($testCard['updated_at']), 'card has updated_at');
assert_true(array_key_exists('author_name', $testCard), 'card has author_name field');

// ─── SMTP SETTINGS ──────────────────────────────────────
section('SMTP Settings');

// Only admin can manage SMTP
$r = req('get_smtp_settings', [], 'GET', '', $memberCookie2);
assert_eq(403, $r['status'], 'member cannot get SMTP settings');

$r = req('get_smtp_settings');
assert_eq(200, $r['status'], 'admin can get SMTP settings');
assert_eq('', $r['body']['smtp_host'] ?? '', 'SMTP host empty by default');
assert_eq('', $r['body']['smtp_from_email'] ?? '', 'SMTP from_email empty by default');

$r = req('update_smtp_settings', [
    'smtp_host' => 'smtp.example.com',
    'smtp_port' => 587,
    'smtp_user' => 'user@example.com',
    'smtp_pass' => 'secret123',
    'smtp_from_email' => 'noreply@example.com',
    'smtp_from_name' => 'Tasssks',
    'smtp_encryption' => 'tls',
], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'admin can save SMTP settings');

$r = req('get_smtp_settings');
assert_eq('smtp.example.com', $r['body']['smtp_host'], 'SMTP host saved');
assert_eq(587, (int)$r['body']['smtp_port'], 'SMTP port saved');
assert_eq('user@example.com', $r['body']['smtp_user'], 'SMTP user saved');
assert_eq('noreply@example.com', $r['body']['smtp_from_email'], 'SMTP from_email saved');
assert_eq('Tasssks', $r['body']['smtp_from_name'], 'SMTP from_name saved');
assert_eq('tls', $r['body']['smtp_encryption'], 'SMTP encryption saved');

// Password should not be returned in plain
assert_true(!empty($r['body']['smtp_pass_set']), 'SMTP password is set (masked)');

$r = req('update_smtp_settings', ['smtp_host' => 'smtp.example.com', 'smtp_port' => 587, 'smtp_encryption' => 'invalid'], 'POST', $adminCsrf);
assert_eq(400, $r['status'], 'invalid encryption rejected');

$r = req('update_smtp_settings', [], 'POST', $memberCsrf2, $memberCookie2);
assert_eq(403, $r['status'], 'member cannot update SMTP settings');

// ─── SEND NOTIFICATIONS (IMMEDIATE) ─────────────────────
section('Send Notifications (Immediate)');

// Set admin notification to immediate
$r = req('update_notification_settings', ['email' => 'admin@test.com', 'delivery' => 'immediate'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'set delivery to immediate');

// Watch the project so admin gets notifications
$r = req('watch', ['project_id' => $adminProjectId], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'admin watches project for notifications');

// Create a card to generate a notification
$r = req('create_card', ['column_id' => $colId, 'title' => 'Immediate test card'], 'POST', $memberCsrf2, $memberCookie2);
assert_eq(200, $r['status'], 'member creates card (triggers notification)');

// send_notifications only processes immediate users
$r = req('send_notifications');
assert_eq(200, $r['status'], 'send_notifications endpoint returns 200');
assert_true(isset($r['body']['processed']), 'send_notifications reports processed count');
assert_true($r['body']['processed'] >= 1, 'immediate user processed');

// send_notifications skips daily users
$r = req('send_notifications');
assert_eq(200, $r['status'], 'second call succeeds');
assert_eq(0, $r['body']['processed'], 'no pending immediate after first run');

// ─── SEND DIGEST (DAILY) ────────────────────────────────
section('Send Digest (Daily)');

// Switch to daily
$r = req('update_notification_settings', ['email' => 'admin@test.com', 'delivery' => 'daily'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'switch to daily delivery');

$r = req('create_card', ['column_id' => $colId, 'title' => 'Daily test card'], 'POST', $memberCsrf2, $memberCookie2);
assert_eq(200, $r['status'], 'another card triggers notification');

// send_notifications should NOT process daily users
$r = req('send_notifications');
assert_eq(200, $r['status'], 'send_notifications with daily user');
assert_eq(0, $r['body']['processed'], 'send_notifications skips daily users');

// send_digest processes daily users
$r = req('send_digest');
assert_eq(200, $r['status'], 'send_digest endpoint returns 200');
assert_true(isset($r['body']['processed']), 'send_digest reports processed count');
assert_true($r['body']['processed'] >= 1, 'daily user processed by digest');

// After digest, nothing pending
$r = req('send_digest');
assert_eq(200, $r['status'], 'second digest call succeeds');
assert_eq(0, $r['body']['processed'], 'no pending after digest');

// last_digest_at is set after digest runs
$r = req('get_smtp_settings');
assert_true(!empty($r['body']['last_digest_at']), 'last_digest_at set after digest');

// Unauthenticated digest without token is rejected
$noAuthCookie = tempnam(sys_get_temp_dir(), 'kanban_noauth_');
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "http://localhost:$TEST_PORT/?action=send_digest",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_COOKIEFILE => $noAuthCookie,
    CURLOPT_COOKIEJAR => $noAuthCookie,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
assert_eq(403, $httpCode, 'unauthenticated digest without token rejected');
@unlink($noAuthCookie);

// Cron token auth
$r = req('generate_cron_token', [], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'generate cron token succeeds');
$cronToken = $r['body']['token'];
assert_true(strlen($cronToken) >= 16, 'cron token is long enough');

// Digest via cron token (unauthenticated session)
$anonCookie = tempnam(sys_get_temp_dir(), 'kanban_anon_');
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "http://localhost:$TEST_PORT/?action=send_digest&cron_token=$cronToken",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_COOKIEFILE => $anonCookie,
    CURLOPT_COOKIEJAR => $anonCookie,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$digestBody = json_decode(substr($response, $headerSize), true);
curl_close($ch);
assert_eq(200, $httpCode, 'digest via cron token succeeds');
assert_true(isset($digestBody['processed']), 'cron token digest returns processed');

// Invalid cron token
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "http://localhost:$TEST_PORT/?action=send_digest&cron_token=invalid",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_COOKIEFILE => $anonCookie,
    CURLOPT_COOKIEJAR => $anonCookie,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
assert_eq(403, $httpCode, 'invalid cron token rejected');
@unlink($anonCookie);

// ─── WATCHER COUNTS ─────────────────────────────────────
section('Watcher Counts');

// Admin is watching the project (from earlier test)
$r = req('list_projects');
assert_eq(200, $r['status'], 'list projects with watch data');
$proj = array_values(array_filter($r['body'], fn($p) => $p['id'] === $adminProjectId))[0] ?? null;
assert_true(isset($proj['watcher_count']), 'project has watcher_count field');
assert_true($proj['watcher_count'] >= 1, 'project watcher_count is at least 1');
assert_true(isset($proj['is_watching']), 'project has is_watching field');
assert_eq(true, (bool)$proj['is_watching'], 'admin is watching the project');

// Have the email guest re-watch for combined count test
$gwCookie = tempnam(sys_get_temp_dir(), 'kanban_gwc_');
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "http://localhost:$TEST_PORT/?action=auth_status&guest=$emailGuestToken",
    CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
    CURLOPT_COOKIEFILE => $gwCookie, CURLOPT_COOKIEJAR => $gwCookie,
]);
$resp = curl_exec($ch);
$hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$gwCsrf = (json_decode(substr($resp, $hs), true))['csrf_token'] ?? '';
curl_close($ch);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "http://localhost:$TEST_PORT/?action=guest_watch&guest=$emailGuestToken",
    CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['project_id' => $adminProjectId]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-CSRF-Token: $gwCsrf"],
    CURLOPT_COOKIEFILE => $gwCookie, CURLOPT_COOKIEJAR => $gwCookie,
]);
curl_exec($ch); curl_close($ch);
@unlink($gwCookie);

// list_watchers includes both users and guests
$r = req('list_watchers', ['project_id' => $adminProjectId]);
assert_eq(200, $r['status'], 'list_watchers returns combined');
$userWatchers = array_filter($r['body'], fn($w) => ($w['type'] ?? '') === 'user');
$guestWatchers = array_filter($r['body'], fn($w) => ($w['type'] ?? '') === 'guest');
assert_true(count($userWatchers) >= 1, 'list_watchers includes user watchers');
assert_true(count($guestWatchers) >= 1, 'list_watchers includes guest watchers');
assert_true(count($r['body']) >= 2, 'total watchers includes users + guests');

// Check cards have watcher data
$r = req('list_cards', ['project_id' => $adminProjectId]);
assert_eq(200, $r['status'], 'list cards with watch data');
$card = array_values(array_filter($r['body'], fn($c) => $c['id'] == $commentCardId))[0] ?? null;
assert_true(isset($card['watcher_count']), 'card has watcher_count field');
assert_true(isset($card['is_watching']), 'card has is_watching field');

// Watch a card and verify count
$r = req('watch', ['project_id' => $adminProjectId, 'card_id' => $commentCardId], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'watch card for count test');

$r = req('list_cards', ['project_id' => $adminProjectId]);
$card = array_values(array_filter($r['body'], fn($c) => $c['id'] == $commentCardId))[0] ?? null;
assert_true($card['watcher_count'] >= 1, 'card watcher_count after watch');
assert_eq(true, (bool)$card['is_watching'], 'card is_watching is true');

// Unwatch and verify
$r = req('unwatch', ['project_id' => $adminProjectId, 'card_id' => $commentCardId], 'POST', $adminCsrf);
$r = req('list_cards', ['project_id' => $adminProjectId]);
$card = array_values(array_filter($r['body'], fn($c) => $c['id'] == $commentCardId))[0] ?? null;
assert_eq(false, (bool)$card['is_watching'], 'card is_watching false after unwatch');

// ─── SECURITY: HEADERS ──────────────────────────────────
section('Security Headers');

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "$BASE/",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_NOBODY => true,
]);
$headResponse = curl_exec($ch);
curl_close($ch);

assert_true(stripos($headResponse, 'X-Frame-Options: DENY') !== false, 'X-Frame-Options header present');
assert_true(stripos($headResponse, 'X-Content-Type-Options: nosniff') !== false, 'X-Content-Type-Options header present');
assert_true(stripos($headResponse, 'Referrer-Policy: strict-origin-when-cross-origin') !== false, 'Referrer-Policy header present');
assert_true(stripos($headResponse, 'X-Powered-By') === false, 'X-Powered-By header removed');
assert_true(stripos($headResponse, 'HttpOnly') !== false, 'Session cookie has HttpOnly flag');
assert_true(stripos($headResponse, 'SameSite=Strict') !== false, 'Session cookie has SameSite flag');

// ─── SECURITY: SENSITIVE FILE ACCESS ────────────────────
section('Sensitive File Blocking');

$sensitiveFiles = ['kanban.sqlite', 'test.sqlite', 'data.db', '.env', '.git/config'];
foreach ($sensitiveFiles as $f) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "$BASE/$f",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    assert_eq(403, $code, "access to $f blocked");
}

// ─── SECURITY: RATE LIMIT ───────────────────────────────
section('Rate Limit');

$rateCookie = tempnam(sys_get_temp_dir(), 'kanban_rate_');
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "$BASE/?action=auth_status",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_COOKIEFILE => $rateCookie,
    CURLOPT_COOKIEJAR => $rateCookie,
]);
$resp = curl_exec($ch);
$hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$rateCsrf = (json_decode(substr($resp, $hs), true))['csrf_token'] ?? '';
curl_close($ch);

for ($i = 0; $i < 6; $i++) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "$BASE/?action=auth_login",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['email' => 'rate@test.com', 'password' => 'wrong']),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-CSRF-Token: $rateCsrf"],
        CURLOPT_COOKIEFILE => $rateCookie,
        CURLOPT_COOKIEJAR => $rateCookie,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "$BASE/?action=auth_login",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['email' => 'rate@test.com', 'password' => 'wrong']),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-CSRF-Token: $rateCsrf"],
    CURLOPT_COOKIEFILE => $rateCookie,
    CURLOPT_COOKIEJAR => $rateCookie,
]);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
assert_eq(429, $httpCode, 'rate limit enforced after 5 attempts');

// X-Forwarded-For should NOT bypass rate limit
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "$BASE/?action=auth_login",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['email' => 'rate@test.com', 'password' => 'wrong']),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-CSRF-Token: $rateCsrf", 'X-Forwarded-For: 10.0.0.99'],
    CURLOPT_COOKIEFILE => $rateCookie,
    CURLOPT_COOKIEJAR => $rateCookie,
]);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
assert_eq(429, $httpCode, 'X-Forwarded-For does not bypass rate limit');
@unlink($rateCookie);

// ─── SECURITY: CSRF ─────────────────────────────────────
section('CSRF Protection');

// Use the existing admin session (already authenticated with valid CSRF)
// POST without CSRF token
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "$BASE/?action=create_project",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['name' => 'CSRF test']),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_COOKIEJAR => $cookieFile,
]);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
assert_eq(403, $httpCode, 'POST without CSRF token rejected');

// POST with wrong CSRF token
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "$BASE/?action=create_project",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['name' => 'CSRF test']),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: invalidtoken'],
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_COOKIEJAR => $cookieFile,
]);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
assert_eq(403, $httpCode, 'POST with wrong CSRF token rejected');

// POST with valid CSRF token
$r = req('create_project', ['name' => 'CSRF valid test'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'POST with valid CSRF token accepted');

// ─── CLEANUP ─────────────────────────────────────────────
@unlink($memberCookie2);
section('Team Remove');

$r = req('team_remove', ['id' => $memberId], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'remove member');

$r = req('team_list');
assert_eq(1, count($r['body']), 'only admin remains');

// ─── RESULTS ─────────────────────────────────────────────
echo "\n" . str_repeat('=', 40) . "\n";
echo "Results: \033[32m$passed passed\033[0m, " . ($failed ? "\033[31m$failed failed\033[0m" : "0 failed") . "\n";

// Cleanup
proc_terminate($serverProc);
proc_close($serverProc);
@unlink($cookieFile);
@unlink($memberCookie);
@unlink($TEST_DB);

exit($failed > 0 ? 1 : 0);
