<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class SuperAdmin
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->is_super_admin && $request->user()->status === 'active',403);
        return $next($request);
    }
}
