#!/bin/bash
# Double-click this to start the karaoke.
# It serves the page from THIS Mac on the house Wi-Fi, so the guests' phones can
# reach it too — that is why it listens on 0.0.0.0 and not just on this machine.
cd "$(dirname "$0")"
PORT=$(python3 -c "import json;print(json.load(open('karaoke_standalone.json')).get('port','8899'))")
IP=$(ipconfig getifaddr en0 || ipconfig getifaddr en1 || echo 127.0.0.1)
echo "Karaoke is running."
echo "  On this Mac:      http://localhost:$PORT/karaoke.php"
echo "  Guests' phones:   the QR code on the page (http://$IP:$PORT/...)"
echo "Leave this window open. Close it to stop."
# Homebrew on the PATH, so anything that shells out (yt-dlp → ffmpeg) can find it.
export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"
exec php -S "0.0.0.0:$PORT" -t .
