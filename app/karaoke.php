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
// The page answers ONE ajax call itself: the current 🆕 New list plus the catalog, so a
// download that finishes while the page is open shows up without a reload. Same helper
// the page uses at render time, for both editions — and it MUST sit here, before the
// first byte of HTML, or the JSON arrives glued to the page head.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'karaoke_new_list') {
    header('Content-Type: application/json');
    if ($KAR_LOCAL) { $_cat = kar_songs(); }
    else {
        $_kq  = @json_decode((string)@file_get_contents('/var/www/your-server/karaoke_songs.json'), true);
        $_cat = (is_array($_kq) && !empty($_kq['database']) && is_array($_kq['database'])) ? array_values($_kq['database']) : [];
    }
    [$_new, $_dup] = $pdo ? kar_new_downloads($pdo, $_cat) : [[], []];
    echo json_encode(['ok' => true, 'new' => $_new, 'dup' => (object)$_dup, 'db' => $_cat], JSON_UNESCAPED_UNICODE);
    exit;
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
        // The default Mac comes first (is_default), so a browser with no remembered choice —
        // or a remembered name that no longer exists — lands on it, never on whichever name
        // happens to sort first. the owner, 2026-09-12: a fresh tab on the laptop was defaulting
        // to a mini that nothing answers to.
        if ($pdo) $KAR_MACS = $pdo->query("SELECT name FROM karaoke_macs ORDER BY is_default DESC, name")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { $KAR_MACS = []; }
    if (!$KAR_MACS) $KAR_MACS = ['Laptop'];   // never render an empty picker
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cantoria</title>
<link rel="icon" href="/favicon.ico">
<style>
  /* every button on the page presses in — no more "dead" solid blocks (2026-09-12) */
  button:active:not(:disabled) { transform: scale(.94); filter: brightness(1.18); }
  button:disabled { opacity: .55; cursor: wait; }
  * { box-sizing: border-box; }
  body { margin:0; background:#1A1F2C; color:#e2e8f0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; }
  .kar-wrap { max-width:1660px; margin:0 auto; padding:18px 26px 26px; }
  /* fixed right-hand column of the YouTube panel — keeps every field ending on one line */
  /* The printed guest sheet. Hidden on screen; on paper it is the ONLY thing that prints. */
  #kar-qr-print { display:none; }
  @media print {
    body > *:not(#kar-qr-print) { display:none !important; }
    #kar-qr-print { display:block !important; text-align:center; color:#000; background:#fff;
      font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; padding:24px 18px; }
    #kar-qr-print h1 { font-size:40px; margin:0 0 6px; letter-spacing:-.01em; }
    #kar-qr-print .kar-pr-lead { font-size:20px; margin:0 0 22px; color:#333; }
    #kar-qr-print #kar-qr-print-code { display:inline-block; padding:14px; border:2px solid #000; border-radius:12px; }
    #kar-qr-print #kar-qr-print-code img,
    #kar-qr-print #kar-qr-print-code canvas { display:block; width:420px; height:420px; }
    #kar-qr-print .kar-pr-wifi { font-size:18px; margin:0 0 18px; color:#111; }
    #kar-qr-print .kar-pr-ask { font-size:14px; color:#555; }
    #kar-qr-print .kar-pr-steps { font-size:17px; line-height:1.6; margin:24px 0 0; color:#111; }
    #kar-qr-print .kar-pr-url { font-size:10px; color:#777; margin:18px 0 0; word-break:break-all; }
    @page { margin:12mm; }
  }
  .kar-dlrt { flex:0 0 350px; display:flex; gap:10px; align-items:center; justify-content:flex-start; }
  /* help icon, inlined so both editions carry it with no extra file to ship */
  /* big enough that the word inside the icon is legible (the owner, 2026-09-12) */
  .kar-helpbtn { appearance:none; -webkit-appearance:none; background:none; border:none; padding:0;
    margin-left:auto; line-height:0; cursor:pointer; opacity:.85; transition:opacity .12s, filter .12s; }
  .kar-helpbtn:hover { opacity:1; }
  .kar-helpbtn.on { opacity:1; filter:drop-shadow(0 0 7px rgba(210,173,108,.85)); }
  .kar-helpico-big { width:46px !important; height:42px !important; margin:0 !important; vertical-align:middle !important; }
  .kar-helpico { display:inline-block; width:20px; height:18px; vertical-align:-4px; margin-right:5px;
    background:url(data:image/png;base64,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) no-repeat center/contain; }
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
  /* The row reads as two sections: SET UP (what the song is) and SING (what you do with it).
     Same tint on both, told apart by the gap between them — the owner, 2026-09-10. The widths in
     the group-heading row above are tied to these: left content 124+12+48+12+48 = 244, +16 padding
     = 260. Change a column width and that number has to change with it. */
  .kar-sect { display: flex; align-items: center; gap: 12px; padding: 3px 8px;
    background: rgba(148,163,184,.10); border: 1px solid rgba(148,163,184,.10); border-radius: 8px; }
  .kar-sect-a { flex: 0 0 auto; }
  .kar-sect-b { flex: 1; min-width: 0; }
  .kar-sectgap { flex: 0 0 auto; width: 28px; }
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
  /* WHICH PANEL IS OPEN, readable at a glance (the owner, 2026-09-13: "sometimes you forget
     where you are… a button needs to be lit up telling me that that is what's active").
     The tiles are solid brand colours at rest, so a merely lighter fill was too weak a
     signal. Three things move together instead: the open tile gets a RING (a dark gap then
     a bright ring in its own colour, which reads as selected on any background), lifts 2px,
     and grows a caret pointing down at the space it opened; every other tile dims. Exactly
     one tile is ever bright, so the answer is available without reading anything. */
  /* A LABELLED GROUP on the header row (the owner, 2026-09-13). The first four controls are
     one thing — they choose which list of songs you are looking at, and whose. Everything
     else on that row plays or opens a panel. A caps label over them says so, in the same
     form as the NOW PLAYING / KEY / TEMPO / PLAYBACK labels on the bar directly below, so
     the two rows read as one design rather than two.
     The row is bottom-aligned (align-items:flex-end) so the chips sit level with the search
     box and the tiles, and the label lives in the space above them — his own suggestion, and
     it is what makes the group look like a single object instead of four loose buttons. */
  /* ALL FOUR THE SAME BLUE (the owner, 2026-09-13: "the song database is a different colour
     and the other ones are not - can we make them all the same"). The solid fill used to BE
     the active signal, so making them uniform would have removed the only sign of which list
     is on screen. The ring takes that job instead - the same language as the header tiles, so
     "active" looks the same everywhere on this page. */
  /* The selected list LIGHTS UP rather than growing (the owner, 2026-09-13: "make it light
     up without becoming a lot bigger" and then "leave the colour blue and add a yellow line
     around the box"). The blue fill never changes; a gold edge and a gold glow do the work.
     The edge is drawn as an INSET shadow, not a thicker border, so the box stays exactly the
     same size selected or not - measured both ways. Gold is the page's own accent (the search
     box and the Now Playing bar). !important beats the inline colour karSwitch writes. */
  /* the owner, 2026-09-18: "too many colours" - the chips were vivid blue with a gold ring.
     One grey for all three; the active one is simply lighter, with a soft neutral ring. */
  .kar-chip.kar-on { border-color: #cbd5e1 !important;
    box-shadow: inset 0 0 0 1.5px #cbd5e1, 0 0 10px rgba(203,213,225,.22); }
  .kar-grp { display: inline-flex; flex-direction: column; gap: 8px; flex: 0 0 auto; }
  .kar-grplbl { font-size: 10px; font-weight: 800; letter-spacing: .09em; text-transform: uppercase;
    color: #bfdbfe; background: rgba(96,165,250,.13); border: 1px solid rgba(96,165,250,.30);
    border-radius: 7px; padding: 3px 9px; text-align: center; line-height: 1.3;
    align-self: center; width: calc(100% - 20px); }
  .kar-tile { transition: background .12s, border-color .12s, box-shadow .12s, transform .12s, opacity .12s; }
  .kar-tile-on { transform: translateY(-2px); }
  .kar-tile-on::after { content: ''; position: absolute; left: 50%; bottom: -20px; width: 0; height: 0;
    transform: translateX(-50%); border: 7px solid transparent; border-top-color: var(--kt, #fff);
    filter: drop-shadow(0 2px 2px rgba(0,0,0,.35)); }
  .kar-tile-off { opacity: .5; }
  .kar-tile-off:hover { opacity: .8; }

  /* ── SIMPLE MODE (2026-09-17) ──────────────────────────────────────────────────────────
     the owner: some of his friends sing well and cannot manage a computer. Simple mode is for
     them - search, pick a key, play, stop, and nothing else on screen.
     It is a MODE, not a second page: one class on #karaoke-page and everything below follows.
     The song rows are rebuilt constantly by karRender(), so styling them from here means the
     row builder is never touched at all.
     !important is deliberate - the column widths are written inline on each element and an
     inline style beats a stylesheet rule without it. Contained to this block, and completely
     inert whenever the class is absent. */
  .kar-simple .kar-grplbl,
  .kar-simple #kar-grp-special,
  /* 🆕 New Songs is NOT hidden any more (the owner, 2026-09-18). Simple mode hid it, while the
     download messages kept telling him the song was "under 🆕 New Songs" - pointing at a chip
     he could not see. It is also the review bench: the Duplicate column, renaming, and where
     a song goes to be tidied. "That's one of the things we're gonna need for the whole
     project." Do not hide it again. */
  .kar-simple #kar-sec-key,
  .kar-simple #kar-sec-tempo,
  .kar-simple #kar-lbl-playback,
  .kar-simple #kar-lyrics-btn,
  .kar-simple #kar-bands,
  /* Delete is NOT hidden any more (the owner, 2026-09-18: "let's bring the delete button back").
     It is how a duplicate gets removed, which is the other half of reviewing a download in
     🆕 New Songs. The file is MOVED to the Deleted folder, never destroyed. The header and the
     ✕ cells below must be unhidden together. */
  .kar-simple #kar-h-add,
  /* Seq Number is NOT hidden any more (the owner, 2026-09-18: "let's bring the numbers back").
     A freshly downloaded song is row 1 of 🆕 New Songs, and the number is what makes that
     readable at a glance. The header and the cells below must be unhidden together. */
  .kar-simple .kar-q-add,
  .kar-simple .kar-sectgap { display: none !important; }
  /* The Guide is NOT trimmed any more (the owner, 2026-09-18: "let's bring all chapters of the
     guide back"). Simple mode used to show 2 of the 9 cards and hide the group headings, which
     left the page describing features it was also hiding. Everything the page can do now has a
     card. Nothing here hides a Guide card - do not add a rule that does.
     ⚠ Historical note worth keeping: the hide rule used to be
     "#kar-guide-cards > button", which carries an id, a class AND a type - so a bare
     "#kar-gc-sing" exception (id + class) LOST to it even with !important, because specificity
     is compared BEFORE !important among equally-important rules. That shipped an EMPTY Guide
     until it was rendered and looked at. If a selector like that ever comes back, repeat the
     parent id in the exception to make it two ids. */

  /* Guide stops being green in simple mode. Green means GO on this page (Play, Start); a manual
     has no business wearing it, and in simple mode it was the loudest thing in the corner and the
     least important. Complete mode keeps its coloured tiles - this is scoped to simple only. */
  .kar-simple #kar-guide-btn,
  .kar-simple #kar-start-btn,
  .kar-simple #kar-stop-btn { background: #334155 !important; border-color: #475569 !important; color: #e2e8f0 !important; }
  /* ⚠ Start and Stop go slate too - the owner's choice, 2026-09-17, after seeing both rendered. I argued
     to keep them coloured (once the chrome is quiet they are the only colour left, so a singer finds
     Stop without reading). He chose full uniformity; it is his product and his singers.
     SAFE because karPauseToggle only swaps the TEXT - "⏹ Stop" becomes "▶ Resume" - and never the
     colour, so the paused state still reads correctly with no colour at all. Verified before building.
     The row Play buttons stay green: they are the main action of the page and were never in question. */

  /* Bigger, because some of these singers are reading a television from across the room. */
  .kar-simple .kar-row     { padding: 10px 6px !important; }
  .kar-simple .kar-name    { width: 560px !important; font-size: 17px !important; }
  .kar-simple #kar-h-song  { width: 560px !important; font-size: 12px !important; }
  .kar-simple .kar-play    { width: 90px !important; font-size: 15px !important; padding: 7px 0 !important; }
  .kar-simple #kar-h-casai { width: 90px !important; font-size: 12px !important; }
  .kar-simple .kar-star    { font-size: 16px !important; }   /* NOT enlarged with the rest: the star is a
     secondary action (put this on someone's list) and at 22px it competed with Play, which is the whole
     point of the page. the owner, 2026-09-17: "too big for what we're doing here." */

  /* The two list chips join the grey too, and selection is shown the way the Simple|Complete switch
     already shows it - BRIGHTER means selected - with a near-white ring instead of the gold one.
     Gold is a warm colour and this page is now cool slate, which is exactly why it clashed
     (the owner: "maybe a different color than orange, something that matches with gray"). White is not
     a new hue at all, just more light, so it cannot fight anything else on screen. */
  .kar-simple .kar-chip        { background: #1a2230 !important; border-color: #475569 !important; color: #94a3b8 !important; }
  /* ⚠ The gold ring you SEE is the box-shadow, not the border - `.kar-chip.kar-on` draws
     `inset 0 0 0 1.5px #fbbf24` plus a gold glow. Overriding border-color alone changes nothing
     visible, and checking border-color alone will tell you the gold is gone when it is still on
     screen. Override the SHADOW. */
  .kar-simple .kar-chip.kar-on { background: #334155 !important; border-color: #e2e8f0 !important; color: #fff !important;
    box-shadow: inset 0 0 0 1.5px #e2e8f0, 0 0 10px rgba(226,232,240,.28) !important; }
  .kar-simple .kar-pitch   { font-size: 16px !important; }
  .kar-simple .kar-pstep   { font-size: 17px !important; }

  /* The three searches, in the order you use them: your own songs, then YouTube, then a
     link somebody handed you. One box; the mode decides what the box DOES. the owner,
     2026-09-18: "there are three types of search ... it would be nice to have those three
     in the sequence, somewhere in the header". */
  /* The three searches. the owner, 2026-09-18: "maybe we can put the names without the boxes
     up there... and the one that is clicked on has a little shadow behind that identifies
     which one we're doing, but not the boxes with the borders and all that."
     So: plain text, no border, no colour coding. The active one is brighter and sits on a
     soft shadow. */
  /* The three searches, INSIDE the white bar. Barely there until you look: grey on white,
     no border, no colour coding. The one in use goes dark on a soft backdrop. the owner,
     2026-09-18: "not colours, but just barely visible". */
  .kar-smode { appearance:none; -webkit-appearance:none; font-family:inherit; cursor:pointer;
               background:none; border:none; padding:4px 9px; border-radius:7px;
               font-size:11.5px; font-weight:700; color:#94a3b8; white-space:nowrap;
               transition:color .12s, background .12s; }
  .kar-smode:hover  { color:#475569; }
  .kar-smode.kar-on { color:#0f172a; background:rgba(15,23,42,.075); }
  .kar-sdiv { color:#cbd5e1; font-size:11px; user-select:none; }
  .kar-arrow { display:flex; align-items:center; gap:12px; padding:9px 12px; border-radius:10px;
               margin-bottom:6px; font-size:14px; }
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
  <div style="display:flex;align-items:baseline;gap:10px;margin-bottom:10px;flex-wrap:wrap">
    <div style="margin:0">
      <h1 style="margin:0;font-size:22px;font-weight:800;color:#f3f4f6;letter-spacing:.01em">🎤 Cantoria</h1>
    </div>
    <?php if ($KAR_LOCAL): ?>
    <span style="color:#64748b;font-size:12.5px">everything runs on this Mac — nothing to sign in to</span>
    <?php else: ?>
    <span style="color:#64748b;font-size:12.5px"><a href="/app.php?view=people" style="color:#60A5FA;text-decoration:none">← back to casAI</a></span>
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
      // What this Mac REALLY has for the MC. Worth showing rather than leaving people to
      // discover it at a party: a missing applause file simply falls back to a silent
      // walk-up, which looks like a fault and is impossible to tell apart from a bug.
      $_mcAp    = function_exists('kar_mc_applause') ? kar_mc_applause() : '';
      $_mcVid   = ($_mcAp !== '' && function_exists('kar_mc_applause_has_video') && kar_mc_applause_has_video($_mcAp));
      $_mcVoice = function_exists('kar_mc_voice') ? (kar_mc_voice() ?: 'the Mac\'s default voice') : '';
      $_mcOn    = !function_exists('kar_mc_on') || kar_mc_on();
  } else {
      $_kjPath = '/var/www/your-server/karaoke_songs.json';
      $_kj = is_file($_kjPath) ? json_decode((string)file_get_contents($_kjPath), true) : null;
      $_kjDb   = (is_array($_kj) && !empty($_kj['database']) && is_array($_kj['database'])) ? array_values($_kj['database']) : [];
      $_kjGen  = is_array($_kj) ? (string)($_kj['generated_at'] ?? '') : '';
      // The MC block in the Setup section reads these unconditionally, but the Setup card
      // only exists on the standalone edition — on casAI the section body is still emitted
      // (hidden), so without defaults every casAI page load logged four PHP warnings.
      $_mcAp = ''; $_mcVid = false; $_mcVoice = ''; $_mcOn = false;
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
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
      <div class="kar-grp">
        <div class="kar-grplbl">Songs and singers</div>
        <div style="display:flex;gap:8px;align-items:center">
      <button type="button" class="kar-chip kar-on" onclick="karSwitch('db',this)" style="font-family:inherit;background:#1a2230;border:1.5px solid #334155;color:#94a3b8;cursor:pointer;font-size:11.5px;font-weight:700;padding:6px 6px;border-radius:999px">🗂 Song Database <span id="kar-db-count" style="font-weight:600;opacity:.8;font-size:10.5px"><?= count($_kjDb) ?></span></button>
      <button id="kar-chip-new" type="button" class="kar-chip" onclick="karSwitch('new',this)" title="Everything downloaded in the last 30 days, newest first — so last night's songs, and last month's, are one click away" style="font-family:inherit;background:#1a2230;border:1.5px solid #334155;color:#94a3b8;cursor:pointer;font-size:11.5px;font-weight:700;padding:6px 6px;border-radius:999px">🆕 New Songs <span id="kar-new-count" style="font-weight:600;opacity:.8;font-size:10.5px"><?= count($_kjNew) ?></span></button>
      <select id="kar-who" class="kar-chip" onchange="karWhoChange(this)" onmousedown="karWhoTouch()" title="Whose Best list this is. Pick a name to see their songs, or add a new person." style="font-family:inherit;background:#1a2230;border:1.5px solid #334155;color:#94a3b8;cursor:pointer;font-size:11.5px;font-weight:700;padding:6px 6px;border-radius:999px">
        <?php foreach (array_keys($_kjBestBy) as $_kbp): ?>
        <option value="<?= h($_kbp) ?>">⭐ Best of <?= h($_kbp) ?> <?= count($_kjBestBy[$_kbp]) ?></option>
        <?php endforeach; ?>
        <option value="__add__">＋ Add a person…</option>
        <option value="__remove__">− Remove a person…</option>
      </select>
        </div>
      </div>
      <!-- The three searches live INSIDE the bar, not above it. the owner, 2026-09-18:
           "can you put those three buttons inside of the search bar... not colours, but just
           barely visible? You click on one and then that whole bar becomes that." So the bar
           IS the mode: pick one and its placeholder, its icon and its button follow. -->
      <div id="kar-searchbar" style="flex:1;min-width:430px;display:flex;align-items:center">
        <div id="kar-bar" style="flex:1;min-width:0;display:flex;align-items:center;height:46px;background:#f8fafc;border:2px solid #D2AD6C;border-radius:12px;padding:0 7px 0 13px;box-shadow:0 0 0 3px rgba(210,173,108,.15)">
          <span id="kar-sicon" style="flex:0 0 auto;font-size:18px;line-height:1;pointer-events:none;margin-right:9px">🔍</span>
          <input id="kar-search" type="text" placeholder="Search a song or an artist…" oninput="karSearchInput()" onkeydown="karSearchKey(event)" title="Type here. Esc clears it." style="font-family:inherit;flex:1;min-width:60px;background:none;border:none;outline:none;color:#0f172a;font-size:15px;font-weight:600;padding:0">
          <span style="flex:0 0 auto;display:flex;align-items:center;gap:1px;margin-left:8px">
            <button type="button" class="kar-smode kar-on" id="kar-sm-list" onclick="karSetMode('list')" title="Search the karaoke list — the songs you already have">Karaoke List</button>
            <span class="kar-sdiv">|</span>
            <button type="button" class="kar-smode" id="kar-sm-yt" onclick="karSetMode('yt')" title="Search YouTube for a song you do not have yet">YouTube</button>
            <span class="kar-sdiv">|</span>
            <button type="button" class="kar-smode" id="kar-sm-link" onclick="karSetMode('link')" title="Paste a link somebody gave you and download it">Link</button>
          </span>
          <button type="button" id="kar-go" onclick="karSearchGo()" style="display:none;flex:0 0 auto;appearance:none;-webkit-appearance:none;font-family:inherit;cursor:pointer;height:34px;padding:0 15px;margin-left:7px;border-radius:8px;font-size:13px;font-weight:800;background:#334155;border:1px solid #64748b;color:#f1f5f9">Search</button>
        </div>
      </div>
      <div class="kar-grp" id="kar-grp-special">
        <div class="kar-grplbl">Special features</div>
        <div style="display:flex;gap:8px;align-items:center">
      <button type="button" onclick="karQToggle()" id="kar-q-btn" title="The singing queue — who sings next, in order" class="kar-tile" style="appearance:none;-webkit-appearance:none;font-family:inherit;position:relative;background:#D2AD6C;border:1px solid #D2AD6C;color:#1a1305;cursor:pointer;display:inline-flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;width:78px;height:56px;padding:4px 5px;border-radius:11px;transition:background .12s,border-color .12s,box-shadow .12s"><span id="kar-q-count" style="position:absolute;top:-7px;right:-7px;min-width:20px;height:20px;padding:0 6px;border-radius:999px;background:#0f1522;border:2px solid #D2AD6C;color:#f3d9a4;font-size:11px;font-weight:800;line-height:16px;text-align:center">0</span><span style="font-size:23px;line-height:1">🎤</span><span style="font-size:10.5px;font-weight:800;line-height:1.15;text-align:center">Singing Queue</span></button>
      <button type="button" onclick="karDlToggle()" id="kar-dl-btn" title="Search YouTube from here and download songs into the library" class="kar-tile" style="appearance:none;-webkit-appearance:none;font-family:inherit;position:relative;background:#EF4444;border:1px solid #EF4444;color:#fff;cursor:pointer;display:inline-flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;width:78px;height:56px;padding:4px 5px;border-radius:11px;transition:background .12s,border-color .12s,box-shadow .12s"><span style="font-size:23px;line-height:1">▶</span><span style="font-size:10.5px;font-weight:800;line-height:1.15;text-align:center">YouTube Downloads</span></button>
      <button type="button" onclick="karQrToggle()" id="kar-qr-btn" title="The code guests scan to request or bring songs from their own phones" class="kar-tile" style="appearance:none;-webkit-appearance:none;font-family:inherit;position:relative;background:#a855f7;border:1px solid #a855f7;color:#fff;cursor:pointer;display:inline-flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;width:78px;height:56px;padding:4px 5px;border-radius:11px;transition:background .12s,border-color .12s,box-shadow .12s"><span style="font-size:23px;line-height:1">📱</span><span style="font-size:10.5px;font-weight:800;line-height:1.15;text-align:center">Guest QR</span></button>
        </div>
      </div>
      <!-- Simple / Complete. Placed to the LEFT of Guide and Refresh and sized to match them
           (78x56, the same tile as Guide) so the row reads as four even buttons rather than a
           small control tacked on at the end - the owner, 2026-09-17, looking at the first cut.
           Still grey: it is a setting, not a feature, and on a simple-mode page a coloured tile
           would be the most interesting thing on screen. It can live in the open rather than
           hidden in the Guide because it is symmetric and instantly reversible - press it,
           press it again, you are back. The row's own gap:8px spaces it; no margin needed. -->
      <span id="kar-mode-sw" title="Simple shows only what you need to sing. Complete shows everything." style="display:inline-flex;align-items:stretch;border:1px solid #475569;border-radius:11px;overflow:hidden">
        <button type="button" id="kar-mode-s" onclick="karSetSimple(true)" title="Just what you need to sing - search, key, play, stop" style="appearance:none;-webkit-appearance:none;font-family:inherit;border:none;border-right:1px solid #475569;cursor:pointer;width:78px;height:54px;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase">Simple</button>
        <button type="button" id="kar-mode-c" onclick="karSetSimple(false)" title="Everything - the singing queue, downloads and guest requests" style="appearance:none;-webkit-appearance:none;font-family:inherit;border:none;cursor:pointer;width:78px;height:54px;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase">Complete</button>
      </span>
      <button type="button" onclick="karGuideToggle()" id="kar-guide-btn" title="How everything on this page works — all the rules in one readable place" class="kar-tile" style="appearance:none;-webkit-appearance:none;font-family:inherit;position:relative;background:#16a34a;border:1px solid #16a34a;color:#fff;cursor:pointer;display:inline-flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;width:78px;height:56px;padding:4px 5px;border-radius:11px;transition:background .12s,border-color .12s,box-shadow .12s"><span style="font-size:23px;line-height:1">📖</span><span style="font-size:10.5px;font-weight:800;line-height:1.15;text-align:center">Guide</span></button>
      <button type="button" onclick="location.reload()" title="Refresh — reload the song lists from the server" style="appearance:none;-webkit-appearance:none;font-family:inherit;margin-left:auto;background:linear-gradient(180deg,rgba(255,255,255,.28) 0%,rgba(255,255,255,.08) 47%,rgba(255,255,255,0) 48%),linear-gradient(180deg,#5b6676 0%,#232c3a 100%);border:1px solid rgba(255,255,255,.14);box-shadow:inset 0 1px 0 rgba(255,255,255,.45),inset 0 -3px 6px rgba(0,0,0,.28),0 5px 12px rgba(0,0,0,.45),0 2px 3px rgba(0,0,0,.35);color:#fff;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;width:56px;height:56px;border-radius:50%;padding:0;flex-direction:column;gap:0"><svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="filter:drop-shadow(0 1px 1px rgba(0,0,0,.4))"><path d="M20.5 12a8.5 8.5 0 1 1-2.49-6.01"/><path d="M20.5 4v5.5h-5.5"/></svg><span style="font-size:8.5px;font-weight:800;letter-spacing:.01em;line-height:1;text-shadow:0 1px 1px rgba(0,0,0,.45)">REFRESH</span></button>
    </div>
    <div id="kar-now-bar" style="position:sticky;top:8px;z-index:40;margin-top:10px;background:#28241a;border:1px solid rgba(210,173,108,.45);border-radius:10px;padding:9px 6px 9px 16px;box-shadow:0 4px 16px rgba(0,0,0,.45)">
      <!-- Four labelled sections, divided by a rule, so the eye can find "the key" or "the
           tempo" without reading the whole bar (the owner, 2026-09-12: "no sections... you
           have to figure out whatever the thing is"). -->
      <div style="display:flex;align-items:stretch;flex-wrap:wrap;row-gap:8px">
        <?php if (!$KAR_LOCAL): // casAI only — and shown only when there is more than one Mac to choose from ?>
        <span style="display:<?= count($KAR_MACS) > 1 ? 'flex' : 'none' ?>;flex-direction:column;gap:5px;padding:0 16px 0 0">
          <span style="color:#b8a06a;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.10em;line-height:1">Play on</span>
          <select id="kar-mac" onchange="karMacChange(this)" title="Which Mac the music comes out of. Every button on this page — Play, Stop, key, tempo — goes to the Mac picked here." style="font-family:inherit;background:#121620;border:1px solid #4b5563;color:#e2e8f0;cursor:pointer;font-size:12px;font-weight:700;padding:4px 8px;border-radius:8px">
            <?php foreach ($KAR_MACS as $_km): ?>
            <option value="<?= h($_km) ?>"><?= h($_km) ?></option>
            <?php endforeach; ?>
            <option value="__addmac__">＋ Add a Mac…</option>
            <option value="__removemac__">− Remove this Mac…</option>
          </select>
        </span>
        <?php endif; ?>
        <span style="display:flex;flex-direction:column;gap:5px;flex:1;min-width:220px;padding-right:16px;justify-content:center">
          <span style="color:#b8a06a;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.10em;line-height:1">♪ Now playing</span>
          <span style="display:flex;align-items:baseline;gap:8px;flex-wrap:wrap"><span id="kar-now-song" style="color:#f3f4f6;font-size:13.5px;font-weight:700"></span><span id="kar-now-player" style="color:#8a8070;font-size:11px"></span></span>
          <span id="kar-prog" style="display:none;align-items:center;gap:10px;margin-top:2px">
            <span id="kar-time-pos" style="color:#cbd5e1;font-size:11px;font-variant-numeric:tabular-nums;width:34px;text-align:right">0:00</span>
            <input id="kar-seek" type="range" min="0" max="1000" value="0" title="Drag to move within the song" style="flex:1;min-width:160px;accent-color:#D2AD6C;cursor:pointer;margin:0">
            <span id="kar-time-dur" style="color:#8a8070;font-size:11px;font-variant-numeric:tabular-nums;width:34px">0:00</span>
          </span>
        </span>
        <span id="kar-sec-key" style="display:flex;flex-direction:column;gap:5px;padding:0 16px;border-left:1px solid rgba(210,173,108,.28)">
          <span style="color:#b8a06a;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.10em;line-height:1;text-align:center">Key</span>
          <span style="display:flex;align-items:center;gap:6px">
            <button type="button" onclick="karLiveAdj(-1)" title="Lower the key one semitone while the song plays. Takes a few seconds; not saved to the song." style="font-family:inherit;width:34px;background:#121620;border:1px solid #4b5563;color:#e2e8f0;cursor:pointer;font-size:15px;font-weight:700;padding:2px 0;border-radius:6px">−</button>
            <span id="kar-live-val" style="color:#D2AD6C;font-size:16px;font-weight:800;width:32px;text-align:center">0</span>
            <button type="button" onclick="karLiveAdj(1)" title="Raise the key one semitone while the song plays. Takes a few seconds; not saved to the song." style="font-family:inherit;width:34px;background:#121620;border:1px solid #4b5563;color:#e2e8f0;cursor:pointer;font-size:15px;font-weight:700;padding:2px 0;border-radius:6px">+</button>
          </span>
        </span>
        <span id="kar-sec-tempo" style="display:flex;flex-direction:column;gap:5px;padding:0 16px;border-left:1px solid rgba(210,173,108,.28)">
          <span style="color:#b8a06a;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.10em;line-height:1;text-align:center">Tempo</span>
          <span style="display:flex;align-items:center;gap:6px">
            <button type="button" onclick="karTempoAdj(-5)" title="Slow the song 5%; the key stays true. Takes a few seconds; not saved. casAI player only." style="font-family:inherit;width:34px;background:#121620;border:1px solid #4b5563;color:#e2e8f0;cursor:pointer;font-size:15px;font-weight:700;padding:2px 0;border-radius:6px">−</button>
            <span id="kar-tempo-val" style="color:#6ee7b7;font-size:15px;font-weight:800;width:44px;text-align:center">100%</span>
            <button type="button" onclick="karTempoAdj(5)" title="Speed the song up 5%; the key stays true. Takes a few seconds; not saved. casAI player only." style="font-family:inherit;width:34px;background:#121620;border:1px solid #4b5563;color:#e2e8f0;cursor:pointer;font-size:15px;font-weight:700;padding:2px 0;border-radius:6px">+</button>
          </span>
        </span>
        <span style="display:flex;flex-direction:column;gap:5px;padding:0 16px;border-left:1px solid rgba(210,173,108,.28)">
          <span id="kar-lbl-playback" style="color:#b8a06a;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.10em;line-height:1;text-align:center">Playback</span>
          <span style="display:flex;align-items:center;gap:8px">
            <button id="kar-lyrics-btn" type="button" onclick="karLyricsToggle(this)" title="Hide the lyrics screen, or bring it back in front of everything" style="font-family:inherit;background:#334155;border:1px solid #475569;color:#e2e8f0;cursor:pointer;font-size:11px;font-weight:800;line-height:1.1;padding:0 10px;height:36px;border-radius:8px;white-space:nowrap">🎬 Lyrics<br>Screen</button>
            <button type="button" id="kar-start-btn" onclick="karPlayAgain()" title="Start this song from the beginning — same player, at the key shown" style="font-family:inherit;background:#16a34a;border:1px solid #16a34a;color:#fff;cursor:pointer;font-size:12px;font-weight:800;padding:0 16px;height:36px;border-radius:8px">▶ Start</button>
            <button type="button" onclick="karPauseToggle(this)" id="kar-stop-btn" title="Stop the song where it is. Press again to resume. To end a song, close the lyrics screen (Q)." style="font-family:inherit;background:#dc2626;border:1px solid #dc2626;color:#fff;cursor:pointer;font-size:12px;font-weight:800;padding:0 16px;height:36px;border-radius:8px">⏹ Stop</button>
          </span>
        </span>
      </div>
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
      <p id="kar-guide-intro" style="margin:12px 0 0;color:#94a3b8;font-size:13px">Select a topic.</p>
      <p id="kar-guide-back" style="display:none;margin:12px 0 0"><button type="button" onclick="karGuideBack()" style="font-family:inherit;background:rgba(210,173,108,.12);border:1px solid #D2AD6C;color:#D2AD6C;cursor:pointer;font-size:12.5px;font-weight:700;padding:6px 13px;border-radius:8px">← All topics</button></p>

      <div id="kar-guide-cards" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(215px,1fr));gap:8px;margin:12px 0 4px">
        <?php
        // One row per card: key, title, and the one line that says what it is for.
        $_karCards = [];
        // Numbered by a counter, not by hand: the two editions do not carry the same set of
        // cards, and hand-typed numbers went wrong the moment one was inserted or moved.
        $_n = 0;
        $_num = function ($t) use (&$_n) { return (++$_n) . ' · ' . $t; };
        if ($KAR_LOCAL) $_karCards[] = ['setup', $_num('Installing Cantoria'), 'One-time installation, the songs folder and the player.', 'Setting up'];
        else            $_karCards[] = ['setup', $_num('Installing Cantoria'), 'One-time installation, choosing the Mac and the songs folder.', 'Setting up'];
        // How it is put together comes before how it is used — this is the card someone
        // reads to understand the machines before touching anything (the owner, 2026-09-12).
        // casAI only: it names the machines, so it is gated to the copy that never leaves
        // the household, and every name in it is read at render time from the database.
        if (!$KAR_LOCAL) $_karCards[] = ['config', $_num('Configuration and workflow'), 'Machines, release process and shared data.', 'Setting up'];
        $_karCards[] = ['update', $_num('Software updates'), $KAR_LOCAL ? 'Installing the latest version.' : 'How the other Macs receive a release.', 'Setting up'];
        $_karCards[] = ['sing',  $_num('Play a song'),          'Search, playback and key.', 'Using it'];
        $_karCards[] = ['while', $_num('While it is playing'),  'Live controls: key, speed, start and stop.', 'Using it'];
        $_karCards[] = ['songs', $_num('Managing songs'),       'Best lists, new arrivals, renaming and removal.', 'Using it'];
        $_karCards[] = ['party', $_num('Party controls'),       'The singing queue, guest requests and downloads.', 'At a party'];
        // The three party panels each get a card of their own. Their words live HERE and
        // nowhere else — the floating "?" beside each panel borrows this same text rather
        // than keeping a second copy that would quietly drift out of step with it.
        $_karCards[] = ['upnext',    $_num('Singing Queue'), 'Who sings next, and scheduling fairness.', 'At a party'];
        $_karCards[] = ['downloads', $_num('YouTube Downloads'), 'Searching YouTube and adding songs.', 'At a party'];
        $_karCards[] = ['guestqr',   $_num('Guest QR'),  'Song requests from guests\' phones.', 'At a party'];
        // Grouped, because ten cards in one flat grid is a wall (the owner, 2026-09-13). The
        // heading spans the whole grid row; the numbers still run 1..N in reading order,
        // because he refers to cards by number out loud.
        $_grp = '';
        foreach ($_karCards as [$_k, $_t, $_d, $_g]):
          if ($_g !== $_grp): $_grp = $_g; ?>
        <div class="kar-ghdr" style="grid-column:1/-1;color:#8ea2bd;font-size:10px;font-weight:700;letter-spacing:.16em;text-transform:uppercase;border-top:1px solid rgba(148,163,184,.22);padding-top:9px;margin:<?= $_grp === 'Setting up' ? '2px' : '14px' ?> 0 0"><?= h($_grp) ?></div>
        <?php endif; ?>
        <button type="button" id="kar-gc-<?= $_k ?>" onclick="karGuideOpen('<?= $_k ?>')" style="font-family:inherit;text-align:left;background:#1a2130;border:1px solid #334155;border-radius:9px;padding:11px 13px;cursor:pointer">
          <span style="display:block;color:#D2AD6C;font-size:13.5px;font-weight:800"><?= h($_t) ?></span>
          <span style="display:block;color:#94a3b8;font-size:12px;line-height:1.5;margin-top:3px"><?= h($_d) ?></span>
        </button>
        <?php endforeach; ?>
      </div>

      <!-- ── the sections themselves. Grey text, gold only for headings and things you click ── -->
      <div id="kar-guide-body" style="display:none;margin-top:14px;border-top:1px solid #334155;padding-top:14px;color:#cbd5e1;font-size:13.5px;line-height:1.8">

        <div class="kar-gs" id="kar-gs-setup" style="display:none">
          <h3 style="margin:0 0 8px;font-size:14.5px;font-weight:800;color:#D2AD6C">Installing Cantoria on your Mac</h3>
          <p style="margin:0 0 10px">This installs Cantoria on your Mac. You do it once. It is one command, which you copy and paste rather than type, and you will not have to use the command line again.</p>
          <div class="kar-gs-body" style="display:grid;gap:9px">        <div><b style="color:#D2AD6C">Before you start</b> — you need to be signed in to your Mac on an administrator account, and be connected to the internet. Nothing else.</div>
        <div><b style="color:#D2AD6C">1 · Open Terminal</b> — Terminal comes with every Mac; you may simply never have opened it. Press <b>⌘ Space</b>, type <code>Terminal</code>, press <b>Return</b>. A window like the one below opens. This is the only unfamiliar part; everything after it is copy and paste.</div>
        <div style="margin:1px 0 2px;border-radius:10px;overflow:hidden;border:1px solid #3a4354;box-shadow:0 6px 18px rgba(0,0,0,.45)">
          <div style="display:flex;align-items:center;gap:7px;background:linear-gradient(#3b414d,#2b303a);padding:7px 11px">
            <span style="width:11px;height:11px;border-radius:50%;background:#ff5f57"></span>
            <span style="width:11px;height:11px;border-radius:50%;background:#febc2e"></span>
            <span style="width:11px;height:11px;border-radius:50%;background:#28c840"></span>
            <span style="flex:1;text-align:center;margin-right:34px;color:#c7ccd6;font-size:11px;font-weight:700;letter-spacing:.02em">Terminal — zsh — 80&#215;24</span>
          </div>
          <div style="background:#0b0e14;padding:11px 13px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11.5px;line-height:1.7;color:#d7dce5">
            <div style="color:#78818f">Last login: Sat Sep 13 09:14:22 on ttys000</div>
            <div><span style="color:#6ee7b7">you@Mac</span> <span style="color:#8ea2bd">~</span> % <span style="display:inline-block;width:7px;height:14px;background:#d7dce5;vertical-align:-3px;margin-left:2px"></span></div>
          </div>
        </div>
        <div style="color:#94a3b8;font-size:12.5px;margin-top:-3px">That is all there is at first: a prompt, waiting. Nothing is typed yet.</div>
        <div><b style="color:#D2AD6C">2 · Paste in the command</b> — select the line below and copy it (<b>⌘C</b>). Click once inside the Terminal window, paste (<b>⌘V</b>) — the command appears after the prompt — and press <b>Return</b>. Copy and paste it — do not try to type it manually.</div>
        <div style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;color:#e2e8f0;background:#0d1118;border:1px solid #334155;border-radius:8px;padding:9px 12px;word-break:break-all">curl -fsSL https://raw.githubusercontent.com/claudegulino-bit/family-karaoke/main/install.sh | bash</div>
        <div><b style="color:#D2AD6C">3 · What the installer does</b> — it puts four things on your Mac:</div>
        <ul style="margin:-3px 0 0;padding-left:20px;line-height:1.75">
          <li><b>App 1 — the player.</b> Plays the song, puts the words on the screen, and changes the key and the speed while it is playing.</li>
          <li><b>App 2 — the downloader.</b> Fetches a song from YouTube when you or a guest ask for one.</li>
          <li><b>App 3 — the media toolkit.</b> Checks each downloaded file is in a format the player can show — some YouTube files arrive with sound but no picture, and this is what catches them.</li>
          <li><b>App 4 — Cantoria itself.</b> The page you are reading, an icon on the Desktop to open it, and a background service so your Mac is ready to play whenever it is switched on.</li>
        </ul>
        <div><b style="color:#D2AD6C">4 · What it will ask you</b> — where your songs are kept, and at some point it may ask for your Mac password: the same one you use to log in. Type it and press Return. <b>Nothing appears on screen as you type it</b> — no characters, not even dots. That is normal.</div>
        <div><b style="color:#D2AD6C">5 · What you will see</b> — several minutes of text scrolling past. None of it needs reading. It has finished when the prompt comes back and you can type again.</div>
        <div><b style="color:#D2AD6C">6 · Check it worked</b> — click the button later in this card called <b>✅ Verify installation</b>. All four apps should come back green. If one is red it did not install: run the command in step 2 again, then press <b>✅ Verify installation</b> once more.</div>
        <div><b style="color:#D2AD6C">7 · The announcer and the applause</b> — the applause is <b>already installed</b>; there is nothing to download. It is a public-domain recording that ships with Cantoria, so the walk to the microphone is never silent.
          <div style="margin:6px 0 0;padding-left:12px;border-left:2px solid #334155">
            <div style="margin-bottom:5px"><b>For a cheering crowd on the screen</b> instead of sound alone, put a video file named <code>applause.mp4</code> in a folder called <code>@ Cantoria/sounds</code> next to your songs folder. Cantoria prefers a video whenever it finds one. If your songs are shared through Google Drive, every Mac in the house gets it at the same time.</div>
            <div><b>The announcing voices</b> are Apple&rsquo;s own, and the good ones are a download. Open <b>System Settings ▸ Accessibility ▸ Spoken Content ▸ System voice ▸ ⓘ</b> and add <b>Ava (Premium)</b> or <b>Evan (Enhanced)</b> for English, <b>Alice</b> for Italian and <b>M&oacute;nica</b> for Spanish. A song is announced in its own language, by that language&rsquo;s voice.</div>
          </div>
        </div>
        <div><b style="color:#D2AD6C">8 · Future software updates</b> — never go through Terminal. When there is a new release, you retrieve it from the master computer from inside Cantoria: open <b>📖 Guide</b>, choose <a href="#" onclick="karGuideOpen('update');return false" style="color:#D2AD6C"><b>Software updates</b></a>, and press <b>⬆︎ Cantoria Software Update</b>. It downloads and installs itself.</div>
          </div>
<?php if (!$KAR_LOCAL): ?>
          <p style="margin:12px 0 0"><b>Select the Mac.</b> The <b>Play on</b> selector in the gold bar determines which Mac receives playback and the setup actions below. It is shown only when more than one Mac is registered; with a single Mac there is nothing to choose and everything goes to it. If the Mac is not listed there, choose <b>＋ Add a Mac…</b>, enter a name, and set <code>"mac_name"</code> to the same value in <code>~/casai/karaoke_config.json</code> on that Mac. The two must match exactly.</p>
<?php endif; ?>
          <p style="margin:0 0 4px"><b>Songs folder.</b> A single folder containing the song files.</p>
          <button type="button" onclick="karPickFolder()" id="kar-pick-btn" style="font-family:inherit;margin:2px 0;background:rgba(210,173,108,.12);border:1px solid #D2AD6C;color:#D2AD6C;cursor:pointer;font-size:13px;font-weight:700;padding:8px 16px;border-radius:8px">📁 Choose the karaoke songs folder…</button>
          <span id="kar-pick-msg" style="display:block;margin:4px 0 12px;color:#94a3b8;font-size:12px">Current folder: <b id="kar-pick-cur" style="color:#cbd5e1"><?= h($_kj['songs_folder'] ?? 'not chosen yet') ?></b><br><span style="color:#94a3b8">The folder chooser opens on the Mac that plays the music; a web page cannot access local file paths.</span></span>
          <?php if ($KAR_LOCAL): // reads this Mac's own config — meaningless on casAI, which is not a Mac ?>
          <p style="margin:0 0 4px"><b>Announcement settings on this Mac.</b></p>
          <div style="margin:0 0 12px;padding:9px 12px;background:#0d1117;border:1px solid #334155;border-radius:8px;font-size:12.5px;line-height:1.7">
            <div>Announcements: <b style="color:<?= $_mcOn ? '#6ee7b7' : '#94a3b8' ?>"><?= $_mcOn ? 'on' : 'off' ?></b></div>
            <div>Voice: <b style="color:#cbd5e1"><?= h($_mcVoice) ?></b></div>
            <div>Applause:
              <?php if ($_mcVid): ?>
                <b style="color:#6ee7b7">video</b> <span style="color:#64748b"><?= h(basename($_mcAp)) ?></span>
              <?php elseif ($_mcAp !== ''): ?>
                <b style="color:#D2AD6C">audio only</b> <span style="color:#64748b"><?= h(basename($_mcAp)) ?></span>
              <?php else: ?>
                <b style="color:#d98888">not found — the walk-up will be silent</b>
                <div style="color:#94a3b8;margin-top:3px">Place <code>applause.mp4</code> (or <code>.wav</code>) in <code>~/Karaoke/sounds/</code> or in <code>@ Cantoria/sounds/</code> alongside the songs. It is used immediately.</div>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>
          <?php if (kar_is_local()): ?>
          <p style="margin:0 0 4px"><b>Lyrics window.</b></p>
          <label style="display:flex;align-items:flex-start;gap:9px;margin:0 0 12px;padding:9px 12px;background:#0d1117;border:1px solid #334155;border-radius:8px;cursor:pointer;font-size:12.5px;line-height:1.6">
            <input type="checkbox" id="kar-ontop" onchange="karSetOnTop(this)" <?= !empty(kar_cfg()['words_on_top']) ? 'checked' : '' ?> style="margin-top:3px;width:16px;height:16px;accent-color:#D2AD6C;cursor:pointer">
            <span><b style="color:#cbd5e1">Keep the lyrics window in front.</b><br>
            <span style="color:#94a3b8">When off, the lyrics window may open behind the browser, particularly when a song is played directly rather than from the queue. When on, it always stays in front. Turn it off to view the lyrics and the song list side by side.</span></span>
          </label>
          <?php endif; ?>
          <p style="margin:0 0 4px"><b>If something is missing.</b> The installer puts all three components on your Mac already, so you should not need this — only if the check below reports one of them absent. In Terminal:</p>
          <div style="margin:0 0 4px;padding:9px 12px;background:#0d1117;border:1px solid #334155;border-radius:8px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;color:#cbd5e1">brew install mpv yt-dlp ffmpeg</div>
          <p style="margin:0 0 8px;color:#94a3b8;font-size:12.5px">If the response is <i>command not found: brew</i>, Homebrew itself is missing — run the following first, then repeat the command above:</p>
          <div style="margin:0 0 8px;padding:9px 12px;background:#0d1117;border:1px solid #334155;border-radius:8px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px;color:#cbd5e1;overflow-x:auto;white-space:nowrap">/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"</div>
          <button type="button" onclick="karCheckTools()" id="kar-tools-btn" style="font-family:inherit;margin:2px 0;background:rgba(210,173,108,.12);border:1px solid #D2AD6C;color:#D2AD6C;cursor:pointer;font-size:13px;font-weight:700;padding:8px 16px;border-radius:8px">✅ Verify installation</button>
          <span id="kar-tools-msg" style="display:block;margin:4px 0 12px;color:#94a3b8;font-size:12px">Checks the four apps from step 3 on your Mac. Green means installed; red means it is not there.</span>
          <p style="margin:0"><b>Leave your Mac on and awake</b> during a party. It plays the music and receives your guests' requests.</p>
        </div>

        <div class="kar-gs" id="kar-gs-sing" style="display:none">
          <h3 style="margin:0 0 8px;font-size:14.5px;font-weight:800;color:#D2AD6C">Play a song</h3>
          <ul style="margin:0;padding-left:20px">
            <li><b>Choose a list.</b> The three boxes under <b>Songs and singers</b> select what the page shows: <b>🗂 Song Database</b> (everything), <b>🆕 New Songs</b> (added in the last 30 days) and <b>⭐ Best of</b> (one person's list — the dropdown chooses the person). The box outlined in gold is the list currently on screen.</li>
            <li><b>Search</b> — filters the list on screen by title, artist or singer's name. Esc clears it.</li>
            <li><b>Seq Number</b> — the song's position in the list as currently displayed; the first song is always 1. A singer can request a song by number. Sorting the list or opening a Best list renumbers it from 1.</li>
            <li><b>▶ Play</b> — plays the song on the Mac. On that Mac, <b>F</b> or a <b>double-click</b> switches full screen on and off; <b>Q</b> or the window's red <b>✕</b> closes the player.</li>
            <li><b>Pitch</b> — the key the song starts in. Use − and + to transpose by semitones. The value is saved.</li>
            <li><b>Reset</b> — plays the song once in its original key, then restores the saved pitch. Use it when another singer performs the song.</li>
            <li><b>⭐</b> adds the song to the Best list of the person named in the dropdown; clicking it again removes it.</li>
          </ul>
        </div>

        <div class="kar-gs" id="kar-gs-while" style="display:none">
          <h3 style="margin:0 0 8px;font-size:14.5px;font-weight:800;color:#D2AD6C">While it is playing</h3>
          <ul style="margin:0;padding-left:20px">
            <li>The <b>gold bar</b> controls the song currently playing. It stays at the top of the page as the list scrolls.</li>
            <li><b>Key</b> and <b>Speed</b> take effect immediately, mid-song.</li>
            <li><b>The progress line</b> under the song name shows how far through it is — drag it to move within the song.</li>
            <li><b>▶ Start</b> restarts the song from the beginning. <b>⏹ Stop</b> pauses it where it is and becomes <b>▶ Resume</b>.</li>
            <li><b>🎬 Lyrics Screen</b> hides the lyrics window or brings it back. It otherwise stays in front of the browser while a song plays. To end a song, close that window — <b>Q</b> or its red <b>✕</b> on the Mac.</li>
            <li>Changes made in the gold bar apply to the current performance only. A song's saved key is the <b>Pitch</b> value on its row.</li>
          </ul>
        </div>

        <div class="kar-gs" id="kar-gs-party" style="display:none">
          <h3 style="margin:0 0 8px;font-size:14.5px;font-weight:800;color:#D2AD6C">Party controls</h3>
          <p style="margin:0 0 8px;color:#94a3b8;font-size:12.5px">Four buttons at the top right open a panel. The one that is open is ringed and raised, with a marker pointing at its panel; the others dim, so which panel is open is visible at a glance. The first three each have a card of their own.</p>
          <ul style="margin:0;padding-left:20px">
            <li><b>🎶 Singing Queue</b> — who sings next. Click <span style="display:inline-block;border:1px solid #60A5FA;background:rgba(96,165,250,.14);color:#93c5fd;font-weight:800;border-radius:5px;padding:0 7px;line-height:1.6">＋</span> on a song to add a singer; press <b>▶ Next singer</b> to start each performance. <a href="#" onclick="karGuideOpen('upnext');return false" style="color:#D2AD6C">Open the Singing Queue card</a>.</li>
            <li><b>📱 Guest QR</b> — guests request songs from their own phones. <a href="#" onclick="karGuideOpen('guestqr');return false" style="color:#D2AD6C">Open the Guest QR card</a>.</li>
            <li><b>▶ YouTube Downloads</b> — search YouTube and add songs to the library. <a href="#" onclick="karGuideOpen('downloads');return false" style="color:#D2AD6C">Open the YouTube Downloads card</a>.</li>
            <li><b>📖 Guide</b> — this page.</li>
            <li><b>Closing a panel</b> — press its button again, press <b>✕ Close</b> inside it, or press Esc.</li>
            <li>The purple strip below the buttons reports activity, such as a guest's song arriving.</li>
          </ul>
        </div>

        <div class="kar-gs" id="kar-gs-songs" style="display:none">
          <h3 style="margin:0 0 8px;font-size:14.5px;font-weight:800;color:#D2AD6C">Managing songs</h3>
          <ul style="margin:0;padding-left:20px">
            <li><b>⭐ Best lists</b> — one per person. The dropdown at the top is that list: its menu names every person with the number of songs they have, and selecting a name opens their list. <b>＋ Add a person</b> and <b>− Remove a person</b> are at the foot of the same menu.</li>
            <li><b>Adding to a list</b> — with the person selected, click <b>⭐</b> on a song's row to add it, and again to remove it. Removing a person keeps a copy of their list in the log, so it can be restored.</li>
            <li><b>🆕 New Songs</b> — every song added in the last 30 days. The <b>Duplicate</b> column flags songs that appear to match one already in the library.</li>
            <li><b>✎</b> renames a song. <b>✕</b> removes it: the file is moved to a Deleted folder, not destroyed, and can be restored.</li>
            <li><b>Licensing.</b> These songs are for private use at home. For commercial use — a restaurant, a hall, a ticketed event — point Cantoria at a licensed song library. The songs folder is a setting — see <a href="#" onclick="karGuideOpen('setup');return false" style="color:#D2AD6C">Installing Cantoria</a>.</li>
          </ul>
        </div>


        <div class="kar-gs" id="kar-gs-upnext" style="display:none">
          <h3 style="margin:0 0 8px;font-size:14.5px;font-weight:800;color:#D2AD6C">Singing Queue</h3>
          <div class="kar-gs-body" style="display:grid;gap:9px">        <div><b style="color:#D2AD6C">Add a singer to the queue</b> — select the singer's name in the <b>⭐ Best of</b> dropdown at the top of the page, then click <span style="display:inline-block;border:1px solid #60A5FA;background:rgba(96,165,250,.14);color:#93c5fd;font-weight:800;border-radius:5px;padding:0 7px;line-height:1.6">＋</span> on the song. The entry is queued at the pitch shown on that row.</div>
        <div><b style="color:#D2AD6C">Start the next singer</b> — press <b style="color:#6ee7b7">▶ Next singer</b>. The song at the top of the queue plays and the queue advances automatically.</div>
        <div><b style="color:#D2AD6C">Scheduling fairness</b> (the <span style="display:inline-block;width:11px;height:11px;border:2px solid #6ee7b7;border-radius:3px;vertical-align:-1px;margin:0 3px"></span> beside that button) — when enabled, every singer performs once before anyone performs twice, twice before anyone performs a third time, and so on. The order is managed automatically.</div>
        <div><b style="color:#D2AD6C">Overriding the schedule</b> — <b>↑ ↓</b> move a person up or down, and the <span style="display:inline-block;border:1px solid #7f1d1d;color:#f87171;font-weight:800;border-radius:5px;padding:0 7px;line-height:1.6">✕</span> beside a name removes that entry. <b>Clear the queue</b>, at the right, removes every entry — intended for the end of the night.</div>
          </div>
        </div>

        <div class="kar-gs" id="kar-gs-downloads" style="display:none">
          <h3 style="margin:0 0 8px;font-size:14.5px;font-weight:800;color:#D2AD6C">YouTube Downloads</h3>
          <div class="kar-gs-body" style="display:grid;gap:9px">        <div><b style="color:#fca5a5">1 · Search</b> — enter an artist or a title and press <b style="color:#fca5a5">▶ Search YouTube</b>. Each result carries its duration, its channel, and a warning where the song already exists in your library.</div>
        <div><b style="color:#fca5a5">Karaoke &amp; lyrics only</b> — the checkbox beside the search box, normally ticked. YouTube is asked for the karaoke version, and of the thirty results returned, those shown are the ones naming <b>karaoke</b>, <b>lyrics</b> or <b>testo</b> in the title or the channel — all of them, however many that is. The rest are counted beside the heading and <b>Show them</b> displays them. Untick it to search for the ordinary record instead: your words go to YouTube exactly as typed and every result is shown. The setting is remembered on this computer.</div>
        <div><b style="color:#fca5a5">2 · Download it</b> — <b style="color:#fca5a5">⬇ Download</b> starts it immediately. <b>▶ Watch</b> opens the video on YouTube in a new tab first. Every row you open stays marked — the most recent in red, the earlier ones as <b>✓ watched</b> — so after trying several you can see which they were and download whichever you chose. A row you have sent to the list is marked in green. To send a link to somebody, open it with <b>▶ Watch</b> and copy it from the address bar.</div>
        <div><b style="color:#fca5a5">Or paste a link</b> — paste a YouTube address into the box and press <b>⬇ Download this link</b>. <b>open YouTube ↗</b> opens YouTube in a new tab for videos you prefer to find there.</div>
        <div><b style="color:#fca5a5">3 · It downloads straight away</b> — no second button. Songs are fetched one at a time, typically a minute or two each, and the panel may be closed while this runs. Tap several and they queue up behind one another. <b>Clear the list</b> tidies away anything finished or failed.</div>
        <div><b style="color:#fca5a5">4 · Result</b> — a completed row reads <b style="color:#10B981">✓ Completed</b> and tells you the song is now under <b style="color:#c084fc">🆕 New Songs</b>, which is where songs are renamed, given a key or removed, and where they stay for 30 days. Completed rows remain here while anything else is still downloading — so you can see a whole batch arrive — and clear themselves a couple of minutes after the last one lands. A <b style="color:#f87171">failed</b> download does not clear: it stays here in red with the reason, because a song that never arrived cannot appear under 🆕 New Songs and this is the only place you would ever find out.</div>
        <div><b style="color:#fca5a5">Closing the panel</b> — the <b>YouTube Downloads</b> button closes it and keeps the search results. <b style="color:#fca5a5">✕ Clear</b> discards the search results and closes.</div>
        <div><b style="color:#fca5a5">Guest requests</b> — songs requested from guests’ phones appear here under the guest’s name and download automatically.</div>
          </div>
        </div>

        <div class="kar-gs" id="kar-gs-guestqr" style="display:none">
          <h3 style="margin:0 0 8px;font-size:14.5px;font-weight:800;color:#D2AD6C">Guest QR — songs from guests’ phones</h3>
          <div class="kar-gs-body" style="display:grid;gap:9px">        <div><b style="color:#c084fc">What it is</b> — song requests from guests' own phones. A guest scans the code with the phone camera; no app is required.</div>
        <div><b style="color:#c084fc">What a guest can do</b> — request a song from the library, or add a new one from YouTube. Requests are placed in the <b style="color:#D2AD6C">🎶 Singing Queue</b>.</div>
        <div><b style="color:#c084fc">What a guest cannot do</b> — play, stop, rename or delete anything.</div>
        <div><b style="color:#c084fc">🔄 New code</b> — invalidates every code previously displayed. Use it after a party.</div>
          </div>
        </div>
        <?php if (!$KAR_LOCAL): ?>
        <div class="kar-gs" id="kar-gs-config" style="display:none">
          <h3 style="margin:0 0 8px;font-size:14.5px;font-weight:800;color:#D2AD6C">Configuration and workflow</h3>
          <p style="margin:0 0 10px;color:#94a3b8;font-size:12.5px">Every box is a computer. The first two are the <b style="color:#cbd5e1">same laptop</b>, running two different Cantorias: the master, inside casAI, where all development is done — and a standalone copy, independent of casAI, where each release is tested before anyone else receives it.</p>
          <?php
          // The machines live in the database (karaoke_settings.rollout_chain), NOT in this
          // file. The card is shown on casAI only — but this file is published to a public
          // repository, so no household or machine name may sit in the source. One row per
          // box, rows separated by ";" and fields by "|":
          //     tier|name|line|line|>legend       (any number of caption lines)
          //     tier 1 = the master · 2 = where it is tried · 3 = the houses
          //     a line starting with ">" is NOT drawn in the box — it is that machine's
          //     one-line explanation in the "Who does what" list under the chart.
          $_rcRaw = '';
          if ($pdo) { try { $_rcRaw = (string)$pdo->query("SELECT v FROM karaoke_settings WHERE k='rollout_chain'")->fetchColumn(); } catch (Throwable $e) {} }
          $_rc = [];
          foreach (array_filter(explode(';', $_rcRaw)) as $_row) {
              $_f = array_map('trim', explode('|', $_row));
              if (count($_f) >= 2 && $_f[1] !== '') {
                  $_cap = []; $_leg = ''; $_shared = false;
                  foreach (array_slice($_f, 2) as $_x) {
                      if ($_x === '') continue;
                      if ($_x === '+songs') { $_shared = true; }
                      elseif ($_x[0] === '>') { if ($_leg === '') $_leg = trim(substr($_x, 1)); } else { $_cap[] = $_x; }
                  }
                  $_rc[] = ['t' => (int)$_f[0], 'n' => $_f[1], 'c' => $_cap, 'l' => $_leg,
                            'p' => (stripos(implode(' ', $_cap), 'planned') !== false),
                            // "+songs" = this Mac reads the shared Google Drive folder. Only
                            // the owner's own machines do; everybody else keeps their own copy,
                            // and kar_sync() refuses to reach into somebody else's folder.
                            'g' => $_shared];
              }
          }
          $_spine = array_values(array_filter($_rc, function ($r) { return $r['t'] < 3; }));
          $_house = array_values(array_filter($_rc, function ($r) { return $r['t'] >= 3; }));
          // One colour per tier, used identically in the chart and the list beneath it, so
          // the eye can go from a box to its explanation without reading.
          $_tc = [1 => ['st' => '#D2AD6C', 'fi' => '#2b2417', 'nm' => '#f3d9a4', 'cp' => '#cbd5e1', 'lb' => '1 · Build'],
                  2 => ['st' => '#60A5FA', 'fi' => '#16233a', 'nm' => '#bfdbfe', 'cp' => '#a5b4c8', 'lb' => '2 · Test'],
                  3 => ['st' => '#6ee7b7', 'fi' => '#15302a', 'nm' => '#d1fae5', 'cp' => '#a5b4c8', 'lb' => '3 · Sing']];
          $_sc = ['st' => '#c084fc', 'fi' => '#2a1f3d', 'nm' => '#e9d5ff', 'cp' => '#c4b5fd'];
          if ($_spine && $_house):
              $_W = 760; $_x0 = 100; $_cw = $_W - $_x0 - 8; $_mid = (int)($_x0 + $_cw / 2);
              // The master is deliberately the biggest box on the chart.
              $_dim = function ($t) { return $t === 1 ? [360, 96] : ($t === 2 ? [300, 82] : [208, 84]); };
              // Shrink a name that will not fit its box. Boxes narrow as houses are added
              // (208px at one, ~116px at five), so a long name must be made to fit, not assumed
              // to. 0.57 em per character is measured from a real render of "a family member Laptop"
              // in this bold serif - re-measure if the font ever changes.
              $_fit = function (string $t, float $box, float $size) {
                  $w = mb_strlen($t) * $size * 0.57;
                  $room = $box - 16;
                  return $w > $room ? max(11.0, round($size * $room / $w, 1)) : $size;
              };
              $_hn  = count($_house);
              [$_hw0, $_hh] = $_dim(3);
              $_hw  = (int)min($_hw0, ($_cw - ($_hn - 1) * 18) / $_hn);
              $_hsp = $_hw + 18;
              $_hx0 = (int)($_x0 + ($_cw - ($_hn * $_hw + ($_hn - 1) * 18)) / 2);
              $_cx  = function ($i) use ($_hx0, $_hsp, $_hw) { return (int)($_hx0 + $i * $_hsp + $_hw / 2); };
              // Walk the spine downward, remembering where each box sits. The gap between
              // boxes holds the arrow AND its label, so it is wider than before.
              // The title lives INSIDE the svg so it centres on $_mid - the middle of the boxes -
              // and not on the drawing's own middle. They are 46px apart, because the left 100px
              // is reserved for the tier badges. An html title above the chart sits visibly left.
              $_titleH = 28;
              $_y = []; $_cursor = 8 + $_titleH;
              foreach ($_spine as $_i => $_s) { [$_w, $_hgt] = $_dim($_s['t']); $_y[$_i] = $_cursor; $_cursor += $_hgt + 56; }
              $_botY  = $_cursor - 56;
              $_railY = $_botY + 30;
              $_hy    = $_railY + 22;
              $_sy    = $_hy + $_hh + 34;      // the shared songs folder, a band under the houses
              $_sh    = 50;
              $_H     = $_sy + $_sh + 8;
              $_rows = function ($n) { return $n >= 2 ? [0.34, 0.61, 0.83] : ($n === 1 ? [0.42, 0.73] : [0.62]); };
              // A small tier badge in the left margin, level with its row.
              $_badge = function ($y, $c, $txt) {
                  return '<rect x="8" y="' . (int)($y - 12) . '" width="82" height="24" rx="12" fill="' . $c . '" fill-opacity="0.16" stroke="' . $c . '" stroke-width="1"/>'
                       . '<text x="49" y="' . (int)($y + 4) . '" text-anchor="middle" fill="' . $c . '" font-family="inherit" font-size="11" font-weight="800">' . h($txt) . '</text>';
              };
          ?>
          <svg viewBox="0 0 <?= $_W ?> <?= $_H ?>" style="width:100%;max-width:<?= $_W ?>px;height:auto;display:block;margin:2px auto 10px" role="img" aria-label="Which computer is which, the order a change reaches them, and the one songs folder they all share">
            <defs>
              <marker id="karArrB" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6" markerHeight="6" orient="auto"><path d="M0,0 L10,5 L0,10 z" fill="#60A5FA"/></marker>
              <marker id="karArrG" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6" markerHeight="6" orient="auto"><path d="M0,0 L10,5 L0,10 z" fill="#6ee7b7"/></marker>
            </defs>
            <text x="<?= $_mid ?>" y="20" text-anchor="middle" fill="#D2AD6C" font-family="inherit" font-size="15" font-weight="800" letter-spacing="0.3">Cantoria Development and Update Process</text>
            <?php foreach ($_spine as $_i => $_s):
                  [$_w, $_hgt] = $_dim($_s['t']);
                  $_c  = $_tc[$_s['t']] ?? $_tc[2];
                  $_x  = (int)($_mid - $_w / 2);
                  $_ms = ($_s['t'] === 1);
                  $_r  = $_rows(count($_s['c'])); ?>
            <?= $_badge($_y[$_i] + $_hgt / 2, $_c['st'], $_c['lb']) ?>
            <rect x="<?= $_x ?>" y="<?= $_y[$_i] ?>" width="<?= $_w ?>" height="<?= $_hgt ?>" rx="12" fill="<?= $_c['fi'] ?>" stroke="<?= $_c['st'] ?>" stroke-width="<?= $_ms ? 2.2 : 1.6 ?>"/>
            <text x="<?= $_mid ?>" y="<?= (int)($_y[$_i] + $_hgt * $_r[0]) ?>" text-anchor="middle" fill="<?= $_c['nm'] ?>" font-family="inherit" font-size="<?= $_fit($_s['n'], $_w, $_ms ? 19 : 16) ?>" font-weight="800"><?= h($_s['n']) ?></text>
            <?php foreach ($_s['c'] as $_li => $_ln): ?><text x="<?= $_mid ?>" y="<?= (int)($_y[$_i] + $_hgt * $_r[$_li + 1]) ?>" text-anchor="middle" fill="<?= $_c['cp'] ?>" font-family="inherit" font-size="13"><?= h($_ln) ?></text><?php endforeach; ?>
            <?php if ($_i < count($_spine) - 1): $_ny = $_y[$_i + 1]; ?>
            <line x1="<?= $_mid ?>" y1="<?= $_y[$_i] + $_hgt ?>" x2="<?= $_mid ?>" y2="<?= $_ny - 6 ?>" stroke="#60A5FA" stroke-width="2" marker-end="url(#karArrB)"/>
            <text x="<?= $_mid + 12 ?>" y="<?= (int)(($_y[$_i] + $_hgt + $_ny) / 2 + 4) ?>" fill="#93c5fd" font-family="inherit" font-size="11.5" font-weight="600">new version released</text>
            <?php endif; ?>
            <?php endforeach; ?>

            <line x1="<?= $_mid ?>" y1="<?= $_botY ?>" x2="<?= $_mid ?>" y2="<?= $_railY ?>" stroke="#6ee7b7" stroke-width="2"/>
            <text x="<?= $_mid + 12 ?>" y="<?= $_botY + 11 ?>" fill="#6ee7b7" font-family="inherit" font-size="11.5" font-weight="600">retrieved by each user</text>
            <text x="<?= $_mid + 12 ?>" y="<?= $_botY + 25 ?>" fill="#6ee7b7" font-family="inherit" font-size="11.5" font-weight="600">from the 📖 Guide button on their Mac</text>
            <?php if ($_hn > 1): ?><line x1="<?= $_cx(0) ?>" y1="<?= $_railY ?>" x2="<?= $_cx($_hn - 1) ?>" y2="<?= $_railY ?>" stroke="#6ee7b7" stroke-width="2"/><?php endif; ?>
            <?= $_badge($_hy + $_hh / 2, $_tc[3]['st'], $_tc[3]['lb']) ?>
            <?php foreach ($_house as $_i => $_hb):
                  $_pl = $_hb['p']; $_c = $_tc[3];
                  $_r  = $_rows(count($_hb['c'])); ?>
            <line x1="<?= $_cx($_i) ?>" y1="<?= $_railY ?>" x2="<?= $_cx($_i) ?>" y2="<?= $_hy - 6 ?>" stroke="#6ee7b7" stroke-width="2" marker-end="url(#karArrG)"<?= $_pl ? ' stroke-dasharray="5 4"' : '' ?>/>
            <rect x="<?= (int)($_hx0 + $_i * $_hsp) ?>" y="<?= $_hy ?>" width="<?= $_hw ?>" height="<?= $_hh ?>" rx="12" fill="<?= $_pl ? 'none' : $_c['fi'] ?>" stroke="<?= $_c['st'] ?>" stroke-width="1.6"<?= $_pl ? ' stroke-dasharray="5 4" stroke-opacity="0.7"' : '' ?>/>
            <text x="<?= $_cx($_i) ?>" y="<?= (int)($_hy + $_hh * $_r[0]) ?>" text-anchor="middle" fill="<?= $_pl ? '#94a3b8' : $_c['nm'] ?>" font-family="inherit" font-size="<?= $_fit($_hb['n'], $_hw, 16) ?>" font-weight="800"><?= h($_hb['n']) ?></text>
            <?php foreach ($_hb['c'] as $_li => $_ln): ?><text x="<?= $_cx($_i) ?>" y="<?= (int)($_hy + $_hh * $_r[$_li + 1]) ?>" text-anchor="middle" fill="<?= $_c['cp'] ?>" font-family="inherit" font-size="12.5"><?= h($_ln) ?></text><?php endforeach; ?>
            <?php if ($_hb['g']): ?>
            <line x1="<?= $_cx($_i) ?>" y1="<?= $_hy + $_hh ?>" x2="<?= $_cx($_i) ?>" y2="<?= $_sy ?>" stroke="#c084fc" stroke-width="1.6" stroke-dasharray="3 4"/>
            <?php else: ?>
            <text x="<?= $_cx($_i) ?>" y="<?= (int)($_hy + $_hh + 20) ?>" text-anchor="middle" fill="#94a3b8" font-family="inherit" font-size="11.5" font-style="italic">its own copy of the songs</text>
            <?php endif; ?>
            <?php endforeach; ?>

            <?= $_badge($_sy + $_sh / 2, $_sc['st'], 'Songs') ?>
            <rect x="<?= $_x0 ?>" y="<?= $_sy ?>" width="<?= $_cw ?>" height="<?= $_sh ?>" rx="12" fill="<?= $_sc['fi'] ?>" stroke="<?= $_sc['st'] ?>" stroke-width="1.6"/>
            <text x="<?= $_mid ?>" y="<?= $_sy + 21 ?>" text-anchor="middle" fill="<?= $_sc['nm'] ?>" font-family="inherit" font-size="14" font-weight="800">🎵 One songs folder in Google Drive — shared by my own Macs only</text>
            <?php /* ⚠ the band is $_cw (652px) wide: a sub-line past ~110 characters at 12px is clipped at the right edge, silently. */ ?>
            <text x="<?= $_mid ?>" y="<?= $_sy + 39 ?>" text-anchor="middle" fill="<?= $_sc['cp'] ?>" font-family="inherit" font-size="12">the laptop and my two minis see the same songs · everyone else keeps their own copy</text>
          </svg>
          <p style="margin:-2px 0 14px;color:#94a3b8;font-size:12px;text-align:center">Arrows show the order in which a release reaches each computer, not a network connection, and the dotted lines indicate the shared songs folder rather than a connection. Each Mac installs its own update; nothing is pushed from here.</p>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:0 0 14px">
            <div style="background:#0d1117;border:1px solid #334155;border-radius:10px;padding:11px 14px">
              <div style="font-size:12px;font-weight:800;color:#f3f4f6;margin-bottom:8px;letter-spacing:.02em">ROLES</div>
              <div style="display:grid;gap:7px;font-size:12.5px;line-height:1.5">
              <?php foreach ($_rc as $_m): $_c = $_tc[$_m['t']] ?? $_tc[3]; ?>
                <div style="display:flex;gap:9px;align-items:flex-start"><span style="flex:0 0 11px;width:11px;height:11px;margin-top:4px;border-radius:50%;background:<?= $_m['p'] ? 'none' : $_c['st'] ?>;border:2px <?= $_m['p'] ? 'dashed' : 'solid' ?> <?= $_c['st'] ?>"></span><span><b style="color:<?= $_c['nm'] ?>"><?= h($_m['n']) ?></b> — <?= h($_m['l'] !== '' ? $_m['l'] : implode(', ', $_m['c'])) ?></span></div>
              <?php endforeach; ?>
              </div>
            </div>
            <div style="background:#0d1117;border:1px solid #334155;border-radius:10px;padding:11px 14px">
              <div style="font-size:12px;font-weight:800;color:#f3f4f6;margin-bottom:8px;letter-spacing:.02em">RELEASE PROCESS</div>
              <div style="display:grid;gap:7px;font-size:12.5px;line-height:1.5">
                <div style="display:flex;gap:9px"><b style="flex:0 0 18px;color:#D2AD6C">1</b><span><b style="color:#f3d9a4">Development</b> — all changes are made in casAI, on the master Cantoria.</span></div>
                <div style="display:flex;gap:9px"><b style="flex:0 0 18px;color:#60A5FA">2</b><span><b style="color:#bfdbfe">Testing</b> — each release is then installed on the standalone copy, on the same laptop, and used before distribution.</span></div>
                <div style="display:flex;gap:9px"><b style="flex:0 0 18px;color:#6ee7b7">3</b><span><b style="color:#d1fae5">Installation</b> — each user retrieves the release on their own Mac, from the 📖 Guide button. Songs, lists and saved keys are unaffected.</span></div>
                <div style="display:flex;gap:9px"><b style="flex:0 0 18px;color:#c084fc">♪</b><span><b style="color:#e9d5ff">Songs</b> — not part of any release. My own Macs read one shared Google Drive folder; every other Mac keeps its own copy.</span></div>
              </div>
            </div>
          </div>
          <?php else: ?>
          <p style="margin:0 0 12px;color:#94a3b8;font-size:12.5px">The chart cannot be drawn — the machine list has not been set.</p>
          <?php endif; ?>

          <div style="display:grid;gap:9px">
            <div><b style="color:#D2AD6C">This page contains no audio</b> — it lists the contents of the shared folder and sends playback requests to a Mac.</div>
            <div><b style="color:#D2AD6C">⭐ Best lists</b> — my own Macs synchronize their lists with each other; a Mac belonging to somebody else shares nothing. This page maintains its own, so a person's list here may differ from the list on the Macs.</div>
            <div><b style="color:#D2AD6C">If a song does not play</b> — the Mac is asleep, the wrong Mac is selected under <b>Play on</b>, or that Mac points to a different songs folder.</div>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($KAR_LOCAL): ?>
        <div class="kar-gs" id="kar-gs-update" style="display:none">
          <h3 style="margin:0 0 8px;font-size:14.5px;font-weight:800;color:#D2AD6C">Software updates</h3>
          <p style="margin:0 0 8px">Installs the latest version. <b>Songs, settings, Best lists and saved keys are preserved</b>; only the program is replaced.</p>
          <button type="button" onclick="karUpdate()" id="kar-upd-btn" style="font-family:inherit;margin:2px 0;background:rgba(210,173,108,.12);border:1px solid #D2AD6C;color:#D2AD6C;cursor:pointer;font-size:13px;font-weight:700;padding:8px 16px;border-radius:8px">⬆︎ Cantoria Software Update</button>
          <div id="kar-upd-state" style="display:none;margin-top:8px;padding:9px 13px;border-radius:8px;font-size:13px;font-weight:700;line-height:1.6"></div>
          <span id="kar-upd-msg" style="display:block;margin-top:6px;color:#94a3b8;font-size:12px">Installed version: <b id="kar-upd-ver" style="color:#cbd5e1"><?= h(kar_installed_version()) ?></b></span>
        </div>
        <?php else: ?>
        <?php /* On the master there is no update to retrieve — this is where releases are made.
                The card exists so the release path is written down in the same place the other
                Macs read it: "in this computer we make the changes directly... but number three
                needs to stay here to explain that other computers will have the button they
                click to get the update" (2026-09-12). */ ?>
        <div class="kar-gs" id="kar-gs-update" style="display:none">
          <h3 style="margin:0 0 8px;font-size:14.5px;font-weight:800;color:#D2AD6C">Software updates</h3>
          <div class="kar-gs-body" style="display:grid;gap:9px">        <div><b style="color:#D2AD6C">This Mac</b> — the development machine. Changes are made here directly and released from here, so there is nothing to retrieve and no update button on this copy.</div>
        <div><b style="color:#D2AD6C">Every other Mac</b> — opens its own <b>📖 Guide → Software updates</b> and presses <b style="color:#6ee7b7">⬆︎ Cantoria Software Update</b>. Each machine installs the release itself; nothing is sent to it from here.</div>
        <div><b style="color:#D2AD6C">What is preserved</b> — on those machines the songs, settings, Best lists and saved keys are kept. Only the program is replaced.</div>
          </div>
        </div>
        <?php endif; ?>

      </div>
    </div>
    <div id="kar-yt-hint" style="display:none;margin-top:8px;background:rgba(210,173,108,.07);border:1px solid rgba(210,173,108,.25);border-radius:8px;padding:8px 14px;font-size:12.5px;color:#94a3b8"></div>
    <div id="kar-activity" style="display:none;margin-top:8px;background:rgba(192,132,252,.08);border:1px solid rgba(192,132,252,.35);border-radius:8px;padding:8px 14px;font-size:12.5px;color:#e2e8f0;line-height:1.6"></div>
    <div id="kar-dl-panel" style="display:none;margin-top:10px;background:#20171d;border:1px solid rgba(239,68,68,.5);border-radius:12px;padding:14px 16px;box-shadow:0 10px 30px rgba(0,0,0,.55)">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
        <button type="button" onclick="karDlAdd()" id="kar-dl-add" title="Download the pasted YouTube link now" style="font-family:inherit;background:#3f4757;border:1px solid #566072;color:#e2e8f0;cursor:pointer;font-size:13px;font-weight:800;padding:8px 16px;border-radius:8px;white-space:nowrap;width:186px">⬇ Download this link</button>
        <input id="kar-dl-url" type="text" placeholder="Paste the YouTube link of the song here…" style="font-family:inherit;flex:1;min-width:240px;background:#0d1118;border:1px solid #334155;border-radius:8px;color:#e2e8f0;font-size:13px;padding:8px 12px">
        <div class="kar-dlrt"></div>
      </div>
      <!-- The search box sits ON the header line rather than in a section of its own: the
           panel title and a "SEARCH YOUTUBE" label said the same thing twice and pushed the
           field down the page (the owner, 2026-09-12: "we don't need those two things, we only
           need one... move that up as much as possible so we don't waste any space").
           The page cannot run yt-dlp, so the Mac answers the search — the same machinery the
           guest page has had since 2026-09-08. -->
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px">
        <button type="button" onclick="karYtSearch()" id="kar-yt-btn" title="Search YouTube for a song" style="font-family:inherit;background:#EF4444;border:1px solid #EF4444;color:#fff;cursor:pointer;font-size:13px;font-weight:800;padding:8px 16px;border-radius:8px;white-space:nowrap;width:186px">▶ Search YouTube</button>
        <input id="kar-yt-q" type="text" placeholder="Type a singer or a song…" style="font-family:inherit;flex:1;min-width:240px;background:#0d1118;border:1px solid #334155;border-radius:8px;color:#e2e8f0;font-size:13px;padding:8px 12px">
        <!-- Ticked, which is the normal case, this does TWO things: YouTube is asked for the
             karaoke version, and results that name none of karaoke / lyrics / testo are held
             back behind a count. Unticked, the words go to YouTube exactly as typed and
             everything is shown — for the rare case of wanting the ordinary record rather
             than something to sing to (the owner, 2026-09-14). -->
        <label style="display:flex;align-items:center;gap:6px;color:#94a3b8;font-size:12.5px;font-weight:600;cursor:pointer;white-space:nowrap" title="On: YouTube is asked for the karaoke version, and results without karaoke, lyrics or testo in the name are held back behind a count. Off: your words go to YouTube exactly as typed and every result is shown — for when you want the ordinary song rather than something to sing to.">
          <input type="checkbox" id="kar-yt-only" checked onchange="KAR_YT_ONLY=this.checked;try{localStorage.setItem('kar_yt_only',this.checked?'1':'0')}catch(e){}">
          Karaoke &amp; lyrics only
        </label>
        <div class="kar-dlrt">
          <button type="button" onclick="karDlClearClose()" title="Wipe the search results and close. To close WITHOUT wiping them, click the YouTube Downloads button above." style="font-family:inherit;background:rgba(239,68,68,.14);border:1px solid rgba(239,68,68,.55);color:#fca5a5;cursor:pointer;font-size:12px;font-weight:700;padding:5px 12px;border-radius:8px">✕ Clear</button>
          <a href="https://www.youtube.com" target="_blank" rel="noopener" onclick="karYtHint()" title="Browse YouTube itself in a new tab; copy a link and paste it here" style="font-family:inherit;background:#EF4444;border:1px solid #EF4444;color:#fff;font-size:12.5px;font-weight:700;text-decoration:none;padding:7px 13px;border-radius:8px;white-space:nowrap">▶ open YouTube ↗</a>
          <button type="button" id="kar-helpbtn-dl" onclick="karHelpToggle('dl')" class="kar-helpbtn" title="Show or hide how this panel works — your choice is remembered on this computer"><span class="kar-helpico kar-helpico-big"></span></button>
        </div>
      </div>
      <!-- Instructions live in their own block, opened by the ? button, instead of as small
           grey print always on screen (the owner, 2026-09-07: "I see a lot of explanation...
           it would allow us to organize what we wanna say in a better way"). Shown by default
           so a new machine teaches its owner; hidden for good once dismissed. -->
      <div id="kar-help-dl" class="kar-help" style="display:none"><button type="button" onclick="karHelpToggle('dl')" title="Close" style="float:right;margin:-2px -4px 0 8px;font-family:inherit;background:none;border:none;color:#94a3b8;cursor:pointer;font-size:14px;font-weight:700;line-height:1">✕</button>

      </div>
      <div style="border-top:1px solid #1e293b;margin:12px 0 10px"></div>
      <div id="kar-yt-res"></div>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:15px 0 6px">
        <button type="button" onclick="karDlStart()" id="kar-dl-start" title="Download everything in the list below" style="font-family:inherit;background:#16a34a;border:1px solid #16a34a;color:#fff;cursor:pointer;font-size:13px;font-weight:800;padding:8px 16px;border-radius:8px;white-space:nowrap;width:186px">⬇ Download the list</button>
      </div>
      <div style="display:flex;gap:10px;align-items:center">
        <div id="kar-dl-list" style="flex:1;min-width:0;border:1px solid rgba(239,68,68,.28);border-radius:9px;background:rgba(0,0,0,.22);padding:8px 10px;min-height:84px"></div>
        <div class="kar-dlrt"><button type="button" onclick="karDlClear()" title="Empties the whole list at once — removes the links only, no files are touched" style="font-family:inherit;background:none;border:1px solid #334155;color:#94a3b8;cursor:pointer;font-size:12.5px;font-weight:600;padding:7px 14px;border-radius:8px">Clear the list</button></div>
      </div>
    </div>
    <div id="kar-q-panel" style="display:none;margin-top:10px;background:#121620;border:1px solid rgba(210,173,108,.35);border-radius:10px;padding:14px 16px">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
        <span style="font-size:13.5px;font-weight:800;color:#D2AD6C">🎶 Singing Queue</span>
        <button type="button" onclick="karQClear()" style="margin-left:auto;font-family:inherit;background:none;border:1px solid #334155;color:#94a3b8;cursor:pointer;font-size:12px;font-weight:600;padding:5px 12px;border-radius:8px">Clear the queue</button>
        <button type="button" onclick="karPanelClose()" title="Close this panel (or press Esc)" style="font-family:inherit;background:none;border:1px solid #334155;color:#94a3b8;cursor:pointer;font-size:12px;font-weight:600;padding:5px 12px;border-radius:8px">✕ Close</button>
        <button type="button" id="kar-helpbtn-q" onclick="karHelpToggle('q')" class="kar-helpbtn" style="margin-left:0" title="Show or hide how this panel works — your choice is remembered on this computer"><span class="kar-helpico kar-helpico-big"></span></button>
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
      </div>
      <div id="kar-q-now" style="display:none;margin-top:10px;color:#D2AD6C;font-size:13.5px;font-weight:700"></div>
      <div id="kar-q-list" style="margin-top:4px"></div>
    </div>
    <div id="kar-qr-panel" style="display:none;margin-top:10px;background:#121620;border:1px solid rgba(192,132,252,.4);border-radius:10px;padding:16px 18px">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
        <span style="font-size:13.5px;font-weight:800;color:#c084fc">📱 Guest QR</span>
        <button type="button" onclick="karPanelClose()" title="Close this panel (or press Esc)" style="margin-left:auto;font-family:inherit;background:none;border:1px solid #334155;color:#94a3b8;cursor:pointer;font-size:12px;font-weight:600;padding:5px 12px;border-radius:8px">✕ Close</button>
        <button type="button" id="kar-helpbtn-qr" onclick="karHelpToggle('qr')" class="kar-helpbtn" style="margin-left:0" title="Show or hide how this panel works — your choice is remembered on this computer"><span class="kar-helpico kar-helpico-big"></span></button>
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
          <p style="margin:12px 0 5px;color:#94a3b8;font-size:11.5px">Wi-Fi network printed on the sheet. This is <b style="color:#cbd5e1">per computer</b> — each Mac keeps its own, because each one sits on its own network. macOS will not tell us the name, so type it once:</p>
          <input id="kar-qr-wifi" type="text" maxlength="60" placeholder="your Wi-Fi network name" onchange="karQrSaveWifi()" style="font-family:inherit;width:230px;background:#0d1118;border:1px solid #334155;border-radius:8px;color:#e2e8f0;font-size:12.5px;padding:7px 10px;transition:border-color .2s">
          <button type="button" onclick="karQrPrint()" title="Print one page with a big code — tape it on the wall so guests scan it there instead of crowding the Mac" style="margin-top:12px;margin-right:8px;font-family:inherit;background:rgba(192,132,252,.14);border:1px solid #c084fc;color:#e9d5ff;cursor:pointer;font-size:12.5px;font-weight:700;padding:7px 14px;border-radius:8px">🖨 Print it</button>
          <button type="button" onclick="karQrWindow()" id="kar-qr-win" title="Puts the code in the corner of the lyrics screen, on top of everything, so the whole room can see it while you run the party" style="margin-top:12px;margin-right:8px;font-family:inherit;background:rgba(192,132,252,.14);border:1px solid #c084fc;color:#e9d5ff;cursor:pointer;font-size:12.5px;font-weight:700;padding:7px 14px;border-radius:8px">📺 Show on the lyrics screen</button>
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
    <div id="kar-songs-area">
    <div id="kar-count" style="margin-top:10px;color:#64748b;font-size:11.5px"></div>
    <!-- Two group headings over the row: the left half is about setting a song up, the right half
         about singing it. These are hand-aligned to the controls below, so the numbers must stay in
         step with them. Measured from the container's own left edge: the list box adds 1px of border
         and each row 6px of padding, so BOTH heading rows carry 7px of extra left padding (16+7=23)
         to sit over the row. The SET UP label then spans its three controls — pitch 114 + star 48 +
         delete 48 with 12px gaps = 234, inset 9px (the section band's 8px padding + 1px border) —
         and the spacer runs to where the ＋ Add button starts. -->
    <div id="kar-bands" style="display:flex;align-items:flex-end;gap:0;margin-top:14px;padding:0 16px 0 23px;font-size:10px;font-weight:800;letter-spacing:.10em;text-transform:uppercase">
      <span style="flex:0 0 auto;width:234px;margin-left:9px;text-align:center;color:#6ee7b7;border-bottom:1px solid rgba(110,231,183,.35);padding-bottom:3px" title="How the song is set up: its key, whether it is on someone&#39;s Best list, and removing it">Set up</span>
      <span style="flex:0 0 auto;width:70px"></span>
      <span style="flex:1;min-width:0;color:#6ee7b7;border-bottom:1px solid rgba(110,231,183,.35);padding-bottom:3px" title="Singing it: queue it for someone, play it now, or rename it">Play and sing</span>
    </div>
    <div style="display:flex;align-items:flex-end;gap:12px;margin-top:6px;padding:0 16px 0 23px;font-size:10.5px;font-weight:700;letter-spacing:.04em;line-height:1.3;text-transform:uppercase;color:#94a3b8">
      <span class="kar-sect kar-sect-a">
      <span style="flex:0 0 auto;width:114px;text-align:center" title="The pitch the Play button uses. − / + change it a semitone at a time, or type a number — it saves by itself (gold = your saved pitch). ⟲ drops it to 0 for one play only, for a guest singer, then your pitch comes back.">Pitch</span>
      <span style="flex:0 0 auto;width:48px;text-align:center" title="⭐ = on the selected person's Best list — click the star to add or remove the song for whoever is picked in the dropdown at the top">Best<br>List</span>
      <span id="kar-h-del" style="flex:0 0 auto;width:48px;text-align:center" title="✕ removes the song — the file is moved to the 09-Deleted by casAI folder (recoverable), never destroyed">Delete</span>
      </span>
      <span class="kar-sectgap"></span>
      <span class="kar-sect kar-sect-b">
      <span id="kar-h-add" style="flex:0 0 auto;width:58px;text-align:center" title="➕ adds the song to the singing queue, for the person picked in the dropdown, at the pitch shown">Add to<br>Queue</span>
      <span id="kar-h-qmidi" style="flex:0 0 auto;width:58px;text-align:center" title="Plays the song in QMidi, at the pitch shown in the Pitch box">Play<br>QMidi</span>
      <span id="kar-h-casai" style="flex:0 0 auto;width:58px;text-align:center" title="Plays the song with casAI's own player, at the pitch shown in the Pitch box. Press Q on the Mac keyboard to close its window">Play<br>casAI</span>
      <span id="kar-h-seq" style="flex:0 0 auto;width:54px;text-align:center" title="Just a count of the list you are looking at — the top song is always 1. Sort it differently, search it, or switch to a Best list and it counts again from 1.">Seq<br>Number</span>
      <span id="kar-h-song" onclick="karSortToggle()" style="flex:0 0 auto;width:460px;cursor:pointer;user-select:none" title="Click a song&#39;s name to rename it. Click THIS heading to sort — A→Z, then Z→A, then back to the normal order">Song Filename</span>
      <span id="kar-h-dup" style="flex:0 0 auto;width:300px;display:none" title="Songs already in your library that this one looked like when it came down. Play both, keep the better one, remove the other with ✕">Duplicate</span>
      </span>
    </div>
    <!-- Songs that have just come down. A download must end in something you can press, not in
           a hunt: the owner, 2026-09-18 — "I just want that song we download to show up as a simple
           click and play". It is ALSO the only place a FAILED download is visible in simple mode,
           where the Downloads panel is hidden; without it a song that never arrives fails silently. -->
    <div id="kar-arrivals" style="display:none;margin:0 0 8px"></div>
    <div id="kar-list" style="margin-top:4px;background:#121620;border:1px solid #334155;border-radius:10px;padding:6px 16px;height:calc(100vh - 275px);min-height:300px;overflow-y:auto"></div>
    <p style="margin:10px 0 0;color:#64748b;font-size:11.5px">List updated <?= h($_kjGen ?: 'unknown') ?> from the Google Drive song folders on the Mac · how everything works is under <b style="color:#94a3b8">📖 Guide</b> at the top.</p>
    </div>
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
      // The dropdown IS the Best-of box now (the owner, 2026-09-13: "why do I need to click on
      // Best of Claude… is there a way to eliminate one box"). Its own option carries the name
      // AND the count, so one control does what two used to.
      var sel = document.getElementById('kar-who');
      if (sel) {
        var o = sel.querySelector('option[value="' + (karWho || '').replace(/"/g, '\\"') + '"]');
        if (o) o.textContent = '⭐ Best of ' + karWho + ' ' + KAR_DATA.best.length;
        if (sel.value !== karWho) sel.value = karWho;
      }
    }
    // Re-picking the SAME name fires no change event, so without this you could never get
    // back to the Best list from Song Database or 🆕 New once the chip was gone. Touching the
    // dropdown at all means "show me this person's songs" - which is what you wanted anyway.
    function karWhoTouch(){
      if (karView !== 'best') karSwitch('best', document.getElementById('kar-who'));
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
          opt.value = nn; opt.textContent = '⭐ Best of ' + nn + ' 0';
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
          karSwitch('best', document.getElementById('kar-who'));
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
      karSwitch('best', document.getElementById('kar-who'));
    }
    // The pitch written into the file name. Two notations are in real use: (0) (-3) on most of
    // the library, and [-2] [+1] on a couple of dozen older files. Parentheses win when both are
    // present. Brackets are accepted ONLY when signed — a bare [2] is far more likely to mean
    // "version 2" than a pitch, and [C] [Am] in those same names are chord tags, not numbers.
    function karFnPitch(n){
      var m = n.match(/\(([+-]?\d{1,2})\)/) || n.match(/\[([+-]\d{1,2})\]/);
      return m ? parseInt(m[1], 10) : null;
    }
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
      // Choosing a list means "show me songs now" - so close whatever panel is open, or the
      // list you just asked for renders underneath it and the click looks like it did nothing
      // (the owner, 2026-09-13). The panel is one click away again whenever you want it.
      karPanelClose();
      karView = view;
      document.querySelectorAll('.kar-chip').forEach(function(b){
        b.classList.remove('kar-on');
        b.style.background='#1a2230'; b.style.borderColor='#334155'; b.style.color='#94a3b8';   // one quiet grey for all of them; the active one is lifted below is active
      });
      btn.classList.add('kar-on');
      btn.style.background='#334155'; btn.style.borderColor='#94a3b8'; btn.style.color='#f1f5f9';
      // Picking a list means "show me my songs", so the SEARCH MODE follows the view. Without
      // this the list came back but the bar still said YouTube, and typing in it did nothing -
      // which is indistinguishable from a frozen page. the owner, 2026-09-18: "I clicked on the
      // song database, but it wouldn't go back... doesn't allow me to do anything."
      if (KAR_SMODE !== 'list') karSetMode('list', true);
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
      var found = false;
      if (saved) { for (var i=0;i<sel.options.length;i++) if (sel.options[i].value === saved) { sel.value = saved; found = true; } }
      // a stale remembered name (a Mac renamed or removed) must not keep resurfacing
      if (saved && !found) { try { localStorage.removeItem('kar_mac'); } catch(e){} }
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

    // The song list used to be sized by a GUESS — height:calc(100vh - 275px) — so the moment
    // anything above it was taller than those 275 pixels (a panel open, the header wrapping on
    // a narrower window) the page itself overflowed. Scrolling then carried the header buttons
    // off the top and stopped dead a couple of inches later, because the list has its own
    // scrollbar and the page had nothing more to give (the owner, 2026-09-14: "they go up enough
    // where the top of the page gets hidden... is that intentional, or is that a mistake?").
    // It was a mistake. Measure the space that is actually left instead, so the page fits the
    // window, the header never leaves, and only the list scrolls.
    function karFitList(){
      var l = document.getElementById('kar-list');
      if (!l) return;
      // Document-relative top, so the answer does not change with how far the page is scrolled.
      var top = l.getBoundingClientRect().top + (window.pageYOffset || document.documentElement.scrollTop || 0);
      var h = window.innerHeight - top - 14;   // a little breathing room under the list
      // ⚠ the stylesheet carries min-height:300px, which WINS over an inline height and
      // silently reinstates the overflow on a short window. Stand it down; the floor below
      // is the real one.
      l.style.minHeight = '0';
      l.style.height = Math.max(220, Math.round(h)) + 'px';
    }
    window.addEventListener('resize', karFitList);

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
      var rowNo = 0;      // a plain count of what is on screen — the top row is always 1
      for (var k = 0; k < order.length; k++) {
        var i = order[k];
        var full = src[i];
        if (q && full.toLowerCase().indexOf(q) === -1) continue;
        rowNo++;
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
          + '<span class="kar-sect kar-sect-a">'
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
          + '</span>'                                   // end SET UP
          + '<span class="kar-sectgap"></span>'
          + '<span class="kar-sect kar-sect-b">'       // begin SING
          // Add and Play sit side by side in SING and are the two things you can DO with a song,
          // so they are built to the same shape — only the colour tells them apart.
          + '<button type="button" class="kar-q-add" data-i="' + i + '" title="Add to the singing queue for ' + karEsc(karWho) + ', at the pitch shown" '
          + 'style="font-family:inherit;flex:0 0 auto;width:58px;cursor:pointer;font-size:11px;padding:3px 0;border-radius:6px;'
          + 'background:rgba(96,165,250,.12);border:1px solid #334155;color:#93c5fd">＋ Add</button>'
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
          // Nothing but the row's place in the list on screen, counted fresh on every render —
          // sort, search or switch list and it starts at 1 again. It is a way to say "play 129"
          // out loud, not an identity: it deliberately has no tie to the song or the database.
          + '<span class="kar-num" style="flex:0 0 auto;width:54px;text-align:center;font-size:13px;'
          + 'font-variant-numeric:tabular-nums;color:' + (playing ? '#D2AD6C' : '#94a3b8') + '">'
          + rowNo + '</span>'
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
          + '</span>'                                   // end SING
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
      listEl.innerHTML = out.length ? (out.join('') + karSimpleYtFoot(q))
        : (karView === 'best' && !src.length
           ? '<p style="color:#94a3b8;font-size:13px">' + karEsc(karWho) + '’s list is empty — open 🗂 Song Database and click the ☆ on their songs to build it.</p>'
           : (karView === 'new' && !src.length
              ? '<p style="color:#94a3b8;font-size:13px">Nothing downloaded in the last 30 days — new songs land here automatically when they arrive.</p>'
              : (karSimple && q
                 // SIMPLE MODE ONLY. The Downloads panel is hidden here, so without this a singer
                 // whose song is missing is simply stuck (the owner, 2026-09-17). The offer appears
                 // where they already are - they have just typed the song name - and only once the
                 // library has failed them, so nothing is added to the page at rest.
                 ? '<div style="padding:22px 6px;text-align:center">'
                   + '<p style="color:#cbd5e1;font-size:16px;margin:0 0 4px">No song called <b>&ldquo;' + karEsc(q) + '&rdquo;</b> in your library.</p>'
                   + karDidYouMeanHtml(q)
                   + '<p style="color:#94a3b8;font-size:13.5px;margin:0 0 16px">Or fetch it from YouTube - it will be added to your songs.</p>'
                   + '<button type="button" onclick="karSimpleYt()" style="font-family:inherit;cursor:pointer;font-size:15px;'
                   + 'font-weight:800;padding:12px 22px;border-radius:10px;background:#334155;border:1px solid #475569;color:#e2e8f0">'
                   + 'Search YouTube for it</button></div>'
                 : '<p style="color:#94a3b8;font-size:13px">No songs match that search.</p>')));
    }
    // SIMPLE MODE: a way out at the BOTTOM of the results, not only when there are none.
    // the owner, 2026-09-17: searching his band "883" returns six songs he owns and not the one he
    // wants - and the empty-state offer never fires, because the search DID find things. Searching
    // by artist will nearly always land here, so the offer belongs after the last row, which is
    // where you are standing once you have looked and not found it. Nothing shows without a search.
    // ── DID YOU MEAN ─────────────────────────────────────────────────────────────────────────
    // the owner, 2026-09-17: "I put a song name and I don't find it because I misspelled part of the
    // name. What do I do then?" Without this he would conclude he does not own it and fetch a
    // SECOND COPY of a song already on the shelf. Runs only when the strict search found nothing,
    // so it costs nothing on a normal search.
    function karNorm(s){
      return s.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')  // strip accents
              .replace(/[^a-z0-9 ]+/g, ' ').replace(/\s+/g, ' ').trim();
    }
    function karLev(a, b){                      // ordinary edit distance, short strings only
      if (a === b) return 0;
      if (Math.abs(a.length - b.length) > 2) return 9;
      var prev = [], cur = [], i, j;
      for (j = 0; j <= b.length; j++) prev[j] = j;
      for (i = 1; i <= a.length; i++) {
        cur[0] = i;
        for (j = 1; j <= b.length; j++) {
          cur[j] = Math.min(prev[j] + 1, cur[j-1] + 1, prev[j-1] + (a[i-1] === b[j-1] ? 0 : 1));
        }
        for (j = 0; j <= b.length; j++) prev[j] = cur[j];
      }
      return prev[b.length];
    }
    function karDidYouMean(q){
      var qt = karNorm(q).split(' ').filter(function(w){ return w.length >= 3; });
      if (!qt.length) return [];
      var src = KAR_DATA[karRenderedView] || [];
      var scored = [];
      for (var i = 0; i < src.length; i++) {
        var words = karNorm(src[i]).split(' '), hit = 0;
        for (var k = 0; k < qt.length; k++) {
          for (var w = 0; w < words.length; w++) {
            var tol = qt[k].length <= 5 ? 1 : 2;            // longer words tolerate more slips
            if (words[w].indexOf(qt[k]) === 0 || karLev(qt[k], words[w]) <= tol) { hit++; break; }
          }
        }
        if (hit === qt.length) scored.push([src[i].length, src[i]]);   // every word accounted for
      }
      scored.sort(function(a, b){ return a[0] - b[0]; });
      return scored.slice(0, 4).map(function(x){ return x[1]; });
    }
    function karTrySpelling(name){
      var el = document.getElementById('kar-search');
      el.value = name.replace(/\.[^.]+$/, '');    // the page searches on the visible name
      karRender();
    }
    function karDidYouMeanHtml(q){
      var m = karDidYouMean(q);
      if (!m.length) return '';
      return '<div style="margin:0 0 20px">'
        + '<p style="color:#cbd5e1;font-size:15px;margin:0 0 10px">Did you mean one of these?</p>'
        + m.map(function(n){
            return '<button type="button" onclick="karTrySpelling(' + JSON.stringify(n).replace(/"/g, '&quot;') + ')" '
              + 'style="font-family:inherit;display:block;margin:0 auto 7px;cursor:pointer;font-size:15px;'
              + 'padding:9px 16px;border-radius:8px;background:#334155;border:1px solid #475569;color:#e2e8f0">'
              + karEsc(n.replace(/\.[^.]+$/, '')) + '</button>';
          }).join('')
        + '</div>';
    }
    function karSimpleYtFoot(q){
      if (!karSimple || !q) return '';
      return '<div style="margin-top:10px;border-top:1px solid #1e293b;padding:16px 6px;text-align:center">'
        + '<span style="color:#94a3b8;font-size:14px;margin-right:12px">Not the one you want?</span>'
        + '<button type="button" onclick="karSimpleYt()" style="font-family:inherit;cursor:pointer;font-size:14px;'
        + 'font-weight:800;padding:10px 18px;border-radius:9px;background:#334155;border:1px solid #475569;color:#e2e8f0">'
        + 'Search YouTube for &ldquo;' + karEsc(q) + '&rdquo;</button></div>';
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
          try { karFitList(); } catch(e){}
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
        karNowPlaying = st.s; karNowArmed = true;   // restored after a reload — the song is already known to the Mac
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
    // 🎬 Lyrics: one button hides the lyrics window or brings it back — the window is
    // kept in front of the browser now, so this is the only way it leaves the screen.
    function karLyricsToggle(btn){
      btn.disabled = true;
      var fd = new FormData(); fd.append('form_type', 'karaoke_lyrics'); fd.append('mac', karMac());
      fetch(KAR_API, {method:'POST', body: fd}).then(function(r){ return r.json(); }).then(function(d){
        btn.disabled = false;
        if (!d.ok) { alert('Could not reach the player — reload and try again.'); return; }
        if (d.note) { btn.innerHTML = '🎬 ' + (d.note.indexOf('hidden') !== -1 ? 'Screen<br>hidden' : 'Screen<br>shown'); setTimeout(function(){ btn.innerHTML = '🎬 Lyrics<br>Screen'; }, 2500); }
      }).catch(function(){ btn.disabled = false; alert('Network error — nothing changed.'); });
    }
    // ── ⏹ Stop = pause; progress line; drag-to-seek (2026-09-12) ──────────────────────
    function karPauseToggle(btn){
      btn.disabled = true;
      var fd = new FormData(); fd.append('form_type', 'karaoke_pause'); fd.append('mac', karMac());
      fetch(KAR_API, {method:'POST', body: fd}).then(function(r){ return r.json(); }).then(function(d){
        btn.disabled = false;
        if (!d.ok) { alert('Could not reach the player — reload and try again.'); return; }
        if (d.note) karPauseLabel(d.note.indexOf('paused') !== -1);   // standalone answers at once; casAI via the poll
        karNowPollSoon();
      }).catch(function(){ btn.disabled = false; alert('Network error — nothing changed.'); });
    }
    function karPauseLabel(paused){
      var b = document.getElementById('kar-stop-btn');
      b.innerHTML = paused ? '▶ Resume' : '⏹ Stop';
      b.style.background = paused ? '#d97706' : '#dc2626'; b.style.borderColor = b.style.background;
    }
    function karFmt(t){ t = Math.max(0, Math.round(t || 0)); return Math.floor(t / 60) + ':' + ('0' + (t % 60)).slice(-2); }
    var karSeekDrag = false, karNowGone = 0, karNowTimer = null;
    (function(){
      var sl = document.getElementById('kar-seek');
      sl.addEventListener('pointerdown', function(){ karSeekDrag = true; });
      sl.addEventListener('input', function(){ if (karNowDur) document.getElementById('kar-time-pos').textContent = karFmt(sl.value / 1000 * karNowDur); });
      sl.addEventListener('change', function(){
        karSeekDrag = false;
        if (!karNowDur) return;   // no length known yet — nothing to map the slider to
        var secs = Math.round(sl.value / 1000 * karNowDur);
        var fd = new FormData(); fd.append('form_type', 'karaoke_seek'); fd.append('mac', karMac()); fd.append('seconds', String(secs));
        fetch(KAR_API, {method:'POST', body: fd}).then(function(r){ return r.json(); }).then(function(d){ if (!d.ok) alert('Could not move the song' + (d.error ? ': ' + d.error : '') + '.'); karNowPollSoon(); }).catch(function(){});
      });
    })();
    var karNowDur = 0, karNowSentAt = 0, karNowArmed = false;
    // Called whenever a play is sent from this page: the Mac needs a few seconds to pick the
    // request up and start the player, and until then its last report still says "nothing
    // playing". Without this grace the bar cleared itself right after every Play and Stop
    // had nothing to act on (the owner, 2026-09-12: "those buttons look dead").
    function karNowStarted(){ karNowSentAt = Date.now(); karNowArmed = false; karNowGone = 0; }
    function karNowPoll(){
      if (!karNowPlaying) { document.getElementById('kar-prog').style.display = 'none'; return; }
      var fd = new FormData(); fd.append('form_type', 'karaoke_now_state'); fd.append('mac', karMac());
      fetch(KAR_API, {method:'POST', body: fd}).then(function(r){ return r.json(); }).then(function(d){
        if (!d.ok) return;
        var st = d.state || {};
        if (!st.playing || (st.file && karNowPlaying && st.file !== karNowPlaying)) {
          // "nothing playing" only counts once the Mac has reported THIS song at least once,
          // and never inside the first 15 s after a play was sent; then three quiet reads in a
          // row = the song ended or the window was closed.
          if (!karNowArmed || Date.now() - karNowSentAt < 15000) return;
          if (++karNowGone >= 3) { karNowPlaying = null; karNowSave(); karNowBar(); karPauseLabel(false); var l = document.getElementById('kar-list'); var t = l.scrollTop; karRender(); l.scrollTop = t; }
          return;
        }
        karNowGone = 0; karNowArmed = true;
        karNowDur = st.dur || 0;
        var pr = document.getElementById('kar-prog'); pr.style.display = 'flex';
        document.getElementById('kar-time-dur').textContent = karFmt(karNowDur);
        if (!karSeekDrag) {
          document.getElementById('kar-seek').value = karNowDur ? Math.round(st.pos / karNowDur * 1000) : 0;
          document.getElementById('kar-time-pos').textContent = karFmt(st.pos);
        }
        karPauseLabel(!!st.paused);
      }).catch(function(){});
    }
    function karNowPollSoon(){ setTimeout(karNowPoll, 1200); }
    setInterval(karNowPoll, 1000);
    // The old end-the-song path — no longer on a button (Stop pauses now); kept for the sentinel.
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
        karChipCounts();
        karRender();
      }).catch(function(){ alert('Network error — the removal was not sent.'); });
    }
    // One delegated listener for Play and Reset — rows themselves carry no handlers (People-tab DOM lesson).
    document.getElementById('kar-list').addEventListener('click', function(ev){
      var src = KAR_DATA[karRenderedView] || [];
      // ➕: add this song to the singing queue for the selected person.
      var qb = ev.target.closest ? ev.target.closest('.kar-q-add') : null;
      if (qb) {
        var songQ = src[parseInt(qb.getAttribute('data-i'), 10)];
        if (!songQ) return;
        // .kar-row, not parentElement — the Pitch box now lives in the SET UP section while this
        // button is in SING, so a parent lookup would find nothing.
        var rowQ = qb.closest ? qb.closest('.kar-row') : qb.parentElement;
        var inpQ = rowQ ? rowQ.querySelector('.kar-pitch') : null;
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
          if (!d.ok) { alert('Not saved. ' + karWhyFail(d.error)); return; }
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
      // Play ALWAYS plays the number currently showing in the Pitch box. Look it up from the ROW —
      // Pitch sits in the SET UP section and Play in SING, so parentElement would miss it.
      var rowP = b.closest ? b.closest('.kar-row') : b.parentElement;
      var inp = rowP ? rowP.querySelector('.kar-pitch') : null;
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
          karNowPlaying = song; karNowStarted();
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
          alert('Could not play that song. ' + karWhyFail(d.error));
        }
      }).catch(function(){
        b.textContent = lbl; b.style.color = '#EF4444'; b.disabled = false;
        alert('Network error — the play request was not sent. Reload the page and try again.');
      });
    });
    // ⚠ "unknown song" is NOT a session problem. It means the name the page sent is not in the
    // song catalogue - the file was deleted or renamed and something still points at the old name
    // (2026-09-17: two stale Best-list entries did exactly this). Blaming the session sent the owner
    // hunting a login fault that did not exist, so say what actually happened.
    function karWhyFail(err){
      if (err === 'unknown song')
        return 'That song is no longer in the library - it was renamed or removed, and this list still '
             + 'points at the old name. Press Refresh; if it is still here, tell Claude.';
      return (err ? err + '. ' : '') + 'Your session may have expired - reload and sign in again.';
    }

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
        if (!d.ok) { alert('The pitch was NOT saved. ' + karWhyFail(d.error)); return; }
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
    // the moment youtube.com loads — the window handle goes dead, the window name is
    // cleared, and no script can ever focus or reuse that tab again. Verified live
    // 2026-09-06 (curl: coop same-origin-allow-popups). Tab REUSE is impossible; that
    // part has not changed.
    //
    // 2026-09-11: the earlier answer to it — open once per session, then show a hint
    // instead of opening — was worse than the problem it solved. His report: "when I
    // click on YouTube, it does not go to YouTube. It stays there." Two faults. A
    // button that does nothing reads as broken. And the hint could be FALSE: the flag
    // recorded "we opened it once", not "it is still open", so once he closed the
    // YouTube tab every later click insisted it was already open — and a page cannot
    // detect that closure. So every click opens YouTube now. A spare tab is a much
    // smaller cost than a dead button that tells him something untrue.
    function karYtHint(){
      var el = document.getElementById('kar-yt-hint');
      if (!el) return;
      el.style.display = '';
      el.innerHTML = 'YouTube opened in a new tab — find the song there, copy its link, then '
        + '<b>click back to this tab</b> and paste it in the box below.';
    }
    // ▶ QMidi column: HIDDEN by default since 2026-09-06 (the owner moved to the casAI
    // player) — hidden, never deleted. The Guide's checkbox brings it back any time;
    // the choice is remembered per browser (localStorage kar_show_qmidi).
    var karShowQmidi = false;
    try { karShowQmidi = localStorage.getItem('kar_show_qmidi') === '1'; } catch(e){}
    // ── SIMPLE / COMPLETE ────────────────────────────────────────────────────────────
    // Remembered per browser, exactly like the QMidi switch. Default is COMPLETE, so
    // nothing changes for anyone until it is deliberately turned on.
    var karSimple = false;
    try { karSimple = localStorage.getItem('kar_simple') === '1'; } catch (e) {}
    function karApplySimple(){
      var p = document.getElementById('karaoke-page');
      if (p) p.classList.toggle('kar-simple', karSimple);
      var s = document.getElementById('kar-mode-s'), c = document.getElementById('kar-mode-c');
      // Chrome palette (2026-09-17). the owner: "there's no special reason why guide is more
      // important than stop" - right, and Guide was green, which on this page means GO. In
      // simple mode every page control goes quiet slate so the only colour left belongs to the
      // music: Play on each row, and Start / Stop. Those two then carry the whole signal, which
      // is what a singer at a microphone actually needs to find without reading.
      if (s){ s.style.background = karSimple ? '#334155' : '#1a2230'; s.style.color = karSimple ? '#fff' : '#94a3b8'; }
      if (c){ c.style.background = karSimple ? '#1a2230' : '#334155'; c.style.color = karSimple ? '#94a3b8' : '#fff'; }
      // the list box is sized from whatever is above it, and that just changed
      if (typeof karFitList === 'function') karFitList();
    }
    function karSetSimple(v){
      karSimple = !!v;
      try { localStorage.setItem('kar_simple', karSimple ? '1' : '0'); } catch (e) {}
      karApplySimple();
    }
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
      'kar-guide-panel': { btn:'kar-guide-btn', col:'#fff',    bd:'#16a34a', rest:'#16a34a', rgb:'22,163,74',   bg:'#4ade80', lit:'#052e16' },
      'kar-dl-panel':    { btn:'kar-dl-btn',    col:'#fff',    bd:'#EF4444', rest:'#EF4444', rgb:'239,68,68',   bg:'#f87171', lit:'#fff' },
      'kar-q-panel':     { btn:'kar-q-btn',     col:'#1a1305', bd:'#D2AD6C', rest:'#D2AD6C', rgb:'210,173,108', bg:'#f3d9a4', lit:'#1a1305' },
      'kar-qr-panel':    { btn:'kar-qr-btn',    col:'#fff',    bd:'#a855f7', rest:'#a855f7', rgb:'168,85,247',  bg:'#c084fc', lit:'#fff' }
    };
    function karBtnLight(pid, on){
      var p = KAR_PANELS[pid], b = p && document.getElementById(p.btn);
      if (!b) return;
      b.style.background  = on ? p.bg : (p.rest || '#1e293b');
      b.style.borderColor = on ? 'rgb(' + p.rgb + ')'      : p.bd;
      b.style.color       = on ? p.lit                     : p.col;
      // A dark gap then a solid ring in the tile's own colour: strong enough to read against
      // a tile that is already a solid colour at rest. The plain lighter fill was not.
      b.style.boxShadow   = on ? '0 0 0 3px #1A1F2C, 0 0 0 6px rgb(' + p.rgb + '), 0 6px 14px rgba(0,0,0,.45)' : 'none';
      b.style.setProperty('--kt', p.bg);
      b.classList.toggle('kar-tile-on', !!on);
      karTilesDim();
    }
    // Exactly one tile bright: whichever panel is open stays at full strength and the rest
    // step back. With nothing open, all four return to normal — no tile is "the odd one out"
    // just because the page has just loaded.
    function karTilesDim(){
      var anyOn = false, k;
      for (k in KAR_PANELS) {
        var el = document.getElementById(KAR_PANELS[k].btn);
        if (el && el.classList.contains('kar-tile-on')) { anyOn = true; break; }
      }
      for (k in KAR_PANELS) {
        var t = document.getElementById(KAR_PANELS[k].btn);
        if (t) t.classList.toggle('kar-tile-off', anyOn && !t.classList.contains('kar-tile-on'));
      }
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
        btn.classList.toggle('on', !!on);
        btn.title = on ? 'Hide how this panel works' : 'Show how this panel works';
        var lbl               = btn.querySelector('.kar-helplbl');   // gone now, kept for safety
        if (lbl) lbl.textContent = on ? 'Hide' : 'Help';
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
          try { karFitList(); } catch(e){}
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
        ? '<b style="color:#6ee7b7">✅ Saved.</b> Current folder: <b id="kar-pick-cur" style="color:#6ee7b7">' + karEsc(path) + '</b>'
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
    // Keep the words window in front. Saved on the Mac, and applied to the player that is open
// right now so the answer is immediate rather than "next time".
function karSetOnTop(cb){
  var on = cb.checked ? '1' : '0';
  cb.disabled = true;
  var fd = new FormData();
  fd.append('form_type','karaoke_set_ontop'); fd.append('on', on);
  fetch(KAR_API,{method:'POST',body:fd,credentials:'same-origin'})
    .then(function(r){return r.json();})
    .then(function(d){ cb.disabled=false; if(!d.ok){ cb.checked=!cb.checked; alert('Could not save that.'); } })
    .catch(function(){ cb.disabled=false; cb.checked=!cb.checked; alert('Could not save that.'); });
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
      if (same) { body.style.display = 'none'; karGuideCards(true); return; }   // closing a card brings the index back
      var sec = document.getElementById('kar-gs-' + key);
      if (sec) { sec.style.display = 'block'; body.style.display = 'block'; }
      karGuideCards(same);   // one topic at a time: the index steps aside while a card is open
    }
    // Show or hide the card index and the "back" link above it.
    function karGuideCards(show){
      var g = document.getElementById('kar-guide-cards');
      var i = document.getElementById('kar-guide-intro');
      var b = document.getElementById('kar-guide-back');
      if (g) g.style.display = show ? 'grid' : 'none';
      if (i) i.style.display = show ? '' : 'none';
      if (b) b.style.display = show ? 'none' : '';
    }
    function karGuideBack(){
      if (karGuideOpenKey) karGuideOpen(karGuideOpenKey);   // toggles the open card shut
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
        btn.textContent = '⬆︎ Cantoria Software Update';
        if (!ok) {
          karUpdState('failed', '✕ <b>Not updated.</b><div style="font-weight:600;font-size:12.5px;margin-top:2px">' + karEsc(note) + '</div>');
          return;
        }
        // "Already up to date" is a real answer, not a non-event — it is the one people
        // will see most often, so it gets said as plainly as the others.
        if (note.indexOf('Already up to date') === 0) {
          karUpdState('same', '✔︎ <b>Nothing to update.</b><div style="font-weight:600;font-size:12.5px;margin-top:2px">This Mac is already on the latest version. ' + karEsc(note.replace(/^Already up to date \(/, '').replace(/\)\.?\s*$/, '')) + '</div>');
          return;
        }
        var m = note.match(/karaoke (\S+?)\s*\(was (\S+?)\)/);
        var ver = m ? m[1] : '', was = m ? m[2] : '';
        var vEl = document.getElementById('kar-upd-ver');
        if (vEl && ver) vEl.textContent = ver;
        karUpdState('done', '✅ <b>Updated.</b><div style="font-weight:600;font-size:12.5px;margin-top:2px">'
          + (ver ? 'Now on <b>' + karEsc(ver) + '</b>' + (was ? ' — was ' + karEsc(was) : '') + '. ' : karEsc(note) + ' ')
          + '<a href="#" onclick="location.reload();return false;" style="color:#93c5fd">Reload the page to use it →</a></div>');
      });
    }
    function karCheckTools(){
      var btn = document.getElementById('kar-tools-btn');
      var msg = document.getElementById('kar-tools-msg');
      btn.disabled = true;
      btn.textContent = '⏳ Querying the Mac…';
      msg.innerHTML = '<span style="color:#D2AD6C">Checking installed versions…</span>';
      karMacAsk('tools', null, function(ok, note){
        btn.disabled = false;
        btn.textContent = '✅ Verify installation';
        // One line per app, each coloured by its own mark - a green/red checklist rather
        // than a sentence (the owner, 2026-09-13). The Mac sends the lines joined by "|".
        var rows = String(note || '').split('|').map(function (ln) {
          ln = ln.trim();
          var col = ln.indexOf('\u2705') === 0 ? '#6ee7b7' : (ln.indexOf('\u274c') === 0 ? '#f87171' : '#D2AD6C');
          return '<div style="color:' + col + ';font-weight:' + (col === '#D2AD6C' ? '600' : '700') + '">' + karEsc(ln) + '</div>';
        }).join('');
        msg.innerHTML = '<div style="margin-top:4px;line-height:1.85">'
          + '<div style="color:' + (ok ? '#6ee7b7' : '#f87171') + ';font-weight:800;margin-bottom:2px">'
          + (ok ? 'All four apps are installed.' : 'Not finished — see below.') + '</div>' + rows + '</div>';
      });
    }
    // READING THE GUIDE IS A MODE (the owner, 2026-09-13: "just get into a guide state, and
    // nothing else around"). The header and the gold bar stay - they are the permanent
    // controls - but the song list steps out of the way while the Guide is open.
    function karSongsArea(show){
      var e = document.getElementById('kar-songs-area');
      if (e) e.style.display = show ? '' : 'none';
    }
    function karPanelClose(){
      karSongsArea(true);
      // a help card belongs to its panel — it should not outlive it on screen
      ['dl','q','qr'].forEach(function(k){ try { localStorage.setItem('kar_help_' + k, '0'); } catch(e){} karHelpApply(k); });
      Object.keys(KAR_PANELS).forEach(function(pid){
        document.getElementById(pid).style.display = 'none';
        karBtnLight(pid, false);
      });
      if (karDlTimer) { clearTimeout(karDlTimer); karDlTimer = null; }
          try { karFitList(); } catch(e){}
    }
    // Esc closes whatever panel is open. The guard protects boxes where losing what you
    // typed would hurt — but NOT the YouTube panel's own two boxes: opening that panel puts
    // the cursor straight in the search field, so the old blanket guard meant Esc could
    // never close it at all, and a half-typed query is nothing to protect.
    var KAR_ESC_OK = { 'kar-yt-q': 1, 'kar-dl-url': 1 };
    document.addEventListener('keydown', function(ev){
      if (ev.key !== 'Escape') return;
      var el = ev.target, t = el && el.tagName;
      if ((t === 'INPUT' || t === 'TEXTAREA' || t === 'SELECT') && !KAR_ESC_OK[el.id]) return;
      karPanelClose();
    });
    function karPanelShow(id){
      var wasOpen = document.getElementById(id).style.display !== 'none';
      karPanelClose();
      if (wasOpen) return false;
      karSongsArea(id !== 'kar-guide-panel');
      document.getElementById(id).style.display = '';
      karBtnLight(id, true);
      return true;
          try { karFitList(); } catch(e){}
    }
    function karGuideToggle(){ karPanelShow('kar-guide-panel'); }
    function karDlToggle(){
      if (!karPanelShow('kar-dl-panel')) return;
      karDlRefresh();
      var q = document.getElementById('kar-yt-q');
      if (q) { q.focus(); q.select(); }
    }
    function karDlStatusStyle(st){
      if (st === 'Done') return 'color:#10B981';
      if (st === 'Error') return 'color:#EF4444';
      if (st === 'Downloading') return 'color:#D2AD6C';
      if (st === 'Pending') return 'color:#60A5FA';
      return 'color:#94a3b8'; // Queued
    }
    var karDlSeen = {}, karDlHasSeen = false;   // ids already drawn — anything newer gets a brief highlight
    function karDlRender(rows){
      var el = document.getElementById('kar-dl-list');
      if (!rows.length) {
        // Must not imply "nothing happened" — a song that arrived leaves this panel within
        // 10 minutes, and the old wording ("Nothing in the list yet") read as a failure
        // (the owner, 2026-09-07: "he didn't download it... the link disappeared" — it had in
        // fact downloaded fine 20 seconds after he pasted it).
        var ghost = '<div style="display:flex;gap:10px;align-items:center;padding:8px 4px;opacity:.3">'
          + '<span style="flex:0 0 106px;height:9px;border-radius:4px;background:#475569"></span>'
          + '<span style="flex:1;height:9px;border-radius:4px;background:#334155"></span></div>';
        el.innerHTML = ghost + ghost
          + '<p style="color:#64748b;font-size:12px;margin:6px 0 0;text-align:center">Nothing downloading right now. '
          + 'Songs that arrived are under <b style="color:#c084fc">🆕 New Songs</b>; anything that failed stays here, in red.</p>';
        karDlHasSeen = true;
        return;
      }
      var h = rows.map(function(r){
        var name = r.title ? karEsc(r.title) : '<span style="color:#64748b">looking up the title…</span>';
        if (r.requested_by) { name += ' <span style="font-size:11px;color:#c084fc;font-weight:700">· requested by ' + karEsc(r.requested_by) + '</span>'; }
        // A failure's reason is the whole point of the row — show it in red, not grey.
        var rnote = r.note || '';   // NULL when the song had no duplicate — never dereference it raw
        var noteCol = r.status === 'Error' ? '#f87171'
          : ((rnote.indexOf('already') !== -1 || rnote.indexOf('own') !== -1) ? '#D2AD6C' : '#64748b');
        var note = rnote ? '<div style="font-size:11px;color:' + noteCol + ';margin-top:1px">' + karEsc(rnote) + '</div>' : '';
        var canRemove = (r.status === 'Queued' || r.status === 'Error');
        var fresh = !karDlSeen[r.id] && karDlHasSeen; karDlSeen[r.id] = 1;
        return '<div style="display:flex;gap:10px;align-items:flex-start;padding:6px 6px;border-bottom:1px solid #1e293b;border-radius:6px;transition:background 1.8s' + (fresh ? ';background:rgba(96,165,250,.22)' : '') + '" ' + (fresh ? 'data-fresh="1"' : '') + '>'
          + '<span style="flex:0 0 106px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;' + karDlStatusStyle(r.status) + '">'
          + (r.status === 'Downloading' ? '⬇ Downloading' : r.status === 'Done' ? '✓ Completed' : r.status === 'Error' ? '✕ Didn\'t work' : r.status) + '</span>'
          + '<div style="flex:1;min-width:0"><div style="font-size:13px;color:#e2e8f0;word-break:break-word">' + name + '</div>' + note + '</div>'
          + (canRemove ? '<button type="button" onclick="karDlRemove(' + r.id + ')" title="Remove this line from the list (the URL only — no file is touched)" style="font-family:inherit;flex:0 0 auto;background:none;border:none;color:#94a3b8;cursor:pointer;font-size:13px;padding:0 2px">✕</button>' : '')
          + '</div>';
      }).join('');
      el.innerHTML = h;
      karDlHasSeen = true;
      // The list sits below the search results now (the owner, 2026-09-12: the search box goes
      // first) — so a row just added from a result is scrolled into view rather than left off-screen.
      var freshRow = el.querySelector('[data-fresh]');
      if (freshRow && freshRow.scrollIntoView) freshRow.scrollIntoView({block:'nearest', behavior:'smooth'});
      setTimeout(function(){ el.querySelectorAll('[data-fresh]').forEach(function(d){ d.style.background = 'transparent'; }); }, 900);
    }
    function karDlRefresh(){
      if (karDlTimer) { clearTimeout(karDlTimer); karDlTimer = null; }
      var fd = new FormData(); fd.append('form_type', 'karaoke_dl_state');
      fetch(KAR_API, {method:'POST', body: fd}).then(function(r){ return r.json(); }).then(function(d){
        if (!d.ok) return;
        karNewNotice(d.rows);
        karDlRender(d.rows);
        var qn = d.rows.filter(function(r){ return r.status === 'Queued'; }).length;
        var sb = document.getElementById('kar-dl-start');
        // Nothing normally waits any more, so the button spends its life as the section
        // title; it only becomes an action if an auto-start did not get through.
        sb.textContent = qn ? ('⬇ Download ' + qn + (qn === 1 ? ' song' : ' songs')) : 'DOWNLOAD LIST';
        sb.disabled = !qn;
        sb.style.background  = qn ? '#16a34a' : 'transparent';
        sb.style.borderColor = qn ? '#16a34a' : 'transparent';
        sb.style.color       = qn ? '#fff' : '#94a3b8';
        sb.style.cursor      = qn ? 'pointer' : 'default';
        sb.style.letterSpacing = qn ? '0' : '.10em';
        // Keep polling while anything is still moving (title lookups, pending/active downloads).
        var busy = d.rows.some(function(r){ return r.status === 'Pending' || r.status === 'Downloading' || (r.status === 'Queued' && !r.title); });
        if (busy && document.getElementById('kar-dl-panel').style.display !== 'none') {
          karDlTimer = setTimeout(karDlRefresh, 4000);
        }
      }).catch(function(){});
    }
    // ONE STEP, NOT TWO (the owner, 2026-09-13: "why wait? why can we not download it as we
    // say download it"). Adding a song now starts it. The two-step flow existed so the
    // duplicate warning could be read BEFORE spending a download - but a duplicate here is
    // a warning and never a refusal, so the pause guarded against nothing, and it cost him
    // an evening believing a song had downloaded when it was only listed. Batching is
    // unaffected: the Mac still fetches one at a time, so tapping five still queues five.
    function karDlGo(){
      var fd = new FormData(); fd.append('form_type', 'karaoke_dl_start');
      return fetch(KAR_API, {method:'POST', body: fd}).then(function(r){ return r.json(); })
        .catch(function(){ return {ok:false}; });
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
        karDlGo().then(karDlRefresh);

      }).catch(function(){ alert('Network error — the link was not added.'); });
    }
    document.getElementById('kar-dl-url').addEventListener('keydown', function(ev){
      if (ev.key === 'Enter') { ev.preventDefault(); karDlAdd(); }
    });

    // ---- Search YouTube from the page -------------------------------------------------
    // The page cannot run yt-dlp, so a search is a row the Mac answers: insert, then poll.
    // Same machinery the guest page has used since 2026-09-08.
    var KAR_YT_HITS = [], karYtPoll = null;
    // karEsc does not escape quotes, which is fine for text but not for an attribute.
    function karEscA(s){ return karEsc(String(s == null ? '' : s)).replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }

    function karYtSearch(){
      var inp = document.getElementById('kar-yt-q'), q = inp.value.trim();
      if (!q) { inp.focus(); return; }
      // The mode is captured AT SEARCH TIME, so ticking the box afterwards cannot quietly
      // re-filter results that came back from a search never steered at karaoke.
      KAR_YT_Q = q; KAR_YT_SHOWALL = false; KAR_YT_MODE = KAR_YT_ONLY;
      KAR_YT_OPENED = -1; KAR_YT_WATCHED = {}; KAR_YT_ADDED = {};
      var btn = document.getElementById('kar-yt-btn'), res = document.getElementById('kar-yt-res');
      btn.disabled = true; btn.textContent = 'Searching…';
      res.innerHTML = '<div style="color:#D2AD6C;font-size:12.5px;padding:6px 2px">Looking on YouTube…</div>';
      if (karYtPoll) { clearInterval(karYtPoll); karYtPoll = null; }
      var fd = new FormData(); fd.append('form_type','karaoke_yt_search'); fd.append('q', q);
      fd.append('only', KAR_YT_ONLY ? '1' : '0');
      fetch(KAR_API, {method:'POST', body: fd}).then(function(r){ return r.json(); }).then(function(d){
        if (!d.ok) { karYtDone(); res.innerHTML = '<div style="color:#f87171;font-size:12.5px;padding:6px 2px">' + karEsc(d.error || 'Search failed.') + '</div>'; return; }
        var tries = 0;
        karYtPoll = setInterval(function(){
          tries++;
          if (tries > 40) { clearInterval(karYtPoll); karYtPoll = null; karYtDone();
            res.innerHTML = '<div style="color:#f87171;font-size:12.5px;padding:6px 2px">That took too long. Is the Mac awake?</div>'; return; }
          var fp = new FormData(); fp.append('form_type','karaoke_yt_poll'); fp.append('sid', d.sid);
          fetch(KAR_API, {method:'POST', body: fp}).then(function(r){ return r.json(); }).then(function(r){
            if (!r.ok || r.status === 'Pending') return;
            clearInterval(karYtPoll); karYtPoll = null; karYtDone();
            if (r.status === 'Error') { res.innerHTML = '<div style="color:#f87171;font-size:12.5px;padding:6px 2px">' + karEsc(r.note || 'The search did not work.') + '</div>'; return; }
            KAR_YT_HITS = r.results || [];
            karYtRender();
          }).catch(function(){});
        }, 1200);
      }).catch(function(){ karYtDone(); res.innerHTML = '<div style="color:#f87171;font-size:12.5px;padding:6px 2px">Network hiccup — try again.</div>'; });
    }
    // ── SIMPLE MODE: the search box falls through to YouTube ────────────────────────────────
    // One search implementation, not two: these call the SAME server endpoints the Downloads
    // panel uses (karaoke_yt_search / _yt_poll / _dl_add / _dl_state). Only the rendering here is
    // smaller - no duplicate warnings, no download list, no panel. Four taps: type, search, tap
    // the one you want, it arrives.
    var karSimpleYtPoll = null;
    function karSimpleYt(){
      var q = (document.getElementById('kar-search').value || '').trim();
      if (!q) return;
      var list = document.getElementById('kar-list');
      var say = function(colour, msg){ list.innerHTML = '<p style="color:' + colour + ';font-size:16px;padding:22px 6px;text-align:center">' + msg + '</p>'; };
      say('#D2AD6C', 'Looking on YouTube for &ldquo;' + karEsc(q) + '&rdquo;&hellip;');
      var fd = new FormData();
      fd.append('form_type', 'karaoke_yt_search'); fd.append('q', q); fd.append('only', '1');
      fetch(KAR_API, {method:'POST', body: fd}).then(function(r){ return r.json(); }).then(function(d){
        if (!d.ok) { say('#f87171', karEsc(d.error || 'The search did not start.')); return; }
        var tries = 0;
        if (karSimpleYtPoll) clearInterval(karSimpleYtPoll);
        karSimpleYtPoll = setInterval(function(){
          tries++;
          if (tries > 40) { clearInterval(karSimpleYtPoll); karSimpleYtPoll = null;
            say('#f87171', 'That took too long. Is the Mac awake?'); return; }
          var fp = new FormData(); fp.append('form_type', 'karaoke_yt_poll'); fp.append('sid', d.sid);
          fetch(KAR_API, {method:'POST', body: fp}).then(function(r){ return r.json(); }).then(function(r){
            if (!r.ok || r.status === 'Pending') return;
            clearInterval(karSimpleYtPoll); karSimpleYtPoll = null;
            if (r.status === 'Error' || !(r.results || []).length) {
              say('#94a3b8', 'Nothing found on YouTube for &ldquo;' + karEsc(q) + '&rdquo;.'); return;
            }
            KAR_YT_HITS = r.results;
            karSimpleYtRender();
          }).catch(function(){});
        }, 1200);
      }).catch(function(){ say('#f87171', 'Network hiccup - try again.'); });
    }
    function karSimpleYtRender(){
      // ⚠ NO CAP. EVERY result is shown. The Mac fetches 30 and a cap here throws the rest
      // away - which is the exact problem the over-fetch was built to solve: the one you want
      // is the thirteenth. the owner removed a cap from the OTHER renderer on 2026-09-14, and
      // this one kept its own at 8 until he searched Celentano and got eight songs
      // (2026-09-18). If you are about to add slice() here, don't.
      var out = KAR_YT_HITS.map(function(h, i){
        return '<div class="kar-row" style="display:flex;align-items:center;gap:14px;padding:10px 6px;border-top:1px solid #1e293b">'
          + '<button type="button" onclick="karSimpleGet(' + i + ',this)" style="font-family:inherit;flex:0 0 auto;width:130px;cursor:pointer;'
          + 'font-size:15px;font-weight:800;padding:10px 0;border-radius:8px;background:rgba(22,163,74,.18);border:1px solid #16a34a;color:#6ee7b7">Get this one</button>'
          + '<a href="' + karEscA(h.url) + '" target="_blank" rel="noopener" title="Listen to it on YouTube first" style="flex:0 0 auto;font-family:inherit;text-decoration:none;font-size:14px;font-weight:800;padding:10px 14px;border-radius:8px;background:rgba(239,68,68,.14);border:1px solid #EF4444;color:#fca5a5">▶ Watch</a>'
          + '<img src="' + karEscA(h.thumb) + '" alt="" style="flex:0 0 auto;width:96px;height:54px;object-fit:cover;border-radius:5px;background:#1e293b">'
          + '<span style="font-size:17px;color:#e2e8f0;line-height:1.35">' + karEsc(h.title)
          // ⚠ KEEP THIS. The server already tells us which songs he owns that look like this one
          // (h.have), and the first cut of this renderer dropped it. That warning is exactly what
          // catches a typo-driven duplicate - he searches a misspelt name, the library "has
          // nothing", and he fetches a second copy of a song already on the shelf.
          + ((h.have && h.have.length)
             ? '<div style="color:#D2AD6C;font-size:13px;margin-top:4px">&#9888; you may already have this &mdash; ' + karEsc(h.have[0].label) + '</div>'
             : '')
          + '</span></div>';
      }).join('');
      document.getElementById('kar-list').innerHTML =
        '<p style="color:#94a3b8;font-size:14px;padding:12px 6px 2px">' + KAR_YT_HITS.length +
        ' results \u2014 tap the one you want. It downloads and joins your songs.</p>' + out;
    }
    function karSimpleGet(i, btn){
      var h = KAR_YT_HITS[i]; if (!h) return;
      btn.disabled = true; btn.textContent = 'Getting…';
      var fd = new FormData(); fd.append('form_type', 'karaoke_dl_add'); fd.append('url', h.url);
      fetch(KAR_API, {method:'POST', body: fd}).then(function(r){ return r.json(); }).then(function(d){
        if (!d.ok) { btn.disabled = false; btn.textContent = 'Get this one';
          alert('Could not fetch that song' + (d.error ? ': ' + d.error : '') + '.'); return; }
        btn.textContent = 'Downloading…';
        // It lands in the arrival strip under the search box, with a Play button on it.
        // This used to set the search box to the file's own name instead. That worked, but it
        // threw away whatever he was looking for, and a filtered list is not "click and play" -
        // it is "look, it is the only one left". the owner, 2026-09-18: "I just want that song we
        // download to show up as a simple click and play".
        karArrAdd(h.url, h.title);
        karDlGo().then(karArrPoll);
      }).catch(function(){ btn.disabled = false; btn.textContent = 'Get this one';
        alert('Network error — the song was not added.'); });
    }
    // ------------------------------------------------------------------ THE THREE SEARCHES
    // One box. The mode decides what it does: filter the songs you have, look on YouTube, or
    // take a link somebody handed you. the owner, 2026-09-18: "there are three types of search...
    // it would be nice to have those three in the sequence". They share ONE YouTube renderer and
    // ONE download path with the Downloads panel - this project has been bitten repeatedly by two
    // implementations of the same thing drifting apart.
    var KAR_SMODE = 'list';
    // ⚠ The mode is DELIBERATELY not remembered. the owner, 2026-09-18: "we have to make sure
    // that the karaoke list is the default. No matter what we come in from, when we get here,
    // the karaoke list search is the default." YouTube and Link are momentary errands; the
    // list is home. Remembering YouTube would bring the page back with the song list replaced
    // by last night's results and no obvious way back. Do not add persistence here.

    function karSetMode(m, skipRender){
      if (['list','yt','link'].indexOf(m) < 0) m = 'list';
      var was = KAR_SMODE;
      KAR_SMODE = m;
      ['list','yt','link'].forEach(function(k){
        var b = document.getElementById('kar-sm-' + (k === 'link' ? 'link' : k));
        if (b) b.classList.toggle('kar-on', k === m);
      });
      var inp = document.getElementById('kar-search'),
          go  = document.getElementById('kar-go'),
          ic  = document.getElementById('kar-sicon');
      if (!inp) return;
      // The bar keeps ONE look in every mode - the owner, 2026-09-18, on a header with three more
      // colours than it needed: "not colours, but just barely visible". Only the placeholder,
      // the icon and the button change, so the bar reads as one thing that is doing one job.
      if (m === 'list'){
        inp.placeholder = 'Search a song or an artist\u2026';
        if (ic) ic.textContent = '\uD83D\uDD0D';
        if (go) go.style.display = 'none';
        // Coming back from YouTube results, the list area is showing hits, not songs.
        if (was !== 'list') { inp.value = ''; if (!skipRender) karRender(); }
      } else if (m === 'yt'){
        inp.placeholder = 'What song are you looking for?';
        if (ic) ic.textContent = '\u25B6';
        if (go){ go.style.display = ''; go.textContent = 'Search'; }
      } else {
        inp.placeholder = 'Paste the link here\u2026';
        if (ic) ic.textContent = '\uD83D\uDD17';
        if (go){ go.style.display = ''; go.textContent = 'Download'; }
      }
      inp.focus();
    }
    // Typing only filters in list mode - in the other two the box is holding a question, not a filter.
    function karSearchInput(){ if (KAR_SMODE === 'list') karRender(); }
    function karSearchKey(ev){
      if (ev.key === 'Escape'){ karClearSearch(true); return; }
      if (ev.key === 'Enter' && KAR_SMODE !== 'list'){ ev.preventDefault(); karSearchGo(); }
    }
    function karSearchGo(){
      if (KAR_SMODE === 'yt') karSimpleYt();
      else if (KAR_SMODE === 'link') karLinkAdd();
      else karRender();
    }
    // A pasted link goes through the SAME add-and-start path as a YouTube result.
    function karLinkAdd(){
      var inp = document.getElementById('kar-search');
      var url = (inp.value || '').trim();
      if (!url) return;
      if (!/^https?:\/\//i.test(url)) { alert('That does not look like a link. It should start with http.'); return; }
      var go = document.getElementById('kar-go');
      go.disabled = true; go.textContent = '…';
      var fd = new FormData(); fd.append('form_type', 'karaoke_dl_add'); fd.append('url', url);
      fetch(KAR_API, {method:'POST', body: fd}).then(function(r){ return r.json(); }).then(function(d){
        go.disabled = false; go.textContent = 'Download';
        if (!d.ok) { alert('That link was not accepted' + (d.error ? ': ' + d.error : '') + '.'); return; }
        inp.value = '';
        karArrAdd(url, 'your link');
        karDlGo().then(karArrPoll);
      }).catch(function(){ go.disabled = false; go.textContent = 'Download'; alert('Network error — the link was not sent.'); });
    }

    // ------------------------------------------------------- SONGS THAT HAVE JUST ARRIVED
    // Keyed by url, because that is the one thing known at the moment of the click - the real
    // filename does not exist until yt-dlp has made it (and sanitised it).
    var KAR_ARR = {}, KAR_ARR_TIMER = null;
    function karArrAdd(url, title){
      if (!KAR_ARR[url]) KAR_ARR[url] = { url: url, title: title || 'a song', status: 'Downloading', file: '' };
      karArrRender();
    }
    function karArrDismiss(url){ delete KAR_ARR[url]; karArrRender(); }
    function karArrRender(){
      var box = document.getElementById('kar-arrivals');
      if (!box) return;
      var keys = Object.keys(KAR_ARR);
      if (!keys.length){ box.style.display = 'none'; box.innerHTML = ''; return; }
      box.style.display = '';
      box.innerHTML = keys.map(function(u){
        var a = KAR_ARR[u], esc = karEsc(a.file ? a.file.replace(/\.[^.]+$/, '') : a.title);
        if (a.status === 'Done'){
          return '<div class="kar-arrow" style="background:rgba(22,163,74,.14);border:1px solid #16a34a">'
            + '<span style="flex:0 0 auto;font-size:17px">🎵</span>'
            + '<span style="flex:1;min-width:0;color:#d1fae5;font-weight:700;word-break:break-word">' + esc + '</span>'
            + '<button type="button" onclick="karArrPlay(' + karEscA(JSON.stringify(u)) + ',this)" style="flex:0 0 auto;appearance:none;-webkit-appearance:none;font-family:inherit;cursor:pointer;background:#16a34a;border:1px solid #6ee7b7;color:#fff;font-size:14px;font-weight:800;padding:8px 18px;border-radius:9px">▶ Play</button>'
            + '<button type="button" onclick="karArrDismiss(' + karEscA(JSON.stringify(u)) + ')" title="Hide this" style="flex:0 0 auto;appearance:none;-webkit-appearance:none;font-family:inherit;cursor:pointer;background:none;border:none;color:#94a3b8;font-size:16px;padding:2px 6px">✕</button>'
            + '</div>';
        }
        if (a.status === 'Error'){
          return '<div class="kar-arrow" style="background:rgba(239,68,68,.12);border:1px solid #EF4444">'
            + '<span style="flex:0 0 auto;font-size:17px">✕</span>'
            + '<span style="flex:1;min-width:0;color:#fca5a5"><b>That song did not download.</b> ' + karEsc(a.note || '') + '</span>'
            + '<button type="button" onclick="karArrDismiss(' + karEscA(JSON.stringify(u)) + ')" style="flex:0 0 auto;appearance:none;-webkit-appearance:none;font-family:inherit;cursor:pointer;background:none;border:none;color:#94a3b8;font-size:16px;padding:2px 6px">✕</button>'
            + '</div>';
        }
        return '<div class="kar-arrow" style="background:rgba(210,173,108,.12);border:1px solid #D2AD6C">'
          + '<span style="flex:0 0 auto;font-size:17px">⬇</span>'
          + '<span style="flex:1;min-width:0;color:#f3d9a4">Getting <b>' + esc + '</b>…</span>'
          + '</div>';
      }).join('');
    }
    // No autoplay, ever - a song starting by itself mid-party is the wrong kind of surprise.
    function karArrPlay(url, btn){
      var a = KAR_ARR[url]; if (!a || !a.file) return;
      btn.disabled = true; btn.textContent = '…';
      var fd = new FormData();
      fd.append('form_type', 'karaoke_play'); fd.append('mac', karMac());
      fd.append('song', a.file); fd.append('player', 'mpv');
      fetch(KAR_API, {method:'POST', body: fd}).then(function(r){ return r.json(); }).then(function(d){
        btn.disabled = false; btn.innerHTML = '▶ Play';
        if (!d.ok){ alert('Could not play that song. ' + karWhyFail(d.error)); return; }
        karNowPlaying = a.file; karNowStarted(); karNowPlayingPlayer = 'mpv';
        karLivePitch = karSavedPitch(a.file); karLiveTempo = 100;
        karNowSave();
        var t = document.getElementById('kar-tempo-val'); if (t) t.textContent = '100%';
        karNowBar();
        if (KAR_SMODE === 'list') karRender();
      }).catch(function(){ btn.disabled = false; btn.innerHTML = '▶ Play'; alert('Network error — the play request was not sent.'); });
    }
    // One poll for every arrival at once, however they were started.
    function karArrPoll(){
      if (KAR_ARR_TIMER) return;
      var tries = 0;
      KAR_ARR_TIMER = setInterval(function(){
        tries++;
        var live = Object.keys(KAR_ARR).filter(function(u){ return KAR_ARR[u].status === 'Downloading'; });
        if (!live.length || tries > 200){ clearInterval(KAR_ARR_TIMER); KAR_ARR_TIMER = null; return; }
        var fd = new FormData(); fd.append('form_type', 'karaoke_dl_state');
        fetch(KAR_API, {method:'POST', body: fd}).then(function(r){ return r.json(); }).then(function(st){
          var landed = false;
          (st.rows || []).forEach(function(row){
            var a = KAR_ARR[row.url]; if (!a || a.status !== 'Downloading') return;
            if (row.title) a.title = row.title;
            if (row.status === 'Done'){ a.status = 'Done'; a.file = row.filename || ''; landed = true; }
            else if (row.status === 'Error'){ a.status = 'Error'; a.note = row.note || ''; }
          });
          // The song has to be in KAR_DATA before Play can find it.
          if (landed) karNewRefresh().then(karArrRender); else karArrRender();
        }).catch(function(){});
      }, 2500);
    }

    function karYtDone(){ var b = document.getElementById('kar-yt-btn'); b.disabled = false; b.textContent = '▶ Search YouTube'; }

    // Coming back from YouTube, six near-identical results look the same and he cannot tell
    // which one he just watched (the owner, 2026-09-12: "if I don't remember which one I
    // watched, it'll be a problem"). So the row he opened or copied stays marked, and a row
    // sent to the download list keeps a stronger, permanent mark of its own. It must NOT say
    // "already" (the owner, 2026-09-18): the mark is painted the instant Download is pressed, so
    // "already in the list" sat beside a button reading "Downloading..." and read as though the
    // click had done nothing.
    // Three marks, strongest first: a row already downloading (green, permanent) · the one
    // just opened (red, moves) · every earlier one you opened (grey, permanent). The trail
    // is the point — watching three songs used to leave only the third marked, so coming
    // back to pick the first meant hunting through sixteen near-identical rows from memory
    // (the owner, 2026-09-14: "I watch number three, and by that time I think I wanna
    // download number one. How does that work?").
    var KAR_YT_OPENED = -1, KAR_YT_WATCHED = {}, KAR_YT_ADDED = {};

    // Marks live in those three objects, never only in the DOM: the rows are rebuilt from
    // scratch whenever the list is re-rendered — pressing Show them, for one — and painting
    // them again from state is what stops a re-render wiping the trail.
    function karYtPaint(){
      for (var j = 0; j < KAR_YT_HITS.length; j++){
        var row = document.getElementById('kar-ytrow-' + j);
        if (!row) continue;
        var st = KAR_YT_ADDED[j] ? 'added'
               : (j === KAR_YT_OPENED ? 'open' : (KAR_YT_WATCHED[j] ? 'watched' : ''));
        if (st === 'added') row.dataset.added = '1';
        karYtRowMark(row, st);
      }
    }
    var KAR_YT_Q = '';
    function karYtRowMark(row, state){
      var tag = row.querySelector('.kar-ytmark');
      if (state === 'added'){
        row.style.background = 'rgba(22,163,74,.13)';
        row.style.borderLeftColor = '#16a34a';
        if (tag){ tag.textContent = '✓ sent to the download list'; tag.style.color = '#6ee7b7'; tag.style.display = ''; }
      } else if (state === 'open'){
        row.style.background = 'rgba(239,68,68,.12)';
        row.style.borderLeftColor = '#EF4444';
        if (tag){ tag.textContent = '← this is the one you opened'; tag.style.color = '#fca5a5'; tag.style.display = ''; }
      } else if (state === 'watched'){
        row.style.background = 'rgba(148,163,184,.07)';
        row.style.borderLeftColor = '#64748b';
        if (tag){ tag.textContent = '✓ watched'; tag.style.color = '#94a3b8'; tag.style.display = ''; }
      } else {
        row.style.background = '';
        row.style.borderLeftColor = 'transparent';
        if (tag){ tag.style.display = 'none'; }
      }
    }
    // The panel's own button is the only thing that wipes the search. Every other way out
    // — the tile, Esc, opening another panel — is a plain close and leaves it as it was.
    function karDlClearClose(){ karYtClear(false); karPanelClose(); }
    function karYtClear(refocus){
      KAR_YT_HITS = []; KAR_YT_OPENED = -1; KAR_YT_Q = ''; KAR_YT_SHOWALL = false;
      KAR_YT_WATCHED = {}; KAR_YT_ADDED = {};
      var r = document.getElementById('kar-yt-res'); if (r) r.innerHTML = '';
      var q = document.getElementById('kar-yt-q');
      if (q) { q.value = ''; if (refocus !== false) q.focus(); }
    }
    function karYtTouch(i){
      KAR_YT_WATCHED[i] = 1;   // never cleared: every song you opened stays on the trail
      KAR_YT_OPENED = i;
      karYtPaint();
    }
    // Only results whose title or channel says karaoke / lyric / testo are shown — the owner's
    // rule, 2026-09-14. The ones held back are never thrown away: they are counted on screen
    // and one click brings them back, because a filter that hides silently can lie.
    var KAR_YT_SHOWALL = false;
    // Ticked by default: a Mac nobody has configured behaves like a karaoke machine.
    var KAR_YT_ONLY = true, KAR_YT_MODE = true;
    function karYtToggleAll(){ KAR_YT_SHOWALL = !KAR_YT_SHOWALL; karYtRender(); }

    function karYtRender(){
      var res = document.getElementById('kar-yt-res');
      if (!KAR_YT_HITS.length) { res.innerHTML = '<div style="color:#94a3b8;font-size:12.5px;padding:6px 2px">Nothing found — try the singer\'s name, or fewer words.</div>'; return; }
      // Which rows to draw, as ORIGINAL indices into KAR_YT_HITS: every button in a row
      // indexes that array, so a filtered view must never renumber them.
      var pass = [], rest = [];
      for (var k = 0; k < KAR_YT_HITS.length; k++) { (KAR_YT_HITS[k].ok ? pass : rest).push(k); }
      // A search where nothing at all carries one of the words is shown in full rather than
      // as an empty page: the filter narrows the list, it never leaves you with nothing.
      // Three ways everything gets shown: the box was unticked for this search, he asked
      // to see the rest, or nothing at all carried one of the words — a filter that
      // narrows must never leave him with an empty page.
      var showAll = !KAR_YT_MODE || KAR_YT_SHOWALL || !pass.length;
      // EVERY result that passes is shown, never a fixed dozen. Capping it would put the
      // thirteenth good one out of sight — which is the exact problem this whole search
      // was built to solve (the owner, 2026-09-14). So the count below is only ever the
      // results that did not match, and the line beside it can be taken literally.
      var show = showAll ? pass.concat(rest) : pass;
      var hidden = KAR_YT_HITS.length - show.length;
      var head = '<span style="color:#fca5a5;font-size:12px;font-weight:800">' + show.length
               + (showAll ? ' results' : ' karaoke results') + ' for “' + karEsc(KAR_YT_Q) + '”</span>';
      if (!KAR_YT_MODE) {
        head += '<span style="color:#94a3b8;font-size:12px">· every result, not only the singable ones</span>';
      } else if (hidden > 0) {
        head += '<span style="color:#94a3b8;font-size:12px">· ' + hidden + ' more, without karaoke or lyrics in the name</span>'
             +  '<button type="button" onclick="karYtToggleAll()" style="font-family:inherit;background:none;border:1px solid #334155;color:#cbd5e1;cursor:pointer;font-size:11.5px;font-weight:700;padding:4px 10px;border-radius:7px">Show them</button>';
      } else if (showAll && rest.length) {
        head += '<button type="button" onclick="karYtToggleAll()" style="font-family:inherit;background:none;border:1px solid #334155;color:#cbd5e1;cursor:pointer;font-size:11.5px;font-weight:700;padding:4px 10px;border-radius:7px">Karaoke &amp; lyrics only</button>';
      }
      var out = ['<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:4px 2px 2px">' + head + '</div>',
        '<div style="color:#94a3b8;font-size:12px;padding:2px 2px 6px"><b style="color:#e2e8f0">▶ Watch</b> opens the video on YouTube, and the row stays marked · <b style="color:#fca5a5">⬇ Download</b> fetches it.</div>'];
      for (var n = 0; n < show.length; n++) {
        var i = show[n], h = KAR_YT_HITS[i];
        // A duplicate is a WARNING, never a refusal — he keeps several versions of a song
        // on purpose, and tidies up from 🆕 New at the end of the night.
        var dup = (h.have && h.have.length)
          ? '<div style="color:#D2AD6C;font-size:11.5px;margin-top:2px">⚠ you may already have this — ' + karEsc(h.have[0].label) + '</div>'
          : '';
        // Shown only once the held-back rows are revealed, so it is clear which is which.
        var why = (h.ok || !KAR_YT_MODE) ? '' : ' · <span style="color:#f59e0b">no karaoke or lyrics in the name</span>';
        out.push('<div id="kar-ytrow-' + i + '" style="display:flex;gap:10px;align-items:center;padding:7px 0 7px 7px;border-top:1px solid #1e293b;border-left:3px solid transparent;border-radius:0 6px 6px 0">'
          + '<img src="' + karEscA(h.thumb) + '" alt="" style="flex:0 0 72px;width:72px;height:41px;object-fit:cover;border-radius:5px;background:#1e293b">'
          + '<div style="flex:1;min-width:0">'
          + '<div style="color:#e2e8f0;font-size:12.5px;font-weight:600;line-height:1.35">' + karEsc(h.title) + '</div>'
          + '<div style="color:#64748b;font-size:11px">' + karEsc(h.chan) + (h.len ? ' · ' + karEsc(h.len) : '') + why + '</div>'
          + dup + '<div class="kar-ytmark" style="display:none;font-size:11px;font-weight:800;margin-top:2px"></div></div>'
          + '<div class="kar-dlrt">'
          + '<a href="' + karEscA(h.url) + '" target="_blank" rel="noopener" onclick="karYtTouch(' + i + ')" title="Open this video on YouTube — the row stays marked so you can find it when you come back" style="flex:0 0 auto;font-family:inherit;background:none;border:1px solid #334155;color:#e2e8f0;text-decoration:none;font-size:12px;font-weight:700;padding:7px 12px;border-radius:8px">▶ Watch</a>'
          + '<button type="button" onclick="karYtAdd(' + i + ',this)" style="flex:0 0 auto;font-family:inherit;background:rgba(239,68,68,.16);border:1px solid #EF4444;color:#fecaca;cursor:pointer;font-size:12.5px;font-weight:800;padding:7px 14px;border-radius:8px">⬇ Download</button>'
          + '</div>'
          + '</div>');
      }
      res.innerHTML = out.join('');
      karYtPaint();   // the rows are brand new — put the marks back on them
    }

    // Adding goes through the SAME list as a pasted link, so ⬇ Download the list still
    // does the fetching — one download path, not two.
    function karYtAdd(i, btn){
      var h = KAR_YT_HITS[i]; if (!h) return;
      btn.disabled = true; btn.textContent = '…';
      var fd = new FormData(); fd.append('form_type','karaoke_dl_add'); fd.append('url', h.url);
      fetch(KAR_API, {method:'POST', body: fd}).then(function(r){ return r.json(); }).then(function(d){
        btn.disabled = false;
        btn.textContent = d.ok ? '✓ Downloading…' : '⬇ Download';
        if (!d.ok) { alert('Not added' + (d.error ? ': ' + d.error : '') + '.'); return; }
        btn.style.color = '#6ee7b7'; btn.style.borderColor = '#16a34a';
        KAR_YT_ADDED[i] = 1;   // in state, not just the DOM — Show them rebuilds the rows
        karYtPaint();
        karArrAdd(h.url, h.title);          // same arrival strip, however it was started
        karDlGo().then(function(){ karDlRefresh(); karArrPoll(); });
      }).catch(function(){ btn.disabled = false; btn.textContent = '⬇ Download'; alert('Network error — the song was not added.'); });
    }
    document.getElementById('kar-yt-q').addEventListener('keydown', function(ev){
      if (ev.key === 'Enter') { ev.preventDefault(); karYtSearch(); }
    });
    function karDlStart(){
      var fd = new FormData(); fd.append('form_type', 'karaoke_dl_start');
      fetch(KAR_API, {method:'POST', body: fd}).then(function(r){ return r.json(); }).then(function(d){
        if (!d.ok) { alert('Could not start' + (d.error ? ': ' + d.error : '') + '.'); return; }
        if (!d.started) {
          var el = document.getElementById('kar-dl-list');
          el.innerHTML = '<p style="color:#D2AD6C;font-size:12.5px;margin:4px 0 0">Nothing is waiting to download. Songs added earlier have already been downloaded — they are in the Song Database and under 🆕 New Songs.</p>';
          karNewRefresh();
          return;
        }
        karDlRefresh();
      }).catch(function(){ alert('Network error — the download was not started.'); });
    }
    function karDlClear(){
      if (!confirm('Empty the download list?\n\nClears the links still waiting and any that failed. Songs that already arrived are untouched — they stay in your library and under 🆕 New Songs. A download in progress keeps going.')) return;
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
        karNowPlaying = pick.song; karNowStarted();
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
      if (!confirm('Clear the whole singing queue?\n\nOnly the requests list empties — songs, pitches and Best lists are untouched.')) return;
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
    // 🖨 Print — a second QR at print resolution, then the browser's own print dialog. No
    // popup window: a blocker would swallow it silently and the button would look dead.
    function karQrPrint(){
      var url = (document.getElementById('kar-qr-url') || {}).textContent || '';
      if (!url) { alert('The code is still loading — give it a second and press it again.'); return; }
      var holder = document.getElementById('kar-qr-print-code');
      holder.innerHTML = '';
      new QRCode(holder, { text: url, width: 420, height: 420, correctLevel: QRCode.CorrectLevel.M });
      // Only printed when there is something to say. On a Mac serving this page over the
      // house network it is REQUIRED — a guest on mobile data cannot reach the page at all;
      // over the internet it is a helpful extra for anyone with no signal.
      var wifiEl = document.getElementById('kar-qr-print-wifi');
      if (KAR_WIFI) {
        wifiEl.innerHTML = (KAR_ON_LAN ? 'First join the Wi-Fi: ' : 'No signal? Join the Wi-Fi: ')
          + '<b>' + karEsc(KAR_WIFI) + '</b><br><span class="kar-pr-ask">Ask the host for the password</span>';
      } else if (KAR_ON_LAN) {
        wifiEl.innerHTML = 'First join the house Wi-Fi<br><span class="kar-pr-ask">Ask the host for the network and password</span>';
      } else { wifiEl.innerHTML = ''; }
      document.getElementById('kar-qr-print-url').textContent = url;
      // qrcodejs draws synchronously, but give the browser one frame to lay the image out
      // before the print dialog snapshots the page.
      setTimeout(function(){ window.print(); }, 120);
    }

    // 📺 Put the code in the corner of the lyrics screen. The Mac cannot draw a QR — it has
    // no library and adding one would be a dependency on every family Mac — so the PICTURE
    // is rendered here and sent with the request. Toggles: press again to take it away.
    var karQrOnScreen = false;
    function karQrWindow(){
      var btn = document.getElementById('kar-qr-win');
      var want = !karQrOnScreen;
      var fd = new FormData();
      fd.append('form_type', 'karaoke_qr_window');
      fd.append('show', want ? '1' : '0');
      fd.append('mac', karMac());
      if (want) {
        var url = (document.getElementById('kar-qr-url') || {}).textContent || '';
        if (!url) { alert('The code is still loading — give it a second and press it again.'); return; }
        // Rendered large so it stays sharp on a television, then drawn onto a WHITE square
        // with a margin round it. That margin is not decoration — a QR needs a quiet zone or
        // a phone cannot lock on, and this one ends up small, on a dark screen, over a moving
        // picture, which is the hardest case there is.
        var tmp = document.createElement('div');
        new QRCode(tmp, { text: url, width: 480, height: 480, correctLevel: QRCode.CorrectLevel.M });
        var el = tmp.querySelector('canvas') || tmp.querySelector('img');
        if (!el) { alert('Could not build the picture of the code.'); return; }
        var pad = 48, size = 480, c = document.createElement('canvas');
        c.width = c.height = size + pad * 2;
        var g = c.getContext('2d');
        g.fillStyle = '#ffffff'; g.fillRect(0, 0, c.width, c.height);
        var send = function(){
          var data = '';
          try { g.drawImage(el, pad, pad, size, size); data = c.toDataURL('image/png'); } catch (e) { data = ''; }
          if (!data) { alert('Could not build the picture of the code.'); btn.disabled = false; return; }
          fd.append('png', data);
          karQrSend(fd, btn, want);
        };
        btn.disabled = true;
        // qrcodejs uses a canvas everywhere modern, but falls back to an <img> whose data URL
        // may not have decoded yet — drawing it too early would send a blank white square.
        if (el.tagName === 'IMG' && !el.complete) { el.onload = send; el.onerror = send; } else { send(); }
        return;
      }
      btn.disabled = true;
      karQrSend(fd, btn, want);
    }
    function karQrSend(fd, btn, want){
      fetch(KAR_API, {method:'POST', body: fd}).then(function(r){ return r.json(); }).then(function(d){
        btn.disabled = false;
        if (!d.ok) { alert(d.error || 'That did not work.'); return; }
        karQrOnScreen = want;
        btn.textContent = want ? '📺 Take it off the lyrics screen' : '📺 Show on the lyrics screen';
        btn.style.background = want ? '#a855f7' : 'rgba(192,132,252,.14)';
        btn.style.color = want ? '#fff' : '#e9d5ff';
      }).catch(function(){ btn.disabled = false; alert('Network error — nothing changed.'); });
    }

    function karQrRotate(){
      if (!confirm('Issue a NEW guest code?\n\nEvery QR code shown or scanned before will stop working — guests will need to scan the new one.')) return;
      karQrLoad('rotate');
    }
    var KAR_WIFI = '', KAR_ON_LAN = false;
    function karQrSaveWifi(){
      var wf = document.getElementById('kar-qr-wifi');
      KAR_WIFI = (wf.value || '').trim();
      var fd = new FormData();
      fd.append('form_type', 'karaoke_qr'); fd.append('action', 'wifi'); fd.append('wifi', KAR_WIFI);
      fetch(KAR_API, {method:'POST', body: fd}).then(function(r){ return r.json(); }).then(function(d){
        if (!d.ok) { alert(d.error || 'Could not save that.'); return; }
        wf.style.borderColor = '#16a34a';
        setTimeout(function(){ wf.style.borderColor = '#334155'; }, 1400);
      }).catch(function(){ alert('Network error — the name was not saved.'); });
    }
    function karQrLoad(action){
      var fd = new FormData();
      fd.append('form_type', 'karaoke_qr');
      fd.append('action', action);
      fetch(KAR_API, {method:'POST', body: fd}).then(function(r){ return r.json(); }).then(function(d){
        if (!d.ok) { alert('Could not load the guest code' + (d.error ? ': ' + d.error : '') + '.'); return; }
        karQrShown = d.url;
        KAR_WIFI = d.wifi || '';
        KAR_ON_LAN = !!d.lan;   // true only on a Mac serving this page over the house network
        var wf = document.getElementById('kar-qr-wifi'); if (wf) wf.value = KAR_WIFI;
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
    try { KAR_YT_ONLY = localStorage.getItem('kar_yt_only') !== '0';
          document.getElementById('kar-yt-only').checked = KAR_YT_ONLY; } catch(e){}
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
    // A finished download appears under 🆕 New and in the Song Database WITHOUT a reload.
    // Three real downloads on 2026-09-12 were reported as "did not download": the Done row
    // had retired from the panel and the page still showed the list it was born with, so
    // the songs existed everywhere except on screen.
    var karNewDoneSeen = null;
    function karNewNotice(rows){
      var first = (karNewDoneSeen === null); if (first) karNewDoneSeen = {};
      var fresh = false;
      rows.forEach(function(r){ if (r.status === 'Done' && !karNewDoneSeen[r.id]) { karNewDoneSeen[r.id] = 1; if (!first) fresh = true; } });
      if (fresh) karNewRefresh();
    }
    // The numbers ON THE CHIPS come from the server at page load, so anything that changes
    // a list in place - a delete, a download arriving - left them stale until a refresh
    // (the owner, 2026-09-14: "I deleted a bunch... in the button it still says fifteen").
    // Every path that changes a list calls this instead.
    function karChipCounts(){
      var d = document.getElementById('kar-db-count');
      if (d) d.textContent = KAR_DATA.db.length;
      var n = document.getElementById('kar-new-count');
      if (n) n.textContent = KAR_DATA.new.length;
    }
    function karNewRefresh(){
      var fd = new FormData(); fd.append('form_type', 'karaoke_new_list');
      // RETURNS the promise: a caller that wants to show the song it just fetched has to wait
      // until it is actually in KAR_DATA, otherwise it renders a list that does not contain it yet.
      return fetch(location.pathname, {method:'POST', body: fd}).then(function(r){ return r.json(); }).then(function(d){
        if (!d.ok) return;
        KAR_DATA.new = d.new || [];
        if (d.db && d.db.length) KAR_DATA.db = d.db;
        Object.keys(d.dup || {}).forEach(function(k){ KAR_DUP[k] = d.dup[k]; });
        karChipCounts();
        if (karView === 'new' || karView === 'db') karRender();
      }).catch(function(){});
    }
    function karActivityPoll(){
      var fd = new FormData(); fd.append('form_type', 'karaoke_dl_state');
      fetch(KAR_API, {method:'POST', body: fd}).then(function(r){ return r.json(); }).then(function(d){
        if (!d.ok) return;
        karNewNotice(d.rows);
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
                ? '✅ <b>' + karEsc(name) + '</b> is ready — <b>' + karEsc(r.requested_by) + '</b> is in line to sing it. It is under 🆕 New Songs.'
                : '✅ <b>' + karEsc(name) + '</b> is in the Song Database and under 🆕 New Songs.' };
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
    karApplySimple();     // Simple / Complete — hide or show everything but singing
    karHelpApply('dl');  // the "? How it works" blocks, per this computer's remembered choice
    karHelpApply('q');
    karHelpApply('qr');
    karNowRestore();   // bring back the playing song's name/pitch/tempo after a reload
    karNowBar();       // the bar itself is always on screen — render its idle state too
    karSetMode(KAR_SMODE);   // placeholder, colours and the Go button follow the remembered mode
    karRender();
    karFitList();   // size the list to the window before anything is drawn on it
    </script>
    <?php endif; ?>
  </div>
</div>
    <!-- ⚠ MUST be a direct child of <body>. The print rule hides every top-level element
         except this one — nested three divs deep, as it first was, its own wrapper got
         hidden and took it with it, and the page printed blank (2026-09-14). Same trap
         as the floating panels: position and print rules do not escape a hidden parent. -->
    <div id="kar-qr-print">
      <h1>Sing with us</h1>
      <p class="kar-pr-lead">Point your phone camera at this code</p>
      <p class="kar-pr-wifi" id="kar-qr-print-wifi"></p>
      <div id="kar-qr-print-code"></div>
      <p class="kar-pr-steps">Type your first name · find your song · tap <b>Request</b><br>
         You will see your place in the queue. You can bring a song from YouTube too.</p>
      <p class="kar-pr-url" id="kar-qr-print-url"></p>
    </div>
</body>
</html>
