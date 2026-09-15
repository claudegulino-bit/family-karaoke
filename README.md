# Family Karaoke

A karaoke system that runs entirely on one Mac. No accounts, no subscription, no cloud —
your songs stay in your own folder and nothing leaves the house.

Guests use their own phones: they scan a square on the screen, search your songs, and put
themselves in the queue. They can even paste a YouTube link and the Mac fetches the song
while the party carries on.

## What it does

- **Plays your songs** with the key set per song, and lets you change key *and* speed
  while someone is singing.
- **A queue** with fair turns — everybody sings once before anybody sings twice.
- **A list per person**, so each singer's songs are one click away.
- **Guests on their phones** — request a song, or bring a new one in from YouTube.
- **Brings songs in from YouTube** and warns you when one looks like something you already own.

## Installing it

On the Mac that will play the music, open **Terminal** (hold ⌘ and press Space, type
`Terminal`, press Return) and paste this one line:

```
curl -fsSL https://raw.githubusercontent.com/claudegulino-bit/family-karaoke/main/install.sh | bash -s -- ~/Karaoke
```

Then:

1. Install the player — in the same Terminal window: `brew install mpv yt-dlp`
   (if it answers *command not found: brew*, first paste
   `/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"`).
2. Open the `Karaoke` folder in your home folder and double-click **start.command**.
3. In the browser it opens, press **📖 Guide** and follow section 1 — it will ask you to
   point at your songs folder, and check that everything is installed.

## The applause

Cantoria ships with a cheering crowd, so the walk to the microphone is never silent and never a
frozen frame. You do not have to install anything.

`applause.mp4` is original work, made for Cantoria and owned outright by its author — free to use,
copy and pass on with the app. It opens on a dark stage while the announcement is on screen, then the
crowd arrives as the voice finishes. `applause.m4a` sits behind it as a sound-only fallback:
*"Sound Effects - Applause after a concert"* by **Amada44** from Wikimedia Commons, **CC0 1.0
Universal Public Domain Dedication** — credited because knowing where a file came from is worth more
than the licence asks.
<https://commons.wikimedia.org/wiki/File:Sound_Effects_-_Applause_after_a_concert.ogg>

Want your own crowd instead? Put a video named `applause.mp4` in a folder called `@ Cantoria/sounds`
next to your songs folder — a video is preferred wherever one is found, and if your songs are shared
through Drive every Mac in the house gets it at once. Use something you own or that is genuinely free
to pass on: most "free stock" sites forbid redistributing their files on a standalone basis, which is
exactly what including one here would be.

## Keeping it up to date

Press **Update** in the Guide. Or run `~/Karaoke/update.sh` in Terminal.

Your settings, your songs, your lists and everyone's saved keys are never touched.

## What it needs

A Mac, the songs in a folder, and `mpv` and `yt-dlp` (the two the install line above puts
on). The Mac has to be awake during the party — it is both the player and the little
server the guests' phones talk to, over your own Wi-Fi.

## Licence

Do what you like with it.