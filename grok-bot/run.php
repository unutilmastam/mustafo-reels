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
set_time_limit(120);
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

function http_json(string $method, string $url, array $headers, ?array $body = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_TIMEOUT        => 60,
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
    $TW = 1080; $TH = 1920;
    $scale = min($TW / $w, $TH / $h) * 0.92;      // to'liq sig'adi, chetida joy qoladi
    $nw = (int)round($w * $scale); $nh = (int)round($h * $scale);
    $out = imagecreatetruecolor($TW, $TH);
    imagefill($out, 0, 0, imagecolorallocate($out, 205, 200, 195));
    imagecopyresampled($out, $img, (int)(($TW - $nw) / 2), $TH - $nh - 40, 0, 0, $nw, $nh, $w, $h);
    imagejpeg($out, $dst, 92);
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
         . "\"caption\" (Uzbek Instagram caption, 1-3 short lines with emojis, then 4-6 relevant hashtags), "
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
    return max(0, min(10, (int)($CFG['EXTEND_SECONDS'] ?? 0)));
}

/** Videoning 2-qismi uchun ko'rsatma. */
function ext_prompt(string $cont, string $line2, string $music): string {
    $cont = $cont !== '' ? $cont : 'He continues naturally with a new playful action; the camera smoothly changes angle.';
    $say  = $line2 !== '' ? " He then says in Uzbek, slowly and clearly, pronouncing every sound correctly: \"$line2\"." : '';
    return "Continue this exact video seamlessly: same boy, same face, same outfit, same place and lighting. $cont$say "
         . "Keep the same background music ($music) continuing without a break, quietly under his voice. "
         . "Professional modern cinematic look, smooth realistic motion.";
}

