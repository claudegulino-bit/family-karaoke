#!/bin/bash
# Fetches the current karaoke and puts it in place. Safe to run any time:
# your settings, your songs and your lists are never touched.
set -euo pipefail
REPO_TGZ="https://codeload.github.com/claudegulino-bit/family-karaoke/tar.gz/refs/heads/main"
DEST="${1:-$(cd "$(dirname "$0")" && pwd)}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "Fetching the newest karaoke…"
curl -fsSL "$REPO_TGZ" -o "$TMP/k.tgz"
tar -xzf "$TMP/k.tgz" -C "$TMP"
SRC="$(find "$TMP" -type d -name app -maxdepth 2 | head -1)"
[ -d "$SRC" ] || { echo "That download did not look right — nothing was changed."; exit 1; }

mkdir -p "$DEST"
for f in "$SRC"/*; do
  b="$(basename "$f")"
  [ "$b" = "karaoke_standalone.example.json" ] && continue
  cp "$f" "$DEST/$b"
done
# The settings file is yours. It is only ever created, never overwritten.
[ -f "$DEST/karaoke_standalone.json" ] || cp "$SRC/karaoke_standalone.example.json" "$DEST/karaoke_standalone.json"
chmod +x "$DEST/start.command" "$DEST/update.sh" 2>/dev/null || true
echo "Done — karaoke $(cat "$DEST/VERSION" 2>/dev/null || echo '') is in $DEST"