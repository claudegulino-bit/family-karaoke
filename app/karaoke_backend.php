<?php
// karaoke_backend.php — the one place that knows WHICH karaoke this is.
//
// Built 2026-09-08 for the standalone edition (the owner, 2026-09-06: "If I give this
// to my brother... they're gonna be seeing my financials. How do I give them this
// without casAI?" — and his own better answer: "can they run that locally on their
// Mac so they don't need databases and clouds?").
//
// The page (karaoke.php) and the guest page are ONE copy each, serving both worlds:
//
//   SERVER MODE  — the owner's setup. MySQL on the VPS, the song list scp'd up as
//                  karaoke_songs.json, and every Mac-directed action queued for
//                  scripts/karaoke_watch.py to pick up over SSH. Handlers live in
//                  app.php and are NOT touched by this file.
//
//   STANDALONE   — one Mac, one house, no server and no casAI. SQLite in a file,
//                  the songs folder scanned live, and mpv driven straight from PHP.
//                  Nothing financial, medical or personal is anywhere near it.
//
// Mode is decided by ONE marker file (karaoke_standalone.json) sitting beside the
// page. The VPS never has that file, so the live system cannot be flipped by
// accident — the check is a file test, not a guess about the environment.
//
// ⚠ app.php keeps its own copy of the server-mode handlers. Two implementations of
// the same rules can drift; converging app.php onto this file is a deliberate,
// separately-verified follow-up, NOT something to slip into the same pass that
// builds standalone.

// ---------------------------------------------------------------------------
// Mode + configuration
// ---------------------------------------------------------------------------

$_kar_cfg = null;      // parsed karaoke_standalone.json, reset by kar_cfg_save()
$_kar_songs = null;    // the last folder scan, reset whenever the folder changes

function kar_marker_path(): ?string {
    // Checked in both places so the same bundle works whether the files are real
    // copies in one folder (a shipped standalone) or symlinks into public/ (how it
    // is developed and tested here — PHP resolves __DIR__ through a symlink to the
    // REAL directory, which would otherwise miss the marker entirely).
    $candidates = [];
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $candidates[] = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/karaoke_standalone.json';
    }
    $candidates[] = __DIR__ . '/karaoke_standalone.json';
    foreach ($candidates as $p) {
        if (is_file($p)) return $p;
    }
    return null;
}

function kar_is_local(): bool {
    static $v = null;
    if ($v === null) $v = kar_marker_path() !== null;
    return $v;
}

// ── PHP AND SQLITE MUST AGREE ON WHAT TIME IT IS ──────────────────────────────────────
// SQLite stamps every row with datetime('now','localtime') — the Mac's own clock — while
// PHP writes done_at with date(), on PHP's timezone. A bare Homebrew PHP has no timezone
// of its own and answers UTC, so the two land HOURS apart and every comparison between
// them is silently wrong: a finished download sat in the panel for four hours instead of
// two minutes (Kitchen Mac, 2026-09-14 — and this laptop's own rows were 4h out too).
//
// ⚠ Do NOT guard this on `!ini_get('date.timezone')`. PHP reports 'UTC' from ini_get even
// when the setting is COMMENTED OUT in php.ini, so such a guard never fires on precisely
// the machines that need it — measured on two Macs, 14 Sep 2026.
//
// Standalone only. The server names its timezone deliberately and its database shares
// that same clock, so there is nothing to reconcile there and nothing to disturb.
if (kar_is_local()) {
    $_kar_tz = @readlink('/etc/localtime');
    if ($_kar_tz && preg_match('#zoneinfo/(.+)$#', $_kar_tz, $_kar_m)
        && in_array($_kar_m[1], timezone_identifiers_list(), true)
        && $_kar_m[1] !== date_default_timezone_get()) {
        date_default_timezone_set($_kar_m[1]);
    }
    unset($_kar_tz, $_kar_m);
}

/**
 * "N units ago", in whichever dialect this install speaks. The standalone edition is
 * SQLite and casAI is MariaDB, and a cutoff written in one is a fatal error in the
 * other — this is the one place that difference is allowed to live.
 * $unit is a bare word: minutes / hours / days.
 */
function kar_ago(int $n, string $unit): string {
    $n = max(0, $n);
    $unit = strtolower(preg_replace('/[^a-z]/i', '', $unit));
    if (!in_array($unit, ['minutes', 'hours', 'days'], true)) $unit = 'hours';
    if (kar_is_local()) return "datetime('now','localtime','-$n $unit')";
    return 'NOW() - INTERVAL ' . $n . ' ' . strtoupper(rtrim($unit, 's'));
}

/**
 * "now", in whichever dialect this edition is talking. Lives here beside kar_ago() so the
 * SQLite/MariaDB difference has exactly ONE home.
 */
function kar_now_sql(): string {
    return kar_is_local() ? "datetime('now','localtime')" : 'NOW()';
}

function kar_cfg(): array {
    global $_kar_cfg;
    if ($_kar_cfg !== null) return $_kar_cfg;
    $cfg = [];
    $m = kar_marker_path();
    if ($m) {
        $j = json_decode((string)file_get_contents($m), true);
        if (is_array($j)) $cfg = $j;
    }
    // The songs folder may also be set the way the watcher already reads it, so a
    // machine that already ran the casAI karaoke keeps working without re-choosing.
    if (empty($cfg['songs_folder'])) {
        $home = getenv('HOME') ?: '';
        $alt = $home . '/casai/karaoke_config.json';
        if ($home && is_file($alt)) {
            $j = json_decode((string)file_get_contents($alt), true);
            if (is_array($j) && !empty($j['songs_folder'])) {
                $cfg['songs_folder'] = $j['songs_folder'];
                foreach (['deleted_folder', 'temp_folder', 'live_lists_folder'] as $k) {
                    if (empty($cfg[$k]) && !empty($j[$k])) $cfg[$k] = $j[$k];
                }
            }
        }
    }
    $_kar_cfg = $cfg;
    return $cfg;
}

function kar_cfg_save(array $patch): bool {
    global $_kar_cfg;
    $m = kar_marker_path();
    if (!$m) return false;
    $cfg = kar_cfg();
    foreach ($patch as $k => $v) $cfg[$k] = $v;
    $ok = @file_put_contents($m, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false;
    // Keep the rest of THIS request honest — a stale cache would report the old folder.
    if ($ok) { $_kar_cfg = $cfg; kar_songs_cache_clear(); }
    return $ok;
}

function kar_songs_dir(): string {
    $c = kar_cfg();
    if (!empty($c['songs_folder'])) return rtrim($c['songs_folder'], '/');
    return (getenv('HOME') ?: '') . '/Music';
}

function kar_sibling_dir(string $key, string $default): string {
    $c = kar_cfg();
    if (!empty($c[$key])) return rtrim($c[$key], '/');
    return dirname(kar_songs_dir()) . '/' . $default;
}

function kar_deleted_dir(): string   { return kar_sibling_dir('deleted_folder', '09-Deleted by casAI'); }
function kar_live_lists_dir(): string { return kar_sibling_dir('live_lists_folder', '08-Live Playlists'); }

function kar_data_dir(): string {
    $c = kar_cfg();
    if (!empty($c['data_folder'])) $d = rtrim($c['data_folder'], '/');
    else {
        $m = kar_marker_path();
        $d = ($m ? dirname($m) : sys_get_temp_dir()) . '/data';
    }
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $d;
}

// ---------------------------------------------------------------------------
// Database — SQLite locally, the caller's MySQL PDO on the server
// ---------------------------------------------------------------------------

// Same nine tables as the server, same column names and meanings, so every query
// in the page and every handler reads the same shape in both worlds.
const KAR_SCHEMA = [
    "CREATE TABLE IF NOT EXISTS karaoke_pitches (
        filename TEXT PRIMARY KEY,
        pitch    INTEGER NOT NULL,
        updated_at TEXT DEFAULT (datetime('now','localtime')))",
    "CREATE TABLE IF NOT EXISTS karaoke_best (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        person   TEXT NOT NULL,
        filename TEXT NOT NULL,
        created_at TEXT DEFAULT (datetime('now','localtime')),
        UNIQUE (person, filename))",
    "CREATE TABLE IF NOT EXISTS karaoke_sing_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        singer   TEXT NOT NULL,
        filename TEXT NOT NULL,
        pitch    INTEGER NOT NULL DEFAULT 0,
        status   TEXT NOT NULL DEFAULT 'Waiting',
        position INTEGER NOT NULL DEFAULT 0,
        created_at TEXT DEFAULT (datetime('now','localtime')))",
    "CREATE TABLE IF NOT EXISTS karaoke_downloads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        url          TEXT NOT NULL,
        requested_by TEXT DEFAULT NULL,
        auto_sing    INTEGER NOT NULL DEFAULT 0,
        title        TEXT DEFAULT NULL,
        status       TEXT NOT NULL DEFAULT 'Queued',
        note         TEXT DEFAULT NULL,
        dup_note     TEXT DEFAULT NULL,
        filename     TEXT DEFAULT NULL,
        requested_at TEXT DEFAULT (datetime('now','localtime')),
        done_at      TEXT DEFAULT NULL)",
    "CREATE TABLE IF NOT EXISTS karaoke_play_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        filename   TEXT NOT NULL,
        pitch      INTEGER DEFAULT NULL,
        player     TEXT NOT NULL DEFAULT 'mpv',
        target_mac TEXT DEFAULT NULL,
        status     TEXT NOT NULL DEFAULT 'Pending',
        note       TEXT DEFAULT NULL,
        requested_at TEXT DEFAULT (datetime('now','localtime')),
        played_at  TEXT DEFAULT NULL)",
    "CREATE TABLE IF NOT EXISTS karaoke_renames (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        old_filename TEXT NOT NULL,
        new_filename TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'Pending',
        note   TEXT DEFAULT NULL,
        requested_at TEXT DEFAULT (datetime('now','localtime')),
        done_at TEXT DEFAULT NULL)",
    "CREATE TABLE IF NOT EXISTS karaoke_deletes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        filename TEXT NOT NULL,
        status   TEXT NOT NULL DEFAULT 'Pending',
        note     TEXT DEFAULT NULL,
        requested_at TEXT DEFAULT (datetime('now','localtime')),
        done_at  TEXT DEFAULT NULL)",
    // A guest searching YouTube from their phone. The page cannot run yt-dlp itself
    // (on casAI it is not even the same machine), so the search is a request the Mac
    // answers — exactly like a play or a download. Results are a JSON blob; the row is
    // disposable and swept after a few hours.
    "CREATE TABLE IF NOT EXISTS karaoke_searches (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        query        TEXT NOT NULL,
        requested_by TEXT DEFAULT NULL,
        status       TEXT NOT NULL DEFAULT 'Pending',
        results      TEXT DEFAULT NULL,
        note         TEXT DEFAULT NULL,
        -- 1 = steer the search at karaoke and hide the rest (the normal case); 0 = the
        -- words exactly as typed, everything shown. The host's checkbox, 2026-09-14.
        want_karaoke INTEGER NOT NULL DEFAULT 1,
        requested_at TEXT DEFAULT (datetime('now','localtime')),
        done_at      TEXT DEFAULT NULL)",
    "CREATE TABLE IF NOT EXISTS karaoke_settings (k TEXT PRIMARY KEY, v TEXT NOT NULL)",
    // Removals have to be remembered, not just done. Two Macs merge their lists by
    // taking the union of what each one has — so without a record of "this was taken
    // off, at this moment", every un-starred song would come straight back from the
    // other machine on the next sync.
    "CREATE TABLE IF NOT EXISTS karaoke_removals (
        kind TEXT NOT NULL,          -- 'best' | 'person' | 'pitch'
        k1   TEXT NOT NULL,          -- person, or filename for a pitch
        k2   TEXT NOT NULL DEFAULT '',
        at   TEXT NOT NULL,
        PRIMARY KEY (kind, k1, k2))",
    "CREATE TABLE IF NOT EXISTS karaoke_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        at TEXT DEFAULT (datetime('now','localtime')),
        kind TEXT, detail TEXT)",
];

