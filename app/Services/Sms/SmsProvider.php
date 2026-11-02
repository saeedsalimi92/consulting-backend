<?php
namespace App\Services\Sms;
interface SmsProvider {
 public function send(string $phone,string $message,string $idempotencyKey): array;
 /** @param array<string,string> $parameters */
 public function sendTemplate(string $phone,string $template,array $parameters,string $idempotencyKey): array;
}
