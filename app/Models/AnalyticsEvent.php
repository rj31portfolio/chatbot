<?php

namespace App\Models;

class AnalyticsEvent extends TenantModel
{
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