/** Columns added after a version shipped. Applied on every open, failures ignored —
 *  see the note in kar_db(). Append here; never edit a line already released. */
const KAR_ADD_COLUMNS = [
    "ALTER TABLE karaoke_searches ADD COLUMN want_karaoke INTEGER NOT NULL DEFAULT 1",
    // Which duplicate rule wrote a row's dup_note - see kar_new_downloads().
    "ALTER TABLE karaoke_downloads ADD COLUMN dup_rule TEXT DEFAULT NULL",
    // When he marked the song CHECKED and took it off the 🆕 New review list.
    "ALTER TABLE karaoke_downloads ADD COLUMN reviewed_at TEXT DEFAULT NULL",
];

function kar_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $file = kar_data_dir() . '/karaoke.sqlite';
    $fresh = !is_file($file);
    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // WAL so a long download writing status never blocks the page reading the list.
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA busy_timeout=5000');
    foreach (KAR_SCHEMA as $sql) $pdo->exec($sql);
    // CREATE TABLE IF NOT EXISTS cannot add a column to a database that already exists,
    // so every new column needs a line here too — otherwise a Mac that has been running
    // Cantoria for months breaks on the first query after an update. SQLite throws
    // "duplicate column name" when it is already there, which is the expected case.
    foreach (KAR_ADD_COLUMNS as $sql) { try { $pdo->exec($sql); } catch (Throwable $e) { /* already present */ } }
    if ($fresh) kar_log('setup', 'created ' . $file);
    return $pdo;
}

function kar_log(string $kind, string $detail): void {
    try {
        kar_db()->prepare('INSERT INTO karaoke_log (kind, detail) VALUES (?,?)')
                ->execute([$kind, mb_substr($detail, 0, 500)]);
    } catch (Throwable $e) { /* logging must never break a party */ }
}

// ---------------------------------------------------------------------------
// The song catalog
// ---------------------------------------------------------------------------

const KAR_MEDIA_EXT = ['mp4','mp3','m4a','mov','mid','kar','midi','avi','m4v','wav'];

/** Every song file in a folder, sorted — the standalone equivalent of the
 *  karaoke_songs.json the server is fed by karaoke_sync.py. Scanned live, so a
 *  download or a rename shows up on the next page load with nothing to push. */
function kar_songs_fresh_in(string $dir): array {
    $out = [];
    if (is_dir($dir)) {
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '' || $f[0] === '.') continue;
            if (!is_file($dir . '/' . $f)) continue;
            // The applause is allowed to live in the songs folder — that is the one folder
            // every Mac in the family definitely has — but it is not a song and must never
            // appear in the list or be picked by scheduling fairness.
            if (strcasecmp(pathinfo($f, PATHINFO_FILENAME), 'applause') === 0) continue;
            if (in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), KAR_MEDIA_EXT, true)) $out[] = $f;
        }
    }
    // Accent-aware, case-insensitive — the same order the server's sorted list has.
    usort($out, static fn($a, $b) => strcasecmp($a, $b));
    return $out;
}

function kar_songs_fresh(): array { return kar_songs_fresh_in(kar_songs_dir()); }

function kar_songs_cache_clear(): void { global $_kar_songs; $_kar_songs = null; }

/** The catalog, scanned once per request. */
function kar_songs(): array {
    global $_kar_songs;
    if ($_kar_songs === null) $_kar_songs = kar_songs_fresh();
    return $_kar_songs;
}

/** The catalog, whichever way this install learns about its songs — the folder here,
 *  the published karaoke_songs.json on the server. One reader, so the guest page and
 *  the host page can never disagree about which songs exist. */
function kar_catalog(?PDO $db = null): array {
    if (kar_is_local()) return kar_songs();
    $j = @json_decode((string)@file_get_contents('/var/www/your-server/karaoke_songs.json'), true);
    return (is_array($j) && !empty($j['database']) && is_array($j['database'])) ? array_values($j['database']) : [];
}

function kar_catalog_has(?PDO $db, string $song): bool {
    return $song !== '' && in_array($song, kar_catalog($db), true);
}

function kar_known(string $song): bool {
    return in_array($song, kar_songs_fresh(), true);
}

/** The same guard the server applies before ever touching a name. */
function kar_ok_name(string $n): bool {
    if ($n === '' || strlen($n) > 255) return false;
    if (strpos($n, '/') !== false || strpos($n, "\\") !== false || strpos($n, ':') !== false) return false;
    if ($n[0] === '.') return false;
    if (preg_match('/[\x00-\x1F]/', $n)) return false;
    return true;
}

function kar_filename_pitch(string $name): ?int {
    // (0) (-3) is the usual notation; [-2] [+1] appears on some older files. Parentheses win.
    // Signed only inside brackets — a bare [2] probably means "version 2", and [C] [Am] in those
    // same names are chord tags. Must stay in step with karFnPitch() in karaoke.php.
    if (preg_match('/\(([+-]?\d{1,2})\)/', $name, $m)) return (int)$m[1];
    if (preg_match('/\[([+-]\d{1,2})\]/', $name, $m))  return (int)$m[1];
    return null;
}

// ---------------------------------------------------------------------------
// The player — mpv, driven straight from PHP (no queue, no SSH, no watcher)
// ---------------------------------------------------------------------------

const KAR_MPV_SOCK = '/tmp/casai-mpv.sock';

