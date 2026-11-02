<?php
namespace App\Services\Sms;
class FakeSmsProvider implements SmsProvider
{
    public function send(string $phone,string $message,string $idempotencyKey): array
    {
        abort_unless(app()->environment(['local','testing']),503,'Fake SMS is restricted to local/testing.');
        return ['status'=>'simulated','reference'=>'fake-sms-'.substr(hash('sha256',$idempotencyKey),0,20),'message_length'=>mb_strlen($message)];
    }
    public function sendTemplate(string $phone,string $template,array $parameters,string $idempotencyKey): array
    {
        abort_unless(app()->environment(['local','testing']) && config('auth_otp.fake_sms_enabled'),503,'Fake SMS requires explicit local/testing enablement.');
        return ['status'=>'simulated','reference'=>'fake-sms-'.substr(hash('sha256',$idempotencyKey),0,20)];
    }
}
