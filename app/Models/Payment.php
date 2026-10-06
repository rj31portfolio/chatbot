<?php
namespace App\Models;

class Payment extends TenantModel
{
    protected function casts(): array { return ['metadata'=>'array']; }
}
