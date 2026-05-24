<?php
require_once __DIR__ . '/helpers.php';

$action = getAction();
$db     = getDB();

try {
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

        $rounds = $db->prepare("SELECT id, round_num, picks_per_coach, picks_to_lock, is_tiebreak, state, has_tie_at_cutoff, finalized_at FROM rounds WHERE election_id=? ORDER BY round_num");
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
        $expected  = (int)($d['expected_voters'] ?? 0);
        $maxRoster = (int)($d['max_roster_size'] ?? 12);
        $rounds    = $d['rounds'] ?? [];

        if ($name === '')                 jsonError('Name is required');
        if (!preg_match('/^[a-z0-9_-]{3,64}$/', $voteCode))
                                          jsonError('Vote code must be 3–64 chars (letters, digits, dash, underscore)');
        if ($expected < 1 || $expected > 1000) jsonError('Expected voters must be 1–1000');
        if ($maxRoster < 1 || $maxRoster > 100) jsonError('Max roster size must be 1–100');
        if (!is_array($rounds) || count($rounds) < 1) jsonError('At least one round is required');

        // Validate rounds
        foreach ($rounds as $r) {
            $ppc = (int)($r['picks_per_coach'] ?? 0);
            $ptl = (int)($r['picks_to_lock']   ?? 0);
            if ($ppc < 1 || $ptl < 1)            jsonError('Each round needs picks_per_coach ≥ 1 and picks_to_lock ≥ 1');
            if ($ptl > $ppc)                     jsonError('picks_to_lock cannot exceed picks_per_coach');
        }

        // Uniqueness
        $exists = $db->prepare("SELECT id FROM elections WHERE LOWER(vote_code)=?");
        $exists->execute([$voteCode]);
        if ($exists->fetch()) jsonError('That vote code is already in use', 409);

        $db->beginTransaction();
        try {
            $ins = $db->prepare("INSERT INTO elections (name, vote_code, status, expected_voters, max_roster_size) VALUES (?,?,?,?,?)");
            $ins->execute([$name, $voteCode, 'setup', $expected, $maxRoster]);
            $eid = (int)$db->lastInsertId();

            $rIns = $db->prepare("INSERT INTO rounds (election_id, round_num, picks_per_coach, picks_to_lock) VALUES (?,?,?,?)");
            $i = 1;
            foreach ($rounds as $r) {
                $rIns->execute([$eid, $i++, (int)$r['picks_per_coach'], (int)$r['picks_to_lock']]);
            }

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

            audit($db, $eid, 'admin', 'create_election', ['name' => $name, 'rounds' => count($rounds)]);
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
        if (isset($d['expected_voters'])) { $sets[] = 'expected_voters=?'; $args[] = (int)$d['expected_voters']; }
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

        // Pre-flight: needs ≥1 round, ≥ picks_per_coach players for round 1, and ≥ expected_voters codes generated
        $playerCount = (int)$db->query("SELECT COUNT(*) FROM players WHERE election_id={$id} AND active=1")->fetchColumn();
        $codeCount   = (int)$db->query("SELECT COUNT(*) FROM voter_codes WHERE election_id={$id} AND revoked=0")->fetchColumn();
        $round1      = $db->query("SELECT picks_per_coach FROM rounds WHERE election_id={$id} ORDER BY round_num LIMIT 1")->fetchColumn();
        if (!$round1)                            jsonError('No rounds configured');
        if ($playerCount < (int)$round1)         jsonError("Need at least {$round1} active players to start round 1");
        if ($codeCount   < (int)$e['expected_voters']) jsonError("Generate at least {$e['expected_voters']} voter codes before activating");

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

    if ($action === 'update_rounds') {
        // Replace round configuration — only allowed if election still in setup
        $d  = getInput();
        $id = (int)($d['id'] ?? 0);
        $rounds = $d['rounds'] ?? [];
        if (!$id) jsonError('id required');
        $stmt = $db->prepare("SELECT status FROM elections WHERE id=?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) jsonError('Not found', 404);
        if ($row['status'] !== 'setup') jsonError('Cannot edit rounds after election is active');

        foreach ($rounds as $r) {
            $ppc = (int)($r['picks_per_coach'] ?? 0);
            $ptl = (int)($r['picks_to_lock']   ?? 0);
            if ($ppc < 1 || $ptl < 1) jsonError('Bad round config');
            if ($ptl > $ppc)          jsonError('picks_to_lock cannot exceed picks_per_coach');
        }

        $db->beginTransaction();
        $db->prepare("DELETE FROM rounds WHERE election_id=?")->execute([$id]);
        $rIns = $db->prepare("INSERT INTO rounds (election_id, round_num, picks_per_coach, picks_to_lock) VALUES (?,?,?,?)");
        $i = 1;
        foreach ($rounds as $r) {
            $rIns->execute([$id, $i++, (int)$r['picks_per_coach'], (int)$r['picks_to_lock']]);
        }
        audit($db, $id, 'admin', 'update_rounds', ['count' => count($rounds)]);
        $db->commit();
        jsonResponse(['ok' => true]);
    }

    jsonError('Unknown action', 400);
} catch (Throwable $e) {
    jsonError('Server error: ' . $e->getMessage(), 500);
}
