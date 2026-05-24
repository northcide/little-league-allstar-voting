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
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        checkRateLimit($ip);
        validateCsrf();

        $data     = getInput();
        $voteCode = strtolower(trim($data['vote_code'] ?? ''));
        $word     = strtolower(trim($data['word'] ?? ''));

        if ($voteCode === '' || $word === '') jsonError('Election code and word are required');

        // Find election by vote_code (case-insensitive via utf8mb4_unicode_ci)
        $stmt = $db->prepare("SELECT id, name, status, expected_voters, current_round FROM elections WHERE LOWER(vote_code)=? AND status IN ('active','setup','completed') LIMIT 1");
        $stmt->execute([$voteCode]);
        $election = $stmt->fetch();
        if (!$election) {
            recordFailedLogin($ip);
            jsonError('Election code not found', 401);
        }
        if ($election['status'] === 'setup') {
            jsonError('This election has not started yet. Please wait for the admin to activate it.', 403);
        }
        if ($election['status'] === 'archived') {
            jsonError('This election is archived.', 403);
        }

        // Find word code for this election
        $stmt = $db->prepare("SELECT * FROM voter_codes WHERE election_id=? AND LOWER(word)=? LIMIT 1");
        $stmt->execute([$election['id'], $word]);
        $vc = $stmt->fetch();
        if (!$vc || $vc['revoked']) {
            recordFailedLogin($ip);
            jsonError('Word code not found for this election', 401);
        }

        clearRateLimit($ip);

        // Resume or claim
        $token = $vc['session_token'];
        if (!$token) {
            $token = makeSessionToken();
            $db->prepare("UPDATE voter_codes SET session_token=?, claimed_at=NOW(), last_seen_at=NOW() WHERE id=?")
               ->execute([$token, $vc['id']]);
            audit($db, (int)$election['id'], 'coach', 'claim_word', ['word' => $word]);
        } else {
            // Rotate the token on a fresh login from a different browser
            $token = makeSessionToken();
            $db->prepare("UPDATE voter_codes SET session_token=?, last_seen_at=NOW() WHERE id=?")
               ->execute([$token, $vc['id']]);
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
