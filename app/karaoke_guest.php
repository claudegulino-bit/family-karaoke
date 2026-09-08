<?php
// karaoke_guest.php — song requests from guests' own phones (2026-09-06).
// NO login: gated by a rotating party token (?t=...) carried in the QR code shown on the
// Up Next panel. Request-ONLY: a guest can search the song database and put (their name,
// a song) into the Up Next line — nothing else. No play, no pitch, no delete, no rename.
// Caps: 3 waiting songs per name, 50 waiting total. The host runs the line from karaoke.php.
//
// Two worlds, one page (2026-09-08): on casAI the guests reach it over the internet at
// getcasa.ai; on a standalone Mac they reach the Mac itself on the house Wi-Fi. What a
// guest can do is identical, and identically small.
require_once __DIR__ . '/karaoke_backend.php';
$KAR_LOCAL = kar_is_local();

header('Cache-Control: no-store');
function gh($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

if ($KAR_LOCAL) {
    $pdo = kar_db();
} else {
    $cfg = require __DIR__ . '/../config/database.php';
    $pdo = new PDO("mysql:host={$cfg['host']};dbname={$cfg['database']};charset=utf8mb4",
        $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}
// "12 hours ago", in whichever dialect this install speaks — the guest caps are the same
// either way, only the way of saying it differs.
$KAR_12H = $KAR_LOCAL ? "datetime('now','localtime','-12 hours')" : "NOW() - INTERVAL 12 HOUR";

$tok = trim($_REQUEST['t'] ?? '');
$real = '';
try { $real = (string)$pdo->query("SELECT v FROM karaoke_settings WHERE k='guest_token'")->fetchColumn(); } catch (Throwable $e) {}
$tokenOk = $real !== '' && $tok !== '' && hash_equals($real, $tok);

// ---- JSON actions (request / line state) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!$tokenOk) { echo json_encode(['ok' => false, 'error' => 'This party code is no longer valid — scan the QR code on the screen again.']); exit; }
    $act = $_POST['action'] ?? '';
    /** @var bool $KAR_LOCAL */
    $qState = function() use ($pdo) {
        $rows = [];
        foreach ($pdo->query("SELECT singer, filename, status FROM karaoke_sing_queue WHERE status IN ('Singing','Waiting') ORDER BY (status='Singing') DESC, position ASC, id ASC") as $r) {
            $rows[] = ['singer' => $r['singer'], 'song' => preg_replace('/\.[a-z0-9]{2,4}$/i', '', $r['filename']), 'status' => $r['status']];
        }
        return $rows;
    };
    // The guest's own download requests (last 12h) — shown as live status on their phone.
    $dlState = function($name) use ($pdo, $KAR_12H) {
        if ($name === '') return [];
        $st = $pdo->prepare("SELECT status, title, note FROM karaoke_downloads WHERE requested_by = ? AND requested_at > $KAR_12H ORDER BY id DESC LIMIT 5");
        $st->execute([$name]);
        $rows = [];
        foreach ($st as $r) {
            $rows[] = ['status' => $r['status'], 'title' => (string)$r['title'], 'note' => (string)$r['note']];
        }
        return $rows;
    };
    $gName = trim($_POST['name'] ?? '');
    try {
        if ($act === 'dlreq') {
            // Guest brings a NEW song: paste a YouTube link -> the Mac downloads it into the
            // library, adds it to the guest's own Best list, and puts them in the Up Next line
            // (the owner's design, 2026-09-07). Caps keep it a party feature, not a firehose.
            $name = $gName;
            $url  = trim($_POST['url'] ?? '');
            if ($name === '' || mb_strlen($name) > 40 || strpos($name, '/') !== false || strpos($name, '\\') !== false) throw new Exception('Please enter your name first (up to 40 letters).');
            if (strlen($url) > 500 || !preg_match('#^https://(www\.|m\.|music\.)?(youtube\.com/(watch\?|shorts/)|youtu\.be/)[^\s]+$#', $url)) throw new Exception('That does not look like a YouTube link — in YouTube tap Share, then Copy link, and paste it here.');
            $mine = $pdo->prepare("SELECT COUNT(*) FROM karaoke_downloads WHERE requested_by = ? AND requested_at > $KAR_12H AND status <> 'Error'");
            $mine->execute([$name]);
            if ((int)$mine->fetchColumn() >= 2) throw new Exception('You already brought 2 new songs tonight — enjoy those first!');
            $all = $pdo->query("SELECT COUNT(*) FROM karaoke_downloads WHERE requested_by IS NOT NULL AND requested_at > $KAR_12H AND status <> 'Error'")->fetchColumn();
            if ((int)$all >= 15) throw new Exception('The download list is full for tonight — ask the host to add it.');
            $dup = $pdo->prepare("SELECT COUNT(*) FROM karaoke_downloads WHERE url = ? AND status IN ('Queued','Pending','Downloading')");
            $dup->execute([$url]);
            if ((int)$dup->fetchColumn() > 0) throw new Exception('That song is already being fetched — give it a few minutes.');
            // Straight to Pending: the Mac starts right away, nobody has to press anything.
            $pdo->prepare("INSERT INTO karaoke_downloads (url, requested_by, auto_sing, status) VALUES (?, ?, 1, 'Pending')")->execute([$url, $name]);
            if ($KAR_LOCAL) kar_worker_spawn();   // nobody is watching a queue here — start it now
            echo json_encode(['ok' => true, 'error' => '', 'queue' => $qState(), 'dls' => $dlState($name)], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($act === 'req') {
            $name = trim($_POST['name'] ?? '');
            $song = trim($_POST['song'] ?? '');
            if ($name === '' || mb_strlen($name) > 40 || strpos($name, '/') !== false || strpos($name, '\\') !== false) throw new Exception('Please enter your name (up to 40 letters).');
            if (!kar_catalog_has($pdo, $song)) throw new Exception('That song is not in the database anymore — search again.');
            $mine = $pdo->prepare("SELECT COUNT(*) FROM karaoke_sing_queue WHERE status='Waiting' AND singer = ?");
            $mine->execute([$name]);
            if ((int)$mine->fetchColumn() >= 3) throw new Exception('You already have 3 songs waiting — sing one first!');
            if ((int)$pdo->query("SELECT COUNT(*) FROM karaoke_sing_queue WHERE status='Waiting'")->fetchColumn() >= 50) throw new Exception('The line is full right now — try again in a little while.');
            $pos = (int)$pdo->query("SELECT COALESCE(MAX(position),0) FROM karaoke_sing_queue")->fetchColumn() + 1;
            // Guests sing at the original key (pitch 0) — the host can adjust live during the song.
            $pdo->prepare("INSERT INTO karaoke_sing_queue (singer, filename, pitch, status, position) VALUES (?,?,0,'Waiting',?)")->execute([$name, $song, $pos]);
        }
        echo json_encode(['ok' => true, 'error' => '', 'queue' => $qState(), 'dls' => $dlState($gName)], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'queue' => $qState(), 'dls' => $dlState($gName)], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ---- the page ----
$_db = $tokenOk ? kar_catalog() : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>🎤 Song Requests</title>
<style>
  * { box-sizing: border-box; }
  body { margin:0; background:#1A1F2C; color:#e2e8f0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; }
  .wrap { max-width:560px; margin:0 auto; padding:16px 14px 40px; }
  input { font-family:inherit; }
</style>
</head>
<body>
<div class="wrap">
  <h1 style="margin:0 0 4px;font-size:20px;font-weight:800;color:#f3f4f6">🎤 Song Requests</h1>
<?php if (!$tokenOk): ?>
  <p style="color:#f87171;font-size:14px;margin-top:14px">This link isn't active. Scan the QR code on the karaoke screen to get in — and if you already did, ask the host to show the code again (it may have been renewed).</p>
<?php else: ?>
  <p style="color:#94a3b8;font-size:12.5px;margin:0 0 12px">Type your name, find your song, tap Request — you'll see your place in line.</p>
  <input id="g-name" type="text" placeholder="Your name" maxlength="40" style="width:100%;background:#121620;border:1px solid #334155;border-radius:10px;color:#e2e8f0;font-size:15px;padding:11px 13px">
  <div id="g-line" style="margin-top:12px"></div>
  <input id="g-search" type="text" placeholder="Search a song… (artist or title)" style="width:100%;margin-top:12px;background:#121620;border:1px solid #334155;border-radius:10px;color:#e2e8f0;font-size:15px;padding:11px 13px">
  <div id="g-results" style="margin-top:8px"></div>
  <div style="margin-top:16px;background:rgba(210,173,108,.07);border:1px solid rgba(210,173,108,.3);border-radius:10px;padding:12px 14px">
    <p style="margin:0;color:#D2AD6C;font-size:13.5px;font-weight:700">🎁 Can't find your song?</p>
    <p style="margin:6px 0 8px;color:#94a3b8;font-size:12px">Open YouTube on your phone, find the song (a karaoke or lyrics version works best), tap <b>Share → Copy link</b>, come back here and paste it:</p>
    <input id="g-url" type="text" placeholder="Paste the YouTube link here…" style="width:100%;background:#121620;border:1px solid #334155;border-radius:10px;color:#e2e8f0;font-size:14px;padding:10px 12px">
    <button type="button" id="g-dlbtn" onclick="gDlReq()" style="margin-top:8px;width:100%;font-family:inherit;background:#166534;border:1px solid #16a34a;color:#fff;cursor:pointer;font-size:14px;font-weight:700;padding:10px 0;border-radius:10px">🎁 Bring this song to the party</button>
    <div id="g-dls" style="margin-top:6px"></div>
    <p style="margin:8px 0 0;color:#64748b;font-size:10.5px">The song downloads in a few minutes, joins the party list under your name, and you're put in the line to sing it. Up to 2 new songs per person per night.</p>
  </div>
  <p style="color:#64748b;font-size:11px;margin-top:18px">Up to 3 songs waiting per person. Songs play at the original key — the host can adjust the pitch live.</p>
<script>
  var G_DB = <?= json_encode($_db, JSON_UNESCAPED_UNICODE) ?>;
  var G_TOK = <?= json_encode($tok) ?>;
  var gQueue = [];
  function gEsc(s){ return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
  var nameEl = document.getElementById('g-name');
  try { var n0 = localStorage.getItem('kguest_name'); if (n0) nameEl.value = n0; } catch(e){}
  nameEl.addEventListener('change', function(){ try { localStorage.setItem('kguest_name', nameEl.value.trim()); } catch(e){} gLine(); });

  function gPost(fields){
    var fd = new FormData();
    fd.append('t', G_TOK);
    if (!('name' in fields)) fd.append('name', nameEl.value.trim());  // so 'state' can fetch this guest's downloads
    for (var k in fields) fd.append(k, fields[k]);
    return fetch('/karaoke_guest.php?t=' + encodeURIComponent(G_TOK), { method:'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(d){ if (d.queue) { gQueue = d.queue; gLine(); } gDls(d.dls); return d; });
  }
  function gDls(list){
    var el = document.getElementById('g-dls');
    if (!el) return;
    if (!list || !list.length) { el.innerHTML = ''; return; }
    var out = [];
    for (var i = 0; i < list.length; i++) {
      var d = list[i], txt, col;
      if (d.status === 'Done') { txt = '✅ ' + (d.title || 'Your song') + ' — ready! You are in the line to sing it.'; col = '#6ee7b7'; }
      else if (d.status === 'Error') { txt = '✕ ' + (d.title || 'Your song') + ' — could not be fetched. Try a different YouTube version.'; col = '#f87171'; }
      else { txt = '⏳ ' + (d.title || 'Your song') + ' — on its way, give it a few minutes…'; col = '#D2AD6C'; }
      out.push('<div style="padding:4px 0;font-size:12.5px;color:' + col + '">' + gEsc(txt) + '</div>');
    }
    el.innerHTML = out.join('');
  }
  function gDlReq(){
    var name = nameEl.value.trim();
    if (!name) { alert('Type your name first, so the song is yours!'); nameEl.focus(); return; }
    try { localStorage.setItem('kguest_name', name); } catch(e){}
    var u = document.getElementById('g-url');
    var url = u.value.trim();
    if (!url) { u.focus(); return; }
    var b = document.getElementById('g-dlbtn');
    b.disabled = true; b.textContent = '…';
    gPost({ action:'dlreq', name:name, url:url }).then(function(d){
      b.disabled = false; b.textContent = '🎁 Bring this song to the party';
      if (!d.ok) { alert(d.error || 'Not sent.'); return; }
      u.value = '';
    }).catch(function(){ b.disabled = false; b.textContent = '🎁 Bring this song to the party'; alert('Network hiccup — try again.'); });
  }
  function gLine(){
    var me = nameEl.value.trim().toLowerCase();
    var singing = gQueue.filter(function(e){ return e.status === 'Singing'; })[0];
    var waiting = gQueue.filter(function(e){ return e.status === 'Waiting'; });
    var out = [];
    if (singing) out.push('<div style="color:#D2AD6C;font-size:13.5px;font-weight:700;padding:6px 2px">🎤 Now singing: ' + gEsc(singing.singer) + ' — ' + gEsc(singing.song) + '</div>');
    for (var i = 0; i < waiting.length; i++) {
      var e = waiting[i];
      var mine = me && e.singer.toLowerCase() === me;
      out.push('<div style="display:flex;gap:8px;align-items:baseline;padding:4px 2px;border-top:1px solid #1e293b;' + (mine ? 'background:rgba(210,173,108,.08);border-radius:6px' : '') + '">'
        + '<span style="flex:0 0 20px;color:#64748b;font-size:12px;font-weight:700">' + (i + 1) + '.</span>'
        + '<span style="color:' + (mine ? '#D2AD6C' : '#94a3b8') + ';font-size:13px;font-weight:700">' + gEsc(e.singer) + '</span>'
        + '<span style="color:#cbd5e1;font-size:12.5px">' + gEsc(e.song) + '</span></div>');
    }
    document.getElementById('g-line').innerHTML = out.length
      ? '<div style="background:#121620;border:1px solid rgba(210,173,108,.3);border-radius:10px;padding:8px 10px">' + out.join('') + '</div>'
      : '';
  }
  var srch = document.getElementById('g-search');
  srch.addEventListener('input', gResults);
  function gResults(){
    var q = srch.value.trim().toLowerCase();
    var res = document.getElementById('g-results');
    if (q.length < 2) { res.innerHTML = ''; return; }
    var out = [], n = 0;
    for (var i = 0; i < G_DB.length && n < 40; i++) {
      if (G_DB[i].toLowerCase().indexOf(q) === -1) continue;
      n++;
      out.push('<div style="display:flex;align-items:center;gap:8px;padding:7px 4px;border-top:1px solid #1e293b">'
        + '<span style="flex:1;color:#e2e8f0;font-size:13px">' + gEsc(G_DB[i].replace(/\.[a-z0-9]{2,4}$/i,'')) + '</span>'
        + '<button type="button" data-i="' + i + '" style="font-family:inherit;flex:0 0 auto;background:#166534;border:1px solid #16a34a;color:#fff;cursor:pointer;font-size:12.5px;font-weight:700;padding:6px 12px;border-radius:8px">Request</button>'
        + '</div>');
    }
    res.innerHTML = out.length ? out.join('') : '<p style="color:#64748b;font-size:12.5px">No songs match.</p>';
  }
  document.getElementById('g-results').addEventListener('click', function(ev){
    var b = ev.target.closest ? ev.target.closest('button') : null;
    if (!b) return;
    var name = nameEl.value.trim();
    if (!name) { alert('Type your name first, so the host knows who is singing!'); nameEl.focus(); return; }
    try { localStorage.setItem('kguest_name', name); } catch(e){}
    var song = G_DB[parseInt(b.getAttribute('data-i'), 10)];
    b.disabled = true; b.textContent = '…';
    gPost({ action:'req', name:name, song:song }).then(function(d){
      if (!d.ok) { b.disabled = false; b.textContent = 'Request'; alert(d.error || 'Not added.'); return; }
      b.textContent = '✓ In line!'; b.style.background = '#1d4ed8'; b.style.borderColor = '#2563eb';
    }).catch(function(){ b.disabled = false; b.textContent = 'Request'; alert('Network hiccup — try again.'); });
  });
  gPost({ action:'state' }).catch(function(){});
  setInterval(function(){ gPost({ action:'state' }).catch(function(){}); }, 12000);
</script>
<?php endif; ?>
</div>
</body>
</html>
