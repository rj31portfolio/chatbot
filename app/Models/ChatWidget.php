<?php
namespace App\Models;

class ChatWidget extends TenantModel
{
    protected function casts(): array { return ['settings'=>'array','active'=>'boolean','is_demo'=>'boolean','installed_at'=>'datetime']; }
    public function domains() { return $this->hasMany(ChatWidgetDomain::class); }
}
