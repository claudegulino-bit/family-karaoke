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
    $j = @json_decode((string)@file_get_contents('/var/www/getcasa.ai/karaoke_songs.json'), true);
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
        if ($crowd !== '') $args[] = '--loop-file=inf';   // the crowd keeps going
        else               $args[] = '--pause';           // held until the presentation is done
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
function kar_mc_build(string $singer, string $title, string $artist): string {
    $c      = kar_cfg();
    $lead   = (string)($c['announce_lead'] ?? 'And now');
    $verb   = (string)($c['announce_verb'] ?? 'will sing');
    $by     = (string)($c['announce_by']   ?? 'from');
    $pause  = (float)($c['announce_pause'] ?? 0.9);
    $voice  = kar_mc_voice();

    $tail = $title . ($artist !== '' ? ', ' . $by . ' ' . $artist : '');
    $a = kar_mc_clip($lead, $voice, 178);
    $b = kar_mc_clip($singer . ' ' . $verb, $voice, 178);
    $d = kar_mc_clip($tail, $voice, 115);
    if ($a === '' || $b === '' || $d === '') return '';

    $dir = kar_data_dir() . '/mc';
    $out = $dir . '/' . substr(sha1($voice . '|' . $lead . '|' . $singer . '|' . $verb . '|' . $tail . '|' . $pause), 0, 16) . '.wav';
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

const KAR_NOISE_WORDS = ['karaoke','lyrics','lyric','video','official','testo','audio','cover',
    'version','versione','instrumental','base','musicale','the','con','feat','featuring',
    'live','remastered','hd','4k','full'];

function kar_title_tokens(string $title): array {
    preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($title), $m);
    return array_values(array_diff($m[0] ?? [], KAR_NOISE_WORDS));
}

