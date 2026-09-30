#!/bin/bash
# Fetches the current karaoke and puts it in place. Safe to run any time:
# your settings, your songs and your lists are never touched.
set -euo pipefail

# ⚠ EVERYTHING lives inside this function on purpose. This script replaces the program
# files — and one of them is this script. Bash reads a plain script a piece at a time as
# it runs, so overwriting it mid-run makes it carry on at the wrong place in the new file
# and die on a nonsense syntax error. Wrapped in a function, bash parses the whole thing
# before the first line executes, and replacing the file underneath is harmless.
main() {
REPO="claudegulino-bit/family-karaoke"
DEST="${1:-$(cd "$(dirname "$0")" && pwd)}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
HAD="$(cat "$DEST/VERSION" 2>/dev/null || true)"

echo "Fetching the newest Cantoria…"
# Ask which commit is current and fetch THAT one. The plain branch download is cached
# for a few seconds, which is long enough to hand back the version you already have.
# The "?_=" is not decoration. Without it GitHub hands back a cached branch pointer
# for up to a minute, so an Update pressed just after a change reports "already up to
# date" — the one wrong answer nobody would think to question.
SHA="$(curl -fsSL "https://api.github.com/repos/$REPO/commits/main?_=$(date +%s)" 2>/dev/null \
        | sed -n 's/.*"sha"[[:space:]]*:[[:space:]]*"\([0-9a-f]\{40\}\)".*/\1/p' | head -1)"
REF="${SHA:-refs/heads/main}"
curl -fsSL "https://codeload.github.com/$REPO/tar.gz/$REF" -o "$TMP/k.tgz"
tar -xzf "$TMP/k.tgz" -C "$TMP"
SRC="$(find "$TMP" -type d -name app -maxdepth 2 | head -1)"
[ -d "$SRC" ] || { echo "That download did not look right — nothing was changed."; exit 1; }

