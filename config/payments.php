<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Discount Codes
    |--------------------------------------------------------------------------
    |
    | Codes that grant a free (unpaid) "success" payment record when redeemed
    | via POST /payments/redeem-discount, bypassing the Zarinpal flow. Matched
    | case-insensitively after trimming. Add more codes to this list as needed.
    |
    */

    'discount_codes' => array_filter(array_map('trim', explode(',', env('PAYMENT_DISCOUNT_CODES', 'kmu')))),

    /*
    |--------------------------------------------------------------------------
    | Prices and wallet
    |--------------------------------------------------------------------------
    |
    | What each service costs, in IRR. Every request is paid from the user's wallet
    | (or from a legacy one-off credit: a successful Payment whose matching
    | `is_used_*` flag is still false — admin credits, discount codes, Bazaar
    | purchases). The server decides the price; clients only display it.
    |
    | `wallet.topups` are the amounts the apps offer for a top-up (IRR); a custom amount
    | between `wallet.min` and `wallet.max` is also accepted.
    |
    */

    'prices' => [
        'lab_test' => (int) env('PRICE_LAB_TEST', 3_990_000),
        'insurance' => (int) env('PRICE_INSURANCE', 3_990_000),
        'consultation' => (int) env('PRICE_CONSULTATION', 4_500_000),
    ],

    'wallet' => [
        'min' => 100_000,
        'max' => 100_000_000,
        'topups' => [5_000_000, 10_000_000, 20_000_000, 50_000_000],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cafe Bazaar in-app billing (Android app)
    |--------------------------------------------------------------------------
    |
    | The app buys a consumable product through Poolakey and sends the purchase
    | token to POST /payments/bazaar/verify, which checks it against Bazaar's
    | developer API before creating a successful Payment. Each product id maps
    | to the amount recorded (IRR) and whether it grants the lab-test/insurance
    | credit; the price itself is whatever is set in Bazaar's Pishkhan.
    |
    */

    'bazaar' => [
        'package_name' => env('BAZAAR_PACKAGE_NAME', 'com.gordeh.app'),
        // Pishkhan → app → «API پیشخان بازار» → «دریافت توکن جدید».
        'api_secret' => env('BAZAAR_API_SECRET'),
        'api_url' => env('BAZAAR_API_URL', 'https://pardakht.cafebazaar.ir/devapi/v2/api'),
        'products' => [
            'gordeh_analysis' => ['amount' => 3_990_000, 'grants_credit' => true, 'description' => 'خرید تحلیل آزمایش کلیه (بازار)'],
            // A pre-paid consultation: spent by the next booking (see PaymentService::charge()).
            'gordeh_consultation' => ['amount' => 4_500_000, 'grants_credit' => false, 'grants_consultation' => true, 'description' => 'پرداخت ثبت نوبت مشاوره تغذیه (بازار)'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Zarinpal
    |--------------------------------------------------------------------------
    |
    | Read through config() (not env()) so the values survive `config:cache`.
    |
    */

    'zarinpal' => [
        'api_url' => env('ZARINPAL_API_URL', 'https://payment.zarinpal.com/pg/v4/payment/'),
        'merchant_id' => env('ZARINPAL_MERCHANT_ID'),
        'callback_url' => env('ZARINPAL_CALLBACK_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mobile payment page
    |--------------------------------------------------------------------------
    |
    | The app opens `page_url/{token}` (a page on the registered gateway domain), whose button
    | asks the API to start the Zarinpal payment. Zarinpal sends the user back to `callback_url`
    | (same domain), which settles the payment and returns to the app's deep link.
    |
    */

    'mobile' => [
        'page_url' => env('PAYMENT_MOBILE_PAGE_URL', 'https://gordeh.com/payment/mobile'),
        'callback_url' => env('PAYMENT_MOBILE_CALLBACK_URL', 'https://gordeh.com/payments/callback?m=1'),
        'token_ttl_minutes' => (int) env('PAYMENT_MOBILE_TOKEN_TTL', 30),
    ],

];
