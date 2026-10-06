<?php

namespace App\Models;

class AutomationRule extends TenantModel
{
    protected function casts(): array
    {
        return ['settings' => 'array', 'active' => 'boolean'];
    }
}
