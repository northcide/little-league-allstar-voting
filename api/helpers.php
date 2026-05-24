<?php
// ── Headers ───────────────────────────────────────────────────────────────────
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

// ── Session ───────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_name('allstar_session');
    $lifetime = 8 * 60 * 60; // 8 hours
    ini_set('session.gc_maxlifetime', $lifetime);
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

// ── Database ──────────────────────────────────────────────────────────────────
if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(503);
    echo json_encode(['error' => 'Database not configured. Run api/setup.php first.']);
    exit;
}
require_once __DIR__ . '/config.php';

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}

// ── Response helpers ──────────────────────────────────────────────────────────
function jsonResponse(mixed $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function jsonError(string $message, int $status = 400): void {
    jsonResponse(['error' => $message], $status);
}

function getInput(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

function getAction(): string {
    return $_GET['action'] ?? $_POST['action'] ?? '';
}

function nowUtc(): string {
    return (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
}

// ── CSRF ──────────────────────────────────────────────────────────────────────
function validateCsrf(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        jsonError('Invalid or missing CSRF token', 403);
    }
}

// ── Rate limiting (file-based, per IP) ────────────────────────────────────────
function _rlFile(string $ip): string {
    return sys_get_temp_dir() . '/allstar_rl_' . md5($ip) . '.json';
}

function checkRateLimit(string $ip, int $max = 15): void {
    $file = _rlFile($ip);
    $data = file_exists($file) ? json_decode(file_get_contents($file), true) : null;
    if (!$data || time() > ($data['reset'] ?? 0)) return;
    if (($data['count'] ?? 0) >= $max) {
        jsonError('Too many login attempts. Please try again later.', 429);
    }
}

function recordFailedLogin(string $ip): void {
    $file = _rlFile($ip);
    $data = file_exists($file) ? json_decode(file_get_contents($file), true) : null;
    if (!$data || time() > ($data['reset'] ?? 0)) {
        $data = ['count' => 0, 'reset' => time() + 300];
    }
    $data['count']++;
    file_put_contents($file, json_encode($data), LOCK_EX);
}

function clearRateLimit(string $ip): void {
    $file = _rlFile($ip);
    if (file_exists($file)) unlink($file);
}

// ── PIN helpers ───────────────────────────────────────────────────────────────
function verifyPin(string $input, string $stored): bool {
    if ($stored === '') return false;
    if (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$2a$')) {
        return password_verify($input, $stored);
    }
    return hash_equals($stored, $input);
}

function hashPin(string $pin): string {
    return password_hash($pin, PASSWORD_DEFAULT);
}

// ── Auth helpers ──────────────────────────────────────────────────────────────
function currentRole(): string {
    return $_SESSION['role'] ?? '';
}

function requireAdmin(): void {
    if (currentRole() !== 'admin') jsonError('Admin access required', 403);
    validateCsrf();
}

function requireCoach(): void {
    if (currentRole() !== 'coach') jsonError('Coach access required', 403);
    validateCsrf();
}

function requireAuth(): void {
    if (!currentRole()) jsonError('Not authenticated', 401);
    validateCsrf();
}

// ── Coach context: resolve current voter code via session ─────────────────────
function currentVoterCode(PDO $db): ?array {
    if (currentRole() !== 'coach') return null;
    $token = $_SESSION['voter_token'] ?? '';
    if (!$token) return null;
    $stmt = $db->prepare(
        "SELECT vc.*, e.id AS e_id, e.name AS e_name, e.vote_code, e.status AS e_status,
                e.expected_voters, e.max_roster_size, e.current_round
         FROM voter_codes vc
         JOIN elections e ON e.id = vc.election_id
         WHERE vc.session_token = ? AND vc.revoked = 0"
    );
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

// ── Admin context: currently selected election (set via elections.php?select) ─
function currentAdminElectionId(): ?int {
    return isset($_SESSION['admin_election_id']) ? (int)$_SESSION['admin_election_id'] : null;
}

// ── Audit log ─────────────────────────────────────────────────────────────────
function audit(PDO $db, ?int $electionId, string $actor, string $action, array $detail = []): void {
    $stmt = $db->prepare(
        "INSERT INTO audit_log (election_id, actor, action, detail) VALUES (?,?,?,?)"
    );
    $stmt->execute([$electionId, $actor, $action, json_encode($detail)]);
}

// ── Random tokens ─────────────────────────────────────────────────────────────
function makeSessionToken(): string { return bin2hex(random_bytes(32)); }   // 64 hex chars
function makeBallotToken(): string  { return bin2hex(random_bytes(16)); }   // 32 hex chars

// ── Touch coach activity (for "logged in" counter) ────────────────────────────
function touchVoter(PDO $db, int $voterCodeId): void {
    $db->prepare("UPDATE voter_codes SET last_seen_at = NOW() WHERE id = ?")
       ->execute([$voterCodeId]);
}
