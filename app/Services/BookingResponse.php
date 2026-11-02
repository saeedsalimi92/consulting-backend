<?php
namespace App\Services;
use Illuminate\Support\Carbon;
class BookingResponse {
    public static function present(object $booking): object {
        foreach(['starts_at','ends_at','hold_expires_at','checked_in_at','session_started_at','session_completed_at'] as $field)
            if($booking->$field) $booking->$field=Carbon::parse($booking->$field,'UTC')->toIso8601String();
        $booking->remaining_irr=max(0,(int)$booking->total_irr-(int)$booking->paid_irr);
        return $booking;
    }
    public static function presentForClient(object $booking): object {
        $booking=self::present($booking);
        $release=Carbon::parse($booking->starts_at)->subMinutes((int)BookingPolicy::get('connection_release_minutes',config('booking.connection_release_minutes',10)));
        $booking->connection_available_at=$release->toIso8601String();
        $booking->connection_available=(bool)($booking->session_connection && in_array($booking->status,['confirmed','checked_in','in_progress','completed'],true) && $release->lte(now('UTC')));
        if(!$booking->connection_available) {
            unset($booking->session_connection,$booking->session_instructions);
        }
        unset($booking->connection_released_at,$booking->connection_release_notified_at);
        return $booking;
    }
}
