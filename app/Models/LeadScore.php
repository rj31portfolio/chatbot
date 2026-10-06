<?php

namespace App\Models;

class LeadScore extends TenantModel
{
    protected function casts(): array
    {
        return ['breakdown' => 'array'];
    }
}
