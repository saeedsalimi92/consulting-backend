<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class EnsureClient {
    public function handle(Request $request, Closure $next) {
        $user = $request->user();
        if (!$user?->tokenCan('client') || !(bool)($user->is_active ?? true) || !$user->canAccessClientPortal()) {
            return response()->json(['message' => 'A client account is required for this action.'], 403);
        }
        return $next($request);
    }
}
