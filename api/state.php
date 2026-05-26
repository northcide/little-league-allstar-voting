<?php
require_once __DIR__ . '/helpers.php';

$action = getAction();
$db     = getDB();

try {
    if (!currentRole()) jsonError('Not authenticated', 401);

    // ── Coach view ────────────────────────────────────────────────────────────
    if (currentRole() === 'coach') {
        $vc = currentVoterCode($db);
        if (!$vc) jsonError('Session expired', 401);
        $eid = (int)$vc['e_id'];
        touchVoter($db, (int)$vc['id']);

        // Latest non-pending round
        $stmt = $db->prepare(
            "SELECT * FROM rounds WHERE election_id=? AND state IN ('active','all_submitted','finalized')
             ORDER BY round_num DESC LIMIT 1"
        );
        $stmt->execute([$eid]);
        $round = $stmt->fetch();

        $players = $db->prepare("SELECT id, name, jersey FROM players WHERE election_id=? AND active=1 ORDER BY sort_order, name");
        $players->execute([$eid]);

        $locked = $db->prepare("SELECT player_id, locked_in_round FROM locked_roster WHERE election_id=? ORDER BY locked_in_round, player_id");
        $locked->execute([$eid]);

        // Per-round vote tallies for finalized rounds (touches ballot_picks only — anonymity boundary preserved).
        // Keyed by round_num so admin + coach views can both look it up from locked_in_round / round.round_num.
        $tStmt = $db->prepare(
            "SELECT r.round_num, bp.player_id, COUNT(*) AS cnt
             FROM ballot_picks bp
             JOIN rounds r ON r.id = bp.round_id
             WHERE r.election_id = ? AND r.state = 'finalized'
             GROUP BY r.round_num, bp.player_id"
        );
        $tStmt->execute([$eid]);
        $roundTallies = [];
        foreach ($tStmt->fetchAll() as $row) {
            $roundTallies[(int)$row['round_num']][(int)$row['player_id']] = (int)$row['cnt'];
        }

        // Per-round "tied at cutoff" player IDs (the top-vote-getters who tied for the last lock slot)
        $teStmt = $db->prepare(
            "SELECT round_num, tie_player_ids_json
             FROM rounds
             WHERE election_id = ? AND state = 'finalized' AND has_tie_at_cutoff = 1"
        );
        $teStmt->execute([$eid]);
        $roundTiedIds = [];
        foreach ($teStmt->fetchAll() as $row) {
            $ids = json_decode($row['tie_player_ids_json'] ?? 'null', true) ?: [];
            // Re-index in case older finalized rounds stored non-sequential keys
            $roundTiedIds[(int)$row['round_num']] = array_values(array_map('intval', $ids));
        }

        $resp = [
            'role'       => 'coach',
            'voter_word' => $vc['word'],
            'election'   => [
                'id'              => $eid,
                'name'            => $vc['e_name'],
                'status'          => $vc['e_status'],
                'expected_voters' => (int)$vc['expected_voters'],
                'max_roster_size' => (int)($vc['max_roster_size'] ?? 12),
                'current_round'   => (int)$vc['current_round'],
            ],
            'players' => $players->fetchAll(),
            'locked'  => $locked->fetchAll(),
            'round_tallies' => $roundTallies,
            'round_tied_ids' => $roundTiedIds,
            'round'   => null,
            'ballot'  => null,
        ];

        if ($round) {
            $sub = $db->prepare("SELECT ballot_token, submitted_at FROM submissions WHERE round_id=? AND voter_code_id=?");
            $sub->execute([(int)$round['id'], (int)$vc['id']]);
            $subRow = $sub->fetch();

            $resp['round'] = [
                'id'                => (int)$round['id'],
                'round_num'         => (int)$round['round_num'],
                'picks_per_coach'   => (int)$round['picks_per_coach'],
                'picks_to_lock'     => (int)$round['picks_to_lock'],
                'state'             => $round['state'],
                'has_tie_at_cutoff' => (bool)$round['has_tie_at_cutoff'],
            ];

            if ($round['state'] === 'finalized') {
                $win = $db->prepare("SELECT player_id FROM locked_roster WHERE election_id=? AND locked_in_round=?");
                $win->execute([$eid, (int)$round['round_num']]);
                $resp['round']['winners'] = array_map(fn($r) => (int)$r['player_id'], $win->fetchAll());
                $resp['round']['tie_player_ids'] = array_values(json_decode($round['tie_player_ids_json'] ?? 'null', true) ?: []);
            }

            if ($subRow) {
                $picks = $db->prepare("SELECT player_id FROM ballot_picks WHERE round_id=? AND ballot_token=?");
                $picks->execute([(int)$round['id'], $subRow['ballot_token']]);
                $resp['ballot'] = [
                    'submitted' => true,
                    'submitted_at' => $subRow['submitted_at'],
                    'picks' => array_map('intval', $picks->fetchAll(PDO::FETCH_COLUMN)),
                ];
            } else {
                $draft = $_SESSION["draft_r{$round['id']}"] ?? [];
                $resp['ballot'] = [
                    'submitted' => false,
                    'picks'     => array_map('intval', $draft),
                ];
            }
        }

        jsonResponse($resp);
    }

    // ── Admin view ────────────────────────────────────────────────────────────
    if (currentRole() === 'admin') {
        $eid = currentAdminElectionId();
        $resp = [
            'role'     => 'admin',
            'election' => null,
        ];

        if (!$eid) {
            jsonResponse($resp);
        }

        $stmt = $db->prepare("SELECT * FROM elections WHERE id=?");
        $stmt->execute([$eid]);
        $e = $stmt->fetch();
        if (!$e) { unset($_SESSION['admin_election_id']); jsonResponse($resp); }

        $players = $db->prepare("SELECT id, name, jersey, sort_order, active FROM players WHERE election_id=? ORDER BY sort_order, name");
        $players->execute([$eid]);
        $rounds  = $db->prepare("SELECT id, round_num, picks_per_coach, picks_to_lock, state, has_tie_at_cutoff, tie_player_ids_json, finalized_at FROM rounds WHERE election_id=? ORDER BY round_num");
        $rounds->execute([$eid]);
        $locked  = $db->prepare("SELECT player_id, locked_in_round FROM locked_roster WHERE election_id=? ORDER BY locked_in_round, player_id");
        $locked->execute([$eid]);

        // Voter codes — derive state badges; never include picks
        $codesStmt = $db->query("SELECT id, word, revoked, session_token, last_seen_at FROM voter_codes WHERE election_id={$eid} ORDER BY CAST(word AS UNSIGNED), word");
        $codes = $codesStmt->fetchAll();
        $now = time();

        // Current round (latest non-pending, else first pending)
        $currentRound = null;
        $allRounds = [];
        foreach ($rounds->fetchAll() as $r) {
            $r['tie_player_ids'] = array_values(json_decode($r['tie_player_ids_json'] ?? 'null', true) ?: []);
            unset($r['tie_player_ids_json']);
            $allRounds[] = $r;
            if (in_array($r['state'], ['active','all_submitted','finalized'], true)) {
                if (!$currentRound || (int)$r['round_num'] > (int)$currentRound['round_num']) $currentRound = $r;
            }
        }
        if (!$currentRound) {
            foreach ($allRounds as $r) { if ($r['state'] === 'pending') { $currentRound = $r; break; } }
        }

        // Submitted count for current round
        $submitted = 0;
        if ($currentRound && in_array($currentRound['state'], ['active','all_submitted','finalized'], true)) {
            $submitted = (int)$db->query("SELECT COUNT(*) FROM submissions WHERE round_id={$currentRound['id']}")->fetchColumn();
        }

        // Per-code status
        foreach ($codes as &$c) {
            $c['claimed']   = (bool)$c['session_token'];
            $c['logged_in'] = $c['last_seen_at'] && (strtotime($c['last_seen_at']) > $now - 30);
            $c['submitted'] = false;
            if ($currentRound) {
                $s = $db->prepare("SELECT 1 FROM submissions WHERE round_id=? AND voter_code_id=?");
                $s->execute([$currentRound['id'], $c['id']]);
                $c['submitted'] = (bool)$s->fetch();
            }
            unset($c['session_token']);
            if ($c['revoked'])         $c['status'] = 'revoked';
            elseif ($c['submitted'])   $c['status'] = 'submitted';
            elseif ($c['logged_in'])   $c['status'] = 'logged_in';
            else                       $c['status'] = 'signed_in';
            $c['revoked'] = (bool)$c['revoked'];
        }
        unset($c);

        $loggedIn = count(array_filter($codes, fn($c) => $c['logged_in'] || $c['submitted']));

        // Per-round vote tallies for finalized rounds (touches ballot_picks only)
        $tStmt = $db->prepare(
            "SELECT r.round_num, bp.player_id, COUNT(*) AS cnt
             FROM ballot_picks bp
             JOIN rounds r ON r.id = bp.round_id
             WHERE r.election_id = ? AND r.state = 'finalized'
             GROUP BY r.round_num, bp.player_id"
        );
        $tStmt->execute([$eid]);
        $roundTallies = [];
        foreach ($tStmt->fetchAll() as $row) {
            $roundTallies[(int)$row['round_num']][(int)$row['player_id']] = (int)$row['cnt'];
        }

        // Tied-at-cutoff IDs per finalized round (admin uses these to show TIED indicator too)
        $teStmt = $db->prepare(
            "SELECT round_num, tie_player_ids_json
             FROM rounds
             WHERE election_id = ? AND state = 'finalized' AND has_tie_at_cutoff = 1"
        );
        $teStmt->execute([$eid]);
        $roundTiedIds = [];
        foreach ($teStmt->fetchAll() as $row) {
            $ids = json_decode($row['tie_player_ids_json'] ?? 'null', true) ?: [];
            $roundTiedIds[(int)$row['round_num']] = array_values(array_map('intval', $ids));
        }

        $resp['election'] = $e;
        $resp['players']  = $players->fetchAll();
        $resp['rounds']   = $allRounds;
        $resp['locked']   = $locked->fetchAll();
        $resp['codes']    = $codes;
        $resp['current_round'] = $currentRound;
        $resp['round_tallies'] = $roundTallies;
        $resp['round_tied_ids'] = $roundTiedIds;
        $signedIn = count(array_filter($codes, fn($c) => !$c['revoked']));
        $resp['counts']   = [
            'signed_in'     => $signedIn,
            'logged_in'     => $loggedIn,
            'submitted'     => $submitted,
            'outstanding'   => max(0, $signedIn - $submitted),
            'roster_locked' => count($resp['locked']),
            'roster_max'    => (int)$e['max_roster_size'],
        ];

        jsonResponse($resp);
    }

    jsonError('Unknown role', 403);
} catch (Throwable $e) {
    jsonError('Server error: ' . $e->getMessage(), 500);
}
