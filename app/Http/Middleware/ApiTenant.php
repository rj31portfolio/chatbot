<?php
namespace App\Http\Middleware;
use App\Models\Business;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
class ApiTenant
{
    public function handle(Request $request,Closure $next)
    {
        $token=$request->user()?->currentAccessToken();
        abort_unless($request->bearerToken()&&$token instanceof \App\Models\PersonalAccessToken,401);
        $business=$request->user()->businesses()->where('businesses.id',$token->business_id)->firstOrFail();
        abort_unless($business->status==='active'&&$business->owner->status==='active'&&$request->user()->status==='active',403);
        abort_unless($request->user()->tokenCan('workspace:'.$business->id),403);
        $request->attributes->set('business',$business);$request->attributes->set('membership',$business->pivot);
        return app(TenantContext::class)->run($business,fn()=> $next($request));
    }
}
