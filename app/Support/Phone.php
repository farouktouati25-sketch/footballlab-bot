<?php

namespace App\Support;

class Phone
{
    /** "+971 50 123 4567", "00971501234567", "050 123 4567" → "971501234567" (UAE local numbers get 971). */
    public static function normalize(string $number): string
    {
        $n = preg_replace('/\D+/', '', $number) ?? '';
        if (str_starts_with($n, '00')) {
            $n = substr($n, 2);
        }
        if (preg_match('/^05\d{8}$/', $n)) {
            $n = '971'.substr($n, 1);
        } elseif (preg_match('/^5\d{8}$/', $n)) {
            $n = '971'.$n;
        }

        return $n;
    }
}
