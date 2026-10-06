<?php

namespace App\Models;

class Subscription extends TenantModel
{
    protected function casts(): array
    {
        return ['trial_ends_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function plan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }
}