function kar_tool(string $name): string {
    // Apple Silicon and Intel keep Homebrew in different places; hardcoding either
    // means the system installs cleanly on the other and fails on the first Play.
    foreach (["/opt/homebrew/bin/$name", "/usr/local/bin/$name"] as $p) {
        if (is_file($p)) return $p;
    }
    $which = trim((string)@shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
    return $which !== '' ? $which : "/opt/homebrew/bin/$name";
}

/**
 * Turn yt-dlp's own output into a reason a person can act on.
 *
 * Every failed download used to say the same eleven words — "the download did not
 * finish — try a different YouTube version" — while the real reason, which yt-dlp
 * prints every time, was thrown away. That made a failure in another house
 * impossible to diagnose at all. (the owner, 2026-09-11: "I have not been able to
 * download any song.")
 *
 * So: keep yt-dlp's own words, and put a plain-English sentence in front of the few
 * causes that have one. Never guess beyond that — the raw line is always the truth.
 */
function kar_dl_reason(string $out): string {
    $lines = array_values(array_filter(array_map('trim', explode("\n", $out)), 'strlen'));
    if (!$lines) return 'yt-dlp printed nothing at all, so it may not have run. Press "Check it worked" in the Guide.';

    // yt-dlp names the problem on an ERROR line. Keep the LAST one: a retry chain can
    // print several, and the final one is its verdict.
    $err = '';
    foreach ($lines as $l) { if (stripos($l, 'ERROR') === 0) $err = $l; }
    // No ERROR line? Then a WARNING usually holds it. Failing that, the last line — but
    // never a bare file path: yt-dlp prints the name it MEANT to write even when the
    // merge fails, and handing that back as "the reason" explains nothing.
    if ($err === '') { foreach ($lines as $l) { if (stripos($l, 'WARNING') === 0) $err = $l; } }
    if ($err === '') $err = (string)end($lines);
    if ($err !== '' && $err[0] === '/') {
        $err = 'yt-dlp ran but no finished file arrived, and it gave no reason.'
             . ' Usually the picture and sound could not be joined — check ffmpeg with'
             . ' "Check it worked" in the Guide.';
    }
    $err = preg_replace('/^ERROR:\s*/i', '', $err);
    $err = preg_replace('#^\[[^\]]+\]\s*[\w-]{6,}:\s*#', '', $err);   // drop "[youtube] dQw4w9WgXcQ:"

    // Only causes whose wording is unambiguous get a translation.
    $hints = [
        'ffmpeg' => 'ffmpeg is missing on this Mac, so the picture and the sound could not be joined into one file. Install it: brew install ffmpeg',
        'sign in to confirm'      => 'YouTube asked this Mac to prove it is not a robot. Try a different version of the song.',
        'confirm your age'        => 'YouTube wants an age check on that video. Try a different version of the song.',
        'private video'           => 'That video is private.',
        'members-only'            => 'That video is for channel members only.',
        'no space left'           => 'The disk is full.',
        'permission denied'       => 'This Mac was not allowed to write into the songs folder.',
    ];
    $hint = '';
    foreach ($hints as $needle => $text) {
        if (stripos($err, $needle) !== false) { $hint = $text . ' — '; break; }
    }
    return mb_substr($hint . $err, 0, 240);
}

function kar_mpv_send(array $cmd, float $timeout = 2.0) {
    $sock = @stream_socket_client('unix://' . KAR_MPV_SOCK, $errno, $errstr, $timeout);
    if (!$sock) return null;
    stream_set_timeout($sock, (int)$timeout, (int)(($timeout - (int)$timeout) * 1e6));
    fwrite($sock, json_encode(['command' => $cmd]) . "\n");
    $resp = fgets($sock);
    fclose($sock);
    return $resp === false ? null : $resp;
}

function kar_mpv_alive(): bool {
    if (!file_exists(KAR_MPV_SOCK)) return false;
    $r = kar_mpv_send(['get_property', 'pid']);
    return $r !== null && strpos($r, '"success"') !== false;
}

function kar_lua_path(): string {
    // Shipped beside the page in a standalone bundle; falls back to the casAI
    // scripts folder on a machine that already has the full system.
    $m = kar_marker_path();
    foreach ([($m ? dirname($m) : '') . '/casai_pitch.lua',
              (getenv('HOME') ?: '') . '/casai/scripts/casai_pitch.lua'] as $p) {
        if ($p !== '/casai_pitch.lua' && is_file($p)) return $p;
    }
    return '';
}

/** Play a song NOW. Returns [ok, note]. */
function kar_play(string $song, int $pitch, string $singer = ''): array {
    $path = kar_songs_dir() . '/' . $song;
    if (!is_file($path)) return [false, 'file not found in the songs folder'];
    $scale = round(2 ** ($pitch / 12.0), 6);
    // A named singer means an introduction. Two shapes, depending on what we have:
    //   with a crowd VIDEO — the applause plays on screen and the song is loaded at the end
    //   without one       — the song is loaded PAUSED and released when the voice is done
    // Either way nothing of the song is heard until the presentation is over.
    $mc    = ($singer !== '' && kar_mc_on());
    $crowd = $mc ? kar_mc_applause() : '';
    if ($crowd !== '' && !kar_mc_applause_has_video($crowd)) $crowd = '';
    if ($crowd !== '') $path = $crowd;          // the player opens on the crowd, not the song
    if (kar_mpv_alive()) {
        // Pause BEFORE loading: mpv keeps the property across a loadfile, so the new song
        // arrives already held. Setting it after would let a moment of audio escape.
        kar_mpv_send(['set_property', 'pause', $mc && $crowd === '']);
        // AND THE PICTURE (the owner, 2026-09-13: "the announcement is still overlapping with
        // the video"). Paused held the sound back but left the song's first frame on screen
        // behind the announcement, so the video appeared to start and then start again. With
        // the video track off the introduction happens on a black screen; the worker turns it
        // back on at the end. Set BEFORE loadfile - mpv keeps it across the load, so there is
        // not even a flash. Always reasserted, so a previous announcement cannot leave it off.
        // force-window FIRST: with no video track mpv makes no window at all, so the
        // announcement would have nowhere to appear (the owner, 2026-09-13).
        kar_mpv_send(['set_property', 'force-window', 'yes']);
        kar_mpv_send(['set_property', 'vid', ($mc && $crowd === '') ? 'no' : 'auto']);
        kar_mpv_send(['set_property', 'loop-file', $crowd !== '' ? 'inf' : 'no']);
        kar_mpv_send(['set_property', 'volume', 100]);
        // Re-assert the words window's on-top setting on EVERY song, not only at launch.
        // A running player keeps whatever it started with, so an instance that was already
        // open when the switch was turned on would stay behind the browser for the rest of
        // the night. Sent both ways, so unticking takes effect on the next song too.
        kar_mpv_send(['set_property', 'ontop', !empty(kar_cfg()['words_on_top'])]);
        kar_mpv_send(['loadfile', $path, 'replace']);
        // Speed persists across loads — every song starts at normal tempo.
        kar_mpv_send(['set_property', 'speed', 1.0]);
        // Through the lua script so the on-screen UP/DOWN counter stays in step.
        kar_mpv_send(['script-message', 'casai-set-pitch', (string)$pitch]);
        if ($mc) { kar_mc_spawn($song, $singer, $pitch); }
        else     { kar_mpv_send(['show-text', sprintf('casAI player · pitch %+d · UP/DOWN arrows change it', $pitch), 5000]); }
        return [true, sprintf('pitch %+d applied%s', $pitch, $mc ? ', announcing ' . $singer : '')];
    }
    @unlink(KAR_MPV_SOCK);
    $lua = kar_lua_path();
    $args = [
        kar_tool('mpv'),
        '--input-ipc-server=' . KAR_MPV_SOCK,
        // ⚠ The @rb LABEL is mandatory — without it af-command "set-pitch" fails
        // with "error running command" and the song plays at the original key
        // while the screen cheerfully reports the pitch it was asked for.
        '--af=@rb:rubberband=pitch-scale=' . $scale,
    ];
    if ($lua !== '') {
        $args[] = '--script=' . $lua;
        $args[] = '--script-opts=casai-pitch=' . $pitch;
    }
    // NOT fullscreen: the screen is shared — words on one side, the song list on the
    // other. F toggles fullscreen when the whole screen is wanted.
    $args[] = '--geometry=55%x70%-0+60';
    // ⚠ THE PLAYER STAYS OPEN BETWEEN SONGS, and that is the point of these two options.
    // Left to itself mpv quits when a song ends and the next one opens a brand-new window
    // wherever the geometry says — so any arrangement of words-here, song-list-there is
    // undone every single song. Idle + keep-open means ONE window: put it where you want
    // it once, and every later song loads into that same window, same place, same size.
    // It also fixes the words opening behind the browser, without forcing them on top of
    // it — a background service cannot raise a window, but it does not need to if the
    // window never went away.
    $args[] = '--idle=yes';
    $args[] = '--keep-open=always';
    // Off by default, deliberately: pinning the words above everything stops the two
    // windows being used side by side. "words_on_top": true if you want it anyway.
    $cfg = kar_cfg();
    if (!empty($cfg['words_on_top'])) $args[] = '--ontop';
    $args[] = '--osd-font-size=48';
    if ($mc) {
        if ($crowd !== '') {
            $args[] = '--loop-file=inf';                  // the crowd keeps going
        } else {
            $args[] = '--pause';                          // held until the presentation is done
            $args[] = '--vid=no';                         // and no picture until then either
            $args[] = '--force-window=yes';               // but keep a black window for the words
        }
        $args[] = '--osd-align-x=center';
        $args[] = '--osd-align-y=center';
        $args[] = '--osd-duration=60000';
    }
    $args[] = $path;
    $cmd = implode(' ', array_map('escapeshellarg', $args)) . ' >/dev/null 2>&1 & echo $!';
    @exec($cmd);
    for ($i = 0; $i < 20; $i++) {
        if (kar_mpv_alive()) break;
        usleep(250000);
    }
    if ($mc) { kar_mc_spawn($song, $singer, $pitch); }
    else     { kar_mpv_send(['show-text', sprintf('casAI player · pitch %+d · UP/DOWN arrows change it · F fullscreen · Q closes', $pitch), 6000]); }
    return [true, sprintf('pitch %+d applied%s', $pitch, $mc ? ', announcing ' . $singer : '')];
}

// ─────────────────────────────────────────────────────────────────────────────
// THE MC — the introduction that plays before a singer's song
//
// the owner's design, settled by ear on 10 September 2026:
//   the song is loaded but HELD PAUSED, its first frame on screen
//   the screen names the singer and the song
//   the voice, ABOVE everything — "And now… <singer> will sing… <title>, from <artist>"
//   applause with the screen still up, long enough to stand, cross the floor, take the
//     microphone, turn round and say thank you
//   only then does the song start, from the very beginning, with nothing over it
//
// Nothing here may stop the music. Every step is best-effort: if the voice cannot be
// built, or mpv does not answer, the song plays anyway without an introduction. A party
// does not care that the announcer failed.
// ─────────────────────────────────────────────────────────────────────────────

const KAR_MC_LEAD_IN  = 3.0;   // seconds of screen before the voice, to read it
const KAR_MC_WALK_UP  = 14.0;  // applause + screen, while the singer reaches the mic

// ⚠ THE SONG IS HELD PAUSED FOR THE WHOLE INTRODUCTION (the owner, 10 Sep 2026:
// "the song has to start after the presentation is complete"). An earlier version played it
// quietly underneath as walk-on music, and on a Mac hearing a song for the first time — where
// building the voice takes a few seconds — that came out as the announcement landing on top of
// the music. Paused, the overlap is not merely quiet, it is impossible.

/** The name to SAY. A person can keep more than one Best list - "Claude" for songs sung
 *  well, "Claude — practice" for ones still being learned - and the list name doubles as the
 *  singer name. Announce the part before the separator so the MC does not say "Claude —
 *  practice will sing". A dash inside a name (Jean-Paul) is untouched: the separator must
 *  have spaces around it, or be a bracket. */
function kar_mc_name(string $singer): string {
    foreach ([' — ', ' – ', ' - ', ' ('] as $sep) {
        $i = mb_strpos($singer, $sep);
        if ($i !== false) return trim(mb_substr($singer, 0, $i));
    }
    return $singer;
}

/** Is the MC switched on? Off by "announce": false in karaoke_standalone.json. */
function kar_mc_on(): bool {
    $c = kar_cfg();
    return !array_key_exists('announce', $c) || !empty($c['announce']);
}

/** Which voice speaks. Evan (Enhanced) unless the config names another.
 *
 * ⚠ Voice CLASS is what matters, not the name. Of the 185 voices macOS ships, almost all
 * are "compact" and sound like a robot — nine Italian ones were rejected one after another
 * before this was understood. Evan is Enhanced. If he is missing on this Mac, download him:
 * System Settings ▸ Accessibility ▸ Spoken Content ▸ System voice ▸ ⓘ. */
function kar_mc_voice(): string {
    $c = kar_cfg();
    $want = trim((string)($c['announce_voice'] ?? ''));
    $have = (string)@shell_exec('say -v "?" 2>/dev/null');
    if ($want !== '' && strpos($have, $want) !== false) return $want;
    if (strpos($have, 'Evan (Enhanced)') !== false) return 'Evan (Enhanced)';
    return '';   // whatever the Mac's own default voice is — worse, but it still speaks
}

/** Split a karaoke filename into the artist and the title a person would say aloud.
 *
 * Measured against the owner's real 2,058-song library: artist AND title both correct on 97%.
 * Everything stripped here describes the FILE, never the song — pitch markers like (-3),
 * CSG codes, the karaoke singers' own names, [C]/[D] key tags, USA1/2/3 numbering, and words
 * such as Video, Lyrics, Testo, Cori, Karaoke. */
function kar_title_artist(string $file): array {
    $junk = 'lyrics?|letras?|testo|testi|karaokes?|official|video|audio|hd|hq|4k|instrumental|base|'
          . 'cover|remaster(?:ed)?|cori|con\s+cori|senza\s+voce|con\s+voce|full|version|'
          . 'originale?|live|remix|edit|clip|spanish|italian|english|usa\d*|ita\d*|esp\d*';
    $s = preg_replace('/\.[A-Za-z0-9]{2,4}$/', '', $file);
    $s = preg_replace('/\(\s*[+-]?\d{1,2}\s*\)/', ' ', $s);          // (0) (-3) pitch
    $s = preg_replace('/\bCSG\d*\b/i', ' ', $s);
    foreach (kar_singer_names() as $p) {
        $s = preg_replace('/\b' . preg_quote($p, '/') . '\b/iu', ' ', $s);
    }
    $s = preg_replace('/[\(\[\{][^()\[\]{}]*(?:' . $junk . ')[^()\[\]{}]*[\)\]\}]/iu', ' ', $s);
    for ($i = 0; $i < 4; $i++) {
        $s = preg_replace('/\s*[-–—]\s*(?:' . $junk . ')(?:\s+(?:' . $junk . '))*\s*$/iu', '', $s);
    }
    $s = preg_replace('/\[[^\]]{0,3}\]/', ' ', $s);                  // [C] [D] [+1] key tags
    $s = preg_replace('/^\s*party\s*[-–—]\s*/i', '', $s);            // compilation prefix
    $s = trim(preg_replace('/\s{2,}/', ' ', $s), " -–—_@");

    $parts = preg_split('/\s+[-–—]\s*|\s*[-–—]\s+/u', $s, 2);
    $artist = (count($parts) === 2 && trim($parts[1]) !== '') ? $parts[0] : '';
    $title  = (count($parts) === 2 && trim($parts[1]) !== '') ? $parts[1] : $s;

    $tidy = function (string $v) use ($junk): string {
        $v = preg_replace('/^[\s@#]+/', '', $v);
        $v = preg_replace('/\s*\b(?:feat\.?|ft\.?|featuring)\b.*$/iu', '', $v);
        $v = preg_replace('/[\(\[\{].*?[\)\]\}]/u', ' ', $v);
        for ($i = 0; $i < 5; $i++) {
            $v = preg_replace('/\s+(?:' . $junk . ')\s*$/iu', '', $v);
        }
        return trim(preg_replace('/\s{2,}/', ' ', $v), " -–—_.,&");
    };
    $artist = $tidy($artist);
    $title  = preg_replace('/\s+\d$/', '', $tidy($title));           // trailing copy number
    if ($title === '') { $artist = ''; $title = $tidy($s); }
    return [$artist, $title];
}

/** Every singer name Cantoria knows, so they can be stripped out of a filename. */
function kar_singer_names(): array {
    static $names = null;
    if ($names !== null) return $names;
    $names = [];
    try {
        foreach (kar_db()->query('SELECT DISTINCT person FROM karaoke_best') as $r) {
            $n = trim((string)$r['person']);
            if ($n !== '' && mb_strlen($n) >= 3) $names[] = $n;
        }
    } catch (Throwable $e) { /* a brand-new install has no lists yet */ }
    return $names;
}

/** One spoken line, as a wav. Cached: a line is spoken once in the life of the system. */
function kar_mc_clip(string $text, string $voice, int $rate): string {
    $dir = kar_data_dir() . '/mc';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $out = $dir . '/' . substr(sha1($voice . '|' . $rate . '|' . $text), 0, 16) . '.aiff';
    if (is_file($out) && filesize($out) > 0) return $out;
    $cmd = 'say';
    if ($voice !== '') $cmd .= ' -v ' . escapeshellarg($voice);
    $cmd .= ' -r ' . (int)$rate . ' -o ' . escapeshellarg($out) . ' ' . escapeshellarg($text) . ' 2>/dev/null';
    @exec($cmd);
    return (is_file($out) && filesize($out) > 0) ? $out : '';
}

/** The whole announcement as one wav: three lines, real silence between them, loud.
 *
 * Kept as three separate clips joined by silence rather than one sentence — that is what
 * makes the pause a dial instead of a guess, and it survives changing the voice. */
// ── THE THREE ANNOUNCERS (the owner, 2026-09-14) ──────────────────────────────────────
// A song is announced WHOLE in its own language, by that language's voice. Four phrasings
// each, and they are the SAME FOUR MOODS in every language - formal welcome · and-now ·
// your turn · applause - so the rotation varies the wording without changing the character
// of the MC. Each line is three parts because the audio is three clips joined by real
// silence; that rhythm was settled by ear on 10 Sep and is untouched here.
//
// ⚠ THIS IS THE SECOND IMPLEMENTATION. casAI's watcher announces through
// ~/Karaoke/sounds/announce.py; this is the standalone edition's own. They must be changed
// TOGETHER or the two will drift - the phrasings and the language test below are a
// deliberate mirror of that file.
const KAR_MC_PHRASINGS = [
  'en' => [['Ladies and gentlemen',       '{singer} will sing',                   '{title}, by {artist}!'],
           ['And now',                    '{singer} will sing our next song',     '{title}, by {artist}!'],
           ['Next up',                    '{singer} is about to sing',            '{title}, by {artist}!'],
           ["Let's hear it for {singer}", 'who is going to sing',                 '{title}, by {artist}!']],
  'it' => [['Signore e signori',           '{singer} canterà',                     '{title}, di {artist}!'],
           ['E adesso',                    '{singer} canterà la prossima canzone', '{title}, di {artist}!'],
           ['Tocca a {singer}',            'che canta',                            '{title}, di {artist}!'],
           ['Un applauso per {singer}',    'che si appresta a cantare',            '{title}, di {artist}!']],
  'es' => [['Señoras y señores',           '{singer} cantará',                     '{title}, de {artist}!'],
           ['Y ahora',                     '{singer} cantará la próxima canción',  '{title}, de {artist}!'],
           ['Le toca a {singer}',          'que canta',                            '{title}, de {artist}!'],
           ['Un aplauso para {singer}',    'que está a punto de cantar',           '{title}, de {artist}!']],
];

/** Which language is this song? Read from the title, where the answer honestly is. The
 *  decisive signal is shape, not vocabulary: Italian and Spanish words almost always end in
 *  a vowel (~90%), English ones rarely (~35%). Undecided answers English. */
function kar_mc_lang(string $title, string $artist = ''): string {
    $noise = ' party video lyrics lyric testo testi karaoke official audio hd hq live remastered versione version base musicale sanremo vincitore folk dance napoletano napoletana canzoni alta qualita qualità academy italia cover instrumental full album new nuovo mix spanish english csg csg0 csg1 the of ';
    $it = ' il lo la gli le un uno una di del della dei delle che non se per con mi ti ci vi ne è ma come quando dove tutto tutti questo quella quello sono sei siamo amore cuore notte vita tempo sempre più mai perché me te noi voi loro nel nella sul sulla da dal dalla alla ai al così anche solo senza dopo prima ancora niente nulla uomo donna mondo giorno sera cielo mare sole luna casa cosa canta canzone ';
    $es = ' el los las de que no si por para tu su es está están pero como cuando donde todo todos nada nadie quien corazón amor noche vida siempre nunca sin más muy bien ser estar hacer tener querer mujer hombre mundo día sol mar luna casa cosa yo ella nosotros ustedes canción ';
    $en = ' the a an of to in on for with and or but you your my me is are am was were be been do dont cant wont love heart night day time life world man woman girl boy baby never always all some this that what when where how why go going get got make take say said know now here there out up down over again away home come like just only from ';
    $t = mb_strtolower($title . ' ' . $artist);
    $t = preg_replace('/\.[a-z0-9]{2,4}$/u', '', $t);
    $t = preg_replace('/\(.*?\)|\[.*?\]/u', ' ', $t);
    $t = preg_replace("/[^a-zàèéìòùáíóúüñ'\s-]/u", ' ', $t);
    $w = [];
    foreach (preg_split('/\s+/u', trim($t)) as $x) {
        $x = trim($x, "-'");
        if (mb_strlen($x) > 1 && mb_strpos($noise, ' ' . $x . ' ') === false) $w[] = $x;
    }
    $n = count($w);
    if ($n < 2) return 'en';
    $acc = 0; $cit = 0; $ces = 0; $cen = 0; $vend = 0;
    foreach ($w as $x) {
        if (preg_match('/[àèéìòùáíóúñ]/u', $x)) $acc++;
        if (mb_strpos($it, ' ' . $x . ' ') !== false) $cit++;
        if (mb_strpos($es, ' ' . $x . ' ') !== false) $ces++;
        if (mb_strpos($en, ' ' . $x . ' ') !== false) $cen++;
        if (mb_strpos('aeiou', mb_substr($x, -1)) !== false) $vend++;
    }
    $romance = 3.0 * $acc + 1.5 * ($cit + $ces) - 1.5 * $cen + (($vend / $n) - 0.55) * 8.0;
    if ($romance <= 1.0) return 'en';
    // Spanish or Italian? Grave accents (è ò ì ù) are Italian only; Spanish uses acute and ñ.
    // Spanish ends words on consonants far more often, and the attached pronouns pair up:
    // bésame/abrázame against baciami/abbracciami.
    $txt = implode(' ', $w);
    $sp = $ces * 2 + preg_match_all('/ñ/u', $txt) * 4 + preg_match_all('/[áíóú]/u', $txt) * 2;
    $il = $cit * 2 + preg_match_all('/[èòìù]/u', $txt) * 4;
    foreach ($w as $x) {
        if (preg_match('/(ción|dad|ame|arme|arte|mos|cho|cha)$/u', $x)) $sp += 2;
        if (mb_strlen($x) > 3 && mb_strpos('snrlzd', mb_substr($x, -1)) !== false) $sp += 1;
        if (mb_strpos($x, 'gli') !== false || mb_strpos($x, 'gn') !== false) $il += 3;
        if (preg_match('/(zione|mento|ami|armi|arti|iamo|etto|ino)$/u', $x)) $il += 2;
    }
    return $sp > $il ? 'es' : 'it';
}

/** A shuffled bag, not a cycle: never the same phrasing twice running, and all four are
 *  used before any repeats. */
function kar_mc_phrasing(string $lang): array {
    $set = KAR_MC_PHRASINGS[$lang] ?? KAR_MC_PHRASINGS['en'];
    $f   = kar_data_dir() . '/mc/phrasing_bag.json';
    $bag = is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: []) : [];
    $left = $bag[$lang] ?? [];
    if (!$left) {
        $left = range(0, count($set) - 1);
        shuffle($left);
        $last = $bag[$lang . '_last'] ?? null;
        if ($last !== null && count($left) > 1 && $left[0] === $last) { $left[] = array_shift($left); }
    }
    $i = (int)array_shift($left);
    $bag[$lang] = array_values($left);
    $bag[$lang . '_last'] = $i;
    @mkdir(dirname($f), 0775, true);
    @file_put_contents($f, json_encode($bag));
    return $set[$i];
}

