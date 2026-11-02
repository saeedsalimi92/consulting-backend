<?php
namespace App\Http\Controllers\Booking;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Hash,Password,RateLimiter};
use Illuminate\Validation\ValidationException;
use App\Services\Auth\{AuthFlowException,IranianMobile,ProfileCompleteness,RecaptchaVerifier,OtpAuthentication};
use InvalidArgumentException;

class ClientAuthController extends Controller
{
    /** @deprecated Public accounts are created only after OTP verification. Kept as a safe compatibility response. */
    public function register() {
        throw AuthFlowException::make(410,'OTP_REGISTRATION_REQUIRED','برای ساخت حساب، شماره موبایل خود را با کد پیامکی تأیید کنید.');
    }
    public function login(Request $r, OtpAuthentication $otp, ProfileCompleteness $profiles) {
        $v=$r->validate(['email'=>'required|string|max:255','password'=>'required|string']);
        $identifier=trim($v['email']);
        try { $canonical=IranianMobile::normalize($identifier); }
        catch (InvalidArgumentException) { $canonical=null; }
        $key='client-login:'.sha1(strtolower($canonical ?? $identifier).'|'.$r->ip());
        if(RateLimiter::tooManyAttempts($key,5)) throw ValidationException::withMessages(['email'=>'Too many attempts']);
        $matches=$canonical ? $otp->usersForMobile($canonical) : [];
        $user=$canonical ? (count($matches)===1?$matches[0]:null) : User::where('email',$identifier)->first();
        if(!$user || !(bool)($user->is_active??true) || !filled($user->password) || !Hash::check($v['password'],$user->password) || !$user->canAccessClientPortal()) {
            RateLimiter::hit($key,60);
            throw ValidationException::withMessages(['email'=>'Invalid credentials']);
        }
        RateLimiter::clear($key);
        return response()->json(['data'=>$otp->present($user),
            'token'=>$user->createToken('client',['client'])->plainTextToken]+$profiles->for($user));
    }
    public function me(Request $r, OtpAuthentication $otp) { return response()->json(['data'=>$otp->present($r->user())]); }
    public function update(Request $r, OtpAuthentication $otp) {
        $v=$r->validate(['name'=>'sometimes|required|string|max:255','phone'=>'sometimes|nullable|string|max:30|unique:users,phone,'.$r->user()->id,
            'birth_date'=>'sometimes|nullable|date','address'=>'sometimes|nullable|string|max:2000']);
        if (array_key_exists('phone',$v)) {
            try { $new=filled($v['phone']) ? IranianMobile::normalize($v['phone']) : null; $current=filled($r->user()->phone) ? IranianMobile::normalize($r->user()->phone) : null; }
            catch (InvalidArgumentException) { throw AuthFlowException::make(422,'MOBILE_INVALID','شماره موبایل معتبر نیست.'); }
            if ($new !== $current) throw AuthFlowException::make(422,'MOBILE_INVALID','برای تغییر شماره، فرایند تأیید شماره موبایل را انجام دهید.');
            unset($v['phone']);
        }
        $r->user()->update($v);
        return $this->me($r,$otp);
    }
    public function logout(Request $r) { $r->user()->currentAccessToken()?->delete(); return response()->noContent(); }
    public function forgot(Request $r, RecaptchaVerifier $captcha) {
        $v=$r->validate(['email'=>'required|email','recaptcha_token'=>app()->environment('testing')?'nullable|string|max:4096':'required|string|max:4096']);
        $captcha->verify($v['recaptcha_token'] ?? '','password_reset_request',$r->ip());
        unset($v['recaptcha_token']);
        abort_unless(config('booking.client_reset_url'),503,'Client reset URL not configured');
        Password::sendResetLink($v);
        return response()->json(['message'=>'If the account exists, a reset link will be sent.']);
    }
    public function reset(Request $r) {
        $v=$r->validate(['token'=>'required|string','email'=>'required|email','password'=>'required|string|min:12|confirmed']);
        $status=Password::reset($v,function(User $user,string $password) {
            $user->forceFill(['password'=>$password])->save();
            $user->tokens()->delete();
        });
        if($status!==Password::PASSWORD_RESET) throw ValidationException::withMessages(['token'=>'Invalid or expired reset token']);
        return response()->json(['message'=>'Password reset']);
    }
}
