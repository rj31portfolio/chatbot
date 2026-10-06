<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class BusinessPermission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        $member = $request->attributes->get('membership');
        $permissions = json_decode($member?->permissions ?? '[]',true) ?: [];
        abort_unless($member?->role === 'owner' || in_array($permission,$permissions,true),403,'You do not have permission for this action.');
        return $next($request);
    }
}
