"""Cantoria — the Next-singer intro with the announcement MIXED IN (2026-09-26 spec).

One file per (singer, song): the singer's still photo + the on-screen name and song + the
announcer's voice + the crowd video's applause, all starting together when Next singer is pressed.

    cantoria_mc_intro.py <job.json>
    job = {"lang": "en"|"it"|"es", "template": "Ladies and gentlemen... please welcome... [NAME]! Singing... [SONG]!",
           "name": "Maria Rossi", "song": "Azzurro", "photo": "<singer photo.jpg>",
           "crowd": "<crowd video - its applause is the sound bed>", "out": "<out.mp4>", "voice": "<reference.wav>"}

Voice: Chatterbox cloned from the reference (default: assets/announcer/ref_klankbeeld_dry.wav,
klankbeeld, freesound.org, CC BY 4.0). exaggeration 0.55, cfg_weight 0.4, temperature 0.8.
Chatterbox misreads stretched spellings ("Miiiiike" -> "Meeee"), so the name is spoken PLAIN and
the main vowel of the FIRST name is lengthened ~3x afterwards. The song title is never stretched.
Everything runs on this Mac.
"""
import json, os, re, subprocess, sys, tempfile
from pathlib import Path
import numpy as np
import soundfile as sf

HERE = Path(__file__).resolve().parent
ASSETS = Path(os.environ.get("CANTORIA_ASSETS") or HERE.parent / "assets" / "announcer")
EXAG, CFG, TEMP = 0.55, 0.4, 0.8
SR = 24000
PAUSE_DOTS, PAUSE_BANG = 0.38, 0.25        # "..." and "!" between spoken chunks
# "A little faster": the whole announcement 12% quicker, pitch unchanged.
SPEED = float(os.environ.get("CANTORIA_VOICE_SPEED", "1.12"))
VOICE_AT = 0.8                             # seconds into the intro when the voice starts
FONT = "/System/Library/Fonts/Supplemental/Arial Bold.ttf"
FF = os.environ.get("FFMPEG") or "ffmpeg"

_models = {}


def model(lang):
    import torch
    dev = "mps" if torch.backends.mps.is_available() else "cpu"
    if "patched" not in _models:          # multilingual weights were saved on CUDA
        _load = torch.load
        torch.load = lambda *a, **k: _load(*a, **{**k, "map_location": "cpu"})
        _models["patched"] = True
    key = "en" if lang == "en" else "mtl"
    if key not in _models:
        if key == "en":
            from chatterbox.tts import ChatterboxTTS
            _models[key] = ChatterboxTTS.from_pretrained(device=dev)
        else:
            from chatterbox.mtl_tts import ChatterboxMultilingualTTS
            _models[key] = ChatterboxMultilingualTTS.from_pretrained(device=torch.device(dev))
    return _models[key]


def speak(text, lang, voice):
    m = model(lang)
    kw = dict(audio_prompt_path=str(voice), exaggeration=EXAG, cfg_weight=CFG, temperature=TEMP)
    wav = m.generate(text, **kw) if lang == "en" else m.generate(text, language_id=lang, **kw)
    x = wav.squeeze(0).cpu().numpy().astype(np.float32)
    return trim(x)


def trim(x, thr=0.02):
    """Cut leading/trailing near-silence so our own pauses set the rhythm."""
    a = np.abs(x)
    idx = np.where(a > thr * max(a.max(), 1e-6))[0]
    if len(idx) == 0:
        return x
    s, e = max(0, idx[0] - int(0.02 * SR)), min(len(x), idx[-1] + int(0.06 * SR))
    return x[s:e]


def silence(sec):
    return np.zeros(int(sec * SR), np.float32)


def atempo(x, factor):
    """Pitch-preserving time stretch through ffmpeg (factor < 1 = slower). Chains atempo so any
    factor down to 0.25 is legal."""
    chain, f = [], factor
    while f < 0.5:
        chain.append("atempo=0.5"); f /= 0.5
    chain.append(f"atempo={f:.4f}")
    with tempfile.TemporaryDirectory() as d:
        i, o = f"{d}/i.wav", f"{d}/o.wav"
        sf.write(i, x, SR)
        subprocess.run([FF, "-v", "error", "-y", "-i", i, "-af", ",".join(chain), o], check=True)
        y, _ = sf.read(o, dtype="float32")
    return y


