<?php
// ===== SOZLAMALAR =====
// Bu faylni "config.php" deb nomlang va kalitlarni yozing.

return [
    // xAI (Grok) API kalit: https://console.x.ai -> API Keys
    'XAI_API_KEY'    => 'xai-BU_YERGA_KALIT',

    // Buffer API kalit: https://publish.buffer.com/settings/api
    'BUFFER_API_KEY' => 'BU_YERGA_BUFFER_KALIT',

    // mustafo.xayrulloyev kanali (o'zgartirmang)
    'CHANNEL_ID'     => '6ad9d6fe778b240e2f89d12e',

    // Shu papka internetdagi manzili (oxirida / yo'q)
    'BASE_URL'       => 'https://itcode.uz/mustafo-bot',

    // Brauzer yoki cron orqali ishga tushirish uchun maxfiy so'z (o'zgartiring!)
    'SECRET'         => 'mustafo-' . 'ozgartiring123',

    // Video sozlamalari
    'DURATION'       => 10,       // soniya (1-15)
    'RESOLUTION'     => '720p',   // '720p' yoki '480p'
    'EXTEND_SECONDS' => 0,        // uzaytirish: 10 = jami 25 s, 0 = o'chiq (15 s gacha)

    // ===== KLING (Kie.ai orqali) =====
    // Kalit: https://kie.ai -> API Key. Bo'sh qoldirsangiz faqat Grok ishlaydi.
    'KIE_API_KEY'    => '',
    'KLING_MODE'     => 'pro',     // 'pro' yoki 'std'
    // Qaysi kuni qaysi dvigatel (1=Dush ... 7=Yaksh)
    'ENGINE_DAYS'    => [2 => 'grok', 4 => 'kling', 6 => 'grok'],

    // true = darhol joylaydi, false = Buffer navbatiga qo'shadi
    'POST_NOW'       => true,

    // Ixtiyoriy: Telegram xabar (bo'sh qoldirsangiz yuborilmaydi)
    'TG_BOT_TOKEN'   => '',
    'TG_CHAT_ID'     => '',
];
