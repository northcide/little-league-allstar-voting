<?php
require_once __DIR__ . '/helpers.php';

$action = getAction();
$db     = getDB();

try {
    // ── Public: minimal list of active elections for the login screen ────────
    // Returns only name + vote_code (no counts, no status of setup/archived)
    // so an unauthenticated visitor can pick an election to log in to.
    if ($action === 'public_list') {
        $rows = $db->query(
            "SELECT name, vote_code FROM elections WHERE status='active' ORDER BY created_at DESC"
        )->fetchAll();
        jsonResponse(['elections' => $rows]);
    }

    requireAdmin();

    if ($action === 'list') {
        $rows = $db->query(
            "SELECT e.id, e.name, e.vote_code, e.status, e.expected_voters, e.max_roster_size, e.current_round,
                    (SELECT COUNT(*) FROM players p WHERE p.election_id=e.id AND p.active=1) AS player_count,
                    (SELECT COUNT(*) FROM voter_codes v WHERE v.election_id=e.id) AS code_count,
                    (SELECT COUNT(*) FROM rounds r WHERE r.election_id=e.id) AS round_count
             FROM elections e
             ORDER BY e.created_at DESC"
        )->fetchAll();
        jsonResponse(['elections' => $rows]);
    }

    if ($action === 'get') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('id required');
        $stmt = $db->prepare("SELECT * FROM elections WHERE id=?");
        $stmt->execute([$id]);
        $e = $stmt->fetch();
        if (!$e) jsonError('Not found', 404);

        $players = $db->prepare("SELECT id, name, jersey, sort_order, active FROM players WHERE election_id=? ORDER BY sort_order, name");
        $players->execute([$id]);

        $rounds = $db->prepare("SELECT id, round_num, picks_per_coach, picks_to_lock, state, has_tie_at_cutoff, finalized_at FROM rounds WHERE election_id=? ORDER BY round_num");
        $rounds->execute([$id]);

        jsonResponse([
            'election' => $e,
            'players'  => $players->fetchAll(),
            'rounds'   => $rounds->fetchAll(),
        ]);
    }

    if ($action === 'create') {
        $d         = getInput();
        $name      = trim($d['name'] ?? '');
        $voteCode  = strtolower(trim($d['vote_code'] ?? ''));
        $maxRoster = (int)($d['max_roster_size'] ?? 12);
        $coachPw   = (string)($d['coach_password'] ?? '');

        if ($name === '')                 jsonError('Name is required');
        if (!preg_match('/^[a-z0-9_-]{3,64}$/', $voteCode))
                                          jsonError('Vote code must be 3–64 chars (letters, digits, dash, underscore)');
        if ($maxRoster < 1 || $maxRoster > 100) jsonError('Max roster size must be 1–100');
        if (strlen($coachPw) < 4)         jsonError('Coach password must be at least 4 characters');

        // Uniqueness
        $exists = $db->prepare("SELECT id FROM elections WHERE LOWER(vote_code)=?");
        $exists->execute([$voteCode]);
        if ($exists->fetch()) jsonError('That vote code is already in use', 409);

        $db->beginTransaction();
        try {
            $ins = $db->prepare("INSERT INTO elections (name, vote_code, status, expected_voters, max_roster_size, coach_password) VALUES (?,?,?,0,?,?)");
            $ins->execute([$name, $voteCode, 'setup', $maxRoster, password_hash($coachPw, PASSWORD_DEFAULT)]);
            $eid = (int)$db->lastInsertId();

            // Players, if provided
            if (!empty($d['players']) && is_array($d['players'])) {
                $pIns = $db->prepare("INSERT INTO players (election_id, name, jersey, sort_order) VALUES (?,?,?,?)");
                $i = 0;
                foreach ($d['players'] as $p) {
                    $pname = trim($p['name'] ?? '');
                    if ($pname === '') continue;
                    $pIns->execute([$eid, $pname, $p['jersey'] ?? null, $i++]);
                }
            }

            audit($db, $eid, 'admin', 'create_election', ['name' => $name]);
            $db->commit();
            $_SESSION['admin_election_id'] = $eid;
            jsonResponse(['id' => $eid]);
        } catch (Throwable $e) {
            $db->rollBack();
            jsonError('Create failed: ' . $e->getMessage(), 500);
        }
    }

    if ($action === 'update') {
        $d   = getInput();
        $id  = (int)($d['id'] ?? 0);
        if (!$id) jsonError('id required');
        $stmt = $db->prepare("SELECT * FROM elections WHERE id=?");
        $stmt->execute([$id]);
        $e = $stmt->fetch();
        if (!$e) jsonError('Not found', 404);

        $sets = [];
        $args = [];
        if (isset($d['name']))            { $sets[] = 'name=?';            $args[] = trim($d['name']); }
        if (!empty($d['coach_password'])) {
            $pw = (string)$d['coach_password'];
            if (strlen($pw) < 4) jsonError('Coach password must be at least 4 characters');
            $sets[] = 'coach_password=?'; $args[] = password_hash($pw, PASSWORD_DEFAULT);
        }
        if (isset($d['max_roster_size'])) {
            $m = (int)$d['max_roster_size'];
            if ($m < 1 || $m > 100) jsonError('Max roster size must be 1–100');
            $sets[] = 'max_roster_size=?'; $args[] = $m;
        }
        if (!$sets) jsonError('Nothing to update');
        $args[] = $id;
        $db->prepare("UPDATE elections SET " . implode(',', $sets) . " WHERE id=?")->execute($args);
        audit($db, $id, 'admin', 'update_election', $d);
        jsonResponse(['ok' => true]);
    }

    if ($action === 'activate') {
        $d  = getInput();
        $id = (int)($d['id'] ?? 0);
        if (!$id) jsonError('id required');
        $stmt = $db->prepare("SELECT * FROM elections WHERE id=?");
        $stmt->execute([$id]);
        $e = $stmt->fetch();
        if (!$e) jsonError('Not found', 404);

        // Pre-flight: ≥2 active players. Coaches sign in dynamically via shared
        // password so there's no pre-generation requirement.
        $playerCount = (int)$db->query("SELECT COUNT(*) FROM players WHERE election_id={$id} AND active=1")->fetchColumn();
        if ($playerCount < 2) jsonError("Need at least 2 active players to activate the election");
        if (empty($e['coach_password'])) jsonError("Set a coach password before activating");

        $db->prepare("UPDATE elections SET status='active' WHERE id=?")->execute([$id]);
        audit($db, $id, 'admin', 'activate_election', []);
        jsonResponse(['ok' => true]);
    }

    if ($action === 'archive') {
        $d  = getInput();
        $id = (int)($d['id'] ?? 0);
        if (!$id) jsonError('id required');
        $db->prepare("UPDATE elections SET status='archived' WHERE id=?")->execute([$id]);
        audit($db, $id, 'admin', 'archive_election', []);
        jsonResponse(['ok' => true]);
    }

    if ($action === 'delete') {
        // Hard delete — cascades via FK to players, rounds, voter_codes,
        // submissions, ballot_picks, locked_roster. Requires the admin to
        // pass confirm_name matching the election name (typo guard).
        $d        = getInput();
        $id       = (int)($d['id'] ?? 0);
        $confirm  = trim($d['confirm_name'] ?? '');
        if (!$id) jsonError('id required');
        $stmt = $db->prepare("SELECT name FROM elections WHERE id=?");
        $stmt->execute([$id]);
        $e = $stmt->fetch();
        if (!$e) jsonError('Not found', 404);
        if ($confirm !== $e['name']) {
            jsonError('To confirm deletion, send confirm_name matching the election name exactly', 400);
        }
        audit($db, null, 'admin', 'delete_election', ['id' => $id, 'name' => $e['name']]);
        $db->prepare("DELETE FROM elections WHERE id=?")->execute([$id]);
        if (isset($_SESSION['admin_election_id']) && (int)$_SESSION['admin_election_id'] === $id) {
            unset($_SESSION['admin_election_id']);
        }
        jsonResponse(['ok' => true]);
    }

    if ($action === 'select') {
        $d  = getInput();
        $id = (int)($d['id'] ?? 0);
        if (!$id) jsonError('id required');
        $stmt = $db->prepare("SELECT id FROM elections WHERE id=?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) jsonError('Not found', 404);
        $_SESSION['admin_election_id'] = $id;
        jsonResponse(['ok' => true]);
    }

    jsonError('Unknown action', 400);
} catch (Throwable $e) {
    jsonError('Server error: ' . $e->getMessage(), 500);
}
