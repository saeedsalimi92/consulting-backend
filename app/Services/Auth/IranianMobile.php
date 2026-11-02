<?php
namespace App\Services\Auth;

use InvalidArgumentException;

final class IranianMobile
{
    public static function normalize(string $value): string
    {
        $value = strtr(trim($value), [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ]);
        $value = preg_replace('/[\s()\-‐‑‒–—]+/u', '', $value) ?? '';
        if (str_starts_with($value, '0098')) $value = '+98'.substr($value, 4);
        elseif (str_starts_with($value, '98')) $value = '+'.$value;
        elseif (str_starts_with($value, '0')) $value = '+98'.substr($value, 1);
        elseif (preg_match('/^9\d{9}$/', $value)) $value = '+98'.$value;
        if (!preg_match('/^\+989\d{9}$/', $value)) throw new InvalidArgumentException('MOBILE_INVALID');
        return $value;
    }

    public static function smsIrFormat(string $canonical): string
    {
        return preg_replace('/^\+98/', '', $canonical) ?: $canonical;
    }
}
