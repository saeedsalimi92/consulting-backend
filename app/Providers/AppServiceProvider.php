<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;use Illuminate\Support\Facades\Gate;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use App\Services\Auth\{RecaptchaVerifier,GoogleRecaptchaVerifier,TestingRecaptchaVerifier};
use App\Services\Sms\{SmsProvider,ConfiguredSmsProvider,FakeSmsProvider,SmsIrProvider};
use App\Modules\Counselor\Models\{Counselor,Specialty,Comment};use App\Modules\Counselor\Policies\{CounselorPolicy,SpecialtyPolicy,CommentPolicy};
class AppServiceProvider extends ServiceProvider {public function register():void{
 $this->app->bind(RecaptchaVerifier::class,fn()=>app()->environment('testing')?new TestingRecaptchaVerifier():new GoogleRecaptchaVerifier());
 $this->app->bind(SmsProvider::class,fn()=>match(config('auth_otp.provider')){'fake'=>new FakeSmsProvider(),'log'=>new ConfiguredSmsProvider(),default=>new SmsIrProvider()});
}public function boot():void{Gate::policy(Counselor::class,CounselorPolicy::class);Gate::policy(Specialty::class,SpecialtyPolicy::class);Gate::policy(Comment::class,CommentPolicy::class);
 RateLimiter::for('booking-reservation',fn(Request $r)=>Limit::perMinute(10)->by('reservation:'.($r->user()?->id??$r->ip())));
 RateLimiter::for('booking-payment',fn(Request $r)=>Limit::perMinute(10)->by('payment:'.($r->user()?->id??$r->ip())));
 RateLimiter::for('booking-cancellation',fn(Request $r)=>Limit::perMinute(5)->by('cancellation:'.($r->user()?->id??$r->ip())));
 RateLimiter::for('otp-request',fn(Request $r)=>[Limit::perMinute(10)->by('otp-request-minute:'.$r->ip()),Limit::perHour(30)->by('otp-request-hour:'.$r->ip())]);
 RateLimiter::for('otp-verify',fn(Request $r)=>[Limit::perMinute(20)->by('otp-verify-minute:'.$r->ip()),Limit::perHour(100)->by('otp-verify-hour:'.$r->ip())]);
 ResetPassword::createUrlUsing(fn($user,$token)=>rtrim(config('booking.client_reset_url'),'/').'?'.http_build_query(['token'=>$token,'email'=>$user->getEmailForPasswordReset()]));}}
