#!/usr/bin/env python3
"""Mustafo multfilm personaji va animatsion Reels generatori (1080x1920, 30fps).

Ishlatish:  python3 cartoon.py chiqish.mp4 [epizod_nomi]
"""
import math, sys, subprocess, random, wave, struct, os, tempfile
import cairo

W, H, FPS = 1080, 1920, 30
FONT = "Poppins"

# --- Ranglar (asl rasmdagi kiyimlarga mos) ---
SKIN = (0.97, 0.82, 0.71)
SKIN_D = (0.90, 0.70, 0.58)
HAIR = (0.29, 0.18, 0.12)
HAIR_L = (0.42, 0.28, 0.19)
SHIRT = (0.62, 0.69, 0.56)
SHIRT_D = (0.49, 0.57, 0.45)
PANTS = (0.89, 0.83, 0.72)
PANTS_D = (0.78, 0.71, 0.60)
SHOE = (0.98, 0.98, 0.98)
OUT = (0.22, 0.16, 0.13)


def ease(t):
    t = max(0.0, min(1.0, t))
    return t * t * (3 - 2 * t)


def back_out(t):
    t = max(0.0, min(1.0, t))
    c = 1.9
    return 1 + (c + 1) * (t - 1) ** 3 + c * (t - 1) ** 2


def rrect(c, x, y, w, h, r):
    c.new_sub_path()
    c.arc(x + w - r, y + r, r, -math.pi / 2, 0)
    c.arc(x + w - r, y + h - r, r, 0, math.pi / 2)
    c.arc(x + r, y + h - r, r, math.pi / 2, math.pi)
    c.arc(x + r, y + r, r, math.pi, 3 * math.pi / 2)
    c.close_path()


def fill_stroke(c, col, lw=6, out=OUT):
    c.set_source_rgb(*col)
    c.fill_preserve()
    c.set_source_rgb(*out)
    c.set_line_width(lw)
    c.stroke()


def ellipse(c, x, y, rx, ry):
    c.save()
    c.translate(x, y)
    c.scale(rx, ry)
    c.arc(0, 0, 1, 0, 2 * math.pi)
    c.restore()


# ---------------- PERSONAJ ----------------
def draw_arm(c, sx, sy, a1, a2, side):
    """sx,sy yelka; a1 yelka burchagi, a2 tirsak burchagi (radian, 0 = pastga)."""
    L1, L2 = 135, 125
    ex = sx + math.sin(a1) * L1 * side
    ey = sy + math.cos(a1) * L1
    hx = ex + math.sin(a1 + a2) * L2 * side
    hy = ey + math.cos(a1 + a2) * L2
    c.set_line_cap(cairo.LINE_CAP_ROUND)
    # kontur
    c.set_source_rgb(*OUT)
    c.set_line_width(76)
    c.move_to(sx, sy); c.line_to(ex, ey); c.stroke()
    c.set_line_width(62)
    c.move_to(ex, ey); c.line_to(hx, hy); c.stroke()
    # yeng
    c.set_source_rgb(*SHIRT)
    c.set_line_width(64)
    c.move_to(sx, sy); c.line_to(ex, ey); c.stroke()
    # bilak (teri) — yeng shimarilgan
    c.set_source_rgb(*SKIN)
    c.set_line_width(50)
    c.move_to(ex, ey); c.line_to(hx, hy); c.stroke()
    # shimarilgan yeng manjeti
    c.set_source_rgb(*SHIRT_D)
    c.set_line_width(58)
    mx = ex + math.sin(a1 + a2) * 18 * side
    my = ey + math.cos(a1 + a2) * 18
    c.move_to(ex, ey); c.line_to(mx, my); c.stroke()
    # kaft
    c.arc(hx, hy, 34, 0, 2 * math.pi)
    fill_stroke(c, SKIN, 5)


