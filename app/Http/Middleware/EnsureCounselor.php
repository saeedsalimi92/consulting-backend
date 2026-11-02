<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EnsureCounselor
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless((bool)($request->user()?->is_active ?? true) && $request->user()?->tokenCan('counselor') && DB::table('counselors')
            ->where('user_id', $request->user()->id)->whereNull('deleted_at')->exists(), 403);
        return $next($request);
    }
}
