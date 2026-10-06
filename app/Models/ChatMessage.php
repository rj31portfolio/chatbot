<?php

namespace App\Models;

class ChatMessage extends TenantModel
{
    protected function casts(): array
    {
        return ['citations' => 'array'];
    }
}
