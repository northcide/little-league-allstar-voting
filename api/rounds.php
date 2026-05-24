<?php
require_once __DIR__ . '/helpers.php';

$action = getAction();
$db     = getDB();

try {
    requireAdmin();

    $eid = currentAdminElectionId();
    if (!$eid) jsonError('No election selected', 400);

    // ── Start next round (creates a new round on-demand with admin-supplied config) ──
    if ($action === 'start_next') {
        $in  = getInput();
        $ppc = (int)($in['picks_per_coach'] ?? 0);
        $ptl = (int)($in['picks_to_lock']   ?? 0);
        if ($ppc < 1 || $ptl < 1 || $ptl > $ppc) {
            jsonError('picks_per_coach and picks_to_lock are required, must be ≥1, with picks_to_lock ≤ picks_per_coach');
        }

        $db->beginTransaction();
        try {
            // Lock the election row
            $stmt = $db->prepare("SELECT * FROM elections WHERE id=? FOR UPDATE");
            $stmt->execute([$eid]);
            $e = $stmt->fetch();
            if (!$e || $e['status'] !== 'active') {
                $db->rollBack();
                jsonError('Election is not active', 400);
            }

            // Ensure prior round (if any) is finalized
            if ($e['current_round'] > 0) {
                $prev = $db->prepare("SELECT state FROM rounds WHERE election_id=? AND round_num=?");
                $prev->execute([$eid, $e['current_round']]);
                $pr = $prev->fetch();
                if (!$pr) { $db->rollBack(); jsonError('Previous round not found', 500); }
                if ($pr['state'] !== 'finalized') {
                    $db->rollBack();
                    jsonError('Finalize the current round before starting the next', 409);
                }
            }

            // Sanity: enough eligible players remain, and roster has room
            $lockedCount = (int)$db->query("SELECT COUNT(*) FROM locked_roster WHERE election_id={$eid}")->fetchColumn();
            $totalActive = (int)$db->query("SELECT COUNT(*) FROM players WHERE election_id={$eid} AND active=1")->fetchColumn();
            $remaining   = $totalActive - $lockedCount;
            if ($remaining < $ppc) {
                $db->rollBack();
                jsonError("Only {$remaining} unlocked players remain; round needs {$ppc} picks per coach", 409);
            }
            $slotsRemaining = (int)$e['max_roster_size'] - $lockedCount;
            if ($slotsRemaining <= 0) {
                $db->rollBack();
                jsonError("Roster is already full ({$lockedCount}/{$e['max_roster_size']}). You can finalize all rounds now.", 409);
            }

            // Create the round at MAX(round_num)+1 and activate it
            $nextRn = ((int)$db->query("SELECT COALESCE(MAX(round_num),0) FROM rounds WHERE election_id={$eid}")->fetchColumn()) + 1;
            $ins = $db->prepare("INSERT INTO rounds (election_id, round_num, picks_per_coach, picks_to_lock, state) VALUES (?,?,?,?,'active')");
            $ins->execute([$eid, $nextRn, $ppc, $ptl]);
            $newId = (int)$db->lastInsertId();
            $db->prepare("UPDATE elections SET current_round=? WHERE id=?")->execute([$nextRn, $eid]);
            audit($db, $eid, 'admin', 'start_round', ['round_num' => $nextRn, 'picks_per_coach' => $ppc, 'picks_to_lock' => $ptl]);
            $db->commit();
            jsonResponse(['ok' => true, 'round_id' => $newId, 'round_num' => $nextRn]);
        } catch (Throwable $ex) {
            if ($db->inTransaction()) $db->rollBack();
            throw $ex;
        }
    }

    // ── Finalize ──────────────────────────────────────────────────────────────
    if ($action === 'finalize') {
        $d        = getInput();
        $rid      = (int)($d['round_id'] ?? 0);
        $override = !empty($d['override']);
        if (!$rid) jsonError('round_id required');

        $db->beginTransaction();
        try {
            $r = $db->prepare("SELECT * FROM rounds WHERE id=? AND election_id=? FOR UPDATE");
            $r->execute([$rid, $eid]);
            $round = $r->fetch();
            if (!$round) { $db->rollBack(); jsonError('Round not found', 404); }
            if ($round['state'] === 'finalized') { $db->rollBack(); jsonError('Already finalized', 409); }
            if ($round['state'] === 'pending')   { $db->rollBack(); jsonError('Round has not started', 409); }

            // Determine expected vs submitted
            $expected  = (int)$db->query("SELECT expected_voters FROM elections WHERE id={$eid}")->fetchColumn();
            $submitted = (int)$db->query("SELECT COUNT(*) FROM submissions WHERE round_id={$rid}")->fetchColumn();
            if (!$override && $submitted < $expected) {
                $db->rollBack();
                jsonError("Only {$submitted}/{$expected} submitted. Use override to force-finalize.", 409);
            }

            // Tally — ONLY join ballot_picks (anonymity boundary)
            $tally = $db->prepare(
                "SELECT bp.player_id, COUNT(*) AS cnt
                 FROM ballot_picks bp
                 WHERE bp.round_id = ?
                 GROUP BY bp.player_id
                 ORDER BY cnt DESC, bp.player_id ASC"
            );
            $tally->execute([$rid]);
            $results = $tally->fetchAll();

            // Exclude already-locked players (defensive — the ballot should already)
            $lockedIds = $db->prepare("SELECT player_id FROM locked_roster WHERE election_id=?");
            $lockedIds->execute([$eid]);
            $locked = array_map('intval', $lockedIds->fetchAll(PDO::FETCH_COLUMN));
            $results = array_values(array_filter($results, fn($row) => !in_array((int)$row['player_id'], $locked, true)));

            // Clip picks_to_lock to remaining roster slots so we never exceed the cap
            $maxRoster      = (int)$db->query("SELECT max_roster_size FROM elections WHERE id={$eid}")->fetchColumn();
            $lockedCount    = (int)$db->query("SELECT COUNT(*) FROM locked_roster WHERE election_id={$eid}")->fetchColumn();
            $slotsRemaining = max(0, $maxRoster - $lockedCount);
            if ($slotsRemaining === 0) {
                $db->rollBack();
                jsonError("Roster is already full ({$lockedCount}/{$maxRoster}). Use 'Edit locked players' to revise instead.", 409);
            }
            $picksToLock = min((int)$round['picks_to_lock'], $slotsRemaining);
            $clipped     = $picksToLock < (int)$round['picks_to_lock'];
            $winnerIds   = [];
            $hasTie      = 0;
            $tieIds      = [];

            if (count($results) > 0) {
                // Take top N strictly; flag tie if cutoff has equal counts beyond N
                $cutoffCount = $picksToLock - 1;
                if ($cutoffCount < count($results)) {
                    $cutoffVal = (int)$results[$cutoffCount]['cnt'];
                    // Find all players matching the cutoff value
                    $atCutoff = array_filter($results, fn($r) => (int)$r['cnt'] === $cutoffVal);
                    $aboveCutoff = array_filter($results, fn($r) => (int)$r['cnt'] > $cutoffVal);
                    $slotsLeft = $picksToLock - count($aboveCutoff);
                    if ($slotsLeft > 0 && count($atCutoff) > $slotsLeft) {
                        $hasTie = 1;
                        $tieIds = array_map(fn($r) => (int)$r['player_id'], $atCutoff);
                        // Lock only the unambiguous winners (above cutoff); tied players await tiebreak
                        $winnerIds = array_map(fn($r) => (int)$r['player_id'], $aboveCutoff);
                    } else {
                        $winnerIds = array_slice(array_map(fn($r) => (int)$r['player_id'], $results), 0, $picksToLock);
                    }
                } else {
                    $winnerIds = array_map(fn($r) => (int)$r['player_id'], $results);
                }
            }

            // Persist winners → locked_roster
            $lockIns = $db->prepare("INSERT IGNORE INTO locked_roster (election_id, player_id, locked_in_round) VALUES (?,?,?)");
            foreach ($winnerIds as $pid) {
                $lockIns->execute([$eid, $pid, (int)$round['round_num']]);
            }

            $db->prepare(
                "UPDATE rounds SET state='finalized', finalized_at=NOW(),
                                   finalized_by_override=?, has_tie_at_cutoff=?,
                                   tie_player_ids_json=?
                 WHERE id=?"
            )->execute([
                $override ? 1 : 0,
                $hasTie,
                json_encode($tieIds),
                $rid,
            ]);

            audit($db, $eid, 'admin', 'finalize_round', [
                'round_id' => $rid, 'round_num' => (int)$round['round_num'],
                'winners' => $winnerIds, 'override' => (bool)$override, 'tie' => (bool)$hasTie,
                'clipped_to_roster_cap' => $clipped,
            ]);

            // If all configured rounds are done AND no pending rounds remain, mark election completed (admin can still add tiebreaks)
            $pending = (int)$db->query("SELECT COUNT(*) FROM rounds WHERE election_id={$eid} AND state='pending'")->fetchColumn();
            if ($pending === 0 && $hasTie === 0) {
                // Don't auto-complete — admin clicks a button. Just leave it active.
            }

            $db->commit();
            jsonResponse([
                'ok' => true,
                'winners' => $winnerIds,
                'tie'     => (bool)$hasTie,
                'tie_player_ids' => $tieIds,
                'clipped_to_roster_cap' => $clipped,
            ]);
        } catch (Throwable $ex) {
            if ($db->inTransaction()) $db->rollBack();
            throw $ex;
        }
    }

    // ── Reset a coach's ballot for the current/active round ───────────────────
    if ($action === 'reset_ballot') {
        $d  = getInput();
        $rid = (int)($d['round_id'] ?? 0);
        $vid = (int)($d['voter_code_id'] ?? 0);
        if (!$rid || !$vid) jsonError('round_id and voter_code_id required');

        $db->beginTransaction();
        try {
            // Find the ballot_token via submissions, then delete from both atomically
            $s = $db->prepare("SELECT ballot_token FROM submissions WHERE round_id=? AND voter_code_id=? FOR UPDATE");
            $s->execute([$rid, $vid]);
            $row = $s->fetch();
            if ($row) {
                $db->prepare("DELETE FROM ballot_picks WHERE round_id=? AND ballot_token=?")
                   ->execute([$rid, $row['ballot_token']]);
                $db->prepare("DELETE FROM submissions WHERE round_id=? AND voter_code_id=?")
                   ->execute([$rid, $vid]);
            }
            audit($db, $eid, 'admin', 'reset_ballot', ['round_id' => $rid, 'voter_code_id' => $vid]);
            $db->commit();
            jsonResponse(['ok' => true]);
        } catch (Throwable $ex) {
            if ($db->inTransaction()) $db->rollBack();
            throw $ex;
        }
    }

    // ── Edit results post-finalize (admin override) ───────────────────────────
    if ($action === 'edit_results') {
        $d   = getInput();
        $rid = (int)($d['round_id'] ?? 0);
        $newWinners = $d['locked_player_ids'] ?? [];
        if (!$rid) jsonError('round_id required');
        if (!is_array($newWinners)) jsonError('locked_player_ids[] required');

        $db->beginTransaction();
        try {
            $r = $db->prepare("SELECT * FROM rounds WHERE id=? AND election_id=? FOR UPDATE");
            $r->execute([$rid, $eid]);
            $round = $r->fetch();
            if (!$round) { $db->rollBack(); jsonError('Round not found', 404); }
            if ($round['state'] !== 'finalized') { $db->rollBack(); jsonError('Round must be finalized first', 409); }

            // Enforce roster cap: (total - this round's previous winners) + new winners ≤ cap
            $maxRoster   = (int)$db->query("SELECT max_roster_size FROM elections WHERE id={$eid}")->fetchColumn();
            $totalLocked = (int)$db->query("SELECT COUNT(*) FROM locked_roster WHERE election_id={$eid}")->fetchColumn();
            $inThisRound = (int)$db->query("SELECT COUNT(*) FROM locked_roster WHERE election_id={$eid} AND locked_in_round=" . (int)$round['round_num'])->fetchColumn();
            $newTotal    = $totalLocked - $inThisRound + count($newWinners);
            if ($newTotal > $maxRoster) {
                $db->rollBack();
                jsonError("That would lock {$newTotal} players but the roster cap is {$maxRoster}", 409);
            }

            // Remove previously locked players from this round
            $db->prepare("DELETE FROM locked_roster WHERE election_id=? AND locked_in_round=?")
               ->execute([$eid, (int)$round['round_num']]);

            $lockIns = $db->prepare("INSERT INTO locked_roster (election_id, player_id, locked_in_round, was_manual) VALUES (?,?,?,1)");
            foreach ($newWinners as $pid) {
                $lockIns->execute([$eid, (int)$pid, (int)$round['round_num']]);
            }
            $db->prepare("UPDATE rounds SET has_tie_at_cutoff=0, tie_player_ids_json=NULL WHERE id=?")->execute([$rid]);
            audit($db, $eid, 'admin', 'edit_results', ['round_id' => $rid, 'new_winners' => $newWinners]);
            $db->commit();
            jsonResponse(['ok' => true]);
        } catch (Throwable $ex) {
            if ($db->inTransaction()) $db->rollBack();
            throw $ex;
        }
    }

    // ── Manual lock / unlock a player ─────────────────────────────────────────
    if ($action === 'manual_lock') {
        $d   = getInput();
        $pid = (int)($d['player_id'] ?? 0);
        $rn  = (int)($d['round_num'] ?? 0);
        if (!$pid) jsonError('player_id required');
        if (!$rn)  $rn = (int)$db->query("SELECT current_round FROM elections WHERE id={$eid}")->fetchColumn() ?: 1;

        // Refuse if at cap (skip check if this player is already locked — it's an idempotent update)
        $isAlreadyLocked = $db->prepare("SELECT 1 FROM locked_roster WHERE election_id=? AND player_id=?");
        $isAlreadyLocked->execute([$eid, $pid]);
        if (!$isAlreadyLocked->fetchColumn()) {
            $maxRoster   = (int)$db->query("SELECT max_roster_size FROM elections WHERE id={$eid}")->fetchColumn();
            $lockedCount = (int)$db->query("SELECT COUNT(*) FROM locked_roster WHERE election_id={$eid}")->fetchColumn();
            if ($lockedCount >= $maxRoster) {
                jsonError("Roster is already full ({$lockedCount}/{$maxRoster}). Unlock someone first.", 409);
            }
        }

        $db->prepare("INSERT INTO locked_roster (election_id, player_id, locked_in_round, was_manual) VALUES (?,?,?,1)
                      ON DUPLICATE KEY UPDATE locked_in_round=VALUES(locked_in_round), was_manual=1")
           ->execute([$eid, $pid, $rn]);
        audit($db, $eid, 'admin', 'manual_lock', ['player_id' => $pid, 'round_num' => $rn]);
        jsonResponse(['ok' => true]);
    }

    if ($action === 'manual_unlock') {
        $d   = getInput();
        $pid = (int)($d['player_id'] ?? 0);
        if (!$pid) jsonError('player_id required');
        $db->prepare("DELETE FROM locked_roster WHERE election_id=? AND player_id=?")
           ->execute([$eid, $pid]);
        audit($db, $eid, 'admin', 'manual_unlock', ['player_id' => $pid]);
        jsonResponse(['ok' => true]);
    }

    if ($action === 'complete_election') {
        $db->prepare("UPDATE elections SET status='completed' WHERE id=?")->execute([$eid]);
        audit($db, $eid, 'admin', 'complete_election', []);
        jsonResponse(['ok' => true]);
    }

    jsonError('Unknown action', 400);
} catch (Throwable $e) {
    jsonError('Server error: ' . $e->getMessage(), 500);
}
