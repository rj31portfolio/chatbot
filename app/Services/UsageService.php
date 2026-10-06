<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Subscription;
use App\Models\SubscriptionUsage;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UsageService
{
    public function subscription(): Subscription
    {
        $business = app(TenantContext::class)->business();
        if ($business->status !== 'active' || $business->owner->status !== 'active') {
            throw ValidationException::withMessages(['subscription' => 'This business account is suspended.']);
        }
        $subscription = Subscription::with('plan')->first();
        if (! $subscription || ! in_array($subscription->status, ['trial', 'active'], true) || ($subscription->ends_at && $subscription->ends_at->isPast()) || ($subscription->status === 'trial' && $subscription->trial_ends_at?->isPast())) {
            throw ValidationException::withMessages(['subscription' => 'Your subscription has expired. Please contact the account owner.']);
        }

        return $subscription;
    }

    public function limit(string $metric): int
    {
        return (int) ($this->subscription()->plan->limits[$metric] ?? 0);
    }

    public function consume(string $metric, int $amount = 1): void
    {
        DB::transaction(function () use ($metric, $amount) {
            Business::whereKey(app(TenantContext::class)->id())->lockForUpdate()->firstOrFail();
            $usage = SubscriptionUsage::firstOrCreate(['period' => now()->format('Y-m'), 'metric' => $metric], ['amount' => 0]);
            if ($usage->amount + $amount > $this->limit($metric)) {
                throw ValidationException::withMessages(['usage' => "Your {$metric} limit has been reached. Upgrade your plan to continue."]);
            }
            $usage->increment('amount', $amount);
        });
    }

    public function refund(string $metric, int $amount): void
    {
        DB::transaction(function () use ($metric, $amount) {
            Business::whereKey(app(TenantContext::class)->id())->lockForUpdate()->firstOrFail();
            $usage = SubscriptionUsage::where('period', now()->format('Y-m'))->where('metric', $metric)->first();
            if ($usage) {
                $usage->update(['amount' => max(0, $usage->amount - $amount)]);
            }
        });
    }

    public function createWithinLimit(string $metric, string $model, callable $create): mixed
    {
        return DB::transaction(function () use ($metric, $model, $create) {
            Business::whereKey(app(TenantContext::class)->id())->lockForUpdate()->firstOrFail();
            if ($model::count() >= $this->limit($metric)) {
                throw ValidationException::withMessages(['limit' => "Your {$metric} limit has been reached."]);
            }

            return $create();
        });
    }
}
