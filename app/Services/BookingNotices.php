<?php
namespace App\Services;
use App\Jobs\SendBookingNotice;
use Illuminate\Support\Facades\DB;
use App\Models\User;
class BookingNotices {
    public function invalidateConnection(int $bookingId): void {
        // Connection details are copied into release notifications. Remove that copy
        // whenever a schedule or connection changes so old links cannot remain usable.
        DB::table('booking_in_app_notifications')->where('booking_id',$bookingId)
            ->where('event_key','like','connection-release-%')->delete();
    }

    public function queue(int $bookingId,string $event,string $recipient='client',string $channel='sms'): void {
        if($channel==='in_app') {
            $booking=DB::table('bookings')->where('id',$bookingId)->first();
            if(!$booking) return;
            $users=match($recipient) {
                'client'=>[(int)$booking->user_id],
                'counselor'=>array_values(array_filter([(int)(DB::table('counselors')->where('id',$booking->counselor_id)->value('user_id') ?? 0)])),
                'reception'=>User::where('is_active',true)->get()->filter(fn($user) => $user->hasAdminPermission('appointments.manage'))->pluck('id')->map(fn($id)=>(int)$id)->all(),
                default=>[],
            };
            foreach($users as $userId) DB::table('booking_in_app_notifications')->insertOrIgnore([
                'user_id'=>$userId,'booking_id'=>$bookingId,'event_key'=>$event,
                'title'=>$event==='connection-release'?'اطلاعات جلسه آماده است':'به‌روزرسانی رزرو',
                'body'=>$event==='connection-release'?'اطلاعات اتصال جلسه در جزئیات رزرو شما قرار گرفت.':'وضعیت رزرو شما به‌روزرسانی شد.',
                'payload'=>json_encode(['event'=>$event]),'created_at'=>now(),'updated_at'=>now(),
            ]);
            if ($recipient === 'reception' && \Illuminate\Support\Facades\Schema::hasTable('admin_notifications')) {
                DB::table('admin_notifications')->insertOrIgnore([
                    'permission' => 'appointments.manage', 'event' => $event, 'title' => 'به‌روزرسانی رزرو',
                    'body' => 'برای یک رزرو نیاز به پیگیری پذیرش وجود دارد.', 'resource_type' => 'booking',
                    'resource_id' => $bookingId, 'created_by' => null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            return;
        }
        // In-app delivery is available even when SMS/email credentials are absent.
        $this->queue($bookingId,$event,$recipient,'in_app');
        DB::table('booking_notification_logs')->insertOrIgnore(
            ['booking_id'=>$bookingId,'channel'=>$channel,'recipient'=>$recipient,'event'=>$event,
                'status'=>'pending','updated_at'=>now(),'created_at'=>now()]);
        $notice=DB::table('booking_notification_logs')->where('booking_id',$bookingId)
            ->where('channel',$channel)->where('recipient',$recipient)->where('event',$event)->first();
        if($notice->status==='pending' && ($channel!=='sms' || in_array(config('booking.sms_driver'),['fake','sms_ir'],true))) SendBookingNotice::dispatch($notice->id)->afterCommit();
        if($notice->status==='pending' && $channel==='sms' && config('booking.sms_driver')==='log')
            DB::table('booking_notification_logs')->where('id',$notice->id)->update(['status'=>'not_configured',
                'error'=>'SMS provider is not configured','updated_at'=>now()]);
    }
}