def stretch_main_vowel(x, factor=3.0):
    """Lengthen the loudest VOICED stretch (the main vowel) of a spoken first name ~3x.
    Voiced = strong energy and a low zero-crossing rate (consonants hiss, vowels hum)."""
    hop = int(0.01 * SR)
    n = len(x) // hop
    if n < 8:
        return x
    fr = x[: n * hop].reshape(n, hop)
    rms = np.sqrt((fr ** 2).mean(1))
    zcr = (np.abs(np.diff(np.sign(fr), axis=1)) > 0).mean(1)
    voiced = (rms > rms.max() * 0.30) & (zcr < 0.15)
    best, cur = (0, 0), None
    for i, v in enumerate(voiced):
        if v and cur is None:
            cur = i
        if (not v or i == n - 1) and cur is not None:
            end = i if not v else i + 1
            if end - cur > best[1] - best[0]:
                best = (cur, end)
            cur = None
    s, e = best
    if e - s < 4:
        return x
    # the steady middle of the vowel, not its onset or its glide out
    m = (e - s) // 6
    s, e = (s + m) * hop, (e - m) * hop
    core = atempo(x[s:e], 1.0 / factor)
    n = int(0.012 * SR)                                   # short crossfades so the joins do not click
    return xfade(xfade(x[:s], core, n), x[e:], n)


def xfade(a, b, n):
    if len(a) < n or len(b) < n:
        return np.concatenate([a, b])
    w = np.linspace(0, 1, n, dtype=np.float32)
    return np.concatenate([a[:-n], a[-n:] * (1 - w) + b[:n] * w, b[n:]])


DIGITS = {"it": "zero uno due tre quattro cinque sei sette otto nove".split(),
          "es": "cero uno dos tres cuatro cinco seis siete ocho nueve".split(),
          "en": "zero one two three four five six seven eight nine".split()}


def spoken_artist(artist, lang):
    """An artist whose name is only digits is read digit by digit: 883 -> "otto otto tre".
    Written out here rather than trusting the model to guess. Titles are never touched
    ("24.000 Baci" is a number, not a name)."""
    if re.fullmatch(r"\d{2,5}", artist):
        return " ".join(DIGITS.get(lang, DIGITS["en"])[int(c)] for c in artist)
    return artist


_whisper = {}


def hear(x, lang):
    """What a listener would hear: Whisper (runs on this Mac), with each word's start/end."""
    import whisper
    if "m" not in _whisper:
        _whisper["m"] = whisper.load_model("small")
    audio = x if SR == 16000 else __import__("librosa").resample(x, orig_sr=SR, target_sr=16000)
    r = _whisper["m"].transcribe(audio.astype(np.float32), language=lang, word_timestamps=True, fp16=False)
    return [(w["word"].strip(), w["start"], w["end"]) for seg in r["segments"] for w in seg.get("words", [])]


def _norm(w):
    import unicodedata
    w = unicodedata.normalize("NFKD", w.lower())
    return "".join(c for c in w if c.isalpha())


def name_match(word, targets):
    """Did the listener hear the first name? "Claude"/"Clawd"/"Clod" yes, "Cloud" no."""
    from difflib import SequenceMatcher
    w = _norm(word)
    return bool(w) and any(SequenceMatcher(None, w, _norm(t)).ratio() >= 0.75 for t in targets)


