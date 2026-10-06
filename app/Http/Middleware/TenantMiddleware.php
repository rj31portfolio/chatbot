<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;

class TenantMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->status === 'active', 403, 'Your account is suspended.');
        $business = $request->user()->businesses()->where('businesses.id', session('business_id'))->first();
        if (! $business) {
            $business = $request->user()->businesses()->first();
            if (! $business) {
                return redirect()->route('business.create');
            }
            session(['business_id' => $business->id]);
        }
        abort_unless($business->status === 'active', 403, 'This business is suspended.');
        abort_unless($business->owner->status === 'active', 403, 'This business account is suspended.');
        $request->attributes->set('business', $business);
        $request->attributes->set('membership', $business->pivot);

        return app(TenantContext::class)->run($business, fn () => $next($request));
    }
}
