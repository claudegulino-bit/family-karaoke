<?php
// SCHEDULING FAIRNESS — one implementation for every place a request can arrive (the owner, 2026-10-16: a guest sang
// twice in a row, because guest requests never went through the fairness arranging). Pure PDO + PHP: no player, no other
// helpers, so the casAI page (app.php), each Mac's page (karaoke_api.php) and the guest page (karaoke_guest.php) all use
// the same code and cannot drift. Works on SQLite and MariaDB. Load it with require_once.
//
// THE SWITCH lives on the SERVER (karaoke_settings 'q_fair'), not in one browser, so a request from a guest's phone is
// arranged exactly like one from the host's screen.
//
// THE PARTY (the owner, 2026-10-16: "for fairness to be fair we have to have a start time ... from the beginning of the party
// to the end"). Fairness counts songs sung SINCE THE PARTY STARTED. A party starts with the first request that arrives
// after a quiet gap of 3 hours (nothing requested for that long), or when the host presses "New party"; it has no fixed
// end — it simply goes on while requests keep coming. The start time is stored ('party_start') and shown on the queue panel.
//
// WHO IS WHO: "Ann", "Ann Smith" and a guest's "Ann S" are one person to the count — names are matched
// against the singers list (name, full name, a unique first name, one typo). Unknown names stand for themselves.

const KAR_PARTY_GAP = 3 * 3600;

/* ---------------------------------------------------------------- settings, portable */
function kar_q_setting_get(PDO $db, string $k): string {
    try { $s = $db->prepare("SELECT v FROM karaoke_settings WHERE k = ?"); $s->execute([$k]); $v = $s->fetchColumn(); return $v === false ? '' : (string)$v; }
    catch (Throwable $e) { return ''; }
}
function kar_q_setting_set(PDO $db, string $k, string $v): void {
    $u = $db->prepare("UPDATE karaoke_settings SET v = ? WHERE k = ?"); $u->execute([$v, $k]);
    if (!$u->rowCount()) {
        $c = $db->prepare("SELECT COUNT(*) FROM karaoke_settings WHERE k = ?"); $c->execute([$k]);
        if (!(int)$c->fetchColumn()) $db->prepare("INSERT INTO karaoke_settings (k, v) VALUES (?, ?)")->execute([$k, $v]);
    }
}
function kar_q_fair_on(PDO $db): bool { return kar_q_setting_get($db, 'q_fair') === '1'; }
function kar_q_fair_set(PDO $db, bool $on): void { kar_q_setting_set($db, 'q_fair', $on ? '1' : '0'); }

/* ---------------------------------------------------------------- who is who */
function kar_q_norm(string $s): string {
    $s = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)));
    if (class_exists('Normalizer')) { $d = Normalizer::normalize($s, Normalizer::FORM_D); if ($d !== false) $s = preg_replace('/\p{Mn}+/u', '', $d); }
    return $s;
}
/** The known people: ['names'=>[normalised name or full name => person key], 'firsts'=>[first word => key | null when two people share it]]. */
function kar_q_people(PDO $db): array {
    static $cache = []; $id = spl_object_id($db);
    if (isset($cache[$id]) && $cache[$id]['t'] > time() - 30) return $cache[$id]['v'];
    $names = []; $firstOf = [];
    $add = function ($name, $full) use (&$names, &$firstOf) {
        $n = kar_q_norm((string)$name); $f = kar_q_norm((string)$full);
        $key = $f !== '' ? $f : $n; if ($key === '') return;
        if ($n !== '') $names[$n] = $key; $names[$key] = $key;
        $w = explode(' ', $key)[0]; if ($w !== '') { if (isset($firstOf[$w]) && $firstOf[$w] !== $key) $firstOf[$w] = null; elseif (!array_key_exists($w, $firstOf)) $firstOf[$w] = $key; }
    };
    try { foreach ($db->query("SELECT name, full_name FROM karaoke_singers") as $r) $add($r['name'], $r['full_name']); } catch (Throwable $e) {}
    // a list name already known as someone's short name stays theirs (the list "Ann" belongs to the singer Ann Smith)
    try { foreach ($db->query("SELECT DISTINCT person FROM karaoke_best") as $r) { if (!in_array($r['person'], ['Dance', 'Warm-Up'], true) && !isset($names[kar_q_norm((string)$r['person'])])) $add($r['person'], ''); } } catch (Throwable $e) {}
    $v = ['names' => $names, 'firsts' => $firstOf];
    $cache[$id] = ['t' => time(), 'v' => $v];
    return $v;
}
/** The one identity a typed name stands for. */
function kar_q_person_key(PDO $db, string $name): string {
    $n = kar_q_norm($name); if ($n === '') return '';
    $p = kar_q_people($db);
    if (isset($p['names'][$n])) return $p['names'][$n];
    $w = explode(' ', $n)[0];
    if (isset($p['firsts'][$w]) && $p['firsts'][$w] !== null && (strpos($n, ' ') === false || strpos($p['firsts'][$w], $n) === 0)) return $p['firsts'][$w];   // "ann" / "ann s"
    if (strlen($n) >= 4 && strpos($n, ' ') === false) {            // one typo: "anne" -> "ann"
        $hits = [];
        foreach ($p['firsts'] as $f => $key) { if ($key !== null && levenshtein($n, (string)$f) === 1) $hits[$key] = 1; }
        if (count($hits) === 1) return (string)array_key_first($hits);
    }
    return $n;
}