def build_voice(job, workdir):
    lang, voice = job["lang"], Path(job.get("voice") or ASSETS / "ref_klankbeeld_dry.wav")
    name, song = job["name"].strip(), job["song"].strip()
    artist = spoken_artist((job.get("artist") or "").strip(), lang)
    template = job["template"]
    if not artist:                     # no artist: drop "di/by/de [ARTIST]" and end on the song
        template = re.sub(r"[\s.]*\b(di|de|by)\s*\[ARTIST\]", "", template)
    # ONE SENTENCE, ONE TAKE: a break used to fall between the name and the rest of the line.
    # The line used to be
    # spoken in pieces and glued; now the voice says it whole, naturally, and only the first name's
    # vowel is lengthened afterwards, found by listening (Whisper word timings).
    # name_say = how the VOICE should say the name ("Clawd" for Claude) - the screen is unchanged.
    say = (job.get("name_say") or name).strip()
    first = name.split()[0] if name.split() else ""
    first_say = say.split()[0] if say.split() else ""
    text = template.replace("[NAME]", say).replace("[SONG]", song).replace("[ARTIST]", artist)
    text = re.sub(r"\s+", " ", text).strip()
    best = None
    has_name = "[NAME]" in template            # a line without a name needs no listening check
    for attempt in range(3 if has_name else 1):
        x = speak(text, lang, voice)
        words = hear(x, lang)
        heard = " ".join(w for w, _, _ in words)
        hit = next(((s0, e0) for w, s0, e0 in words if name_match(w, (first, first_say))), None)
        print(f"heard (take {attempt + 1}): {heard}", flush=True)
        if best is None or (hit and not best[1]):
            best = (x, hit)
        if hit:
            break
    x, hit = best
    if hit:
        s0, e0 = int(max(0, hit[0] - 0.03) * SR), int(min(len(x) / SR, hit[1] + 0.03) * SR)
        n = int(0.012 * SR)
        x = xfade(xfade(x[:s0], stretch_main_vowel(x[s0:e0]), n), x[e0:], n)
    elif has_name:
        print(f"WARNING: the name '{first}' was not heard clearly in any take", flush=True)
    dry = x
    raw = f"{workdir}/voice_dry.wav"
    sf.write(raw, dry, SR)

    # Stadium FX: high-pass 80 Hz, light reverb (~1.1 s tail, wet -15 dB), 3:1 compressor,
    # -14 LUFS, peak <= -1 dBFS. The reverb is a synthetic decaying-noise impulse (no IR files).
    ir_len = int(1.1 * SR)
    t = np.arange(ir_len) / SR
    rng = np.random.default_rng(7)
    ir = (rng.standard_normal(ir_len) * np.exp(-t * 6.3 / 1.1)).astype(np.float32)
    ir /= np.abs(ir).sum()
    irf = f"{workdir}/ir.wav"
    sf.write(irf, ir, SR)
    out = f"{workdir}/voice.wav"
    fx = (f"[0:a]atempo={SPEED:.3f},highpass=f=80,asplit[d][w];"
          "[w][1:a]afir=dry=10:wet=10[r];[r]volume=-15dB[rv];"
          "[d][rv]amix=inputs=2:normalize=0,acompressor=ratio=3:threshold=0.1:attack=5:release=120,"
          "loudnorm=I=-14:TP=-1:LRA=7,alimiter=limit=0.89:level=disabled[o]")
    subprocess.run([FF, "-v", "error", "-y", "-i", raw, "-i", irf, "-filter_complex", fx,
                    "-map", "[o]", "-ar", "48000", "-ac", "2", out], check=True)
    return out, len(dry) / SR / SPEED


def text_card(name, song, path, W=1920, H=1080):
    """The name and song as a transparent 1920x1080 overlay (this ffmpeg has no drawtext)."""
    from PIL import Image, ImageDraw, ImageFont
    im = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    d = ImageDraw.Draw(im)
    band = Image.new("RGBA", (W, 250), (0, 0, 0, 150))
    im.alpha_composite(band, (0, H - 290))
    f1, f2 = ImageFont.truetype(FONT, 84), ImageFont.truetype(FONT, 62)
    for txt, font, y, col in [(name, f1, H - 272, (255, 214, 90, 255)), (song, f2, H - 150, (255, 255, 255, 255))]:
        w = d.textlength(txt, font=font)
        x = (W - w) / 2
        d.text((x + 3, y + 3), txt, font=font, fill=(0, 0, 0, 200))
        d.text((x, y), txt, font=font, fill=col)
    im.save(path)


