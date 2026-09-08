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