def draw_leg(c, x, top, bottom, lift):
    c.set_line_cap(cairo.LINE_CAP_ROUND)
    c.set_source_rgb(*OUT)
    c.set_line_width(92)
    c.move_to(x, top); c.line_to(x, bottom - lift); c.stroke()
    c.set_source_rgb(*PANTS)
    c.set_line_width(80)
    c.move_to(x, top); c.line_to(x, bottom - lift); c.stroke()
    # krossovka
    ellipse(c, x + (8 if x > 0 else -8), bottom - lift + 18, 66, 36)
    fill_stroke(c, SHOE, 6)
    c.set_source_rgb(0.80, 0.80, 0.82)
    c.set_line_width(5)
    c.move_to(x - 55, bottom - lift + 34); c.line_to(x + 60, bottom - lift + 34); c.stroke()


def draw_head(c, p):
    hy = -900
    # quloqlar
    for s in (-1, 1):
        c.arc(s * 182, hy + 10, 40, 0, 2 * math.pi)
        fill_stroke(c, SKIN, 6)
        c.arc(s * 182, hy + 10, 18, 0, 2 * math.pi)
        c.set_source_rgba(*SKIN_D, 0.8); c.fill()
    # yuz
    ellipse(c, 0, hy, 190, 182)
    fill_stroke(c, SKIN, 7)
    # soch (yon tomonga taralgan)
    c.move_to(-192, hy - 10)
    c.curve_to(-212, hy - 165, -120, hy - 245, 20, hy - 240)
    c.curve_to(165, hy - 236, 224, hy - 140, 194, hy - 15)
    c.curve_to(178, hy - 62, 152, hy - 96, 112, hy - 102)
    c.curve_to(40, hy - 112, -40, hy - 140, -85, hy - 168)
    c.curve_to(-112, hy - 122, -160, hy - 80, -192, hy - 10)
    c.close_path()
    fill_stroke(c, HAIR, 7)
    # yaltiroq chiziqlar + yon farq
    c.set_source_rgb(*HAIR_L)
    c.set_line_width(9)
    c.set_line_cap(cairo.LINE_CAP_ROUND)
    c.move_to(-60, hy - 200); c.curve_to(20, hy - 212, 110, hy - 190, 165, hy - 120); c.stroke()
    c.move_to(-40, hy - 165); c.curve_to(30, hy - 172, 100, hy - 150, 140, hy - 112); c.stroke()
    c.set_source_rgb(*OUT)
    c.set_line_width(6)
    c.move_to(-85, hy - 168); c.curve_to(-82, hy - 195, -76, hy - 215, -66, hy - 234); c.stroke()

    # qoshlar
    br = p.get("brow", 0)
    c.set_source_rgb(*HAIR)
    c.set_line_width(13)
    for s in (-1, 1):
        c.move_to(s * 105, hy - 68 - br * 14)
        c.curve_to(s * 85, hy - 85 - br * 18, s * 50, hy - 86 - br * 18, s * 32, hy - 72 - br * 10)
        c.stroke()

    # ko'zlar
    blink = p.get("blink", 0)
    lx = p.get("look", 0) * 9
    for s in (-1, 1):
        ex, ey = s * 72, hy - 15
        ry = max(3, 38 * (1 - blink))
        ellipse(c, ex, ey, 30, ry)
        c.set_source_rgb(0.98, 0.98, 0.98); c.fill_preserve()
        c.set_source_rgb(*OUT); c.set_line_width(5); c.stroke()
        if blink < 0.8:
            ellipse(c, ex + lx, ey + 4, 21, min(ry - 4, 28))
            c.set_source_rgb(0.33, 0.20, 0.12); c.fill()
            ellipse(c, ex + lx, ey + 6, 11, min(ry - 10, 15) if ry > 14 else 3)
            c.set_source_rgb(0.08, 0.05, 0.04); c.fill()
            c.arc(ex + lx + 8, ey - 8, 7, 0, 2 * math.pi)
            c.set_source_rgb(1, 1, 1); c.fill()
    # yonoqlar
    for s in (-1, 1):
        ellipse(c, s * 118, hy + 60, 36, 22)
        c.set_source_rgba(0.98, 0.55, 0.55, 0.38); c.fill()
    # burun
    c.set_source_rgb(*SKIN_D)
    c.set_line_width(7)
    c.arc(0, hy + 45, 16, 0.3, math.pi - 0.3); c.stroke()
    # og'iz
    m = p.get("mouth", 0)
    my = hy + 100
    if m < 0.08:
        c.set_source_rgb(*OUT)
        c.set_line_width(8)
        c.arc(0, my - 30, 48, 0.55, math.pi - 0.55); c.stroke()
    else:
        h = 14 + 46 * m
        c.move_to(-50, my - 6)
        c.curve_to(-40, my + h, 40, my + h, 50, my - 6)
        c.curve_to(25, my + 4, -25, my + 4, -50, my - 6)
        c.close_path()
        fill_stroke(c, (0.55, 0.12, 0.15), 6)
        c.save()
        c.move_to(-50, my - 6)
        c.curve_to(-40, my + h, 40, my + h, 50, my - 6)
        c.close_path()
        c.clip()
        ellipse(c, 0, my + h * 0.75, 30, 16)
        c.set_source_rgb(0.95, 0.45, 0.5); c.fill()
        c.restore()


