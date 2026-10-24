<?php
/**
 * Mustafo Grok bot: rasm -> Grok video -> Buffer -> Instagram.
 *
 * Rejimlar:
 *   new    — yangi video buyurtma qiladi (haftasiga 3 marta cron)
 *   check  — tayyor bo'lgan videoni yuklab, Instagram'ga joylaydi (har 5 daqiqada cron)
 *   status — holatni ko'rsatadi
 *
 * Ishga tushirish:
 *   brauzer/cron: https://SAYT/mustafo-bot/run.php?key=SECRET&mode=check
 *   CLI:          php run.php check
 */

declare(strict_types=1);
date_default_timezone_set('Asia/Tashkent');
set_time_limit(600);
ignore_user_abort(true);
header('Content-Type: text/plain; charset=utf-8');

$DIR = __DIR__;
if (!file_exists("$DIR/config.php")) {
    exit("config.php topilmadi. config.example.php ni nusxalab, config.php deb nomlang.\n");
}
$CFG   = require "$DIR/config.php";
$IDEAS = require "$DIR/ideas.php";
$SCENES = require "$DIR/scenes.php";

// --- ruxsat tekshiruvi ---
$isCli = PHP_SAPI === 'cli';
if (!$isCli && (($_GET['key'] ?? '') !== $CFG['SECRET'])) {
    http_response_code(403);
    exit("Ruxsat yo'q\n");
}
$mode = $isCli ? ($argv[1] ?? 'check') : ($_GET['mode'] ?? 'check');

foreach (['photos', 'prepared', 'videos'] as $d) {
    if (!is_dir("$DIR/$d")) mkdir("$DIR/$d", 0755, true);
}

$STATE_FILE = "$DIR/state.json";
$state = file_exists($STATE_FILE)
    ? (json_decode((string)file_get_contents($STATE_FILE), true) ?: [])
    : [];
$state += ['idea' => 0, 'photo' => 0, 'scene' => 0, 'recent' => [], 'pending' => null, 'history' => []];

