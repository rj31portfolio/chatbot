<?php

namespace App\Models;

class WebhookEndpoint extends TenantModel
{
    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'events' => 'array', 'active' => 'boolean'];
    }
}
