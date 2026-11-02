<?php

namespace Tests\Unit;

use App\Services\Auth\AuthFlowException;
use App\Services\Auth\GoogleRecaptchaVerifier;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoogleRecaptchaVerifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['recaptcha.enabled'=>true,'recaptcha.secret_key'=>'unit-test-secret',
            'recaptcha.min_score'=>0.5,'recaptcha.allowed_hostnames'=>['localhost']]);
    }

    public function test_valid_v3_response_checks_google_endpoint_action_hostname_score_and_freshness(): void
    {
        Http::fake(['https://www.google.com/recaptcha/api/siteverify'=>Http::response([
            'success'=>true,'action'=>'otp_request','hostname'=>'localhost','score'=>0.9,
            'challenge_ts'=>now()->toIso8601String(),
        ],200)]);

        app(GoogleRecaptchaVerifier::class)->verify('opaque-v3-token','otp_request','127.0.0.1');

        Http::assertSent(fn($request)=>$request->url()==='https://www.google.com/recaptcha/api/siteverify'
            && $request['response']==='opaque-v3-token'
            && $request['secret']==='unit-test-secret'
            && $request['remoteip']==='127.0.0.1');
    }

    #[DataProvider('invalidResponses')]
    public function test_invalid_action_hostname_score_or_freshness_is_rejected(array $response): void
    {
        Http::fake(['https://www.google.com/recaptcha/api/siteverify'=>Http::response($response,200)]);
        try {
            app(GoogleRecaptchaVerifier::class)->verify('opaque-token','otp_request');
            $this->fail('Expected CAPTCHA_INVALID.');
        } catch (AuthFlowException $exception) {
            $this->assertSame('CAPTCHA_INVALID',$exception->errorCode);
            $this->assertSame(422,$exception->httpStatus);
        }
    }

    public static function invalidResponses(): array
    {
        $base=['success'=>true,'action'=>'otp_request','hostname'=>'localhost','score'=>0.9,
            'challenge_ts'=>'2026-10-15T10:00:00+00:00'];
        return [
            'invalid token'=>[['success'=>false]],
            'wrong action'=>[array_replace($base,['action'=>'login'])],
            'wrong hostname'=>[array_replace($base,['hostname'=>'untrusted.example'])],
            'low score'=>[array_replace($base,['score'=>0.1])],
            'expired'=>[array_replace($base,['challenge_ts'=>'2020-01-01T00:00:00+00:00'])],
        ];
    }

    public function test_google_unavailable_response_fails_closed_without_raw_provider_details(): void
    {
        Http::fake(['https://www.google.com/recaptcha/api/siteverify'=>Http::response(['error-codes'=>['timeout']],503)]);
        try {
            app(GoogleRecaptchaVerifier::class)->verify('opaque-token','otp_request');
            $this->fail('Expected CAPTCHA_UNAVAILABLE.');
        } catch (AuthFlowException $exception) {
            $this->assertSame('CAPTCHA_UNAVAILABLE',$exception->errorCode);
            $this->assertSame(503,$exception->httpStatus);
            $this->assertStringNotContainsString('timeout',$exception->getMessage());
        }
    }

    public function test_production_cannot_disable_recaptcha_as_a_bypass(): void
    {
        config(['recaptcha.enabled'=>false]);
        app()->detectEnvironment(fn()=>'production');
        try {
            app(GoogleRecaptchaVerifier::class)->verify('opaque-token','otp_request');
            $this->fail('Production must fail closed when CAPTCHA is disabled.');
        } catch (AuthFlowException $exception) {
            $this->assertSame('CAPTCHA_UNAVAILABLE',$exception->errorCode);
            $this->assertSame(503,$exception->httpStatus);
        }
    }
}
