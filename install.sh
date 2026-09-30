#!/bin/bash
# Karaoke — the whole installation, from a bare Mac.
#
# It fetches the program, installs the two things it needs, asks where the songs are,
# makes the Desktop icon, and starts it. The only thing it cannot do for you is type
# your Mac password, which Homebrew asks for once.
#
#   curl -fsSL <install url> | bash -s -- ~/Karaoke
#
# Options, for when someone else is driving:
#   --songs "/path/to/songs"   skip the folder question
#   --sync  "/path/to/folder"  keep lists in step with another Mac through a shared folder
#   --no-autostart             never start on its own (chosen automatically for laptops)
set -uo pipefail

main() {
REPO="claudegulino-bit/family-karaoke"
DEST="$HOME/Karaoke"
SONGS=""; SYNC=""; AUTOSTART="auto"

while [ $# -gt 0 ]; do
  case "$1" in
    --songs) SONGS="${2:-}"; shift 2 ;;
    --sync)  SYNC="${2:-}";  shift 2 ;;
    --no-autostart) AUTOSTART="no"; shift ;;
    -*) echo "Unknown option $1"; exit 1 ;;
    *) DEST="$1"; shift ;;
  esac
done

say() { printf "\n\033[1m%s\033[0m\n" "$1"; }
ok()  { printf "   %s\n" "$1"; }

say "Cantoria — setting up this Mac"

# ---------------------------------------------------------------- 1 · the tools
# ---------------------------------------------------- 0 · preflight
# ⚠ THIS RUNS BEFORE ANYTHING IS INSTALLED, and it exists because of 2026-09-22:
# an afternoon was spent installing onto a 2018 INTEL Mac mini before anyone checked
# the chip. Homebrew no longer ships macOS Intel bottles, so every tool would have had
# to compile from source. Ten seconds of looking would have saved the whole session.
say "Checking this Mac"
ARCH="$(uname -m)"
if [ "$ARCH" != "arm64" ]; then
  echo "   This is an Intel Mac ($ARCH). Homebrew no longer publishes ready-built"
  echo "   packages for Intel macOS, so php, mpv and ffmpeg would each have to be"
  echo "   compiled from source - hours of work, and it usually fails partway."
  echo "   Cantoria needs an Apple Silicon Mac (M1 or newer). Nothing was changed."
  exit 1
fi
ok "Apple Silicon — good"
if ! curl -fsS -m 10 -o /dev/null https://formulae.brew.sh/api/formula/php.json 2>/dev/null; then
  echo "   This Mac cannot reach the internet, and the installer needs to download"
  echo "   php and mpv. Connect to Wi-Fi and run this again. Nothing was changed."
  exit 1
fi
ok "online — good"
# An orphaned Homebrew is invisible until an install silently fails: `ls` prints a bare
# UID where a username should be, because the account that installed it is gone.
PREBREW="$(command -v brew || true)"
if [ -n "$PREBREW" ]; then
  BPFX="$(dirname "$(dirname "$PREBREW")")"
  BOWN="$(stat -f '%Su' "$BPFX/Cellar" 2>/dev/null || stat -f '%Su' "$BPFX" 2>/dev/null || echo '')"
  if [ -n "$BOWN" ] && [ "$BOWN" != "$(whoami)" ]; then
    echo "   Homebrew is here but belongs to another account ('$BOWN'), so this one"
    echo "   cannot install anything with it. That happens when the Mac's original"
    echo "   user was deleted. Fix it with this one line, then run the installer again:"
    echo ""
    echo "     sudo chown -R \"\$(whoami):admin\" $BPFX"
    echo ""
    echo "   Nothing was changed."
    exit 1
  fi
  ok "Homebrew — yours, good"
fi

