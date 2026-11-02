<?php
namespace App\Jobs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\{DB,Log,Mail};
use Illuminate\Support\Carbon;
use App\Services\Auth\IranianMobile;
use App\Services\Sms\{FakeSmsProvider,SmsIrProvider,SmsProvider};

class SendBookingNotice implements ShouldQueue {
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
    public int $tries=3;
    public function __construct(public int $noticeId) {}
    public function handle(): void {
        $notice=DB::table('booking_notification_logs')->find($this->noticeId);
        if(!$notice || in_array($notice->status,['sent','sending','simulated'],true)) return;
        $booking=DB::table('bookings')->find($notice->booking_id);
        if(!$booking) return;
        DB::table('booking_notification_logs')->where('id',$notice->id)->increment('attempts',1,['last_attempt_at'=>now(),'updated_at'=>now()]);
        $when=Carbon::parse($booking->starts_at,'UTC')->setTimezone('Asia/Tehran')->format('Y-m-d H:i');
        $template=DB::table('booking_notification_templates')->where('event',$notice->event)->where('channel',$notice->channel)->where('is_active',true)->first();
        $message=$template?->body ?? 'Appointment #'.$booking->id.' at '.$when.' Asia/Tehran. Please check your account for details.';
        $subject=$template?->subject ?: 'Appointment update';
        $message=strtr($message,['{{booking_id}}'=>(string)$booking->id,'{{starts_at}}'=>$when,'{{timezone}}'=>'Asia/Tehran']);
        try {
            if($notice->channel==='email') {
                $user=DB::table('users')->find($booking->user_id);
                if($notice->recipient!=='client' || !$user?->email) throw new \RuntimeException('Email recipient unavailable');
                Mail::raw($message,fn($mail)=>$mail->to($user->email)->subject($subject));
            } else {
                $template=$this->smsTemplate($notice->event);
                if(!$template || !in_array(config('booking.sms_driver'),['fake','sms_ir'],true)) {
                    DB::table('booking_notification_logs')->where('id',$notice->id)->update(['status'=>'not_configured','error'=>'SMS template/provider is not configured','updated_at'=>now()]);
                    return;
                }
                if($notice->event==='confirmed' && !in_array($booking->status,['confirmed','checked_in'],true)) {
                    DB::table('booking_notification_logs')->where('id',$notice->id)->update(['status'=>'not_configured','error'=>'Booking has not been confirmed','updated_at'=>now()]);
                    return;
                }
                $user=$notice->recipient==='counselor'
                    ? DB::table('users')->join('counselors','counselors.user_id','=','users.id')->where('counselors.id',$booking->counselor_id)->first(['users.*'])
                    : DB::table('users')->where('id',$booking->user_id)->first();
                if(!$user?->phone) throw new \RuntimeException('SMS recipient unavailable');
                DB::table('booking_notification_logs')->where('id',$notice->id)->update(['status'=>'sending','provider'=>config('booking.sms_driver'),'updated_at'=>now()]);
                $local=Carbon::parse($booking->starts_at,'UTC')->setTimezone('Asia/Tehran');
                $counselor=DB::table('counselors')->where('id',$booking->counselor_id)->value('name')??'';
                $parameters=['BookingId'=>(string)$booking->id,'AppointmentDate'=>$local->format('Y-m-d'),
                    'AppointmentTime'=>$local->format('H:i'),'ClientName'=>(string)DB::table('users')->where('id',$booking->user_id)->value('name'),
                    'CounselorName'=>(string)$counselor];
                $provider=config('booking.sms_driver')==='fake'?new FakeSmsProvider():new SmsIrProvider();
                $response=$provider->sendTemplate(IranianMobile::normalize($user->phone),(string)$template,$parameters,'booking-notice-'.$notice->id);
                $deliveryProvider = isset($response['delivery_mode']) ? 'sms_ir_'.$response['delivery_mode'] : config('booking.sms_driver');
                DB::table('booking_notification_logs')->where('id',$notice->id)->update([
                    'status'=>in_array(($response['status']??null),['simulated','sandbox_simulated'],true)?'simulated':'sent','provider'=>$deliveryProvider,
                    'provider_reference'=>$response['reference']??null,'sent_at'=>now(),'error'=>null,'updated_at'=>now()]);
                return;
            }
            DB::table('booking_notification_logs')->where('id',$notice->id)->update(['status'=>'sent','sent_at'=>now(),'error'=>null,'updated_at'=>now()]);
        } catch(\Throwable $e) {
            DB::table('booking_notification_logs')->where('id',$notice->id)->update(['status'=>'failed',
                'error'=>'Delivery failed','updated_at'=>now()]);
            throw $e;
        }
    }

    private function smsTemplate(string $event): ?string
    {
        if($event==='confirmed') return config('auth_otp.templates.booking');
        if($event==='cancelled') return config('auth_otp.templates.cancel');
        if(str_starts_with($event,'rescheduled-')) return config('auth_otp.templates.reschedule');
        return null;
    }
}
