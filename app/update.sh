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
  cp "$f" "$DEST/$b"
done
# The settings file is yours. It is only ever created, never overwritten.
[ -f "$DEST/karaoke_standalone.json" ] || cp "$SRC/karaoke_standalone.example.json" "$DEST/karaoke_standalone.json"
chmod +x "$DEST/start.command" "$DEST/update.sh" 2>/dev/null || true
NOW="$(cat "$DEST/VERSION" 2>/dev/null || true)"
if [ -n "$HAD" ] && [ "$HAD" = "$NOW" ]; then
  echo "Already up to date (karaoke $NOW)."
else
  echo "Updated to Cantoria $NOW${HAD:+ (was $HAD)} — in $DEST"
fi
}
main "$@"