def draw_character(c, x, ground, scale, p):
    """p: bob, lean, squash, arm_l/arm_r (a1,a2), step, blink, mouth, look, head_tilt"""
    c.save()
    # soya
    sh = 1 - min(0.5, p.get("jump", 0) / 400)
    ellipse(c, x, ground + 30 * scale, 190 * scale * sh, 34 * scale * sh)
    c.set_source_rgba(0, 0, 0, 0.18); c.fill()

    c.translate(x, ground - p.get("jump", 0) * scale)
    c.scale(scale, scale)
    sq = p.get("squash", 0)
    c.scale(1 + sq * 0.12, 1 - sq * 0.12)
    c.rotate(p.get("lean", 0))
    bob = p.get("bob", 0)
    step = p.get("step", 0)

    # oyoqlar
    draw_leg(c, -48, -340 + bob, -10, max(0, step) * 50)
    draw_leg(c, 48, -340 + bob, -10, max(0, -step) * 50)

    c.translate(0, bob)
    # orqadagi qo'l (chap)
    al = p.get("arm_l", (0.12, 0.15))
    ar = p.get("arm_r", (0.12, 0.15))
    draw_arm(c, -140, -600, al[0], al[1], -1)

    # tana — ko'ylak
    rrect(c, -165, -640, 330, 320, 70)
    fill_stroke(c, SHIRT, 7)
    # ko'ylak etagi / shim belbog'i
    rrect(c, -150, -345, 300, 46, 20)
    fill_stroke(c, PANTS, 6)
    # tugmalar
    for i in range(4):
        c.arc(0, -565 + i * 58, 9, 0, 2 * math.pi)
        c.set_source_rgb(0.95, 0.95, 0.92); c.fill_preserve()
        c.set_source_rgb(*SHIRT_D); c.set_line_width(3); c.stroke()
    c.set_source_rgb(*SHIRT_D); c.set_line_width(4)
    c.move_to(-14, -630); c.line_to(-14, -350); c.stroke()
    # cho'ntak
    rrect(c, 50, -560, 80, 80, 10)
    c.set_source_rgb(*SHIRT_D); c.set_line_width(4); c.stroke()
    # bo'yin + yoqa
    rrect(c, -40, -670, 80, 50, 18)
    c.set_source_rgb(*SKIN_D); c.fill()
    for s in (-1, 1):
        c.move_to(0, -622); c.line_to(s * 85, -652); c.line_to(s * 68, -575); c.close_path()
        fill_stroke(c, SHIRT_D, 5)

    # bosh
    c.save()
    c.translate(0, -650)
    c.rotate(p.get("head_tilt", 0))
    c.scale(1.22, 1.22)
    c.translate(0, 706)
    draw_head(c, p)
    c.restore()

    # oldingi qo'l (o'ng)
    draw_arm(c, 140, -600, ar[0], ar[1], 1)
    c.restore()


