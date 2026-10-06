<?php

namespace App\Http\Middleware;

use App\Models\Business;
use App\Models\ChatWidget;
use App\Models\ChatWidgetDomain;
use App\Services\UsageService;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WidgetAuthentication
{
    public function handle(Request $r, Closure $next)
    {
        $publicId = $r->header('X-Widget-Id') ?? $r->input('widget_id');
        abort_unless(is_string($publicId) && Str::isUuid($publicId), 404);
        // This is the only public lookup without a tenant scope; the result establishes the business context.
        $widget = ChatWidget::withoutGlobalScopes()->where('public_id', $publicId)->where('active', true)->firstOrFail();
        $business = Business::whereKey($widget->business_id)->where('status', 'active')->firstOrFail();
        abort_unless($business->owner->status === 'active', 403);

        return app(TenantContext::class)->run($business, function () use ($r, $next, $widget) {
            $origin = $r->header('Origin');
            $parts = is_string($origin) ? parse_url($origin) : false;
            abort_unless($parts && in_array($parts['scheme'] ?? '', ['https', 'http'], true) && ! isset($parts['user']) && ! isset($parts['pass']) && (! isset($parts['path']) || $parts['path'] === '') && empty($parts['query']) && empty($parts['fragment']), 403, 'This website is not authorized to use the widget.');
            $host = strtolower($parts['host'] ?? '');
            abort_unless(ChatWidgetDomain::where('chat_widget_id', $widget->id)->where('domain', $host)->exists(), 403, 'This website is not on the approved domain list.');
            app(UsageService::class)->subscription();
            $r->attributes->set('widget', $widget);
            $r->attributes->set('widget_origin', $origin);

            return $next($r);
        });
    }
}
