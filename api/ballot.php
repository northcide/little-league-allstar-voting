<?php
require_once __DIR__ . '/helpers.php';

$action = getAction();
$db     = getDB();

try {
    requireCoach();

    $vc = currentVoterCode($db);
    if (!$vc) jsonError('Session expired. Please log in again.', 401);
    $eid = (int)$vc['e_id'];
    touchVoter($db, (int)$vc['id']);

    // Current active round (if any)
    $stmt = $db->prepare(
        "SELECT * FROM rounds WHERE election_id=? AND state IN ('active','all_submitted','finalized')
         ORDER BY round_num DESC LIMIT 1"
    );
    $stmt->execute([$eid]);
    $round = $stmt->fetch();

    if ($action === 'current') {
        // Always returns the coach view: players, locked, draft, submitted state, round info
        $players = $db->prepare("SELECT id, name, jersey FROM players WHERE election_id=? AND active=1 ORDER BY sort_order, name");
        $players->execute([$eid]);
        $allPlayers = $players->fetchAll();

        $locked = $db->prepare("SELECT player_id, locked_in_round FROM locked_roster WHERE election_id=? ORDER BY locked_in_round, player_id");
        $locked->execute([$eid]);
        $lockedRows = $locked->fetchAll();
        $lockedIds = array_map(fn($r) => (int)$r['player_id'], $lockedRows);

        $resp = [
            'election' => [
                'id'            => $eid,
                'name'          => $vc['e_name'],
                'status'        => $vc['e_status'],
                'current_round' => (int)$vc['current_round'],
            ],
            'voter_word' => $vc['word'],
            'players'    => $allPlayers,
            'locked'     => $lockedRows,
            'round'      => null,
            'ballot'     => null,
        ];

        if ($round) {
            $resp['round'] = [
                'id'                => (int)$round['id'],
                'round_num'         => (int)$round['round_num'],
                'picks_per_coach'   => (int)$round['picks_per_coach'],
                'picks_to_lock'     => (int)$round['picks_to_lock'],
                'state'             => $round['state'],
                'round_type'        => $round['round_type'] ?? 'regular',
                'has_tie_at_cutoff' => (bool)$round['has_tie_at_cutoff'],
            ];

            // Has this voter submitted yet?
            $sub = $db->prepare("SELECT id, ballot_token, submitted_at FROM submissions WHERE round_id=? AND voter_code_id=?");
            $sub->execute([(int)$round['id'], (int)$vc['id']]);
            $subRow = $sub->fetch();

            if ($subRow) {
                $picks = $db->prepare("SELECT player_id FROM ballot_picks WHERE round_id=? AND ballot_token=?");
                $picks->execute([(int)$round['id'], $subRow['ballot_token']]);
                $resp['ballot'] = [
                    'submitted'    => true,
                    'submitted_at' => $subRow['submitted_at'],
                    'picks'        => array_map('intval', $picks->fetchAll(PDO::FETCH_COLUMN)),
                ];
            } else {
                // Draft lives in session only
                $draftKey = "draft_r{$round['id']}";
                $draft    = $_SESSION[$draftKey] ?? [];
                $resp['ballot'] = [
                    'submitted' => false,
                    'picks'     => array_values(array_filter(array_map('intval', $draft), fn($pid) => !in_array($pid, $lockedIds, true))),
                ];
            }

            // If finalized, attach public results
            if ($round['state'] === 'finalized') {
                $win = $db->prepare("SELECT player_id, locked_in_round FROM locked_roster WHERE election_id=? AND locked_in_round=?");
                $win->execute([$eid, (int)$round['round_num']]);
                $resp['round']['winners'] = array_map(fn($r) => (int)$r['player_id'], $win->fetchAll());
                $resp['round']['tie_player_ids'] = json_decode($round['tie_player_ids_json'] ?? 'null', true) ?: [];
            }
        }

        jsonResponse($resp);
    }

    if ($action === 'save_draft') {
        // Session-only — does not persist or affect tallies
        if (!$round || $round['state'] !== 'active') jsonError('No active round', 409);
        $d   = getInput();
        $ids = array_values(array_unique(array_map('intval', $d['player_ids'] ?? [])));
        $_SESSION["draft_r{$round['id']}"] = $ids;
        jsonResponse(['ok' => true]);
    }

    if ($action === 'submit') {
        if (!$round) jsonError('No active round', 409);
        $d   = getInput();
        $rid = (int)($d['round_id'] ?? 0);
        if ($rid !== (int)$round['id']) jsonError('Round mismatch — your ballot is stale; please refresh', 409);

        // For alternate rounds the payload is an ORDERED array (1st choice first).
        // For regular rounds, order is irrelevant — we still pull as an array and
        // dedupe, but don't store a rank.
        $rawIds = is_array($d['player_ids'] ?? null) ? $d['player_ids'] : [];
        $ids = [];
        $seen = [];
        foreach ($rawIds as $v) {
            $i = (int)$v;
            if ($i <= 0 || isset($seen[$i])) continue;
            $seen[$i] = true;
            $ids[] = $i;
        }

        $db->beginTransaction();
        try {
            // Lock the round row to serialize against finalize
            $r = $db->prepare("SELECT * FROM rounds WHERE id=? FOR UPDATE");
            $r->execute([$rid]);
            $rnow = $r->fetch();
            if (!$rnow || $rnow['state'] !== 'active') {
                $db->rollBack();
                jsonError('This round is no longer accepting ballots', 409);
            }

            $isAlternate = ($rnow['round_type'] ?? 'regular') === 'alternate';
            $picksReq = (int)$rnow['picks_per_coach'];
            if (count($ids) !== $picksReq) {
                $db->rollBack();
                jsonError($isAlternate
                    ? "You must rank exactly {$picksReq} players"
                    : "You must pick exactly {$picksReq} players", 400);
            }

            // Validate every id is an active, non-locked player in this election
            $lockedIds = $db->prepare("SELECT player_id FROM locked_roster WHERE election_id=?");
            $lockedIds->execute([$eid]);
            $locked = array_map('intval', $lockedIds->fetchAll(PDO::FETCH_COLUMN));

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $check = $db->prepare("SELECT id FROM players WHERE election_id=? AND active=1 AND id IN ($placeholders)");
            $check->execute(array_merge([$eid], $ids));
            $okIds = array_map('intval', $check->fetchAll(PDO::FETCH_COLUMN));

            if (count($okIds) !== count($ids)) {
                $db->rollBack();
                jsonError('One or more selected players are invalid', 400);
            }
            foreach ($ids as $pid) {
                if (in_array($pid, $locked, true)) {
                    $db->rollBack();
                    jsonError('Locked players cannot be selected', 400);
                }
            }

            // For alternate rounds, every pick must also be in the round's candidate whitelist
            if ($isAlternate) {
                $candStmt = $db->prepare("SELECT player_id FROM round_candidates WHERE round_id=?");
                $candStmt->execute([$rid]);
                $cand = array_map('intval', $candStmt->fetchAll(PDO::FETCH_COLUMN));
                foreach ($ids as $pid) {
                    if (!in_array($pid, $cand, true)) {
                        $db->rollBack();
                        jsonError('Player not in this round\'s candidate pool', 400);
                    }
                }
            }

            // Idempotent: if a submission row already exists, return it as success.
            $existing = $db->prepare("SELECT ballot_token FROM submissions WHERE round_id=? AND voter_code_id=?");
            $existing->execute([$rid, (int)$vc['id']]);
            $exist = $existing->fetch();
            if ($exist) {
                $db->commit();
                jsonResponse(['ok' => true, 'already_submitted' => true]);
            }

            $ballotToken = makeBallotToken();

            $db->prepare("INSERT INTO submissions (round_id, voter_code_id, ballot_token, submitted_at) VALUES (?,?,?,NOW())")
               ->execute([$rid, (int)$vc['id'], $ballotToken]);

            if ($isAlternate) {
                $pIns = $db->prepare("INSERT INTO ballot_picks (round_id, ballot_token, player_id, `rank`) VALUES (?,?,?,?)");
                $rank = 1;
                foreach ($ids as $pid) {
                    $pIns->execute([$rid, $ballotToken, $pid, $rank++]);
                }
            } else {
                $pIns = $db->prepare("INSERT INTO ballot_picks (round_id, ballot_token, player_id) VALUES (?,?,?)");
                foreach ($ids as $pid) {
                    $pIns->execute([$rid, $ballotToken, $pid]);
                }
            }

            // Update round state to all_submitted if every signed-in coach has voted
            $submitted = (int)$db->query("SELECT COUNT(*) FROM submissions WHERE round_id={$rid}")->fetchColumn();
            $signedIn  = (int)$db->query("SELECT COUNT(*) FROM voter_codes WHERE election_id={$eid} AND revoked=0")->fetchColumn();
            if ($signedIn > 0 && $submitted >= $signedIn) {
                $db->prepare("UPDATE rounds SET state='all_submitted' WHERE id=? AND state='active'")->execute([$rid]);
            }

            // Clear draft
            unset($_SESSION["draft_r{$rid}"]);
            $db->commit();
            jsonResponse(['ok' => true, 'submitted_at' => date('c')]);
        } catch (Throwable $ex) {
            if ($db->inTransaction()) $db->rollBack();
            throw $ex;
        }
    }

    jsonError('Unknown action', 400);
} catch (Throwable $e) {
    jsonError('Server error: ' . $e->getMessage(), 500);
}
