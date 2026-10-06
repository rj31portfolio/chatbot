<?php

namespace App\Models;

class AuditLog extends TenantModel
{
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
