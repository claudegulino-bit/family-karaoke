<?php
// karaoke_worker.php — the background half of the standalone edition.
//
// Two jobs, both of which must NOT happen inside a web request:
//   downloads — a YouTube fetch takes minutes, and ▶ Play must stay instant
//   pick      — the folder chooser waits for a person to walk to the Mac
//   announce  — the MC introduction runs for ~25 seconds before the song comes up
//
// Started detached by karaoke_api.php; never reachable over HTTP (it refuses a
// web SAPI outright). One at a time, guarded by a lock file.
//
//   php karaoke_worker.php <marker-path> [pick <queue-id> | announce <base64-json>]

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }

// The marker path is handed in so the worker resolves the SAME install the page did —
// it has no DOCUMENT_ROOT of its own to fall back on.
$marker = $argv[1] ?? '';
if ($marker !== '' && is_file($marker)) $_SERVER['DOCUMENT_ROOT'] = dirname($marker);

require_once __DIR__ . '/karaoke_backend.php';
if (!kar_is_local()) exit("not a standalone install\n");

$db   = kar_db();
$job  = $argv[2] ?? 'downloads';
$lock = kar_data_dir() . '/worker.lock';

// ---------------------------------------------------------------------------
// The folder chooser
// ---------------------------------------------------------------------------
// ---------------------------------------------------------------------------
// The MC — the introduction, run out here so the web request never waits for it
// ---------------------------------------------------------------------------
if ($job === 'announce') {
    // Deliberately outside the worker lock: a YouTube download in progress is no reason
    // for the party to lose its announcements.
    // A short log, because the MC runs unattended in the background: when it goes wrong
    // there is otherwise nothing at all to look at. Timings included — the failure this
    // exists for was a sequencing one, and only the timings showed it.
    $mclog = function (string $m) {
        $d = kar_data_dir();
        @file_put_contents($d . '/mc.log', date('Y-m-d H:i:s') . '  ' . $m . "\n", FILE_APPEND);
    };
    $spec   = json_decode((string)base64_decode((string)($argv[3] ?? '')), true) ?: [];
    $song   = (string)($spec['song'] ?? '');
    $singer = (string)($spec['singer'] ?? '');
    if ($song === '' || $singer === '') exit;

    // Nothing below may stop the music. If any step fails, put the volume back and let
    // the song play — a party does not care that the announcer failed.
    try {
        // TWO SHAPES, depending on what applause this Mac has.
        //
        //   a crowd VIDEO — the player is already showing it, cheering, and the song is
        //                   loaded only when the presentation ends
        //   sound, or none — the song is loaded but held PAUSED, first frame on screen
        //
        // Either way the song itself is not heard until the introduction is over. An early
        // version played it quietly underneath as walk-on music, and on a Mac announcing a song
        // for the first time — where building the voice takes seconds — the announcement landed
        // on top of the music. Do not go back to that.
        for ($i = 0; $i < 40; $i++) {
            if (kar_mpv_alive()) break;
            usleep(250000);
        }
        if (!kar_mpv_alive()) throw new RuntimeException('the player never answered');

        $ap    = kar_mc_applause();
        $crowd = ($ap !== '' && kar_mc_applause_has_video($ap));

        if (!$crowd) kar_mpv_send(['set_property', 'pause', true]);   // certain, not assumed
        [$artist, $title] = kar_title_artist($song);                  // pure text, costs nothing
        $screen = $singer . ' will sing' . "\n" . $title . ($artist !== '' ? "\nfrom " . $artist : '');
        kar_mpv_send(['show-text', $screen, 60000]);

        // The crowd sits under the voice so the words win; it comes up for the walk.
        if ($crowd) kar_mpv_send(['set_property', 'volume', 38]);

        $t0    = microtime(true);
        $wav   = kar_mc_build($singer, $title, $artist);
        $spent = microtime(true) - $t0;
        $mclog(sprintf('%s / %s%s — voice %s (%.1fs), applause %s',
            $singer, $title, ($artist !== '' ? ' / ' . $artist : ''),
            ($wav !== '' ? 'ready' : 'FAILED TO BUILD'), $spent,
            ($ap === '' ? 'NONE FOUND — silent walk-up' : ($crowd ? 'ON SCREEN: ' : 'sound only: ') . $ap)));

        // Time already spent building counts towards the reading pause, so a cached
        // announcement still gets its full beat and a slow one does not wait twice.
        $left = KAR_MC_LEAD_IN - $spent;
        if ($left > 0) usleep((int)($left * 1000000));

        // Sound-only applause needs a separate player, and needs looping — a raw recording is
        // far shorter than the presentation and stops a third of the way through otherwise.
        $play = function (string $file, float $vol): int {
            if ($file === '') return 0;
            $out = [];
            @exec('afplay -v ' . escapeshellarg((string)$vol) . ' ' . escapeshellarg($file)
                . ' >/dev/null 2>&1 & echo $!', $out);
            return (int)($out[0] ?? 0);
        };
        $kill = function (int $pid) { if ($pid > 0) @exec('kill ' . $pid . ' >/dev/null 2>&1'); };
        $loop = (!$crowd && $ap !== '') ? kar_mc_applause_loop() : '';

        $soft = $crowd ? 0 : $play($loop, 0.30);
        if ($wav !== '') {
            @exec('afplay ' . escapeshellarg($wav) . ' >/dev/null 2>&1');   // blocks until spoken
        }
        $kill($soft);

        // And now the room lets go, while they stand, cross the floor and take the microphone.
        if ($crowd) kar_mpv_send(['set_property', 'volume', 100]);
        $loud = $crowd ? 0 : $play($loop, 1.0);
        usleep((int)(KAR_MC_WALK_UP * 1000000));
        $kill($loud);

        $mclog('presentation finished — starting the song');
        kar_mpv_send(['show-text', '', 1]);
        if ($crowd) {
            // Off the crowd and onto the song, which starts at its very first note.
            kar_mpv_send(['set_property', 'loop-file', 'no']);
            kar_mpv_send(['loadfile', kar_songs_dir() . '/' . $song, 'replace']);
            kar_mpv_send(['set_property', 'volume', 100]);
        } else {
            kar_mpv_send(['seek', 0, 'absolute']);
        }
        kar_mpv_send(['set_property', 'pause', false]);
    } catch (Throwable $e) {
        // Whatever went wrong, the song must still play — it is being held paused, so the
        // one thing that must never be skipped is releasing it.
        $mclog('FAILED: ' . $e->getMessage() . ' — starting the song anyway');
        @kar_mpv_send(['show-text', '', 1]);
        // Whatever went wrong, the song must still play. If the crowd is on screen it has to
        // be replaced; if the song is held it has to be released. Do both, blindly.
        @kar_mpv_send(['set_property', 'loop-file', 'no']);
        @kar_mpv_send(['set_property', 'volume', 100]);
        @kar_mpv_send(['loadfile', kar_songs_dir() . '/' . $song, 'replace']);
        @kar_mpv_send(['set_property', 'pause', false]);
    }
    exit;
}

