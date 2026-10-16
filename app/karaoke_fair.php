<?php
// SCHEDULING FAIRNESS — one implementation for every place a request can arrive (the owner, 2026-10-16: a guest sang
// twice in a row, because guest requests never went through the fairness arranging). Pure PDO + PHP: no player, no other
// helpers, so the casAI page (app.php), each Mac's page (karaoke_api.php) and the guest page (karaoke_guest.php) all use
// the same code and cannot drift. Works on SQLite and MariaDB. Load it with require_once.
//
// The switch lives on the SERVER (karaoke_settings 'q_fair'), not in one browser, so a request from a guest's phone is
// arranged exactly like one from the host's screen.

/** Songs each singer has had in the last 12 hours (Singing + Done) — a party, even one that runs past midnight.
 *  Older rows never count: the table is only emptied by "Clear the queue", so last week's songs used to skew "tonight". */
function kar_q_sung_counts(PDO $db): array {
    $cut = date('Y-m-d H:i:s', time() - 12 * 3600);
    $m = [];
    $st = $db->prepare("SELECT singer, COUNT(*) c FROM karaoke_sing_queue WHERE status IN ('Singing','Done') AND created_at >= ? GROUP BY singer");
    $st->execute([$cut]);
    foreach ($st as $r) $m[$r['singer']] = (int)$r['c'];
    return $m;
}

/** The ids of the waiting requests in the fair order. $waiting = [['id'=>..,'singer'=>..],..] in the current order.
 *  A request's ROUND = songs that singer already had tonight + how many of their requests sit ahead of it in the queue.
 *  Round 0 for everyone first, then round 1, and so on; inside a round the current order is kept, except that the person
 *  who just sang goes last among equals. */
function kar_q_fair_order(array $waiting, array $sung, ?string $lastSinger = null): array {
    $seen = []; $rows = [];
    foreach ($waiting as $i => $w) {
        $s = (string)$w['singer'];
        $k = $seen[$s] ?? 0; $seen[$s] = $k + 1;
        $round = (int)($sung[$s] ?? 0) + $k;
        $just = ($lastSinger !== null && $s === $lastSinger) ? 1 : 0;
        $rows[] = [$round, $just, $i, (int)$w['id']];
    }
    usort($rows, function ($a, $b) { return [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]; });
    return array_map(function ($r) { return $r[3]; }, $rows);
}

/** Re-sequence the Waiting requests fairly. Returns true if anything moved. */
function kar_q_arrange(PDO $db): bool {
    $w = $db->query("SELECT id, singer, position FROM karaoke_sing_queue WHERE status='Waiting' ORDER BY position ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    if (count($w) < 2) return false;
    $last = $db->query("SELECT singer FROM karaoke_sing_queue WHERE status='Singing' ORDER BY id DESC LIMIT 1")->fetchColumn();
    if ($last === false) {      // nobody on now: the last one to finish — but only tonight's, never a song from days ago
        $q = $db->prepare("SELECT singer FROM karaoke_sing_queue WHERE status='Done' AND created_at >= ? ORDER BY id DESC LIMIT 1");
        $q->execute([date('Y-m-d H:i:s', time() - 12 * 3600)]);
        $last = $q->fetchColumn();
    }
    $ids = kar_q_fair_order($w, kar_q_sung_counts($db), $last === false ? null : (string)$last);
    $cur = array_map(function ($r) { return (int)$r['id']; }, $w);
    if ($ids === $cur) return false;
    $pos = array_map(function ($r) { return (int)$r['position']; }, $w); sort($pos);
    $u = $db->prepare('UPDATE karaoke_sing_queue SET position = ? WHERE id = ?');
    foreach ($ids as $n => $id) $u->execute([$pos[$n] ?? ($n + 1), $id]);
    return true;
}

function kar_q_fair_on(PDO $db): bool {
    try { return (string)$db->query("SELECT v FROM karaoke_settings WHERE k='q_fair'")->fetchColumn() === '1'; }
    catch (Throwable $e) { return false; }
}

function kar_q_fair_set(PDO $db, bool $on): void {
    $v = $on ? '1' : '0';
    $u = $db->prepare("UPDATE karaoke_settings SET v = ? WHERE k = 'q_fair'"); $u->execute([$v]);
    if (!$u->rowCount() && !(int)$db->query("SELECT COUNT(*) FROM karaoke_settings WHERE k='q_fair'")->fetchColumn()) {
        $db->prepare("INSERT INTO karaoke_settings (k, v) VALUES ('q_fair', ?)")->execute([$v]);
    }
}

/** Call right after ANY request is added, from wherever: if fairness is on, the visible queue is re-sequenced. */
function kar_q_after_add(PDO $db): void { if (kar_q_fair_on($db)) kar_q_arrange($db); }
