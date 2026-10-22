#!/usr/bin/env python3
"""Rasmdan vertikal Reels video yasaydi (1080x1920, 30fps).
Ishlatish: python3 make_reel.py rasm.jpg chiqish.mp4 "matn1" "matn2" "matn3"
"""
import sys, os, math, subprocess, tempfile, wave, struct, random
from PIL import Image, ImageFilter, ImageDraw, ImageFont, ImageEnhance

W, H, FPS, DUR = 1080, 1920, 30, 9
FONT = "/usr/share/fonts/truetype/google-fonts/Poppins-Bold.ttf"
BPM = 105

def make_audio(path, dur):
    sr = 44100
    n = int(sr * dur)
    beat = 60.0 / BPM
    random.seed(1)
    notes = [261.6, 329.6, 392.0, 329.6]  # C E G E arpejio
    data = []
    for i in range(n):
        t = i / sr
        pb = t % beat
        k = math.sin(2 * math.pi * (120 * math.exp(-pb * 18)) * pb) * math.exp(-pb * 9) * 0.6
        ph = (t + beat / 2) % beat
        hat = (random.random() * 2 - 1) * math.exp(-ph * 60) * 0.12
        step = int(t / (beat / 2)) % 4
        ps = t % (beat / 2)
        mel = math.sin(2 * math.pi * notes[step] * t) * math.exp(-ps * 6) * 0.18
        v = k + hat + mel
        fade = min(1, t / 0.3, (dur - t) / 0.6)
        data.append(int(max(-1, min(1, v * fade)) * 30000))
    with wave.open(path, "w") as w:
        w.setnchannels(1); w.setsampwidth(2); w.setframerate(sr)
        w.writeframes(b"".join(struct.pack("<h", s) for s in data))

def wrap(draw, text, font, maxw):
    words, lines, cur = text.split(), [], ""
    for wd in words:
        test = (cur + " " + wd).strip()
        if draw.textlength(test, font=font) <= maxw:
            cur = test
        else:
            lines.append(cur); cur = wd
    lines.append(cur)
    return lines

def bubble(img, text, y, progress):
    """Gap pufagi: progress 0..1 paydo bo'lish animatsiyasi."""
    if progress <= 0:
        return
    s = 0.6 + 0.4 * min(1, progress) + 0.06 * math.sin(min(1, progress) * math.pi)
    font = ImageFont.truetype(FONT, int(64 * s))
    layer = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)
    lines = wrap(d, text, font, 820 * s)
    lh = int(80 * s)
    bw = max(d.textlength(l, font=font) for l in lines) + 90 * s
    bh = lh * len(lines) + 70 * s
    x0 = (W - bw) / 2
    d.rounded_rectangle([x0 + 8, y + 10, x0 + bw + 8, y + bh + 10], radius=int(45 * s), fill=(0, 0, 0, 90))
    d.rounded_rectangle([x0, y, x0 + bw, y + bh], radius=int(45 * s), fill=(255, 255, 255, 245))
    tx = W / 2
    d.polygon([(tx - 30, y + bh - 2), (tx + 30, y + bh - 2), (tx + 5, y + bh + 50)], fill=(255, 255, 255, 245))
    for i, l in enumerate(lines):
        lw = d.textlength(l, font=font)
        d.text(((W - lw) / 2, y + 35 * s + i * lh), l, font=font, fill=(25, 25, 35))
    img.alpha_composite(layer)

def main():
    src, out, *texts = sys.argv[1:]
    if not texts:
        texts = ["Salom!"]
    photo = Image.open(src).convert("RGB")
    bg = photo.resize((W, int(W * photo.height / photo.width)) if photo.width / photo.height > W / H
                      else (int(H * photo.width / photo.height) + 1, H))
    bg = bg.resize((max(W, bg.width), max(H, bg.height)))
    bg = bg.crop(((bg.width - W) // 2, (bg.height - H) // 2, (bg.width - W) // 2 + W, (bg.height - H) // 2 + H))
    bg = ImageEnhance.Brightness(bg.filter(ImageFilter.GaussianBlur(40))).enhance(0.55).convert("RGBA")
    fh = 1280
    fg = photo.resize((int(fh * photo.width / photo.height), fh), Image.LANCZOS).convert("RGBA")
    mask = Image.new("L", fg.size, 0)
    ImageDraw.Draw(mask).rounded_rectangle([0, 0, fg.width, fg.height], radius=50, fill=255)
    fg.putalpha(mask)

    tmp = tempfile.mkdtemp()
    seg = DUR / len(texts)
    beat = 60.0 / BPM
    for f in range(FPS * DUR):
        t = f / FPS
        frame = bg.copy()
        zoom = 1 + 0.08 * (t / DUR)
        pb = (t % beat) / beat
        zoom *= 1 + 0.018 * math.exp(-pb * 6)  # ritmga mos "puls"
        sw, sh = int(fg.width * zoom), int(fg.height * zoom)
        fgz = fg.resize((sw, sh), Image.BILINEAR)
        sway = int(10 * math.sin(t * 1.6))
        frame.alpha_composite(fgz, ((W - sw) // 2 + sway, 470 + (fh - sh) // 2))
        idx = min(int(t / seg), len(texts) - 1)
        bubble(frame, texts[idx], 110, (t - idx * seg) / 0.35)
        # pastki imzo
        d = ImageDraw.Draw(frame)
        ft = ImageFont.truetype(FONT, 40)
        tag = "@mustafo.xayrulloyev"
        d.text(((W - d.textlength(tag, font=ft)) / 2, H - 110), tag, font=ft, fill=(255, 255, 255, 230))
        frame.convert("RGB").save(f"{tmp}/{f:04d}.jpg", quality=90)

    make_audio(f"{tmp}/a.wav", DUR)
    subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-framerate", str(FPS), "-i", f"{tmp}/%04d.jpg",
                    "-i", f"{tmp}/a.wav", "-c:v", "libx264", "-pix_fmt", "yuv420p", "-preset", "medium",
                    "-crf", "21", "-c:a", "aac", "-b:a", "128k", "-shortest", "-movflags", "+faststart", out],
                   check=True)
    print("OK", out)

if __name__ == "__main__":
    main()
