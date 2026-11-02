<?php
namespace App\Jobs;

use App\Services\Sms\FakeSmsProvider;
use App\Services\Sms\ConfiguredSmsProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class DeliverSmsReminder implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
    public int $tries=8;
    public function __construct(public int $reminderId) {}
    public function backoff(): array { $id=DB::table('booking_sms_reminders')->where('id',$this->reminderId)->value('rule_id');$delay=$id?(int)DB::table('booking_sms_reminder_rules')->where('id',$id)->value('retry_delay_seconds'):300;return array_fill(0,7,max(30,$delay)); }
    public function handle(): void
    {
        $claim=DB::transaction(function(){
            $reminder=DB::table('booking_sms_reminders')->where('id',$this->reminderId)->lockForUpdate()->first();
            if(!$reminder || !in_array($reminder->status,['pending','queued'],true)) return null;
            $booking=DB::table('bookings')->where('id',$reminder->booking_id)->lockForUpdate()->first();
            if(!$booking || !in_array($booking->status,['confirmed','checked_in'],true) || $booking->starts_at!==$reminder->appointment_starts_at || Carbon::parse($booking->starts_at,'UTC')->lte(now()->utc())) {
                DB::table('booking_sms_reminders')->where('id',$reminder->id)->update(['status'=>'cancelled','last_error'=>'Booking is no longer eligible','updated_at'=>now()]); return null;
            }
            $consent=DB::table('user_notification_preferences')->where('user_id',$reminder->user_id)->where('sms_enabled',true)->exists();
            $user=DB::table('users')->where('id',$reminder->user_id)->first();
            if(!$consent || !$user?->phone) { DB::table('booking_sms_reminders')->where('id',$reminder->id)->update(['status'=>'skipped','last_error'=>!$consent?'SMS consent missing':'Phone unavailable','updated_at'=>now()]); return null; }
            $rule=DB::table('booking_sms_reminder_rules')->find($reminder->rule_id);
            if(!$rule) { DB::table('booking_sms_reminders')->where('id',$reminder->id)->update(['status'=>'cancelled','updated_at'=>now()]); return null; }
            $attempt=(int)$reminder->attempts+1;$retryAttempt=(int)$reminder->retry_attempts+1;
            DB::table('booking_sms_reminders')->where('id',$reminder->id)->update(['status'=>'sending','attempts'=>$attempt,'retry_attempts'=>$retryAttempt,'last_attempt_at'=>now(),'updated_at'=>now()]);
            DB::table('booking_sms_delivery_attempts')->insertOrIgnore(['reminder_id'=>$reminder->id,'attempt_number'=>$attempt,'provider'=>config('booking.sms_driver'),'status'=>'sending','attempted_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
            return compact('reminder','booking','user','rule','attempt','retryAttempt');
        },3);
        if(!$claim)return;
        $mode=$claim['booking']->session_mode;
        $local=Carbon::parse($claim['booking']->starts_at,'UTC')->setTimezone('Asia/Tehran');
        $counselor=DB::table('counselors')->where('id',$claim['booking']->counselor_id)->value('name')??'';
        $service=DB::table('booking_services')->where('id',$claim['booking']->booking_service_id)->value('title')??'';
        $profile=json_decode(DB::table('cms_settings')->where('key','center_profile')->value('value')?:'{}',true)?:[];
        $text=strtr($claim['rule']->template,['{{client_name}}'=>$claim['user']->name,'{{counselor_name}}'=>$counselor,
            '{{appointment_date}}'=>$local->format('Y-m-d'),'{{appointment_time}}'=>$local->format('H:i'),'{{session_type}}'=>$mode,
            '{{center_name}}'=>$profile['name']??config('app.name'),'{{booking_reference}}'=>'BK-'.$claim['booking']->id,'{{center_address}}'=>$profile['address']??'','{{service_name}}'=>$service]);
        try {
            $driver=config('booking.sms_driver');
            $driver=DB::table('cms_settings')->where('key','booking.sms_driver')->value('value')?:$driver;
            if($driver==='fake') $response=(new FakeSmsProvider())->send($claim['user']->phone,$text,'reminder-'.$this->reminderId);
            elseif($driver==='log') { DB::table('booking_sms_reminders')->where('id',$this->reminderId)->update(['status'=>'not_configured','provider'=>'log','last_error'=>'No SMS provider configured; no message sent','updated_at'=>now()]);DB::table('booking_sms_delivery_attempts')->where('reminder_id',$this->reminderId)->where('attempt_number',$claim['attempt'])->update(['status'=>'not_configured','error_code'=>'provider_not_configured','completed_at'=>now(),'updated_at'=>now()]);return; }
            else $response=(new ConfiguredSmsProvider())->send($claim['user']->phone,$text,'reminder-'.$this->reminderId);
            $deliveryProvider=isset($response['delivery_mode'])?'sms_ir_'.$response['delivery_mode']:$driver;
            DB::table('booking_sms_reminders')->where('id',$this->reminderId)->update(['status'=>in_array($response['status'],['simulated','sandbox_simulated'],true)?'simulated':'sent',
                'provider'=>$deliveryProvider,'provider_reference'=>$response['reference']??null,'provider_response'=>json_encode($response),
                'sent_at'=>now(),'last_error'=>null,'updated_at'=>now()]);
            DB::table('booking_sms_delivery_attempts')->where('reminder_id',$this->reminderId)->where('attempt_number',$claim['attempt'])->update(['status'=>$response['status'],'provider'=>$deliveryProvider,'provider_reference'=>$response['reference']??null,'provider_response'=>json_encode($response),'completed_at'=>now(),'updated_at'=>now()]);
        } catch(\Throwable $e) {
            $current=DB::table('booking_sms_reminders')->find($this->reminderId); $rule=DB::table('booking_sms_reminder_rules')->find($current->rule_id);
            $failed=(int)$current->retry_attempts >= (int)$rule->max_attempts;
            $retryAt=now()->addSeconds((int)$rule->retry_delay_seconds);
            DB::table('booking_sms_reminders')->where('id',$this->reminderId)->update(['status'=>$failed?'failed':'pending',
                'available_at'=>$failed?null:$retryAt,'last_error'=>'Provider delivery failed','updated_at'=>now()]);
            DB::table('booking_sms_delivery_attempts')->where('reminder_id',$this->reminderId)->where('attempt_number',$claim['attempt'])->update(['status'=>'failed','error_code'=>'provider_delivery_failed','completed_at'=>now(),'updated_at'=>now()]);
            if(!$failed) throw $e;
        }
    }
}