/** His step-0 rule: does the library already hold this song? Closest match first. */
function kar_dedup_matches(string $title): array {
    $toks = array_unique(kar_title_tokens($title));
    if (!$toks) return [];
    $need = min(2, count($toks));
    $scored = [];
    foreach (kar_songs_fresh() as $f) {
        // Whole-word comparison, never substrings — "gli" living inside "English"
        // produced false duplicate warnings on the very first live test.
        $score = count(array_intersect($toks, array_unique(kar_title_tokens($f))));
        if ($score >= $need) $scored[] = [$score, $f];
    }
    usort($scored, static fn($a, $b) => $b[0] <=> $a[0] ?: strcasecmp($a[1], $b[1]));
    return array_map(static fn($r) => pathinfo($r[1], PATHINFO_FILENAME), array_slice($scored, 0, 3));
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
    $st = $db->prepare("SELECT COUNT(*) FROM karaoke_sing_queue
                        WHERE LOWER(singer) = LOWER(?) AND status IN ('Waiting','Singing')");
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
    $toks = array_unique(kar_title_tokens($title));
    if (!$toks) return [];
    $need = min(2, count($toks));
    $scored = [];
    foreach (kar_songs_fresh() as $f) {
        $score = count(array_intersect($toks, array_unique(kar_title_tokens($f))));
        if ($score >= $need) $scored[] = [$score, $f];
    }
    usort($scored, static fn($a, $b) => $b[0] <=> $a[0] ?: strcasecmp($a[1], $b[1]));
    return array_map(
        static fn($r) => ['file' => $r[1], 'label' => pathinfo($r[1], PATHINFO_FILENAME)],
        array_slice($scored, 0, 2));
}

function kar_yt_search(string $query, int $limit = 6): array {
    $q = trim(preg_replace('/\s+/u', ' ', $query));
    if ($q === '') return [];
    $limit = max(1, min(10, $limit));
    $cfg = kar_cfg();
    $cookies = !empty($cfg['browser_cookies'])
        ? ' --cookies-from-browser ' . escapeshellarg((string)$cfg['browser_cookies']) : '';
    $cmd = escapeshellarg(kar_tool('yt-dlp'))
         . ' --no-warnings --flat-playlist --skip-download'
         . ' --print ' . escapeshellarg("%(id)s\t%(title)s\t%(duration)s\t%(channel)s")
         . $cookies . ' ' . escapeshellarg('ytsearch' . $limit . ':' . $q . ' karaoke') . ' 2>/dev/null';
    $out = (string)@shell_exec($cmd);
    $rows = [];
    foreach (explode("\n", $out) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $p = explode("\t", $line);
        if (count($p) < 2 || $p[0] === '' || $p[0] === 'NA') continue;
        $secs = (isset($p[2]) && is_numeric($p[2])) ? (int)$p[2] : 0;
        $rows[] = [
            'id'    => $p[0],
            'url'   => 'https://www.youtube.com/watch?v=' . $p[0],
            'title' => $p[1],
            'secs'  => $secs,
            'len'   => $secs > 0 ? sprintf('%d:%02d', intdiv($secs, 60), $secs % 60) : '',
            'chan'  => $p[3] ?? '',
            // built from the id rather than asked for: always present, always valid
            'thumb' => 'https://i.ytimg.com/vi/' . $p[0] . '/mqdefault.jpg',
            // Full filenames, not display names: the page offers "sing ours", and that
            // request has to name a real file the catalogue will accept.
            'have'  => kar_have_matches($p[1]),
        ];
    }
    return $rows;
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
// Keeping two Macs in step
// ---------------------------------------------------------------------------
//
// the owner sings in his office and with friends in the other room, and wants the same
// singers and the same starred songs in both places. The two Macs share exactly one
// thing — the Google Drive songs folder — so the lists travel the same way.
//
// ⚠ NOT by sharing the database file. SQLite in a syncing folder corrupts: Drive has no
// idea two programs are writing to it. Instead each Mac writes ONLY ITS OWN small file
// and reads everyone else's, so there is never a second writer to collide with.
//
// Merging is by (person, song), newest wins, and a removal beats an older add — which is
// why removals are recorded rather than just done.
//
// It is OFF unless "sync_folder" is set in the config. A brother's install shares nothing
// and must never start reaching into somebody else's folder.

function kar_sync_dir(): ?string {
    $c = kar_cfg();
    if (empty($c['sync_folder'])) return null;
    $d = rtrim((string)$c['sync_folder'], '/');
    if (!is_dir($d)) @mkdir($d, 0755, true);
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

/** Read every Mac's file, work out what the shared truth is, and make this Mac match. */
function kar_sync(PDO $db, bool $force = false): bool {
    $dir = kar_sync_dir();
    if (!$dir) return false;
    // Once a minute is plenty — Drive takes longer than that to carry a file across anyway.
    $stamp = kar_data_dir() . '/last_sync';
    if (!$force && is_file($stamp) && (time() - (int)@filemtime($stamp)) < 60) return false;
    @touch($stamp);

    $mine = kar_sync_snapshot($db);
    @file_put_contents($dir . '/' . kar_machine() . '.json',
        json_encode($mine, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $bestAt = []; $pitchAt = []; $goneAt = [];
    foreach (glob($dir . '/*.json') ?: [] as $f) {
        $j = json_decode((string)@file_get_contents($f), true);
        if (!is_array($j)) continue;                       // a half-written file is skipped, not fatal
        foreach ($j['removals'] ?? [] as [$kind, $k1, $k2, $at]) {
            $key = $kind . "\x00" . $k1 . "\x00" . $k2;
            if (!isset($goneAt[$key]) || $at > $goneAt[$key]) $goneAt[$key] = $at;
        }
        foreach ($j['best'] ?? [] as [$person, $file, $at]) {
            $key = $person . "\x00" . $file;
            if (!isset($bestAt[$key]) || $at > $bestAt[$key]) $bestAt[$key] = $at;
        }
        foreach ($j['pitches'] ?? [] as [$file, $p, $at]) {
            if (!isset($pitchAt[$file]) || $at > $pitchAt[$file][1]) $pitchAt[$file] = [(int)$p, $at];
        }
    }

    $db->beginTransaction();
    try {
        foreach ($bestAt as $key => $at) {
            [$person, $file] = explode("\x00", $key, 2);
            $removed = max($goneAt['best' . "\x00" . $person . "\x00" . $file] ?? '',
                           $goneAt['person' . "\x00" . $person . "\x00"] ?? '');
            if ($removed !== '' && $removed > $at) {
                $db->prepare('DELETE FROM karaoke_best WHERE person=? AND filename=?')->execute([$person, $file]);
            } else {
                $db->prepare('INSERT OR IGNORE INTO karaoke_best (person, filename, created_at) VALUES (?,?,?)')
                   ->execute([$person, $file, $at]);
            }
        }
        foreach ($pitchAt as $file => [$p, $at]) {
            $removed = $goneAt['pitch' . "\x00" . $file . "\x00"] ?? '';
            if ($removed !== '' && $removed > $at) {
                $db->prepare('DELETE FROM karaoke_pitches WHERE filename=?')->execute([$file]);
            } else {
                $db->prepare("INSERT INTO karaoke_pitches (filename, pitch, updated_at) VALUES (?,?,?)
                              ON CONFLICT(filename) DO UPDATE SET pitch=excluded.pitch, updated_at=excluded.updated_at
                              WHERE excluded.updated_at > karaoke_pitches.updated_at")->execute([$file, $p, $at]);
            }
        }
        // Everyone's removals become everyone's removals, or a third Mac would keep
        // handing a deleted song back.
        foreach ($goneAt as $key => $at) {
            [$kind, $k1, $k2] = array_pad(explode("\x00", $key, 3), 3, '');
            $db->prepare("INSERT INTO karaoke_removals (kind,k1,k2,at) VALUES (?,?,?,?)
                          ON CONFLICT(kind,k1,k2) DO UPDATE SET at=excluded.at WHERE excluded.at > karaoke_removals.at")
               ->execute([$kind, $k1, $k2, $at]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        kar_log('sync', 'merge failed: ' . $e->getMessage());
        return false;
    }
    // Write again, so this Mac's file now carries what everyone agreed.
    @file_put_contents($dir . '/' . kar_machine() . '.json',
        json_encode(kar_sync_snapshot($db), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return true;
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

/** 🆕 New: downloads of the last 30 days, newest first, intersected with the live
 *  catalog so a renamed-out or removed file never shows as a ghost. */
function kar_new_downloads(PDO $db, array $catalog): array {
    $new = []; $dup = [];
    $cut = kar_ago(30, 'days');
    $set = array_flip($catalog);
    try {
        $q = $db->query("SELECT filename, dup_note FROM karaoke_downloads
                         WHERE status='Done' AND filename IS NOT NULL AND done_at > $cut
                         ORDER BY done_at DESC");
        foreach ($q as $r) {
            if (isset($set[$r['filename']]) && !in_array($r['filename'], $new, true)) {
                $new[] = $r['filename'];
                if (!empty($r['dup_note'])) $dup[$r['filename']] = $r['dup_note'];
            }
        }
    } catch (Throwable $e) { $new = []; $dup = []; }
    return [$new, $dup];
}
