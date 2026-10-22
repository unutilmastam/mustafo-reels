<?php
// Video g'oyalari. Bot ularni navbat bilan ishlatadi, oxiriga yetgach boshidan boshlaydi.
// 'prompt'  — Grok'ga ko'rsatma (inglizcha yaxshiroq tushunadi), ichida o'zbekcha gap.
// 'caption' — Instagram matni.

$base = 'Animate the child in this photo as a realistic, natural, wholesome short vertical video. '
      . 'Keep his face, hair, outfit and identity exactly as in the image. Smooth natural motion, warm soft lighting. '
      . 'He speaks slowly and clearly in Uzbek, pronouncing every syllable. '
      . 'His name is pronounced in three clear syllables: Mus-ta-fo (moos-TAH-foh) — never shorten it to "Musfo". ';

return [
    [
        'prompt'  => $base . 'He smiles, waves at the camera and says cheerfully in Uzbek, in a natural 6-year-old boy voice: "Assalomu alaykum! Men... Mus-ta-fo! Sahifamga xush kelibsiz!"',
        'caption' => "Assalomu alaykum! Men Mustafo 👋\nSahifamga xush kelibsiz! Obuna bo'ling, har hafta yangi video 😊\n\n#mustafo #bolalar #uzbekistan #reels #trend",
    ],
    [
        'prompt'  => $base . 'He does a fun, simple kids dance to an upbeat rhythm, then points at the camera and says in Uzbek, natural child voice: "Qani, siz ham raqsga tushing!"',
        'caption' => "Kayfiyat 100% 💃🕺\nSiz ham men bilan raqsga tushing!\n\n#raqs #bolalar #trend #reels #uzbek",
    ],
    [
        'prompt'  => $base . 'He looks surprised, puts hands on cheeks, then laughs and says in Uzbek, natural child voice: "Onam \'uxla\' desa, mening uyqum birdan qochib ketadi!"',
        'caption' => "Har bir ota-ona buni biladi 😂\nOnam 'uxla' desa...\n\n#bolalar #kulgi #ota_ona #reels #uzbekistan",
    ],
    [
        'prompt'  => $base . 'He holds an imaginary microphone, nods confidently like a little blogger and says in Uzbek, natural child voice: "Bugun sizga eng zo\'r sirni aytaman: har kuni tabassum qiling!"',
        'caption' => "Kichkina bloger maslahati 🎤😊\nHar kuni tabassum qiling!\n\n#bloger #bolalar #motivatsiya #reels",
    ],
    [
        'prompt'  => $base . 'He thinks with a finger on his chin, then excitedly says in Uzbek, natural child voice: "Katta bo\'lsam, kim bo\'laman? Albatta, dasturchi — xuddi dadamdek!"',
        'caption' => "Katta bo'lsam kim bo'laman? 👨‍💻\nSiz bolaligingizda kim bo'lishni orzu qilgansiz? Izohda yozing 👇\n\n#orzu #bolalar #dasturchi #reels",
    ],
    [
        'prompt'  => $base . 'He shows a thumbs up, winks and says in Uzbek, natural child voice: "Bog\'chadan qaytdim — endi o\'yin vaqti!"',
        'caption' => "Bog'chadan keyingi eng baxtli payt 😄🎈\n\n#bogcha #bolalar #oyin #reels #uzbek",
    ],
    [
        'prompt'  => $base . 'He claps his hands happily, jumps a little and says in Uzbek, natural child voice: "Dam olish kuni keldi! Hammaga yaxshi kayfiyat!"',
        'caption' => "Dam olish kuni keldi! 🥳\nHammaga yaxshi kayfiyat tilayman!\n\n#damolish #bolalar #kayfiyat #reels",
    ],
    [
        'prompt'  => $base . 'He makes a cute serious face, crosses his arms, then breaks into a big smile and says in Uzbek, natural child voice: "Men jiddiyman... Yo\'q, hazillashdim!"',
        'caption' => "Jiddiy bo'lishga harakat qildim 😎😂\n\n#kulgi #bolalar #trend #reels #uzbekistan",
    ],
    [
        'prompt'  => $base . 'He sends a flying kiss and makes a heart shape with his hands, saying in Uzbek, natural child voice: "Onajonim, sizni juda yaxshi ko\'raman!"',
        'caption' => "Eng yaxshi onaga ❤️\nOnangizga yuboring!\n\n#onajon #muhabbat #bolalar #reels",
    ],
    [
        'prompt'  => $base . 'He waves goodbye, smiles and says in Uzbek, natural child voice: "Obuna bo\'lishni unutmang! Keyingi videoda ko\'rishguncha!"',
        'caption' => "Keyingi videoda ko'rishguncha! 👋\nObuna bo'lishni unutmang 🔔\n\n#mustafo #bolalar #reels #uzbekistan",
    ],
];
