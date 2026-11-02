<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsIrSandboxOtpIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mocked_sandbox_request_can_authenticate_new_account_without_exposing_code(): void
    {
        config([
            'auth_otp.provider' => 'sms_ir',
            'auth_otp.cooldown_seconds' => 60,
            'services.sms_ir.mode' => 'sandbox',
            'services.sms_ir.sandbox_api_key' => 'sandbox-test-key',
            'services.sms_ir.production_api_key' => 'production-test-key',
            'services.sms_ir.base_url' => 'https://api.sms.ir/v1',
            'services.sms_ir.live_send_enabled' => false,
        ]);
        $capturedCode = null;
        Http::fake(function (Request $request) use (&$capturedCode) {
            $this->assertSame('https://api.sms.ir/v1/send/verify', $request->url());
            $this->assertSame('sandbox-test-key', $request->header('x-api-key')[0]);
            $this->assertSame(123456, $request->data()['templateId']);
            $this->assertSame('9123456789', $request->data()['mobile']);
            $this->assertSame('Code', $request->data()['parameters'][0]['name']);
            $capturedCode = $request->data()['parameters'][0]['value'];
            $this->assertMatchesRegularExpression('/^\d{6}$/', $capturedCode);
            return Http::response(['status' => 1, 'message' => 'accepted', 'data' => ['messageId' => 56789, 'cost' => 1]], 200);
        });

        $challenge = $this->postJson('/api/auth/otp/request', [
            'mobile' => '۰۹۱۲۳۴۵۶۷۸۹', 'recaptcha_token' => 'valid-test-token', 'recaptcha_action' => 'otp_request',
        ])->assertOk()->assertJsonMissingPath('data.code')->json('data.challenge_id');

        $audit = DB::table('auth_otp_challenges')->where('id', $challenge)->first();
        $this->assertSame('sent', $audit->status);
        $this->assertSame('sandbox_simulated:56789', $audit->provider_reference);
        $this->assertSame(0, User::count());

        $this->postJson('/api/auth/otp/verify', [
            'mobile' => '09123456789', 'challenge_id' => $challenge, 'code' => $capturedCode,
        ])->assertOk()->assertJsonPath('has_password', false)->assertJsonPath('mobile_verified', true);

        $this->assertSame(1, User::count());
        $this->postJson('/api/auth/otp/verify', [
            'mobile' => '09123456789', 'challenge_id' => $challenge, 'code' => $capturedCode,
        ])->assertStatus(422)->assertJsonPath('code', 'OTP_CONSUMED');
        Http::assertSentCount(1);
    }
}