# ---------------- FON VA EFFEKTLAR ----------------
random.seed(7)
BOKEH = [(random.uniform(0, W), random.uniform(0, H * 0.75), random.uniform(30, 110),
          random.uniform(0.05, 0.18), random.uniform(0, 6.28)) for _ in range(26)]


def draw_bg(c, t, theme):
    top, bot = theme
    g = cairo.LinearGradient(0, 0, 0, H)
    g.add_color_stop_rgb(0, *top)
    g.add_color_stop_rgb(1, *bot)
    c.set_source(g); c.paint()
    for (x, y, r, a, ph) in BOKEH:
        yy = y + math.sin(t * 0.8 + ph) * 25
        rg = cairo.RadialGradient(x, yy, 0, x, yy, r)
        rg.add_color_stop_rgba(0, 1, 1, 0.92, a)
        rg.add_color_stop_rgba(1, 1, 1, 0.92, 0)
        c.set_source(rg)
        c.arc(x, yy, r, 0, 2 * math.pi); c.fill()
    # pol
    ellipse(c, W / 2, 1720, 760, 120)
    c.set_source_rgba(1, 1, 1, 0.22); c.fill()


def heart(c, x, y, s, alpha):
    c.save(); c.translate(x, y); c.scale(s, s)
    c.move_to(0, 30)
    c.curve_to(-60, -10, -35, -55, 0, -25)
    c.curve_to(35, -55, 60, -10, 0, 30)
    c.close_path()
    c.set_source_rgba(0.96, 0.28, 0.42, alpha); c.fill()
    c.restore()


def star(c, x, y, r, alpha, rot):
    c.save(); c.translate(x, y); c.rotate(rot)
    for i in range(10):
        rr = r if i % 2 == 0 else r * 0.45
        a = i * math.pi / 5
        (c.move_to if i == 0 else c.line_to)(math.sin(a) * rr, -math.cos(a) * rr)
    c.close_path()
    c.set_source_rgba(1, 0.82, 0.25, alpha); c.fill()
    c.restore()


def wrap(c, text, maxw):
    words, lines, cur = text.split(), [], ""
    for w in words:
        test = (cur + " " + w).strip()
        if c.text_extents(test).x_advance <= maxw:
            cur = test
        else:
            lines.append(cur); cur = w
    lines.append(cur)
    return lines


def bubble(c, text, cx, y, prog, size=62):
    if prog <= 0:
        return
    s = back_out(min(1, prog / 0.35))
    c.save()
    c.translate(cx, y)
    c.scale(s, s)
    c.select_font_face(FONT, cairo.FONT_SLANT_NORMAL, cairo.FONT_WEIGHT_BOLD)
    c.set_font_size(size)
    lines = wrap(c, text, 800)
    lh = size * 1.28
    bw = max(c.text_extents(l).x_advance for l in lines) + 90
    bh = lh * len(lines) + 60
    rrect(c, -bw / 2 + 8, 10, bw, bh, 44)
    c.set_source_rgba(0, 0, 0, 0.15); c.fill()
    rrect(c, -bw / 2, 0, bw, bh, 44)
    c.set_source_rgb(1, 1, 1); c.fill_preserve()
    c.set_source_rgb(*OUT); c.set_line_width(6); c.stroke()
    c.move_to(-30, bh - 3); c.line_to(30, bh - 3); c.line_to(-10, bh + 60); c.close_path()
    c.set_source_rgb(1, 1, 1); c.fill()
    c.move_to(-30, bh); c.line_to(-10, bh + 60); c.line_to(30, bh)
    c.set_source_rgb(*OUT); c.set_line_width(6); c.stroke()
    # matnni typewriter effekti bilan
    total = sum(len(l) for l in lines)
    shown = int(total * min(1, prog / 0.9))
    c.set_source_rgb(0.12, 0.12, 0.16)
    for i, l in enumerate(lines):
        part = l[:max(0, shown)]
        shown -= len(l)
        ext = c.text_extents(l)
        c.move_to(-ext.x_advance / 2, 30 + size + i * lh - 8)
        c.show_text(part)
    c.restore()


