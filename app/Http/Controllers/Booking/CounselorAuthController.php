<?php
namespace App\Http\Controllers\Booking;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class CounselorAuthController extends Controller
{
    public function login(Request $request)
    {
        $data=$request->validate(['email'=>'required|email','password'=>'required|string']);
        $user=User::where('email',$data['email'])->first();
        if(!$user || !(bool)($user->is_active??true) || !Hash::check($data['password'],$user->password) || !$user->counselor()->exists())
            throw ValidationException::withMessages(['email'=>'اطلاعات ورود معتبر نیست.']);
        return response()->json(['data'=>['id'=>$user->id,'name'=>$user->name],
            'token'=>$user->createToken('counselor-portal',['counselor'])->plainTextToken]);
    }
}
