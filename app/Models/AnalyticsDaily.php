<?php

namespace App\Models;

class AnalyticsDaily extends TenantModel
{
    protected $table = 'analytics_daily';

    protected function casts(): array
    {
        return ['date' => 'date', 'metrics' => 'array'];
    }
}