def caption(c, text, y, alpha=1, size=54):
    c.select_font_face(FONT, cairo.FONT_SLANT_NORMAL, cairo.FONT_WEIGHT_BOLD)
    c.set_font_size(size)
    ext = c.text_extents(text)
    x = (W - ext.x_advance) / 2
    c.move_to(x, y); c.text_path(text)
    c.set_source_rgba(0.12, 0.10, 0.10, alpha); c.set_line_width(10); c.stroke_preserve()
    c.set_source_rgba(1, 1, 1, alpha); c.fill()


# ---------------- AUDIO ----------------
def make_audio(path, dur, bpm, seed=1):
    sr = 44100
    n = int(sr * dur)
    beat = 60.0 / bpm
    rnd = random.Random(seed)
    scales = [[261.6, 329.6, 392.0, 523.3, 392.0, 329.6, 293.7, 349.2],
              [293.7, 349.2, 440.0, 587.3, 440.0, 349.2, 329.6, 392.0]]
    notes = scales[seed % 2]
    bass = [130.8, 130.8, 174.6, 196.0]
    out = bytearray()
    for i in range(n):
        t = i / sr
        pb = t % beat
        k = math.sin(2 * math.pi * (110 * math.exp(-pb * 20)) * pb) * math.exp(-pb * 10) * 0.55
        bar = int(t / beat) % 4
        cl = 0.0
        if bar in (1, 3):
            cl = (rnd.random() * 2 - 1) * math.exp(-pb * 35) * 0.22
        ph = (t + beat / 2) % beat
        hat = (rnd.random() * 2 - 1) * math.exp(-ph * 70) * 0.08
        step = int(t / (beat / 2)) % 8
        ps = t % (beat / 2)
        mel = (math.sin(2 * math.pi * notes[step] * t) + 0.3 * math.sin(4 * math.pi * notes[step] * t)) \
            * math.exp(-ps * 7) * 0.13
        bs = math.sin(2 * math.pi * bass[int(t / (beat * 2)) % 4] * t) * 0.12
        v = k + cl + hat + mel + bs
        fade = min(1, t / 0.2, (dur - t) / 0.8)
        out += struct.pack("<h", int(max(-1, min(1, v * fade)) * 30000))
    with wave.open(path, "w") as w:
        w.setnchannels(1); w.setsampwidth(2); w.setframerate(sr)
        w.writeframes(bytes(out))


# ---------------- EPIZODLAR ----------------
def talk(t, start, end):
    if start <= t < end:
        return abs(math.sin((t - start) * 13)) * 0.9
    return 0.0


def blink_at(t):
    for b in (1.7, 4.3, 6.9, 9.6, 12.2, 14.8):
        if 0 <= t - b < 0.16:
            return math.sin((t - b) / 0.16 * math.pi)
    return 0.0