if ($job === 'pick') {
    $id = (int)($argv[3] ?? 0);
    $script = 'try' . "\n"
        . 'set f to choose folder with prompt "Where are your karaoke songs?"' . "\n"
        . 'POSIX path of f' . "\n"
        . 'on error number -128' . "\n"
        . '"__CANCELLED__"' . "\n"
        . 'end try';
    // No timeout: it is waiting for a human, and a person can take minutes.
    $out = trim((string)@shell_exec('osascript -e ' . escapeshellarg($script) . ' 2>/dev/null'));

    if ($out === '' || $out === '__CANCELLED__') {
        // Cancel is a NO, not an error — nothing is changed.
        $db->prepare("UPDATE karaoke_play_queue SET status='Skipped', note=? WHERE id=?")
           ->execute(['cancelled — the songs folder was not changed', $id]);
        exit;
    }
    $picked = rtrim($out, '/');
    if (!is_dir($picked)) {
        $db->prepare("UPDATE karaoke_play_queue SET status='Error', note=? WHERE id=?")
           ->execute(['that folder could not be opened', $id]);
        exit;
    }
    // The sibling folders follow the songs folder — but ONLY if they were the default
    // beside the OLD one. A folder somebody set by hand is theirs, and moving it because
    // the songs moved would quietly relocate their deleted songs behind their back.
    $cfg = kar_cfg();
    $oldParent = dirname(kar_songs_dir());
    $newParent = dirname($picked);
    $patch = ['songs_folder' => $picked];
    foreach (['deleted_folder' => '09-Deleted by casAI', 'live_lists_folder' => '08-Live Playlists'] as $k => $d) {
        $was = (string)($cfg[$k] ?? '');
        if ($was === '' || $was === $oldParent . '/' . $d) $patch[$k] = $newParent . '/' . $d;
    }
    kar_cfg_save($patch);
    $n = count(kar_songs_fresh_in($picked));
    $db->prepare("UPDATE karaoke_play_queue SET status='Played', note=? WHERE id=?")
       ->execute([$n . ' songs found; saved — reload the page to see them', $id]);
    exit;
}

