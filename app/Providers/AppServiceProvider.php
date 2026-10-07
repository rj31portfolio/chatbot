<?php

namespace App\Providers;

use App\AI\AIProviderInterface;
use App\AI\AIProviderManager;
use App\Billing\PaymentGatewayInterface;
use App\Billing\RazorpayGateway;
use App\Models\BusinessSetting;
use App\Models\PersonalAccessToken;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Support\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->bind(AIProviderInterface::class, AIProviderManager::class);
        $this->app->bind(PaymentGatewayInterface::class, RazorpayGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        RateLimiter::for('auth', fn ($r) => Limit::perMinute(5)->by($r->ip().'|'.strtolower($r->input('email', ''))));
        RateLimiter::for('widget', fn ($r) => Limit::perMinute(90)->by($r->ip().'|'.$r->header('X-Widget-Id')));
        RateLimiter::for('widget-session', fn ($r) => Limit::perMinute(5)->by($r->ip()));
        RateLimiter::for('widget-message', fn ($r) => Limit::perMinute(15)->by($r->ip().'|'.$r->input('session_id')));
        View::composer(['layouts.app', 'auth.form', 'landing', 'legal', 'demo', 'widget-installation'], function ($view) {
            $platform = PlatformSetting::pluck('value', 'key');
            $view->with('brand', $platform['brand']['value'] ?? config('saas.brand'));
            $view->with('logoUrl', $platform['logo_url']['value'] ?? null);
            $view->with('faviconUrl', $platform['favicon_url']['value'] ?? null);
            $view->with('secondaryColor', $platform['secondary_color']['value'] ?? config('saas.secondary_color'));
            $view->with('companyName', $platform['company_name']['value'] ?? config('saas.brand'));
            $view->with('companyUrl', $platform['website_url']['value'] ?? null);
            $view->with('supportEmail', $platform['support_email']['value'] ?? null);
            $view->with('primaryColor', $platform['primary_color']['value'] ?? config('saas.primary_color'));
            if (request()->attributes->get('business')) {
                $subscription = Subscription::with('plan')->first();
                if ($subscription?->plan->limits['white_label'] ?? 0) {
                    $branding = BusinessSetting::where('key', 'branding')->first()?->value ?? [];
                    foreach (['brand' => 'brand', 'primary_color' => 'primaryColor', 'logo_url' => 'logoUrl', 'support_email' => 'supportEmail'] as $key => $variable) {
                        if (! empty($branding[$key])) {
                            $view->with($variable, $branding[$key]);
                        }
                    }
                }
            }
            if (auth()->check()) {
                $view->with('availableBusinesses', auth()->user()->businesses()->get());
            }
        });
    }
}