mkdir -p "$DEST"
for f in "$SRC"/*; do
  b="$(basename "$f")"
  [ "$b" = "karaoke_standalone.example.json" ] && continue
  [ -d "$f" ] && continue   # folders are handled explicitly below (see "announcer/")
  [ "$b" = "VERSION" ] && continue   # written LAST, below - see there
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
# The settings file is yours. It is only ever created, never overwritten.
[ -f "$DEST/karaoke_standalone.json" ] || cp "$SRC/karaoke_standalone.example.json" "$DEST/karaoke_standalone.json"
chmod +x "$DEST/start.command" "$DEST/update.sh" 2>/dev/null || true

# THE ANNOUNCER VOICE, added to an already-installed Mac by its own next update (2026-09-26).
# Runs ONCE — the moment the voice engine is already working, this whole block is a single
# fast check and does nothing else. Never fatal: a Mac that cannot get it keeps working with
# its own built-in voice.
# ⚠ KEEP IN STEP WITH install.sh's OWN COPY OF THIS BLOCK (karaoke_install_template.sh) —
# two implementations of the one setup drift exactly like the pitch reader has (hard rule 9).
EXISTING_VOICE="$(/usr/bin/python3 -c 'import json,sys
try: print(json.load(open(sys.argv[1])).get("announce_chatterbox",""))
except Exception: print("")' "$DEST/karaoke_standalone.json" 2>/dev/null || true)"
if [ "$(uname -m)" = "arm64" ] && [ ! -x "$DEST/announcer/.venv/bin/python" ] \
   && { [ -z "$EXISTING_VOICE" ] || [ "$EXISTING_VOICE" = "$DEST/announcer" ]; }; then
  echo "Setting up the announcer voice (once) — a few gigabytes, several minutes…"
  BREW="$(command -v brew || true)"
  [ -z "$BREW" ] && [ -x /opt/homebrew/bin/brew ] && BREW=/opt/homebrew/bin/brew
  [ -z "$BREW" ] && [ -x /usr/local/bin/brew ]    && BREW=/usr/local/bin/brew
  PY312="$(command -v python3.12 || true)"
  [ -z "$PY312" ] && [ -x /opt/homebrew/opt/python@3.12/bin/python3.12 ] && PY312=/opt/homebrew/opt/python@3.12/bin/python3.12
  if [ -z "$PY312" ] && [ -n "$BREW" ]; then
    NONINTERACTIVE=1 HOMEBREW_NO_ENV_HINTS=1 "$BREW" install python@3.12 >/tmp/cantoria-voice.log 2>&1 </dev/null || true
    PY312="/opt/homebrew/opt/python@3.12/bin/python3.12"; [ -x "$PY312" ] || PY312="$(command -v python3.12 || true)"
  fi
  if [ -n "$PY312" ] && [ -x "$PY312" ]; then
    # TWO pip calls, not one - see install.sh's copy of this same block for why.
    if "$PY312" -m venv "$DEST/announcer/.venv" >/tmp/cantoria-voice.log 2>&1 && \
       "$DEST/announcer/.venv/bin/pip" install -q --upgrade pip >>/tmp/cantoria-voice.log 2>&1 && \
       "$DEST/announcer/.venv/bin/pip" install -q "setuptools<81" numpy soundfile librosa pillow torch torchaudio >>/tmp/cantoria-voice.log 2>&1 && \
       "$DEST/announcer/.venv/bin/pip" install -q openai-whisper chatterbox-tts >>/tmp/cantoria-voice.log 2>&1; then
      echo "The announcer voice is set up."
    else
      echo "The announcer voice could not be fully set up — Cantoria still works with this"
      echo "Mac's own voice. Log: /tmp/cantoria-voice.log"
      rm -rf "$DEST/announcer/.venv"
    fi
  else
    echo "python@3.12 is not available — the announcer voice was skipped for now."
  fi
fi
/usr/bin/python3 - "$DEST/karaoke_standalone.json" "$DEST/announcer" <<'PYINNER'
import json, sys
path, announcer = sys.argv[1:3]
cfg = json.load(open(path))
# NEVER overwrite an existing pointer (this Mac may deliberately point elsewhere - the
# dev laptop keeps its own private voice folder this way). Only ever filled when unset.
cfg.setdefault("announce_chatterbox", announcer)
json.dump(cfg, open(path, "w"), indent=4, ensure_ascii=False)
PYINNER

# The Desktop icon follows the shipped one, so every Mac gets the Cantoria icon with its next
# update — not only a fresh install. Only the picture changes; the launcher inside is left alone.
APP="$HOME/Desktop/Cantoria.app"
if [ -d "$APP" ] && [ -f "$DEST/karaoke.icns" ] && ! cmp -s "$DEST/karaoke.icns" "$APP/Contents/Resources/applet.icns"; then
  rm -f "$APP/Contents/Resources/Assets.car"
  /usr/libexec/PlistBuddy -c "Delete :CFBundleIconName" "$APP/Contents/Info.plist" 2>/dev/null || true
  cp "$DEST/karaoke.icns" "$APP/Contents/Resources/applet.icns" 2>/dev/null || true
  xattr -cr "$APP" 2>/dev/null || true
  codesign --force --deep -s - "$APP" 2>/dev/null || true
  touch "$APP" 2>/dev/null || true
  echo "The Cantoria icon on the Desktop was refreshed."
fi
# THE ICON'S LAUNCHER follows the shipped one too (2026-09-29). Only the script inside the
# existing Cantoria.app is replaced - same bundle, same place - so a Dock icon pointing at it
# keeps working. Skipped once this Mac already has launcher v2.
if [ -d "$APP" ] && ! osadecompile "$APP/Contents/Resources/Scripts/main.scpt" 2>/dev/null | grep -q "cantoria launcher v2"; then
  PORT="$(/usr/bin/python3 -c 'import json,sys;print(json.load(open(sys.argv[1])).get("port","8899"))' "$DEST/karaoke_standalone.json" 2>/dev/null || echo 8899)"
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
  if osacompile -o "$TMP/main.scpt" "$TMP/launch.applescript" 2>/dev/null; then
    cp "$TMP/main.scpt" "$APP/Contents/Resources/Scripts/main.scpt"
    xattr -cr "$APP" 2>/dev/null || true
    codesign --force --deep -s - "$APP" 2>/dev/null || true
    touch "$APP" 2>/dev/null || true
    echo "The Cantoria icon now opens Chrome directly."
  fi
fi
# VERSION IS WRITTEN LAST (2026-09-29): it is this Mac's claim that the update finished. An
# update that died halfway once wrote it first, so the Mac reported the new version while
# running the old program, hid its own Update button, and every retry failed the same way.
cp "$SRC/VERSION" "$DEST/VERSION"
NOW="$(cat "$DEST/VERSION" 2>/dev/null || true)"
if [ -n "$HAD" ] && [ "$HAD" = "$NOW" ]; then
  echo "Already up to date (karaoke $NOW)."
else
  echo "Updated to Cantoria $NOW${HAD:+ (was $HAD)} — in $DEST"
fi
}
main "$@"