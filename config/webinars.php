<?php

/*
| Webinars registrations can be made for, keyed by the slug the frontend sends. Registering for a
| webinar listed here schedules its reminder SMSs (day before, the morning of, one hour before, and
| at the start). After changing a date, run `php artisan gordeh:webinar-reminders {slug}` so already
| scheduled reminders move with it (add --notify to also text registrants the new date).
|
| title/badge/summary/speaker/speaker_title/mode/banner: what the webinars list shows.
| starts_at: local Tehran time. The join link isn't sent from here: Kavenegar tokens only take
| letters and digits, so it is written into the webinar-reminder-1h / -start templates.
*/

return [

    'kidney-awareness-1405-07' => [
        'starts_at' => env('WEBINAR_KIDNEY_AWARENESS_STARTS_AT', '2026-10-24 21:00'),
        // Shown in the webinars list of the website and the apps (GET /webinars).
        'title' => 'کلیه‌ات رو بشناس قبل از اینکه علامت بده',
        'badge' => 'وبینار رایگان گرده',
        'summary' => 'بیماری کلیه را پیش از بروز علائم بشناسید و پیشرفت آن را کند کنید.',
        'speaker' => 'دکتر حمیدرضا بادلی',
        'speaker_title' => 'پروفسور نفرولوژی، بورد فوق تخصص',
        'mode' => 'آنلاین',
        // A path on the website.
        'banner' => '/images/webinar/webinar-banner-1405-08-02.webp',
    ],

];
