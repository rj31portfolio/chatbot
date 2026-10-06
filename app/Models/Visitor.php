<?php

namespace App\Models;

class Visitor extends TenantModel
{
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
