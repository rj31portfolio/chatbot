<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(\App\Support\TenantContext::class);
        $this->app->bind(\App\AI\AIProviderInterface::class,\App\AI\AIProviderManager::class);
        $this->app->bind(\App\Billing\PaymentGatewayInterface::class,\App\Billing\RazorpayGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\RateLimiter::for('auth',fn($r)=>\Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by($r->ip().'|'.strtolower($r->input('email',''))));
        \Illuminate\Support\Facades\RateLimiter::for('widget',fn($r)=>\Illuminate\Cache\RateLimiting\Limit::perMinute(90)->by($r->ip().'|'.$r->header('X-Widget-Id')));
        \Illuminate\Support\Facades\RateLimiter::for('widget-session',fn($r)=>\Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by($r->ip()));
        \Illuminate\Support\Facades\RateLimiter::for('widget-message',fn($r)=>\Illuminate\Cache\RateLimiting\Limit::perMinute(15)->by($r->ip().'|'.$r->input('session_id')));
        \Illuminate\Support\Facades\View::composer(['layouts.app','auth.form','landing','legal','demo'],function($view){
            $platform=\App\Models\PlatformSetting::pluck('value','key');
            $view->with('brand',$platform['brand']['value']??config('saas.brand'));
            $view->with('logoUrl',$platform['logo_url']['value']??null);
            $view->with('faviconUrl',$platform['favicon_url']['value']??null);
            $view->with('secondaryColor',$platform['secondary_color']['value']??config('saas.secondary_color'));
            $view->with('companyName',$platform['company_name']['value']??config('saas.brand'));
            $view->with('companyUrl',$platform['website_url']['value']??null);
            $view->with('supportEmail',$platform['support_email']['value']??null);
            $view->with('primaryColor',$platform['primary_color']['value']??config('saas.primary_color'));
            if(auth()->check()) $view->with('availableBusinesses',auth()->user()->businesses()->get());
        });
    }
}
