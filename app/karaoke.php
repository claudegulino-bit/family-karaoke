<?php
// Full-page Karaoke system — opened in its own tab from People > 🎤 Karaoke.
// the owner (2026-09-06): "karaoke requires a space" — the in-app tab lost half the screen
// to the sidebar and People headers.
//
// ONE page, TWO worlds (2026-09-08). Which one it is comes from karaoke_backend.php:
//
//   SERVER   — the owner's casAI. MySQL, the song list published by karaoke_sync.py, a
//              login, three named Macs, and every action posted to /app.php where it
//              becomes a queue row for the watcher.
//   LOCAL    — one Mac in one house (another household). SQLite in a file,
//              the songs folder scanned live, no login, no Mac picker, and actions
//              posted to karaoke_api.php which drives mpv on this same machine.
//
// Everything below is shared. Where the two genuinely differ, the difference is named
// with $KAR_LOCAL rather than duplicated into a second copy of the page.
require_once __DIR__ . '/karaoke_backend.php';
$KAR_LOCAL = kar_is_local();

if (!$KAR_LOCAL) {
    // casAI's own gate. Standalone has no accounts to check — one Mac, one house.
    session_start();
    if (empty($_SESSION['user_id'])) { header('Location: /login.php'); exit; }
}
header('Cache-Control: no-store, no-cache, must-revalidate');
function h($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }

