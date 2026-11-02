<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Hash};
use Illuminate\Validation\Rules\Password;

class AccountSecurityController extends Controller
{
    public function session(Request $request)
    {
        $token=$request->user()->currentAccessToken();
        return response()->json(['data'=>[
            'user'=>$request->user()->only('id','name','email'),
            'token'=>['id'=>$token?->id,'name'=>$token?->name,'abilities'=>$token?->abilities,
                'created_at'=>$token?->created_at,'last_used_at'=>$token?->last_used_at,
                'expires_at'=>$token?->expires_at ?? ($token?->created_at?->copy()->addMinutes(config('sanctum.expiration')))],
        ]]);
    }

    public function changePassword(Request $request)
    {
        $data=$request->validate(['current_password'=>'required|string','password'=>['required','confirmed',Password::min(12)]]);
        abort_unless(Hash::check($data['current_password'],$request->user()->password),422,'Current password is incorrect');
        DB::transaction(function () use ($request,$data) {
            $request->user()->forceFill(['password'=>$data['password']])->save();
            $request->user()->tokens()->delete();
            if (DB::getSchemaBuilder()->hasTable('booking_audit_events')) DB::table('booking_audit_events')->insert([
                'actor_id'=>$request->user()->id,'action'=>'account.password_changed','details'=>'{}','created_at'=>now(),'updated_at'=>now(),
            ]);
        });
        return response()->json(['message'=>'Password changed. Sign in again.']);
    }

    public function logoutEverywhere(Request $request)
    {
        $request->user()->tokens()->delete();
        return response()->noContent();
    }
}
