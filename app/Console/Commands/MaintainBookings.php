<?php
namespace App\Console\Commands;
use App\Services\BookingNotices;
use App\Services\BookingSlots;
use App\Services\BookingPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
class MaintainBookings extends Command {
    protected $signature='booking:maintain';
    protected $description='Expire unpaid holds and queue appointment reminders';
    public function handle(BookingNotices $notices,BookingSlots $slots): int {
        DB::table('bookings')->where('status','held')->where('hold_expires_at','<=',now()->utc()->toDateTimeString())
            ->update(['status'=>'expired','updated_at'=>now()]);
        DB::table('booking_payments')->where('status','pending')->whereIn('booking_id',
            DB::table('bookings')->select('id')->where('status','expired'))->update(['status'=>'failed','updated_at'=>now()]);
        DB::table('booking_waitlist')->where('status','notified')->whereNotNull('offer_expires_at')->where('offer_expires_at','<=',now()->utc())
            ->update(['status'=>'active','offer_expires_at'=>null,'updated_at'=>now()]);
        DB::table('booking_waitlist')->where('status','active')->where('starts_at','>',now()->utc()->toDateTimeString())
            ->orderBy('id')->chunkById(100,function($items)use($slots) {
                foreach($items as $item) {
                    $service=$slots->service((int)$item->counselor_id,(int)$item->booking_service_id);
                    $date=Carbon::parse($item->starts_at,'UTC')->setTimezone('Asia/Tehran')->toDateString();
                    $found=collect($slots->slots((int)$item->counselor_id,$service,$date))->first(fn($slot)=>
                        Carbon::parse($slot['starts_at'])->equalTo(Carbon::parse($item->starts_at,'UTC')) && $slot['available']);
                    if(!$found) continue;
                    DB::transaction(function()use($item) {
                        $locked=DB::table('booking_waitlist')->where('id',$item->id)->where('status','active')->lockForUpdate()->first();
                        if(!$locked) return;
                        DB::table('booking_waitlist')->where('id',$item->id)->update(['status'=>'notified','notified_at'=>now(),
                            'offer_expires_at'=>now()->utc()->addHours((int)BookingPolicy::get('waitlist_offer_hours',24)),
                            'offer_count'=>DB::raw('offer_count + 1'),'updated_at'=>now()]);
                        DB::table('booking_audit_events')->insert(['actor_id'=>null,'booking_id'=>null,'action'=>'waitlist.offer_created',
                            'details'=>json_encode(['waitlist_id'=>$item->id]),'created_at'=>now(),'updated_at'=>now()]);
                        $offer=(int)DB::table('booking_waitlist')->where('id',$item->id)->value('offer_count');
                        DB::table('booking_in_app_notifications')->insertOrIgnore(['user_id'=>$item->user_id,'booking_id'=>null,
                            'event_key'=>'waitlist-available-'.$item->id.'-'.$offer,'title'=>'زمان درخواستی شما آزاد شده است',
                            'body'=>'برای رزرو این زمان وارد بخش انتخاب نوبت شوید؛ زمان تا ثبت رزرو تضمین نمی‌شود.',
                            'payload'=>json_encode(['counselor_id'=>$item->counselor_id,'service_id'=>$item->booking_service_id,'starts_at'=>$item->starts_at]),
                            'created_at'=>now(),'updated_at'=>now()]);
                    },3);
                }
            });
        $releaseAt=now()->utc()->addMinutes((int)BookingPolicy::get('connection_release_minutes',config('booking.connection_release_minutes',10)));
        DB::table('bookings')->whereIn('status',['confirmed','checked_in','in_progress'])->whereNull('connection_release_notified_at')
            ->whereIn('session_mode',['meet','voice_call'])
            ->where('starts_at','<=',$releaseAt->toDateTimeString())->where('starts_at','>',now()->utc()->toDateTimeString())
            ->orderBy('id')->chunkById(100,function($bookings)use($notices) {
                foreach($bookings as $booking) {
                    if(!$booking->session_connection) {
                        $notices->queue($booking->id,'missing-connection-'.$booking->starts_at,'reception','in_app');
                        continue;
                    }
                    DB::transaction(function()use($booking) {
                        $locked=DB::table('bookings')->where('id',$booking->id)->lockForUpdate()->first();
                        if(!$locked || $locked->connection_release_notified_at) return;
                        DB::table('bookings')->where('id',$locked->id)->update(['connection_released_at'=>now()->utc(),
                            'connection_release_notified_at'=>now()->utc(),'updated_at'=>now()]);
                        $counselorUser=DB::table('counselors')->where('id',$locked->counselor_id)->value('user_id');
                        $payload=json_encode(['mode'=>$locked->session_mode,'connection'=>$locked->session_connection,'instructions'=>$locked->session_instructions]);
                        foreach(array_filter([(int)$locked->user_id,(int)$counselorUser]) as $userId) DB::table('booking_in_app_notifications')->insertOrIgnore([
                            'user_id'=>$userId,'booking_id'=>$locked->id,'event_key'=>'connection-release-'.$locked->starts_at,
                            'title'=>'اطلاعات اتصال جلسه آماده است','body'=>'اطلاعات اتصال در جزئیات رزرو قرار گرفت.',
                            'payload'=>$payload,'created_at'=>now(),'updated_at'=>now()]);
                    },3);
                }
            });
        $day=now('Asia/Tehran')->startOfDay();
        $from=$day->copy()->utc()->toDateTimeString(); $to=$day->copy()->addDay()->utc()->toDateTimeString();
        DB::table('bookings')->where('status','confirmed')->where('starts_at','>=',$from)
            ->where('starts_at','>',now()->utc()->toDateTimeString())->where('starts_at','<',$to)
            ->orderBy('id')->chunkById(100,function($bookings)use($notices) {
                foreach($bookings as $booking) {
                    $event='reminder-'.Carbon::parse($booking->starts_at,'UTC')->setTimezone('Asia/Tehran')->format('Ymd');
                    $notices->queue($booking->id,$event,'client');
                    $notices->queue($booking->id,$event,'counselor');
                }
            });
        return self::SUCCESS;
    }
}
