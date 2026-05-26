<?php
require_once __DIR__ . '/helpers.php';

$action = getAction();
$db     = getDB();

try {
    requireAdmin();

    $eid = currentAdminElectionId();
    if (!$eid) jsonError('No election selected', 400);

    if ($action === 'list') {
        // Per-code status — codes only, never identities.
        $rid = (int)($_GET['round_id'] ?? 0);
        if (!$rid) {
            // Use current round if active
            $rid = (int)$db->query(
                "SELECT r.id FROM rounds r JOIN elections e ON e.id=r.election_id
                 WHERE r.election_id={$eid} AND r.state IN ('active','all_submitted','finalized')
                 ORDER BY r.round_num DESC LIMIT 1"
            )->fetchColumn();
        }

        $sql = "SELECT vc.id, vc.word, vc.revoked,
                       (vc.session_token IS NOT NULL) AS claimed,
                       vc.last_seen_at,
                       (SELECT 1 FROM submissions s WHERE s.round_id=? AND s.voter_code_id=vc.id LIMIT 1) AS submitted_round
                FROM voter_codes vc
                WHERE vc.election_id=?
                ORDER BY CAST(vc.word AS UNSIGNED), vc.word";
        $stmt = $db->prepare($sql);
        $stmt->execute([$rid ?: 0, $eid]);
        $rows = $stmt->fetchAll();

        // State derivation
        $now = time();
        foreach ($rows as &$r) {
            $r['claimed']    = (bool)$r['claimed'];
            $r['revoked']    = (bool)$r['revoked'];
            $r['submitted']  = (bool)$r['submitted_round'];
            unset($r['submitted_round']);
            // "logged in" = seen in last 30s
            $r['logged_in']  = $r['last_seen_at'] && (strtotime($r['last_seen_at']) > $now - 30);
            if ($r['revoked'])         $r['status'] = 'revoked';
            elseif ($r['submitted'])   $r['status'] = 'submitted';
            elseif ($r['logged_in'])   $r['status'] = 'logged_in';
            elseif ($r['claimed'])     $r['status'] = 'claimed';
            else                       $r['status'] = 'unclaimed';
        }
        jsonResponse(['codes' => $rows, 'round_id' => $rid]);
    }

    if ($action === 'revoke') {
        $d   = getInput();
        $id  = (int)($d['voter_code_id'] ?? 0);
        if (!$id) jsonError('voter_code_id required');
        $db->prepare("UPDATE voter_codes SET revoked=1, session_token=NULL WHERE id=? AND election_id=?")
           ->execute([$id, $eid]);
        audit($db, $eid, 'admin', 'code_revoke', ['voter_code_id' => $id]);
        jsonResponse(['ok' => true]);
    }

    if ($action === 'unrevoke') {
        $d   = getInput();
        $id  = (int)($d['voter_code_id'] ?? 0);
        if (!$id) jsonError('voter_code_id required');
        $db->prepare("UPDATE voter_codes SET revoked=0 WHERE id=? AND election_id=?")
           ->execute([$id, $eid]);
        audit($db, $eid, 'admin', 'code_unrevoke', ['voter_code_id' => $id]);
        jsonResponse(['ok' => true]);
    }

    jsonError('Unknown action', 400);
} catch (Throwable $e) {
    jsonError('Server error: ' . $e->getMessage(), 500);
}
