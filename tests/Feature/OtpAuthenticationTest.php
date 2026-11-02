<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\{AuthFlowException,IranianMobile};
use App\Services\Sms\SmsProvider;
use App\Services\Auth\RecaptchaVerifier;
use App\Services\Auth\TestingRecaptchaVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class OtpAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public string $issuedCode='';

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth_otp.provider'=>'fake','auth_otp.fake_sms_enabled'=>true,'auth_otp.templates.otp'=>'10001',
            'recaptcha.enabled'=>true,'auth_otp.cooldown_seconds'=>60,'auth_otp.max_accepted_per_hour'=>3]);
        $this->app->instance(SmsProvider::class, new class($this) implements SmsProvider {
            public function __construct(private OtpAuthenticationTest $test) {}
            public function send(string $phone,string $message,string $idempotencyKey): array { return ['status'=>'simulated']; }
            public function sendTemplate(string $phone,string $template,array $parameters,string $idempotencyKey): array {
                $this->test->issuedCode=$parameters['Code'];
                return ['status'=>'simulated','delivery_mode'=>'sandbox','reference'=>'test-message'];
            }
        });
    }

    public function test_iranian_mobile_formats_normalize_to_one_canonical_number(): void
    {
        foreach (['09123456789','+989123456789','989123456789','۰۹۱۲۳۴۵۶۷۸۹','٠٩١٢٣٤٥٦٧٨٩'] as $number) {
            $this->assertSame('+989123456789', IranianMobile::normalize($number));
        }
        $this->expectException(\InvalidArgumentException::class);
        IranianMobile::normalize('02112345678');
    }

    public function test_new_mobile_user_is_created_only_after_valid_single_use_otp(): void
    {
        $challenge=$this->postJson('/api/auth/otp/request',['mobile'=>'۰۹۱۲۳۴۵۶۷۸۹','recaptcha_token'=>'valid-test-token','recaptcha_action'=>'otp_request'])
            ->assertOk()->assertJsonPath('success',true)->json('data.challenge_id');
        $this->assertSame(0,User::count());
        $response=$this->postJson('/api/auth/otp/verify',['mobile'=>'+989123456789','challenge_id'=>$challenge,'code'=>$this->issuedCode])
            ->assertOk()->assertJsonPath('profile_complete',false)->assertJsonPath('has_password',false)
            ->assertJsonPath('data.phone','+989123456789');
        $this->assertSame(1,User::count());
        $this->assertNotEmpty($response->json('token'));
        $this->postJson('/api/auth/otp/verify',['mobile'=>'09123456789','challenge_id'=>$challenge,'code'=>$this->issuedCode])
            ->assertStatus(422)->assertJsonPath('code','OTP_CONSUMED');
    }

    public function test_otp_request_does_not_reveal_accounts_and_database_enforces_cooldown_and_hourly_limit(): void
    {
        User::create(['name'=>'Existing','email'=>'otp-existing@example.test','password'=>'password-123456','phone'=>'+989111111111']);
        $first=$this->postJson('/api/auth/otp/request',['mobile'=>'09111111111','recaptcha_token'=>'valid-test-token','recaptcha_action'=>'otp_request'])->assertOk()->json('data.challenge_id');
        $cooldown=$this->postJson('/api/auth/otp/request',['mobile'=>'+989111111111','recaptcha_token'=>'valid-test-token','recaptcha_action'=>'otp_request'])
            ->assertStatus(429)->assertJsonPath('code','OTP_COOLDOWN')->assertHeader('Retry-After');
        $this->assertArrayNotHasKey('dev_otp', $cooldown->json('data') ?? []);
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/otp/resend',['mobile'=>'989111111111','recaptcha_token'=>'valid-test-token','recaptcha_action'=>'otp_resend'])->assertOk();
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/otp/request',['mobile'=>'09111111111','recaptcha_token'=>'valid-test-token','recaptcha_action'=>'otp_request'])->assertOk();
        $this->travel(61)->seconds();
        $limited=$this->postJson('/api/auth/otp/resend',['mobile'=>'09111111111','recaptcha_token'=>'valid-test-token','recaptcha_action'=>'otp_resend'])
            ->assertStatus(429)->assertJsonPath('code','OTP_RATE_LIMITED');
        $this->assertArrayNotHasKey('dev_otp', $limited->json('data') ?? []);
        $this->assertSame(1,User::count());
        $this->assertNotEmpty($first);
    }

    public function test_invalid_attempts_persist_and_lock_challenge_after_five_tries(): void
    {
        $challenge=$this->postJson('/api/auth/otp/request',['mobile'=>'09122222222','recaptcha_token'=>'valid-test-token','recaptcha_action'=>'otp_request'])->assertOk()->json('data.challenge_id');
        for($i=1;$i<5;$i++) $this->postJson('/api/auth/otp/verify',['mobile'=>'09122222222','challenge_id'=>$challenge,'code'=>'000000'])
            ->assertStatus(422)->assertJsonPath('code','OTP_INVALID');
        $this->postJson('/api/auth/otp/verify',['mobile'=>'09122222222','challenge_id'=>$challenge,'code'=>'000000'])
            ->assertStatus(429)->assertJsonPath('code','OTP_TOO_MANY_ATTEMPTS');
        $this->assertSame(5,(int)DB::table('auth_otp_challenges')->where('id',$challenge)->value('attempts'));
    }

    public function test_captcha_failure_is_stable_and_does_not_create_a_challenge(): void
    {
        $this->enableOtpPreview();
        $this->postJson('/api/auth/otp/request',['mobile'=>'09133333333','recaptcha_token'=>'invalid-test-token','recaptcha_action'=>'otp_request'])
            ->assertStatus(422)->assertJsonPath('code','CAPTCHA_INVALID');
        $this->assertSame(0,DB::table('auth_otp_challenges')->count());
    }

    public function test_dev_otp_returns_the_same_code_after_sandbox_acceptance(): void
    {
        $this->enableOtpPreview();
        $response=$this->postJson('/api/auth/otp/request',['mobile'=>'09133333334','recaptcha_token'=>'valid-test-token','recaptcha_action'=>'otp_request'])
            ->assertOk();

        $this->assertSame($this->issuedCode, $response->json('data.dev_otp'));
        $this->assertMatchesRegularExpression('/^\d{6}$/', $response->json('data.dev_otp'));
        $challenge=$response->json('data.challenge_id');
        $this->assertSame(
            hash_hmac('sha256', $challenge.'|+989133333334|'.$this->issuedCode, (string) config('app.key')),
            DB::table('auth_otp_challenges')->where('id',$challenge)->value('code_hash')
        );
        $this->postJson('/api/auth/otp/verify',['mobile'=>'09133333334','challenge_id'=>$challenge,'code'=>$response->json('data.dev_otp')])
            ->assertOk();
    }

    public function test_dev_otp_is_hidden_outside_the_exact_local_sandbox_configuration(): void
    {
        $this->enableOtpPreview();
        $forbiddenConfigurations = [
            ['environment'=>'testing'],
            ['environment'=>'staging'],
            ['environment'=>'production'],
            ['provider'=>'fake'],
            ['mode'=>'production'],
            ['live_send_enabled'=>true],
            ['enabled'=>false],
        ];

        foreach ($forbiddenConfigurations as $index=>$override) {
            $this->setPreviewConfiguration($override);
            $response=$this->postJson('/api/auth/otp/request',[
                'mobile'=>'09133333'.str_pad((string)$index, 3, '0', STR_PAD_LEFT),
                'recaptcha_token'=>'valid-test-token','recaptcha_action'=>'otp_request',
            ])->assertOk();
            $this->assertArrayNotHasKey('dev_otp', $response->json('data'), json_encode($override));
        }
    }

    public function test_provider_failure_is_not_counted_as_an_accepted_sms_request(): void
    {
        $this->enableOtpPreview();
        $this->app->instance(SmsProvider::class, new class implements SmsProvider {
            public function send(string $phone,string $message,string $idempotencyKey): array { throw new \RuntimeException('provider down'); }
            public function sendTemplate(string $phone,string $template,array $parameters,string $idempotencyKey): array { throw new \RuntimeException('provider down'); }
        });
        $response=$this->postJson('/api/auth/otp/request',['mobile'=>'09155555555','recaptcha_token'=>'valid-test-token','recaptcha_action'=>'otp_request'])
            ->assertStatus(503)->assertJsonPath('code','SMS_PROVIDER_UNAVAILABLE');
        $this->assertArrayNotHasKey('dev_otp', $response->json('data') ?? []);
        $this->assertSame(0,DB::table('auth_otp_challenges')->whereNotNull('accepted_at')->count());
        $this->assertSame('provider_failed',DB::table('auth_otp_challenges')->value('status'));
    }

    public function test_password_login_accepts_legacy_email_and_canonical_mobile_identifiers(): void
    {
        $user=User::create(['name'=>'Password client','email'=>'password-client@example.test','password'=>'LongPassword1234','phone'=>'09166666666']);
        $this->postJson('/api/client/login',['email'=>'+989166666666','password'=>'LongPassword1234'])
            ->assertOk()->assertJsonPath('data.id',$user->id)->assertJsonPath('has_password',true);
        $this->postJson('/api/client/login',['email'=>'password-client@example.test','password'=>'LongPassword1234'])
            ->assertOk()->assertJsonPath('data.id',$user->id);
    }

    public function test_unknown_mobile_and_wrong_password_return_same_generic_error_without_account_creation(): void
    {
        User::create(['name'=>'Known client','email'=>'known-generic@example.test','password'=>'KnownPassword123',
            'phone'=>'+989188888887','is_active'=>true]);
        $wrongPassword=$this->postJson('/api/client/login',['email'=>'09188888887','password'=>'incorrect-password']);
        $unknownMobile=$this->postJson('/api/client/login',['email'=>'09189999999','password'=>'incorrect-password']);
        $wrongPassword->assertStatus(422);
        $unknownMobile->assertStatus(422);
        $this->assertSame($wrongPassword->json('message'),$unknownMobile->json('message'));
        $this->assertSame($wrongPassword->json('errors.email'),$unknownMobile->json('errors.email'));
        $this->assertSame(1,User::count());
    }

    public function test_existing_user_can_use_otp_without_creating_duplicate_and_reports_password_state(): void
    {
        $user=User::create(['name'=>'Returning client','email'=>'returning@example.test','phone'=>'+989155555555',
            'password'=>'StrongPassword123','is_active'=>true]);
        $challenge=$this->postJson('/api/auth/otp/request',['mobile'=>'09155555555','recaptcha_token'=>'valid-test-token','recaptcha_action'=>'otp_request'])
            ->assertOk()->json('data.challenge_id');
        $this->postJson('/api/auth/otp/verify',['mobile'=>'09155555555','challenge_id'=>$challenge,'code'=>$this->issuedCode])
            ->assertOk()->assertJsonPath('data.id',$user->id)->assertJsonPath('has_password',true)
            ->assertJsonPath('mobile_verified',true)->assertJsonPath('data.has_password',true);
        $this->assertSame(1,User::count());
    }

    public function test_existing_passwordless_client_is_authenticated_without_duplicate_account(): void
    {
        $user=User::create(['name'=>'Existing OTP client','email'=>'existing-otp@example.test','password'=>null,
            'phone'=>'+989177777777','is_active'=>true]);
        $challenge=$this->postJson('/api/auth/otp/request',['mobile'=>'09177777777','recaptcha_token'=>'valid-test-token','recaptcha_action'=>'otp_request'])
            ->assertOk()->json('data.challenge_id');
        $this->postJson('/api/auth/otp/verify',['mobile'=>'09177777777','challenge_id'=>$challenge,'code'=>$this->issuedCode])
            ->assertOk()->assertJsonPath('data.id',$user->id)->assertJsonPath('has_password',false)
            ->assertJsonPath('data.profile_complete',false)->assertJsonPath('data.mobile_verified',true);
        $this->assertSame(1,User::count());
    }

    public function test_password_setup_cannot_overwrite_an_existing_password(): void
    {
        $user=User::create(['name'=>'Existing password','email'=>'existing-password@example.test','password'=>'OriginalPassword123',
            'phone'=>'+989166666666','phone_verified_at'=>now(),'last_otp_verified_at'=>now(),'is_active'=>true]);
        $token=$user->createToken('client',['client'])->plainTextToken;
        $this->withSanctumToken($token)->postJson('/api/auth/password/set',[
            'password'=>'ReplacementPassword123','password_confirmation'=>'ReplacementPassword123',
        ])->assertStatus(409)->assertJsonPath('code','PASSWORD_ALREADY_CONFIGURED');
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('OriginalPassword123',$user->fresh()->password));
    }

    public function test_otp_user_can_set_password_and_complete_profile_without_replacing_token(): void
    {
        $challenge=$this->postJson('/api/auth/otp/request',['mobile'=>'09177777777','recaptcha_token'=>'valid-test-token','recaptcha_action'=>'otp_request'])
            ->assertOk()->json('data.challenge_id');
        $token=$this->postJson('/api/auth/otp/verify',['mobile'=>'09177777777','challenge_id'=>$challenge,'code'=>$this->issuedCode])
            ->assertOk()->json('token');
        $this->withSanctumToken($token)->putJson('/api/auth/profile',['first_name'=>'آرزو','last_name'=>'آزاده'])
            ->assertOk()->assertJsonPath('data.profile_complete',true)->assertJsonPath('data.mobile_verified',true);
        $this->withSanctumToken($token)->postJson('/api/auth/password/set',['password'=>'StrongPassword123','password_confirmation'=>'StrongPassword123'])
            ->assertOk()->assertJsonPath('data.has_password',true);
        $this->withSanctumToken($token)->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.has_password',true);
    }

    public function test_privileged_account_is_not_duplicated_or_granted_client_token_by_otp(): void
    {
        User::create(['name'=>'Admin','email'=>'otp-admin@example.test','password'=>'password-123456','phone'=>'+989188888888','is_admin'=>true]);
        $challenge=$this->postJson('/api/auth/otp/request',['mobile'=>'09188888888','recaptcha_token'=>'valid-test-token','recaptcha_action'=>'otp_request'])
            ->assertOk()->json('data.challenge_id');
        $this->postJson('/api/auth/otp/verify',['mobile'=>'09188888888','challenge_id'=>$challenge,'code'=>$this->issuedCode])
            ->assertStatus(422)->assertJsonPath('code','OTP_INVALID');
        $this->assertSame(1,User::count());
        $this->assertSame(0,DB::table('personal_access_tokens')->count());
    }

    private function enableOtpPreview(): void
    {
        $this->setPreviewConfiguration([]);
    }

    private function setPreviewConfiguration(array $override): void
    {
        $environment = $override['environment'] ?? 'local';
        $this->app['env'] = $environment;
        $this->app->instance(RecaptchaVerifier::class, new TestingRecaptchaVerifier());
        config([
            'app.env' => $environment,
            'auth_otp.provider' => $override['provider'] ?? 'sms_ir',
            'auth_otp.dev_preview_enabled' => $override['enabled'] ?? true,
            'services.sms_ir.mode' => $override['mode'] ?? 'sandbox',
            'services.sms_ir.live_send_enabled' => $override['live_send_enabled'] ?? false,
        ]);
    }

    public function test_profile_gate_rejects_direct_booking_creation_with_authoritative_error(): void
    {
        $user=User::create(['name'=>'Incomplete','email'=>'incomplete@example.test','password'=>'password-123456','phone'=>'+989144444444']);
        $counselor=DB::table('counselors')->insertGetId(['name'=>'Gate counselor','slug'=>'gate-counselor','is_active'=>true,'is_public'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $service=DB::table('booking_services')->insertGetId(['title'=>'Gate service','duration_minutes'=>60,'price_irr'=>100000,'deposit_irr'=>0,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('counselor_booking_services')->insert(['counselor_id'=>$counselor,'booking_service_id'=>$service,'price_irr'=>100000,'commission_type'=>'percentage','commission_value'=>0]);
        $token=$user->createToken('client',['client'])->plainTextToken;
        $this->withSanctumToken($token)->postJson('/api/booking/reservations',[
            'counselor_id'=>$counselor,'service_id'=>$service,'starts_at'=>now()->addDays(3)->toIso8601String(),
            'mode'=>'online','payment_option'=>'full',
        ])->assertStatus(422)->assertJsonPath('code','PROFILE_INCOMPLETE')->assertJsonPath('missing_profile_fields.0','first_name');
    }
}
