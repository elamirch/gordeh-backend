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

];
