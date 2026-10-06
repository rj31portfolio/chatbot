<?php
namespace App\Models;

class BusinessSetting extends TenantModel
{
    protected function casts(): array { return ['value'=>'array']; }
}
