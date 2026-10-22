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
$state += ['idea' => 0, 'photo' => 0, 'scene' => 0, 'pending' => null, 'history' => []];

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

/** Grok bilan bolani yangi fonga/atmosferaga joylaydi (9:16). Natija faylini saqlaydi. */
function make_scene(string $srcUrl, string $scene, string $dst): void {
    global $CFG;
    $prompt = "Place this exact same little boy into a new scene: $scene. "
        . "Keep his face, facial features, hairstyle, skin tone and identity exactly the same; "
        . "outfit may suit the scene but must look natural and age-appropriate. "
        . "Vertical 9:16 full-frame composition, the boy in the center, visible from head to at least the knees, "
        . "photorealistic, natural cinematic lighting, sharp focus, wholesome family-friendly mood. "
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
    if ($mode === 'status') {
        echo "Rasmlar: " . count(list_photos()) . "\n";
        echo "Keyingi g'oya: #" . ($state['idea'] % count($IDEAS) + 1) . " / " . count($IDEAS) . "\n";
        echo "Keyingi fon: " . $SCENES[$state['scene'] % count($SCENES)] . "\n";
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
        $idea  = $IDEAS[$state['idea'] % count($IDEAS)];

        $scene = $SCENES[$state['scene'] % count($SCENES)];

        // 1) Rasmni kesmasdan 9:16 kanvasga joylash
        $padName = bin2hex(random_bytes(8)) . '.jpg';   // taxmin qilib bo'lmaydigan nom
        prepare_image($photo, "$DIR/prepared/$padName");

        // 2) Grok: bolani yangi fon/atmosferaga joylash
        $prepName = bin2hex(random_bytes(8)) . '.jpg';
        try {
            make_scene("{$CFG['BASE_URL']}/prepared/$padName", $scene, "$DIR/prepared/$prepName");
        } finally {
            @unlink("$DIR/prepared/$padName");
        }
        $imgUrl = "{$CFG['BASE_URL']}/prepared/$prepName";

        $res = http_json('POST', 'https://api.x.ai/v1/videos/generations',
            ["Authorization: Bearer {$CFG['XAI_API_KEY']}"],
            [
                'model'        => 'grok-imagine-video',
                'prompt'       => $idea['prompt'],
                'image'        => ['url' => $imgUrl],
                'duration'     => (int)$CFG['DURATION'],
                'aspect_ratio' => '9:16',
                'resolution'   => $CFG['RESOLUTION'],
            ]);
        if (empty($res['request_id'])) throw new RuntimeException('request_id kelmadi: ' . json_encode($res));

        $state['pending'] = [
            'request_id' => $res['request_id'],
            'idea'       => $state['idea'] % count($IDEAS),
            'photo'      => basename($photo),
            'prepared'   => $prepName,
            'scene'      => $state['scene'] % count($SCENES),
            'created'    => date('c'),
        ];
        $state['idea']++;
        $state['photo']++;
        $state['scene']++;
        save_state($state);
        logmsg("Video buyurtma qilindi: {$res['request_id']} (g'oya #" . ($state['pending']['idea'] + 1) . ")");
        exit;
    }

    if ($mode === 'check') {
        $p = $state['pending'];
        if (!$p) { echo "Kutilayotgan video yo'q.\n"; exit; }

        $res = http_json('GET', "https://api.x.ai/v1/videos/{$p['request_id']}",
            ["Authorization: Bearer {$CFG['XAI_API_KEY']}"]);
        $status = $res['status'] ?? 'unknown';

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

        // 2) Buffer orqali Instagram Reels
        $idea = $IDEAS[$p['idea']];
        $query = 'mutation($input: CreatePostInput!) { createPost(input: $input) {
            __typename
            ... on PostActionSuccess { post { id status dueAt } }
            ... on MutationError { message }
        } }';
        $input = [
            'channelId'      => $CFG['CHANNEL_ID'],
            'schedulingType' => 'automatic',
            'mode'           => $CFG['POST_NOW'] ? 'shareNow' : 'addToQueue',
            'text'           => $idea['caption'],
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
