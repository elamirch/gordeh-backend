<?php

/*
|--------------------------------------------------------------------------
| Nephrology text consultation
|--------------------------------------------------------------------------
|
| Money is IRR (rials) everywhere on the server, like the wallet. The patient pays exactly the
| doctor's fee from the wallet; Gordeh keeps `commission_percent` of it and the rest is the
| doctor's earning, withdrawable once the consultation is finished.
|
*/

return [
    'commission_percent' => (int) env('NEPHROLOGY_COMMISSION_PERCENT', 10),

    // The doctor has this long after payment to send the first reply, otherwise the fee is refunded.
    'reply_deadline_hours' => (int) env('NEPHROLOGY_REPLY_DEADLINE_HOURS', 12),

    // Once the doctor has replied, the conversation stays open this long.
    'chat_open_hours' => (int) env('NEPHROLOGY_CHAT_OPEN_HOURS', 48),

    // The patient may write this many messages before the doctor's first reply.
    'max_messages_before_reply' => 6,

    // After a consultation ends, the doctor can still open the patient's shared record this long.
    'doctor_access_days' => (int) env('NEPHROLOGY_DOCTOR_ACCESS_DAYS', 30),

    'fee' => [
        'default' => 4_500_000,
        'min' => 1_000_000,
        'max' => 30_000_000,
    ],

    'min_payout' => (int) env('NEPHROLOGY_MIN_PAYOUT', 1_000_000),

    // Documents asked at sign-up: key => required.
    'documents' => [
        'council' => true,
        'councilB' => true,
        'sub' => true,
        'base' => true,
        'nid' => true,
        'photo' => true,
        'license' => false,
        'extra' => false,
    ],

    'focus' => [
        'بیماری مزمن کلیه (CKD)',
        'نفروپاتی دیابتی',
        'فشار خون و کلیه',
        'دیالیز',
        'پیوند کلیه',
        'گلومرولونفریت',
        'سنگ کلیه',
        'نفرولوژی کودکان',
        'بیماری کلیهٔ پلی‌کیستیک',
    ],
];