say "1 of 6 · The player"
BREW="$(command -v brew || true)"
[ -z "$BREW" ] && [ -x /opt/homebrew/bin/brew ] && BREW=/opt/homebrew/bin/brew
[ -z "$BREW" ] && [ -x /usr/local/bin/brew ] && BREW=/usr/local/bin/brew
if [ -z "$BREW" ]; then
  ok "Homebrew is not on this Mac yet — installing it."
  # ⚠ </dev/tty ONLY EXISTS IN A TERMINAL. Run from the .pkg installer — or any wrapper —
  # there is no terminal, and that redirect fails outright. So ask for a password the
  # normal way when a terminal is there, and go non-interactive when it is not.
  if [ -t 0 ] || [ -e /dev/tty ]; then
    ok "It will ask for your Mac password. Nothing appears as you type it; that is normal."
    /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)" </dev/tty || {
      echo "   Homebrew would not install. Nothing else was changed."; exit 1; }
  else
    NONINTERACTIVE=1 /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)" || {
      echo "   Homebrew would not install. Nothing else was changed."; exit 1; }
  fi
  BREW="$( [ -x /opt/homebrew/bin/brew ] && echo /opt/homebrew/bin/brew || echo /usr/local/bin/brew )"
fi
eval "$("$BREW" shellenv)" 2>/dev/null || true
# ⚠ FOUR, NOT TWO. Apple REMOVED php from macOS in Monterey (12.0) and Cantoria IS php —
# with no php the page cannot be served at all and the Desktop icon opens nothing. This
# installed only mpv and yt-dlp until 2026-09-21; the Macs that worked did so because
# `brew install php` had been typed by hand. ffmpeg was the same story: without it a
# download is never codec-checked, so an AV1 file arrives playing sound with no picture.
FAILED=""
TOOLLOG="$(mktemp -t cantoria-tool)"
for t in php mpv yt-dlp ffmpeg; do
  if command -v "$t" >/dev/null 2>&1; then ok "$t — already here"
  else
    # ⚠ BRACES ARE LOAD-BEARING. macOS ships /bin/bash 3.2, and under a UTF-8 locale
    # it parses `$t…` as a variable NAMED `t…` — `set -u` then kills the script.
    # A fresh Mac has no Homebrew bash, so `| bash` IS 3.2. This line only runs when a
    # tool is MISSING, so it never fired on a Mac that already had them — which is why
    # it survived until the first genuinely new Mac (2026-09-22). Do not remove the {}.
    ok "installing ${t}…"
    # ⚠ TWO BUGS LIVED HERE UNTIL 2026-09-23, both found on a genuinely fresh Mac.
    #  (a) output went to /dev/null, so a failure could only ever say "FAILED" with no
    #      reason. It cost three rounds of guesswork to find an orphaned Homebrew.
    #      Now it goes to a log and the real error is PRINTED when the step fails.
    #  (b) Homebrew 7 asks "Do you want to proceed? [y/n]" before pulling dependencies.
    #      Under `curl ... | bash` the script's stdin IS THE PIPE, so that question can
    #      never be answered and brew gives up. NONINTERACTIVE + </dev/null settle it.
    if NONINTERACTIVE=1 HOMEBREW_NO_ENV_HINTS=1 "$BREW" install "$t" > "$TOOLLOG" 2>&1 </dev/null; then
      ok "$t — installed"
    else
      ok "$t — FAILED. Homebrew said:"
      sed 's/^/        /' "$TOOLLOG" | tail -12
      FAILED="$FAILED $t"
    fi
  fi
done
eval "$("$BREW" shellenv)" 2>/dev/null || true
if ! command -v php >/dev/null 2>&1 && [ ! -x /opt/homebrew/bin/php ] && [ ! -x /usr/local/bin/php ]; then
  echo "   php would not install, and Cantoria is built on php — it cannot run without it."
  echo "   Nothing else was changed. Try again when this Mac is online."
  exit 1
fi
[ -n "$FAILED" ] && ok "still missing:$FAILED — say so and it can be finished in a minute"

# ------------------------------------------------------------- 2 · the program
say "2 of 6 · Cantoria itself"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
SHA="$(curl -fsSL "https://api.github.com/repos/$REPO/commits/main?_=$(date +%s)" 2>/dev/null \
        | sed -n 's/.*"sha"[[:space:]]*:[[:space:]]*"\([0-9a-f]\{40\}\)".*/\1/p' | head -1)"
