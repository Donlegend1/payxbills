<?php

namespace App\Support;

class Money
{
    /**
     * ₦625,000 or ₦1,250.50 (kobo shown only when present).
     */
    public static function format(float|int|string|null $amount): string
    {
        $amount = round((float) $amount, 2);

        return '₦'.number_format($amount, floor($amount) == $amount ? 0 : 2);
    }
}
