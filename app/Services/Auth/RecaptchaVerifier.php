<?php
namespace App\Services\Auth;

interface RecaptchaVerifier
{
    public function verify(string $token, string $expectedAction, ?string $ip = null): void;
}
