<?php
require_once __DIR__ . '/helpers.php';

$action = getAction();
$db     = getDB();

try {
    requireAdmin();

    $eid = currentAdminElectionId();
    if (!$eid) jsonError('No election selected', 400);

    // ── List candidates for the next alternate round ──────────────────────────
    // Returns three sets: all unlocked active players, those that received any
    // vote in a finalized round (default-checked for a fresh alternate round),
    // and the tied-at-cutoff players from the most-recent alternate round
    // (default-checked when continuing tie resolution). Also returns the
    // suggested slot count.
    if ($action === 'alternate_candidates') {
        $all = $db->prepare(
            "SELECT p.id, p.name, p.jersey FROM players p
             WHERE p.election_id = ? AND p.active = 1
               AND p.id NOT IN (SELECT player_id FROM locked_roster WHERE election_id = ?)
             ORDER BY p.sort_order, p.name"
        );
        $all->execute([$eid, $eid]);
        $allRows = $all->fetchAll();

        $voted = $db->prepare(
            "SELECT DISTINCT bp.player_id
             FROM ballot_picks bp
             JOIN rounds r ON r.id = bp.round_id
             WHERE r.election_id = ? AND r.state = 'finalized'
               AND bp.player_id NOT IN (SELECT player_id FROM locked_roster WHERE election_id = ?)"
        );
        $voted->execute([$eid, $eid]);
        $votedIds = array_map('intval', $voted->fetchAll(PDO::FETCH_COLUMN));

        // Most-recent alternate round with unresolved cutoff tie
        $prev = $db->prepare(
            "SELECT id, round_num, picks_to_lock, has_tie_at_cutoff, tie_player_ids_json
             FROM rounds
             WHERE election_id = ? AND round_type = 'alternate' AND state = 'finalized'
             ORDER BY round_num DESC LIMIT 1"
        );
        $prev->execute([$eid]);
        $prevAlt = $prev->fetch() ?: null;

        $priorTie = [];
        $suggestedSlots = 0;
        if ($prevAlt && (int)$prevAlt['has_tie_at_cutoff'] === 1) {
            $rawIds = array_values(json_decode($prevAlt['tie_player_ids_json'] ?? '[]', true) ?: []);
            // Only count as "unresolved" if none of those tied players were locked since
            if ($rawIds) {
                $in = implode(',', array_map('intval', $rawIds));
                $stillUnlocked = (int)$db->query(
                    "SELECT COUNT(*) FROM players p
                     WHERE p.id IN ($in)
                       AND p.id NOT IN (SELECT player_id FROM locked_roster WHERE election_id = $eid)"
                )->fetchColumn();
                if ($stillUnlocked === count($rawIds)) {
                    $priorTie = array_map('intval', $rawIds);
                }
            }
        }
        if (!empty($priorTie)) {
            $lp = $db->prepare("SELECT COUNT(*) FROM locked_roster WHERE election_id=? AND locked_in_round=?");
            $lp->execute([$eid, (int)$prevAlt['round_num']]);
            $lockedInPrev = (int)$lp->fetchColumn();
            $suggestedSlots = max(1, (int)$prevAlt['picks_to_lock'] - $lockedInPrev);
        }

        jsonResponse([
            'all_active'        => $allRows,
            'with_prior_votes'  => $votedIds,
            'prior_tie'         => $priorTie,
            'suggested_slots'   => $suggestedSlots,
        ]);
    }

    // ── Start a new alternate round ───────────────────────────────────────────
    if ($action === 'start_alternate') {
        $in   = getInput();
        $ppc  = (int)($in['picks_per_coach'] ?? 0);
        $ptl  = (int)($in['picks_to_lock']   ?? 0);
        $cand = $in['candidate_ids'] ?? [];
        if (!is_array($cand) || count($cand) < 2) jsonError('Pick at least 2 candidates for an alternate round');
        if ($ppc < 1 || $ptl < 1) jsonError('Alternates count must be ≥1');
        if ($ptl > count($cand))  jsonError('Cannot lock more alternates than candidates provided');
        // For alternates we enforce ppc == ptl — every ranked position becomes a slot
        if ($ppc !== $ptl) $ppc = $ptl;

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT * FROM elections WHERE id=? FOR UPDATE");
            $stmt->execute([$eid]);
            $e = $stmt->fetch();
            if (!$e || $e['status'] !== 'active') {
                $db->rollBack();
                jsonError('Election is not active', 400);
            }
            // Previous round (if any) must be finalized
            if ($e['current_round'] > 0) {
                $prev = $db->prepare("SELECT state FROM rounds WHERE election_id=? AND round_num=?");
                $prev->execute([$eid, $e['current_round']]);
                $pr = $prev->fetch();
                if ($pr && $pr['state'] !== 'finalized') {
                    $db->rollBack();
                    jsonError('Finalize the current round before starting an alternate round', 409);
                }
            }

            // Validate every candidate is an active, non-locked player in this election
            $candIds = array_values(array_unique(array_map('intval', $cand)));
            $placeholders = implode(',', array_fill(0, count($candIds), '?'));
            $valid = $db->prepare(
                "SELECT id FROM players WHERE election_id=? AND active=1
                  AND id IN ($placeholders)
                  AND id NOT IN (SELECT player_id FROM locked_roster WHERE election_id=?)"
            );
            $valid->execute(array_merge([$eid], $candIds, [$eid]));
            $validIds = array_map('intval', $valid->fetchAll(PDO::FETCH_COLUMN));
            $invalid = array_diff($candIds, $validIds);
            if (!empty($invalid)) {
                $db->rollBack();
                jsonError('One or more candidates are invalid (locked, inactive, or wrong election): ' . implode(',', $invalid), 400);
            }

            $nextRn = ((int)$db->query("SELECT COALESCE(MAX(round_num),0) FROM rounds WHERE election_id={$eid}")->fetchColumn()) + 1;
            $ins = $db->prepare("INSERT INTO rounds (election_id, round_num, picks_per_coach, picks_to_lock, round_type, state) VALUES (?,?,?,?,'alternate','active')");
            $ins->execute([$eid, $nextRn, $ppc, $ptl]);
            $newId = (int)$db->lastInsertId();
            // Populate round_candidates
            $cIns = $db->prepare("INSERT INTO round_candidates (round_id, player_id) VALUES (?,?)");
            foreach ($validIds as $pid) $cIns->execute([$newId, $pid]);

            $db->prepare("UPDATE elections SET current_round=? WHERE id=?")->execute([$nextRn, $eid]);
            audit($db, $eid, 'admin', 'start_alternate_round', [
                'round_num' => $nextRn,
                'picks_per_coach' => $ppc,
                'picks_to_lock' => $ptl,
                'candidates' => $validIds,
            ]);
            $db->commit();
            jsonResponse(['ok' => true, 'round_id' => $newId, 'round_num' => $nextRn]);
        } catch (Throwable $ex) {
            if ($db->inTransaction()) $db->rollBack();
            throw $ex;
        }
    }

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

            // Sanity: enough eligible players remain, and roster has room.
            // Roster cap applies only to MAIN roster (alternate_rank IS NULL);
            // alternates sit outside the cap.
            $totalLockedAll = (int)$db->query("SELECT COUNT(*) FROM locked_roster WHERE election_id={$eid}")->fetchColumn();
            $mainLockedCount = (int)$db->query("SELECT COUNT(*) FROM locked_roster WHERE election_id={$eid} AND alternate_rank IS NULL")->fetchColumn();
            $totalActive = (int)$db->query("SELECT COUNT(*) FROM players WHERE election_id={$eid} AND active=1")->fetchColumn();
            $remaining   = $totalActive - $totalLockedAll;
            if ($remaining < $ppc) {
                $db->rollBack();
                jsonError("Only {$remaining} unlocked players remain; round needs {$ppc} picks per coach", 409);
            }
            $slotsRemaining = (int)$e['max_roster_size'] - $mainLockedCount;
            if ($slotsRemaining <= 0) {
                $db->rollBack();
                jsonError("Roster is already full ({$mainLockedCount}/{$e['max_roster_size']}). You can finalize all rounds now.", 409);
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

            // Gate on signed-in coaches (count of non-revoked voter_codes), not pre-set expected_voters
            $signedIn  = (int)$db->query("SELECT COUNT(*) FROM voter_codes WHERE election_id={$eid} AND revoked=0")->fetchColumn();
            $submitted = (int)$db->query("SELECT COUNT(*) FROM submissions WHERE round_id={$rid}")->fetchColumn();
            if (!$override && $submitted < $signedIn) {
                $db->rollBack();
                jsonError("Only {$submitted}/{$signedIn} coaches have submitted. Use override to force-finalize.", 409);
            }

            // Tally — ONLY join ballot_picks (anonymity boundary)
            // Regular rounds: count votes. Alternate rounds: Borda points = sum of (picks_per_coach + 1 - rank).
            $isAlternate = ($round['round_type'] ?? 'regular') === 'alternate';
            if ($isAlternate) {
                $ppc = (int)$round['picks_per_coach'];
                $tally = $db->prepare(
                    "SELECT bp.player_id, SUM(? + 1 - bp.`rank`) AS cnt
                     FROM ballot_picks bp
                     WHERE bp.round_id = ? AND bp.`rank` IS NOT NULL
                     GROUP BY bp.player_id
                     ORDER BY cnt DESC, bp.player_id ASC"
                );
                $tally->execute([$ppc, $rid]);
            } else {
                $tally = $db->prepare(
                    "SELECT bp.player_id, COUNT(*) AS cnt
                     FROM ballot_picks bp
                     WHERE bp.round_id = ?
                     GROUP BY bp.player_id
                     ORDER BY cnt DESC, bp.player_id ASC"
                );
                $tally->execute([$rid]);
            }
            $results = $tally->fetchAll();

            // Exclude already-locked players (defensive — the ballot should already)
            $lockedIds = $db->prepare("SELECT player_id FROM locked_roster WHERE election_id=?");
            $lockedIds->execute([$eid]);
            $locked = array_map('intval', $lockedIds->fetchAll(PDO::FETCH_COLUMN));
            $results = array_values(array_filter($results, fn($row) => !in_array((int)$row['player_id'], $locked, true)));

            // Clip picks_to_lock to remaining roster slots so we never exceed the cap.
            // For alternate rounds, alternates sit outside the main roster cap, so skip.
            if ($isAlternate) {
                $picksToLock = (int)$round['picks_to_lock'];
                $clipped     = false;
            } else {
                $maxRoster      = (int)$db->query("SELECT max_roster_size FROM elections WHERE id={$eid}")->fetchColumn();
                $lockedCount    = (int)$db->query("SELECT COUNT(*) FROM locked_roster WHERE election_id={$eid} AND alternate_rank IS NULL")->fetchColumn();
                $slotsRemaining = max(0, $maxRoster - $lockedCount);
                if ($slotsRemaining === 0) {
                    $db->rollBack();
                    jsonError("Roster is already full ({$lockedCount}/{$maxRoster}). Use 'Edit locked players' to revise instead.", 409);
                }
                $picksToLock = min((int)$round['picks_to_lock'], $slotsRemaining);
                $clipped     = $picksToLock < (int)$round['picks_to_lock'];
            }
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
                        // array_filter preserves keys → re-index so json_encode produces a JSON array, not an object
                        $tieIds = array_values(array_map(fn($r) => (int)$r['player_id'], $atCutoff));
                        // Lock only the unambiguous winners (above cutoff); tied players await tiebreak
                        $winnerIds = array_map(fn($r) => (int)$r['player_id'], $aboveCutoff);
                    } else {
                        $winnerIds = array_slice(array_map(fn($r) => (int)$r['player_id'], $results), 0, $picksToLock);
                    }
                } else {
                    $winnerIds = array_map(fn($r) => (int)$r['player_id'], $results);
                }
            }

            // Persist winners → locked_roster.
            // For alternate rounds, also assign sequential alternate_rank that continues
            // from the highest existing alternate_rank in this election (so a tie-
            // resolution alternate round picks up where the previous one left off).
            if ($isAlternate) {
                $nextRank = ((int)$db->query("SELECT COALESCE(MAX(alternate_rank), 0) FROM locked_roster WHERE election_id={$eid}")->fetchColumn()) + 1;
                $lockIns = $db->prepare("INSERT IGNORE INTO locked_roster (election_id, player_id, locked_in_round, alternate_rank) VALUES (?,?,?,?)");
                foreach ($winnerIds as $pid) {
                    $lockIns->execute([$eid, $pid, (int)$round['round_num'], $nextRank]);
                    $nextRank++;
                }
            } else {
                $lockIns = $db->prepare("INSERT IGNORE INTO locked_roster (election_id, player_id, locked_in_round) VALUES (?,?,?)");
                foreach ($winnerIds as $pid) {
                    $lockIns->execute([$eid, $pid, (int)$round['round_num']]);
                }
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

        // Belt-and-suspenders: ensure the round + voter_code both belong to the
        // admin's currently selected election. Admin has full access across
        // elections, but this guards against a stale UI request reaching into
        // the wrong election by accident.
        $own = $db->prepare("SELECT 1 FROM rounds WHERE id=? AND election_id=?");
        $own->execute([$rid, $eid]);
        if (!$own->fetchColumn()) jsonError('Round does not belong to the selected election', 404);
        $own = $db->prepare("SELECT 1 FROM voter_codes WHERE id=? AND election_id=?");
        $own->execute([$vid, $eid]);
        if (!$own->fetchColumn()) jsonError('Voter does not belong to the selected election', 404);

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

            // Defense in depth: every chosen player must belong to this election
            foreach ($newWinners as $pid) {
                $own = $db->prepare("SELECT 1 FROM players WHERE id=? AND election_id=?");
                $own->execute([(int)$pid, $eid]);
                if (!$own->fetchColumn()) {
                    $db->rollBack();
                    jsonError("Player #{$pid} does not belong to this election", 400);
                }
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

        // Defense in depth: don't accept a player from a different election
        $own = $db->prepare("SELECT 1 FROM players WHERE id=? AND election_id=?");
        $own->execute([$pid, $eid]);
        if (!$own->fetchColumn()) jsonError('Player does not belong to the selected election', 404);

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
