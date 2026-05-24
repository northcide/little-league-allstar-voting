<?php
require_once __DIR__ . '/helpers.php';

$action = getAction();
$db     = getDB();

try {
    requireAdmin();

    $eid = currentAdminElectionId();
    if (!$eid) jsonError('No election selected', 400);

    $stmt = $db->prepare("SELECT status FROM elections WHERE id=?");
    $stmt->execute([$eid]);
    $election = $stmt->fetch();
    if (!$election) jsonError('Election not found', 404);

    if ($action === 'list') {
        $stmt = $db->prepare("SELECT id, name, jersey, sort_order, active FROM players WHERE election_id=? ORDER BY sort_order, name");
        $stmt->execute([$eid]);
        jsonResponse(['players' => $stmt->fetchAll()]);
    }

    if ($action === 'bulk_set') {
        // Replace whole roster — only allowed while in setup
        $d       = getInput();
        $players = $d['players'] ?? [];
        if (!is_array($players)) jsonError('players[] required');
        if ($election['status'] !== 'setup') jsonError('Use add/edit/remove once the election is active');

        $db->beginTransaction();
        $db->prepare("DELETE FROM players WHERE election_id=?")->execute([$eid]);
        $ins = $db->prepare("INSERT INTO players (election_id, name, jersey, sort_order) VALUES (?,?,?,?)");
        $i = 0;
        foreach ($players as $p) {
            $name = trim($p['name'] ?? '');
            if ($name === '') continue;
            $ins->execute([$eid, $name, $p['jersey'] ?? null, $i++]);
        }
        audit($db, $eid, 'admin', 'players_bulk_set', ['count' => $i]);
        $db->commit();
        jsonResponse(['ok' => true, 'count' => $i]);
    }

    if ($action === 'add') {
        $d    = getInput();
        $name = trim($d['name'] ?? '');
        if ($name === '') jsonError('Name required');
        $jersey = $d['jersey'] ?? null;

        $maxOrder = (int)$db->query("SELECT COALESCE(MAX(sort_order),0) FROM players WHERE election_id={$eid}")->fetchColumn();
        $ins = $db->prepare("INSERT INTO players (election_id, name, jersey, sort_order) VALUES (?,?,?,?)");
        $ins->execute([$eid, $name, $jersey, $maxOrder + 1]);
        audit($db, $eid, 'admin', 'player_add', ['name' => $name]);
        jsonResponse(['id' => (int)$db->lastInsertId()]);
    }

    if ($action === 'edit') {
        $d    = getInput();
        $id   = (int)($d['id'] ?? 0);
        $name = trim($d['name'] ?? '');
        if (!$id || $name === '') jsonError('id and name required');
        $stmt = $db->prepare("UPDATE players SET name=?, jersey=? WHERE id=? AND election_id=?");
        $stmt->execute([$name, $d['jersey'] ?? null, $id, $eid]);
        audit($db, $eid, 'admin', 'player_edit', ['id' => $id, 'name' => $name]);
        jsonResponse(['ok' => true]);
    }

    if ($action === 'remove') {
        $d  = getInput();
        $id = (int)($d['id'] ?? 0);
        if (!$id) jsonError('id required');

        // Hard delete only if no ballot history and not locked. Otherwise soft-delete (active=0).
        $locked = $db->prepare("SELECT 1 FROM locked_roster WHERE election_id=? AND player_id=?");
        $locked->execute([$eid, $id]);
        $inLocked = (bool)$locked->fetch();

        $picked = $db->prepare("SELECT 1 FROM ballot_picks bp JOIN rounds r ON r.id=bp.round_id WHERE r.election_id=? AND bp.player_id=? LIMIT 1");
        $picked->execute([$eid, $id]);
        $inPicked = (bool)$picked->fetch();

        if ($inLocked) jsonError('Cannot remove a locked player. Use Unlock first.', 409);

        if ($inPicked) {
            $db->prepare("UPDATE players SET active=0 WHERE id=? AND election_id=?")->execute([$id, $eid]);
            audit($db, $eid, 'admin', 'player_deactivate', ['id' => $id]);
        } else {
            $db->prepare("DELETE FROM players WHERE id=? AND election_id=?")->execute([$id, $eid]);
            audit($db, $eid, 'admin', 'player_delete', ['id' => $id]);
        }
        jsonResponse(['ok' => true]);
    }

    if ($action === 'reorder') {
        $d   = getInput();
        $ids = $d['ids'] ?? [];
        if (!is_array($ids)) jsonError('ids[] required');
        $upd = $db->prepare("UPDATE players SET sort_order=? WHERE id=? AND election_id=?");
        $i = 0;
        foreach ($ids as $pid) {
            $upd->execute([$i++, (int)$pid, $eid]);
        }
        jsonResponse(['ok' => true]);
    }

    jsonError('Unknown action', 400);
} catch (Throwable $e) {
    jsonError('Server error: ' . $e->getMessage(), 500);
}