/** The best announcer voice for a language THAT IS ACTUALLY INSTALLED on this Mac.
 *
 * ⚠ WHY THIS EXISTS. The Enhanced voices are a separate download in System Settings, so on a
 * Mac that has never been set up for Cantoria they are simply absent. Naming one anyway made
 * `say` fail, kar_mc_clip return '', kar_mc_build return '' — and the announcement went SILENT
 * with nothing on screen to say why, only "FAILED TO BUILD" in data/mc.log. That is the worst
 * shape of bug for a brand-new install: everything looks fine and one feature is quietly dead.
 *
 * So: take what the owner configured if it is installed, else the best installed voice for that
 * language, else ANY voice in that language, else '' — which makes kar_mc_clip drop the -v flag
 * and use the Mac's own default. Worse, but it still speaks. Never silent. */
function kar_mc_voice_for(string $lang): string {
    static $have = null;
    if ($have === null) $have = (string)@shell_exec('say -v "?" 2>/dev/null');
    $c = kar_cfg();

    // 1) what the owner asked for, if this Mac actually has it
    $want = trim((string)($c['announce_voice_' . $lang] ?? ''));
    if ($want !== '' && strpos($have, $want) !== false) return $want;

    // 2) the nicest voice we know of for that language, if installed
    $pref = [
        'en' => ['Ava (Premium)', 'Evan (Enhanced)', 'Samantha (Enhanced)', 'Samantha', 'Alex'],
        'it' => ['Alice (Enhanced)', 'Alice', 'Federica (Enhanced)', 'Luca (Enhanced)'],
        'es' => ['Mónica (Enhanced)', 'Mónica', 'Diego (Enhanced)', 'Diego', 'Paulina'],
    ];
    foreach (($pref[$lang] ?? []) as $v) {
        if (strpos($have, $v) !== false) return $v;
    }

    // 3) any installed voice at all in that language — `say -v "?"` lists "Name   xx_YY   # sample"
    $code = ['en' => 'en_', 'it' => 'it_', 'es' => 'es_'][$lang] ?? 'en_';
    foreach (explode("\n", $have) as $line) {
        if (preg_match('/^(.+?)\s{2,}' . $code . '/', $line, $m)) return trim($m[1]);
    }

    // 4) the Mac's own default. Not ideal, but it speaks.
    return '';
}

function kar_mc_build(string $singer, string $title, string $artist): string {
    $c      = kar_cfg();
    $pause  = (float)($c['announce_pause'] ?? 0.9);
    // The song picks its own announcer: language from the title, then that language's voice
    // and one of its four phrasings.
    $lang   = kar_mc_lang($title, $artist);
    $voice  = kar_mc_voice_for($lang);
    [$p1, $p2, $p3] = kar_mc_phrasing($lang);
    if ($artist === '') $p3 = '{title}!';
    $fill = function (string $x) use ($singer, $title, $artist): string {
        return str_replace(['{singer}', '{title}', '{artist}'], [$singer, $title, $artist], $x);
    };
    $lead = $fill($p1); $mid = $fill($p2); $tail = $fill($p3);
    $a = kar_mc_clip($lead, $voice, 178);
    $b = kar_mc_clip($mid !== '' ? $mid : ' ', $voice, 178);
    $d = kar_mc_clip($tail, $voice, 115);
    if ($a === '' || $b === '' || $d === '') return '';

    $dir = kar_data_dir() . '/mc';
    $out = $dir . '/' . substr(sha1($voice . '|' . $lead . '|' . $mid . '|' . $tail . '|' . $pause), 0, 16) . '.wav';
    if (is_file($out) && filesize($out) > 0) return $out;

    $ff = kar_tool('ffmpeg');
    if ($ff === '') return '';
    // Loud and plain. loudnorm ERRORS above I=-5 rather than clamping, so normalise to its
    // ceiling and drive that into a limiter — louder than loudnorm alone can go, still clean.
    $filter = '[0:a]volume=3.2[s1];[1:a]volume=3.3[s2];[2:a]atempo=0.94,volume=3.8[s3];'
            . 'aevalsrc=0:d=' . $pause . ':s=24000[p1];aevalsrc=0:d=' . $pause . ':s=24000[p2];'
            . '[s1][p1][s2][p2][s3]concat=n=5:v=0:a=1,'
            . 'acompressor=threshold=0.06:ratio=6:attack=3:release=100,'
            . 'loudnorm=I=-5:TP=-0.5,volume=1.7,alimiter=limit=0.98:level=disabled';
    $cmd = escapeshellarg($ff) . ' -y -v error'
         . ' -i ' . escapeshellarg($a) . ' -i ' . escapeshellarg($b) . ' -i ' . escapeshellarg($d)
         . ' -filter_complex ' . escapeshellarg($filter)
         . ' -ar 44100 -ac 2 ' . escapeshellarg($out) . ' 2>/dev/null';
    @exec($cmd);
    return (is_file($out) && filesize($out) > 0) ? $out : '';
}

/** The applause that carries the walk to the microphone.
 *
 * ⚠ NO APPLAUSE RECORDING SHIPS WITH CANTORIA, and that is deliberate. The one used in the
 * original house came from YouTube under an uploader's "no copyright" label — a claim, not a
 * licence. Fine inside one family; not something to publish in a public repository.
 *
 * So it is looked for instead, in three places, in this order:
 *   1. whatever "applause" names in karaoke_standalone.json
 *   2. sounds/applause.wav next to the app
 *   3. NEXT TO THE SONGS — "@ Cantoria/sounds/applause.wav" beside the songs folder
 *
 * Three is the useful one. A family that shares its songs through Drive shares this too: put
 * the file there once and every Mac in the house has it, with nothing to copy and nothing
 * published. With no file at all the walk-up simply runs in silence. */