curl -fsSL "https://codeload.github.com/$REPO/tar.gz/${SHA:-refs/heads/main}" -o "$TMP/k.tgz" || {
  echo "   Could not download Cantoria. Is this Mac online?"; exit 1; }
tar -xzf "$TMP/k.tgz" -C "$TMP"
SRC="$(find "$TMP" -type d -name app -maxdepth 2 | head -1)"
[ -d "$SRC" ] || { echo "   That download did not look right — nothing was changed."; exit 1; }
mkdir -p "$DEST/logs"
for f in "$SRC"/*; do
  b="$(basename "$f")"
  [ "$b" = "karaoke_standalone.example.json" ] && continue
  [ -d "$f" ] && continue   # folders are handled explicitly below (see "announcer/")
  cp "$f" "$DEST/$b"
done
# THE ANNOUNCER FOLDER (2026-09-26): copied as CONTENTS-INTO, not as a directory, because
# `cp -R "$f" "$DEST/$b"` NESTS instead of merging when $DEST/announcer already exists — which
# is exactly the case on an update, and it would have buried the shipped files a level deep
# under the multi-gigabyte .venv this preserves. This form works whether $DEST/announcer is
# fresh or already has a working voice installed in it.
if [ -d "$SRC/announcer" ]; then
  mkdir -p "$DEST/announcer/assets"
  cp -R "$SRC/announcer/." "$DEST/announcer/"
fi
chmod +x "$DEST/start.command" "$DEST/update.sh" 2>/dev/null || true
ok "version $(cat "$DEST/VERSION" 2>/dev/null) — in $DEST"

# ---------------------------------------------------------------- 3 · the songs
say "3 of 6 · Your songs"
if [ -z "$SONGS" ] && [ -f "$DEST/karaoke_standalone.json" ]; then
  SONGS="$(/usr/bin/python3 -c 'import json,sys;print(json.load(open(sys.argv[1])).get("songs_folder",""))' "$DEST/karaoke_standalone.json" 2>/dev/null || true)"
fi
if [ -z "$SONGS" ] || [ ! -d "$SONGS" ]; then
  ok "A folder chooser is opening — point it at the folder holding your song files."
  SONGS="$(osascript -e 'try
set f to choose folder with prompt "Where are your karaoke songs?"
POSIX path of f
on error number -128
""
end try' 2>/dev/null | sed 's:/$::')"
fi
if [ -n "$SONGS" ] && [ -d "$SONGS" ]; then
  # ⚠ grep -c PRINTS 0 and EXITS 1 when nothing matches, so `|| echo 0` prints a second
  # zero and N becomes the two-line "0\n0". Ignore grep's status instead.
  N="$(ls -1 "$SONGS" 2>/dev/null | grep -icE '\.(mp4|mp3|m4a|mov|avi|m4v|wav|mid|kar)$' || :)"
  case "$N" in ''|*[!0-9]*) N=0 ;; esac
  ok "$N songs found"
else
  ok "No folder chosen — you can set it later in the Guide, under Setting up the Mac."
  SONGS=""
fi
PARENT="$(dirname "$SONGS" 2>/dev/null || echo "$HOME")"
/usr/bin/python3 - "$DEST/karaoke_standalone.json" "$SONGS" "$PARENT" "$SYNC" <<'PY'
import json, sys, os
path, songs, parent, sync = sys.argv[1:5]
cfg = {}
if os.path.exists(path):
    try: cfg = json.load(open(path))
    except Exception: cfg = {}
cfg.setdefault("_readme", "This file being here is what makes this a standalone karaoke: no server, no accounts, no cloud. Everything else is chosen on screen, in the Guide.")
if songs:
    cfg["songs_folder"]      = songs
    cfg.setdefault("deleted_folder",    os.path.join(parent, "09-Deleted by casAI"))
    cfg.setdefault("live_lists_folder", os.path.join(parent, "08-Live Playlists"))
    cfg.setdefault("temp_folder",       os.path.join(parent, "03-YouTube New Songs"))
cfg.setdefault("port", "8899")
cfg.setdefault("singers", [])
if sync: cfg["sync_folder"] = sync
json.dump(cfg, open(path, "w"), indent=4, ensure_ascii=False)
PY

# ------------------------------------------------------ 4 · the announcer voice
# THE VOICE IS PART OF CANTORIA, NOT AN EXTRA — the whole install, not something added on later.
# Every Mac gets: a photo intro
# at Next singer (their photo, name, song and applause) spoken by Chatterbox (MIT), cloned from
# klankbeeld's Freesound clip (CC BY 4.0 — credited in the Guide), plus a local speech check
# (OpenAI Whisper) that confirms the name was actually said before it is used.
#
# This is a few GB and several minutes — real, and unlike php/mpv/yt-dlp/ffmpeg. It is NEVER
# fatal: if any step here fails, Cantoria falls back to the Mac's own built-in voice and says so.
# Do not exit non-zero over anything in this block.
say "4 of 6 · The announcer voice"
ok "This downloads a few gigabytes and can take several minutes. Cantoria works either way —"
ok "without it, the Mac's own voice announces instead."
ANNOUNCER="$DEST/announcer"
VOICE_OK="no"
# A Mac already pointed at a voice folder of its own (the dev laptop keeps a private one)
# skips this entirely - nothing to gain from building a second, unused engine here.
EXISTING="$(/usr/bin/python3 -c 'import json,sys
try: print(json.load(open(sys.argv[1])).get("announce_chatterbox",""))
except Exception: print("")' "$DEST/karaoke_standalone.json" 2>/dev/null || true)"
if [ -n "$EXISTING" ] && [ "$EXISTING" != "$ANNOUNCER" ]; then
  ok "this Mac already points its voice at $EXISTING — leaving it alone"
  VOICE_OK="yes"
else
PY312="$(command -v python3.12 || true)"
[ -z "$PY312" ] && [ -x /opt/homebrew/opt/python@3.12/bin/python3.12 ] && PY312=/opt/homebrew/opt/python@3.12/bin/python3.12
if [ -z "$PY312" ]; then
  ok "installing python@3.12…"
  if NONINTERACTIVE=1 HOMEBREW_NO_ENV_HINTS=1 "$BREW" install python@3.12 > "$TOOLLOG" 2>&1 </dev/null; then
    PY312="/opt/homebrew/opt/python@3.12/bin/python3.12"
    [ -x "$PY312" ] || PY312="$(command -v python3.12 || true)"
  else
    ok "python@3.12 — FAILED. Homebrew said:"; sed 's/^/        /' "$TOOLLOG" | tail -12
  fi
fi
if [ -n "$PY312" ] && [ -x "$PY312" ]; then
  if [ ! -x "$ANNOUNCER/.venv/bin/python" ]; then
    ok "setting up the voice (this is the several-minute part)…"
    mkdir -p "$ANNOUNCER"
    # TWO pip calls, not one (2026-09-26): numpy has to be fully installed BEFORE
    # openai-whisper/chatterbox-tts are, or one of their own dependencies (pkuseg) fails to
    # build - it imports numpy directly in its own setup.py, and pip's build isolation does
    # not see a package installed in the SAME command. Found by testing this exact line.
    if "$PY312" -m venv "$ANNOUNCER/.venv" > "$TOOLLOG" 2>&1 && \
       "$ANNOUNCER/.venv/bin/pip" install -q --upgrade pip >> "$TOOLLOG" 2>&1 && \
       "$ANNOUNCER/.venv/bin/pip" install -q "setuptools<81" numpy soundfile librosa pillow torch torchaudio >> "$TOOLLOG" 2>&1 && \
       "$ANNOUNCER/.venv/bin/pip" install -q openai-whisper chatterbox-tts >> "$TOOLLOG" 2>&1; then
      ok "voice engine installed"
    else
      ok "the voice engine could not be fully installed. Log:"
      sed 's/^/        /' "$TOOLLOG" | tail -15
      rm -rf "$ANNOUNCER/.venv"
    fi
  else
    ok "voice engine — already here"
  fi
else
  ok "python@3.12 is not available — skipping the voice for now (Cantoria still works)."
fi
if [ -x "$ANNOUNCER/.venv/bin/python" ] && [ -f "$ANNOUNCER/cantoria_mc_intro.py" ]; then
  VOICE_OK="yes"
fi
fi   # closes "already pointed elsewhere" above
/usr/bin/python3 - "$DEST/karaoke_standalone.json" "$ANNOUNCER" <<'PY'
import json, sys
path, announcer = sys.argv[1:3]
cfg = json.load(open(path))
# Always recorded, even if setup failed above — kar_cb_dir() checks the files itself and
# falls back cleanly, so this never breaks anything, it only tells Cantoria where to look.
# NEVER overwrite an existing pointer (see update.sh's copy of this same guard).
cfg.setdefault("announce_chatterbox", announcer)
json.dump(cfg, open(path, "w"), indent=4, ensure_ascii=False)
PY
if [ "$VOICE_OK" = "yes" ]; then
  ok "the voice is ready — the first announcement will take a little longer while its"
  ok "models download once."
else
  ok "the voice was not set up — Cantoria will use this Mac's own voice instead."
fi

# -------------------------------------------------------------- 5 · the icon
say "5 of 6 · The Desktop icon"
# ⚠ ORDER MATTERS. osacompile SEALS the bundle, so the icon goes in BEFORE signing and the
# signature is applied LAST — otherwise macOS refuses to open it as "damaged or incomplete".
PORT="$(/usr/bin/python3 -c 'import json,sys;print(json.load(open(sys.argv[1])).get("port","8899"))' "$DEST/karaoke_standalone.json")"
# Look in Homebrew's own prefixes too: php may have been installed a minute ago by this
# very script, before the shell's PATH knew about it.
PHPBIN="$(command -v php || true)"
[ -z "$PHPBIN" ] && [ -x /opt/homebrew/bin/php ] && PHPBIN=/opt/homebrew/bin/php
[ -z "$PHPBIN" ] && [ -x /usr/local/bin/php ]    && PHPBIN=/usr/local/bin/php
[ -z "$PHPBIN" ] && PHPBIN=php
cat > "$TMP/launch.applescript" <<AS
-- cantoria launcher v2 (KEEP IN STEP with update.sh's copy in publish_karaoke.php)
on run
	set theURL to "http://localhost:$PORT/karaoke.php"
	set code to do shell script "curl -s -o /dev/null -m 5 -w '%{http_code}' " & quoted form of theURL & " 2>/dev/null || echo 000"
	if code is not "200" then
		-- stdin, stdout and stderr all redirected, or this script waits on the server forever
		-- and every later double-click only wakes the stuck copy (2026-09-26)
		-- If this Mac has the start-by-itself service, wake THAT rather than starting a second
		-- server beside it (two copies left the service failing every 10 s, 2026-09-29).
		do shell script "launchctl kickstart gui/$(id -u)/com.familykaraoke.server 2>/dev/null || (cd $DEST && nohup $PHPBIN -S 0.0.0.0:$PORT -t . > $DEST/logs/server.log 2>&1 < /dev/null &)"
		repeat 20 times
			delay 1
			set code to do shell script "curl -s -o /dev/null -m 5 -w '%{http_code}' " & quoted form of theURL & " 2>/dev/null || echo 000"
			if code is "200" then exit repeat
		end repeat
	end if
	if code is "200" then
		-- Chrome BY NAME first (2026-09-29): on one family Mac "the default browser" for plain http://
		-- was not Chrome, so "open location" handed the page to nothing and the icon looked dead.
		try
			do shell script "open -a 'Google Chrome' " & quoted form of theURL
		on error
			open location theURL
		end try
	else
		activate
		display dialog "Cantoria could not start on this Mac." buttons {"OK"} default button 1 with icon caution with title "Cantoria" giving up after 30
	end if
end run
AS
rm -rf "$TMP/Cantoria.app"
if osacompile -o "$TMP/Cantoria.app" "$TMP/launch.applescript" 2>/dev/null; then
  # THE CANTORIA ICON (pink/orange, microphone, the name — chosen 2026-09-26). macOS 26 draws an
  # osacompile app from its Assets.car and ignores applet.icns, so the icon came out a faint gray
  # square nobody could find. Remove the asset catalogue and its Info.plist pointer, and the
  # .icns is what shows.
  if [ -f "$DEST/karaoke.icns" ]; then
    rm -f "$TMP/Cantoria.app/Contents/Resources/Assets.car"
    /usr/libexec/PlistBuddy -c "Delete :CFBundleIconName" "$TMP/Cantoria.app/Contents/Info.plist" 2>/dev/null || true
    cp "$DEST/karaoke.icns" "$TMP/Cantoria.app/Contents/Resources/applet.icns"
  fi
  xattr -cr "$TMP/Cantoria.app" 2>/dev/null || true
  codesign --force --deep -s - "$TMP/Cantoria.app" 2>/dev/null || true
  rm -rf "$HOME/Desktop/Cantoria.app" "$HOME/Desktop/Karaoke.app"
  mv "$TMP/Cantoria.app" "$HOME/Desktop/Cantoria.app"
  ok "Cantoria.app is on the Desktop"
else
  ok "could not build the Desktop icon — start.command in $DEST does the same job"
fi

# ----------------------------------------------------- 5 · running, and staying up
say "6 of 6 · Starting it"
MODEL="$(sysctl -n hw.model 2>/dev/null || echo unknown)"
if [ "$AUTOSTART" = "auto" ]; then
  case "$MODEL" in MacBook*) AUTOSTART="no" ;; *) AUTOSTART="yes" ;; esac
fi
PLIST="$HOME/Library/LaunchAgents/com.familykaraoke.server.plist"
if [ "$AUTOSTART" = "yes" ]; then
  mkdir -p "$HOME/Library/LaunchAgents"
  cat > "$PLIST" <<PLI
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key><string>com.familykaraoke.server</string>
  <!-- ⚠ Without this key, launchd can place the job outside the real GUI
       (Aqua) session — especially likely if bootstrap fell through to the legacy
       launchctl load below. mpv then opens a WINDOW but cannot actually play
       video through it: it comes up idle, the song never loads, and nothing
       in mpv's own log says why. Found and traced on the M4, 2026-09-21. -->
  <key>LimitLoadToSessionType</key><string>Aqua</string>
  <key>ProgramArguments</key>
  <array>
    <string>/usr/bin/caffeinate</string><string>-s</string>
    <string>$PHPBIN</string>
    <string>-S</string><string>0.0.0.0:$PORT</string>
    <string>-t</string><string>$DEST</string>
  </array>
  <key>WorkingDirectory</key><string>$DEST</string>
  <key>RunAtLoad</key><true/>
  <key>KeepAlive</key><true/>
  <key>StandardOutPath</key><string>$DEST/logs/server.log</string>
  <key>StandardErrorPath</key><string>$DEST/logs/server.log</string>
</dict>
</plist>
PLI
  launchctl bootout "gui/$(id -u)/com.familykaraoke.server" 2>/dev/null || true
  launchctl bootstrap "gui/$(id -u)" "$PLIST" 2>/dev/null || launchctl load "$PLIST" 2>/dev/null || true
  ok "it will start by itself whenever this Mac is on, and stay awake while it runs"
else
  ok "this is a laptop, so it runs only when you open Karaoke — it will not serve a page"
  ok "to every café Wi-Fi you join."
  pkill -f "php -S 0.0.0.0:$PORT -t $DEST" 2>/dev/null || true
  # ⚠ </dev/null matters. Without it the server keeps the installer's own input open,
  # and anything reading the installer's output — a pipe, a log, a wrapper — waits forever
  # for a script that has actually already finished.
  ( cd "$DEST" && nohup "$PHPBIN" -S "0.0.0.0:$PORT" -t . > "$DEST/logs/server.log" 2>&1 </dev/null & )
fi
sleep 3

if [ "$(curl -s -o /dev/null -m 6 -w '%{http_code}' "http://localhost:$PORT/karaoke.php" 2>/dev/null)" = "200" ]; then
  say "Done."
  ok "Open Karaoke on the Desktop, or go to http://localhost:$PORT/karaoke.php"
  ok "Press 📖 Guide on the page to learn it in two minutes."
else
  say "Installed, but it is not answering yet."
  ok "Open Karaoke on the Desktop and it will start. If it will not, say so."
fi
}
main "$@"