/** Video uchun yakuniy ko'rsatma (talaffuz va sifat qoidalari bilan). */
function video_prompt(string $action, string $line, string $music = 'soft, cheerful instrumental music matching the mood'): string {
    return "Professional modern cinematic vertical video, smooth realistic motion, shallow depth of field, high detail. "
         . "Keep the boy's face, hair, outfit and identity exactly as in the image. $action "
         . "He says in Uzbek, in a natural cheerful 6-year-old boy voice, slowly and clearly, pronouncing every sound and syllable correctly, "
         . "without skipping letters: \"$line\". Lip movements match the speech. "
         . "If the name Mustafo is said, pronounce it in three syllables: Mus-ta-fo. "
         . "Audio: background music playing throughout the whole video — $music — instrumental, no lyrics, "
         . "mixed quietly under his voice so every word stays clear, plus subtle natural ambient sounds of the place.";
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
            'image'        => ['url' => $srcUrl, 'type' => 'image_url'],
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

try {
    // Kechiktirilgan buyurtma: ?mode=later&min=60 -> 60 daqiqadan keyin cron (check) o'zi 'new' qiladi
    if ($mode === 'later') {
        $min = max(1, min(1440, (int)($isCli ? ($argv[2] ?? 60) : ($_GET['min'] ?? 60))));
        $state['run_at'] = time() + $min * 60;
        save_state($state);
        logmsg("Yangi video $min daqiqadan keyin yasaladi: " . date('H:i', $state['run_at']) . " (Toshkent vaqti)");
        exit;
    }
    if ($mode === 'check' && empty($state['pending']) && !empty($state['run_at']) && time() >= $state['run_at']) {
        unset($state['run_at']);
        save_state($state);
        $mode = 'new';
    }

    if ($mode === 'status') {
        echo "Rasmlar: " . count(list_photos()) . "\n";
        echo "Rejim: har safar yangi g'oya (AI)\n";
        echo "Oxirgi g'oyalar:\n";
        foreach (array_slice($state['recent'], -5) as $r) echo " * $r\n";
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
            $caption = $gen['caption'];
            $summary = $gen['summary'] . ' | kiyim: ' . $gen['outfit'] . ' | musiqa: ' . $gen['music'] . ' | "' . $gen['line'] . '"';
        } catch (Throwable $e) {
            logmsg("G'oya yaratilmadi, tayyor ro'yxatdan olinadi: " . $e->getMessage());
            $idea    = $IDEAS[$state['idea'] % count($IDEAS)];
            $scene   = $SCENES[$state['scene'] % count($SCENES)];
            $outfit  = '';
            $prompt  = $idea['prompt'];
            $extPrompt = ext_prompt('', '', 'the same background music');
            $caption = $idea['caption'];
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

        $res = http_json('POST', 'https://api.x.ai/v1/videos/generations',
            ["Authorization: Bearer {$CFG['XAI_API_KEY']}"],
            [
                'model'        => 'grok-imagine-video',
                'prompt'       => $prompt,
                'image'        => ['url' => $imgUrl],
                'duration'     => extend_seconds() > 0 ? 15 : (int)$CFG['DURATION'],
                'aspect_ratio' => '9:16',
                'resolution'   => $CFG['RESOLUTION'],
            ]);
        if (empty($res['request_id'])) throw new RuntimeException('request_id kelmadi: ' . json_encode($res));

        $state['pending'] = [
            'request_id' => $res['request_id'],
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
        logmsg("Video buyurtma qilindi: {$res['request_id']}\nG'oya: $summary");
        exit;
    }

    if ($mode === 'check') {
        $p = $state['pending'];
        if (!$p) { echo "Kutilayotgan video yo'q.\n"; exit; }

        $res = http_json('GET', "https://api.x.ai/v1/videos/{$p['request_id']}",
            ["Authorization: Bearer {$CFG['XAI_API_KEY']}"]);
        $status = $res['status'] ?? 'unknown';

        if (in_array($status, ['failed', 'expired'], true) && ($p['stage'] ?? '') === 'ext' && !empty($p['base_video'])) {
            logmsg("Uzaytirish bajarilmadi ($status), 15 soniyalik video joylanadi.");
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
        if (extend_seconds() > 0 && ($p['stage'] ?? 'base') === 'base') {
            try {
                $ext = http_json('POST', 'https://api.x.ai/v1/videos/extensions',
                    ["Authorization: Bearer {$CFG['XAI_API_KEY']}"],
                    [
                        'model'    => 'grok-imagine-video',
                        'prompt'   => $p['ext_prompt'] ?? ext_prompt('', '', 'the same background music'),
                        'duration' => extend_seconds(),
                        'video'    => ['url' => $videoUrl],
                    ]);
                if (empty($ext['request_id'])) throw new RuntimeException('request_id kelmadi: ' . json_encode($ext));
                $state['pending']['request_id'] = $ext['request_id'];
                $state['pending']['stage'] = 'ext';
                $state['pending']['base_video'] = $vidName;
                save_state($state);
                logmsg("1-qism tayyor, uzaytirilmoqda (+" . extend_seconds() . " s): {$ext['request_id']}");
                exit;
            } catch (Throwable $e) {
                logmsg("Uzaytirib bo'lmadi, 15 soniyalik video joylanadi: " . $e->getMessage());
            }
        }
        if (!empty($p['base_video'])) @unlink("$DIR/videos/{$p['base_video']}");

        // 2) Buffer orqali Instagram Reels
        $caption = $p['caption'] ?? ($IDEAS[$p['idea'] ?? 0]['caption']);
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
        save_state($state);
        logmsg("✅ Joylandi: $videoUrl (Buffer post {$cp['post']['id']})");
        notify("✅ Mustafo bot: yangi Reels joylandi!\n$videoUrl");
        exit;
    }

    echo "Noma'lum rejim: $mode (new | check | status)\n";
} catch (Throwable $e) {
    logmsg('XATO: ' . $e->getMessage());
    notify('❌ Mustafo bot xatosi: ' . $e->getMessage());
    http_response_code(500);
}
