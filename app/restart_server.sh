#!/bin/bash
# Stops whatever old Cantoria server is holding this Mac's Cantoria port, then starts a fresh one.
# Run by the Desktop Cantoria icon only when the page does not answer (an old copy left running
# in the background, or stuck), so the new one never fails with "address already in use".
# It touches only a PHP server on Cantoria's own port - never a song that is playing (that is a
# separate program), never anything else.
cd "$(dirname "$0")" || exit 1
PORT="$(/usr/bin/python3 -c 'import json;print(json.load(open("karaoke_standalone.json")).get("port","8899"))' 2>/dev/null || echo 8899)"
# a Mac that starts Cantoria by itself: ask that service to restart (it stops the old copy first)
if [ -f "$HOME/Library/LaunchAgents/com.familykaraoke.server.plist" ] && launchctl kickstart -k "gui/$(id -u)/com.familykaraoke.server" 2>/dev/null; then
  exit 0
fi
for p in $(/usr/sbin/lsof -nP -tiTCP:"$PORT" -sTCP:LISTEN 2>/dev/null); do
  case "$(ps -o comm= -p "$p" 2>/dev/null)" in *php*) kill "$p" 2>/dev/null ;; esac
done
sleep 1
for p in $(/usr/sbin/lsof -nP -tiTCP:"$PORT" -sTCP:LISTEN 2>/dev/null); do
  case "$(ps -o comm= -p "$p" 2>/dev/null)" in *php*) kill -9 "$p" 2>/dev/null ;; esac
done
sleep 1
export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"
mkdir -p logs
nohup php -S "0.0.0.0:$PORT" -t . > logs/server.log 2>&1 < /dev/null &
exit 0