function save_state(array $s): void {
    global $STATE_FILE;
    $s['history'] = array_slice($s['history'], -50);
    file_put_contents($STATE_FILE, json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function logmsg(string $m): void {
    global $DIR;
    $line = '[' . date('Y-m-d H:i:s') . "] $m\n";
    echo $line;
    file_put_contents("$DIR/bot.log", $line, FILE_APPEND);
}

function http_json(string $method, string $url, array $headers, ?array $body = null, int $timeout = 180, int $tries = 3): array {
    for ($i = 1; ; $i++) {
        try {
            return http_json_once($method, $url, $headers, $body, $timeout);
        } catch (RuntimeException $e) {
            $retry = str_starts_with($e->getMessage(), 'Tarmoq xatosi') || preg_match('/^HTTP (429|5\d\d|400: .*download)/', $e->getMessage());
            if (!$retry || $i >= $tries) throw $e;
            sleep(5 * $i);
        }
    }
}

function http_json_once(string $method, string $url, array $headers, ?array $body, int $timeout): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 20,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException("Tarmoq xatosi: $err");
    $data = json_decode((string)$raw, true);
    if ($code >= 400) throw new RuntimeException("HTTP $code: " . substr((string)$raw, 0, 500));
    return is_array($data) ? $data : [];
}

function notify(string $text): void {
    global $CFG;
    if (empty($CFG['TG_BOT_TOKEN']) || empty($CFG['TG_CHAT_ID'])) return;
    try {
        http_json('POST', "https://api.telegram.org/bot{$CFG['TG_BOT_TOKEN']}/sendMessage", [],
            ['chat_id' => $CFG['TG_CHAT_ID'], 'text' => $text]);
    } catch (Throwable $e) { /* jim */ }
}

/**
 * Rasmni KESMASDAN 9:16 (1080x1920) kanvasga joylaydi.
 * Bola to'liq ko'rinadi, atrofi neytral rang bilan to'ldiriladi — keyin Grok
 * u yerga yangi fon chizadi.
 */
function prepare_image(string $src, string $dst): void {
    $info = getimagesize($src);
    if (!$info) throw new RuntimeException("Rasm o'qilmadi: $src");
    $img = match ($info[2]) {
        IMAGETYPE_JPEG => imagecreatefromjpeg($src),
        IMAGETYPE_PNG  => imagecreatefrompng($src),
        IMAGETYPE_WEBP => imagecreatefromwebp($src),
        default        => throw new RuntimeException("Qo'llanmaydigan rasm turi: $src"),
    };
    [$w, $h] = [$info[0], $info[1]];
    $TW = 720; $TH = 1280;
    $scale = min($TW / $w, $TH / $h) * 0.92;      // to'liq sig'adi, chetida joy qoladi
    $nw = (int)round($w * $scale); $nh = (int)round($h * $scale);
    $out = imagecreatetruecolor($TW, $TH);
    imagefill($out, 0, 0, imagecolorallocate($out, 205, 200, 195));
    imagecopyresampled($out, $img, (int)(($TW - $nw) / 2), $TH - $nh - 40, 0, 0, $nw, $nh, $w, $h);
    imagejpeg($out, $dst, 85);
}

/**
 * Grok "kreativ rejissyor": har safar YANGI g'oya o'ylab topadi
 * (fon, harakat, kamera, o'zbekcha gap, Instagram matni). Oldingilarini takrorlamaydi.
 */
function invent_idea(array $recent): array {
    global $CFG;
    $avoid = $recent ? "Do NOT repeat or closely resemble these recent videos:\n- " . implode("\n- ", $recent) : '';
    $sys = "You are a creative director for a wholesome Instagram Reels page of a 6-year-old Uzbek boy named Mustafo. "
         . "Every time, invent ONE fresh, trendy, fun, family-friendly 10-second video concept with a modern, professional, cinematic look. "
         . "Vary everything: location/atmosphere (cities, nature, seasons, Uzbek culture, fantasy, sports, holidays, etc.), "
         . "activity (dance trend, funny reaction, mini story, lifehack, compliment to parents, motivation, joke...), "
         . "and camera work (slow dolly-in, orbit, tracking shot, low angle, close-up to wide, etc.). "
         . "AUDIENCE (real Instagram stats): 72% women, mostly mothers aged 25-34; 65% Uzbekistan, 21% Iran, also Russia, Kyrgyzstan, Tajikistan; "
         . "99% of viewers are new people from the Reels feed. So make moments young mothers find heartwarming and want to save and share; "
         . "the story must be understandable WITHOUT words (visual storytelling), the spoken line is a bonus. "
         . "WHAT WORKS on this page (learned from real results): the best video (28x more views) was the boy in a golden autumn park "
         . "throwing falling leaves with arms wide open — strong motion and particles from the very first frame, seasonal, warm and emotional. "
         . "The weakest video started with the boy just standing still and looking at the camera. So ALWAYS: "
         . "(1) the first second must already have strong, eye-catching motion — leaves/snow/petals/bubbles/confetti/water splashes flying, "
         . "running, jumping, spinning, a surprise reveal; never start with him standing still or posing; "
         . "(2) prefer themes tied to the current season, weather and upcoming holidays in Uzbekistan (use today's date), "
         . "and emotional, cozy, cinematic moments that parents want to share; "
         . "(3) golden-hour or magical lighting, rich colors, depth and atmosphere. "
         . "The boy says ONE short, natural, everyday Uzbek (Latin script) sentence, max 10 words, simple words a child uses, correct grammar. "
         . "Prefer NOT to use his own name in the spoken line. Never anything unsafe, romantic, scary, or inappropriate for children. "
         . "Return ONLY valid JSON with keys: "
         . "\"scene\" (English, detailed background/atmosphere/lighting description for an image), "
         . "\"outfit\" (English, a NEW stylish age-appropriate outfit for him that fits the scene and season — different every time: colors, style, accessories like cap, glasses, scarf; modest and child-appropriate), "
         . "\"action\" (English, what he does + camera movement, for a video), "
         . "\"line\" (the Uzbek sentence he says), "
         . "\"continuation\" (English, what happens next in a second part of the same video: a new action and a different camera angle, same place, same outfit), "
         . "\"line2\" (a second short Uzbek sentence he says in that second part, max 8 words), "
         . "\"music\" (English, background music style that matches the mood and place, e.g. 'gentle acoustic guitar, warm autumn mood' or 'upbeat modern kids pop beat'; instrumental only, no lyrics), "
         . "\"caption\" (Uzbek Instagram caption: line 1 = a short emotional hook; line 2 = a simple QUESTION to parents that invites comments (e.g. 'Sizning farzandingiz ham shunday qiladimi? 👇'); line 3 = a soft call to follow and share (e.g. 'Yoqsa, do'stlaringizga ulashing 💛'); then 4-6 relevant Uzbek/English hashtags, no location or school names), "
         . "\"shots\" (array of exactly 3 objects {\"prompt\": English, max 400 chars, \"duration\": 5} - a mini 3-shot story of the same concept, "
         . "same place and outfit, each shot a different camera angle; shot 1 opens with strong motion; ONLY shot 3 contains speech: "
         . "he says the Uzbek line in quotes, in a natural cute little boy voice, slowly and clearly; every shot mentions the same background music), "
         . "\"summary\" (5-8 English words describing the concept).";
    $res = http_json('POST', 'https://api.x.ai/v1/chat/completions',
        ["Authorization: Bearer {$CFG['XAI_API_KEY']}"],
        [
            'model'           => $CFG['TEXT_MODEL'] ?? 'grok-4.7',
            'temperature'     => 1.0,
            'response_format' => ['type' => 'json_object'],
            'messages'        => [
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user', 'content' => "Today is " . date('l, j F Y') . ". $avoid\nCreate the next video concept."],
            ],
        ]);
    $txt = $res['choices'][0]['message']['content'] ?? '';
    $txt = trim(preg_replace('/^```(?:json)?|```$/m', '', $txt));
    $j = json_decode($txt, true);
    foreach (['scene', 'outfit', 'action', 'line', 'music', 'caption', 'summary'] as $k) {
        if (empty($j[$k]) || !is_string($j[$k])) throw new RuntimeException("G'oya noto'g'ri formatda: " . substr($txt, 0, 300));
    }
    return $j;
}

/** Uzaytirish soniyalari (0 = o'chiq). Standart: 0 (o'chiq); 10 → jami 25 soniya. */
function extend_seconds(): int {
    global $CFG;
    return max(0, min(10, (int)($CFG['EXTEND_SECONDS'] ?? 0)));  // 2-10 s; 0 = o'chiq
}

/** Videoning 2-qismi uchun ko'rsatma. */
function ext_prompt(string $cont, string $line2, string $music): string {
    $cont = $cont !== '' ? $cont : 'He continues naturally with a new playful action; the camera smoothly changes angle.';
    $say  = $line2 !== '' ? " He then says in Uzbek, slowly and clearly, pronouncing every sound correctly: \"$line2\"." : '';
    return "Continue this exact video seamlessly: same boy, same face, same outfit, same place and lighting. $cont$say "
         . "Keep the same background music ($music) continuing without a break, quietly under his voice. "
         . "Professional modern cinematic look, smooth realistic motion.";
}

/** Instagram matnidagi heshteglarni tozalaydi: xatolarni tuzatadi, takrorlarni olib tashlaydi, #reels qo'shadi. */
function fix_caption(string $caption): string {
    $fix = ['rekels' => 'reels', 'reals' => 'reels', 'reel' => 'reels', 'rells' => 'reels', 'relss' => 'reels',
            'uzbekiston' => 'uzbekistan', 'ozbekiston' => 'uzbekistan', 'bolalr' => 'bolalar'];
    $seen = [];
    $text = preg_replace_callback('/#([\p{L}\p{N}_\']+)/u', function ($m) use ($fix, &$seen) {
        $t = mb_strtolower(str_replace("'", '', $m[1]));
        $t = $fix[$t] ?? $t;
        if (isset($seen[$t]) || mb_strlen($t) < 2) return '';
        $seen[$t] = true;
        return '#' . $t;
    }, $caption);
    if (!isset($seen['reels'])) $text = rtrim($text) . ' #reels';
    if (!isset($seen['bolalar'])) $text = rtrim($text) . ' #bolalar';
    return trim(preg_replace('/[ \t]{2,}/', ' ', $text));
}

/** Bugun qaysi dvigatel: 'grok' yoki 'kling'. ENGINE_DAYS => [hafta_kuni => dvigatel], 1=Dush..7=Yaksh */
function pick_engine(): string {
    global $CFG;
    if (empty($CFG['KIE_API_KEY'])) return 'grok';
    $map = $CFG['ENGINE_DAYS'] ?? [2 => 'grok', 4 => 'kling', 6 => 'grok'];
    $e = $map[(int)date('N')] ?? ($CFG['ENGINE_DEFAULT'] ?? 'grok');
    return in_array($e, ['grok', 'kling'], true) ? $e : 'grok';
}

/** Kling 3.0 (Kie.ai orqali): 3 kadrli, ovoz va musiqali video buyurtma qiladi. taskId qaytaradi. */
function kling_create(string $imgUrl, array $shots, string $fallbackPrompt): string {
    global $CFG;
    $multi = [];
    foreach (array_slice($shots, 0, 5) as $sh) {
        if (!is_array($sh) || empty($sh['prompt'])) continue;
        $multi[] = ['prompt' => mb_substr((string)$sh['prompt'], 0, 490), 'duration' => max(1, min(12, (int)($sh['duration'] ?? 5)))];
    }
    $input = [
        'image_urls' => [$imgUrl],
        'sound'      => true,
        'mode'       => $CFG['KLING_MODE'] ?? 'pro',
    ];
    if (count($multi) >= 2) {
        $input['multi_shots']  = true;
        $input['multi_prompt'] = $multi;
        $input['duration']     = (string)array_sum(array_column($multi, 'duration'));
        $input['prompt']       = '';
    } else {
        $input['multi_shots'] = false;
        $input['prompt']      = mb_substr($fallbackPrompt, 0, 2400);
        $input['duration']    = (string)($CFG['KLING_DURATION'] ?? 15);
    }
    $res = http_json('POST', 'https://api.kie.ai/api/v1/jobs/createTask',
        ["Authorization: Bearer {$CFG['KIE_API_KEY']}"],
        ['model' => 'kling-3.0/video', 'input' => $input]);
    $id = $res['data']['taskId'] ?? '';
    if ($id === '') throw new RuntimeException('Kling taskId bermadi: ' . substr(json_encode($res, JSON_UNESCAPED_UNICODE), 0, 400));
    return $id;
}

/** Kling vazifasi holatini Grok formatiga o'giradi. */
function kling_status(string $taskId): array {
    global $CFG;
    $res = http_json('GET', 'https://api.kie.ai/api/v1/jobs/recordInfo?taskId=' . urlencode($taskId),
        ["Authorization: Bearer {$CFG['KIE_API_KEY']}"]);
    $d = $res['data'] ?? [];
    $st = $d['state'] ?? 'unknown';
    if ($st === 'success') {
        $rj = is_string($d['resultJson'] ?? null) ? json_decode($d['resultJson'], true) : ($d['resultJson'] ?? []);
        $url = $rj['resultUrls'][0] ?? '';
        return ['status' => $url ? 'done' : 'failed', 'video' => ['url' => $url], 'raw' => $d];
    }
    if ($st === 'fail') return ['status' => 'failed', 'raw' => $d];
    return ['status' => 'processing', 'progress' => $st];
}

/** Video uchun yakuniy ko'rsatma (talaffuz va sifat qoidalari bilan). */
function video_prompt(string $action, string $line, string $music = 'soft, cheerful instrumental music matching the mood'): string {
    return "Professional modern cinematic vertical video, smooth realistic motion, shallow depth of field, high detail. "
         . "The video must open with strong, eye-catching motion from the very first frame (no static start). "
         . "Keep the boy's face, hair, outfit and identity exactly as in the image. $action "
         . "He says in Uzbek, in a natural cheerful 6-year-old boy voice, slowly and clearly, pronouncing every sound and syllable correctly, "
         . "without skipping letters: \"$line\". Lip movements match the speech. "
         . "If the name Mustafo is said, pronounce it in three syllables: Mus-ta-fo. "
         . "Audio: background music playing throughout the whole video — $music — instrumental, no lyrics, "
         . "mixed quietly under his voice so every word stays clear, plus subtle natural ambient sounds of the place.";
}

/** Hostingdagi faylni base64 data URI ga aylantiradi (Grok uni yuklab olishga hojat qolmaydi). */
function to_data_uri(string $url): string {
    global $CFG, $DIR;
    $rel = substr($url, strlen($CFG['BASE_URL']) + 1);
    $path = "$DIR/$rel";
    if (!str_starts_with($url, $CFG['BASE_URL'] . '/') || !is_file($path)) return $url;
    $mime = str_ends_with(strtolower($path), '.mp4') ? 'video/mp4' : 'image/jpeg';
    return "data:$mime;base64," . base64_encode((string)file_get_contents($path));
}

/** Grok bilan bolani yangi fonga/atmosferaga joylaydi (9:16). Natija faylini saqlaydi. */
function make_scene(string $srcUrl, string $scene, string $dst, string $outfit = ''): void {
    global $CFG;
    $prompt = "Place this exact same little boy into a new scene: $scene. "
        . "Keep his face, facial features, hairstyle, skin tone and identity exactly the same; "
        . ($outfit !== ''
            ? "Dress him in a completely new outfit (replace his current clothes): $outfit. "
            : "Dress him in a new stylish age-appropriate outfit that fits the scene (different from the photo). ")
        . "Vertical 9:16 full-frame composition, the boy in the center, visible from head to at least the knees, "
        . "modern professional cinematic photography, photorealistic, beautiful lighting, sharp focus, wholesome family-friendly mood. "
        . "The new background must fill the entire frame, no borders, no blank areas, no text.";
    $res = http_json('POST', 'https://api.x.ai/v1/images/edits',
        ["Authorization: Bearer {$CFG['XAI_API_KEY']}"],
        [
            'model'        => $CFG['IMAGE_MODEL'] ?? 'grok-imagine-image',
            'prompt'       => $prompt,
            'image'        => ['url' => to_data_uri($srcUrl), 'type' => 'image_url'],
            'aspect_ratio' => '9:16',
        ]);
    $item = $res['data'][0] ?? $res;
    if (!empty($item['b64_json'])) {
        $bin = base64_decode($item['b64_json']);
    } elseif (!empty($item['url'])) {
        $ch = curl_init($item['url']);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60]);
        $bin = curl_exec($ch);
        curl_close($ch);
    } else {
        throw new RuntimeException('Grok rasm qaytarmadi: ' . substr(json_encode($res, JSON_UNESCAPED_UNICODE), 0, 400));
    }
    if (!$bin || strlen($bin) < 5000) throw new RuntimeException('Yangi fonli rasm yuklab olinmadi.');
    // Har qanday formatni JPEG ga o'tkazamiz
    $im = @imagecreatefromstring($bin);
    if (!$im) throw new RuntimeException("Yangi fonli rasm o'qilmadi.");
    imagejpeg($im, $dst, 92);
}

