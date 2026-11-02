<?php

namespace Tests\Unit;

use App\Services\Sms\SmsIrProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SmsIrProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.sms_ir.mode' => 'sandbox',
            'services.sms_ir.sandbox_api_key' => 'sandbox-test-key',
            'services.sms_ir.production_api_key' => 'production-test-key',
            'services.sms_ir.base_url' => 'https://api.sms.ir/v1',
            'services.sms_ir.live_send_enabled' => false,
        ]);
    }

    public function test_sandbox_sends_documented_verify_payload_and_returns_simulated_acceptance(): void
    {
        Http::fake(['api.sms.ir/*' => Http::response([
            'status' => 1, 'message' => 'accepted', 'data' => ['messageId' => 89545112, 'cost' => 1],
        ], 200)]);

        $result = (new SmsIrProvider())->sendTemplate('9123456789', '123456', ['Code' => '654321'], 'challenge-ref');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.sms.ir/v1/send/verify'
            && $request->method() === 'POST'
            && $request->header('x-api-key')[0] === 'sandbox-test-key'
            && $request->header('Accept')[0] === 'text/plain'
            && $request->data() === [
                'mobile' => '9123456789', 'templateId' => 123456,
                'parameters' => [['name' => 'Code', 'value' => '654321']],
            ]);
        $this->assertSame('sandbox_simulated', $result['status']);
        $this->assertSame('sandbox', $result['delivery_mode']);
        $this->assertSame('89545112', $result['reference']);
        $this->assertSame('simulated', $result['cost_type']);
        $this->assertSame(1.0, $result['cost']);
    }

    public function test_sandbox_never_falls_back_to_production_key(): void
    {
        config(['services.sms_ir.sandbox_api_key' => null]);
        Http::fake();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('credential for the selected mode is missing');
        try {
            (new SmsIrProvider())->sendTemplate('9123456789', '123456', ['Code' => '654321'], 'challenge-ref');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_production_requires_explicit_enablement_and_uses_only_production_key(): void
    {
        config(['services.sms_ir.mode' => 'production']);
        Http::fake();
        try {
            (new SmsIrProvider())->sendTemplate('9123456789', '9876', ['Code' => '654321'], 'id');
            $this->fail('Production sending must be disabled by default.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Production sending is disabled', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_enabled_production_uses_only_production_key(): void
    {
        config(['services.sms_ir.mode' => 'production', 'services.sms_ir.live_send_enabled' => true]);
        Http::fake(['api.sms.ir/*' => Http::response(['status' => 1, 'data' => ['messageId' => 9]], 200)]);
        $result = (new SmsIrProvider())->sendTemplate('9123456789', '9876', ['BookingId' => '4'], 'id');
        Http::assertSent(fn (Request $request) => $request->header('x-api-key')[0] === 'production-test-key'
            && $request->data()['templateId'] === 9876);
        $this->assertSame('accepted', $result['status']);
        $this->assertSame('production', $result['delivery_mode']);
        $this->assertArrayNotHasKey('cost_type', $result);
    }

    public function test_http_200_with_provider_rejection_is_not_acceptance(): void
    {
        Http::fake(['api.sms.ir/*' => Http::response(['status' => 10, 'message' => 'rejected'], 200)]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not accept');
        (new SmsIrProvider())->sendTemplate('9123456789', '123456', ['Code' => '654321'], 'id');
    }

    public function test_malformed_provider_response_fails_closed(): void
    {
        Http::fake(['api.sms.ir/*' => Http::response('not-json', 200)]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid response');
        (new SmsIrProvider())->sendTemplate('9123456789', '123456', ['Code' => '654321'], 'id');
    }

    public function test_provider_rate_limit_and_server_errors_fail_without_retry(): void
    {
        foreach ([429, 500] as $status) {
            Http::fake(['api.sms.ir/*' => Http::response(['status' => 1], $status)]);
            try {
                (new SmsIrProvider())->sendTemplate('9123456789', '123456', ['Code' => '654321'], 'id');
                $this->fail('Provider HTTP errors must fail closed.');
            } catch (RuntimeException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
            Http::assertSentCount(1);
            Http::preventStrayRequests(false);
        }
    }

    public function test_connection_failure_is_sanitized(): void
    {
        Http::fake(fn () => throw new ConnectionException('network failure with secret material'));
        try {
            (new SmsIrProvider())->sendTemplate('9123456789', '123456', ['Code' => '654321'], 'id');
            $this->fail('Connection errors must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('SMS.ir provider connection failed.', $exception->getMessage());
            $this->assertStringNotContainsString('secret material', $exception->getMessage());
        }
    }

    public function test_local_transport_diagnostic_is_categorized_and_redacts_request_values(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $key = 'sandbox-secret-for-test';
        $recipient = '9123456789';
        $otp = '654321';
        config(['services.sms_ir.sandbox_api_key' => $key]);
        Http::fake(fn () => throw new ConnectionException(
            "cURL error 6: Could not resolve host: api.sms.ir; key=$key mobile=$recipient code=$otp"
        ));
        Log::spy();

        try {
            (new SmsIrProvider())->sendTemplate($recipient, '123456', ['Code' => $otp], 'id');
            $this->fail('A transport failure must remain a failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('SMS.ir provider connection failed.', $exception->getMessage());
        }

        Log::shouldHaveReceived('warning')->once()->with('SMS.ir transport failure', Mockery::on(function (array $context) use ($key, $recipient, $otp) {
            $this->assertSame('dns_resolution_failure', $context['category']);
            $this->assertSame(ConnectionException::class, $context['exception_type']);
            $this->assertStringNotContainsString($key, $context['diagnostic']);
            $this->assertStringNotContainsString($recipient, $context['diagnostic']);
            $this->assertStringNotContainsString($otp, $context['diagnostic']);
            $this->assertStringContainsString('[redacted]', $context['diagnostic']);
            return true;
        }));
    }

    public function test_missing_parameters_invalid_mode_and_plain_text_fail_without_http(): void
    {
        Http::fake();
        foreach ([
            fn () => (new SmsIrProvider())->sendTemplate('9123456789', '123456', [], 'id'),
            fn () => (new SmsIrProvider())->send('9123456789', 'plain', 'id'),
        ] as $call) {
            try { $call(); $this->fail('Unsupported dispatch must fail closed.'); }
            catch (RuntimeException) { $this->assertTrue(true); }
        }
        config(['services.sms_ir.mode' => 'unknown']);
        try { (new SmsIrProvider())->sendTemplate('9123456789', '123456', ['Code'=>'1'], 'id'); $this->fail(); }
        catch (RuntimeException $exception) { $this->assertStringContainsString('mode is invalid', $exception->getMessage()); }
        Http::assertNothingSent();
    }
}
