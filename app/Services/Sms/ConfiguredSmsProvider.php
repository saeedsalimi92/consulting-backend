<?php
namespace App\Services\Sms;
class ConfiguredSmsProvider implements SmsProvider
{
    public function send(string $phone,string $message,string $idempotencyKey): array
    {
        throw new \RuntimeException('No documented real SMS provider has been configured.');
    }
    public function sendTemplate(string $phone,string $template,array $parameters,string $idempotencyKey): array
    {
        throw new \RuntimeException('No documented real SMS provider has been configured.');
    }
}