if ($KAR_LOCAL) {
    $pdo = kar_db();                       // SQLite, created on first run
} else {
    $config = require __DIR__ . '/../config/database.php';
    try {
        $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}";
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Throwable $e) { $pdo = null; }
}
// ---- WHICH MAC? (2026-09-07) ---------------------------------------------
// the owner has three: this laptop, the other Macs. They
// share ONE Google Drive songs folder and ONE casAI server, so without a name they
// would all answer the same ▶ Play and the song would start in two houses at once.
// A request is addressed to a Mac by name; each Mac answers only its own.
// TO ADD A MAC: use "+ Add a Mac..." in the Play-on box on the page — then put the SAME
// name in that Mac's ~/casai/karaoke_config.json as "mac_name". The two must match exactly.
// ⚠ STANDALONE HAS NO PICKER AT ALL: one Mac, nothing to address, so his machine names
// never travel to anyone else's house.
$KAR_MACS = [];
if (!$KAR_LOCAL) {
    try {
        if ($pdo) $KAR_MACS = $pdo->query("SELECT name FROM karaoke_macs ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { $KAR_MACS = []; }
    if (!$KAR_MACS) $KAR_MACS = ['MacBook Pro'];   // never render an empty picker
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cantoria</title>
<link rel="icon" href="/favicon.ico">
<style>
  * { box-sizing: border-box; }
  body { margin:0; background:#1A1F2C; color:#e2e8f0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; }
  .kar-wrap { max-width:1660px; margin:0 auto; padding:18px 26px 26px; }
  code { background:#121620; padding:1px 5px; border-radius:4px; }
  /* Pitch stepper: real − / + buttons either side of the number, big enough to
     hit easily (the owner, 2026-09-06: "make the field wider, and the arrows could
     go one to the right and one to the left where we have more space"). The
     browser's tiny built-in spinner is hidden — the buttons replace it. */
  .kar-pitch::-webkit-inner-spin-button, .kar-pitch::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
  .kar-pitch { -moz-appearance: textfield; appearance: textfield; }
  /* The pitch controls are ONE control, not four. They live inside a single bordered, rounded
     group (.kar-pgrp) and the parts have no borders of their own — the owner, 2026-09-10: "it looks
     like there are four columns within pitch". The group's border also carries the state that
     used to live on the number box: gold = a saved pitch, dashed blue = a temporary guest reset. */
  .kar-pgrp { display: inline-flex; align-items: center; flex: 0 0 auto; width: 114px;
    background: #121620; border: 1px solid #334155; border-radius: 8px; overflow: hidden; }
  /* The ⟲ is HIDDEN, not dimmed, on a song already at 0 — nothing to reset, so nothing to see.
     Its space is still reserved (visibility, not display) because collapsing it would make the
     rows ragged: every column after Pitch would shift left on those rows only. */
  .kar-preset.is-idle { visibility: hidden; }
  .kar-pgrp.is-saved { border-color: #D2AD6C; }
  .kar-pgrp.is-temp  { border-style: dashed; border-color: #60A5FA; }
  .kar-pstep { flex: 0 0 auto; width: 26px; height: 24px; font-size: 16px; font-weight: 700; line-height: 1;
    background: transparent; border: 0; color: #94a3b8; border-radius: 0; cursor: pointer;
    font-family: inherit; padding: 0; }
  .kar-preset { flex: 0 0 auto; width: 26px; height: 24px; font-size: 19px; font-weight: 400; line-height: 1;
    background: transparent; border: 0; border-radius: 0; cursor: pointer; font-family: inherit; padding: 0; }
  .kar-pstep:hover, .kar-preset:hover { background: rgba(96,165,250,.18); color: #e2e8f0; }
  .kar-pstep:active, .kar-preset:active { background: rgba(96,165,250,.30); }
  .kar-pgrp .kar-pitch { background: transparent; border: 0; border-radius: 0; }
  /* The "? How it works" cards FLOAT — they used to sit inside their panel and make it
     twice as tall, which is what made the panels feel heavy. Now they stand beside the
     work instead of on top of it, and stay put until closed.
     WIDE AND SHORT, deliberately (the owner, 2026-09-08: "high and narrow… vertically they're
     narrow… it will look better if it was rectangular but longest left to right"). A tall
     column pinned to the right edge is genuinely hard to read; the same words across a wide
     box are two or three short rows instead of a long ladder. Nothing sits under it, so the
     width costs nothing.
     TOP IS MEASURED, NOT FIXED (the owner, 2026-09-08: "it comes up too high, it covers half
     of the buttons… it should be lower, where the songs begin"). karHelpPlace() reads the
     song list's own top and puts the card there, so it lies OVER THE SONGS and never over
     the header buttons or the panel it is explaining — including when an open panel has
     pushed the list down. The 230px here is only the value before the first measurement. */
  .kar-help { position: fixed; right: 18px; top: 230px;
    width: min(760px, calc(100vw - 36px)); max-height: calc(100vh - 130px);
    overflow-y: auto; z-index: 60; background: #161c28; border: 1px solid #D2AD6C;
    border-radius: 10px; padding: 13px 17px; font-size: 12.5px; line-height: 1.7;
    color: #cbd5e1; box-shadow: 0 10px 34px rgba(0,0,0,.55); }
  @media (max-width: 900px) { .kar-help { position: static; width: auto; max-height: none; margin-bottom: 12px; } }
</style>
<script src="/qrcode.min.js"></script>
</head>
<body>
<div class="kar-wrap">
  <div style="display:flex;align-items:baseline;gap:14px;margin-bottom:14px;flex-wrap:wrap">
    <div style="margin:0">
      <h1 style="margin:0;font-size:22px;font-weight:800;color:#f3f4f6;letter-spacing:.01em">🎤 Cantoria</h1>
      <div style="color:#94a3b8;font-size:12px;margin-top:1px">Karaoke for any room</div>
    </div>
    <?php if ($KAR_LOCAL): ?>
    <span style="color:#64748b;font-size:12.5px">everything runs on this Mac — nothing to sign in to</span>
    <?php else: ?>
    <span style="color:#64748b;font-size:12.5px">songs play in QMidi on the Mac · <a href="/app.php?view=people" style="color:#60A5FA;text-decoration:none">back to casAI</a></span>
    <?php endif; ?>
  </div>
  <?php
  // WHERE THE SONGS COME FROM — the second real difference between the two worlds.
  // Server: karaoke_songs.json, written by ~/casai/karaoke_sync.py on the Mac and pushed
  // via scp, because the page and the files are on different machines.
  // Local: the folder itself, scanned on every page load — the page IS on the Mac, so a
  // download or a rename shows up on the next reload with nothing to publish.
  if ($KAR_LOCAL) {
      $_kjDb  = kar_songs();
      $_kjGen = date('Y-m-d H:i');
      $_kj    = ['songs_folder' => kar_songs_dir()];   // never "not published yet"
  } else {
      $_kjPath = '/var/www/getcasa.ai/karaoke_songs.json';
      $_kj = is_file($_kjPath) ? json_decode((string)file_get_contents($_kjPath), true) : null;
      $_kjDb   = (is_array($_kj) && !empty($_kj['database']) && is_array($_kj['database'])) ? array_values($_kj['database']) : [];
      $_kjGen  = is_array($_kj) ? (string)($_kj['generated_at'] ?? '') : '';
  }
  // Per-person Best lists (2026-09-06, the owner's redesign): the Main Playlist view is gone
  // (it was a strict subset of the database — ▶ plays straight from the database file,
  // never through a QMidi playlist), and "Claude Best" became one person's list among many.
  // The QMidi .qmpl playlists on the Mac are untouched — this is casAI's own favorites data.
  // Two Macs, one set of lists: pick up anything starred on the other one before drawing
  // this page. Throttled inside, and silent if this install shares nothing.
  if ($KAR_LOCAL && $pdo) { try { kar_sync($pdo); } catch (Throwable $e) { /* never block the party */ } }
  $_kjBestBy  = $pdo ? kar_best_lists($pdo) : [];
  $_kjWho     = kar_best_default($_kjBestBy, $pdo);   // whose list opens first — never a hardcoded name
  // Stored working pitches (the editable pitch box) — override the filename pitch on ▶ plays.
  $_kjPitch = $pdo ? kar_pitch_map($pdo) : [];
  // 🆕 New — everything downloaded in the last 30 days, newest first (the owner, 2026-09-07:
  // "you don't remember what you downloaded last night... a temporary place, a simple click"),
  // each with the duplicate finding made at download time so the review list can still flag
  // it days later.
  [$_kjNew, $_kjDup] = $pdo ? kar_new_downloads($pdo, $_kjDb) : [[], []];
  ?>
  <div id="karaoke-page">
    <?php if ($_kj === null): ?>
    <p style="color:#94a3b8;font-size:13px">The karaoke song list hasn't been published to the server yet — ask Claude to run <code>karaoke_sync.py</code> and it will appear here.</p>
    <?php else: ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <button type="button" class="kar-chip kar-on" onclick="karSwitch('db',this)" style="font-family:inherit;background:#1d4ed8;border:1px solid #2563eb;color:#fff;cursor:pointer;font-size:12px;font-weight:700;padding:6px 11px;border-radius:999px">🗂 Song Database <span style="font-weight:600;opacity:.8"><?= count($_kjDb) ?></span></button>
      <button type="button" class="kar-chip" onclick="karSwitch('new',this)" title="Everything downloaded in the last 30 days, newest first — so last night's songs, and last month's, are one click away" style="font-family:inherit;background:#1e293b;border:1px solid #334155;color:#94a3b8;cursor:pointer;font-size:12px;font-weight:700;padding:6px 11px;border-radius:999px">🆕 New <span style="font-weight:600;opacity:.8"><?= count($_kjNew) ?></span></button>
      <button type="button" id="kar-best-chip" class="kar-chip" onclick="karSwitch('best',this)" style="font-family:inherit;background:#1e293b;border:1px solid #334155;color:#94a3b8;cursor:pointer;font-size:12px;font-weight:700;padding:6px 11px;border-radius:999px">⭐ Best of <span id="kar-best-name"><?= h($_kjWho !== '' ? $_kjWho : 'nobody yet') ?></span> <span id="kar-best-count" style="font-weight:600;opacity:.8"><?= $_kjWho !== '' ? count($_kjBestBy[$_kjWho]) : 0 ?></span></button>
      <select id="kar-who" onchange="karWhoChange(this)" title="Whose Best list — pick a person, or add a new one" style="font-family:inherit;background:#1e293b;border:1px solid #334155;color:#94a3b8;cursor:pointer;font-size:12px;font-weight:700;padding:6px 8px;border-radius:999px">
        <?php foreach (array_keys($_kjBestBy) as $_kbp): ?>
        <option value="<?= h($_kbp) ?>"><?= h($_kbp) ?></option>
        <?php endforeach; ?>
        <option value="__add__">＋ Add a person…</option>
        <option value="__remove__">− Remove a person…</option>
      </select>
      <input id="kar-search" type="text" placeholder="Search songs, pitch, CSG, names…" oninput="karRender()" onkeydown="if(event.key==='Escape'){karClearSearch(true);}" title="Type to filter. Esc clears it — and switching views clears it too." style="font-family:inherit;flex:1;min-width:150px;background:#121620;border:1px solid #334155;border-radius:8px;color:#e2e8f0;font-size:13px;padding:8px 12px">
      <button type="button" onclick="karYtGo()" title="Opens YouTube in the next tab — browse, copy a song's link, then click back to this tab and paste it" style="font-family:inherit;background:#EF4444;border:1px solid #EF4444;color:#fff;cursor:pointer;font-size:12px;font-weight:700;padding:6px 13px;border-radius:999px">▶ YouTube</button>
      <button type="button" onclick="karQToggle()" id="kar-q-btn" title="The Up Next queue — who sings next, in order" style="appearance:none;-webkit-appearance:none;font-family:inherit;background:#1e293b;border:1px solid rgba(210,173,108,.45);color:#D2AD6C;cursor:pointer;font-size:12px;font-weight:700;padding:6px 11px;transition:background .12s,border-color .12s,box-shadow .12s;border-radius:999px">🎶 Up Next <span id="kar-q-count" style="font-weight:600;opacity:.8">0</span></button>
      <button type="button" onclick="karDlToggle()" id="kar-dl-btn" style="appearance:none;-webkit-appearance:none;font-family:inherit;background:#1e293b;border:1px solid #334155;color:#94a3b8;cursor:pointer;font-size:12px;font-weight:700;padding:6px 11px;transition:background .12s,border-color .12s,box-shadow .12s;border-radius:999px">⬇ Downloads</button>
      <button type="button" onclick="karQrToggle()" id="kar-qr-btn" title="The code guests scan to request or bring songs from their own phones" style="appearance:none;-webkit-appearance:none;font-family:inherit;background:#1e293b;border:1px solid rgba(192,132,252,.45);color:#c084fc;transition:background .12s,border-color .12s,box-shadow .12s;cursor:pointer;font-size:12px;font-weight:700;padding:6px 11px;border-radius:999px"><span style="font-size:15px">📱</span> Guest QR</button>
      <button type="button" onclick="location.reload()" title="Reload the song lists from the server (after a download or rename)" style="font-family:inherit;background:#1e293b;border:1px solid #334155;color:#94a3b8;cursor:pointer;font-size:12px;font-weight:700;padding:6px 11px;border-radius:999px"><span style="font-size:15px">🔄</span> Refresh</button>
      <button type="button" onclick="karGuideToggle()" id="kar-guide-btn" title="How everything on this page works — all the rules in one readable place" style="appearance:none;-webkit-appearance:none;font-family:inherit;background:#1e293b;border:1px solid #334155;color:#94a3b8;cursor:pointer;font-size:12px;font-weight:700;padding:6px 11px;transition:background .12s,border-color .12s,box-shadow .12s;border-radius:999px"><span style="font-size:15px">📖</span> Guide</button>
    </div>
    <div id="kar-guide-panel" style="display:none;margin-top:10px;background:#121620;border:1px solid #334155;border-radius:10px;padding:16px 22px;max-height:calc(100vh - 220px);overflow-y:auto">
      <div style="display:flex;align-items:center;gap:10px">
        <h2 style="margin:0;font-size:16px;font-weight:800;color:#f3f4f6">🎤 Cantoria Guide</h2>
        <button type="button" onclick="karPanelClose()" title="Close this panel (or press Esc)" style="margin-left:auto;font-family:inherit;background:none;border:1px solid #334155;color:#94a3b8;cursor:pointer;font-size:12px;font-weight:600;padding:5px 12px;border-radius:8px">✕ Close</button>
      </div>
      <!-- Rebuilt 2026-09-08 on the owner's own reading of it: "very busy, unorganized… too
           many colors. Maybe multiple boxes, each box clearly says what it's for."
           So: six cards, shut until you pick one, and TWO colours in the whole panel —
           gold for anything you can click or a heading, grey for everything you read.
           The dozen colours that were here before signalled nothing; they were decoration
           pretending to be structure. -->
      <p style="margin:12px 0 0;color:#94a3b8;font-size:13px">Pick the part you want. Everything else stays out of the way.</p>

      <div id="kar-guide-cards" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(215px,1fr));gap:8px;margin:12px 0 4px">
        <?php
        // One row per card: key, title, and the one line that says what it is for.
        $_karCards = [];
        if ($KAR_LOCAL) $_karCards[] = ['setup', '1 · Setting up the Mac', 'Once only — the songs folder, the player, and putting it on another Mac.'];
        else            $_karCards[] = ['setup', '1 · Setting up the Mac', 'Once only — naming the Mac, the songs folder, and the player.'];
        $_karCards[] = ['sing',  '2 · Play a song',            'Find it, play it, and set the key.'];
        $_karCards[] = ['while', '3 · While it is playing',    'The gold bar: key, speed, start and stop.'];
        $_karCards[] = ['party', '4 · Party controls',         'The singing queue, guests\' phones, and new songs.'];
        $_karCards[] = ['songs', '5 · Managing songs',         'Best lists, new arrivals, renaming and removing.'];
        // The three party panels each get a card of their own. Their words live HERE and
        // nowhere else — the floating "?" beside each panel borrows this same text rather
        // than keeping a second copy that would quietly drift out of step with it.
        $_karCards[] = ['upnext',    '6 · Up Next',   'The singing queue, and what scheduling fairness does.'];
        $_karCards[] = ['downloads', '7 · Downloads', 'Bringing songs in from YouTube.'];
        $_karCards[] = ['guestqr',   '8 · Guest QR',  'Guests asking for songs from their own phones.'];
        if ($KAR_LOCAL) $_karCards[] = ['update','9 · Software updates',      'Installing the newest version. Your songs are never touched.'];
        foreach ($_karCards as [$_k, $_t, $_d]): ?>
        <button type="button" id="kar-gc-<?= $_k ?>" onclick="karGuideOpen('<?= $_k ?>')" style="font-family:inherit;text-align:left;background:#1a2130;border:1px solid #334155;border-radius:9px;padding:11px 13px;cursor:pointer">
          <span style="display:block;color:#D2AD6C;font-size:13.5px;font-weight:800"><?= h($_t) ?></span>
          <span style="display:block;color:#94a3b8;font-size:12px;line-height:1.5;margin-top:3px"><?= h($_d) ?></span>
        </button>
        <?php endforeach; ?>
      </div>

      <!-- ── the sections themselves. Grey text, gold only for headings and things you click ── -->
      <div id="kar-guide-body" style="display:none;margin-top:14px;border-top:1px solid #334155;padding-top:14px;color:#cbd5e1;font-size:13.5px;line-height:1.8">

        <div class="kar-gs" id="kar-gs-setup" style="display:none">
          <h3 style="margin:0 0 8px;font-size:14.5px;font-weight:800;color:#D2AD6C">Setting up the Mac</h3>
          <p style="margin:0 0 10px;color:#94a3b8;font-size:12.5px">A once-only job. If you are already singing, there is nothing to do here.</p>
          <?php if ($KAR_LOCAL): ?>
          <p style="margin:0 0 6px"><b>Putting the karaoke on another Mac.</b> Open <b>Terminal</b> on that Mac — hold ⌘, press Space, type <code>Terminal</code>, press Return — then paste this one line and press Return. It does everything: the player, the karaoke, the songs folder and the Desktop icon.</p>
          <div style="margin:0 0 12px;padding:9px 12px;background:#0d1117;border:1px solid #334155;border-radius:8px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11.5px;color:#cbd5e1;overflow-x:auto;white-space:nowrap">curl -fsSL https://raw.githubusercontent.com/claudegulino-bit/family-karaoke/main/install.sh | bash</div>
          <p style="margin:0 0 12px;color:#94a3b8;font-size:12.5px">It asks for the Mac password once, if that Mac has never had Homebrew. <b>Nothing appears on screen as you type it</b> — no dots, no stars. That is normal.</p>
          <?php else: ?>
          <p style="margin:0 0 6px"><b>Pick the Mac first.</b> The <b>Play on</b> box in the gold bar says which Mac this page is talking to — the music, and the buttons below, all go to that one.</p>
          <p style="margin:0 0 12px"><b>Give the Mac its name.</b> If it is not in the <b>Play on</b> box, pick <b>＋ Add a Mac…</b> and type a name. Then on that Mac set <code>"mac_name"</code> in <code>~/casai/karaoke_config.json</code> to the same name, so two houses never start the same song at once.</p>
          <?php endif; ?>
          <p style="margin:0 0 4px"><b>Where the songs are.</b> One folder, holding the song files.</p>
          <button type="button" onclick="karPickFolder()" id="kar-pick-btn" style="font-family:inherit;margin:2px 0;background:rgba(210,173,108,.12);border:1px solid #D2AD6C;color:#D2AD6C;cursor:pointer;font-size:13px;font-weight:700;padding:8px 16px;border-radius:8px">📁 Choose the karaoke songs folder…</button>
          <span id="kar-pick-msg" style="display:block;margin:4px 0 12px;color:#94a3b8;font-size:12px">Using now: <b id="kar-pick-cur" style="color:#cbd5e1"><?= h($_kj['songs_folder'] ?? 'not chosen yet') ?></b><br><span style="color:#94a3b8">The chooser opens on the Mac that plays the music — a web page is never allowed to see a real folder path.</span></span>
          <p style="margin:0 0 4px"><b>The player.</b> Two free programs do the playing and the downloading. In Terminal:</p>
          <div style="margin:0 0 4px;padding:9px 12px;background:#0d1117;border:1px solid #334155;border-radius:8px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;color:#cbd5e1">brew install mpv yt-dlp</div>
          <p style="margin:0 0 8px;color:#94a3b8;font-size:12.5px">If it answers <i>command not found: brew</i>, paste this first, let it finish, then repeat the line above:</p>
          <div style="margin:0 0 8px;padding:9px 12px;background:#0d1117;border:1px solid #334155;border-radius:8px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px;color:#cbd5e1;overflow-x:auto;white-space:nowrap">/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"</div>
          <button type="button" onclick="karCheckTools()" id="kar-tools-btn" style="font-family:inherit;margin:2px 0;background:rgba(210,173,108,.12);border:1px solid #D2AD6C;color:#D2AD6C;cursor:pointer;font-size:13px;font-weight:700;padding:8px 16px;border-radius:8px">✅ Check it worked</button>
          <span id="kar-tools-msg" style="display:block;margin:4px 0 12px;color:#94a3b8;font-size:12px">Press this when Terminal has finished — it asks the Mac what is really installed, so you do not have to judge it from the scrollback.</span>
          <p style="margin:0"><b>Keep the Mac on and awake</b> during a party. It does the playing, and it is what the guests' phones are talking to.</p>
        </div>

        <div class="kar-gs" id="kar-gs-sing" style="display:none">
          <h3 style="margin:0 0 8px;font-size:14.5px;font-weight:800;color:#D2AD6C">Play a song</h3>
          <ul style="margin:0;padding-left:20px">
            <li><b>Find it</b> — type anything in the search box: the artist, the title, or the name of whoever sings it.</li>
            <li><b>Press ▶ Play</b> — it plays on the Mac. On that Mac's keyboard, <b>F</b> makes it full screen and <b>Q</b> closes it.</li>
            <li><b>The number beside it is your key</b> — press − or + to move it up or down. It stays that way for next time.</li>
            <li><b>Someone else wants to sing it?</b> Press <b>Reset</b>, then Play. It plays once in the original key and your own key comes straight back.</li>
          </ul>
          <p style="margin:10px 0 0;color:#94a3b8;font-size:12.5px"><label style="cursor:pointer"><input type="checkbox" id="kar-qmidi-cb" onchange="karQmidiToggle(this)" style="vertical-align:-1px;margin-right:6px">Show the old blue ▶ QMidi play button too — hidden, not deleted.</label></p>
        </div>

        <div class="kar-gs" id="kar-gs-while" style="display:none">
          <h3 style="margin:0 0 8px;font-size:14.5px;font-weight:800;color:#D2AD6C">While it is playing</h3>
          <ul style="margin:0;padding-left:20px">
            <li>The <b>gold bar</b> at the top of the page is always there — that is where you steer the song being sung.</li>
            <li><b>Key</b> and <b>Speed</b> change it right now, in the middle of the song.</li>
            <li><b>▶ Start</b> begins the song again from the top. <b>⏹ Stop</b> stops the music.</li>
            <li>Anything changed up there is <b>just for tonight</b>. The key a song always starts at is the number on its own row.</li>
          </ul>
        </div>

        <div class="kar-gs" id="kar-gs-party" style="display:none">
          <h3 style="margin:0 0 8px;font-size:14.5px;font-weight:800;color:#D2AD6C">Party controls</h3>
          <p style="margin:0 0 8px;color:#94a3b8;font-size:12.5px">Three buttons at the top do the work. Each has its own card below with the full detail.</p>
          <ul style="margin:0;padding-left:20px">
            <li><b>🎶 Up Next</b> — the singing queue. Click <span style="display:inline-block;border:1px solid #60A5FA;background:rgba(96,165,250,.14);color:#93c5fd;font-weight:800;border-radius:5px;padding:0 7px;line-height:1.6">＋</span> on a song to add someone to it, then keep pressing <b>▶ Next singer</b> all night. <a href="#" onclick="karGuideOpen('upnext');return false" style="color:#D2AD6C">Card 6</a>.</li>
            <li><b>📱 Guest QR</b> — guests scan it with their phone and ask for songs themselves. <a href="#" onclick="karGuideOpen('guestqr');return false" style="color:#D2AD6C">Card 8</a>.</li>
            <li><b>⬇ Downloads</b> — bring new songs in from YouTube. <a href="#" onclick="karGuideOpen('downloads');return false" style="color:#D2AD6C">Card 7</a>.</li>
            <li>When something happens on its own — a guest's song arriving, for instance — the purple strip under the buttons tells you.</li>
          </ul>
        </div>

        <div class="kar-gs" id="kar-gs-songs" style="display:none">
          <h3 style="margin:0 0 8px;font-size:14.5px;font-weight:800;color:#D2AD6C">Managing songs</h3>
          <ul style="margin:0;padding-left:20px">
            <li><b>⭐ is each person's own list</b> — pick their name in the dropdown at the top, then click the stars on their songs.</li>
            <li><b>🆕 New</b> holds everything that arrived in the last month, so you never have to remember what came in last night. Its <b>Duplicate</b> column warns you when a song looks like one you already own.</li>
            <li><b>✎ renames a song · ✕ removes it.</b> Removed songs go to a "Deleted" folder — nothing is ever destroyed.</li>
            <li><b>These songs are yours, for singing at home.</b> If you ever run this somewhere commercial — a restaurant, a hall, a paid event — point it at a properly licensed song library instead. The songs folder is a setting, so that is a two-minute change (Card 1, step 2).</li>
          </ul>
        </div>


        <div class="kar-gs" id="kar-gs-upnext" style="display:none">
          <h3 style="margin:0 0 8px;font-size:14.5px;font-weight:800;color:#D2AD6C">Up Next — the singing queue</h3>
          <div class="kar-gs-body" style="display:grid;gap:9px">        <div><b style="color:#D2AD6C">Add a singer to the queue</b> — pick their name in the dropdown at the top of the page, then click <span style="display:inline-block;border:1px solid #60A5FA;background:rgba(96,165,250,.14);color:#93c5fd;font-weight:800;border-radius:5px;padding:0 7px;line-height:1.6">＋</span> on the song they want. They join the queue at the pitch showing on that row.</div>
        <div><b style="color:#D2AD6C">Start the next singer</b> — press <b style="color:#6ee7b7">▶ Next singer</b>. It plays whoever is at the top of the queue and moves it on by itself, so that one button runs the whole night.</div>
        <div><b style="color:#D2AD6C">Scheduling fairness</b> (the <span style="display:inline-block;width:11px;height:11px;border:2px solid #6ee7b7;border-radius:3px;vertical-align:-1px;margin:0 3px"></span> beside that button) — leave it ticked and everyone sings once before anyone sings twice, twice before anyone sings a third time, and so on. Nobody has to keep track of whose turn it is.</div>
        <div><b style="color:#D2AD6C">Overriding the schedule</b> — <b>↑ ↓</b> move a person up or down, and the <span style="display:inline-block;border:1px solid #7f1d1d;color:#f87171;font-weight:800;border-radius:5px;padding:0 7px;line-height:1.6">✕</span> beside a name takes <i>that one person</i> out. <b>Clear the queue</b>, over on the right, empties <i>the whole thing</i> — that one is for the end of the night.</div>
          </div>
        </div>

        <div class="kar-gs" id="kar-gs-downloads" style="display:none">
          <h3 style="margin:0 0 8px;font-size:14.5px;font-weight:800;color:#D2AD6C">Downloads — songs from YouTube</h3>
          <div class="kar-gs-body" style="display:grid;gap:9px">        <div><b style="color:#93c5fd">1 · Add the song</b> — press <b>▶ YouTube</b> at the top, find the song, copy its link, paste it in the box below and press <b>+ Add to list</b>. Add as many as you like.</div>
        <div><b style="color:#93c5fd">2 · Fetch them</b> — press <b style="color:#6ee7b7">⬇ Download the list</b>. They come down one at a time, a minute or two each. You can close this panel and carry on.</div>
        <div><b style="color:#93c5fd">3 · Where they end up</b> — a song that arrives leaves this panel and lives under <b style="color:#c084fc">🆕 New</b> for a month. One that <b style="color:#f87171">didn't work</b> stays here with the reason, so it can't slip past you.</div>
        <div><b style="color:#93c5fd">Guests can add songs too</b> — anything they send from their phone shows up here marked with their name, and downloads by itself.</div>
          </div>
        </div>

        <div class="kar-gs" id="kar-gs-guestqr" style="display:none">
          <h3 style="margin:0 0 8px;font-size:14.5px;font-weight:800;color:#D2AD6C">Guest QR — songs from guests’ phones</h3>
          <div class="kar-gs-body" style="display:grid;gap:9px">        <div><b style="color:#c084fc">What it is</b> — hold this screen up, or leave it open on the TV, and guests point their phone camera at the square. No app, no password, nothing to install.</div>
        <div><b style="color:#c084fc">What they can do</b> — ask for a song already in your library, or bring a new one from YouTube. Either way they end up in the <b style="color:#D2AD6C">🎶 Up Next</b> queue, and this page tells you the moment it happens.</div>
        <div><b style="color:#c084fc">What they cannot do</b> — they cannot play, stop, rename or delete anything. Requesting is all the code allows.</div>
        <div><b style="color:#c084fc">The red button</b> — press <b style="color:#f87171">🔄 New code</b> after a party and every QR you have shown stops working, so last night's guests can't keep sending songs. You'll need to show the new square next time.</div>
          </div>
        </div>
        <?php if ($KAR_LOCAL): ?>
        <div class="kar-gs" id="kar-gs-update" style="display:none">
          <h3 style="margin:0 0 8px;font-size:14.5px;font-weight:800;color:#D2AD6C">Software updates</h3>
          <p style="margin:0 0 8px">When a new version is released, this installs it. <b>Your songs, your settings, everyone's lists and every saved key are left exactly as they are</b> — only the program itself is replaced.</p>
          <button type="button" onclick="karUpdate()" id="kar-upd-btn" style="font-family:inherit;margin:2px 0;background:rgba(210,173,108,.12);border:1px solid #D2AD6C;color:#D2AD6C;cursor:pointer;font-size:13px;font-weight:700;padding:8px 16px;border-radius:8px">⬆︎ Update the karaoke</button>
          <div id="kar-upd-state" style="display:none;margin-top:8px;padding:9px 13px;border-radius:8px;font-size:13px;font-weight:700;line-height:1.6"></div>
          <span id="kar-upd-msg" style="display:block;margin-top:6px;color:#94a3b8;font-size:12px">This version: <b id="kar-upd-ver" style="color:#cbd5e1"><?= h(kar_installed_version()) ?></b></span>
        </div>
        <?php endif; ?>

      </div>
    </div>
    <div id="kar-yt-hint" style="display:none;margin-top:8px;background:rgba(210,173,108,.07);border:1px solid rgba(210,173,108,.25);border-radius:8px;padding:8px 14px;font-size:12.5px;color:#94a3b8"></div>
    <div id="kar-activity" style="display:none;margin-top:8px;background:rgba(192,132,252,.08);border:1px solid rgba(192,132,252,.35);border-radius:8px;padding:8px 14px;font-size:12.5px;color:#e2e8f0;line-height:1.6"></div>
    <div id="kar-dl-panel" style="display:none;margin-top:10px;background:#121620;border:1px solid #334155;border-radius:10px;padding:14px 16px">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
        <span style="font-size:13.5px;font-weight:800;color:#f3f4f6">⬇ Downloads</span>
        <button type="button" id="kar-helpbtn-dl" onclick="karHelpToggle('dl')" title="Show or hide how this panel works — your choice is remembered on this computer" style="margin-left:auto;font-family:inherit;background:none;border:1px solid #334155;color:#94a3b8;cursor:pointer;font-size:12px;font-weight:700;padding:5px 12px;border-radius:8px">? How it works</button>
        <button type="button" onclick="karPanelClose()" title="Close this panel (or press Esc)" style="font-family:inherit;background:none;border:1px solid #334155;color:#94a3b8;cursor:pointer;font-size:12px;font-weight:600;padding:5px 12px;border-radius:8px">✕ Close</button>
      </div>
      <!-- Instructions live in their own block, opened by the ? button, instead of as small
           grey print always on screen (the owner, 2026-09-07: "I see a lot of explanation...
           it would allow us to organize what we wanna say in a better way"). Shown by default
           so a new machine teaches its owner; hidden for good once dismissed. -->
      <div id="kar-help-dl" class="kar-help" style="display:none"><button type="button" onclick="karHelpToggle('dl')" title="Close" style="float:right;margin:-2px -4px 0 8px;font-family:inherit;background:none;border:none;color:#94a3b8;cursor:pointer;font-size:14px;font-weight:700;line-height:1">✕</button>

      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <input id="kar-dl-url" type="text" placeholder="Paste the YouTube link of the song here…" style="font-family:inherit;flex:1;min-width:240px;background:#0d1118;border:1px solid #334155;border-radius:8px;color:#e2e8f0;font-size:13px;padding:8px 12px">
        <button type="button" onclick="karDlAdd()" style="font-family:inherit;background:rgba(96,165,250,.10);border:1px solid #334155;color:#93c5fd;cursor:pointer;font-size:12.5px;font-weight:700;padding:7px 14px;border-radius:8px">+ Add to list</button>
        <button type="button" onclick="karDlStart()" id="kar-dl-start" style="font-family:inherit;background:#166534;border:1px solid #16a34a;color:#fff;cursor:pointer;font-size:12.5px;font-weight:700;padding:7px 14px;border-radius:8px">⬇ Download the list</button>
        <button type="button" onclick="karDlClear()" title="Empties the whole list at once — removes the links only, no files are touched" style="font-family:inherit;background:none;border:1px solid #334155;color:#94a3b8;cursor:pointer;font-size:12.5px;font-weight:600;padding:7px 14px;border-radius:8px">Clear the list</button>
      </div>
      <div id="kar-dl-list" style="margin-top:10px"></div>
    </div>
    <div id="kar-q-panel" style="display:none;margin-top:10px;background:#121620;border:1px solid rgba(210,173,108,.35);border-radius:10px;padding:14px 16px">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
        <span style="font-size:13.5px;font-weight:800;color:#D2AD6C">🎶 Up Next — the singing queue</span>
        <button type="button" id="kar-helpbtn-q" onclick="karHelpToggle('q')" title="Show or hide how this panel works — your choice is remembered on this computer" style="margin-left:auto;font-family:inherit;background:none;border:1px solid #334155;color:#94a3b8;cursor:pointer;font-size:12px;font-weight:700;padding:5px 12px;border-radius:8px">? How it works</button>
        <button type="button" onclick="karPanelClose()" title="Close this panel (or press Esc)" style="font-family:inherit;background:none;border:1px solid #334155;color:#94a3b8;cursor:pointer;font-size:12px;font-weight:600;padding:5px 12px;border-radius:8px">✕ Close</button>
      </div>
      <div id="kar-help-q" class="kar-help" style="display:none"><button type="button" onclick="karHelpToggle('q')" title="Close" style="float:right;margin:-2px -4px 0 8px;font-family:inherit;background:none;border:none;color:#94a3b8;cursor:pointer;font-size:14px;font-weight:700;line-height:1">✕</button>

      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <button type="button" onclick="karQNext()" id="kar-q-next" style="font-family:inherit;background:#166534;border:1px solid #16a34a;color:#fff;cursor:pointer;font-size:14px;font-weight:800;padding:9px 20px;border-radius:8px">▶ Next singer</button>
        <select id="kar-q-player" onchange="try{localStorage.setItem('kar_q_player',this.value)}catch(e){}" title="Which player the Next-singer button uses" style="font-family:inherit;background:#1e293b;border:1px solid #334155;color:#94a3b8;font-size:12.5px;font-weight:700;padding:8px 10px;border-radius:8px">
          <option value="qmidi">plays in QMidi</option>
          <option value="mpv">plays in casAI player</option>
        </select>
        <label style="display:flex;align-items:center;gap:6px;color:#94a3b8;font-size:12.5px;font-weight:600;cursor:pointer" title="With this on, ▶ Next singer picks whoever has sung the LEAST tonight — everyone sings one song before anyone sings a second, two before anyone's third, and so on">
          <input type="checkbox" id="kar-q-fair" onchange="try{localStorage.setItem('kar_q_fair',this.checked?'1':'')}catch(e){}">
          Scheduling fairness
        </label>
        <button type="button" onclick="karQClear()" style="margin-left:auto;font-family:inherit;background:none;border:1px solid #334155;color:#94a3b8;cursor:pointer;font-size:12px;font-weight:600;padding:7px 12px;border-radius:8px">Clear the queue</button>
      </div>
      <div id="kar-q-now" style="display:none;margin-top:10px;color:#D2AD6C;font-size:13.5px;font-weight:700"></div>
      <div id="kar-q-list" style="margin-top:4px"></div>
    </div>
    <div id="kar-qr-panel" style="display:none;margin-top:10px;background:#121620;border:1px solid rgba(192,132,252,.4);border-radius:10px;padding:16px 18px">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
        <span style="font-size:13.5px;font-weight:800;color:#c084fc">📱 Guest QR</span>
        <button type="button" id="kar-helpbtn-qr" onclick="karHelpToggle('qr')" title="Show or hide how this panel works — your choice is remembered on this computer" style="margin-left:auto;font-family:inherit;background:none;border:1px solid #334155;color:#94a3b8;cursor:pointer;font-size:12px;font-weight:700;padding:5px 12px;border-radius:8px">? How it works</button>
        <button type="button" onclick="karPanelClose()" title="Close this panel (or press Esc)" style="font-family:inherit;background:none;border:1px solid #334155;color:#94a3b8;cursor:pointer;font-size:12px;font-weight:600;padding:5px 12px;border-radius:8px">✕ Close</button>
      </div>
      <!-- The big "scan this" line below stays in the panel body on purpose: it is aimed at
           the GUEST holding the phone, not at the host. Only the host-facing explanation
           moved in here. -->
      <div id="kar-help-qr" class="kar-help" style="display:none"><button type="button" onclick="karHelpToggle('qr')" title="Close" style="float:right;margin:-2px -4px 0 8px;font-family:inherit;background:none;border:none;color:#94a3b8;cursor:pointer;font-size:14px;font-weight:700;line-height:1">✕</button>

      </div>
      <div style="display:flex;gap:22px;flex-wrap:wrap;align-items:center">
        <div id="kar-qr-code" style="background:#fff;padding:12px;border-radius:10px"></div>
        <div style="flex:1;min-width:240px">
          <p style="margin:0;color:#e2e8f0;font-size:15px;font-weight:800">📱 Guests: scan this with your phone camera</p>
          <p id="kar-qr-url" style="margin:10px 0 0;color:#64748b;font-size:10.5px;word-break:break-all"></p>
          <button type="button" onclick="karQrRotate()" title="Issues a fresh code — every QR shown before stops working. Do this after a party so old guests can't keep requesting" style="margin-top:10px;font-family:inherit;background:none;border:1px solid #7f1d1d;color:#f87171;cursor:pointer;font-size:11.5px;font-weight:600;padding:6px 12px;border-radius:8px">🔄 New code (old QR stops working)</button>
        </div>
      </div>
    </div>
    <div id="kar-del-pop" style="display:none;position:fixed;z-index:60;background:#1c2331;border:1px solid #7f1d1d;border-radius:10px;padding:12px 14px;max-width:360px;box-shadow:0 6px 24px rgba(0,0,0,.65)">
      <div style="color:#f87171;font-size:12px;font-weight:700;margin-bottom:4px">Remove this song?</div>
      <div id="kar-del-song" style="color:#e2e8f0;font-size:12.5px;font-weight:600;margin-bottom:6px;word-break:break-word"></div>
      <div style="color:#94a3b8;font-size:11.5px;margin-bottom:10px">The file is <b>moved</b> to the "09-Deleted by casAI" folder — not destroyed. You can get it back.</div>
      <div style="display:flex;gap:8px;justify-content:flex-end">
        <button type="button" onclick="karDelHide()" style="font-family:inherit;background:none;border:1px solid #334155;color:#94a3b8;cursor:pointer;font-size:12px;font-weight:600;padding:6px 14px;border-radius:8px">Cancel</button>
        <button type="button" onclick="karDelDo()" id="kar-del-yes" style="font-family:inherit;background:#7f1d1d;border:1px solid #ef4444;color:#fff;cursor:pointer;font-size:12px;font-weight:700;padding:6px 14px;border-radius:8px">✕ Remove</button>
      </div>
    </div>
    <div id="kar-now-bar" style="position:sticky;top:8px;z-index:40;margin-top:10px;background:#28241a;border:1px solid rgba(210,173,108,.45);border-radius:10px;padding:9px 16px;box-shadow:0 4px 16px rgba(0,0,0,.45)">
      <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
        <?php if (!$KAR_LOCAL): // one Mac in standalone — nothing to address, so no picker ?>
        <span style="display:flex;align-items:center;gap:6px">
          <span style="color:#94a3b8;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em">Play on</span>
          <select id="kar-mac" onchange="karMacChange(this)" title="Which Mac the music comes out of. Every button on this page — Play, Stop, pitch, tempo — goes to the Mac picked here." style="font-family:inherit;background:#121620;border:1px solid #4b5563;color:#e2e8f0;cursor:pointer;font-size:12px;font-weight:700;padding:4px 8px;border-radius:8px">
            <?php foreach ($KAR_MACS as $_km): ?>
            <option value="<?= h($_km) ?>"><?= h($_km) ?></option>
            <?php endforeach; ?>
            <option value="__addmac__">＋ Add a Mac…</option>
            <option value="__removemac__">− Remove this Mac…</option>
          </select>
        </span>
        <?php endif; ?>
        <span style="color:#D2AD6C;font-weight:700;font-size:13px">♪ Now playing:</span>
        <span id="kar-now-song" style="color:#e2e8f0;font-size:13px;font-weight:600"></span>
        <span id="kar-now-player" style="color:#64748b;font-size:11.5px"></span>
        <span style="margin-left:auto;display:flex;align-items:center;gap:8px">
          <span style="color:#94a3b8;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em">Live pitch</span>
          <button type="button" onclick="karLiveAdj(-1)" title="Lower the key by one semitone, while the song keeps playing" style="font-family:inherit;width:34px;background:#121620;border:1px solid #334155;color:#e2e8f0;cursor:pointer;font-size:15px;font-weight:700;padding:2px 0;border-radius:6px">−</button>
          <span id="kar-live-val" style="color:#D2AD6C;font-size:15px;font-weight:800;width:32px;text-align:center">0</span>
          <button type="button" onclick="karLiveAdj(1)" title="Raise the key by one semitone, while the song keeps playing" style="font-family:inherit;width:34px;background:#121620;border:1px solid #334155;color:#e2e8f0;cursor:pointer;font-size:15px;font-weight:700;padding:2px 0;border-radius:6px">+</button>
          <span style="color:#94a3b8;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;margin-left:10px">Tempo</span>
          <button type="button" onclick="karTempoAdj(-5)" title="Slow the song down 5% — the key stays true (casAI player only)" style="font-family:inherit;width:34px;background:#121620;border:1px solid #334155;color:#e2e8f0;cursor:pointer;font-size:15px;font-weight:700;padding:2px 0;border-radius:6px">−</button>
          <span id="kar-tempo-val" style="color:#6ee7b7;font-size:14px;font-weight:800;width:44px;text-align:center">100%</span>
          <button type="button" onclick="karTempoAdj(5)" title="Speed the song up 5% — the key stays true (casAI player only)" style="font-family:inherit;width:34px;background:#121620;border:1px solid #334155;color:#e2e8f0;cursor:pointer;font-size:15px;font-weight:700;padding:2px 0;border-radius:6px">+</button>
          <span style="color:#64748b;font-size:11px">a few seconds to take effect · not saved</span>
          <button type="button" onclick="karPlayAgain()" title="Start this song from the beginning — same player, at the pitch you have it now" style="font-family:inherit;margin-left:10px;background:rgba(16,185,129,.12);border:1px solid #16a34a;color:#6ee7b7;cursor:pointer;font-size:12px;font-weight:700;padding:5px 14px;border-radius:8px">▶ Start</button>
          <button type="button" onclick="karStop()" id="kar-stop-btn" title="Stop the music — silences the player within a few seconds" style="font-family:inherit;background:rgba(239,68,68,.12);border:1px solid #7f1d1d;color:#f87171;cursor:pointer;font-size:12px;font-weight:700;padding:5px 14px;border-radius:8px">⏹ Stop</button>
        </span>
      </div>
    </div>
    <div id="kar-count" style="margin-top:10px;color:#64748b;font-size:11.5px"></div>
    <!-- Two group headings over the row: the left half is about setting a song up, the right half
         about singing it. Widths here must stay in step with the row below — the left group is
         124+12+48+12+48 = 244px, then a 16px spacer with a 12px gap either side. -->
    <div style="display:flex;align-items:flex-end;gap:12px;margin-top:14px;padding:0 16px;font-size:10px;font-weight:800;letter-spacing:.10em;text-transform:uppercase">
      <span style="flex:0 0 auto;width:244px;text-align:center;color:#94a3b8;border-bottom:1px solid #334155;padding-bottom:3px" title="How the song is set up: its key, whether it is on someone&#39;s Best list, and removing it">Set up</span>
      <span style="flex:0 0 auto;width:16px"></span>
      <span style="flex:1;min-width:0;color:#6ee7b7;border-bottom:1px solid rgba(110,231,183,.35);padding-bottom:3px" title="Singing it: queue it for someone, play it now, or rename it">Sing</span>
    </div>
    <div style="display:flex;align-items:flex-end;gap:12px;margin-top:6px;padding:0 16px;font-size:10.5px;font-weight:700;letter-spacing:.04em;line-height:1.3;text-transform:uppercase;color:#94a3b8">
      <span style="flex:0 0 auto;width:124px;text-align:center" title="The pitch the Play button uses. − / + change it a semitone at a time, or type a number — it saves by itself (gold = your saved pitch). ⟲ drops it to 0 for one play only, for a guest singer, then your pitch comes back.">Pitch</span>
      <span style="flex:0 0 auto;width:48px;text-align:center" title="⭐ = on the selected person's Best list — click the star to add or remove the song for whoever is picked in the dropdown at the top">Best<br>List</span>
      <span style="flex:0 0 auto;width:48px;text-align:center" title="✕ removes the song — the file is moved to the 09-Deleted by casAI folder (recoverable), never destroyed">Delete</span>
      <span style="flex:0 0 auto;width:16px"></span>
      <span style="flex:0 0 auto;width:58px;text-align:center" title="➕ adds the song to the Up Next singing queue, for the person picked in the dropdown, at the pitch shown">Add to<br>Queue</span>
      <span id="kar-h-qmidi" style="flex:0 0 auto;width:58px;text-align:center" title="Plays the song in QMidi, at the pitch shown in the Pitch box">Play<br>QMidi</span>
      <span id="kar-h-casai" style="flex:0 0 auto;width:58px;text-align:center" title="Plays the song with casAI's own player, at the pitch shown in the Pitch box. Press Q on the Mac keyboard to close its window">Play<br>casAI</span>
      <span id="kar-h-song" onclick="karSortToggle()" style="flex:0 0 auto;width:460px;cursor:pointer;user-select:none" title="Click a song&#39;s name to rename it. Click THIS heading to sort — A→Z, then Z→A, then back to the normal order">Song Filename</span>
      <span id="kar-h-dup" style="flex:0 0 auto;width:300px;display:none" title="Songs already in your library that this one looked like when it came down. Play both, keep the better one, remove the other with ✕">Duplicate</span>
    </div>
    <div id="kar-list" style="margin-top:4px;background:#121620;border:1px solid #334155;border-radius:10px;padding:6px 16px;height:calc(100vh - 275px);min-height:300px;overflow-y:auto"></div>
    <p style="margin:10px 0 0;color:#64748b;font-size:11.5px">List updated <?= h($_kjGen ?: 'unknown') ?> from the Google Drive song folders on the Mac · how everything works is under <b style="color:#94a3b8">📖 Guide</b> at the top.</p>
    <script>
    // WHERE ACTIONS GO — the third and last real difference between the two worlds.
    // Server: /app.php, which turns each one into a queue row for the Mac watcher.
    // Local: karaoke_api.php on this same Mac, which just does it. Same request shapes,
    // same answers, so nothing else on this page has to know which world it is in.
    var KAR_LOCAL = <?= $KAR_LOCAL ? 'true' : 'false' ?>;
    var KAR_API   = KAR_LOCAL ? '/karaoke_api.php' : '/app.php';
    var KAR_DATA = {
      db:   <?= json_encode($_kjDb, JSON_UNESCAPED_UNICODE) ?>,
      new:  <?= json_encode($_kjNew, JSON_UNESCAPED_UNICODE) ?>,
      best: []
    };
    // Per-person Best lists — editable via the ⭐ on each row; person picked in the dropdown.
    var KAR_BEST_BY = <?= json_encode((object)$_kjBestBy, JSON_UNESCAPED_UNICODE) ?>;
    var KAR_PITCH = <?= json_encode((object)$_kjPitch, JSON_UNESCAPED_UNICODE) ?>;
    // Songs the download-time check thought you might already own → shown in 🆕 New only.
    var KAR_DUP = <?= json_encode((object)$_kjDup, JSON_UNESCAPED_UNICODE) ?>;
    var karWho = <?= json_encode($_kjWho) ?>;
    try { var _w = localStorage.getItem('kar_best_who'); if (_w && KAR_BEST_BY[_w]) karWho = _w; } catch(e){}
    var KAR_BEST_SET = {};
    function karRebuildBest(){
      KAR_DATA.best = KAR_BEST_BY[karWho] || [];
      KAR_BEST_SET = {};
      KAR_DATA.best.forEach(function(n){ KAR_BEST_SET[n.replace(/\.[a-z0-9]{2,4}$/i,'')] = 1; });
      var nm = document.getElementById('kar-best-name');
      var ct = document.getElementById('kar-best-count');
      if (nm) nm.textContent = karWho || 'nobody yet';
      if (ct) ct.textContent = KAR_DATA.best.length;
      var sel = document.getElementById('kar-who');
      if (sel && sel.value !== karWho) sel.value = karWho;
    }
    function karWhoChange(sel){
      if (sel.value === '__add__') {
        var nn = prompt('Name of the person for the new Best list:');
        sel.value = karWho;  // put the select back first, in case they cancel
        if (nn === null) return;
        nn = nn.trim();
        if (nn === '' || nn.length > 40) { alert('Please use a name of 1-40 characters.'); return; }
        if (!KAR_BEST_BY[nn]) {
          KAR_BEST_BY[nn] = [];
          var opt = document.createElement('option');
          opt.value = nn; opt.textContent = nn;
          // Keep the list alphabetical: insert before the first name that sorts after it.
          var before = sel.querySelector('option[value="__add__"]');
          for (var oi = 0; oi < sel.options.length; oi++) {
            var ov = sel.options[oi].value;
            if (ov === '__add__') break;
            if (ov.toLowerCase() > nn.toLowerCase()) { before = sel.options[oi]; break; }
          }
          sel.insertBefore(opt, before);
        }
        karWho = nn;
      } else if (sel.value === '__remove__') {
        // Removes the CURRENTLY SELECTED person and their whole Best list (snapshotted
        // to the audit log server-side, so it can be brought back if this was a mistake).
        sel.value = karWho;  // put the select back first
        var gone = karWho;
        if (!gone) { alert('There is nobody on the list yet — add a person first.'); return; }
        var cnt = (KAR_BEST_BY[gone] || []).length;
        if (!confirm('Remove "' + gone + '" from the list?\n\nTheir Best list (' + cnt + ' song' + (cnt === 1 ? '' : 's') + ') is removed too — a copy is kept in the log, so it can be brought back if you change your mind.')) return;
        var fdR = new FormData();
        fdR.append('form_type', 'karaoke_best_remove_person');
        fdR.append('person', gone);
        fetch(KAR_API, {method:'POST', body: fdR}).then(function(r){ return r.json(); }).then(function(d){
          if (!d.ok) { alert('Not removed' + (d.error ? ': ' + d.error : '') + '.'); return; }
          delete KAR_BEST_BY[gone];
          var og = sel.querySelector('option[value="' + gone.replace(/"/g, '\\"') + '"]');
          if (og) og.remove();
          // An empty list is a legitimate state — a Mac nobody has sung on yet. It used to
          // put "Claude" back, which on someone else's machine is a stranger's name.
          var names = Object.keys(KAR_BEST_BY);
          karWho = names.length ? names[0] : '';
          try { localStorage.setItem('kar_best_who', karWho); } catch(e){}
          karRebuildBest();
          karSwitch('best', document.getElementById('kar-best-chip'));
        }).catch(function(){ alert('Network error — the removal was not sent.'); });
        return;
      } else {
        karWho = sel.value;
      }
      try { localStorage.setItem('kar_best_who', karWho); } catch(e){}
      karRebuildBest();
      // Picking a person means "show me their songs" — jump straight to their Best list
      // (the owner, 2026-09-07: it only switched when the Best chip was already active,
      // which read as random). karSwitch re-renders, so no separate karRender needed.
      karSwitch('best', document.getElementById('kar-best-chip'));
    }
    function karFnPitch(n){ var m = n.match(/\(([+-]?\d{1,2})\)/); return m ? parseInt(m[1], 10) : null; }
    var karView = 'db';
    // One place that empties the search box. render=true when the caller is not about to
    // re-render anyway (the ✕ Show all button); karSwitch passes false and renders itself.
    function karClearSearch(render){
      var el = document.getElementById('kar-search');
      if (!el) return;
      if (el.value !== '') { el.value = ''; }
      if (render !== false) karRender();
    }

    function karSwitch(view, btn){
      karView = view;
      document.querySelectorAll('.kar-chip').forEach(function(b){
        b.classList.remove('kar-on');
        b.style.background='#1e293b'; b.style.borderColor='#334155'; b.style.color='#94a3b8';
      });
      btn.classList.add('kar-on');
      btn.style.background='#1d4ed8'; btn.style.borderColor='#2563eb'; btn.style.color='#fff';
      // Switching views starts fresh. the owner, 2026-09-10: a search left in the box quietly
      // filtered the next view too, so 🆕 New would come up empty and the reason was invisible.
      karClearSearch(false);
      karRender();
    }
    var karRenderedView = 'db';
    // Sorting the SONG FILENAME column. '' = the view's natural order (alphabetical for the
    // library, newest-first for 🆕 New), then A→Z, then Z→A, then back to natural.
    var karSort = '';
    // Which Mac every button on this page talks to. Remembered per browser, so the
    // TV Mac in one house and the laptop in another each keep their own choice.
    var karMacName = '';
    function karMac(){ return karMacName; }
    function karMacChange(sel){
      if (sel.value === '__addmac__') {
        var nn = prompt('What is this Mac called? (e.g. Kitchen Mac mini)\n\nYou will put the same name in that Mac\'s karaoke_config.json.');
        sel.value = karMacName;                       // put the box back first, in case they cancel
        if (nn === null) return;
        karMacSave('karaoke_mac_add', nn.trim(), sel);
        return;
      }
      if (sel.value === '__removemac__') {
        var gone = karMacName;
        sel.value = karMacName;
        if (!confirm('Take "' + gone + '" off the list?\n\nNothing on that Mac changes — it just stops being a choice here.')) return;
        karMacSave('karaoke_mac_remove', gone, sel);
        return;
      }
      karMacName = sel.value;
      try { localStorage.setItem('kar_mac', karMacName); } catch(e){}
    }
    // Rebuilds the picker from the server's answer, so the page and the saved list can
    // never drift apart — the same reason the singer dropdown re-reads after a change.
    function karMacSave(ft, name, sel){
      if (!name) return;
      var fd = new FormData();
      fd.append('form_type', ft);
      fd.append('name', name);
      fetch(KAR_API, {method:'POST', body: fd}).then(function(r){ return r.json(); }).then(function(d){
        if (!d.ok) { alert(d.error || 'That did not save.'); return; }
        var keep = (ft === 'karaoke_mac_add') ? d.name : (d.macs[0] || '');
        while (sel.options.length && sel.options[0].value !== '__addmac__') sel.remove(0);
        for (var i = d.macs.length - 1; i >= 0; i--) {
          var o = document.createElement('option');
          o.value = d.macs[i]; o.textContent = d.macs[i];
          sel.insertBefore(o, sel.firstChild);
        }
        sel.value = keep;
        karMacName = keep;
        try { localStorage.setItem('kar_mac', karMacName); } catch(e){}
        if (ft === 'karaoke_mac_add') {
          alert('"' + d.name + '" is on the list.\n\nNow on THAT Mac, open ~/casai/karaoke_config.json and set:\n\n    "mac_name": "' + d.name + '"\n\nThe two names must match exactly, or it will not answer.');
        }
      }).catch(function(){ alert('Network error — nothing was saved.'); });
    }
    (function(){
      var sel = document.getElementById('kar-mac');
      if (!sel) return;
      var saved = null;
      try { saved = localStorage.getItem('kar_mac'); } catch(e){}
      // A remembered Mac that has since been removed from the list falls back to the
      // first one rather than sending music to a name nothing answers to.
      if (saved) { for (var i=0;i<sel.options.length;i++) if (sel.options[i].value === saved) sel.value = saved; }
      karMacName = sel.value;
    })();

    var karNowPlaying = null;         // the song last sent to a player — its row stays lit until another plays
    var karNowPlayingPlayer = 'qmidi';  // which engine it went to: 'qmidi' or 'mpv' (the casAI player)
    // The Duplicate column — 🆕 New only. Names the songs already in the library that this
    // one looked like when it came down, so the two can be compared and one removed.
    function karDupCell(full){
      var d = KAR_DUP[full];
      if (!d) return '<span style="flex:0 0 auto;width:300px;font-size:11px;color:#64748b" '
        + 'title="Nothing was flagged when this song came down.">—</span>';
      return '<span style="flex:0 0 auto;width:300px;font-size:10.5px;line-height:1.35;color:#94a3b8" title="' + karEsc(d) + '">'
        + '<b style="color:#D2AD6C;font-size:11px">⚠ You may already have this</b><br>' + karEsc(d) + '</span>';
    }
    function karSortToggle(){
      karSort = (karSort === '') ? 'az' : (karSort === 'az') ? 'za' : '';
      karRender();
    }

    function karRender(){
      var listEl = document.getElementById('kar-list');
      if (!listEl) return;
      var q = (document.getElementById('kar-search').value || '').toLowerCase();
      var src = KAR_DATA[karView] || [];
      karRenderedView = karView;
      var hd = document.getElementById('kar-h-dup');
      if (hd) hd.style.display = (karView === 'new') ? '' : 'none';
      // ⚠ Sort the ORDER, not the array. Every click handler below resolves its data-i against
      // KAR_DATA[karRenderedView], so reordering the array itself would make Play, ✎ and ✕ act on
      // the wrong song. Building an index list keeps data-i meaning what it has always meant.
      var order = [];
      for (var oi = 0; oi < src.length; oi++) order.push(oi);
      if (karSort === 'az' || karSort === 'za') {
        var dir = (karSort === 'az') ? 1 : -1;
        order.sort(function(a, b){
          return dir * src[a].localeCompare(src[b], undefined, { sensitivity: 'base', numeric: true });
        });
      }
      var hs = document.getElementById('kar-h-song');
      if (hs) hs.textContent = 'Song Filename' + (karSort === 'az' ? '  ▲' : karSort === 'za' ? '  ▼' : '');

      var out = [];
      for (var k = 0; k < order.length; k++) {
        var i = order[k];
        var full = src[i];
        if (q && full.toLowerCase().indexOf(q) === -1) continue;
        var name = full.replace(/\.[a-z0-9]{2,4}$/i,'');
        var inBest = !!KAR_BEST_SET[name];
        var star = '<button type="button" class="kar-star" data-i="' + i + '" title="'
          + (inBest ? 'On ' : 'Not on ') + karWho + '’s Best list — click to ' + (inBest ? 'remove it' : 'add it') + '" '
          + 'style="font-family:inherit;flex:0 0 auto;width:48px;background:none;border:none;cursor:pointer;font-size:17px;line-height:1;padding:0;text-align:center;'
          + (inBest ? 'color:#FFD34D;text-shadow:0 0 6px rgba(255,211,77,.45)' : 'color:#94a3b8') + '">' + (inBest ? '⭐' : '☆') + '</button>';
        var ovr = Object.prototype.hasOwnProperty.call(KAR_PITCH, full);
        var eff = ovr ? KAR_PITCH[full] : karFnPitch(full);
        if (eff === null) eff = 0;  // every song shows its real playing pitch — 0 by default
        var playing = (full === karNowPlaying);
        var pQm = playing && karNowPlayingPlayer === 'qmidi';
        var pMv = playing && karNowPlayingPlayer === 'mpv';
        out.push('<div class="kar-row' + (playing ? ' kar-row-playing' : '') + '" style="display:flex;align-items:center;gap:12px;padding:4px 6px;border-top:1px solid #1e293b;border-radius:6px'
          + (playing ? ';background:rgba(210,173,108,.16)' : '') + '">'
          + '<span class="kar-pgrp' + (ovr ? ' is-saved' : '') + '">'
          + '<button type="button" class="kar-pstep kar-pdn" data-i="' + i + '" title="Pitch DOWN one semitone — saves right away">−</button>'
          + '<input type="number" class="kar-pitch" data-i="' + i + '" min="-12" max="12" step="1" value="' + (eff === null ? '' : eff) + '" '
          + 'title="Pitch this song plays at. Use − / + or type a number — blank goes back to the filename pitch." '
          + 'style="font-family:inherit;flex:0 0 auto;width:34px;color:' + (ovr ? '#D2AD6C' : '#94a3b8') + ';font-size:13px;padding:2px 2px;text-align:center">'
          + '<button type="button" class="kar-pstep kar-pup" data-i="' + i + '" title="Pitch UP one semitone — saves right away">+</button>'
          // Guest reset lives INSIDE the pitch control now, not in a column of its own — it is a
          // thing you do TO the pitch, so it belongs beside it (the owner, 2026-09-10: too many columns).
          + '<button type="button" class="kar-preset kar-reset' + (eff === 0 ? ' is-idle' : '') + '" data-i="' + i + '" title="'
          + (eff === 0
              ? 'Already at the original key — nothing to reset'
              : 'Guest singer: drops the pitch to 0 for the NEXT PLAY ONLY — your saved pitch comes back by itself afterwards')
          + '" style="color:#D2AD6C">⟲</button>'
          + '</span>'
          + star
          + '<button type="button" class="kar-del" data-i="' + i + '" title="Remove this song from the database — the file is moved to the 09-Deleted by casAI folder (recoverable), not destroyed" '
          + 'style="font-family:inherit;flex:0 0 auto;width:48px;background:none;border:none;color:#94a3b8;cursor:pointer;font-size:13px;padding:0;text-align:center">✕</button>'
          // The gap that splits the row in two: SET UP on the left (what the song is), SING on
          // the right (what you do with it). the owner's arrangement, 2026-09-10.
          + '<span style="flex:0 0 auto;width:16px"></span>'
          + '<button type="button" class="kar-q-add" data-i="' + i + '" title="Add to the Up Next queue for ' + karEsc(karWho) + ', at the pitch shown" '
          + 'style="font-family:inherit;flex:0 0 auto;width:58px;background:rgba(96,165,250,.15);border:1px solid #60A5FA;color:#93c5fd;cursor:pointer;font-size:15px;font-weight:800;line-height:1;padding:2px 0;text-align:center;border-radius:6px">＋</button>'
          + (karShowQmidi
            ? '<button type="button" class="kar-play" data-player="qmidi" data-i="' + i + '" title="' + (pQm ? 'This song is playing now in QMidi — click to start it again' : 'Play this song in QMidi on the Mac, at the pitch shown in the Pitch box') + '" '
              + 'style="font-family:inherit;flex:0 0 auto;width:58px;cursor:pointer;font-size:11px;padding:3px 0;border-radius:6px;'
              + (pQm ? 'background:#EF4444;border:1px solid #EF4444;color:#fff;font-weight:700' : 'background:rgba(96,165,250,.10);border:1px solid #334155;color:#93c5fd')
              + '">' + (pQm ? '♪ ♪ ♪' : '▶ QMidi') + '</button>'
            : '')
          + '<button type="button" class="kar-play" data-player="mpv" data-i="' + i + '" title="' + (pMv ? 'This song is playing now in the casAI player — click to start it again' : 'Play this song with casAI\'s player on the Mac, at the pitch shown in the Pitch box. Press Q on the Mac keyboard to close its window') + '" '
          + 'style="font-family:inherit;flex:0 0 auto;width:58px;cursor:pointer;font-size:11px;padding:3px 0;border-radius:6px;'
          + (pMv ? 'background:#EF4444;border:1px solid #EF4444;color:#fff;font-weight:700' : 'background:rgba(16,185,129,.10);border:1px solid #334155;color:#6ee7b7')
          + '">' + (pMv ? '♪ ♪ ♪' : (karShowQmidi ? '▶ casAI' : '▶ Play')) + '</button>'
          // 460px fits 9 of every 10 real filenames on one line (measured: half are ≤45
          // characters, 90% ≤66); the long ones wrap to a second line rather than pushing
          // the Duplicate column out to the far right where it read as stranded.
          // The name IS the rename control — click it and it becomes a text box. There used to be
          // a separate ✎ column; the owner asked for it back, the row already has enough buttons.
          + '<span class="kar-name" data-i="' + i + '" title="Click to rename — this changes the REAL file name in the music folder" '
          + 'style="flex:0 0 auto;width:460px;word-break:break-word;font-size:13px;cursor:text;color:' + (playing ? '#D2AD6C;font-weight:700' : '#e2e8f0') + '">' + karEsc(name) + '</span>'
          // 🆕 New is the review bench, so it gets its own Duplicate column — what this
          // song looked like when it came down, side by side with the name.
          + (karView === 'new' ? karDupCell(full) : '')
          + '</div>');
      }
      var lbl = karView === 'db' ? 'song database' : (karView === 'new' ? 'new downloads (last 30 days)' : (karWho + '’s Best list'));
      // While a search is active this line stops being a quiet caption and becomes a notice you
      // cannot miss, with a one-click way out — the old 11.5px grey was easy to walk past, which
      // is exactly how a forgotten search made a view look empty for no visible reason.
      var cntEl = document.getElementById('kar-count');
      if (q) {
        cntEl.style.cssText = 'margin-top:10px;background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.5);'
          + 'border-radius:8px;padding:7px 12px;color:#fcd34d;font-size:13px;font-weight:700;'
          + 'display:flex;align-items:center;gap:10px;flex-wrap:wrap';
        cntEl.innerHTML = '<span>🔍 Filtered — showing ' + out.length + ' of ' + src.length + ' in the '
          + karEsc(lbl) + '</span>'
          + '<span style="font-weight:600;color:#e2e8f0">“' + karEsc(q) + '”</span>'
          + '<button type="button" onclick="karClearSearch(true)" style="font-family:inherit;margin-left:auto;'
          + 'background:#3b3324;border:1px solid #D2AD6C;color:#f3d9a4;cursor:pointer;font-size:12px;'
          + 'font-weight:700;padding:4px 12px;border-radius:999px">✕ Show all</button>';
      } else {
        cntEl.style.cssText = 'margin-top:10px;color:#64748b;font-size:11.5px';
        cntEl.textContent = out.length + ' of ' + src.length + ' songs in the ' + lbl;
      }
      listEl.innerHTML = out.length ? out.join('')
        : (karView === 'best' && !src.length
           ? '<p style="color:#94a3b8;font-size:13px">' + karEsc(karWho) + '’s list is empty — open 🗂 Song Database and click the ☆ on their songs to build it.</p>'
           : (karView === 'new' && !src.length
              ? '<p style="color:#94a3b8;font-size:13px">Nothing downloaded in the last 30 days — new songs land here automatically when they arrive.</p>'
              : '<p style="color:#94a3b8;font-size:13px">No songs match that search.</p>'));
    }
    function karEsc(s){ return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
    // ---- Now Playing bar with live pitch (adjust the key WHILE the song plays) ----
    var karLivePitch = 0;
    function karNowBar(){
      // The bar stays on screen AT ALL TIMES (the owner, 2026-09-06) — a fixed home for
      // Stop/Again/pitch/tempo, nothing appearing and disappearing. Idle = a grey hint.
      var sng = document.getElementById('kar-now-song');
      if (!karNowPlaying) {
        sng.textContent = 'nothing yet — press ▶ Play on a song';
        sng.style.color = '#64748b'; sng.style.fontStyle = 'italic';
        document.getElementById('kar-now-player').textContent = '';
        document.getElementById('kar-live-val').textContent = '0';
        document.getElementById('kar-tempo-val').textContent = '100%';
        return;
      }
      sng.style.color = '#e2e8f0'; sng.style.fontStyle = 'normal';
      sng.textContent = karNowPlaying.replace(/\.[a-z0-9]{2,4}$/i,'');
      document.getElementById('kar-now-player').textContent = karNowPlayingPlayer === 'mpv' ? '· casAI player' : '· QMidi';
      document.getElementById('kar-live-val').textContent = (karLivePitch > 0 ? '+' : '') + karLivePitch;
    }
    var karLiveTempo = 100;
    // The Now Playing bar (with the Live pitch and Tempo controls) is page memory — without
    // this it vanished on every reload/⟳ Refresh while the song kept playing, taking the
    // tempo buttons with it. Saved to localStorage and restored for up to 15 minutes.
    function karNowSave(){
      try {
        if (karNowPlaying) localStorage.setItem('kar_now', JSON.stringify({s: karNowPlaying, p: karNowPlayingPlayer, lp: karLivePitch, lt: karLiveTempo, t: Date.now()}));
        else localStorage.removeItem('kar_now');
      } catch(e){}
    }
    function karNowRestore(){
      try {
        var raw = localStorage.getItem('kar_now');
        if (!raw) return;
        var st = JSON.parse(raw);
        if (!st || !st.s || (Date.now() - (st.t || 0)) > 15 * 60 * 1000) { localStorage.removeItem('kar_now'); return; }
        karNowPlaying = st.s;
        karNowPlayingPlayer = st.p || 'qmidi';
        karLivePitch = typeof st.lp === 'number' ? st.lp : 0;
        karLiveTempo = typeof st.lt === 'number' ? st.lt : 100;
        document.getElementById('kar-tempo-val').textContent = karLiveTempo + '%';
        karNowBar();
      } catch(e){}
    }
    function karTempoAdj(d){
      if (!karNowPlaying) return;
      if (karNowPlayingPlayer !== 'mpv') { alert('Tempo control works with the casAI player — play the song with the green ▶ casAI button.'); return; }
      var nt = Math.max(50, Math.min(150, karLiveTempo + d));
      if (nt === karLiveTempo) return;
      karLiveTempo = nt;
      karNowSave();
      document.getElementById('kar-tempo-val').textContent = nt + '%';
      var fd = new FormData();
      fd.append('form_type', 'karaoke_live_tempo'); fd.append('mac', karMac());
      fd.append('tempo', String(nt));
      fetch(KAR_API, {method:'POST', body: fd}).then(function(r){ return r.json(); }).then(function(d2){
        if (!d2.ok) alert('The tempo change was not sent' + (d2.error ? ': ' + d2.error : '') + '.');
      }).catch(function(){ alert('Network error — the tempo change was not sent.'); });
    }
    function karLiveAdj(d){
      if (!karNowPlaying) return;
      var np = Math.max(-12, Math.min(12, karLivePitch + d));
      if (np === karLivePitch) return;
      karLivePitch = np;
      karNowSave();
      karNowBar();
      var fd = new FormData();
      fd.append('form_type', 'karaoke_live_pitch'); fd.append('mac', karMac());
      fd.append('pitch', String(np));
      fd.append('player', karNowPlayingPlayer);
      fetch(KAR_API, {method:'POST', body: fd}).then(function(r){ return r.json(); }).then(function(d2){
        if (!d2.ok) alert('The pitch change was not sent' + (d2.error ? ': ' + d2.error : '') + '.');
      }).catch(function(){ alert('Network error — the pitch change was not sent.'); });
    }
    function karPlayAgain(){
      // Restart the now-playing song from the top — same player, at the pitch as
      // adjusted live (so a mid-song key change carries into the restart).
      if (!karNowPlaying) return;
      var fd = new FormData();
      fd.append('form_type', 'karaoke_play'); fd.append('mac', karMac());
      fd.append('song', karNowPlaying);
      fd.append('player', karNowPlayingPlayer);
      fd.append('pitch_once', String(karLivePitch));
      fetch(KAR_API, {method:'POST', body: fd}).then(function(r){ return r.json(); }).then(function(d){
        if (!d.ok) { alert('Could not restart the song' + (d.error ? ': ' + d.error : '') + '.'); return; }
        karLiveTempo = 100;   // a fresh start comes back at normal speed
        karNowSave();
        document.getElementById('kar-tempo-val').textContent = '100%';
        karNowBar();
      }).catch(function(){ alert('Network error — the restart was not sent.'); });
    }
    function karStop(){
      var btn = document.getElementById('kar-stop-btn');
      btn.disabled = true; btn.textContent = '…';
      var fd = new FormData(); fd.append('form_type', 'karaoke_stop'); fd.append('mac', karMac());
      fetch(KAR_API, {method:'POST', body: fd}).then(function(r){ return r.json(); }).then(function(d){
        btn.disabled = false; btn.textContent = '⏹ Stop';
        if (!d.ok) { alert('Could not send the stop — your session may have expired; reload and sign in again.'); return; }
        // Clear the now-playing highlight — the Mac silences both players within ~3 seconds.
        karNowPlaying = null;
        karNowSave();
        karNowBar();
        var listEl = document.getElementById('kar-list');
        var st = listEl.scrollTop;
        karRender();
        listEl.scrollTop = st;
      }).catch(function(){
        btn.disabled = false; btn.textContent = '⏹ Stop';
        alert('Network error — the stop was not sent.');
      });
    }
    function karSavedPitch(song){
      if (Object.prototype.hasOwnProperty.call(KAR_PITCH, song)) return KAR_PITCH[song];
      var fp = karFnPitch(song);
      return fp === null ? 0 : fp;
    }
    function karStylePitchBox(inp, song){
      var ovr = Object.prototype.hasOwnProperty.call(KAR_PITCH, song);
      var grp = inp.closest ? inp.closest('.kar-pgrp') : inp.parentElement;
      if (grp) { grp.classList.remove('is-temp'); grp.classList.toggle('is-saved', ovr); }
      inp.style.color = ovr ? '#D2AD6C' : '#94a3b8';
      karStyleReset(inp);
    }
    // The ⟲ only means something when there is a pitch to come back FROM. At 0 there is nothing
    // to reset, so it is dimmed and does nothing — otherwise it drew a "temporary" border round a
    // number that was not going to change (the owner spotted this, 2026-09-10).
    function karStyleReset(inp){
      var rb = inp.parentElement ? inp.parentElement.querySelector('.kar-reset') : null;
      if (!rb) return;
      var v = parseInt(inp.value, 10);
      var idle = (isNaN(v) || v === 0) && inp.dataset.temp !== '1';
      rb.classList.toggle('is-idle', idle);
      rb.title = idle
        ? 'Already at the original key — nothing to reset'
        : 'Guest singer: drops the pitch to 0 for the NEXT PLAY ONLY — your saved pitch comes back by itself afterwards';
    }
    // Delete confirmation: a small popover NEXT TO the clicked ✕, at the same level —
    // not the browser's confirm() box, which always lands top-center far from the row.
    var karDelSong = null;
    function karDelShow(btn, song){
      karDelSong = song;
      document.getElementById('kar-del-song').textContent = song.replace(/\.[a-z0-9]{2,4}$/i, '');
      var pop = document.getElementById('kar-del-pop');
      pop.style.display = 'block';
      var r = btn.getBoundingClientRect();
      var pw = pop.offsetWidth, ph = pop.offsetHeight;
      // The ✕ sits in the control block on the LEFT, so open to its RIGHT (over the song name),
      // vertically centered on the button; fall back to the left if there's no room.
      var left = r.right + 10;
      if (left + pw > window.innerWidth - 8) left = Math.max(8, r.left - pw - 10);
      var top = r.top + r.height / 2 - ph / 2;
      if (top < 8) top = 8;
      if (top + ph > window.innerHeight - 8) top = window.innerHeight - ph - 8;
      pop.style.left = left + 'px';
      pop.style.top = top + 'px';
    }
    function karDelHide(){
      karDelSong = null;
      document.getElementById('kar-del-pop').style.display = 'none';
    }
    // Close the popover on any outside click, and if the list scrolls under it.
    document.addEventListener('click', function(ev){
      var pop = document.getElementById('kar-del-pop');
      if (pop.style.display === 'none') return;
      if (pop.contains(ev.target)) return;
      if (ev.target.closest && ev.target.closest('.kar-del')) return;
      karDelHide();
    });
    document.getElementById('kar-list').addEventListener('scroll', function(){
      if (karDelSong !== null) karDelHide();
    });
    function karDelDo(){
      var songD = karDelSong;
      karDelHide();
      if (!songD) return;
      var fdD = new FormData();
      fdD.append('form_type', 'karaoke_delete');
      fdD.append('song', songD);
      fetch(KAR_API, {method:'POST', body: fdD}).then(function(r){ return r.json(); }).then(function(d){
        if (!d.ok) { alert('Not removed' + (d.error ? ': ' + d.error : '') + '.'); return; }
        var ixDb = KAR_DATA.db.indexOf(songD);
        if (ixDb !== -1) KAR_DATA.db.splice(ixDb, 1);
        var ixNw = KAR_DATA.new.indexOf(songD);
        if (ixNw !== -1) KAR_DATA.new.splice(ixNw, 1);
        Object.keys(KAR_BEST_BY).forEach(function(p){
          var a = KAR_BEST_BY[p]; var ix = a.indexOf(songD);
          if (ix !== -1) a.splice(ix, 1);
        });
        delete KAR_PITCH[songD];
        karRebuildBest();
        karRender();
      }).catch(function(){ alert('Network error — the removal was not sent.'); });
    }
    // One delegated listener for Play and Reset — rows themselves carry no handlers (People-tab DOM lesson).
    document.getElementById('kar-list').addEventListener('click', function(ev){
      var src = KAR_DATA[karRenderedView] || [];
      // ➕: add this song to the Up Next singing queue for the selected person.
      var qb = ev.target.closest ? ev.target.closest('.kar-q-add') : null;
      if (qb) {
        var songQ = src[parseInt(qb.getAttribute('data-i'), 10)];
        if (!songQ) return;
        var inpQ = qb.parentElement.querySelector('.kar-pitch');
        var pv = inpQ ? parseInt(inpQ.value, 10) : 0;
        if (isNaN(pv)) pv = 0;
        var fdQ = new FormData();
        fdQ.append('form_type', 'karaoke_q_add');
        fdQ.append('singer', karWho);
        fdQ.append('song', songQ);
        fdQ.append('pitch', String(pv));
        qb.textContent = '…';
        karQPost(fdQ).then(function(d){
          if (!d.ok) { qb.textContent = '➕'; alert('Not added' + (d.error ? ': ' + d.error : '') + '.'); return; }
          qb.textContent = '✓'; qb.style.color = '#6ee7b7';
          setTimeout(function(){ qb.textContent = '➕'; qb.style.color = '#93c5fd'; }, 1500);
        }).catch(function(){ qb.textContent = '➕'; alert('Network error — the request was not added.'); });
        return;
      }
      // ⭐ / ☆: add or remove this song on the selected person's Best list.
      var stb = ev.target.closest ? ev.target.closest('.kar-star') : null;
      if (stb) {
        var songS = src[parseInt(stb.getAttribute('data-i'), 10)];
        if (!songS) return;
        var nameS = songS.replace(/\.[a-z0-9]{2,4}$/i,'');
        var wantS = !KAR_BEST_SET[nameS];
        var fdS = new FormData();
        fdS.append('form_type', 'karaoke_best_toggle');
        fdS.append('song', songS);
        fdS.append('person', karWho);
        fdS.append('want', wantS ? '1' : '0');
        fetch(KAR_API, {method:'POST', body: fdS}).then(function(r){ return r.json(); }).then(function(d){
          if (!d.ok) { alert('Not saved' + (d.error ? ': ' + d.error : '') + '. Your session may have expired — reload and sign in again.'); return; }
          var aS = KAR_BEST_BY[karWho] || (KAR_BEST_BY[karWho] = []);
          var ixS = aS.indexOf(songS);
          if (wantS && ixS === -1) aS.push(songS);
          if (!wantS && ixS !== -1) aS.splice(ixS, 1);
          karRebuildBest();
          var listElS = document.getElementById('kar-list');
          var stS = listElS.scrollTop;
          karRender();
          listElS.scrollTop = stS;
        }).catch(function(){ alert('Network error — the star was not saved.'); });
        return;
      }
      var rb = ev.target.closest ? ev.target.closest('.kar-reset') : null;
      if (rb) {
        // Reset = set the Pitch box to 0 for the NEXT play only. Nothing plays, nothing is saved.
        var song0 = src[parseInt(rb.getAttribute('data-i'), 10)];
        var inp0 = rb.parentElement.querySelector('.kar-pitch');
        if (!song0 || !inp0) return;
        var cur0 = parseInt(inp0.value, 10);
        if (isNaN(cur0) || cur0 === 0) return;   // already the original key — nothing to reset
        inp0.value = 0;
        inp0.dataset.temp = '1';
        var grp0 = inp0.closest ? inp0.closest('.kar-pgrp') : inp0.parentElement;
        if (grp0) { grp0.classList.remove('is-saved'); grp0.classList.add('is-temp'); }
        inp0.style.color = '#60A5FA';
        inp0.title = 'Temporary 0 for a guest — after Play, this goes back to the saved pitch (' + karSavedPitch(song0) + ')';
        karStyleReset(inp0);
        return;
      }
      var dl = ev.target.closest ? ev.target.closest('.kar-del') : null;
      if (dl) {
        var songD = src[parseInt(dl.getAttribute('data-i'), 10)];
        if (!songD) return;
        karDelShow(dl, songD);
        return;
      }
      // Rename by clicking the song's own name — it turns into a text box in place.
      // (Replaced a separate ✎ column and a browser prompt(), 2026-09-10.)
      var nm = ev.target.closest ? ev.target.closest('.kar-name') : null;
      if (nm) {
        if (nm.querySelector('input')) return;              // already editing
        var songR = src[parseInt(nm.getAttribute('data-i'), 10)];
        if (!songR) return;
        var extM = songR.match(/\.[a-z0-9]{2,4}$/i);
        var ext = extM ? extM[0] : '';
        var stemOld = ext ? songR.slice(0, -ext.length) : songR;
        var prevHtml = nm.innerHTML;
        var box = document.createElement('input');
        box.type = 'text'; box.value = stemOld;
        box.style.cssText = 'width:100%;box-sizing:border-box;background:#0f172a;border:1px solid #60A5FA;'
          + 'border-radius:6px;color:#e2e8f0;font-size:13px;font-family:inherit;padding:3px 7px';
        box.title = 'Enter to save · Esc to cancel. The extension (' + (ext || 'none') + ') is kept.';
        nm.textContent = ''; nm.appendChild(box);
        box.focus(); box.select();
        var settled = false;
        function finish(save) {
          if (settled) return; settled = true;
          var stemNew = box.value.trim();
          if (!save || stemNew === '' || stemNew === stemOld) { nm.innerHTML = prevHtml; return; }
          nm.textContent = stemNew + ' …';
          var fdR = new FormData();
          fdR.append('form_type', 'karaoke_rename');
          fdR.append('song', songR);
          fdR.append('new_stem', stemNew);
          fetch(KAR_API, {method:'POST', body: fdR}).then(function(r){ return r.json(); }).then(function(d){
            if (!d.ok) { nm.innerHTML = prevHtml; alert('Not renamed' + (d.error ? ': ' + d.error : '') + '.'); return; }
            // Optimistic update so the tab shows the new name right away; the Mac renames the
            // real file and refreshes the server catalog within ~15 seconds.
            var ixDbR = KAR_DATA.db.indexOf(songR);
            if (ixDbR !== -1) KAR_DATA.db[ixDbR] = d.new_name;
            var ixNwR = KAR_DATA.new.indexOf(songR);
            if (ixNwR !== -1) KAR_DATA.new[ixNwR] = d.new_name;
            Object.keys(KAR_BEST_BY).forEach(function(p){
              var a = KAR_BEST_BY[p]; var ix = a.indexOf(songR);
              if (ix !== -1) a[ix] = d.new_name;
            });
            if (Object.prototype.hasOwnProperty.call(KAR_PITCH, songR)) {
              KAR_PITCH[d.new_name] = KAR_PITCH[songR]; delete KAR_PITCH[songR];
            }
            karRebuildBest();
            karRender();
          }).catch(function(){ nm.innerHTML = prevHtml; alert('Network error — the rename was not sent.'); });
        }
        box.addEventListener('blur', function(){ finish(true); });
        box.addEventListener('keydown', function(e){
          e.stopPropagation();
          if (e.key === 'Enter') { e.preventDefault(); finish(true); }
          else if (e.key === 'Escape') { e.preventDefault(); finish(false); }
        });
        return;
      }
      var b = ev.target.closest ? ev.target.closest('.kar-play') : null;
      if (!b || b.disabled) return;
      var song = src[parseInt(b.getAttribute('data-i'), 10)];
      if (!song) return;
      var player = b.getAttribute('data-player') || 'qmidi';
      var lbl = player === 'mpv' ? '▶ casAI' : '▶ QMidi';
      // Play ALWAYS plays the number currently showing in the Pitch box.
      var inp = b.parentElement.querySelector('.kar-pitch');
      var boxVal = inp ? parseInt(inp.value, 10) : NaN;
      var pitch = isNaN(boxVal) ? karSavedPitch(song) : Math.max(-12, Math.min(12, boxVal));
      var temp = inp && inp.dataset.temp === '1';
      b.disabled = true; b.textContent = '…';
      var fd = new FormData();
      fd.append('form_type', 'karaoke_play'); fd.append('mac', karMac());
      fd.append('song', song);
      fd.append('pitch_once', String(pitch));
      fd.append('player', player);
      fetch(KAR_API, {method:'POST', body: fd}).then(function(r){ return r.json(); }).then(function(d){
        if (d.ok) {
          // Light up the playing row (gold row, red ♪ on the button that was clicked) —
          // it stays lit until another song is played. Re-render rebuilds everything from
          // state, which also brings a guest-reset pitch box back to the saved pitch;
          // scroll position is preserved.
          karNowPlaying = song;
          karNowPlayingPlayer = player;
          karLivePitch = pitch;
          karLiveTempo = 100;
          karNowSave();
          document.getElementById('kar-tempo-val').textContent = '100%';
          karNowBar();
          var listEl = document.getElementById('kar-list');
          var st = listEl.scrollTop;
          karRender();
          listEl.scrollTop = st;
        } else {
          b.textContent = lbl; b.style.color = '#EF4444'; b.disabled = false;
          alert('Could not queue the song' + (d.error ? ': ' + d.error : '') + '. Your session may have expired — reload and sign in again.');
        }
      }).catch(function(){
        b.textContent = lbl; b.style.color = '#EF4444'; b.disabled = false;
        alert('Network error — the play request was not sent. Reload the page and try again.');
      });
    });
    // − / + pitch steppers: nudge one semitone, then save through the exact same
    // path as typing (a dispatched change event) so behavior can never drift.
    document.getElementById('kar-list').addEventListener('click', function(ev){
      var b = ev.target.closest ? ev.target.closest('.kar-pstep') : null;
      if (!b) return;
      // ⚠ The guest-reset button sits inside this same group and once carried .kar-pstep for its
      // looks — which made THIS listener fire too, stepping the pitch down and SAVING it. Styling
      // and behaviour must never share a class here.
      if (b.classList.contains('kar-reset')) return;
      var inp = b.parentElement.querySelector('.kar-pitch');
      if (!inp) return;
      var v = parseInt(inp.value, 10);
      if (isNaN(v)) v = 0;
      v += b.classList.contains('kar-pup') ? 1 : -1;
      if (v > 12) v = 12;
      if (v < -12) v = -12;
      inp.value = v;
      inp.dispatchEvent(new Event('change', { bubbles: true }));
    });
    // Pitch box: type a number, it saves on its own (delegated — rows carry no handlers).
    document.getElementById('kar-list').addEventListener('change', function(ev){
      var inp = ev.target.closest ? ev.target.closest('.kar-pitch') : null;
      if (!inp) return;
      var src = KAR_DATA[karRenderedView] || [];
      var song = src[parseInt(inp.getAttribute('data-i'), 10)];
      if (!song) return;
      delete inp.dataset.temp;  // typing a number is a real change — it saves, it is not the guest reset
      var val = inp.value.trim();
      if (val !== '' && (isNaN(parseInt(val, 10)) || parseInt(val, 10) < -12 || parseInt(val, 10) > 12)) {
        alert('Pitch must be a whole number between -12 and +12.');
        return;
      }
      var fd = new FormData();
      fd.append('form_type', 'karaoke_set_pitch');
      fd.append('song', song);
      fd.append('pitch', val);
      inp.disabled = true;
      fetch(KAR_API, {method:'POST', body: fd}).then(function(r){ return r.json(); }).then(function(d){
        inp.disabled = false;
        if (!d.ok) { alert('The pitch was NOT saved' + (d.error ? ': ' + d.error : '') + '. Your session may have expired — reload and sign in again.'); return; }
        if (val === '' || !d.stored) {
          // cleared, or the typed value equals the song's own default — no override kept, box stays grey
          delete KAR_PITCH[song];
          if (val === '') {
            var fp = karFnPitch(song);
            inp.value = (fp === null ? 0 : fp);
          }
          karStylePitchBox(inp, song);
        } else {
          KAR_PITCH[song] = parseInt(val, 10);
          karStylePitchBox(inp, song);
        }
      }).catch(function(){ inp.disabled = false; alert('Network error — the pitch was NOT saved.'); });
    });
    // ---- YouTube download queue ----
    var karDlTimer = null;
    // YouTube serves Cross-Origin-Opener-Policy, which SEVERS the tab from this page
    // the moment youtube.com loads — window handles go dead, the window name is
    // cleared, and no script can focus or reuse that tab again. Verified live
    // 2026-09-06 (curl: coop same-origin-allow-popups); the earlier named-window
    // reuse could never work. So: open ONCE per session (sessionStorage survives a
    // reload of this page), and afterwards the button points him at the tab instead
    // of piling up copies. His catch: "still creating a new YouTube tab every time."
    function karYtGo(){
      var p = document.getElementById('kar-dl-panel');
      if (p.style.display === 'none') karDlToggle();
      var opened = false;
      try { opened = sessionStorage.getItem('kar_yt_opened') === '1'; } catch (e) {}
      if (!opened) {
        window.open('https://www.youtube.com', '_blank');
        try { sessionStorage.setItem('kar_yt_opened', '1'); } catch (e) {}
        karYtHint(false);
      } else {
        karYtHint(true);
      }
    }
    function karYtFresh(){
      // He closed the YouTube tab and wants a new one — explicit, so no duplicate risk.
      window.open('https://www.youtube.com', '_blank');
      karYtHint(false);
      return false;
    }
    function karYtHint(already){
      var el = document.getElementById('kar-yt-hint');
      if (!el) return;
      el.style.display = '';
      el.innerHTML = (already
        ? '<b style="color:#D2AD6C">YouTube is already open in another tab</b> — click the <b>YouTube tab at the top of the browser</b> (or press ⌘ + Tab keys) to go back to it. Your search is still there. '
        : 'YouTube opened in the tab next to this one — go back and forth by <b>clicking the tabs at the top of the browser</b>. ')
        + 'Closed it? <a href="#" onclick="return karYtFresh()" style="color:#60A5FA">Open a fresh YouTube tab</a>.';
    }
    // ▶ QMidi column: HIDDEN by default since 2026-09-06 (the owner moved to the casAI
    // player) — hidden, never deleted. The Guide's checkbox brings it back any time;
    // the choice is remembered per browser (localStorage kar_show_qmidi).
    var karShowQmidi = false;
    try { karShowQmidi = localStorage.getItem('kar_show_qmidi') === '1'; } catch(e){}
    function karApplyQmidiVis(){
      var h = document.getElementById('kar-h-qmidi');
      if (h) h.style.display = karShowQmidi ? '' : 'none';
      var hc = document.getElementById('kar-h-casai');
      if (hc) hc.innerHTML = karShowQmidi ? 'Play<br>casAI' : 'Play';
      var cb = document.getElementById('kar-qmidi-cb');
      if (cb) cb.checked = karShowQmidi;
      // The Up Next player picker only means anything while BOTH players are on screen
      // (the owner, 2026-09-07: "that was when we had two choices — now we only have casAI").
      // Tied to the same switch as the column, so it returns by itself if QMidi ever does.
      var ps = document.getElementById('kar-q-player');
      if (ps) {
        ps.style.display = karShowQmidi ? '' : 'none';
        if (!karShowQmidi) ps.value = 'mpv';
      }
    }
    function karQmidiToggle(cb){
      karShowQmidi = cb.checked;
      try { localStorage.setItem('kar_show_qmidi', karShowQmidi ? '1' : ''); } catch(e){}
      karApplyQmidiVis();
      karRender();
    }
    // One panel at a time (the owner, 2026-09-07: Downloads + Up Next + QR all open at
    // once buried the song list — "I find this all very confusing"). Opening any panel
    // closes the others; clicking the open one's button just closes it.
    // btn = its header button · col/bd = how it looks at rest · rgb = its own accent,
    // used for the lit fill, border and halo · lit = the bright text colour when open.
    var KAR_PANELS = {
      'kar-guide-panel': { btn:'kar-guide-btn', col:'#94a3b8', bd:'#334155',               rgb:'110,231,183', bg:'#1f3d35', lit:'#a7f3d0' },
      'kar-dl-panel':    { btn:'kar-dl-btn',    col:'#94a3b8', bd:'#334155',               rgb:'96,165,250',  bg:'#22344f', lit:'#bfdbfe' },
      'kar-q-panel':     { btn:'kar-q-btn',     col:'#D2AD6C', bd:'rgba(210,173,108,.45)', rgb:'210,173,108', bg:'#3b3324', lit:'#f3d9a4' },
      'kar-qr-panel':    { btn:'kar-qr-btn',    col:'#c084fc', bd:'rgba(192,132,252,.45)', rgb:'192,132,252', bg:'#362a4d', lit:'#e9d5ff' }
    };
    function karBtnLight(pid, on){
      var p = KAR_PANELS[pid], b = p && document.getElementById(p.btn);
      if (!b) return;
      b.style.background  = on ? p.bg : '#1e293b';
      b.style.borderColor = on ? 'rgb(' + p.rgb + ')'      : p.bd;
      b.style.color       = on ? p.lit                     : p.col;
      b.style.boxShadow   = on ? '0 0 0 3px rgba(' + p.rgb + ',.20)' : 'none';
    }
    // ── Per-panel "? How it works" blocks ───────────────────────────────────────────────
    // One shared mechanism so every panel behaves the same way (the owner wants this on the
    // others too). Shown by DEFAULT — a fresh machine teaches whoever sits down at it —
    // and hidden for good on that computer once its owner has read it and pressed ?.
    // OFF by default (the owner, 2026-09-08: "the instruction should be only if we need
    // it"). The Guide covers all of this properly now, so these are a reminder beside your
    // hands, not a lecture you have to scroll past every time you open a panel.
    function karHelpOn(key){
      try { return localStorage.getItem('kar_help_' + key) === '1'; } catch(e) { return false; }
    }
    // Which Guide card each floating "?" belongs to. The words live in the Guide and are
    // BORROWED from it here — one copy, so the two can never drift apart the way two
    // hand-kept copies of the same paragraph always eventually do.
    var KAR_HELP_SECTION = { q: 'upnext', dl: 'downloads', qr: 'guestqr' };
    function karHelpApply(key){
      var on  = karHelpOn(key);
      var box = document.getElementById('kar-help-' + key);
      if (box) {
        if (on && !box.querySelector('.kar-help-body')) {
          var src = document.querySelector('#kar-gs-' + KAR_HELP_SECTION[key] + ' .kar-gs-body');
          if (src) {
            var b = document.createElement('div');
            b.className = 'kar-help-body';
            b.style.display = 'grid';
            b.style.gap = '9px';
            b.innerHTML = src.innerHTML;
            box.appendChild(b);
          }
        }
        box.style.display = on ? '' : 'none';
        if (on) karHelpPlace(box);
      }
      var btn = document.getElementById('kar-helpbtn-' + key);
      if (btn) {
        btn.style.color       = on ? '#D2AD6C' : '#94a3b8';
        btn.style.borderColor = on ? '#D2AD6C' : '#334155';
        btn.textContent       = on ? '? Hide this' : '? How it works';
      }
    }
    // Put the card where the SONGS start, not under the header. Measured live because the
    // list moves: an open panel pushes it down, and the header wraps on a narrow window.
    // Clamped so a very tall panel can never push the card off the bottom of the screen.
    function karHelpPlace(box){
      if (window.innerWidth <= 900) { box.style.top = ''; box.style.maxHeight = ''; return; }
      var list = document.getElementById('kar-list');
      var vh   = window.innerHeight;
      var top  = list ? Math.round(list.getBoundingClientRect().top) : 230;
      if (top < 120)      top = 120;
      if (top > vh - 220) top = vh - 220;
      box.style.top = top + 'px';
      box.style.maxHeight = (vh - top - 16) + 'px';
    }
    function karHelpToggle(key){
      var turningOn = !karHelpOn(key);
      try { localStorage.setItem('kar_help_' + key, turningOn ? '1' : '0'); } catch(e){}
      // Only one floating card at a time, or they stack on top of each other in the corner.
      if (turningOn) ['dl','q','qr'].forEach(function(k){
        if (k !== key) { try { localStorage.setItem('kar_help_' + k, '0'); } catch(e){} karHelpApply(k); }
      });
      karHelpApply(key);
    }
    // ── "Choose the karaoke songs folder…" (Guide, step 2) ──────────────────────────────
    // The picker runs ON THE MAC, not here: a browser is never given a real filesystem
    // path (only a folder's name), so the page asks the watcher to show a native macOS
    // chooser and then waits for the answer. Same queue every other Mac action uses.
    function karPickPost(body){
      return fetch(KAR_API, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body })
        .then(function(r){ return r.json(); });
    }
    function karPickDone(ok, text, path){
      var btn = document.getElementById('kar-pick-btn');
      btn.disabled = false;
      btn.textContent = '📁 Choose the karaoke songs folder…';
      var msg = document.getElementById('kar-pick-msg');
      msg.innerHTML = ok
        ? '<b style="color:#6ee7b7">✅ Saved.</b> Using now: <b id="kar-pick-cur" style="color:#6ee7b7">' + karEsc(path) + '</b>'
          + (text ? '<br><span style="color:#64748b">' + karEsc(text) + '</span>' : '')
        : '<span style="color:#f87171">' + karEsc(text) + '</span>';
    }
    // Ask the Mac to do one job and wait for its answer. Shared by both Guide buttons
    // (choose the songs folder · check the Terminal step worked) — same queue, same poll.
    function karMacAsk(task, onWorking, onDone){
      karPickPost('form_type=karaoke_pick_folder&mode=start&task=' + task + '&mac=' + encodeURIComponent(karMac())).then(function(d){
        if (!d.ok) throw new Error('start failed');
        var tries = 0;
        var poll = setInterval(function(){
          if (++tries > 90) {   // 3 minutes, then stop asking
            clearInterval(poll);
            onDone(false, 'No answer from the Mac. Is it switched on, awake, and running casAI karaoke?');
            return;
          }
          karPickPost('form_type=karaoke_pick_folder&mode=check&task=' + task + '&id=' + d.id).then(function(s){
            if (!s.ok || s.status === 'Pending' || s.status === 'Claimed') return;
            // 'Working' = the Mac has it in hand and is waiting for a person.
            if (s.status === 'Working') { if (onWorking) onWorking(); return; }
            clearInterval(poll);
            onDone(s.status === 'Played', s.note || '');
          }).catch(function(){});
        }, 2000);
      }).catch(function(){ onDone(false, 'Network error — the Mac was not asked.'); });
    }
    function karPickFolder(){
      var btn = document.getElementById('kar-pick-btn');
      var msg = document.getElementById('kar-pick-msg');
      btn.disabled = true;
      btn.textContent = '📁 Waiting…';
      msg.innerHTML = '<span style="color:#D2AD6C">Go to the Mac — a folder chooser is opening there. Pick your songs folder and press Choose.</span>';
      karMacAsk('folder',
        function(){ msg.innerHTML = '<span style="color:#6ee7b7">The chooser is open on the Mac now — pick your songs folder and press Choose.</span>'; },
        function(ok, note){
          if (ok) { var bits = note.split(' — '); karPickDone(true, bits.slice(1).join(' — '), bits[0]); }
          else    { karPickDone(false, note || 'The songs folder was not changed.'); }
        });
    }
    // Four states, and every one of them says so out loud: working · updated ·
    // nothing to update · not updated. Anything quieter reads as "nothing happened".
    // The Guide opens one section at a time. Six labels beat four screens of prose —
    // the owner, reading it: "very busy, unorganized… maybe multiple boxes, each box clearly
    // says what it's for."
    var karGuideOpenKey = null;
    function karGuideOpen(key){
      var same = (karGuideOpenKey === key);
      karGuideOpenKey = same ? null : key;
      var cards = document.querySelectorAll('#kar-guide-cards button');
      for (var i = 0; i < cards.length; i++) {
        var on = !same && cards[i].id === 'kar-gc-' + key;
        cards[i].style.borderColor = on ? '#D2AD6C' : '#334155';
        cards[i].style.background  = on ? 'rgba(210,173,108,.10)' : '#1a2130';
      }
      var body = document.getElementById('kar-guide-body');
      var secs = document.querySelectorAll('.kar-gs');
      for (var j = 0; j < secs.length; j++) secs[j].style.display = 'none';
      if (same) { body.style.display = 'none'; return; }
      var sec = document.getElementById('kar-gs-' + key);
      if (sec) { sec.style.display = 'block'; body.style.display = 'block'; }
    }
    function karUpdState(kind, html){
      var box = document.getElementById('kar-upd-state');
      var skin = {
        working: ['rgba(210,173,108,.12)', '#D2AD6C', '#f0d9ac'],
        done:    ['rgba(16,185,129,.14)',  '#16a34a', '#6ee7b7'],
        same:    ['rgba(96,165,250,.10)',  '#60A5FA', '#93c5fd'],
        failed:  ['rgba(239,68,68,.12)',   '#ef4444', '#fca5a5']
      }[kind];
      box.style.display = 'block';
      box.style.background  = skin[0];
      box.style.border      = '1px solid ' + skin[1];
      box.style.color       = skin[2];
      box.innerHTML = html;
    }
    function karUpdate(){
      // Fetching and replacing the program is a real change to this Mac, so it asks first.
      if (!confirm('Fetch the newest karaoke?\n\nOnly the program is replaced. Your songs, your settings, the lists and every saved key stay exactly as they are.')) return;
      var btn = document.getElementById('kar-upd-btn');
      btn.disabled = true;
      btn.style.opacity = .6;
      btn.textContent = '⏳ Working…';
      karUpdState('working', '⏳ <b>Fetching the newest Cantoria…</b><div style="font-weight:600;font-size:12.5px;opacity:.85;margin-top:2px">This usually takes a few seconds. You will see the answer right here — leave this open.</div>');
      karMacAsk('update', null, function(ok, note){
        btn.disabled = false;
        btn.style.opacity = 1;
        btn.textContent = '⬆︎ Update the karaoke';
        if (!ok) {
          karUpdState('failed', '✕ <b>Not updated.</b><div style="font-weight:600;font-size:12.5px;margin-top:2px">' + karEsc(note) + '</div>');
          return;
        }
        // "Already up to date" is a real answer, not a non-event — it is the one people
        // will see most often, so it gets said as plainly as the others.
        if (note.indexOf('Already up to date') === 0) {
          karUpdState('same', '✔︎ <b>Nothing to update.</b><div style="font-weight:600;font-size:12.5px;margin-top:2px">This Mac already has the newest karaoke. ' + karEsc(note.replace(/^Already up to date \(/, '').replace(/\)\.?\s*$/, '')) + '</div>');
          return;
        }
        var m = note.match(/karaoke (\S+?)\s*\(was (\S+?)\)/);
        var ver = m ? m[1] : '', was = m ? m[2] : '';
        var vEl = document.getElementById('kar-upd-ver');
        if (vEl && ver) vEl.textContent = ver;
        karUpdState('done', '✅ <b>Updated.</b><div style="font-weight:600;font-size:12.5px;margin-top:2px">'
          + (ver ? 'Now on <b>' + karEsc(ver) + '</b>' + (was ? ' — was ' + karEsc(was) : '') + '. ' : karEsc(note) + ' ')
          + '<a href="#" onclick="location.reload();return false;" style="color:#93c5fd">Reload the page to start using it →</a></div>');
      });
    }
    function karCheckTools(){
      var btn = document.getElementById('kar-tools-btn');
      var msg = document.getElementById('kar-tools-msg');
      btn.disabled = true;
      btn.textContent = '⏳ Asking the Mac…';
      msg.innerHTML = '<span style="color:#D2AD6C">Checking what is installed on the Mac…</span>';
      karMacAsk('tools', null, function(ok, note){
        btn.disabled = false;
        btn.textContent = '✅ Check it worked';
        msg.innerHTML = ok
          ? '<span style="color:#6ee7b7"><b>✅ All set.</b> ' + karEsc(note) + '</span>'
          : '<span style="color:#f87171"><b>Not ready yet.</b> ' + karEsc(note) + '</span>';
      });
    }
    function karPanelClose(){
      // a help card belongs to its panel — it should not outlive it on screen
      ['dl','q','qr'].forEach(function(k){ try { localStorage.setItem('kar_help_' + k, '0'); } catch(e){} karHelpApply(k); });
      Object.keys(KAR_PANELS).forEach(function(pid){
        document.getElementById(pid).style.display = 'none';
        karBtnLight(pid, false);
      });
      if (karDlTimer) { clearTimeout(karDlTimer); karDlTimer = null; }
    }
    // Esc closes whatever panel is open — as long as you're not typing in a box.
    document.addEventListener('keydown', function(ev){
      if (ev.key !== 'Escape') return;
      var t = ev.target && ev.target.tagName;
      if (t === 'INPUT' || t === 'TEXTAREA' || t === 'SELECT') return;
      karPanelClose();
    });
    function karPanelShow(id){
      var wasOpen = document.getElementById(id).style.display !== 'none';
      karPanelClose();
      if (wasOpen) return false;
      document.getElementById(id).style.display = '';
      karBtnLight(id, true);
      return true;
    }
    function karGuideToggle(){ karPanelShow('kar-guide-panel'); }
    function karDlToggle(){ if (karPanelShow('kar-dl-panel')) karDlRefresh(); }
    function karDlStatusStyle(st){
      if (st === 'Done') return 'color:#10B981';
      if (st === 'Error') return 'color:#EF4444';
      if (st === 'Downloading') return 'color:#D2AD6C';
      if (st === 'Pending') return 'color:#60A5FA';
      return 'color:#94a3b8'; // Queued
    }
    function karDlRender(rows){
      var el = document.getElementById('kar-dl-list');
      if (!rows.length) {
        // Must not imply "nothing happened" — a song that arrived leaves this panel within
        // 10 minutes, and the old wording ("Nothing in the list yet") read as a failure
        // (the owner, 2026-09-07: "he didn't download it... the link disappeared" — it had in
        // fact downloaded fine 20 seconds after he pasted it).
        el.innerHTML = '<p style="color:#64748b;font-size:12.5px;margin:4px 0 0">Nothing being fetched right now.</p>';
        return;
      }
      var h = rows.map(function(r){
        var name = r.title ? karEsc(r.title) : '<span style="color:#64748b">looking up the title…</span>';
        if (r.requested_by) { name += ' <span style="font-size:11px;color:#c084fc;font-weight:700">· requested by ' + karEsc(r.requested_by) + '</span>'; }
        // A failure's reason is the whole point of the row — show it in red, not grey.
        var noteCol = r.status === 'Error' ? '#f87171'
          : ((r.note.indexOf('already') !== -1 || r.note.indexOf('own') !== -1) ? '#D2AD6C' : '#64748b');
        var note = r.note ? '<div style="font-size:11px;color:' + noteCol + ';margin-top:1px">' + karEsc(r.note) + '</div>' : '';
        var canRemove = (r.status === 'Queued' || r.status === 'Error');
        return '<div style="display:flex;gap:10px;align-items:flex-start;padding:6px 0;border-bottom:1px solid #1e293b">'
          + '<span style="flex:0 0 106px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;' + karDlStatusStyle(r.status) + '">'
          + (r.status === 'Downloading' ? '⬇ Downloading' : r.status === 'Done' ? '✓ Done' : r.status === 'Error' ? '✕ Didn\'t work' : r.status) + '</span>'
          + '<div style="flex:1;min-width:0"><div style="font-size:13px;color:#e2e8f0;word-break:break-word">' + name + '</div>' + note + '</div>'
          + (canRemove ? '<button type="button" onclick="karDlRemove(' + r.id + ')" title="Remove this line from the list (the URL only — no file is touched)" style="font-family:inherit;flex:0 0 auto;background:none;border:none;color:#94a3b8;cursor:pointer;font-size:13px;padding:0 2px">✕</button>' : '')
          + '</div>';
      }).join('');
      el.innerHTML = h;
    }
    function karDlRefresh(){
      if (karDlTimer) { clearTimeout(karDlTimer); karDlTimer = null; }
      var fd = new FormData(); fd.append('form_type', 'karaoke_dl_state');
      fetch(KAR_API, {method:'POST', body: fd}).then(function(r){ return r.json(); }).then(function(d){
        if (!d.ok) return;
        karDlRender(d.rows);
        var qn = d.rows.filter(function(r){ return r.status === 'Queued'; }).length;
        var sb = document.getElementById('kar-dl-start');
        sb.textContent = qn ? ('⬇ Download ' + qn + (qn === 1 ? ' song' : ' songs')) : '⬇ Download the list';
        // Keep polling while anything is still moving (title lookups, pending/active downloads).
        var busy = d.rows.some(function(r){ return r.status === 'Pending' || r.status === 'Downloading' || (r.status === 'Queued' && !r.title); });
        if (busy && document.getElementById('kar-dl-panel').style.display !== 'none') {
          karDlTimer = setTimeout(karDlRefresh, 4000);
        }
      }).catch(function(){});
    }
    function karDlAdd(){
      var inp = document.getElementById('kar-dl-url');
      var url = inp.value.trim();
      if (!url) return;
      var fd = new FormData();
      fd.append('form_type', 'karaoke_dl_add');
      fd.append('url', url);
      fetch(KAR_API, {method:'POST', body: fd}).then(function(r){ return r.json(); }).then(function(d){
        if (!d.ok) { alert('Not added' + (d.error ? ': ' + d.error : '') + '.'); return; }
        inp.value = '';
        karDlRefresh();
      }).catch(function(){ alert('Network error — the link was not added.'); });
    }
    document.getElementById('kar-dl-url').addEventListener('keydown', function(ev){
      if (ev.key === 'Enter') { ev.preventDefault(); karDlAdd(); }
    });
    function karDlStart(){
      var fd = new FormData(); fd.append('form_type', 'karaoke_dl_start');
      fetch(KAR_API, {method:'POST', body: fd}).then(function(r){ return r.json(); }).then(function(d){
        if (!d.ok) { alert('Could not start' + (d.error ? ': ' + d.error : '') + '.'); return; }
        if (!d.started) { alert('Nothing to download — add a YouTube link first.'); return; }
        karDlRefresh();
      }).catch(function(){ alert('Network error — the download was not started.'); });
    }
    function karDlClear(){
      if (!confirm('Empty the download list?\n\nClears the links still waiting and any that failed. Songs that already arrived are untouched — they stay in your library and under 🆕 New. A download in progress keeps going.')) return;
      var fd = new FormData(); fd.append('form_type', 'karaoke_dl_clear');
      fetch(KAR_API, {method:'POST', body: fd}).then(function(r){ return r.json(); }).then(function(d){
        if (!d.ok) { alert('Could not clear the list.'); return; }
        karDlRefresh();
      }).catch(function(){ alert('Network error — the list was not cleared.'); });
    }
    function karDlRemove(id){
      var fd = new FormData();
      fd.append('form_type', 'karaoke_dl_remove');
      fd.append('id', String(id));
      fetch(KAR_API, {method:'POST', body: fd}).then(function(r){ return r.json(); }).then(function(d){
        if (!d.ok) { alert('Not removed' + (d.error ? ': ' + d.error : '') + '.'); return; }
        karDlRefresh();
      }).catch(function(){ alert('Network error.'); });
    }
    // ===== Up Next singing queue (the party MC queue, 2026-09-06) =====
    var karQ = [];
    var karQSung = {};   // songs sung tonight per person — drives the Fair-turns rotation
    function karQPost(fd){
      return fetch(KAR_API, {method:'POST', body: fd}).then(function(r){ return r.json(); }).then(function(d){
        if (d.queue) { karQ = d.queue; karQSung = d.sung || {}; karQRender(); }
        return d;
      });
    }
    function karQFetch(){
      var fd = new FormData(); fd.append('form_type', 'karaoke_q_state');
      karQPost(fd).catch(function(){});
    }
    function karQToggle(){ if (karPanelShow('kar-q-panel')) karQFetch(); }
    function karQRender(){
      var waiting = karQ.filter(function(e){ return e.status === 'Waiting'; });
      var singing = karQ.filter(function(e){ return e.status === 'Singing'; })[0];
      var cnt = document.getElementById('kar-q-count');
      if (cnt) cnt.textContent = waiting.length;
      var nowEl = document.getElementById('kar-q-now');
      if (nowEl) {
        if (singing) {
          nowEl.style.display = '';
          nowEl.textContent = '🎤 Now singing: ' + singing.singer + ' — '
            + singing.song.replace(/\.[a-z0-9]{2,4}$/i,'') + '  (' + (singing.pitch > 0 ? '+' : '') + singing.pitch + ')';
        } else nowEl.style.display = 'none';
      }
      var out = [];
      for (var i = 0; i < waiting.length; i++) {
        var e = waiting[i];
        out.push('<div style="display:flex;align-items:center;gap:10px;padding:6px 6px;border-top:1px solid #1e293b">'
          + '<span style="flex:0 0 22px;color:#64748b;font-size:12px;font-weight:700">' + (i + 1) + '.</span>'
          + '<span style="flex:0 0 auto;color:#D2AD6C;font-size:13.5px;font-weight:700">' + karEsc(e.singer) + '</span>'
          + '<span style="color:#e2e8f0;font-size:13px">' + karEsc(e.song.replace(/\.[a-z0-9]{2,4}$/i,'')) + '</span>'
          + '<span style="color:#94a3b8;font-size:12px">(' + (e.pitch > 0 ? '+' : '') + e.pitch + ')</span>'
          + '<span style="margin-left:auto;display:flex;gap:4px">'
          + '<button type="button" class="kar-q-up" data-id="' + e.id + '" title="Move up the queue" style="font-family:inherit;background:none;border:1px solid #334155;color:#94a3b8;cursor:pointer;font-size:12px;padding:2px 8px;border-radius:6px">↑</button>'
          + '<button type="button" class="kar-q-dn" data-id="' + e.id + '" title="Move down the queue" style="font-family:inherit;background:none;border:1px solid #334155;color:#94a3b8;cursor:pointer;font-size:12px;padding:2px 8px;border-radius:6px">↓</button>'
          + '<button type="button" class="kar-q-rm" data-id="' + e.id + '" title="Remove this request from the queue" style="font-family:inherit;background:none;border:1px solid #7f1d1d;color:#f87171;cursor:pointer;font-size:12px;padding:2px 8px;border-radius:6px">✕</button>'
          + '</span></div>');
      }
      document.getElementById('kar-q-list').innerHTML = out.length ? out.join('')
        : '<p style="color:#64748b;font-size:12.5px;margin:8px 0 0">The queue is empty — pick a singer in the dropdown and click ➕ on a song.</p>';
    }
    function karQNext(){
      var waiting = karQ.filter(function(e){ return e.status === 'Waiting'; });
      if (!waiting.length) { alert('The queue is empty — add a request first: pick the singer in the dropdown, then click ➕ on a song.'); return; }
      var singingE = karQ.filter(function(e){ return e.status === 'Singing'; })[0];
      var lastSinger = singingE ? singingE.singer : null;
      var pick = waiting[0];
      if (document.getElementById('kar-q-fair').checked) {
        // FULL ROTATION (the owner, 2026-09-06): nobody sings a 2nd song until everyone
        // waiting has sung their 1st — and nobody a 3rd until everyone their 2nd.
        // Pick = whoever has sung the LEAST tonight; ties go to queue order, except that
        // the person who just sang goes last among equals (no back-to-back on a tie).
        var bestCnt = null, bestJust = null;
        for (var i = 0; i < waiting.length; i++) {
          var cnt = karQSung[waiting[i].singer] || 0;
          var just = (lastSinger && waiting[i].singer === lastSinger) ? 1 : 0;
          if (bestCnt === null || cnt < bestCnt || (cnt === bestCnt && just < bestJust)) {
            pick = waiting[i]; bestCnt = cnt; bestJust = just;
          }
        }
      }
      var player = document.getElementById('kar-q-player').value === 'mpv' ? 'mpv' : 'qmidi';
      var fd = new FormData();
      fd.append('form_type', 'karaoke_q_play'); fd.append('mac', karMac());
      fd.append('id', String(pick.id));
      fd.append('player', player);
      karQPost(fd).then(function(d){
        if (!d.ok) { alert('Could not start the next singer' + (d.error ? ': ' + d.error : '') + '.'); return; }
        karNowPlaying = pick.song;
        karNowPlayingPlayer = player;
        karLivePitch = pick.pitch;
        karLiveTempo = 100;
        karNowSave();
        var tv = document.getElementById('kar-tempo-val'); if (tv) tv.textContent = '100%';
        karNowBar();
        var listEl = document.getElementById('kar-list');
        var st = listEl.scrollTop;
        karRender();
        listEl.scrollTop = st;
      }).catch(function(){ alert('Network error — the play was not sent.'); });
    }
    function karQClear(){
      if (!confirm('Clear the whole Up Next queue?\n\nOnly the requests list empties — songs, pitches and Best lists are untouched.')) return;
      var fd = new FormData(); fd.append('form_type', 'karaoke_q_clear');
      karQPost(fd).catch(function(){ alert('Network error.'); });
    }
    document.getElementById('kar-q-list').addEventListener('click', function(ev){
      var b = ev.target.closest ? ev.target.closest('button') : null;
      if (!b) return;
      var fd = new FormData();
      if (b.classList.contains('kar-q-rm')) { fd.append('form_type', 'karaoke_q_remove'); }
      else if (b.classList.contains('kar-q-up')) { fd.append('form_type', 'karaoke_q_move'); fd.append('dir', 'up'); }
      else if (b.classList.contains('kar-q-dn')) { fd.append('form_type', 'karaoke_q_move'); fd.append('dir', 'down'); }
      else return;
      fd.append('id', b.getAttribute('data-id'));
      karQPost(fd).catch(function(){ alert('Network error.'); });
    });
    // Guest QR: its own panel behind the 📱 header button (the owner's design, 2026-09-07 —
    // "a small QR icon... you click on that icon, and that opens up that all process").
    var karQrShown = '';
    function karQrToggle(){
      if (karPanelShow('kar-qr-panel') && !karQrShown) karQrLoad('get');
    }
    function karQrRotate(){
      if (!confirm('Issue a NEW guest code?\n\nEvery QR code shown or scanned before will stop working — guests will need to scan the new one.')) return;
      karQrLoad('rotate');
    }
    function karQrLoad(action){
      var fd = new FormData();
      fd.append('form_type', 'karaoke_qr');
      fd.append('action', action);
      fetch(KAR_API, {method:'POST', body: fd}).then(function(r){ return r.json(); }).then(function(d){
        if (!d.ok) { alert('Could not load the guest code' + (d.error ? ': ' + d.error : '') + '.'); return; }
        karQrShown = d.url;
        document.getElementById('kar-qr-url').textContent = d.url;
        var holder = document.getElementById('kar-qr-code');
        holder.innerHTML = '';
        new QRCode(holder, { text: d.url, width: 216, height: 216, correctLevel: QRCode.CorrectLevel.M });
      }).catch(function(){ alert('Network error — could not load the guest code.'); });
    }
    try { var _qp = localStorage.getItem('kar_q_player'); if (_qp) document.getElementById('kar-q-player').value = _qp; } catch(e){}
    // With the QMidi button hidden, ▶ Next singer follows to the casAI player too — and
    // the picker itself is hidden by karApplyQmidiVis(), which runs after this line.
    if (!karShowQmidi) document.getElementById('kar-q-player').value = 'mpv';
    try { if (localStorage.getItem('kar_q_fair') === '1') document.getElementById('kar-q-fair').checked = true; } catch(e){}
    karQFetch();
    setInterval(function(){
      var p = document.getElementById('kar-q-panel');
      if (p.style.display !== 'none' || karQ.length) karQFetch();
    }, 10000);
    // Live activity line (the owner, 2026-09-07): his phone showed download progress while
    // the Mac stayed silent — the host page only knew if the Downloads panel was open.
    // This strip watches on its own, every 12s, no panel needed: guest requests show as
    // they download, and completions stay on screen a couple of minutes.
    var karActPrev = null;   // id -> status from the previous poll (null = first poll, no announcements)
    var karActDone = {};     // id -> {msg, until} — finished lines kept visible ~2 min
    function karActivityPoll(){
      var fd = new FormData(); fd.append('form_type', 'karaoke_dl_state');
      fetch(KAR_API, {method:'POST', body: fd}).then(function(r){ return r.json(); }).then(function(d){
        if (!d.ok) return;
        var lines = [], now = Date.now(), queueChanged = false;
        d.rows.forEach(function(r){
          var name = r.title || 'a song';
          if (r.status === 'Pending' || r.status === 'Downloading' || (r.status === 'Queued' && !r.title)) {
            lines.push(r.requested_by
              ? '🎁 <b>' + karEsc(r.requested_by) + '</b> brought a song — <b>' + karEsc(name) + '</b> is downloading…'
              : '⬇ Downloading <b>' + karEsc(name) + '</b>…');
          }
          if (karActPrev && karActPrev[r.id] && karActPrev[r.id] !== r.status) {
            if (r.status === 'Done') {
              karActDone[r.id] = { until: now + 120000, msg: r.requested_by
                ? '✅ <b>' + karEsc(name) + '</b> is ready — <b>' + karEsc(r.requested_by) + '</b> is in line to sing it. Press ⟳ — you\'ll find it under 🆕 New.'
                : '✅ <b>' + karEsc(name) + '</b> is in the Song Database. Press ⟳ — you\'ll find it under 🆕 New.' };
              if (r.requested_by) queueChanged = true;
            } else if (r.status === 'Error' && r.requested_by) {
              karActDone[r.id] = { until: now + 120000,
                msg: '✕ <b>' + karEsc(r.requested_by) + '</b>\'s song didn\'t work out — ' + karEsc(r.note || 'ask them to try a different YouTube version') + '.' };
            }
          }
        });
        karActPrev = {};
        d.rows.forEach(function(r){ karActPrev[r.id] = r.status; });
        Object.keys(karActDone).forEach(function(id){
          if (karActDone[id].until > now) lines.push(karActDone[id].msg); else delete karActDone[id];
        });
        var el = document.getElementById('kar-activity');
        if (lines.length) { el.style.display = ''; el.innerHTML = lines.join('<br>'); }
        else el.style.display = 'none';
        if (queueChanged) karQFetch();  // the guest joined the singing queue — refresh the count
      }).catch(function(){});
    }
    karActivityPoll();
    setInterval(karActivityPoll, 12000);
    // First render on page load — without this, ⟳ Refresh (a plain reload) left the
    // list empty until a chip was clicked (the owner, 2026-09-06). The Song Database
    // chip is already marked active in the HTML, and karView starts as 'db' to match.
    karRebuildBest();
    karApplyQmidiVis();  // hide/show the QMidi column per the remembered Guide setting
    karHelpApply('dl');  // the "? How it works" blocks, per this computer's remembered choice
    karHelpApply('q');
    karHelpApply('qr');
    karNowRestore();   // bring back the playing song's name/pitch/tempo after a reload
    karNowBar();       // the bar itself is always on screen — render its idle state too
    karRender();
    </script>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
