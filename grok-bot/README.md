# Mustafo Grok bot

Rasm → Grok video (o'zbekcha gapiradi) → Buffer → Instagram Reels. To'liq avtomatik.

## O'rnatish (telefondan, ~15 daqiqa)

1. **xAI kalit:** https://console.x.ai → API Keys → Create. Balansga pul qo'shing (10 soniyalik video ≈ $0.80).
2. **Buffer kalit:** https://publish.buffer.com/settings/api → kalit yarating.
3. **cPanel → File Manager → public_html** ga `mustafo-bot.zip` ni yuklang → **Extract**.
4. `mustafo-bot` ichida `config.example.php` ni **Copy** qilib `config.php` deb nomlang → **Edit**:
   kalitlarni, `BASE_URL` va `SECRET` ni yozing.
5. `photos/` papkasiga o'g'lingiz rasmlarini yuklang (5–20 ta).
6. **Sinov** (brauzerda):
   - `https://itcode.uz/mustafo-bot/run.php?key=SECRET&mode=new` — video buyurtma
   - 2–5 daqiqadan keyin: `...&mode=check` — tayyor bo'lsa Instagram'ga joylaydi
   - `...&mode=status` — holat
7. **cPanel → Cron Jobs** — 2 ta vazifa:
   - Har 5 daqiqada: `curl -s "https://itcode.uz/mustafo-bot/run.php?key=SECRET&mode=check" >/dev/null`
   - Sesh/Chor/Pay soat 18:00: `0 18 * * 2,3,4` → `curl -s "https://itcode.uz/mustafo-bot/run.php?key=SECRET&mode=new" >/dev/null`

## G'oyalar
`ideas.php` — har bir video uchun Grok ko'rsatmasi va Instagram matni. Navbat bilan ishlatiladi.
