<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingSlots
{
    public const TIMEZONE = 'Asia/Tehran';

    public function service(int $counselorId, int $serviceId, bool $publicOnly = false): object
    {
        $service = DB::table('booking_services')->where('id', $serviceId)->where('is_active', true)->first();
        if (!$service || !DB::table('counselors')->where('id', $counselorId)->where('is_active', true)->whereNull('deleted_at')->exists()) {
            throw ValidationException::withMessages(['service_id' => 'Service or counselor unavailable']);
        }
        $assignment = DB::table('counselor_booking_services')->where('counselor_id', $counselorId)
            ->where('booking_service_id', $serviceId)->first();
        if (!$assignment) throw ValidationException::withMessages(['service_id' => 'Service not assigned to counselor']);
        if ($publicOnly && !DB::table('counselors')->where('id', $counselorId)->where('is_public', true)->exists()) {
            throw ValidationException::withMessages(['counselor_id' => 'Counselor is not publicly bookable']);
        }
        $service->effective_price_irr = $assignment->price_irr ?? $service->price_irr;
        $service->commission_type = $assignment->commission_type;
        $service->commission_value = $assignment->commission_value;
        return $service;
    }

    public function slots(int $counselorId, object $service, string $date): array
    {
        $day = Carbon::createFromFormat('!Y-m-d', $date, self::TIMEZONE);
        if (!$day || $day->format('Y-m-d') !== $date || $day->lt(now(self::TIMEZONE)->startOfDay()) || $day->gt(now(self::TIMEZONE)->addMonths(3)->endOfDay())) {
            throw ValidationException::withMessages(['date' => 'Date outside booking window']);
        }
        $hours = DB::table('counselor_working_hours')->where('counselor_id', $counselorId)
            ->where('weekday', $day->dayOfWeek)->where('is_active', true)->orderBy('starts_at')->get();
        $holiday=DB::table('center_holidays')->where('holiday_date',$date)->first();
        if($holiday&&!$holiday->is_open)return [];
        $centerHours=$holiday?null:DB::table('center_operating_hours')->where('weekday',$day->dayOfWeek)->first();
        if($centerHours&&!$centerHours->is_open)return [];
        $centerOpens=$holiday?$holiday->opens_at:$centerHours?->opens_at;$centerCloses=$holiday?$holiday->closes_at:$centerHours?->closes_at;
        $slots = [];
        foreach ($hours as $range) {
            $cursor = Carbon::parse($date.' '.$range->starts_at, self::TIMEZONE);
            $end = Carbon::parse($date.' '.$range->ends_at, self::TIMEZONE);
            if($centerOpens&&Carbon::parse($date.' '.$centerOpens,self::TIMEZONE)->gt($cursor))$cursor=Carbon::parse($date.' '.$centerOpens,self::TIMEZONE);
            if($centerCloses&&Carbon::parse($date.' '.$centerCloses,self::TIMEZONE)->lt($end))$end=Carbon::parse($date.' '.$centerCloses,self::TIMEZONE);
            if(!$cursor->lt($end))continue;
            while ($cursor->copy()->addMinutes($service->duration_minutes)->lte($end)) {
                $finish = $cursor->copy()->addMinutes($service->duration_minutes);
                $startUtc = $cursor->copy()->utc()->toDateTimeString();
                $endUtc = $finish->copy()->utc()->toDateTimeString();
                $busy = DB::table('bookings')->where('counselor_id', $counselorId)
                    ->where('starts_at', '<', $endUtc)->where('ends_at', '>', $startUtc)
                    ->where(fn ($q) => $q->whereIn('status', ['confirmed', 'checked_in', 'in_progress', 'completed'])
                        ->orWhere(fn ($q) => $q->where('status', 'held')->where('hold_expires_at', '>', now()->utc()->toDateTimeString())))->exists();
                $off = DB::table('counselor_time_off')->where('counselor_id', $counselorId)
                    ->where('starts_at', '<', $endUtc)->where('ends_at', '>', $startUtc)->exists();
                $key = $cursor->toIso8601String();
                $slots[$key] = ['starts_at' => $key, 'ends_at' => $finish->toIso8601String(),
                    'available' => !$busy && !$off && $cursor->gt(now(self::TIMEZONE)->addMinutes(15))];
                $cursor = $finish;
            }
        }
        return array_values($slots);
    }

    public function requireAvailable(int $counselorId, object $service, Carbon $start): void
    {
        $local = $start->copy()->setTimezone(self::TIMEZONE);
        $slots = $this->slots($counselorId, $service, $local->format('Y-m-d'));
        foreach ($slots as $slot) {
            if (Carbon::parse($slot['starts_at'])->equalTo($start) && $slot['available']) return;
        }
        throw ValidationException::withMessages(['starts_at' => 'Slot unavailable or not aligned with schedule']);
    }
}
