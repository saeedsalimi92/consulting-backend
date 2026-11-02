<?php
namespace App\Services\Auth;

use App\Models\User;
use App\Services\Sms\SmsProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class OtpAuthentication
{
    public function __construct(
        private readonly RecaptchaVerifier $captcha,
        private readonly SmsProvider $sms,
        private readonly ProfileCompleteness $profiles,
    ) {}

    public function request(string $rawMobile, string $captchaToken, string $captchaAction, ?string $ip, string $purpose = 'login', ?User $user = null): array
    {
        try { $mobile = IranianMobile::normalize($rawMobile); }
        catch (InvalidArgumentException) { throw AuthFlowException::make(422, 'MOBILE_INVALID', 'شماره موبایل معتبر نیست.'); }
        if (!in_array($purpose, ['login', 'mobile_change', 'step_up'], true)) throw AuthFlowException::make(422, 'MOBILE_INVALID', 'درخواست معتبر نیست.');
        $this->captcha->verify($captchaToken, $captchaAction, $ip);
        if (in_array($purpose,['mobile_change','step_up'],true) && !$user) throw AuthFlowException::make(401, 'OTP_INVALID', 'درخواست احراز هویت معتبر نیست.');
        if ($purpose === 'step_up') {
            try { $current=IranianMobile::normalize((string)$user->phone); }
            catch (InvalidArgumentException) { throw AuthFlowException::make(422,'MOBILE_INVALID','ابتدا شماره موبایل حساب خود را ثبت و تأیید کنید.'); }
            if ($current !== $mobile) throw AuthFlowException::make(422,'MOBILE_INVALID','شماره واردشده با شماره حساب شما مطابقت ندارد.');
        }

        $result = DB::transaction(function () use ($mobile, $ip, $purpose, $user) {
            DB::table('auth_otp_phone_locks')->insertOrIgnore(['mobile'=>$mobile,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('auth_otp_phone_locks')->where('mobile',$mobile)->lockForUpdate()->first();
            if (in_array($purpose,['mobile_change','step_up'],true)) {
                $owners = $this->usersForMobile($mobile);
                if (count($owners) > 0 && (int) $owners[0]->id !== (int) $user->id) {
                    throw AuthFlowException::make(409, 'MOBILE_ALREADY_USED', 'این شماره موبایل قابل استفاده نیست.');
                }
                if (count($owners) > 1) throw AuthFlowException::make(409, 'MOBILE_ALREADY_USED', 'این شماره موبایل قابل استفاده نیست.');
            }
            $now = now();
            $accepted = DB::table('auth_otp_challenges')->where('mobile',$mobile)->whereNotNull('accepted_at')
                ->where('accepted_at','>=',$now->copy()->subHour())->orderBy('accepted_at')->get(['id','accepted_at']);
            if ($accepted->count() >= (int) config('auth_otp.max_accepted_per_hour', 3)) {
                $retry = max(1, now()->parse($accepted->first()->accepted_at)->addHour()->getTimestamp() - $now->getTimestamp());
                throw AuthFlowException::make(429, 'OTP_RATE_LIMITED', 'تعداد درخواست‌های کد تأیید به حد مجاز رسیده است.', ['retry_after_seconds'=>$retry], $retry);
            }
            $last = DB::table('auth_otp_challenges')->where('mobile',$mobile)->whereNotNull('accepted_at')->orderByDesc('accepted_at')->first();
            if ($last && $last->accepted_at) {
                $retryAt = now()->parse($last->accepted_at)->addSeconds((int) config('auth_otp.cooldown_seconds',60));
                $retry = max(1, $retryAt->getTimestamp() - $now->getTimestamp());
                if ($retryAt->gt($now)) {
                    throw AuthFlowException::make(429, 'OTP_COOLDOWN', 'برای دریافت کد تازه کمی صبر کنید.', ['retry_after_seconds'=>$retry], $retry);
                }
            }
            $id = (string) Str::uuid();
            $digits = max(4, min(8, (int) config('auth_otp.code_length',6)));
            $code = (string) random_int((int) ('1'.str_repeat('0',$digits-1)), (int) str_repeat('9',$digits));
            $challengeUser = in_array($purpose,['mobile_change','step_up'],true) ? $user->id : null;
            DB::table('auth_otp_challenges')->insert([
                'id'=>$id,'mobile'=>$mobile,'purpose'=>$purpose,'user_id'=>$challengeUser,
                'code_hash'=>$this->hashCode($id,$mobile,$code),'status'=>'pending','attempts'=>0,
                'request_ip_hash'=>$ip ? hash_hmac('sha256',$ip,(string) config('app.key')) : null,
                'expires_at'=>$now->copy()->addSeconds((int) config('auth_otp.code_ttl_seconds',300)),
                'created_at'=>$now,'updated_at'=>$now,
            ]);
            try {
                $template = config('services.sms_ir.mode') === 'sandbox'
                    ? config('auth_otp.sandbox_otp_template_id', 123456)
                    : config('auth_otp.templates.otp');
                if (!$template) throw AuthFlowException::make(503, 'SMS_PROVIDER_UNAVAILABLE', 'ارسال پیامک موقتاً در دسترس نیست.');
                $delivery = $this->sms->sendTemplate(IranianMobile::smsIrFormat($mobile), (string) $template, ['Code'=>$code], $id);
                $DBnow = now();
                $providerReference = $delivery['reference'] ?? null;
                if (isset($delivery['delivery_mode'])) {
                    $auditPrefix = $delivery['delivery_mode'] === 'sandbox' ? 'sandbox_simulated' : 'production';
                    $providerReference = $auditPrefix.($providerReference !== null ? ':'.$providerReference : '');
                }
                DB::table('auth_otp_challenges')->where('id',$id)->update([
                    'status'=>'sent','accepted_at'=>$DBnow,'provider_reference'=>$providerReference,'updated_at'=>$DBnow,
                ]);
                DB::table('auth_otp_challenges')->where('mobile',$mobile)->where('id','!=',$id)->where('status','sent')
                    ->update(['status'=>'superseded','updated_at'=>$DBnow]);
                if ($purpose === 'mobile_change') User::whereKey($user->id)->update(['pending_phone'=>$mobile,'updated_at'=>$DBnow]);
            } catch (Throwable $e) {
                DB::table('auth_otp_challenges')->where('id',$id)->update(['status'=>'provider_failed','updated_at'=>now()]);
                return ['__error'=>$e instanceof AuthFlowException ? $e : AuthFlowException::make(503, 'SMS_PROVIDER_UNAVAILABLE', 'ارسال پیامک موقتاً در دسترس نیست.')];
            }
            $response = ['challenge_id'=>$id,'expires_in'=>(int) config('auth_otp.code_ttl_seconds',300),
                'resend_after'=>(int) config('auth_otp.cooldown_seconds',60),'code_length'=>$digits,
                'accepted_requests_remaining'=>max(0,(int)config('auth_otp.max_accepted_per_hour',3)-$accepted->count()-1)];
            if ($this->mayPreviewOtp($purpose, $delivery)) $response['dev_otp'] = $code;
            return $response;
        }, 1);
        if (isset($result['__error'])) throw $result['__error'];
        return $result;
    }

    public function verify(string $rawMobile, string $challengeId, string $code, ?User $authenticatedUser = null): array
    {
        try { $mobile = IranianMobile::normalize($rawMobile); }
        catch (InvalidArgumentException) { throw AuthFlowException::make(422, 'MOBILE_INVALID', 'شماره موبایل معتبر نیست.'); }
        $result = DB::transaction(function () use ($mobile, $challengeId, $code, $authenticatedUser) {
            $challenge = DB::table('auth_otp_challenges')->where('id',$challengeId)->where('mobile',$mobile)->lockForUpdate()->first();
            if (!$challenge) throw AuthFlowException::make(422, 'OTP_INVALID', 'کد تأیید معتبر نیست.');
            if ($challenge->status === 'consumed') throw AuthFlowException::make(422, 'OTP_CONSUMED', 'این کد قبلاً استفاده شده است.');
            if ((int)$challenge->attempts >= (int)config('auth_otp.max_attempts',5) || $challenge->status === 'locked') {
                throw AuthFlowException::make(429, 'OTP_TOO_MANY_ATTEMPTS', 'تعداد تلاش‌های ناموفق بیش از حد مجاز است.');
            }
            if ($challenge->status !== 'sent' || now()->gte(now()->parse($challenge->expires_at))) {
                if ($challenge->status === 'sent') DB::table('auth_otp_challenges')->where('id',$challengeId)->update(['status'=>'expired','updated_at'=>now()]);
                return ['__error'=>AuthFlowException::make(422, 'OTP_EXPIRED', 'کد تأیید منقضی شده است.')];
            }
            if (!hash_equals($challenge->code_hash, $this->hashCode($challengeId,$mobile,$code))) {
                $attempts = (int)$challenge->attempts + 1;
                DB::table('auth_otp_challenges')->where('id',$challengeId)->update([
                    'attempts'=>$attempts,'status'=>$attempts >= (int)config('auth_otp.max_attempts',5) ? 'locked' : 'sent','updated_at'=>now(),
                ]);
                if ($attempts >= (int)config('auth_otp.max_attempts',5)) return ['__error'=>AuthFlowException::make(429, 'OTP_TOO_MANY_ATTEMPTS', 'تعداد تلاش‌های ناموفق بیش از حد مجاز است.')];
                return ['__error'=>AuthFlowException::make(422, 'OTP_INVALID', 'کد تأیید درست نیست.', ['attempts_remaining'=>max(0,(int)config('auth_otp.max_attempts',5)-$attempts)])];
            }
            $now = now();
            DB::table('auth_otp_challenges')->where('id',$challengeId)->update(['status'=>'consumed','consumed_at'=>$now,'updated_at'=>$now]);
            if (in_array($challenge->purpose,['mobile_change','step_up'],true)) {
                if (!$authenticatedUser || (int)$authenticatedUser->id !== (int)$challenge->user_id
                    || ($challenge->purpose === 'mobile_change' && $authenticatedUser->pending_phone !== $mobile)
                    || ($challenge->purpose === 'step_up' && $authenticatedUser->phone_verified_at === null)) {
                    throw AuthFlowException::make(401, 'OTP_INVALID', 'درخواست احراز هویت معتبر نیست.');
                }
                if ($challenge->purpose === 'step_up') {
                    User::whereKey($authenticatedUser->id)->update(['last_otp_verified_at'=>$now,'updated_at'=>$now]);
                    $authenticatedUser->refresh();
                    return ['user'=>$this->present($authenticatedUser),'token'=>null]+$this->profiles->for($authenticatedUser);
                }
                $owners = $this->usersForMobile($mobile);
                foreach ($owners as $owner) if ((int)$owner->id !== (int)$authenticatedUser->id) {
                    return ['__error'=>AuthFlowException::make(409, 'MOBILE_ALREADY_USED', 'این شماره موبایل قابل استفاده نیست.')];
                }
                DB::table('auth_mobile_claims')->where('user_id',$authenticatedUser->id)->delete();
                DB::table('auth_mobile_claims')->insertOrIgnore(['mobile'=>$mobile,'user_id'=>$authenticatedUser->id,'verified_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
                User::whereKey($authenticatedUser->id)->update(['phone'=>$mobile,'phone_verified_at'=>$now,'last_otp_verified_at'=>$now,'pending_phone'=>null,'updated_at'=>$now]);
                $authenticatedUser->refresh();
                return ['user'=>$this->present($authenticatedUser),'token'=>null]+$this->profiles->for($authenticatedUser);
            }

            $claim = DB::table('auth_mobile_claims')->where('mobile',$mobile)->lockForUpdate()->first();
            $owners = $this->usersForMobile($mobile);
            if (count($owners) > 1 || ($claim && count($owners) === 1 && (int)$claim->user_id !== (int)$owners[0]->id)) {
                return ['__error'=>AuthFlowException::make(422, 'OTP_INVALID', 'کد تأیید معتبر نیست.')];
            }
            $user = $owners[0] ?? null;
            if (!$user) {
                if ($claim) return ['__error'=>AuthFlowException::make(422, 'OTP_INVALID', 'کد تأیید معتبر نیست.')];
                $user = User::create(['name'=>'کاربر مراجع','first_name'=>null,'last_name'=>null,'email'=>null,'password'=>null,
                    'phone'=>$mobile,'phone_verified_at'=>$now,'last_otp_verified_at'=>$now,'is_active'=>true]);
            } else {
                if (!(bool)($user->is_active ?? true) || !$user->canAccessClientPortal()) {
                    return ['__error'=>AuthFlowException::make(422, 'OTP_INVALID', 'کد تأیید معتبر نیست.')];
                }
                $user->forceFill(['phone'=>$mobile,'phone_verified_at'=>$now,'last_otp_verified_at'=>$now])->save();
            }
            DB::table('auth_mobile_claims')->insertOrIgnore(['mobile'=>$mobile,'user_id'=>$user->id,'verified_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
            $claim = DB::table('auth_mobile_claims')->where('mobile',$mobile)->first();
            if (!$claim || (int)$claim->user_id !== (int)$user->id) return ['__error'=>AuthFlowException::make(422, 'OTP_INVALID', 'کد تأیید معتبر نیست.')];
            $token = $user->createToken('client',['client'])->plainTextToken;
            return ['user'=>$this->present($user),'token'=>$token]+$this->profiles->for($user);
        }, 3);
        if (isset($result['__error'])) throw $result['__error'];
        return $result;
    }

    /** @return list<User> */
    public function usersForMobile(string $canonical): array
    {
        return User::query()->whereNotNull('phone')->get(['id','name','first_name','last_name','email','password','phone','phone_verified_at','last_otp_verified_at','birth_date','address','is_active','is_admin','admin_permissions'])->filter(function (User $user) use ($canonical) {
            try { return IranianMobile::normalize((string)$user->phone) === $canonical; }
            catch (InvalidArgumentException) { return false; }
        })->values()->all();
    }

    public function present(User $user): array
    {
        return array_merge($user->only('id','name','first_name','last_name','email','phone','phone_verified_at','birth_date','address'), $this->profiles->for($user));
    }

    private function hashCode(string $id, string $mobile, string $code): string
    {
        return hash_hmac('sha256', $id.'|'.$mobile.'|'.$code, (string) config('app.key'));
    }

    private function mayPreviewOtp(string $purpose, array $delivery): bool
    {
        return $purpose === 'login'
            && app()->environment('local')
            && config('app.env') === 'local'
            && config('auth_otp.provider') === 'sms_ir'
            && config('services.sms_ir.mode') === 'sandbox'
            && config('services.sms_ir.live_send_enabled') === false
            && config('auth_otp.dev_preview_enabled') === true
            && ($delivery['delivery_mode'] ?? null) === 'sandbox';
    }
}
