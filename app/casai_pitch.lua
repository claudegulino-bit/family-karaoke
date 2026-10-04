-- casAI karaoke player — on-screen pitch control (the owner, 2026-09-06:
-- "change the pitch right from the screen").
-- While a song plays fullscreen: UP arrow = key up one semitone, DOWN = down one.
-- The current pitch flashes on screen. The watcher can also set an absolute pitch
-- with `script-message casai-set-pitch N` (used on song load and by the page's
-- live-pitch bar, so the on-screen counter never drifts from reality).
-- The rubberband filter MUST be launched with the @rb label (see karaoke_watch.py).

local pitch = 0

local function apply(show)
    local scale = 2 ^ (pitch / 12)
    mp.command_native({"af-command", "rb", "set-pitch", string.format("%.6f", scale)})
    -- Published so the watcher/tests can read the real runtime pitch back
    -- (get_property af only shows launch-time params, not af-command changes).
    mp.set_property_native("user-data/casai-pitch", pitch)
    if show ~= false then
        mp.osd_message(string.format("PITCH %+d   (UP/DOWN arrows change it · Q closes)", pitch), 2.5)
    end
end

local function set_pitch(v)
    pitch = math.max(-12, math.min(12, math.floor(tonumber(v) or 0)))
    apply()
end

mp.register_script_message("casai-set-pitch", set_pitch)

local opt = mp.get_opt("casai-pitch")
if opt then
    pitch = math.max(-12, math.min(12, math.floor(tonumber(opt) or 0)))
end

mp.register_event("file-loaded", function() apply(false) end)

mp.add_key_binding("UP", "casai-pitch-up", function()
    if pitch < 12 then pitch = pitch + 1 end
    apply()
end, { repeatable = true })

mp.add_key_binding("DOWN", "casai-pitch-down", function()
    if pitch > -12 then pitch = pitch - 1 end
    apply()
end, { repeatable = true })

-- ---------------------------------------------------------------------------------------------
-- AUTOMATIC SONG LEVEL (owner request, 2026-10-04). YouTube-sourced karaoke files are often MASTERED HOT:
-- 63 % of the library peaks above 0 dBFS and 15 % is louder than -10 LUFS. Played at full volume
-- they overload the sound on its way to the mixer and come out "flat / bouncing off the walls"
-- (the owner's test on a family Mac: lowering the player volume made it clearly better; the other
-- karaoke player had the same level problem). YouTube itself turns loud songs DOWN. So does this: each song is lowered just
-- enough to be no louder than TARGET_LUFS and to leave HEADROOM_DB under full scale. It NEVER
-- turns a song up. Runs in the on_preloaded hook = before the first note, every song, whichever
-- Mac is playing. The level comes from song_loudness.json next to this script; a song that is not
-- in it (a new download, or any Mac without the table) is measured once with ffmpeg (about a second) and remembered.
-- Only songs are touched: a looping crowd video, a still photo, a short clip get no change.
-- The user's own volume keys (9/0) still work on top of this.
-- ---------------------------------------------------------------------------------------------
local utils = require "mp.utils"
local TARGET_LUFS = -14.0
local HEADROOM_DB = 1.0
local MAX_CUT_DB = 20.0

local script_dir = (debug.getinfo(1, "S").source:match("^@(.*)/[^/]*$")) or "."
local home = os.getenv("HOME") or ""
local cache_path = home .. "/.casai_song_gain.json"
local table_data, cache_data = nil, nil

local function read_json(p)
    local f = io.open(p, "r"); if not f then return nil end
    local s = f:read("*a"); f:close()
    return utils.parse_json(s)
end

local function norm(s)  -- file names are compared as given; macOS may hand us the decomposed form, so try both
    return s
end

local function lookup(name)
    if not table_data then table_data = read_json(script_dir .. "/song_loudness.json") or {} end
    if not cache_data then cache_data = read_json(cache_path) or {} end
    return table_data[name] or cache_data[name]
end

local function find_ffmpeg()
    for _, p in ipairs({ "/opt/homebrew/bin/ffmpeg", "/usr/local/bin/ffmpeg", "/usr/bin/ffmpeg" }) do
        local f = io.open(p, "r"); if f then f:close(); return p end
    end
    return "ffmpeg"
end

local function measure(path)
    local r = mp.command_native({ name = "subprocess", capture_stderr = true, capture_stdout = true, playback_only = false,
        args = { find_ffmpeg(), "-hide_banner", "-nostats", "-i", path, "-vn", "-af", "ebur128=peak=true", "-f", "null", "-" } })
    if not r or not r.stderr then return nil end
    local lufs, peak
    for v in r.stderr:gmatch("I:%s+(-?[%d%.]+) LUFS") do lufs = tonumber(v) end
    for v in r.stderr:gmatch("Peak:%s+(-?[%d%.]+) dBFS") do peak = tonumber(v) end
    if lufs and peak then return { lufs, peak } end
    return nil
end

local function remember(name, vals)
    cache_data = cache_data or {}
    cache_data[name] = vals
    local f = io.open(cache_path, "w")
    if f then f:write(utils.format_json(cache_data)); f:close() end
end

local function set_gain(db)
    mp.set_property_number("volume-gain", db)
    mp.set_property_native("user-data/casai-song-gain", db)
end

mp.add_hook("on_preloaded", 50, function()
    local path = mp.get_property("path") or ""
    local loop = mp.get_property("loop-file") or "no"
    local dur = mp.get_property_number("duration", 0)
    local has_audio = (mp.get_property("audio-params") ~= nil) or (mp.get_property_number("track-list/count", 0) > 0)
    -- only a real song: not looping, long enough, and an .mp4 file
    if loop ~= "no" or dur < 60 or not path:lower():match("%.mp4$") then set_gain(0); return end
    local name = path:match("([^/]+)$") or path
    local vals = lookup(name)
    if not vals then
        vals = measure(path)
        if vals then remember(name, vals) end
    end
    if not vals then set_gain(0); return end
    local cut = math.min(0, TARGET_LUFS - vals[1], -HEADROOM_DB - vals[2])
    cut = math.max(-MAX_CUT_DB, cut)
    if cut > -0.3 then cut = 0 end
    set_gain(cut)
    mp.msg.info(string.format("song level %.1f LUFS, peak %.1f dBFS -> %.1f dB", vals[1], vals[2], cut))
end)
