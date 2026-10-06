<?php

namespace App\Models;

class ChatWidget extends TenantModel
{
    protected $attributes = ['welcome_message'=>'Hi! How can I help you today?'];
    protected function casts(): array
    {
        return ['settings' => 'array', 'active' => 'boolean', 'is_demo' => 'boolean', 'installed_at' => 'datetime'];
    }

    public function domains()
    {
        return $this->hasMany(ChatWidgetDomain::class);
    }
}
