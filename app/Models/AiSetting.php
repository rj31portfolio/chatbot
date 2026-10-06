<?php
namespace App\Models;

class AiSetting extends TenantModel
{
    protected $table = 'ai_settings';
    protected function casts(): array { return ['features'=>'array','lead_fields'=>'array','scoring'=>'array']; }
}
