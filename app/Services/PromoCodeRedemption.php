<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PromoCodeRedemption
{
    public function quote(string $code, int $clientId, int $price): array
    {
        $promo = DB::table('booking_promo_codes')->where('code', strtoupper(trim($code)))->lockForUpdate()->first();
        if (!$promo || !$promo->is_active) throw ValidationException::withMessages(['promo_code' => 'Code is invalid or inactive.']);
        $now = now()->utc();
        if (($promo->starts_at && $promo->starts_at > $now->toDateTimeString()) || ($promo->ends_at && $promo->ends_at < $now->toDateTimeString())) {
            throw ValidationException::withMessages(['promo_code' => 'Code is outside its redemption period.']);
        }
        $count = DB::table('booking_promo_redemptions')->where('promo_code_id', $promo->id)->count();
        if ($promo->max_redemptions !== null && $count >= $promo->max_redemptions) {
            throw ValidationException::withMessages(['promo_code' => 'Code redemption limit reached.']);
        }
        $clientCount = DB::table('booking_promo_redemptions')->where('promo_code_id', $promo->id)->where('client_id', $clientId)->count();
        if ($clientCount >= $promo->max_per_client) throw ValidationException::withMessages(['promo_code' => 'Code already used by this client.']);
        $discount = $promo->discount_type === 'percentage'
            ? intdiv($price * min(10000, (int) $promo->discount_value), 10000)
            : min($price, (int) $promo->discount_value);
        $discount = min(max(0, $price - 1), $discount);
        return ['promo_code_id' => (int) $promo->id, 'discount_irr' => $discount, 'price_irr' => max(1, $price - $discount)];
    }

    public function record(int $promoId, int $bookingId, int $clientId, int $discount): void
    {
        DB::table('booking_promo_redemptions')->insert(['promo_code_id' => $promoId, 'booking_id' => $bookingId,
            'client_id' => $clientId, 'discount_irr' => $discount, 'created_at' => now(), 'updated_at' => now()]);
    }
}