// ---------------------------------------------------------------------------
// Fetching a newer karaoke
// ---------------------------------------------------------------------------
if ($job === 'update') {
    $id  = (int)($argv[3] ?? 0);
    $dir = dirname((string)kar_marker_path());
    $sh  = $dir . '/update.sh';
    if (!is_file($sh)) {
        $db->prepare("UPDATE karaoke_play_queue SET status='Error', note=? WHERE id=?")
           ->execute(['this copy was not installed from the internet, so there is nothing to update from', $id]);
        exit;
    }
    // update.sh replaces the program files — including, possibly, this one. That is safe:
    // PHP has already read what it is running, and the page reloads afterwards.
    $out = (string)@shell_exec('cd ' . escapeshellarg($dir) . ' && /bin/bash ' . escapeshellarg($sh) . ' 2>&1');
    $lines = array_values(array_filter(array_map('trim', explode("\n", $out)), 'strlen'));
    $last  = $lines ? end($lines) : '';
    $ok = $last !== '' && (str_starts_with($last, 'Updated to') || str_starts_with($last, 'Already up to date'));
    $db->prepare("UPDATE karaoke_play_queue SET status=?, note=? WHERE id=?")
       ->execute([$ok ? 'Played' : 'Error',
                  mb_substr($last !== '' ? $last : 'the update did not finish', 0, 400), $id]);
    exit;
}

// ---------------------------------------------------------------------------
// Downloads
// ---------------------------------------------------------------------------
@touch($lock);
register_shutdown_function(static function () use ($lock) { @unlink($lock); });

$ytdlp   = kar_tool('yt-dlp');
$ffprobe = kar_tool('ffprobe');
$cfg     = kar_cfg();
// Some videos need the browser's cookies; it can also raise a keychain prompt, so it
// is off unless the config asks for it.
$cookies = !empty($cfg['browser_cookies']) ? ' --cookies-from-browser ' . escapeshellarg((string)$cfg['browser_cookies']) : '';

const KAR_GUEST_MAX_SECONDS = 720;   // 12 minutes — songs, not concerts

