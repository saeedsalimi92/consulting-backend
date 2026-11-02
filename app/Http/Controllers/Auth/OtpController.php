<?php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\{AuthFlowException,OtpAuthentication,ProfileCompleteness};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Hash};
use Illuminate\Validation\Rules\Password;

final class OtpController extends Controller
{
    public function config()
    {
        return response()->json(['data'=>[
            'recaptcha'=>['enabled'=>(bool)config('recaptcha.enabled'),'version'=>'v3','site_key'=>config('recaptcha.site_key')],
            'otp'=>['code_length'=>(int)config('auth_otp.code_length'),'expires_in'=>(int)config('auth_otp.code_ttl_seconds'),
                'resend_after'=>(int)config('auth_otp.cooldown_seconds')],
        ]]);
    }

    public function request(Request $request, OtpAuthentication $otp)
    {
        if (!is_string($request->input('recaptcha_token')) || $request->input('recaptcha_token') === '') throw AuthFlowException::make(422,'CAPTCHA_INVALID','اعتبارسنجی امنیتی انجام نشد. دوباره تلاش کنید.');
        if ($request->input('recaptcha_action') !== 'otp_request') throw AuthFlowException::make(422,'CAPTCHA_INVALID','اعتبارسنجی امنیتی انجام نشد. دوباره تلاش کنید.');
        $data = $request->validate(['mobile'=>'required|string|max:40','recaptcha_token'=>'string|max:4096']);
        return response()->json(['success'=>true,'data'=>$otp->request($data['mobile'],$data['recaptcha_token'],'otp_request',$request->ip())]);
    }

    public function resend(Request $request, OtpAuthentication $otp)
    {
        if (!is_string($request->input('recaptcha_token')) || $request->input('recaptcha_token') === '') throw AuthFlowException::make(422,'CAPTCHA_INVALID','اعتبارسنجی امنیتی انجام نشد. دوباره تلاش کنید.');
        if ($request->input('recaptcha_action') !== 'otp_resend') throw AuthFlowException::make(422,'CAPTCHA_INVALID','اعتبارسنجی امنیتی انجام نشد. دوباره تلاش کنید.');
        $data = $request->validate(['mobile'=>'required|string|max:40','recaptcha_token'=>'string|max:4096']);
        return response()->json(['success'=>true,'data'=>$otp->request($data['mobile'],$data['recaptcha_token'],'otp_resend',$request->ip())]);
    }

    public function verify(Request $request, OtpAuthentication $otp)
    {
        $data = $request->validate(['mobile'=>'required|string|max:40','challenge_id'=>'required|uuid','code'=>'required|string|size:6']);
        $result = $otp->verify($data['mobile'],$data['challenge_id'],$data['code'],$request->user());
        if ($result['token']) {
            $user = $result['user']; $token = $result['token']; unset($result['user'], $result['token']);
            return response()->json(['success'=>true,'token'=>$token,'data'=>$user]+$result);
        }
        return response()->json(['success'=>true,'data'=>$result]);
    }

    public function me(Request $request, OtpAuthentication $otp)
    {
        return response()->json(['data'=>$otp->present($request->user())]);
    }

    public function updateProfile(Request $request, OtpAuthentication $otp, ProfileCompleteness $profiles)
    {
        $data = $request->validate([
            'first_name'=>'sometimes|required|string|max:120',
            'last_name'=>'sometimes|required|string|max:120',
            'address'=>'sometimes|nullable|string|max:2000',
            'birth_date'=>'sometimes|nullable|date',
        ]);
        $user = $request->user();
        DB::transaction(function () use ($user, $data) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->fill($data);
            if (array_key_exists('first_name',$data) || array_key_exists('last_name',$data)) {
                $locked->name = trim(($locked->first_name ?? '').' '.($locked->last_name ?? '')) ?: $locked->name;
            }
            $locked->save();
        }, 3);
        $user->refresh();
        return response()->json(['data'=>$otp->present($user)]);
    }

    public function setPassword(Request $request, OtpAuthentication $otp)
    {
        $data = $request->validate(['password'=>['required','confirmed',Password::min(12)->letters()->mixedCase()->numbers()]]);
        $user = $request->user();
        if (filled($user->password)) throw AuthFlowException::make(409,'PASSWORD_ALREADY_CONFIGURED','این حساب از قبل رمز عبور دارد.');
        if (!$user->phone_verified_at || !$user->last_otp_verified_at || $user->last_otp_verified_at->lt(now()->subMinutes(10))) {
            throw AuthFlowException::make(403,'OTP_INVALID','برای تعیین رمز عبور، دوباره شماره موبایل خود را تأیید کنید.');
        }
        $user->forceFill(['password'=>$data['password']])->save();
        return response()->json(['success'=>true,'data'=>['has_password'=>true]]);
    }

    public function requestMobileChange(Request $request, OtpAuthentication $otp)
    {
        if (!is_string($request->input('recaptcha_token')) || $request->input('recaptcha_token') === '' || $request->input('recaptcha_action') !== 'mobile_change') throw AuthFlowException::make(422,'CAPTCHA_INVALID','اعتبارسنجی امنیتی انجام نشد. دوباره تلاش کنید.');
        $data = $request->validate(['mobile'=>'required|string|max:40','recaptcha_token'=>'string|max:4096']);
        return response()->json(['success'=>true,'data'=>$otp->request($data['mobile'],$data['recaptcha_token'],'mobile_change',$request->ip(),'mobile_change',$request->user())]);
    }

    public function verifyMobileChange(Request $request, OtpAuthentication $otp)
    {
        $data = $request->validate(['mobile'=>'required|string|max:40','challenge_id'=>'required|uuid','code'=>'required|string|size:6']);
        $result = $otp->verify($data['mobile'],$data['challenge_id'],$data['code'],$request->user());
        return response()->json(['success'=>true,'data'=>$result]);
    }

    public function requestStepUp(Request $request, OtpAuthentication $otp)
    {
        if (!is_string($request->input('recaptcha_token')) || $request->input('recaptcha_token') === '' || $request->input('recaptcha_action') !== 'auth_step_up') throw AuthFlowException::make(422,'CAPTCHA_INVALID','اعتبارسنجی امنیتی انجام نشد. دوباره تلاش کنید.');
        $data=$request->validate(['recaptcha_token'=>'string|max:4096']);
        return response()->json(['success'=>true,'data'=>$otp->request((string)$request->user()->phone,$data['recaptcha_token'],'auth_step_up',$request->ip(),'step_up',$request->user())]);
    }

    public function verifyStepUp(Request $request, OtpAuthentication $otp)
    {
        $data=$request->validate(['challenge_id'=>'required|uuid','code'=>'required|string|size:6']);
        $result=$otp->verify((string)$request->user()->phone,$data['challenge_id'],$data['code'],$request->user());
        return response()->json(['success'=>true,'data'=>$result]);
    }
}
