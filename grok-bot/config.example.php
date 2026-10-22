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

    // true = darhol joylaydi, false = Buffer navbatiga qo'shadi
    'POST_NOW'       => true,

    // Ixtiyoriy: Telegram xabar (bo'sh qoldirsangiz yuborilmaydi)
    'TG_BOT_TOKEN'   => '',
    'TG_CHAT_ID'     => '',
];