function kar_set(PDO $db, int $id, array $fields): void {
    $sets = []; $vals = [];
    foreach ($fields as $k => $v) { $sets[] = "$k = ?"; $vals[] = $v; }
    $vals[] = $id;
    $db->prepare('UPDATE karaoke_downloads SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
}

// --- 0 · answer searches -----------------------------------------------------
// A guest is standing there with a phone open, so these go before anything else.
$srch = $db->query("SELECT id, query FROM karaoke_searches WHERE status='Pending' ORDER BY id LIMIT 5")->fetchAll();
foreach ($srch as $q) {
    try {
        $rows = kar_yt_search((string)$q['query']);
        $db->prepare("UPDATE karaoke_searches SET status=?, results=?, done_at=? WHERE id=?")
           ->execute([$rows ? 'Done' : 'Empty', json_encode($rows, JSON_UNESCAPED_UNICODE),
                      date('Y-m-d H:i:s'), (int)$q['id']]);
    } catch (Throwable $e) {
        $db->prepare("UPDATE karaoke_searches SET status='Error', note=?, done_at=? WHERE id=?")
           ->execute([$e->getMessage(), date('Y-m-d H:i:s'), (int)$q['id']]);
    }
}
// Yesterday's searches are of no interest to anybody.
$db->exec("DELETE FROM karaoke_searches WHERE requested_at < datetime('now','localtime','-12 hours')");

// --- 1 · look up titles (and run the duplicate check) ----------------------
// Guest rows land as Pending, so their title has to be read before the download —
// that is where the duration gate happens.
$rows = $db->query("SELECT id, url, requested_by FROM karaoke_downloads
                    WHERE title IS NULL AND status IN ('Queued','Pending') ORDER BY id")->fetchAll();
foreach ($rows as $r) {
    $cmd = escapeshellarg($ytdlp) . ' --no-playlist --skip-download --no-warnings'
         . ' --print "%(title)s" --print "%(duration)s"' . $cookies . ' '
         . escapeshellarg($r['url']) . ' 2>/dev/null';
    $out = array_values(array_filter(array_map('trim', explode("\n", (string)@shell_exec($cmd))), 'strlen'));
    if (!$out) {
        kar_set($db, (int)$r['id'], ['status'=>'Error', 'note'=>'could not read that link', 'done_at'=>date('Y-m-d H:i:s')]);
        continue;
    }
    $title = $out[0];
    $secs  = isset($out[1]) ? (int)$out[1] : 0;
    if ($r['requested_by'] !== null && $secs > KAR_GUEST_MAX_SECONDS) {
        kar_set($db, (int)$r['id'], ['title'=>$title, 'status'=>'Error',
            'note'=>'that video is longer than 12 minutes — songs only, please', 'done_at'=>date('Y-m-d H:i:s')]);
        continue;
    }
    $matches = kar_dedup_matches($title);
    // dup_note is written ONCE and never overwritten — it is what the 🆕 New list shows
    // in its Duplicate column days later, long after the transient note is gone.
    $dup = $matches ? '⚠ you may already have this — compare with: ' . implode(' · ', $matches) : null;
    kar_set($db, (int)$r['id'], ['title'=>$title, 'note'=>$dup, 'dup_note'=>$dup]);
}

// --- 2 · download what has been started ------------------------------------
while ($row = $db->query("SELECT id, url, title, requested_by, auto_sing FROM karaoke_downloads
                          WHERE status='Pending' AND title IS NOT NULL ORDER BY id LIMIT 1")->fetch()) {
    $id = (int)$row['id'];
    kar_set($db, $id, ['status'=>'Downloading', 'note'=>'downloading…']);

    $dir = kar_songs_dir();
    // ⚠ THE CODEC RULE: force H.264. YouTube's "best" is often AV1, which plays as
    // sound with NO PICTURE — the one failure that looks like a broken song file.
    $fmt = 'bv*[vcodec^=avc1][ext=mp4]+ba[ext=m4a]/b[ext=mp4]/b';
    // The person who asked for it goes into the file name, in the house convention
    // (the owner, 2026-09-08) — "(0)" because a guest's song always starts at the original
    // key. It lands as "Title (0) Josie.mp4", so the end-of-night tidy-up is a tidy-up
    // rather than a rename from scratch.
    $nameTag = '';
    if (!empty($row['requested_by'])) {
        $who = preg_replace('#[/\\\\:*?"<>|%]#u', '', (string)$row['requested_by']);
        $who = trim(preg_replace('/\s+/u', ' ', $who));
        if ($who !== '') $nameTag = ' (0) ' . mb_substr($who, 0, 24);
    }

    // ffmpeg is what joins the picture to the sound. Without it the merge produces
    // nothing at all — and with --no-warnings it does so almost silently, leaving only
    // two "Title.f299.mp4" part-files behind. Say it plainly before trying, rather than
    // report "the download did not finish" for a thing that was never going to.
    $ffmpeg = kar_tool('ffmpeg');
    if (!is_file($ffmpeg)) {
        kar_set($db, $id, ['status'=>'Error', 'done_at'=>date('Y-m-d H:i:s'),
            'note'=>'ffmpeg is missing on this Mac, so the picture and the sound cannot be joined'
                  . ' into one file. In Terminal:  brew install ffmpeg']);
        kar_log('download', 'row ' . $id . ': ffmpeg missing');
        continue;
    }

    // NOT --no-warnings: a warning is often the only thing that says why nothing arrived,
    // and since 2026-09-11 this output is what the person is shown.
    // --ffmpeg-location: yt-dlp otherwise looks for ffmpeg on PATH, and the worker is
    // started by the web server with a bare one — so on a Mac where ffmpeg is installed
    // in /opt/homebrew/bin, yt-dlp still said "ffmpeg is not installed". Found the hard
    // way on the Kitchen Mac, 2026-09-11.
    $cmd = escapeshellarg($ytdlp) . ' --no-playlist --newline'
         . ' --ffmpeg-location ' . escapeshellarg(dirname($ffmpeg))
         . ' -f ' . escapeshellarg($fmt)
         . ' --merge-output-format mp4'
         . ' -o ' . escapeshellarg($dir . '/%(title)s' . $nameTag . '.%(ext)s')
         . ' --print after_move:filepath --no-simulate'
         . $cookies . ' ' . escapeshellarg($row['url']) . ' 2>&1';
    $out = (string)@shell_exec($cmd);

    // yt-dlp prints the name it MEANT to produce even when the merge fails, so a printed
    // path is not proof of anything — the file has to actually be there.
    $path = $intended = '';
    foreach (array_reverse(array_map('trim', explode("\n", $out))) as $line) {
        if ($line === '' || strpos($line, $dir . '/') !== 0) continue;
        if ($intended === '') $intended = $line;
        if (is_file($line)) { $path = $line; break; }
    }
    if ($path === '') {
        // Clear the half-finished parts, or they sit in the songs folder for ever.
        if ($intended !== '') {
            foreach (glob(preg_replace('/\.[^.\/]+$/', '', $intended) . '.f*') ?: [] as $frag) @unlink($frag);
        }
        kar_set($db, $id, ['status'=>'Error', 'note'=>kar_dl_reason($out),
                           'done_at'=>date('Y-m-d H:i:s')]);
        kar_log('download', 'row ' . $id . ' failed: ' . kar_dl_reason($out));
        continue;
    }
    $file = basename($path);
    $note = 'downloaded into the songs folder';
    // Verify rather than assume — a wrong codec warns instead of quietly delivering a
    // file that will play with no picture.
    if (is_file($ffprobe)) {
        $codec = trim((string)@shell_exec(escapeshellarg($ffprobe)
            . ' -v error -select_streams v:0 -show_entries stream=codec_name -of default=nw=1:nk=1 '
            . escapeshellarg($path) . ' 2>/dev/null'));
        if ($codec !== '' && stripos($codec, 'h264') === false) {
            $note .= ' — ⚠ it came down as ' . $codec . ', which may play with sound but no picture';
        }
    }
    kar_set($db, $id, ['status'=>'Done', 'filename'=>$file, 'note'=>$note, 'done_at'=>date('Y-m-d H:i:s')]);

    // A guest who brought a song becomes a real singer: it joins THEIR list and they
    // join the line — one request, and they are ready to sing it.
    if (!empty($row['requested_by']) && (int)$row['auto_sing'] === 1) {
        $who = (string)$row['requested_by'];
        try {
            $db->prepare('INSERT OR IGNORE INTO karaoke_best (person, filename) VALUES (?,?)')->execute([$who, $file]);
            $pos = (int)$db->query('SELECT COALESCE(MAX(position),0) FROM karaoke_sing_queue')->fetchColumn() + 1;
            $db->prepare("INSERT INTO karaoke_sing_queue (singer, filename, pitch, status, position)
                          VALUES (?,?,0,'Waiting',?)")->execute([$who, $file, $pos]);
            kar_set($db, $id, ['note'=>'brought by ' . $who . ' — on their list and in the line to sing']);
        } catch (Throwable $e) {
            kar_log('error', 'guest after-download: ' . $e->getMessage());
        }
    }
}
