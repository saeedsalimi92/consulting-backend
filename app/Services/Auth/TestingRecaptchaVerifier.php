<?php
namespace App\Services\Auth;

final class TestingRecaptchaVerifier implements RecaptchaVerifier
{
    public function verify(string $token, string $expectedAction, ?string $ip = null): void
    {
        if ($token === 'invalid-test-token') {
            throw AuthFlowException::make(422, 'CAPTCHA_INVALID', 'اعتبارسنجی امنیتی انجام نشد. دوباره تلاش کنید.');
        }
    }
}
