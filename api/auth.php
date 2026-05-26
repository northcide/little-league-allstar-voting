<?php
require_once __DIR__ . '/helpers.php';

$action = getAction();
$db     = getDB();

try {
    if ($action === 'check') {
        // Returns identity + CSRF token. Coach sessions also touch last_seen.
        $role = currentRole();
        $name = $db->query("SELECT value FROM settings WHERE `key`='league_name'")->fetchColumn();

        $result = [
            'role'        => $role ?: null,
            'league_name' => $name ?: 'Allstar',
            'csrf_token'  => $_SESSION['csrf_token'],
        ];

        if ($role === 'coach') {
            $vc = currentVoterCode($db);
            if (!$vc) {
                $_SESSION = [];
                jsonResponse(['role' => null, 'league_name' => $name ?: 'Allstar', 'csrf_token' => $_SESSION['csrf_token'] ?? '']);
            }
            touchVoter($db, (int)$vc['id']);
            $result['election'] = [
                'id'              => (int)$vc['e_id'],
                'name'            => $vc['e_name'],
                'status'          => $vc['e_status'],
                'expected_voters' => (int)$vc['expected_voters'],
                'current_round'   => (int)$vc['current_round'],
            ];
            $result['voter_word'] = $vc['word'];
        }

        if ($role === 'admin') {
            $eid = currentAdminElectionId();
            if ($eid) {
                $stmt = $db->prepare("SELECT id, name, status, expected_voters, current_round, vote_code FROM elections WHERE id=?");
                $stmt->execute([$eid]);
                $row = $stmt->fetch();
                if ($row) $result['election'] = $row;
            }
        }

        jsonResponse($result);
    }

    if ($action === 'admin_login') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        checkRateLimit($ip);
        validateCsrf();

        $data = getInput();
        $pin  = trim($data['pin'] ?? '');
        if ($pin === '') jsonError('PIN required');

        $stored = $db->query("SELECT value FROM settings WHERE `key`='admin_pin'")->fetchColumn();
        if (!verifyPin($pin, (string)$stored)) {
            recordFailedLogin($ip);
            jsonError('Incorrect PIN', 401);
        }

        clearRateLimit($ip);
        session_regenerate_id(true);
        $_SESSION['role'] = 'admin';
        $_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));

        jsonResponse([
            'role'       => 'admin',
            'csrf_token' => $_SESSION['csrf_token'],
        ]);
    }

    if ($action === 'coach_login') {
        // New flow (2026-05-26): coach enters the election's shared password.
        // We auto-assign the next sequential coach number, persist the
        // session_token on a voter_codes row, and rely on cookies to keep
        // this device tied to that number across page reloads.
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        checkRateLimit($ip);
        validateCsrf();

        $data     = getInput();
        $voteCode = strtolower(trim($data['vote_code'] ?? ''));
        $password = (string)($data['password'] ?? '');

        if ($voteCode === '' || $password === '') jsonError('Election and password are required');

        // Find election by vote_code (case-insensitive)
        $stmt = $db->prepare("SELECT id, name, status, expected_voters, current_round, coach_password FROM elections WHERE LOWER(vote_code)=? AND status IN ('active','completed') LIMIT 1");
        $stmt->execute([$voteCode]);
        $election = $stmt->fetch();
        if (!$election) {
            recordFailedLogin($ip);
            jsonError('Election not found or not active', 401);
        }
        if ($election['status'] === 'archived') {
            jsonError('This election is archived.', 403);
        }
        if (empty($election['coach_password']) || !password_verify($password, $election['coach_password'])) {
            recordFailedLogin($ip);
            jsonError('Wrong password', 401);
        }

        clearRateLimit($ip);

        // Resume if this session already has a voter_token for this election
        $existingToken = $_SESSION['voter_token'] ?? '';
        $vc = null;
        if ($existingToken) {
            $stmt = $db->prepare("SELECT * FROM voter_codes WHERE election_id=? AND session_token=? AND revoked=0 LIMIT 1");
            $stmt->execute([(int)$election['id'], $existingToken]);
            $vc = $stmt->fetch() ?: null;
        }

        if ($vc) {
            // Existing assignment for this device — just refresh last_seen
            $token = $vc['session_token'];
            $db->prepare("UPDATE voter_codes SET last_seen_at=NOW() WHERE id=?")->execute([$vc['id']]);
        } else {
            // Allocate the next sequential coach number for this election
            $db->beginTransaction();
            try {
                $maxStmt = $db->prepare("SELECT COALESCE(MAX(CAST(word AS UNSIGNED)), 0) FROM voter_codes WHERE election_id=?");
                $maxStmt->execute([(int)$election['id']]);
                $nextNum = (int)$maxStmt->fetchColumn() + 1;
                $token   = makeSessionToken();
                $ins = $db->prepare("INSERT INTO voter_codes (election_id, word, session_token, claimed_at, last_seen_at) VALUES (?,?,?,NOW(),NOW())");
                $ins->execute([(int)$election['id'], (string)$nextNum, $token]);
                $vcId = (int)$db->lastInsertId();
                audit($db, (int)$election['id'], 'coach', 'assign_coach', ['coach_num' => $nextNum]);
                $db->commit();
                $vc = ['id' => $vcId, 'word' => (string)$nextNum];
            } catch (Throwable $ex) {
                if ($db->inTransaction()) $db->rollBack();
                jsonError('Failed to assign a coach number', 500);
            }
        }

        session_regenerate_id(true);
        $_SESSION['role']        = 'coach';
        $_SESSION['voter_token'] = $token;
        $_SESSION['election_id'] = (int)$election['id'];
        $_SESSION['csrf_token']  = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));

        jsonResponse([
            'role'        => 'coach',
            'csrf_token'  => $_SESSION['csrf_token'],
            'voter_word'  => $vc['word'],
            'election'    => [
                'id'              => (int)$election['id'],
                'name'            => $election['name'],
                'status'          => $election['status'],
                'expected_voters' => (int)$election['expected_voters'],
                'current_round'   => (int)$election['current_round'],
            ],
        ]);
    }

    if ($action === 'logout') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        jsonResponse(['ok' => true]);
    }

    jsonError('Unknown action', 400);
} catch (Throwable $e) {
    jsonError('Server error: ' . $e->getMessage(), 500);
}
