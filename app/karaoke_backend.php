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
    "CREATE TABLE IF NOT EXISTS karaoke_settings (k TEXT PRIMARY KEY, v TEXT NOT NULL)",
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
    return preg_match('/\(([+-]?\d{1,2})\)/', $name, $m) ? (int)$m[1] : null;
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
function kar_play(string $song, int $pitch): array {
    $path = kar_songs_dir() . '/' . $song;
    if (!is_file($path)) return [false, 'file not found in the songs folder'];
    $scale = round(2 ** ($pitch / 12.0), 6);
    if (kar_mpv_alive()) {
        kar_mpv_send(['loadfile', $path, 'replace']);
        // Speed persists across loads — every song starts at normal tempo.
        kar_mpv_send(['set_property', 'speed', 1.0]);
        // Through the lua script so the on-screen UP/DOWN counter stays in step.
        kar_mpv_send(['script-message', 'casai-set-pitch', (string)$pitch]);
        kar_mpv_send(['show-text', sprintf('casAI player · pitch %+d · UP/DOWN arrows change it', $pitch), 5000]);
        return [true, sprintf('pitch %+d applied', $pitch)];
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
    // ⚠ ON TOP, and this is not a preference — it is the difference between a words
    // screen and no words screen. mpv is started by a background service, and macOS
    // does not let a background service bring a window to the front, so the words open
    // BEHIND whatever browser is showing the song list: playing, invisible, and looking
    // like a fault. Set "words_on_top": false in the config to turn it off.
    $cfg = kar_cfg();
    if (!array_key_exists('words_on_top', $cfg) || $cfg['words_on_top']) $args[] = '--ontop';
    $args[] = '--osd-font-size=48';
    $args[] = $path;
    $cmd = implode(' ', array_map('escapeshellarg', $args)) . ' >/dev/null 2>&1 & echo $!';
    @exec($cmd);
    for ($i = 0; $i < 20; $i++) {
        if (kar_mpv_alive()) break;
        usleep(250000);
    }
    kar_mpv_send(['show-text', sprintf('casAI player · pitch %+d · UP/DOWN arrows change it · F fullscreen · Q closes', $pitch), 6000]);
    return [true, sprintf('pitch %+d applied', $pitch)];
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
    $cut = kar_is_local()
        ? "datetime('now','localtime','-30 days')"
        : "NOW() - INTERVAL 30 DAY";
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