function kar_mc_applause(): string {
    $c     = kar_cfg();
    $songs = kar_songs_dir();
    $near  = $songs !== '' ? dirname($songs) . '/@ Cantoria/sounds/' : '';
    // A VIDEO is preferred over a sound file: a cheering crowd on screen while the singer
    // walks up beats a frozen frame of the song, and it plays through the player itself
    // rather than a separate afplay — one less thing to go wrong on someone else's Mac.
    $tries = [trim((string)($c['applause'] ?? ''))];
    foreach (['mp4', 'mov', 'm4v', 'wav', 'mp3', 'm4a'] as $ext) {
        $tries[] = __DIR__ . '/sounds/applause.' . $ext;
        // Shipped with the bundle, flat beside the page — update.sh copies files with a plain
        // `cp`, so a subdirectory would never arrive. This is the CC0 recording every install
        // gets out of the box, so a fresh Mac is never silent during the walk to the mic.
        $tries[] = __DIR__ . '/applause.' . $ext;
        if ($near  !== '') $tries[] = $near . 'applause.' . $ext;
        if ($songs !== '') $tries[] = $songs . '/applause.' . $ext;
    }
    foreach ($tries as $p) {
        if ($p !== '' && is_file($p)) return $p;
    }
    return '';
}

/** Does the applause we found have a picture, or is it only sound? */
function kar_mc_applause_has_video(string $file = ''): bool {
    $f = $file !== '' ? $file : kar_mc_applause();
    if ($f === '') return false;
    return in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), ['mp4', 'mov', 'm4v'], true);
}

/** The applause, looped long enough to actually cover the presentation.
 *
 * The recording itself is about six seconds. The walk to the microphone alone is fourteen, and
 * the announcement is on top of that — so played once it stopped a third of the way in and left
 * the room in silence, which is exactly how it sounded. This loops it out to a comfortable
 * length once, caches it, and fades the last second so the end is never a cliff. */
function kar_mc_applause_loop(float $seconds = 45.0): string {
    $src = kar_mc_applause();
    if ($src === '') return '';
    $ff = kar_tool('ffmpeg');
    if ($ff === '') return $src;                     // no ffmpeg: better six seconds than none
    $dir = kar_data_dir() . '/mc';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $out = $dir . '/applause_' . (int)$seconds . 's_' . substr(sha1($src . filemtime($src)), 0, 10) . '.wav';
    if (is_file($out) && filesize($out) > 0) return $out;
    $cmd = escapeshellarg($ff) . ' -y -v error -stream_loop -1 -i ' . escapeshellarg($src)
         . ' -t ' . (int)$seconds
         . ' -af ' . escapeshellarg('afade=t=out:st=' . ((int)$seconds - 1) . ':d=1')
         . ' -ar 44100 -ac 2 ' . escapeshellarg($out) . ' 2>/dev/null';
    @exec($cmd);
    return (is_file($out) && filesize($out) > 0) ? $out : $src;
}

/** Start the introduction in the background so the web request returns at once. */
function kar_mc_spawn(string $song, string $singer, int $pitch): void {
    $worker = __DIR__ . '/karaoke_worker.php';
    if (!is_file($worker)) return;
    $php = PHP_BINARY ?: 'php';
    $arg = base64_encode(json_encode(['song' => $song, 'singer' => $singer, 'pitch' => $pitch]));
    // Deliberately NOT behind the worker lock: a download running is no reason for the
    // party to lose its announcements.
    @exec(escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' '
        . escapeshellarg(kar_marker_path() ?: '') . ' announce ' . escapeshellarg($arg)
        . ' >/dev/null 2>&1 &');
}

function kar_qmidi_available(): bool { return is_dir('/Applications/QMidi Pro.app'); }

/** The blue ▶ QMidi button. Optional in standalone — a Mac that has never had QMidi
 *  gets told so plainly rather than hearing the song come out of the other player. */
function kar_play_qmidi(string $song, int $pitch): array {
    if (!kar_qmidi_available()) {
        return [false, 'QMidi is not installed on this Mac — use the green ▶ Play button, which needs nothing else'];
    }
    $path = kar_songs_dir() . '/' . $song;
    if (!is_file($path)) return [false, 'file not found in the songs folder'];
    if (kar_mpv_alive()) kar_mpv_send(['quit']);       // never two songs at once
    @exec('open -a ' . escapeshellarg('QMidi Pro') . ' ' . escapeshellarg($path) . ' >/dev/null 2>&1');
    // QMidi has no absolute pitch command — reset, then step. The is-running guard matters:
    // a bare tell would LAUNCH it.
    $dir = $pitch >= 0 ? 'pitch up' : 'pitch down';
    $lines = ['if application "QMidi Pro" is running then', 'tell application "QMidi Pro"', 'delay 1.5', 'reset pitch'];
    for ($i = 0; $i < min(abs($pitch), 12); $i++) $lines[] = $dir;
    $lines[] = 'end tell'; $lines[] = 'end if';
    @exec('osascript -e ' . escapeshellarg(implode("\n", $lines)) . ' >/dev/null 2>&1');
    return [true, sprintf('pitch %+d applied (QMidi)', $pitch)];
}

function kar_stop_all(): string {
    $notes = [];
    if (kar_mpv_alive()) { kar_mpv_send(['quit']); $notes[] = 'player closed'; }
    else $notes[] = 'player was not running';
    // QMidi is optional in standalone — only ever touched if it is actually there,
    // and the "is running" guard matters: a bare tell would LAUNCH it.
    if (is_dir('/Applications/QMidi Pro.app')) {
        @exec('osascript -e ' . escapeshellarg(
            'if application "QMidi Pro" is running then' . "\n" .
            'tell application "QMidi Pro" to stop' . "\n" . 'delay 0.7' . "\n" .
            'tell application "QMidi Pro" to stop' . "\n" . 'end if') . ' >/dev/null 2>&1');
        $notes[] = 'QMidi stopped';
    }
    return implode('; ', $notes);
}

// ---------------------------------------------------------------------------
// Downloads — a detached worker, so a multi-minute fetch never blocks ▶ Play
// ---------------------------------------------------------------------------

function kar_worker_spawn(): void {
    $worker = __DIR__ . '/karaoke_worker.php';
    if (!is_file($worker)) return;
    $lock = kar_data_dir() . '/worker.lock';
    // One worker at a time. A lock older than 40 minutes is a crashed run, not a
    // live one — the longest single download the worker itself allows is 30.
    if (is_file($lock) && (time() - (int)@filemtime($lock)) < 2400) return;
    @touch($lock);
    $php = PHP_BINARY ?: 'php';
    $marker = kar_marker_path() ?: '';
    @exec(escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($marker) . ' >/dev/null 2>&1 &');
}

// ---------------------------------------------------------------------------
// "Do we already have this song?"  —  the owner, 2026-09-20
// ---------------------------------------------------------------------------
// His words, and they are the specification:
//   "Say we already have this ONLY if it is the same song from the same singer.
//    It needs to be minimum two words of the SONG NAME, not the singer name.
//    Adriano Celentano is the singer name — if you match the singer name you get
//    ALL the songs, which is not the right thing to do. The words that make up the
//    title may be in the wrong sequence, but those words need to be there. Maybe
//    one character is wrong, in which case you have to figure that out."
//
// ⚠ THE OLD RULE WAS "2 SHARED WORDS ANYWHERE" ($need = min(2, count($toks))). "Adriano
//   Celentano" is two words, so every Celentano song matched every other Celentano song
//   and he was shown a warning naming three unrelated songs. Do not go back to token
//   overlap, and do not let the ARTIST alone satisfy the match — that was his correction.
//
// ⚠ THIS LOGIC EXISTS TWICE. dup_is_same_song() in scripts/karaoke_watch.py is the same
//   rule for casAI. Change one and change the other, or casAI and the Macs will disagree
//   about what a duplicate is.
//
// Measured on his real library (1,975 songs) before it shipped:
//   recall   74 of 74 known duplicate pairs (the ones removed 19 Sep) still recognised
//   noise    64 warnings -> 26 across his own 30 real searches, every one the right song
//   false +  36 of 49,191 same-artist/different-title pairs, and every one inspected by
//            hand was genuinely the same song ("Baila Morena"/"Balla Morena",
//            "Take Me Home, Country Road"/"Roads")

// Bump this whenever kar_dup_same() changes: every machine then recomputes its stored
// Duplicate column on its next page load, including the ones nobody can log into.
const KAR_DUP_RULE = '2026-09-20';
const KAR_TYPE_MARK = '/\((?:karaoke|lyrics?|original|testo|live|acoustic|instrumental|audio|video)\b/i';
const KAR_NOISE_WORDS = ['karaoke','lyrics','lyric','video','official','testo','testi','audio',
    'cover','version','versione','instrumental','strumentale','base','musicale','feat','featuring',
    'live','remastered','hd','hq','4k','full','con','subtitles','sottotitoli','music','song',
    'canzone','letra','letras','mp3','the','academy','karafun','zoom','sing','backing','track',
    'italia','italiano',
    // Language markers — the library writes them as (English)/(Spanish) and sometimes as
    // "- Spanish", so they must never decide whether two names are the same song.
    'english','spanish','french','francese','inglese','spagnolo','napoletano','siciliano'];

