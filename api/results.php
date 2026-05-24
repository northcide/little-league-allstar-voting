<?php
require_once __DIR__ . '/helpers.php';

$action = getAction();
$db     = getDB();

try {
    // Public results = winners + tie flag only. Available to coach.
    if ($action === 'public') {
        $rid = (int)($_GET['round_id'] ?? 0);
        if (!$rid) jsonError('round_id required');
        // Confirm coach has access to this round's election
        if (currentRole() === 'coach') {
            $vc = currentVoterCode($db);
            if (!$vc) jsonError('Session expired', 401);
            $check = $db->prepare("SELECT 1 FROM rounds WHERE id=? AND election_id=?");
            $check->execute([$rid, (int)$vc['e_id']]);
            if (!$check->fetch()) jsonError('Round not in your election', 403);
        } elseif (currentRole() === 'admin') {
            // ok
        } else {
            jsonError('Not authenticated', 401);
        }

        $r = $db->prepare("SELECT id, round_num, state, has_tie_at_cutoff, tie_player_ids_json, election_id FROM rounds WHERE id=?");
        $r->execute([$rid]);
        $round = $r->fetch();
        if (!$round) jsonError('Round not found', 404);
        if ($round['state'] !== 'finalized') jsonError('Not finalized', 409);

        $w = $db->prepare("SELECT player_id FROM locked_roster WHERE election_id=? AND locked_in_round=?");
        $w->execute([$round['election_id'], (int)$round['round_num']]);
        jsonResponse([
            'round_num'        => (int)$round['round_num'],
            'winners'          => array_map(fn($r) => (int)$r['player_id'], $w->fetchAll()),
            'has_tie'          => (bool)$round['has_tie_at_cutoff'],
            'tie_player_ids'   => json_decode($round['tie_player_ids_json'] ?? 'null', true) ?: [],
        ]);
    }

    // Admin-only full tally with vote counts
    if ($action === 'full') {
        requireAdmin();
        $rid = (int)($_GET['round_id'] ?? 0);
        if (!$rid) jsonError('round_id required');
        $eid = currentAdminElectionId();
        if (!$eid) jsonError('No election selected');

        $r = $db->prepare("SELECT * FROM rounds WHERE id=? AND election_id=?");
        $r->execute([$rid, $eid]);
        $round = $r->fetch();
        if (!$round) jsonError('Round not found', 404);

        // Tally — anonymity boundary: only join ballot_picks → players
        $tally = $db->prepare(
            "SELECT p.id AS player_id, p.name, p.jersey, COALESCE(t.cnt, 0) AS votes
             FROM players p
             LEFT JOIN (SELECT player_id, COUNT(*) AS cnt FROM ballot_picks WHERE round_id=? GROUP BY player_id) t
               ON t.player_id = p.id
             WHERE p.election_id=? AND p.active=1
             ORDER BY votes DESC, p.name ASC"
        );
        $tally->execute([$rid, $eid]);
        $rows = $tally->fetchAll();

        // Flag locked players from prior rounds (they should already not be in tally if ballot enforced it)
        $locked = $db->prepare("SELECT player_id, locked_in_round FROM locked_roster WHERE election_id=?");
        $locked->execute([$eid]);
        $lockedMap = [];
        foreach ($locked->fetchAll() as $l) $lockedMap[(int)$l['player_id']] = (int)$l['locked_in_round'];

        foreach ($rows as &$r2) {
            $r2['player_id'] = (int)$r2['player_id'];
            $r2['votes']     = (int)$r2['votes'];
            $r2['locked_in_round'] = $lockedMap[$r2['player_id']] ?? null;
        }
        unset($r2);

        $submitted = (int)$db->query("SELECT COUNT(*) FROM submissions WHERE round_id={$rid}")->fetchColumn();
        $expected  = (int)$db->query("SELECT expected_voters FROM elections WHERE id={$eid}")->fetchColumn();

        jsonResponse([
            'round' => [
                'id' => (int)$round['id'],
                'round_num' => (int)$round['round_num'],
                'state' => $round['state'],
                'picks_per_coach' => (int)$round['picks_per_coach'],
                'picks_to_lock' => (int)$round['picks_to_lock'],
                'has_tie_at_cutoff' => (bool)$round['has_tie_at_cutoff'],
                'tie_player_ids' => json_decode($round['tie_player_ids_json'] ?? 'null', true) ?: [],
            ],
            'tally' => $rows,
            'submitted' => $submitted,
            'expected'  => $expected,
        ]);
    }

    jsonError('Unknown action', 400);
} catch (Throwable $e) {
    jsonError('Server error: ' . $e->getMessage(), 500);
}
