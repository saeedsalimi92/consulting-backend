<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
class BookingPolicy
{
    public static function get(string $key,mixed $fallback=null): mixed
    {
        $value=DB::table('booking_policy_settings')->where('key',$key)->value('value');
        return $value===null ? $fallback : $value;
    }
}