function list_photos(): array {
    global $DIR;
    $files = glob("$DIR/photos/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}", GLOB_BRACE) ?: [];
    sort($files);
    return $files;
}

// ================= REJIMLAR =================

$forceEngine = null;
$fe = $isCli ? ($argv[2] ?? '') : ($_GET['engine'] ?? '');
if (in_array($fe, ['grok', 'kling'], true)) $forceEngine = $fe;

try {
    // Kechiktirilgan buyurtma: ?mode=later&min=60 -> 60 daqiqadan keyin cron (check) o'zi 'new' qiladi
    if ($mode === 'later') {
        $min = max(1, min(1440, (int)($isCli ? ($argv[2] ?? 60) : ($_GET['min'] ?? 60))));
        $state['run_at'] = time() + $min * 60;
        $state['run_engine'] = $forceEngine;
        save_state($state);
        logmsg("Yangi video $min daqiqadan keyin yasaladi: " . date('H:i', $state['run_at']) . " (Toshkent vaqti)");
        exit;
    }
    // Avtomatik jadval (Toshkent vaqti): standart — Sesh/Pay/Shan, 19:50 da boshlanadi -> ~20:00 da chiqadi
    if ($mode === 'check' && empty($state['pending'])) {
        $days  = $CFG['POST_DAYS'] ?? [2, 4, 6];          // 1=Dush ... 7=Yaksh
        $start = $CFG['POST_TIME'] ?? '19:50';
        $today = date('Y-m-d');
        $now   = date('H:i');
        if (in_array((int)date('N'), $days, true) && $now >= $start && $now < '23:00'
            && ($state['last_auto'] ?? '') !== $today) {
            $state['last_auto'] = $today;
            save_state($state);
            logmsg("Jadval bo'yicha yangi video boshlandi ($today $now).");
            $mode = 'new';
        }
    }
    if ($mode === 'check' && empty($state['pending']) && !empty($state['run_at']) && time() >= $state['run_at']) {
        unset($state['run_at']);
        $forceEngine = $state['run_engine'] ?? $forceEngine;
        unset($state['run_engine']);
        $state['last_auto'] = date('Y-m-d');   // bugungi post shu bo'ladi, ikkinchisi chiqmaydi
        save_state($state);
        $mode = 'new';
    }

    if ($mode === 'status') {
        echo "Rasmlar: " . count(list_photos()) . "\n";
        echo "Rejim: har safar yangi g'oya (AI)\n";
        echo "Bugungi dvigatel: " . strtoupper(pick_engine()) . (empty($CFG['KIE_API_KEY']) ? " (Kling kaliti yo'q)" : "") . "\n";
        echo "Oxirgi g'oyalar:\n";
        foreach (array_slice($state['recent'], -5) as $r) echo " * $r\n";
        $dn = ['', 'Dush', 'Sesh', 'Chor', 'Pay', 'Jum', 'Shan', 'Yaksh'];
        echo "Jadval: " . implode(', ', array_map(fn($d) => $dn[$d], $CFG['POST_DAYS'] ?? [2, 4, 6])) . " soat " . ($CFG['POST_TIME'] ?? '19:50') . " (Toshkent), joylanishi ~10 daqiqada\n";
        if (!empty($state['run_at'])) echo "Rejalashtirilgan: " . date('Y-m-d H:i', $state['run_at']) . " (Toshkent)\n";
        echo "Kutilayotgan: " . json_encode($state['pending'], JSON_UNESCAPED_UNICODE) . "\n";
        echo "Oxirgi natijalar:\n";
        foreach (array_slice($state['history'], -5) as $h) echo " - " . json_encode($h, JSON_UNESCAPED_UNICODE) . "\n";
        exit;
    }

    if ($mode === 'new') {
        if ($state['pending']) {
            logmsg("Oldingi video hali tayyorlanmoqda, yangisi buyurtma qilinmadi.");
            exit;
        }
        $photos = list_photos();
        if (!$photos) throw new RuntimeException("photos/ papkasida rasm yo'q.");

        $photo = $photos[$state['photo'] % count($photos)];

        // 0) Har safar yangi g'oya (xato bo'lsa — tayyor ro'yxatdan)
        try {
            $gen = invent_idea(array_slice($state['recent'], -15));
            $scene   = $gen['scene'];
            $outfit  = $gen['outfit'];
            $prompt  = video_prompt($gen['action'], $gen['line'], $gen['music']);
            $extPrompt = ext_prompt($gen['continuation'] ?? '', $gen['line2'] ?? '', $gen['music']);
            $caption = fix_caption($gen['caption']);
            $shots   = is_array($gen['shots'] ?? null) ? $gen['shots'] : [];
            $summary = $gen['summary'] . ' | kiyim: ' . $gen['outfit'] . ' | musiqa: ' . $gen['music'] . ' | "' . $gen['line'] . '"';
        } catch (Throwable $e) {
            logmsg("G'oya yaratilmadi, tayyor ro'yxatdan olinadi: " . $e->getMessage());
            $idea    = $IDEAS[$state['idea'] % count($IDEAS)];
            $scene   = $SCENES[$state['scene'] % count($SCENES)];
            $outfit  = '';
            $prompt  = $idea['prompt'];
            $extPrompt = ext_prompt('', '', 'the same background music');
            $caption = $idea['caption'];
            $shots   = [];
            $summary = 'tayyor g\'oya #' . ($state['idea'] % count($IDEAS) + 1);
            $state['idea']++;
            $state['scene']++;
        }

        // 1) Rasmni kesmasdan 9:16 kanvasga joylash
        $padName = bin2hex(random_bytes(8)) . '.jpg';   // taxmin qilib bo'lmaydigan nom
        prepare_image($photo, "$DIR/prepared/$padName");

        // 2) Grok: bolani yangi fon/atmosferaga joylash
        $prepName = bin2hex(random_bytes(8)) . '.jpg';
        try {
            make_scene("{$CFG['BASE_URL']}/prepared/$padName", $scene, "$DIR/prepared/$prepName", $outfit);
        } finally {
            @unlink("$DIR/prepared/$padName");
        }
        $imgUrl = "{$CFG['BASE_URL']}/prepared/$prepName";

        $engine = $forceEngine ?? pick_engine();
        if ($engine === 'kling') {
            try {
                $res = ['request_id' => kling_create($imgUrl, $shots, $prompt)];
            } catch (Throwable $e) {
                logmsg("Kling ishlamadi, Grok bilan davom etiladi: " . $e->getMessage());
                $engine = 'grok';
            }
        }
        if ($engine === 'grok') {
            $res = http_json('POST', 'https://api.x.ai/v1/videos/generations',
                ["Authorization: Bearer {$CFG['XAI_API_KEY']}"],
                [
                    'model'        => 'grok-imagine-video',
                    'prompt'       => $prompt,
                    'image'        => ['url' => $imgUrl],
                    'duration'     => (int)$CFG['DURATION'],
                    'aspect_ratio' => '9:16',
                    'resolution'   => $CFG['RESOLUTION'],
                ]);
        }
        if (empty($res['request_id'])) throw new RuntimeException('request_id kelmadi: ' . json_encode($res));

        $state['pending'] = [
            'request_id' => $res['request_id'],
            'engine'     => $engine,
            'caption'    => $caption,
            'summary'    => $summary,
            'stage'      => 'base',
            'ext_prompt' => $extPrompt,
            'photo'      => basename($photo),
            'prepared'   => $prepName,
            'created'    => date('c'),
        ];
        $state['recent'][] = $summary;
        $state['recent'] = array_slice($state['recent'], -30);
        $state['photo']++;
        save_state($state);
        logmsg("Video buyurtma qilindi [" . strtoupper($engine) . "]: {$res['request_id']}\nG'oya: $summary");
        exit;
    }

    if ($mode === 'check') {
        $p = $state['pending'];
        if (!$p) { echo "Kutilayotgan video yo'q.\n"; exit; }

        if (($p['engine'] ?? 'grok') === 'kling') {
            $res = kling_status($p['request_id']);
        } else {
            $res = http_json('GET', "https://api.x.ai/v1/videos/{$p['request_id']}",
                ["Authorization: Bearer {$CFG['XAI_API_KEY']}"]);
        }
        $status = $res['status'] ?? 'unknown';

        if (in_array($status, ['failed', 'expired'], true) && ($p['stage'] ?? '') === 'ext' && !empty($p['base_video'])) {
            logmsg("Uzaytirish bajarilmadi ($status), 1-qism joylanadi.");
            $res = ['status' => 'done', 'video' => ['url' => "{$CFG['BASE_URL']}/videos/{$p['base_video']}"]];
            $state['pending']['stage'] = 'final';
            $p = $state['pending'];
            unset($p['base_video']);
            $status = 'done';
        }
        if (in_array($status, ['failed', 'expired'], true)) {
            logmsg("Grok video yasay olmadi ($status): " . json_encode($res, JSON_UNESCAPED_UNICODE));
            notify("❌ Mustafo bot: Grok video yasay olmadi ($status).");
            $state['history'][] = ['t' => date('c'), 'ok' => false, 'reason' => $status];
            $state['pending'] = null;
            save_state($state);
            exit;
        }
        if ($status !== 'done' || empty($res['video']['url'])) {
            echo "Hali tayyor emas: $status " . ($res['progress'] ?? '') . "%\n";
            exit;
        }

        // 1) Videoni hostingga yuklab olish (Grok havolasi vaqtinchalik)
        $vidName = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.mp4';
        $fp = fopen("$DIR/videos/$vidName", 'wb');
        $ch = curl_init($res['video']['url']);
        curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 90]);
        $ok = curl_exec($ch);
        curl_close($ch);
        fclose($fp);
        if (!$ok || filesize("$DIR/videos/$vidName") < 10000) throw new RuntimeException('Video yuklab olinmadi.');
        $videoUrl = "{$CFG['BASE_URL']}/videos/$vidName";

        // 1b) Uzaytirish: 15 s -> 25 s (bir marta)
        if (extend_seconds() > 0 && ($p['engine'] ?? 'grok') === 'grok' && ($p['stage'] ?? 'base') === 'base') {
            try {
                $ext = http_json('POST', 'https://api.x.ai/v1/videos/extensions',
                    ["Authorization: Bearer {$CFG['XAI_API_KEY']}"],
                    [
                        'model'    => 'grok-imagine-video',
                        'prompt'   => $p['ext_prompt'] ?? ext_prompt('', '', 'the same background music'),
                        'duration' => extend_seconds(),
                        'video'    => ['url' => to_data_uri($videoUrl)],
                    ]);
                if (empty($ext['request_id'])) throw new RuntimeException('request_id kelmadi: ' . json_encode($ext));
                $state['pending']['request_id'] = $ext['request_id'];
                $state['pending']['stage'] = 'ext';
                $state['pending']['base_video'] = $vidName;
                save_state($state);
                logmsg("1-qism tayyor, uzaytirilmoqda (+" . extend_seconds() . " s): {$ext['request_id']}");
                exit;
            } catch (Throwable $e) {
                logmsg("Uzaytirib bo'lmadi, 1-qism joylanadi: " . $e->getMessage());
            }
        }
        if (!empty($p['base_video'])) @unlink("$DIR/videos/{$p['base_video']}");

        // 2) Buffer orqali Instagram Reels
        $caption = $p['caption'] ?? ($IDEAS[$p['idea'] ?? 0]['caption']);
        $epNo = ($state['series'] ?? 0) + 1;
        $caption = "Mustafo sarguzashtlari • №$epNo 🎬\n" . $caption
            . (stripos($caption, 'obuna') === false ? "\n\nYangi qismlarni o'tkazib yubormang — obuna bo'ling 💛" : '');
        $query = 'mutation($input: CreatePostInput!) { createPost(input: $input) {
            __typename
            ... on PostActionSuccess { post { id status dueAt } }
            ... on MutationError { message }
        } }';
        $input = [
            'channelId'      => $CFG['CHANNEL_ID'],
            'schedulingType' => 'automatic',
            'mode'           => $CFG['POST_NOW'] ? 'shareNow' : 'addToQueue',
            'text'           => $caption,
            'assets'         => [['video' => ['url' => $videoUrl]]],
            'metadata'       => ['instagram' => [
                'type'              => 'reel',
                'shouldShareToFeed' => true,
                'isAiGenerated'     => true,
            ]],
        ];
        $buf = http_json('POST', 'https://api.buffer.com',
            ["Authorization: Bearer {$CFG['BUFFER_API_KEY']}"],
            ['query' => $query, 'variables' => ['input' => $input]]);

        $cp = $buf['data']['createPost'] ?? null;
        if (!$cp || ($cp['__typename'] ?? '') !== 'PostActionSuccess') {
            throw new RuntimeException('Buffer xatosi: ' . json_encode($buf, JSON_UNESCAPED_UNICODE));
        }

        // Tayyorlangan rasmni o'chiramiz (endi kerak emas)
        @unlink("$DIR/prepared/{$p['prepared']}");

        $state['history'][] = ['t' => date('c'), 'ok' => true, 'video' => $vidName, 'post' => $cp['post']['id']];
        $state['pending'] = null;
        $state['series'] = $epNo;
        save_state($state);
        logmsg("✅ Joylandi: $videoUrl (Buffer post {$cp['post']['id']})");
        notify("✅ Mustafo bot: yangi Reels joylandi!\n$videoUrl");
        exit;
    }

    echo "Noma'lum rejim: $mode (new | check | status)\n";
} catch (Throwable $e) {
    logmsg('XATO: ' . $e->getMessage());
    if ($mode === 'new' && empty($state['pending']) && ($state['last_auto'] ?? '') === date('Y-m-d')) {
        $state['auto_fail'] = (($state['auto_fail_day'] ?? '') === date('Y-m-d')) ? ($state['auto_fail'] ?? 0) + 1 : 1;
        $state['auto_fail_day'] = date('Y-m-d');
        if ($state['auto_fail'] < 3) {
            unset($state['last_auto']);   // 5 daqiqadan keyin qayta urinadi
            logmsg("Qayta urinish rejalashtirildi ({$state['auto_fail']}/3).");
        }
        save_state($state);
    }
    notify('❌ Mustafo bot xatosi: ' . $e->getMessage());
    http_response_code(500);
}