/** Lowercase and strip accents, so "perché" and "perche" are the same word. */
function kar_dup_fold(string $s): string {
    // GENERATED from Python's own unicodedata NFD-strip, so this PHP fold and the
    // Python one in scripts/karaoke_watch.py can never disagree. iconv('ASCII//TRANSLIT')
    // was tried first and is NOT portable - on this Mac it turned "perché" into
    // something Python did not, and three real duplicate pairs stopped matching.
    static $from = ['À','Á','Â','Ã','Ä','Å','Ç','È','É','Ê','Ë','Ì','Í','Î','Ï','Ñ','Ò','Ó','Ô','Õ','Ö','Ù','Ú','Û','Ü','Ý','à','á','â','ã','ä','å','ç','è','é','ê','ë','ì','í','î','ï','ñ','ò','ó','ô','õ','ö','ù','ú','û','ü','ý','ÿ','Ā','ā','Ă','ă','Ą','ą','Ć','ć','Ĉ','ĉ','Ċ','ċ','Č','č','Ď','ď','Ē','ē','Ĕ','ĕ','Ė','ė','Ę','ę','Ě','ě','Ĝ','ĝ','Ğ','ğ','Ġ','ġ','Ģ','ģ','Ĥ','ĥ','Ĩ','ĩ','Ī','ī','Ĭ','ĭ','Į','į','İ','Ĵ','ĵ','Ķ','ķ','Ĺ','ĺ','Ļ','ļ','Ľ','ľ','Ń','ń','Ņ','ņ','Ň','ň','Ō','ō','Ŏ','ŏ','Ő','ő','Ŕ','ŕ','Ŗ','ŗ','Ř','ř','Ś','ś','Ŝ','ŝ','Ş','ş','Š','š','Ţ','ţ','Ť','ť','Ũ','ũ','Ū','ū','Ŭ','ŭ','Ů','ů','Ű','ű','Ų','ų','Ŵ','ŵ','Ŷ','ŷ','Ÿ','Ź','ź','Ż','ż','Ž','ž','Ḁ','ḁ','Ḃ','ḃ','Ḅ','ḅ','Ḇ','ḇ','Ḉ','ḉ','Ḋ','ḋ','Ḍ','ḍ','Ḏ','ḏ','Ḑ','ḑ','Ḓ','ḓ','Ḕ','ḕ','Ḗ','ḗ','Ḙ','ḙ','Ḛ','ḛ','Ḝ','ḝ','Ḟ','ḟ','Ḡ','ḡ','Ḣ','ḣ','Ḥ','ḥ','Ḧ','ḧ','Ḩ','ḩ','Ḫ','ḫ','Ḭ','ḭ','Ḯ','ḯ','Ḱ','ḱ','Ḳ','ḳ','Ḵ','ḵ','Ḷ','ḷ','Ḹ','ḹ','Ḻ','ḻ','Ḽ','ḽ','Ḿ','ḿ','Ṁ','ṁ','Ṃ','ṃ','Ṅ','ṅ','Ṇ','ṇ','Ṉ','ṉ','Ṋ','ṋ','Ṍ','ṍ','Ṏ','ṏ','Ṑ','ṑ','Ṓ','ṓ','Ṕ','ṕ','Ṗ','ṗ','Ṙ','ṙ','Ṛ','ṛ','Ṝ','ṝ','Ṟ','ṟ','Ṡ','ṡ','Ṣ','ṣ','Ṥ','ṥ','Ṧ','ṧ','Ṩ','ṩ','Ṫ','ṫ','Ṭ','ṭ','Ṯ','ṯ','Ṱ','ṱ','Ṳ','ṳ','Ṵ','ṵ','Ṷ','ṷ','Ṹ','ṹ','Ṻ','ṻ','Ṽ','ṽ','Ṿ','ṿ','Ẁ','ẁ','Ẃ','ẃ','Ẅ','ẅ','Ẇ','ẇ','Ẉ','ẉ','Ẋ','ẋ','Ẍ','ẍ','Ẏ','ẏ','Ẑ','ẑ','Ẓ','ẓ','Ẕ','ẕ','ẖ','ẗ','ẘ','ẙ','Ạ','ạ','Ả','ả','Ấ','ấ','Ầ','ầ','Ẩ','ẩ','Ẫ','ẫ','Ậ','ậ','Ắ','ắ','Ằ','ằ','Ẳ','ẳ','Ẵ','ẵ','Ặ','ặ','Ẹ','ẹ','Ẻ','ẻ','Ẽ','ẽ','Ế','ế','Ề','ề','Ể','ể','Ễ','ễ','Ệ','ệ','Ỉ','ỉ','Ị','ị','Ọ','ọ','Ỏ','ỏ','Ố','ố','Ồ','ồ','Ổ','ổ','Ỗ','ỗ','Ộ','ộ','Ớ','ớ','Ờ','ờ','Ở','ở','Ỡ','ỡ','Ợ','ợ','Ụ','ụ','Ủ','ủ','Ứ','ứ','Ừ','ừ','Ử','ử','Ữ','ữ','Ự','ự','Ỳ','ỳ','Ỵ','ỵ','Ỷ','ỷ','Ỹ','ỹ'];
    static $to   = ['A','A','A','A','A','A','C','E','E','E','E','I','I','I','I','N','O','O','O','O','O','U','U','U','U','Y','a','a','a','a','a','a','c','e','e','e','e','i','i','i','i','n','o','o','o','o','o','u','u','u','u','y','y','A','a','A','a','A','a','C','c','C','c','C','c','C','c','D','d','E','e','E','e','E','e','E','e','E','e','G','g','G','g','G','g','G','g','H','h','I','i','I','i','I','i','I','i','I','J','j','K','k','L','l','L','l','L','l','N','n','N','n','N','n','O','o','O','o','O','o','R','r','R','r','R','r','S','s','S','s','S','s','S','s','T','t','T','t','U','u','U','u','U','u','U','u','U','u','U','u','W','w','Y','y','Y','Z','z','Z','z','Z','z','A','a','B','b','B','b','B','b','C','c','D','d','D','d','D','d','D','d','D','d','E','e','E','e','E','e','E','e','E','e','F','f','G','g','H','h','H','h','H','h','H','h','H','h','I','i','I','i','K','k','K','k','K','k','L','l','L','l','L','l','L','l','M','m','M','m','M','m','N','n','N','n','N','n','N','n','O','o','O','o','O','o','O','o','P','p','P','p','R','r','R','r','R','r','R','r','S','s','S','s','S','s','S','s','S','s','T','t','T','t','T','t','T','t','U','u','U','u','U','u','U','u','U','u','V','v','V','v','W','w','W','w','W','w','W','w','W','w','X','x','X','x','Y','y','Z','z','Z','z','Z','z','h','t','w','y','A','a','A','a','A','a','A','a','A','a','A','a','A','a','A','a','A','a','A','a','A','a','A','a','E','e','E','e','E','e','E','e','E','e','E','e','E','e','E','e','I','i','I','i','O','o','O','o','O','o','O','o','O','o','O','o','O','o','O','o','O','o','O','o','O','o','O','o','U','u','U','u','U','u','U','u','U','u','U','u','U','u','Y','y','Y','y','Y','y','Y','y'];
    return mb_strtolower(str_replace($from, $to, $s));
}

/**
 * What identifies the song, with the decoration stripped: the library's own trailing
 * metadata (type, singer codes, Best marker, pitch) and YouTube's.
 */
function kar_dup_core(string $name, bool $isFile = false): string {
    $s = $name;
    if ($isFile) $s = preg_replace('/\.[A-Za-z0-9]{2,4}$/', '', $s);
    $s = str_replace(["\u{ff5c}", "\u{2044}", "\u{29f8}"], ['|', '/', '/'], $s);
    $s = explode('|', $s)[0];
    if (preg_match(KAR_TYPE_MARK, $s, $m, PREG_OFFSET_CAPTURE)) {
        $s = substr($s, 0, $m[0][1]);   // the convention: everything after (Type) is singers
    }
    // KEEP what is inside the brackets and strip only the brackets themselves. Deleting the
    // groups wholesale removed REAL title words and cost three known duplicates in testing:
    // "I (Who Have Nothing)", "Alone Again (Naturally)", "Historia De Un Amor (Spanish)".
    // Genuine metadata sits AFTER the (Type) marker and is already gone.
    $s = preg_replace('/[()\[\]]/', ' ', $s);
    return trim($s, " -\t");
}

/**
 * [artist, title] from a LIBRARY filename, whose convention is "Artist - Title".
 * ONLY the filename is ever split — a YouTube title is far too messy to parse and is
 * only ever searched ("Mina   Amor mio karaoke", "One of Us - ABBA | KaraFun").
 */
function kar_dup_split(string $cored): array {
    $p = preg_split('/\s+-\s+/', $cored, 2);
    return count($p) === 2 ? [$p[0], $p[1]] : ['', $cored];
}

function kar_dup_toks(string $s, bool $dropNoise = true): array {
    preg_match_all('/[a-z0-9]+/', kar_dup_fold($s), $m);
    $ws = $dropNoise ? array_values(array_diff($m[0] ?? [], KAR_NOISE_WORDS)) : ($m[0] ?? []);
    // a trailing "2" is a disambiguator, not part of the name
    while (count($ws) > 1 && preg_match('/^\d{1,2}$/', (string)end($ws))) array_pop($ws);
    return array_values($ws);
}

function kar_dup_lev(string $a, string $b, int $cap): int {
    $la = strlen($a); $lb = strlen($b);
    if (abs($la - $lb) > $cap) return $cap + 1;
    $prev = range(0, $lb);
    for ($i = 1; $i <= $la; $i++) {
        $cur = [$i];
        for ($j = 1; $j <= $lb; $j++) {
            $cur[$j] = min($prev[$j] + 1, $cur[$j-1] + 1,
                           $prev[$j-1] + ($a[$i-1] !== $b[$j-1] ? 1 : 0));
        }
        if (min($cur) > $cap) return $cap + 1;
        $prev = $cur;
    }
    return $prev[$lb];
}

/**
 * His "maybe one character is wrong". Short words must be exact: one edit turns "se"
 * into "si" and "mio" into "mia", which are different words, not typos.
 */
function kar_dup_near(string $a, string $b): bool {
    if ($a === $b) return true;
    $n = min(strlen($a), strlen($b));
    if ($n >= 7) return kar_dup_lev($a, $b, 2) <= 2;
    if ($n >= 4) return kar_dup_lev($a, $b, 1) <= 1;
    return false;
}

function kar_dup_has(string $w, array $pool): bool {
    foreach ($pool as $x) if (kar_dup_near($w, $x)) return true;
    return false;
}

/** True only when the SINGER matches AND the SONG TITLE matches. */
function kar_dup_same(string $incoming, string $filename): bool {
    $inc = kar_dup_toks(kar_dup_core($incoming));
    if (!$inc) return false;
    [$artist, $title] = kar_dup_split(kar_dup_core($filename, true));
    $ta = kar_dup_toks($artist);
    $tt = kar_dup_toks($title);
    // ⚠ A TITLE CAN BE MADE ENTIRELY OF NOISE WORDS. "L'Italiano" is Toto Cutugno's most
    // famous song and it reduced to the single letter "l", so it could never match - he had
    // it twice and the chart check called it missing (found 2026-09-20). When stripping
    // leaves nothing usable, compare the raw words instead - on BOTH sides, or they cannot
    // meet.
    if (!$tt || (count($tt) === 1 && strlen($tt[0]) < 4)) {
        $tt  = kar_dup_toks($title, false);
        $inc = kar_dup_toks(kar_dup_core($incoming), false);
    }
    if (!$tt) return false;
    // THE TITLE — every word of it has to be there, in any order, one typo forgiven.
    foreach ($tt as $w) if (!kar_dup_has($w, $inc)) return false;
    // A one-word title is only ever accepted on an EXACT match: a single fuzzy hit on a
    // short word is how "Soli" would come to mean "Sole", and one word is not a song.
    if (count($tt) === 1 && (strlen($tt[0]) < 4 || !in_array($tt[0], $inc, true))) return false;
    // THE SINGER — one word of the artist is enough (people write "Morandi", not "Gianni
    // Morandi"), but the artist can NEVER carry the match on its own: the title test above
    // has already had to pass, which is the whole point of his correction.
    if (!$ta) return true;
    foreach ($ta as $w) if (kar_dup_has($w, $inc)) return true;
    return false;
}

/**
 * Every library file that is the same song, closest name first.
 * $files lets a caller pass a catalog instead of reading the folder - casAI has the
 * catalog but no songs folder, and both editions share this function.
 * $skip excludes the file being examined, or every song would flag itself.
 */
function kar_dup_scan(string $title, int $limit, ?array $files = null, string $skip = ''): array {
    $want = count(kar_dup_toks(kar_dup_core($title)));
    $out = [];
    foreach ($files ?? kar_songs_fresh() as $f) {
        if ($skip !== '' && $f === $skip) continue;
        if (strcasecmp(pathinfo($f, PATHINFO_FILENAME), 'applause') === 0) continue;
        if (kar_dup_same($title, $f)) {
            $out[] = [abs(count(kar_dup_toks(kar_dup_core($f, true))) - $want), $f];
        }
    }
    usort($out, static fn($a, $b) => $a[0] <=> $b[0] ?: strcasecmp($a[1], $b[1]));
    return array_column(array_slice($out, 0, $limit), 1);
}

/** His step-0 rule: does the library already hold this song, BEFORE downloading? */
function kar_dedup_matches(string $title): array {
    return array_map(static fn($f) => pathinfo($f, PATHINFO_FILENAME), kar_dup_scan($title, 3));
}

// ---------------------------------------------------------------------------
// Guest access — the QR points at THIS Mac on the house Wi-Fi
// ---------------------------------------------------------------------------

/**
 * A guest's FIRST name, and nothing else — the singer list is a list of first names,
 * so a guest who types their full name still becomes just the first word and the
 * dropdown stays tidy. Everything unsafe for a filename is stripped on the way.
 */
function kar_first_name(string $raw): string {
    $n = trim(preg_replace('/\s+/u', ' ', $raw));
    $n = preg_replace('#[/\\:*?"<>|%]#u', '', $n);   // % too: it breaks a yt-dlp -o template
    $n = trim(explode(' ', $n)[0] ?? '');
    return mb_substr($n, 0, 20);
}

/**
 * Is somebody already singing under this name at this party? Only the live queue and the
 * last few hours of activity count — a name nobody is using is handed straight over, so
 * the real Mike is still Mike every time he walks in.
 */
