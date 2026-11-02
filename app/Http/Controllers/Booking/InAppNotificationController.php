<?php
namespace App\Http\Controllers\Booking;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class InAppNotificationController extends Controller
{
    public function index(Request $request) { return response()->json(['data'=>DB::table('booking_in_app_notifications')->where('user_id',$request->user()->id)->orderByDesc('id')->paginate(30)]); }
    public function read(Request $request,int $notification) { abort_unless(DB::table('booking_in_app_notifications')->where('id',$notification)->where('user_id',$request->user()->id)->update(['read_at'=>now(),'updated_at'=>now()]),404); return response()->noContent(); }
}
