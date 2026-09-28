<?php
/**
 * Tasssks API Test Suite
 * Run: php tests.php
 * Spins up its own test server using test.sqlite (never touches tasssks.sqlite).
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

function colorGreen(string $s): string { return "\033[32m" . $s . "\033[0m"; }
function colorRed(string $s): string { return "\033[31m" . $s . "\033[0m"; }
function colorBold(string $s): string { return "\033[1m" . $s . "\033[0m"; }

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

function reqUpload(string $action, string $filePath, int $cardId, string $csrf = '', string $cookie = ''): array {
    global $BASE, $cookieFile;
    $url = "$BASE/?action=$action";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'file' => new CURLFile($filePath, 'text/plain', basename($filePath)),
            'card_id' => $cardId,
        ],
        CURLOPT_COOKIEFILE => $cookie ?: $cookieFile,
        CURLOPT_COOKIEJAR => $cookie ?: $cookieFile,
    ]);
    $headers = [];
    if ($csrf) $headers[] = "X-CSRF-Token: $csrf";
    if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

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
        echo '  ' . colorGreen('PASS') . ' ' . $msg . "\n";
    } else {
        $failed++;
        echo '  ' . colorRed('FAIL') . ' ' . $msg . "\n";
        echo '    ' . colorRed('expected: ' . var_export($expected, true)) . "\n";
        echo '    ' . colorRed('actual:   ' . var_export($actual, true)) . "\n";
    }
}

function assert_true($val, string $msg): void {
    global $passed, $failed;
    if ((bool)$val) {
        $passed++;
        echo '  ' . colorGreen('PASS') . ' ' . $msg . "\n";
    } else {
        $failed++;
        echo '  ' . colorRed('FAIL') . ' ' . $msg . "\n";
        echo '    ' . colorRed('expected: true') . "\n";
        echo '    ' . colorRed('actual:   false') . "\n";
    }
}

function section(string $name): void {
    echo "\n" . colorBold('=== ' . $name . ' ===') . "\n";
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
assert_true(!empty($r['body']['recovery_key']), 'setup returns recovery key');
assert_eq(32, strlen($r['body']['recovery_key']), 'recovery key is 32 chars');
$setupRecoveryKey = $r['body']['recovery_key'];
$adminCsrf = $r['body']['csrf_token'];

$r = req('auth_status');
assert_eq(true, $r['body']['authenticated'], 'authenticated after setup');
assert_eq('admin', $r['body']['user']['role'], 'first user is admin');
assert_eq(false, $r['body']['needs_setup'], 'needs_setup is false after setup');

$r = req('auth_setup', ['name' => 'Dup', 'email' => 'dup@test.com', 'password' => '123456'], 'POST');
assert_eq(400, $r['status'], 'cannot run setup again');

// ─── HEALTH ──────────────────────────────────────────────
section('Health Check');

$r = req('ping');
assert_eq(200, $r['status'], 'ping returns 200');
assert_eq('pong', $r['body']['status'], 'ping returns pong');

// Test DB error path: non-writable directory = 503
$noWriteDir = sys_get_temp_dir() . '/tasssks_test_nowrite';
@mkdir($noWriteDir, 0755);
chmod($noWriteDir, 0000);
$noWriteDb = $noWriteDir . '/test.sqlite';
$noWritePort = $TEST_PORT + 2;
$noWriteProc = proc_open(
    sprintf('TASSSKS_DB_FILE=%s php -S localhost:%d -t %s %s/index.php', escapeshellarg($noWriteDb), $noWritePort, escapeshellarg(__DIR__), escapeshellarg(__DIR__)),
    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $noWritePipes
);
usleep(500000);
$ch = curl_init("http://localhost:$noWritePort/?action=ping");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$noWriteBody = curl_exec($ch);
$noWriteCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
proc_terminate($noWriteProc);
proc_close($noWriteProc);
chmod($noWriteDir, 0755);
@unlink($noWriteDb);
rmdir($noWriteDir);
$noWriteResult = json_decode($noWriteBody, true) ?? [];
assert_eq(503, $noWriteCode, 'ping returns 503 when DB directory is not writable');
assert_eq('Database unavailable', $noWriteResult['error'] ?? null, 'ping returns DB error message');

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
assert_true(empty($r['body']['recovery_key']), 'second login does not return recovery key');
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
assert_true(!empty($r['body']['recovery_key']), 'first login returns recovery key for member');
assert_true(!empty($r['body']['password_reset']), 'first login sets password_reset for member');
$memberRecoveryKey = $r['body']['recovery_key'];
$memberCsrf = $r['body']['csrf_token'];
// Consume password_reset flag so later tests work normally
req('account_update', ['name' => 'Member', 'email' => 'member@test.com', 'password' => 'newpass1'], 'POST', $memberCsrf, $memberCookie);

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

// ─── CARD INSERT POSITION ────────────────────────────────
section('Card Insert Position');

$r = req('list_columns', ['project_id' => $adminProjectId]);
$posColId = $r['body'][2]['id'] ?? 0;

$r = req('create_card', ['column_id' => $posColId, 'title' => 'Pos First'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'create first card in position test column');
$posFirstId = $r['body']['id'];

$r = req('create_card', ['column_id' => $posColId, 'title' => 'Pos Second'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'create second card in position test column');
$posSecondId = $r['body']['id'];
assert_true($r['body']['position'] > 0, 'default create appends after existing card');

$r = req('create_card', ['column_id' => $posColId, 'title' => 'Pos Top', 'at_top' => 1], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'create card at top');
assert_eq(0, $r['body']['position'], 'at_top create returns position 0');
$posTopId = $r['body']['id'];

$r = req('list_cards', ['project_id' => $adminProjectId]);
$posCards = array_values(array_filter($r['body'], fn($c) => $c['column_id'] == $posColId));
assert_eq(3, count($posCards), 'position test column has 3 cards');
assert_eq($posTopId, $posCards[0]['id'], 'at_top card is first in column');
assert_eq($posFirstId, $posCards[1]['id'], 'default card order preserved (first)');
assert_eq($posSecondId, $posCards[2]['id'], 'default card order preserved (second)');

$r = req('create_card', ['column_id' => $posColId, 'title' => 'Pos Newest Top', 'at_top' => 1], 'POST', $adminCsrf);
$posNewestTopId = $r['body']['id'];
$r = req('list_cards', ['project_id' => $adminProjectId]);
$posCards = array_values(array_filter($r['body'], fn($c) => $c['column_id'] == $posColId));
assert_eq($posNewestTopId, $posCards[0]['id'], 'second at_top card becomes first');
assert_eq($posTopId, $posCards[1]['id'], 'previous top card shifted down');

// ─── MOVE ALL CARDS ──────────────────────────────────────
section('Move All Cards');

// Isolated source + target columns so the default columns are not disturbed.
$r = req('create_column', ['project_id' => $adminProjectId, 'name' => 'MASSRC'], 'POST', $adminCsrf);
$maSrc = $r['body']['id'];
$r = req('create_column', ['project_id' => $adminProjectId, 'name' => 'MASTGT'], 'POST', $adminCsrf);
$maTgt = $r['body']['id'];

// Target already holds two cards.
$r = req('create_card', ['column_id' => $maTgt, 'title' => 'X'], 'POST', $adminCsrf);
$maX = $r['body']['id'];
$r = req('create_card', ['column_id' => $maTgt, 'title' => 'Y'], 'POST', $adminCsrf);
$maY = $r['body']['id'];

// Source holds three cards.
$r = req('create_card', ['column_id' => $maSrc, 'title' => 'A'], 'POST', $adminCsrf);
$maA = $r['body']['id'];
$r = req('create_card', ['column_id' => $maSrc, 'title' => 'B'], 'POST', $adminCsrf);
$maB = $r['body']['id'];
$r = req('create_card', ['column_id' => $maSrc, 'title' => 'C'], 'POST', $adminCsrf);
$maC = $r['body']['id'];

$r = req('move_all_cards', ['column_id' => $maSrc], 'POST', $adminCsrf);
assert_eq(400, $r['status'], 'move all cards requires target_column_id');

$r = req('move_all_cards', ['target_column_id' => $maTgt], 'POST', $adminCsrf);
assert_eq(400, $r['status'], 'move all cards requires column_id');

$r = req('move_all_cards', ['column_id' => $maSrc, 'target_column_id' => $maSrc], 'POST', $adminCsrf);
assert_eq(400, $r['status'], 'move all cards rejects same source and target');

$r = req('move_all_cards', ['column_id' => $maSrc, 'target_column_id' => 999999], 'POST', $adminCsrf);
assert_eq(404, $r['status'], 'move all cards rejects unknown target column');

$r = req('list_columns', ['project_id' => $memberProjectId]);
$maForeignCol = $r['body'][0]['id'] ?? 0;
$r = req('move_all_cards', ['column_id' => $maSrc, 'target_column_id' => $maForeignCol], 'POST', $adminCsrf);
assert_eq(404, $r['status'], 'move all cards rejects target column from another project');

$r = req('move_all_cards', ['column_id' => $maSrc, 'target_column_id' => $maTgt], 'POST', $memberCsrf, $memberCookie);
assert_eq(403, $r['status'], 'member cannot move all cards in a project they do not own');

$r = req('move_all_cards', ['column_id' => $maSrc, 'target_column_id' => $maTgt], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'move all cards succeeds');
assert_eq(3, $r['body']['moved'], 'reports how many cards moved');

$r = req('list_cards', ['project_id' => $adminProjectId]);
$maSrcCards = array_values(array_filter($r['body'], fn($c) => $c['column_id'] == $maSrc));
$maTgtCards = array_values(array_filter($r['body'], fn($c) => $c['column_id'] == $maTgt));
assert_eq(0, count($maSrcCards), 'source column is empty after the move');
assert_eq(5, count($maTgtCards), 'target column holds all five cards');

// Source order is preserved and sits above the cards already in the target.
assert_eq($maA, $maTgtCards[0]['id'], 'moved card A is first in target');
assert_eq($maB, $maTgtCards[1]['id'], 'moved card B is second in target');
assert_eq($maC, $maTgtCards[2]['id'], 'moved card C is third in target');
assert_eq($maX, $maTgtCards[3]['id'], 'pre-existing target card X shifts down and keeps its order');
assert_eq($maY, $maTgtCards[4]['id'], 'pre-existing target card Y shifts down and keeps its order');
assert_eq([0, 1, 2, 3, 4], array_map(fn($c) => $c['position'], $maTgtCards), 'target positions stay contiguous 0-4');

// Moving from an empty column changes nothing.
$r = req('move_all_cards', ['column_id' => $maSrc, 'target_column_id' => $maTgt], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'move from an empty column succeeds');
assert_eq(0, $r['body']['moved'], 'move from an empty column reports 0 moved');

$r = req('list_cards', ['project_id' => $adminProjectId]);
$maTgtCards = array_values(array_filter($r['body'], fn($c) => $c['column_id'] == $maTgt));
assert_eq(5, count($maTgtCards), 'move from an empty column leaves the target untouched');
assert_eq([0, 1, 2, 3, 4], array_map(fn($c) => $c['position'], $maTgtCards), 'move from an empty column leaves target positions untouched');

// Source order survives gaps in the source positions.
// Moving the middle card out leaves the source positions non-contiguous.
$r = req('create_column', ['project_id' => $adminProjectId, 'name' => 'MAGAP'], 'POST', $adminCsrf);
$maGapSrc = $r['body']['id'];
$r = req('create_column', ['project_id' => $adminProjectId, 'name' => 'MAGAPT'], 'POST', $adminCsrf);
$maGapTgt = $r['body']['id'];

$r = req('create_card', ['column_id' => $maGapSrc, 'title' => 'G1'], 'POST', $adminCsrf);
$maG1 = $r['body']['id'];
$r = req('create_card', ['column_id' => $maGapSrc, 'title' => 'G2'], 'POST', $adminCsrf);
$maG2 = $r['body']['id'];
$r = req('create_card', ['column_id' => $maGapSrc, 'title' => 'G3'], 'POST', $adminCsrf);
$maG3 = $r['body']['id'];

$r = req('move_card', ['id' => $maG2, 'column_id' => $maGapTgt, 'position' => 0], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'move middle gap card out to create a gap');

$r = req('list_cards', ['project_id' => $adminProjectId]);
$maGapCards = array_values(array_filter($r['body'], fn($c) => $c['column_id'] == $maGapSrc));
assert_eq([0, 2], array_map(fn($c) => $c['position'], $maGapCards), 'source now has non-contiguous positions');

$r = req('move_all_cards', ['column_id' => $maGapSrc, 'target_column_id' => $maGapTgt], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'move all cards works with gapped source positions');
assert_eq(2, $r['body']['moved'], 'gapped source moves both remaining cards');

$r = req('list_cards', ['project_id' => $adminProjectId]);
$maGapTgtCards = array_values(array_filter($r['body'], fn($c) => $c['column_id'] == $maGapTgt));
assert_eq(3, count($maGapTgtCards), 'gapped target holds all three cards');
assert_eq($maG1, $maGapTgtCards[0]['id'], 'gapped source keeps order: G1 first');
assert_eq($maG3, $maGapTgtCards[1]['id'], 'gapped source keeps order: G3 second');
assert_eq($maG2, $maGapTgtCards[2]['id'], 'card moved in earlier now sits below the bulk move');
assert_eq([0, 1, 2], array_map(fn($c) => $c['position'], $maGapTgtCards), 'gapped target positions renumbered to 0-2');

$r = req('delete_column', ['id' => $maSrc], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'delete column still works after adding the menu');
$r = req('delete_column', ['id' => $maTgt], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'delete column with cards still works');

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

// list_comments returns comments for a card
$r = req('list_comments', ['card_id' => $commentCardId]);
assert_eq(200, $r['status'], 'list_comments returns 200');
assert_true(is_array($r['body']), 'list_comments returns an array');
assert_true(count($r['body']) >= 2, 'list_comments returns created comments');

// list_comments returns empty array for card with no comments
$emptyCard = req('create_card', ['column_id' => $colId, 'title' => 'No comments card'], 'POST', $adminCsrf);
$r = req('list_comments', ['card_id' => $emptyCard['body']['id']]);
assert_eq(200, $r['status'], 'list_comments returns 200 for empty card');
assert_eq(0, count($r['body']), 'list_comments returns empty array for card with no comments');

// list_comments returns 400 for missing card_id
$r = req('list_comments', []);
assert_eq(400, $r['status'], 'list_comments returns 400 for missing card_id');

// list_comments returns 404 for non-existent card
$r = req('list_comments', ['card_id' => 99999]);
assert_eq(404, $r['status'], 'list_comments returns 404 for non-existent card');

// list_comments returns comments with expected fields
$r = req('list_comments', ['card_id' => $commentCardId]);
assert_true(count($r['body']) > 0, 'list_comments returns at least one comment');
$first = $r['body'][0];
assert_true(isset($first['id']), 'comment has id field');
assert_true(isset($first['card_id']), 'comment has card_id field');
assert_true(isset($first['author_name']), 'comment has author_name field');
assert_true(isset($first['content']), 'comment has content field');
assert_true(isset($first['created_at']), 'comment has created_at field');

// delete_comment works
$r = req('delete_comment', ['id' => $adminCommentId], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'admin can delete comment');
$r = req('list_comments', ['card_id' => $commentCardId]);
assert_true(count($r['body']) < 2, 'comment was deleted');

// list_cards returns comment_count
$r = req('list_cards', ['project_id' => $adminProjectId]);
assert_eq(200, $r['status'], 'list_cards for comment_count check');
$commentCard = null;
foreach ($r['body'] as $c) { if ($c['id'] == $commentCardId) { $commentCard = $c; break; } }
assert_true($commentCard !== null, 'comment card found in list_cards');
assert_true(array_key_exists('comment_count', $commentCard), 'list_cards returns comment_count field');
assert_eq(1, (int) $commentCard['comment_count'], 'comment_count reflects remaining comments');

// unread_counts excludes own comments
$r = req('create_card', ['column_id' => $colId, 'title' => 'Unread test card'], 'POST', $adminCsrf);
$unreadCardId = $r['body']['id'];
$r = req('create_comment', ['card_id' => $unreadCardId, 'content' => 'Admin own comment'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'admin comments on unread card');
$r = req('unread_counts', ['project_id' => $adminProjectId]);
assert_eq(200, $r['status'], 'unread_counts succeeds');
assert_eq(0, (int) ($r['body'][$unreadCardId] ?? 0), 'own comment does not count as unread');
$r = req('create_comment', ['card_id' => $unreadCardId, 'content' => 'Member comment'], 'POST', $memberCsrf, $memberCookie);
assert_eq(200, $r['status'], 'member comments on unread card');
$r = req('unread_counts', ['project_id' => $adminProjectId]);
assert_eq(200, $r['status'], 'unread_counts after other comment');
assert_eq(1, (int) ($r['body'][$unreadCardId] ?? 0), 'other user comment counts as unread');

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

// Webhook UX: create with Telegram fields, update, test
$r = req('create_webhook', [
    'project_id' => $adminProjectId,
    'type' => 'telegram',
    'bot_token' => '123456:ABC-DEF',
    'chat_id' => '-1001234567890',
    'message_template' => '*{{event}}*: {{project}}',
], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'create telegram webhook with bot_token and chat_id');
$telegramHookId = $r['body']['id'];

$r = req('list_webhooks', ['project_id' => $adminProjectId]);
assert_eq(1, count($r['body']), 'one webhook after telegram create');
assert_eq('telegram', $r['body'][0]['type'], 'webhook type is telegram');
assert_eq('123456:ABC-DEF', $r['body'][0]['bot_token'], 'bot_token stored');
assert_eq('-1001234567890', $r['body'][0]['chat_id'], 'chat_id stored');

$r = req('update_webhook', [
    'id' => $telegramHookId,
    'message_template' => '{{event}} in {{project}}',
], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'update webhook message_template');

$r = req('list_webhooks', ['project_id' => $adminProjectId]);
assert_eq('{{event}} in {{project}}', $r['body'][0]['message_template'], 'message_template updated');

$r = req('test_webhook', ['id' => $telegramHookId], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'test webhook succeeds');

$r = req('delete_webhook', ['id' => $telegramHookId], 'POST', $adminCsrf);

// Create webhook with message_template (Slack)
$r = req('create_webhook', [
    'project_id' => $adminProjectId,
    'url' => 'https://hooks.slack.com/test',
    'type' => 'slack',
    'message_template' => '*{{event}}*: {{project}}',
], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'create slack webhook with message_template');
$slackHookId = $r['body']['id'];

$r = req('list_webhooks', ['project_id' => $adminProjectId]);
assert_eq('*{{event}}*: {{project}}', $r['body'][0]['message_template'], 'slack message_template stored');

$r = req('delete_webhook', ['id' => $slackHookId], 'POST', $adminCsrf);

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

$sensitiveFiles = ['tasssks.sqlite', 'test.sqlite', 'data.db', '.env', '.git/config', 'index.php.bak'];
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

// ─── RECOVERY KEY ───────────────────────────────────────────
section('Recovery Key');

// Login with recovery key (admin)
$rkCookie = tempnam(sys_get_temp_dir(), 'kanban_rk_');
$r = req('auth_login', ['email' => 'admin@test.com', 'recovery_key' => $setupRecoveryKey], 'POST', '', $rkCookie);
assert_eq(200, $r['status'], 'recovery key login succeeds');
assert_true(!empty($r['body']['recovery_key']), 'recovery key login returns new key');
assert_true($r['body']['recovery_key'] !== $setupRecoveryKey, 'new key differs from old key');
$rotatedKey = $r['body']['recovery_key'];
$rkCsrf = $r['body']['csrf_token'];

// Old key no longer works
@unlink($rkCookie);
$rkCookie = tempnam(sys_get_temp_dir(), 'kanban_rk_');
$r = req('auth_login', ['email' => 'admin@test.com', 'recovery_key' => $setupRecoveryKey], 'POST', '', $rkCookie);
assert_eq(403, $r['status'], 'old recovery key rejected after rotation');

// New rotated key works
@unlink($rkCookie);
$rkCookie = tempnam(sys_get_temp_dir(), 'kanban_rk_');
$r = req('auth_login', ['email' => 'admin@test.com', 'recovery_key' => $rotatedKey], 'POST', '', $rkCookie);
assert_eq(200, $r['status'], 'rotated recovery key works');
$rotatedKey2 = $r['body']['recovery_key'];
$rkCsrf = $r['body']['csrf_token'];

// Wrong recovery key fails
@unlink($rkCookie);
$rkCookie = tempnam(sys_get_temp_dir(), 'kanban_rk_');
$r = req('auth_login', ['email' => 'admin@test.com', 'recovery_key' => 'deadbeefdeadbeefdeadbeefdeadbeef'], 'POST', '', $rkCookie);
assert_eq(403, $r['status'], 'wrong recovery key returns 403');

// Recovery key with dashes (formatted) works
@unlink($rkCookie);
$rkCookie = tempnam(sys_get_temp_dir(), 'kanban_rk_');
$formatted = implode('-', str_split(strtoupper($rotatedKey2), 4));
$r = req('auth_login', ['email' => 'admin@test.com', 'recovery_key' => $formatted], 'POST', '', $rkCookie);
assert_eq(200, $r['status'], 'formatted recovery key (with dashes) works');
$rkCsrf = $r['body']['csrf_token'];

// Regenerate recovery key from account
$r = req('regenerate_recovery_key', ['password' => 'admin123'], 'POST', $rkCsrf, $rkCookie);
assert_eq(200, $r['status'], 'regenerate recovery key succeeds');
assert_true(!empty($r['body']['recovery_key']), 'regenerate returns new key');
$regenKey = $r['body']['recovery_key'];

// Regenerate with wrong password fails
$r = req('regenerate_recovery_key', ['password' => 'wrongpass'], 'POST', $rkCsrf, $rkCookie);
assert_eq(403, $r['status'], 'regenerate with wrong password fails');

// Login with regenerated key works
@unlink($rkCookie);
$rkCookie = tempnam(sys_get_temp_dir(), 'kanban_rk_');
$r = req('auth_login', ['email' => 'admin@test.com', 'recovery_key' => $regenKey], 'POST', '', $rkCookie);
assert_eq(200, $r['status'], 'regenerated key login works');
$afterRegenKey = $r['body']['recovery_key'];

// Recovery key in password field (fallback)
@unlink($rkCookie);
$rkCookie = tempnam(sys_get_temp_dir(), 'kanban_rk_');
$r = req('auth_login', ['email' => 'admin@test.com', 'password' => $afterRegenKey], 'POST', '', $rkCookie);
assert_eq(200, $r['status'], 'recovery key in password field works');
assert_true(!empty($r['body']['recovery_key']), 'password-field fallback rotates key');
$fallbackKey = $r['body']['recovery_key'];

// Formatted key (with dashes) in password field also works
@unlink($rkCookie);
$rkCookie = tempnam(sys_get_temp_dir(), 'kanban_rk_');
$formattedFallback = implode('-', str_split(strtoupper($fallbackKey), 4));
$r = req('auth_login', ['email' => 'admin@test.com', 'password' => $formattedFallback], 'POST', '', $rkCookie);
assert_eq(200, $r['status'], 'formatted key in password field works');
assert_true(!empty($r['body']['recovery_key']), 'formatted fallback rotates key');

// Password reset after recovery key login
assert_true(!empty($r['body']['password_reset']), 'recovery login sets password_reset flag');
$rkCsrf2 = $r['body']['csrf_token'];
$r = req('account_update', ['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'recovered99'], 'POST', $rkCsrf2, $rkCookie);
assert_eq(200, $r['status'], 'password reset without current password works');

// After reset, current password is required again
$r = req('account_update', ['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'another1'], 'POST', $rkCsrf2, $rkCookie);
assert_eq(403, $r['status'], 'flag cleared: current password required again');

// New password works for login
@unlink($rkCookie);
$rkCookie = tempnam(sys_get_temp_dir(), 'kanban_rk_');
$r = req('auth_login', ['email' => 'admin@test.com', 'password' => 'recovered99'], 'POST', '', $rkCookie);
assert_eq(200, $r['status'], 'new password after recovery works');

// Member recovery key login
@unlink($rkCookie);
$rkCookie = tempnam(sys_get_temp_dir(), 'kanban_rk_');
$r = req('auth_login', ['email' => 'member@test.com', 'recovery_key' => $memberRecoveryKey], 'POST', '', $rkCookie);
assert_eq(200, $r['status'], 'member recovery key login works');
assert_true(!empty($r['body']['recovery_key']), 'member gets rotated key');
assert_true(!empty($r['body']['password_reset']), 'member recovery login sets password_reset');

@unlink($rkCookie);

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

for ($i = 0; $i < 16; $i++) {
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
assert_eq(429, $httpCode, 'rate limit enforced after 15 attempts');

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

// ─── SECURITY: ATTACHMENT CSRF ───────────────────────────
section('Attachment CSRF');

$tmpFile = tempnam(sys_get_temp_dir(), 'kanban_att_');
file_put_contents($tmpFile, 'test attachment content');

$r = req('create_card', ['column_id' => $colId, 'title' => 'Attachment test card'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'create card for attachment test');
$attCardId = $r['body']['id'];

$r = reqUpload('upload_attachment', $tmpFile, $attCardId);
assert_eq(403, $r['status'], 'upload attachment without CSRF token rejected');

$r = reqUpload('upload_attachment', $tmpFile, $attCardId, 'badsigtok');
assert_eq(403, $r['status'], 'upload attachment with wrong CSRF token rejected');

$r = reqUpload('upload_attachment', $tmpFile, $attCardId, $adminCsrf);
assert_eq(200, $r['status'], 'upload attachment with valid CSRF token accepted');
assert_true(!empty($r['body']['id']), 'upload returns attachment id');

@unlink($tmpFile);

// ─── CLEANUP ─────────────────────────────────────────────
@unlink($memberCookie2);
section('Team Remove');

$r = req('team_remove', ['id' => $memberId], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'remove member');

$r = req('team_list');
assert_eq(1, count($r['body']), 'only admin remains');

// ─── SEARCH FEATURE ─────────────────────────────────────
section('Search Feature');

// Fetch raw HTML page to verify search markup and JS are present
$ch = curl_init("$BASE/");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_COOKIEJAR => $cookieFile,
]);
$html = curl_exec($ch);
curl_close($ch);

assert_true(str_contains($html, 'id="board-search"'), 'search input element present in HTML');
assert_true(str_contains($html, 'search-wrapper'), 'search-wrapper CSS class present');
assert_true(str_contains($html, 'search-count'), 'search-count element present');
assert_true(str_contains($html, 'search-clear'), 'search-clear button present');
assert_true(str_contains($html, 'searchCards('), 'searchCards JS function referenced');
assert_true(str_contains($html, 'clearSearch('), 'clearSearch JS function referenced');
assert_true(str_contains($html, 'updateColumnCounts('), 'updateColumnCounts JS function referenced');

// Toast notification system
assert_true(str_contains($html, '.toast'), 'toast CSS class present');
assert_true(str_contains($html, 'toast-error'), 'toast-error CSS class present');
assert_true(str_contains($html, 'toast-success'), 'toast-success CSS class present');
assert_true(str_contains($html, 'toast(msg'), 'toast JS function defined');
assert_true(str_contains($html, '@keyframes toast-in'), 'toast animation defined');
assert_true(!str_contains($html, 'id="account-error"'), 'no inline account-error element');
assert_true(!str_contains($html, 'id="team-error"'), 'no inline team-error element');
assert_true(!str_contains($html, 'id="smtp-msg"'), 'no inline smtp-msg element');
assert_true(!str_contains($html, 'alert('), 'no alert() calls');

// Verify list_cards returns title and description (data contract for client-side search)
$r = req('create_card', ['column_id' => $colId, 'title' => 'Search Alpha', 'description' => 'Findable description content'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'create card with description for search');
$searchCard1 = $r['body']['id'];

$r = req('create_card', ['column_id' => $colId, 'title' => 'Search Beta', 'description' => 'Another unique keyword'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'create second card with description');
$searchCard2 = $r['body']['id'];

$r = req('create_card', ['column_id' => $colId, 'title' => 'No match card', 'description' => ''], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'create card with empty description');
$searchCard3 = $r['body']['id'];

$r = req('list_cards', ['project_id' => $adminProjectId]);
assert_eq(200, $r['status'], 'list_cards succeeds');

$card1 = null;
$card2 = null;
$card3 = null;
foreach ($r['body'] as $c) {
    if ($c['id'] == $searchCard1) $card1 = $c;
    if ($c['id'] == $searchCard2) $card2 = $c;
    if ($c['id'] == $searchCard3) $card3 = $c;
}

assert_true($card1 !== null, 'search card 1 found in list_cards');
assert_eq('Search Alpha', $card1['title'], 'card title returned correctly');
assert_eq('Findable description content', $card1['description'], 'card description returned correctly');

assert_true($card2 !== null, 'search card 2 found in list_cards');
assert_eq('Search Beta', $card2['title'], 'second card title returned correctly');
assert_eq('Another unique keyword', $card2['description'], 'second card description returned correctly');

assert_true($card3 !== null, 'search card 3 found in list_cards');
assert_eq('No match card', $card3['title'], 'card with empty description has correct title');
assert_eq('', $card3['description'], 'empty description returned as empty string');

// Verify array_key_exists for the fields the JS search depends on
assert_true(array_key_exists('title', $card1), 'title field exists in card response');
assert_true(array_key_exists('description', $card1), 'description field exists in card response');
assert_true(array_key_exists('id', $card1), 'id field exists in card response (used for data-id)');

assert_true(str_contains($html, 'toggleTheme'), 'toggleTheme function present in HTML');
assert_true(str_contains($html, 'guest-theme-icon-moon'), 'guest theme moon icon present');
assert_true(str_contains($html, 'guest-theme-icon-sun'), 'guest theme sun icon present');
assert_true(str_contains($html, 'guest_can_view_time'), 'guest_can_view_time field referenced in guest navbar');

// ─── WYSIWYG Editor ──────────────────────────────────────
section('WYSIWYG Editor');

// Fetch the HTML page to verify CDN includes and editor markup
$ch = curl_init("$BASE/");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookieFile]);
$html = curl_exec($ch);
curl_close($ch);

// CDN includes
assert_true(str_contains($html, 'quill@2.0.3/dist/quill.snow.css'), 'Quill CSS CDN included');
assert_true(str_contains($html, 'quill@2.0.3/dist/quill.js'), 'Quill JS CDN included');
assert_true(str_contains($html, 'turndown@7.2.0/dist/turndown.js'), 'Turndown CDN included');
assert_true(str_contains($html, 'turndown-plugin-gfm'), 'Turndown GFM plugin CDN included');

// JS helpers and config
assert_true(str_contains($html, '_initQuill('), 'Quill init helper defined');
assert_true(str_contains($html, '_quillToMarkdown('), 'Quill-to-Markdown helper defined');
assert_true(str_contains($html, 'new TurndownService('), 'TurndownService instantiated');
assert_true(str_contains($html, 'DividerBlot'), 'Custom HR blot registered');
assert_true(str_contains($html, 'QUILL_TOOLBAR'), 'Toolbar config defined');
assert_true(str_contains($html, 'coerceOlToUl'), 'OL-to-UL coercion active');
assert_true(str_contains($html, 'forcedBullet'), 'Turndown forces OL to bullets');

// marked.js configured for target="_blank"
assert_true(str_contains($html, 'target="_blank"') || str_contains($html, "target=\"_blank\""), 'marked.js link renderer adds target=_blank');
assert_true(str_contains($html, 'noopener noreferrer'), 'Links get rel=noopener noreferrer');
assert_true(preg_match('/link\s*\(\s*href\s*,\s*title\s*,\s*text\s*\)/', $html), 'marked.js link renderer uses v12 positional params (href, title, text)');
assert_true(!str_contains($html, 'parseInline'), 'marked.js renderer does not call removed parseInline');

// Editor containers (Quill mounts on divs, not textareas)
assert_true(str_contains($html, 'id="new-card-desc"'), 'New card description editor container exists');
assert_true(str_contains($html, 'id="new-comment"'), 'New comment editor container exists');
assert_true(str_contains($html, 'quill-wrap'), 'Quill wrapper class used');
assert_true(str_contains($html, 'quill-wrap compact'), 'Compact Quill wrapper for comments');

// DOMPurify allows target attr
assert_true(str_contains($html, 'ADD_ATTR'), 'DOMPurify configured to allow target attribute');

// Comment raw data attribute for edit roundtrip
assert_true(str_contains($html, 'data-raw'), 'Comments store raw markdown in data-raw attribute');

// CMD+ENTER submits comment
assert_true(str_contains($html, 'keydown'), 'keydown handler configured for comment submit');
assert_true(str_contains($html, 'metaKey'), 'CMD key binding exists');

// Markdown list styling
assert_true(str_contains($html, '.markdown-body ol'), 'CSS styles markdown-body ordered lists');
assert_true(str_contains($html, '.markdown-body ul'), 'CSS styles markdown-body unordered lists');
assert_true(str_contains($html, '.markdown-body li'), 'CSS styles markdown-body list items');
assert_true(str_contains($html, '.comment-author'), 'Comment author class exists');
assert_true(str_contains($html, '.comment-body'), 'Comment body class exists');
assert_true(str_contains($html, 'wrote:'), 'Comment shows "wrote:" after author name');
assert_true(str_contains($html, '.comment-body') && str_contains($html, 'margin-top: 16px'), 'Comment body has 16px top margin');

// ─── API TOKENS ─────────────────────────────────────────
section('API Tokens');

// Helper for bearer-token requests
function reqBearer(string $action, string $token, array $data = [], string $method = 'GET'): array {
    global $BASE;
    $url = "$BASE/?action=$action";
    $ch = curl_init();
    if ($method === 'GET' && $data) {
        $url .= '&' . http_build_query($data);
    }
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
    ]);
    $headers = ["Authorization: Bearer $token"];
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = substr($response, $headerSize);
    curl_close($ch);
    return ['status' => $httpCode, 'body' => json_decode($body, true) ?? [], 'raw' => $body];
}

// List tokens (none yet)
$r = req('list_api_tokens', [], 'GET', '', $cookieFile);
assert_eq(200, $r['status'], 'list_api_tokens returns 200');
assert_eq([], $r['body'], 'no tokens initially');

// Create token without name fails
$r = req('create_api_token', ['name' => ''], 'POST', $adminCsrf);
assert_eq(400, $r['status'], 'create token without name fails');

// Create token
$r = req('create_api_token', ['name' => 'Test Token'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'create token returns 200');
assert_true(!empty($r['body']['token']), 'raw token returned');
assert_eq('Test Token', $r['body']['name'], 'token name matches');
$apiToken = $r['body']['token'];
$tokenId = $r['body']['id'];

// List tokens (one now)
$r = req('list_api_tokens', [], 'GET', '', $cookieFile);
assert_eq(200, $r['status'], 'list tokens after creation');
assert_eq(1, count($r['body']), 'one token in list');
assert_eq('Test Token', $r['body'][0]['name'], 'token name in list');
assert_true(empty($r['body'][0]['token_hash'] ?? ''), 'hash not exposed in list');

// Use bearer token to access API (no CSRF, no session)
$r = reqBearer('list_projects', $apiToken);
assert_eq(200, $r['status'], 'bearer token grants access to list_projects');

// Use bearer token for POST (no CSRF needed)
$r = reqBearer('list_api_tokens', $apiToken);
assert_eq(200, $r['status'], 'bearer token grants access to list_api_tokens');

// Invalid bearer token
$r = reqBearer('list_projects', 'invalid_token_here');
assert_eq(401, $r['status'], 'invalid bearer token returns 401');

// Revoke token
$r = req('revoke_api_token', ['id' => $tokenId], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'revoke token returns 200');

// Revoked token no longer works
$r = reqBearer('list_projects', $apiToken);
assert_eq(401, $r['status'], 'revoked token returns 401');

// List tokens (empty again)
$r = req('list_api_tokens', [], 'GET', '', $cookieFile);
assert_eq(0, count($r['body']), 'no tokens after revoke');

// Revoke non-existent token
$r = req('revoke_api_token', ['id' => 99999], 'POST', $adminCsrf);
assert_eq(404, $r['status'], 'revoke non-existent token returns 404');

// ─── TAGS ──────────────────────────────────────────────
section('Tags');

// Create tag with explicit color
$r = req('create_tag', ['project_id' => $adminProjectId, 'name' => 'Urgent', 'color' => '#ef4444'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'create tag with color returns 200');
$tagId = $r['body']['id'];

// List tags — verify color is persisted
$r = req('list_tags', ['project_id' => $adminProjectId], 'GET', '', $cookieFile);
assert_eq(200, $r['status'], 'list tags returns 200');
$urgentTag = array_values(array_filter($r['body'], fn($t) => $t['id'] == $tagId))[0] ?? null;
assert_eq('#ef4444', $urgentTag['color'], 'tag color persisted correctly');
assert_eq('Urgent', $urgentTag['name'], 'tag name persisted correctly');

// Create tag with default color (no color param)
$r = req('create_tag', ['project_id' => $adminProjectId, 'name' => 'Nice to have'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'create tag without color returns 200');
$tagId2 = $r['body']['id'];
$r = req('list_tags', ['project_id' => $adminProjectId], 'GET', '', $cookieFile);
$defaultTag = array_values(array_filter($r['body'], fn($t) => $t['id'] == $tagId2))[0] ?? null;
assert_eq('#5e81ac', $defaultTag['color'], 'tag gets default color when none specified');

// Create tag — missing name fails
$r = req('create_tag', ['project_id' => $adminProjectId, 'name' => '', 'color' => '#22c55e'], 'POST', $adminCsrf);
assert_eq(400, $r['status'], 'create tag without name fails');

// Clean up tags
req('delete_tag', ['id' => $tagId], 'POST', $adminCsrf);
req('delete_tag', ['id' => $tagId2], 'POST', $adminCsrf);

// Create admin API token for time tracking tests (bypasses rate limiter for team_add)
$r = req('create_api_token', ['name' => 'Admin For Time'], 'POST', $adminCsrf);
$adminBearerToken = $r['body']['token'];

// ─── TIME TRACKING ──────────────────────────────────────
section('Time Tracking');

// Create a fresh member for time tracking (original was removed in Team Remove tests)
$r = reqBearer('team_add', $adminBearerToken, ['name' => 'Time Member', 'email' => 'time@test.com', 'password' => 'time123', 'role' => 'member'], 'POST');
assert_eq(200, $r['status'], 'create time member');
$timeMemberId = $r['body']['id'];
// Create bearer token for the member (via direct DB — bearer auth bypasses rate limiter)
$timeMemberTokenRaw = bin2hex(random_bytes(32));
$timeMemberTokenHash = hash('sha256', $timeMemberTokenRaw);
$testDb = new PDO('sqlite:' . $TEST_DB, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$testDb->exec("INSERT INTO api_tokens (user_id, name, token_hash) VALUES ($timeMemberId, 'Time Member Token', '$timeMemberTokenHash')");
$testDb = null;
usleep(50_000);

// Create a card for time tracking tests
$r = req('create_card', ['column_id' => $colId, 'title' => 'Time tracked card'], 'POST', $adminCsrf);
$timeCardId = $r['body']['id'];

// Create time entry — missing card_id fails
$r = req('create_time_entry', ['minutes' => 60, 'worked_at' => '2026-08-17'], 'POST', $adminCsrf);
assert_eq(400, $r['status'], 'create time entry without card_id fails');

// Create time entry — zero minutes fails
$r = req('create_time_entry', ['card_id' => $timeCardId, 'minutes' => 0, 'worked_at' => '2026-08-17'], 'POST', $adminCsrf);
assert_eq(400, $r['status'], 'create time entry with 0 minutes fails');

// Create time entry — negative minutes fails
$r = req('create_time_entry', ['card_id' => $timeCardId, 'minutes' => -30, 'worked_at' => '2026-08-17'], 'POST', $adminCsrf);
assert_eq(400, $r['status'], 'create time entry with negative minutes fails');

// Create time entry — missing worked_at fails
$r = req('create_time_entry', ['card_id' => $timeCardId, 'minutes' => 60], 'POST', $adminCsrf);
assert_eq(400, $r['status'], 'create time entry without worked_at fails');

// Create valid time entry
$r = req('create_time_entry', ['card_id' => $timeCardId, 'minutes' => 90, 'worked_at' => '2026-08-15', 'note' => 'Planning session'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'create time entry returns 200');
assert_true(!empty($r['body']['id']), 'time entry id returned');
$timeEntryId1 = $r['body']['id'];

// Create another entry (different date)
$r = req('create_time_entry', ['card_id' => $timeCardId, 'minutes' => 120, 'worked_at' => '2026-08-16', 'note' => 'Implementation'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'create second time entry');
$timeEntryId2 = $r['body']['id'];

// Create entry as member (via bearer token)
$r = reqBearer('create_time_entry', $timeMemberTokenRaw, ['card_id' => $timeCardId, 'minutes' => 45, 'worked_at' => '2026-08-16', 'note' => 'Code review'], 'POST');
assert_eq(200, $r['status'], 'member can create time entry');
$memberTimeEntryId = $r['body']['id'];

// List time entries by card
$r = req('list_time_entries', ['card_id' => $timeCardId], 'GET', '', $cookieFile);
assert_eq(200, $r['status'], 'list time entries by card returns 200');
assert_eq(3, count($r['body']), 'three time entries for card');

// List time entries by project
$r = req('list_time_entries', ['project_id' => $adminProjectId], 'GET', '', $cookieFile);
assert_eq(200, $r['status'], 'list time entries by project returns 200');
assert_eq(3, count($r['body']), 'three time entries for project');

// List with date filter
$r = req('list_time_entries', ['project_id' => $adminProjectId, 'from' => '2026-08-16', 'to' => '2026-08-16'], 'GET', '', $cookieFile);
assert_eq(200, $r['status'], 'list time entries with date filter');
assert_eq(2, count($r['body']), 'two entries on 2026-08-16');

// Time report — aggregated
$r = req('time_report', ['project_id' => $adminProjectId], 'GET', '', $cookieFile);
assert_eq(200, $r['status'], 'time report returns 200');
assert_eq(255, $r['body']['total_minutes'], 'total minutes is 255 (90+120+45)');
assert_true(!empty($r['body']['entries']), 'report contains entries');

// Time report — with date filter
$r = req('time_report', ['project_id' => $adminProjectId, 'from' => '2026-08-15', 'to' => '2026-08-15'], 'GET', '', $cookieFile);
assert_eq(90, $r['body']['total_minutes'], 'filtered report total is 90');

// Delete own time entry (member deletes their own)
$r = reqBearer('delete_time_entry', $timeMemberTokenRaw, ['id' => $memberTimeEntryId], 'POST');
assert_eq(200, $r['status'], 'member can delete own time entry');

// Member cannot delete admin's entry
$r = reqBearer('delete_time_entry', $timeMemberTokenRaw, ['id' => $timeEntryId1], 'POST');
assert_eq(403, $r['status'], 'member cannot delete others time entry');

// Project owner (admin) can delete any entry
$r = req('delete_time_entry', ['id' => $timeEntryId2], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'project owner can delete any time entry');

// Verify remaining entries
$r = req('list_time_entries', ['project_id' => $adminProjectId], 'GET', '', $cookieFile);
assert_eq(1, count($r['body']), 'one entry remaining after deletions');

// Time entry stores author_name and card_title
$r = req('list_time_entries', ['card_id' => $timeCardId], 'GET', '', $cookieFile);
assert_eq('Admin', $r['body'][0]['author_name'], 'author_name stored on entry');
assert_true(!empty($r['body'][0]['card_title']), 'card_title stored on entry');

// Guest access to time report (disabled by default)
$guestToken = '';
$r = req('list_guests', ['project_id' => $adminProjectId], 'GET', '', $cookieFile);
if (count($r['body']) === 0) {
    $r = req('create_guest', ['project_id' => $adminProjectId, 'name' => 'Time Guest'], 'POST', $adminCsrf);
    $guestToken = $r['body']['token'];
} else {
    $guestToken = $r['body'][0]['token'];
}
$guestCookie = tempnam(sys_get_temp_dir(), 'kanban_guest_');
$r = req('time_report', ['project_id' => $adminProjectId, 'guest' => $guestToken], 'GET', '', $guestCookie);
assert_eq(403, $r['status'], 'guest cannot access time report by default');

// Enable guest time access
$r = req('update_project', ['id' => $adminProjectId, 'guest_can_view_time' => 1], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'enable guest_can_view_time');

// Guest can now access time report
$r = req('time_report', ['project_id' => $adminProjectId, 'guest' => $guestToken], 'GET', '', $guestCookie);
assert_eq(200, $r['status'], 'guest can access time report when enabled');
assert_true(isset($r['body']['total_minutes']), 'guest sees report data');

// list_projects includes guest_can_view_time
$r = req('list_projects');
$proj = array_values(array_filter($r['body'], fn($p) => $p['id'] === $adminProjectId))[0] ?? null;
assert_true(isset($proj['guest_can_view_time']), 'list_projects includes guest_can_view_time');
assert_eq(1, (int)$proj['guest_can_view_time'], 'guest_can_view_time is 1 after enabling');

// Guest cannot create time entries (403 from CSRF check since guest has no CSRF token)
$r = req('create_time_entry', ['card_id' => $timeCardId, 'minutes' => 30, 'worked_at' => '2026-08-17'], 'POST', '', $guestCookie);
assert_true($r['status'] === 401 || $r['status'] === 403, 'guest cannot create time entries');

// CSV export
$r = req('time_report', ['project_id' => $adminProjectId, 'format' => 'csv'], 'GET', '', $cookieFile);
assert_eq(200, $r['status'], 'csv export returns 200');
assert_true(str_contains($r['raw'], 'Card,Author,Minutes,Date,Note'), 'csv has header row');

// --- Start/End time feature ---

// Create entry with start_time and end_time → auto-calculates minutes
$r = req('create_time_entry', ['card_id' => $timeCardId, 'start_time' => '09:00', 'end_time' => '11:30', 'worked_at' => '2026-08-18'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'create time entry with start/end times');
$startEndEntryId = $r['body']['id'];

// List shows start_time, end_time, and calculated minutes (150 = 2h30m)
$r = req('list_time_entries', ['card_id' => $timeCardId], 'GET', '', $cookieFile);
$startEndEntry = array_values(array_filter($r['body'], fn($e) => $e['id'] == $startEndEntryId))[0] ?? null;
assert_eq('09:00', $startEndEntry['start_time'], 'start_time stored');
assert_eq('11:30', $startEndEntry['end_time'], 'end_time stored');
assert_eq(150, (int)$startEndEntry['minutes'], 'minutes auto-calculated from start/end (2h30m = 150)');

// Create in-progress entry (start_time only, no end_time, no minutes)
$r = req('create_time_entry', ['card_id' => $timeCardId, 'start_time' => '14:00', 'worked_at' => '2026-08-18'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'create in-progress entry (start_time only)');
$inProgressEntryId = $r['body']['id'];

// In-progress entry has minutes=0
$r = req('list_time_entries', ['card_id' => $timeCardId], 'GET', '', $cookieFile);
$inProgressEntry = array_values(array_filter($r['body'], fn($e) => $e['id'] == $inProgressEntryId))[0] ?? null;
assert_eq('14:00', $inProgressEntry['start_time'], 'in-progress start_time stored');
assert_eq(null, $inProgressEntry['end_time'], 'in-progress end_time is null');
assert_eq(0, (int)$inProgressEntry['minutes'], 'in-progress minutes is 0');

// Update time entry — fill end_time → auto-calculates minutes
$r = req('update_time_entry', ['id' => $inProgressEntryId, 'end_time' => '16:45'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'update time entry with end_time');

// Verify update calculated minutes (14:00→16:45 = 165 min)
$r = req('list_time_entries', ['card_id' => $timeCardId], 'GET', '', $cookieFile);
$updatedEntry = array_values(array_filter($r['body'], fn($e) => $e['id'] == $inProgressEntryId))[0] ?? null;
assert_eq('16:45', $updatedEntry['end_time'], 'end_time updated');
assert_eq(165, (int)$updatedEntry['minutes'], 'minutes recalculated after update (2h45m = 165)');

// Update time entry — change note
$r = req('update_time_entry', ['id' => $inProgressEntryId, 'note' => 'Updated note'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'update time entry note');
$r = req('list_time_entries', ['card_id' => $timeCardId], 'GET', '', $cookieFile);
$updatedEntry = array_values(array_filter($r['body'], fn($e) => $e['id'] == $inProgressEntryId))[0] ?? null;
assert_eq('Updated note', $updatedEntry['note'], 'note updated');

// Update time entry — not found
$r = req('update_time_entry', ['id' => 99999, 'note' => 'x'], 'POST', $adminCsrf);
assert_eq(404, $r['status'], 'update non-existent time entry returns 404');

// Update time entry — member cannot update admin's entry
$r = reqBearer('update_time_entry', $timeMemberTokenRaw, ['id' => $inProgressEntryId, 'note' => 'Hijack'], 'POST');
assert_eq(403, $r['status'], 'member cannot update others time entry');

// Zero minutes still fails without start_time
$r = req('create_time_entry', ['card_id' => $timeCardId, 'minutes' => 0, 'worked_at' => '2026-08-18'], 'POST', $adminCsrf);
assert_eq(400, $r['status'], 'zero minutes without start_time still fails');

// Clean up: delete test entries
$r = req('delete_time_entry', ['id' => $startEndEntryId], 'POST', $adminCsrf);
$r = req('delete_time_entry', ['id' => $inProgressEntryId], 'POST', $adminCsrf);

// ─── UPDATES ──────────────────────────────────────────────
section('Updates');

// auth_status returns version field
$r = req('auth_status');
assert_true(isset($r['body']['version']), 'auth_status returns version field');
assert_eq('0.3.0', $r['body']['version'], 'version matches expected value');

// auth_status returns update_available field
assert_true(array_key_exists('update_available', $r['body']), 'auth_status returns update_available field');
assert_eq(false, $r['body']['update_available'], 'update_available is false initially');

// check_update requires admin (unauthenticated returns 403)
$noAuthCookie = tempnam(sys_get_temp_dir(), 'kanban_noauth_');
$r = req('check_update', [], 'GET', '', $noAuthCookie);
assert_eq(403, $r['status'], 'check_update requires admin');
@unlink($noAuthCookie);

// check_update succeeds for admin (will fail to fetch from GitHub in test, but endpoint works)
$r = req('check_update', [], 'GET', '', $cookieFile);
assert_true(in_array($r['status'], [200, 502]), 'check_update returns 200 or 502 (network)');

// GITHUB_RAW_URL defined in CONFIGURATION section (before API router)
$source = file_get_contents(__DIR__ . '/index.php');
$configPos = strpos($source, 'CONFIGURATION');
$routerPos = strpos($source, 'API ROUTER');
$constPos = strpos($source, "define('GITHUB_RAW_URL'");
assert_true($configPos !== false, 'CONFIGURATION section exists');
assert_true($routerPos !== false, 'API ROUTER section exists');
assert_true($constPos !== false, 'GITHUB_RAW_URL constant defined');
assert_true($constPos < $routerPos, 'GITHUB_RAW_URL defined before API router');

// apply_update requires admin (unauthenticated returns 403)
$noAuthCookie2 = tempnam(sys_get_temp_dir(), 'kanban_noauth_');
$r = req('apply_update', [], 'POST', '', $noAuthCookie2);
assert_eq(403, $r['status'], 'apply_update requires admin');
@unlink($noAuthCookie2);

// apply_update fails without prior check
$r = req('apply_update', [], 'POST', $adminCsrf, $cookieFile);
assert_eq(400, $r['status'], 'apply_update fails without prior check');

// index.php.bak blocked by security
$ch = curl_init("$BASE/index.php.bak");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => false]);
curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
assert_eq(403, $code, 'index.php.bak blocked by security');

@unlink($guestCookie);

// ─── PROJECT REPORTING SETTINGS ─────────────────────────
section('Project Reporting Settings');

// New project has default week_start_day=1 (Monday) and cycle_reset_day=1
$r = req('create_project', ['name' => 'Reporting Test'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'create reporting test project');
$reportProjId = $r['body']['id'];

$r = req('list_projects');
$rp = array_values(array_filter($r['body'], fn($p) => $p['id'] === $reportProjId))[0] ?? null;
assert_eq(1, (int)($rp['week_start_day'] ?? -1), 'default week_start_day is 1 (Monday)');
assert_eq(1, (int)($rp['cycle_reset_day'] ?? -1), 'default cycle_reset_day is 1');

// Update week_start_day to 0 (Sunday)
$r = req('update_project', ['id' => $reportProjId, 'week_start_day' => 0], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'update week_start_day to 0');

$r = req('list_projects');
$rp = array_values(array_filter($r['body'], fn($p) => $p['id'] === $reportProjId))[0] ?? null;
assert_eq(0, (int)$rp['week_start_day'], 'week_start_day persisted as 0');

// Update cycle_reset_day to 25
$r = req('update_project', ['id' => $reportProjId, 'cycle_reset_day' => 25], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'update cycle_reset_day to 25');

$r = req('list_projects');
$rp = array_values(array_filter($r['body'], fn($p) => $p['id'] === $reportProjId))[0] ?? null;
assert_eq(25, (int)$rp['cycle_reset_day'], 'cycle_reset_day persisted as 25');

// Validate week_start_day range (must be 0-6)
$r = req('update_project', ['id' => $reportProjId, 'week_start_day' => 7], 'POST', $adminCsrf);
assert_eq(400, $r['status'], 'week_start_day=7 rejected');

$r = req('update_project', ['id' => $reportProjId, 'week_start_day' => -1], 'POST', $adminCsrf);
assert_eq(400, $r['status'], 'week_start_day=-1 rejected');

// Validate cycle_reset_day range (must be 1-31)
$r = req('update_project', ['id' => $reportProjId, 'cycle_reset_day' => 32], 'POST', $adminCsrf);
assert_eq(400, $r['status'], 'cycle_reset_day=32 rejected');

$r = req('update_project', ['id' => $reportProjId, 'cycle_reset_day' => 0], 'POST', $adminCsrf);
assert_eq(400, $r['status'], 'cycle_reset_day=0 rejected');

// Valid edge cases
$r = req('update_project', ['id' => $reportProjId, 'week_start_day' => 6], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'week_start_day=6 (Saturday) accepted');

$r = req('update_project', ['id' => $reportProjId, 'cycle_reset_day' => 31], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'cycle_reset_day=31 accepted');

// Both fields can be updated together
$r = req('update_project', ['id' => $reportProjId, 'week_start_day' => 1, 'cycle_reset_day' => 15], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'both fields updated together');

$r = req('list_projects');
$rp = array_values(array_filter($r['body'], fn($p) => $p['id'] === $reportProjId))[0] ?? null;
assert_eq(1, (int)$rp['week_start_day'], 'week_start_day=1 after combined update');
assert_eq(15, (int)$rp['cycle_reset_day'], 'cycle_reset_day=15 after combined update');

// Cleanup reporting test project
req('delete_project', ['id' => $reportProjId], 'POST', $adminCsrf);

// ─── LOCALE SETTINGS ────────────────────────────────────
section('Locale Settings');

// auth_status returns locale field (default 'en')
$r = req('auth_status');
assert_true(isset($r['body']['locale']), 'auth_status returns locale field');
assert_eq('en', $r['body']['locale'], 'default locale is en');

// Set locale to pt-BR
$r = req('auth_set_locale', ['locale' => 'pt-BR'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'set locale to pt-BR');

// Verify persistence
$r = req('auth_status');
assert_eq('pt-BR', $r['body']['locale'], 'locale persisted as pt-BR');

// Set locale to de
$r = req('auth_set_locale', ['locale' => 'de'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'set locale to de');

$r = req('auth_status');
assert_eq('de', $r['body']['locale'], 'locale persisted as de');

// Invalid locale rejected
$r = req('auth_set_locale', ['locale' => 'xx-INVALID'], 'POST', $adminCsrf);
assert_eq(400, $r['status'], 'invalid locale rejected');

// Empty locale rejected
$r = req('auth_set_locale', ['locale' => ''], 'POST', $adminCsrf);
assert_eq(400, $r['status'], 'empty locale rejected');

// Member cannot set locale
$r = req('auth_set_locale', ['locale' => 'fr'], 'POST', $memberCsrf, $memberCookie);
assert_eq(403, $r['status'], 'member cannot set locale');

// Reset locale back to en
$r = req('auth_set_locale', ['locale' => 'en'], 'POST', $adminCsrf);
assert_eq(200, $r['status'], 'reset locale to en');

// ─── CODE QUALITY ────────────────────────────────────────
section('Code Quality');

$src = file_get_contents(__DIR__ . '/index.php');

// Helper functions exist
assert_true(str_contains($src, 'function setSetting('), 'setSetting helper defined');
assert_true(str_contains($src, 'function rotateRecoveryKey('), 'rotateRecoveryKey helper defined');
assert_true(str_contains($src, 'function formatEventText('), 'formatEventText helper defined');

// Helpers are actually used (not dead code)
assert_true(substr_count($src, 'setSetting(') >= 3, 'setSetting used in multiple places');
assert_true(substr_count($src, 'rotateRecoveryKey(') >= 3, 'rotateRecoveryKey used in multiple places');
assert_true(substr_count($src, 'formatEventText(') >= 3, 'formatEventText used in multiple places');

// No leftover duplication of the patterns these helpers replaced
$rawUpsertCount = preg_match_all('/INSERT INTO settings.*ON CONFLICT.*DO UPDATE/', $src);
assert_eq(1, $rawUpsertCount, 'settings upsert only appears in setSetting helper');

$rawRotateCount = preg_match_all('/UPDATE users SET recovery_key_hash/', $src);
assert_eq(1, $rawRotateCount, 'recovery key update only appears in rotateRecoveryKey helper');

// Dead code removed
assert_true(!str_contains($src, 'QUILL_TOOLBAR_FULL'), 'QUILL_TOOLBAR_FULL removed (consolidated)');
assert_true(!str_contains($src, 'QUILL_TOOLBAR_COMPACT'), 'QUILL_TOOLBAR_COMPACT removed (consolidated)');

// Quill toolbar must not be focusable (sibling query, not ancestor closest)
assert_true(str_contains($src, "parentNode.querySelector('.ql-toolbar')"), 'Quill toolbar found via sibling query (not closest)');
assert_true(!str_contains($src, "closest('.ql-toolbar')"), 'Quill toolbar does not use closest (broken for sibling)');
assert_true(str_contains($src, "toolbar.querySelectorAll('button, select')") && str_contains($src, "tabindex', '-1')"), 'Toolbar children get tabindex=-1 to remove from tab order');

// selectTagColor must not hardcode a specific hidden input ID (bug: project settings uses different ID)
assert_true(!str_contains($src, "selectTagColor(el, color) {\n") || !str_contains($src, "\$('#new-tag-color').val(color)"), 'selectTagColor does not hardcode new-tag-color input');

// formatDate helper exists and uses Intl.DateTimeFormat
assert_true(str_contains($src, 'formatDate('), 'formatDate helper defined');
assert_true(str_contains($src, 'Intl.DateTimeFormat'), 'formatDate uses Intl.DateTimeFormat');

// loadComments error handling prevents stuck "Loading..." state
assert_true(str_contains($src, 'loadComments(cardId)'), 'loadComments function defined');
assert_true(str_contains($src, "catch (e)") && str_contains($src, 'Failed to load comments'), 'loadComments has try/catch with fallback message');
assert_true(str_contains($src, '.fail(()') && str_contains($src, 'Failed to load comments'), 'loadComments has .fail() handler');
assert_true(str_contains($src, 'safeContent') && str_contains($src, "c.content || ''"), 'loadComments null-safes comment content');

// loadProjectWatchState null-user guard prevents crash
assert_true(str_contains($src, 'this.user && watchers.some'), 'loadProjectWatchState null-checks this.user');

// Footer must not overlap content on long pages (flex layout, not fixed)
assert_true(str_contains($src, "flex-direction: column") && str_contains($src, "min-height: 100vh"), 'Body uses flex column with min-height 100vh');
assert_true(!str_contains($src, "position: fixed") || !preg_match('/\.app-footer\s*\{[^}]*position\s*:\s*fixed/', $src), 'Footer is not position:fixed');
assert_true(str_contains($src, "margin-top: auto"), 'Footer uses margin-top:auto to stay at bottom');

// Text replacements: typographic shortcuts
assert_true(str_contains($src, 'TEXT_REPLACEMENTS'), 'TEXT_REPLACEMENTS map defined');
assert_true(str_contains($src, "':check:'") && str_contains($src, "'\\u2705'"), ':check: maps to checkmark');
assert_true(str_contains($src, "':cross:'") && str_contains($src, "'\\u274C'"), ':cross: maps to cross');
assert_true(str_contains($src, "':x:'") && str_contains($src, "'\\u274C'"), ':x: maps to cross');
assert_true(str_contains($src, "'->'") && str_contains($src, "'\\u2192'"), "-> maps to right arrow");
assert_true(str_contains($src, "':arrow-right:'") && str_contains($src, "'\\u2192'"), ":arrow-right: maps to right arrow");
assert_true(str_contains($src, "'--'") && str_contains($src, "'\\u2014'"), "-- maps to em dash");
assert_true(str_contains($src, "':emdash:'") && str_contains($src, "'\\u2014'"), ":emdash: maps to em dash");
assert_true(str_contains($src, "'<-'") && str_contains($src, "'\\u2190'"), "<- maps to left arrow");
assert_true(str_contains($src, '_applyReplacements('), '_applyReplacements helper defined');
assert_true(str_contains($src, '_initQuillReplacements('), '_initQuillReplacements helper defined');
assert_true(str_contains($src, 'quill.getText()'), 'Quill handler checks full editor text (not just delta fragment)');

// Webhook UX overhaul: preset-specific fields, edit, test
assert_true(str_contains($src, 'togglePresetFields()'), 'togglePresetFields function defined');
assert_true(str_contains($src, 'editWebhook('), 'editWebhook function defined');
assert_true(str_contains($src, 'testWebhook('), 'testWebhook function defined');
assert_true(str_contains($src, "webhook-bot-token") && str_contains($src, "webhook-chat-id"), 'Telegram fields (bot-token, chat-id) exist in form');
assert_true(str_contains($src, "webhook-slack-template"), 'Slack template field exists in form');
assert_true(str_contains($src, "form-group-hint"), 'form-group-hint CSS class used for setup instructions');
assert_true(str_contains($src, "update_webhook") && str_contains($src, "test_webhook"), 'update_webhook and test_webhook API routes registered');
assert_true(str_contains($src, 'apiUpdateWebhook'), 'apiUpdateWebhook function defined');
assert_true(str_contains($src, 'apiTestWebhook'), 'apiTestWebhook function defined');
assert_true(str_contains($src, 'bot_token TEXT') || str_contains($src, "ADD COLUMN bot_token"), 'bot_token column added to project_webhooks');
assert_true(str_contains($src, 'chat_id TEXT') || str_contains($src, "ADD COLUMN chat_id"), 'chat_id column added to project_webhooks');
assert_true(str_contains($src, 'message_template TEXT') || str_contains($src, "ADD COLUMN message_template"), 'message_template column added to project_webhooks');
assert_true(str_contains($src, '{{event}}') || str_contains($src, '{{project}}'), 'Template variable placeholders supported');

// Card detail: column selector dropdown
assert_true(str_contains($src, 'moveCardToColumn('), 'moveCardToColumn function defined');
assert_true(str_contains($src, 'webhook-column') || str_contains($src, 'card-column-select') || str_contains($src, "id=\"card-column"), 'Column selector element exists in card detail');

// Card cross-references: #123 in description links to card
assert_true(str_contains($src, 'linkCardRefs('), 'linkCardRefs helper defined');
assert_true(str_contains($src, '#/card/') || str_contains($src, '#project/') || str_contains($src, '#projects/'), 'Card reference links use hash-based URL');

// Card reference autocomplete
assert_true(str_contains($src, '_initCardRefAutocomplete('), '_initCardRefAutocomplete helper defined');
assert_true(str_contains($src, 'card-ref-dropdown') || str_contains($src, 'cardRefDropdown'), 'Card ref dropdown element exists');
assert_true(str_contains($src, 'getBounds('), 'Uses Quill getBounds for dropdown positioning');

// Card title is required — show feedback instead of silently failing
assert_true(str_contains($src, "this.toast('Title is required.')"), 'createCard shows toast when title is empty');

// Card title inline editing (no separate modal)
assert_true(str_contains($src, 'editCardTitleInline('), 'editCardTitleInline helper defined');
assert_true(str_contains($src, 'ondblclick') && str_contains($src, 'editCardTitleInline'), 'title span has ondblclick for inline edit');
assert_true(str_contains($src, 'btn-edit-title') && str_contains($src, 'editCardTitleInline'), 'edit button calls editCardTitleInline');
assert_true(!str_contains($src, 'editCardTitle(') || str_contains($src, 'editCardTitleInline('), 'old editCardTitle modal removed');

// Card description inline editing (no separate modal)
assert_true(str_contains($src, 'editCardDescriptionInline('), 'editCardDescriptionInline helper defined');
assert_true(str_contains($src, 'saveCardDescriptionInline('), 'saveCardDescriptionInline helper defined');
assert_true(!str_contains($src, 'editCardDescription(') || str_contains($src, 'editCardDescriptionInline('), 'old editCardDescription modal removed');

// Refetch on window focus (vue-query-like refetchOnWindowFocus)
assert_true(str_contains($src, 'refetchActiveView('), 'refetchActiveView helper defined');
assert_true(str_contains($src, "addEventListener('focus'"), 'Window focus listener registered');
assert_true(str_contains($src, "addEventListener('visibilitychange'"), 'Visibility change listener registered');
assert_true(str_contains($src, "hasClass('active')") && str_contains($src, 'refetchActiveView'), 'refetchActiveView guards against open modal');
assert_true(str_contains($src, '_activeRequests'), 'In-flight request counter prevents overlapping refetches');

// ─── TAG FILTER ──────────────────────────────────────────
section('Tag Filter');

$src = file_get_contents(__DIR__ . '/index.php');

// Filter button exists in toolbar
assert_true(str_contains($src, 'tag-filter-btn'), 'tag-filter-btn CSS class defined');
assert_true(str_contains($src, 'toggleTagFilter('), 'toggleTagFilter JS function referenced');
assert_true(str_contains($src, 'applyTagFilter('), 'applyTagFilter JS function referenced');
assert_true(str_contains($src, 'clearTagFilter('), 'clearTagFilter JS function referenced');

// Filter state
assert_true(str_contains($src, '_activeFilters'), '_activeFilters state variable used');
assert_true(str_contains($src, 'new Set()') || str_contains($src, '_activeFilters ='), '_activeFilters initialized as Set');

// Filter dropdown markup
assert_true(str_contains($src, 'tag-filter-dropdown'), 'tag-filter-dropdown class defined');
assert_true(str_contains($src, 'tag-filter-row'), 'tag-filter-row class defined');
assert_true(str_contains($src, 'tag-filter-apply'), 'tag-filter-apply button class defined');

// Filter logic in renderBoard
assert_true(str_contains($src, '_activeFilters.size') || str_contains($src, '_activeFilters.has'), 'renderBoard checks _activeFilters');

// Filter clears on project switch
assert_true(str_contains($src, '_activeFilters') && str_contains($src, 'loadBoard('), '_activeFilters cleared on project load');

// Filter count badge
assert_true(str_contains($src, 'filter-count') || str_contains($src, 'filter-badge'), 'filter count badge element exists');

// CSS for filter dropdown
assert_true(str_contains($src, '.tag-filter-dropdown'), 'tag-filter-dropdown CSS rule defined');
assert_true(str_contains($src, '.tag-filter-row'), 'tag-filter-row CSS rule defined');

// ─── PROJECT SORT & SEARCH ──────────────────────────────
section('Project Sort & Search');

$src = file_get_contents(__DIR__ . '/index.php');

// DB migration: position column on projects
assert_true(str_contains($src, 'ALTER TABLE projects ADD COLUMN position'), 'migration adds position column to projects');
assert_true(str_contains($src, "PRAGMA user_version = 8") || str_contains($src, 'user_version = 8'), 'migration version bumped to 8');

// Backend: reorder_projects endpoint
assert_true(str_contains($src, 'reorder_projects'), 'reorder_projects route exists');
assert_true(str_contains($src, 'apiReorderProjects'), 'apiReorderProjects function defined');

// Backend: list_projects orders by position
assert_true(str_contains($src, 'ORDER BY position') && str_contains($src, 'list_projects'), 'list_projects orders by position');

// Frontend: SortableJS on project list
assert_true(str_contains($src, 'initProjectSortable'), 'initProjectSortable helper defined');
assert_true(str_contains($src, "projects-list") && str_contains($src, 'Sortable'), 'SortableJS initialized on projects-list');

// Frontend: project search
assert_true(str_contains($src, 'searchProjects('), 'searchProjects function defined');
assert_true(str_contains($src, 'project-search') || str_contains($src, 'projects-search'), 'project search input exists');

// ─── COLUMN MENU ─────────────────────────────────────────
section('Column Menu');

$src = file_get_contents(__DIR__ . '/index.php');

// Backend
assert_true(str_contains($src, "'move_all_cards' => apiMoveAllCards()"), 'move_all_cards route registered');
assert_true(str_contains($src, 'function apiMoveAllCards'), 'apiMoveAllCards function defined');

// Auth matches apiDeleteColumn
assert_true(preg_match('/function apiMoveAllCards.*?requireOwner\(/s', $src) === 1, 'apiMoveAllCards uses requireOwner');

// Target column lookup is scoped to the source project
assert_true(str_contains($src, 'SELECT id FROM columns_ WHERE id = ? AND project_id = ?'), 'target column lookup is scoped by project_id');

// Frontend: meatball menu replaces the delete button
assert_true(str_contains($src, 'column-menu'), 'column-menu class defined');
assert_true(str_contains($src, 'column-menu-toggle'), 'column-menu-toggle class defined');
assert_true(str_contains($src, 'toggleColumnMenu('), 'toggleColumnMenu JS function defined');
assert_true(str_contains($src, 'showMoveAllCards('), 'showMoveAllCards JS function defined');
assert_true(str_contains($src, 'Move all cards to'), 'Move all cards to menu item rendered');
assert_true(str_contains($src, 'Delete column'), 'Delete column menu item rendered');

// The meatball toggle must not reuse .dropdown-toggle, whose 768px rule hides its last svg
assert_true(preg_match('/\.column-menu-toggle\s*\{[^}]*\}/s', $src) === 1, 'column-menu-toggle has its own CSS rule');
assert_true(str_contains($src, 'class="column-menu-toggle"'), 'column menu toggle button uses only its own class');

// Regression: the document click-outside handler only exempts .dropdown. A column menu
// wrapped only in .column-menu was closed by the same click that opened it.
assert_true(str_contains($src, 'class="column-menu dropdown"'), 'column menu wrapper also carries the dropdown class');
assert_true(str_contains($src, "closest('.dropdown')"), 'click-outside handler still keys off .dropdown');

// The target select should default to the next column along the board, not the first one.
assert_true(str_contains($src, 'this.columns[this.columns.indexOf(col) + 1] || this.columns[0]'), 'default target is the next column, wrapping to the first');
assert_true(str_contains($src, "? ' selected' : ''"), 'the default target option is marked selected');

// SortableJS must not start a column drag from the menu
assert_true(str_contains($src, "filter: '.column-menu'"), 'column drag is filtered from the menu');

// ─── COLUMN COUNTS ───────────────────────────────────────
section('Column Counts');

$src = file_get_contents(__DIR__ . '/index.php');

// Dragging a card between columns moves the card in the DOM, but the per-column count
// badges were never refreshed, so they kept their old values after a manual move.
// initSortable has two card-move handlers (the guest path and the signed-in path) and
// both must refresh. Each handler is read as a bounded slice on purpose: a lazy regex
// over the whole file would match the updateColumnCounts definition further down and
// pass for the wrong reason. The bound of 1000 is measured: the last call in either
// handler sits at +752, and the next handler starts 2193 bytes later, so the slice
// cannot bleed into a neighbouring handler or reach the definition.
assert_true(str_contains($src, 'updateColumnCounts()'), 'updateColumnCounts helper exists');

$cardDragHandlers = 0;
$cardDragHandlersRefreshing = 0;
$cardDragHandlersCaching = 0;
$scanPos = 0;
while (($p = strpos($src, 'onEnd: (evt) => {', $scanPos)) !== false) {
    $cardDragHandlers++;
    $handler = substr($src, $p, 1000);
    if (str_contains($handler, 'updateColumnCounts()')) $cardDragHandlersRefreshing++;
    if (str_contains($handler, 'cached.column_id = newColumnId')) $cardDragHandlersCaching++;
    $scanPos = $p + 1;
}
assert_eq(2, $cardDragHandlers, 'initSortable has two card-move handlers (guest and signed-in)');
assert_eq($cardDragHandlers, $cardDragHandlersRefreshing, 'every card-move handler refreshes column counts');

// A drag moves the card in the DOM and on the server, but not in the this.cards cache.
// Leaving the cache stale made the move-all modal report "Move 0 cards" and made any
// later renderBoard() redraw the card back into the column it had left.
assert_eq($cardDragHandlers, $cardDragHandlersCaching, 'every card-move handler updates the client card cache');

$cardDragPos = strpos($src, "this.api('move_card'");
$cardDragTail = $cardDragPos === false ? '' : substr($src, $cardDragPos, 800);
assert_true(
    strpos($cardDragTail, 'updateColumnCounts()') !== false
    && strpos($cardDragTail, 'updateColumnCounts()') > strpos($cardDragTail, "'POST'"),
    'column counts refresh after move_card is sent'
);

// The counts must count the same thing renderBoard does: cards that are still visible,
// so the numbers stay correct while a tag filter or search is active.
assert_true(str_contains($src, ".card[data-id]:visible"), 'column counts count visible cards only');

// Other card mutations re-render the whole board, so they cannot drift.
assert_true(preg_match('/createCard\([^)]*\)\s*\{.*?refreshBoard\(\)/s', $src) === 1, 'createCard re-renders the board');
assert_true(preg_match('/deleteCard\([^)]*\)\s*\{.*?refreshBoard\(\)/s', $src) === 1, 'deleteCard re-renders the board');

// ─── ATTACHMENT LIGHTBOX ─────────────────────────────────
echo "\n" . colorBold('=== Attachment Lightbox ===') . "\n";
assert_true(str_contains($src, 'previewImage(src)'), 'previewImage function defined');
assert_true(str_contains($src, "overlay.className = 'lightbox'"), 'previewImage creates a .lightbox overlay');

// ESC must close the lightbox, not the card modal behind it. The lightbox check
// has to run before the #modal-overlay branch in the global keydown handler.
$globalKeydownPos = strpos($src, 'const mod = e.metaKey || e.ctrlKey;');
$escTail = $globalKeydownPos === false ? '' : substr($src, $globalKeydownPos, 700);
$lightboxEscPos = strpos($escTail, "\$('.lightbox').length");
$modalEscPos = strpos($escTail, "\$('#modal-overlay').hasClass('active')");
assert_true($lightboxEscPos !== false, 'Escape handler checks for an open lightbox');
assert_true(
    $lightboxEscPos !== false && $modalEscPos !== false && $lightboxEscPos < $modalEscPos,
    'Escape closes the lightbox before the card modal'
);

// ─── RESULTS ─────────────────────────────────────────────
echo "\n" . colorBold(str_repeat('=', 80)) . "\n";
if ($failed === 0) {
    echo colorGreen('ALL TESTS PASSED') . "\n";
} else {
    echo colorRed('TESTS FAILED') . "\n";
}
$total = $passed + $failed;
echo colorBold("Pass: $passed  Fail: $failed  Total: $total") . "\n";
echo colorBold(str_repeat('=', 80)) . "\n";

// Cleanup
proc_terminate($serverProc);
proc_close($serverProc);
@unlink($cookieFile);
@unlink($memberCookie);
@unlink($timeMemberCookie ?? '');
@unlink($TEST_DB);

exit($failed > 0 ? 1 : 0);