def episode_salom(t, bpm):
    """Tanishuv + raqs: 13 soniya."""
    beat = 60 / bpm
    p = {"blink": blink_at(t), "look": 0}
    texts = []
    x = W / 2
    # 0-1.1: pastdan sakrab chiqadi
    if t < 1.1:
        k = t / 1.1
        p["jump"] = math.sin(k * math.pi) * 380 - (1 - ease(k * 1.6)) * 900
        p["squash"] = -0.4 * math.sin(k * math.pi)
        p["arm_l"] = (2.6 * math.sin(k * math.pi), 0.2)
        p["arm_r"] = (2.6 * math.sin(k * math.pi), 0.2)
    elif t < 1.35:
        k = (t - 1.1) / 0.25
        p["squash"] = 0.5 * math.sin(k * math.pi)
    # 1.35-4.2: qo'l silkitib salomlashadi
    elif t < 4.2:
        w = t - 1.35
        p["arm_r"] = (2.3, -0.35 + 0.45 * math.sin(w * 11))
        p["mouth"] = talk(t, 1.5, 3.4)
        p["head_tilt"] = 0.08 * math.sin(w * 3)
        p["brow"] = 1
        texts.append(("Assalomu alaykum! Men Mustafo!", 1.45))
    # 4.2-9: ritmga raqs
    elif t < 9.0:
        w = t - 4.2
        bb = (w % beat) / beat
        beatn = int(w / beat)
        p["bob"] = 22 * math.cos(bb * 2 * math.pi)
        p["lean"] = 0.12 * math.sin(w * math.pi / beat)
        side = 1 if beatn % 2 == 0 else -1
        up = 2.9 - 0.3 * math.cos(bb * math.pi * 2)
        p["arm_r"] = (up if side > 0 else 0.5, 0.3)
        p["arm_l"] = (up if side < 0 else 0.5, 0.3)
        p["step"] = side * math.sin(bb * math.pi)
        p["head_tilt"] = -0.15 * math.sin(w * math.pi / beat)
        p["mouth"] = 0.5 if bb < 0.3 else 0.15
        p["look"] = side
        x = W / 2 + 60 * math.sin(w * math.pi / (beat * 2))
        texts.append(("Kayfiyat 100%! Qani, birga raqsga!", 4.3))
    # 9-13: obuna bo'lishni so'raydi
    else:
        w = t - 9.0
        p["arm_r"] = (2.0, -0.2 + 0.1 * math.sin(w * 8))
        p["arm_l"] = (0.25, 0.2)
        p["mouth"] = talk(t, 9.1, 11.0)
        p["head_tilt"] = 0.1
        p["brow"] = 0.6
        p["bob"] = 6 * math.sin(w * 6)
        texts.append(("Obuna bo'ling, har kuni yangi sarguzasht!", 9.1))
    return x, p, texts


EPISODES = {
    "salom": dict(fn=episode_salom, dur=13, bpm=112, theme=((1.0, 0.93, 0.84), (0.98, 0.78, 0.66)),
                  title="MUSTAFO SHOU"),
}


def render(out, name="salom"):
    ep = EPISODES[name]
    dur, bpm = ep["dur"], ep["bpm"]
    tmp = tempfile.mkdtemp()
    wav = os.path.join(tmp, "a.wav")
    make_audio(wav, dur, bpm, seed=len(name))
    proc = subprocess.Popen(
        ["ffmpeg", "-y", "-loglevel", "error", "-f", "rawvideo", "-pix_fmt", "bgra", "-s", f"{W}x{H}",
         "-r", str(FPS), "-i", "-", "-i", wav, "-c:v", "libx264", "-pix_fmt", "yuv420p", "-preset", "medium",
         "-crf", "20", "-c:a", "aac", "-b:a", "128k", "-shortest", "-movflags", "+faststart", out],
        stdin=subprocess.PIPE)
    surf = cairo.ImageSurface(cairo.FORMAT_ARGB32, W, H)
    beat = 60 / bpm
    for f in range(int(dur * FPS)):
        t = f / FPS
        c = cairo.Context(surf)
        draw_bg(c, t, ep["theme"])
        x, p, texts = ep["fn"](t, bpm)
        # ritmda yulduzchalar
        for i in range(6):
            ang = i * 1.05 + t * 0.6
            bb = ((t + i * 0.13) % beat) / beat
            star(c, W / 2 + math.cos(ang) * 430, 980 + math.sin(ang) * 520, 22 + 10 * (1 - bb), 0.55, t + i)
        draw_character(c, x, 1700, 1.0, p)
        if t > dur - 3.8:
            for i in range(7):
                k = ((t - (dur - 3.8)) * 0.5 + i / 7) % 1
                heart(c, 150 + i * 130 + 30 * math.sin(t * 3 + i), 1650 - k * 1100, 1.1, 1 - k)
        for (txt, start) in texts:
            bubble(c, txt, W / 2, 150, t - start)
        caption(c, ep["title"], 1860, 0.9, 46)
        surf.flush()
        proc.stdin.write(bytes(surf.get_data()))
    proc.stdin.close()
    proc.wait()
    print("OK", out)


if __name__ == "__main__":
    render(sys.argv[1], sys.argv[2] if len(sys.argv) > 2 else "salom")