function kar_name_busy(PDO $db, string $n): bool {
    if ($n === '') return false;
    // The 12-hour bound matters (2026-09-17): without it a queue nobody cleared after the last
    // party keeps every name in it "taken" for ever, and the next person to walk in under their
    // own name is quietly renamed. That is how Ella became Ella G six minutes into her own
    // evening - and, because scheduling fairness counts by name, how she started getting two
    // turns a round while everyone else got one.
    $st = $db->prepare("SELECT COUNT(*) FROM karaoke_sing_queue
                        WHERE LOWER(singer) = LOWER(?) AND status IN ('Waiting','Singing')
                          AND created_at > " . kar_ago(12, 'hours'));
    $st->execute([$n]);
    if ((int)$st->fetchColumn() > 0) return true;
    $st = $db->prepare("SELECT COUNT(*) FROM karaoke_downloads
                        WHERE LOWER(requested_by) = LOWER(?)
                          AND requested_at > " . kar_ago(6, 'hours'));
    $st->execute([$n]);
    return (int)$st->fetchColumn() > 0;
}

/**
 * Two people called Mike at one party must not share a Best list. Rather than number them
 * — "Mike 2" means nothing to anyone and leaves the host renaming it later — the page asks
 * the second one for the first letter of their surname and they become Mike G
 * (the owner, 2026-09-08). A number is only ever a last resort, if even that collides.
 */
function kar_claim_name(PDO $db, string $first, string $initial = ''): string {
    $first = kar_first_name($first);
    if ($first === '') return '';
    $initial = strtoupper(preg_replace('/[^A-Za-z]/', '', $initial));
    if ($initial !== '') {
        $withInitial = $first . ' ' . mb_substr($initial, 0, 1);
        if (!kar_name_busy($db, $withInitial)) return $withInitial;
        $first = $withInitial;          // even that is taken — fall through to numbering
    } elseif (!kar_name_busy($db, $first)) {
        return $first;
    }
    for ($i = 2; $i <= 20; $i++) if (!kar_name_busy($db, $first . ' ' . $i)) return $first . ' ' . $i;
    return $first . ' ' . random_int(21, 99);
}

/**
 * Search YouTube with yt-dlp — no API key, no account, nothing to run out of mid-party.
 * "karaoke" is added to the words the guest typed, because someone asking for "Volare"
 * at a party wants the backing track, not Modugno singing it (the owner, 2026-09-08).
 * --flat-playlist keeps it to one request instead of one per result, which is the
 * difference between three seconds and twenty.
 */
/**
 * The same duplicate test as kar_dedup_matches, but returning the real file so the guest
 * page can offer "sing ours" — a request has to name a file the catalogue will accept,
 * and the display name has the extension stripped off.
 */
function kar_have_matches(string $title): array {
    return array_map(
        static fn($f) => ['file' => $f, 'label' => pathinfo($f, PATHINFO_FILENAME)],
        kar_dup_scan($title, 2));
}

/**
 * The three words that mean "somebody can sing along to this" — the owner's own list
 * (2026-09-14), measured against his real downloads before it was built: 14 of 14 genuine
 * searches carry one of them. Two details earn their keep:
 *   "lyric" is a PREFIX — ABBA's is "Official Lyric Video", singular, and an exact match
 *                         on "lyrics" would have thrown it away.
 *   "testo" is Italian  — half the library says Testo where YouTube's English results say
 *                         Lyrics; an English-only list would quietly discard exactly the
 *                         results he downloads most. The \b keeps "contesto" out.
 *   deliberately NOT "instrumental" / "base" / "backing track" — an instrumental has the
 *                         lead vocal stripped but NO words on screen, so it is singable only
 *                         if you already know the song by heart. Two of his 2,057 files say
 *                         instrumental and both are dance music. the owner, 2026-09-14: "we
 *                         don't need an instrumental. If anyone wants, he can download it as
 *                         a non-karaoke and non-lyrics" — i.e. by unticking the box. Do not
 *                         add it back without asking him.
 * The u modifier is not optional: without it the pattern is read as bytes and a title with
 * an accent can silently fail to match (the em-dash lesson, 2026-08-21).
 */
function kar_looks_karaoke(string $title, string $chan = ''): bool {
    // Title OR channel: "Karaoke Academy Italia" is a channel, and some channels never put
    // the word in the title at all.
    return (bool)preg_match('/karaoke|\blyric|\btesto/ui', $title . ' ' . $chan);
}

function kar_yt_search(string $query, int $limit = 30, bool $wantKaraoke = true): array {
    $q = trim(preg_replace('/\s+/u', ' ', $query));
    if ($q === '') return [];
    $limit = max(1, min(40, $limit));   // over-fetch: ask for 30, the page shows the dozen
                                        // that pass the filter. One request either way.
    $cfg = kar_cfg();
    $cookies = !empty($cfg['browser_cookies'])
        ? ' --cookies-from-browser ' . escapeshellarg((string)$cfg['browser_cookies']) : '';
    $cmd = escapeshellarg(kar_tool('yt-dlp'))
         . ' --no-warnings --flat-playlist --skip-download'
         . ' --print ' . escapeshellarg("%(id)s\t%(title)s\t%(duration)s\t%(channel)s")
         . $cookies . ' ' . escapeshellarg('ytsearch' . $limit . ':' . $q
             // The appended word is the bigger lever of the two: the filter only hides
             // what came back, this decides what YouTube is asked for at all. Unticking
             // the box has to turn BOTH off or the choice is cosmetic (the owner, 2026-09-14).
             . ($wantKaraoke ? ' karaoke' : '')) . ' 2>/dev/null';
    $out = (string)@shell_exec($cmd);
    $rows = [];
    foreach (explode("\n", $out) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $p = explode("\t", $line);
        if (count($p) < 2 || $p[0] === '' || $p[0] === 'NA') continue;
        $secs = (isset($p[2]) && is_numeric($p[2])) ? (int)$p[2] : 0;
        $chan = $p[3] ?? '';
        $rows[] = [
            'id'    => $p[0],
            'url'   => 'https://www.youtube.com/watch?v=' . $p[0],
            'title' => $p[1],
            'secs'  => $secs,
            'len'   => $secs > 0 ? sprintf('%d:%02d', intdiv($secs, 60), $secs % 60) : '',
            'chan'  => $chan,
            // built from the id rather than asked for: always present, always valid
            'thumb' => 'https://i.ytimg.com/vi/' . $p[0] . '/mqdefault.jpg',
            // Full filenames, not display names: the page offers "sing ours", and that
            // request has to name a real file the catalogue will accept.
            'have'  => kar_have_matches($p[1]),
            // The filter INFORMS, it never decides alone: every row is kept and tagged, so
            // the page can say how many it is holding back and reveal them on a click.
            'ok'    => kar_looks_karaoke($p[1], $chan),
        ];
    }
    return $rows;
}

/**
 * The guest QR, on top of the lyrics screen.
 *
 * The panel holding the QR is exclusive — opening it closes whatever else was open — so the
 * code was only ever on screen when the host was NOT running the party (the owner, 2026-09-14).
 * This parks it in the corner of the lyrics screen instead, where the whole room is already
 * looking, in its own borderless window.
 *
 * Deliberately NOT painted into the video: that would mean changing how the player builds the
 * picture, and the player is the one thing that must not fail mid-party. A separate window can
 * be closed on its own and the music carries on. It also has no IPC socket, so ⏹ Stop and the
 * mutual silencing — which only ever quit mpv through that socket — cannot take it down.
 *
 * --ontop alone is NOT enough: a window in true fullscreen gets its own macOS Space and an
 * ordinary floating window from another app vanishes behind it. --ontop-level=system raises it
 * above the menu-bar level (measured on macOS 26: layer 3 -> layer 26) and --on-all-workspaces
 * puts it on every Space.
 */
const KAR_QR_PNG = '/tmp/casai-guest-qr.png';
const KAR_QR_PID = '/tmp/casai-guest-qr.pid';

function kar_qr_hide(): void {
    $pid = (int)@file_get_contents(KAR_QR_PID);
    if ($pid > 1) @exec('kill ' . $pid . ' 2>/dev/null');
    @unlink(KAR_QR_PID);
}

function kar_qr_show(string $dataUrl): string {
    // The picture is drawn by the browser and sent here: this Mac has no QR library, and
    // adding one would be a dependency on every family machine.
    if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
        throw new Exception('that is not a picture of a code');
    }
    $bytes = base64_decode($m[1], true);
    if ($bytes === false || strlen($bytes) < 64) throw new Exception('the picture did not decode');
    file_put_contents(KAR_QR_PNG, $bytes);

    // Size and place it against the lyrics window when one has been remembered, so the code
    // lands ON that screen and is big enough to scan from across a room.
    $pad = 24; $side = 240; $x = 60; $y = 60;
    $d = kar_cfg()['lyrics_window'] ?? null;
    if (is_array($d) && isset($d['x'], $d['y'], $d['w'], $d['h'], $d['scale'], $d['menubar'])) {
        $sc = (float)$d['scale'] ?: 1.0;
        $side = (int)max(200, min(460, round((float)$d['h'] * $sc * 0.22)));
        $x = (int)max(0, round(((float)$d['x'] + (float)$d['w']) * $sc) - $side - $pad);
        $y = (int)max(0, round(((float)$d['y'] - (float)$d['menubar']) * $sc) + $pad);
    }

    kar_qr_hide();
    $args = [kar_tool('mpv'), '--ontop', '--ontop-level=system', '--on-all-workspaces',
             '--no-border', '--no-osc', '--no-input-default-bindings',
             '--image-display-duration=inf', '--loop', '--really-quiet',
             '--geometry=' . $side . 'x' . $side . '+' . $x . '+' . $y, KAR_QR_PNG];
    $out = [];
    @exec(implode(' ', array_map('escapeshellarg', $args)) . ' >/dev/null 2>&1 & echo $!', $out);
    if (!empty($out[0])) file_put_contents(KAR_QR_PID, trim($out[0]));
    return $side . 'x' . $side . '+' . $x . '+' . $y;
}

function kar_lan_ip(): string {
    $out = [];
    // The interface actually carrying traffic, asked of the routing table rather
    // than guessed from a list of candidates.
    $iface = trim((string)@shell_exec("route -n get default 2>/dev/null | awk '/interface:/{print $2}'"));
    if ($iface !== '') {
        $ip = trim((string)@shell_exec('ipconfig getifaddr ' . escapeshellarg($iface) . ' 2>/dev/null'));
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    foreach (['en0', 'en1'] as $i) {
        $ip = trim((string)@shell_exec("ipconfig getifaddr $i 2>/dev/null"));
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';
}

/** One-row settings, the standalone twin of casAI's karaoke_settings upsert. */
function kar_get_setting(string $k): string {
    return (string)kar_db()->query("SELECT v FROM karaoke_settings WHERE k=" . kar_db()->quote($k))->fetchColumn();
}
function kar_set_setting(string $k, string $v): void {
    kar_db()->prepare("INSERT INTO karaoke_settings (k,v) VALUES (?,?)
                       ON CONFLICT(k) DO UPDATE SET v=excluded.v")->execute([$k, $v]);
}

function kar_guest_url(string $token): string {
    $c = kar_cfg();
    // The port the page is REALLY being served on wins over the one written in the
    // settings. They are normally the same; when they are not, the settings file is the
    // one that is wrong, and believing it would print a QR code that answers nowhere.
    $port = (string)($_SERVER['SERVER_PORT'] ?? '') ?: (string)($c['port'] ?? '8899');
    $host = !empty($c['lan_host']) ? $c['lan_host'] : kar_lan_ip();
    return 'http://' . $host . ':' . $port . '/karaoke_guest.php?t=' . $token;
}

function kar_guest_token(bool $rotate = false): string {
    $db = kar_db();
    $tok = (string)$db->query("SELECT v FROM karaoke_settings WHERE k='guest_token'")->fetchColumn();
    if ($rotate || $tok === '') {
        $tok = bin2hex(random_bytes(8));
        $db->prepare("INSERT INTO karaoke_settings (k,v) VALUES ('guest_token',?)
                      ON CONFLICT(k) DO UPDATE SET v=excluded.v")->execute([$tok]);
    }
    return $tok;
}

/** Which version of the program this Mac is running, if it was installed from the
 *  internet. A copy put here by hand has no VERSION file, and says so. */
function kar_installed_version(): string {
    $m = kar_marker_path();
    $f = ($m ? dirname($m) : __DIR__) . '/VERSION';
    $v = is_file($f) ? trim((string)file_get_contents($f)) : '';
    return $v !== '' ? $v : 'installed by hand — no version recorded';
}

// ---------------------------------------------------------------------------
// LIST AND KEY SHARING BETWEEN MACHINES — OFF BY DESIGN. DO NOT REBUILD IT.
// ---------------------------------------------------------------------------
//
// the owner's rule, 2026-09-20, in his own words:
//
//     "Every computer can have their own singers and their own songs for each singer.
//      The only thing that we have to sync is the master database... A key changed in
//      Cantoria becomes the official key for that song IN THAT COMPUTER, and if anyone
//      wants to nullify it to sing it at 0, he can click on the little icon."
//
// So the ONLY thing shared is the song library — one Google Drive folder that his three
// Macs point at. That needs no code at all. Singer lists and song keys belong to the
// machine they were set on and never travel.
//
// ⚠ WHY THIS MUST NOT COME BACK. kar_sync() used to merge Best lists and pitch overrides
// through a shared folder: newest wins, with removal tombstones so a delete beat an older
// add. On 2026-09-19 the laptop's file carried 439 removal notes left by the rename and
// de-duplication work. Measured against the Kitchen Mac's own file, a single successful sync
// would have deleted 439 of its 461 list entries. It survived only because the sync
// happened to be broken at the time. That is the whole argument, and it is not theoretical.
//
// A backup is a different thing and is fine: written out, never read back, restored only
// by a person. See ~/casai_backups/cantoria_lists_2026-09-20/ and the copy in Drive under
// "@ Cantoria". Each machine is now the only live copy of its own lists, so take one
// whenever the lists are touched.
//
// The functions below are kept so every caller keeps working, and inert so none of them
// can do anything. Do not "fix" the early return.

function kar_sync_dir(): ?string {
    // ⚠ 2026-09-19: this used to trust config['sync_folder'] blindly and @mkdir it. On the Kitchen Mac
    // that path belonged to ANOTHER Mac (/Users/<other>/My Drive/...), so is_dir() was false, the
    // mkdir could not create a folder under someone else's home, and sync was silently OFF from
    // 13 Sep — which is why 439 of its Best entries still named files the 18 Sep rename had
    // renamed away. The shared folder is always a SIBLING of the songs folder, so derive it the
    // way kar_sibling_dir() already derives the others, and never create a foreign absolute path.
    $c = kar_cfg();
    $cands = [];
    if (!empty($c['sync_folder'])) {
        $cfgd = rtrim((string)$c['sync_folder'], '/');
        $cands[] = $cfgd;                                              // as configured
        $cands[] = dirname(kar_songs_dir()) . '/' . basename($cfgd);   // same name, THIS Mac's Drive
    }
    // ⚠ 2026-09-20: this fallback used to be added UNCONDITIONALLY — a regression I introduced
    // on 2026-09-19 which broke the documented off switch, so removing "sync_folder" from a
    // config no longer turned sharing off. It is now only reached when sync_folder is set.
    if (!empty($c['sync_folder'])) $cands[] = dirname(kar_songs_dir()) . '/@ Karaoke Sync';
    if (!$cands) return null;                      // not configured -> sharing is OFF
    foreach ($cands as $d) { if ($d !== '' && is_dir($d)) return $d; }
    $d = end($cands);                 // create only the sibling beside the songs, never someone else's path
    @mkdir($d, 0755, true);
    return is_dir($d) ? $d : null;
}

/** This Mac's name, used only to name its own file in the shared folder. */
function kar_machine(): string {
    $c = kar_cfg();
    $n = (string)($c['machine'] ?? '');
    if ($n === '') $n = trim((string)@shell_exec('scutil --get ComputerName 2>/dev/null'));
    if ($n === '') $n = (string)gethostname();
    $n = preg_replace('/[^A-Za-z0-9 _-]/', '', $n);
    return trim($n) !== '' ? trim($n) : 'this-Mac';
}

/** Everything this Mac currently knows, plus what it has deliberately removed. */
function kar_sync_snapshot(PDO $db): array {
    $best = [];
    foreach ($db->query("SELECT person, filename, COALESCE(created_at, datetime('now','localtime')) at FROM karaoke_best") as $r) {
        $best[] = [$r['person'], $r['filename'], $r['at']];
    }
    $pitch = [];
    foreach ($db->query("SELECT filename, pitch, COALESCE(updated_at, datetime('now','localtime')) at FROM karaoke_pitches") as $r) {
        $pitch[] = [$r['filename'], (int)$r['pitch'], $r['at']];
    }
    $gone = [];
    foreach ($db->query("SELECT kind, k1, k2, at FROM karaoke_removals") as $r) {
        $gone[] = [$r['kind'], $r['k1'], $r['k2'], $r['at']];
    }
    return ['machine' => kar_machine(), 'written_at' => date('Y-m-d H:i:s'),
            'best' => $best, 'pitches' => $pitch, 'removals' => $gone];
}

function kar_sync_record_removal(PDO $db, string $kind, string $k1, string $k2 = ''): void {
    try {
        $db->prepare("INSERT INTO karaoke_removals (kind,k1,k2,at) VALUES (?,?,?,datetime('now','localtime'))
                      ON CONFLICT(kind,k1,k2) DO UPDATE SET at=excluded.at")->execute([$kind, $k1, $k2]);
    } catch (Throwable $e) { /* a failed note must never block the click */ }
}

/**
 * INERT SINCE 2026-09-20. Lists and keys never leave the machine they were set on —
 * see the rule at the top of this section. The old merging implementation is in git
 * history and in karaoke_backend.php.bak-2026-09-20-syncoff if it is ever needed as a
 * reference; it must not be called.
 *
 * Kept as a function, rather than deleted, because karaoke_api.php calls it from nine
 * places and a missing function would be a fatal error on a machine that updates the
 * backend without the API. Returning false is exactly what every caller already expects
 * when sharing is off.
 */
function kar_sync(PDO $db, bool $force = false): bool {
    return false;
}

// ---------------------------------------------------------------------------
// Shared read helpers — the page uses these in BOTH modes
// ---------------------------------------------------------------------------

/** Best lists keyed by person, alphabetical, starting from whatever this install seeds. */
/** Who is in the singer dropdown before anybody has starred a song.
 *  This is DATA, not code — the same reason the Mac list became a table. A household's
 *  names have no business being written into a program other households run; a fresh
 *  install starts with nobody and fills up as people are added.
 *    server    — the karaoke_settings row 'default_singers' (comma separated)
 *    local     — the "singers" key in karaoke_standalone.json                        */
function kar_best_seed(?PDO $db = null): array {
    if (kar_is_local()) {
        $c = kar_cfg();
        return (!empty($c['singers']) && is_array($c['singers'])) ? array_values($c['singers']) : [];
    }
    if (!$db) return [];
    try {
        $v = (string)$db->query("SELECT v FROM karaoke_settings WHERE k='default_singers'")->fetchColumn();
    } catch (Throwable $e) { return []; }
    return $v === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $v)), 'strlen'));
}

function kar_best_lists(PDO $db): array {
    $out = [];
    foreach (kar_best_seed($db) as $person) $out[(string)$person] = [];
    try {
        foreach ($db->query("SELECT person, filename FROM karaoke_best ORDER BY person, filename") as $r) {
            $out[$r['person']][] = $r['filename'];
        }
    } catch (Throwable $e) { /* table missing → empty lists, page still works */ }
    uksort($out, 'strcasecmp');
    return $out;
}

/** Whose Best list the page opens on. The seeded name if it is there (so casAI still
 *  opens on Claude, not on whoever happens to sort first), otherwise the first real
 *  person, otherwise nobody — which a brand-new Mac legitimately is. */
function kar_best_default(array $lists, ?PDO $db = null): string {
    foreach (kar_best_seed($db) as $person) {
        if (isset($lists[(string)$person])) return (string)$person;
    }
    return $lists ? (string)array_key_first($lists) : '';
}

function kar_pitch_map(PDO $db): array {
    $out = [];
    try {
        foreach ($db->query("SELECT filename, pitch FROM karaoke_pitches") as $r) {
            $out[$r['filename']] = (int)$r['pitch'];
        }
    } catch (Throwable $e) { $out = []; }
    return $out;
}

/**
 * 🆕 New: downloads of the last 30 days, newest first, intersected with the live
 * catalog so a renamed-out or removed file never shows as a ghost.
 *
 * THE DUPLICATE COLUMN HEALS ITSELF (the owner, 2026-09-20).  dup_note is written once at
 * download time, which is right — the finding has to survive until he sits down to review
 * days later.  But when the RULE changes, every stored note is suddenly wrong, and on
 * 2026-09-20 they were: "Un albero di trenta piani" was flagged against "Per averti",
 * "Torna A Surriento" against Richard Marx.  They also named files from before the 18 Sep
 * library rename, so they were stale twice over.
 * So each row records WHICH rule wrote it.  A row written under an older rule is
 * recomputed here, once, and stored — which also reaches the Macs that nobody can log
 * into.  Bump KAR_DUP_RULE whenever kar_dup_same() changes and every machine repairs
 * itself on its next page load.
 * It compares the FILE against the rest of the catalog, not the old YouTube title: by now
 * the song has his own name, and the question he is actually asking at review time is
 * "do I already have this one under a different name?"
 */
function kar_new_downloads(PDO $db, array $catalog): array {
    $new = []; $dup = [];
    $cut = kar_ago(30, 'days');
    $set = array_flip($catalog);
    $redo = [];
    try {
        // reviewed_at IS NULL: 🆕 New is a review BENCH, not an archive. Once he has done
        // the due diligence on a song it leaves the bench on his word rather than waiting
        // out the 30 days (the owner, 2026-09-20: "rather than waiting a month for the songs
        // to stay there when I already did the due diligence"). The song itself is never
        // touched - it stays in the song database exactly as it was.
        $q = $db->query("SELECT filename, dup_note, dup_rule FROM karaoke_downloads
                         WHERE status='Done' AND filename IS NOT NULL AND done_at > $cut
                           AND reviewed_at IS NULL
                         ORDER BY done_at DESC");
        foreach ($q as $r) {
            if (isset($set[$r['filename']]) && !in_array($r['filename'], $new, true)) {
                $new[] = $r['filename'];
                if (($r['dup_rule'] ?? '') !== KAR_DUP_RULE) {
                    $redo[] = $r['filename'];
                } elseif (!empty($r['dup_note'])) {
                    $dup[$r['filename']] = $r['dup_note'];
                }
            }
        }
    } catch (Throwable $e) { return [[], []]; }

    // Repair at most a handful per load, so a long backlog can never stall the page —
    // whatever is left heals on the next one.
    foreach (array_slice($redo, 0, 12) as $f) {
        try {
            $hits = kar_dup_scan($f, 3, $catalog, $f);
            $note = $hits ? implode(' · ', array_map(
                static fn($h) => pathinfo($h, PATHINFO_FILENAME), $hits)) : null;
            if ($note !== null) $note = mb_substr($note, 0, 390);
            $st = $db->prepare("UPDATE karaoke_downloads SET dup_note = ?, dup_rule = ?
                                WHERE filename = ?");
            $st->execute([$note, KAR_DUP_RULE, $f]);
            if ($note !== null) $dup[$f] = $note;
        } catch (Throwable $e) { /* a repair that fails just waits for the next load */ }
    }
    return [$new, $dup];
}
