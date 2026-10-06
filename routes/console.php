<?php

use App\Jobs\DeliverWebhook;
use App\Jobs\SummarizeConversation;
use App\Models\AiSetting;
use App\Models\AnalyticsDaily;
use App\Models\Business;
use App\Models\ChatSession;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookLog;
use App\Services\AnalyticsService;
use App\Support\TenantContext;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('app:create-admin', function () {
    $email = $this->ask('Admin email');
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Invalid email.');

        return 1;
    }
    $password = $this->secret('Password (at least 12 characters, letters and numbers)');
    if (strlen($password) < 12 || ! preg_match('/[a-z]/i', $password) || ! preg_match('/[0-9]/', $password)) {
        $this->error('Password does not meet requirements.');

        return 1;
    }
    if (User::where('email', $email)->exists()) {
        $this->error('An account with that email already exists.');

        return 1;
    }
    $u = User::create(['name' => $this->ask('Admin name', 'Platform Admin'), 'email' => $email, 'password' => $password]);
    $u->forceFill(['is_super_admin' => true, 'email_verified_at' => now()])->save();
    $this->info('Super admin created.');
})->purpose('Create a super admin interactively without default credentials');

Artisan::command('app:maintenance', function () {
    Business::each(function ($business) {
        app(TenantContext::class)->run($business, function () {
            $settings = AiSetting::first();
            WebhookLog::where('status', 'pending')->where('attempts', '<', 5)->where('created_at', '<', now()->subMinute())->each(fn ($log) => DeliverWebhook::dispatch($business->id, $log->id));
            if (! $settings) {
                return;
            }
            ChatSession::where('created_at', '<', now()->subDays($settings->retention_days))->delete();
            ChatSession::whereIn('status', ['active', 'transferred'])->where('last_activity', '<', now()->subMinutes(30))->each(function ($s) {
                $s->update(['status' => 'abandoned']);
                SummarizeConversation::dispatch($s->business_id, $s->id);
            });
            Subscription::whereIn('status', ['active', 'trial'])->where(function ($q) {
                $q->where('ends_at', '<', now())->orWhere(function ($q) {
                    $q->where('status', 'trial')->where('trial_ends_at', '<', now());
                });
            })->update(['status' => 'expired']);
            AnalyticsDaily::updateOrCreate(['date' => now()->toDateString()], ['metrics' => app(AnalyticsService::class)->metrics()]);
        });
    });
    $this->info('Retention, idle sessions, subscription expiry, and analytics processed.');
})->purpose('Process scheduled tenant maintenance');
Schedule::command('app:maintenance')->everyFifteenMinutes()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
