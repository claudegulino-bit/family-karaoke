<?php
// karaoke_api.php — the STANDALONE endpoint.
//
// In server mode the page posts every action to /app.php, where 24 handlers turn it
// into a queue row that scripts/karaoke_watch.py picks up over SSH. None of that
// exists here: the page, the database and the player are on ONE Mac, so a ▶ Play is
// just a call to mpv. Same request shapes, same JSON answers — the page cannot tell
// the difference, which is the whole point of keeping one copy of it.
//
// ⚠ This file REFUSES to run unless the standalone marker is present. The live server
// never has that file, so app.php stays the only thing answering there.

require_once __DIR__ . '/karaoke_backend.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!kar_is_local()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'not a standalone karaoke install']);
    exit;
}

$ft = (string)($_POST['form_type'] ?? '');
$db = kar_db();

function kj($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

/** The pitch a request should actually play at: one-time value → stored → filename → 0. */
function kar_effective_pitch(PDO $db, string $song): int {
    $st = $db->prepare('SELECT pitch FROM karaoke_pitches WHERE filename = ?');
    $st->execute([$song]);
    $p = $st->fetchColumn();
    if ($p !== false) return (int)$p;
    return kar_filename_pitch($song) ?? 0;
}

try {
    switch ($ft) {

    // ---------------------------------------------------------------- playing
    case 'karaoke_play': {
        $song = trim((string)($_POST['song'] ?? ''));
        if (!kar_ok_name($song) || !kar_known($song)) kj(['ok'=>false,'error'=>'unknown song']);
        $pitch = kar_effective_pitch($db, $song);
        $once = trim((string)($_POST['pitch_once'] ?? ''));
        if ($once !== '' && preg_match('/^[+-]?\d{1,2}$/', $once) && (int)$once >= -12 && (int)$once <= 12) {
            $pitch = (int)$once;
        }
        // 'qmidi' only ever arrives if the hidden blue column has been switched back on.
        [$ok, $note] = ((string)($_POST['player'] ?? 'mpv') === 'qmidi')
            ? kar_play_qmidi($song, $pitch)
            : kar_play($song, $pitch);
        kj(['ok'=>$ok, 'error'=>$ok ? '' : $note, 'note'=>$note]);
    }

    case 'karaoke_stop':
        kj(['ok'=>true, 'note'=>kar_stop_all()]);

    case 'karaoke_live_pitch': {
        $p = trim((string)($_POST['pitch'] ?? ''));
        if (!preg_match('/^[+-]?\d{1,2}$/', $p) || (int)$p < -12 || (int)$p > 12) kj(['ok'=>false,'error'=>'invalid pitch']);
        if (!kar_mpv_alive()) kj(['ok'=>false,'error'=>'nothing is playing']);
        // Through the lua script so the on-screen UP/DOWN counter stays in step with the page.
        kar_mpv_send(['script-message', 'casai-set-pitch', (string)((int)$p)]);
        kj(['ok'=>true, 'error'=>'']);
    }

    case 'karaoke_live_tempo': {
        $t = trim((string)($_POST['tempo'] ?? ''));
        if (!preg_match('/^\d{2,3}$/', $t) || (int)$t < 50 || (int)$t > 150) kj(['ok'=>false,'error'=>'invalid tempo']);
        if (!kar_mpv_alive()) kj(['ok'=>false,'error'=>'nothing is playing']);
        kar_mpv_send(['set_property', 'speed', ((int)$t) / 100]);
        kar_mpv_send(['show-text', 'TEMPO ' . (int)$t . '%', 1500]);
        kj(['ok'=>true, 'error'=>'']);
    }

    // ---------------------------------------------------------------- pitches
    case 'karaoke_set_pitch': {
        $song = trim((string)($_POST['song'] ?? ''));
        $raw  = trim((string)($_POST['pitch'] ?? ''));
        if (!kar_ok_name($song) || !kar_known($song)) kj(['ok'=>false,'error'=>'unknown song']);
        if ($raw === '') {
            $db->prepare('DELETE FROM karaoke_pitches WHERE filename = ?')->execute([$song]);
            kar_sync_record_removal($db, 'pitch', $song);
            try { kar_sync($db, true); } catch (Throwable $e) { }
            kj(['ok'=>true, 'error'=>'', 'stored'=>false]);
        }
        if (!preg_match('/^[+-]?\d{1,2}$/', $raw) || (int)$raw < -12 || (int)$raw > 12) {
            kj(['ok'=>false, 'error'=>'pitch must be a whole number from -12 to +12']);
        }
        // A value equal to the song's own default is not an override — store nothing, so
        // the gold border never appears on a number that changes nothing.
        $default = kar_filename_pitch($song) ?? 0;
        if ((int)$raw === $default) {
            $db->prepare('DELETE FROM karaoke_pitches WHERE filename = ?')->execute([$song]);
            kar_sync_record_removal($db, 'pitch', $song);
            try { kar_sync($db, true); } catch (Throwable $e) { }
            kj(['ok'=>true, 'error'=>'', 'stored'=>false]);
        }
        $db->prepare("INSERT INTO karaoke_pitches (filename, pitch, updated_at) VALUES (?,?,datetime('now','localtime'))
                      ON CONFLICT(filename) DO UPDATE SET pitch = excluded.pitch, updated_at = excluded.updated_at")->execute([$song, (int)$raw]);
        $db->prepare('DELETE FROM karaoke_removals WHERE kind=? AND k1=? AND k2=?')->execute(['pitch', $song, '']);
        try { kar_sync($db, true); } catch (Throwable $e) { }
        kj(['ok'=>true, 'error'=>'', 'stored'=>true]);
    }

    // ------------------------------------------------------------- best lists
    case 'karaoke_best_toggle': {
        $song   = trim((string)($_POST['song'] ?? ''));
        $person = trim((string)($_POST['person'] ?? ''));
        $want   = (string)($_POST['want'] ?? '') === '1';
        if ($person === '' || mb_strlen($person) > 40 || strpbrk($person, '/\\') !== false) kj(['ok'=>false,'error'=>'bad person name']);
        if (!kar_ok_name($song) || !kar_known($song)) kj(['ok'=>false,'error'=>'unknown song']);
        if ($want) {
            $db->prepare('INSERT OR IGNORE INTO karaoke_best (person, filename) VALUES (?,?)')->execute([$person, $song]);
            // An add cancels an older removal, or the other Mac would keep taking it away.
            $db->prepare('DELETE FROM karaoke_removals WHERE kind=? AND k1=? AND k2=?')->execute(['best', $person, $song]);
        } else {
            $db->prepare('DELETE FROM karaoke_best WHERE person = ? AND filename = ?')->execute([$person, $song]);
            kar_sync_record_removal($db, 'best', $person, $song);
        }
        try { kar_sync($db, true); } catch (Throwable $e) { }
        kj(['ok'=>true, 'error'=>'', 'on'=>$want]);
    }

    case 'karaoke_best_remove_person': {
        $person = trim((string)($_POST['person'] ?? ''));
        if ($person === '' || mb_strlen($person) > 40 || strpbrk($person, '/\\') !== false) kj(['ok'=>false,'error'=>'bad person name']);
        $st = $db->prepare('SELECT filename FROM karaoke_best WHERE person = ? ORDER BY filename');
        $st->execute([$person]);
        $songs = $st->fetchAll(PDO::FETCH_COLUMN);
        if ($songs) {
            // Never delete without capturing the values somewhere recoverable first.
            kar_log('delete', 'Best list removed: ' . $person . ' (' . count($songs) . ') ' . json_encode($songs, JSON_UNESCAPED_UNICODE));
            $db->prepare('DELETE FROM karaoke_best WHERE person = ?')->execute([$person]);
            kar_sync_record_removal($db, 'person', $person);
            try { kar_sync($db, true); } catch (Throwable $e) { }
        }
        kj(['ok'=>true, 'error'=>'', 'removed'=>count($songs)]);
    }

    // ------------------------------------------------------------- guest link
    case 'karaoke_qr': {
        $rotate = (string)($_POST['action'] ?? 'get') === 'rotate';
        kj(['ok'=>true, 'error'=>'', 'url'=>kar_guest_url(kar_guest_token($rotate))]);
    }

    // -------------------------------------------------------- the singing line
    case 'karaoke_q_state':
    case 'karaoke_q_add':
    case 'karaoke_q_remove':
    case 'karaoke_q_move':
    case 'karaoke_q_play':
    case 'karaoke_q_clear': {
        $qState = function () use ($db) {
            $rows = [];
            $q = $db->query("SELECT id, singer, filename, pitch, status FROM karaoke_sing_queue
                             WHERE status IN ('Singing','Waiting')
                             ORDER BY (status='Singing') DESC, position ASC, id ASC");
            foreach ($q as $r) {
                $rows[] = ['id'=>(int)$r['id'], 'singer'=>$r['singer'], 'song'=>$r['filename'],
                           'pitch'=>(int)$r['pitch'], 'status'=>$r['status']];
            }
            return $rows;
        };
        // Songs each person has sung tonight — feeds Fair turns (full rotation).
        $qSung = function () use ($db) {
            $m = [];
            foreach ($db->query("SELECT singer, COUNT(*) c FROM karaoke_sing_queue WHERE status IN ('Singing','Done') GROUP BY singer") as $r) {
                $m[$r['singer']] = (int)$r['c'];
            }
            return $m;
        };
        try {
            if ($ft === 'karaoke_q_state') { try { kar_sync($db); } catch (Throwable $e) { } }
            if ($ft === 'karaoke_q_add') {
                $singer = trim((string)($_POST['singer'] ?? ''));
                $song   = trim((string)($_POST['song'] ?? ''));
                $pitch  = (int)($_POST['pitch'] ?? 0);
                if ($singer === '' || mb_strlen($singer) > 40 || strpbrk($singer, '/\\') !== false) throw new Exception('bad singer name');
                if ($pitch < -12 || $pitch > 12) $pitch = 0;
                if (!kar_ok_name($song) || !kar_known($song)) throw new Exception('unknown song');
                $pos = (int)$db->query('SELECT COALESCE(MAX(position),0) FROM karaoke_sing_queue')->fetchColumn() + 1;
                $db->prepare("INSERT INTO karaoke_sing_queue (singer, filename, pitch, status, position)
                              VALUES (?,?,?, 'Waiting', ?)")->execute([$singer, $song, $pitch, $pos]);
            } elseif ($ft === 'karaoke_q_remove') {
                $db->prepare("DELETE FROM karaoke_sing_queue WHERE id = ? AND status = 'Waiting'")->execute([(int)($_POST['id'] ?? 0)]);
            } elseif ($ft === 'karaoke_q_move') {
                $id  = (int)($_POST['id'] ?? 0);
                $dir = (string)($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';
                $cur = $db->prepare("SELECT id, position FROM karaoke_sing_queue WHERE id = ? AND status = 'Waiting'");
                $cur->execute([$id]);
                if ($row = $cur->fetch()) {
                    $op  = $dir === 'up' ? '<' : '>';
                    $ord = $dir === 'up' ? 'DESC' : 'ASC';
                    $nb = $db->prepare("SELECT id, position FROM karaoke_sing_queue WHERE status='Waiting' AND position $op ? ORDER BY position $ord LIMIT 1");
                    $nb->execute([$row['position']]);
                    if ($n = $nb->fetch()) {
                        $u = $db->prepare('UPDATE karaoke_sing_queue SET position = ? WHERE id = ?');
                        $u->execute([$n['position'], $row['id']]);
                        $u->execute([$row['position'], $n['id']]);
                    }
                }
            } elseif ($ft === 'karaoke_q_play') {
                $id = (int)($_POST['id'] ?? 0);
                $cur = $db->prepare("SELECT id, filename, pitch, singer FROM karaoke_sing_queue WHERE id = ? AND status = 'Waiting'");
                $cur->execute([$id]);
                if (!($row = $cur->fetch())) throw new Exception('entry not found (already played?)');
                $db->exec("UPDATE karaoke_sing_queue SET status='Done' WHERE status='Singing'");
                $db->prepare("UPDATE karaoke_sing_queue SET status='Singing' WHERE id = ?")->execute([$id]);
                if ((string)($_POST['player'] ?? 'mpv') === 'qmidi') kar_play_qmidi($row['filename'], (int)$row['pitch']);
                // The singer's name goes with the play, and that is what turns it into an
                // introduction. A plain ▶ Play from the song list passes no name and is unchanged.
                else                                                    kar_play($row['filename'], (int)$row['pitch'], (string)$row['singer']);
            } elseif ($ft === 'karaoke_q_clear') {
                $db->exec('DELETE FROM karaoke_sing_queue');
            }
            kj(['ok'=>true, 'error'=>'', 'queue'=>$qState(), 'sung'=>(object)$qSung()]);
        } catch (Throwable $qe) {
            kj(['ok'=>false, 'error'=>$qe->getMessage(), 'queue'=>$qState(), 'sung'=>(object)$qSung()]);
        }
    }

    // -------------------------------------------------- renaming and removing
    case 'karaoke_rename': {
        $song = trim((string)($_POST['song'] ?? ''));
        $stem = trim((string)($_POST['new_stem'] ?? ''));
        if (!kar_ok_name($song) || !kar_known($song)) kj(['ok'=>false,'error'=>'unknown song']);
        $ext = strtolower(pathinfo($song, PATHINFO_EXTENSION));
        if ($stem === '' || strlen($stem) > 230 || preg_match('#[/\\\\:]#', $stem) || $stem[0] === '.'
            || preg_match('/[\x00-\x1F]/', $stem)) {
            kj(['ok'=>false,'error'=>'that name is not allowed (no / \\ : characters, cannot start with a dot)']);
        }
        $newName = $stem . '.' . $ext;
        if ($newName === $song) kj(['ok'=>false,'error'=>'the name is unchanged']);
        $dir = kar_songs_dir();
        if (file_exists($dir . '/' . $newName)) kj(['ok'=>false,'error'=>'a song with that name already exists']);
        if (!@rename($dir . '/' . $song, $dir . '/' . $newName)) {
            kj(['ok'=>false,'error'=>'the file could not be renamed — is the folder available?']);
        }
        // Everything that points at the old name follows it, or the song loses its pitch,
        // its place on someone's Best list, and its row under 🆕 New.
        foreach ([
            'UPDATE karaoke_pitches   SET filename = ? WHERE filename = ?',
            'UPDATE karaoke_best      SET filename = ? WHERE filename = ?',
            'UPDATE karaoke_downloads SET filename = ? WHERE filename = ?',
            'UPDATE karaoke_sing_queue SET filename = ? WHERE filename = ?',
        ] as $sql) {
            try { $db->prepare($sql)->execute([$newName, $song]); } catch (Throwable $e) { /* keep going */ }
        }
        // The live-playlist symlinks (QMidi) point by path — repoint any that match.
        $live = kar_live_lists_dir();
        if (is_dir($live)) {
            foreach (glob($live . '/*/*') ?: [] as $lnk) {
                if (is_link($lnk) && basename((string)readlink($lnk)) === $song) {
                    @unlink($lnk);
                    @symlink($dir . '/' . $newName, dirname($lnk) . '/' . $newName);
                }
            }
        }
        $db->prepare('INSERT INTO karaoke_renames (old_filename, new_filename, status, done_at)
                      VALUES (?,?, "Done", datetime("now","localtime"))')->execute([$song, $newName]);
        kj(['ok'=>true, 'error'=>'', 'new_name'=>$newName]);
    }

    case 'karaoke_delete': {
        $song = trim((string)($_POST['song'] ?? ''));
        if (!kar_ok_name($song) || !kar_known($song)) kj(['ok'=>false,'error'=>'unknown song']);
        $dir  = kar_songs_dir();
        $dest = kar_deleted_dir();
        if (!is_dir($dest)) @mkdir($dest, 0755, true);
        // MOVED, never destroyed — a wrong click is always recoverable from that folder.
        $target = $dest . '/' . $song;
        $n = 2;
        while (file_exists($target)) {
            $target = $dest . '/' . pathinfo($song, PATHINFO_FILENAME) . " ($n)." . pathinfo($song, PATHINFO_EXTENSION);
            $n++;
        }
        if (!@rename($dir . '/' . $song, $target)) kj(['ok'=>false,'error'=>'the file could not be moved']);
        foreach (['DELETE FROM karaoke_pitches WHERE filename = ?',
                  'DELETE FROM karaoke_best WHERE filename = ?'] as $sql) {
            try { $db->prepare($sql)->execute([$song]); } catch (Throwable $e) { }
        }
        $live = kar_live_lists_dir();
        if (is_dir($live)) {
            foreach (glob($live . '/*/*') ?: [] as $lnk) {
                if (is_link($lnk) && basename((string)readlink($lnk)) === $song) @unlink($lnk);
            }
        }
        $db->prepare('INSERT INTO karaoke_deletes (filename, status, note, done_at)
                      VALUES (?, "Done", ?, datetime("now","localtime"))')
           ->execute([$song, 'moved to ' . basename($dest)]);
        kj(['ok'=>true, 'error'=>'']);
    }

    // ------------------------------------------------------------- downloads
    case 'karaoke_dl_add': {
        $url = trim((string)($_POST['url'] ?? ''));
        if (strlen($url) > 500 || !preg_match('#^https://(www\.|m\.|music\.)?(youtube\.com/(watch\?|shorts/)|youtu\.be/)[^\s]+$#', $url)) {
            kj(['ok'=>false,'error'=>'that does not look like a YouTube link']);
        }
        $dup = $db->prepare("SELECT COUNT(*) FROM karaoke_downloads WHERE url = ? AND status IN ('Queued','Pending','Downloading')");
        $dup->execute([$url]);
        if ((int)$dup->fetchColumn() > 0) kj(['ok'=>false,'error'=>'that link is already in the list']);
        $db->prepare('INSERT INTO karaoke_downloads (url) VALUES (?)')->execute([$url]);
        kar_worker_spawn();   // looks up the title + duplicate warning; downloads nothing yet
        kj(['ok'=>true, 'error'=>'']);
    }

    case 'karaoke_dl_start': {
        $n = $db->exec("UPDATE karaoke_downloads SET status='Pending' WHERE status='Queued'");
        kar_worker_spawn();
        kj(['ok'=>true, 'started'=>(int)$n]);
    }

    case 'karaoke_dl_remove': {
        // A Done row is 🆕 New's record of that song, not a list entry to tidy away.
        $st = $db->prepare("DELETE FROM karaoke_downloads WHERE id = ? AND status IN ('Queued','Error')");
        $st->execute([(int)($_POST['id'] ?? 0)]);
        kj(['ok'=>$st->rowCount() > 0]);
    }

    case 'karaoke_dl_clear': {
        // Clearing the LIST must never erase the HISTORY — Done rows are deliberately spared.
        $n = $db->exec("DELETE FROM karaoke_downloads WHERE status IN ('Queued','Error')");
        kj(['ok'=>true, 'removed'=>(int)$n]);
    }

    case 'karaoke_dl_state': {
        // The fetching machine, not an archive: successes retire in 10 minutes (the song
        // lives under 🆕 New), failures stay 7 days because this is the ONLY place a
        // failure is ever visible.
        $rows = $db->query("SELECT id, url, title, status, note, filename, requested_by FROM karaoke_downloads
            WHERE status IN ('Queued','Pending','Downloading')
               OR (status='Done'  AND COALESCE(done_at, requested_at) > datetime('now','localtime','-10 minutes'))
               OR (status='Error' AND COALESCE(done_at, requested_at) > datetime('now','localtime','-7 days'))
            ORDER BY id DESC LIMIT 20")->fetchAll();
        kar_worker_spawn();   // keeps a queue moving even if a worker died mid-fetch
        // The page polls this every few seconds whether or not a panel is open, which
        // makes it the natural heartbeat for keeping the Macs in step. Without it a
        // screen left open all evening never notices a song starred in the other room.
        try { kar_sync($db); } catch (Throwable $e) { }   // throttled inside, once a minute
        kj(['ok'=>true, 'rows'=>$rows]);
    }

    // --------------------------------------------------- setup helpers (Guide)
    case 'karaoke_pick_folder': {
        // Two-phase, exactly as the server version: start queues the job, check polls it.
        // A folder chooser waits for a person to walk to the Mac, so it CANNOT run inside
        // the request — it goes to a detached worker and the answer lands on the row.
        $tasks = ['folder' => '__PICKFOLDER__', 'tools' => '__CHECKTOOLS__', 'update' => '__UPDATE__'];
        $task = (string)($_POST['task'] ?? 'folder');
        // A task this version does not know is refused, not quietly treated as "open the
        // folder chooser" — which is how an older copy answered a newer button by putting
        // a dialog on the screen that nobody had asked for.
        if (!isset($tasks[$task])) kj(['ok'=>false, 'error'=>'this version does not know how to do that — update it first']);
        $mode = ((string)($_POST['mode'] ?? 'start') === 'check') ? 'check' : 'start';
        $sentinel = $tasks[$task];

        if ($mode === 'start') {
            $db->prepare("INSERT INTO karaoke_play_queue (filename, player, status) VALUES (?, 'all', 'Pending')")
               ->execute([$sentinel]);
            $id = (int)$db->lastInsertId();
            if ($task === 'tools') {
                // Fast enough to answer inline — no chooser, nothing to wait for.
                $parts = [];
                foreach (['mpv', 'yt-dlp'] as $t) {
                    $bin = kar_tool($t);
                    $v = is_file($bin) ? trim((string)@shell_exec(escapeshellarg($bin) . ' --version 2>/dev/null | head -1')) : '';
                    // "mpv 0.41.0 Copyright ..." and a bare "2026.08.19" both have to come out
                    // as just the version — so take the first number-looking word, whatever else
                    // the program decides to print around it.
                    $ver = preg_match('/\d[\d.]*/', $v, $m) ? $m[0] : '';
                    $parts[] = $v !== ''
                        ? "$t is installed (v$ver)"
                        : "$t is not installed yet";
                }
                $note = implode(' · ', $parts);
                if (strpos($note, 'not installed') !== false) $note .= ' — run the Terminal command above, then check again';
                $db->prepare("UPDATE karaoke_play_queue SET status='Played', note=? WHERE id=?")->execute([$note, $id]);
            } else {
                // 'folder' waits for a person at the Mac; 'update' talks to the internet.
                // Neither can happen inside a web request, so both go to the worker.
                $php = PHP_BINARY ?: 'php';
                @exec(escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/karaoke_worker.php') . ' '
                    . escapeshellarg((string)kar_marker_path()) . ' ' . ($task === 'update' ? 'update' : 'pick')
                    . ' ' . (int)$id . ' >/dev/null 2>&1 &');
            }
            kj(['ok'=>true, 'id'=>$id]);
        }

        $st = $db->prepare('SELECT status, note FROM karaoke_play_queue WHERE id = ? AND filename = ?');
        $st->execute([(int)($_POST['id'] ?? 0), $sentinel]);
        $r = $st->fetch();
        kj($r ? ['ok'=>true, 'status'=>$r['status'], 'note'=>(string)$r['note']]
              : ['ok'=>false, 'error'=>'not found']);
    }

    // The Mac picker does not exist in standalone — one Mac, nothing to address. The page
    // hides it, so these are never called; answered honestly rather than left to 404.
    case 'karaoke_mac_add':
    case 'karaoke_mac_remove':
        kj(['ok'=>false, 'error'=>'this karaoke runs on one Mac — there is nothing to choose between']);

    default:
        http_response_code(400);
        kj(['ok'=>false, 'error'=>'unknown action']);
    }
} catch (Throwable $e) {
    kar_log('error', $ft . ': ' . $e->getMessage());
    http_response_code(500);
    kj(['ok'=>false, 'error'=>'something went wrong on this Mac: ' . $e->getMessage()]);
}