/* ---------------------------------------------------------------- the party */
function kar_q_last_activity(PDO $db): int {
    $v = $db->query("SELECT MAX(created_at) FROM karaoke_sing_queue")->fetchColumn();
    return $v ? (int)strtotime((string)$v) : 0;
}
/** Epoch when the current party started; derived from the requests on file when nothing is stored yet. 0 = no party. */
function kar_q_party_start(PDO $db): int {
    $s = (int)kar_q_setting_get($db, 'party_start');
    if ($s > 0) return $s;
    $rows = $db->query("SELECT created_at FROM karaoke_sing_queue ORDER BY id DESC")->fetchAll(PDO::FETCH_COLUMN);
    if (!$rows) return 0;
    $start = (int)strtotime((string)$rows[0]); $prev = $start;
    foreach ($rows as $c) { $t = (int)strtotime((string)$c); if ($prev - $t >= KAR_PARTY_GAP) break; $start = $t; $prev = $t; }
    kar_q_setting_set($db, 'party_start', (string)$start);
    return $start;
}
/** Call BEFORE a new request is inserted: after a quiet gap (or on the very first request) this request starts a new party. */
function kar_q_before_add(PDO $db): void {
    $last = kar_q_last_activity($db);
    if ($last === 0 || time() - $last >= KAR_PARTY_GAP) kar_q_setting_set($db, 'party_start', (string)time());
    else kar_q_party_start($db);
}
function kar_q_party_new(PDO $db): void { kar_q_setting_set($db, 'party_start', (string)time()); }
/** For the queue panel. */
function kar_q_party_info(PDO $db): array {
    $s = kar_q_party_start($db); $last = kar_q_last_activity($db);
    return ['start' => $s, 'active' => ($s > 0 && $last > 0 && time() - $last < KAR_PARTY_GAP), 'at' => $s ? date('g:i A', $s) : ''];
}
function kar_q_cut(PDO $db): string { $s = kar_q_party_start($db); return date('Y-m-d H:i:s', $s > 0 ? $s : time() - 12 * 3600); }

/* ---------------------------------------------------------------- the fair order */
/** Songs each PERSON has had since the party started (Singing + Done), keyed by person key. */
function kar_q_sung_counts(PDO $db): array {
    $m = [];
    $st = $db->prepare("SELECT singer, COUNT(*) c FROM karaoke_sing_queue WHERE status IN ('Singing','Done') AND created_at >= ? GROUP BY singer");
    $st->execute([kar_q_cut($db)]);
    foreach ($st as $r) { $k = kar_q_person_key($db, (string)$r['singer']); $m[$k] = ($m[$k] ?? 0) + (int)$r['c']; }
    return $m;
}

/** The ids of the waiting requests in the fair order. $waiting = [['id'=>..,'singer'=>..],..] in the current order, singer
 *  being the person key. A request's ROUND = songs that person already had + how many of their requests sit ahead of it.
 *  Round 0 for everyone first, then round 1, and so on; inside a round the current order is kept, and the same person
 *  is never placed twice in a row while someone else is waiting at that level. */
function kar_q_fair_order(array $waiting, array $sung, ?string $lastSinger = null): array {
    $seen = []; $rows = [];
    foreach ($waiting as $i => $w) {
        $s = (string)$w['singer'];
        $k = $seen[$s] ?? 0; $seen[$s] = $k + 1;
        $rows[] = ['round' => (int)($sung[$s] ?? 0) + $k, 'idx' => $i, 'id' => (int)$w['id'], 's' => $s];
    }
    // Lowest round first, current order inside a round; then pick one at a time and NEVER the same person twice in a row
    // while another person is waiting at that same level (the person who just sang counts as the previous one).
    usort($rows, function ($a, $b) { return [$a['round'], $a['idx']] <=> [$b['round'], $b['idx']]; });
    $out = []; $prev = $lastSinger;
    while ($rows) {
        $min = $rows[0]['round']; $pick = 0;
        foreach ($rows as $n => $r) { if ($r['round'] !== $min) break; if ($r['s'] !== $prev) { $pick = $n; break; } }
        $r = array_splice($rows, $pick, 1)[0];
        $out[] = $r['id']; $prev = $r['s'];
    }
    return $out;
}

/** Re-sequence the Waiting requests fairly. Returns true if anything moved. */
function kar_q_arrange(PDO $db): bool {
    $w = $db->query("SELECT id, singer, position FROM karaoke_sing_queue WHERE status='Waiting' ORDER BY position ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    if (count($w) < 2) return false;
    $last = $db->query("SELECT singer FROM karaoke_sing_queue WHERE status='Singing' ORDER BY id DESC LIMIT 1")->fetchColumn();
    if ($last === false) {      // nobody on now: the last one to finish — tonight's party only
        $q = $db->prepare("SELECT singer FROM karaoke_sing_queue WHERE status='Done' AND created_at >= ? ORDER BY id DESC LIMIT 1");
        $q->execute([kar_q_cut($db)]);
        $last = $q->fetchColumn();
    }
    $keyed = array_map(function ($r) use ($db) { return ['id' => $r['id'], 'singer' => kar_q_person_key($db, (string)$r['singer'])]; }, $w);
    $ids = kar_q_fair_order($keyed, kar_q_sung_counts($db), $last === false ? null : kar_q_person_key($db, (string)$last));
    $cur = array_map(function ($r) { return (int)$r['id']; }, $w);
    if ($ids === $cur) return false;
    $pos = array_map(function ($r) { return (int)$r['position']; }, $w); sort($pos);
    $u = $db->prepare('UPDATE karaoke_sing_queue SET position = ? WHERE id = ?');
    foreach ($ids as $n => $id) $u->execute([$pos[$n] ?? ($n + 1), $id]);
    return true;
}

/** Call right after ANY request is added, from wherever: if fairness is on, the visible queue is re-sequenced. */
function kar_q_after_add(PDO $db): void { if (kar_q_fair_on($db)) kar_q_arrange($db); }