TAIL = 6.0      # seconds of swelling applause after the song title - the walk to the microphone


def render(job):
    """THE PHOTO INTRO FORMAT: the singer's own still PHOTO, the name and song laid over it,
    the APPLAUSE taken from the crowd video, and the announcer - all in one file, so all four
    start together on Next singer."""
    with tempfile.TemporaryDirectory() as wd:
        voice, vlen = build_voice(job, wd)
        card = f"{wd}/card.png"
        # "<name> will sing" / the song
        text_card(f'{job["name"]} {job["will"]}' if job.get("will") else job["name"], job["song"], card)
        end_voice = VOICE_AT + vlen
        dur = round(end_voice + TAIL, 2)
        # Picture: a blurred, darkened fill of the photo behind the photo itself, which grows
        # very slowly (5% over the whole intro) so a still does not look frozen.
        vf = (f"[0:v]split[a][b];"
              f"[a]scale=1920:1080:force_original_aspect_ratio=increase,crop=1920:1080,boxblur=30:3,eq=brightness=-0.22[bg];"
              f"[b]scale=w=-2:h='trunc(740*(1+0.05*t/{dur})/2)*2':eval=frame[fg];"
              f"[bg][fg]overlay=x=(W-w)/2:y=40+(740-h)/2:eval=frame[pic];"
              f"[pic][1:v]overlay=0:0,fps=30,format=yuv420p[v];")
        # Sound: the crowd video's applause, DUCKED under the voice (sidechain), then SWELLING
        # after the song title and fading out at the end.
        vdelay = int(VOICE_AT * 1000)
        af = (f"[2:a]adelay={vdelay}|{vdelay},apad,asplit[vo][key];"
              f"[3:a]aresample=48000,atrim=0:{dur},"
              f"volume='if(lt(t,{end_voice:.2f}),0.45,min(1.3,0.45+0.85*(t-{end_voice:.2f})/1.2))':eval=frame,"
              f"afade=t=in:d=0.4,afade=t=out:st={dur - 1.4:.2f}:d=1.4[apl];"
              f"[apl][key]sidechaincompress=threshold=0.03:ratio=8:attack=20:release=400[duck];"
              f"[duck][vo]amix=inputs=2:normalize=0:duration=first,atrim=0:{dur},"
              f"alimiter=limit=0.89:level=disabled[a]")
        subprocess.run([FF, "-v", "error", "-y",
                        "-loop", "1", "-framerate", "30", "-i", job["photo"],
                        "-loop", "1", "-framerate", "30", "-i", card,
                        "-i", voice,
                        "-stream_loop", "-1", "-i", job["crowd"],
                        "-filter_complex", vf + af, "-map", "[v]", "-map", "[a]", "-t", str(dur),
                        "-c:v", "libx264", "-crf", "18", "-preset", "fast", "-c:a", "aac", "-b:a", "192k",
                        "-movflags", "+faststart", job["out"]], check=True)
    print("ok", job["out"], f"voice {vlen:.1f}s, intro {dur:.1f}s", flush=True)


def voice_only(job):
    """The LIVE announcer (karaoke_worker.php 'mcvoice'): the finished announcement as one wav -
    build-up wording, stretched first name, stadium FX - played at Next singer over whatever
    intro is on screen. Same voice path as render(), without the picture."""
    with tempfile.TemporaryDirectory() as wd:
        wav, vlen = build_voice(job, wd)
        subprocess.run([FF, "-v", "error", "-y", "-i", wav, "-c:a", "pcm_s16le", job["out"]], check=True)
    print("ok", job["out"], f"voice {vlen:.1f}s", flush=True)


if __name__ == "__main__":
    for j in json.loads(Path(sys.argv[1]).read_text()):
        try:
            (voice_only if j.get("mode") == "voice" else render)(j)
        except Exception as e:
            print("FAILED", j.get("out"), repr(e)[:300], flush=True)
