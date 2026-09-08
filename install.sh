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

say "Karaoke — setting up this Mac"

# ---------------------------------------------------------------- 1 · the tools
say "1 of 5 · The player"
BREW="$(command -v brew || true)"
[ -z "$BREW" ] && [ -x /opt/homebrew/bin/brew ] && BREW=/opt/homebrew/bin/brew
[ -z "$BREW" ] && [ -x /usr/local/bin/brew ] && BREW=/usr/local/bin/brew
if [ -z "$BREW" ]; then
  ok "Homebrew is not on this Mac yet — installing it."
  ok "It will ask for your Mac password. Nothing appears as you type it; that is normal."
  /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)" </dev/tty || {
    echo "   Homebrew would not install. Nothing else was changed."; exit 1; }
  BREW="$( [ -x /opt/homebrew/bin/brew ] && echo /opt/homebrew/bin/brew || echo /usr/local/bin/brew )"
fi
eval "$("$BREW" shellenv)" 2>/dev/null || true
for t in mpv yt-dlp; do
  if command -v "$t" >/dev/null 2>&1; then ok "$t — already here"
  else ok "installing $t…"; "$BREW" install "$t" >/dev/null 2>&1 && ok "$t — installed" || ok "$t — FAILED, the karaoke will not play until this works"
  fi
done

# ------------------------------------------------------------- 2 · the program
say "2 of 5 · The karaoke itself"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
SHA="$(curl -fsSL "https://api.github.com/repos/$REPO/commits/main?_=$(date +%s)" 2>/dev/null \
        | sed -n 's/.*"sha"[[:space:]]*:[[:space:]]*"\([0-9a-f]\{40\}\)".*/\1/p' | head -1)"
curl -fsSL "https://codeload.github.com/$REPO/tar.gz/${SHA:-refs/heads/main}" -o "$TMP/k.tgz" || {
  echo "   Could not download the karaoke. Is this Mac online?"; exit 1; }
tar -xzf "$TMP/k.tgz" -C "$TMP"
SRC="$(find "$TMP" -type d -name app -maxdepth 2 | head -1)"
[ -d "$SRC" ] || { echo "   That download did not look right — nothing was changed."; exit 1; }
mkdir -p "$DEST/logs"
for f in "$SRC"/*; do
  b="$(basename "$f")"
  [ "$b" = "karaoke_standalone.example.json" ] && continue
  cp "$f" "$DEST/$b"
done
chmod +x "$DEST/start.command" "$DEST/update.sh" 2>/dev/null || true
ok "version $(cat "$DEST/VERSION" 2>/dev/null) — in $DEST"

# ---------------------------------------------------------------- 3 · the songs
say "3 of 5 · Your songs"
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
  N="$(ls -1 "$SONGS" 2>/dev/null | grep -icE '\.(mp4|mp3|m4a|mov|avi|m4v|wav|mid|kar)$' || echo 0)"
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

# -------------------------------------------------------------- 4 · the icon
say "4 of 5 · The Desktop icon"
# ⚠ ORDER MATTERS. osacompile SEALS the bundle, so the icon goes in BEFORE signing and the
# signature is applied LAST — otherwise macOS refuses to open it as "damaged or incomplete".
PORT="$(/usr/bin/python3 -c 'import json,sys;print(json.load(open(sys.argv[1])).get("port","8899"))' "$DEST/karaoke_standalone.json")"
PHPBIN="$(command -v php || echo /opt/homebrew/bin/php)"
cat > "$TMP/launch.applescript" <<AS
on run
	set theURL to "http://localhost:$PORT/karaoke.php"
	set code to do shell script "curl -s -o /dev/null -m 5 -w '%{http_code}' " & quoted form of theURL & " 2>/dev/null || echo 000"
	if code is not "200" then
		do shell script "cd $DEST && nohup $PHPBIN -S 0.0.0.0:$PORT -t . > $DEST/logs/server.log 2>&1 &"
		delay 3
		set code to do shell script "curl -s -o /dev/null -m 5 -w '%{http_code}' " & quoted form of theURL & " 2>/dev/null || echo 000"
	end if
	if code is "200" then
		open location theURL
	else
		display dialog "The karaoke could not start on this Mac." buttons {"OK"} default button 1 with icon caution with title "Karaoke"
	end if
end run
AS
rm -rf "$TMP/Karaoke.app"
if osacompile -o "$TMP/Karaoke.app" "$TMP/launch.applescript" 2>/dev/null; then
  [ -f "$DEST/karaoke.icns" ] && cp "$DEST/karaoke.icns" "$TMP/Karaoke.app/Contents/Resources/applet.icns"
  xattr -cr "$TMP/Karaoke.app" 2>/dev/null || true
  codesign --force --deep -s - "$TMP/Karaoke.app" 2>/dev/null || true
  rm -rf "$HOME/Desktop/Karaoke.app"
  mv "$TMP/Karaoke.app" "$HOME/Desktop/Karaoke.app"
  ok "Karaoke.app is on the Desktop"
else
  ok "could not build the Desktop icon — start.command in $DEST does the same job"
fi

# ----------------------------------------------------- 5 · running, and staying up
say "5 of 5 · Starting it"
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
