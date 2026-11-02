<?php
namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;
use Throwable;

final class GoogleRecaptchaVerifier implements RecaptchaVerifier
{
    public function verify(string $token, string $expectedAction, ?string $ip = null): void
    {
        if (!config('recaptcha.enabled')) {
            if (app()->environment('production')) throw AuthFlowException::make(503, 'CAPTCHA_UNAVAILABLE', 'اعتبارسنجی امنیتی موقتاً در دسترس نیست.');
            return;
        }
        $secret = (string) config('recaptcha.secret_key');
        if ($secret === '') throw AuthFlowException::make(503, 'CAPTCHA_UNAVAILABLE', 'اعتبارسنجی امنیتی موقتاً در دسترس نیست.');
        try {
            $response = Http::asForm()->acceptJson()->connectTimeout(3)->timeout(5)->post('https://www.google.com/recaptcha/api/siteverify', array_filter([
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $ip,
            ], fn ($value) => $value !== null));
        } catch (Throwable) {
            throw AuthFlowException::make(503, 'CAPTCHA_UNAVAILABLE', 'اعتبارسنجی امنیتی موقتاً در دسترس نیست.');
        }
        if (!$response->successful()) throw AuthFlowException::make(503, 'CAPTCHA_UNAVAILABLE', 'اعتبارسنجی امنیتی موقتاً در دسترس نیست.');
        $data = $response->json();
        $allowed = (array) config('recaptcha.allowed_hostnames', []);
        try { $issuedAt = isset($data['challenge_ts']) ? now()->parse($data['challenge_ts']) : null; } catch (Throwable) { $issuedAt = null; }
        $fresh = $issuedAt && $issuedAt->lte(now()->addSeconds(30)) && $issuedAt->gte(now()->subMinutes(2));
        if (!(bool) ($data['success'] ?? false)
            || ($data['action'] ?? null) !== $expectedAction
            || !in_array(strtolower((string) ($data['hostname'] ?? '')), array_map('strtolower', $allowed), true)
            || !is_numeric($data['score'] ?? null)
            || (float) $data['score'] < (float) config('recaptcha.min_score', 0.5)
            || !$fresh) {
            throw AuthFlowException::make(422, 'CAPTCHA_INVALID', 'اعتبارسنجی امنیتی انجام نشد. دوباره تلاش کنید.');
        }
    }
}
